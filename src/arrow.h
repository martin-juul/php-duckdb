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

/* Producer callbacks may execute PHP, including nested autoload callbacks.
 * Zend's exception_save/restore pair uses a single global prev_exception
 * slot, so placing our pending exception there would expose it to nested
 * restores. Hold both slots locally, as Zend does for object destructors. */
class arrow_release_exception_scope {
public:
    arrow_release_exception_scope()
        : exception_(EG(exception)), previous_exception_(EG(prev_exception)),
          opline_before_exception_(EG(opline_before_exception)) {
        EG(exception) = nullptr;
        EG(prev_exception) = nullptr;
    }

    arrow_release_exception_scope(const arrow_release_exception_scope &) = delete;
    arrow_release_exception_scope &operator=(const arrow_release_exception_scope &) = delete;

    ~arrow_release_exception_scope() {
        /* Finish any callback-local saved exception before restoring the
         * caller's independent saved state. Retain newly raised exceptions
         * and chain the original exception rather than discarding either. */
        if (EG(prev_exception)) {
            if (EG(exception)) {
                zend_exception_set_previous(EG(exception), EG(prev_exception));
            } else {
                EG(exception) = EG(prev_exception);
            }
            EG(prev_exception) = nullptr;
        }
        if (exception_) {
            if (EG(exception)) {
                zend_exception_set_previous(EG(exception), exception_);
            } else {
                EG(exception) = exception_;
            }
        }
        EG(prev_exception) = previous_exception_;
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
