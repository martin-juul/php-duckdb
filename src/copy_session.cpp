/* Pumped statement execution. Statements that may reach a PHP callback
 * start on the request thread and then run on the connection's helper
 * thread, while the request thread services callbacks handed over by the
 * helper and DuckDB's worker threads. */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"
#include "php_duckdb.h"
#include "copy_session.h"
#include "copy.h"
#include "scalar.h"

#include <algorithm>
#include <cstdio>
#include <cstdlib>
#include <exception>

static int64_t steady_now() {
    return std::chrono::duration_cast<std::chrono::nanoseconds>(
               std::chrono::steady_clock::now().time_since_epoch())
        .count();
}

static bool env_flag(const char *name) {
    const char *value = std::getenv(name);
    return value && value[0] && !(value[0] == '0' && value[1] == '\0');
}

/* Test-only switches. They are read once per process. */
static bool force_pump() {
    static const bool enabled = env_flag("DUCKDB_PHP_TEST_FORCE_PUMP");
    return enabled;
}

/* Leases nested at least this deep never service their callbacks, as if
 * the request thread were stuck elsewhere; 1 covers every lease and 0
 * disables the switch. */
static int stuck_lease_depth() {
    static const int depth = [] {
        const char *value = std::getenv("DUCKDB_PHP_TEST_STUCK_LEASE");
        long parsed = value ? std::strtol(value, nullptr, 10) : 0;
        if (parsed < 0) {
            parsed = 0;
        }
        return static_cast<int>(parsed);
    }();
    return depth;
}

/* How long a queued callback tolerates the request thread staying away
 * from its leased session. Giving up turns a hang into an error. */
static int64_t stuck_limit_ns() {
    static const int64_t limit = [] {
        const char *value = std::getenv("DUCKDB_PHP_COPY_STUCK_SECONDS");
        long seconds = value ? std::strtol(value, nullptr, 10) : 0;
        if (seconds <= 0) {
            seconds = 60;
        }
        return static_cast<int64_t>(seconds) * 1000000000LL;
    }();
    return limit;
}

/* ------------------------------------------------------------------ */
/* Driver state (request thread)                                      */
/* ------------------------------------------------------------------ */

int64_t copy_driver::unserviced_for(const copy_session *session, int64_t queued_at) {
    std::lock_guard<std::mutex> lock(mutex);
    if (away_since == 0 || serving == session) {
        return 0;
    }
    return steady_now() - std::max(away_since, queued_at);
}

static std::shared_ptr<copy_driver> &this_driver() {
    static thread_local std::shared_ptr<copy_driver> driver = std::make_shared<copy_driver>();
    return driver;
}

/* Leases this request thread is inside, counting nested ones. */
static thread_local int lease_depth = 0;

/* From construction until destruction, the request thread services only
 * `serving` (nothing when null); the outer state is restored afterwards. */
class driver_away {
public:
    explicit driver_away(const copy_session *serving) : driver(*this_driver()) {
        std::lock_guard<std::mutex> lock(driver.mutex);
        outer_serving = driver.serving;
        outer_since = driver.away_since;
        driver.serving = serving;
        driver.away_since = steady_now();
    }

    ~driver_away() {
        std::lock_guard<std::mutex> lock(driver.mutex);
        driver.serving = outer_serving;
        driver.away_since = outer_since;
    }

    driver_away(const driver_away &) = delete;
    driver_away &operator=(const driver_away &) = delete;

private:
    copy_driver &driver;
    const copy_session *outer_serving = nullptr;
    int64_t outer_since = 0;
};

/* ------------------------------------------------------------------ */
/* Connection helpers                                                 */
/* ------------------------------------------------------------------ */

/* The thread that runs one connection's leased DuckDB work. It touches no
 * PHP state; callbacks it reaches go through the session's queue. */
struct conn_helper {
    std::mutex mutex;
    std::condition_variable cv;
    std::function<void()> work;
    bool busy = false;
    bool stop = false;
    std::thread thread;
};

static std::mutex helper_registry_mutex;
static std::vector<std::shared_ptr<conn_helper>> helper_registry;

static void helper_main(conn_helper *helper) {
    std::unique_lock<std::mutex> lock(helper->mutex);
    for (;;) {
        helper->cv.wait(lock, [helper] {
            return helper->stop || helper->work;
        });
        if (!helper->work) {
            return;
        }

        std::function<void()> work = std::move(helper->work);
        helper->work = nullptr;
        lock.unlock();

        work();
        work = nullptr;

        lock.lock();
        helper->busy = false;
        helper->cv.notify_all();
    }
}

/* The connection's helper, started on first use. Caller holds conn.mutex.
 * Throws std::system_error when the thread cannot start. */
static std::shared_ptr<conn_helper> conn_helper_for(conn_inner &conn) {
    if (conn.helper) {
        return conn.helper;
    }

    auto helper = std::make_shared<conn_helper>();
    helper->thread = std::thread(helper_main, helper.get());
    {
        std::lock_guard<std::mutex> lock(helper_registry_mutex);
        helper_registry.push_back(helper);
    }
    conn.helper = helper;
    return helper;
}

/* Hand `work` to the helper once its previous lease has parked. */
static void helper_post(conn_helper &helper, std::function<void()> work) {
    std::unique_lock<std::mutex> lock(helper.mutex);
    helper.cv.wait(lock, [&helper] {
        return !helper.busy;
    });
    helper.busy = true;
    helper.work = std::move(work);
    helper.cv.notify_all();
}

void duckdb_conn_helper_stop(const std::shared_ptr<conn_helper> &helper) {
    if (!helper) {
        return;
    }

    std::thread thread;
    {
        std::lock_guard<std::mutex> lock(helper->mutex);
        helper->stop = true;
        thread = std::move(helper->thread);
    }
    helper->cv.notify_all();
    if (thread.joinable()) {
        thread.join();
    }

    std::lock_guard<std::mutex> lock(helper_registry_mutex);
    helper_registry.erase(std::remove(helper_registry.begin(), helper_registry.end(), helper),
                          helper_registry.end());
}

void duckdb_helpers_shutdown() {
    std::vector<std::shared_ptr<conn_helper>> helpers;
    {
        std::lock_guard<std::mutex> lock(helper_registry_mutex);
        helpers = helper_registry;
    }
    for (auto &helper : helpers) {
        duckdb_conn_helper_stop(helper);
    }
}

/* ------------------------------------------------------------------ */
/* Sessions                                                           */
/* ------------------------------------------------------------------ */

bool duckdb_conn_needs_pump(const conn_inner &conn) {
    return force_pump() || conn.copy_registrations.load(std::memory_order_acquire) > 0 ||
           conn.scalar_registrations.load(std::memory_order_acquire) > 0;
}

void copy_session::service() {
    for (;;) {
        std::shared_ptr<copy_request> request;
        {
            std::lock_guard<std::mutex> lock(mutex);
            if (!open || queue.empty()) {
                return;
            }
            request = queue.front();
            queue.pop_front();
            request->started = true;
        }

        /* The work contains bailouts itself: it always returns here. */
        request->work();

        {
            std::lock_guard<std::mutex> lock(mutex);
            request->finished = true;
        }
        cv.notify_all();

        /* After exit() or a fatal error, no further PHP runs for this
         * statement: fail the queued and future callbacks. */
        if (duckdb_copy_unwinding()) {
            close(duckdb_copy_unwind_reason());
            return;
        }
    }
}

void copy_session::close(const std::string &reason) {
    {
        std::lock_guard<std::mutex> lock(mutex);
        if (!open) {
            return;
        }
        open = false;
        close_reason = reason;
        queue.clear();
    }
    cv.notify_all();
}

bool copy_session::submit(const std::shared_ptr<copy_request> &request, std::string &error) {
    std::unique_lock<std::mutex> lock(mutex);
    if (!open) {
        error = close_reason;
        return false;
    }
    request->queued_at = steady_now();
    queue.push_back(request);
    cv.notify_all();

    for (;;) {
        if (request->finished) {
            return true;
        }
        if (!request->started) {
            if (!open) {
                error = close_reason;
                return false;
            }

            if (driver_state->unserviced_for(this, request->queued_at) > stuck_limit_ns()) {
                open = false;
                close_reason = "COPY callback abandoned: the driving thread stayed inside DuckDB too long";
                queue.clear();
                error = close_reason;
                lock.unlock();
                cv.notify_all();
                return false;
            }
        }
        cv.wait_for(lock, std::chrono::milliseconds(50));
    }
}

idx_t duckdb_context_connection_id(duckdb_client_context context) {
    idx_t id = duckdb_client_context_get_connection_id(context);
    duckdb_destroy_client_context(&context);
    return id;
}

idx_t duckdb_conn_connection_id(conn_inner &conn) {
    if (!conn.connection_id_known) {
        duckdb_client_context context = nullptr;
        duckdb_connection_get_client_context(conn.conn, &context);
        conn.connection_id = duckdb_context_connection_id(context);
        conn.connection_id_known = true;
    }
    return conn.connection_id;
}

std::shared_ptr<copy_session> duckdb_session_open(conn_inner &conn) {
    auto session = std::make_shared<copy_session>();
    session->driver_state = this_driver();
    session->db = conn.db;
    session->connection_id = duckdb_conn_connection_id(conn);
    duckdb_copy_session_attach(conn, *session);
    duckdb_scalar_session_attach(conn, *session);
    {
        std::lock_guard<std::mutex> lock(conn.db->session_mutex);
        conn.db->sessions[session->connection_id] = session;
    }
    return session;
}

void duckdb_session_end(conn_inner &conn, const std::shared_ptr<copy_session> &session_ref,
                        const std::string &failure, bool can_call_php) {
    /* Keep the session alive while its finish hook runs, whatever owns the
     * caller's reference. */
    std::shared_ptr<copy_session> session = session_ref;
    session->close(failure.empty() ? "COPY statement finished" : failure);
    if (auto db = session->db.lock()) {
        std::lock_guard<std::mutex> lock(db->session_mutex);
        auto found = db->sessions.find(session->connection_id);
        if (found != db->sessions.end() && found->second.lock() == session) {
            db->sessions.erase(found);
        }
    }
    if (session->finish) {
        auto finish = std::move(session->finish);
        session->finish = nullptr;
        finish(failure, can_call_php);
    }
}

std::shared_ptr<copy_session> duckdb_session_find(db_inner &db, idx_t connection_id) {
    std::lock_guard<std::mutex> lock(db.session_mutex);
    auto found = db.sessions.find(connection_id);
    if (found == db.sessions.end()) {
        return nullptr;
    }
    return found->second.lock();
}

std::shared_ptr<copy_session> duckdb_session_find_bind(db_inner &db, idx_t connection_id) {
    auto session = duckdb_session_find(db, connection_id);
    if (!session || !session->marshal_bind.load(std::memory_order_acquire)) {
        return nullptr;
    }
    return session;
}

/* After exit() or a fatal error the helper gets the stuck limit to park;
 * one that does not would keep running DuckDB under a request that is
 * ending, so stop the process instead. */
[[noreturn]] static void abort_unparked_helper() {
    std::fprintf(stderr,
                 "DuckDB: the connection's helper thread did not stop within %lld seconds after exit() or a "
                 "fatal error in a PHP callback (DUCKDB_PHP_COPY_STUCK_SECONDS); aborting\n",
                 static_cast<long long>(stuck_limit_ns() / 1000000000LL));
    std::fflush(stderr);
    std::abort();
}

bool duckdb_session_lease(conn_inner &conn, copy_session &session, const std::function<void()> &work,
                          std::string &error) {
    std::shared_ptr<conn_helper> helper;
    try {
        helper = conn_helper_for(conn);
    } catch (const std::system_error &failure) {
        error = std::string("Unable to start the DuckDB helper thread: ") + failure.what();
        return false;
    }

    /* Guarded by session.mutex. The helper's last access to this frame is
     * setting it, so the frame outlives every use. */
    bool done = false;
    helper_post(*helper, [&work, &session, &done] {
        work();

        std::lock_guard<std::mutex> lock(session.mutex);
        done = true;
        session.cv.notify_all();
    });

    lease_depth++;
    bool stuck = stuck_lease_depth() > 0 && lease_depth >= stuck_lease_depth();
    bool unwinding = false;
    std::chrono::steady_clock::time_point park_deadline;
    /* Once exit() or a fatal error in a PHP callback is recorded, by this
     * lease's callbacks, a lease nested in them or before this lease began,
     * no PHP runs for the statement: close the session, interrupt the query
     * and give the helper the stuck limit to park. The caller then unwinds
     * normally and the method's after-hook re-raises. */
    auto check_unwinding = [&] {
        if (unwinding || !duckdb_copy_unwinding()) {
            return;
        }
        unwinding = true;
        session.close(duckdb_copy_unwind_reason());
        duckdb_interrupt(conn.conn);
        park_deadline = std::chrono::steady_clock::now() + std::chrono::nanoseconds(stuck_limit_ns());
    };
    {
        driver_away away(stuck ? nullptr : &session);
        for (;;) {
            check_unwinding();
            if (!stuck) {
                session.service();
            }
            check_unwinding();

            std::unique_lock<std::mutex> lock(session.mutex);
            if (!stuck && session.open && !session.queue.empty()) {
                continue;
            }
            if (done) {
                break;
            }
            if (!unwinding) {
                session.cv.wait(lock);
                continue;
            }
            if (session.cv.wait_until(lock, park_deadline) == std::cv_status::timeout && !done) {
                abort_unparked_helper();
            }
        }
    }
    lease_depth--;
    return true;
}

/* Helper thread: execute a pending query until it is ready or fails. When
 * DuckDB's workers hold every task, back off briefly instead of spinning. */
static duckdb_pending_state drive_pending(duckdb_pending_result pending) {
    int idle = 0;
    for (;;) {
        duckdb_pending_state state = duckdb_pending_execute_task(pending);
        if (state == DUCKDB_PENDING_RESULT_READY || state == DUCKDB_PENDING_ERROR) {
            return state;
        }

        if (state != DUCKDB_PENDING_NO_TASKS_AVAILABLE) {
            idle = 0;
            continue;
        }
        if (idle < 5) {
            idle++;
        }
        std::this_thread::sleep_for(std::chrono::microseconds(32 << idle));
    }
}

std::string duckdb_lease_failure(copy_session &session, const char *error, const char *fallback) {
    if (duckdb_copy_unwinding() && duckdb_classify_error_message(error) == DUCKDB_ERROR_INTERRUPT) {
        std::string reason = session.closed_reason();
        if (!reason.empty()) {
            return reason;
        }
    }
    return (error && error[0]) ? error : fallback;
}

duckdb_state duckdb_pump_execute(conn_inner &conn, duckdb_prepared_statement statement, bool streaming,
                                 duckdb_result *out, std::string &start_error,
                                 std::shared_ptr<copy_session> *stream_session) {
    /* Workers start as soon as the pending query exists, so the session must
     * be published first. */
    auto session = duckdb_session_open(conn);

    duckdb_pending_result pending = nullptr;
    duckdb_state started = streaming ? duckdb_pending_prepared_streaming(statement, &pending)
                                     : duckdb_pending_prepared(statement, &pending);
    if (started == DuckDBError) {
        const char *error = pending ? duckdb_pending_error(pending) : nullptr;
        start_error = (error && error[0]) ? error : "Failed to start query";
        duckdb_session_end(conn, session, start_error);
        if (pending) {
            duckdb_destroy_pending(&pending);
        }
        return DuckDBError;
    }

    duckdb_pending_state state = DUCKDB_PENDING_ERROR;
    duckdb_state result = DuckDBError;
    std::string lease_error;
    bool leased = duckdb_session_lease(
        conn, *session,
        [&] {
            state = drive_pending(pending);
            if (state == DUCKDB_PENDING_RESULT_READY) {
                result = duckdb_execute_pending(pending, out);
            }
        },
        lease_error);
    if (!leased) {
        start_error = lease_error;
        duckdb_session_end(conn, session, start_error);
        duckdb_discard_pending(conn, pending);
        return DuckDBError;
    }

    if (state == DUCKDB_PENDING_ERROR) {
        /* execute_pending would wrap this message in a generic error. */
        start_error = duckdb_lease_failure(*session, duckdb_pending_error(pending), "Query execution failed");
        /* Close before execute_pending, which cancels the executor. */
        duckdb_session_end(conn, session, start_error);
        duckdb_discard_pending(conn, pending);
        return DuckDBError;
    }

    /* A stream keeps executing while it is fetched: its callbacks still need
     * the session, which its open-result record now owns. */
    if (streaming && stream_session && result == DuckDBSuccess) {
        *stream_session = session;
    } else {
        duckdb_session_end(conn, session);
    }
    duckdb_destroy_pending(&pending);
    return result;
}

bool duckdb_pump_fetch_chunk(conn_inner &conn, copy_session &session, duckdb_result &result,
                             duckdb_data_chunk &chunk, std::string &error) {
    chunk = nullptr;
    return duckdb_session_lease(
        conn, session,
        [&] {
            chunk = duckdb_fetch_chunk(result);
        },
        error);
}

void duckdb_throw_start_error(const std::string &message) {
    duckdb_error_type type = duckdb_classify_error_message(message.c_str());
    if (type == DUCKDB_ERROR_INVALID) {
        type = DUCKDB_ERROR_INTERNAL;
    }
    duckdb_throw_error(type, message.c_str());
}

bool duckdb_pump_query(conn_inner &conn, const char *sql, duckdb_result *out, duckdb_statement_type *out_type) {
    duckdb_extracted_statements extracted = nullptr;
    idx_t count = duckdb_extract_statements(conn.conn, sql, &extracted);
    if (count == 0) {
        /* Keep duckdb_query()'s exact handling of empty input and parse
         * errors. DuckDB 2.0 runs the statements before a syntax error, so
         * this runs as a lease too, and their bind callbacks reach PHP
         * through the session from the helper. */
        duckdb_destroy_extracted(&extracted);
        auto session = duckdb_session_open(conn);
        session->marshal_bind.store(true, std::memory_order_release);

        duckdb_state state = DuckDBError;
        std::string lease_error;
        bool leased = duckdb_session_lease(
            conn, *session,
            [&] {
                state = duckdb_query(conn.conn, sql, out);
            },
            lease_error);
        if (!leased) {
            duckdb_session_end(conn, session, lease_error);
            duckdb_throw_start_error(lease_error);
            return false;
        }

        if (state == DuckDBError) {
            duckdb_session_end(conn, session, duckdb_lease_failure(*session, duckdb_result_error(out), "Query failed"));
            duckdb_throw_result_error(out);
            return false;
        }
        duckdb_session_end(conn, session);
        return true;
    }

    /* Like duckdb_query(): run every statement and stop at the first error.
     * The result returned is the first statement's, replaced by each later
     * statement's until one that returns rows is held. */
    bool held = false;
    bool held_rows = false;
    for (idx_t i = 0; i < count; i++) {
        duckdb_prepared_statement statement = nullptr;
        if (duckdb_prepare_extracted_statement(conn.conn, extracted, i, &statement) == DuckDBError) {
            const char *error = statement ? duckdb_prepare_error(statement) : nullptr;
            std::string message = error ? error : "Failed to prepare statement";
            duckdb_destroy_prepare(&statement);
            duckdb_destroy_extracted(&extracted);
            if (held) {
                duckdb_destroy_result(out);
            }
            duckdb_throw_prepare_error(message.c_str());
            return false;
        }

        duckdb_result current = {};
        std::string start_error;
        duckdb_statement_type current_type = duckdb_prepared_statement_type(statement);
        duckdb_state state = duckdb_pump_execute(conn, statement, false, &current, start_error);
        duckdb_destroy_prepare(&statement);
        if (!start_error.empty() || state == DuckDBError) {
            duckdb_destroy_extracted(&extracted);
            if (held) {
                duckdb_destroy_result(out);
            }
            if (!start_error.empty()) {
                duckdb_throw_start_error(start_error);
            } else {
                duckdb_throw_result_error(&current);
            }
            return false;
        }

        if (held && held_rows) {
            duckdb_destroy_result(&current);
            continue;
        }
        if (held) {
            duckdb_destroy_result(out);
        }
        *out = current;
        if (out_type) {
            *out_type = current_type;
        }
        held = true;
        held_rows = duckdb_result_return_type(*out) == DUCKDB_RESULT_TYPE_QUERY_RESULT;
    }

    duckdb_destroy_extracted(&extracted);
    return true;
}
