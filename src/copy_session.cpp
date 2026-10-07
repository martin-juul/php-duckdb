/* Pumped statement execution. Statements that may reach a PHP COPY format
 * run through DuckDB's pending API on the request thread, which services
 * callbacks handed over by DuckDB worker threads between execution slices. */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"
#include "php_duckdb.h"
#include "copy_session.h"
#include "copy.h"

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

static bool service_only() {
    static const bool enabled = env_flag("DUCKDB_PHP_TEST_PUMP_SERVICE_ONLY");
    return enabled;
}

/* The driver behaves as if it never left DuckDB: it stops servicing
 * requests and its stuck clock keeps running. */
static bool stuck_driver() {
    static const bool enabled = env_flag("DUCKDB_PHP_TEST_STUCK_DRIVER");
    return enabled;
}

/* How long a waiting worker tolerates the driver staying inside one DuckDB
 * call. A pending engine error can make that call spin until every task
 * ends, including the waiting worker's; giving up turns that into an error. */
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

bool duckdb_conn_needs_pump(const conn_inner &conn) {
    return force_pump() || conn.copy_registrations.load(std::memory_order_acquire) > 0;
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

        request->work();

        {
            std::lock_guard<std::mutex> lock(mutex);
            request->finished = true;
        }
        cv.notify_all();
    }
}

void copy_session::wait_for_work(std::chrono::milliseconds timeout) {
    std::unique_lock<std::mutex> lock(mutex);
    cv.wait_for(lock, timeout, [this] {
        return !open || !queue.empty();
    });
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

            int64_t since = duckdb_since.load(std::memory_order_acquire);
            if (since != 0 && steady_now() - since > stuck_limit_ns()) {
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
    if (conn.session) {
        duckdb_session_end(conn, conn.session, "COPY statement superseded by another statement on its connection");
    }

    auto session = std::make_shared<copy_session>();
    session->db = conn.db;
    session->connection_id = duckdb_conn_connection_id(conn);
    duckdb_copy_session_attach(conn, *session);
    {
        std::lock_guard<std::mutex> lock(conn.db->session_mutex);
        conn.db->sessions[session->connection_id] = session;
    }
    conn.session = session;
    return session;
}

void duckdb_session_end(conn_inner &conn, const std::shared_ptr<copy_session> &session_ref,
                        const std::string &failure, bool can_call_php) {
    /* The caller may pass conn.session itself, which is reset below. */
    std::shared_ptr<copy_session> session = session_ref;
    session->close(failure.empty() ? "COPY statement finished" : failure);
    if (auto db = session->db.lock()) {
        std::lock_guard<std::mutex> lock(db->session_mutex);
        auto found = db->sessions.find(session->connection_id);
        if (found != db->sessions.end() && found->second.lock() == session) {
            db->sessions.erase(found);
        }
    }
    if (conn.session == session) {
        conn.session.reset();
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

duckdb_pending_state duckdb_session_step(copy_session &session, duckdb_pending_result pending) {
    if (stuck_driver()) {
        int64_t idle = 0;
        session.duckdb_since.compare_exchange_strong(idle, steady_now(), std::memory_order_acq_rel);
    } else {
        session.service();
        session.duckdb_since.store(steady_now(), std::memory_order_release);
    }

    duckdb_pending_state state;
    if (service_only()) {
        state = duckdb_pending_execute_check_state(pending);
        /* check_state reports a finished execution as an error without a
         * message; execute_pending then returns the result. */
        const char *error = state == DUCKDB_PENDING_ERROR ? duckdb_pending_error(pending) : nullptr;
        if (state == DUCKDB_PENDING_ERROR && (!error || !error[0])) {
            state = DUCKDB_PENDING_RESULT_READY;
        }
    } else {
        state = duckdb_pending_execute_task(pending);
    }
    if (!stuck_driver()) {
        session.duckdb_since.store(0, std::memory_order_release);
    }

    if (state == DUCKDB_PENDING_NO_TASKS_AVAILABLE ||
        (service_only() && state == DUCKDB_PENDING_RESULT_NOT_READY)) {
        session.wait_for_work(std::chrono::milliseconds(1));
    }
    return state;
}

duckdb_state duckdb_pump_execute(conn_inner &conn, duckdb_prepared_statement statement, bool streaming,
                                 duckdb_result *out, std::string &start_error) {
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

    duckdb_pending_state state;
    do {
        state = duckdb_session_step(*session, pending);
    } while (state != DUCKDB_PENDING_RESULT_READY && state != DUCKDB_PENDING_ERROR);

    if (state == DUCKDB_PENDING_ERROR) {
        /* execute_pending would wrap this message in a generic error. */
        const char *error = duckdb_pending_error(pending);
        start_error = (error && error[0]) ? error : "Query execution failed";
        /* Close before execute_pending, which cancels the executor. */
        duckdb_session_end(conn, session, start_error);
        duckdb_discard_pending(conn, pending);
        return DuckDBError;
    }
    duckdb_session_end(conn, session);
    duckdb_state result = duckdb_execute_pending(pending, out);
    duckdb_destroy_pending(&pending);
    return result;
}

void duckdb_throw_start_error(const std::string &message) {
    duckdb_error_type type = duckdb_classify_error_message(message.c_str());
    if (type == DUCKDB_ERROR_INVALID) {
        type = DUCKDB_ERROR_INTERNAL;
    }
    duckdb_throw_error(type, message.c_str());
}

bool duckdb_pump_query(conn_inner &conn, const char *sql, duckdb_result *out) {
    duckdb_extracted_statements extracted = nullptr;
    idx_t count = duckdb_extract_statements(conn.conn, sql, &extracted);
    if (count == 0) {
        /* No statement can reach a COPY format; keep duckdb_query()'s exact
         * handling of empty input and parse errors. */
        duckdb_destroy_extracted(&extracted);
        if (duckdb_query(conn.conn, sql, out) == DuckDBError) {
            duckdb_throw_result_error(out);
            return false;
        }
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
        held = true;
        held_rows = duckdb_result_return_type(*out) == DUCKDB_RESULT_TYPE_QUERY_RESULT;
    }

    duckdb_destroy_extracted(&extracted);
    return true;
}
