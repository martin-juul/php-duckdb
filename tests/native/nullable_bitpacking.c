/* Standalone DuckDB 1.5.6 checkpoint regression; no PHP extension involved.
 * Supply a new database path. Success means SQL completed; run under Memcheck
 * to detect the uninitialized compression bytes written during close.
 */
#include "duckdb.h"
#include <stdio.h>

int main(int argc, char **argv) {
    if (argc != 2) {
        fprintf(stderr, "Usage: %s NEW_DATABASE_PATH\n", argv[0]);
        return 2;
    }

    FILE *existing = fopen(argv[1], "rb");
    if (existing) {
        fclose(existing);
        fputs("Database path must not exist\n", stderr);
        return 2;
    }

    duckdb_config config = NULL;
    duckdb_database database = NULL;
    duckdb_connection connection = NULL;
    char *error = NULL;
    int status = 1;

    if (duckdb_create_config(&config) == DuckDBError ||
        duckdb_set_config(config, "threads", "1") == DuckDBError) {
        fputs("Cannot configure DuckDB\n", stderr);
        goto cleanup;
    }
    if (duckdb_open_ext(argv[1], &database, config, &error) == DuckDBError) {
        fprintf(stderr, "Cannot open DuckDB: %s\n", error ? error : "unknown error");
        goto cleanup;
    }
    if (duckdb_connect(database, &connection) == DuckDBError) {
        fputs("Cannot connect to DuckDB\n", stderr);
        goto cleanup;
    }

    duckdb_result result;
    if (duckdb_query(connection,
                     "CREATE TABLE ints AS SELECT CASE WHEN i%3=0 THEN NULL "
                     "ELSE i%100 END::UINTEGER AS v FROM range(1000) t(i)",
                     &result) == DuckDBError) {
        fprintf(stderr, "Query failed: %s\n", duckdb_result_error(&result));
        duckdb_destroy_result(&result);
        goto cleanup;
    }
    duckdb_destroy_result(&result);
    status = 0;

cleanup:
    duckdb_free(error);
    duckdb_destroy_config(&config);
    if (connection)
        duckdb_disconnect(&connection);
    if (database)
        duckdb_close(&database); /* checkpoint triggers the error */
    return status;
}
