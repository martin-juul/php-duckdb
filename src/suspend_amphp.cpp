/*
  +----------------------------------------------------------------------+
  | duckdb - native DuckDB driver for PHP                                |
  +----------------------------------------------------------------------+
  | Copyright (c) Martin Juul Christiansen (https://juul.xyz)            |
  +----------------------------------------------------------------------+
  | This source file is subject to the MIT license that is bundled with  |
  | this package in the file LICENSE.                                    |
  +----------------------------------------------------------------------+
  | AMPHP v3 (Revolt event loop) integration for                         |
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
/* AMPHP integration (amphp/amp v3, Revolt event loop)                 */
/*                                                                     */
/* AMPHP is a userland framework: nothing is linked, and every         */
/* interaction goes through the Revolt event loop's PHP API. When      */
/* suspend() is called where the Revolt loop is available, the fiber   */
/* (or main context) is suspended on the loop until the query's        */
/* completion stream is readable (worker modes) or a 1ms delay elapses */
/* between DuckDB task slices (polling mode) - the loop keeps serving  */
/* other fibers while the query runs.                                  */
/* ================================================================== */

/* Revolt's watcher API is Closure-typed, and the watcher callback must
 * capture the per-fiber suspension - something C cannot hand to a PHP
 * closure. So the watcher/suspension dance is compiled once per request
 * from this snippet: plain PHP, exception-safe via finally, and exactly
 * the mechanism Amp\delay() itself uses. */
static const char duckdb_amphp_glue[] =
    "namespace DuckDB\\Internal;\n"
    "use Revolt\\EventLoop;\n"
    "function amphp_wait($stream): void {\n"
    "    $suspension = EventLoop::getSuspension();\n"
    "    $watcher = EventLoop::onReadable($stream, static function (string $watcherId) use ($suspension): void {\n"
    "        EventLoop::cancel($watcherId);\n"
    "        $suspension->resume();\n"
    "    });\n"
    "    try {\n"
    "        $suspension->suspend();\n"
    "    } finally {\n"
    "        EventLoop::cancel($watcher);\n"
    "    }\n"
    "}\n"
    "function amphp_delay(float $seconds): void {\n"
    "    $suspension = EventLoop::getSuspension();\n"
    "    $watcher = EventLoop::delay($seconds, static function () use ($suspension): void {\n"
    "        $suspension->resume();\n"
    "    });\n"
    "    try {\n"
    "        $suspension->suspend();\n"
    "    } finally {\n"
    "        EventLoop::cancel($watcher);\n"
    "    }\n"
    "}\n";

/* Is the Revolt event loop (amphp/amp v3's runtime) loadable? */
static bool duckdb_amphp_supported(void) {
    zend_class_entry *ce = duckdb_lookup_userland_class("Revolt\\EventLoop");
    if (ce == nullptr) {
        return false;
    }
    /* The exact method surface the glue relies on. */
    static const char *const methods[] = {"getsuspension", "onreadable", "delay", "cancel"};
    for (const char *m : methods) {
        if (!zend_hash_str_exists(&ce->function_table, m, strlen(m))) {
            return false;
        }
    }
    return true;
}

/* Compile the glue once per request. The function_exists check is the
 * guard (per-request function table, so ZTS-safe); a collision with
 * userland code of the same name counts as "already defined". */
static bool duckdb_amphp_ensure_glue(void) {
    static const char wait_fn[] = "duckdb\\internal\\amphp_wait";
    if (zend_hash_str_find_ptr(CG(function_table), wait_fn, sizeof(wait_fn) - 1) != nullptr) {
        return true;
    }
    return zend_eval_string(duckdb_amphp_glue, nullptr, "duckdb amphp glue") == SUCCESS;
}

/* Park on the loop until `stream_zval` (resource of the dup'd completion
 * pipe) is readable. Returns false when an exception escaped suspend(). */
static bool duckdb_amphp_wait_stream(zval *stream_zval) {
    zval arg, rv;
    ZVAL_COPY(&arg, stream_zval);
    bool ok = duckdb_call_php("DuckDB\\Internal\\amphp_wait", 1, &arg, &rv);
    zval_ptr_dtor(&arg);
    zval_ptr_dtor(&rv); /* IS_UNDEF-safe */
    return ok;
}

/* Yield to the loop for ~1ms (polling mode). Same contract as above. */
static bool duckdb_amphp_delay(void) {
    zval arg, rv;
    ZVAL_DOUBLE(&arg, 0.001);
    bool ok = duckdb_call_php("DuckDB\\Internal\\amphp_delay", 1, &arg, &rv);
    zval_ptr_dtor(&rv); /* IS_UNDEF-safe */
    return ok;
}

/* Suspend on the Revolt event loop until the query completes. Returns
 * false only when the glue could not be compiled, in which case the
 * caller falls through to the generic fiber protocol. */
static bool duckdb_pending_suspend_amphp(php_duckdb_pending_object *intern, std::shared_ptr<async_task> &task) {
    if (!duckdb_amphp_ensure_glue()) {
        return false;
    }

    zval stream_zval;
    ZVAL_UNDEF(&stream_zval);

#ifndef PHP_WIN32
    if (intern->read_fd >= 0) {
        /* dup() so the stream cannot steal the pipe from the PendingQuery;
         * on dup failure degrade to the delay loop. Revolt's stream-select
         * driver needs a stream resource, not a raw fd. */
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
        bool ok = !Z_ISUNDEF(stream_zval) ? duckdb_amphp_wait_stream(&stream_zval)
                                          : duckdb_amphp_delay();
        if (!ok) {
            /* An exception escaped the suspension: propagate it. AMPHP
             * futures are not cancellable, so the query keeps running in
             * the background and stays owned by the PendingQuery. */
            break;
        }
    }

    zval_ptr_dtor(&stream_zval); /* IS_UNDEF-safe; frees the stream + dup'd fd */
    return true;
}

/* Dispatcher entry point: claim the suspension when the Revolt event
 * loop is available and can suspend here; returns false otherwise so the
 * dispatcher tries the next runtime. */
bool duckdb_suspend_amphp(php_duckdb_pending_object *intern, std::shared_ptr<async_task> &task) {
    if (!duckdb_amphp_supported()) {
        return false;
    }
    return duckdb_pending_suspend_amphp(intern, task);
}
