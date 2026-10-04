#ifndef PHP_DUCKDB_ARROW_H
#define PHP_DUCKDB_ARROW_H

#include "php_duckdb.h"

/* Apache Arrow C Data Interface ABI. DuckDB's public header only forward
 * declares these structures. */
#ifndef ARROW_C_DATA_INTERFACE
#define ARROW_C_DATA_INTERFACE
struct ArrowSchema {
    const char *format;
    const char *name;
    const char *metadata;
    int64_t flags;
    int64_t n_children;
    ArrowSchema **children;
    ArrowSchema *dictionary;
    void (*release)(ArrowSchema *);
    void *private_data;
};

struct ArrowArray {
    int64_t length;
    int64_t null_count;
    int64_t offset;
    int64_t n_buffers;
    int64_t n_children;
    const void **buffers;
    ArrowArray **children;
    ArrowArray *dictionary;
    void (*release)(ArrowArray *);
    void *private_data;
};
#endif

/* PHP 8.6 removed the global saved-exception slot. Detect its presence
 * from the actual headers, including development and custom runtimes. */
template <typename Globals>
auto duckdb_arrow_previous_exception_slot(Globals *globals, int)
    -> decltype(&globals->prev_exception) {
    return &globals->prev_exception;
}

template <typename Globals>
zend_object **duckdb_arrow_previous_exception_slot(Globals *, long) {
    return nullptr;
}

/* Producer callbacks may execute PHP, including nested autoload callbacks.
 * On runtimes with a global saved-exception slot, detach it as well as the
 * pending exception so nested restores cannot expose the caller's state.
 * Hold the exception objects locally, as Zend does for object destructors. */
class arrow_release_exception_scope {
public:
    arrow_release_exception_scope()
        : exception_(EG(exception)), previous_exception_(nullptr),
          opline_before_exception_(EG(opline_before_exception)) {
        zend_object **previous_slot =
            duckdb_arrow_previous_exception_slot(ZEND_MODULE_GLOBALS_BULK(executor), 0);
        if (previous_slot) {
            previous_exception_ = *previous_slot;
            *previous_slot = nullptr;
        }
        EG(exception) = nullptr;
    }

    arrow_release_exception_scope(const arrow_release_exception_scope &) = delete;
    arrow_release_exception_scope &operator=(const arrow_release_exception_scope &) = delete;

    ~arrow_release_exception_scope() {
        /* Finish any callback-local saved exception before restoring the
         * caller's independent saved state. Retain newly raised exceptions
         * and chain the original exception rather than discarding either. */
        zend_object **previous_slot =
            duckdb_arrow_previous_exception_slot(ZEND_MODULE_GLOBALS_BULK(executor), 0);
        if (previous_slot && *previous_slot) {
            if (EG(exception)) {
                zend_exception_set_previous(EG(exception), *previous_slot);
            } else {
                EG(exception) = *previous_slot;
            }
            *previous_slot = nullptr;
        }
        if (exception_) {
            if (EG(exception)) {
                zend_exception_set_previous(EG(exception), exception_);
            } else {
                EG(exception) = exception_;
            }
        }
        if (previous_slot) {
            *previous_slot = previous_exception_;
        }
        if (exception_ || previous_exception_) {
            EG(opline_before_exception) = opline_before_exception_;
        }
    }

private:
    zend_object *exception_;
    zend_object *previous_exception_;
    const zend_op *opline_before_exception_;
};

struct arrow_schema_data {
    ArrowSchema schema = {};

    ~arrow_schema_data() {
        if (schema.release) {
            arrow_release_exception_scope exception_scope;
            schema.release(&schema);
        }
    }
};

struct arrow_chunk_data {
    ArrowArray array = {};
    std::shared_ptr<arrow_schema_data> schema;
    idx_t row_count = 0;

    ~arrow_chunk_data() {
        if (array.release) {
            arrow_release_exception_scope exception_scope;
            array.release(&array);
        }
    }
};

zend_class_entry *duckdb_arrow_schema_class_entry();
zend_class_entry *duckdb_arrow_chunk_class_entry();
std::shared_ptr<arrow_schema_data> duckdb_arrow_schema_from_zval(zval *value);
std::shared_ptr<arrow_chunk_data> duckdb_arrow_chunk_from_zval(zval *value);
void duckdb_arrow_schema_wrap(zval *out, std::shared_ptr<arrow_schema_data> data);
void duckdb_arrow_chunk_wrap(zval *out, std::shared_ptr<arrow_chunk_data> data);
void duckdb_register_arrow_classes(zend_class_entry *schema_ce, zend_class_entry *chunk_ce);
bool duckdb_arrow_check_error(duckdb_error_data error);
bool duckdb_arrow_extension_metadata(const ArrowSchema &schema, std::string &extension_name,
                                     std::string &extension_metadata);
bool duckdb_arrow_require_lossless(duckdb_logical_type type, const ArrowSchema &schema);

#endif
