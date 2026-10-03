#ifndef PHP_DUCKDB_BIGNUM_DECODE_H
#define PHP_DUCKDB_BIGNUM_DECODE_H

#include "php_duckdb_cxx_compat.h"
#include <duckdb.h>

/* Decode a non-NULL BIGNUM cell as an exact decimal PHP string. The caller
 * owns the vector/chunk and validates the row index and NULL validity. */
bool duckdb_decode_bignum(duckdb_vector vec, idx_t row, zval *out);

#endif
