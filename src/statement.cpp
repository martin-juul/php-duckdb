/*
  +----------------------------------------------------------------------+
  | duckdb - native DuckDB driver for PHP                                |
  +----------------------------------------------------------------------+
  | Copyright (c) Martin Juul Christiansen (https://juul.xyz)            |
  +----------------------------------------------------------------------+
  | This source file is subject to the MIT license that is bundled with  |
  | this package in the file LICENSE.                                    |
  +----------------------------------------------------------------------+
  | DuckDB\Statement: prepared statements, parameter binding (positional |
  | and named) and statement metadata.                                   |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_duckdb.h"

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

/* ================================================================== */
/* Parameter resolution / binding                                     */
/* ================================================================== */

bool duckdb_resolve_param_index(duckdb_prepared_statement stmt, zval *param, idx_t *index) {
    if (Z_TYPE_P(param) == IS_LONG) {
        zend_long i = Z_LVAL_P(param);
        idx_t count = duckdb_nparams(stmt);
        if (i < 1 || (uint64_t)i > (uint64_t)count) {
            zend_value_error("Invalid parameter index " ZEND_LONG_FMT
                             ": parameters are 1-based and this statement has %d parameter(s)",
                             i, (int)count);
            return false;
        }
        *index = (idx_t)i;
        return true;
    }

    if (Z_TYPE_P(param) == IS_STRING) {
        const char *name = Z_STRVAL_P(param);
        size_t name_len = Z_STRLEN_P(param);
        /* Accept names with or without the $ / : prefix. */
        if (name_len > 0 && (name[0] == '$' || name[0] == ':')) {
            name++;
            name_len--;
        }
        if (name_len == 0) {
            zend_value_error("Invalid empty parameter name");
            return false;
        }
        std::string name_str(name, name_len);
        if (duckdb_bind_parameter_index(stmt, index, name_str.c_str()) == DuckDBError) {
            zend_throw_exception_ex(duckdb_binder_exception_ce, DUCKDB_ERROR_BINDER,
                                    "Unknown named parameter \"%s\"", name_str.c_str());
            return false;
        }
        return true;
    }

    zend_type_error("Parameter must be an integer position or a string name, got %s",
                    zend_zval_type_name(param));
    return false;
}

bool duckdb_bind_params_array(duckdb_prepared_statement stmt, HashTable *params) {
    if (zend_hash_num_elements(params) == 0) {
        return true;
    }

    if (zend_array_is_list(params)) {
        /* A list binds positionally; validate the count up front so an
         * out-of-range position is a programmer error (ValueError), not a
         * DuckDB bind failure. */
        idx_t nparams = duckdb_nparams(stmt);
        uint32_t given = zend_hash_num_elements(params);
        if ((idx_t)given > nparams) {
            zend_value_error("Too many values: statement has %d parameter(s), %d given",
                             (int)nparams, (int)given);
            return false;
        }
        idx_t i = 1;
        zval *val;
        ZEND_HASH_FOREACH_VAL(params, val) {
            duckdb_value duck_val = duckdb_php_to_duckdb_value(val);
            if (duck_val == nullptr) {
                return false;
            }
            duckdb_state st = duckdb_bind_value(stmt, i, duck_val);
            duckdb_destroy_value(&duck_val);
            if (st == DuckDBError) {
                zend_throw_exception_ex(duckdb_binder_exception_ce, DUCKDB_ERROR_BINDER,
                                        "Failed to bind parameter %d", (int)i);
                return false;
            }
            i++;
        } ZEND_HASH_FOREACH_END();
        return true;
    }

    zend_string *key;
    zend_long num_key;
    zval *val;
    ZEND_HASH_FOREACH_KEY_VAL(params, num_key, key, val) {
        zval param_zv;
        if (key) {
            ZVAL_STR(&param_zv, key);
        } else {
            /* Mixed arrays are ambiguous: integer keys in an associative
             * parameter array are treated as 1-based positions. */
            ZVAL_LONG(&param_zv, num_key);
        }
        idx_t index;
        if (!duckdb_resolve_param_index(stmt, &param_zv, &index)) {
            return false;
        }
        duckdb_value duck_val = duckdb_php_to_duckdb_value(val);
        if (duck_val == nullptr) {
            return false;
        }
        duckdb_state st = duckdb_bind_value(stmt, index, duck_val);
        duckdb_destroy_value(&duck_val);
        if (st == DuckDBError) {
            zend_throw_exception_ex(duckdb_binder_exception_ce, DUCKDB_ERROR_BINDER,
                                    "Failed to bind parameter %d", (int)index);
            return false;
        }
    } ZEND_HASH_FOREACH_END();
    return true;
}

/* Shared by bindValue() and bindBlob(). */
static void duckdb_statement_bind_impl(INTERNAL_FUNCTION_PARAMETERS, bool as_blob) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *param;
    zval *value;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ZVAL(param)
        Z_PARAM_ZVAL(value)
    ZEND_PARSE_PARAMETERS_END();

    if (as_blob && Z_TYPE_P(value) != IS_STRING) {
        zend_argument_type_error(2, "must be of type string, %s given", zend_zval_type_name(value));
        RETURN_THROWS();
    }

    php_duckdb_statement_object *intern = Z_DUCKDB_STATEMENT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Statement")) {
        RETURN_THROWS();
    }
    if (!duckdb_connection_guard(intern->inner->conn)) {
        RETURN_THROWS();
    }

    idx_t index;
    duckdb_state st;
    {
        /* The whole resolve+bind sequence runs under the connection mutex:
         * an async worker may currently be executing this same statement. */
        std::lock_guard<std::mutex> lk(intern->inner->conn->mutex);
        if (!duckdb_resolve_param_index(intern->inner->stmt, param, &index)) {
            RETURN_THROWS();
        }

        duckdb_value duck_val;
        if (as_blob) {
            duck_val = duckdb_create_blob((const uint8_t *)Z_STRVAL_P(value), Z_STRLEN_P(value));
        } else {
            duck_val = duckdb_php_to_duckdb_value(value);
        }
        if (duck_val == nullptr) {
            RETURN_THROWS();
        }

        st = duckdb_bind_value(intern->inner->stmt, index, duck_val);
        duckdb_destroy_value(&duck_val);
    }

    if (st == DuckDBError) {
        zend_throw_exception_ex(duckdb_binder_exception_ce, DUCKDB_ERROR_BINDER,
                                "Failed to bind parameter %d", (int)index);
        RETURN_THROWS();
    }
    RETURN_THIS();
}

/* ================================================================== */
/* DuckDB\Statement                                                   */
/* ================================================================== */

PHP_METHOD(DuckDB_Statement, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    zend_throw_error(NULL, "DuckDB\\Statement objects must be created via DuckDB\\Connection::prepare()");
}

PHP_METHOD(DuckDB_Statement, bindValue) {
    duckdb_statement_bind_impl(INTERNAL_FUNCTION_PARAM_PASSTHRU, /*as_blob=*/false);
}

PHP_METHOD(DuckDB_Statement, bindBlob) {
    duckdb_statement_bind_impl(INTERNAL_FUNCTION_PARAM_PASSTHRU, /*as_blob=*/true);
}

PHP_METHOD(DuckDB_Statement, clearBindings) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_statement_object *intern = Z_DUCKDB_STATEMENT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Statement")) {
        RETURN_THROWS();
    }
    if (!duckdb_connection_guard(intern->inner->conn)) {
        RETURN_THROWS();
    }
    duckdb_clear_bindings(intern->inner->stmt);
}

PHP_METHOD(DuckDB_Statement, parameterCount) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_statement_object *intern = Z_DUCKDB_STATEMENT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Statement")) {
        RETURN_THROWS();
    }
    RETURN_LONG((zend_long)duckdb_nparams(intern->inner->stmt));
}

PHP_METHOD(DuckDB_Statement, parameterName) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long param;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(param)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_statement_object *intern = Z_DUCKDB_STATEMENT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Statement")) {
        RETURN_THROWS();
    }
    idx_t count = duckdb_nparams(intern->inner->stmt);
    if (param < 1 || (uint64_t)param > (uint64_t)count) {
        zend_argument_value_error(1, "must be between 1 and %d", (int)count);
        RETURN_THROWS();
    }

    char *name = (char *)duckdb_parameter_name(intern->inner->stmt, (idx_t)param);
    if (name == nullptr) {
        /* Positional (`?`) parameters have no name; report the position. */
        RETURN_LONG(param);
    }
    RETVAL_STRING(name);
    duckdb_free(name);
}

PHP_METHOD(DuckDB_Statement, parameterType) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *param;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(param)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_statement_object *intern = Z_DUCKDB_STATEMENT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Statement")) {
        RETURN_THROWS();
    }

    idx_t index;
    if (!duckdb_resolve_param_index(intern->inner->stmt, param, &index)) {
        RETURN_THROWS();
    }

    scoped_duckdb_logical_type param_type(duckdb_param_logical_type(intern->inner->stmt, index));
    if (!param_type) {
        RETURN_STRING("UNKNOWN");
    }
    /* NB: bind to a named local -- a temporary's c_str() dies inside the
     * ZVAL_STRING macro's intermediate declaration (use-after-free). */
    std::string type_name = duckdb_logical_type_render(param_type.get());
    RETURN_STRING(type_name.c_str());
}

PHP_METHOD(DuckDB_Statement, statementType) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_statement_object *intern = Z_DUCKDB_STATEMENT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Statement")) {
        RETURN_THROWS();
    }
    RETURN_STRING(duckdb_statement_type_name(duckdb_prepared_statement_type(intern->inner->stmt)));
}

PHP_METHOD(DuckDB_Statement, columnCount) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_statement_object *intern = Z_DUCKDB_STATEMENT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Statement")) {
        RETURN_THROWS();
    }
    RETURN_LONG((zend_long)duckdb_prepared_statement_column_count(intern->inner->stmt));
}

PHP_METHOD(DuckDB_Statement, columnName) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long index;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(index)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_statement_object *intern = Z_DUCKDB_STATEMENT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Statement")) {
        RETURN_THROWS();
    }
    idx_t count = duckdb_prepared_statement_column_count(intern->inner->stmt);
    if (index < 0 || (uint64_t)index >= (uint64_t)count) {
        zend_argument_value_error(1, "must be between 0 and %d", count > 0 ? (int)count - 1 : 0);
        RETURN_THROWS();
    }
    /* The C API allocates the returned string; free it with duckdb_free. */
    const char *name = duckdb_prepared_statement_column_name(intern->inner->stmt, (idx_t)index);
    if (name == nullptr) {
        zend_argument_value_error(1, "must be between 0 and %d", count > 0 ? (int)count - 1 : 0);
        RETURN_THROWS();
    }
    RETVAL_STRING(name);
    duckdb_free((void *)name);
}

PHP_METHOD(DuckDB_Statement, columnType) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long index;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(index)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_statement_object *intern = Z_DUCKDB_STATEMENT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Statement")) {
        RETURN_THROWS();
    }
    idx_t count = duckdb_prepared_statement_column_count(intern->inner->stmt);
    if (index < 0 || (uint64_t)index >= (uint64_t)count) {
        zend_argument_value_error(1, "must be between 0 and %d", count > 0 ? (int)count - 1 : 0);
        RETURN_THROWS();
    }
    scoped_duckdb_logical_type col_type(
        duckdb_prepared_statement_column_logical_type(intern->inner->stmt, (idx_t)index));
    if (!col_type) {
        RETURN_STRING("UNKNOWN");
    }
    std::string type_name = duckdb_logical_type_render(col_type.get());
    RETURN_STRING(type_name.c_str());
}

/* Shared execute implementation: bind $params, then execute (materialized
 * or streaming). */
static void duckdb_statement_execute_impl(INTERNAL_FUNCTION_PARAMETERS, bool streaming) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    HashTable *params = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT(params)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_statement_object *intern = Z_DUCKDB_STATEMENT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Statement")) {
        RETURN_THROWS();
    }
    if (!duckdb_connection_guard(intern->inner->conn)) {
        RETURN_THROWS();
    }

    duckdb_result res = {};
    {
        std::lock_guard<std::mutex> lk(intern->inner->conn->mutex);
        if (params && !duckdb_bind_params_array(intern->inner->stmt, params)) {
            RETURN_THROWS();
        }
        duckdb_state st;
        if (streaming) {
            /* duckdb_execute_prepared_streaming is deprecated upstream;
             * the replacement is the pending-result API. */
            duckdb_pending_result pending = nullptr;
            if (duckdb_pending_prepared_streaming(intern->inner->stmt, &pending) == DuckDBError) {
                const char *err = pending ? duckdb_pending_error(pending) : nullptr;
                std::string msg = (err && err[0]) ? err : "Failed to start streaming query";
                if (pending) {
                    duckdb_destroy_pending(&pending);
                }
                duckdb_error_type type = duckdb_classify_error_message(msg.c_str());
                if (type == DUCKDB_ERROR_INVALID) {
                    type = DUCKDB_ERROR_INTERNAL;
                }
                duckdb_throw_error(type, msg.c_str());
                RETURN_THROWS();
            }
            st = duckdb_execute_pending(pending, &res);
            /* duckdb_execute_pending does NOT consume the pending handle. */
            duckdb_destroy_pending(&pending);
        } else {
            st = duckdb_execute_prepared(intern->inner->stmt, &res);
        }
        if (st == DuckDBError) {
            duckdb_throw_result_error(&res);
            RETURN_THROWS();
        }
        /* A new execution on this connection invalidates any open
         * streaming result. */
        intern->inner->conn->execution_epoch.fetch_add(1, std::memory_order_relaxed);
    }

    duckdb_result_instantiate(return_value, &res, streaming,
                              streaming ? intern->inner : nullptr);
}

PHP_METHOD(DuckDB_Statement, execute) {
    duckdb_statement_execute_impl(INTERNAL_FUNCTION_PARAM_PASSTHRU, /*streaming=*/false);
}

PHP_METHOD(DuckDB_Statement, executeStreaming) {
    duckdb_statement_execute_impl(INTERNAL_FUNCTION_PARAM_PASSTHRU, /*streaming=*/true);
}

PHP_METHOD(DuckDB_Statement, executeAsync) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    HashTable *params = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT(params)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_statement_object *intern = Z_DUCKDB_STATEMENT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Statement")) {
        RETURN_THROWS();
    }
    if (!duckdb_connection_guard(intern->inner->conn)) {
        RETURN_THROWS();
    }

    /* Bind on the request thread, under the connection mutex so this cannot
     * interleave with a running async execution of the same statement; the
     * worker thread only executes. */
    if (params) {
        std::lock_guard<std::mutex> lk(intern->inner->conn->mutex);
        if (!duckdb_bind_params_array(intern->inner->stmt, params)) {
            RETURN_THROWS();
        }
    }

    int fds[2] = {-1, -1};
    if (!duckdb_create_notify_pipe(fds)) {
        RETURN_THROWS();
    }

    auto task = std::make_shared<async_task>();
    task->mode = task_mode::THREAD_PREPARED;
    task->conn = intern->inner->conn;
    task->stmt = intern->inner; /* keeps the statement alive during execution */
    task->notify_write_fd = fds[1];

    /* Starting a new execution invalidates any open streaming result on
     * this connection. The bump is sequenced before the thread is
     * created: thread creation is the happens-before edge that publishes
     * the task to the worker. */
    intern->inner->conn->execution_epoch.fetch_add(1, std::memory_order_relaxed);

    try {
        /* Register before creation: MSHUTDOWN may only proceed once every
         * started worker has also finished. */
        duckdb_async_worker_start(task);
        std::thread(duckdb_async_run, task).detach();
    } catch (const std::system_error &e) {
        duckdb_async_worker_finish();
        /* Thread creation failed (resource exhaustion): no worker owns the
         * write end, so close both fds ourselves. */
        close(fds[0]);
        close(fds[1]);
        task->notify_write_fd = -1;
        zend_throw_exception_ex(duckdb_internal_exception_ce, DUCKDB_ERROR_INTERNAL,
                                "Failed to start async worker thread: %s", e.what());
        RETURN_THROWS();
    }

    object_init_ex(return_value, duckdb_pending_ce);
    php_duckdb_pending_object *p = Z_DUCKDB_PENDING_P(return_value);
    p->task = task;
    p->read_fd = fds[0];
}
