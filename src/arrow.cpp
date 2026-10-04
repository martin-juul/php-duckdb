#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"
#include "arrow.h"

extern "C" {
#include "ext/json/php_json.h"
}

#include <cstring>

/* Check the generated representation, rather than querying connection settings:
 * a settings query would invalidate a live streaming result. */
bool duckdb_arrow_require_lossless(duckdb_logical_type type, const ArrowSchema &schema) {
    auto id = duckdb_get_type_id(type);
    const char *required_format = nullptr;
    const char *required_type = nullptr;
    switch (id) {
        case DUCKDB_TYPE_HUGEINT:
        case DUCKDB_TYPE_UHUGEINT:
            required_type = id == DUCKDB_TYPE_HUGEINT ? "hugeint" : "uhugeint";
            required_format = "w:16";
            break;
        case DUCKDB_TYPE_TIME_TZ:
            required_type = "time_tz";
            required_format = "w:8";
            break;
        case DUCKDB_TYPE_BIT:
            required_type = "bit";
            /* Both representations use binary storage; only the extension
             * metadata preserves the bit length and logical interpretation. */
            required_format = schema.format;
            break;
        default:
            break;
    }
    if (required_format) {
        std::string extension_name;
        std::string extension_metadata;
        if (!duckdb_arrow_extension_metadata(schema, extension_name, extension_metadata)) {
            return false;
        }
        bool lossless = false;
        if (extension_name == "arrow.opaque" && strcmp(schema.format, required_format) == 0) {
            zval decoded;
            ZVAL_UNDEF(&decoded);
            if (php_json_decode_ex(&decoded, extension_metadata.data(), extension_metadata.size(), 0, 512) == SUCCESS &&
                Z_TYPE(decoded) == IS_OBJECT) {
                zval *vendor = zend_hash_str_find(Z_OBJPROP(decoded), "vendor_name", sizeof("vendor_name") - 1);
                zval *name = zend_hash_str_find(Z_OBJPROP(decoded), "type_name", sizeof("type_name") - 1);
                lossless = vendor && Z_TYPE_P(vendor) == IS_STRING && zend_string_equals_literal(Z_STR_P(vendor), "DuckDB") &&
                    name && Z_TYPE_P(name) == IS_STRING && zend_string_equals_cstr(Z_STR_P(name), required_type, strlen(required_type));
            }
            if (!Z_ISUNDEF(decoded)) {
                zval_ptr_dtor(&decoded);
            }
        }
        if (!lossless) {
            duckdb_throw_msg("Lossless Arrow conversion is required for HUGEINT, UHUGEINT, BIT and TIMETZ; "
                             "set arrow_lossless_conversion=true before querying or exporting a DataChunk");
            return false;
        }
    }

    switch (id) {
        case DUCKDB_TYPE_LIST:
        case DUCKDB_TYPE_ARRAY: {
            scoped_duckdb_logical_type child(id == DUCKDB_TYPE_LIST
                ? duckdb_list_type_child_type(type) : duckdb_array_type_child_type(type));
            return duckdb_arrow_require_lossless(child.get(), *schema.children[0]);
        }
        case DUCKDB_TYPE_MAP: {
            scoped_duckdb_logical_type key(duckdb_map_type_key_type(type));
            scoped_duckdb_logical_type value(duckdb_map_type_value_type(type));
            return duckdb_arrow_require_lossless(key.get(), *schema.children[0]->children[0]) &&
                duckdb_arrow_require_lossless(value.get(), *schema.children[0]->children[1]);
        }
        case DUCKDB_TYPE_STRUCT:
        case DUCKDB_TYPE_UNION: {
            idx_t count = id == DUCKDB_TYPE_STRUCT
                ? duckdb_struct_type_child_count(type) : duckdb_union_type_member_count(type);
            for (idx_t i = 0; i < count; i++) {
                scoped_duckdb_logical_type child(id == DUCKDB_TYPE_STRUCT
                    ? duckdb_struct_type_child_type(type, i) : duckdb_union_type_member_type(type, i));
                if (!duckdb_arrow_require_lossless(child.get(), *schema.children[i])) {
                    return false;
                }
            }
            break;
        }
        default:
            break;
    }
    return true;
}

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

namespace {
zend_class_entry *schema_class;
zend_class_entry *chunk_class;
zend_object_handlers schema_handlers;
zend_object_handlers chunk_handlers;

template <class Data>
struct arrow_object {
    std::shared_ptr<Data> data;
    zend_object std;
};

template <class Data>
arrow_object<Data> *from_object(zend_object *object) {
    return reinterpret_cast<arrow_object<Data> *>(reinterpret_cast<char *>(object) - offsetof(arrow_object<Data>, std));
}

template <class Data>
zend_object *create_object(zend_class_entry *ce) {
    auto *object = static_cast<arrow_object<Data> *>(ecalloc(1, sizeof(arrow_object<Data>) + zend_object_properties_size(ce)));
    new (&object->data) std::shared_ptr<Data>();
    zend_object_std_init(&object->std, ce);
    object_properties_init(&object->std, ce);
    object->std.handlers = ce == schema_class ? &schema_handlers : &chunk_handlers;
    return &object->std;
}

template <class Data>
void free_object(zend_object *object) {
    auto *intern = from_object<Data>(object);
    intern->data.~shared_ptr();
    zend_object_std_dtor(object);
}

template <class Struct>
Struct *address_pointer(zend_long address) {
    if (address <= 0 || static_cast<uintptr_t>(address) % alignof(Struct) != 0) {
        zend_value_error("Arrow C Data Interface address must be positive and aligned");
        return nullptr;
    }
    return reinterpret_cast<Struct *>(static_cast<uintptr_t>(address));
}

/* Metadata is a sequence of int32 lengths followed by arbitrary bytes.
 * Keep duplicate keys and embedded NUL bytes. Inputs are trusted native
 * allocations; structural checks cannot make invalid pointers safe. */
bool metadata_length(const char *metadata, size_t &length) {
    length = 0;
    if (!metadata) {
        return true;
    }
    constexpr size_t max_bytes = 64 * 1024 * 1024;
    int32_t count;
    memcpy(&count, metadata, sizeof(count));
    if (count < 0 || count > 65536) {
        zend_value_error("Invalid Arrow metadata entry count");
        return false;
    }
    length = sizeof(count);
    for (int32_t i = 0; i < count; i++) {
        for (int part = 0; part < 2; part++) {
            if (length > max_bytes - sizeof(int32_t)) {
                zend_value_error("Invalid or oversized Arrow metadata");
                return false;
            }
            int32_t size;
            memcpy(&size, metadata + length, sizeof(size));
            length += sizeof(size);
            if (size < 0 || static_cast<size_t>(size) > max_bytes - length) {
                zend_value_error("Invalid or oversized Arrow metadata");
                return false;
            }
            length += static_cast<size_t>(size);
        }
    }
    return true;
}

bool validate_schema(const ArrowSchema &schema, size_t depth = 0) {
    if (depth > 128 || !schema.release || !schema.format || schema.n_children < 0 || schema.n_children > 65536 ||
        (schema.n_children && !schema.children)) {
        zend_value_error("Invalid, released, or excessively nested Arrow schema");
        return false;
    }
    size_t length;
    if (!metadata_length(schema.metadata, length)) {
        return false;
    }
    for (int64_t i = 0; i < schema.n_children; i++) {
        if (!schema.children[i]) {
            zend_value_error("Arrow schema contains a null child");
            return false;
        }
        if (!validate_schema(*schema.children[i], depth + 1)) {
            return false;
        }
    }
    return !schema.dictionary || validate_schema(*schema.dictionary, depth + 1);
}

bool validate_array(const ArrowArray &array, const ArrowSchema &schema, size_t depth = 0) {
    if (depth > 128 || !array.release || array.length < 0 || array.offset < 0 || array.null_count < -1 ||
        array.null_count > array.length || array.n_buffers < 0 || array.n_buffers > 65536 ||
        (array.n_buffers && !array.buffers) || array.n_children != schema.n_children ||
        (array.n_children && !array.children) ||
        (array.dictionary != nullptr) != (schema.dictionary != nullptr)) {
        zend_value_error("Invalid, released, or incompatible Arrow array");
        return false;
    }
    if (array.offset > INT64_MAX - array.length) {
        zend_value_error("Arrow array offset and length overflow");
        return false;
    }
    bool is_struct = strcmp(schema.format, "+s") == 0;
    if (is_struct && array.n_buffers != 1) {
        zend_value_error("Arrow struct arrays require one validity buffer");
        return false;
    }
    if (is_struct && array.null_count > 0 && !array.buffers[0]) {
        zend_value_error("Arrow struct null rows require a validity bitmap");
        return false;
    }
    for (int64_t i = 0; i < array.n_children; i++) {
        if (!array.children[i]) {
            zend_value_error("Arrow array contains a null child");
            return false;
        }
        if (is_struct && array.children[i]->length < array.offset + array.length) {
            zend_value_error("Arrow struct child does not contain the parent slice");
            return false;
        }
        if (!validate_array(*array.children[i], *schema.children[i], depth + 1)) {
            return false;
        }
    }
    return !array.dictionary || validate_array(*array.dictionary, *schema.dictionary, depth + 1);
}

struct copied_schema {
    std::string format;
    std::string name;
    std::string metadata;
    std::vector<ArrowSchema *> children;
    ArrowSchema *dictionary = nullptr;

    ~copied_schema() {
        for (ArrowSchema *child : children) {
            if (child->release) {
                child->release(child);
            }
            delete child;
        }
        if (dictionary) {
            if (dictionary->release) {
                dictionary->release(dictionary);
            }
            delete dictionary;
        }
    }
};

void release_copied_schema(ArrowSchema *schema) {
    if (!schema || !schema->release) {
        return;
    }
    schema->release = nullptr;
    delete static_cast<copied_schema *>(schema->private_data);
    schema->private_data = nullptr;
}

void copy_schema(const ArrowSchema &source, ArrowSchema &out) {
    auto storage = std::make_unique<copied_schema>();
    storage->format = source.format;
    if (source.name) {
        storage->name = source.name;
    }
    if (source.metadata) {
        size_t length = 0;
        metadata_length(source.metadata, length);
        storage->metadata.assign(source.metadata, length);
    }
    storage->children.reserve(static_cast<size_t>(source.n_children));
    for (int64_t i = 0; i < source.n_children; i++) {
        auto child = std::make_unique<ArrowSchema>();
        *child = {};
        copy_schema(*source.children[i], *child);
        storage->children.push_back(child.release());
    }
    if (source.dictionary) {
        storage->dictionary = new ArrowSchema{};
        copy_schema(*source.dictionary, *storage->dictionary);
    }
    out = {};
    out.format = storage->format.c_str();
    out.name = source.name ? storage->name.c_str() : nullptr;
    out.metadata = source.metadata ? storage->metadata.data() : nullptr;
    out.flags = source.flags;
    out.n_children = source.n_children;
    out.children = storage->children.empty() ? nullptr : storage->children.data();
    out.dictionary = storage->dictionary;
    out.release = release_copied_schema;
    out.private_data = storage.release();
}

void schema_to_array(const ArrowSchema &schema, zval *out) {
    array_init(out);
    add_assoc_string(out, "format", schema.format);
    if (schema.name) {
        add_assoc_string(out, "name", schema.name);
    } else {
        add_assoc_null(out, "name");
    }
    add_assoc_long(out, "flags", static_cast<zend_long>(schema.flags));

    zval metadata;
    array_init(&metadata);
    if (schema.metadata) {
        int32_t count;
        memcpy(&count, schema.metadata, sizeof(count));
        size_t offset = sizeof(count);
        for (int32_t i = 0; i < count; i++) {
            zval entry;
            array_init(&entry);
            for (const char *name : {"key", "value"}) {
                int32_t length;
                memcpy(&length, schema.metadata + offset, sizeof(length));
                offset += sizeof(length);
                add_assoc_stringl(&entry, name, schema.metadata + offset, static_cast<size_t>(length));
                offset += static_cast<size_t>(length);
            }
            add_next_index_zval(&metadata, &entry);
        }
    }
    add_assoc_zval(out, "metadata", &metadata);

    zval children;
    array_init(&children);
    for (int64_t i = 0; i < schema.n_children; i++) {
        zval child;
        schema_to_array(*schema.children[i], &child);
        add_next_index_zval(&children, &child);
    }
    add_assoc_zval(out, "children", &children);
    if (schema.dictionary) {
        zval dictionary;
        schema_to_array(*schema.dictionary, &dictionary);
        add_assoc_zval(out, "dictionary", &dictionary);
    } else {
        add_assoc_null(out, "dictionary");
    }
}
} // namespace

bool duckdb_arrow_extension_metadata(const ArrowSchema &schema, std::string &extension_name,
                                     std::string &extension_metadata) {
    extension_name.clear();
    extension_metadata.clear();
    size_t length;
    if (!metadata_length(schema.metadata, length)) {
        return false;
    }
    if (!schema.metadata) {
        return true;
    }
    const char *cursor = schema.metadata;
    int32_t count;
    memcpy(&count, cursor, sizeof(count));
    cursor += sizeof(count);
    for (int32_t i = 0; i < count; i++) {
        int32_t key_length;
        memcpy(&key_length, cursor, sizeof(key_length));
        cursor += sizeof(key_length);
        std::string key(cursor, static_cast<size_t>(key_length));
        cursor += key_length;

        int32_t value_length;
        memcpy(&value_length, cursor, sizeof(value_length));
        cursor += sizeof(value_length);
        if (key == "ARROW:extension:name") {
            extension_name.assign(cursor, static_cast<size_t>(value_length));
        } else if (key == "ARROW:extension:metadata") {
            extension_metadata.assign(cursor, static_cast<size_t>(value_length));
        }
        cursor += value_length;
    }
    return true;
}

zend_class_entry *duckdb_arrow_schema_class_entry() {
    return schema_class;
}

zend_class_entry *duckdb_arrow_chunk_class_entry() {
    return chunk_class;
}

std::shared_ptr<arrow_schema_data> duckdb_arrow_schema_from_zval(zval *value) {
    auto data = from_object<arrow_schema_data>(Z_OBJ_P(value))->data;
    if (!duckdb_initialized_guard(static_cast<bool>(data), "DuckDB\\ArrowSchema")) {
        return nullptr;
    }
    return data;
}

std::shared_ptr<arrow_chunk_data> duckdb_arrow_chunk_from_zval(zval *value) {
    auto data = from_object<arrow_chunk_data>(Z_OBJ_P(value))->data;
    if (!duckdb_initialized_guard(static_cast<bool>(data), "DuckDB\\ArrowChunk")) {
        return nullptr;
    }
    return data;
}

void duckdb_arrow_schema_wrap(zval *out, std::shared_ptr<arrow_schema_data> data) {
    object_init_ex(out, schema_class);
    from_object<arrow_schema_data>(Z_OBJ_P(out))->data = std::move(data);
}

void duckdb_arrow_chunk_wrap(zval *out, std::shared_ptr<arrow_chunk_data> data) {
    object_init_ex(out, chunk_class);
    from_object<arrow_chunk_data>(Z_OBJ_P(out))->data = std::move(data);
}

bool duckdb_arrow_check_error(duckdb_error_data error) {
    if (!error) {
        return true;
    }
    bool success = !duckdb_error_data_has_error(error);
    if (!success) {
        const char *message = duckdb_error_data_message(error);
        duckdb_throw_error(duckdb_error_data_error_type(error), message ? message : "Arrow conversion failed");
    }
    duckdb_destroy_error_data(&error);
    return success;
}

void duckdb_register_arrow_classes(zend_class_entry *schema_ce, zend_class_entry *chunk_ce) {
    schema_class = schema_ce;
    chunk_class = chunk_ce;
    schema_ce->create_object = create_object<arrow_schema_data>;
    chunk_ce->create_object = create_object<arrow_chunk_data>;
    for (zend_class_entry *ce : {schema_ce, chunk_ce}) {
        ce->ce_flags |= ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;
    }
    memcpy(&schema_handlers, zend_get_std_object_handlers(), sizeof(schema_handlers));
    schema_handlers.offset = offsetof(arrow_object<arrow_schema_data>, std);
    schema_handlers.free_obj = free_object<arrow_schema_data>;
    schema_handlers.clone_obj = nullptr;
    memcpy(&chunk_handlers, zend_get_std_object_handlers(), sizeof(chunk_handlers));
    chunk_handlers.offset = offsetof(arrow_object<arrow_chunk_data>, std);
    chunk_handlers.free_obj = free_object<arrow_chunk_data>;
    chunk_handlers.clone_obj = nullptr;
}

PHP_METHOD(DuckDB_ArrowSchema, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    zend_throw_error(nullptr, "DuckDB\\ArrowSchema objects must be created by Arrow conversion or importFromC()");
}

PHP_METHOD(DuckDB_ArrowSchema, importFromC) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long address;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(address)
    ZEND_PARSE_PARAMETERS_END();

    auto source = address_pointer<ArrowSchema>(address);
    if (!source || !validate_schema(*source)) {
        RETURN_THROWS();
    }
    auto data = std::make_shared<arrow_schema_data>();
    data->schema = *source;
    source->release = nullptr;
    duckdb_arrow_schema_wrap(return_value, std::move(data));
}

PHP_METHOD(DuckDB_ArrowSchema, exportToC) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long address;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(address)
    ZEND_PARSE_PARAMETERS_END();

    auto data = duckdb_arrow_schema_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    auto target = address_pointer<ArrowSchema>(address);
    if (!target) {
        RETURN_THROWS();
    }
    if (target->release) {
        zend_value_error("Arrow schema destination must be zero-initialized or released");
        RETURN_THROWS();
    }
    if (!validate_schema(data->schema)) {
        RETURN_THROWS();
    }
    copy_schema(data->schema, *target);
}

PHP_METHOD(DuckDB_ArrowSchema, toArray) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto data = duckdb_arrow_schema_from_zval(ZEND_THIS);
    if (!data || !validate_schema(data->schema)) {
        RETURN_THROWS();
    }
    schema_to_array(data->schema, return_value);
}

PHP_METHOD(DuckDB_ArrowChunk, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    zend_throw_error(nullptr, "DuckDB\\ArrowChunk objects must be created by Arrow conversion or importFromC()");
}

PHP_METHOD(DuckDB_ArrowChunk, importFromC) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *schema;
    zend_long address;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJECT_OF_CLASS(schema, schema_class)
        Z_PARAM_LONG(address)
    ZEND_PARSE_PARAMETERS_END();

    auto schema_data = duckdb_arrow_schema_from_zval(schema);
    if (!schema_data) {
        RETURN_THROWS();
    }
    if (!validate_schema(schema_data->schema)) {
        RETURN_THROWS();
    }
    if (strcmp(schema_data->schema.format, "+s") != 0) {
        zend_value_error("Arrow chunks require a struct schema for the record batch");
        RETURN_THROWS();
    }
    auto source = address_pointer<ArrowArray>(address);
    if (!source || !validate_array(*source, schema_data->schema)) {
        RETURN_THROWS();
    }
    if (source->n_buffers != 1 || source->null_count > 0) {
        zend_value_error("Arrow record batches require one validity buffer and no null rows");
        RETURN_THROWS();
    }
    if (static_cast<uint64_t>(source->length) > UINT32_MAX || source->length > ZEND_LONG_MAX) {
        zend_value_error("Arrow record batch row count exceeds the supported integer range");
        RETURN_THROWS();
    }
    if (source->null_count == -1 && source->buffers[0]) {
        auto validity = static_cast<const uint8_t *>(source->buffers[0]);
        for (int64_t row = 0; row < source->length; row++) {
            int64_t index = source->offset + row;
            if ((validity[index / 8] & (1U << (index % 8))) == 0) {
                zend_value_error("Arrow record batches must not contain null rows");
                RETURN_THROWS();
            }
        }
    }
    auto data = std::make_shared<arrow_chunk_data>();
    data->schema = std::move(schema_data);
    data->array = *source;
    data->row_count = static_cast<idx_t>(source->length);
    source->release = nullptr;
    duckdb_arrow_chunk_wrap(return_value, std::move(data));
}

PHP_METHOD(DuckDB_ArrowChunk, exportToC) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long address;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(address)
    ZEND_PARSE_PARAMETERS_END();

    auto data = duckdb_arrow_chunk_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    if (!data->array.release) {
        duckdb_throw_msg("Arrow chunk has already been consumed");
        RETURN_THROWS();
    }
    auto target = address_pointer<ArrowArray>(address);
    if (!target) {
        RETURN_THROWS();
    }
    if (target->release) {
        zend_value_error("Arrow array destination must be zero-initialized or released");
        RETURN_THROWS();
    }
    *target = data->array;
    data->array.release = nullptr;
}

PHP_METHOD(DuckDB_ArrowChunk, schema) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto data = duckdb_arrow_chunk_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    duckdb_arrow_schema_wrap(return_value, data->schema);
}

PHP_METHOD(DuckDB_ArrowChunk, rowCount) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto data = duckdb_arrow_chunk_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    if (data->row_count > static_cast<idx_t>(ZEND_LONG_MAX)) {
        zend_value_error("Arrow row count exceeds the PHP integer range");
        RETURN_THROWS();
    }
    RETURN_LONG(static_cast<zend_long>(data->row_count));
}

PHP_METHOD(DuckDB_ArrowChunk, isConsumed) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto data = duckdb_arrow_chunk_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    RETURN_BOOL(!data->array.release);
}
