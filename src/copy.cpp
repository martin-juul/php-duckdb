/* Custom COPY TO formats implemented in PHP.
 *
 * DuckDB calls COPY functions from its own worker threads, but PHP code may
 * only run on the request thread. Statements on connections with registered
 * formats therefore run through the pump in copy_session.cpp: a callback on
 * the request thread calls PHP directly, and a callback on a worker hands
 * its work to the request thread and waits. Nothing that DuckDB may free on
 * another thread (extra info, bind data, global state) holds PHP values. */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"
#include "php_duckdb.h"
#include "copy.h"
#include "copy_session.h"
#include "data_chunk.h"

extern "C" {
#include "zend_exceptions.h"
#include "zend_fibers.h"
#include "zend_interfaces.h"
}

#include <algorithm>
#include <atomic>
#include <cctype>
#include <cstring>
#include <unordered_map>

#ifdef PHP_WIN32
#include <direct.h>
#else
#include <unistd.h>
#endif

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

zend_class_entry *duckdb_copy_to_function_ce;
zend_class_entry *duckdb_copy_to_writer_ce;

/* Recognized by the bundled SDK's patched binder, which then treats the
 * format as missing; an unpatched library reports it as a binder error. */
static const char decline_prefix[] = "[copy-function-declined] ";

static const char parallel_message[] =
    "PHP COPY formats do not support PARTITION_BY, PER_THREAD_OUTPUT or EXPORT DATABASE";

/* Request-thread state. */
static thread_local int handler_depth = 0;
static thread_local zend_object *pending_cause = nullptr;
static thread_local zend_object *pending_exit = nullptr;
static thread_local bool pending_bailout = false;
static thread_local bool native_bind = false;
static thread_local bool prepare_used_format = false;
static thread_local const char *probe_token = nullptr;
static thread_local const void *probe_answer = nullptr;

/* One registered name in one DuckDB instance. It is the copy function's
 * extra info, so it lives as long as the catalog entry. */
struct copy_slot {
    std::string name;
    std::weak_ptr<db_inner> db;
    std::mutex mutex;
    std::unordered_map<idx_t, std::shared_ptr<copy_registration>> entries;
};

struct copy_bind {
    std::shared_ptr<copy_registration> registration;
    std::vector<std::string> types;
    duckdb_value options = nullptr;

    ~copy_bind() {
        if (options) {
            duckdb_destroy_value(&options);
        }
    }
};

/* One execution of a COPY: DuckDB's global state. */
struct copy_exec {
    copy_bind *bind = nullptr;
    std::weak_ptr<copy_session> session;
    std::string path;
    std::atomic<bool> in_callback{false};
};

/* PHP-side session state; only touched on the request thread. */
struct copy_php_state {
    conn_inner *conn = nullptr;
    std::vector<std::pair<std::shared_ptr<copy_registration>, zend_object *>> pins;
    struct writer {
        zend_object *object = nullptr;
        bool closed = false;
    };
    std::unordered_map<copy_exec *, writer> writers;
    zend_object *cause = nullptr;
};

void duckdb_register_copy_interfaces(zend_class_entry *function_ce, zend_class_entry *writer_ce) {
    duckdb_copy_to_function_ce = function_ce;
    duckdb_copy_to_writer_ce = writer_ce;
}

/* ------------------------------------------------------------------ */
/* Handler invocation (request thread)                                */
/* ------------------------------------------------------------------ */

enum class call_outcome {
    ok,
    exception,
    exit,
    bailout,
};

/* Only plain locals: zend_try returns here by longjmp. */
static bool invoke_method(zend_object *object, const char *method, uint32_t argc, zval *argv, zval *retval) {
    zend_function *fn = static_cast<zend_function *>(
        zend_hash_str_find_ptr_lc(&object->ce->function_table, method, strlen(method)));
    /* A fatal error unwinds to here and leaves the engine pointing at the
     * handler's discarded frames; the calling method is still running. */
    zend_execute_data *const frame = EG(current_execute_data);
    volatile bool completed = false;
    zend_try {
        zend_call_known_instance_method(fn, object, retval, argc, argv);
        completed = true;
    } zend_catch {
        EG(current_execute_data) = frame;
        completed = false;
    } zend_end_try();
    return completed;
}

static std::string exception_message(zend_object *exception) {
    zval rv;
    zval *message = zend_read_property_ex(zend_get_exception_base(exception), exception,
                                          ZSTR_KNOWN(ZEND_STR_MESSAGE), /*silent=*/1, &rv);
    if (message && Z_TYPE_P(message) == IS_STRING && Z_STRLEN_P(message) > 0) {
        return std::string(Z_STRVAL_P(message), Z_STRLEN_P(message));
    }
    return std::string(ZSTR_VAL(exception->ce->name), ZSTR_LEN(exception->ce->name));
}

static zend_object *make_exception(const std::string &message) {
    zval exception;
    object_init_ex(&exception, duckdb_exception_ce);
    zend_update_property_stringl(zend_ce_exception, Z_OBJ(exception), "message", sizeof("message") - 1,
                                 message.data(), message.size());
    return Z_OBJ(exception);
}

/* Call a handler method with the connection marked busy and Fiber switches
 * blocked. On failure `message` describes it and an ordinary exception is
 * returned through `exception` (owned by the caller). */
static call_outcome call_handler(conn_inner *conn, zend_object *object, const char *method, uint32_t argc,
                                 zval *argv, zval *retval, std::string &message, zend_object **exception) {
    if (conn) {
        conn->handler_thread.store(std::this_thread::get_id(), std::memory_order_release);
        conn->handler_depth.fetch_add(1, std::memory_order_acq_rel);
    }
    handler_depth++;
    zend_fiber_switch_block();

    ZVAL_UNDEF(retval);
    bool completed = invoke_method(object, method, argc, argv, retval);

    zend_fiber_switch_unblock();
    handler_depth--;
    if (conn) {
        conn->handler_depth.fetch_sub(1, std::memory_order_acq_rel);
    }

    if (!completed) {
        pending_bailout = true;
        zval_ptr_dtor(retval);
        ZVAL_UNDEF(retval);
        message = "COPY handler stopped by a fatal error";
        return call_outcome::bailout;
    }
    if (EG(exception)) {
        zend_object *thrown = EG(exception);
        GC_ADDREF(thrown);
        zend_clear_exception();
        zval_ptr_dtor(retval);
        ZVAL_UNDEF(retval);
        if (zend_is_unwind_exit(thrown) || zend_is_graceful_exit(thrown)) {
            if (pending_exit) {
                OBJ_RELEASE(thrown);
            } else {
                pending_exit = thrown;
            }
            message = "COPY aborted by exit()";
            return call_outcome::exit;
        }
        message = exception_message(thrown);
        *exception = thrown;
        return call_outcome::exception;
    }
    return call_outcome::ok;
}

/* Record a handler failure; the first ordinary exception becomes the cause. */
static void keep_cause(zend_object **slot, zend_object *exception) {
    if (!exception) {
        return;
    }
    if (*slot) {
        OBJ_RELEASE(exception);
        return;
    }
    *slot = exception;
}

static std::string stale_message(const copy_registration &registration) {
    return "COPY format '" + registration.name +
           "' was registered again or released after this statement was prepared; prepare it again";
}

static std::string describe(const std::string &format, const char *method, call_outcome outcome,
                            const std::string &message) {
    if (outcome == call_outcome::exception) {
        return "COPY format '" + format + "' " + method + "(): " + message;
    }
    return message;
}

/* ------------------------------------------------------------------ */
/* Option and type decoding (request thread)                          */
/* ------------------------------------------------------------------ */

static bool unnamed_struct(duckdb_logical_type type) {
    if (duckdb_get_type_id(type) != DUCKDB_TYPE_STRUCT) {
        return false;
    }
    idx_t count = duckdb_struct_type_child_count(type);
    for (idx_t i = 0; i < count; i++) {
        char *name = duckdb_struct_type_child_name(type, i);
        bool empty = !name || !name[0];
        duckdb_free(name);
        if (!empty) {
            return false;
        }
    }
    return count > 0;
}

static void decode_value(duckdb_value value, zval *out) {
    if (!value || duckdb_is_null_value(value)) {
        ZVAL_NULL(out);
        return;
    }
    duckdb_logical_type type = duckdb_get_value_type(value);

    /* Mixed-type option values arrive as a STRUCT with empty field names. */
    if (unnamed_struct(type)) {
        array_init(out);
        idx_t count = duckdb_struct_type_child_count(type);
        for (idx_t i = 0; i < count; i++) {
            scoped_duckdb_value child(duckdb_get_struct_child(value, i));
            zval element;
            decode_value(child.get(), &element);
            add_next_index_zval(out, &element);
        }
        return;
    }

    /* Decode through a flat one-row vector, like result values. */
    duckdb_scoped<duckdb_vector, duckdb_destroy_vector> constant(duckdb_create_vector(type, 1));
    duckdb_scoped<duckdb_vector, duckdb_destroy_vector> flat(duckdb_create_vector(type, 1));
    if (!constant || !flat) {
        ZVAL_NULL(out);
        return;
    }
    duckdb_vector_reference_value(constant.get(), value);
    scoped_duckdb_selection selection(duckdb_create_selection_vector(1));
    duckdb_selection_vector_get_data_ptr(selection.get())[0] = 0;
    duckdb_vector_copy_sel(constant.get(), flat.get(), selection.get(), 1, 0, 0);
    if (!duckdb_decode_vector_value(flat.get(), type, 0, out)) {
        ZVAL_NULL(out);
    }
}

static void decode_options(duckdb_value options, zval *out) {
    array_init(out);
    if (!options || duckdb_is_null_value(options)) {
        return;
    }
    duckdb_logical_type type = duckdb_get_value_type(options);
    if (duckdb_get_type_id(type) != DUCKDB_TYPE_STRUCT) {
        return;
    }
    /* DuckDB keeps options in a hash map; sort them for a stable order. */
    idx_t count = duckdb_struct_type_child_count(type);
    std::vector<std::pair<std::string, idx_t>> names;
    for (idx_t i = 0; i < count; i++) {
        char *name = duckdb_struct_type_child_name(type, i);
        names.emplace_back(name ? name : "", i);
        duckdb_free(name);
    }
    std::sort(names.begin(), names.end());

    for (const auto &name : names) {
        scoped_duckdb_value child(duckdb_get_struct_child(options, name.second));
        zval element;
        decode_value(child.get(), &element);
        add_assoc_zval_ex(out, name.first.data(), name.first.size(), &element);
    }
}

static void types_array(const std::vector<std::string> &types, zval *out) {
    array_init_size(out, static_cast<uint32_t>(types.size()));
    for (const auto &type : types) {
        add_next_index_stringl(out, type.data(), type.size());
    }
}

/* ------------------------------------------------------------------ */
/* Sessions (request thread)                                          */
/* ------------------------------------------------------------------ */

static copy_php_state *php_state_of(copy_session &session) {
    return static_cast<copy_php_state *>(session.php_state.load(std::memory_order_acquire));
}

static zend_object *pinned_function(copy_php_state &state, const copy_registration *registration) {
    for (auto &pin : state.pins) {
        if (pin.first.get() == registration) {
            return pin.second;
        }
    }
    return nullptr;
}

static void finish_session(copy_php_state *state, const std::string &failure, bool can_call_php) {
    bool call_php = can_call_php && !pending_bailout;
    for (auto &entry : state->writers) {
        auto &writer = entry.second;
        if (!writer.object) {
            continue;
        }
        if (!writer.closed && !failure.empty() && call_php) {
            zend_object *reason = state->cause;
            if (reason) {
                GC_ADDREF(reason);
            } else {
                reason = make_exception(failure);
            }
            zval argument;
            ZVAL_OBJ(&argument, reason);
            zval retval;
            std::string message;
            zend_object *thrown = nullptr;
            call_handler(state->conn, writer.object, "abort", 1, &argument, &retval, message, &thrown);
            zval_ptr_dtor(&retval);
            zval_ptr_dtor(&argument);
            if (thrown) {
                OBJ_RELEASE(thrown);
            }
            call_php = !pending_bailout;
        }
        OBJ_RELEASE(writer.object);
        writer.object = nullptr;
    }
    for (auto &pin : state->pins) {
        OBJ_RELEASE(pin.second);
    }
    if (state->cause) {
        if (!failure.empty() && !pending_cause) {
            pending_cause = state->cause;
        } else {
            OBJ_RELEASE(state->cause);
        }
    }
    delete state;
}

void duckdb_copy_session_attach(conn_inner &conn, copy_session &session) {
    if (conn.copy_regs.empty()) {
        return;
    }
    auto *state = new copy_php_state();
    state->conn = &conn;
    for (auto &entry : conn.copy_regs) {
        auto &registration = entry.second;
        if (!registration->alive.load(std::memory_order_acquire)) {
            continue;
        }
        GC_ADDREF(registration->function);
        state->pins.emplace_back(registration, registration->function);
    }
    session.php_state.store(state, std::memory_order_release);
    /* duckdb_session_end() keeps the session alive while this runs. */
    copy_session *owner = &session;
    session.finish = [state, owner](const std::string &failure, bool can_call_php) {
        owner->php_state.store(nullptr, std::memory_order_release);
        finish_session(state, failure, can_call_php);
    };
}

/* Open the execution's writer on first use. */
static bool ensure_open(copy_php_state &state, copy_exec &exec, std::string &error) {
    auto &writer = state.writers[&exec];
    if (writer.object) {
        return true;
    }

    copy_registration *registration = exec.bind->registration.get();
    zend_object *function = pinned_function(state, registration);
    if (!function) {
        error = stale_message(*registration);
        return false;
    }

    zval argv[3];
    ZVAL_STRINGL(&argv[0], exec.path.data(), exec.path.size());
    types_array(exec.bind->types, &argv[1]);
    decode_options(exec.bind->options, &argv[2]);
    zval retval;
    std::string message;
    zend_object *thrown = nullptr;
    call_outcome outcome = call_handler(state.conn, function, "open", 3, argv, &retval, message, &thrown);
    zval_ptr_dtor(&argv[0]);
    zval_ptr_dtor(&argv[1]);
    zval_ptr_dtor(&argv[2]);
    if (outcome != call_outcome::ok) {
        keep_cause(&state.cause, thrown);
        error = describe(registration->name, "open", outcome, message);
        return false;
    }
    if (Z_TYPE(retval) != IS_OBJECT || !instanceof_function(Z_OBJCE(retval), duckdb_copy_to_writer_ce)) {
        zval_ptr_dtor(&retval);
        error = "COPY format '" + registration->name + "' open() must return a DuckDB\\CopyToWriter";
        return false;
    }
    writer.object = Z_OBJ(retval);
    return true;
}

static bool php_write(copy_session &session, copy_exec &exec, duckdb_data_chunk chunk, std::string &error) {
    copy_php_state *state = php_state_of(session);
    if (!state) {
        error = "COPY format is not registered on this connection";
        return false;
    }
    if (!ensure_open(*state, exec, error)) {
        return false;
    }

    auto data = std::make_shared<data_chunk_data>();
    data->chunk = chunk;
    data->borrowed = true;
    idx_t columns = duckdb_data_chunk_get_column_count(chunk);
    for (idx_t i = 0; i < columns; i++) {
        data->names.push_back("col" + std::to_string(i));
    }
    zval batch;
    duckdb_data_chunk_wrap(&batch, data);

    zval retval;
    std::string message;
    zend_object *thrown = nullptr;
    auto &writer = state->writers[&exec];
    call_outcome outcome = call_handler(state->conn, writer.object, "write", 1, &batch, &retval, message, &thrown);
    data->expired = true;
    data->chunk = nullptr;
    zval_ptr_dtor(&retval);
    zval_ptr_dtor(&batch);
    if (outcome != call_outcome::ok) {
        keep_cause(&state->cause, thrown);
        error = describe(exec.bind->registration->name, "write", outcome, message);
        return false;
    }
    return true;
}

static bool php_finalize(copy_session &session, copy_exec &exec, std::string &error) {
    copy_php_state *state = php_state_of(session);
    if (!state) {
        error = "COPY format is not registered on this connection";
        return false;
    }
    if (!ensure_open(*state, exec, error)) {
        return false;
    }

    zval retval;
    std::string message;
    zend_object *thrown = nullptr;
    auto &writer = state->writers[&exec];
    call_outcome outcome = call_handler(state->conn, writer.object, "close", 0, nullptr, &retval, message, &thrown);
    zval_ptr_dtor(&retval);
    if (outcome != call_outcome::ok) {
        keep_cause(&state->cause, thrown);
        error = describe(exec.bind->registration->name, "close", outcome, message);
        return false;
    }
    writer.closed = true;
    return true;
}

/* Run `work` on the session's request thread: directly when DuckDB called
 * us there, otherwise by handing it over and waiting. */
static bool on_request_thread(copy_session &session, const std::function<bool(std::string &)> &work,
                              std::string &error) {
    if (std::this_thread::get_id() == session.driver) {
        return work(error);
    }

    bool ok = false;
    std::string work_error;
    auto request = std::make_shared<copy_request>();
    request->work = [&]() {
        ok = work(work_error);
    };
    if (!session.submit(request, error)) {
        return false;
    }
    if (!ok) {
        error = work_error;
    }
    return ok;
}

/* ------------------------------------------------------------------ */
/* DuckDB callbacks (any thread)                                      */
/* ------------------------------------------------------------------ */

const char duckdb_copy_not_pumped_message[] =
    "PHP COPY formats run only through query(), execute(), prepared statements and queryPending(), "
    "not queryAsync() or executeAsync()";
static const char *const not_pumped_message = duckdb_copy_not_pumped_message;

static idx_t context_connection_id(duckdb_client_context context) {
    idx_t id = duckdb_client_context_get_connection_id(context);
    duckdb_destroy_client_context(&context);
    return id;
}

static bool option_is_probe(duckdb_value options, std::string &token) {
    if (!options || duckdb_is_null_value(options)) {
        return false;
    }
    duckdb_logical_type type = duckdb_get_value_type(options);
    if (duckdb_get_type_id(type) != DUCKDB_TYPE_STRUCT) {
        return false;
    }
    idx_t count = duckdb_struct_type_child_count(type);
    for (idx_t i = 0; i < count; i++) {
        char *name = duckdb_struct_type_child_name(type, i);
        bool probe = name && strcmp(name, "DUCKDB_PHP_PROBE") == 0;
        duckdb_free(name);
        if (probe) {
            scoped_duckdb_value child(duckdb_get_struct_child(options, i));
            char *text = duckdb_get_varchar(child.get());
            token = text ? text : "";
            duckdb_free(text);
            return true;
        }
    }
    return false;
}

static void copy_bind_callback(duckdb_copy_function_bind_info info) {
    auto *slot = static_cast<std::shared_ptr<copy_slot> *>(duckdb_copy_function_bind_get_extra_info(info))->get();
    idx_t connection_id = context_connection_id(duckdb_copy_function_bind_get_client_context(info));
    duckdb_value options = duckdb_copy_function_bind_get_options(info);

    std::string token;
    if (option_is_probe(options, token)) {
        if (probe_token && token == probe_token) {
            probe_answer = slot;
        }
        duckdb_destroy_value(&options);
        duckdb_copy_function_bind_set_error(info, "COPY format registration probe");
        return;
    }

    std::shared_ptr<copy_registration> registration;
    {
        std::lock_guard<std::mutex> lock(slot->mutex);
        auto found = slot->entries.find(connection_id);
        if (found != slot->entries.end()) {
            registration = found->second;
        }
    }
    if (!registration || !registration->alive.load(std::memory_order_acquire)) {
        duckdb_destroy_value(&options);
        std::string message =
            std::string(decline_prefix) + "COPY format '" + slot->name + "' is not registered on this connection";
        duckdb_copy_function_bind_set_error(info, message.c_str());
        return;
    }
    if (registration->owner != std::this_thread::get_id()) {
        duckdb_destroy_value(&options);
        duckdb_copy_function_bind_set_error(info, not_pumped_message);
        return;
    }
    if (handler_depth > 0) {
        duckdb_destroy_value(&options);
        duckdb_copy_function_bind_set_error(info, "PHP COPY formats cannot run inside another COPY handler");
        return;
    }

    auto *bind = new copy_bind();
    bind->registration = registration;
    bind->options = options;
    idx_t columns = duckdb_copy_function_bind_get_column_count(info);
    for (idx_t i = 0; i < columns; i++) {
        scoped_duckdb_logical_type type(duckdb_copy_function_bind_get_column_type(info, i));
        bind->types.push_back(duckdb_logical_type_sql(type.get()));
    }
    prepare_used_format = true;

    if (!native_bind) {
        auto conn = registration->conn.lock();
        zval argv[2];
        types_array(bind->types, &argv[0]);
        decode_options(bind->options, &argv[1]);
        zval retval;
        std::string message;
        zend_object *thrown = nullptr;
        call_outcome outcome =
            call_handler(conn.get(), registration->function, "bind", 2, argv, &retval, message, &thrown);
        zval_ptr_dtor(&argv[0]);
        zval_ptr_dtor(&argv[1]);
        zval_ptr_dtor(&retval);
        if (outcome != call_outcome::ok) {
            keep_cause(&pending_cause, thrown);
            delete bind;
            std::string error = describe(registration->name, "bind", outcome, message);
            duckdb_copy_function_bind_set_error(info, error.c_str());
            return;
        }
    }

    duckdb_copy_function_bind_set_bind_data(info, bind, [](void *data) {
        delete static_cast<copy_bind *>(data);
    });
}

/* DuckDB resolves a relative target against the process working directory,
 * not PHP's per-request one, and this may run on a DuckDB thread. */
static std::string absolute_path(const char *path) {
    std::string value = path ? path : "";
    if (value.empty() || value.find("://") != std::string::npos || value[0] == '/' || value[0] == '\\' ||
        (value.size() > 1 && std::isalpha(static_cast<unsigned char>(value[0])) && value[1] == ':')) {
        return value;
    }

    char buffer[4096];
#ifdef PHP_WIN32
    if (!_getcwd(buffer, sizeof(buffer))) {
        return value;
    }
    return std::string(buffer) + "\\" + value;
#else
    if (!getcwd(buffer, sizeof(buffer))) {
        return value;
    }
    return std::string(buffer) + "/" + value;
#endif
}

static void copy_global_init_callback(duckdb_copy_function_global_init_info info) {
    auto *bind = static_cast<copy_bind *>(duckdb_copy_function_global_init_get_bind_data(info));
    auto *slot =
        static_cast<std::shared_ptr<copy_slot> *>(duckdb_copy_function_global_init_get_extra_info(info))->get();
    if (!bind) {
        duckdb_copy_function_global_init_set_error(info, not_pumped_message);
        return;
    }
    idx_t connection_id =
        context_connection_id(duckdb_copy_function_global_init_get_client_context(info));
    auto db = slot->db.lock();
    auto session = db ? duckdb_session_find(*db, connection_id) : nullptr;
    if (!session || !session->is_open() || !session->php_state.load(std::memory_order_acquire)) {
        /* Without a live registration the statement is not pumped either. */
        if (!bind->registration->alive.load(std::memory_order_acquire)) {
            duckdb_copy_function_global_init_set_error(info, stale_message(*bind->registration).c_str());
        } else {
            duckdb_copy_function_global_init_set_error(info, not_pumped_message);
        }
        return;
    }
    if (session->copy_inits.fetch_add(1) > 0) {
        session->close(parallel_message);
        duckdb_copy_function_global_init_set_error(info, parallel_message);
        return;
    }

    auto *exec = new copy_exec();
    exec->bind = bind;
    exec->session = session;
    exec->path = absolute_path(duckdb_copy_function_global_init_get_file_path(info));
    duckdb_copy_function_global_init_set_global_state(info, exec, [](void *data) {
        delete static_cast<copy_exec *>(data);
    });
}

/* Shared by sink and finalize: run `work` for the execution, fail the
 * session on any error, and report the error to DuckDB. */
template <typename SetError>
static void run_callback(copy_exec *exec, SetError set_error,
                         const std::function<bool(copy_session &, std::string &)> &work) {
    auto session = exec ? exec->session.lock() : nullptr;
    if (!session || !session->is_open()) {
        set_error(not_pumped_message);
        return;
    }
    if (exec->in_callback.exchange(true)) {
        session->close(parallel_message);
        set_error(parallel_message);
        return;
    }

    std::string error;
    bool ok = on_request_thread(
        *session,
        [&](std::string &work_error) {
            return work(*session, work_error);
        },
        error);
    exec->in_callback.store(false);
    if (!ok) {
        /* Release waiting workers before DuckDB starts cancelling. */
        session->close(error);
        set_error(error.c_str());
    }
}

static void copy_sink_callback(duckdb_copy_function_sink_info info, duckdb_data_chunk input) {
    auto *exec = static_cast<copy_exec *>(duckdb_copy_function_sink_get_global_state(info));
    run_callback(
        exec,
        [info](const char *error) {
            duckdb_copy_function_sink_set_error(info, error);
        },
        [exec, input](copy_session &session, std::string &error) {
            return php_write(session, *exec, input, error);
        });
}

static void copy_finalize_callback(duckdb_copy_function_finalize_info info) {
    auto *exec = static_cast<copy_exec *>(duckdb_copy_function_finalize_get_global_state(info));
    run_callback(
        exec,
        [info](const char *error) {
            duckdb_copy_function_finalize_set_error(info, error);
        },
        [exec](copy_session &session, std::string &error) {
            return php_finalize(session, *exec, error);
        });
}

/* ------------------------------------------------------------------ */
/* Registration                                                       */
/* ------------------------------------------------------------------ */

static bool valid_name(const std::string &name) {
    if (name.empty() || !(std::isalpha(static_cast<unsigned char>(name[0])) || name[0] == '_')) {
        return false;
    }
    for (char c : name) {
        if (!std::isalnum(static_cast<unsigned char>(c)) && c != '_') {
            return false;
        }
    }
    return true;
}

static bool reserved_name(const std::string &lower) {
    static const char *const reserved[] = {"csv", "parquet", "json", "avro", "iceberg", "ndjson", "jsonl"};
    for (const char *name : reserved) {
        if (lower == name) {
            return true;
        }
    }
    return false;
}

/* Find or create the instance-wide slot for `lower`. Throws on failure. */
static std::shared_ptr<copy_slot> ensure_slot(db_inner &db, const std::shared_ptr<db_inner> &owner,
                                              const std::string &lower) {
    std::lock_guard<std::mutex> lock(db.copy_mutex);
    auto found = db.copy_slots.find(lower);
    if (found != db.copy_slots.end()) {
        return found->second;
    }

    auto slot = std::make_shared<copy_slot>();
    slot->name = lower;
    slot->db = owner;

    /* Register on a private connection, outside any user transaction. */
    duckdb_connection infra = nullptr;
    if (duckdb_connect(db.db, &infra) == DuckDBError) {
        duckdb_throw_msg("Could not open a connection to register the COPY format");
        return nullptr;
    }

    duckdb_copy_function function = duckdb_create_copy_function();
    duckdb_copy_function_set_name(function, lower.c_str());
    duckdb_copy_function_set_extra_info(function, new std::shared_ptr<copy_slot>(slot), [](void *data) {
        delete static_cast<std::shared_ptr<copy_slot> *>(data);
    });
    duckdb_copy_function_set_bind(function, copy_bind_callback);
    duckdb_copy_function_set_global_init(function, copy_global_init_callback);
    duckdb_copy_function_set_sink(function, copy_sink_callback);
    duckdb_copy_function_set_finalize(function, copy_finalize_callback);
    duckdb_state registered = duckdb_register_copy_function(infra, function);
    duckdb_destroy_copy_function(&function);
    if (registered == DuckDBError) {
        duckdb_disconnect(&infra);
        duckdb_throw_msg("DuckDB could not register the COPY format");
        return nullptr;
    }

    /* DuckDB keeps an existing function of the same name silently. Bind a
     * probe statement and check that our callback answered for this slot. */
    std::string token = std::to_string(reinterpret_cast<uintptr_t>(slot.get()));
    std::string sql = "COPY (SELECT 1) TO 'duckdb_php_probe' (FORMAT \"" + lower + "\", DUCKDB_PHP_PROBE '" +
                      token + "')";
    probe_token = token.c_str();
    probe_answer = nullptr;
    duckdb_prepared_statement probe = nullptr;
    duckdb_prepare(infra, sql.c_str(), &probe);
    duckdb_destroy_prepare(&probe);
    bool ours = probe_answer == slot.get();
    probe_token = nullptr;
    probe_answer = nullptr;
    duckdb_disconnect(&infra);

    if (!ours) {
        std::string message = "COPY format '" + lower + "' is already provided by DuckDB or an extension";
        duckdb_throw_msg(message.c_str());
        return nullptr;
    }
    db.copy_slots[lower] = slot;
    return slot;
}

PHP_METHOD(DuckDB_Connection, registerCopyToFunction) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_string *name;
    zval *function;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(name)
        Z_PARAM_OBJECT_OF_CLASS(function, duckdb_copy_to_function_ce)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_connection_object *intern = Z_DUCKDB_CONNECTION_P(ZEND_THIS);
    if (!duckdb_connection_guard(intern->inner)) {
        RETURN_THROWS();
    }
    conn_inner &conn = *intern->inner;
    if (handler_depth > 0 || !duckdb_conn_check_not_busy(conn)) {
        if (!EG(exception)) {
            duckdb_throw_msg("COPY formats cannot be registered inside a COPY handler");
        }
        RETURN_THROWS();
    }

    std::string display(ZSTR_VAL(name), ZSTR_LEN(name));
    if (!valid_name(display)) {
        zend_argument_value_error(1, "must be a non-empty identifier of letters, digits and underscores");
        RETURN_THROWS();
    }
    std::string lower = display;
    for (auto &c : lower) {
        c = static_cast<char>(std::tolower(static_cast<unsigned char>(c)));
    }
    if (reserved_name(lower)) {
        zend_argument_value_error(1, "must not name a built-in COPY format");
        RETURN_THROWS();
    }
    if (conn.session && conn.session->is_open()) {
        duckdb_throw_msg("COPY formats cannot be registered while a COPY statement runs on this connection");
        RETURN_THROWS();
    }

    std::shared_ptr<copy_slot> slot;
    try {
        slot = ensure_slot(*conn.db, conn.db, lower);
    } catch (const std::exception &error) {
        duckdb_throw_msg(error.what());
        RETURN_THROWS();
    }
    if (!slot) {
        RETURN_THROWS();
    }

    auto registration = std::make_shared<copy_registration>();
    registration->name = display;
    registration->owner = std::this_thread::get_id();
    registration->conn = intern->inner;
    registration->function = Z_OBJ_P(function);
    GC_ADDREF(registration->function);

    idx_t connection_id = duckdb_conn_connection_id(conn);
    {
        std::lock_guard<std::mutex> lock(slot->mutex);
        slot->entries[connection_id] = registration;
    }

    auto previous = conn.copy_regs.find(lower);
    if (previous != conn.copy_regs.end()) {
        previous->second->alive.store(false, std::memory_order_release);
        OBJ_RELEASE(previous->second->function);
        previous->second->function = nullptr;
        previous->second = registration;
    } else {
        conn.copy_regs[lower] = registration;
        conn.copy_registrations.fetch_add(1, std::memory_order_acq_rel);
    }
}

void duckdb_copy_connection_gc(conn_inner *conn, zend_get_gc_buffer *buffer) {
    if (!conn) {
        return;
    }
    for (auto &entry : conn->copy_regs) {
        if (entry.second->function) {
            zend_get_gc_buffer_add_obj(buffer, entry.second->function);
        }
    }
}

void duckdb_copy_connection_free(conn_inner *conn) {
    if (!conn || conn->copy_regs.empty()) {
        return;
    }
    idx_t connection_id = conn->connection_id_known ? conn->connection_id : duckdb_conn_connection_id(*conn);
    for (auto &entry : conn->copy_regs) {
        auto &registration = entry.second;
        registration->alive.store(false, std::memory_order_release);
        {
            std::lock_guard<std::mutex> lock(conn->db->copy_mutex);
            auto found = conn->db->copy_slots.find(entry.first);
            if (found != conn->db->copy_slots.end()) {
                std::lock_guard<std::mutex> slot_lock(found->second->mutex);
                auto own = found->second->entries.find(connection_id);
                if (own != found->second->entries.end() && own->second == registration) {
                    found->second->entries.erase(own);
                }
            }
        }
        if (registration->function) {
            OBJ_RELEASE(registration->function);
            registration->function = nullptr;
        }
    }
    conn->copy_regs.clear();
    conn->copy_registrations.store(0, std::memory_order_release);
}

/* ------------------------------------------------------------------ */
/* Method boundary                                                    */
/* ------------------------------------------------------------------ */

void duckdb_copy_prepare_begin() {
    prepare_used_format = false;
}

bool duckdb_copy_prepare_used_format() {
    return prepare_used_format;
}

duckdb_copy_native_bind_scope::duckdb_copy_native_bind_scope() {
    native_bind = true;
}

duckdb_copy_native_bind_scope::~duckdb_copy_native_bind_scope() {
    native_bind = false;
}

static void duckdb_copy_chain_cause() {
    if (!pending_cause) {
        return;
    }
    if (EG(exception) && !pending_exit) {
        zend_exception_set_previous(EG(exception), pending_cause);
    } else {
        OBJ_RELEASE(pending_cause);
    }
    pending_cause = nullptr;
}

bool duckdb_copy_unwinding() {
    return pending_exit || pending_bailout;
}

void duckdb_copy_after_method() {
    if (handler_depth > 0) {
        return;
    }
    duckdb_drain_deferred_connections();

    if (pending_bailout) {
        pending_bailout = false;
        if (pending_cause) {
            OBJ_RELEASE(pending_cause);
            pending_cause = nullptr;
        }
        /* The fatal error was already reported; drop the COPY failure. */
        if (EG(exception)) {
            zend_clear_exception();
        }
        zend_bailout();
    }
    if (pending_exit) {
        zend_object *exit_object = pending_exit;
        pending_exit = nullptr;
        if (pending_cause) {
            OBJ_RELEASE(pending_cause);
            pending_cause = nullptr;
        }
        if (EG(exception)) {
            zend_clear_exception();
        }
        bool graceful = zend_is_graceful_exit(exit_object);
        OBJ_RELEASE(exit_object);
        if (graceful) {
            zend_throw_graceful_exit();
        } else {
            zend_throw_unwind_exit();
        }
        return;
    }
    duckdb_copy_chain_cause();
}

void duckdb_copy_after_destructor() {
    if (handler_depth == 0 && pending_cause) {
        OBJ_RELEASE(pending_cause);
        pending_cause = nullptr;
    }
    duckdb_copy_after_method();
}
