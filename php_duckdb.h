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

#define PHP_DUCKDB_VERSION "1.0.0"
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

#include <atomic>
#include <condition_variable>
#include <cstdint>
#include <memory>
#include <mutex>
#include <string>
#include <thread>
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
#include <io.h>
#include <fcntl.h>
#define close(fd) _close(fd)
#define write(fd, buf, n) _write(fd, buf, n)
#else
#include <errno.h>
#include <sys/socket.h>
#include <unistd.h>
#endif

/* ================================================================== */
/* Internal handle wrappers (no PHP state)                            */
/* ================================================================== */

/* A DuckDB database instance. Connections hold a shared_ptr to this,
 * so the database outlives its connections. */
struct db_inner {
    duckdb_database db = nullptr;
    ~db_inner() {
        if (db) {
            duckdb_close(&db);
        }
    }
};

/* A DuckDB connection. DuckDB connections are not safe for concurrent
 * use from multiple threads, so every statement execution (sync or
 * async) holds `mutex` for the duration of the DuckDB call. */
struct conn_inner {
    std::shared_ptr<db_inner> db;
    duckdb_connection conn = nullptr;
    std::mutex mutex;
    /* Set by Connection::close(); the physical disconnect stays deferred to
     * the destructor so statements/results still holding this shared_ptr
     * remain valid. */
    std::atomic<bool> closed{false};
    /* Monotonic counter bumped every time a statement starts executing on
     * this connection. DuckDB allows only ONE open streaming result per
     * connection: a newer execution invalidates the previous stream. The
     * epoch lets streaming results detect that and fail loudly instead of
     * silently truncating. */
    std::atomic<uint64_t> execution_epoch{0};
    ~conn_inner() {
        std::lock_guard<std::mutex> lock(mutex);
        if (conn) {
            duckdb_disconnect(&conn);
        }
    }
};

/* A prepared statement, reference-counted so a Statement object may be
 * freed while an asynchronous execution of it is still running. */
struct stmt_inner {
    std::shared_ptr<conn_inner> conn;
    duckdb_prepared_statement stmt = nullptr;
    ~stmt_inner() {
        if (stmt) {
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

/* State of one asynchronous query. Shared between the PHP PendingQuery
 * object and, for THREAD_* modes, the detached worker thread. The worker
 * thread NEVER touches PHP runtime state (zvals, allocators, EG) - only
 * DuckDB handles, plain data and the notification fd. */
struct async_task {
    std::shared_ptr<conn_inner> conn;
    std::shared_ptr<stmt_inner> stmt; /* THREAD_PREPARED only          */
    std::string sql;                  /* THREAD_QUERY / POLLING only   */
    task_mode mode = task_mode::THREAD_QUERY;

    std::mutex m;
    std::condition_variable cv;
    bool done = false;
    bool error = false;
    bool consumed = false;
    duckdb_error_type error_type = DUCKDB_ERROR_INVALID;
    std::string error_msg;
    duckdb_result result = {};
    duckdb_pending_result pending = nullptr;   /* POLLING, until executed */
    /* POLLING keeps its prepared statement alive for as long as the pending
     * result may reference it (duckdb_pending_prepared does NOT take
     * ownership). Destroyed after the pending result. */
    duckdb_prepared_statement owned_stmt = nullptr;

    int notify_write_fd = -1;

    ~async_task() {
        /* The destroy calls below touch connection state; serialize them
         * with any in-flight execution on this connection. The connection
         * outlives the task via conn, and no code path destroys a task
         * while holding the connection mutex, so this cannot deadlock. */
        if (conn && (pending || !consumed)) {
            std::lock_guard<std::mutex> lk(conn->mutex);
            if (pending) {
                duckdb_destroy_pending(&pending);
                pending = nullptr;
            }
            if (!consumed) {
                duckdb_destroy_result(&result);
            }
        }
        if (owned_stmt) {
            duckdb_destroy_prepare(&owned_stmt);
            owned_stmt = nullptr;
        }
    }
};

/* ================================================================== */
/* Materialized or streaming query result (shared by Result and       */
/* ResultIterator)                                                    */
/* ================================================================== */

struct result_data {
    duckdb_result result = {};
    bool streaming = false;
    idx_t column_count = 0;
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
    /* Connection execution epoch at creation (streaming results only). */
    uint64_t epoch = 0;

    ~result_data() {
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
        /* Destroying a STREAMING result closes the stream on the
         * connection, so serialize with other executions. Materialized
         * results are self-contained and need no lock. */
        if (streaming && stmt_keepalive) {
            std::lock_guard<std::mutex> lk(stmt_keepalive->conn->mutex);
            duckdb_destroy_result(&result);
        } else {
            duckdb_destroy_result(&result);
        }
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
    int read_fd = -1; /* transferred to a php_stream by getStream() */
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
bool duckdb_bind_params_array(duckdb_prepared_statement stmt, HashTable *params);

/* result.cpp */
/* Instantiate a DuckDB\Result object wrapping `res`. Takes ownership of
 * `res`. `keepalive` keeps a prepared statement alive for streaming
 * results (pass nullptr otherwise). */
void duckdb_result_instantiate(zval *return_value, duckdb_result *res, bool streaming, std::shared_ptr<stmt_inner> keepalive);

/* pending.cpp */
/* Worker thread entry point for THREAD_* modes. */
void duckdb_async_run(std::shared_ptr<async_task> task);
/* Advance a task one step; returns true when finished. For POLLING mode
 * this executes a slice of the DuckDB task graph on the calling thread. */
bool duckdb_task_step(std::shared_ptr<async_task> task);
/* Shared completion logic for PendingQuery::await()/suspend(). Throws
 * on failure or double consumption; otherwise returns the Result. */
void duckdb_pending_complete(php_duckdb_pending_object *intern, zval *return_value);
/* Create the completion-notification fd pair. Returns false and throws
 * on failure. */
bool duckdb_create_notify_pipe(int fds[2]);

#endif /* PHP_DUCKDB_H */
