# Custom logging

`DuckDB\LogStorage` buffers DuckDB log entries in native memory. Register a
named storage on a `Database`, select it through SQL, then drain it explicitly
from PHP:

```php
$db = new DuckDB\Database();
$storage = new DuckDB\LogStorage(capacity: 1024);
$db->registerLogStorage('application', $storage);
$conn = $db->connect();
$conn->query("SET logging_storage = 'application'");
$conn->query('SET enable_logging = true');
$conn->query("SELECT write_log('report complete')");
foreach ($storage->drain() as $entry) {
    printf("%s: %s\n", $entry['level'], $entry['message']);
}
```

Registration and activation apply to the underlying database instance. Its
connections share the selected storage. Handles that open the same database
file in one process can share that instance; independent in-memory databases do
not. Use distinct names for independently registered sinks. Empty names, names
containing NUL bytes, duplicate registrations and the reserved names `memory`,
`stdout` and `file` throw `ValueError`. Reserved-name comparisons ignore case.
A native registration failure throws `DuckDB\Exception`.

## Records and limits

`drain(): array` removes the currently buffered records and returns a list of
arrays with these fields:

| Field | PHP type | Meaning |
| --- | --- | --- |
| `timestamp` | `int` | Unix timestamp in microseconds, captured by DuckDB |
| `level` | `string` | DuckDB severity text |
| `type` | `string` | DuckDB log type |
| `message` | `string` | Copied message text |

The constructor's positive `$capacity` defaults to 1024 records. The buffer also
limits native record data to 8 MiB. When a new record exceeds either limit,
older records are evicted; a record larger than 8 MiB is discarded.
`droppedCount(): int` reports cumulative evictions and discarded oversized
records, as well as entries discarded when a concurrent drain briefly holds the
buffer lock. Draining does not reset this count. Drain regularly during long jobs.

Native worker callbacks copy log data into the buffer without executing PHP.
`drain()` makes ordinary PHP arrays on the calling thread, so application code
can forward entries to its logger or a custom sink. No PSR logging package is
required. A sink exception occurs in the application's draining code and does
not propagate through a DuckDB worker callback.

## Lifetime and asynchronous work

The database retains registered native storage after the PHP storage wrapper is
released. Keep the wrapper while you need to drain it. Connections and pending
queries retain their database until their work and cleanup finish. A storage
wrapper can still drain its copied records after the database is released.

Synchronous, prepared, streaming, pending and background queries use the same
native logging path. For a background job, drain while polling and once more
after completion. Draining during a running query returns a snapshot; later
records appear in a subsequent drain. Entries reflect buffer arrival order;
there is no global timestamp ordering guarantee across concurrent workers.

Register storages before starting concurrent queries or changing logging
settings on other connections. DuckDB's storage registration API does not
synchronize registration with concurrent logging configuration changes.

See [the runnable logging example](../examples/logging.php) for a custom sink
bridge and background-query polling, and [the API reference](api.md) for the
complete method signatures.
