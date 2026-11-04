/* Public-C-API regression for the ARRAY join gather patch; no PHP
 * dependency. Set DUCKDB_SDK to an absolute SDK directory:
 *
 * cc -std=c11 -Wall -Wextra -I"$DUCKDB_SDK/include" \
 *   tests/native/array_join_gather.c -L"$DUCKDB_SDK/lib" \
 *   -Wl,-rpath,"$DUCKDB_SDK/lib" -lduckdb -o /tmp/array-join-gather
 *
 * /tmp/array-join-gather
 *
 * valgrind --error-exitcode=99 --errors-for-leak-kinds=definite \
 *   --leak-check=full /tmp/array-join-gather
 *
 * A hash join whose build side has unique keys gathers the build columns of
 * the matched probe rows straight into their output positions. For a column
 * that contains an ARRAY, the engine gathers a LIST and casts it to the ARRAY
 * type. Unpatched DuckDB 2.0.0 casts the first N rows of the gathered vector
 * instead of the N selected positions, so a LEFT JOIN with unmatched rows
 * fails with "Cannot cast list with length 0 to array with length 2", a
 * garbage length or an internal allocation error. Each case compares every
 * fetched row with the value computed from the probe key and prints "ok" or
 * "FAIL".
 *
 * The unpatched DuckDB 2.0.0 engine fails 17 of the 23 checks in every run.
 * Its LIST of ARRAYs case reads list entries that the gather never wrote: it
 * passes, fails or crashes the process with a segmentation fault, depending on
 * the run. Memcheck does not flag these stale reads; the checks do. The
 * last-row-unmatched, all-matched, FULL, duplicate-key and INNER cases do not
 * reach the faulty cast and pass there.
 */
#include "duckdb.h"

#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

/* Each case creates the one table it joins, then runs its query. */
typedef struct {
    const char *name;
    const char *table;
    const char *sql;
    idx_t rows;
} join_case;

static int failures = 0;

static void report(bool passed, const char *name, const char *detail) {
    if (passed) {
        printf("ok   %s\n", name);
        return;
    }

    failures++;
    printf("FAIL %s: %s\n", name, detail);
}

static bool execute(duckdb_connection connection, const char *sql) {
    duckdb_result result;
    bool succeeded = duckdb_query(connection, sql, &result) == DuckDBSuccess;
    if (!succeeded) {
        fprintf(stderr, "setup failed: %s\n%s\n", duckdb_result_error(&result), sql);
    }

    duckdb_destroy_result(&result);
    return succeeded;
}

static bool same_text(const char *left, const char *right) {
    if (!left || !right) {
        return left == right;
    }

    return strcmp(left, right) == 0;
}

/* Each query returns (actual, expected): the joined value and the value the
 * join must produce for that row, computed without a join. */
static void check_case(duckdb_connection connection, const join_case *test) {
    duckdb_result result;
    if (duckdb_query(connection, test->sql, &result) == DuckDBError) {
        report(false, test->name, duckdb_result_error(&result));
        duckdb_destroy_result(&result);
        return;
    }

    char detail[1024] = "";
    idx_t rows = duckdb_row_count(&result);
    if (rows != test->rows) {
        snprintf(detail, sizeof(detail), "expected %llu rows, got %llu", (unsigned long long)test->rows,
                 (unsigned long long)rows);
        report(false, test->name, detail);
        duckdb_destroy_result(&result);
        return;
    }

    bool passed = true;
    for (idx_t row = 0; row < rows && passed; row++) {
        char *actual = duckdb_value_is_null(&result, 0, row) ? NULL : duckdb_value_varchar(&result, 0, row);
        char *expected = duckdb_value_is_null(&result, 1, row) ? NULL : duckdb_value_varchar(&result, 1, row);

        if (!same_text(actual, expected)) {
            passed = false;
            snprintf(detail, sizeof(detail), "row %llu: got %s, expected %s", (unsigned long long)row,
                     actual ? actual : "NULL", expected ? expected : "NULL");
        }

        duckdb_free(actual);
        duckdb_free(expected);
    }

    report(passed, test->name, detail);
    duckdb_destroy_result(&result);
}

#define INTS "CREATE TABLE ints AS SELECT i AS id, [i, i + 1]::INTEGER[2] AS arr FROM range(10) t(i)"
#define WIDE "CREATE TABLE wide AS SELECT i AS id, [i, i + 1]::INTEGER[2] AS arr FROM range(5000) t(i)"

static const join_case cases[] = {
    {"LEFT JOIN, every other row unmatched", INTS,
     "SELECT arr, CASE WHEN i % 2 = 0 THEN [i, i + 1]::INTEGER[2] END "
     "FROM range(10) t(i) LEFT JOIN (SELECT * FROM ints WHERE id % 2 = 0) ON i = id",
     10},
    {"LEFT JOIN, first row unmatched", INTS,
     "SELECT arr, CASE WHEN i <> 0 THEN [i, i + 1]::INTEGER[2] END "
     "FROM range(10) t(i) LEFT JOIN (SELECT * FROM ints WHERE id <> 0) ON i = id",
     10},
    {"LEFT JOIN, last row unmatched", INTS,
     "SELECT arr, CASE WHEN i <> 9 THEN [i, i + 1]::INTEGER[2] END "
     "FROM range(10) t(i) LEFT JOIN (SELECT * FROM ints WHERE id <> 9) ON i = id",
     10},
    {"LEFT JOIN, one middle row unmatched", INTS,
     "SELECT arr, CASE WHEN i <> 5 THEN [i, i + 1]::INTEGER[2] END "
     "FROM range(10) t(i) LEFT JOIN (SELECT * FROM ints WHERE id <> 5) ON i = id",
     10},
    {"LEFT JOIN, only the last row matched", INTS,
     "SELECT arr, CASE WHEN i = 9 THEN [i, i + 1]::INTEGER[2] END "
     "FROM range(10) t(i) LEFT JOIN (SELECT * FROM ints WHERE id = 9) ON i = id",
     10},
    {"LEFT JOIN, every row matched", INTS,
     "SELECT arr, [i, i + 1]::INTEGER[2] FROM range(10) t(i) LEFT JOIN ints ON i = id", 10},
    {"LEFT JOIN, NULL arrays and NULL elements",
     "CREATE TABLE nulls AS SELECT i AS id, CASE WHEN i % 4 = 0 THEN NULL ELSE [i, NULL]::INTEGER[2] END AS arr "
     "FROM range(10) t(i)",
     "SELECT arr, CASE WHEN i % 3 <> 0 AND i % 4 <> 0 THEN [i, NULL]::INTEGER[2] END "
     "FROM range(10) t(i) LEFT JOIN (SELECT * FROM nulls WHERE id % 3 <> 0) ON i = id",
     10},
    {"LEFT JOIN, VARCHAR elements",
     "CREATE TABLE strs AS SELECT i AS id, ['short', 'a string longer than twelve bytes ' || i, NULL]::VARCHAR[3] "
     "AS arr FROM range(10) t(i)",
     "SELECT arr, CASE WHEN i % 2 = 1 THEN ['short', 'a string longer than twelve bytes ' || i, NULL]::VARCHAR[3] "
     "END FROM range(10) t(i) LEFT JOIN (SELECT * FROM strs WHERE id % 2 = 1) ON i = id",
     10},
    {"LEFT JOIN, STRUCT elements",
     "CREATE TABLE structs AS SELECT i AS id, [{'a': i, 'b': 'value ' || i}, NULL]::STRUCT(a INTEGER, b VARCHAR)[2] "
     "AS arr FROM range(10) t(i)",
     "SELECT arr, CASE WHEN i % 3 = 1 THEN [{'a': i, 'b': 'value ' || i}, NULL]::STRUCT(a INTEGER, b VARCHAR)[2] "
     "END FROM range(10) t(i) LEFT JOIN (SELECT * FROM structs WHERE id % 3 = 1) ON i = id",
     10},
    {"LEFT JOIN, LIST elements",
     "CREATE TABLE lists AS SELECT i AS id, [range(i % 3), NULL]::BIGINT[][2] AS arr FROM range(10) t(i)",
     "SELECT arr, CASE WHEN i % 2 = 0 THEN [range(i % 3), NULL]::BIGINT[][2] END "
     "FROM range(10) t(i) LEFT JOIN (SELECT * FROM lists WHERE id % 2 = 0) ON i = id",
     10},
    {"LEFT JOIN, ARRAY elements",
     "CREATE TABLE nested AS SELECT i AS id, [[i, i], [i + 1, i + 1], [i + 2, i + 2]]::INTEGER[2][3] AS arr "
     "FROM range(10) t(i)",
     "SELECT arr, CASE WHEN i % 2 = 0 THEN [[i, i], [i + 1, i + 1], [i + 2, i + 2]]::INTEGER[2][3] END "
     "FROM range(10) t(i) LEFT JOIN (SELECT * FROM nested WHERE id % 2 = 0) ON i = id",
     10},
    {"LEFT JOIN, STRUCT containing an ARRAY",
     "CREATE TABLE wrapped AS SELECT i AS id, {'k': i, 'v': [i, i + 1]::INTEGER[2]} AS arr FROM range(10) t(i)",
     "SELECT arr, CASE WHEN i % 2 = 0 THEN {'k': i, 'v': [i, i + 1]::INTEGER[2]} END "
     "FROM range(10) t(i) LEFT JOIN (SELECT * FROM wrapped WHERE id % 2 = 0) ON i = id",
     10},
    {"LEFT JOIN, LIST of ARRAYs",
     "CREATE TABLE arrlists AS SELECT i AS id, list_transform(range(i % 3), lambda x: [x, i]::INTEGER[2]) AS arr "
     "FROM range(10) t(i)",
     "SELECT arr, CASE WHEN i % 2 = 0 THEN list_transform(range(i % 3), lambda x: [x, i]::INTEGER[2]) END "
     "FROM range(10) t(i) LEFT JOIN (SELECT * FROM arrlists WHERE id % 2 = 0) ON i = id",
     10},
    {"LEFT JOIN, several chunks", WIDE,
     "SELECT arr, CASE WHEN i % 3 <> 0 THEN [i, i + 1]::INTEGER[2] END "
     "FROM range(5000) t(i) LEFT JOIN (SELECT * FROM wide WHERE id % 3 <> 0) ON i = id",
     5000},
    {"LEFT JOIN, several chunks of STRUCT elements",
     "CREATE TABLE wide_structs AS SELECT i AS id, CASE WHEN i % 11 = 0 THEN NULL "
     "ELSE [{'n': i, 's': 'row ' || i}, NULL]::STRUCT(n INTEGER, s VARCHAR)[2] END AS arr FROM range(5000) t(i)",
     "SELECT arr, CASE WHEN i % 19 <> 0 AND i % 11 <> 0 "
     "THEN [{'n': i, 's': 'row ' || i}, NULL]::STRUCT(n INTEGER, s VARCHAR)[2] END "
     "FROM range(5000) t(i) LEFT JOIN (SELECT * FROM wide_structs WHERE id % 19 <> 0) ON i = id",
     5000},
    {"LEFT JOIN, later chunks without matches", WIDE,
     "SELECT arr, CASE WHEN i % 2 = 0 AND i < 2048 THEN [i, i + 1]::INTEGER[2] END "
     "FROM range(5000) t(i) LEFT JOIN (SELECT * FROM wide WHERE id % 2 = 0 AND id < 2048) ON i = id",
     5000},
    {"LEFT JOIN, several chunks with a sparse build side", WIDE,
     "SELECT arr, CASE WHEN i % 97 = 0 THEN [i, i + 1]::INTEGER[2] END "
     "FROM range(5000) t(i) LEFT JOIN (SELECT * FROM wide WHERE id % 97 = 0) ON i = id",
     5000},
    {"scalar subquery", INTS,
     "SELECT (SELECT arr FROM ints WHERE id = i AND id % 2 = 0), CASE WHEN i % 2 = 0 THEN [i, i + 1]::INTEGER[2] END "
     "FROM range(10) t(i)",
     10},
    {"scalar subquery, several chunks", WIDE,
     "SELECT (SELECT arr FROM wide WHERE id = i AND id % 3 = 0), CASE WHEN i % 3 = 0 THEN [i, i + 1]::INTEGER[2] END "
     "FROM range(5000) t(i)",
     5000},
    {"RIGHT JOIN", INTS,
     "SELECT arr, CASE WHEN i % 2 = 0 THEN [i, i + 1]::INTEGER[2] END "
     "FROM (SELECT * FROM ints WHERE id % 2 = 0) RIGHT JOIN range(10) t(i) ON i = id",
     10},
    {"FULL JOIN",
     "CREATE TABLE full_side AS SELECT i AS id, [i, i + 1]::INTEGER[2] AS arr FROM range(20) t(i)",
     "SELECT arr, CASE WHEN id IS NOT NULL THEN [id, id + 1]::INTEGER[2] END "
     "FROM range(10) t(i) FULL JOIN (SELECT * FROM full_side WHERE id % 2 = 0) ON i = id",
     15},
    {"LEFT JOIN with duplicate build keys", INTS,
     "SELECT arr, CASE WHEN i % 2 = 0 THEN [i, i + 1]::INTEGER[2] END "
     "FROM range(10) t(i) LEFT JOIN (SELECT * FROM ints WHERE id % 2 = 0 UNION ALL "
     "SELECT * FROM ints WHERE id % 4 = 0) ON i = id",
     13},
    {"INNER JOIN", INTS,
     "SELECT arr, [i, i + 1]::INTEGER[2] FROM range(10) t(i) JOIN (SELECT * FROM ints WHERE id % 2 = 0) ON i = id",
     5},
};

/* Each case gets a new database: an internal error on the unpatched engine
 * invalidates its database and would otherwise fail every later case. */
static bool run_case(const join_case *test) {
    duckdb_database database;
    duckdb_connection connection;
    if (duckdb_open(NULL, &database) == DuckDBError) {
        fprintf(stderr, "cannot open an in-memory database\n");
        return false;
    }

    if (duckdb_connect(database, &connection) == DuckDBError) {
        fprintf(stderr, "cannot connect\n");
        duckdb_close(&database);
        return false;
    }

    bool prepared = execute(connection, test->table);

    if (prepared) {
        check_case(connection, test);
    }

    duckdb_disconnect(&connection);
    duckdb_close(&database);
    return prepared;
}

int main(void) {
    size_t count = sizeof(cases) / sizeof(cases[0]);
    for (size_t i = 0; i < count; i++) {
        if (!run_case(&cases[i])) {
            return 2;
        }
    }

    printf("%d of %zu checks failed\n", failures, count);
    return failures == 0 ? 0 : 1;
}
