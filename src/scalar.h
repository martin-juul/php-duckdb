#ifndef PHP_DUCKDB_SCALAR_H
#define PHP_DUCKDB_SCALAR_H

#include "php_duckdb.h"

/* Opaque registration; native catalog/bind/state handles contain no owned
 * Zend values. The connection owns the PHP values and exposes them to GC. */
struct scalar_registration;

extern zend_class_entry *duckdb_scalar_function_ce;
extern zend_class_entry *duckdb_scalar_binding_ce;
extern zend_class_entry *duckdb_scalar_executor_ce;

void duckdb_register_scalar_interfaces(zend_class_entry *function_ce, zend_class_entry *binding_ce,
                                       zend_class_entry *executor_ce);
void duckdb_scalar_connection_gc(conn_inner *conn, zend_get_gc_buffer *buffer);
void duckdb_scalar_connection_free(conn_inner *conn);
/* Drain retired native tokens on the request thread, after native locals and
 * connection locks have gone. Never call this from a DuckDB worker. */
void duckdb_scalar_drain();

/* Shared callback boundary implemented by copy.cpp. */
bool duckdb_php_call_handler(conn_inner *conn, zend_object *object, const char *method, uint32_t argc,
                             zval *argv, zval *retval, std::string &error);
bool duckdb_php_handler_active();
void duckdb_php_release_object(zend_object *object);
void duckdb_php_mark_prepare_callback();

#endif
