/* PHP scalar functions. Native DuckDB handles contain tokens, never zvals.
 * The owning connection exposes and releases every PHP reference on its
 * request thread, including state retired by DuckDB worker threads. */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"
#include "php_duckdb.h"
#include "scalar.h"
#include "copy.h"
#include "copy_session.h"
#include "data_chunk.h"
#include "vector.h"
#include "expression.h"

extern "C" {
#include "zend_exceptions.h"
}

#include <algorithm>
#include <cstring>

zend_class_entry *duckdb_scalar_function_ce;
zend_class_entry *duckdb_scalar_expression_function_ce;
zend_class_entry *duckdb_scalar_binding_ce;
zend_class_entry *duckdb_scalar_executor_ce;

const char duckdb_scalar_not_pumped_message[] =
    "PHP scalar functions run only through query(), execute(), prepared statements and queryPending(), "
    "not queryAsync() or executeAsync()";

static thread_local bool scalar_prepared = false;
static thread_local bool scalar_reaping = false;
static thread_local std::vector<std::weak_ptr<conn_inner>> scalar_connections;

struct scalar_token {
    uint64_t id;
    std::atomic<bool> alive{true};
};

struct scalar_registry {
    struct entry {
        std::shared_ptr<scalar_token> token;
        zend_object *object = nullptr;
    };
    uint64_t next = 0;
    std::unordered_map<uint64_t, entry> objects;
    std::shared_ptr<conn_inner> conversion;
};

struct scalar_slot {
    std::string name;
    std::vector<std::string> arguments;
    std::string result;
    std::weak_ptr<db_inner> db;
    std::mutex mutex;
    std::unordered_map<idx_t, std::shared_ptr<scalar_registration>> entries;
};

struct scalar_bind {
    std::shared_ptr<scalar_registration> registration;
    std::shared_ptr<scalar_token> binding;
    std::shared_ptr<scalar_slot> slot;
    std::vector<std::string> arguments;
};

struct scalar_bind_arguments {
    conn_inner *conn;
    zval values;
    std::vector<std::shared_ptr<expression_state>> states;

    explicit scalar_bind_arguments(conn_inner *owner) : conn(owner) {
        array_init(&values);
    }

    ~scalar_bind_arguments() {
        release();
    }

    void release() {
        for (auto &state : states) {
            state->invalidate();
        }
        states.clear();
        if (!Z_ISUNDEF(values)) {
            duckdb_callback_zval_release(conn, &values);
        }
    }
};

struct scalar_local {
    std::shared_ptr<scalar_registration> registration;
    std::shared_ptr<scalar_token> executor;
    std::weak_ptr<copy_session> session;
};

void duckdb_register_scalar_interfaces(zend_class_entry *function_ce, zend_class_entry *expression_function_ce,
                                      zend_class_entry *binding_ce, zend_class_entry *executor_ce) {
    duckdb_scalar_function_ce = function_ce;
    duckdb_scalar_expression_function_ce = expression_function_ce;
    duckdb_scalar_binding_ce = binding_ce;
    duckdb_scalar_executor_ce = executor_ce;
}

static std::shared_ptr<scalar_token> store_object(scalar_registry &registry, zend_object *object) {
    auto token = std::make_shared<scalar_token>();
    token->id = ++registry.next;
    GC_ADDREF(object);
    registry.objects.emplace(token->id, scalar_registry::entry{token, object});
    return token;
}

static zend_object *lookup_object(conn_inner &conn, const std::shared_ptr<scalar_token> &token) {
    if (!conn.scalar_state || !token || !token->alive.load(std::memory_order_acquire)) {
        return nullptr;
    }
    auto found = conn.scalar_state->objects.find(token->id);
    return found == conn.scalar_state->objects.end() ? nullptr : found->second.object;
}

/* Called only after DuckDB has returned to the request thread. */
static void reap_objects(conn_inner &conn) {
    if (!conn.scalar_state || duckdb_copy_unwinding()) {
        return;
    }
    std::vector<zend_object *> retired;
    for (auto it = conn.scalar_state->objects.begin(); it != conn.scalar_state->objects.end();) {
        if (it->second.token.use_count() == 1) {
            it->second.token->alive.store(false, std::memory_order_release);
            retired.push_back(it->second.object);
            it = conn.scalar_state->objects.erase(it);
        } else {
            ++it;
        }
    }
    for (auto *object : retired) {
        duckdb_callback_release(&conn, object);
    }
}

static std::string stale(const scalar_registration &registration) {
    return "Scalar function '" + registration.name +
        "' was registered again or released after this statement was prepared; prepare it again";
}

static bool invoke(conn_inner &conn, zend_object *object, const std::string &name, const char *method,
                   uint32_t argc, zval *argv, zval *retval, std::string &error) {
    if (duckdb_copy_unwinding()) {
        ZVAL_UNDEF(retval);
        error = "Scalar function callback stopped by exit() or a fatal error";
        return false;
    }
    std::string message;
    zend_object *cause = nullptr;
    const char *lookup = strcmp(method, "bindExpressions") == 0 ? "bindexpressions" : method;
    auto outcome = duckdb_callback_call(&conn, object, lookup, argc, argv, retval, message, &cause,
                                        "Scalar function");
    if (outcome == duckdb_callback_outcome::ok) {
        if (!conn.scalar_state) {
            error = "Scalar function registration was released during its callback";
            return false;
        }
        return true;
    }
    duckdb_callback_keep_cause(cause);
    error = "Scalar function '" + name + "' " + method + "(): " + message;
    return false;
}

static bool capture_exception(std::string &error) {
    if (!EG(exception)) {
        return false;
    }
    zend_object *cause = EG(exception);
    GC_ADDREF(cause);
    zend_clear_exception();
    duckdb_callback_keep_cause(cause);
    error = "Scalar function result could not be converted to its declared return type";
    return true;
}

static bool resolve_type(conn_inner &conn, const std::string &declaration, scoped_duckdb_value &value) {
    zval wrapper;
    zval input;
    ZVAL_NULL(&input);
    object_init_ex(&wrapper, duckdb_value_class_entry());
    std::vector<scoped_duckdb_value> values;
    bool ok = duckdb_value_initialize(&wrapper, declaration, &input) &&
        duckdb_convert_values(&conn, {&wrapper}, values);
    duckdb_callback_zval_release(&conn, &wrapper);
    if (ok) {
        value = std::move(values.front());
    }
    return ok;
}

static void scalar_bind_callback(duckdb_bind_info info) {
    try {
        auto slot = *static_cast<std::shared_ptr<scalar_slot> *>(duckdb_scalar_function_bind_get_extra_info(info));
        duckdb_client_context context = nullptr;
        duckdb_scalar_function_get_client_context(info, &context);
        idx_t id = duckdb_context_connection_id(context);
        std::shared_ptr<scalar_registration> registration;
        {
            std::lock_guard<std::mutex> lock(slot->mutex);
            auto found = slot->entries.find(id);
            if (found != slot->entries.end()) {
                registration = found->second;
            }
        }
        if (!registration || !registration->alive.load(std::memory_order_acquire)) {
            std::string error = "Scalar function '" + slot->name + "' is not registered on this connection";
            duckdb_scalar_function_bind_set_error(info, error.c_str());
            return;
        }
        if (registration->owner != std::this_thread::get_id()) {
            duckdb_scalar_function_bind_set_error(info, duckdb_scalar_not_pumped_message);
            return;
        }
        if (duckdb_callback_active()) {
            duckdb_scalar_function_bind_set_error(info, "PHP scalar functions cannot run inside another PHP callback");
            return;
        }
        auto conn = registration->conn.lock();
        zend_object *function = conn ? lookup_object(*conn, registration->function) : nullptr;
        if (!function) {
            duckdb_scalar_function_bind_set_error(info, "Scalar function registration was released");
            return;
        }
        auto bind = std::make_unique<scalar_bind>();
        bind->registration = registration;
        bind->slot = slot;
        bool with_expressions = instanceof_function(function->ce, duckdb_scalar_expression_function_ce);
        scalar_bind_arguments arguments(conn.get());
        idx_t count = duckdb_scalar_function_bind_get_argument_count(info);
        arguments.states.reserve(count);
        for (idx_t i = 0; i < count; i++) {
            duckdb_scoped<duckdb_expression, duckdb_destroy_expression> expression(
                duckdb_scalar_function_bind_get_argument(info, i));
            if (!expression) {
                duckdb_scalar_function_bind_set_error(info, "Unable to inspect scalar argument type");
                return;
            }
            scoped_duckdb_logical_type type(duckdb_expression_return_type(expression.get()));
            std::string declaration = duckdb_logical_type_sql(type.get());
            bind->arguments.push_back(declaration);
            if (with_expressions) {
                duckdb_client_context fold_context = nullptr;
                duckdb_scalar_function_get_client_context(info, &fold_context);
                zval argument;
                ZVAL_UNDEF(&argument);
                auto state = duckdb_expression_wrap(&argument, expression.release(), fold_context);
                if (!state) {
                    if (!Z_ISUNDEF(argument)) {
                        duckdb_callback_zval_release(conn.get(), &argument);
                    }
                    std::string error = "Unable to expose scalar argument expression";
                    capture_exception(error);
                    duckdb_scalar_function_bind_set_error(info, error.c_str());
                    return;
                }
                arguments.states.push_back(std::move(state));
                add_next_index_zval(&arguments.values, &argument);
            } else {
                add_next_index_stringl(&arguments.values, declaration.data(), declaration.size());
            }
        }
        scalar_prepared = true;
        zval retval;
        std::string error;
        const char *method = with_expressions ? "bindExpressions" : "bind";
        bool ok = invoke(*conn, function, registration->name, method, 1, &arguments.values, &retval, error);
        arguments.release();
        if (ok && (Z_TYPE(retval) != IS_OBJECT || !instanceof_function(Z_OBJCE(retval), duckdb_scalar_binding_ce))) {
            error = with_expressions
                ? "ScalarExpressionFunction::bindExpressions() must return a DuckDB\\ScalarBinding"
                : "ScalarFunction::bind() must return a DuckDB\\ScalarBinding";
            ok = false;
        }
        if (ok) {
            bind->binding = store_object(*conn->scalar_state, Z_OBJ(retval));
            /* A handler may issue a plain query on another connection,
             * which resets prepare bookkeeping on the same PHP thread. */
            scalar_prepared = true;
        }
        duckdb_callback_zval_release(conn.get(), &retval);
        if (capture_exception(error) || duckdb_copy_unwinding()) {
            if (error.empty()) {
                error = "Scalar function callback stopped by exit() or a fatal error";
            }
            ok = false;
        }
        if (!ok) {
            duckdb_scalar_function_bind_set_error(info, error.c_str());
            return;
        }
        duckdb_scalar_function_set_bind_data(info, bind.release(), [](void *data) {
            delete static_cast<scalar_bind *>(data);
        });
        duckdb_scalar_function_set_bind_data_copy(info, [](void *data) -> void * {
            return new scalar_bind(*static_cast<scalar_bind *>(data));
        });
    } catch (const std::exception &error) {
        duckdb_scalar_function_bind_set_error(info, error.what());
    }
}

static void scalar_init_callback(duckdb_init_info info) {
    try {
        auto *bind = static_cast<scalar_bind *>(duckdb_scalar_function_init_get_bind_data(info));
        if (!bind || !bind->registration->alive.load(std::memory_order_acquire)) {
            std::string error = bind ? stale(*bind->registration) : "Scalar bind state is unavailable";
            duckdb_scalar_function_init_set_error(info, error.c_str());
            return;
        }
        duckdb_client_context context = nullptr;
        duckdb_scalar_function_init_get_client_context(info, &context);
        idx_t id = duckdb_context_connection_id(context);
        auto database = bind->slot->db.lock();
        auto session = database ? duckdb_session_find(*database, id) : nullptr;
        if (!session) {
            duckdb_scalar_function_init_set_error(info, duckdb_scalar_not_pumped_message);
            return;
        }
        auto local = std::make_unique<scalar_local>();
        local->registration = bind->registration;
        local->session = session;
        std::string error;
        bool ok = duckdb_callback_on_request_thread(*session, [&](std::string &message) {
            if (!bind->registration->alive.load(std::memory_order_acquire)) {
                message = stale(*bind->registration);
                return false;
            }
            auto conn = bind->registration->conn.lock();
            zend_object *binding = conn ? lookup_object(*conn, bind->binding) : nullptr;
            if (!binding) {
                message = "Scalar binding was released";
                return false;
            }
            zval retval;
            bool success = invoke(*conn, binding, bind->registration->name, "init", 0, nullptr, &retval, message);
            if (success && (Z_TYPE(retval) != IS_OBJECT || !instanceof_function(Z_OBJCE(retval), duckdb_scalar_executor_ce))) {
                message = "ScalarBinding::init() must return a DuckDB\\ScalarExecutor";
                success = false;
            }
            if (success) {
                local->executor = store_object(*conn->scalar_state, Z_OBJ(retval));
            }
            duckdb_callback_zval_release(conn.get(), &retval);
            if (capture_exception(message) || duckdb_copy_unwinding()) {
                if (message.empty()) {
                    message = "Scalar function callback stopped by exit() or a fatal error";
                }
                success = false;
            }
            return success;
        }, error);
        if (!ok) {
            session->close(error);
            duckdb_scalar_function_init_set_error(info, error.c_str());
            return;
        }
        duckdb_scalar_function_init_set_state(info, local.release(), [](void *data) {
            delete static_cast<scalar_local *>(data);
        });
    } catch (const std::exception &error) {
        duckdb_scalar_function_init_set_error(info, error.what());
    }
}

static bool write_results(conn_inner &conn, const scalar_bind &bind, zval *results,
                          duckdb_vector output, idx_t rows, std::string &error) {
    if (Z_TYPE_P(results) != IS_ARRAY || !zend_array_is_list(Z_ARRVAL_P(results)) ||
        zend_hash_num_elements(Z_ARRVAL_P(results)) != rows) {
        error = "ScalarExecutor::execute() must return a list containing exactly one value per input row";
        return false;
    }
    std::vector<zval> wrappers(rows);
    std::vector<zval *> inputs;
    zval *value;
    bool ok = true;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(results), value) {
        zval *wrapper = &wrappers[inputs.size()];
        object_init_ex(wrapper, duckdb_value_class_entry());
        inputs.push_back(wrapper);
        if (!duckdb_value_initialize(wrapper, bind.slot->result, value)) {
            ok = false;
            break;
        }
    } ZEND_HASH_FOREACH_END();
    std::vector<scoped_duckdb_value> converted;
    if (ok && !inputs.empty()) {
        ok = duckdb_convert_values(conn.scalar_state->conversion.get(), inputs, converted);
    }
    for (auto *wrapper : inputs) {
        duckdb_callback_zval_release(&conn, wrapper);
    }
    if (!ok) {
        capture_exception(error);
        if (error.empty()) {
            error = "Scalar function result conversion failed";
        }
        return false;
    }
    scoped_duckdb_logical_type target_type(duckdb_vector_get_column_type(output));
    duckdb_scoped<duckdb_vector, duckdb_destroy_vector> constant(duckdb_create_vector(target_type.get(), 1));
    scoped_duckdb_selection selection(duckdb_create_selection_vector(1));
    if (!constant || !selection) {
        throw std::bad_alloc();
    }
    duckdb_selection_vector_get_data_ptr(selection.get())[0] = 0;
    for (idx_t row = 0; row < rows; row++) {
        if (!duckdb_vector_types_equal(duckdb_get_value_type(converted[row].get()), target_type.get())) {
            error = "Scalar result conversion produced a different logical type";
            return false;
        }
        duckdb_vector_reference_value(constant.get(), converted[row].get());
        duckdb_vector_copy_sel(constant.get(), output, selection.get(), 1, 0, row);
    }
    return true;
}

static void scalar_execute_callback(duckdb_function_info info, duckdb_data_chunk input, duckdb_vector output) {
    try {
        auto *bind = static_cast<scalar_bind *>(duckdb_scalar_function_get_bind_data(info));
        auto *local = static_cast<scalar_local *>(duckdb_scalar_function_get_state(info));
        auto session = local ? local->session.lock() : nullptr;
        if (!bind || !local || !session) {
            duckdb_scalar_function_set_error(info, duckdb_scalar_not_pumped_message);
            return;
        }
        std::string error;
        bool ok = duckdb_callback_on_request_thread(*session, [&](std::string &message) {
            if (!bind->registration->alive.load(std::memory_order_acquire)) {
                message = stale(*bind->registration);
                return false;
            }
            auto conn = bind->registration->conn.lock();
            zend_object *executor = conn ? lookup_object(*conn, local->executor) : nullptr;
            if (!executor) {
                message = "Scalar executor was released";
                return false;
            }
            auto batch = std::make_shared<data_chunk_data>();
            batch->chunk = input;
            batch->borrowed = true;
            for (size_t i = 0; i < bind->arguments.size(); i++) {
                batch->names.push_back("arg" + std::to_string(i));
            }
            zval argument;
            duckdb_data_chunk_wrap(&argument, batch);
            zval retval;
            bool success = invoke(*conn, executor, bind->registration->name, "execute", 1, &argument, &retval, message);
            batch->expired = true;
            batch->chunk = nullptr;
            duckdb_callback_zval_release(conn.get(), &argument);
            if (success) {
                success = write_results(*conn, *bind, &retval, output, duckdb_data_chunk_get_size(input), message);
            }
            duckdb_callback_zval_release(conn.get(), &retval);
            if (capture_exception(message) || duckdb_copy_unwinding()) {
                if (message.empty()) {
                    message = "Scalar function callback stopped by exit() or a fatal error";
                }
                success = false;
            }
            return success;
        }, error);
        if (!ok) {
            session->close(error);
            duckdb_scalar_function_set_error(info, error.c_str());
        }
    } catch (const std::exception &error) {
        duckdb_scalar_function_set_error(info, error.what());
    }
}

static bool catalog_contains(conn_inner &conn, const std::string &name) {
    duckdb_scoped<duckdb_prepared_statement, duckdb_destroy_prepare> statement;
    if (duckdb_prepare(conn.conn,
        "SELECT 1 FROM duckdb_functions() WHERE lower(function_name) = lower(?) LIMIT 1", statement.out()) != DuckDBSuccess) {
        throw std::runtime_error("Unable to inspect existing scalar functions");
    }
    duckdb_bind_varchar_length(statement.get(), 1, name.data(), name.size());
    duckdb_result result;
    if (duckdb_execute_prepared(statement.get(), &result) != DuckDBSuccess) {
        duckdb_destroy_result(&result);
        throw std::runtime_error("Unable to inspect existing scalar functions");
    }
    bool found = duckdb_row_count(&result) != 0;
    duckdb_destroy_result(&result);
    return found;
}

static std::shared_ptr<scalar_slot> ensure_slot(conn_inner &conn, const std::string &name,
                                               std::vector<scoped_duckdb_value> &types) {
    std::lock_guard<std::mutex> lock(conn.db->scalar_mutex);
    std::vector<std::string> arguments;
    for (size_t i = 0; i + 1 < types.size(); i++) {
        arguments.push_back(duckdb_logical_type_sql(duckdb_get_value_type(types[i].get())));
    }
    std::string result = duckdb_logical_type_sql(duckdb_get_value_type(types.back().get()));
    auto found = conn.db->scalar_slots.find(name);
    if (found != conn.db->scalar_slots.end()) {
        if (found->second->arguments != arguments || found->second->result != result) {
            duckdb_throw_msg("A scalar function name must keep the same parameter and return types on every connection");
            return nullptr;
        }
        return found->second;
    }
    if (catalog_contains(*conn.scalar_state->conversion, name)) {
        duckdb_throw_msg("Scalar function name is already provided by DuckDB or an extension");
        return nullptr;
    }
    auto slot = std::make_shared<scalar_slot>();
    slot->name = name;
    slot->arguments = std::move(arguments);
    slot->result = std::move(result);
    slot->db = conn.db;
    duckdb_scalar_function function = duckdb_create_scalar_function();
    duckdb_scalar_function_set_name(function, name.c_str());
    for (size_t i = 0; i + 1 < types.size(); i++) {
        duckdb_scalar_function_add_parameter(function, duckdb_get_value_type(types[i].get()));
    }
    duckdb_scalar_function_set_return_type(function, duckdb_get_value_type(types.back().get()));
    duckdb_scalar_function_set_volatile(function);
    duckdb_scalar_function_set_special_handling(function);
    duckdb_scalar_function_set_extra_info(function, new std::shared_ptr<scalar_slot>(slot), [](void *data) {
        delete static_cast<std::shared_ptr<scalar_slot> *>(data);
    });
    duckdb_scalar_function_set_bind(function, scalar_bind_callback);
    duckdb_scalar_function_set_init(function, scalar_init_callback);
    duckdb_scalar_function_set_function(function, scalar_execute_callback);
    duckdb_state status = duckdb_register_scalar_function(conn.scalar_state->conversion->conn, function);
    duckdb_destroy_scalar_function(&function);
    if (status != DuckDBSuccess) {
        duckdb_throw_msg("Unable to register scalar function; check its declared parameter and return types");
        return nullptr;
    }
    conn.db->scalar_slots.emplace(name, slot);
    return slot;
}

DUCKDB_COPY_METHOD(DuckDB_Connection, registerScalarFunction) {
#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
    zend_string *name;
    zval *parameters;
    zval *result;
    zval *function;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STR(name)
        Z_PARAM_ARRAY(parameters)
        Z_PARAM_ZVAL(result)
        Z_PARAM_OBJECT(function)
    ZEND_PARSE_PARAMETERS_END();

    if (!instanceof_function(Z_OBJCE_P(function), duckdb_scalar_function_ce) &&
        !instanceof_function(Z_OBJCE_P(function), duckdb_scalar_expression_function_ce)) {
        zend_argument_type_error(4, "must implement DuckDB\\ScalarFunction or DuckDB\\ScalarExpressionFunction");
        RETURN_THROWS();
    }

    auto conn = Z_DUCKDB_CONNECTION_P(ZEND_THIS)->inner;
    if (!duckdb_connection_guard(conn)) {
        RETURN_THROWS();
    }
    if (duckdb_callback_active()) {
        duckdb_throw_msg("Scalar functions cannot be registered inside a PHP callback");
        RETURN_THROWS();
    }
    if (ZSTR_LEN(name) == 0 || memchr(ZSTR_VAL(name), '\0', ZSTR_LEN(name))) {
        zend_argument_value_error(1, "must be non-empty and contain no NUL byte");
        RETURN_THROWS();
    }
    if (!zend_array_is_list(Z_ARRVAL_P(parameters))) {
        zend_argument_value_error(2, "must be a list of type declarations");
        RETURN_THROWS();
    }
    std::string lower(ZSTR_VAL(name), ZSTR_LEN(name));
    for (auto &c : lower) {
        if (c >= 'A' && c <= 'Z') {
            c = static_cast<char>(c - 'A' + 'a');
        }
    }
    try {
        std::unique_lock<std::mutex> lock;
        if (!duckdb_conn_enter(*conn, lock)) {
            RETURN_THROWS();
        }
        if (!conn->scalar_state) {
            auto registry = std::make_shared<scalar_registry>();
            registry->conversion = std::make_shared<conn_inner>();
            registry->conversion->db = conn->db;
            if (duckdb_connect(conn->db->db, &registry->conversion->conn) != DuckDBSuccess) {
                duckdb_throw_msg("Unable to open scalar result conversion connection");
                RETURN_THROWS();
            }
            conn->scalar_state = std::move(registry);
            scalar_connections.emplace_back(conn);
        }
        std::vector<scoped_duckdb_value> types;
        zval *parameter;
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(parameters), parameter) {
            std::string declaration;
            scoped_duckdb_value resolved;
            if (!duckdb_type_spec(parameter, declaration) || !resolve_type(*conn, declaration, resolved)) {
                RETURN_THROWS();
            }
            types.push_back(std::move(resolved));
        } ZEND_HASH_FOREACH_END();
        std::string declaration;
        scoped_duckdb_value resolved;
        if (!duckdb_type_spec(result, declaration) || !resolve_type(*conn, declaration, resolved)) {
            RETURN_THROWS();
        }
        types.push_back(std::move(resolved));
        auto slot = ensure_slot(*conn, lower, types);
        if (!slot) {
            RETURN_THROWS();
        }
        auto registration = std::make_shared<scalar_registration>();
        registration->name.assign(ZSTR_VAL(name), ZSTR_LEN(name));
        registration->owner = std::this_thread::get_id();
        registration->conn = conn;
        registration->function = store_object(*conn->scalar_state, Z_OBJ_P(function));
        idx_t id = duckdb_conn_connection_id(*conn);
        {
            std::lock_guard<std::mutex> slot_lock(slot->mutex);
            slot->entries[id] = registration;
        }
        auto previous = conn->scalar_regs.find(lower);
        if (previous != conn->scalar_regs.end()) {
            previous->second->alive.store(false, std::memory_order_release);
            previous->second = registration;
        } else {
            conn->scalar_regs.emplace(lower, registration);
            conn->scalar_registrations.fetch_add(1, std::memory_order_acq_rel);
        }
        lock.unlock();
        reap_objects(*conn);
    } catch (const std::exception &error) {
        duckdb_throw_msg(error.what());
        RETURN_THROWS();
    }
}

void duckdb_scalar_connection_gc(conn_inner *conn, zend_get_gc_buffer *buffer) {
    if (!conn || !conn->scalar_state) {
        return;
    }
    for (auto &entry : conn->scalar_state->objects) {
        zend_get_gc_buffer_add_obj(buffer, entry.second.object);
    }
}

void duckdb_scalar_connection_free(conn_inner *conn) {
    if (!conn) {
        return;
    }
    for (auto &entry : conn->scalar_regs) {
        entry.second->alive.store(false, std::memory_order_release);
        std::lock_guard<std::mutex> lock(conn->db->scalar_mutex);
        auto slot = conn->db->scalar_slots.find(entry.first);
        if (slot != conn->db->scalar_slots.end()) {
            std::lock_guard<std::mutex> slot_lock(slot->second->mutex);
            auto found = slot->second->entries.find(conn->connection_id);
            if (found != slot->second->entries.end() && found->second == entry.second) {
                slot->second->entries.erase(found);
            }
        }
    }
    conn->scalar_regs.clear();
    conn->scalar_registrations.store(0, std::memory_order_release);
    auto registry = std::move(conn->scalar_state);
    if (registry) {
        std::vector<zend_object *> objects;
        for (auto &entry : registry->objects) {
            entry.second.token->alive.store(false, std::memory_order_release);
            objects.push_back(entry.second.object);
        }
        registry->objects.clear();
        for (auto *object : objects) {
            duckdb_callback_release(conn, object);
        }
    }
}

void duckdb_scalar_session_attach(conn_inner &conn, copy_session &session) {
    auto finish = std::move(session.finish);
    session.finish = [&conn, finish](const std::string &failure, bool can_call_php) {
        if (finish) {
            finish(failure, can_call_php);
        }
        if (can_call_php) {
            reap_objects(conn);
        }
    };
}

void duckdb_scalar_prepare_begin() {
    scalar_prepared = false;
}

bool duckdb_scalar_prepare_used_function() {
    return scalar_prepared;
}

void duckdb_scalar_after_method() {
    if (scalar_reaping || duckdb_copy_unwinding()) {
        return;
    }
    scalar_reaping = true;
    /* Destructors may execute queries or register another connection.
     * Scan a snapshot so those operations cannot invalidate iterators. */
    auto connections = scalar_connections;
    scalar_connections.erase(std::remove_if(scalar_connections.begin(), scalar_connections.end(),
        [](const std::weak_ptr<conn_inner> &conn) {
            return conn.expired();
        }), scalar_connections.end());
    for (auto &connection : connections) {
        auto conn = connection.lock();
        if (conn) {
            reap_objects(*conn);
        }
    }
    scalar_reaping = false;
}
