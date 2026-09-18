/*
  +----------------------------------------------------------------------+
  | duckdb - native DuckDB driver for PHP                                |
  +----------------------------------------------------------------------+
  | Copyright (c) Martin Juul Christiansen (https://juul.xyz)            |
  +----------------------------------------------------------------------+
  | This source file is subject to the MIT license that is bundled with  |
  | this package in the file LICENSE.                                    |
  +----------------------------------------------------------------------+
  | Internal contract between the PendingQuery core (pending.cpp)        |
  | and the runtime integrations (suspend_*.cpp).                        |
  +----------------------------------------------------------------------+
*/



#ifndef DUCKDB_SUSPEND_INTERNAL_H
#define DUCKDB_SUSPEND_INTERNAL_H

#include "php.h"
#include "php_duckdb.h"

/* Shared helpers provided by pending.cpp. */
bool duckdb_call_php(const char *name, uint32_t argc, zval *args, zval *rv);
bool duckdb_task_step(std::shared_ptr<async_task> task);
void duckdb_pending_complete(php_duckdb_pending_object *intern, zval *return_value);
void duckdb_task_cancel(std::shared_ptr<async_task> &task);
zend_class_entry *duckdb_lookup_userland_class(const char *name);

/* Runtime integration entry points, claimed by suspend() in this order:
 * Swoole 6+, True Async, AMPHP v3 (Revolt), ReactPHP (react/async v4+).
 * Each returns true when its runtime claimed the suspension and the wait
 * loop ran to completion; false when the runtime is absent or inactive,
 * so the dispatcher tries the next one. On true with EG(exception) set,
 * the wait was interrupted (e.g. cooperative cancellation). */
bool duckdb_suspend_swoole(php_duckdb_pending_object *intern, std::shared_ptr<async_task> &task);
bool duckdb_suspend_true_async(php_duckdb_pending_object *intern, std::shared_ptr<async_task> &task);
bool duckdb_suspend_amphp(php_duckdb_pending_object *intern, std::shared_ptr<async_task> &task);
bool duckdb_suspend_reactphp(php_duckdb_pending_object *intern, std::shared_ptr<async_task> &task);

#endif /* DUCKDB_SUSPEND_INTERNAL_H */
