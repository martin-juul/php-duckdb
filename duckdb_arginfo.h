/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 8a4943f00f89053e3661fc76b4671e5ab9a71736 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DuckDB_version, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DuckDB_Exception_getErrorType, 0, 0, DuckDB\\ErrorType, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_DuckDB_Interval___construct, 0, 0, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, months, IS_LONG, 0, "0")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, days, IS_LONG, 0, "0")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, micros, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DuckDB_Interval_getMonths, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_Interval_getDays arginfo_class_DuckDB_Interval_getMonths

#define arginfo_class_DuckDB_Interval_getMicros arginfo_class_DuckDB_Interval_getMonths

#define arginfo_class_DuckDB_Interval___toString arginfo_DuckDB_version

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DuckDB_Interval_jsonSerialize, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DuckDB_Interval_fromSeconds, 0, 1, DuckDB\\Interval, 0)
	ZEND_ARG_TYPE_INFO(0, seconds, IS_DOUBLE, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_DuckDB_Database___construct, 0, 0, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, path, IS_STRING, 0, "\':memory:\'")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, config, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DuckDB_Database_connect, 0, 0, DuckDB\\Connection, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_DuckDB_Connection___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DuckDB_Connection_query, 0, 1, DuckDB\\Result, 0)
	ZEND_ARG_TYPE_INFO(0, sql, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_Connection_queryStreaming arginfo_class_DuckDB_Connection_query

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DuckDB_Connection_queryAsync, 0, 1, DuckDB\\PendingQuery, 0)
	ZEND_ARG_TYPE_INFO(0, sql, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_Connection_queryPending arginfo_class_DuckDB_Connection_queryAsync

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DuckDB_Connection_execute, 0, 1, DuckDB\\Result, 0)
	ZEND_ARG_TYPE_INFO(0, sql, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, params, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DuckDB_Connection_prepare, 0, 1, DuckDB\\Statement, 0)
	ZEND_ARG_TYPE_INFO(0, sql, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DuckDB_Connection_appender, 0, 1, DuckDB\\Appender, 0)
	ZEND_ARG_TYPE_INFO(0, table, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, schema, IS_STRING, 1, "null")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, catalog, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DuckDB_Connection_interrupt, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DuckDB_Connection_getTableNames, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, sql, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_Connection_close arginfo_class_DuckDB_Connection_interrupt

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DuckDB_Connection_isClosed, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_Connection_queryProgress arginfo_class_DuckDB_Interval_jsonSerialize

#define arginfo_class_DuckDB_Connection_beginTransaction arginfo_class_DuckDB_Connection_interrupt

#define arginfo_class_DuckDB_Connection_commit arginfo_class_DuckDB_Connection_interrupt

#define arginfo_class_DuckDB_Connection_rollBack arginfo_class_DuckDB_Connection_interrupt

#define arginfo_class_DuckDB_Statement___construct arginfo_class_DuckDB_Connection___construct

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DuckDB_Statement_bindValue, 0, 2, DuckDB\\Statement, 0)
	ZEND_ARG_TYPE_MASK(0, param, MAY_BE_LONG|MAY_BE_STRING, NULL)
	ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DuckDB_Statement_bindBlob, 0, 2, DuckDB\\Statement, 0)
	ZEND_ARG_TYPE_MASK(0, param, MAY_BE_LONG|MAY_BE_STRING, NULL)
	ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_Statement_clearBindings arginfo_class_DuckDB_Connection_interrupt

#define arginfo_class_DuckDB_Statement_parameterCount arginfo_class_DuckDB_Interval_getMonths

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DuckDB_Statement_parameterName, 0, 1, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, param, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DuckDB_Statement_parameterType, 0, 1, IS_STRING, 0)
	ZEND_ARG_TYPE_MASK(0, param, MAY_BE_LONG|MAY_BE_STRING, NULL)
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_Statement_statementType arginfo_DuckDB_version

#define arginfo_class_DuckDB_Statement_columnCount arginfo_class_DuckDB_Interval_getMonths

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DuckDB_Statement_columnName, 0, 1, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, index, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_Statement_columnType arginfo_class_DuckDB_Statement_columnName

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DuckDB_Statement_execute, 0, 0, DuckDB\\Result, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, params, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_Statement_executeStreaming arginfo_class_DuckDB_Statement_execute

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DuckDB_Statement_executeAsync, 0, 0, DuckDB\\PendingQuery, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, params, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_Result___construct arginfo_class_DuckDB_Connection___construct

#define arginfo_class_DuckDB_Result_columnCount arginfo_class_DuckDB_Interval_getMonths

#define arginfo_class_DuckDB_Result_columnName arginfo_class_DuckDB_Statement_columnName

#define arginfo_class_DuckDB_Result_columnType arginfo_class_DuckDB_Statement_columnName

#define arginfo_class_DuckDB_Result_columns arginfo_class_DuckDB_Interval_jsonSerialize

#define arginfo_class_DuckDB_Result_rowCount arginfo_class_DuckDB_Interval_getMonths

#define arginfo_class_DuckDB_Result_rowsChanged arginfo_class_DuckDB_Interval_getMonths

#define arginfo_class_DuckDB_Result_statementType arginfo_DuckDB_version

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DuckDB_Result_fetchRow, 0, 0, IS_ARRAY, 1)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, mode, DuckDB\\FetchMode, 0, "DuckDB\\FetchMode::Assoc")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DuckDB_Result_fetchAll, 0, 0, IS_ARRAY, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, mode, DuckDB\\FetchMode, 0, "DuckDB\\FetchMode::Assoc")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DuckDB_Result_fetchColumn, 0, 0, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, column, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DuckDB_Result_getIterator, 0, 0, Iterator, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_ResultIterator___construct arginfo_class_DuckDB_Connection___construct

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DuckDB_ResultIterator_current, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_ResultIterator_key arginfo_class_DuckDB_Interval_getMonths

#define arginfo_class_DuckDB_ResultIterator_next arginfo_class_DuckDB_Connection_interrupt

#define arginfo_class_DuckDB_ResultIterator_rewind arginfo_class_DuckDB_Connection_interrupt

#define arginfo_class_DuckDB_ResultIterator_valid arginfo_class_DuckDB_Connection_isClosed

#define arginfo_class_DuckDB_PendingQuery___construct arginfo_class_DuckDB_Connection___construct

#define arginfo_class_DuckDB_PendingQuery_isReady arginfo_class_DuckDB_Connection_isClosed

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DuckDB_PendingQuery_await, 0, 0, DuckDB\\Result, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_PendingQuery_suspend arginfo_class_DuckDB_PendingQuery_await

#define arginfo_class_DuckDB_PendingQuery_cancel arginfo_class_DuckDB_Connection_interrupt

#define arginfo_class_DuckDB_PendingQuery_getFd arginfo_class_DuckDB_Interval_getMonths

#define arginfo_class_DuckDB_PendingQuery_getStream arginfo_class_DuckDB_ResultIterator_current

#define arginfo_class_DuckDB_Appender___construct arginfo_class_DuckDB_Connection___construct

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DuckDB_Appender_appendRow, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, values, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_Appender_beginRow arginfo_class_DuckDB_Connection_interrupt

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DuckDB_Appender_append, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_DuckDB_Appender_appendDefault arginfo_class_DuckDB_Connection_interrupt

#define arginfo_class_DuckDB_Appender_endRow arginfo_class_DuckDB_Connection_interrupt

#define arginfo_class_DuckDB_Appender_flush arginfo_class_DuckDB_Connection_interrupt

#define arginfo_class_DuckDB_Appender_close arginfo_class_DuckDB_Connection_interrupt


ZEND_FUNCTION(DuckDB_version);
ZEND_METHOD(DuckDB_Exception, getErrorType);
ZEND_METHOD(DuckDB_Interval, __construct);
ZEND_METHOD(DuckDB_Interval, getMonths);
ZEND_METHOD(DuckDB_Interval, getDays);
ZEND_METHOD(DuckDB_Interval, getMicros);
ZEND_METHOD(DuckDB_Interval, __toString);
ZEND_METHOD(DuckDB_Interval, jsonSerialize);
ZEND_METHOD(DuckDB_Interval, fromSeconds);
ZEND_METHOD(DuckDB_Database, __construct);
ZEND_METHOD(DuckDB_Database, connect);
ZEND_METHOD(DuckDB_Connection, __construct);
ZEND_METHOD(DuckDB_Connection, query);
ZEND_METHOD(DuckDB_Connection, queryStreaming);
ZEND_METHOD(DuckDB_Connection, queryAsync);
ZEND_METHOD(DuckDB_Connection, queryPending);
ZEND_METHOD(DuckDB_Connection, execute);
ZEND_METHOD(DuckDB_Connection, prepare);
ZEND_METHOD(DuckDB_Connection, appender);
ZEND_METHOD(DuckDB_Connection, interrupt);
ZEND_METHOD(DuckDB_Connection, getTableNames);
ZEND_METHOD(DuckDB_Connection, close);
ZEND_METHOD(DuckDB_Connection, isClosed);
ZEND_METHOD(DuckDB_Connection, queryProgress);
ZEND_METHOD(DuckDB_Connection, beginTransaction);
ZEND_METHOD(DuckDB_Connection, commit);
ZEND_METHOD(DuckDB_Connection, rollBack);
ZEND_METHOD(DuckDB_Statement, __construct);
ZEND_METHOD(DuckDB_Statement, bindValue);
ZEND_METHOD(DuckDB_Statement, bindBlob);
ZEND_METHOD(DuckDB_Statement, clearBindings);
ZEND_METHOD(DuckDB_Statement, parameterCount);
ZEND_METHOD(DuckDB_Statement, parameterName);
ZEND_METHOD(DuckDB_Statement, parameterType);
ZEND_METHOD(DuckDB_Statement, statementType);
ZEND_METHOD(DuckDB_Statement, columnCount);
ZEND_METHOD(DuckDB_Statement, columnName);
ZEND_METHOD(DuckDB_Statement, columnType);
ZEND_METHOD(DuckDB_Statement, execute);
ZEND_METHOD(DuckDB_Statement, executeStreaming);
ZEND_METHOD(DuckDB_Statement, executeAsync);
ZEND_METHOD(DuckDB_Result, __construct);
ZEND_METHOD(DuckDB_Result, columnCount);
ZEND_METHOD(DuckDB_Result, columnName);
ZEND_METHOD(DuckDB_Result, columnType);
ZEND_METHOD(DuckDB_Result, columns);
ZEND_METHOD(DuckDB_Result, rowCount);
ZEND_METHOD(DuckDB_Result, rowsChanged);
ZEND_METHOD(DuckDB_Result, statementType);
ZEND_METHOD(DuckDB_Result, fetchRow);
ZEND_METHOD(DuckDB_Result, fetchAll);
ZEND_METHOD(DuckDB_Result, fetchColumn);
ZEND_METHOD(DuckDB_Result, getIterator);
ZEND_METHOD(DuckDB_ResultIterator, __construct);
ZEND_METHOD(DuckDB_ResultIterator, current);
ZEND_METHOD(DuckDB_ResultIterator, key);
ZEND_METHOD(DuckDB_ResultIterator, next);
ZEND_METHOD(DuckDB_ResultIterator, rewind);
ZEND_METHOD(DuckDB_ResultIterator, valid);
ZEND_METHOD(DuckDB_PendingQuery, __construct);
ZEND_METHOD(DuckDB_PendingQuery, isReady);
ZEND_METHOD(DuckDB_PendingQuery, await);
ZEND_METHOD(DuckDB_PendingQuery, suspend);
ZEND_METHOD(DuckDB_PendingQuery, cancel);
ZEND_METHOD(DuckDB_PendingQuery, getFd);
ZEND_METHOD(DuckDB_PendingQuery, getStream);
ZEND_METHOD(DuckDB_Appender, __construct);
ZEND_METHOD(DuckDB_Appender, appendRow);
ZEND_METHOD(DuckDB_Appender, beginRow);
ZEND_METHOD(DuckDB_Appender, append);
ZEND_METHOD(DuckDB_Appender, appendDefault);
ZEND_METHOD(DuckDB_Appender, endRow);
ZEND_METHOD(DuckDB_Appender, flush);
ZEND_METHOD(DuckDB_Appender, close);


static const zend_function_entry ext_functions[] = {
	ZEND_NS_FALIAS("DuckDB", version, DuckDB_version, arginfo_DuckDB_version)
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_FetchMode_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_ErrorType_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_Exception_methods[] = {
	ZEND_ME(DuckDB_Exception, getErrorType, arginfo_class_DuckDB_Exception_getErrorType, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_ConnectionException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_ParserException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_BinderException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_CatalogException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_ConstraintException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_TransactionException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_ConversionException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_IOException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_InterruptedException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_InternalException_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_Interval_methods[] = {
	ZEND_ME(DuckDB_Interval, __construct, arginfo_class_DuckDB_Interval___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Interval, getMonths, arginfo_class_DuckDB_Interval_getMonths, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Interval, getDays, arginfo_class_DuckDB_Interval_getDays, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Interval, getMicros, arginfo_class_DuckDB_Interval_getMicros, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Interval, __toString, arginfo_class_DuckDB_Interval___toString, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Interval, jsonSerialize, arginfo_class_DuckDB_Interval_jsonSerialize, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Interval, fromSeconds, arginfo_class_DuckDB_Interval_fromSeconds, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_Database_methods[] = {
	ZEND_ME(DuckDB_Database, __construct, arginfo_class_DuckDB_Database___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Database, connect, arginfo_class_DuckDB_Database_connect, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_Connection_methods[] = {
	ZEND_ME(DuckDB_Connection, __construct, arginfo_class_DuckDB_Connection___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(DuckDB_Connection, query, arginfo_class_DuckDB_Connection_query, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Connection, queryStreaming, arginfo_class_DuckDB_Connection_queryStreaming, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Connection, queryAsync, arginfo_class_DuckDB_Connection_queryAsync, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Connection, queryPending, arginfo_class_DuckDB_Connection_queryPending, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Connection, execute, arginfo_class_DuckDB_Connection_execute, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Connection, prepare, arginfo_class_DuckDB_Connection_prepare, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Connection, appender, arginfo_class_DuckDB_Connection_appender, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Connection, interrupt, arginfo_class_DuckDB_Connection_interrupt, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Connection, getTableNames, arginfo_class_DuckDB_Connection_getTableNames, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Connection, close, arginfo_class_DuckDB_Connection_close, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Connection, isClosed, arginfo_class_DuckDB_Connection_isClosed, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Connection, queryProgress, arginfo_class_DuckDB_Connection_queryProgress, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Connection, beginTransaction, arginfo_class_DuckDB_Connection_beginTransaction, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Connection, commit, arginfo_class_DuckDB_Connection_commit, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Connection, rollBack, arginfo_class_DuckDB_Connection_rollBack, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_Statement_methods[] = {
	ZEND_ME(DuckDB_Statement, __construct, arginfo_class_DuckDB_Statement___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(DuckDB_Statement, bindValue, arginfo_class_DuckDB_Statement_bindValue, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Statement, bindBlob, arginfo_class_DuckDB_Statement_bindBlob, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Statement, clearBindings, arginfo_class_DuckDB_Statement_clearBindings, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Statement, parameterCount, arginfo_class_DuckDB_Statement_parameterCount, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Statement, parameterName, arginfo_class_DuckDB_Statement_parameterName, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Statement, parameterType, arginfo_class_DuckDB_Statement_parameterType, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Statement, statementType, arginfo_class_DuckDB_Statement_statementType, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Statement, columnCount, arginfo_class_DuckDB_Statement_columnCount, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Statement, columnName, arginfo_class_DuckDB_Statement_columnName, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Statement, columnType, arginfo_class_DuckDB_Statement_columnType, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Statement, execute, arginfo_class_DuckDB_Statement_execute, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Statement, executeStreaming, arginfo_class_DuckDB_Statement_executeStreaming, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Statement, executeAsync, arginfo_class_DuckDB_Statement_executeAsync, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_Result_methods[] = {
	ZEND_ME(DuckDB_Result, __construct, arginfo_class_DuckDB_Result___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(DuckDB_Result, columnCount, arginfo_class_DuckDB_Result_columnCount, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Result, columnName, arginfo_class_DuckDB_Result_columnName, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Result, columnType, arginfo_class_DuckDB_Result_columnType, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Result, columns, arginfo_class_DuckDB_Result_columns, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Result, rowCount, arginfo_class_DuckDB_Result_rowCount, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Result, rowsChanged, arginfo_class_DuckDB_Result_rowsChanged, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Result, statementType, arginfo_class_DuckDB_Result_statementType, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Result, fetchRow, arginfo_class_DuckDB_Result_fetchRow, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Result, fetchAll, arginfo_class_DuckDB_Result_fetchAll, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Result, fetchColumn, arginfo_class_DuckDB_Result_fetchColumn, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Result, getIterator, arginfo_class_DuckDB_Result_getIterator, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_ResultIterator_methods[] = {
	ZEND_ME(DuckDB_ResultIterator, __construct, arginfo_class_DuckDB_ResultIterator___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(DuckDB_ResultIterator, current, arginfo_class_DuckDB_ResultIterator_current, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_ResultIterator, key, arginfo_class_DuckDB_ResultIterator_key, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_ResultIterator, next, arginfo_class_DuckDB_ResultIterator_next, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_ResultIterator, rewind, arginfo_class_DuckDB_ResultIterator_rewind, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_ResultIterator, valid, arginfo_class_DuckDB_ResultIterator_valid, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_PendingQuery_methods[] = {
	ZEND_ME(DuckDB_PendingQuery, __construct, arginfo_class_DuckDB_PendingQuery___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(DuckDB_PendingQuery, isReady, arginfo_class_DuckDB_PendingQuery_isReady, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_PendingQuery, await, arginfo_class_DuckDB_PendingQuery_await, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_PendingQuery, suspend, arginfo_class_DuckDB_PendingQuery_suspend, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_PendingQuery, cancel, arginfo_class_DuckDB_PendingQuery_cancel, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_PendingQuery, getFd, arginfo_class_DuckDB_PendingQuery_getFd, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_PendingQuery, getStream, arginfo_class_DuckDB_PendingQuery_getStream, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};


static const zend_function_entry class_DuckDB_Appender_methods[] = {
	ZEND_ME(DuckDB_Appender, __construct, arginfo_class_DuckDB_Appender___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(DuckDB_Appender, appendRow, arginfo_class_DuckDB_Appender_appendRow, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Appender, beginRow, arginfo_class_DuckDB_Appender_beginRow, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Appender, append, arginfo_class_DuckDB_Appender_append, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Appender, appendDefault, arginfo_class_DuckDB_Appender_appendDefault, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Appender, endRow, arginfo_class_DuckDB_Appender_endRow, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Appender, flush, arginfo_class_DuckDB_Appender_flush, ZEND_ACC_PUBLIC)
	ZEND_ME(DuckDB_Appender, close, arginfo_class_DuckDB_Appender_close, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_DuckDB_FetchMode(void)
{
	zend_class_entry *class_entry = zend_register_internal_enum("DuckDB\\FetchMode", IS_UNDEF, class_DuckDB_FetchMode_methods);

	zend_enum_add_case_cstr(class_entry, "Assoc", NULL);

	zend_enum_add_case_cstr(class_entry, "Num", NULL);

	zend_enum_add_case_cstr(class_entry, "Both", NULL);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_ErrorType(void)
{
	zend_class_entry *class_entry = zend_register_internal_enum("DuckDB\\ErrorType", IS_LONG, class_DuckDB_ErrorType_methods);

	zval enum_case_Invalid_value;
	ZVAL_LONG(&enum_case_Invalid_value, 0);
	zend_enum_add_case_cstr(class_entry, "Invalid", &enum_case_Invalid_value);

	zval enum_case_OutOfRange_value;
	ZVAL_LONG(&enum_case_OutOfRange_value, 1);
	zend_enum_add_case_cstr(class_entry, "OutOfRange", &enum_case_OutOfRange_value);

	zval enum_case_Conversion_value;
	ZVAL_LONG(&enum_case_Conversion_value, 2);
	zend_enum_add_case_cstr(class_entry, "Conversion", &enum_case_Conversion_value);

	zval enum_case_UnknownType_value;
	ZVAL_LONG(&enum_case_UnknownType_value, 3);
	zend_enum_add_case_cstr(class_entry, "UnknownType", &enum_case_UnknownType_value);

	zval enum_case_Decimal_value;
	ZVAL_LONG(&enum_case_Decimal_value, 4);
	zend_enum_add_case_cstr(class_entry, "Decimal", &enum_case_Decimal_value);

	zval enum_case_MismatchType_value;
	ZVAL_LONG(&enum_case_MismatchType_value, 5);
	zend_enum_add_case_cstr(class_entry, "MismatchType", &enum_case_MismatchType_value);

	zval enum_case_DivideByZero_value;
	ZVAL_LONG(&enum_case_DivideByZero_value, 6);
	zend_enum_add_case_cstr(class_entry, "DivideByZero", &enum_case_DivideByZero_value);

	zval enum_case_ObjectSize_value;
	ZVAL_LONG(&enum_case_ObjectSize_value, 7);
	zend_enum_add_case_cstr(class_entry, "ObjectSize", &enum_case_ObjectSize_value);

	zval enum_case_InvalidType_value;
	ZVAL_LONG(&enum_case_InvalidType_value, 8);
	zend_enum_add_case_cstr(class_entry, "InvalidType", &enum_case_InvalidType_value);

	zval enum_case_Serialization_value;
	ZVAL_LONG(&enum_case_Serialization_value, 9);
	zend_enum_add_case_cstr(class_entry, "Serialization", &enum_case_Serialization_value);

	zval enum_case_Transaction_value;
	ZVAL_LONG(&enum_case_Transaction_value, 10);
	zend_enum_add_case_cstr(class_entry, "Transaction", &enum_case_Transaction_value);

	zval enum_case_NotImplemented_value;
	ZVAL_LONG(&enum_case_NotImplemented_value, 11);
	zend_enum_add_case_cstr(class_entry, "NotImplemented", &enum_case_NotImplemented_value);

	zval enum_case_Expression_value;
	ZVAL_LONG(&enum_case_Expression_value, 12);
	zend_enum_add_case_cstr(class_entry, "Expression", &enum_case_Expression_value);

	zval enum_case_Catalog_value;
	ZVAL_LONG(&enum_case_Catalog_value, 13);
	zend_enum_add_case_cstr(class_entry, "Catalog", &enum_case_Catalog_value);

	zval enum_case_Parser_value;
	ZVAL_LONG(&enum_case_Parser_value, 14);
	zend_enum_add_case_cstr(class_entry, "Parser", &enum_case_Parser_value);

	zval enum_case_Planner_value;
	ZVAL_LONG(&enum_case_Planner_value, 15);
	zend_enum_add_case_cstr(class_entry, "Planner", &enum_case_Planner_value);

	zval enum_case_Scheduler_value;
	ZVAL_LONG(&enum_case_Scheduler_value, 16);
	zend_enum_add_case_cstr(class_entry, "Scheduler", &enum_case_Scheduler_value);

	zval enum_case_Executor_value;
	ZVAL_LONG(&enum_case_Executor_value, 17);
	zend_enum_add_case_cstr(class_entry, "Executor", &enum_case_Executor_value);

	zval enum_case_Constraint_value;
	ZVAL_LONG(&enum_case_Constraint_value, 18);
	zend_enum_add_case_cstr(class_entry, "Constraint", &enum_case_Constraint_value);

	zval enum_case_Index_value;
	ZVAL_LONG(&enum_case_Index_value, 19);
	zend_enum_add_case_cstr(class_entry, "Index", &enum_case_Index_value);

	zval enum_case_Stat_value;
	ZVAL_LONG(&enum_case_Stat_value, 20);
	zend_enum_add_case_cstr(class_entry, "Stat", &enum_case_Stat_value);

	zval enum_case_Connection_value;
	ZVAL_LONG(&enum_case_Connection_value, 21);
	zend_enum_add_case_cstr(class_entry, "Connection", &enum_case_Connection_value);

	zval enum_case_Syntax_value;
	ZVAL_LONG(&enum_case_Syntax_value, 22);
	zend_enum_add_case_cstr(class_entry, "Syntax", &enum_case_Syntax_value);

	zval enum_case_Settings_value;
	ZVAL_LONG(&enum_case_Settings_value, 23);
	zend_enum_add_case_cstr(class_entry, "Settings", &enum_case_Settings_value);

	zval enum_case_Binder_value;
	ZVAL_LONG(&enum_case_Binder_value, 24);
	zend_enum_add_case_cstr(class_entry, "Binder", &enum_case_Binder_value);

	zval enum_case_Network_value;
	ZVAL_LONG(&enum_case_Network_value, 25);
	zend_enum_add_case_cstr(class_entry, "Network", &enum_case_Network_value);

	zval enum_case_Optimizer_value;
	ZVAL_LONG(&enum_case_Optimizer_value, 26);
	zend_enum_add_case_cstr(class_entry, "Optimizer", &enum_case_Optimizer_value);

	zval enum_case_NullPointer_value;
	ZVAL_LONG(&enum_case_NullPointer_value, 27);
	zend_enum_add_case_cstr(class_entry, "NullPointer", &enum_case_NullPointer_value);

	zval enum_case_Io_value;
	ZVAL_LONG(&enum_case_Io_value, 28);
	zend_enum_add_case_cstr(class_entry, "Io", &enum_case_Io_value);

	zval enum_case_Interrupt_value;
	ZVAL_LONG(&enum_case_Interrupt_value, 29);
	zend_enum_add_case_cstr(class_entry, "Interrupt", &enum_case_Interrupt_value);

	zval enum_case_Fatal_value;
	ZVAL_LONG(&enum_case_Fatal_value, 30);
	zend_enum_add_case_cstr(class_entry, "Fatal", &enum_case_Fatal_value);

	zval enum_case_Internal_value;
	ZVAL_LONG(&enum_case_Internal_value, 31);
	zend_enum_add_case_cstr(class_entry, "Internal", &enum_case_Internal_value);

	zval enum_case_InvalidInput_value;
	ZVAL_LONG(&enum_case_InvalidInput_value, 32);
	zend_enum_add_case_cstr(class_entry, "InvalidInput", &enum_case_InvalidInput_value);

	zval enum_case_OutOfMemory_value;
	ZVAL_LONG(&enum_case_OutOfMemory_value, 33);
	zend_enum_add_case_cstr(class_entry, "OutOfMemory", &enum_case_OutOfMemory_value);

	zval enum_case_Permission_value;
	ZVAL_LONG(&enum_case_Permission_value, 34);
	zend_enum_add_case_cstr(class_entry, "Permission", &enum_case_Permission_value);

	zval enum_case_ParameterNotResolved_value;
	ZVAL_LONG(&enum_case_ParameterNotResolved_value, 35);
	zend_enum_add_case_cstr(class_entry, "ParameterNotResolved", &enum_case_ParameterNotResolved_value);

	zval enum_case_ParameterNotAllowed_value;
	ZVAL_LONG(&enum_case_ParameterNotAllowed_value, 36);
	zend_enum_add_case_cstr(class_entry, "ParameterNotAllowed", &enum_case_ParameterNotAllowed_value);

	zval enum_case_Dependency_value;
	ZVAL_LONG(&enum_case_Dependency_value, 37);
	zend_enum_add_case_cstr(class_entry, "Dependency", &enum_case_Dependency_value);

	zval enum_case_Http_value;
	ZVAL_LONG(&enum_case_Http_value, 38);
	zend_enum_add_case_cstr(class_entry, "Http", &enum_case_Http_value);

	zval enum_case_MissingExtension_value;
	ZVAL_LONG(&enum_case_MissingExtension_value, 39);
	zend_enum_add_case_cstr(class_entry, "MissingExtension", &enum_case_MissingExtension_value);

	zval enum_case_Autoload_value;
	ZVAL_LONG(&enum_case_Autoload_value, 40);
	zend_enum_add_case_cstr(class_entry, "Autoload", &enum_case_Autoload_value);

	zval enum_case_Sequence_value;
	ZVAL_LONG(&enum_case_Sequence_value, 41);
	zend_enum_add_case_cstr(class_entry, "Sequence", &enum_case_Sequence_value);

	zval enum_case_InvalidConfiguration_value;
	ZVAL_LONG(&enum_case_InvalidConfiguration_value, 42);
	zend_enum_add_case_cstr(class_entry, "InvalidConfiguration", &enum_case_InvalidConfiguration_value);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_Exception(zend_class_entry *class_entry_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "Exception", class_DuckDB_Exception_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_Exception);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_ConnectionException(zend_class_entry *class_entry_DuckDB_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "ConnectionException", class_DuckDB_ConnectionException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_DuckDB_Exception);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_ParserException(zend_class_entry *class_entry_DuckDB_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "ParserException", class_DuckDB_ParserException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_DuckDB_Exception);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_BinderException(zend_class_entry *class_entry_DuckDB_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "BinderException", class_DuckDB_BinderException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_DuckDB_Exception);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_CatalogException(zend_class_entry *class_entry_DuckDB_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "CatalogException", class_DuckDB_CatalogException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_DuckDB_Exception);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_ConstraintException(zend_class_entry *class_entry_DuckDB_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "ConstraintException", class_DuckDB_ConstraintException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_DuckDB_Exception);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_TransactionException(zend_class_entry *class_entry_DuckDB_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "TransactionException", class_DuckDB_TransactionException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_DuckDB_Exception);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_ConversionException(zend_class_entry *class_entry_DuckDB_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "ConversionException", class_DuckDB_ConversionException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_DuckDB_Exception);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_IOException(zend_class_entry *class_entry_DuckDB_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "IOException", class_DuckDB_IOException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_DuckDB_Exception);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_InterruptedException(zend_class_entry *class_entry_DuckDB_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "InterruptedException", class_DuckDB_InterruptedException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_DuckDB_Exception);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_InternalException(zend_class_entry *class_entry_DuckDB_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "InternalException", class_DuckDB_InternalException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_DuckDB_Exception);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_Interval(zend_class_entry *class_entry_JsonSerializable)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "Interval", class_DuckDB_Interval_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL;
	zend_class_implements(class_entry, 1, class_entry_JsonSerializable);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_Database(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "Database", class_DuckDB_Database_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL;

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_Connection(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "Connection", class_DuckDB_Connection_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL;

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_Statement(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "Statement", class_DuckDB_Statement_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL;

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_Result(zend_class_entry *class_entry_IteratorAggregate)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "Result", class_DuckDB_Result_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL;
	zend_class_implements(class_entry, 1, class_entry_IteratorAggregate);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_ResultIterator(zend_class_entry *class_entry_Iterator)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "ResultIterator", class_DuckDB_ResultIterator_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL;
	zend_class_implements(class_entry, 1, class_entry_Iterator);

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_PendingQuery(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "PendingQuery", class_DuckDB_PendingQuery_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL;

	return class_entry;
}

static zend_class_entry *register_class_DuckDB_Appender(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DuckDB", "Appender", class_DuckDB_Appender_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL;

	return class_entry;
}
