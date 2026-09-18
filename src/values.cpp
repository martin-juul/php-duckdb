/*
  +----------------------------------------------------------------------+
  | duckdb - native DuckDB driver for PHP                                |
  +----------------------------------------------------------------------+
  | Copyright (c) Martin Juul Christiansen (https://juul.xyz)            |
  +----------------------------------------------------------------------+
  | This source file is subject to the MIT license that is bundled with  |
  | this package in the file LICENSE.                                    |
  +----------------------------------------------------------------------+
  | Exception mapping, DuckDB type names, the DuckDB\Interval value      |
  | object and the PHP value -> DuckDB value converter used by both      |
  | prepared statement binding and the appender.                         |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"

/* php_date.h predates C++ linkage guards; wrap it ourselves. */
extern "C" {
#include "ext/date/php_date.h"
}

#include "php_duckdb.h"

#include <cmath>

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

/* ================================================================== */
/* Errors                                                             */
/* ================================================================== */

static zend_class_entry *duckdb_exception_ce_for_type(duckdb_error_type type) {
    switch (type) {
        case DUCKDB_ERROR_CONNECTION:
            return duckdb_connection_exception_ce;
        case DUCKDB_ERROR_PARSER:
        case DUCKDB_ERROR_SYNTAX:
            return duckdb_parser_exception_ce;
        case DUCKDB_ERROR_BINDER:
        case DUCKDB_ERROR_PARAMETER_NOT_RESOLVED:
        case DUCKDB_ERROR_PARAMETER_NOT_ALLOWED:
            return duckdb_binder_exception_ce;
        case DUCKDB_ERROR_CATALOG:
        case DUCKDB_ERROR_DEPENDENCY:
            return duckdb_catalog_exception_ce;
        case DUCKDB_ERROR_CONSTRAINT:
            return duckdb_constraint_exception_ce;
        case DUCKDB_ERROR_TRANSACTION:
        case DUCKDB_ERROR_SEQUENCE:
            return duckdb_transaction_exception_ce;
        case DUCKDB_ERROR_CONVERSION:
        case DUCKDB_ERROR_OUT_OF_RANGE:
        case DUCKDB_ERROR_MISMATCH_TYPE:
        case DUCKDB_ERROR_DIVIDE_BY_ZERO:
        case DUCKDB_ERROR_DECIMAL:
        case DUCKDB_ERROR_INVALID_TYPE:
        case DUCKDB_ERROR_UNKNOWN_TYPE:
            return duckdb_conversion_exception_ce;
        case DUCKDB_ERROR_IO:
        case DUCKDB_ERROR_NETWORK:
        case DUCKDB_ERROR_HTTP:
        case DUCKDB_ERROR_PERMISSION:
        case DUCKDB_ERROR_MISSING_EXTENSION:
        case DUCKDB_ERROR_AUTOLOAD:
            return duckdb_io_exception_ce;
        case DUCKDB_ERROR_INTERRUPT:
            return duckdb_interrupted_exception_ce;
        case DUCKDB_ERROR_FATAL:
        case DUCKDB_ERROR_INTERNAL:
        case DUCKDB_ERROR_NULL_POINTER:
        case DUCKDB_ERROR_SERIALIZATION:
            return duckdb_internal_exception_ce;
        default:
            return duckdb_exception_ce;
    }
}

void duckdb_throw_error(duckdb_error_type type, const char *msg) {
    zend_throw_exception(duckdb_exception_ce_for_type(type),
                         msg ? msg : "Unknown DuckDB error", (zend_long)type);
}

void duckdb_throw_msg(const char *msg) {
    zend_throw_exception(duckdb_exception_ce, msg ? msg : "Unknown DuckDB error", 0);
}

/* DuckDB error messages carry a "<Type> Error: " prefix (e.g. "Parser
 * Error: syntax error at ..."). The prepare and open APIs expose only
 * the message, so the category is recovered from that prefix. */
duckdb_error_type duckdb_classify_error_message(const char *msg) {
    if (msg == nullptr) {
        return DUCKDB_ERROR_INVALID;
    }
    struct {
        const char *prefix;
        duckdb_error_type type;
    } static const prefixes[] = {
        {"Parser Error:", DUCKDB_ERROR_PARSER},
        {"Syntax Error:", DUCKDB_ERROR_SYNTAX},
        {"Binder Error:", DUCKDB_ERROR_BINDER},
        {"Catalog Error:", DUCKDB_ERROR_CATALOG},
        {"Constraint Error:", DUCKDB_ERROR_CONSTRAINT},
        {"Transaction Error:", DUCKDB_ERROR_TRANSACTION},
        {"TransactionContext Error:", DUCKDB_ERROR_TRANSACTION},
        {"Sequence Error:", DUCKDB_ERROR_SEQUENCE},
        {"Conversion Error:", DUCKDB_ERROR_CONVERSION},
        {"Out of Range Error:", DUCKDB_ERROR_OUT_OF_RANGE},
        {"Type Mismatch Error:", DUCKDB_ERROR_MISMATCH_TYPE},
        {"Divide By Zero Error:", DUCKDB_ERROR_DIVIDE_BY_ZERO},
        {"Invalid Input Error:", DUCKDB_ERROR_INVALID_INPUT},
        {"Invalid Configuration Error:", DUCKDB_INVALID_CONFIGURATION},
        {"Not implemented Error:", DUCKDB_ERROR_NOT_IMPLEMENTED},
        {"IO Error:", DUCKDB_ERROR_IO},
        {"HTTP Error:", DUCKDB_ERROR_HTTP},
        {"Network Error:", DUCKDB_ERROR_NETWORK},
        {"Permission Error:", DUCKDB_ERROR_PERMISSION},
        {"Missing Extension Error:", DUCKDB_ERROR_MISSING_EXTENSION},
        {"Autoloading Error:", DUCKDB_ERROR_AUTOLOAD},
        {"Interrupted Error:", DUCKDB_ERROR_INTERRUPT},
        {"INTERNAL Error:", DUCKDB_ERROR_INTERNAL},
        {"FATAL Error:", DUCKDB_ERROR_FATAL},
        {"Invalid Error:", DUCKDB_ERROR_INVALID_INPUT},
    };
    for (const auto &entry : prefixes) {
        if (strncmp(msg, entry.prefix, strlen(entry.prefix)) == 0) {
            return entry.type;
        }
    }
    return DUCKDB_ERROR_INVALID;
}

void duckdb_throw_prepare_error(const char *msg) {
    duckdb_throw_error(duckdb_classify_error_message(msg), msg);
}

void duckdb_throw_result_error(duckdb_result *res) {
    const char *err = duckdb_result_error(res);
    duckdb_error_type type = duckdb_result_error_type(res);
    std::string msg = err ? err : "Query failed";
    duckdb_destroy_result(res);
    duckdb_throw_error(type, msg.c_str());
}

PHP_METHOD(DuckDB_Exception, getErrorType) {
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    zend_long code = Z_LVAL_P(zend_read_property(zend_ce_exception, Z_OBJ_P(ZEND_THIS), "code",
                                                 sizeof("code") - 1, /*silent=*/true, NULL));
    if (code < 0 || code > DUCKDB_INVALID_CONFIGURATION) {
        RETURN_NULL();
    }
    zend_object *case_obj = nullptr;
    if (zend_enum_get_case_by_value(&case_obj, duckdb_error_type_ce, code, NULL, /*try_from=*/true) == SUCCESS
        && case_obj != nullptr) {
        /* The case object is borrowed from the enum's case table; copy
         * (addref) it like zend_enum_fetch_case() does. */
        RETURN_OBJ_COPY(case_obj);
    }
    RETURN_NULL();
}

/* ================================================================== */
/* Type names                                                         */
/* ================================================================== */

const char *duckdb_type_name(duckdb_type type) {
    switch (type) {
        case DUCKDB_TYPE_BOOLEAN: return "BOOLEAN";
        case DUCKDB_TYPE_TINYINT: return "TINYINT";
        case DUCKDB_TYPE_SMALLINT: return "SMALLINT";
        case DUCKDB_TYPE_INTEGER: return "INTEGER";
        case DUCKDB_TYPE_BIGINT: return "BIGINT";
        case DUCKDB_TYPE_UTINYINT: return "UTINYINT";
        case DUCKDB_TYPE_USMALLINT: return "USMALLINT";
        case DUCKDB_TYPE_UINTEGER: return "UINTEGER";
        case DUCKDB_TYPE_UBIGINT: return "UBIGINT";
        case DUCKDB_TYPE_FLOAT: return "FLOAT";
        case DUCKDB_TYPE_DOUBLE: return "DOUBLE";
        case DUCKDB_TYPE_TIMESTAMP: return "TIMESTAMP";
        case DUCKDB_TYPE_DATE: return "DATE";
        case DUCKDB_TYPE_TIME: return "TIME";
        case DUCKDB_TYPE_INTERVAL: return "INTERVAL";
        case DUCKDB_TYPE_HUGEINT: return "HUGEINT";
        case DUCKDB_TYPE_UHUGEINT: return "UHUGEINT";
        case DUCKDB_TYPE_VARCHAR: return "VARCHAR";
        case DUCKDB_TYPE_BLOB: return "BLOB";
        case DUCKDB_TYPE_DECIMAL: return "DECIMAL";
        case DUCKDB_TYPE_TIMESTAMP_S: return "TIMESTAMP_S";
        case DUCKDB_TYPE_TIMESTAMP_MS: return "TIMESTAMP_MS";
        case DUCKDB_TYPE_TIMESTAMP_NS: return "TIMESTAMP_NS";
        case DUCKDB_TYPE_ENUM: return "ENUM";
        case DUCKDB_TYPE_LIST: return "LIST";
        case DUCKDB_TYPE_STRUCT: return "STRUCT";
        case DUCKDB_TYPE_MAP: return "MAP";
        case DUCKDB_TYPE_ARRAY: return "ARRAY";
        case DUCKDB_TYPE_UUID: return "UUID";
        case DUCKDB_TYPE_UNION: return "UNION";
        case DUCKDB_TYPE_BIT: return "BIT";
        case DUCKDB_TYPE_TIME_TZ: return "TIME_TZ";
        case DUCKDB_TYPE_TIMESTAMP_TZ: return "TIMESTAMP_TZ";
        case DUCKDB_TYPE_TIME_NS: return "TIME_NS";
        case DUCKDB_TYPE_GEOMETRY: return "GEOMETRY";
        case DUCKDB_TYPE_VARIANT: return "VARIANT";
        case DUCKDB_TYPE_SQLNULL: return "SQLNULL";
        default: return "UNKNOWN";
    }
}

/* Render a logical type the way DuckDB itself does (LogicalType::ToString):
 * DECIMAL(10,3), INTEGER[], INTEGER[3], STRUCT(a INTEGER, b VARCHAR),
 * MAP(VARCHAR, INTEGER), UNION(num INTEGER, txt VARCHAR), ENUM('sad', 'ok').
 * Falls back to the bare type name when a child cannot be resolved. */
std::string duckdb_logical_type_render(duckdb_logical_type type) {
    switch (duckdb_get_type_id(type)) {
        case DUCKDB_TYPE_DECIMAL:
            return "DECIMAL(" + std::to_string(duckdb_decimal_width(type)) + "," +
                   std::to_string(duckdb_decimal_scale(type)) + ")";
        case DUCKDB_TYPE_LIST: {
            scoped_duckdb_logical_type child(duckdb_list_type_child_type(type));
            return child ? duckdb_logical_type_render(child.get()) + "[]" : "LIST";
        }
        case DUCKDB_TYPE_ARRAY: {
            scoped_duckdb_logical_type child(duckdb_array_type_child_type(type));
            if (!child) {
                return "ARRAY";
            }
            return duckdb_logical_type_render(child.get()) + "[" +
                   std::to_string(duckdb_array_type_array_size(type)) + "]";
        }
        case DUCKDB_TYPE_MAP: {
            scoped_duckdb_logical_type key(duckdb_map_type_key_type(type));
            scoped_duckdb_logical_type value(duckdb_map_type_value_type(type));
            if (!key || !value) {
                return "MAP";
            }
            return "MAP(" + duckdb_logical_type_render(key.get()) + ", " +
                   duckdb_logical_type_render(value.get()) + ")";
        }
        case DUCKDB_TYPE_STRUCT: {
            std::string out = "STRUCT(";
            idx_t count = duckdb_struct_type_child_count(type);
            for (idx_t i = 0; i < count; i++) {
                if (i > 0) {
                    out += ", ";
                }
                char *name = duckdb_struct_type_child_name(type, i);
                if (name) {
                    out += name;
                    out += " ";
                    duckdb_free(name);
                }
                scoped_duckdb_logical_type child(duckdb_struct_type_child_type(type, i));
                out += child ? duckdb_logical_type_render(child.get()) : "?";
            }
            return out + ")";
        }
        case DUCKDB_TYPE_UNION: {
            std::string out = "UNION(";
            idx_t count = duckdb_union_type_member_count(type);
            for (idx_t i = 0; i < count; i++) {
                if (i > 0) {
                    out += ", ";
                }
                char *name = duckdb_union_type_member_name(type, i);
                if (name) {
                    out += name;
                    out += " ";
                    duckdb_free(name);
                }
                scoped_duckdb_logical_type member(duckdb_union_type_member_type(type, i));
                out += member ? duckdb_logical_type_render(member.get()) : "?";
            }
            return out + ")";
        }
        case DUCKDB_TYPE_ENUM: {
            std::string out = "ENUM(";
            uint32_t count = duckdb_enum_dictionary_size(type);
            for (uint32_t i = 0; i < count; i++) {
                if (i > 0) {
                    out += ", ";
                }
                char *value = duckdb_enum_dictionary_value(type, i);
                out += "'";
                if (value) {
                    out += value;
                    duckdb_free(value);
                }
                out += "'";
            }
            return out + ")";
        }
        default:
            return duckdb_type_name(duckdb_get_type_id(type));
    }
}

const char *duckdb_statement_type_name(duckdb_statement_type type) {
    switch (type) {
        case DUCKDB_STATEMENT_TYPE_SELECT: return "SELECT";
        case DUCKDB_STATEMENT_TYPE_INSERT: return "INSERT";
        case DUCKDB_STATEMENT_TYPE_UPDATE: return "UPDATE";
        case DUCKDB_STATEMENT_TYPE_EXPLAIN: return "EXPLAIN";
        case DUCKDB_STATEMENT_TYPE_DELETE: return "DELETE";
        case DUCKDB_STATEMENT_TYPE_PREPARE: return "PREPARE";
        case DUCKDB_STATEMENT_TYPE_CREATE: return "CREATE";
        case DUCKDB_STATEMENT_TYPE_EXECUTE: return "EXECUTE";
        case DUCKDB_STATEMENT_TYPE_ALTER: return "ALTER";
        case DUCKDB_STATEMENT_TYPE_TRANSACTION: return "TRANSACTION";
        case DUCKDB_STATEMENT_TYPE_COPY: return "COPY";
        case DUCKDB_STATEMENT_TYPE_ANALYZE: return "ANALYZE";
        case DUCKDB_STATEMENT_TYPE_VARIABLE_SET: return "VARIABLE_SET";
        case DUCKDB_STATEMENT_TYPE_CREATE_FUNC: return "CREATE_FUNC";
        case DUCKDB_STATEMENT_TYPE_DROP: return "DROP";
        case DUCKDB_STATEMENT_TYPE_EXPORT: return "EXPORT";
        case DUCKDB_STATEMENT_TYPE_PRAGMA: return "PRAGMA";
        case DUCKDB_STATEMENT_TYPE_VACUUM: return "VACUUM";
        case DUCKDB_STATEMENT_TYPE_CALL: return "CALL";
        case DUCKDB_STATEMENT_TYPE_SET: return "SET";
        case DUCKDB_STATEMENT_TYPE_LOAD: return "LOAD";
        case DUCKDB_STATEMENT_TYPE_RELATION: return "RELATION";
        case DUCKDB_STATEMENT_TYPE_EXTENSION: return "EXTENSION";
        case DUCKDB_STATEMENT_TYPE_LOGICAL_PLAN: return "LOGICAL_PLAN";
        case DUCKDB_STATEMENT_TYPE_ATTACH: return "ATTACH";
        case DUCKDB_STATEMENT_TYPE_DETACH: return "DETACH";
        case DUCKDB_STATEMENT_TYPE_MULTI: return "MULTI";
        case DUCKDB_STATEMENT_TYPE_COPY_DATABASE: return "COPY_DATABASE";
        case DUCKDB_STATEMENT_TYPE_UPDATE_EXTENSIONS: return "UPDATE_EXTENSIONS";
        case DUCKDB_STATEMENT_TYPE_MERGE_INTO: return "MERGE_INTO";
        default: return "INVALID";
    }
}

/* ================================================================== */
/* DuckDB\Interval                                                    */
/* ================================================================== */

void duckdb_interval_instantiate(zval *return_value, duckdb_interval interval) {
    object_init_ex(return_value, duckdb_interval_ce);
    php_duckdb_interval_object *intern = Z_DUCKDB_INTERVAL_P(return_value);
    intern->interval = interval;
}

PHP_METHOD(DuckDB_Interval, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long months = 0, days = 0, micros = 0;

    ZEND_PARSE_PARAMETERS_START(0, 3)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(months)
        Z_PARAM_LONG(days)
        Z_PARAM_LONG(micros)
    ZEND_PARSE_PARAMETERS_END();

    if (months < INT32_MIN || months > INT32_MAX) {
        zend_argument_value_error(1, "must be between %d and %d", INT32_MIN, INT32_MAX);
        RETURN_THROWS();
    }
    if (days < INT32_MIN || days > INT32_MAX) {
        zend_argument_value_error(2, "must be between %d and %d", INT32_MIN, INT32_MAX);
        RETURN_THROWS();
    }

    php_duckdb_interval_object *intern = Z_DUCKDB_INTERVAL_P(ZEND_THIS);
    intern->interval.months = (int32_t)months;
    intern->interval.days = (int32_t)days;
    intern->interval.micros = (int64_t)micros;
}

PHP_METHOD(DuckDB_Interval, getMonths) {
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG(Z_DUCKDB_INTERVAL_P(ZEND_THIS)->interval.months);
}

PHP_METHOD(DuckDB_Interval, getDays) {
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG(Z_DUCKDB_INTERVAL_P(ZEND_THIS)->interval.days);
}

PHP_METHOD(DuckDB_Interval, getMicros) {
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG((zend_long)Z_DUCKDB_INTERVAL_P(ZEND_THIS)->interval.micros);
}

PHP_METHOD(DuckDB_Interval, fromSeconds) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    double seconds;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_DOUBLE(seconds)
    ZEND_PARSE_PARAMETERS_END();

    if (!std::isfinite(seconds)) {
        zend_argument_value_error(1, "must be finite");
        RETURN_THROWS();
    }

    duckdb_interval interval = {0, 0, (int64_t)llround(seconds * 1000000.0)};
    duckdb_interval_instantiate(return_value, interval);
}

/* Append "N unit"/"N units" to `str`. */
static void duckdb_interval_append_unit(std::string &str, int64_t value, const char *unit, bool &first) {
    if (value == 0) {
        return;
    }
    if (!first) {
        str += ' ';
    }
    first = false;
    str += std::to_string(value);
    str += ' ';
    str += unit;
    if (value != 1 && value != -1) {
        str += 's';
    }
}

PHP_METHOD(DuckDB_Interval, __toString) {
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    duckdb_interval interval = Z_DUCKDB_INTERVAL_P(ZEND_THIS)->interval;

    std::string str;
    bool first = true;

    int32_t years = interval.months / 12;
    int32_t months = interval.months % 12;
    duckdb_interval_append_unit(str, years, "year", first);
    duckdb_interval_append_unit(str, months, "month", first);
    duckdb_interval_append_unit(str, interval.days, "day", first);

    if (interval.micros != 0) {
        if (!first) {
            str += ' ';
        }
        first = false;
        int64_t micros = interval.micros;
        if (micros < 0) {
            str += '-';
            micros = -micros;
        }
        int64_t hours = micros / 3600000000LL;
        int64_t minutes = (micros / 60000000LL) % 60;
        int64_t seconds = (micros / 1000000LL) % 60;
        int64_t fraction = micros % 1000000LL;
        char buf[40];
        if (fraction == 0) {
            snprintf(buf, sizeof(buf), "%02d:%02d:%02d", (int)hours, (int)minutes, (int)seconds);
        } else {
            snprintf(buf, sizeof(buf), "%02d:%02d:%02d.%06d",
                     (int)hours, (int)minutes, (int)seconds, (int)fraction);
        }
        str += buf;
    }

    if (first) {
        str = "00:00:00";
    }
    RETURN_STRING(str.c_str());
}

PHP_METHOD(DuckDB_Interval, jsonSerialize) {
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    duckdb_interval interval = Z_DUCKDB_INTERVAL_P(ZEND_THIS)->interval;
    array_init_size(return_value, 3);
    add_assoc_long(return_value, "months", interval.months);
    add_assoc_long(return_value, "days", interval.days);
    add_assoc_long(return_value, "micros", (zend_long)interval.micros);
}

/* ================================================================== */
/* PHP value -> DuckDB value conversion                               */
/* ================================================================== */

/* Infer the DuckDB logical type of a PHP value. Returns nullptr (and
 * throws) for unsupported values. */
static duckdb_logical_type duckdb_php_infer_logical_type(zval *value, uint32_t depth);

/* Depth-guarded conversion entry point; the public
 * duckdb_php_to_duckdb_value() wraps this with depth 0. */
static duckdb_value duckdb_php_to_duckdb_value_depth(zval *value, uint32_t depth);

/* Structural equality for logical types produced from PHP values. DuckDB
 * does not validate child types in duckdb_create_list_value() et al, so a
 * heterogeneous PHP array would build a corrupt value - we validate
 * instead. */
static bool duckdb_logical_types_equal(duckdb_logical_type a, duckdb_logical_type b) {
    duckdb_type id = duckdb_get_type_id(a);
    if (id != duckdb_get_type_id(b)) {
        return false;
    }
    switch (id) {
        case DUCKDB_TYPE_LIST: {
            scoped_duckdb_logical_type ca(duckdb_list_type_child_type(a));
            scoped_duckdb_logical_type cb(duckdb_list_type_child_type(b));
            return ca && cb && duckdb_logical_types_equal(ca.get(), cb.get());
        }
        case DUCKDB_TYPE_ARRAY: {
            if (duckdb_array_type_array_size(a) != duckdb_array_type_array_size(b)) {
                return false;
            }
            scoped_duckdb_logical_type ca(duckdb_array_type_child_type(a));
            scoped_duckdb_logical_type cb(duckdb_array_type_child_type(b));
            return ca && cb && duckdb_logical_types_equal(ca.get(), cb.get());
        }
        case DUCKDB_TYPE_STRUCT: {
            idx_t count = duckdb_struct_type_child_count(a);
            if (count != duckdb_struct_type_child_count(b)) {
                return false;
            }
            for (idx_t i = 0; i < count; i++) {
                char *name_a = duckdb_struct_type_child_name(a, i);
                char *name_b = duckdb_struct_type_child_name(b, i);
                bool names_equal = name_a && name_b && strcmp(name_a, name_b) == 0;
                if (name_a) {
                    duckdb_free(name_a);
                }
                if (name_b) {
                    duckdb_free(name_b);
                }
                if (!names_equal) {
                    return false;
                }
                scoped_duckdb_logical_type ca(duckdb_struct_type_child_type(a, i));
                scoped_duckdb_logical_type cb(duckdb_struct_type_child_type(b, i));
                if (!ca || !cb || !duckdb_logical_types_equal(ca.get(), cb.get())) {
                    return false;
                }
            }
            return true;
        }
        default:
            return true;
    }
}

static bool duckdb_php_zval_is_datetime(zval *value) {
    return Z_TYPE_P(value) == IS_OBJECT &&
           instanceof_function(Z_OBJCE_P(value), php_date_get_interface_ce());
}

static duckdb_value duckdb_php_datetime_to_value(zval *value) {
    timelib_time *time = Z_PHPDATE_P(value)->time;
    duckdb_timestamp ts;
    ts.micros = time->sse * 1000000 + time->us;
    return duckdb_create_timestamp(ts);
}

/* Convert an array with sequential keys 0..n-1 to a DuckDB LIST value. */
static duckdb_value duckdb_php_array_to_list_value(zval *value, uint32_t depth) {
    HashTable *ht = Z_ARRVAL_P(value);
    uint32_t count = zend_hash_num_elements(ht);

    if (count == 0) {
        zend_value_error("Cannot convert an empty array to a DuckDB LIST "
                         "(the element type cannot be inferred)");
        return nullptr;
    }

    /* Infer the child type from the first non-NULL element. */
    duckdb_logical_type child_type = nullptr;
    zval *elem;
    ZEND_HASH_FOREACH_VAL(ht, elem) {
        if (Z_TYPE_P(elem) != IS_NULL) {
            child_type = duckdb_php_infer_logical_type(elem, depth + 1);
            break;
        }
    } ZEND_HASH_FOREACH_END();

    if (child_type == nullptr) {
        if (!EG(exception)) {
            zend_value_error("Cannot convert an array of only NULL values to a DuckDB LIST "
                             "(the element type cannot be inferred)");
        }
        return nullptr;
    }

    duckdb_value *children = (duckdb_value *)safe_emalloc(count, sizeof(duckdb_value), 0);
    uint32_t i = 0;
    ZEND_HASH_FOREACH_VAL(ht, elem) {
        children[i] = duckdb_php_to_duckdb_value_depth(elem, depth + 1);
        if (children[i] == nullptr) {
            goto list_failure;
        }
        /* duckdb_create_list_value() does not validate its children; a
         * heterogeneous PHP array would build a corrupt value. Enforce a
         * uniform element type (NULLs adapt to it) instead. */
        if (Z_TYPE_P(elem) != IS_NULL) {
            /* NB: duckdb_get_value_type() borrows the value's internal
             * type -- it must NOT be destroyed. */
            duckdb_logical_type actual = duckdb_get_value_type(children[i]);
            if (!actual || !duckdb_logical_types_equal(child_type, actual)) {
                zend_value_error("Cannot convert array to a DuckDB LIST: element %u has a "
                                 "different type than the inferred element type %s",
                                 i, duckdb_type_name(duckdb_get_type_id(child_type)));
                goto list_failure;
            }
        }
        i++;
    } ZEND_HASH_FOREACH_END();

    {
        duckdb_value list = duckdb_create_list_value(child_type, children, count);
        for (uint32_t j = 0; j < count; j++) {
            duckdb_destroy_value(&children[j]);
        }
        efree(children);
        duckdb_destroy_logical_type(&child_type);
        if (list == nullptr) {
            zend_value_error("Failed to create a DuckDB LIST value");
            return nullptr;
        }
        return list;
    }

list_failure:
    for (uint32_t j = 0; j <= i; j++) {
        if (children[j]) {
            duckdb_destroy_value(&children[j]);
        }
    }
    efree(children);
    duckdb_destroy_logical_type(&child_type);
    return nullptr;
}

/* Convert an associative array to a DuckDB STRUCT value. */
static duckdb_value duckdb_php_array_to_struct_value(zval *value, uint32_t depth) {
    HashTable *ht = Z_ARRVAL_P(value);
    uint32_t count = zend_hash_num_elements(ht);

    duckdb_logical_type *member_types = (duckdb_logical_type *)safe_emalloc(count, sizeof(duckdb_logical_type), 0);
    const char **member_names = (const char **)safe_emalloc(count, sizeof(char *), 0);
    duckdb_value *children = (duckdb_value *)safe_emalloc(count, sizeof(duckdb_value), 0);
    zend_string **owned_names = (zend_string **)safe_emalloc(count, sizeof(zend_string *), 0);

    uint32_t done = 0; /* number of fully initialized elements */
    zend_string *key;
    zend_long num_key;
    zval *elem;

    ZEND_HASH_FOREACH_KEY_VAL(ht, num_key, key, elem) {
        owned_names[done] = key ? zend_string_copy(key) : zend_long_to_str(num_key);
        member_names[done] = ZSTR_VAL(owned_names[done]);
        member_types[done] = nullptr;
        children[done] = nullptr;

        /* NULL members fall back to VARCHAR; the value binds as NULL. */
        if (Z_TYPE_P(elem) == IS_NULL) {
            member_types[done] = duckdb_create_logical_type(DUCKDB_TYPE_VARCHAR);
        } else {
            member_types[done] = duckdb_php_infer_logical_type(elem, depth + 1);
        }
        if (member_types[done] != nullptr) {
            children[done] = duckdb_php_to_duckdb_value_depth(elem, depth + 1);
        }
        if (member_types[done] == nullptr || children[done] == nullptr) {
            goto failure;
        }
        done++;
    } ZEND_HASH_FOREACH_END();

    {
        duckdb_logical_type struct_type = duckdb_create_struct_type(member_types, member_names, count);
        duckdb_value result = struct_type ? duckdb_create_struct_value(struct_type, children) : nullptr;
        if (struct_type) {
            duckdb_destroy_logical_type(&struct_type);
        }

        for (uint32_t j = 0; j < count; j++) {
            zend_string_release(owned_names[j]);
            duckdb_destroy_logical_type(&member_types[j]);
            duckdb_destroy_value(&children[j]);
        }
        efree(member_types);
        efree(member_names);
        efree(children);
        efree(owned_names);
        if (result == nullptr) {
            zend_value_error("Failed to create a DuckDB STRUCT value");
            return nullptr;
        }
        return result;
    }

failure:
    for (uint32_t j = 0; j <= done; j++) {
        zend_string_release(owned_names[j]);
        if (member_types[j]) {
            duckdb_destroy_logical_type(&member_types[j]);
        }
        if (children[j]) {
            duckdb_destroy_value(&children[j]);
        }
    }
    efree(member_types);
    efree(member_names);
    efree(children);
    efree(owned_names);
    return nullptr;
}

static duckdb_logical_type duckdb_php_infer_logical_type(zval *value, uint32_t depth) {
    if (depth > DUCKDB_MAX_NESTING_DEPTH) {
        zend_value_error("Cannot convert a value nested deeper than %u levels to a DuckDB value",
                         DUCKDB_MAX_NESTING_DEPTH);
        return nullptr;
    }
    switch (Z_TYPE_P(value)) {
        case IS_TRUE:
        case IS_FALSE:
            return duckdb_create_logical_type(DUCKDB_TYPE_BOOLEAN);
        case IS_LONG:
            return duckdb_create_logical_type(DUCKDB_TYPE_BIGINT);
        case IS_DOUBLE:
            return duckdb_create_logical_type(DUCKDB_TYPE_DOUBLE);
        case IS_STRING:
            return duckdb_create_logical_type(DUCKDB_TYPE_VARCHAR);
        case IS_OBJECT:
            if (instanceof_function(Z_OBJCE_P(value), duckdb_interval_ce)) {
                return duckdb_create_logical_type(DUCKDB_TYPE_INTERVAL);
            }
            if (duckdb_php_zval_is_datetime(value)) {
                return duckdb_create_logical_type(DUCKDB_TYPE_TIMESTAMP);
            }
            return nullptr;
        case IS_ARRAY: {
            /* Nested lists/structs infer their full type recursively from
             * the first element, mirroring duckdb_php_array_to_*_value(). */
            HashTable *ht = Z_ARRVAL_P(value);
            if (zend_hash_num_elements(ht) == 0) {
                return nullptr;
            }
            if (zend_array_is_list(ht)) {
                zval *elem;
                ZEND_HASH_FOREACH_VAL(ht, elem) {
                    if (Z_TYPE_P(elem) != IS_NULL) {
                        duckdb_logical_type child = duckdb_php_infer_logical_type(elem, depth + 1);
                        if (child == nullptr) {
                            return nullptr;
                        }
                        duckdb_logical_type list = duckdb_create_list_type(child);
                        duckdb_destroy_logical_type(&child);
                        return list;
                    }
                } ZEND_HASH_FOREACH_END();
                return nullptr;
            }
            uint32_t count = zend_hash_num_elements(ht);
            duckdb_logical_type *member_types = (duckdb_logical_type *)safe_emalloc(count, sizeof(duckdb_logical_type), 0);
            const char **member_names = (const char **)safe_emalloc(count, sizeof(char *), 0);
            zend_string **owned_names = (zend_string **)safe_emalloc(count, sizeof(zend_string *), 0);
            uint32_t i = 0;
            zend_string *key;
            zend_long num_key;
            zval *elem;
            bool failed = false;
            ZEND_HASH_FOREACH_KEY_VAL(ht, num_key, key, elem) {
                owned_names[i] = key ? zend_string_copy(key) : zend_long_to_str(num_key);
                member_names[i] = ZSTR_VAL(owned_names[i]);
                if (Z_TYPE_P(elem) == IS_NULL) {
                    member_types[i] = duckdb_create_logical_type(DUCKDB_TYPE_VARCHAR);
                } else {
                    member_types[i] = duckdb_php_infer_logical_type(elem, depth + 1);
                    if (member_types[i] == nullptr) {
                        failed = true;
                        i++;
                        break;
                    }
                }
                i++;
            } ZEND_HASH_FOREACH_END();
            duckdb_logical_type result = nullptr;
            if (!failed) {
                result = duckdb_create_struct_type(member_types, member_names, count);
            }
            for (uint32_t j = 0; j < i; j++) {
                zend_string_release(owned_names[j]);
                if (member_types[j]) {
                    duckdb_destroy_logical_type(&member_types[j]);
                }
            }
            efree(member_types);
            efree(member_names);
            efree(owned_names);
            return result;
        }
        default:
            return nullptr;
    }
}

static duckdb_value duckdb_php_to_duckdb_value_depth(zval *value, uint32_t depth) {
    if (depth > DUCKDB_MAX_NESTING_DEPTH) {
        zend_value_error("Cannot convert a value nested deeper than %u levels to a DuckDB value",
                         DUCKDB_MAX_NESTING_DEPTH);
        return nullptr;
    }
    switch (Z_TYPE_P(value)) {
        case IS_NULL:
            return duckdb_create_null_value();
        case IS_TRUE:
            return duckdb_create_bool(true);
        case IS_FALSE:
            return duckdb_create_bool(false);
        case IS_LONG:
            return duckdb_create_int64((int64_t)Z_LVAL_P(value));
        case IS_DOUBLE:
            return duckdb_create_double(Z_DVAL_P(value));
        case IS_STRING:
            return duckdb_create_varchar_length(Z_STRVAL_P(value), Z_STRLEN_P(value));
        case IS_OBJECT:
            if (instanceof_function(Z_OBJCE_P(value), duckdb_interval_ce)) {
                return duckdb_create_interval(Z_DUCKDB_INTERVAL_P(value)->interval);
            }
            if (duckdb_php_zval_is_datetime(value)) {
                return duckdb_php_datetime_to_value(value);
            }
            zend_type_error("Cannot convert an object of class %s to a DuckDB value "
                            "(supported: DuckDB\\Interval, DateTimeInterface)",
                            ZSTR_VAL(Z_OBJCE_P(value)->name));
            return nullptr;
        case IS_ARRAY:
            if (zend_array_is_list(Z_ARRVAL_P(value))) {
                return duckdb_php_array_to_list_value(value, depth);
            }
            return duckdb_php_array_to_struct_value(value, depth);
        default:
            zend_type_error("Cannot convert a value of type %s to a DuckDB value",
                            zend_zval_type_name(value));
            return nullptr;
    }
}

duckdb_value duckdb_php_to_duckdb_value(zval *value) {
    return duckdb_php_to_duckdb_value_depth(value, 0);
}
