/* Standalone native vectors. A vector is created from a type resolved on a
 * connection and owns its memory afterwards. Writes convert PHP input with
 * the typed-value machinery, so their semantics match prepared binding. */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"
#include "php_duckdb.h"
#include "vector.h"

#include <algorithm>
#include <exception>
#include <limits>

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

zend_class_entry *duckdb_vector_ce;
static zend_object_handlers vector_handlers;

/* Selection vectors index rows with 32-bit entries. */
static constexpr idx_t max_vector_capacity = (std::numeric_limits<uint32_t>::max)();

/* Bound the size of one generated conversion statement. */
static constexpr size_t conversion_batch = 1024;

struct php_vector_object {
    std::shared_ptr<vector_data> data;
    zend_object std;
};

static php_vector_object *vector_object(zend_object *obj) {
    return reinterpret_cast<php_vector_object *>(reinterpret_cast<char *>(obj) - offsetof(php_vector_object, std));
}

static zend_object *vector_create(zend_class_entry *ce) {
    auto *obj = static_cast<php_vector_object *>(zend_object_alloc(sizeof(php_vector_object), ce));
    new (&obj->data) std::shared_ptr<vector_data>();
    zend_object_std_init(&obj->std, ce);
    object_properties_init(&obj->std, ce);
    obj->std.handlers = &vector_handlers;
    return &obj->std;
}

static void vector_free(zend_object *obj) {
    vector_object(obj)->data.~shared_ptr();
    zend_object_std_dtor(obj);
}

void duckdb_register_vector_class(zend_class_entry *ce) {
    duckdb_vector_ce = ce;
    ce->create_object = vector_create;
    ce->ce_flags |= ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;
    memcpy(&vector_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    vector_handlers.offset = offsetof(php_vector_object, std);
    vector_handlers.free_obj = vector_free;
    vector_handlers.clone_obj = nullptr;
}

std::shared_ptr<vector_data> duckdb_vector_from_zval(zval *value) {
    auto data = vector_object(Z_OBJ_P(value))->data;
    if (!duckdb_initialized_guard(static_cast<bool>(data), "DuckDB\\Vector")) {
        return nullptr;
    }
    return data;
}

void duckdb_vector_wrap(zval *return_value, std::shared_ptr<vector_data> data) {
    object_init_ex(return_value, duckdb_vector_ce);
    vector_object(Z_OBJ_P(return_value))->data = std::move(data);
}

static std::string take_string(char *value) {
    std::string result = value ? value : "";
    duckdb_free(value);
    return result;
}

/* Structural equality of the physical layout and every value-defining
 * parameter. Aliases and geometry CRS do not change the stored bytes. */
bool duckdb_vector_types_equal(duckdb_logical_type a, duckdb_logical_type b) {
    duckdb_type id = duckdb_get_type_id(a);
    if (id != duckdb_get_type_id(b)) {
        return false;
    }

    switch (id) {
        case DUCKDB_TYPE_DECIMAL:
            return duckdb_decimal_width(a) == duckdb_decimal_width(b) &&
                duckdb_decimal_scale(a) == duckdb_decimal_scale(b);
        case DUCKDB_TYPE_ENUM: {
            idx_t size = duckdb_enum_dictionary_size(a);
            if (size != duckdb_enum_dictionary_size(b)) {
                return false;
            }
            for (idx_t i = 0; i < size; i++) {
                if (take_string(duckdb_enum_dictionary_value(a, i)) != take_string(duckdb_enum_dictionary_value(b, i))) {
                    return false;
                }
            }
            return true;
        }
        case DUCKDB_TYPE_LIST: {
            scoped_duckdb_logical_type child_a(duckdb_list_type_child_type(a));
            scoped_duckdb_logical_type child_b(duckdb_list_type_child_type(b));
            return duckdb_vector_types_equal(child_a.get(), child_b.get());
        }
        case DUCKDB_TYPE_ARRAY: {
            if (duckdb_array_type_array_size(a) != duckdb_array_type_array_size(b)) {
                return false;
            }
            scoped_duckdb_logical_type child_a(duckdb_array_type_child_type(a));
            scoped_duckdb_logical_type child_b(duckdb_array_type_child_type(b));
            return duckdb_vector_types_equal(child_a.get(), child_b.get());
        }
        case DUCKDB_TYPE_MAP: {
            scoped_duckdb_logical_type key_a(duckdb_map_type_key_type(a));
            scoped_duckdb_logical_type key_b(duckdb_map_type_key_type(b));
            scoped_duckdb_logical_type value_a(duckdb_map_type_value_type(a));
            scoped_duckdb_logical_type value_b(duckdb_map_type_value_type(b));
            return duckdb_vector_types_equal(key_a.get(), key_b.get()) &&
                duckdb_vector_types_equal(value_a.get(), value_b.get());
        }
        case DUCKDB_TYPE_STRUCT:
        case DUCKDB_TYPE_UNION: {
            bool is_union = id == DUCKDB_TYPE_UNION;
            idx_t count = is_union ? duckdb_union_type_member_count(a) : duckdb_struct_type_child_count(a);
            idx_t other = is_union ? duckdb_union_type_member_count(b) : duckdb_struct_type_child_count(b);
            if (count != other) {
                return false;
            }
            for (idx_t i = 0; i < count; i++) {
                std::string name_a = take_string(is_union
                    ? duckdb_union_type_member_name(a, i) : duckdb_struct_type_child_name(a, i));
                std::string name_b = take_string(is_union
                    ? duckdb_union_type_member_name(b, i) : duckdb_struct_type_child_name(b, i));
                if (name_a != name_b) {
                    return false;
                }

                scoped_duckdb_logical_type child_a(is_union
                    ? duckdb_union_type_member_type(a, i) : duckdb_struct_type_child_type(a, i));
                scoped_duckdb_logical_type child_b(is_union
                    ? duckdb_union_type_member_type(b, i) : duckdb_struct_type_child_type(b, i));
                if (!duckdb_vector_types_equal(child_a.get(), child_b.get())) {
                    return false;
                }
            }
            return true;
        }
        default:
            return true;
    }
}

void duckdb_vector_copy_rows(duckdb_vector source, duckdb_vector target, idx_t source_offset, idx_t count,
                             idx_t target_offset) {
    if (count == 0) {
        return;
    }

    idx_t end = source_offset + count;
    scoped_duckdb_selection selection(duckdb_create_selection_vector(end));
    sel_t *indices = duckdb_selection_vector_get_data_ptr(selection.get());
    for (idx_t row = source_offset; row < end; row++) {
        indices[row] = static_cast<sel_t>(row);
    }

    duckdb_vector_copy_sel(source, target, selection.get(), end, source_offset, target_offset);
}

static vector_fast_kind fast_kind(duckdb_logical_type type) {
    char *alias = duckdb_logical_type_get_alias(type);
    if (alias) {
        duckdb_free(alias);
        return vector_fast_kind::none;
    }

    switch (duckdb_get_type_id(type)) {
        case DUCKDB_TYPE_BOOLEAN:
            return vector_fast_kind::boolean;
        case DUCKDB_TYPE_TINYINT:
            return vector_fast_kind::int8;
        case DUCKDB_TYPE_SMALLINT:
            return vector_fast_kind::int16;
        case DUCKDB_TYPE_INTEGER:
            return vector_fast_kind::int32;
        case DUCKDB_TYPE_BIGINT:
            return vector_fast_kind::int64;
        case DUCKDB_TYPE_UTINYINT:
            return vector_fast_kind::uint8;
        case DUCKDB_TYPE_USMALLINT:
            return vector_fast_kind::uint16;
        case DUCKDB_TYPE_UINTEGER:
            return vector_fast_kind::uint32;
        case DUCKDB_TYPE_UBIGINT:
            return vector_fast_kind::uint64;
        case DUCKDB_TYPE_DOUBLE:
            return vector_fast_kind::float64;
        case DUCKDB_TYPE_VARCHAR:
            return vector_fast_kind::varchar;
        case DUCKDB_TYPE_BLOB:
            return vector_fast_kind::blob;
        default:
            return vector_fast_kind::none;
    }
}

std::shared_ptr<vector_data> duckdb_vector_allocate(duckdb_logical_type type, idx_t capacity) {
    auto data = std::make_shared<vector_data>();
    data->vector = duckdb_create_vector(type, capacity);
    if (!data->vector) {
        duckdb_throw_msg("DuckDB could not allocate the vector");
        return nullptr;
    }

    /* The C API has no logical type copy; the vector returns an owned one. */
    data->type.reset(duckdb_vector_get_column_type(data->vector));
    data->declaration = duckdb_logical_type_sql(data->type.get());
    data->capacity = capacity;
    data->fast = fast_kind(data->type.get());
    duckdb_vector_ensure_validity_writable(data->vector);
    return data;
}

/* One PHP input, classified before any row is changed. */
struct pending_write {
    idx_t row;
    zval *input;
    bool is_null = false;
    bool is_fast = false;
    size_t converted = 0;
};

template <typename T>
static bool fits(zend_long value) {
    if (value < 0 && !std::numeric_limits<T>::is_signed) {
        return false;
    }
    if (std::numeric_limits<T>::is_signed) {
        return value >= static_cast<zend_long>((std::numeric_limits<T>::min)()) &&
            value <= static_cast<zend_long>((std::numeric_limits<T>::max)());
    }
    return static_cast<uint64_t>(value) <= static_cast<uint64_t>((std::numeric_limits<T>::max)());
}

static bool valid_utf8(const char *value, size_t length) {
    duckdb_error_data error = duckdb_valid_utf8_check(value, length);
    bool valid = !duckdb_error_data_has_error(error);
    duckdb_destroy_error_data(&error);
    return valid;
}

/* Accept only input whose SQL cast is an exact identity, so the result
 * matches the conversion path. Anything else, including values that would
 * fail, goes through typed conversion to report DuckDB's own error. */
static bool can_write_fast(vector_fast_kind kind, zval *input) {
    switch (kind) {
        case vector_fast_kind::boolean:
            return Z_TYPE_P(input) == IS_TRUE || Z_TYPE_P(input) == IS_FALSE;
        case vector_fast_kind::int8:
            return Z_TYPE_P(input) == IS_LONG && fits<int8_t>(Z_LVAL_P(input));
        case vector_fast_kind::int16:
            return Z_TYPE_P(input) == IS_LONG && fits<int16_t>(Z_LVAL_P(input));
        case vector_fast_kind::int32:
            return Z_TYPE_P(input) == IS_LONG && fits<int32_t>(Z_LVAL_P(input));
        case vector_fast_kind::int64:
            return Z_TYPE_P(input) == IS_LONG;
        case vector_fast_kind::uint8:
            return Z_TYPE_P(input) == IS_LONG && fits<uint8_t>(Z_LVAL_P(input));
        case vector_fast_kind::uint16:
            return Z_TYPE_P(input) == IS_LONG && fits<uint16_t>(Z_LVAL_P(input));
        case vector_fast_kind::uint32:
            return Z_TYPE_P(input) == IS_LONG && fits<uint32_t>(Z_LVAL_P(input));
        case vector_fast_kind::uint64:
            return Z_TYPE_P(input) == IS_LONG && Z_LVAL_P(input) >= 0;
        case vector_fast_kind::float64:
            return Z_TYPE_P(input) == IS_DOUBLE;
        case vector_fast_kind::varchar:
            return Z_TYPE_P(input) == IS_STRING &&
                memchr(Z_STRVAL_P(input), '\0', Z_STRLEN_P(input)) == nullptr &&
                valid_utf8(Z_STRVAL_P(input), Z_STRLEN_P(input));
        case vector_fast_kind::blob:
            return Z_TYPE_P(input) == IS_STRING;
        case vector_fast_kind::none:
            return false;
    }
    return false;
}

template <typename T>
static void store(duckdb_vector vector, idx_t row, T value) {
    static_cast<T *>(duckdb_vector_get_data(vector))[row] = value;
}

static void write_fast(vector_data *data, idx_t row, zval *input) {
    duckdb_vector vector = data->vector;
    switch (data->fast) {
        case vector_fast_kind::boolean:
            store<bool>(vector, row, Z_TYPE_P(input) == IS_TRUE);
            break;
        case vector_fast_kind::int8:
            store<int8_t>(vector, row, static_cast<int8_t>(Z_LVAL_P(input)));
            break;
        case vector_fast_kind::int16:
            store<int16_t>(vector, row, static_cast<int16_t>(Z_LVAL_P(input)));
            break;
        case vector_fast_kind::int32:
            store<int32_t>(vector, row, static_cast<int32_t>(Z_LVAL_P(input)));
            break;
        case vector_fast_kind::int64:
            store<int64_t>(vector, row, static_cast<int64_t>(Z_LVAL_P(input)));
            break;
        case vector_fast_kind::uint8:
            store<uint8_t>(vector, row, static_cast<uint8_t>(Z_LVAL_P(input)));
            break;
        case vector_fast_kind::uint16:
            store<uint16_t>(vector, row, static_cast<uint16_t>(Z_LVAL_P(input)));
            break;
        case vector_fast_kind::uint32:
            store<uint32_t>(vector, row, static_cast<uint32_t>(Z_LVAL_P(input)));
            break;
        case vector_fast_kind::uint64:
            store<uint64_t>(vector, row, static_cast<uint64_t>(Z_LVAL_P(input)));
            break;
        case vector_fast_kind::float64:
            store<double>(vector, row, Z_DVAL_P(input));
            break;
        case vector_fast_kind::varchar:
        case vector_fast_kind::blob:
            duckdb_unsafe_vector_assign_string_element_len(vector, row, Z_STRVAL_P(input), Z_STRLEN_P(input));
            break;
        case vector_fast_kind::none:
            break;
    }
    duckdb_validity_set_row_valid(duckdb_vector_get_validity(vector), row);
}

/* Convert inputs to native values of exactly the vector's type. Wrapping
 * each input in a Value gives it the same casts as typed binding. */
static bool convert_inputs(conn_inner *conn, vector_data *data, const std::vector<zval *> &inputs,
                           std::vector<scoped_duckdb_value> &out) {
    for (size_t start = 0; start < inputs.size(); start += conversion_batch) {
        size_t end = (std::min)(inputs.size(), start + conversion_batch);
        std::vector<zval> wrappers(end - start);
        std::vector<zval *> pointers;
        bool initialized = true;
        for (size_t i = start; i < end; i++) {
            zval *wrapper = &wrappers[i - start];
            object_init_ex(wrapper, duckdb_value_class_entry());
            pointers.push_back(wrapper);
            if (!duckdb_value_initialize(wrapper, data->declaration, inputs[i])) {
                initialized = false;
                break;
            }
        }

        std::vector<scoped_duckdb_value> converted;
        bool ok = initialized && duckdb_convert_values(conn, pointers, converted);
        for (zval *wrapper : pointers) {
            zval_ptr_dtor(wrapper);
        }
        if (!ok) {
            return false;
        }

        for (auto &value : converted) {
            if (!duckdb_vector_types_equal(duckdb_get_value_type(value.get()), data->type.get())) {
                std::string message = "Converted " +
                    duckdb_logical_type_render(duckdb_get_value_type(value.get())) +
                    " value does not match the " + duckdb_logical_type_render(data->type.get()) + " vector type";
                duckdb_throw_error(DUCKDB_ERROR_CONVERSION, message.c_str());
                return false;
            }
            out.push_back(std::move(value));
        }
    }
    return true;
}

/* Copy each value through a one-row constant vector. DuckDB's copier writes
 * every layout, including nested children, into the flat target. */
static void write_converted(vector_data *data, const std::vector<std::pair<idx_t, duckdb_value>> &values) {
    if (values.empty()) {
        return;
    }

    duckdb_scoped<duckdb_vector, duckdb_destroy_vector> constant(duckdb_create_vector(data->type.get(), 1));
    if (!constant) {
        throw std::bad_alloc();
    }
    scoped_duckdb_selection selection(duckdb_create_selection_vector(1));
    duckdb_selection_vector_get_data_ptr(selection.get())[0] = 0;
    for (auto &entry : values) {
        duckdb_vector_reference_value(constant.get(), entry.second);
        duckdb_vector_copy_sel(constant.get(), data->vector, selection.get(), 1, 0, entry.first);
    }
}

/* Validate and convert every input first, so a rejected value leaves the
 * vector unchanged. Then write the rows in input order. */
static bool write_values(conn_inner *conn, vector_data *data, std::vector<pending_write> &writes) {
    std::vector<zval *> inputs;
    for (auto &write : writes) {
        ZVAL_DEREF(write.input);
        if (Z_TYPE_P(write.input) == IS_NULL) {
            write.is_null = true;
        } else if (can_write_fast(data->fast, write.input)) {
            write.is_fast = true;
        } else {
            write.converted = inputs.size();
            inputs.push_back(write.input);
        }
    }

    std::vector<scoped_duckdb_value> converted;
    if (!inputs.empty()) {
        std::lock_guard<std::mutex> lock(conn->mutex);
        if (!convert_inputs(conn, data, inputs, converted)) {
            return false;
        }
    }

    std::vector<std::pair<idx_t, duckdb_value>> copies;
    for (auto &write : writes) {
        if (write.is_null) {
            duckdb_validity_set_row_invalid(duckdb_vector_get_validity(data->vector), write.row);
        } else if (write.is_fast) {
            write_fast(data, write.row, write.input);
        } else {
            copies.emplace_back(write.row, converted[write.converted].get());
        }
    }
    write_converted(data, copies);
    return true;
}

static bool check_row(vector_data *data, zend_long row, uint32_t arg_num) {
    if (row < 0 || static_cast<idx_t>(row) >= data->capacity) {
        if (data->capacity == 0) {
            zend_argument_value_error(arg_num, "must be a row of a non-empty vector");
        } else {
            zend_argument_value_error(arg_num, "must be between 0 and " ZEND_ULONG_FMT,
                                      static_cast<zend_ulong>(data->capacity - 1));
        }
        return false;
    }
    return true;
}

static bool check_range(vector_data *data, zend_long offset, idx_t count, uint32_t arg_num) {
    if (offset < 0 || static_cast<idx_t>(offset) > data->capacity ||
        count > data->capacity - static_cast<idx_t>(offset)) {
        zend_argument_value_error(arg_num, "must leave the range within the vector capacity of " ZEND_ULONG_FMT,
                                  static_cast<zend_ulong>(data->capacity));
        return false;
    }
    return true;
}

static void throw_native_error(const char *fallback) {
    try {
        throw;
    } catch (const std::exception &error) {
        if (!EG(exception)) {
            duckdb_throw_msg(error.what());
        }
    } catch (...) {
        if (!EG(exception)) {
            duckdb_throw_msg(fallback);
        }
    }
}

PHP_METHOD(DuckDB_Connection, createVector) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *type;
    zend_long capacity = 0;
    bool capacity_null = true;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(type)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG_OR_NULL(capacity, capacity_null)
    ZEND_PARSE_PARAMETERS_END();

    if (capacity_null) {
        capacity = static_cast<zend_long>(duckdb_vector_size());
    }
    if (capacity < 0 || static_cast<uint64_t>(capacity) > max_vector_capacity) {
        zend_argument_value_error(2, "must be between 0 and %u", static_cast<unsigned>(max_vector_capacity));
        RETURN_THROWS();
    }

    std::string declaration;
    if (!duckdb_type_spec(type, declaration)) {
        RETURN_THROWS();
    }

    auto conn = Z_DUCKDB_CONNECTION_P(ZEND_THIS)->inner;
    if (!duckdb_connection_guard(conn)) {
        RETURN_THROWS();
    }

    try {
        /* Resolve the declaration by converting a typed NULL. The resulting
         * value also initializes every row, including nested children. */
        std::vector<scoped_duckdb_value> nulls;
        {
            std::lock_guard<std::mutex> lock(conn->mutex);
            zval wrapper, input;
            object_init_ex(&wrapper, duckdb_value_class_entry());
            ZVAL_NULL(&input);
            bool ok = duckdb_value_initialize(&wrapper, declaration, &input) &&
                duckdb_convert_values(conn.get(), {&wrapper}, nulls);
            zval_ptr_dtor(&wrapper);
            if (!ok) {
                RETURN_THROWS();
            }
        }

        auto data = duckdb_vector_allocate(duckdb_get_value_type(nulls.front().get()), static_cast<idx_t>(capacity));
        if (!data) {
            RETURN_THROWS();
        }
        if (capacity > 0) {
            duckdb_scoped<duckdb_vector, duckdb_destroy_vector> constant(duckdb_create_vector(data->type.get(), 1));
            if (!constant) {
                throw std::bad_alloc();
            }
            duckdb_vector_reference_value(constant.get(), nulls.front().get());

            /* A constant source reads row 0 for every target row. */
            scoped_duckdb_selection selection(duckdb_create_selection_vector(static_cast<idx_t>(capacity)));
            sel_t *indices = duckdb_selection_vector_get_data_ptr(selection.get());
            std::fill(indices, indices + capacity, 0);
            duckdb_vector_copy_sel(constant.get(), data->vector, selection.get(), static_cast<idx_t>(capacity), 0, 0);
        }
        duckdb_vector_wrap(return_value, std::move(data));
    } catch (...) {
        throw_native_error("Unknown error while creating a vector");
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Vector, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    zend_throw_error(nullptr, "DuckDB\\Vector objects must be created via Connection::createVector()");
}

PHP_METHOD(DuckDB_Vector, type) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto data = duckdb_vector_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    std::string type = duckdb_logical_type_render(data->type.get());
    RETURN_STRINGL(type.data(), type.size());
}

PHP_METHOD(DuckDB_Vector, capacity) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto data = duckdb_vector_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    RETURN_LONG(static_cast<zend_long>(data->capacity));
}

PHP_METHOD(DuckDB_Vector, isNull) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long row;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(row)
    ZEND_PARSE_PARAMETERS_END();
    auto data = duckdb_vector_from_zval(ZEND_THIS);
    if (!data || !check_row(data.get(), row, 1)) {
        RETURN_THROWS();
    }
    uint64_t *validity = duckdb_vector_get_validity(data->vector);
    RETURN_BOOL(validity && !duckdb_validity_row_is_valid(validity, static_cast<idx_t>(row)));
}

PHP_METHOD(DuckDB_Vector, get) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long row;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(row)
    ZEND_PARSE_PARAMETERS_END();
    auto data = duckdb_vector_from_zval(ZEND_THIS);
    if (!data || !check_row(data.get(), row, 1)) {
        RETURN_THROWS();
    }
    try {
        if (!duckdb_decode_vector_value(data->vector, data->type.get(), static_cast<idx_t>(row), return_value)) {
            RETURN_THROWS();
        }
    } catch (...) {
        throw_native_error("Unknown error while reading a vector");
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Vector, toArray) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long offset = 0;
    zend_long length = 0;
    bool length_null = true;
    ZEND_PARSE_PARAMETERS_START(0, 2)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(offset)
        Z_PARAM_LONG_OR_NULL(length, length_null)
    ZEND_PARSE_PARAMETERS_END();
    auto data = duckdb_vector_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    if (offset < 0 || static_cast<idx_t>(offset) > data->capacity) {
        zend_argument_value_error(1, "must be between 0 and " ZEND_ULONG_FMT, static_cast<zend_ulong>(data->capacity));
        RETURN_THROWS();
    }
    if (length_null) {
        length = static_cast<zend_long>(data->capacity - static_cast<idx_t>(offset));
    }
    if (length < 0) {
        zend_argument_value_error(2, "must be greater than or equal to 0");
        RETURN_THROWS();
    }
    if (!check_range(data.get(), offset, static_cast<idx_t>(length), 2)) {
        RETURN_THROWS();
    }

    array_init_size(return_value, static_cast<uint32_t>(length));
    try {
        for (idx_t row = static_cast<idx_t>(offset); row < static_cast<idx_t>(offset + length); row++) {
            zval value;
            if (!duckdb_decode_vector_value(data->vector, data->type.get(), row, &value)) {
                zval_ptr_dtor(return_value);
                ZVAL_UNDEF(return_value);
                RETURN_THROWS();
            }
            add_next_index_zval(return_value, &value);
        }
    } catch (...) {
        zval_ptr_dtor(return_value);
        ZVAL_UNDEF(return_value);
        throw_native_error("Unknown error while reading a vector");
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Vector, set) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *connection;
    zend_long row;
    zval *value;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_OBJECT_OF_CLASS(connection, duckdb_connection_ce)
        Z_PARAM_LONG(row)
        Z_PARAM_ZVAL(value)
    ZEND_PARSE_PARAMETERS_END();
    auto data = duckdb_vector_from_zval(ZEND_THIS);
    if (!data || !check_row(data.get(), row, 2)) {
        RETURN_THROWS();
    }
    auto conn = Z_DUCKDB_CONNECTION_P(connection)->inner;
    if (!duckdb_connection_guard(conn)) {
        RETURN_THROWS();
    }

    std::vector<pending_write> writes(1);
    writes[0].row = static_cast<idx_t>(row);
    writes[0].input = value;
    try {
        if (!write_values(conn.get(), data.get(), writes)) {
            RETURN_THROWS();
        }
    } catch (...) {
        throw_native_error("Unknown error while writing a vector");
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Vector, setValues) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *connection;
    HashTable *values;
    zend_long offset = 0;
    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_OBJECT_OF_CLASS(connection, duckdb_connection_ce)
        Z_PARAM_ARRAY_HT(values)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(offset)
    ZEND_PARSE_PARAMETERS_END();
    auto data = duckdb_vector_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    if (!zend_array_is_list(values)) {
        zend_argument_value_error(2, "must be a list");
        RETURN_THROWS();
    }
    if (!check_range(data.get(), offset, zend_hash_num_elements(values), 3)) {
        RETURN_THROWS();
    }
    auto conn = Z_DUCKDB_CONNECTION_P(connection)->inner;
    if (!duckdb_connection_guard(conn)) {
        RETURN_THROWS();
    }

    std::vector<pending_write> writes;
    writes.reserve(zend_hash_num_elements(values));
    idx_t row = static_cast<idx_t>(offset);
    zval *value;
    ZEND_HASH_FOREACH_VAL(values, value) {
        pending_write write;
        write.row = row++;
        write.input = value;
        writes.push_back(write);
    }
    ZEND_HASH_FOREACH_END();

    try {
        if (!write_values(conn.get(), data.get(), writes)) {
            RETURN_THROWS();
        }
    } catch (...) {
        throw_native_error("Unknown error while writing a vector");
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Vector, setNull) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long row;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(row)
    ZEND_PARSE_PARAMETERS_END();
    auto data = duckdb_vector_from_zval(ZEND_THIS);
    if (!data || !check_row(data.get(), row, 1)) {
        RETURN_THROWS();
    }
    duckdb_validity_set_row_invalid(duckdb_vector_get_validity(data->vector), static_cast<idx_t>(row));
}

PHP_METHOD(DuckDB_Vector, copyFrom) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *source_zval;
    zend_long source_offset = 0;
    zend_long count = 0;
    bool count_null = true;
    zend_long target_offset = 0;
    ZEND_PARSE_PARAMETERS_START(1, 4)
        Z_PARAM_OBJECT_OF_CLASS(source_zval, duckdb_vector_ce)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(source_offset)
        Z_PARAM_LONG_OR_NULL(count, count_null)
        Z_PARAM_LONG(target_offset)
    ZEND_PARSE_PARAMETERS_END();
    auto target = duckdb_vector_from_zval(ZEND_THIS);
    if (!target) {
        RETURN_THROWS();
    }
    auto source = duckdb_vector_from_zval(source_zval);
    if (!source) {
        RETURN_THROWS();
    }
    if (source_offset < 0 || static_cast<idx_t>(source_offset) > source->capacity) {
        zend_argument_value_error(2, "must be between 0 and " ZEND_ULONG_FMT,
                                  static_cast<zend_ulong>(source->capacity));
        RETURN_THROWS();
    }
    if (count_null) {
        count = static_cast<zend_long>(source->capacity - static_cast<idx_t>(source_offset));
    }
    if (count < 0) {
        zend_argument_value_error(3, "must be greater than or equal to 0");
        RETURN_THROWS();
    }
    if (!check_range(source.get(), source_offset, static_cast<idx_t>(count), 3) ||
        !check_range(target.get(), target_offset, static_cast<idx_t>(count), 4)) {
        RETURN_THROWS();
    }
    if (!duckdb_vector_types_equal(source->type.get(), target->type.get())) {
        std::string source_type = duckdb_logical_type_render(source->type.get());
        std::string target_type = duckdb_logical_type_render(target->type.get());
        zend_argument_type_error(1, "must have type %s, %s given", target_type.c_str(), source_type.c_str());
        RETURN_THROWS();
    }

    try {
        if (source == target) {
            /* The copier appends nested children while reading; stage
             * self-copies so overlapping ranges read the original rows. */
            auto staged = duckdb_vector_allocate(source->type.get(), static_cast<idx_t>(count));
            if (!staged) {
                RETURN_THROWS();
            }
            duckdb_vector_copy_rows(source->vector, staged->vector, static_cast<idx_t>(source_offset),
                                    static_cast<idx_t>(count), 0);
            duckdb_vector_copy_rows(staged->vector, target->vector, 0, static_cast<idx_t>(count),
                                    static_cast<idx_t>(target_offset));
            return;
        }
        duckdb_vector_copy_rows(source->vector, target->vector, static_cast<idx_t>(source_offset),
                                static_cast<idx_t>(count), static_cast<idx_t>(target_offset));
    } catch (...) {
        throw_native_error("Unknown error while copying a vector");
        RETURN_THROWS();
    }
}

PHP_FUNCTION(DuckDB_vectorSize) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_LONG(static_cast<zend_long>(duckdb_vector_size()));
}
