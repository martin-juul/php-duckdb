/*
  +----------------------------------------------------------------------+
  | duckdb - native DuckDB driver for PHP                                |
  +----------------------------------------------------------------------+
  | Copyright (c) Martin Juul Christiansen (https://juul.xyz)            |
  +----------------------------------------------------------------------+
  | This source file is subject to the MIT license that is bundled with  |
  | this package in the file LICENSE.                                    |
  +----------------------------------------------------------------------+
*/

#ifndef PHP_DUCKDB_H
#define PHP_DUCKDB_H

extern zend_module_entry duckdb_module_entry;
#define phpext_duckdb_ptr &duckdb_module_entry

#define PHP_DUCKDB_VERSION "2.0.0"
#define PHP_DUCKDB_NS      "DuckDB"

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

/* RETURN_THIS() was added in PHP 8.3. */
#ifndef RETURN_THIS
#define RETURN_THIS() RETURN_OBJ_COPY(Z_OBJ_P(ZEND_THIS))
#endif

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "zend_exceptions.h"
#include "zend_enum.h"
#include "zend_interfaces.h"
#include "duckdb.h"

/* Check required APIs in the build scripts and their call sites. DuckDB 1.5.5
 * provides these APIs but predates the DUCKDB_API_VERSION_* header macros. */

#include <atomic>
#include <condition_variable>
#include <cstdint>
#include <functional>
#include <memory>
#include <mutex>
#include <string>
#include <system_error>
#include <thread>
#include <unordered_map>
#include <vector>

/* ================================================================== */
/* RAII for scoped DuckDB handles                                     */
/*                                                                    */
/* Move-only owner for the C API's opaque handle types. The DuckDB    */
/* destroy functions all take a pointer to the handle and null it     */
/* out; this wrapper adapts that convention to RAII so error paths    */
/* cannot leak.                                                       */
/* ================================================================== */

template <typename T, void (*Destroy)(T *)>
class duckdb_scoped {
public:
    duckdb_scoped() = default;
    explicit duckdb_scoped(T handle) : handle_(handle) {}
    ~duckdb_scoped() { reset(); }

    duckdb_scoped(const duckdb_scoped &) = delete;
    duckdb_scoped &operator=(const duckdb_scoped &) = delete;

    duckdb_scoped(duckdb_scoped &&other) noexcept : handle_(other.handle_) { other.handle_ = T{}; }
    duckdb_scoped &operator=(duckdb_scoped &&other) noexcept {
        if (this != &other) {
            reset();
            handle_ = other.handle_;
            other.handle_ = T{};
        }
        return *this;
    }

    /* Reset and return the storage so a DuckDB create function can
     * out-parameter into it: `duckdb_create_config(cfg.out())`. */
    T *out() {
        reset();
        return &handle_;
    }
    T get() const { return handle_; }
    T release() {
        T handle = handle_;
        handle_ = T{};
        return handle;
    }
    explicit operator bool() const { return handle_ != T{}; }

    void reset(T handle = T{}) {
        if (handle_ != T{}) {
            Destroy(&handle_);
        }
        handle_ = handle;
    }

private:
    T handle_ = T{};
};

using scoped_duckdb_config = duckdb_scoped<duckdb_config, duckdb_destroy_config>;
using scoped_duckdb_value = duckdb_scoped<duckdb_value, duckdb_destroy_value>;
using scoped_duckdb_logical_type = duckdb_scoped<duckdb_logical_type, duckdb_destroy_logical_type>;
using scoped_duckdb_chunk = duckdb_scoped<duckdb_data_chunk, duckdb_destroy_data_chunk>;
using scoped_duckdb_prepared = duckdb_scoped<duckdb_prepared_statement, duckdb_destroy_prepare>;

#ifdef PHP_WIN32
#include <winsock2.h>
using duckdb_notify_fd = SOCKET;
constexpr duckdb_notify_fd DUCKDB_INVALID_NOTIFY_FD = INVALID_SOCKET;
#else
#include <errno.h>
#include <sys/socket.h>
#include <unistd.h>
using duckdb_notify_fd = int;
constexpr duckdb_notify_fd DUCKDB_INVALID_NOTIFY_FD = -1;
#endif

inline bool duckdb_notify_fd_valid(duckdb_notify_fd fd) {
    return fd != DUCKDB_INVALID_NOTIFY_FD;
}

inline void duckdb_notify_fd_close(duckdb_notify_fd fd) {
    if (!duckdb_notify_fd_valid(fd)) {
        return;
    }
#ifdef PHP_WIN32
    closesocket(fd);
#else
    close(fd);
#endif
}

duckdb_notify_fd duckdb_notify_fd_duplicate(duckdb_notify_fd fd);

/* ================================================================== */
/* Internal handle wrappers (no PHP state)                            */
/* ================================================================== */

struct copy_session;
struct conn_helper;
struct copy_slot;
struct copy_registration;
struct scalar_slot;
struct scalar_registration;
struct scalar_registry;
struct log_storage_state;

/* A DuckDB database instance. Connections hold a shared_ptr to this,
 * so the database outlives its connections. */
struct db_inner {
    duckdb_database db = nullptr;
    /* Pumped statements by DuckDB connection id, for PHP callbacks that
     * run on DuckDB threads and only know their client context. */
    std::mutex session_mutex;
    std::unordered_map<idx_t, std::weak_ptr<copy_session>> sessions;
    /* PHP COPY formats registered in this instance, by lower-cased name. */
    std::mutex copy_mutex;
    std::unordered_map<std::string, std::shared_ptr<copy_slot>> copy_slots;
    /* PHP scalar functions registered in this instance, by lower-cased name. */
    std::mutex scalar_mutex;
    std::unordered_map<std::string, std::shared_ptr<scalar_slot>> scalar_slots;
    /* One private callback per database; capture values remain operation-scoped. */
    std::mutex typed_capture_mutex;
    std::shared_ptr<void> typed_capture_state;
    /* Native log buffers outlive PHP wrappers and remain valid through
     * duckdb_close(), which may invoke logging callbacks. */
    std::mutex logging_mutex;
    std::unordered_map<std::string, std::shared_ptr<log_storage_state>> log_storages;
    ~db_inner() {
        if (db) {
            duckdb_close(&db);
        }
    }
};

/* Lifecycle of an open-result record. Every transition happens under the
 * connection mutex. */
enum class open_result_state {
    /* Registered; the native handle is live. */
    open,
    /* A fetch failed. Still registered, and the error is kept for re-fetches. */
    errored,
    /* Finished cleanly and unregistered; a newer statement leaves it alone. */
    drained,
    /* A newer statement destroyed the native handle. */
    superseded,
    /* The PHP owner released it. */
    destroyed,
};

/* One open streaming or pending result. DuckDB 2.0 refuses a new statement
 * while one is open, so the extension supersedes it itself before starting
 * a statement. The connection's registry and the PHP owner (result_data or
 * async_task) share the record. Only the thread that moves the state under
 * the connection mutex destroys the native handle, so it is destroyed
 * exactly once. */
struct open_result {
    open_result_state state = open_result_state::open;
    /* A streaming result's native handle. */
    duckdb_result result = {};
    bool streaming = false;
    /* A pending query's native handle. */
    duckdb_pending_result pending = nullptr;
    /* The pumped statement's callback session. Set before the record is
     * registered and never replaced; ending it is idempotent. */
    std::shared_ptr<copy_session> session;
    /* A failed stream's error, kept for re-fetches after a supersede. */
    bool failed = false;
    duckdb_error_type error_type = DUCKDB_ERROR_INVALID;
    std::string error_message;
};

/* Stop and join a connection's helper thread; a no-op once stopped
 * (copy_session.cpp). */
void duckdb_conn_helper_stop(const std::shared_ptr<conn_helper> &helper);
/* MSHUTDOWN: stop and join every helper thread still parked. */
void duckdb_helpers_shutdown();

/* A DuckDB connection. DuckDB connections are not safe for concurrent
 * use from multiple threads, so every statement execution (sync or
 * async) holds `mutex` for the duration of the DuckDB call. PHP-thread
 * code acquires it through duckdb_conn_enter() or duckdb_conn_cleanup(),
 * which refuse re-entry from a PHP handler running for this connection. */
struct conn_inner {
    std::shared_ptr<db_inner> db;
    duckdb_connection conn = nullptr;
    std::mutex mutex;
    /* Set by Connection::close(); the physical disconnect stays deferred to
     * the destructor so statements/results still holding this shared_ptr
     * remain valid. */
    std::atomic<bool> closed{false};
    /* Open streaming and pending results, guarded by `mutex`. A statement
     * start supersedes every record registered here. */
    std::vector<std::shared_ptr<open_result>> open_results;
    /* Nonzero while handler_thread runs a PHP handler for this connection.
     * Re-entering the connection from that thread would self-deadlock. */
    std::atomic<int> handler_depth{0};
    std::atomic<std::thread::id> handler_thread{};
    /* Set when commit() rolled back an aborted transaction, so the
     * rollBack() of a catch block around it succeeds instead of replacing
     * the commit error. Cleared by the next statement start. */
    std::atomic<bool> commit_rolled_back{false};
    /* Native cleanup that destructors could not run while busy. */
    std::mutex deferred_mutex;
    std::vector<std::function<void()>> deferred;
    /* Live COPY format registrations; statements are pumped while nonzero. */
    std::atomic<int> copy_registrations{0};
    /* This connection's COPY formats by lower-cased name (request thread). */
    std::unordered_map<std::string, std::shared_ptr<copy_registration>> copy_regs;
    /* Live scalar functions and their request-thread PHP handlers. */
    std::atomic<int> scalar_registrations{0};
    std::unordered_map<std::string, std::shared_ptr<scalar_registration>> scalar_regs;
    std::shared_ptr<scalar_registry> scalar_state;
    /* DuckDB's id for this connection, resolved when first pumped. */
    idx_t connection_id = 0;
    bool connection_id_known = false;
    /* The thread that drives pumped statements while the request thread
     * holds `mutex` and services their callbacks. Created on the first
     * lease and parked between leases. It never locks `mutex` and holds no
     * reference to this connection, so this destructor never runs on it. */
    std::shared_ptr<conn_helper> helper;
    /* Finish hooks of pumped sessions that an async worker superseded. The
     * worker runs no PHP, so the request thread runs them at its next
     * method exit or object free (duckdb_sessions_reap()). */
    std::mutex reap_mutex;
    std::vector<std::function<void(bool can_call_php)>> reaped;
    /* Async workers started on this connection that have not finished
     * executing. DuckDB 2.0 destroys a prepared statement under the client
     * context lock, which a running query holds, so a statement released
     * meanwhile waits in worker_prepares for the last worker to destroy. */
    std::mutex worker_mutex;
    int workers = 0;
    std::vector<duckdb_prepared_statement> worker_prepares;
    ~conn_inner() {
        std::lock_guard<std::mutex> lock(mutex);
        /* Deferred work never owns the connection, so the last owner can
         * still find some pending; it must run before disconnecting. */
        for (auto &work : deferred) {
            work();
        }
        deferred.clear();
        duckdb_conn_helper_stop(helper);
        if (conn) {
            duckdb_disconnect(&conn);
        }
    }
};

/* True while the calling thread runs a PHP handler for this connection. */
bool duckdb_conn_busy_here(const conn_inner &conn);
/* Throw a ConnectionException and return false when the calling thread runs
 * a PHP handler for this connection. */
bool duckdb_conn_check_not_busy(const conn_inner &conn);

/* What a PHP-thread operation does with the connection. Destructors and
 * deferred cleanup use duckdb_conn_cleanup() instead. */
enum class conn_entry {
    /* Starts a statement: supersedes every open result first. The method
     * must run duckdb_copy_after_method(), because superseding a pumped
     * statement runs its writers' abort(). */
    statement_start,
    /* Only locks: binding, metadata, Arrow import and registration. */
    plain,
    /* Serves an open result's own fetch or Arrow call, which must not end
     * its own statement. */
    own_result,
};

/* Acquire conn->mutex into `lock` for a PHP-thread operation of the given
 * kind. Throws a ConnectionException and returns false instead of locking
 * when the calling thread runs a PHP handler for this connection. */
bool duckdb_conn_enter(conn_inner &conn, std::unique_lock<std::mutex> &lock, conn_entry kind);
/* Supersede every open result: close its session, destroy its native handle
 * and unregister it. With `can_call_php`, the sessions' finish hooks run now
 * and may call PHP; otherwise they stay for the record's owner. Caller holds
 * conn.mutex. */
void duckdb_conn_supersede(conn_inner &conn, bool can_call_php);
/* An async worker's supersede: like duckdb_conn_supersede(), but the
 * sessions' finish hooks move to the connection's reap queue for the
 * request thread. Caller holds conn.mutex on the worker thread. */
void duckdb_conn_supersede_from_worker(conn_inner &conn);
/* Count an async worker on the connection until duckdb_conn_worker_end(). */
void duckdb_conn_worker_begin(conn_inner &conn);
/* The worker finished executing: destroy the prepared statements released
 * while it ran. Caller holds conn.mutex, outside any DuckDB call. */
void duckdb_conn_worker_end(conn_inner &conn);
/* Destroy a prepared statement of this connection. While an async worker
 * may be executing, hand it to the workers; inside a handler of this
 * connection, defer it until the handler returns. Releasing a statement
 * then never waits for a running query's client-context lock. */
void duckdb_conn_destroy_prepare(const std::shared_ptr<conn_inner> &conn, duckdb_prepared_statement &statement);
/* Run native cleanup under conn->mutex, or defer it until the connection's
 * PHP handler returns when the calling thread is inside one. No PHP runs. */
void duckdb_conn_cleanup(const std::shared_ptr<conn_inner> &conn, std::function<void()> work);
/* Run cleanup deferred by duckdb_conn_cleanup(). Caller must not hold
 * conn->mutex and must not be inside a handler for this connection. */
void duckdb_conn_drain_deferred(conn_inner &conn);
/* Drain every connection whose cleanup this thread deferred and that is no
 * longer busy here. */
void duckdb_drain_deferred_connections();

/* Register a streaming result and, for a pumped stream, the session that
 * services its callbacks until it drains, taking ownership of `result`.
 * Caller holds conn.mutex. */
std::shared_ptr<open_result> duckdb_open_result_stream(conn_inner &conn, duckdb_result *result,
                                                       std::shared_ptr<copy_session> session);
/* Register an incomplete pending query and its session, if any, taking
 * ownership of `pending`. Caller holds conn.mutex. */
std::shared_ptr<open_result> duckdb_open_result_pending(conn_inner &conn, duckdb_pending_result pending,
                                                        std::shared_ptr<copy_session> session);
/* Unregister a record that finished cleanly. Caller holds conn.mutex. */
void duckdb_open_result_drained(conn_inner &conn, open_result &record);
/* Release a record its owner no longer needs: close its session and
 * destroy its native handle unless a newer statement already did. No PHP
 * runs. Caller holds conn.mutex. */
void duckdb_open_result_destroy(conn_inner &conn, open_result &record, const std::string &reason);
/* Why a superseded pending query fails. */
extern const char duckdb_superseded_message[];

/* A prepared statement, reference-counted so a Statement object may be
 * freed while an asynchronous execution of it is still running. */
struct stmt_inner {
    std::shared_ptr<conn_inner> conn;
    duckdb_prepared_statement stmt = nullptr;
    /* Bound to a PHP COPY format at prepare time. */
    bool uses_copy_format = false;
    /* Bound to a PHP scalar function at prepare time. */
    bool uses_scalar_function = false;
    ~stmt_inner() {
        if (!stmt) {
            return;
        }
        if (conn) {
            duckdb_conn_destroy_prepare(conn, stmt);
        } else {
            duckdb_destroy_prepare(&stmt);
        }
    }
};

/* An appender. Closing flushes pending rows; the destructor is the
 * safety net for appenders that were never closed explicitly. Once any
 * append operation fails, the appender is invalid (DuckDB cannot recover
 * a partially written row) and `closed` rejects further use. */
struct appender_inner {
    std::shared_ptr<conn_inner> conn;
    duckdb_appender appender = nullptr;
    bool closed = false;
    bool failed = false;
    bool row_open = false;
    ~appender_inner();
};

/* ================================================================== */
/* Async machinery                                                    */
/* ================================================================== */

enum class task_mode {
    THREAD_QUERY,    /* worker thread runs duckdb_query()            */
    THREAD_PREPARED, /* worker thread runs duckdb_execute_prepared() */
    POLLING,         /* single-threaded duckdb_pending_execute*()    */
};

/* Drain and destroy a pending result. Caller holds conn.mutex. */
void duckdb_discard_pending(conn_inner &conn, duckdb_pending_result &pending);
/* Close a pumped statement's session and stop publishing it (copy_session.h). */
void duckdb_session_end(conn_inner &conn, const std::shared_ptr<copy_session> &session,
                        const std::string &failure, bool can_call_php);

/* State of one asynchronous query. Shared between the PHP PendingQuery
 * object and, for THREAD_* modes, the registry-owned worker thread. The
 * worker NEVER touches PHP runtime state (zvals, allocators, EG) - only
 * DuckDB handles, plain data and the notification fd. */
struct async_task {
    std::shared_ptr<conn_inner> conn;
    std::shared_ptr<stmt_inner> stmt; /* THREAD_PREPARED only          */
    std::string sql;                  /* THREAD_QUERY / POLLING only   */
    task_mode mode = task_mode::THREAD_QUERY;

    std::mutex m;
    std::condition_variable cv;
    bool done = false;
    bool cancel_requested = false;
    bool worker_running = false;
    std::thread cancellation_worker;
    bool error = false;
    bool consumed = false;
    duckdb_error_type error_type = DUCKDB_ERROR_INVALID;
    std::string error_msg;
    duckdb_result result = {};
    /* POLLING, until executed: the registered pending query. It owns the
     * native pending handle and, on a pumped connection, the session that
     * services PHP callbacks while the connection's helper runs a slice. */
    std::shared_ptr<open_result> record;
    /* POLLING keeps its prepared statement alive for as long as the pending
     * result may reference it (duckdb_pending_prepared does NOT take
     * ownership). Destroyed after the pending result. */
    duckdb_prepared_statement owned_stmt = nullptr;
    /* The prepared statement's type, recorded when the task is created
     * because the statement may be gone when the result is read. */
    duckdb_statement_type statement_type = DUCKDB_STATEMENT_TYPE_INVALID;
    /* POLLING, request thread only: the first handler exception raised
     * while a step ran, kept until the task's error is thrown. The
     * PendingQuery object releases it. */
    zend_object *late_cause = nullptr;

    duckdb_notify_fd notify_write_fd = DUCKDB_INVALID_NOTIFY_FD;

    ~async_task() {
        if (cancellation_worker.joinable()) {
            cancellation_worker.join();
        }

        /* Release waiting COPY workers before DuckDB cancels the statement. */
        if (record && record->session && conn) {
            duckdb_session_end(*conn, record->session, "PendingQuery discarded", false);
        }

        /* The destroy calls below touch connection state; serialize them
         * with any in-flight execution on this connection. Destruction
         * inside a COPY handler for this connection is deferred until the
         * handler returns, because the handler's thread holds the mutex.
         * The record destroys its own native handle, unless a newer
         * statement already did. */
        if (conn && (record || !consumed)) {
            std::shared_ptr<open_result> owned_record = std::move(record);
            bool destroy_result = !consumed;
            duckdb_result owned_result = result;
            result = {};
            consumed = true;
            duckdb_prepared_statement statement = owned_stmt;
            owned_stmt = nullptr;
            conn_inner *raw = conn.get();
            duckdb_conn_cleanup(conn, [raw, owned_record, destroy_result, owned_result, statement]() mutable {
                if (owned_record) {
                    duckdb_open_result_destroy(*raw, *owned_record, "PendingQuery discarded");
                }
                if (destroy_result) {
                    duckdb_destroy_result(&owned_result);
                }
                if (statement) {
                    duckdb_destroy_prepare(&statement);
                }
            });
        }
        if (owned_stmt) {
            if (conn) {
                duckdb_conn_destroy_prepare(conn, owned_stmt);
            } else {
                duckdb_destroy_prepare(&owned_stmt);
            }
            owned_stmt = nullptr;
        }
    }
};

/* ================================================================== */
/* Materialized or streaming query result (shared by Result and       */
/* ResultIterator)                                                    */
/* ================================================================== */

struct result_data {
    /* A materialized result. A streaming result's handle lives on `record`. */
    duckdb_result result = {};
    /* The registered streaming result; nullptr for materialized results. */
    std::shared_ptr<open_result> record;
    /* Arrow export consults the source ClientContext even for materialized
     * results. Logical close remains immediate; defer physical disconnect. */
    std::shared_ptr<conn_inner> conn_keepalive;
    bool streaming = false;
    idx_t column_count = 0;
    /* Metadata copied at creation, so it stays readable after a newer
     * statement destroyed a streaming result's native handle. */
    std::vector<std::string> column_names;
    duckdb_statement_type statement_type = DUCKDB_STATEMENT_TYPE_INVALID;
    idx_t row_count = 0;
    idx_t rows_changed = 0;
    duckdb_arrow_options arrow_options = nullptr;
    /* Owned logical types, one per column (nullptr for statement types
     * without a result set). Destroyed with the result. */
    duckdb_logical_type *column_types = nullptr;
    /* Keeps the prepared statement alive for streaming results. */
    std::shared_ptr<stmt_inner> stmt_keepalive;

    /* Chunk iteration state (duckdb_fetch_chunk works on both
     * materialized and streaming results). */
    duckdb_data_chunk chunk = nullptr;
    idx_t chunk_size = 0;
    idx_t chunk_pos = 0;
    uint64_t row_index = 0; /* absolute row, for the varchar fallback */
    bool exhausted = false;
    /* Results are forward-only: a Result may hand out exactly one iterator,
     * and iteration may start only before any row was consumed. */
    bool iterator_taken = false;
    /* A pumped stream's handler exception, kept from the call that ran the
     * failing callback until DuckDB reports the failure on a later fetch
     * (request thread only). */
    zend_object *late_cause = nullptr;

    ~result_data() {
        if (late_cause) {
            OBJ_RELEASE(late_cause);
        }
        if (chunk) {
            duckdb_destroy_data_chunk(&chunk);
        }
        if (column_types) {
            for (idx_t i = 0; i < column_count; i++) {
                if (column_types[i]) {
                    duckdb_destroy_logical_type(&column_types[i]);
                }
            }
            delete[] column_types;
        }
        if (arrow_options) {
            duckdb_destroy_arrow_options(&arrow_options);
        }
        /* Destroying a STREAMING result closes the stream on the
         * connection, so serialize with other executions. The record
         * destroys its handle unless a newer statement already did.
         * Materialized results are self-contained and need no lock. */
        if (record && conn_keepalive) {
            /* Release callbacks waiting on a pumped stream before DuckDB
             * cancels it. */
            if (record->session) {
                duckdb_session_end(*conn_keepalive, record->session, "Result discarded", false);
            }

            /* The deferred work keeps the statement until the stream closes. */
            std::shared_ptr<open_result> owned_record = std::move(record);
            std::shared_ptr<stmt_inner> statement = stmt_keepalive;
            conn_inner *raw = conn_keepalive.get();
            duckdb_conn_cleanup(conn_keepalive, [raw, owned_record, statement]() mutable {
                duckdb_open_result_destroy(*raw, *owned_record, std::string());
            });
        }
        duckdb_destroy_result(&result);
        stmt_keepalive.reset();
    }
};

/* ================================================================== */
/* PHP object structs                                                 */
/* ================================================================== */

typedef struct _php_duckdb_database_object {
    std::shared_ptr<db_inner> inner;
    zend_object std;
} php_duckdb_database_object;

typedef struct _php_duckdb_connection_object {
    std::shared_ptr<conn_inner> inner;
    zend_object std;
} php_duckdb_connection_object;

typedef struct _php_duckdb_statement_object {
    std::shared_ptr<stmt_inner> inner;
    HashTable *deferred_bindings;
    zend_object std;
} php_duckdb_statement_object;

typedef struct _php_duckdb_result_object {
    std::shared_ptr<result_data> data;
    zend_object std;
} php_duckdb_result_object;

typedef struct _php_duckdb_result_iterator_object {
    std::shared_ptr<result_data> data;
    zval current;   /* current row zval, IS_UNDEF when invalid */
    zend_long key;
    bool started;
    zend_object std;
} php_duckdb_result_iterator_object;

typedef struct _php_duckdb_pending_object {
    std::shared_ptr<async_task> task;
    duckdb_notify_fd read_fd = DUCKDB_INVALID_NOTIFY_FD; /* transferred to a php_stream by getStream() */
    zend_object std;
} php_duckdb_pending_object;

typedef struct _php_duckdb_appender_object {
    std::shared_ptr<appender_inner> inner;
    zend_object std;
} php_duckdb_appender_object;

typedef struct _php_duckdb_interval_object {
    duckdb_interval interval;
    zend_object std;
} php_duckdb_interval_object;

/* ================================================================== */
/* Class entries (defined in duckdb.cpp)                              */
/* ================================================================== */

extern zend_class_entry *duckdb_database_ce;
extern zend_class_entry *duckdb_connection_ce;
extern zend_class_entry *duckdb_statement_ce;
extern zend_class_entry *duckdb_result_ce;
extern zend_class_entry *duckdb_result_iterator_ce;
extern zend_class_entry *duckdb_pending_ce;
extern zend_class_entry *duckdb_appender_ce;
extern zend_class_entry *duckdb_interval_ce;
extern zend_class_entry *duckdb_fetch_mode_ce;
extern zend_class_entry *duckdb_error_type_ce;

extern zend_class_entry *duckdb_exception_ce;
extern zend_class_entry *duckdb_connection_exception_ce;
extern zend_class_entry *duckdb_parser_exception_ce;
extern zend_class_entry *duckdb_binder_exception_ce;
extern zend_class_entry *duckdb_catalog_exception_ce;
extern zend_class_entry *duckdb_constraint_exception_ce;
extern zend_class_entry *duckdb_transaction_exception_ce;
extern zend_class_entry *duckdb_conversion_exception_ce;
extern zend_class_entry *duckdb_io_exception_ce;
extern zend_class_entry *duckdb_interrupted_exception_ce;
extern zend_class_entry *duckdb_internal_exception_ce;

/* ================================================================== */
/* Object access helpers                                              */
/* ================================================================== */

static inline php_duckdb_database_object *duckdb_database_from_obj(zend_object *obj) {
    return (php_duckdb_database_object *)((char *)(obj) - offsetof(php_duckdb_database_object, std));
}
static inline php_duckdb_connection_object *duckdb_connection_from_obj(zend_object *obj) {
    return (php_duckdb_connection_object *)((char *)(obj) - offsetof(php_duckdb_connection_object, std));
}
static inline php_duckdb_statement_object *duckdb_statement_from_obj(zend_object *obj) {
    return (php_duckdb_statement_object *)((char *)(obj) - offsetof(php_duckdb_statement_object, std));
}
static inline php_duckdb_result_object *duckdb_result_from_obj(zend_object *obj) {
    return (php_duckdb_result_object *)((char *)(obj) - offsetof(php_duckdb_result_object, std));
}
static inline php_duckdb_result_iterator_object *duckdb_result_iterator_from_obj(zend_object *obj) {
    return (php_duckdb_result_iterator_object *)((char *)(obj) - offsetof(php_duckdb_result_iterator_object, std));
}
static inline php_duckdb_pending_object *duckdb_pending_from_obj(zend_object *obj) {
    return (php_duckdb_pending_object *)((char *)(obj) - offsetof(php_duckdb_pending_object, std));
}
static inline php_duckdb_appender_object *duckdb_appender_from_obj(zend_object *obj) {
    return (php_duckdb_appender_object *)((char *)(obj) - offsetof(php_duckdb_appender_object, std));
}
static inline php_duckdb_interval_object *duckdb_interval_from_obj(zend_object *obj) {
    return (php_duckdb_interval_object *)((char *)(obj) - offsetof(php_duckdb_interval_object, std));
}

#define Z_DUCKDB_DATABASE_P(zv)  duckdb_database_from_obj(Z_OBJ_P(zv))
#define Z_DUCKDB_CONNECTION_P(zv) duckdb_connection_from_obj(Z_OBJ_P(zv))
#define Z_DUCKDB_STATEMENT_P(zv) duckdb_statement_from_obj(Z_OBJ_P(zv))
#define Z_DUCKDB_RESULT_P(zv)    duckdb_result_from_obj(Z_OBJ_P(zv))
#define Z_DUCKDB_RESULT_ITERATOR_P(zv) duckdb_result_iterator_from_obj(Z_OBJ_P(zv))
#define Z_DUCKDB_PENDING_P(zv)   duckdb_pending_from_obj(Z_OBJ_P(zv))
#define Z_DUCKDB_APPENDER_P(zv)  duckdb_appender_from_obj(Z_OBJ_P(zv))
#define Z_DUCKDB_INTERVAL_P(zv)  duckdb_interval_from_obj(Z_OBJ_P(zv))

/* ================================================================== */
/* Cross-module helpers                                               */
/* ================================================================== */

/* Reject strings with embedded NUL bytes: the DuckDB C API takes
 * NUL-terminated strings, so an embedded NUL would silently truncate the
 * SQL text (or identifier) — a correctness and security hazard. Throws
 * \ValueError and returns false on violation. */
static inline bool duckdb_check_no_nul(const char *str, size_t len, uint32_t arg_num) {
    if (UNEXPECTED(memchr(str, '\0', len) != nullptr)) {
        zend_argument_value_error(arg_num, "must not contain NUL bytes");
        return false;
    }
    return true;
}

/* Guard against objects whose constructor was bypassed (e.g. via
 * ReflectionClass::newInstanceWithoutConstructor()): their shared_ptr
 * members are null, and dereferencing them would crash. Throws \Error and
 * returns false. */
static inline bool duckdb_initialized_guard(bool initialized, const char *class_name) {
    if (UNEXPECTED(!initialized)) {
        zend_throw_error(NULL, "%s object is not initialized (its constructor was bypassed)",
                         class_name);
        return false;
    }
    return true;
}

/* values.cpp */
const char *duckdb_type_name(duckdb_type type);
std::string duckdb_logical_type_render(duckdb_logical_type type);
const char *duckdb_statement_type_name(duckdb_statement_type type);

/* Maximum nesting depth for PHP<->DuckDB value conversion. The converters
 * recurse on the C stack; unbounded recursion on pathological input
 * (a 10000-deep nested array) overflows the stack and crashes the whole
 * process. 512 matches PHP's own JSON depth default. */
constexpr uint32_t DUCKDB_MAX_NESTING_DEPTH = 512;
/* Throw the DuckDB\Exception subclass matching `type` (code = type). */
void duckdb_throw_error(duckdb_error_type type, const char *msg);
/* Throw the base DuckDB\Exception (code 0). */
void duckdb_throw_msg(const char *msg);
/* Throw ConnectionException when the connection was closed via
 * Connection::close(). Returns false after throwing (never returns with an
 * exception pending), true when the connection is usable. */
bool duckdb_connection_guard(const std::shared_ptr<conn_inner> &conn);
/* Throw for a prepare-time error; the DuckDB prepare API exposes only a
 * message, so the category is derived from its "<Type> Error:" prefix. */
void duckdb_throw_prepare_error(const char *msg);
/* Recover the error category from a DuckDB error message's
 * "<Type> Error:" prefix; DUCKDB_ERROR_INVALID when unrecognized.
 * Used by the open and prepare paths, which expose only a message. */
duckdb_error_type duckdb_classify_error_message(const char *msg);
/* Throw from a failed duckdb_result (message + error type), then
 * destroy the result. Does not return. */
void duckdb_throw_result_error(duckdb_result *res);
/* Convert a PHP value to a DuckDB value (for binding/appending).
 * Returns nullptr and throws on unsupported values. Caller must destroy
 * the returned value with duckdb_destroy_value(). */
duckdb_value duckdb_php_to_duckdb_value(zval *value);
/* Create a DuckDB\Interval object from a duckdb_interval. */
void duckdb_interval_instantiate(zval *return_value, duckdb_interval interval);

/* statement.cpp */
/* Resolve a positional (1-based int) or named (string, with or without
 * $/: prefix) parameter reference to a 1-based index. Returns false and
 * throws on failure. */
bool duckdb_resolve_param_index(duckdb_prepared_statement stmt, zval *param, idx_t *index);
/* Bind an array of parameters (list = positional, assoc = named).
 * Returns false and throws on failure. */
bool duckdb_bind_params_array(duckdb_prepared_statement stmt, HashTable *params, conn_inner *conn);
/* Conversion statements prepared on one connection and reused by later
 * conversions with the same SQL text, which skips parsing and planning.
 * Each use still rebinds with the new values. Destroy the cache before its
 * connection. */
struct conversion_statements {
    static constexpr size_t capacity = 4;
    /* Least recently used first. */
    std::vector<std::pair<std::string, scoped_duckdb_prepared>> entries;
};
/* Conversion runs on the PHP thread with conn->mutex held. `statements`,
 * when given, must belong to `conn`. */
bool duckdb_convert_values(conn_inner *conn, const std::vector<zval *> &inputs,
                          std::vector<scoped_duckdb_value> &outputs, conversion_statements *statements = nullptr);
bool duckdb_value_contains_typed(zval *value);
void duckdb_register_value_class();
zend_class_entry *duckdb_value_class_entry();
bool duckdb_value_initialize(zval *object, const std::string &type, zval *input);
bool duckdb_canonicalize_type(const std::string &declaration, std::string &canonical);
bool duckdb_initialize_typed_registry(db_inner *database);
/* SQL declaration that recreates a resolved type on any connection. */
std::string duckdb_logical_type_sql(duckdb_logical_type type);

/* type_classes.cpp */
/* Resolve a SQL string, scalar value class name or Value to a declaration.
 * Returns false and throws on failure. */
bool duckdb_type_spec(zval *spec, std::string &type);

/* result.cpp */
/* Instantiate a DuckDB\Result object wrapping `res`. Takes ownership of
 * `res`. `keepalive` keeps a prepared statement alive for streaming
 * results (pass nullptr otherwise). A streaming result is registered as the
 * connection's open result, so the caller holds conn.mutex for it. */
/* `statement_type` overrides the result's own type: DuckDB 2.0 reports
 * EXECUTE for every result of a C API prepared statement, so callers that
 * executed one pass duckdb_prepared_statement_type() instead.
 * `session` is a pumped stream's session from duckdb_pump_execute(), which
 * the stream's record owns from now on. */
void duckdb_result_instantiate(zval *return_value, duckdb_result *res, bool streaming,
                               std::shared_ptr<stmt_inner> keepalive, std::shared_ptr<conn_inner> connection,
                               duckdb_statement_type statement_type = DUCKDB_STATEMENT_TYPE_INVALID,
                               std::shared_ptr<copy_session> session = nullptr);
/* Keep the handler exception that a pumped stream's callbacks raised while
 * the call that started it still succeeded: DuckDB reports the failure on a
 * later fetch, which chains it then. */
void duckdb_result_keep_late_cause(zval *result);
/* Consume a buffered result and return its rows with associative keys.
 * Takes ownership of `res`; returns false with an exception pending on error. */
bool duckdb_result_fetch_all_assoc(duckdb_result *res, std::shared_ptr<conn_inner> connection,
                                   zval *return_value);
/* Decode one row of a flat vector with the result type mappings. Returns
 * false and throws on failure. */
bool duckdb_decode_vector_value(duckdb_vector vector, duckdb_logical_type type, idx_t row, zval *out);

/* pending.cpp */
/* Start and register a joinable THREAD_* worker. PendingQuery destruction
 * remains nonblocking: the registry retains the thread handle, reaps
 * completed workers on subsequent starts, and joins every remaining worker
 * at MSHUTDOWN before thread-local cleanup can race module unloading. */
void duckdb_async_worker_start(const std::shared_ptr<async_task> &task);
void duckdb_async_shutdown();
/* Advance a task one step; returns true when finished. For POLLING mode
 * this executes a slice of the DuckDB task graph on the calling thread. */
bool duckdb_task_step(std::shared_ptr<async_task> task);
/* Shared completion logic for PendingQuery::await()/suspend(). Throws
 * on failure or double consumption; otherwise returns the Result. */
void duckdb_pending_complete(php_duckdb_pending_object *intern, zval *return_value);
/* Release a polling task's kept handler exception. Request thread only. */
void duckdb_pending_release_late_cause(async_task &task);
/* Create the completion-notification fd pair. Returns false and throws
 * on failure. */
bool duckdb_create_notify_pipe(duckdb_notify_fd fds[2]);

#endif /* PHP_DUCKDB_H */
