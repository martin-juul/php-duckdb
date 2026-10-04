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
#include "data_chunk.h"
#include <algorithm>

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

appender_inner::~appender_inner() {
    if (appender) {
        std::lock_guard<std::mutex> lk(conn->mutex);

        /* Native destroy itself closes the handle. Clear failed buffers first
         * so destruction cannot retry an earlier partially submitted batch. */
        if (failed) {
            duckdb_appender_clear(appender);
        }
        if (!closed && !failed) {
            /* Best-effort flush; there is no one left to report errors to. */
            if (duckdb_appender_close(appender) == DuckDBError) {
                duckdb_appender_clear(appender);
            }
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

/* Preserve the failed handle for explicit clear(), but never flush or resume
 * it implicitly: some earlier rows may already have reached the table. */
static void duckdb_appender_fail(php_duckdb_appender_object *intern, const char *fallback) {
    intern->inner->failed = true;
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
    if (intern->inner->failed) {
        duckdb_throw_msg("Appender has failed; call clear() before reusing it");
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
    ZEND_HASH_FOREACH_VAL(values, value) {
        inputs.push_back(value);
    } ZEND_HASH_FOREACH_END();

    std::vector<scoped_duckdb_value> converted;
    std::lock_guard<std::mutex> lk(intern->inner->conn->mutex);
    if (!duckdb_convert_values(intern->inner->conn.get(), inputs, converted)) {
        RETURN_THROWS();
    }

    duckdb_state st = duckdb_appender_begin_row(appender);
    for (size_t i = 0; st == DuckDBSuccess && i < converted.size(); i++) {
        st = duckdb_append_value(appender, converted[i].get());
    }
    if (st == DuckDBSuccess) {
        st = duckdb_appender_end_row(appender);
    }
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

static void duckdb_appender_append_chunk(INTERNAL_FUNCTION_PARAMETERS, bool from_arrow) {
    zval *value;
    zend_class_entry *ce = from_arrow ? duckdb_arrow_chunk_class_entry() : duckdb_data_chunk_ce;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(value, ce)
    ZEND_PARSE_PARAMETERS_END();

    auto *intern = Z_DUCKDB_APPENDER_P(ZEND_THIS);
    duckdb_appender appender = duckdb_appender_get(INTERNAL_FUNCTION_PARAM_PASSTHRU, intern);
    if (!appender) {
        RETURN_THROWS();
    }
    if (intern->inner->row_open) {
        zend_throw_error(nullptr, "DuckDB\\Appender: a row is already open (call endRow() first)");
        RETURN_THROWS();
    }

    std::lock_guard<std::mutex> lock(intern->inner->conn->mutex);
    std::shared_ptr<data_chunk_data> data;
    if (from_arrow) {
        auto arrow = duckdb_arrow_chunk_from_zval(value);
        if (!arrow) {
            RETURN_THROWS();
        }
        data = duckdb_import_arrow_chunk(intern->inner->conn.get(), arrow);
    } else {
        data = duckdb_data_chunk_from_zval(value);
    }
    if (!data) {
        RETURN_THROWS();
    }
    idx_t size = duckdb_data_chunk_get_size(data->chunk);
    if (size <= duckdb_vector_size()) {
        if (duckdb_append_data_chunk(appender, data->chunk) == DuckDBError) {
            duckdb_appender_fail(intern, "Failed to append data chunk");
            RETURN_THROWS();
        }
        return;
    }

    /* Arrow batches need not respect DuckDB's vector size. The appender's
     * cast buffer does, so submit bounded chunks even for implicit casts. */
    std::vector<scoped_duckdb_logical_type> owned_types;
    std::vector<duckdb_logical_type> types;
    for (idx_t col = 0; col < data->names.size(); col++) {
        owned_types.emplace_back(duckdb_vector_get_column_type(duckdb_data_chunk_get_vector(data->chunk, col)));
        types.push_back(owned_types.back().get());
    }
    scoped_duckdb_chunk batch(duckdb_create_data_chunk(types.data(), types.size()));
    scoped_duckdb_selection selection(duckdb_create_selection_vector(duckdb_vector_size()));
    sel_t *indices = duckdb_selection_vector_get_data_ptr(selection.get());
    for (idx_t offset = 0; offset < size; offset += duckdb_vector_size()) {
        idx_t count = std::min<idx_t>(duckdb_vector_size(), size - offset);
        duckdb_data_chunk_reset(batch.get());
        for (idx_t row = 0; row < count; row++) {
            indices[row] = static_cast<sel_t>(offset + row);
        }
        for (idx_t col = 0; col < types.size(); col++) {
            duckdb_vector_copy_sel(duckdb_data_chunk_get_vector(data->chunk, col),
                                  duckdb_data_chunk_get_vector(batch.get(), col), selection.get(), count, 0, 0);
        }
        duckdb_data_chunk_set_size(batch.get(), count);
        if (duckdb_append_data_chunk(appender, batch.get()) == DuckDBError) {
            duckdb_appender_fail(intern, "Failed to append data chunk");
            RETURN_THROWS();
        }
    }
}

PHP_METHOD(DuckDB_Appender, appendChunk) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    duckdb_appender_append_chunk(INTERNAL_FUNCTION_PARAM_PASSTHRU, false);
}

PHP_METHOD(DuckDB_Appender, appendArrow) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    duckdb_appender_append_chunk(INTERNAL_FUNCTION_PARAM_PASSTHRU, true);
}

PHP_METHOD(DuckDB_Appender, clear) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();

    auto *intern = Z_DUCKDB_APPENDER_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Appender")) {
        RETURN_THROWS();
    }

    auto &inner = *intern->inner;
    if (inner.closed || !inner.appender) {
        duckdb_throw_msg("Appender is closed");
        RETURN_THROWS();
    }
    if (!duckdb_connection_guard(inner.conn)) {
        RETURN_THROWS();
    }

    std::lock_guard<std::mutex> lk(inner.conn->mutex);
    if (duckdb_appender_clear(inner.appender) == DuckDBError) {
        duckdb_appender_fail(intern, "Failed to clear appender");
        RETURN_THROWS();
    }

    inner.row_open = false;
    inner.failed = false;
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

    if (intern->inner->failed) {
        std::lock_guard<std::mutex> lk(intern->inner->conn->mutex);
        if (duckdb_appender_clear(intern->inner->appender) == DuckDBError) {
            duckdb_appender_throw(intern->inner->appender, "Failed to discard appender buffers");
            RETURN_THROWS();
        }
        return; /* clear buffers before native destroy can implicitly close */
    }

    if (intern->inner->appender) {
        std::lock_guard<std::mutex> lk(intern->inner->conn->mutex);
        if (duckdb_appender_close(intern->inner->appender) == DuckDBError) {
            intern->inner->failed = true;
            duckdb_appender_throw(intern->inner->appender, "Failed to close appender");
            RETURN_THROWS();
        }
    }
}
