#ifndef PHP_DUCKDB_VARIANT_DECODE_H
#define PHP_DUCKDB_VARIANT_DECODE_H
#include "php_duckdb_cxx_compat.h"
#include <duckdb.h>
/* Render a cell from a flattened fetched chunk as JSON. The caller owns the
 * chunk and validates the row index. SQL NULL remains PHP NULL. */
bool duckdb_decode_variant(duckdb_vector vector, idx_t row, zval *out);
#endif
