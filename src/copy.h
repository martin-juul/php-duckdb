#ifndef PHP_DUCKDB_COPY_H
#define PHP_DUCKDB_COPY_H

#include "php_duckdb.h"

struct copy_session;

/* A PHP COPY TO format registered on one connection. Callbacks on DuckDB
 * threads read only the atomic and immutable fields; `function` is touched
 * only on `owner`, while the registration is alive or a session pins it. */
struct copy_registration {
    std::string name;
    std::thread::id owner;
    std::atomic<bool> alive{true};
    std::weak_ptr<conn_inner> conn;
    zend_object *function = nullptr;
};

/* Why a statement that uses a PHP COPY format cannot run on a worker. */
extern const char duckdb_copy_not_pumped_message[];

extern zend_class_entry *duckdb_copy_to_function_ce;
extern zend_class_entry *duckdb_copy_to_writer_ce;

void duckdb_register_copy_interfaces(zend_class_entry *function_ce, zend_class_entry *writer_ce);

/* Connection object lifecycle: expose handlers to GC and retire them. */
void duckdb_copy_connection_gc(conn_inner *conn, zend_get_gc_buffer *buffer);
void duckdb_copy_connection_free(conn_inner *conn);

/* Pin the connection's live handlers for a new session (request thread). */
void duckdb_copy_session_attach(conn_inner &conn, copy_session &session);

/* Prepare-time bookkeeping on the request thread. */
void duckdb_copy_prepare_begin();
bool duckdb_copy_prepare_used_format();
/* getTableNames() binds statements without executing them. */
struct duckdb_copy_native_bind_scope {
    duckdb_copy_native_bind_scope();
    ~duckdb_copy_native_bind_scope();
};

/* Whether a COPY handler hit exit() or a fatal error that is not yet
 * re-raised; no other PHP code should run until it is. */
bool duckdb_copy_unwinding();

/* Run after every PHP method that may have run COPY handlers, once its C++
 * state is gone: drain deferred cleanup, re-raise exit() or a fatal
 * error that a handler hit inside DuckDB, and chain a handler's exception
 * onto the exception the method threw. */
void duckdb_copy_after_method();
/* The same for an object destructor, whose handler failure has no
 * exception of its own to chain onto. */
void duckdb_copy_after_destructor();

/* Define a PHP method whose body runs in a helper, so that
 * duckdb_copy_after_method() runs after the body's C++ locals are gone. */
#define DUCKDB_COPY_METHOD(cls, name)                                   \
    static void cls##_##name##_body(INTERNAL_FUNCTION_PARAMETERS);      \
    PHP_METHOD(cls, name) {                                              \
        cls##_##name##_body(INTERNAL_FUNCTION_PARAM_PASSTHRU);           \
        duckdb_copy_after_method();                                      \
    }                                                                    \
    static void cls##_##name##_body(INTERNAL_FUNCTION_PARAMETERS)

#endif
