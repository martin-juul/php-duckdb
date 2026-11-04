#ifndef PHP_DUCKDB_COPY_H
#define PHP_DUCKDB_COPY_H

#include "php_duckdb.h"

#include <exception>
#include <type_traits>

struct copy_session;

/* Shared PHP callback boundary. These helpers run on the request thread;
 * dispatch is the only helper that may also be called by a DuckDB worker. */
enum class duckdb_callback_outcome {
    ok,
    exception,
    exit,
    bailout,
};

/* Marks the connection busy, blocks Fiber switching and captures PHP
 * unwinding until duckdb_copy_after_method() runs. `exception` receives an
 * owned ordinary exception. `label` identifies fatal/exit error messages. */
duckdb_callback_outcome duckdb_callback_call(conn_inner *conn, zend_object *object, const char *method,
                                             uint32_t argc, zval *argv, zval *retval,
                                             std::string &message, zend_object **exception,
                                             const char *label = "PHP");
/* Takes ownership; the first exception becomes the outer error's cause. */
void duckdb_callback_keep_cause(zend_object *exception);
/* Whether a handler exception is kept for the current method's after-hook. */
bool duckdb_callback_has_cause();
/* Takes the kept handler exception, owned by the caller, so that the
 * after-hook does not drop it; nullptr when none is kept. */
zend_object *duckdb_callback_take_cause();
/* Drops an owned request-thread reference under the same busy/Fiber/unwind
 * guards. Destructor exceptions preserve an already pending outer error. */
void duckdb_callback_release(conn_inner *conn, zend_object *object);
/* The same for a value, including arrays containing user objects. Consumes
 * the value and leaves it UNDEF even when a destructor aborts. */
void duckdb_callback_zval_release(conn_inner *conn, zval *value);
bool duckdb_callback_active();
/* Register the request-thread fatal-error observer during module startup. */
void duckdb_callback_observe_errors();
/* Runs PHP work directly on the driver or queues it while the worker waits.
 * Like duckdb_callback_run(), a bailout inside `work` fails it. */
bool duckdb_callback_on_request_thread(copy_session &session,
                                       const std::function<bool(std::string &)> &work,
                                       std::string &error);

/* Run `work(context)` on the request thread. A bailout inside it, such as a
 * fatal error outside a handler, is recorded like a handler's and returns
 * false instead of jumping over the caller's C++ frames. */
bool duckdb_callback_try(void (*work)(void *), void *context);

/* Thrown by duckdb_callback_marshal() after it recorded a bailout. */
struct duckdb_callback_bailout : std::exception {
    const char *what() const noexcept override {
        return "PHP callback stopped by a fatal error";
    }
};

/* Run PHP API calls that build or release a handler's arguments, such as
 * allocating them. A bailout inside `work` is recorded and thrown as
 * duckdb_callback_bailout, so the caller's native owners unwind normally.
 * `work` must own no native state itself. */
template <typename Work>
void duckdb_callback_marshal(Work &&work) {
    using work_type = typename std::remove_reference<Work>::type;
    auto run = [](void *context) {
        (*static_cast<work_type *>(context))();
    };
    if (!duckdb_callback_try(run, &work)) {
        throw duckdb_callback_bailout();
    }
}

/* Run request-thread PHP work that DuckDB reached, such as a bind callback.
 * A bailout anywhere inside it is recorded and fails the work with `error`
 * set, so the C++ frames around it, DuckDB's included, unwind normally;
 * duckdb_copy_after_method() re-raises it. */
bool duckdb_callback_run(const std::function<bool(std::string &)> &work, std::string &error);

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
/* Why the request is unwinding, for closing the sessions it ends; empty
 * while it is not. */
std::string duckdb_copy_unwind_reason();

/* Run after every PHP method that may have run COPY handlers, once its C++
 * state is gone: drain deferred cleanup, re-raise exit() or a fatal
 * error that a handler hit inside DuckDB, and chain a handler's exception
 * onto the exception the method threw. */
void duckdb_copy_after_method();
/* The same for an object destructor, whose handler failure has no
 * exception of its own to chain onto. */
void duckdb_copy_after_destructor();

/* duckdb_copy_after_method() for a method that may already have set its
 * return value, which it releases when it re-raises exit() or a fatal
 * error. */
void duckdb_copy_after_method_return(zval *return_value);

/* Define a PHP method whose body runs in a helper, so that
 * duckdb_copy_after_method() runs after the body's C++ locals are gone.
 * Every method that can lease the connection's helper, or step a statement
 * that reaches PHP callbacks, is defined this way. */
#define DUCKDB_COPY_METHOD(cls, name)                                   \
    static void cls##_##name##_body(INTERNAL_FUNCTION_PARAMETERS);      \
    PHP_METHOD(cls, name) {                                              \
        cls##_##name##_body(INTERNAL_FUNCTION_PARAM_PASSTHRU);           \
        duckdb_copy_after_method_return(return_value);                   \
    }                                                                    \
    static void cls##_##name##_body(INTERNAL_FUNCTION_PARAMETERS)

#endif
