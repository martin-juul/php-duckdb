/*
  +----------------------------------------------------------------------+
  | duckdb - native DuckDB driver for PHP                                |
  +----------------------------------------------------------------------+
  | Copyright (c) Martin Juul Christiansen (https://juul.xyz)            |
  +----------------------------------------------------------------------+
  | This source file is subject to the MIT license that is bundled with  |
  | this package in the file LICENSE.                                    |
  +----------------------------------------------------------------------+
  | DuckDB\Appender: fast row-by-row bulk inserts.                       |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"
#include "php_duckdb.h"

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

appender_inner::~appender_inner() {
    if (appender) {
        if (!closed) {
            /* Best-effort flush; there is no one left to report errors to. */
            duckdb_appender_close(appender);
        }
        duckdb_appender_destroy(&appender);
    }
}

/* Throw the error currently attached to an appender. */
static void duckdb_appender_throw(duckdb_appender appender, const char *fallback) {
    std::string msg = fallback;
    duckdb_error_type type = DUCKDB_ERROR_INVALID;
    duckdb_error_data error_data = duckdb_appender_error_data(appender);
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
    duckdb_throw_error(type, msg.c_str());
}

/* DuckDB cannot recover a partially written row: once an append operation
 * fails at the DuckDB level, the appender is invalid. Mark it closed (the
 * destructor will destroy it without flushing) and throw. */
static void duckdb_appender_fail(php_duckdb_appender_object *intern, const char *fallback) {
    intern->inner->closed = true;
    duckdb_appender_throw(intern->inner->appender, fallback);
}

/* Get the live appender handle or throw when it has been closed or its
 * connection is closed. */
static duckdb_appender duckdb_appender_get(INTERNAL_FUNCTION_PARAMETERS, php_duckdb_appender_object *intern) {
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Appender")) {
        return nullptr;
    }
    if (intern->inner->closed || intern->inner->appender == nullptr) {
        duckdb_throw_msg("Appender is closed");
        return nullptr;
    }
    if (!duckdb_connection_guard(intern->inner->conn)) {
        return nullptr;
    }
    return intern->inner->appender;
}

PHP_METHOD(DuckDB_Appender, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    zend_throw_error(NULL, "DuckDB\\Appender objects must be created via DuckDB\\Connection::appender()");
}

PHP_METHOD(DuckDB_Appender, beginRow) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_appender_object *intern = Z_DUCKDB_APPENDER_P(ZEND_THIS);
    duckdb_appender appender = duckdb_appender_get(INTERNAL_FUNCTION_PARAM_PASSTHRU, intern);
    if (appender == nullptr) {
        RETURN_THROWS();
    }
    if (intern->inner->row_open) {
        zend_throw_error(NULL, "DuckDB\\Appender: a row is already open (call endRow() first)");
        RETURN_THROWS();
    }

    std::lock_guard<std::mutex> lk(intern->inner->conn->mutex);
    if (duckdb_appender_begin_row(appender) == DuckDBError) {
        duckdb_appender_fail(intern, "Failed to begin appender row");
        RETURN_THROWS();
    }
    intern->inner->row_open = true;
}

PHP_METHOD(DuckDB_Appender, append) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *value;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(value)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_appender_object *intern = Z_DUCKDB_APPENDER_P(ZEND_THIS);
    duckdb_appender appender = duckdb_appender_get(INTERNAL_FUNCTION_PARAM_PASSTHRU, intern);
    if (appender == nullptr) {
        RETURN_THROWS();
    }
    if (!intern->inner->row_open) {
        zend_throw_error(NULL, "DuckDB\\Appender: no row is open (call beginRow() first)");
        RETURN_THROWS();
    }

    /* Convert before submitting this column, preserving an open row on failure. */
    std::lock_guard<std::mutex> lk(intern->inner->conn->mutex);
    std::vector<zval *> inputs{value};
    std::vector<scoped_duckdb_value> converted;
    if (!duckdb_convert_values(intern->inner->conn.get(), inputs, converted)) {
        RETURN_THROWS();
    }

    if (duckdb_append_value(appender, converted[0].get()) == DuckDBError) {
        duckdb_appender_fail(intern, "Failed to append value");
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Appender, appendDefault) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_appender_object *intern = Z_DUCKDB_APPENDER_P(ZEND_THIS);
    duckdb_appender appender = duckdb_appender_get(INTERNAL_FUNCTION_PARAM_PASSTHRU, intern);
    if (appender == nullptr) {
        RETURN_THROWS();
    }
    if (!intern->inner->row_open) {
        zend_throw_error(NULL, "DuckDB\\Appender: no row is open (call beginRow() first)");
        RETURN_THROWS();
    }

    std::lock_guard<std::mutex> lk(intern->inner->conn->mutex);
    if (duckdb_append_default(appender) == DuckDBError) {
        duckdb_appender_fail(intern, "Failed to append column default");
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Appender, endRow) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_appender_object *intern = Z_DUCKDB_APPENDER_P(ZEND_THIS);
    duckdb_appender appender = duckdb_appender_get(INTERNAL_FUNCTION_PARAM_PASSTHRU, intern);
    if (appender == nullptr) {
        RETURN_THROWS();
    }
    if (!intern->inner->row_open) {
        zend_throw_error(NULL, "DuckDB\\Appender: no row is open (call beginRow() first)");
        RETURN_THROWS();
    }

    std::lock_guard<std::mutex> lk(intern->inner->conn->mutex);
    intern->inner->row_open = false;
    if (duckdb_appender_end_row(appender) == DuckDBError) {
        duckdb_appender_fail(intern, "Failed to finish appender row "
                                     "(missing values for some columns?)");
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Appender, appendRow) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    HashTable *values;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY_HT(values)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_appender_object *intern = Z_DUCKDB_APPENDER_P(ZEND_THIS);
    duckdb_appender appender = duckdb_appender_get(INTERNAL_FUNCTION_PARAM_PASSTHRU, intern);
    if (appender == nullptr) {
        RETURN_THROWS();
    }
    if (intern->inner->row_open) {
        zend_throw_error(NULL, "DuckDB\\Appender: a row is already open (call endRow() first)");
        RETURN_THROWS();
    }

    idx_t expected = duckdb_appender_column_count(appender);
    if ((idx_t)zend_hash_num_elements(values) != expected) {
        zend_argument_value_error(1, "must contain exactly %d values (one per column), %d given",
                                  (int)expected, zend_hash_num_elements(values));
        RETURN_THROWS();
    }

    /* One batch conversion finishes before the native row is opened. */
    std::vector<zval *> inputs;
    zval *value;
    ZEND_HASH_FOREACH_VAL(values, value) { inputs.push_back(value); } ZEND_HASH_FOREACH_END();
    std::vector<scoped_duckdb_value> converted;
    std::lock_guard<std::mutex> lk(intern->inner->conn->mutex);
    if (!duckdb_convert_values(intern->inner->conn.get(), inputs, converted)) {
        RETURN_THROWS();
    }
    duckdb_state st = duckdb_appender_begin_row(appender);
    for (size_t i = 0; st == DuckDBSuccess && i < converted.size(); i++) {
        st = duckdb_append_value(appender, converted[i].get());
    }
    if (st == DuckDBSuccess) { st = duckdb_appender_end_row(appender); }
    if (st == DuckDBError) {
        duckdb_appender_fail(intern, "Failed to append row");
        RETURN_THROWS();
    }

}

PHP_METHOD(DuckDB_Appender, flush) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_appender_object *intern = Z_DUCKDB_APPENDER_P(ZEND_THIS);
    duckdb_appender appender = duckdb_appender_get(INTERNAL_FUNCTION_PARAM_PASSTHRU, intern);
    if (appender == nullptr) {
        RETURN_THROWS();
    }

    std::lock_guard<std::mutex> lk(intern->inner->conn->mutex);
    if (duckdb_appender_flush(appender) == DuckDBError) {
        duckdb_appender_fail(intern, "Failed to flush appender");
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Appender, close) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_appender_object *intern = Z_DUCKDB_APPENDER_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Appender")) {
        RETURN_THROWS();
    }
    if (intern->inner->closed) {
        return; /* idempotent */
    }
    intern->inner->closed = true;
    intern->inner->row_open = false;

    if (intern->inner->appender) {
        std::lock_guard<std::mutex> lk(intern->inner->conn->mutex);
        if (duckdb_appender_close(intern->inner->appender) == DuckDBError) {
            duckdb_appender_throw(intern->inner->appender, "Failed to close appender");
            RETURN_THROWS();
        }
    }
}
