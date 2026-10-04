/* Public-C-API regression for nullable FOR bitpacking; no PHP dependency.
 *
 * Compile against the original SDK, then use the same executable and SQL
 * workload with each library. Set DUCKDB_SDK to an absolute SDK directory:
 *
 * cc -std=c11 -Wall -Wextra -I"$DUCKDB_SDK/include" \
 *   tests/native/nullable_bitpacking_matrix.c -L"$DUCKDB_SDK/lib" \
 *   -Wl,-rpath,"$DUCKDB_SDK/lib" -lduckdb -o /tmp/nullable-bitpacking-matrix
 *
 * /tmp/nullable-bitpacking-matrix /tmp/NEW_DATABASE.duckdb
 *
 * On Linux, select the library to validate through LD_LIBRARY_PATH and use
 * another fresh database path for every run:
 *
 * LD_LIBRARY_PATH=/absolute/library/directory valgrind --track-origins=yes \
 *   --error-exitcode=99 --errors-for-leak-kinds=definite --leak-check=full \
 *   --log-file=/tmp/bitpacking-matrix.mem \
 *   /tmp/nullable-bitpacking-matrix /tmp/ANOTHER_NEW_DATABASE.duckdb
 *
 * SQL correctness alone does not detect undefined persisted NULL bytes.
 * Memcheck must also report zero errors. A successful SQL run checkpoints
 * and reopens the file, checking every value and NULL position in 62 cases.
 * Compression is forced here only to exercise the engine paths under test.
 */
#include "duckdb.h"

#include <errno.h>
#include <stdio.h>
#include <sys/stat.h>

#define ARRAY_SIZE(values) (sizeof(values) / sizeof((values)[0]))

struct frame_case {
    const char *type;
    const char *base;
};

static const struct frame_case frames[] = {
    {"INTEGER", "1337"},
    {"INTEGER", "-2147483648"},
    {"BIGINT", "-9223372036854775808"},
    {"UBIGINT", "18446744073709551584"},
    {"HUGEINT", "-170141183460469231731687303715884105728"},
    {"UHUGEINT", "340282366920938463463374607431768211424"},
    {"TINYINT", "-128"},
};

/* Incomplete/full 32-value groups, metadata groups, and buffer reuse. */
static const unsigned counts[] = {1, 31, 32, 33, 2047, 2048, 2049, 4097};

struct extra_case {
    const char *expression;
    const char *mode;
};

static const struct extra_case extras[] = {
    {"NULL::INTEGER", "auto"},
    {"CASE WHEN i%3=0 THEN NULL ELSE 1337 END::INTEGER", "auto"},
    {"1337::INTEGER", "auto"},
    {"(100+i)::BIGINT", "auto"},
    {"(100*i+i%3)::BIGINT", "delta_for"},
    {"(1337+i%7)::INTEGER", "for"},
};

static int query(duckdb_connection connection, const char *sql) {
    duckdb_result result;
    if (duckdb_query(connection, sql, &result) == DuckDBError) {
        fprintf(stderr, "%s\nSQL: %s\n", duckdb_result_error(&result), sql);
        duckdb_destroy_result(&result);
        return 0;
    }
    duckdb_destroy_result(&result);
    return 1;
}

static int verify(duckdb_connection connection, const char *table, const char *expression, unsigned count) {
    char sql[2048];
    snprintf(sql, sizeof(sql),
             "SELECT count(*) = %u AND count(*) FILTER "
             "(WHERE v IS DISTINCT FROM (%s)) = 0 FROM %s",
             count, expression, table);

    duckdb_result result;
    if (duckdb_query(connection, sql, &result) == DuckDBError) {
        fprintf(stderr, "Verification query failed: %s\n", duckdb_result_error(&result));
        duckdb_destroy_result(&result);
        return 0;
    }

    int ok = duckdb_value_boolean(&result, 0, 0);
    duckdb_destroy_result(&result);
    if (!ok) {
        fprintf(stderr, "Mismatch in %s\n", table);
    }
    return ok;
}

static void frame_expression(char *buffer, size_t size, const struct frame_case *frame) {
    snprintf(buffer, size,
             "CASE WHEN i%%3=0 OR i%%32=31 THEN NULL "
             "ELSE '%s'::%s + (i%%31)::%s END",
             frame->base, frame->type, frame->type);
}

int main(int argc, char **argv) {
    if (argc != 2) {
        fprintf(stderr, "Usage: %s NEW_DATABASE_PATH\n", argv[0]);
        return 2;
    }

    struct stat existing;
    if (stat(argv[1], &existing) == 0) {
        fputs("Database path must not exist\n", stderr);
        return 2;
    }
    if (errno != ENOENT) {
        perror("Cannot inspect database path");
        return 2;
    }

    duckdb_config config = NULL;
    duckdb_database database = NULL;
    duckdb_connection connection = NULL;
    char *error = NULL;
    int status = 1;
    char sql[4096], expression[1024], table[64];

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
    if (!query(connection, "SET force_compression='bitpacking'; SET force_bitpacking_mode='for'")) {
        goto cleanup;
    }
    for (size_t type = 0; type < ARRAY_SIZE(frames); ++type) {
        for (size_t count = 0; count < ARRAY_SIZE(counts); ++count) {
            snprintf(table, sizeof(table), "matrix_%zu_%zu", type, count);
            frame_expression(expression, sizeof(expression), &frames[type]);
            snprintf(sql, sizeof(sql), "CREATE TABLE %s AS SELECT i, %s AS v FROM range(%u) t(i)", table,
                     expression, counts[count]);
            if (!query(connection, sql)) {
                goto cleanup;
            }
        }
    }
    if (!query(connection, "CHECKPOINT")) {
        goto cleanup;
    }
    for (size_t index = 0; index < ARRAY_SIZE(extras); ++index) {
        snprintf(sql, sizeof(sql),
                 "SET force_bitpacking_mode='%s'; CREATE TABLE extra_%zu "
                 "AS SELECT i, %s AS v FROM range(4097) t(i); CHECKPOINT",
                 extras[index].mode, index, extras[index].expression);
        if (!query(connection, sql)) {
            goto cleanup;
        }
    }

    duckdb_disconnect(&connection);
    duckdb_close(&database);
    if (duckdb_open_ext(argv[1], &database, config, &error) == DuckDBError) {
        fprintf(stderr, "Cannot reopen DuckDB: %s\n", error ? error : "unknown error");
        goto cleanup;
    }
    if (duckdb_connect(database, &connection) == DuckDBError) {
        fputs("Cannot reconnect to DuckDB\n", stderr);
        goto cleanup;
    }
    for (size_t type = 0; type < ARRAY_SIZE(frames); ++type) {
        for (size_t count = 0; count < ARRAY_SIZE(counts); ++count) {
            snprintf(table, sizeof(table), "matrix_%zu_%zu", type, count);
            frame_expression(expression, sizeof(expression), &frames[type]);
            if (!verify(connection, table, expression, counts[count])) {
                goto cleanup;
            }
        }
    }
    for (size_t index = 0; index < ARRAY_SIZE(extras); ++index) {
        snprintf(table, sizeof(table), "extra_%zu", index);
        if (!verify(connection, table, extras[index].expression, 4097)) {
            goto cleanup;
        }
    }

    puts("PASS 62 bitpacking cases: NULL/valid values survive checkpoint and reopen");
    status = 0;

cleanup:
    duckdb_free(error);
    duckdb_destroy_config(&config);
    if (connection) {
        duckdb_disconnect(&connection);
    }
    if (database) {
        duckdb_close(&database);
    }
    return status;
}
