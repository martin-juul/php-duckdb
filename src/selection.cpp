/* Selection vectors: immutable lists of source row indices that pick,
 * reorder or repeat the rows of vectors and data chunks. */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"
#include "php_duckdb.h"
#include "selection.h"

#include <exception>
#include <limits>

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

zend_class_entry *duckdb_selection_vector_ce;
static zend_object_handlers selection_vector_handlers;

/* Rows are indexed by 32-bit entries, and a vector holds at most
 * UINT32_MAX rows, so the largest row index is one less. */
static constexpr zend_long max_selection_index = static_cast<zend_long>((std::numeric_limits<uint32_t>::max)()) - 1;

struct php_selection_vector_object {
    std::shared_ptr<const selection_data> data;
    zend_object std;
};

static php_selection_vector_object *selection_vector_object(zend_object *obj) {
    return reinterpret_cast<php_selection_vector_object *>(reinterpret_cast<char *>(obj) -
                                                           offsetof(php_selection_vector_object, std));
}

static zend_object *selection_vector_create(zend_class_entry *ce) {
    auto *obj = static_cast<php_selection_vector_object *>(zend_object_alloc(sizeof(php_selection_vector_object), ce));
    new (&obj->data) std::shared_ptr<const selection_data>();
    zend_object_std_init(&obj->std, ce);
    object_properties_init(&obj->std, ce);
    obj->std.handlers = &selection_vector_handlers;
    return &obj->std;
}

static void selection_vector_free(zend_object *obj) {
    selection_vector_object(obj)->data.~shared_ptr();
    zend_object_std_dtor(obj);
}

void duckdb_register_selection_vector_class(zend_class_entry *ce) {
    duckdb_selection_vector_ce = ce;
    ce->create_object = selection_vector_create;
    ce->ce_flags |= ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;
    memcpy(&selection_vector_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    selection_vector_handlers.offset = offsetof(php_selection_vector_object, std);
    selection_vector_handlers.free_obj = selection_vector_free;
    selection_vector_handlers.clone_obj = nullptr;
}

static std::shared_ptr<const selection_data> selection_vector_from_zval(zval *value) {
    auto data = selection_vector_object(Z_OBJ_P(value))->data;
    if (!duckdb_initialized_guard(static_cast<bool>(data), "DuckDB\\SelectionVector")) {
        return nullptr;
    }
    return data;
}

/* Validate every element before allocating, so a rejected list allocates
 * nothing. The native allocation may throw std::bad_alloc. */
static std::shared_ptr<const selection_data> selection_from_list(HashTable *indices, uint32_t arg_num) {
    if (!zend_array_is_list(indices)) {
        zend_argument_value_error(arg_num, "must be a list");
        return nullptr;
    }

    zval *entry;
    ZEND_HASH_FOREACH_VAL(indices, entry) {
        ZVAL_DEREF(entry);
        if (Z_TYPE_P(entry) != IS_LONG) {
            zend_argument_type_error(arg_num, "must contain only integers");
            return nullptr;
        }
        if (Z_LVAL_P(entry) < 0 || Z_LVAL_P(entry) > max_selection_index) {
            zend_argument_value_error(arg_num, "must contain only integers between 0 and " ZEND_LONG_FMT,
                                      max_selection_index);
            return nullptr;
        }
    }
    ZEND_HASH_FOREACH_END();

    auto data = std::make_shared<selection_data>();
    data->count = zend_hash_num_elements(indices);
    data->selection.reset(duckdb_create_selection_vector(data->count));
    if (!data->selection) {
        throw std::bad_alloc();
    }

    sel_t *out = duckdb_selection_vector_get_data_ptr(data->selection.get());
    idx_t position = 0;
    ZEND_HASH_FOREACH_VAL(indices, entry) {
        ZVAL_DEREF(entry);
        idx_t index = static_cast<idx_t>(Z_LVAL_P(entry));
        out[position++] = static_cast<sel_t>(index);
        if (index > data->max_index) {
            data->max_index = index;
        }
    }
    ZEND_HASH_FOREACH_END();
    return data;
}

std::shared_ptr<const selection_data> duckdb_selection_from_arg(zval *value, uint32_t arg_num) {
    ZVAL_DEREF(value);
    if (Z_TYPE_P(value) == IS_OBJECT && instanceof_function(Z_OBJCE_P(value), duckdb_selection_vector_ce)) {
        return selection_vector_from_zval(value);
    }
    if (Z_TYPE_P(value) == IS_ARRAY) {
        return selection_from_list(Z_ARRVAL_P(value), arg_num);
    }

    const char *given = Z_TYPE_P(value) == IS_OBJECT ? ZSTR_VAL(Z_OBJCE_P(value)->name) : zend_zval_type_name(value);
    zend_argument_type_error(arg_num, "must be of type DuckDB\\SelectionVector|array, %s given", given);
    return nullptr;
}

PHP_METHOD(DuckDB_SelectionVector, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    HashTable *indices;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY_HT(indices)
    ZEND_PARSE_PARAMETERS_END();

    auto *obj = selection_vector_object(Z_OBJ_P(ZEND_THIS));
    if (obj->data) {
        zend_throw_error(nullptr, "DuckDB\\SelectionVector object is already initialized");
        RETURN_THROWS();
    }

    try {
        auto data = selection_from_list(indices, 1);
        if (!data) {
            RETURN_THROWS();
        }
        obj->data = std::move(data);
    } catch (const std::exception &error) {
        duckdb_throw_msg(error.what());
        RETURN_THROWS();
    } catch (...) {
        duckdb_throw_msg("Unknown error while creating a selection vector");
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_SelectionVector, count) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto data = selection_vector_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    RETURN_LONG(static_cast<zend_long>(data->count));
}

PHP_METHOD(DuckDB_SelectionVector, get) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long position;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(position)
    ZEND_PARSE_PARAMETERS_END();
    auto data = selection_vector_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }
    if (position < 0 || static_cast<idx_t>(position) >= data->count) {
        if (data->count == 0) {
            zend_argument_value_error(1, "must be a position in a non-empty selection");
        } else {
            zend_argument_value_error(1, "must be between 0 and " ZEND_ULONG_FMT,
                                      static_cast<zend_ulong>(data->count - 1));
        }
        RETURN_THROWS();
    }
    sel_t *indices = duckdb_selection_vector_get_data_ptr(data->selection.get());
    RETURN_LONG(static_cast<zend_long>(indices[position]));
}

PHP_METHOD(DuckDB_SelectionVector, toArray) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto data = selection_vector_from_zval(ZEND_THIS);
    if (!data) {
        RETURN_THROWS();
    }

    array_init_size(return_value, static_cast<uint32_t>(data->count));
    if (data->count == 0) {
        return;
    }
    sel_t *indices = duckdb_selection_vector_get_data_ptr(data->selection.get());
    for (idx_t i = 0; i < data->count; i++) {
        add_next_index_long(return_value, static_cast<zend_long>(indices[i]));
    }
}
