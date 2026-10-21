/* PHP scalar callbacks execute only on the request thread. Native bind-data
 * copies share retirement tokens; worker destruction never touches Zend. */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"
#include "php_duckdb.h"
#include "scalar.h"
#include "copy_session.h"
#include "data_chunk.h"
#include "vector.h"

#include <exception>
#include <cstring>

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

zend_class_entry *duckdb_scalar_function_ce;
zend_class_entry *duckdb_scalar_binding_ce;
zend_class_entry *duckdb_scalar_executor_ce;

struct scalar_token;

struct scalar_registration {
    std::string name;
    std::thread::id owner = std::this_thread::get_id();
    std::atomic<bool> alive{true};
    std::weak_ptr<conn_inner> conn;
    std::weak_ptr<db_inner> db;
    idx_t connection_id = 0;
    zend_object *function = nullptr;
    struct state {
        std::weak_ptr<scalar_token> token;
        zend_object *object = nullptr;
    };
    /* Request-thread only, including all PHP reference counts. */
    std::unordered_map<uint64_t, state> states;
    uint64_t next_id = 0;
};

struct scalar_token {
    std::shared_ptr<scalar_registration> registration;
    uint64_t id = 0;
};

struct scalar_execution {
    std::shared_ptr<scalar_token> token;
    std::weak_ptr<copy_session> session;
};

static thread_local std::vector<std::weak_ptr<scalar_registration>> registrations;
static thread_local bool draining = false;

static const char unavailable[] =
    "PHP scalar functions require their registering connection and request thread; use query(), "
    "execute(), prepared statements or queryPending()";

void duckdb_register_scalar_interfaces(zend_class_entry *function_ce, zend_class_entry *binding_ce,
                                       zend_class_entry *executor_ce) {
    duckdb_scalar_function_ce = function_ce;
    duckdb_scalar_binding_ce = binding_ce;
    duckdb_scalar_executor_ce = executor_ce;
}

static std::shared_ptr<scalar_token> keep_state(const std::shared_ptr<scalar_registration> &registration,
                                               zend_object *object) {
    auto token = std::make_shared<scalar_token>();
    token->registration = registration;
    token->id = ++registration->next_id;
    registration->states.emplace(token->id, scalar_registration::state{token, object});
    GC_ADDREF(object);
    return token;
}

static zend_object *state_object(const std::shared_ptr<scalar_token> &token) {
    auto &states = token->registration->states;
    auto found = states.find(token->id);
    return found == states.end() ? nullptr : found->second.object;
}

void duckdb_scalar_drain() {
    if (draining || duckdb_php_handler_active()) {
        return;
    }
    draining = true;
    /* Destructors can register functions and mutate the registry. Take a
     * snapshot before releasing any PHP references. */
    std::vector<std::shared_ptr<scalar_registration>> live;
    for (auto it = registrations.begin(); it != registrations.end();) {
        if (auto registration = it->lock()) {
            live.push_back(std::move(registration));
            ++it;
        } else {
            it = registrations.erase(it);
        }
    }
    std::vector<zend_object *> retired;
    for (auto &registration : live) {
        for (auto it = registration->states.begin(); it != registration->states.end();) {
            if (it->second.token.expired()) {
                retired.push_back(it->second.object);
                it = registration->states.erase(it);
            } else {
                ++it;
            }
        }
    }
    for (auto *object : retired) {
        duckdb_php_release_object(object);
    }
    draining = false;
}

void duckdb_scalar_connection_gc(conn_inner *conn, zend_get_gc_buffer *buffer) {
    if (!conn) {
        return;
    }
    for (auto &entry : conn->scalar_regs) {
        auto &registration = entry.second;
        if (registration->function) {
            zend_get_gc_buffer_add_obj(buffer, registration->function);
        }
        for (auto &state : registration->states) {
            zend_get_gc_buffer_add_obj(buffer, state.second.object);
        }
    }
}

void duckdb_scalar_connection_free(conn_inner *conn) {
    if (!conn) {
        return;
    }
    std::vector<zend_object *> retired;
    for (auto &entry : conn->scalar_regs) {
        auto &registration = entry.second;
        registration->alive.store(false, std::memory_order_release);
        if (registration->function) {
            retired.push_back(registration->function);
            registration->function = nullptr;
        }
        for (auto &state : registration->states) {
            retired.push_back(state.second.object);
        }
        registration->states.clear();
    }
    conn->scalar_regs.clear();
    conn->scalar_registrations.store(0, std::memory_order_release);
    for (auto *object : retired) {
        duckdb_php_release_object(object);
    }
}

static bool call_object(const std::shared_ptr<scalar_registration> &registration, zend_object *object,
                         const char *method, uint32_t argc, zval *argv, zval *retval,
                         zend_class_entry *expected, std::string &error) {
    auto conn = registration->conn.lock();
    ZVAL_UNDEF(retval);
    if (!conn || !object || !registration->alive.load(std::memory_order_acquire) ||
        registration->owner != std::this_thread::get_id()) {
        error = unavailable;
        return false;
    }
    if (!duckdb_php_call_handler(conn.get(), object, method, argc, argv, retval, error)) {
        return false;
    }
    /* A prepared statement owns the native connection, not its PHP object.
     * The callback may have freed that object and retired this registration.
     * Do not publish new PHP state into a registry with no GC owner. */
    if (!registration->alive.load(std::memory_order_acquire)) {
        error = "PHP scalar function connection was released during its callback";
        return false;
    }
    if (Z_TYPE_P(retval) != IS_OBJECT || !instanceof_function(Z_OBJCE_P(retval), expected)) {
        error = "PHP scalar callback returned an invalid object";
        return false;
    }
    return true;
}

struct scalar_zval_guard {
    zval value;

    scalar_zval_guard() {
        ZVAL_UNDEF(&value);
    }

    ~scalar_zval_guard() {
        if (Z_TYPE(value) == IS_OBJECT) {
            duckdb_php_release_object(Z_OBJ(value));
        } else {
            zval_ptr_dtor(&value);
        }
    }
};

static bool decode_constant(duckdb_value value, zval *out) {
    /* duckdb_get_value_type borrows the value's type; never destroy it. */
    duckdb_logical_type type = duckdb_get_value_type(value);
    duckdb_scoped<duckdb_vector, duckdb_destroy_vector> constant(duckdb_create_vector(type, 1));
    duckdb_scoped<duckdb_vector, duckdb_destroy_vector> flat(duckdb_create_vector(type, 1));
    if (!constant || !flat) {
        throw std::bad_alloc();
    }
    duckdb_vector_reference_value(constant.get(), value);
    duckdb_vector_copy_rows(constant.get(), flat.get(), 0, 1, 0);
    return duckdb_decode_vector_value(flat.get(), type, 0, out);
}

static void scalar_bind_body(duckdb_bind_info info) {
    auto registration = *static_cast<std::shared_ptr<scalar_registration> *>(
        duckdb_scalar_function_bind_get_extra_info(info));
    duckdb_client_context raw_context = nullptr;
    duckdb_scalar_function_get_client_context(info, &raw_context);
    duckdb_scoped<duckdb_client_context, duckdb_destroy_client_context> context(raw_context);
    if (!registration->alive.load(std::memory_order_acquire) ||
        registration->owner != std::this_thread::get_id() ||
        duckdb_client_context_get_connection_id(context.get()) != registration->connection_id) {
        duckdb_scalar_function_bind_set_error(info, unavailable);
        return;
    }
    duckdb_php_mark_prepare_callback();
    if (duckdb_php_handler_active()) {
        duckdb_scalar_function_bind_set_error(info, "PHP scalar functions cannot bind inside a PHP callback");
        return;
    }

    zval argv[2];
    array_init(&argv[0]);
    array_init(&argv[1]);
    std::string error;
    try {
        idx_t count = duckdb_scalar_function_bind_get_argument_count(info);
        for (idx_t i = 0; i < count; i++) {
            duckdb_scoped<duckdb_expression, duckdb_destroy_expression> expression(
                duckdb_scalar_function_bind_get_argument(info, i));
            scoped_duckdb_logical_type type(duckdb_expression_return_type(expression.get()));
            std::string declaration = duckdb_logical_type_sql(type.get());
            add_next_index_stringl(&argv[0], declaration.data(), declaration.size());
            if (duckdb_expression_is_foldable(expression.get())) {
                duckdb_value raw_value = nullptr;
                duckdb_scoped<duckdb_error_data, duckdb_destroy_error_data> failure(
                    duckdb_expression_fold(context.get(), expression.get(), &raw_value));
                scoped_duckdb_value value(raw_value);
                if (duckdb_error_data_has_error(failure.get())) {
                    error = duckdb_error_data_message(failure.get());
                    break;
                }
                zval decoded;
                ZVAL_UNDEF(&decoded);
                if (!decode_constant(value.get(), &decoded)) {
                    zval_ptr_dtor(&decoded);
                    error = "Unable to decode a PHP scalar function constant";
                    break;
                }
                add_index_zval(&argv[1], static_cast<zend_ulong>(i), &decoded);
            }
        }
        if (error.empty()) {
            scalar_zval_guard result;
            zval &retval = result.value;
            bool ok = call_object(registration, registration->function, "bind", 2, argv, &retval,
                                   duckdb_scalar_binding_ce, error);
            if (ok) {
                auto token = keep_state(registration, Z_OBJ(retval));
                auto native = std::make_unique<std::shared_ptr<scalar_token>>(std::move(token));
                duckdb_scalar_function_set_bind_data(info, native.release(), [](void *data) {
                    delete static_cast<std::shared_ptr<scalar_token> *>(data);
                });
                duckdb_scalar_function_set_bind_data_copy(info, [](void *data) -> void * {
                    /* Never let C++ exceptions cross DuckDB's C callback. */
                    try {
                        return new std::shared_ptr<scalar_token>(*static_cast<std::shared_ptr<scalar_token> *>(data));
                    } catch (...) {
                        return nullptr;
                    }
                });
            }
        }
    } catch (...) {
        zval_ptr_dtor(&argv[0]);
        zval_ptr_dtor(&argv[1]);
        throw;
    }
    zval_ptr_dtor(&argv[0]);
    zval_ptr_dtor(&argv[1]);
    if (!error.empty()) {
        duckdb_scalar_function_bind_set_error(info, error.c_str());
    }
}

static void scalar_bind_callback(duckdb_bind_info info) {
    try {
        scalar_bind_body(info);
    } catch (const std::exception &error) {
        duckdb_scalar_function_bind_set_error(info, error.what());
    } catch (...) {
        duckdb_scalar_function_bind_set_error(info, "PHP scalar bind callback failed");
    }
}

static bool on_request_thread(copy_session &session, const std::function<bool(std::string &)> &work,
                              std::string &error) {
    if (session.driver == std::this_thread::get_id()) {
        return work(error);
    }
    bool ok = false;
    auto request = std::make_shared<copy_request>();
    request->work = [&]() {
        try {
            ok = work(error);
        } catch (const std::exception &failure) {
            error = failure.what();
        } catch (...) {
            error = "PHP scalar callback failed";
        }
    };
    return session.submit(request, error) && ok;
}

static void scalar_init_body(duckdb_init_info info) {
    auto *native = static_cast<std::shared_ptr<scalar_token> *>(duckdb_scalar_function_init_get_bind_data(info));
    if (!native || !*native) {
        duckdb_scalar_function_init_set_error(info, unavailable);
        return;
    }
    auto binding = *native;
    auto registration = binding->registration;
    duckdb_client_context context = nullptr;
    duckdb_scalar_function_init_get_client_context(info, &context);
    idx_t connection_id = duckdb_context_connection_id(context);
    auto db = registration->db.lock();
    auto session = db ? duckdb_session_find(*db, connection_id) : nullptr;
    if (!registration->alive.load(std::memory_order_acquire) || !session || !session->is_open() ||
        session->driver != registration->owner || connection_id != registration->connection_id) {
        duckdb_scalar_function_init_set_error(info, unavailable);
        return;
    }
    std::unique_ptr<scalar_execution> execution(new scalar_execution());
    execution->session = session;
    std::string error;
    bool ok = on_request_thread(*session, [&](std::string &failure) {
        scalar_zval_guard result;
        zval &retval = result.value;
        bool success = call_object(registration, state_object(binding), "init", 0, nullptr, &retval,
                                    duckdb_scalar_executor_ce, failure);
        if (success) {
            execution->token = keep_state(registration, Z_OBJ(retval));
        }
        return success;
    }, error);
    if (!ok) {
        if (error.empty()) {
            error = unavailable;
        }
        session->close(error);
        duckdb_scalar_function_init_set_error(info, error.c_str());
        return;
    }
    duckdb_scalar_function_init_set_state(info, execution.release(), [](void *data) {
        delete static_cast<scalar_execution *>(data);
    });
}

static void close_init_session(duckdb_init_info info, const char *error) {
    auto *native = static_cast<std::shared_ptr<scalar_token> *>(duckdb_scalar_function_init_get_bind_data(info));
    if (!native || !*native) {
        return;
    }
    auto &registration = (*native)->registration;
    if (auto db = registration->db.lock()) {
        if (auto session = duckdb_session_find(*db, registration->connection_id)) {
            session->close(error);
        }
    }
}

static void scalar_init_callback(duckdb_init_info info) {
    try {
        scalar_init_body(info);
    } catch (const std::exception &error) {
        close_init_session(info, error.what());
        duckdb_scalar_function_init_set_error(info, error.what());
    } catch (...) {
        close_init_session(info, "PHP scalar init callback failed");
        duckdb_scalar_function_init_set_error(info, "PHP scalar init callback failed");
    }
}

static bool execute_php(scalar_execution &execution, duckdb_data_chunk input, duckdb_vector output,
                         std::string &error) {
    auto borrowed = std::make_shared<data_chunk_data>();
    borrowed->chunk = input;
    borrowed->borrowed = true;
    idx_t columns = duckdb_data_chunk_get_column_count(input);
    for (idx_t i = 0; i < columns; i++) {
        borrowed->names.push_back("col" + std::to_string(i));
    }
    zval argv;
    duckdb_data_chunk_wrap(&argv, borrowed);
    zval retval;
    ZVAL_UNDEF(&retval);
    bool ok = false;
    try {
        auto registration = execution.token->registration;
        ok = call_object(registration, state_object(execution.token), "execute", 1, &argv, &retval,
                          duckdb_vector_ce, error);
        borrowed->expired = true;
        borrowed->chunk = nullptr;
        if (ok) {
            auto vector = duckdb_vector_from_zval(&retval);
            scoped_duckdb_logical_type type(duckdb_vector_get_column_type(output));
            idx_t rows = duckdb_data_chunk_get_size(input);
            if (!vector || vector->capacity < rows || !duckdb_vector_types_equal(vector->type.get(), type.get())) {
                error = "PHP scalar executor must return a Vector with the declared return type and capacity for every input row";
                ok = false;
            } else {
                duckdb_vector_copy_rows(vector->vector, output, 0, rows, 0);
            }
        }
    } catch (...) {
        borrowed->expired = true;
        borrowed->chunk = nullptr;
        zval_ptr_dtor(&retval);
        zval_ptr_dtor(&argv);
        throw;
    }
    zval_ptr_dtor(&retval);
    zval_ptr_dtor(&argv);
    return ok;
}

static void scalar_execute_callback(duckdb_function_info info, duckdb_data_chunk input, duckdb_vector output) {
    auto *execution = static_cast<scalar_execution *>(duckdb_scalar_function_get_state(info));
    auto session = execution ? execution->session.lock() : nullptr;
    try {
        if (!session || !session->is_open()) {
            duckdb_scalar_function_set_error(info, unavailable);
            return;
        }
        std::string error;
        bool ok = on_request_thread(*session, [&](std::string &failure) {
            return execute_php(*execution, input, output, failure);
        }, error);
        if (!ok) {
            if (error.empty()) {
                error = unavailable;
            }
            session->close(error);
            duckdb_scalar_function_set_error(info, error.c_str());
        }
    } catch (const std::exception &error) {
        if (session) {
            session->close(error.what());
        }
        duckdb_scalar_function_set_error(info, error.what());
    } catch (...) {
        if (session) {
            session->close("PHP scalar execution callback failed");
        }
        duckdb_scalar_function_set_error(info, "PHP scalar execution callback failed");
    }
}

static duckdb_logical_type resolve_type(conn_inner &conn, zval *spec) {
    std::string declaration;
    if (!duckdb_type_spec(spec, declaration)) {
        return nullptr;
    }
    zval wrapper;
    zval null;
    object_init_ex(&wrapper, duckdb_value_class_entry());
    ZVAL_NULL(&null);
    std::vector<scoped_duckdb_value> values;
    bool ok = duckdb_value_initialize(&wrapper, declaration, &null) &&
        duckdb_convert_values(&conn, {&wrapper}, values);
    zval_ptr_dtor(&wrapper);
    if (!ok) {
        return nullptr;
    }
    /* Copy the borrowed value type through a vector. The column-type getter
     * returns an owned type that outlives the converted NULL value. */
    duckdb_scoped<duckdb_vector, duckdb_destroy_vector> vector(
        duckdb_create_vector(duckdb_get_value_type(values.front().get()), 1));
    if (!vector) {
        throw std::bad_alloc();
    }
    return duckdb_vector_get_column_type(vector.get());
}

PHP_METHOD(DuckDB_Connection, registerScalarFunction) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_string *name;
    HashTable *parameters;
    zval *return_type;
    zval *function;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STR(name)
        Z_PARAM_ARRAY_HT(parameters)
        Z_PARAM_ZVAL(return_type)
        Z_PARAM_OBJECT_OF_CLASS(function, duckdb_scalar_function_ce)
    ZEND_PARSE_PARAMETERS_END();

    auto conn = Z_DUCKDB_CONNECTION_P(ZEND_THIS)->inner;
    if (!duckdb_connection_guard(conn)) {
        RETURN_THROWS();
    }
    if (duckdb_php_handler_active()) {
        duckdb_throw_msg("Scalar functions cannot be registered inside a PHP callback");
        RETURN_THROWS();
    }
    if (ZSTR_LEN(name) == 0 || memchr(ZSTR_VAL(name), '\0', ZSTR_LEN(name))) {
        zend_argument_value_error(1, "must be non-empty and contain no NUL byte");
        RETURN_THROWS();
    }
    std::string lower(ZSTR_VAL(name), ZSTR_LEN(name));
    for (char &c : lower) {
        if (c >= 'A' && c <= 'Z') {
            c = static_cast<char>(c - 'A' + 'a');
        }
    }
    if (conn->scalar_regs.find(lower) != conn->scalar_regs.end()) {
        zend_argument_value_error(1, "is already registered on this connection");
        RETURN_THROWS();
    }
    try {
        std::unique_lock<std::mutex> lock;
        if (!duckdb_conn_enter(*conn, lock)) {
            RETURN_THROWS();
        }
        scoped_duckdb_logical_type result(resolve_type(*conn, return_type));
        if (!result) {
            RETURN_THROWS();
        }
        std::vector<scoped_duckdb_logical_type> types;
        zval *parameter;
        ZEND_HASH_FOREACH_VAL(parameters, parameter) {
            scoped_duckdb_logical_type type(resolve_type(*conn, parameter));
            if (!type) {
                RETURN_THROWS();
            }
            types.push_back(std::move(type));
        } ZEND_HASH_FOREACH_END();

        auto registration = std::make_shared<scalar_registration>();
        registration->name = lower;
        registration->conn = conn;
        registration->db = conn->db;
        registration->connection_id = duckdb_conn_connection_id(*conn);
        duckdb_scoped<duckdb_scalar_function, duckdb_destroy_scalar_function> native(duckdb_create_scalar_function());
        duckdb_scalar_function_set_name(native.get(), lower.c_str());
        for (auto &type : types) {
            duckdb_scalar_function_add_parameter(native.get(), type.get());
        }
        duckdb_scalar_function_set_return_type(native.get(), result.get());
        duckdb_scalar_function_set_special_handling(native.get());
        duckdb_scalar_function_set_volatile(native.get());
        duckdb_scalar_function_set_bind(native.get(), scalar_bind_callback);
        duckdb_scalar_function_set_init(native.get(), scalar_init_callback);
        duckdb_scalar_function_set_function(native.get(), scalar_execute_callback);
        auto extra = std::make_unique<std::shared_ptr<scalar_registration>>(registration);
        duckdb_scalar_function_set_extra_info(native.get(), extra.release(), [](void *data) {
            delete static_cast<std::shared_ptr<scalar_registration> *>(data);
        });
        /* DuckDB rejects an identical registered signature. Reject every
         * existing function name as well: public registration has no overload
         * or replacement semantics. Query catalog through a separate statement
         * on the registering connection, preserving transaction visibility. */
        scoped_duckdb_prepared lookup;
        if (duckdb_prepare(conn->conn, "SELECT count(*) FROM duckdb_functions() WHERE lower(function_name) = ?", lookup.out()) == DuckDBError ||
            duckdb_bind_varchar(lookup.get(), 1, lower.c_str()) == DuckDBError) {
            duckdb_throw_msg("Could not inspect scalar function name");
            RETURN_THROWS();
        }
        duckdb_result existing = {};
        if (duckdb_execute_prepared(lookup.get(), &existing) == DuckDBError) {
            duckdb_destroy_result(&existing);
            duckdb_throw_msg("Could not inspect scalar function name");
            RETURN_THROWS();
        }
        bool occupied = duckdb_value_int64(&existing, 0, 0) != 0;
        duckdb_destroy_result(&existing);
        if (occupied) {
            zend_argument_value_error(1, "already names an existing DuckDB function");
            RETURN_THROWS();
        }
        /* Install PHP ownership before catalog publication; rollback on error. */
        registrations.push_back(registration);
        conn->scalar_regs.emplace(lower, registration);
        if (duckdb_register_scalar_function(conn->conn, native.get()) == DuckDBError) {
            conn->scalar_regs.erase(lower);
            duckdb_throw_msg("DuckDB could not register the scalar function");
            RETURN_THROWS();
        }
        registration->function = Z_OBJ_P(function);
        GC_ADDREF(registration->function);
        conn->scalar_registrations.fetch_add(1, std::memory_order_acq_rel);
    } catch (const std::exception &error) {
        duckdb_throw_msg(error.what());
        RETURN_THROWS();
    }
}
