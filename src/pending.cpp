/*
  +----------------------------------------------------------------------+
  | duckdb - native DuckDB driver for PHP                                |
  +----------------------------------------------------------------------+
  | Copyright (c) Martin Juul Christiansen (https://juul.xyz)            |
  +----------------------------------------------------------------------+
  | This source file is subject to the MIT license that is bundled with  |
  | this package in the file LICENSE.                                    |
  +----------------------------------------------------------------------+
  | DuckDB\PendingQuery and the asynchronous execution machinery:        |
  | background worker threads with completion notification, a            |
  | single-threaded polling mode, and the suspend() dispatcher. Each     |
  | runtime integration (Swoole, True Async, AMPHP, ReactPHP) lives in   |
  | its own source file, src/suspend_*.cpp.                              |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"
#include "php_streams.h"
#include "main/php_network.h"
#include "php_duckdb.h"
#include "copy.h"
#include "copy_session.h"
#include "suspend_internal.h"

#include <algorithm>
#include <chrono>
#include <thread>

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

duckdb_notify_fd duckdb_notify_fd_duplicate(duckdb_notify_fd fd) {
    if (!duckdb_notify_fd_valid(fd)) {
        return DUCKDB_INVALID_NOTIFY_FD;
    }
#ifdef PHP_WIN32
    WSAPROTOCOL_INFO protocol_info;
    if (WSADuplicateSocket(fd, GetCurrentProcessId(), &protocol_info) != 0) {
        return DUCKDB_INVALID_NOTIFY_FD;
    }
    return WSASocket(FROM_PROTOCOL_INFO, FROM_PROTOCOL_INFO, FROM_PROTOCOL_INFO,
                     &protocol_info, 0, WSA_FLAG_OVERLAPPED | WSA_FLAG_NO_HANDLE_INHERIT);
#else
    return dup(fd);
#endif
}

bool duckdb_create_notify_pipe(duckdb_notify_fd fds[2]) {
    fds[0] = fds[1] = DUCKDB_INVALID_NOTIFY_FD;
#ifdef PHP_WIN32
    /* PHP initializes Winsock before extension startup. A loopback socket
     * pair works with PHP's socket streams and Windows select(), whereas
     * CRT pipe descriptors cannot be monitored by stream_select(). */
    SOCKET listener = WSASocket(AF_INET, SOCK_STREAM, IPPROTO_TCP, NULL, 0,
                                WSA_FLAG_OVERLAPPED | WSA_FLAG_NO_HANDLE_INHERIT);
    SOCKET writer = INVALID_SOCKET;
    SOCKET reader = INVALID_SOCKET;
    int error = 0;
    sockaddr_in address = {};
    address.sin_family = AF_INET;
    address.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
    int address_len = sizeof(address);
    if (listener == INVALID_SOCKET ||
        bind(listener, reinterpret_cast<sockaddr *>(&address), sizeof(address)) == SOCKET_ERROR ||
        getsockname(listener, reinterpret_cast<sockaddr *>(&address), &address_len) == SOCKET_ERROR ||
        listen(listener, 1) == SOCKET_ERROR) {
        error = WSAGetLastError();
    } else {
        writer = WSASocket(AF_INET, SOCK_STREAM, IPPROTO_TCP, NULL, 0,
                           WSA_FLAG_OVERLAPPED | WSA_FLAG_NO_HANDLE_INHERIT);
        if (writer == INVALID_SOCKET ||
            connect(writer, reinterpret_cast<sockaddr *>(&address), sizeof(address)) == SOCKET_ERROR) {
            error = WSAGetLastError();
        } else {
            reader = accept(listener, NULL, NULL);
            if (reader == INVALID_SOCKET) {
                error = WSAGetLastError();
            } else if (!SetHandleInformation(reinterpret_cast<HANDLE>(reader), HANDLE_FLAG_INHERIT, 0)) {
                error = static_cast<int>(GetLastError());
            }
        }
    }
    duckdb_notify_fd_close(listener);
    if (error != 0) {
        duckdb_notify_fd_close(reader);
        duckdb_notify_fd_close(writer);
        zend_throw_exception_ex(duckdb_exception_ce, 0,
                                "Unable to create query completion sockets (%d)", error);
        return false;
    }
    fds[0] = reader;
    fds[1] = writer;
#else
    if (socketpair(AF_UNIX, SOCK_STREAM, 0, fds) != 0) {
        zend_throw_exception_ex(duckdb_exception_ce, 0, "socketpair() failed (%d)", errno);
        return false;
    }
#endif
    return true;
}

namespace {
struct duckdb_async_worker {
    std::weak_ptr<async_task> task;
    std::thread thread;
    bool finished = false;
};

std::mutex duckdb_async_registry_mu;
std::condition_variable duckdb_async_registry_cv;
std::vector<std::unique_ptr<duckdb_async_worker>> duckdb_async_registry;
size_t duckdb_async_active = 0;
}

void duckdb_async_shutdown() {
    std::unique_lock<std::mutex> lk(duckdb_async_registry_mu);
    while (duckdb_async_active > 0) {
        /* Interrupt every connection with an in-flight worker so a
         * runaway query cannot stall process shutdown; the worker notices
         * at the next DuckDB task boundary. Holding the shared_ptr keeps
         * the connection alive across the interrupt call itself. */
        std::vector<std::shared_ptr<conn_inner>> conns;
        for (const auto &worker : duckdb_async_registry) {
            if (auto t = worker->task.lock()) {
                if (t->conn) {
                    conns.push_back(t->conn);
                }
            }
        }
        lk.unlock();
        for (const auto &conn : conns) {
            duckdb_interrupt(conn->conn);
        }
        lk.lock();
        if (duckdb_async_active > 0) {
            duckdb_async_registry_cv.wait_for(lk, std::chrono::milliseconds(10));
        }
    }

    /* A completion notification precedes the thread's C++ and libc exit
     * cleanup. Join before PHP unloads the extension or closes DuckDB so
     * its thread-local destructors cannot race module teardown. */
    for (const auto &worker : duckdb_async_registry) {
        worker->thread.join();
    }
    duckdb_async_registry.clear();
}

/* Worker body: everything that touches task/connection/DuckDB state. The
 * parameter's shared_ptr release at this scope's end may run ~async_task
 * (and even ~conn_inner -> duckdb_disconnect), i.e. libduckdb code, so the
 * registry completion is published strictly after this returns. */
static void duckdb_async_run_task(std::shared_ptr<async_task> task) {
    {
        std::lock_guard<std::mutex> connection_lock(task->conn->mutex);
        bool cancelled;
        {
            std::lock_guard<std::mutex> task_lock(task->m);
            cancelled = task->cancel_requested;
            task->worker_running = !cancelled;
        }

        if (cancelled) {
            task->error = true;
            task->error_type = DUCKDB_ERROR_INTERRUPT;
            task->error_msg = "Query interrupted";
        } else {
            /* A statement start supersedes the connection's open results,
             * including those opened after this task was queued. No PHP
             * runs here: superseded sessions' finish hooks go to the
             * request thread. */
            duckdb_conn_supersede_from_worker(*task->conn);

            duckdb_state state;
            if (task->mode == task_mode::THREAD_PREPARED) {
                state = duckdb_execute_prepared(task->stmt->stmt, &task->result);
            } else {
                state = duckdb_query(task->conn->conn, task->sql.c_str(), &task->result);
            }
            if (state == DuckDBError) {
                task->error = true;
                task->error_type = duckdb_result_error_type(&task->result);
                const char *err = duckdb_result_error(&task->result);
                task->error_msg = err ? err : "Query failed";
            }
        }

        if (task->error) {
            // Retain the copied error, but release its native result before
            // publishing completion. Dropping this handle must not wait for
            // a newer query to release the connection mutex.
            duckdb_destroy_result(&task->result);
            task->consumed = true;
        }

        duckdb_conn_worker_end(*task->conn);

        // Publish completion before a subsequent query can acquire this
        // connection. Cancellation interrupts under the same task mutex.
        {
            std::lock_guard<std::mutex> task_lock(task->m);
            task->worker_running = false;
            task->done = true;
        }
    }

    task->cv.notify_all();
    if (task->cancellation_worker.joinable()) {
        task->cancellation_worker.join();
    }

    if (duckdb_notify_fd_valid(task->notify_write_fd)) {
        char b = 1;
#ifdef PHP_WIN32
        int n;
        do {
            n = send(task->notify_write_fd, &b, 1, 0);
        } while (n == SOCKET_ERROR && WSAGetLastError() == WSAEINTR);
#else
        /* send() with MSG_NOSIGNAL: the PHP side may have closed its read
         * end (PendingQuery dropped before completion), and a plain
         * write() would raise SIGPIPE - fatal in SAPIs that do not ignore
         * it. */
        ssize_t n;
        do {
            n = send(task->notify_write_fd, &b, 1, MSG_NOSIGNAL);
        } while (n == -1 && errno == EINTR);
#endif
        duckdb_notify_fd_close(task->notify_write_fd);
        task->notify_write_fd = DUCKDB_INVALID_NOTIFY_FD;
    }

    task->cv.notify_all();
}

void duckdb_async_worker_start(const std::shared_ptr<async_task> &task) {
    std::lock_guard<std::mutex> lk(duckdb_async_registry_mu);

    /* Bound retained thread handles during long-lived requests. Finished
     * workers no longer acquire the registry lock, so joining here cannot
     * block an active query or deadlock its completion notification. */
    duckdb_async_registry.erase(
        std::remove_if(duckdb_async_registry.begin(), duckdb_async_registry.end(),
                       [](const std::unique_ptr<duckdb_async_worker> &worker) {
                           if (!worker->finished) {
                               return false;
                           }
                           worker->thread.join();
                           return true;
                       }),
        duckdb_async_registry.end());

    auto worker = std::make_unique<duckdb_async_worker>();
    worker->task = task;
    duckdb_async_worker *raw_worker = worker.get();
    duckdb_async_registry.push_back(std::move(worker));

    /* This request thread runs the finish hooks of the sessions the worker
     * supersedes. */
    duckdb_sessions_watch(task->conn);
    duckdb_conn_worker_begin(*task->conn);

    try {
        raw_worker->thread = std::thread([task = task, raw_worker]() mutable {
            duckdb_async_run_task(std::move(task));
            {
                std::lock_guard<std::mutex> completion_lock(duckdb_async_registry_mu);
                raw_worker->finished = true;
                duckdb_async_active--;
            }
            duckdb_async_registry_cv.notify_all();
        });
    } catch (...) {
        duckdb_conn_worker_end(*task->conn);
        duckdb_async_registry.pop_back();
        throw;
    }
    duckdb_async_active++;
}

/* How long one step of a pumped pending query lets the connection's helper
 * execute before control returns to PHP. It keeps the cadence of polling
 * adapters, which yield for 1 ms between steps. */
static constexpr std::chrono::microseconds pending_slice_budget{1000};

/* Helper thread: execute a pending query until it is ready or fails, or the
 * slice budget runs out. When DuckDB's workers hold every task, back off
 * briefly instead of spinning. */
static duckdb_pending_state drive_pending_slice(duckdb_pending_result pending) {
    auto deadline = std::chrono::steady_clock::now() + pending_slice_budget;
    int idle = 0;
    for (;;) {
        duckdb_pending_state state = duckdb_pending_execute_task(pending);
        if (state == DUCKDB_PENDING_RESULT_READY || state == DUCKDB_PENDING_ERROR) {
            return state;
        }

        auto now = std::chrono::steady_clock::now();
        if (now >= deadline) {
            return state;
        }

        if (state != DUCKDB_PENDING_NO_TASKS_AVAILABLE) {
            idle = 0;
            continue;
        }
        if (idle < 5) {
            idle++;
        }
        auto backoff = std::chrono::duration_cast<std::chrono::steady_clock::duration>(
            std::chrono::microseconds(32 << idle));
        std::this_thread::sleep_for(std::min(backoff, deadline - now));
    }
}

/* Record an executed pending query's outcome on its task and release the
 * native handle. Caller holds conn.mutex. */
static void duckdb_task_finish_pending(async_task &task, open_result &record, duckdb_state execute_state) {
    if (execute_state == DuckDBError) {
        task.error = true;
        task.error_type = duckdb_result_error_type(&task.result);
        const char *e = duckdb_result_error(&task.result);
        task.error_msg = (e && e[0]) ? e : "Query execution failed";
    }

    /* duckdb_execute_pending does NOT consume the pending handle; it stays
     * owned by the record and must be destroyed explicitly. */
    duckdb_destroy_pending(&record.pending);
    duckdb_open_result_drained(*task.conn, record);
    task.record.reset();
    if (task.error) {
        duckdb_destroy_result(&task.result);
        task.consumed = true;
    }
}

/* Advance a task by one step and report completion.
 *
 * THREAD_* modes: the worker does the real work; this only observes.
 * POLLING mode: executes one slice of the DuckDB task graph while holding
 * the connection mutex, so calling this in a loop makes real progress
 * without worker threads and without busy-waiting. A pumped query's slice
 * is a lease: the connection's helper thread runs DuckDB while this thread
 * services the PHP callbacks it and DuckDB's workers hand over, so this
 * thread never waits inside DuckDB on a worker that needs PHP. Other
 * queries step on the calling thread. Must only be called from the owning
 * request thread. */
static bool duckdb_task_step_once(const std::shared_ptr<async_task> &task) {
    if (task->mode != task_mode::POLLING) {
        std::lock_guard<std::mutex> lk(task->m);
        return task->done;
    }
    if (!task->record) {
        /* finished, superseded or cancelled */
        std::lock_guard<std::mutex> lk(task->m);
        return task->done;
    }

    bool finished = false;
    {
        /* Own-result entry: the PendingQuery method checked the busy state
         * on entry, and stepping must not supersede its own statement. */
        std::lock_guard<std::mutex> lk(task->conn->mutex);
        open_result &record = *task->record;
        if (record.state == open_result_state::superseded) {
            /* A newer statement destroyed the pending query; its writers
             * abort here unless that statement already ran them. */
            if (record.session) {
                duckdb_session_end(*task->conn, record.session, duckdb_superseded_message, true);
            }
            task->error = true;
            task->error_type = DUCKDB_ERROR_INVALID;
            task->error_msg = duckdb_superseded_message;
            task->consumed = true;
            task->record.reset();
            finished = true;
        } else if (record.session) {
            std::shared_ptr<copy_session> session = record.session;
            duckdb_pending_state st = DUCKDB_PENDING_ERROR;
            bool executed = false;
            duckdb_state execute_state = DuckDBError;
            std::string lease_error;
            bool leased = duckdb_session_lease(
                *task->conn, *session,
                [&] {
                    st = drive_pending_slice(record.pending);
                    if (st == DUCKDB_PENDING_RESULT_READY) {
                        execute_state = duckdb_execute_pending(record.pending, &task->result);
                        executed = true;
                    }
                },
                lease_error);

            if (!leased) {
                duckdb_session_end(*task->conn, session, lease_error, true);
                duckdb_open_result_destroy(*task->conn, record, lease_error);
                task->error = true;
                task->error_type = DUCKDB_ERROR_INTERNAL;
                task->error_msg = lease_error;
                task->consumed = true;
                task->record.reset();
                finished = true;
            } else if (st == DUCKDB_PENDING_RESULT_READY || st == DUCKDB_PENDING_ERROR) {
                std::string failure;
                if (st == DUCKDB_PENDING_ERROR) {
                    failure = duckdb_lease_failure(*session, duckdb_pending_error(record.pending),
                                                     "Query execution failed");
                }
                /* On error, close before execute_pending, which cancels the
                 * executor; it then carries the precise error and type. */
                duckdb_session_end(*task->conn, session, failure, true);
                if (!executed) {
                    execute_state = duckdb_execute_pending(record.pending, &task->result);
                }
                duckdb_task_finish_pending(*task, record, execute_state);
                finished = true;
            }
        } else {
            duckdb_pending_state st = duckdb_pending_execute_task(record.pending);
            if (st == DUCKDB_PENDING_RESULT_READY || st == DUCKDB_PENDING_ERROR) {
                /* For both states, duckdb_execute_pending produces the final
                 * materialized result - on failure it also carries the
                 * precise error message and error type. */
                duckdb_state execute_state = duckdb_execute_pending(record.pending, &task->result);
                duckdb_task_finish_pending(*task, record, execute_state);
                finished = true;
            }
            /* DUCKDB_PENDING_RESULT_NOT_READY: call again to execute the
             * next task. DUCKDB_PENDING_NO_TASKS_AVAILABLE: all tasks are
             * currently claimed by DuckDB's own worker threads; call again
             * shortly. */
        }
    }

    if (finished) {
        std::lock_guard<std::mutex> lk(task->m);
        task->done = true;
        task->cv.notify_all();
        return true;
    }
    return false;
}

/* A step's handler exception fails the query, but DuckDB may report that
 * failure on a later step, and the step's own method call or a runtime's
 * yield between steps would otherwise drop or misplace it. The task keeps
 * the first one until its error is thrown. */
static void duckdb_task_keep_late_cause(async_task &task) {
    zend_object *cause = duckdb_callback_take_cause();
    if (!cause) {
        return;
    }
    if (task.late_cause) {
        duckdb_callback_release(task.conn.get(), cause);
        return;
    }
    task.late_cause = cause;
}

bool duckdb_task_step(std::shared_ptr<async_task> task) {
    /* A handler exception kept before this step belongs to the code that
     * called it, not to this query. */
    bool own_causes = task->mode == task_mode::POLLING && !duckdb_callback_has_cause();
    bool done = duckdb_task_step_once(task);

    /* After exit() or a fatal error in a COPY handler, finish the failing
     * statement here rather than letting a runtime yield to other code. */
    while (!done && duckdb_copy_unwinding()) {
        done = duckdb_task_step_once(task);
    }

    if (own_causes) {
        duckdb_task_keep_late_cause(*task);
    }
    return done;
}

void duckdb_pending_release_late_cause(async_task &task) {
    zend_object *cause = task.late_cause;
    task.late_cause = nullptr;
    duckdb_callback_release(task.conn.get(), cause);
}

void duckdb_pending_complete(php_duckdb_pending_object *intern, zval *return_value) {
    std::shared_ptr<async_task> task = intern->task;

    if (task->error) {
        /* The kept handler exception becomes the cause of this error, unless
         * the calling code already holds one of its own. */
        if (task->late_cause && !duckdb_callback_has_cause()) {
            duckdb_callback_keep_cause(task->late_cause);
            task->late_cause = nullptr;
        }
        duckdb_pending_release_late_cause(*task);
        duckdb_throw_error(task->error_type, task->error_msg.c_str());
        RETURN_THROWS();
    }
    duckdb_pending_release_late_cause(*task);
    if (task->consumed) {
        duckdb_throw_msg("Result has already been consumed");
        RETURN_THROWS();
    }
    task->consumed = true;

    duckdb_result_instantiate(return_value, &task->result, /*streaming=*/false, nullptr, task->conn,
                              task->statement_type);
}

/* ================================================================== */
/* Shared helpers used by the runtime integrations (src/suspend_*.cpp) */
/* ================================================================== */

/* Call a global function or "Class::staticMethod" by name. Returns false
 * when the call failed or threw (EG(exception) is then set). */
bool duckdb_call_php(const char *name, uint32_t argc, zval *args, zval *rv) {
    zval fn;
    ZVAL_STRING(&fn, name);
    ZVAL_UNDEF(rv);
    zend_result r = call_user_function(EG(function_table), NULL, &fn, rv, argc, args);
    zval_ptr_dtor(&fn);
    return r == SUCCESS && EG(exception) == nullptr;
}

/* Look up a userland class by its TRUE-CASE name, allowing autoloading.
 * Userland frameworks (AMPHP, ReactPHP) lazy-load through composer's
 * autoloader, so a bare class-table lookup misses them until userland
 * first references the class - and PSR-4 autoloaders are case-sensitive,
 * so the lookup must use the declared case. Once loaded, subsequent calls
 * are plain hash hits inside zend_lookup_class. A throwing autoloader is
 * treated as "not available". */
zend_class_entry *duckdb_lookup_userland_class(const char *name) {
    zend_string *zs = zend_string_init(name, strlen(name), 0);
    zend_class_entry *ce = zend_lookup_class(zs);
    zend_string_release(zs);
    if (EG(exception)) {
        zend_clear_exception();
        return nullptr;
    }
    return ce;
}

void duckdb_discard_pending(conn_inner &conn, duckdb_pending_result &pending) {
    if (!pending) {
        return;
    }
    // Check DuckDB's active result while holding the connection mutex: an
    // interrupt is only for the statement this pending handle still owns.
    if (duckdb_pending_execute_check_state(pending) != DUCKDB_PENDING_ERROR) {
        duckdb_interrupt(conn.conn);
    }
    duckdb_result discarded = {};
    duckdb_execute_pending(pending, &discarded);
    duckdb_destroy_result(&discarded);
    duckdb_destroy_pending(&pending);
}

/* Interrupt a running task. Safe to call at any time: a finished task is
 * left untouched, and the "result already consumed" path stays the sole
 * owner of double-completion errors. */
void duckdb_task_cancel(std::shared_ptr<async_task> &task) {
    {
        std::lock_guard<std::mutex> lk(task->m);
        if (task->done) {
            return; /* already finished: never disturb the connection */
        }
    }

    if (task->mode == task_mode::POLLING) {
        {
            /* Own-result entry, like duckdb_task_step_once(). */
            std::lock_guard<std::mutex> lk(task->conn->mutex);
            if (task->record) {
                open_result &record = *task->record;
                if (record.session) {
                    duckdb_session_end(*task->conn, record.session, "Query interrupted", true);
                }
                duckdb_open_result_destroy(*task->conn, record, "Query interrupted");
                task->record.reset();
            }
            duckdb_destroy_result(&task->result);
            task->consumed = true;
        }
        {
            std::lock_guard<std::mutex> lk(task->m);
            task->error = true;
            task->error_type = DUCKDB_ERROR_INTERRUPT;
            task->error_msg = "Query interrupted";
            task->done = true;
        }
        task->cv.notify_all();
    } else {
        std::lock_guard<std::mutex> task_lock(task->m);
        if (task->done || task->cancel_requested) {
            return;
        }

        try {
            // DuckDB clears its interrupt flag at query startup. Repeat
            // interrupts until completion so cancellation cannot disappear
            // between the worker's flag check and native query startup.
            // The execution worker owns and joins this helper before its
            // shared task is released or module shutdown can continue.
            task->cancellation_worker = std::thread([raw_task = task.get()] {
                std::unique_lock<std::mutex> lock(raw_task->m);
                while (!raw_task->done) {
                    if (raw_task->worker_running) {
                        duckdb_interrupt(raw_task->conn->conn);
                    }
                    raw_task->cv.wait_for(lock, std::chrono::milliseconds(1),
                                         [raw_task] { return raw_task->done; });
                }
            });
        } catch (const std::exception &error) {
            zend_throw_exception_ex(duckdb_internal_exception_ce, DUCKDB_ERROR_INTERNAL,
                                    "Failed to start cancellation worker: %s", error.what());
            return;
        }

        task->cancel_requested = true;
        if (task->worker_running) {
            duckdb_interrupt(task->conn->conn);
        }
    }
}

/* ================================================================== */
/* DuckDB\PendingQuery                                                */
/* ================================================================== */

/* PendingQuery methods drive the connection, so they refuse to run inside a
 * COPY handler for it. duckdb_task_step() and duckdb_task_cancel() rely on
 * this check at method entry and lock the connection directly. */
static bool duckdb_pending_enter(php_duckdb_pending_object *intern) {
    if (!duckdb_initialized_guard(static_cast<bool>(intern->task), "DuckDB\\PendingQuery")) {
        return false;
    }
    if (intern->task->conn && !duckdb_conn_check_not_busy(*intern->task->conn)) {
        return false;
    }
    return true;
}

PHP_METHOD(DuckDB_PendingQuery, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    zend_throw_error(NULL, "DuckDB\\PendingQuery objects are returned by queryAsync() and friends");
}

DUCKDB_COPY_METHOD(DuckDB_PendingQuery, isReady) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_pending_object *intern = Z_DUCKDB_PENDING_P(ZEND_THIS);
    if (!duckdb_pending_enter(intern)) {
        RETURN_THROWS();
    }
    RETURN_BOOL(duckdb_task_step(intern->task));
}

DUCKDB_COPY_METHOD(DuckDB_PendingQuery, await) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_pending_object *intern = Z_DUCKDB_PENDING_P(ZEND_THIS);
    if (!duckdb_pending_enter(intern)) {
        RETURN_THROWS();
    }
    std::shared_ptr<async_task> task = intern->task;

    if (task->mode == task_mode::POLLING) {
        /* Each step executes a slice of the DuckDB task graph, on this
         * thread or the connection's helper, so this loop makes real
         * progress (not a busy-wait). */
        while (!duckdb_task_step(task)) {
        }
    } else {
        std::unique_lock<std::mutex> lk(task->m);
        task->cv.wait(lk, [&task] { return task->done; });
    }
    duckdb_pending_complete(intern, return_value);
}

DUCKDB_COPY_METHOD(DuckDB_PendingQuery, suspend) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_pending_object *intern = Z_DUCKDB_PENDING_P(ZEND_THIS);
    if (!duckdb_pending_enter(intern)) {
        RETURN_THROWS();
    }
    std::shared_ptr<async_task> task = intern->task;

    /* Runtime integrations, claimed in order: Swoole 6+, True Async,
     * AMPHP v3 (Revolt), ReactPHP (react/async v4+). Each integration
     * lives in its own source file (src/suspend_*.cpp) and returns false
     * when its runtime is absent or inactive, so the first present and
     * active runtime owns resumption. */
    if (duckdb_suspend_swoole(intern, task) ||
        duckdb_suspend_true_async(intern, task) ||
        duckdb_suspend_amphp(intern, task) ||
        duckdb_suspend_reactphp(intern, task)) {
        if (EG(exception)) {
            RETURN_THROWS();
        }
        duckdb_pending_complete(intern, return_value);
        return;
    }

    while (!duckdb_task_step(task)) {
        /* Fiber::suspend($this). Fibers are stackful, so suspending from
         * C frames inside a fiber is legal. If no fiber is active, PHP
         * throws Error("Cannot suspend outside a fiber") - which is the
         * correct failure mode; use await() in blocking code. */
        zval func, arg, rv;
        ZVAL_STRING(&func, "Fiber::suspend");
        ZVAL_COPY(&arg, ZEND_THIS);
        call_user_function(EG(function_table), NULL, &func, &rv, 1, &arg);
        zval_ptr_dtor(&func);
        zval_ptr_dtor(&arg);
        zval_ptr_dtor(&rv);
        if (EG(exception)) {
            RETURN_THROWS();
        }
    }

    duckdb_pending_complete(intern, return_value);
}

DUCKDB_COPY_METHOD(DuckDB_PendingQuery, cancel) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_pending_object *intern = Z_DUCKDB_PENDING_P(ZEND_THIS);
    if (!duckdb_pending_enter(intern)) {
        RETURN_THROWS();
    }
    duckdb_task_cancel(intern->task);
}

PHP_METHOD(DuckDB_PendingQuery, getFd) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    duckdb_notify_fd fd = Z_DUCKDB_PENDING_P(ZEND_THIS)->read_fd;
    if (!duckdb_notify_fd_valid(fd)) {
        RETURN_LONG(-1);
    }
    /* Hand out a duplicate: the PendingQuery keeps owning the original, so
     * a caller that closes the returned descriptor cannot cause a
     * double-close (or close a recycled fd) later. */
    duckdb_notify_fd duplicate = duckdb_notify_fd_duplicate(fd);
    RETURN_LONG(duckdb_notify_fd_valid(duplicate) ? static_cast<zend_long>(duplicate) : -1);
}

PHP_METHOD(DuckDB_PendingQuery, getStream) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_pending_object *intern = Z_DUCKDB_PENDING_P(ZEND_THIS);
    if (!duckdb_notify_fd_valid(intern->read_fd)) {
        duckdb_throw_msg("No completion stream is available for this pending query "
                         "(already taken, or polling mode)");
        RETURN_THROWS();
    }

    php_stream *stream = php_stream_sock_open_from_socket(intern->read_fd, NULL);
    if (stream == NULL) {
        duckdb_throw_msg("Unable to create stream from file descriptor");
        RETURN_THROWS();
    }
    intern->read_fd = DUCKDB_INVALID_NOTIFY_FD; /* ownership transferred to the stream */
    php_stream_to_zval(stream, return_value);
}
