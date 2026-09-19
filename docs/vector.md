# Vectors

PHP counterpart of the C API's vector interface (`duckdb_vector`,
`duckdb_vector_get_data()`, `duckdb_vector_get_validity()`,
`duckdb_vector_get_column_type()`, …).

## Vectors are internal to the driver

Vectors are the columnar storage inside a [data chunk](data_chunk.md): one
contiguous buffer per column plus a validity bitmask for NULLs. The C API
exposes them for writing custom functions and for zero-copy result
consumption.

This driver decodes vectors straight into PHP values inside the extension, so
**no vector API is exposed** — you always see plain PHP arrays. Everything the
vector interface exists for is handled for you:

| C vector concern | How the PHP driver handles it |
|---|---|
| Validity bitmask (`duckdb_vector_get_validity()`) | NULLs decode to PHP `null` transparently |
| `string_t` inline/heap layout | All strings (VARCHAR, BLOB, BIT) decode to binary-safe PHP strings |
| Dictionary/constant/flat vector layouts | An internal detail; every layout decodes identically |
| DECIMAL internal widths (int16/32/64/hugeint by precision) | Decoded to exact decimal strings |
| ENUM dictionary sizes (uint8/16/32) | Decoded to the member label string |
| Nested vectors (LIST/STRUCT/MAP/UNION) | Recursively decoded to PHP arrays (depth-capped at 512) |

## If you were hoping to…

- **…read results without per-row overhead** — use buffered
  `query()`/`fetchAll()`; decoding is done in tight C++ loops either way.
- **…write a custom function over vectors** — that requires C UDF support,
  which is not part of this driver; see
  [table_functions.md](table_functions.md).
- **…feed columnar data in bulk** — use the [Appender](appender.md) (row-wise
  but batched) or `COPY`/`read_parquet()`/`read_csv()` for file sources.
