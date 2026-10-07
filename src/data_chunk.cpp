#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"
extern "C" {
#include "ext/json/php_json.h"
}
#include "php_duckdb.h"
#include "data_chunk.h"
#include "vector.h"

#include <exception>

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

zend_class_entry *duckdb_data_chunk_ce;
static zend_object_handlers data_chunk_handlers;

struct php_data_chunk_object {
    std::shared_ptr<data_chunk_data> data;
    zend_object std;
};

static php_data_chunk_object *data_chunk_object(zend_object *obj) {
    return reinterpret_cast<php_data_chunk_object *>(reinterpret_cast<char *>(obj) - offsetof(php_data_chunk_object, std));
}

static zend_object *data_chunk_create(zend_class_entry *ce) {
    auto *obj = static_cast<php_data_chunk_object *>(zend_object_alloc(sizeof(php_data_chunk_object), ce));
    new (&obj->data) std::shared_ptr<data_chunk_data>();
    zend_object_std_init(&obj->std, ce);
    object_properties_init(&obj->std, ce);
    obj->std.handlers = &data_chunk_handlers;
    return &obj->std;
}

static void data_chunk_free(zend_object *obj) {
    data_chunk_object(obj)->data.~shared_ptr();
    zend_object_std_dtor(obj);
}

void duckdb_register_data_chunk_class(zend_class_entry *ce) {
    duckdb_data_chunk_ce = ce;
    ce->create_object = data_chunk_create;
    ce->ce_flags |= ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;
    memcpy(&data_chunk_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    data_chunk_handlers.offset = offsetof(php_data_chunk_object, std);
    data_chunk_handlers.free_obj = data_chunk_free;
    data_chunk_handlers.clone_obj = nullptr;
}

std::shared_ptr<data_chunk_data> duckdb_data_chunk_from_zval(zval *value) {
    auto data = data_chunk_object(Z_OBJ_P(value))->data;
    if (!duckdb_initialized_guard(static_cast<bool>(data), "DuckDB\\DataChunk")) {
        return nullptr;
    }
    return data;
}

/* DuckDB's importer can borrow some columns while materializing others. Use
 * a non-owning facade and keep the producer's one release callback attached
 * to the whole chunk, rather than to whichever column imports first. */
static void borrowed_arrow_release(ArrowArray *array) {
    array->release = nullptr;
}

/* DuckDB 1.5.6's C importer omits the root offset for data conversion,
 * and its nested struct converter omits offsets inherited from ancestors.
 * Normalize every struct into an offset-zero view: move its slice onto
 * its immediate children and independently rebase its validity bitmap.
 * Lists, fixed arrays and dictionaries retain their own offset semantics. */
static std::shared_ptr<arrow_array_facade> normalize_arrow_array(const ArrowArray &source,
                                                              const ArrowSchema &schema,
                                                              int64_t inherited_offset = 0,
                                                              int64_t length = -1) {
    if (length < 0) {
        length = source.length;
    }
    if (source.offset > INT64_MAX - inherited_offset ||
        inherited_offset > source.length || length > source.length - inherited_offset) {
        zend_value_error("Arrow child array does not contain the parent slice");
        return nullptr;
    }
    int64_t effective_offset = source.offset + inherited_offset;
    if (effective_offset > INT64_MAX - length) {
        zend_value_error("Arrow array slice overflows the array bounds");
        return nullptr;
    }

    auto view = std::make_shared<arrow_array_facade>();
    view->array = source;
    view->array.release = borrowed_arrow_release;
    view->array.offset = effective_offset;
    view->array.length = length;
    bool is_struct = strcmp(schema.format, "+s") == 0;
    if (is_struct) {
        view->array.offset = 0;
        view->buffers.assign(source.buffers, source.buffers + source.n_buffers);
        if (source.buffers[0] && source.null_count != 0) {
            /* GetValidityMask reads an extra byte for unaligned slices,
             * including struct values reached through a list offset. */
            view->validity.assign((static_cast<size_t>(length) + 7) / 8 + 1, 0);
            auto source_validity = static_cast<const uint8_t *>(source.buffers[0]);
            int64_t nulls = 0;
            for (int64_t row = 0; row < length; row++) {
                int64_t source_row = effective_offset + row;
                if ((source_validity[source_row / 8] & (1U << (source_row % 8))) != 0) {
                    view->validity[static_cast<size_t>(row) / 8] |= static_cast<uint8_t>(1U << (row % 8));
                } else {
                    nulls++;
                }
            }
            view->array.null_count = nulls;
            view->buffers[0] = view->validity.empty() ? nullptr : view->validity.data();
        } else {
            view->array.null_count = 0;
            view->buffers[0] = nullptr;
        }
        view->array.buffers = view->buffers.data();
    } else if (source.null_count != 0 && source.n_buffers > 0 && source.buffers[0] &&
               strncmp(schema.format, "+u", 2) != 0) {
        /* Preserve scalar/list/array data offsets, but own a padded copy
         * of their original validity bitmap. DuckDB 1.5.6 copies
         * ceil(size / 8) + 1 bytes for unaligned offsets even when the
         * selected bits fit in the producer's final byte. Union buffer 0
         * holds type IDs and must not be treated as a validity bitmap. */
        size_t bitmap_bytes = static_cast<size_t>(source.offset + source.length) / 8;
        if ((source.offset + source.length) % 8 != 0) {
            bitmap_bytes++;
        }
        view->validity.assign(bitmap_bytes + 1, 0);
        if (bitmap_bytes > 0) {
            memcpy(view->validity.data(), source.buffers[0], bitmap_bytes);
        }
        view->buffers.assign(source.buffers, source.buffers + source.n_buffers);
        view->buffers[0] = view->validity.data();
        view->array.buffers = view->buffers.data();
    }

    view->children.reserve(static_cast<size_t>(source.n_children));
    view->child_pointers.reserve(static_cast<size_t>(source.n_children));
    for (int64_t i = 0; i < source.n_children; i++) {
        auto child = normalize_arrow_array(*source.children[i], *schema.children[i],
                                          is_struct ? effective_offset : 0,
                                          is_struct ? length : -1);
        if (!child) {
            return nullptr;
        }
        view->children.push_back(child);
        view->child_pointers.push_back(&child->array);
    }
    view->array.children = view->child_pointers.empty() ? nullptr : view->child_pointers.data();
    if (source.dictionary) {
        view->dictionary = normalize_arrow_array(*source.dictionary, *schema.dictionary);
        if (!view->dictionary) {
            return nullptr;
        }
        view->array.dictionary = &view->dictionary->array;
    }
    return view;
}

/* Imported schemas have already passed the bounded metadata validation in
 * ArrowSchema::importFromC(). Inspect GeoArrow JSON without assuming a CRS
 * representation or relying on the linked engine's extension behavior. */
static bool arrow_geometry_requires_crs(const ArrowSchema &schema, bool &required) {
    required = false;
    if (!schema.metadata) {
        return true;
    }

    std::string extension_name;
    std::string extension_metadata;
    if (!duckdb_arrow_extension_metadata(schema, extension_name, extension_metadata)) {
        return false;
    }
    if (extension_name != "geoarrow.wkb" || extension_metadata.empty()) {
        return true;
    }

    zval decoded;
    ZVAL_UNDEF(&decoded);
    bool valid = php_json_decode_ex(&decoded, extension_metadata.data(), extension_metadata.size(),
                                    0, 512) == SUCCESS && Z_TYPE(decoded) == IS_OBJECT;
    if (valid) {
        zval *crs = zend_hash_str_find(Z_OBJPROP(decoded), "crs", sizeof("crs") - 1);
        required = crs && Z_TYPE_P(crs) != IS_NULL;
    }
    if (!Z_ISUNDEF(decoded)) {
        zval_ptr_dtor(&decoded);
    }
    if (!valid) {
        duckdb_throw_msg("Invalid GeoArrow extension metadata JSON");
    }
    return valid;
}

static bool arrow_import_preserves_geometry_crs(duckdb_logical_type type, const ArrowSchema &schema) {
    bool required;
    if (!arrow_geometry_requires_crs(schema, required)) {
        return false;
    }
    auto id = duckdb_get_type_id(type);
    if (required) {
        char *crs = id == DUCKDB_TYPE_GEOMETRY ? duckdb_geometry_type_get_crs(type) : nullptr;
        bool preserved = crs && crs[0] != '\0';
        duckdb_free(crs);
        if (!preserved) {
            duckdb_throw_msg("The linked DuckDB SDK erased GeoArrow geometry CRS metadata; "
                             "update to the packaged DuckDB SDK with the Arrow geometry fix");
            return false;
        }
    }
    if (schema.dictionary) {
        return arrow_import_preserves_geometry_crs(type, *schema.dictionary);
    }
    if (strcmp(schema.format, "+r") == 0) {
        return arrow_import_preserves_geometry_crs(type, *schema.children[1]);
    }

    switch (id) {
        case DUCKDB_TYPE_LIST:
        case DUCKDB_TYPE_ARRAY: {
            scoped_duckdb_logical_type child(id == DUCKDB_TYPE_LIST
                ? duckdb_list_type_child_type(type) : duckdb_array_type_child_type(type));
            return arrow_import_preserves_geometry_crs(child.get(), *schema.children[0]);
        }
        case DUCKDB_TYPE_MAP: {
            scoped_duckdb_logical_type key(duckdb_map_type_key_type(type));
            scoped_duckdb_logical_type value(duckdb_map_type_value_type(type));
            return arrow_import_preserves_geometry_crs(key.get(), *schema.children[0]->children[0]) &&
                arrow_import_preserves_geometry_crs(value.get(), *schema.children[0]->children[1]);
        }
        case DUCKDB_TYPE_STRUCT:
        case DUCKDB_TYPE_UNION: {
            idx_t count = id == DUCKDB_TYPE_STRUCT
                ? duckdb_struct_type_child_count(type) : duckdb_union_type_member_count(type);
            for (idx_t i = 0; i < count; i++) {
                scoped_duckdb_logical_type child(id == DUCKDB_TYPE_STRUCT
                    ? duckdb_struct_type_child_type(type, i) : duckdb_union_type_member_type(type, i));
                if (!arrow_import_preserves_geometry_crs(child.get(), *schema.children[i])) {
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

std::shared_ptr<data_chunk_data> duckdb_import_arrow_chunk(conn_inner *conn,
                                                        const std::shared_ptr<arrow_chunk_data> &arrow) {
    if (!arrow || !arrow->array.release) {
        duckdb_throw_msg("Arrow chunk has already been consumed");
        return nullptr;
    }

    try {
        duckdb_scoped<duckdb_arrow_converted_schema, duckdb_destroy_arrow_converted_schema> converted;
        if (!duckdb_arrow_check_error(duckdb_schema_from_arrow(conn->conn, &arrow->schema->schema, converted.out()))) {
            return nullptr;
        }

        auto data = std::make_shared<data_chunk_data>();
        for (int64_t i = 0; i < arrow->schema->schema.n_children; i++) {
            const char *name = arrow->schema->schema.children[i]->name;
            data->names.emplace_back(name ? name : "");
        }

        data->arrow_facade = normalize_arrow_array(arrow->array, arrow->schema->schema);
        if (!data->arrow_facade) {
            return nullptr;
        }

        data->arrow_owner = std::make_shared<arrow_chunk_data>();
        data->arrow_owner->schema = arrow->schema;
        data->arrow_owner->array = arrow->array;
        data->arrow_owner->row_count = arrow->row_count;
        arrow->array.release = nullptr;

        ArrowArray borrowed = data->arrow_facade->array;
        if (!duckdb_arrow_check_error(duckdb_data_chunk_from_arrow(conn->conn, &borrowed, converted.get(), &data->chunk))) {
            return nullptr;
        }
        for (idx_t i = 0; i < data->names.size(); i++) {
            scoped_duckdb_logical_type type(duckdb_vector_get_column_type(duckdb_data_chunk_get_vector(data->chunk, i)));
            if (!arrow_import_preserves_geometry_crs(type.get(), *arrow->schema->schema.children[i])) {
                return nullptr;
            }
        }
        return data;
    } catch (const std::exception &error) {
        duckdb_throw_msg(error.what());
    } catch (...) {
        duckdb_throw_msg("Unknown error during Arrow chunk import");
    }
    return nullptr;
}

PHP_METHOD(DuckDB_Connection, dataChunkFromArrow) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *chunk;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(chunk, duckdb_arrow_chunk_class_entry())
    ZEND_PARSE_PARAMETERS_END();

    auto conn = Z_DUCKDB_CONNECTION_P(ZEND_THIS)->inner;
    if (!duckdb_connection_guard(conn)) {
        RETURN_THROWS();
    }
    auto arrow = duckdb_arrow_chunk_from_zval(chunk);
    if (!arrow) {
        RETURN_THROWS();
    }
    std::unique_lock<std::mutex> lock;
    if (!duckdb_conn_enter(*conn, lock)) {
        RETURN_THROWS();
    }
    auto data = duckdb_import_arrow_chunk(conn.get(), arrow);
    if (!data) {
        RETURN_THROWS();
    }
    object_init_ex(return_value, duckdb_data_chunk_ce);
    data_chunk_object(Z_OBJ_P(return_value))->data = std::move(data);
}

PHP_METHOD(DuckDB_DataChunk, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    zend_throw_error(nullptr, "DuckDB\\DataChunk objects must be created via "
                              "Connection::dataChunkFromArrow() or DataChunk::fromVectors()");
}

PHP_METHOD(DuckDB_DataChunk, fromVectors) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    HashTable *columns;
    zend_long row_count;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ARRAY_HT(columns)
        Z_PARAM_LONG(row_count)
    ZEND_PARSE_PARAMETERS_END();

    if (zend_hash_num_elements(columns) == 0) {
        zend_argument_value_error(1, "must contain at least one vector");
        RETURN_THROWS();
    }
    if (row_count < 0 || static_cast<idx_t>(row_count) > duckdb_vector_size()) {
        zend_argument_value_error(2, "must be between 0 and " ZEND_ULONG_FMT,
                                  static_cast<zend_ulong>(duckdb_vector_size()));
        RETURN_THROWS();
    }

    auto data = std::make_shared<data_chunk_data>();
    std::vector<std::shared_ptr<vector_data>> vectors;
    std::vector<duckdb_logical_type> types;
    zend_string *name;
    zval *column;
    ZEND_HASH_FOREACH_STR_KEY_VAL(columns, name, column) {
        if (!name) {
            zend_argument_value_error(1, "must use column names as keys");
            RETURN_THROWS();
        }
        ZVAL_DEREF(column);
        if (Z_TYPE_P(column) != IS_OBJECT || !instanceof_function(Z_OBJCE_P(column), duckdb_vector_ce)) {
            zend_argument_type_error(1, "must contain only DuckDB\\Vector values");
            RETURN_THROWS();
        }
        auto vector = duckdb_vector_from_zval(column);
        if (!vector) {
            RETURN_THROWS();
        }
        if (vector->capacity < static_cast<idx_t>(row_count)) {
            zend_argument_value_error(1, "must contain vectors with a capacity of at least " ZEND_LONG_FMT
                                      ", column \"%s\" has " ZEND_ULONG_FMT,
                                      row_count, ZSTR_VAL(name), static_cast<zend_ulong>(vector->capacity));
            RETURN_THROWS();
        }
        data->names.emplace_back(ZSTR_VAL(name), ZSTR_LEN(name));
        types.push_back(vector->type.get());
        vectors.push_back(std::move(vector));
    }
    ZEND_HASH_FOREACH_END();

    try {
        data->chunk = duckdb_create_data_chunk(types.data(), types.size());
        if (!data->chunk) {
            duckdb_throw_msg("DuckDB could not allocate the data chunk");
            RETURN_THROWS();
        }
        /* Copy rather than reference: later vector writes must not change
         * a chunk that may already have been appended or exported. */
        for (idx_t i = 0; i < vectors.size(); i++) {
            duckdb_vector_copy_rows(vectors[i]->vector, duckdb_data_chunk_get_vector(data->chunk, i), 0,
                                    static_cast<idx_t>(row_count), 0);
        }
        duckdb_data_chunk_set_size(data->chunk, static_cast<idx_t>(row_count));
    } catch (const std::exception &error) {
        duckdb_throw_msg(error.what());
        RETURN_THROWS();
    } catch (...) {
        duckdb_throw_msg("Unknown error while building a data chunk");
        RETURN_THROWS();
    }
    object_init_ex(return_value, duckdb_data_chunk_ce);
    data_chunk_object(Z_OBJ_P(return_value))->data = std::move(data);
}

PHP_METHOD(DuckDB_DataChunk, vector) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long index;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(index)
    ZEND_PARSE_PARAMETERS_END();
    auto data = duckdb_data_chunk_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    if (index < 0 || static_cast<idx_t>(index) >= data->names.size()) {
        zend_argument_value_error(1, "must be between 0 and %d", static_cast<int>(data->names.size()) - 1);
        RETURN_THROWS();
    }

    try {
        duckdb_vector column = duckdb_data_chunk_get_vector(data->chunk, static_cast<idx_t>(index));
        scoped_duckdb_logical_type type(duckdb_vector_get_column_type(column));
        idx_t size = duckdb_data_chunk_get_size(data->chunk);
        auto vector = duckdb_vector_allocate(type.get(), size);
        if (!vector) {
            RETURN_THROWS();
        }
        duckdb_vector_copy_rows(column, vector->vector, 0, size, 0);
        duckdb_vector_wrap(return_value, std::move(vector));
    } catch (const std::exception &error) {
        duckdb_throw_msg(error.what());
        RETURN_THROWS();
    } catch (...) {
        duckdb_throw_msg("Unknown error while copying a chunk column");
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_DataChunk, select) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zval *selection_zval;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(selection_zval)
    ZEND_PARSE_PARAMETERS_END();
    auto source = duckdb_data_chunk_from_zval(ZEND_THIS);
    if (!source) {
        RETURN_THROWS();
    }

    try {
        auto selection = duckdb_selection_from_arg(selection_zval, 1);
        if (!selection) {
            RETURN_THROWS();
        }
        if (selection->count > duckdb_vector_size()) {
            zend_argument_value_error(1, "must contain at most " ZEND_ULONG_FMT " indices",
                                      static_cast<zend_ulong>(duckdb_vector_size()));
            RETURN_THROWS();
        }
        if (!duckdb_selection_check_source(*selection, duckdb_data_chunk_get_size(source->chunk), 1)) {
            RETURN_THROWS();
        }

        std::vector<scoped_duckdb_logical_type> owned_types;
        std::vector<duckdb_logical_type> types;
        for (idx_t i = 0; i < source->names.size(); i++) {
            owned_types.emplace_back(duckdb_vector_get_column_type(duckdb_data_chunk_get_vector(source->chunk, i)));
            types.push_back(owned_types.back().get());
        }

        auto data = std::make_shared<data_chunk_data>();
        data->names = source->names;
        data->chunk = duckdb_create_data_chunk(types.data(), types.size());
        if (!data->chunk) {
            duckdb_throw_msg("DuckDB could not allocate the data chunk");
            RETURN_THROWS();
        }
        /* The copier writes string and list data into the new chunk, so it
         * needs no reference to an Arrow producer. */
        for (idx_t i = 0; i < types.size(); i++) {
            duckdb_selection_gather(duckdb_data_chunk_get_vector(source->chunk, i),
                                    duckdb_data_chunk_get_vector(data->chunk, i), *selection, 0);
        }
        duckdb_data_chunk_set_size(data->chunk, selection->count);

        object_init_ex(return_value, duckdb_data_chunk_ce);
        data_chunk_object(Z_OBJ_P(return_value))->data = std::move(data);
    } catch (const std::exception &error) {
        duckdb_throw_msg(error.what());
        RETURN_THROWS();
    } catch (...) {
        duckdb_throw_msg("Unknown error while selecting chunk rows");
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_DataChunk, rowCount) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto data = duckdb_data_chunk_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    RETURN_LONG(static_cast<zend_long>(duckdb_data_chunk_get_size(data->chunk)));
}

PHP_METHOD(DuckDB_DataChunk, columnCount) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto data = duckdb_data_chunk_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    RETURN_LONG(static_cast<zend_long>(data->names.size()));
}

PHP_METHOD(DuckDB_DataChunk, columns) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto data = duckdb_data_chunk_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    array_init(return_value);
    for (idx_t i = 0; i < data->names.size(); i++) {
        scoped_duckdb_logical_type type(duckdb_vector_get_column_type(duckdb_data_chunk_get_vector(data->chunk, i)));
        std::string type_name = duckdb_logical_type_render(type.get());
        zval column;
        array_init(&column);
        add_assoc_stringl(&column, "name", data->names[i].data(), data->names[i].size());
        add_assoc_stringl(&column, "type", type_name.data(), type_name.size());
        add_next_index_zval(return_value, &column);
    }
}

PHP_METHOD(DuckDB_DataChunk, toRows) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_object *mode = nullptr;
    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJ_OF_CLASS(mode, duckdb_fetch_mode_ce)
    ZEND_PARSE_PARAMETERS_END();
    auto data = duckdb_data_chunk_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    duckdb_data_chunk_rows(data.get(), mode, return_value);
    if (EG(exception)) {
        RETURN_THROWS();
    }
}

static std::shared_ptr<arrow_schema_data> data_chunk_schema(data_chunk_data *data, duckdb_arrow_options options) {
    std::vector<scoped_duckdb_logical_type> owned_types;
    std::vector<duckdb_logical_type> types;
    std::vector<const char *> names;
    for (idx_t i = 0; i < data->names.size(); i++) {
        owned_types.emplace_back(duckdb_vector_get_column_type(duckdb_data_chunk_get_vector(data->chunk, i)));
        types.push_back(owned_types.back().get());
        names.push_back(data->names[i].c_str());
    }
    auto schema = std::make_shared<arrow_schema_data>();
    if (!duckdb_arrow_check_error(duckdb_to_arrow_schema(options, types.data(), names.data(), names.size(), &schema->schema))) {
        return nullptr;
    }
    for (idx_t i = 0; i < types.size(); i++) {
        if (!duckdb_arrow_require_lossless(types[i], *schema->schema.children[i])) {
            return nullptr;
        }
    }
    return schema;
}

static void data_chunk_export(INTERNAL_FUNCTION_PARAMETERS, bool with_array) {
    zval *connection;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(connection, duckdb_connection_ce)
    ZEND_PARSE_PARAMETERS_END();
    auto data = duckdb_data_chunk_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    auto conn = Z_DUCKDB_CONNECTION_P(connection)->inner;
    if (!duckdb_connection_guard(conn)) {
        RETURN_THROWS();
    }
    std::unique_lock<std::mutex> lock;
    if (!duckdb_conn_enter(*conn, lock)) {
        RETURN_THROWS();
    }
    duckdb_scoped<duckdb_arrow_options, duckdb_destroy_arrow_options> options;
    duckdb_connection_get_arrow_options(conn->conn, options.out());
    auto schema = data_chunk_schema(data.get(), options.get());
    if (!schema) {
        RETURN_THROWS();
    }
    if (!with_array) {
        duckdb_arrow_schema_wrap(return_value, schema);
        return;
    }
    auto arrow = std::make_shared<arrow_chunk_data>();
    arrow->schema = schema;
    if (!duckdb_arrow_check_error(duckdb_data_chunk_to_arrow(options.get(), data->chunk, &arrow->array))) {
        RETURN_THROWS();
    }
    arrow->row_count = duckdb_data_chunk_get_size(data->chunk);
    duckdb_arrow_chunk_wrap(return_value, arrow);
}

PHP_METHOD(DuckDB_DataChunk, arrowSchema) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    data_chunk_export(INTERNAL_FUNCTION_PARAM_PASSTHRU, false);
}

PHP_METHOD(DuckDB_DataChunk, toArrow) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    data_chunk_export(INTERNAL_FUNCTION_PARAM_PASSTHRU, true);
}
