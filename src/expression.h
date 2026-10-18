#ifndef PHP_DUCKDB_EXPRESSION_H
#define PHP_DUCKDB_EXPRESSION_H

#include "php_duckdb.h"

/* Bound argument handles are owned here, but their client context may only
 * be used while ScalarExpressionFunction::bindExpressions() is running. Escaped PHP objects keep
 * this state after invalidate() has released both handles. */
struct expression_state {
    duckdb_scoped<duckdb_expression, duckdb_destroy_expression> expression;
    duckdb_scoped<duckdb_client_context, duckdb_destroy_client_context> context;

    ~expression_state() {
        invalidate();
    }

    void invalidate() {
        expression.reset();
        context.reset();
    }
};

extern zend_class_entry *duckdb_expression_ce;
void duckdb_register_expression_class(zend_class_entry *ce);
/* Consumes both native handles, including when wrapping fails. */
std::shared_ptr<expression_state> duckdb_expression_wrap(zval *out, duckdb_expression expression,
                                                        duckdb_client_context context);

#endif
