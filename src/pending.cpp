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

#include "php.h"
#include "php_streams.h"
#include "main/php_network.h"
#include "php_duckdb.h"
#include "suspend_internal.h"

#include <thread>

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

bool duckdb_create_notify_pipe(int fds[2]) {
#ifdef PHP_WIN32
    if (_pipe(fds, 64, O_BINARY) != 0) {
        zend_throw_exception_ex(duckdb_exception_ce, 0, "pipe() failed (%d)", errno);
        return false;
    }
#else
    if (socketpair(AF_UNIX, SOCK_STREAM, 0, fds) != 0) {
        zend_throw_exception_ex(duckdb_exception_ce, 0, "socketpair() failed (%d)", errno);
        return false;
    }
#endif
    return true;
}

void duckdb_async_run(std::shared_ptr<async_task> task) {
    {
        std::lock_guard<std::mutex> lk(task->conn->mutex);
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

    {
        std::lock_guard<std::mutex> lk(task->m);
        task->done = true;
    }

    if (task->notify_write_fd >= 0) {
        char b = 1;
#ifdef PHP_WIN32
        (void)!write(task->notify_write_fd, &b, 1);
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
        close(task->notify_write_fd);
        task->notify_write_fd = -1;
    }

    task->cv.notify_all();
}

/* Advance a task by one step and report completion.
 *
 * THREAD_* modes: the worker does the real work; this only observes.
 * POLLING mode: executes one slice of the DuckDB task graph ON THE
 * CALLING THREAD (holding the connection mutex for the duration of that
 * slice) via duckdb_pending_execute_task, so calling this in a loop makes
 * real progress without worker threads and without busy-waiting. Must
 * only be called from the owning request thread. */
bool duckdb_task_step(std::shared_ptr<async_task> task) {
    if (task->mode != task_mode::POLLING) {
        std::lock_guard<std::mutex> lk(task->m);
        return task->done;
    }
    if (task->pending == nullptr) {
        /* finished during queryPending() startup, or cancelled */
        std::lock_guard<std::mutex> lk(task->m);
        return task->done;
    }

    duckdb_pending_state st;
    {
        std::lock_guard<std::mutex> lk(task->conn->mutex);
        st = duckdb_pending_execute_task(task->pending);
        if (st == DUCKDB_PENDING_RESULT_READY || st == DUCKDB_PENDING_ERROR) {
            /* For both states, duckdb_execute_pending produces the final
             * materialized result - on failure it also carries the precise
             * error message and error type. */
            if (duckdb_execute_pending(task->pending, &task->result) == DuckDBError) {
                task->error = true;
                task->error_type = duckdb_result_error_type(&task->result);
                const char *e = duckdb_result_error(&task->result);
                task->error_msg = (e && e[0]) ? e : "Query execution failed";
            }
            /* duckdb_execute_pending does NOT consume the pending handle;
             * it stays owned by us and must be destroyed explicitly. */
            duckdb_destroy_pending(&task->pending);
        }
        /* DUCKDB_PENDING_RESULT_NOT_READY: call again to execute the next
         * task. DUCKDB_PENDING_NO_TASKS_AVAILABLE: all tasks are currently
         * claimed by DuckDB's own worker threads; call again shortly. */
    }

    if (st == DUCKDB_PENDING_RESULT_READY || st == DUCKDB_PENDING_ERROR) {
        std::lock_guard<std::mutex> lk(task->m);
        task->done = true;
        task->cv.notify_all();
        return true;
    }
    return false;
}

void duckdb_pending_complete(php_duckdb_pending_object *intern, zval *return_value) {
    std::shared_ptr<async_task> task = intern->task;

    if (task->error) {
        duckdb_throw_error(task->error_type, task->error_msg.c_str());
        RETURN_THROWS();
    }
    if (task->consumed) {
        duckdb_throw_msg("Result has already been consumed");
        RETURN_THROWS();
    }
    task->consumed = true;

    duckdb_result_instantiate(return_value, &task->result, /*streaming=*/false, nullptr);
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
            std::lock_guard<std::mutex> lk(task->conn->mutex);
            if (task->pending) {
                duckdb_destroy_pending(&task->pending);
                task->pending = nullptr;
            }
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
        /* Interrupt the connection; the worker observes the interrupt and
         * finishes with a DUCKDB_ERROR_INTERRUPT error. DuckDB interrupts
         * are connection-level, so every query currently running on this
         * connection is cancelled. */
        duckdb_interrupt(task->conn->conn);
    }
}

/* ================================================================== */
/* DuckDB\PendingQuery                                                */
/* ================================================================== */

PHP_METHOD(DuckDB_PendingQuery, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    zend_throw_error(NULL, "DuckDB\\PendingQuery objects are returned by queryAsync() and friends");
}

PHP_METHOD(DuckDB_PendingQuery, isReady) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_pending_object *intern = Z_DUCKDB_PENDING_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->task), "DuckDB\\PendingQuery")) {
        RETURN_THROWS();
    }
    RETURN_BOOL(duckdb_task_step(intern->task));
}

PHP_METHOD(DuckDB_PendingQuery, await) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_pending_object *intern = Z_DUCKDB_PENDING_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->task), "DuckDB\\PendingQuery")) {
        RETURN_THROWS();
    }
    std::shared_ptr<async_task> task = intern->task;

    if (task->mode == task_mode::POLLING) {
        /* Each step executes a slice of the DuckDB task graph on this
         * thread, so this loop makes real progress (not a busy-wait). */
        while (!duckdb_task_step(task)) {
        }
    } else {
        std::unique_lock<std::mutex> lk(task->m);
        task->cv.wait(lk, [&task] { return task->done; });
    }
    duckdb_pending_complete(intern, return_value);
}

PHP_METHOD(DuckDB_PendingQuery, suspend) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_pending_object *intern = Z_DUCKDB_PENDING_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->task), "DuckDB\\PendingQuery")) {
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

PHP_METHOD(DuckDB_PendingQuery, cancel) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_pending_object *intern = Z_DUCKDB_PENDING_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->task), "DuckDB\\PendingQuery")) {
        RETURN_THROWS();
    }
    duckdb_task_cancel(intern->task);
}

PHP_METHOD(DuckDB_PendingQuery, getFd) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    int fd = Z_DUCKDB_PENDING_P(ZEND_THIS)->read_fd;
    if (fd < 0) {
        RETURN_LONG(-1);
    }
    /* Hand out a duplicate: the PendingQuery keeps owning the original, so
     * a caller that closes the returned descriptor cannot cause a
     * double-close (or close a recycled fd) later. */
#ifdef PHP_WIN32
    RETURN_LONG(_dup(fd));
#else
    RETURN_LONG(dup(fd));
#endif
}

PHP_METHOD(DuckDB_PendingQuery, getStream) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_pending_object *intern = Z_DUCKDB_PENDING_P(ZEND_THIS);
    if (intern->read_fd < 0) {
        duckdb_throw_msg("No completion stream is available for this pending query "
                         "(already taken, or polling mode)");
        RETURN_THROWS();
    }

    php_stream *stream = php_stream_sock_open_from_socket(intern->read_fd, NULL);
    if (stream == NULL) {
        duckdb_throw_msg("Unable to create stream from file descriptor");
        RETURN_THROWS();
    }
    intern->read_fd = -1; /* ownership transferred to the stream */
    php_stream_to_zval(stream, return_value);
}
