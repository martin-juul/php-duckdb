/* Native, discoverable constructors over the shared typed-value storage.
 * All resolution and coercion remains in typed_value.cpp. Constructors only
 * assemble declarations and snapshot inputs; they never open a connection. */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include "php_duckdb_cxx_compat.h"
#include "php_duckdb.h"
#include "type_classes.h"

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

namespace {
std::string sql_quote(const char *data, size_t length, char delimiter = '"') {
    std::string result(1, delimiter);
    for (size_t i = 0; i < length; i++) {
        result += data[i];
        if (data[i] == delimiter) {
            result += delimiter;
        }
    }
    result += delimiter;
    return result;
}

bool type_spec(zval *spec, std::string &type) {
    ZVAL_DEREF(spec);
    if (Z_TYPE_P(spec) == IS_STRING) {
        const char *name = Z_STRVAL_P(spec);
        size_t length = Z_STRLEN_P(spec);
        if (length && *name == '\\') {
            name++;
            length--;
        }
#define MATCH_SCALAR(Class, Sql)                                                                             \
    if (zend_binary_strcasecmp(name, length, "DuckDB\\" #Class, sizeof("DuckDB\\" #Class) - 1) == 0) {       \
        type = Sql;                                                                                          \
        return true;                                                                                         \
    }
        DUCKDB_SCALAR_VALUE_CLASSES(MATCH_SCALAR)
#undef MATCH_SCALAR
        return duckdb_canonicalize_type(std::string(Z_STRVAL_P(spec), Z_STRLEN_P(spec)), type);
    }
    if (Z_TYPE_P(spec) == IS_OBJECT && instanceof_function(Z_OBJCE_P(spec), duckdb_value_class_entry())) {
        zval result;
        ZVAL_UNDEF(&result);
        zend_call_method_with_0_params(Z_OBJ_P(spec), duckdb_value_class_entry(), nullptr, "gettype",
                                       &result);
        if (EG(exception)) {
            zval_ptr_dtor(&result);
            return false;
        }
        type.assign(Z_STRVAL(result), Z_STRLEN(result));
        zval_ptr_dtor(&result);
        return true;
    }
    zend_type_error("A type specification must be a SQL string, scalar value class name, or DuckDB\\Value");
    return false;
}

bool field_type(const char *kind, HashTable *fields, std::string &type) {
    if (zend_hash_num_elements(fields) == 0) {
        zend_value_error("%s requires at least one declared field", kind);
        return false;
    }
    type = std::string(kind) + "(";
    zend_string *key;
    zend_ulong index;
    zval *spec;
    bool first = true;
    ZEND_HASH_FOREACH_KEY_VAL(fields, index, key, spec) {
        std::string child;
        if (!type_spec(spec, child)) {
            return false;
        }
        std::string name = key ? std::string(ZSTR_VAL(key), ZSTR_LEN(key)) : std::to_string(index);
        if (name.empty()) {
            zend_value_error("%s field names must not be empty", kind);
            return false;
        }
        if (!first) {
            type += ", ";
        }
        first = false;
        type += sql_quote(name.data(), name.size()) + " " + child;
    }
    ZEND_HASH_FOREACH_END();
    type += ')';
    return true;
}
} // namespace

#define SCALAR_CONSTRUCTOR(Class, Sql)                                                                       \
    PHP_METHOD(DuckDB_##Class, __construct) {                                                                \
        DUCKDB_TSRMLS_CACHE_UPDATE();                                                                        \
        zval *value;                                                                                         \
        ZEND_PARSE_PARAMETERS_START(1, 1)                                                                    \
            Z_PARAM_ZVAL(value)                                                                              \
        ZEND_PARSE_PARAMETERS_END();                                                                         \
        if (!duckdb_value_initialize(ZEND_THIS, Sql, value)) {                                               \
            RETURN_THROWS();                                                                                 \
        }                                                                                                    \
    }
DUCKDB_SCALAR_VALUE_CLASSES(SCALAR_CONSTRUCTOR)
#undef SCALAR_CONSTRUCTOR

PHP_METHOD(DuckDB_Decimal, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *value;
    zend_long precision = 18, scale = 3;

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_ZVAL(value)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(precision)
        Z_PARAM_LONG(scale)
    ZEND_PARSE_PARAMETERS_END();

    if (precision < 1 || precision > 38 || scale < 0 || scale > precision) {
        zend_value_error("Decimal requires precision between 1 and 38 and scale between 0 and precision");
        RETURN_THROWS();
    }
    if (!duckdb_value_initialize(
            ZEND_THIS, "DECIMAL(" + std::to_string(precision) + "," + std::to_string(scale) + ")", value)) {
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Enum, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *value;
    HashTable *labels;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ZVAL(value)
        Z_PARAM_ARRAY_HT(labels)
    ZEND_PARSE_PARAMETERS_END();

    if (!zend_array_is_list(labels) || zend_hash_num_elements(labels) == 0) {
        zend_value_error("Enum labels must be a non-empty sequential array of strings");
        RETURN_THROWS();
    }
    std::string type = "ENUM(";
    zval *label;
    bool first = true;
    ZEND_HASH_FOREACH_VAL(labels, label) {
        ZVAL_DEREF(label);
        if (Z_TYPE_P(label) != IS_STRING) {
            zend_type_error("Enum labels must be strings");
            RETURN_THROWS();
        }
        if (!first) {
            type += ", ";
        }
        first = false;
        type += sql_quote(Z_STRVAL_P(label), Z_STRLEN_P(label), '\'');
    }
    ZEND_HASH_FOREACH_END();
    type += ')';
    if (!duckdb_value_initialize(ZEND_THIS, type, value)) {
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_ListValue, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *value, *element;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ZVAL(value)
        Z_PARAM_ZVAL(element)
    ZEND_PARSE_PARAMETERS_END();

    std::string type;
    if (!type_spec(element, type) || !duckdb_value_initialize(ZEND_THIS, type + "[]", value)) {
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_ArrayValue, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *value, *element;
    zend_long length;

    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_ZVAL(value)
        Z_PARAM_ZVAL(element)
        Z_PARAM_LONG(length)
    ZEND_PARSE_PARAMETERS_END();

    if (length < 1) {
        zend_value_error("ArrayValue length must be positive");
        RETURN_THROWS();
    }
    std::string type;
    if (!type_spec(element, type) ||
        !duckdb_value_initialize(ZEND_THIS, type + "[" + std::to_string(length) + "]", value)) {
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Struct, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *value;
    HashTable *fields;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ZVAL(value)
        Z_PARAM_ARRAY_HT(fields)
    ZEND_PARSE_PARAMETERS_END();

    std::string type;
    if (!field_type("STRUCT", fields, type) || !duckdb_value_initialize(ZEND_THIS, type, value)) {
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Map, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *value, *key, *item;

    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_ZVAL(value)
        Z_PARAM_ZVAL(key)
        Z_PARAM_ZVAL(item)
    ZEND_PARSE_PARAMETERS_END();

    std::string key_type, value_type;
    if (!type_spec(key, key_type) || !type_spec(item, value_type) ||
        !duckdb_value_initialize(ZEND_THIS, "MAP(" + key_type + ", " + value_type + ")", value)) {
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Union, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *value;
    zend_string *tag;
    HashTable *members;

    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_ZVAL(value)
        Z_PARAM_STR_OR_NULL(tag)
        Z_PARAM_ARRAY_HT(members)
    ZEND_PARSE_PARAMETERS_END();

    std::string type;
    if (!field_type("UNION", members, type)) {
        RETURN_THROWS();
    }
    if (!tag) {
        if (Z_TYPE_P(value) != IS_NULL) {
            zend_value_error("A null Union tag requires a null value");
            RETURN_THROWS();
        }
        if (!duckdb_value_initialize(ZEND_THIS, type, value)) {
            RETURN_THROWS();
        }
        return;
    }
    if (!zend_symtable_exists(members, tag)) {
        zend_value_error("Unknown Union tag");
        RETURN_THROWS();
    }
    zval tagged, copy;
    array_init_size(&tagged, 2);
    add_assoc_str(&tagged, "tag", zend_string_copy(tag));
    ZVAL_COPY_DEREF(&copy, value);
    add_assoc_zval(&tagged, "value", &copy);
    bool success = duckdb_value_initialize(ZEND_THIS, type, &tagged);
    zval_ptr_dtor(&tagged);
    if (!success) {
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Geometry, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *value;
    zend_string *crs = nullptr;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(value)
        Z_PARAM_OPTIONAL
        Z_PARAM_STR_OR_NULL(crs)
    ZEND_PARSE_PARAMETERS_END();

    std::string type = "GEOMETRY";
    if (crs) {
        type += "(" + sql_quote(ZSTR_VAL(crs), ZSTR_LEN(crs), '\'') + ")";
    }
    if (!duckdb_value_initialize(ZEND_THIS, type, value)) {
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_CatalogValue, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *value;
    zend_string *name, *schema = nullptr, *catalog = nullptr;

    ZEND_PARSE_PARAMETERS_START(2, 4)
        Z_PARAM_ZVAL(value)
        Z_PARAM_STR(name)
        Z_PARAM_OPTIONAL
        Z_PARAM_STR_OR_NULL(schema)
        Z_PARAM_STR_OR_NULL(catalog)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(name) == 0 || (schema && ZSTR_LEN(schema) == 0) ||
        (catalog && (ZSTR_LEN(catalog) == 0 || !schema))) {
        zend_value_error(
            "CatalogValue requires non-empty identifiers and a schema when catalog is specified");
        RETURN_THROWS();
    }
    std::string type;
    if (catalog) {
        type += sql_quote(ZSTR_VAL(catalog), ZSTR_LEN(catalog)) + ".";
    }
    if (schema) {
        type += sql_quote(ZSTR_VAL(schema), ZSTR_LEN(schema)) + ".";
    }
    type += sql_quote(ZSTR_VAL(name), ZSTR_LEN(name));
    if (!duckdb_value_initialize(ZEND_THIS, type, value)) {
        RETURN_THROWS();
    }
}

bool duckdb_type_spec(zval *spec, std::string &type) {
    return type_spec(spec, type);
}
