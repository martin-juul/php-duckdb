/* Standalone regression for the pinned Arrow/GeoArrow SDK patch. Build with
 * the SDK's include/lib paths and run normally or under Valgrind. */
#include "duckdb.h"

#include <cstdio>
#include <cstring>
#include <stdexcept>
#include <string>
#include <vector>

struct ArrowSchema {
    const char *format;
    const char *name;
    const char *metadata;
    int64_t flags;
    int64_t n_children;
    ArrowSchema **children;
    ArrowSchema *dictionary;
    void (*release)(ArrowSchema *);
    void *private_data;
};

struct ArrowArray {
    int64_t length;
    int64_t null_count;
    int64_t offset;
    int64_t n_buffers;
    int64_t n_children;
    const void **buffers;
    ArrowArray **children;
    ArrowArray *dictionary;
    void (*release)(ArrowArray *);
    void *private_data;
};

struct Fixture {
    duckdb_database database = nullptr;
    duckdb_connection connection = nullptr;

    ~Fixture() {
        duckdb_disconnect(&connection);
        duckdb_close(&database);
    }
};

struct Result {
    duckdb_result value = {};

    ~Result() {
        duckdb_destroy_result(&value);
    }
};

struct ArrowConversion {
    ArrowSchema schema = {};
    ArrowArray array = {};
    duckdb_arrow_options options = nullptr;
    duckdb_arrow_converted_schema converted = nullptr;
    duckdb_data_chunk source = nullptr;
    duckdb_data_chunk imported = nullptr;
    std::vector<duckdb_logical_type> types;

    ~ArrowConversion() {
        duckdb_destroy_data_chunk(&imported);
        if (array.release) {
            array.release(&array);
        }
        if (schema.release) {
            schema.release(&schema);
        }
        duckdb_destroy_arrow_converted_schema(&converted);
        duckdb_destroy_arrow_options(&options);
        duckdb_destroy_data_chunk(&source);
        for (auto type : types) {
            duckdb_destroy_logical_type(&type);
        }
    }
};

static void check(bool condition, const char *message) {
    if (!condition) {
        throw std::runtime_error(message);
    }
}

static void query(duckdb_connection connection, const char *sql, Result &result) {
    if (duckdb_query(connection, sql, &result.value) == DuckDBError) {
        throw std::runtime_error(duckdb_result_error(&result.value));
    }
}

static void execute(duckdb_connection connection, const char *sql) {
    Result result;
    query(connection, sql, result);
}

static void check_error(duckdb_error_data error) {
    if (!error) {
        return;
    }
    std::string message;
    if (duckdb_error_data_has_error(error)) {
        message = duckdb_error_data_message(error);
    }
    duckdb_destroy_error_data(&error);
    if (!message.empty()) {
        throw std::runtime_error(message);
    }
}

static void check_geometry(duckdb_connection connection, duckdb_logical_type type, const char *expected_crs) {
    auto id = duckdb_get_type_id(type);
    if (id == DUCKDB_TYPE_GEOMETRY) {
        char *crs = duckdb_geometry_type_get_crs(type);
        std::string definition = crs ? crs : "";
        duckdb_free(crs);
        if (!definition.empty() && definition[0] == '{') {
            // Complete PROJJSON and catalog-resolved authority identifiers
            // represent the same CRS. Compare its root identifier, not its
            // incidental whitespace or the IDs of nested datum objects.
            duckdb_prepared_statement statement = nullptr;
            check(duckdb_prepare(connection,
                                "SELECT json_extract_string($1, '$.id.authority') || ':' || "
                                "json_extract_string($1, '$.id.code')",
                                &statement) == DuckDBSuccess, "Cannot prepare CRS identifier check");
            Result result;
            duckdb_state state = duckdb_bind_varchar(statement, 1, definition.c_str());
            if (state == DuckDBSuccess) {
                state = duckdb_execute_prepared(statement, &result.value);
            }
            duckdb_destroy_prepare(&statement);
            check(state == DuckDBSuccess, "Cannot inspect PROJJSON CRS identifier");
            char *identifier = duckdb_value_varchar(&result.value, 0, 0);
            definition = identifier ? identifier : "";
            duckdb_free(identifier);
        }
        check(definition == expected_crs, "GeoArrow conversion lost its CRS declaration");
        return;
    }
    duckdb_logical_type child = nullptr;
    if (id == DUCKDB_TYPE_STRUCT) {
        child = duckdb_struct_type_child_type(type, 0);
    } else if (id == DUCKDB_TYPE_LIST) {
        child = duckdb_list_type_child_type(type);
    } else if (id == DUCKDB_TYPE_ARRAY) {
        child = duckdb_array_type_child_type(type);
    }
    check(child != nullptr, "Unexpected native geometry container type");
    try {
        check_geometry(connection, child, expected_crs);
    } catch (...) {
        duckdb_destroy_logical_type(&child);
        throw;
    }
    duckdb_destroy_logical_type(&child);
}

static void borrowed_release(ArrowArray *array) {
    array->release = nullptr;
}

static void append_metadata(std::string &metadata, const char *key, const char *value) {
    int32_t length = static_cast<int32_t>(strlen(key));
    metadata.append(reinterpret_cast<const char *>(&length), sizeof(length));
    metadata.append(key, static_cast<size_t>(length));
    length = static_cast<int32_t>(strlen(value));
    metadata.append(reinterpret_cast<const char *>(&length), sizeof(length));
    metadata.append(value, static_cast<size_t>(length));
}


static void roundtrip(duckdb_connection connection, const char *sql, bool append_matching_crs = false) {
    Result result;
    query(connection, sql, result);
    ArrowConversion conversion;
    conversion.source = duckdb_fetch_chunk(result.value);
    check(conversion.source != nullptr, "Missing geometry data chunk");
    conversion.options = duckdb_result_get_arrow_options(&result.value);
    conversion.types.push_back(duckdb_column_logical_type(&result.value, 0));
    const char *names[] = {duckdb_column_name(&result.value, 0)};
    check_error(duckdb_to_arrow_schema(conversion.options, conversion.types.data(), names, 1, &conversion.schema));
    check_error(duckdb_data_chunk_to_arrow(conversion.options, conversion.source, &conversion.array));
    check_error(duckdb_schema_from_arrow(connection, &conversion.schema, &conversion.converted));
    /* Keep one original producer owner across materializing conversion. */
    ArrowArray borrowed = conversion.array;
    borrowed.release = borrowed_release;
    check_error(duckdb_data_chunk_from_arrow(connection, &borrowed, conversion.converted, &conversion.imported));
    check(duckdb_data_chunk_get_size(conversion.imported) == 1, "Geometry row count changed");
    auto type = duckdb_vector_get_column_type(duckdb_data_chunk_get_vector(conversion.imported, 0));
    try {
        check_geometry(connection, type, "OGC:CRS84");
    } catch (...) {
        duckdb_destroy_logical_type(&type);
        throw;
    }
    duckdb_destroy_logical_type(&type);
    if (append_matching_crs) {
        execute(connection, "CREATE TABLE IF NOT EXISTS arrow_geometry_append(g GEOMETRY('OGC:CRS84'))");
        duckdb_appender appender = nullptr;
        duckdb_state state = duckdb_appender_create(connection, nullptr, "arrow_geometry_append", &appender);
        if (state == DuckDBSuccess) {
            state = duckdb_append_data_chunk(appender, conversion.imported);
        }
        if (state == DuckDBSuccess) {
            state = duckdb_appender_close(appender);
        }
        std::string error;
        if (state == DuckDBError) {
            const char *message = appender ? duckdb_appender_error(appender) : nullptr;
            error = message ? message : "Cannot append geometry to its matching CRS declaration";
        }
        duckdb_appender_destroy(&appender);
        if (!error.empty()) {
            throw std::runtime_error(error);
        }
        Result appended;
        query(connection, "SELECT count(*) FROM arrow_geometry_append", appended);
        check(duckdb_value_int64(&appended.value, 0, 0) > 0, "Matching CRS geometry append produced no rows");
    }
}


static void foreign_crs(duckdb_connection connection, const char *definition, const char *crs_type) {
    Result result;
    query(connection, "SELECT 'POINT (1 2)'::GEOMETRY AS g", result);
    ArrowConversion conversion;
    conversion.source = duckdb_fetch_chunk(result.value);
    conversion.options = duckdb_result_get_arrow_options(&result.value);
    conversion.types.push_back(duckdb_column_logical_type(&result.value, 0));
    const char *names[] = {"g"};
    check_error(duckdb_to_arrow_schema(conversion.options, conversion.types.data(), names, 1, &conversion.schema));
    check_error(duckdb_data_chunk_to_arrow(conversion.options, conversion.source, &conversion.array));
    int32_t entries = 2;
    std::string metadata(reinterpret_cast<const char *>(&entries), sizeof(entries));
    std::string json = std::string("{\"crs_type\":\"") + crs_type + "\",\"crs\":\"" + definition + "\"}";
    append_metadata(metadata, "ARROW:extension:name", "geoarrow.wkb");
    append_metadata(metadata, "ARROW:extension:metadata", json.c_str());
    conversion.schema.children[0]->metadata = metadata.data();
    check_error(duckdb_schema_from_arrow(connection, &conversion.schema, &conversion.converted));
    ArrowArray borrowed = conversion.array;
    borrowed.release = borrowed_release;
    check_error(duckdb_data_chunk_from_arrow(connection, &borrowed, conversion.converted, &conversion.imported));
    auto type = duckdb_vector_get_column_type(duckdb_data_chunk_get_vector(conversion.imported, 0));
    try {
        check_geometry(connection, type, definition);
    } catch (...) {
        duckdb_destroy_logical_type(&type);
        throw;
    }
    duckdb_destroy_logical_type(&type);
}

static void invalid_metadata(duckdb_connection connection) {
    Result result;
    query(connection, "SELECT 'POINT (1 2)'::GEOMETRY('OGC:CRS84') AS g", result);
    ArrowConversion conversion;
    conversion.options = duckdb_result_get_arrow_options(&result.value);
    conversion.types.push_back(duckdb_column_logical_type(&result.value, 0));
    const char *names[] = {"g"};
    check_error(duckdb_to_arrow_schema(conversion.options, conversion.types.data(), names, 1, &conversion.schema));
    int32_t entries = 2;
    std::string metadata(reinterpret_cast<const char *>(&entries), sizeof(entries));
    append_metadata(metadata, "ARROW:extension:name", "geoarrow.wkb");
    append_metadata(metadata, "ARROW:extension:metadata", "{\"crs\":42}");
    conversion.schema.children[0]->metadata = metadata.data();
    auto error = duckdb_schema_from_arrow(connection, &conversion.schema, &conversion.converted);
    bool failed = error && duckdb_error_data_has_error(error);
    duckdb_destroy_error_data(&error);
    check(failed, "Malformed GeoArrow CRS metadata was silently accepted");
}

int main() {
    try {
        Fixture fixture;
        check(duckdb_open(nullptr, &fixture.database) == DuckDBSuccess, "Cannot open DuckDB");
        check(duckdb_connect(fixture.database, &fixture.connection) == DuckDBSuccess, "Cannot connect to DuckDB");
        execute(fixture.connection, "SET threads=1");
        execute(fixture.connection, "SET arrow_lossless_conversion=true");
        const char *queries[] = {
            "SELECT 'POINT (1 2)'::GEOMETRY('OGC:CRS84') AS g",
            "SELECT {'g': 'POINT (1 2)'::GEOMETRY('OGC:CRS84')} AS s",
            "SELECT ['POINT (1 2)'::GEOMETRY('OGC:CRS84')] AS l",
            "SELECT ['POINT (1 2)'::GEOMETRY('OGC:CRS84')]::GEOMETRY('OGC:CRS84')[1] AS a",
        };
        for (const char *sql : queries) {
            roundtrip(fixture.connection, sql);
        }
        roundtrip(fixture.connection, queries[0], true);
        foreign_crs(fixture.connection, "CUSTOM:123", "authority_code");
        foreign_crs(fixture.connection, "local_grid_identifier", "srid");
        invalid_metadata(fixture.connection);
        execute(fixture.connection, "CREATE TABLE arrow_transaction_guard(i INTEGER)");
        execute(fixture.connection, "BEGIN");
        execute(fixture.connection, "INSERT INTO arrow_transaction_guard VALUES (42)");
        for (const char *sql : queries) {
            roundtrip(fixture.connection, sql);
        }
        roundtrip(fixture.connection, queries[0], true);
        foreign_crs(fixture.connection, "CUSTOM:123", "authority_code");
        foreign_crs(fixture.connection, "local_grid_identifier", "srid");
        invalid_metadata(fixture.connection);
        Result visible;
        query(fixture.connection, "SELECT count(*) FROM arrow_transaction_guard", visible);
        check(duckdb_value_int64(&visible.value, 0, 0) == 1, "Conversion aborted the caller transaction");
        execute(fixture.connection, "ROLLBACK");
        Result rolled_back;
        query(fixture.connection, "SELECT count(*) FROM arrow_transaction_guard", rolled_back);
        check(duckdb_value_int64(&rolled_back.value, 0, 0) == 0, "Conversion committed the caller transaction");
        puts("GeoArrow CRS survives scalar and nested conversion; caller transactions remain intact");
        return 0;
    } catch (const std::exception &error) {
        fprintf(stderr, "%s\n", error.what());
        return 1;
    }
}
