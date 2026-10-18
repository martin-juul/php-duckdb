#ifndef PHP_DUCKDB_LOGGING_H
#define PHP_DUCKDB_LOGGING_H

#include "php_duckdb.h"

struct log_storage_state;

extern zend_class_entry *duckdb_log_storage_ce;
void duckdb_register_log_storage_class(zend_class_entry *ce);

PHP_METHOD(DuckDB_LogStorage, __construct);
PHP_METHOD(DuckDB_LogStorage, drain);
PHP_METHOD(DuckDB_LogStorage, droppedCount);
PHP_METHOD(DuckDB_Database, registerLogStorage);

#endif
