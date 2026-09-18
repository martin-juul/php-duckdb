/*
  +----------------------------------------------------------------------+
  | duckdb - native DuckDB driver for PHP                                |
  +----------------------------------------------------------------------+
  | Copyright (c) Martin Juul Christiansen (https://juul.xyz)            |
  +----------------------------------------------------------------------+
  | This source file is subject to the MIT license that is bundled with  |
  | this package in the file LICENSE.                                    |
  +----------------------------------------------------------------------+
  | ReactPHP (react/async v4+) integration for                           |
  | DuckDB\PendingQuery::suspend().                                      |
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

/* ================================================================== */
/* ReactPHP integration (react/async v4+, React\EventLoop)             */
/*                                                                     */
/* ReactPHP is a userland framework: nothing is linked, and every      */
/* interaction goes through its PHP API. When suspend() is called and  */
/* react/async v4+ is loadable, the current fiber (or the main         */
/* context, via react/async's scheduler fiber) awaits a promise that   */
/* resolves when the query's completion stream becomes readable - the  */
/* event loop keeps serving other events while the query runs.         */
/* Cancelling the surrounding async() promise rejects the awaited      */
/* promise, which is honoured by cancelling the underlying query.      */
/* ================================================================== */

/* Same rationale as the AMPHP glue: promise/deferred wiring is far more
 * readable and exception-safe in plain PHP (finally), and the read-stream
 * callback must capture the Deferred - impossible from C. Compiled once
 * per request, only when ReactPHP is detected. */
static const char duckdb_react_glue[] =
    "namespace DuckDB\\Internal;\n"
    "use React\\Async;\n"
    "use React\\EventLoop\\Loop;\n"
    "use React\\Promise\\Deferred;\n"
    "function react_wait($stream): void {\n"
    "    $deferred = new Deferred(static function ($resolve, $reject) use ($stream): void {\n"
    "        Loop::removeReadStream($stream);\n"
    "        $reject(new \\RuntimeException('Operation cancelled'));\n"
    "    });\n"
    "    Loop::addReadStream($stream, static function () use ($deferred): void {\n"
    "        $deferred->resolve(null);\n"
    "    });\n"
    "    try {\n"
    "        Async\\await($deferred->promise());\n"
    "    } finally {\n"
    "        Loop::removeReadStream($stream);\n"
    "    }\n"
    "}\n";

/* Is ReactPHP with react/async v4+ loaded? React\Async\SimpleFiber only
 * exists in v4 (v3 shipped functions.php alone), so it is the version
 * discriminator; the event loop and promise APIs are verified too. */
static bool duckdb_react_supported(void) {
    zend_class_entry *loop = duckdb_lookup_userland_class("React\\EventLoop\\Loop");
    if (loop == nullptr) {
        return false;
    }
    static const char *const loop_methods[] = {"addreadstream", "removereadstream"};
    for (const char *m : loop_methods) {
        if (!zend_hash_str_exists(&loop->function_table, m, strlen(m))) {
            return false;
        }
    }
    /* React\Async\SimpleFiber only exists in react/async v4 (v3 shipped
     * functions.php alone), so it is the version discriminator. */
    if (duckdb_lookup_userland_class("React\\Async\\SimpleFiber") == nullptr) {
        return false;
    }
    if (duckdb_lookup_userland_class("React\\Promise\\Deferred") == nullptr) {
        return false;
    }
    static const char *const fns[] = {
        "react\\async\\async", "react\\async\\await", "react\\async\\delay",
    };
    for (const char *f : fns) {
        if (!zend_hash_str_find_ptr(CG(function_table), f, strlen(f))) {
            return false;
        }
    }
    return true;
}

static bool duckdb_react_ensure_glue(void) {
    static const char wait_fn[] = "duckdb\\internal\\react_wait";
    if (zend_hash_str_find_ptr(CG(function_table), wait_fn, sizeof(wait_fn) - 1) != nullptr) {
        return true;
    }
    return zend_eval_string(duckdb_react_glue, nullptr, "duckdb react glue") == SUCCESS;
}

/* Await the completion stream's readability on the ReactPHP event loop.
 * Returns false when an exception escaped (e.g. cancellation). */
static bool duckdb_react_wait_stream(zval *stream_zval) {
    zval arg, rv;
    ZVAL_COPY(&arg, stream_zval);
    bool ok = duckdb_call_php("DuckDB\\Internal\\react_wait", 1, &arg, &rv);
    zval_ptr_dtor(&arg);
    zval_ptr_dtor(&rv); /* IS_UNDEF-safe */
    return ok;
}

/* Yield to the loop for ~1ms via React\Async\delay() (polling mode). */
static bool duckdb_react_delay(void) {
    zval arg, rv;
    ZVAL_DOUBLE(&arg, 0.001);
    bool ok = duckdb_call_php("React\\Async\\delay", 1, &arg, &rv);
    zval_ptr_dtor(&rv); /* IS_UNDEF-safe */
    return ok;
}

/* Suspend on the ReactPHP event loop until the query completes. Returns
 * false only when the glue could not be compiled; the caller then falls
 * through to the generic fiber protocol. */
static bool duckdb_pending_suspend_react(php_duckdb_pending_object *intern, std::shared_ptr<async_task> &task) {
    if (!duckdb_react_ensure_glue()) {
        return false;
    }

    zval stream_zval;
    ZVAL_UNDEF(&stream_zval);

#ifndef PHP_WIN32
    if (intern->read_fd >= 0) {
        /* dup() so the stream cannot steal the pipe from the PendingQuery;
         * on dup failure degrade to the delay loop. */
        int fd = dup(intern->read_fd);
        if (fd >= 0) {
            php_stream *stream = php_stream_sock_open_from_socket(fd, NULL);
            if (stream != NULL) {
                /* Ownership transfers to the stream's resource: released
                 * via the zval below, never via php_stream_close(). */
                php_stream_to_zval(stream, &stream_zval);
            }
        }
    }
#endif

    while (!duckdb_task_step(task)) {
        bool ok = !Z_ISUNDEF(stream_zval) ? duckdb_react_wait_stream(&stream_zval)
                                          : duckdb_react_delay();
        if (!ok) {
            /* Cancellation (RuntimeException from the rejected Deferred)
             * or another throwable escaped the await: honour it by
             * cancelling the underlying DuckDB query, then propagate. */
            duckdb_task_cancel(task);
            break;
        }
    }

    zval_ptr_dtor(&stream_zval); /* IS_UNDEF-safe; frees the stream + dup'd fd */
    return true;
}

/* Dispatcher entry point: claim the suspension when react/async v4+ is
 * loadable and can await here; returns false otherwise so the dispatcher
 * falls through to the generic fiber protocol. */
bool duckdb_suspend_reactphp(php_duckdb_pending_object *intern, std::shared_ptr<async_task> &task) {
    if (!duckdb_react_supported()) {
        return false;
    }
    return duckdb_pending_suspend_react(intern, task);
}
