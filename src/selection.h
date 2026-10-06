#ifndef PHP_DUCKDB_SELECTION_H
#define PHP_DUCKDB_SELECTION_H

#include "php_duckdb.h"

/* Selection vectors are the C API exception: destroy takes the handle by
 * value, whereas duckdb_scoped adapts pointer-to-handle destroy functions. */
inline void duckdb_destroy_owned_selection(duckdb_selection_vector *selection) {
    duckdb_destroy_selection_vector(*selection);
    *selection = nullptr;
}

using scoped_duckdb_selection = duckdb_scoped<duckdb_selection_vector, duckdb_destroy_owned_selection>;

/* An immutable list of source row indices. The largest index is cached so
 * each operation checks its source bound once. */
struct selection_data {
    scoped_duckdb_selection selection;
    idx_t count = 0;
    /* Meaningless when count is 0. */
    idx_t max_index = 0;
};

extern zend_class_entry *duckdb_selection_vector_ce;
void duckdb_register_selection_vector_class(zend_class_entry *ce);
/* Accept a SelectionVector or a list of row indices. Throws and returns
 * nullptr for any other argument; may throw std::bad_alloc. */
std::shared_ptr<const selection_data> duckdb_selection_from_arg(zval *value, uint32_t arg_num);

#endif
