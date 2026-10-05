#ifndef PHP_DUCKDB_VECTOR_H
#define PHP_DUCKDB_VECTOR_H

#include "data_chunk.h"

/* Writes of plain PHP scalars that DuckDB would cast without changing their
 * value bypass SQL conversion. Every other input uses typed conversion. */
enum class vector_fast_kind {
    none,
    boolean,
    int8,
    int16,
    int32,
    int64,
    uint8,
    uint16,
    uint32,
    uint64,
    float64,
    varchar,
    blob,
};

/* An owned flat vector. Public operations keep it flat: values are written
 * in place or copied in with DuckDB's vector copier, never referenced. */
struct vector_data {
    duckdb_vector vector = nullptr;
    scoped_duckdb_logical_type type;
    /* SQL declaration of `type`, used to convert input on any connection. */
    std::string declaration;
    idx_t capacity = 0;
    vector_fast_kind fast = vector_fast_kind::none;

    ~vector_data() {
        if (vector) {
            duckdb_destroy_vector(&vector);
        }
    }
};

extern zend_class_entry *duckdb_vector_ce;
void duckdb_register_vector_class(zend_class_entry *ce);
std::shared_ptr<vector_data> duckdb_vector_from_zval(zval *value);
void duckdb_vector_wrap(zval *return_value, std::shared_ptr<vector_data> data);
/* Wrap an uninitialized native vector of `type`; the caller fills every row. */
std::shared_ptr<vector_data> duckdb_vector_allocate(duckdb_logical_type type, idx_t capacity);
bool duckdb_vector_types_equal(duckdb_logical_type a, duckdb_logical_type b);
/* Copy rows with DuckDB's copier, which flattens any source vector layout. */
void duckdb_vector_copy_rows(duckdb_vector source, duckdb_vector target, idx_t source_offset, idx_t count,
                             idx_t target_offset);

#endif
