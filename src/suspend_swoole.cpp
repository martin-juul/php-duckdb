/*
  +----------------------------------------------------------------------+
  | duckdb - native DuckDB driver for PHP                                |
  +----------------------------------------------------------------------+
  | Copyright (c) Martin Juul Christiansen (https://juul.xyz)            |
  +----------------------------------------------------------------------+
  | This source file is subject to the MIT license that is bundled with  |
  | this package in the file LICENSE.                                    |
  +----------------------------------------------------------------------+
  | Swoole 6+ coroutine integration for                                  |
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
/* Swoole 6+ coroutine integration                                     */
/*                                                                     */
/* Swoole is an optional, runtime-only dependency: nothing is linked   */
/* against it and no headers are needed - every interaction goes       */
/* through its PHP API looked up by name. When suspend() is called     */
/* inside a Swoole coroutine, the coroutine yields on the query's      */
/* completion descriptor instead of blocking the scheduler.            */
/* ================================================================== */

/* Real Swoole >= 6 only: OpenSwoole registers OpenSwoole\* classes and
 * OPENSWOOLE_* constants, so it can never satisfy these checks - and the
 * exact method surface we rely on is verified as well. All lookups are
 * per-request hash finds - cheap, and safe in every SAPI. */
static bool duckdb_swoole_supported(void) {
    static const char swoole_coroutine[] = "swoole\\coroutine";
    zend_class_entry *ce = static_cast<zend_class_entry *>(
        zend_hash_str_find_ptr(CG(class_table), swoole_coroutine, sizeof(swoole_coroutine) - 1));
    if (ce == nullptr) {
        return false;
    }
    /* The methods suspend() actually calls: getCid, waitEvent, sleep. */
    static const char *const methods[] = {"getcid", "waitevent", "sleep"};
    for (const char *m : methods) {
        if (!zend_hash_str_exists(&ce->function_table, m, strlen(m))) {
            return false;
        }
    }
    zval *ver = zend_get_constant_str("SWOOLE_VERSION", strlen("SWOOLE_VERSION"));
    if (ver == nullptr || Z_TYPE_P(ver) != IS_STRING || atoi(Z_STRVAL_P(ver)) < 6) {
        return false;
    }
    return true;
}

static zend_long duckdb_swoole_event_read(void) {
    zval *c = zend_get_constant_str("SWOOLE_EVENT_READ", strlen("SWOOLE_EVENT_READ"));
    if (c != nullptr && Z_TYPE_P(c) == IS_LONG) {
        return Z_LVAL_P(c);
    }
    return 512; /* the value in every Swoole 6.x release */
}

/* true when currently executing inside a Swoole coroutine */
static bool duckdb_swoole_in_coroutine(void) {
    zval rv;
    if (!duckdb_call_php("Swoole\\Coroutine::getCid", 0, nullptr, &rv)) {
        return false; /* exception propagates: a real failure, not "absent" */
    }
    bool in = Z_TYPE(rv) == IS_LONG && Z_LVAL(rv) >= 0;
    zval_ptr_dtor(&rv);
    return in;
}

/* Wait for task completion by yielding the Swoole coroutine, keeping the
 * event loop alive for other coroutines while the query runs. */
static void duckdb_pending_suspend_swoole(php_duckdb_pending_object *intern, std::shared_ptr<async_task> &task) {
    int fd = -1;
#ifdef PHP_WIN32
    /* Swoole does not support Windows; unreachable, but stay explicit. */
#else
    if (intern->read_fd >= 0) {
        fd = dup(intern->read_fd);
        if (fd < 0) {
            duckdb_throw_msg("dup() failed for the query completion descriptor");
            return;
        }
    }
#endif

    zend_long event_read = duckdb_swoole_event_read();
    while (!duckdb_task_step(task)) {
        zval args[3], rv;
        bool ok;
        if (fd >= 0) {
            /* The completion descriptor becomes readable exactly once,
             * when the worker finishes. The 0.5s timeout is only a
             * watchdog that re-checks the done flag, covering any
             * notification edge; waitEvent returning false on timeout is
             * expected and not an error. */
            ZVAL_LONG(&args[0], fd);
            ZVAL_LONG(&args[1], event_read);
            ZVAL_DOUBLE(&args[2], 0.5);
            ok = duckdb_call_php("Swoole\\Coroutine::waitEvent", 3, args, &rv);
        } else {
            /* Polling mode has no completion descriptor: duckdb_task_step
             * above executes one slice of the query on THIS thread, so the
             * loop makes real progress; yield the coroutine briefly between
             * slices so peers get scheduler time. */
            ZVAL_DOUBLE(&args[0], 0.001);
            ok = duckdb_call_php("Swoole\\Coroutine::sleep", 1, args, &rv);
        }
        zval_ptr_dtor(&rv); /* IS_UNDEF-safe */
        if (!ok) {
            break; /* exception pending */
        }
    }

    if (fd >= 0) {
        close(fd);
    }
}

/* Dispatcher entry point: claim the suspension when running inside a
 * Swoole 6+ coroutine. Returns false when Swoole is absent or the call
 * is not in a coroutine, so the dispatcher tries the next runtime. */
bool duckdb_suspend_swoole(php_duckdb_pending_object *intern, std::shared_ptr<async_task> &task) {
    if (!duckdb_swoole_supported() || !duckdb_swoole_in_coroutine()) {
        return false;
    }
    /* Swoole 6+: yield the coroutine on the completion descriptor instead
     * of the generic fiber protocol - Swoole's scheduler owns resumption. */
    duckdb_pending_suspend_swoole(intern, task);
    return true;
}
