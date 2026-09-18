/*
  +----------------------------------------------------------------------+
  | duckdb - native DuckDB driver for PHP                                |
  +----------------------------------------------------------------------+
  | Copyright (c) Martin Juul Christiansen (https://juul.xyz)            |
  +----------------------------------------------------------------------+
  | This source file is subject to the MIT license that is bundled with  |
  | this package in the file LICENSE.                                    |
  +----------------------------------------------------------------------+
  | Module lifecycle, DuckDB\Database and DuckDB\Connection.             |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "ext/json/php_json.h"
#include "zend_interfaces.h"
#include "php_streams.h"
#include "php_duckdb.h"
#include "duckdb_arginfo.h"

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif
/* ZEND_TSRMLS_CACHE_DEFINE() is emitted once near the bottom of this file */

/* ================================================================== */
/* Class entries                                                      */
/* ================================================================== */

zend_class_entry *duckdb_database_ce;
zend_class_entry *duckdb_connection_ce;
zend_class_entry *duckdb_statement_ce;
zend_class_entry *duckdb_result_ce;
zend_class_entry *duckdb_result_iterator_ce;
zend_class_entry *duckdb_pending_ce;
zend_class_entry *duckdb_appender_ce;
zend_class_entry *duckdb_interval_ce;
zend_class_entry *duckdb_fetch_mode_ce;
zend_class_entry *duckdb_error_type_ce;

zend_class_entry *duckdb_exception_ce;
zend_class_entry *duckdb_connection_exception_ce;
zend_class_entry *duckdb_parser_exception_ce;
zend_class_entry *duckdb_binder_exception_ce;
zend_class_entry *duckdb_catalog_exception_ce;
zend_class_entry *duckdb_constraint_exception_ce;
zend_class_entry *duckdb_transaction_exception_ce;
zend_class_entry *duckdb_conversion_exception_ce;
zend_class_entry *duckdb_io_exception_ce;
zend_class_entry *duckdb_interrupted_exception_ce;
zend_class_entry *duckdb_internal_exception_ce;

static zend_object_handlers duckdb_database_handlers;
static zend_object_handlers duckdb_connection_handlers;
static zend_object_handlers duckdb_statement_handlers;
static zend_object_handlers duckdb_result_handlers;
static zend_object_handlers duckdb_result_iterator_handlers;
static zend_object_handlers duckdb_pending_handlers;
static zend_object_handlers duckdb_appender_handlers;
static zend_object_handlers duckdb_interval_handlers;

/* ================================================================== */
/* Object creation / destruction                                      */
/*                                                                    */
/* The structs contain C++ members (shared_ptr), so they are          */
/* placement-new'ed after allocation and destructed explicitly in     */
/* free_obj.                                                          */
/* ================================================================== */

#define DUCKDB_DEFINE_OBJECT_CREATE(name)                                                  \
    zend_object *duckdb_##name##_create_object(zend_class_entry *ce) {                     \
        php_duckdb_##name##_object *intern = (php_duckdb_##name##_object *)ecalloc(        \
            1, sizeof(php_duckdb_##name##_object) + zend_object_properties_size(ce));      \
        duckdb_##name##_object_init(intern);                                               \
        zend_object_std_init(&intern->std, ce);                                            \
        object_properties_init(&intern->std, ce);                                          \
        intern->std.handlers = &duckdb_##name##_handlers;                                  \
        return &intern->std;                                                               \
    }

static inline void duckdb_database_object_init(php_duckdb_database_object *o) {
    new (&o->inner) std::shared_ptr<db_inner>();
}
static inline void duckdb_connection_object_init(php_duckdb_connection_object *o) {
    new (&o->inner) std::shared_ptr<conn_inner>();
}
static inline void duckdb_statement_object_init(php_duckdb_statement_object *o) {
    new (&o->inner) std::shared_ptr<stmt_inner>();
}
static inline void duckdb_result_object_init(php_duckdb_result_object *o) {
    new (&o->data) std::shared_ptr<result_data>();
}
static inline void duckdb_result_iterator_object_init(php_duckdb_result_iterator_object *o) {
    new (&o->data) std::shared_ptr<result_data>();
    ZVAL_UNDEF(&o->current);
    o->key = 0;
    o->started = false;
}
static inline void duckdb_pending_object_init(php_duckdb_pending_object *o) {
    new (&o->task) std::shared_ptr<async_task>();
    o->read_fd = -1;
}
static inline void duckdb_appender_object_init(php_duckdb_appender_object *o) {
    new (&o->inner) std::shared_ptr<appender_inner>();
}
static inline void duckdb_interval_object_init(php_duckdb_interval_object *o) {
    o->interval = {0, 0, 0};
}

DUCKDB_DEFINE_OBJECT_CREATE(database)
DUCKDB_DEFINE_OBJECT_CREATE(connection)
DUCKDB_DEFINE_OBJECT_CREATE(statement)
DUCKDB_DEFINE_OBJECT_CREATE(result)
DUCKDB_DEFINE_OBJECT_CREATE(result_iterator)
DUCKDB_DEFINE_OBJECT_CREATE(pending)
DUCKDB_DEFINE_OBJECT_CREATE(appender)
DUCKDB_DEFINE_OBJECT_CREATE(interval)

static void duckdb_database_free_object(zend_object *object) {
    php_duckdb_database_object *intern = duckdb_database_from_obj(object);
    intern->inner.~shared_ptr(); /* duckdb_close() runs in ~db_inner() */
    zend_object_std_dtor(&intern->std);
}

static void duckdb_connection_free_object(zend_object *object) {
    php_duckdb_connection_object *intern = duckdb_connection_from_obj(object);
    intern->inner.~shared_ptr();
    zend_object_std_dtor(&intern->std);
}

static void duckdb_statement_free_object(zend_object *object) {
    php_duckdb_statement_object *intern = duckdb_statement_from_obj(object);
    intern->inner.~shared_ptr();
    zend_object_std_dtor(&intern->std);
}

static void duckdb_result_free_object(zend_object *object) {
    php_duckdb_result_object *intern = duckdb_result_from_obj(object);
    intern->data.~shared_ptr();
    zend_object_std_dtor(&intern->std);
}

static void duckdb_result_iterator_free_object(zend_object *object) {
    php_duckdb_result_iterator_object *intern = duckdb_result_iterator_from_obj(object);
    zval_ptr_dtor(&intern->current);
    intern->data.~shared_ptr();
    zend_object_std_dtor(&intern->std);
}

static void duckdb_pending_free_object(zend_object *object) {
    php_duckdb_pending_object *intern = duckdb_pending_from_obj(object);
    intern->task.~shared_ptr(); /* the worker keeps its own ref until completion */
    if (intern->read_fd >= 0) {
        close(intern->read_fd);
        intern->read_fd = -1;
    }
    /* the write end is closed by the worker after notification */
    zend_object_std_dtor(&intern->std);
}

static void duckdb_appender_free_object(zend_object *object) {
    php_duckdb_appender_object *intern = duckdb_appender_from_obj(object);
    intern->inner.~shared_ptr(); /* ~appender_inner() closes + destroys */
    zend_object_std_dtor(&intern->std);
}

static void duckdb_interval_free_object(zend_object *object) {
    php_duckdb_interval_object *intern = duckdb_interval_from_obj(object);
    zend_object_std_dtor(&intern->std);
}

/* ================================================================== */
/* DuckDB\Database                                                    */
/* ================================================================== */

/* Throw a ConnectionException when the connection has been closed.
 * Returns true when the connection is usable. */
bool duckdb_connection_guard(const std::shared_ptr<conn_inner> &conn) {
    if (conn->closed.load(std::memory_order_acquire)) {
        zend_throw_exception_ex(duckdb_connection_exception_ce, DUCKDB_ERROR_CONNECTION,
                                "Connection is closed");
        return false;
    }
    return true;
}

PHP_METHOD(DuckDB_Database, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    char *path = (char *)":memory:";
    size_t path_len = sizeof(":memory:") - 1;
    HashTable *config = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 2)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING(path, path_len)
        Z_PARAM_ARRAY_HT(config)
    ZEND_PARSE_PARAMETERS_END();

    if (path_len == 0) {
        path = (char *)":memory:";
    }

    scoped_duckdb_config cfg;
    if (duckdb_create_config(cfg.out()) == DuckDBError) {
        duckdb_throw_error(DUCKDB_ERROR_OUT_OF_MEMORY, "Failed to create DuckDB configuration");
        RETURN_THROWS();
    }

    if (config) {
        zend_string *key;
        zend_long num_key;
        zval *val;
        ZEND_HASH_FOREACH_KEY_VAL(config, num_key, key, val) {
            if (key == NULL) {
                zend_argument_value_error(2, "must be a map of option names to values, got integer key " ZEND_LONG_FMT, num_key);
                RETURN_THROWS();
            }
            if (Z_TYPE_P(val) != IS_STRING && Z_TYPE_P(val) != IS_LONG &&
                Z_TYPE_P(val) != IS_DOUBLE && Z_TYPE_P(val) != IS_TRUE && Z_TYPE_P(val) != IS_FALSE) {
                zend_argument_value_error(2, "must only contain scalar values, got %s for option \"%s\"",
                                          zend_zval_type_name(val), ZSTR_VAL(key));
                RETURN_THROWS();
            }
            /* Booleans need DuckDB's canonical "true"/"false" spelling:
             * zval_get_string(false) yields "", which DuckDB rejects. */
            zend_string *str_val;
            if (Z_TYPE_P(val) == IS_TRUE) {
                str_val = zend_string_init("true", sizeof("true") - 1, 0);
            } else if (Z_TYPE_P(val) == IS_FALSE) {
                str_val = zend_string_init("false", sizeof("false") - 1, 0);
            } else {
                str_val = zval_get_string(val);
            }
            duckdb_state st = duckdb_set_config(cfg.get(), ZSTR_VAL(key), ZSTR_VAL(str_val));
            zend_string_release(str_val);
            if (st == DuckDBError) {
                zend_throw_exception_ex(duckdb_exception_ce, DUCKDB_INVALID_CONFIGURATION,
                                        "Invalid DuckDB configuration option \"%s\"", ZSTR_VAL(key));
                RETURN_THROWS();
            }
        } ZEND_HASH_FOREACH_END();
    }

    php_duckdb_database_object *intern = Z_DUCKDB_DATABASE_P(ZEND_THIS);
    auto inner = std::make_shared<db_inner>();

    char *err = nullptr;
    duckdb_state state = duckdb_open_ext(path, &inner->db, cfg.get(), &err);
    if (state == DuckDBError) {
        std::string msg = err ? err : "Unable to open database";
        if (err) {
            duckdb_free(err);
        }
        /* DuckDB defers validation of unknown config options to open time;
         * classify that failure as a configuration error, not a connection
         * error. */
        if (msg.find("options were not recognized") != std::string::npos) {
            duckdb_throw_error(DUCKDB_INVALID_CONFIGURATION, msg.c_str());
        } else {
            /* Open failures carry the same "<Type> Error:" prefix as query
             * errors: a single-writer lock conflict arrives as "IO Error:
             * Could not set lock on file ..." and must surface as
             * IOException(ErrorType::Io), not a generic connection error.
             * Unrecognized messages stay connection errors. */
            duckdb_error_type type = duckdb_classify_error_message(msg.c_str());
            if (type == DUCKDB_ERROR_INVALID) {
                type = DUCKDB_ERROR_CONNECTION;
            }
            duckdb_throw_error(type, msg.c_str());
        }
        RETURN_THROWS();
    }
    intern->inner = inner;
}

PHP_METHOD(DuckDB_Database, connect) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_database_object *intern = Z_DUCKDB_DATABASE_P(ZEND_THIS);

    auto inner = std::make_shared<conn_inner>();
    inner->db = intern->inner;
    if (duckdb_connect(intern->inner->db, &inner->conn) == DuckDBError) {
        duckdb_throw_error(DUCKDB_ERROR_CONNECTION, "Unable to connect to database");
        RETURN_THROWS();
    }

    object_init_ex(return_value, duckdb_connection_ce);
    php_duckdb_connection_object *conn_intern = Z_DUCKDB_CONNECTION_P(return_value);
    conn_intern->inner = inner;
}

/* ================================================================== */
/* DuckDB\Connection                                                  */
/* ================================================================== */

PHP_METHOD(DuckDB_Connection, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    zend_throw_error(NULL, "DuckDB\\Connection objects must be created via DuckDB\\Database::connect()");
}

PHP_METHOD(DuckDB_Connection, query) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    char *sql;
    size_t sql_len;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(sql, sql_len)
    ZEND_PARSE_PARAMETERS_END();

    if (!duckdb_check_no_nul(sql, sql_len, 1)) {
        RETURN_THROWS();
    }

    php_duckdb_connection_object *intern = Z_DUCKDB_CONNECTION_P(ZEND_THIS);
    if (!duckdb_connection_guard(intern->inner)) {
        RETURN_THROWS();
    }

    duckdb_result res = {};
    {
        std::lock_guard<std::mutex> lk(intern->inner->mutex);
        intern->inner->execution_epoch.fetch_add(1, std::memory_order_relaxed);
        if (duckdb_query(intern->inner->conn, sql, &res) == DuckDBError) {
            duckdb_throw_result_error(&res);
            RETURN_THROWS();
        }
    }

    duckdb_result_instantiate(return_value, &res, /*streaming=*/false, nullptr);
}

PHP_METHOD(DuckDB_Connection, queryStreaming) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    char *sql;
    size_t sql_len;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(sql, sql_len)
    ZEND_PARSE_PARAMETERS_END();

    if (!duckdb_check_no_nul(sql, sql_len, 1)) {
        RETURN_THROWS();
    }

    php_duckdb_connection_object *intern = Z_DUCKDB_CONNECTION_P(ZEND_THIS);
    if (!duckdb_connection_guard(intern->inner)) {
        RETURN_THROWS();
    }

    auto stmt = std::make_shared<stmt_inner>();
    stmt->conn = intern->inner;
    duckdb_result res = {};
    {
        std::lock_guard<std::mutex> lk(intern->inner->mutex);
        if (duckdb_prepare(intern->inner->conn, sql, &stmt->stmt) == DuckDBError) {
            const char *err = stmt->stmt ? duckdb_prepare_error(stmt->stmt) : nullptr;
            duckdb_throw_prepare_error(err ? err : "Failed to prepare statement");
            RETURN_THROWS();
        }
        if (duckdb_execute_prepared_streaming(stmt->stmt, &res) == DuckDBError) {
            duckdb_throw_result_error(&res);
            RETURN_THROWS();
        }
        /* The stream is now the connection's one active streaming result. */
        intern->inner->execution_epoch.fetch_add(1, std::memory_order_relaxed);
    }

    duckdb_result_instantiate(return_value, &res, /*streaming=*/true, stmt);
}

PHP_METHOD(DuckDB_Connection, queryAsync) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    char *sql;
    size_t sql_len;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(sql, sql_len)
    ZEND_PARSE_PARAMETERS_END();

    if (!duckdb_check_no_nul(sql, sql_len, 1)) {
        RETURN_THROWS();
    }

    php_duckdb_connection_object *intern = Z_DUCKDB_CONNECTION_P(ZEND_THIS);
    if (!duckdb_connection_guard(intern->inner)) {
        RETURN_THROWS();
    }

    int fds[2] = {-1, -1};
    if (!duckdb_create_notify_pipe(fds)) {
        RETURN_THROWS();
    }

    auto task = std::make_shared<async_task>();
    task->mode = task_mode::THREAD_QUERY;
    task->conn = intern->inner;
    task->sql.assign(sql, sql_len);
    task->notify_write_fd = fds[1];

    object_init_ex(return_value, duckdb_pending_ce);
    php_duckdb_pending_object *p = Z_DUCKDB_PENDING_P(return_value);
    p->task = task;
    p->read_fd = fds[0];

    /* Starting a new execution invalidates any open streaming result on
     * this connection. */
    intern->inner->execution_epoch.fetch_add(1, std::memory_order_relaxed);
    std::thread(duckdb_async_run, task).detach();
}

PHP_METHOD(DuckDB_Connection, queryPending) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    char *sql;
    size_t sql_len;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(sql, sql_len)
    ZEND_PARSE_PARAMETERS_END();

    if (!duckdb_check_no_nul(sql, sql_len, 1)) {
        RETURN_THROWS();
    }

    php_duckdb_connection_object *intern = Z_DUCKDB_CONNECTION_P(ZEND_THIS);
    if (!duckdb_connection_guard(intern->inner)) {
        RETURN_THROWS();
    }

    auto task = std::make_shared<async_task>();
    task->mode = task_mode::POLLING;
    task->conn = intern->inner;
    task->sql.assign(sql, sql_len);

    {
        std::lock_guard<std::mutex> lk(task->conn->mutex);
        duckdb_prepared_statement ps = nullptr;
        if (duckdb_prepare(task->conn->conn, task->sql.c_str(), &ps) == DuckDBError) {
            const char *err = ps ? duckdb_prepare_error(ps) : nullptr;
            std::string msg = err ? err : "Failed to prepare statement";
            if (ps) {
                duckdb_destroy_prepare(&ps);
            }
            duckdb_throw_prepare_error(msg.c_str());
            RETURN_THROWS();
        }
        /* duckdb_pending_prepared does NOT take ownership of the prepared
         * statement, but the pending result may reference its data - keep it
         * alive on the task, destroyed after the pending result. */
        task->owned_stmt = ps;
        if (duckdb_pending_prepared(ps, &task->pending) == DuckDBError) {
            if (task->pending) {
                const char *err = duckdb_pending_error(task->pending);
                std::string msg = err ? err : "Failed to start query";
                duckdb_destroy_pending(&task->pending);
                task->pending = nullptr;
                duckdb_throw_msg(msg.c_str());
                RETURN_THROWS();
            }
            duckdb_throw_msg("Failed to start query");
            RETURN_THROWS();
        }
        task->conn->execution_epoch.fetch_add(1, std::memory_order_relaxed);
    }

    object_init_ex(return_value, duckdb_pending_ce);
    php_duckdb_pending_object *p = Z_DUCKDB_PENDING_P(return_value);
    p->task = task;
    p->read_fd = -1; /* polling mode: no worker thread, no notify channel */
}

PHP_METHOD(DuckDB_Connection, execute) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    char *sql;
    size_t sql_len;
    HashTable *params = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STRING(sql, sql_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT(params)
    ZEND_PARSE_PARAMETERS_END();

    if (!duckdb_check_no_nul(sql, sql_len, 1)) {
        RETURN_THROWS();
    }

    php_duckdb_connection_object *intern = Z_DUCKDB_CONNECTION_P(ZEND_THIS);
    if (!duckdb_connection_guard(intern->inner)) {
        RETURN_THROWS();
    }

    duckdb_prepared_statement ps = nullptr;
    duckdb_result res = {};
    {
        std::lock_guard<std::mutex> lk(intern->inner->mutex);
        if (duckdb_prepare(intern->inner->conn, sql, &ps) == DuckDBError) {
            const char *err = ps ? duckdb_prepare_error(ps) : nullptr;
            std::string msg = err ? err : "Failed to prepare statement";
            if (ps) {
                duckdb_destroy_prepare(&ps);
            }
            duckdb_throw_prepare_error(msg.c_str());
            RETURN_THROWS();
        }
        if (params && !duckdb_bind_params_array(ps, params)) {
            duckdb_destroy_prepare(&ps);
            RETURN_THROWS();
        }
        if (duckdb_execute_prepared(ps, &res) == DuckDBError) {
            duckdb_destroy_prepare(&ps);
            duckdb_throw_result_error(&res);
            RETURN_THROWS();
        }
        intern->inner->execution_epoch.fetch_add(1, std::memory_order_relaxed);
        duckdb_destroy_prepare(&ps);
    }

    duckdb_result_instantiate(return_value, &res, /*streaming=*/false, nullptr);
}

PHP_METHOD(DuckDB_Connection, prepare) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    char *sql;
    size_t sql_len;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(sql, sql_len)
    ZEND_PARSE_PARAMETERS_END();

    if (!duckdb_check_no_nul(sql, sql_len, 1)) {
        RETURN_THROWS();
    }

    php_duckdb_connection_object *intern = Z_DUCKDB_CONNECTION_P(ZEND_THIS);
    if (!duckdb_connection_guard(intern->inner)) {
        RETURN_THROWS();
    }

    auto inner = std::make_shared<stmt_inner>();
    inner->conn = intern->inner;
    {
        std::lock_guard<std::mutex> lk(intern->inner->mutex);
        if (duckdb_prepare(intern->inner->conn, sql, &inner->stmt) == DuckDBError) {
            const char *err = inner->stmt ? duckdb_prepare_error(inner->stmt) : nullptr;
            duckdb_throw_prepare_error(err ? err : "Failed to prepare statement");
            RETURN_THROWS();
        }
    }

    object_init_ex(return_value, duckdb_statement_ce);
    php_duckdb_statement_object *s = Z_DUCKDB_STATEMENT_P(return_value);
    s->inner = inner;
}

PHP_METHOD(DuckDB_Connection, appender) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    char *table;
    size_t table_len;
    char *schema = nullptr;
    size_t schema_len = 0;
    char *catalog = nullptr;
    size_t catalog_len = 0;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STRING(table, table_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING_OR_NULL(schema, schema_len)
        Z_PARAM_STRING_OR_NULL(catalog, catalog_len)
    ZEND_PARSE_PARAMETERS_END();

    if (!duckdb_check_no_nul(table, table_len, 1) ||
        (schema && !duckdb_check_no_nul(schema, schema_len, 2)) ||
        (catalog && !duckdb_check_no_nul(catalog, catalog_len, 3))) {
        RETURN_THROWS();
    }

    php_duckdb_connection_object *intern = Z_DUCKDB_CONNECTION_P(ZEND_THIS);
    if (!duckdb_connection_guard(intern->inner)) {
        RETURN_THROWS();
    }

    auto inner = std::make_shared<appender_inner>();
    inner->conn = intern->inner;
    {
        std::lock_guard<std::mutex> lk(intern->inner->mutex);
        /* Appender operations go through the connection context and
         * invalidate any open streaming result on this connection. */
        intern->inner->execution_epoch.fetch_add(1, std::memory_order_relaxed);
        if (duckdb_appender_create_ext(intern->inner->conn, catalog, schema, table, &inner->appender) == DuckDBError) {
            std::string msg = "Failed to create appender";
            duckdb_error_type type = DUCKDB_ERROR_CATALOG;
            if (inner->appender) {
                duckdb_error_data error_data = duckdb_appender_error_data(inner->appender);
                if (error_data) {
                    if (duckdb_error_data_has_error(error_data)) {
                        const char *err = duckdb_error_data_message(error_data);
                        if (err) {
                            msg = err;
                        }
                        type = duckdb_error_data_error_type(error_data);
                    }
                    duckdb_destroy_error_data(&error_data);
                }
                duckdb_appender_destroy(&inner->appender);
            }
            duckdb_throw_error(type, msg.c_str());
            RETURN_THROWS();
        }
    }

    object_init_ex(return_value, duckdb_appender_ce);
    php_duckdb_appender_object *a = Z_DUCKDB_APPENDER_P(return_value);
    a->inner = inner;
}

PHP_METHOD(DuckDB_Connection, interrupt) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_connection_object *intern = Z_DUCKDB_CONNECTION_P(ZEND_THIS);
    if (!duckdb_connection_guard(intern->inner)) {
        RETURN_THROWS();
    }
    duckdb_interrupt(intern->inner->conn);
}

PHP_METHOD(DuckDB_Connection, close) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_connection_object *intern = Z_DUCKDB_CONNECTION_P(ZEND_THIS);
    /* Flag-only: the underlying duckdb_disconnect() is deferred to the
     * destructor so in-flight async queries and live streaming results
     * finish safely. Idempotent by construction. */
    intern->inner->closed.store(true, std::memory_order_release);
}

PHP_METHOD(DuckDB_Connection, isClosed) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_connection_object *intern = Z_DUCKDB_CONNECTION_P(ZEND_THIS);
    RETURN_BOOL(intern->inner->closed.load(std::memory_order_acquire));
}

PHP_METHOD(DuckDB_Connection, queryProgress) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_connection_object *intern = Z_DUCKDB_CONNECTION_P(ZEND_THIS);
    if (!duckdb_connection_guard(intern->inner)) {
        RETURN_THROWS();
    }
    /* duckdb_query_progress is documented as safe to call concurrently
     * with a running query on the connection; no mutex needed. */
    duckdb_query_progress_type progress = duckdb_query_progress(intern->inner->conn);

    array_init_size(return_value, 3);
    add_assoc_double(return_value, "percentage", progress.percentage);
    add_assoc_long(return_value, "rowsProcessed", (zend_long)progress.rows_processed);
    add_assoc_long(return_value, "totalRowsToProcess", (zend_long)progress.total_rows_to_process);
}

PHP_METHOD(DuckDB_Connection, getTableNames) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    char *sql;
    size_t sql_len;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(sql, sql_len)
    ZEND_PARSE_PARAMETERS_END();

    if (!duckdb_check_no_nul(sql, sql_len, 1)) {
        RETURN_THROWS();
    }

    php_duckdb_connection_object *intern = Z_DUCKDB_CONNECTION_P(ZEND_THIS);
    if (!duckdb_connection_guard(intern->inner)) {
        RETURN_THROWS();
    }

    array_init(return_value);

    scoped_duckdb_value names;
    {
        std::lock_guard<std::mutex> lk(intern->inner->mutex);
        names.reset(duckdb_get_table_names(intern->inner->conn, sql, /*qualified=*/false));
    }
    if (!names) {
        /* nullptr means the statement could not be parsed or bound. */
        zend_throw_exception_ex(duckdb_parser_exception_ce, DUCKDB_ERROR_PARSER,
                                "Failed to extract table names: the statement could not be parsed or bound");
        RETURN_THROWS();
    }

    idx_t count = duckdb_get_list_size(names.get());
    for (idx_t i = 0; i < count; i++) {
        scoped_duckdb_value child(duckdb_get_list_child(names.get(), i));
        if (child) {
            char *name = duckdb_get_varchar(child.get());
            if (name) {
                add_next_index_string(return_value, name);
                duckdb_free(name);
            }
        }
    }
}

/* PDO-style transaction control. Thin wrappers over the corresponding SQL
 * statements so errors (e.g. COMMIT without an active transaction) flow
 * through the normal typed-error classification. Transaction state itself is
 * deliberately not mirrored client-side: DuckDB is the single source of
 * truth, so the driver can never lie about it. */
static void duckdb_connection_exec_simple(INTERNAL_FUNCTION_PARAMETERS, const char *sql) {
    php_duckdb_connection_object *intern = Z_DUCKDB_CONNECTION_P(ZEND_THIS);
    if (!duckdb_connection_guard(intern->inner)) {
        RETURN_THROWS();
    }

    duckdb_result res = {};
    {
        std::lock_guard<std::mutex> lk(intern->inner->mutex);
        if (duckdb_query(intern->inner->conn, sql, &res) == DuckDBError) {
            duckdb_throw_result_error(&res);
            RETURN_THROWS();
        }
    }
    duckdb_destroy_result(&res);
}

PHP_METHOD(DuckDB_Connection, beginTransaction) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    duckdb_connection_exec_simple(INTERNAL_FUNCTION_PARAM_PASSTHRU, "BEGIN TRANSACTION");
}

PHP_METHOD(DuckDB_Connection, commit) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    duckdb_connection_exec_simple(INTERNAL_FUNCTION_PARAM_PASSTHRU, "COMMIT");
}

PHP_METHOD(DuckDB_Connection, rollBack) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    duckdb_connection_exec_simple(INTERNAL_FUNCTION_PARAM_PASSTHRU, "ROLLBACK");
}

/* ================================================================== */
/* Module functions                                                   */
/* ================================================================== */

PHP_FUNCTION(DuckDB_version) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_STRING(duckdb_library_version());
}

/* Global alias kept for backwards compatibility with pre-1.0 releases. */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_duckdb_version_global, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry duckdb_legacy_functions[] = {
    ZEND_FALIAS(duckdb_version, DuckDB_version, arginfo_duckdb_version_global)
    PHP_FE_END
};

/* ================================================================== */
/* Module lifecycle                                                   */
/* ================================================================== */

/* These objects own C++ resources (shared_ptr invariants) that only the
 * constructor paths establish. Block (un)serialization like the engine
 * does for Fiber: without this, unserialize('O:...:{}') would create an
 * object with an empty shared_ptr and the next method call would crash. */
static int duckdb_serialize_deny(zval *object, unsigned char **buffer,
                                 size_t *buf_len, zend_serialize_data *data) {
    zend_throw_exception_ex(zend_ce_exception, 0, "Serialization of '%s' is not allowed",
                            ZSTR_VAL(Z_OBJCE_P(object)->name));
    return FAILURE;
}

static int duckdb_unserialize_deny(zval *object, zend_class_entry *ce, const unsigned char *buf,
                                   size_t buf_len, zend_unserialize_data *data) {
    zend_throw_error(NULL, "Unserialization of '%s' is not allowed", ZSTR_VAL(ce->name));
    return FAILURE;
}

#define DUCKDB_REGISTER_CLASS(name, registrar, ...)                                        \
    do {                                                                                   \
        duckdb_##name##_ce = registrar(__VA_ARGS__);                                       \
        duckdb_##name##_ce->create_object = duckdb_##name##_create_object;                 \
        duckdb_##name##_ce->ce_flags |= ZEND_ACC_NO_DYNAMIC_PROPERTIES;                    \
        duckdb_##name##_ce->serialize = duckdb_serialize_deny;                             \
        duckdb_##name##_ce->unserialize = duckdb_unserialize_deny;                         \
        memcpy(&duckdb_##name##_handlers, &std_object_handlers, sizeof(zend_object_handlers)); \
        duckdb_##name##_handlers.offset = offsetof(php_duckdb_##name##_object, std);     \
        duckdb_##name##_handlers.free_obj = duckdb_##name##_free_object;                   \
        duckdb_##name##_handlers.clone_obj = NULL;                                         \
    } while (0)

PHP_MINIT_FUNCTION(duckdb) {
    duckdb_fetch_mode_ce = register_class_DuckDB_FetchMode();
    duckdb_error_type_ce = register_class_DuckDB_ErrorType();

    duckdb_exception_ce = register_class_DuckDB_Exception(zend_ce_exception);
    duckdb_connection_exception_ce = register_class_DuckDB_ConnectionException(duckdb_exception_ce);
    duckdb_parser_exception_ce = register_class_DuckDB_ParserException(duckdb_exception_ce);
    duckdb_binder_exception_ce = register_class_DuckDB_BinderException(duckdb_exception_ce);
    duckdb_catalog_exception_ce = register_class_DuckDB_CatalogException(duckdb_exception_ce);
    duckdb_constraint_exception_ce = register_class_DuckDB_ConstraintException(duckdb_exception_ce);
    duckdb_transaction_exception_ce = register_class_DuckDB_TransactionException(duckdb_exception_ce);
    duckdb_conversion_exception_ce = register_class_DuckDB_ConversionException(duckdb_exception_ce);
    duckdb_io_exception_ce = register_class_DuckDB_IOException(duckdb_exception_ce);
    duckdb_interrupted_exception_ce = register_class_DuckDB_InterruptedException(duckdb_exception_ce);
    duckdb_internal_exception_ce = register_class_DuckDB_InternalException(duckdb_exception_ce);

    DUCKDB_REGISTER_CLASS(interval, register_class_DuckDB_Interval, php_json_serializable_ce);
    DUCKDB_REGISTER_CLASS(database, register_class_DuckDB_Database);
    DUCKDB_REGISTER_CLASS(connection, register_class_DuckDB_Connection);
    DUCKDB_REGISTER_CLASS(statement, register_class_DuckDB_Statement);
    DUCKDB_REGISTER_CLASS(result, register_class_DuckDB_Result, zend_ce_aggregate);
    DUCKDB_REGISTER_CLASS(result_iterator, register_class_DuckDB_ResultIterator, zend_ce_iterator);
    DUCKDB_REGISTER_CLASS(pending, register_class_DuckDB_PendingQuery);
    DUCKDB_REGISTER_CLASS(appender, register_class_DuckDB_Appender);

    /* Registered manually (not via ext_functions in duckdb_arginfo.h)
     * because gen_stub.php cannot emit a cross-namespace alias. Persistent
     * teardown belongs to Zend: do NOT unregister these in MSHUTDOWN — see
     * the comment in PHP_MSHUTDOWN_FUNCTION(duckdb). */
    zend_register_functions(NULL, duckdb_legacy_functions, NULL, MODULE_PERSISTENT);

    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(duckdb) {
    /* No function teardown here. The legacy alias registered in MINIT is
     * MODULE_PERSISTENT, and persistent functions are owned by
     * CG(function_table): Zend destroys them wholesale in zend_shutdown().
     * php-src itself only unregisters module functions for MODULE_TEMPORARY
     * modules (module_destructor() in zend_API.c). Manually unregistering a
     * persistent function double-frees its zend_internal_function on
     * runtimes whose alias handling keeps the entry reachable after
     * zend_hash_del() — the True Async PHP 8.6 fork SIGSEGVed in
     * zend_function_dtor() during zend_shutdown() (Valgrind: the block was
     * freed by zend_unregister_functions() here, then freed again by
     * zend_hash_destroy() at engine shutdown). */
    return SUCCESS;
}

PHP_MINFO_FUNCTION(duckdb) {
    php_info_print_table_start();
    php_info_print_table_row(2, "duckdb support", "enabled");
    php_info_print_table_row(2, "extension version", PHP_DUCKDB_VERSION);
    php_info_print_table_row(2, "duckdb library", duckdb_library_version());
    php_info_print_table_end();
}

zend_module_entry duckdb_module_entry = {
    STANDARD_MODULE_HEADER,
    "duckdb",
    ext_functions,
    PHP_MINIT(duckdb),
    PHP_MSHUTDOWN(duckdb),
    NULL,
    NULL,
    PHP_MINFO(duckdb),
    PHP_DUCKDB_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_DUCKDB
#ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#endif
ZEND_GET_MODULE(duckdb)
#endif
