#ifndef PHP_DUCKDB_COPY_SESSION_H
#define PHP_DUCKDB_COPY_SESSION_H

#include "php_duckdb.h"

#include <chrono>
#include <deque>

/* Work a DuckDB thread hands to the request thread. `work` runs on the
 * request thread and may call PHP; it never runs once the session closes. */
struct copy_request {
    std::function<void()> work;
    /* Steady-clock nanoseconds when the request was queued. */
    int64_t queued_at = 0;
    /* Set by the request thread when it takes the request; from then on the
     * submitting thread waits for `finished` even if the session closes,
     * because `work` may reference its stack. */
    bool started = false;
    bool finished = false;
};

struct copy_session;

/* What the DuckDB threads waiting on a request thread see of it. Its
 * sessions share it; the guarded fields change as the thread enters and
 * leaves leases and DuckDB calls of its own. */
struct copy_driver {
    std::mutex mutex;
    /* The session whose lease the thread services right now, if any. */
    const copy_session *serving = nullptr;
    /* Steady-clock nanoseconds since the thread stopped servicing every
     * session except `serving`: it entered a lease or a DuckDB call of its
     * own. 0 while it is in neither. */
    int64_t away_since = 0;

    /* How long `session`'s request queued at `queued_at` has gone
     * unserviced because the thread is away; 0 while the thread services
     * that session's lease, or is in no lease and outside DuckDB. */
    int64_t unserviced_for(const copy_session *session, int64_t queued_at);
};

/* One pumped statement. While it is open, the connection's helper thread
 * drives DuckDB and the request thread runs queued requests, so PHP
 * callbacks reached from DuckDB threads never run off the request thread.
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
    std::shared_ptr<copy_driver> driver_state;
    /* Bind callbacks off the request thread run through the queue instead
     * of failing; set only for duckdb_query() leases. */
    std::atomic<bool> marshal_bind{false};
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

    /* Why the session closed; empty while it is open. */
    std::string closed_reason() {
        std::lock_guard<std::mutex> lock(mutex);
        return open ? std::string() : close_reason;
    }

    /* Request thread: run every queued request. */
    void service();
    /* Any thread: fail queued and future requests and wake waiters. */
    void close(const std::string &reason);
    /* DuckDB thread: queue `request` and wait until the request thread ran
     * it. Returns false with `error` set when the session closed first or
     * the driver stayed away from this session past the stuck limit. */
    bool submit(const std::shared_ptr<copy_request> &request, std::string &error);
};

/* The connection id of a client context, which this destroys. */
idx_t duckdb_context_connection_id(duckdb_client_context context);
/* DuckDB's connection id for `conn`, resolved once (request thread). */
idx_t duckdb_conn_connection_id(conn_inner &conn);

/* Whether statements on this connection must be pumped: it has live PHP
 * callback registrations, or the test-only forced-pump switch is set. */
bool duckdb_conn_needs_pump(const conn_inner &conn);

/* Open a session for the connection and publish it to PHP callbacks.
 * Caller holds conn.mutex on the request thread, through a statement-start
 * entry. */
std::shared_ptr<copy_session> duckdb_session_open(conn_inner &conn);
/* Close the session if still open, stop publishing it, and run its finish
 * hook. `failure` is empty when the statement succeeded. */
void duckdb_session_end(conn_inner &conn, const std::shared_ptr<copy_session> &session,
                        const std::string &failure = std::string(), bool can_call_php = true);
/* Find the open session for a DuckDB connection id (any thread). */
std::shared_ptr<copy_session> duckdb_session_find(db_inner &db, idx_t connection_id);
/* The open session that runs bind callbacks for a connection on a DuckDB
 * thread, or null when binding there is not pumped (any thread). */
std::shared_ptr<copy_session> duckdb_session_find_bind(db_inner &db, idx_t connection_id);

/* Run `work` on the connection's helper thread while the calling request
 * thread services the session's requests, and return once both are done.
 * Caller holds conn.mutex for the whole lease; the helper never takes it.
 * Returns false with `error` set when the helper cannot start. */
bool duckdb_session_lease(conn_inner &conn, copy_session &session, const std::function<void()> &work,
                          std::string &error);


/* Execute a prepared statement through the pump: bind callbacks run while
 * it starts on the request thread, then a lease executes it. Caller holds
 * conn.mutex.
 * When the statement fails to start or fails during execution, `out` is
 * untouched and `start_error` holds DuckDB's original message (throw it with
 * duckdb_throw_start_error()); otherwise `out` carries the result or its
 * error, like duckdb_execute_prepared().
 * A streaming statement keeps running while its result is fetched, so its
 * session stays open: on success it moves to `stream_session` for the
 * result's open-result record, and every other outcome ends it. */
duckdb_state duckdb_pump_execute(conn_inner &conn, duckdb_prepared_statement statement, bool streaming,
                                 duckdb_result *out, std::string &start_error,
                                 std::shared_ptr<copy_session> *stream_session = nullptr);

/* Fetch a pumped stream's next chunk as a lease on the connection's helper,
 * while the request thread services the stream's callbacks. Caller holds
 * conn.mutex through an own-result entry. Returns false with `error` set
 * when the helper cannot start; otherwise `chunk` is what
 * duckdb_fetch_chunk() returned. */
bool duckdb_pump_fetch_chunk(conn_inner &conn, copy_session &session, duckdb_result &result,
                             duckdb_data_chunk &chunk, std::string &error);

/* Why a leased call failed with `error`. When exit() or a fatal error ended
 * the lease, DuckDB may report the interrupt that caused instead; the
 * session's close reason names the bailout. */
std::string duckdb_lease_failure(copy_session &session, const char *error, const char *fallback);

/* Run all statements of `sql` through the pump with duckdb_query()'s
 * semantics: stop at the first error and return the result duckdb_query()
 * would. Caller holds conn.mutex. Throws and returns false on failure. */
bool duckdb_pump_query(conn_inner &conn, const char *sql, duckdb_result *out,
                       duckdb_statement_type *out_type = nullptr);

/* Throw a start failure reported by duckdb_pump_execute(). */
void duckdb_throw_start_error(const std::string &message);

#endif
