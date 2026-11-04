/* Public-C-API probe: does pending creation wait on callbacks that need the
 * calling thread? No PHP dependency. Set DUCKDB_SDK to an absolute SDK
 * directory:
 *
 * cc -std=c11 -Wall -Wextra -pthread -I"$DUCKDB_SDK/include" \
 *   tests/native/pending_prepared_callbacks.c -L"$DUCKDB_SDK/lib" \
 *   -Wl,-rpath,"$DUCKDB_SDK/lib" -lduckdb -o /tmp/pending-prepared-callbacks
 *
 * /tmp/pending-prepared-callbacks
 *
 * valgrind --error-exitcode=99 --errors-for-leak-kinds=definite \
 *   --leak-check=full /tmp/pending-prepared-callbacks
 *
 * PENDING_PROBE_TIMEOUT_SECONDS (default 30) bounds every wait, so a deadlock
 * is reported as a timeout instead of hanging.
 *
 * A C API copy function and a C API scalar function stand in for a PHP COPY
 * format and a PHP scalar function. Every callback records the thread DuckDB
 * invoked it on (main, driver or worker, plus distinct pthread_self() values)
 * and what the main thread was doing at that moment: prepare, pending
 * creation, after pending creation returned, execution or destroy. The main
 * thread is the PHP request thread: a callback invoked on any other thread
 * queues its work for the main thread and blocks until the main thread runs
 * it, with a bounded wait, like the extension's pump. The main thread
 * prepares the statement and creates the pending result itself without
 * servicing the queue. It then waits (bounded) for a worker to queue a
 * callback before a driver thread runs duckdb_execute_pending and fetches
 * every chunk while the main thread services queued callbacks. Under
 * threads = 4 and the default async_threads (64 on 2.0.0) it covers COPY TO
 * through the copy function and SELECT through the scalar function, each with
 * duckdb_pending_prepared and duckdb_pending_prepared_streaming, each prepared
 * once and executed twice with a DDL statement in between so the second
 * pending creation rebinds.
 *
 * Recorded outcome on stock DuckDB 2.0.0, asserted below:
 *
 * - Pending creation never waits on a callback that runs or is queued on
 *   another thread. No callback timed out while the main thread was inside
 *   duckdb_pending_prepared[_streaming], buffered or streaming, first
 *   execution or rebind.
 * - Pending creation does schedule the pipeline: a worker thread invokes copy
 *   global_init (COPY) or scalar init (SELECT) before anything drives
 *   execution, usually just after pending creation returns and occasionally
 *   while it is still running. Pending creation returns while that callback
 *   waits for the main thread. These callbacks never run on the main thread.
 * - Copy bind and scalar bind run only on the main thread: once during
 *   duckdb_prepare, and once inside every duckdb_pending_prepared[_streaming]
 *   that rebinds. They never run during execution.
 * - Copy global_init runs once per execution. Copy sink, copy finalize and
 *   scalar execute run only during execution.
 *
 * Reported, not asserted: on 2.0.0 every pending creation of these
 * statements rebinds, the first included (bind runs in prepare and again in
 * pending creation), while 1.5.6 rebinds only after the DDL statement. Which
 * threads run the execution callbacks varies between runs: the driver thread
 * or workers. On 2.0.0 one thread ran all sink or execute calls of a
 * statement and scalar init ran once; on 1.5.6 up to four threads ran them
 * and scalar init ran once per executing thread.
 * The patched 1.5.6 SDK satisfies every assertion.
 *
 * Each case prints "ok" or "FAIL"; the program exits nonzero only when the
 * engine's behavior differs from the recorded outcome.
 */
#define _XOPEN_SOURCE 700
#include "duckdb.h"

#include <errno.h>
#include <ftw.h>
#include <pthread.h>
#include <stdatomic.h>
#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

#define ROW_COUNT 300000
#define MAX_THREADS 64

enum phase {
    PHASE_SETUP,
    PHASE_PREPARE,
    PHASE_PENDING,
    PHASE_AFTER_PENDING,
    PHASE_EXECUTE,
    PHASE_DESTROY,
    PHASE_COUNT
};

enum callback {
    CB_COPY_BIND,
    CB_COPY_GLOBAL_INIT,
    CB_COPY_SINK,
    CB_COPY_FINALIZE,
    CB_SCALAR_BIND,
    CB_SCALAR_INIT,
    CB_SCALAR_EXECUTE,
    CB_COUNT
};

enum thread_kind { THREAD_MAIN, THREAD_DRIVER, THREAD_WORKER, THREAD_COUNT };

static const char *phase_names[PHASE_COUNT] = {"setup", "prepare", "pending", "after-pending", "execute", "destroy"};
static const char *callback_names[CB_COUNT] = {"copy_bind",   "copy_global_init", "copy_sink",     "copy_finalize",
                                               "scalar_bind", "scalar_init",      "scalar_execute"};
static const char *thread_names[THREAD_COUNT] = {"main", "driver", "worker"};

typedef struct {
    idx_t calls[CB_COUNT][PHASE_COUNT][THREAD_COUNT];
    pthread_t threads[CB_COUNT][MAX_THREADS];
    idx_t thread_count[CB_COUNT];
    idx_t queued[PHASE_COUNT];
    idx_t timeouts[PHASE_COUNT];
    idx_t queued_at_pending_return;
    idx_t sink_rows;
    idx_t finalized;
} run_record;

typedef struct request {
    void (*work)(void *);
    void *argument;
    bool done;
    struct request *next;
} request;

typedef struct {
    duckdb_pending_result pending;
    idx_t rows;
    int64_t sum;
    char *error;
} drive_job;

typedef struct {
    duckdb_data_chunk input;
    duckdb_vector output;
} scalar_work;

static pthread_mutex_t lock = PTHREAD_MUTEX_INITIALIZER;
static pthread_cond_t changed;
static request *queue_head = NULL;
static request *queue_tail = NULL;
static idx_t queue_length = 0;
static bool driver_done = false;
static run_record record;
static _Atomic int phase = PHASE_SETUP;
static _Thread_local bool is_main = false;
static _Thread_local bool is_driver = false;
static time_t timeout_seconds = 30;
static int failures = 0;
static char directory[] = "/tmp/pending-prepared-callbacks.XXXXXX";

static void report(bool passed, const char *name, const char *detail) {
    if (passed) {
        printf("ok   %s\n", name);
        return;
    }

    failures++;
    printf("FAIL %s: %s\n", name, detail);
}

static struct timespec deadline_after(time_t seconds) {
    struct timespec deadline;
    clock_gettime(CLOCK_MONOTONIC, &deadline);
    deadline.tv_sec += seconds;

    return deadline;
}

static double elapsed_ms(const struct timespec *start) {
    struct timespec now;
    clock_gettime(CLOCK_MONOTONIC, &now);

    return (double)(now.tv_sec - start->tv_sec) * 1000.0 + (double)(now.tv_nsec - start->tv_nsec) / 1e6;
}

static enum thread_kind current_thread(void) {
    if (is_main) {
        return THREAD_MAIN;
    }

    if (is_driver) {
        return THREAD_DRIVER;
    }

    return THREAD_WORKER;
}

static void record_call(enum callback callback) {
    pthread_t self = pthread_self();

    pthread_mutex_lock(&lock);
    record.calls[callback][atomic_load(&phase)][current_thread()]++;

    bool seen = false;
    for (idx_t index = 0; index < record.thread_count[callback]; index++) {
        if (pthread_equal(record.threads[callback][index], self)) {
            seen = true;
            break;
        }
    }

    if (!seen && record.thread_count[callback] < MAX_THREADS) {
        record.threads[callback][record.thread_count[callback]++] = self;
    }
    pthread_mutex_unlock(&lock);
}

static void remove_request(request *target) {
    request *previous = NULL;

    for (request *entry = queue_head; entry; entry = entry->next) {
        if (entry != target) {
            previous = entry;
            continue;
        }

        if (previous) {
            previous->next = entry->next;
        } else {
            queue_head = entry->next;
        }

        if (queue_tail == entry) {
            queue_tail = previous;
        }

        queue_length--;
        return;
    }
}

/* Runs work on the main thread. Off the main thread the caller blocks until
 * the main thread has run it, or runs it itself after the bounded wait and
 * counts a timeout. */
static void run_on_main(void (*work)(void *), void *argument) {
    if (is_main) {
        work(argument);
        return;
    }

    request entry = {work, argument, false, NULL};

    pthread_mutex_lock(&lock);
    if (queue_tail) {
        queue_tail->next = &entry;
    } else {
        queue_head = &entry;
    }
    queue_tail = &entry;
    queue_length++;
    record.queued[atomic_load(&phase)]++;
    pthread_cond_broadcast(&changed);

    struct timespec deadline = deadline_after(timeout_seconds);
    while (!entry.done) {
        int waited = pthread_cond_timedwait(&changed, &lock, &deadline);

        if (waited == ETIMEDOUT && !entry.done) {
            remove_request(&entry);
            record.timeouts[atomic_load(&phase)]++;
            pthread_mutex_unlock(&lock);

            work(argument);
            return;
        }
    }
    pthread_mutex_unlock(&lock);
}

/* Main thread: runs queued work until the driver finishes. Returns false when
 * no request and no driver progress arrived within the bounded wait. */
static bool service_until_driver_done(void) {
    pthread_mutex_lock(&lock);

    struct timespec deadline = deadline_after(timeout_seconds);
    while (!driver_done || queue_head) {
        if (queue_head) {
            request *entry = queue_head;
            remove_request(entry);
            pthread_mutex_unlock(&lock);

            entry->work(entry->argument);

            pthread_mutex_lock(&lock);
            entry->done = true;
            pthread_cond_broadcast(&changed);
            deadline = deadline_after(timeout_seconds);
            continue;
        }

        if (pthread_cond_timedwait(&changed, &lock, &deadline) == ETIMEDOUT && !driver_done && !queue_head) {
            pthread_mutex_unlock(&lock);
            return false;
        }
    }

    pthread_mutex_unlock(&lock);
    return true;
}

static void no_work(void *argument) {
    (void)argument;
}

static void count_sink_rows(void *argument) {
    duckdb_data_chunk chunk = argument;

    pthread_mutex_lock(&lock);
    record.sink_rows += duckdb_data_chunk_get_size(chunk);
    pthread_mutex_unlock(&lock);
}

static void count_finalize(void *argument) {
    (void)argument;

    pthread_mutex_lock(&lock);
    record.finalized++;
    pthread_mutex_unlock(&lock);
}

static void add_one(void *argument) {
    scalar_work *job = argument;
    idx_t size = duckdb_data_chunk_get_size(job->input);
    int64_t *input = duckdb_vector_get_data(duckdb_data_chunk_get_vector(job->input, 0));
    int64_t *output = duckdb_vector_get_data(job->output);

    for (idx_t row = 0; row < size; row++) {
        output[row] = input[row] + 1;
    }
}

static void copy_bind(duckdb_copy_function_bind_info info) {
    (void)info;

    record_call(CB_COPY_BIND);
    run_on_main(no_work, NULL);
}

static void copy_global_init(duckdb_copy_function_global_init_info info) {
    (void)info;

    record_call(CB_COPY_GLOBAL_INIT);
    run_on_main(no_work, NULL);
}

static void copy_sink(duckdb_copy_function_sink_info info, duckdb_data_chunk chunk) {
    (void)info;

    record_call(CB_COPY_SINK);
    run_on_main(count_sink_rows, chunk);
}

static void copy_finalize(duckdb_copy_function_finalize_info info) {
    (void)info;

    record_call(CB_COPY_FINALIZE);
    run_on_main(count_finalize, NULL);
}

static void scalar_bind(duckdb_bind_info info) {
    (void)info;

    record_call(CB_SCALAR_BIND);
    run_on_main(no_work, NULL);
}

static void scalar_init(duckdb_init_info info) {
    (void)info;

    record_call(CB_SCALAR_INIT);
    run_on_main(no_work, NULL);
}

static void scalar_execute(duckdb_function_info info, duckdb_data_chunk input, duckdb_vector output) {
    (void)info;

    scalar_work job = {input, output};

    record_call(CB_SCALAR_EXECUTE);
    run_on_main(add_one, &job);
}

static bool register_functions(duckdb_connection connection) {
    duckdb_copy_function copy = duckdb_create_copy_function();
    duckdb_copy_function_set_name(copy, "probe_fmt");
    duckdb_copy_function_set_bind(copy, copy_bind);
    duckdb_copy_function_set_global_init(copy, copy_global_init);
    duckdb_copy_function_set_sink(copy, copy_sink);
    duckdb_copy_function_set_finalize(copy, copy_finalize);

    duckdb_state copy_registered = duckdb_register_copy_function(connection, copy);
    duckdb_destroy_copy_function(&copy);

    duckdb_logical_type bigint = duckdb_create_logical_type(DUCKDB_TYPE_BIGINT);
    duckdb_scalar_function scalar = duckdb_create_scalar_function();
    duckdb_scalar_function_set_name(scalar, "probe_scalar");
    duckdb_scalar_function_add_parameter(scalar, bigint);
    duckdb_scalar_function_set_return_type(scalar, bigint);
    duckdb_scalar_function_set_bind(scalar, scalar_bind);
    duckdb_scalar_function_set_init(scalar, scalar_init);
    duckdb_scalar_function_set_function(scalar, scalar_execute);

    duckdb_state scalar_registered = duckdb_register_scalar_function(connection, scalar);
    duckdb_destroy_scalar_function(&scalar);
    duckdb_destroy_logical_type(&bigint);

    return copy_registered == DuckDBSuccess && scalar_registered == DuckDBSuccess;
}

static char *run_sql(duckdb_connection connection, const char *sql) {
    duckdb_result result;
    char *error = NULL;

    if (duckdb_query(connection, sql, &result) == DuckDBError) {
        const char *message = duckdb_result_error(&result);
        error = strdup(message ? message : "unknown error");
    }

    duckdb_destroy_result(&result);
    return error;
}

static void print_setting(duckdb_connection connection, const char *name) {
    char sql[256];
    snprintf(sql, sizeof(sql), "SELECT current_setting('%s')::VARCHAR", name);

    duckdb_result result;
    if (duckdb_query(connection, sql, &result) == DuckDBError) {
        printf("setting %s: unavailable (%s)\n", name, duckdb_result_error(&result));
        duckdb_destroy_result(&result);
        return;
    }

    char *value = duckdb_value_varchar(&result, 0, 0);
    printf("setting %s = %s\n", name, value ? value : "NULL");
    duckdb_free(value);
    duckdb_destroy_result(&result);
}

static void *drive(void *argument) {
    drive_job *job = argument;
    duckdb_result result;

    is_driver = true;

    if (duckdb_execute_pending(job->pending, &result) == DuckDBError) {
        const char *message = duckdb_result_error(&result);
        job->error = strdup(message ? message : "unknown error");
    } else {
        for (;;) {
            duckdb_data_chunk chunk = duckdb_fetch_chunk(result);

            if (!chunk) {
                break;
            }

            idx_t size = duckdb_data_chunk_get_size(chunk);
            int64_t *values = duckdb_vector_get_data(duckdb_data_chunk_get_vector(chunk, 0));

            for (idx_t row = 0; row < size; row++) {
                job->sum += values[row];
            }

            job->rows += size;
            duckdb_destroy_data_chunk(&chunk);
        }
    }

    duckdb_destroy_result(&result);

    pthread_mutex_lock(&lock);
    driver_done = true;
    pthread_cond_broadcast(&changed);
    pthread_mutex_unlock(&lock);

    return NULL;
}

static idx_t total_calls(const run_record *run, enum callback callback) {
    idx_t total = 0;

    for (int stage = 0; stage < PHASE_COUNT; stage++) {
        for (int kind = 0; kind < THREAD_COUNT; kind++) {
            total += run->calls[callback][stage][kind];
        }
    }

    return total;
}

static idx_t calls_in(const run_record *run, enum callback callback, enum phase stage) {
    idx_t total = 0;

    for (int kind = 0; kind < THREAD_COUNT; kind++) {
        total += run->calls[callback][stage][kind];
    }

    return total;
}

static void print_record(const char *label, const run_record *run, double pending_ms) {
    printf("%s:\n", label);

    for (int callback = 0; callback < CB_COUNT; callback++) {
        if (total_calls(run, callback) == 0) {
            continue;
        }

        printf("  %-16s", callback_names[callback]);
        for (int stage = 0; stage < PHASE_COUNT; stage++) {
            if (calls_in(run, callback, stage) == 0) {
                continue;
            }

            printf(" %s:", phase_names[stage]);
            for (int kind = 0; kind < THREAD_COUNT; kind++) {
                if (run->calls[callback][stage][kind] > 0) {
                    printf(" %s=%llu", thread_names[kind], (unsigned long long)run->calls[callback][stage][kind]);
                }
            }
        }
        printf(" (%llu distinct thread(s))\n", (unsigned long long)run->thread_count[callback]);
    }

    printf("  queued off-main:");
    for (int stage = 0; stage < PHASE_COUNT; stage++) {
        printf(" %s=%llu", phase_names[stage], (unsigned long long)run->queued[stage]);
    }
    printf("\n  timeouts:");
    for (int stage = 0; stage < PHASE_COUNT; stage++) {
        printf(" %s=%llu", phase_names[stage], (unsigned long long)run->timeouts[stage]);
    }
    printf("\n  still queued when pending creation returned: %llu; pending creation took %.1f ms\n",
           (unsigned long long)run->queued_at_pending_return, pending_ms);
}

/* Asserts the recorded outcome for one execution. */
static void check_record(const char *label, const run_record *run, bool is_copy, bool rebind) {
    char name[256];
    char detail[256];
    enum callback bind = is_copy ? CB_COPY_BIND : CB_SCALAR_BIND;
    enum callback execution_callbacks[] = {CB_COPY_SINK, CB_COPY_FINALIZE, CB_SCALAR_EXECUTE};

    idx_t timeouts = 0;
    for (int stage = 0; stage < PHASE_COUNT; stage++) {
        timeouts += run->timeouts[stage];
    }

    snprintf(name, sizeof(name), "%s: pending creation never waited on an off-main callback", label);
    snprintf(detail, sizeof(detail), "%llu callback(s) timed out inside pending creation",
             (unsigned long long)run->timeouts[PHASE_PENDING]);
    report(run->timeouts[PHASE_PENDING] == 0, name, detail);

    enum callback init = is_copy ? CB_COPY_GLOBAL_INIT : CB_SCALAR_INIT;
    idx_t started_early = calls_in(run, init, PHASE_PENDING) + calls_in(run, init, PHASE_AFTER_PENDING);
    snprintf(name, sizeof(name), "%s: pending creation started %s on a worker before execution", label,
             callback_names[init]);
    snprintf(detail, sizeof(detail), "%llu call(s) before execution, %llu on the main thread",
             (unsigned long long)started_early, (unsigned long long)run->calls[init][PHASE_PENDING][THREAD_MAIN]);
    bool init_off_main = run->calls[init][PHASE_PENDING][THREAD_MAIN] == 0 &&
                         run->calls[init][PHASE_AFTER_PENDING][THREAD_MAIN] == 0 &&
                         calls_in(run, init, PHASE_PREPARE) == 0 && calls_in(run, init, PHASE_DESTROY) == 0;
    report(started_early >= 1 && init_off_main, name, detail);

    snprintf(name, sizeof(name), "%s: every off-main callback was serviced", label);
    snprintf(detail, sizeof(detail), "%llu callback(s) timed out", (unsigned long long)timeouts);
    report(timeouts == 0, name, detail);

    bool binds_on_main = total_calls(run, bind) == run->calls[bind][PHASE_PREPARE][THREAD_MAIN] +
                                                       run->calls[bind][PHASE_PENDING][THREAD_MAIN];
    snprintf(name, sizeof(name), "%s: %s ran only on the main thread during prepare or pending creation", label,
             callback_names[bind]);
    report(binds_on_main, name, "bind ran on another thread or during execution");

    if (rebind) {
        snprintf(name, sizeof(name), "%s: pending creation rebound once on the main thread", label);
        snprintf(detail, sizeof(detail), "%s ran %llu time(s) in pending creation on the main thread",
                 callback_names[bind], (unsigned long long)run->calls[bind][PHASE_PENDING][THREAD_MAIN]);
        report(run->calls[bind][PHASE_PENDING][THREAD_MAIN] == 1, name, detail);
    } else {
        snprintf(name, sizeof(name), "%s: prepare bound once on the main thread", label);
        snprintf(detail, sizeof(detail), "%s ran %llu time(s) in prepare on the main thread", callback_names[bind],
                 (unsigned long long)run->calls[bind][PHASE_PREPARE][THREAD_MAIN]);
        report(run->calls[bind][PHASE_PREPARE][THREAD_MAIN] == 1, name, detail);
    }

    if (is_copy) {
        snprintf(name, sizeof(name), "%s: copy_global_init ran once", label);
        snprintf(detail, sizeof(detail), "%llu call(s)", (unsigned long long)total_calls(run, CB_COPY_GLOBAL_INIT));
        report(total_calls(run, CB_COPY_GLOBAL_INIT) == 1, name, detail);
    }

    bool execution_only = true;
    bool executed = false;
    for (size_t index = 0; index < sizeof(execution_callbacks) / sizeof(execution_callbacks[0]); index++) {
        enum callback callback = execution_callbacks[index];

        if (total_calls(run, callback) != calls_in(run, callback, PHASE_EXECUTE)) {
            execution_only = false;
        }

        if (calls_in(run, callback, PHASE_EXECUTE) > 0) {
            executed = true;
        }
    }
    snprintf(name, sizeof(name), "%s: sink, finalize and execute ran only during execution", label);
    report(execution_only && executed, name, "one of them ran before execution or during destroy");
}

static void run_scenario(duckdb_connection connection, const char *statement_name, bool is_copy, bool streaming,
                         int *bump) {
    char sql[8192];
    char label[128];

    if (is_copy) {
        snprintf(sql, sizeof(sql), "COPY (SELECT b FROM items) TO '%s/probe.out' (FORMAT probe_fmt)", directory);
    } else {
        snprintf(sql, sizeof(sql), "SELECT probe_scalar(b) FROM items");
    }

    memset(&record, 0, sizeof(record));

    duckdb_prepared_statement prepared = NULL;
    atomic_store(&phase, PHASE_PREPARE);
    duckdb_state prepare_state = duckdb_prepare(connection, sql, &prepared);
    atomic_store(&phase, PHASE_SETUP);

    if (prepare_state == DuckDBError) {
        snprintf(label, sizeof(label), "%s/%s prepare", statement_name, streaming ? "streaming" : "buffered");
        report(false, label, duckdb_prepare_error(prepared));
        duckdb_destroy_prepare(&prepared);
        return;
    }

    for (int execution = 0; execution < 2; execution++) {
        bool rebind = execution == 1;
        snprintf(label, sizeof(label), "%s/%s/%s", statement_name, streaming ? "streaming" : "buffered",
                 rebind ? "rebind" : "first");

        if (rebind) {
            char ddl[64];
            snprintf(ddl, sizeof(ddl), "CREATE TABLE bump_%d (x INTEGER)", (*bump)++);

            char *error = run_sql(connection, ddl);
            if (error) {
                report(false, label, error);
                free(error);
                break;
            }

            memset(&record, 0, sizeof(record));
        }

        duckdb_pending_result pending = NULL;
        struct timespec started;
        clock_gettime(CLOCK_MONOTONIC, &started);

        atomic_store(&phase, PHASE_PENDING);
        duckdb_state pending_state = streaming ? duckdb_pending_prepared_streaming(prepared, &pending)
                                               : duckdb_pending_prepared(prepared, &pending);
        double pending_ms = elapsed_ms(&started);

        /* Before anything drives execution, wait (bounded) for a worker to
         * queue a callback that pending creation scheduled. */
        atomic_store(&phase, PHASE_AFTER_PENDING);
        pthread_mutex_lock(&lock);
        record.queued_at_pending_return = queue_length;
        driver_done = false;

        struct timespec deadline = deadline_after(timeout_seconds);
        while (queue_length == 0) {
            if (pthread_cond_timedwait(&changed, &lock, &deadline) == ETIMEDOUT) {
                break;
            }
        }
        pthread_mutex_unlock(&lock);
        atomic_store(&phase, PHASE_EXECUTE);

        if (pending_state == DuckDBError) {
            report(false, label, duckdb_pending_error(pending));
            duckdb_destroy_pending(&pending);
            atomic_store(&phase, PHASE_SETUP);
            break;
        }

        drive_job job = {pending, 0, 0, NULL};
        pthread_t driver;
        if (pthread_create(&driver, NULL, drive, &job) != 0) {
            report(false, label, "cannot start the driver thread");
            duckdb_destroy_pending(&pending);
            atomic_store(&phase, PHASE_SETUP);
            break;
        }

        if (!service_until_driver_done()) {
            /* The driver is stuck inside DuckDB; it cannot be joined. */
            print_record(label, &record, pending_ms);
            printf("FAIL %s: execution made no progress within %lld s\n", label, (long long)timeout_seconds);
            fflush(stdout);
            _Exit(1);
        }

        pthread_join(driver, NULL);

        atomic_store(&phase, PHASE_DESTROY);
        duckdb_destroy_pending(&pending);
        atomic_store(&phase, PHASE_SETUP);

        print_record(label, &record, pending_ms);

        char name[256];
        char detail[256];
        snprintf(name, sizeof(name), "%s: execution succeeded with the expected result", label);
        if (job.error) {
            report(false, name, job.error);
            free(job.error);
        } else if (is_copy) {
            snprintf(detail, sizeof(detail), "count %lld, sink rows %llu, finalized %llu", (long long)job.sum,
                     (unsigned long long)record.sink_rows, (unsigned long long)record.finalized);
            report(job.rows == 1 && job.sum == ROW_COUNT && record.sink_rows == ROW_COUNT && record.finalized == 1,
                   name, detail);
        } else {
            int64_t expected = (int64_t)ROW_COUNT * (ROW_COUNT - 1) / 2 + ROW_COUNT;
            snprintf(detail, sizeof(detail), "rows %llu, sum %lld, expected sum %lld", (unsigned long long)job.rows,
                     (long long)job.sum, (long long)expected);
            report(job.rows == ROW_COUNT && job.sum == expected, name, detail);
        }

        check_record(label, &record, is_copy, rebind);
    }

    duckdb_destroy_prepare(&prepared);
}

static int remove_entry(const char *path, const struct stat *status, int flag, struct FTW *walk) {
    (void)status;
    (void)flag;
    (void)walk;

    return remove(path);
}

int main(void) {
    const char *timeout_override = getenv("PENDING_PROBE_TIMEOUT_SECONDS");
    if (timeout_override && atoi(timeout_override) > 0) {
        timeout_seconds = atoi(timeout_override);
    }

    pthread_condattr_t attributes;
    pthread_condattr_init(&attributes);
    pthread_condattr_setclock(&attributes, CLOCK_MONOTONIC);
    pthread_cond_init(&changed, &attributes);
    pthread_condattr_destroy(&attributes);

    is_main = true;

    if (!mkdtemp(directory)) {
        perror("mkdtemp");
        return 2;
    }

    duckdb_database database = NULL;
    duckdb_connection connection = NULL;
    int bump = 0;
    int status = 2;

    if (duckdb_open(NULL, &database) == DuckDBError || duckdb_connect(database, &connection) == DuckDBError) {
        fputs("Cannot open DuckDB\n", stderr);
        goto cleanup;
    }

    if (!register_functions(connection)) {
        fputs("Cannot register the copy and scalar functions\n", stderr);
        goto cleanup;
    }

    char setup[128];
    snprintf(setup, sizeof(setup), "CREATE TABLE items AS SELECT range AS b FROM range(%d)", ROW_COUNT);

    char *error = run_sql(connection, "SET threads = 4");
    if (!error) {
        error = run_sql(connection, setup);
    }
    if (error) {
        fprintf(stderr, "Cannot set up: %s\n", error);
        free(error);
        goto cleanup;
    }

    printf("DuckDB %s\n", duckdb_library_version());
    print_setting(connection, "threads");
    print_setting(connection, "async_threads");
    printf("bounded wait %lld s\n", (long long)timeout_seconds);

    run_scenario(connection, "copy", true, false, &bump);
    run_scenario(connection, "copy", true, true, &bump);
    run_scenario(connection, "scalar", false, false, &bump);
    run_scenario(connection, "scalar", false, true, &bump);

    status = failures == 0 ? 0 : 1;
    printf("%d failure(s)\n", failures);

cleanup:
    duckdb_disconnect(&connection);
    duckdb_close(&database);
    nftw(directory, remove_entry, 16, FTW_DEPTH | FTW_PHYS);
    pthread_cond_destroy(&changed);
    return status;
}
