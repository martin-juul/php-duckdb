/* Owned scalar argument expressions, valid during their PHP bind callback. */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"
extern "C" {
#include "ext/json/php_json.h"
}
#include "php_duckdb.h"
#include "expression.h"
#include "selection.h"

#include <exception>

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

zend_class_entry *duckdb_expression_ce;
static zend_object_handlers expression_handlers;

struct php_expression_object {
    std::shared_ptr<expression_state> state;
    zend_object std;
};

static php_expression_object *expression_object(zend_object *object) {
    return reinterpret_cast<php_expression_object *>(reinterpret_cast<char *>(object) -
                                                    offsetof(php_expression_object, std));
}

static zend_object *expression_create(zend_class_entry *ce) {
    auto *object = static_cast<php_expression_object *>(zend_object_alloc(sizeof(php_expression_object), ce));
    new (&object->state) std::shared_ptr<expression_state>();
    zend_object_std_init(&object->std, ce);
    object_properties_init(&object->std, ce);
    object->std.handlers = &expression_handlers;
    return &object->std;
}

static void expression_free(zend_object *object) {
    expression_object(object)->state.~shared_ptr();
    zend_object_std_dtor(object);
}

void duckdb_register_expression_class(zend_class_entry *ce) {
    duckdb_expression_ce = ce;
    ce->create_object = expression_create;
    ce->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;
    memcpy(&expression_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    expression_handlers.offset = offsetof(php_expression_object, std);
    expression_handlers.free_obj = expression_free;
    expression_handlers.clone_obj = nullptr;
}

static void expression_throw_native_error(const char *message) {
    if (!EG(exception)) {
        duckdb_throw_msg(message);
    }
}

/* The fold API can return a serialized DuckDB exception with a generic
 * error code. Recover its category and message before exposing it to PHP. */
static void expression_throw_fold_error(duckdb_error_data error) {
    duckdb_error_type type = duckdb_error_data_error_type(error);
    std::string message = duckdb_error_data_message(error);
    if (!message.empty() && message.front() == '{') {
        zval decoded;
        ZVAL_UNDEF(&decoded);
        if (php_json_decode_ex(&decoded, message.data(), message.size(), PHP_JSON_OBJECT_AS_ARRAY, 512) ==
                SUCCESS &&
            Z_TYPE(decoded) == IS_ARRAY) {
            zval *category = zend_hash_str_find(Z_ARRVAL(decoded), "exception_type", 14);
            zval *detail = zend_hash_str_find(Z_ARRVAL(decoded), "exception_message", 17);
            if (category && detail && Z_TYPE_P(category) == IS_STRING && Z_TYPE_P(detail) == IS_STRING) {
                std::string prefix(Z_STRVAL_P(category), Z_STRLEN_P(category));
                prefix += " Error: ";
                auto classified = duckdb_classify_error_message(prefix.c_str());
                if (classified != DUCKDB_ERROR_INVALID) {
                    type = classified;
                }
                message = prefix + std::string(Z_STRVAL_P(detail), Z_STRLEN_P(detail));
            }
        }
        if (!Z_ISUNDEF(decoded)) {
            zval_ptr_dtor(&decoded);
        }
    }
    duckdb_throw_error(type, message.c_str());
}

std::shared_ptr<expression_state> duckdb_expression_wrap(zval *out, duckdb_expression expression,
                                                        duckdb_client_context context) {
    duckdb_scoped<duckdb_expression, duckdb_destroy_expression> owned_expression(expression);
    duckdb_scoped<duckdb_client_context, duckdb_destroy_client_context> owned_context(context);
    ZVAL_UNDEF(out);
    try {
        if (!owned_expression || !owned_context) {
            duckdb_throw_msg("Unable to inspect scalar argument expression");
            return nullptr;
        }
        auto state = std::make_shared<expression_state>();
        state->expression = std::move(owned_expression);
        state->context = std::move(owned_context);
        if (object_init_ex(out, duckdb_expression_ce) != SUCCESS) {
            return nullptr;
        }
        expression_object(Z_OBJ_P(out))->state = state;
        return state;
    } catch (const std::exception &error) {
        expression_throw_native_error(error.what());
    } catch (...) {
        expression_throw_native_error("Unable to wrap scalar argument expression");
    }
    return nullptr;
}

static std::shared_ptr<expression_state> expression_from_zval(zval *value) {
    auto state = expression_object(Z_OBJ_P(value))->state;
    if (!duckdb_initialized_guard(static_cast<bool>(state), "DuckDB\\Expression")) {
        return nullptr;
    }
    if (!state->expression || !state->context) {
        zend_throw_error(nullptr, "DuckDB\\Expression is only valid during ScalarExpressionFunction::bindExpressions()");
        return nullptr;
    }
    return state;
}

PHP_METHOD(DuckDB_Expression, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    zend_throw_error(nullptr, "DuckDB\\Expression objects are provided by ScalarExpressionFunction::bindExpressions()");
    RETURN_THROWS();
}

PHP_METHOD(DuckDB_Expression, getReturnType) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto state = expression_from_zval(ZEND_THIS);
    if (!state) {
        RETURN_THROWS();
    }
    try {
        scoped_duckdb_logical_type type(duckdb_expression_return_type(state->expression.get()));
        if (!type) {
            duckdb_throw_msg("Unable to inspect scalar argument return type");
            RETURN_THROWS();
        }
        std::string declaration = duckdb_logical_type_sql(type.get());
        RETURN_STRINGL(declaration.data(), declaration.size());
    } catch (const std::exception &error) {
        expression_throw_native_error(error.what());
    } catch (...) {
        expression_throw_native_error("Unable to inspect scalar argument return type");
    }
    RETURN_THROWS();
}

PHP_METHOD(DuckDB_Expression, isFoldable) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto state = expression_from_zval(ZEND_THIS);
    if (!state) {
        RETURN_THROWS();
    }
    RETURN_BOOL(duckdb_expression_is_foldable(state->expression.get()));
}

PHP_METHOD(DuckDB_Expression, fold) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto state = expression_from_zval(ZEND_THIS);
    if (!state) {
        RETURN_THROWS();
    }
    try {
        if (!duckdb_expression_is_foldable(state->expression.get())) {
            duckdb_throw_error(DUCKDB_ERROR_INVALID_INPUT, "Expression is not foldable");
            RETURN_THROWS();
        }
        scoped_duckdb_value value;
        duckdb_scoped<duckdb_error_data, duckdb_destroy_error_data> error(
            duckdb_expression_fold(state->context.get(), state->expression.get(), value.out()));
        if (duckdb_error_data_has_error(error.get())) {
            expression_throw_fold_error(error.get());
            RETURN_THROWS();
        }
        if (!value) {
            duckdb_throw_msg("Expression folding did not return a value");
            RETURN_THROWS();
        }
        duckdb_logical_type type = duckdb_get_value_type(value.get());
        duckdb_scoped<duckdb_vector, duckdb_destroy_vector> constant(duckdb_create_vector(type, 1));
        duckdb_scoped<duckdb_vector, duckdb_destroy_vector> flat(duckdb_create_vector(type, 1));
        scoped_duckdb_selection selection(duckdb_create_selection_vector(1));
        if (!constant || !flat || !selection) {
            throw std::bad_alloc();
        }
        duckdb_vector_reference_value(constant.get(), value.get());
        duckdb_selection_vector_get_data_ptr(selection.get())[0] = 0;
        duckdb_vector_copy_sel(constant.get(), flat.get(), selection.get(), 1, 0, 0);
        if (!duckdb_decode_vector_value(flat.get(), type, 0, return_value)) {
            RETURN_THROWS();
        }
        return;
    } catch (const std::exception &error) {
        expression_throw_native_error(error.what());
    } catch (...) {
        expression_throw_native_error("Unable to fold scalar argument expression");
    }
    RETURN_THROWS();
}
