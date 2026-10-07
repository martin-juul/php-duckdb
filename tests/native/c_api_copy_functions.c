/* Public-C-API regression for the C API copy-function binding patch; no PHP
 * dependency. Set DUCKDB_SDK to an absolute SDK directory:
 *
 * cc -std=c11 -Wall -Wextra -I"$DUCKDB_SDK/include" \
 *   tests/native/c_api_copy_functions.c -L"$DUCKDB_SDK/lib" \
 *   -Wl,-rpath,"$DUCKDB_SDK/lib" -lduckdb -o /tmp/c-api-copy-functions
 *
 * /tmp/c-api-copy-functions
 *
 * valgrind --error-exitcode=99 --errors-for-leak-kinds=definite \
 *   --leak-check=full /tmp/c-api-copy-functions
 *
 * The patched engine rejects PARTITION_BY, PER_THREAD_OUTPUT and EXPORT
 * DATABASE for C API copy functions at bind time. A bind callback declines a
 * binding by setting an error that starts with "[copy-function-declined]":
 * the format then behaves as if it were missing from the catalog. Built-in
 * formats keep their behavior. Each case prints "ok" or "FAIL"; the original
 * 1.5.6 engine fails the rejection and decline cases.
 */
#define _XOPEN_SOURCE 700
#include "duckdb.h"

#include <ftw.h>
#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define DECLINE_PREFIX "[copy-function-declined]"

typedef struct {
    idx_t binds;
    idx_t rows;
    idx_t finalized;
    char path[4096];
} format_state;

static int failures = 0;
static char directory[] = "/tmp/c-api-copy-functions.XXXXXX";

static void report(bool passed, const char *name, const char *detail) {
    if (passed) {
        printf("ok   %s\n", name);
        return;
    }

    failures++;
    printf("FAIL %s: %s\n", name, detail);
}

static void free_bind_data(void *data) {
    free(data);
}

static void accept_bind(duckdb_copy_function_bind_info info) {
    format_state *state = duckdb_copy_function_bind_get_extra_info(info);
    state->binds++;

    /* Owned bind data must be released when the binder rejects the plan. */
    idx_t *columns = malloc(sizeof(idx_t));
    *columns = duckdb_copy_function_bind_get_column_count(info);
    duckdb_copy_function_bind_set_bind_data(info, columns, free_bind_data);
}

static void decline_bind(duckdb_copy_function_bind_info info) {
    format_state *state = duckdb_copy_function_bind_get_extra_info(info);
    state->binds++;

    duckdb_copy_function_bind_set_bind_data(info, malloc(16), free_bind_data);
    duckdb_copy_function_bind_set_error(info, DECLINE_PREFIX " decline_fmt is not registered on this connection");
}

static void failing_bind(duckdb_copy_function_bind_info info) {
    format_state *state = duckdb_copy_function_bind_get_extra_info(info);
    state->binds++;

    duckdb_copy_function_bind_set_error(info, "failing_fmt refused: " DECLINE_PREFIX " inside a message");
}

static void global_init(duckdb_copy_function_global_init_info info) {
    format_state *state = duckdb_copy_function_global_init_get_extra_info(info);
    const char *path = duckdb_copy_function_global_init_get_file_path(info);

    snprintf(state->path, sizeof(state->path), "%s", path ? path : "");
}

static void sink(duckdb_copy_function_sink_info info, duckdb_data_chunk chunk) {
    format_state *state = duckdb_copy_function_sink_get_extra_info(info);
    state->rows += duckdb_data_chunk_get_size(chunk);
}

static void finalize(duckdb_copy_function_finalize_info info) {
    format_state *state = duckdb_copy_function_finalize_get_extra_info(info);
    state->finalized++;
}

static bool register_format(duckdb_connection connection, const char *name, duckdb_copy_function_bind_t bind,
                            format_state *state) {
    duckdb_copy_function function = duckdb_create_copy_function();
    duckdb_copy_function_set_name(function, name);
    duckdb_copy_function_set_bind(function, bind);
    duckdb_copy_function_set_global_init(function, global_init);
    duckdb_copy_function_set_sink(function, sink);
    duckdb_copy_function_set_finalize(function, finalize);
    duckdb_copy_function_set_extra_info(function, state, NULL);

    duckdb_state registered = duckdb_register_copy_function(connection, function);
    duckdb_destroy_copy_function(&function);

    return registered == DuckDBSuccess;
}

/* Runs SQL with "%s" replaced by the scratch directory; returns NULL on
 * success or a heap copy of the error message. */
static char *run(duckdb_connection connection, const char *sql_format, duckdb_error_type *type) {
    char sql[8192];
    snprintf(sql, sizeof(sql), sql_format, directory, directory);

    duckdb_result result;
    char *error = NULL;
    if (duckdb_query(connection, sql, &result) == DuckDBError) {
        const char *message = duckdb_result_error(&result);
        error = strdup(message ? message : "unknown error");
        if (type) {
            *type = duckdb_result_error_type(&result);
        }
    }

    duckdb_destroy_result(&result);
    return error;
}

static void expect_success(duckdb_connection connection, const char *name, const char *sql) {
    char *error = run(connection, sql, NULL);
    report(!error, name, error ? error : "");
    free(error);
}

static void expect_error(duckdb_connection connection, const char *name, const char *sql,
                         duckdb_error_type expected_type, const char *needle) {
    duckdb_error_type type = DUCKDB_ERROR_INVALID;
    char *error = run(connection, sql, &type);

    if (!error) {
        report(false, name, "statement was accepted");
        return;
    }

    char detail[8192];
    snprintf(detail, sizeof(detail), "error type %d: %s", (int)type, error);
    report(type == expected_type && strstr(error, needle) != NULL, name, detail);
    free(error);
}

static void expect_count(duckdb_connection connection, const char *name, const char *sql, int64_t expected) {
    char formatted[8192];
    snprintf(formatted, sizeof(formatted), sql, directory, directory);

    duckdb_result result;
    if (duckdb_query(connection, formatted, &result) == DuckDBError) {
        report(false, name, duckdb_result_error(&result));
        duckdb_destroy_result(&result);
        return;
    }

    int64_t actual = duckdb_value_int64(&result, 0, 0);
    duckdb_destroy_result(&result);

    char detail[128];
    snprintf(detail, sizeof(detail), "expected %lld, got %lld", (long long)expected, (long long)actual);
    report(actual == expected, name, detail);
}

static int remove_entry(const char *path, const struct stat *status, int flag, struct FTW *walk) {
    (void)status;
    (void)flag;
    (void)walk;

    return remove(path);
}

int main(void) {
    if (!mkdtemp(directory)) {
        perror("mkdtemp");
        return 2;
    }

    duckdb_database database = NULL;
    duckdb_connection connection = NULL;
    format_state accepted = {0};
    format_state declined = {0};
    format_state failing = {0};
    int status = 2;

    if (duckdb_open(NULL, &database) == DuckDBError || duckdb_connect(database, &connection) == DuckDBError) {
        fputs("Cannot open DuckDB\n", stderr);
        goto cleanup;
    }

    if (!register_format(connection, "capi_fmt", accept_bind, &accepted) ||
        !register_format(connection, "decline_fmt", decline_bind, &declined) ||
        !register_format(connection, "failing_fmt", failing_bind, &failing)) {
        fputs("Cannot register copy functions\n", stderr);
        goto cleanup;
    }

    char *setup = run(connection, "CREATE TABLE items AS SELECT range %% 2 AS a, range AS b FROM range(10)", NULL);
    if (setup) {
        fprintf(stderr, "Cannot create table: %s\n", setup);
        free(setup);
        goto cleanup;
    }

    /* A plain COPY through the C API function still writes every row. */
    expect_success(connection, "plain COPY with FORMAT capi_fmt", "COPY items TO '%s/plain.out' (FORMAT capi_fmt)");
    report(accepted.rows == 10 && accepted.finalized == 1 && strstr(accepted.path, "plain.out"),
           "plain COPY reached sink and finalize", "sink or finalize state is wrong");

    expect_success(connection, "COPY with format inferred from extension", "COPY items TO '%s/inferred.capi_fmt'");
    report(accepted.rows == 20 && accepted.finalized == 2, "inferred COPY used the C API function",
           "the C API function did not receive the rows");

    expect_success(connection, "PER_THREAD_OUTPUT false is accepted",
                   "COPY items TO '%s/serial.out' (FORMAT capi_fmt, PER_THREAD_OUTPUT false)");

    /* Parallel output modes are rejected at bind time. */
    expect_error(connection, "PARTITION_BY rejected for capi_fmt",
                 "COPY items TO '%s/partitioned' (FORMAT capi_fmt, PARTITION_BY (a))", DUCKDB_ERROR_BINDER,
                 "PARTITION_BY");
    expect_error(connection, "PER_THREAD_OUTPUT rejected for capi_fmt",
                 "COPY items TO '%s/per_thread' (FORMAT capi_fmt, PER_THREAD_OUTPUT true)", DUCKDB_ERROR_BINDER,
                 "PER_THREAD_OUTPUT");
    expect_error(connection, "EXPORT DATABASE rejected for capi_fmt",
                 "EXPORT DATABASE '%s/export_capi' (FORMAT capi_fmt)", DUCKDB_ERROR_BINDER, "EXPORT DATABASE");

    /* A declined binding behaves like a missing format. */
    expect_error(connection, "declined explicit FORMAT is missing",
                 "COPY items TO '%s/declined.out' (FORMAT decline_fmt)", DUCKDB_ERROR_CATALOG,
                 "Copy Function with name decline_fmt does not exist");
    idx_t declined_binds = declined.binds;
    expect_success(connection, "declined inferred format falls back to CSV", "COPY items TO '%s/fallback.decline_fmt'");
    report(declined.binds == declined_binds + 1 && declined.rows == 0, "fallback consulted the declining bind",
           "the declining bind was not called exactly once or received rows");
    expect_count(connection, "fallback file is CSV",
                 "SELECT count(*) FROM read_csv('%s/fallback.decline_fmt', header = true, columns = "
                 "{'a': 'BIGINT', 'b': 'BIGINT'})",
                 10);
    expect_success(connection, "declined inferred format allows CSV PARTITION_BY",
                   "COPY items TO '%s/fallback_partitioned.decline_fmt' (PARTITION_BY (a))");
    expect_count(connection, "fallback partitions are CSV",
                 "SELECT count(*) FROM read_csv('%s/fallback_partitioned.decline_fmt/*/*.csv', hive_partitioning = "
                 "true)",
                 10);
    /* Keep the limit above the CSV header: 1.5.6 otherwise rotates without bound. */
    expect_success(connection, "declined inferred format allows CSV FILE_SIZE_BYTES",
                   "COPY items TO '%s/fallback_rotated.decline_fmt' (FILE_SIZE_BYTES 1000)");
    expect_count(connection, "fallback rotation is CSV",
                 "SELECT count(*) FROM read_csv('%s/fallback_rotated.decline_fmt/*.csv')", 10);

    /* The decline signal must lead the message; other errors are unchanged. */
    expect_error(connection, "ordinary bind error is a binder error",
                 "COPY items TO '%s/failing.out' (FORMAT failing_fmt)", DUCKDB_ERROR_BINDER,
                 "failing_fmt refused: " DECLINE_PREFIX " inside a message");

    /* Built-in formats keep their parallel modes and EXPORT DATABASE. */
    expect_success(connection, "CSV PARTITION_BY", "COPY items TO '%s/csv_partitioned' (FORMAT csv, PARTITION_BY (a))");
    expect_success(connection, "CSV PER_THREAD_OUTPUT",
                   "COPY items TO '%s/csv_per_thread' (FORMAT csv, PER_THREAD_OUTPUT true)");
    expect_success(connection, "Parquet PARTITION_BY",
                   "COPY items TO '%s/parquet_partitioned' (FORMAT parquet, PARTITION_BY (a))");
    expect_count(connection, "Parquet partitions are readable",
                 "SELECT count(*) FROM read_parquet('%s/parquet_partitioned/*/*.parquet', hive_partitioning = true)",
                 10);
    expect_success(connection, "Parquet PER_THREAD_OUTPUT",
                   "COPY items TO '%s/parquet_per_thread' (FORMAT parquet, PER_THREAD_OUTPUT true)");
    expect_success(connection, "EXPORT DATABASE as CSV", "EXPORT DATABASE '%s/export_csv'");
    expect_success(connection, "EXPORT DATABASE as Parquet", "EXPORT DATABASE '%s/export_parquet' (FORMAT parquet)");

    status = failures == 0 ? 0 : 1;
    printf("%d failure(s)\n", failures);

cleanup:
    duckdb_disconnect(&connection);
    duckdb_close(&database);
    nftw(directory, remove_entry, 16, FTW_DEPTH | FTW_PHYS);
    return status;
}
