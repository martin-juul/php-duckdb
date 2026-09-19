/*
  +----------------------------------------------------------------------+
  | duckdb - native DuckDB driver for PHP                                |
  +----------------------------------------------------------------------+
  | Copyright (c) Martin Juul Christiansen (https://juul.xyz)            |
  +----------------------------------------------------------------------+
  | This source file is subject to the MIT license that is bundled with  |
  | this package in the file LICENSE.                                    |
  +----------------------------------------------------------------------+
  | True Async (true-async/php-src) integration for                      |
  | DuckDB\PendingQuery::suspend().                                      |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"
#include "php_streams.h"
#include "main/php_network.h"
#include "php_duckdb.h"
#include "suspend_internal.h"

/* ================================================================== */
/* True Async integration (https://true-async.github.io/)              */
/*                                                                     */
/* Like Swoole, True Async is an optional, runtime-only dependency:    */
/* nothing is linked against it and no headers are needed - every      */
/* interaction goes through its PHP API looked up by name. When        */
/* suspend() is called inside an Async\ coroutine, the coroutine parks */
/* in the libuv reactor (stream_select on the completion descriptor,   */
/* or Async\delay for polling mode) instead of blocking the scheduler. */
/* Cooperative cancellation arrives as a \Cancellation thrown out of   */
/* the suspending call and is honoured by cancelling the query.        */
/* ================================================================== */

/* Is the True Async runtime (ext true_async, Async\ namespace) loaded? */
static bool duckdb_true_async_supported(void) {
    static const char coroutine_class[] = "async\\coroutine";
    if (!zend_hash_str_find_ptr(CG(class_table), coroutine_class, sizeof(coroutine_class) - 1)) {
        return false;
    }
    /* The userland entry points suspend() relies on must exist. */
    static const char delay_fn[] = "async\\delay";
    static const char current_fn[] = "async\\current_coroutine";
    if (!zend_hash_str_find_ptr(CG(function_table), delay_fn, sizeof(delay_fn) - 1) ||
        !zend_hash_str_find_ptr(CG(function_table), current_fn, sizeof(current_fn) - 1)) {
        return false;
    }
    return true;
}

/* true when executing inside a True Async coroutine.
 * Async\current_coroutine() throws Async\AsyncException when no
 * coroutine is active - that expected failure simply means "no". */
static bool duckdb_true_async_in_coroutine(void) {
    zval rv;
    if (!duckdb_call_php("Async\\current_coroutine", 0, nullptr, &rv)) {
        if (EG(exception)) {
            zend_clear_exception();
        }
        return false;
    }
    zval_ptr_dtor(&rv);
    return true;
}

/* Sleep the coroutine for `ms` milliseconds via Async\delay().
 * Returns false when the call threw - e.g. a \Cancellation delivered
 * into the suspended coroutine. */
static bool duckdb_true_async_delay(zend_long ms) {
    zval arg, rv;
    ZVAL_LONG(&arg, ms);
    bool ok = duckdb_call_php("Async\\delay", 1, &arg, &rv);
    zval_ptr_dtor(&rv); /* IS_UNDEF-safe */
    return ok;
}

/* Park the coroutine until `stream` (a dup of the completion pipe) is
 * readable, with a 250ms watchdog that re-checks the done flag. Under
 * True Async stream_select() suspends the coroutine inside the libuv
 * reactor instead of blocking the thread. Returns false when the call
 * threw. */
static bool duckdb_true_async_wait_stream(zval *stream_zval) {
    zval args[5], rv, read_arr, write_arr, except_arr;

    array_init(&read_arr);
    /* COPY (addref): stream_select() empties the read array in place when
     * it reports readiness, and True Async's async path cleans it too -
     * the caller's reference must keep the stream alive through that. */
    zval item;
    ZVAL_COPY(&item, stream_zval);
    zend_hash_next_index_insert_new(Z_ARRVAL(read_arr), &item);
    array_init(&write_arr);
    array_init(&except_arr);

    /* stream_select(&$read, &$write, &$except, $seconds, $microseconds) */
    ZVAL_NEW_REF(&args[0], &read_arr);
    ZVAL_NEW_REF(&args[1], &write_arr);
    ZVAL_NEW_REF(&args[2], &except_arr);
    ZVAL_LONG(&args[3], 0);
    ZVAL_LONG(&args[4], 250000);

    bool ok = duckdb_call_php("stream_select", 5, args, &rv);
    for (int i = 0; i < 5; i++) {
        zval_ptr_dtor(&args[i]);
    }
    zval_ptr_dtor(&rv); /* IS_UNDEF-safe */
    return ok;
}

/* Suspend the current True Async coroutine until the query completes.
 * Worker modes park on the completion pipe via stream_select(); polling
 * mode alternates duckdb_task_step() slices with Async\delay() yields.
 * Either way the scheduler thread stays free to run other coroutines. */
static void duckdb_pending_suspend_true_async(php_duckdb_pending_object *intern, std::shared_ptr<async_task> &task) {
    zval stream_zval;
    ZVAL_UNDEF(&stream_zval);

#ifndef PHP_WIN32
    if (intern->read_fd >= 0) {
        /* dup() so the stream cannot steal the pipe from the PendingQuery.
         * On dup failure (fd exhaustion) degrade to the polling-style
         * delay loop below. */
        int fd = dup(intern->read_fd);
        if (fd >= 0) {
            php_stream *stream = php_stream_sock_open_from_socket(fd, NULL);
            if (stream != NULL) {
                /* Ownership transfers to the stream's resource: after
                 * php_stream_to_zval() a stream must be released via the
                 * zval, never via php_stream_close(). */
                php_stream_to_zval(stream, &stream_zval);
            }
        }
    }
#endif

    while (!duckdb_task_step(task)) {
        bool ok = !Z_ISUNDEF(stream_zval) ? duckdb_true_async_wait_stream(&stream_zval)
                                          : duckdb_true_async_delay(1);
        if (!ok) {
            /* \Cancellation (or another throwable) escaped the wait:
             * honour it by cancelling the underlying DuckDB query,
             * then let the throwable propagate. */
            duckdb_task_cancel(task);
            break;
        }
    }

    zval_ptr_dtor(&stream_zval); /* IS_UNDEF-safe; frees the stream + dup'd fd */
}

/* Dispatcher entry point: claim the suspension when running inside an
 * Async\ coroutine. Returns false when the True Async runtime is absent
 * or the call is not in a coroutine, so the dispatcher tries the next
 * runtime. */
bool duckdb_suspend_true_async(php_duckdb_pending_object *intern, std::shared_ptr<async_task> &task) {
    if (!duckdb_true_async_supported() || !duckdb_true_async_in_coroutine()) {
        return false;
    }
    /* True Async coroutine: park in the libuv reactor instead of the
     * generic fiber protocol - the True Async scheduler owns resumption. */
    duckdb_pending_suspend_true_async(intern, task);
    return true;
}
