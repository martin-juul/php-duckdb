#ifndef PHP_DUCKDB_DATA_CHUNK_H
#define PHP_DUCKDB_DATA_CHUNK_H

#include "arrow.h"

/* Selection vectors are the C API exception: destroy takes the handle by
 * value, whereas duckdb_scoped adapts pointer-to-handle destroy functions. */
inline void duckdb_destroy_owned_selection(duckdb_selection_vector *selection) {
    duckdb_destroy_selection_vector(*selection);
    *selection = nullptr;
}

using scoped_duckdb_selection = duckdb_scoped<duckdb_selection_vector, duckdb_destroy_owned_selection>;

struct arrow_array_facade {
    ArrowArray array = {};
    std::vector<std::shared_ptr<arrow_array_facade>> children;
    std::vector<ArrowArray *> child_pointers;
    std::shared_ptr<arrow_array_facade> dictionary;
    std::vector<const void *> buffers;
    std::vector<uint8_t> validity;
};

struct data_chunk_data {
    duckdb_data_chunk chunk = nullptr;
    std::vector<std::string> names;
    /* Keep the producer's buffers alive even when DuckDB materializes some
     * columns and borrows others. Destroy the native chunk before its owner. */
    std::shared_ptr<arrow_chunk_data> arrow_owner;
    /* Normalize struct slices without changing producer-owned arrays. */
    std::shared_ptr<arrow_array_facade> arrow_facade;

    ~data_chunk_data() {
        if (chunk) {
            duckdb_destroy_data_chunk(&chunk);
        }
    }
};

extern zend_class_entry *duckdb_data_chunk_ce;
void duckdb_register_data_chunk_class(zend_class_entry *ce);
std::shared_ptr<data_chunk_data> duckdb_data_chunk_from_zval(zval *value);
std::shared_ptr<data_chunk_data> duckdb_import_arrow_chunk(conn_inner *conn,
                                                        const std::shared_ptr<arrow_chunk_data> &arrow);
void duckdb_data_chunk_rows(data_chunk_data *data, zend_object *mode, zval *return_value);

#endif
