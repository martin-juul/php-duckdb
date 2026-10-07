#ifndef PHP_DUCKDB_COPY_SESSION_H
#define PHP_DUCKDB_COPY_SESSION_H

#include "php_duckdb.h"

#include <chrono>
#include <deque>

/* Work a DuckDB thread hands to the request thread. `work` runs on the
 * request thread and may call PHP; it never runs once the session closes. */
struct copy_request {
    std::function<void()> work;
    /* Set by the request thread when it takes the request; from then on the
     * submitting thread waits for `finished` even if the session closes,
     * because `work` may reference its stack. */
    bool started = false;
    bool finished = false;
};

/* One pumped statement. While it is open, the request thread drives DuckDB
 * through the pending API and runs queued requests between slices, so COPY
 * callbacks on DuckDB worker threads can reach PHP without PHP ever running
 * off the request thread.
 *
 * Every exit moves the session to closed before any DuckDB call that can
 * cancel the executor: DuckDB's cancellation spins until every task ends,
 * and a worker waiting here would never end otherwise. */
struct copy_session {
    std::mutex mutex;
    std::condition_variable cv;
    bool open = true;
    std::string close_reason;
    std::deque<std::shared_ptr<copy_request>> queue;
    /* The request thread that drives this session. */
    std::thread::id driver = std::this_thread::get_id();
    /* Steady-clock nanoseconds since the driver entered its current DuckDB
     * call on this connection; 0 while it is outside DuckDB. */
    std::atomic<int64_t> duckdb_since{0};
    std::weak_ptr<db_inner> db;
    idx_t connection_id = 0;
    /* COPY global_init calls in this statement; more than one means a
     * parallel or multi-file COPY, which PHP formats do not support. */
    std::atomic<int> copy_inits{0};
    /* Installed by the COPY layer: release PHP-side state when the session
     * ends. `failure` is empty on success; PHP may only run when
     * `can_call_php` is true. */
    std::function<void(const std::string &failure, bool can_call_php)> finish;
    /* The COPY layer's request-thread state, cleared when the session ends.
     * Only the request thread dereferences it. */
    std::atomic<void *> php_state{nullptr};

    bool is_open() {
        std::lock_guard<std::mutex> lock(mutex);
        return open;
    }

    /* Request thread: run every queued request. */
    void service();
    /* Request thread: wait up to `timeout` for queued work. */
    void wait_for_work(std::chrono::milliseconds timeout);
    /* Any thread: fail queued and future requests and wake waiters. */
    void close(const std::string &reason);
    /* DuckDB thread: queue `request` and wait until the request thread ran
     * it. Returns false with `error` set when the session closed first or
     * the driver stayed inside one DuckDB call past the stuck limit. */
    bool submit(const std::shared_ptr<copy_request> &request, std::string &error);
};

/* The connection id of a client context, which this destroys. */
idx_t duckdb_context_connection_id(duckdb_client_context context);
/* DuckDB's connection id for `conn`, resolved once (request thread). */
idx_t duckdb_conn_connection_id(conn_inner &conn);

/* Whether statements on this connection must be pumped: it has live COPY
 * format registrations, or the test-only forced-pump switch is set. */
bool duckdb_conn_needs_pump(const conn_inner &conn);

/* Open a session for the connection and publish it to COPY callbacks.
 * Caller holds conn.mutex on the request thread. */
std::shared_ptr<copy_session> duckdb_session_open(conn_inner &conn);
/* Close the session if still open, stop publishing it, and run its finish
 * hook. `failure` is empty when the statement succeeded. */
void duckdb_session_end(conn_inner &conn, const std::shared_ptr<copy_session> &session,
                        const std::string &failure = std::string(), bool can_call_php = true);
/* Find the open session for a DuckDB connection id (any thread). */
std::shared_ptr<copy_session> duckdb_session_find(db_inner &db, idx_t connection_id);

/* Drive one slice of a pending result, servicing the session first. In the
 * test-only service-only mode it never executes DuckDB tasks itself. */
duckdb_pending_state duckdb_session_step(copy_session &session, duckdb_pending_result pending);

/* Execute a prepared statement through the pump. Caller holds conn.mutex.
 * When the statement fails to start or fails during execution, `out` is
 * untouched and `start_error` holds DuckDB's original message (throw it with
 * duckdb_throw_start_error()); otherwise `out` carries the result or its
 * error, like duckdb_execute_prepared(). */
duckdb_state duckdb_pump_execute(conn_inner &conn, duckdb_prepared_statement statement, bool streaming,
                                 duckdb_result *out, std::string &start_error);

/* Run all statements of `sql` through the pump with duckdb_query()'s
 * semantics: stop at the first error and return the first statement's
 * result. Caller holds conn.mutex. Throws and returns false on failure. */
bool duckdb_pump_query(conn_inner &conn, const char *sql, duckdb_result *out);

/* Throw a start failure reported by duckdb_pump_execute(). */
void duckdb_throw_start_error(const std::string &message);

#endif
