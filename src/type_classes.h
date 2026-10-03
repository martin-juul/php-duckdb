#ifndef PHP_DUCKDB_TYPE_CLASSES_H
#define PHP_DUCKDB_TYPE_CLASSES_H

/* SQL spellings shared by constructors, schema arguments and registration. */
#define DUCKDB_SCALAR_VALUE_CLASSES(X) \
    X(Boolean, "BOOLEAN") \
    X(TinyInt, "TINYINT") \
    X(SmallInt, "SMALLINT") \
    X(Integer, "INTEGER") \
    X(BigInt, "BIGINT") \
    X(UTinyInt, "UTINYINT") \
    X(USmallInt, "USMALLINT") \
    X(UInteger, "UINTEGER") \
    X(UBigInt, "UBIGINT") \
    X(HugeInt, "HUGEINT") \
    X(UHugeInt, "UHUGEINT") \
    X(BigNum, "BIGNUM") \
    X(Float32, "FLOAT") \
    X(Double, "DOUBLE") \
    X(Varchar, "VARCHAR") \
    X(Blob, "BLOB") \
    X(Bit, "BIT") \
    X(Uuid, "UUID") \
    X(Json, "JSON") \
    X(Date, "DATE") \
    X(Time, "TIME") \
    X(TimeNs, "TIME_NS") \
    X(TimeTz, "TIMETZ") \
    X(TimestampS, "TIMESTAMP_S") \
    X(TimestampMs, "TIMESTAMP_MS") \
    X(Timestamp, "TIMESTAMP") \
    X(TimestampNs, "TIMESTAMP_NS") \
    X(TimestampTz, "TIMESTAMPTZ") \
    X(IntervalValue, "INTERVAL") \
    X(Variant, "VARIANT")

#define DUCKDB_PARAMETERIZED_VALUE_CLASSES(X) \
    X(Decimal) X(Enum) X(ListValue) X(ArrayValue) X(Struct) \
    X(Map) X(Union) X(Geometry) X(CatalogValue)

#endif
