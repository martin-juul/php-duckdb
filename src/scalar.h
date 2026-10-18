#ifndef PHP_DUCKDB_SCALAR_H
#define PHP_DUCKDB_SCALAR_H

#include "php_duckdb.h"

struct copy_session;
struct scalar_slot;
struct scalar_registry;

/* Catalog and prepared-statement handles contain native state only. */
struct scalar_registration {
    std::string name;
    std::thread::id owner;
    std::atomic<bool> alive{true};
    std::weak_ptr<conn_inner> conn;
    std::shared_ptr<struct scalar_token> function;
};

extern zend_class_entry *duckdb_scalar_function_ce;
extern zend_class_entry *duckdb_scalar_binding_ce;
extern zend_class_entry *duckdb_scalar_executor_ce;
extern const char duckdb_scalar_not_pumped_message[];

void duckdb_register_scalar_interfaces(zend_class_entry *function_ce, zend_class_entry *binding_ce,
                                      zend_class_entry *executor_ce);
void duckdb_scalar_connection_gc(conn_inner *conn, zend_get_gc_buffer *buffer);
void duckdb_scalar_connection_free(conn_inner *conn);
void duckdb_scalar_session_attach(conn_inner &conn, copy_session &session);
void duckdb_scalar_prepare_begin();
bool duckdb_scalar_prepare_used_function();
void duckdb_scalar_after_method();

#endif
