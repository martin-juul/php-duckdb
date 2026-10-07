# Selection vectors

These APIs are under development in this checkout. They are not included in
the released 1.3.1 archive.

A selection vector is a list of source row indices. DuckDB uses it to pick,
reorder or repeat the rows of a [vector](vector.md) without decoding them.
`DuckDB\SelectionVector` holds such a list natively, and three operations use
it to copy rows:

| Method | Result |
| --- | --- |
| `Vector::select(SelectionVector\|array $selection): Vector` | A new vector holding the selected rows |
| `Vector::copySelected(Vector $source, SelectionVector\|array $selection, int $targetOffset = 0): void` | The selected source rows, written into this vector |
| `DataChunk::select(SelectionVector\|array $selection): DataChunk` | A new chunk holding the selected rows of every column |

Every operation accepts a `SelectionVector` or a plain list of row indices.
Results are ordinary flat vectors and chunks, so every existing operation
works on them unchanged.

## Create a selection

```php
$selection = new DuckDB\SelectionVector([3, 0, 0, 7]);

count($selection);      // 4
$selection->get(0);     // 3
$selection->toArray();  // [3, 0, 0, 7]
```

`new DuckDB\SelectionVector(array $indices)` needs no connection. The input
must be a list of PHP integers from 0 to 4294967294. Indices may repeat and
appear in any order, so a selection may be longer than its source. Numeric
strings, floats, booleans and `null` are rejected rather than converted.

A selection is immutable and implements `Countable`. `get(int $position)`
reads the index at a 0-based position, and `toArray()` returns every index.
Calling the constructor again on an existing selection throws `Error`.

## Select vector rows

```php
$prices = $conn->createVector('DECIMAL(10,2)', 3);
$prices->setValues($conn, ['10.00', '20.00', '30.00']);

$prices->select([2, 0, 0])->toArray();  // ['30.00', '10.00', '10.00']
```

`select()` returns a new vector of the same type whose capacity is the
selection length. Row `i` of the result holds source row `selection[i]`.

`copySelected()` gathers rows into an existing vector instead. It writes
source row `selection[i]` into target row `targetOffset + i` and leaves every
other target row unchanged. The source must have the same type as the target,
as for `copyFrom()`. A vector may gather from itself; the gather reads the
original rows even when it overwrites them.

```php
$target = $conn->createVector('DECIMAL(10,2)', 5);
$target->copySelected($prices, [2, 0], 1);
$target->toArray();  // [null, '30.00', '10.00', null, null]
```

Only gathers are supported. A scatter that writes source row `i` to target
row `selection[i]` has no C API equivalent.

## Filter chunks

`DataChunk::select()` copies the selected rows of every column into a new
chunk with the same column names and types. The new chunk holds its own copy
of the data, so it does not depend on an Arrow producer, the source chunk or a
connection. It can be appended with `Appender::appendChunk()`, decoded with
`toRows()` or exported with `toArrow()`.

A chunk holds at most `DuckDB\vectorSize()` rows, so a selection used with
`DataChunk::select()` may hold at most that many indices. Its indices are
checked against the source chunk's row count. A chunk imported from an
external Arrow batch can hold more rows than one vector. Select from such a
chunk in batches:

```php
$chunk = $conn->dataChunkFromArrow($batch);
$amounts = $chunk->vector(2)->toArray();
$valid = array_keys(array_filter($amounts, fn(?string $amount) => $amount !== null));

foreach (array_chunk($valid, DuckDB\vectorSize()) as $rows) {
    $appender->appendChunk($chunk->select($rows));
}
```

`array_filter()` keeps the original keys. Pass its keys, not the filtered
rows, as the selection.

## Validation

Each operation checks its input before writing any row, so a rejected call
changes nothing.

| Input | Exception |
| --- | --- |
| A selection argument that is not a `SelectionVector` or an array | `TypeError` |
| An array that is not a list | `ValueError` |
| A list element that is not an integer | `TypeError` |
| An index outside 0 to 4294967294 | `ValueError` |
| More than `vectorSize()` indices for `DataChunk::select()` | `ValueError` |
| An index at or beyond the source row count | `ValueError` |
| A target range beyond the target capacity | `ValueError` |
| A `copySelected()` source of another type | `TypeError` |

`DataChunk::select()` throws `DuckDB\Exception` for a chunk without columns,
which DuckDB cannot allocate.

## Memory and lifetime

A selection owns its native index buffer, four bytes per index. Like vector
memory, it does not count toward PHP's `memory_limit`. It remains usable after
every connection and database closes. Selections are final and cannot be
cloned or serialized, and a selection must not be shared between threads.

A plain list is converted and validated on every call. Build a
`SelectionVector` to apply the same rows to several vectors or chunks; its
largest index is checked once per operation.

Gathering VARCHAR or LIST values into the same target repeatedly grows its
storage, as repeated `set()` calls do. Overwritten values keep their previous
storage until the vector is freed.

## C API correspondence

| C API | PHP |
| --- | --- |
| `duckdb_create_selection_vector()`, `duckdb_destroy_selection_vector()` | `new SelectionVector()`; freed with the object |
| `duckdb_selection_vector_get_data_ptr()` | Filled by the constructor; read by `get()` and `toArray()` |
| `duckdb_vector_copy_sel()` | `Vector::select()`, `Vector::copySelected()`, `DataChunk::select()` |
| `duckdb_slice_vector()` | Not exposed; vectors stay flat |

`duckdb_slice_vector()` turns a vector into a dictionary vector that shares
its source's data. PHP vectors stay flat so that writes, reads and chunk
copies work the same on every vector; selections copy rows instead.

See the runnable [selection example](../examples/selection.php).
