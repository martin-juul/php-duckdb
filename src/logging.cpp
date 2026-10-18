/* Custom logging captures native records without running PHP on DuckDB threads. */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"
#include "logging.h"

#include <deque>
#include <exception>
#include <limits>

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

static constexpr size_t log_storage_max_bytes = 8 * 1024 * 1024;

struct native_log_entry {
    int64_t timestamp;
    std::string level;
    std::string type;
    std::string message;

    size_t bytes() const {
        return sizeof(native_log_entry) + level.size() + type.size() + message.size();
    }
};

struct log_storage_state {
    explicit log_storage_state(size_t capacity) : capacity(capacity) {
    }

    std::mutex mutex;
    std::deque<native_log_entry> entries;
    size_t capacity;
    size_t bytes = 0;
    std::atomic<zend_long> dropped{0};

    void drop() noexcept {
        auto count = dropped.load(std::memory_order_relaxed);
        while (count < ZEND_LONG_MAX &&
               !dropped.compare_exchange_weak(count, count + 1, std::memory_order_relaxed)) {
        }
    }
};

zend_class_entry *duckdb_log_storage_ce;
static zend_object_handlers log_storage_handlers;

struct php_log_storage_object {
    std::shared_ptr<log_storage_state> state;
    zend_object std;
};

static php_log_storage_object *log_storage_object(zend_object *obj) {
    return reinterpret_cast<php_log_storage_object *>(reinterpret_cast<char *>(obj) -
                                                     offsetof(php_log_storage_object, std));
}

static zend_object *log_storage_create(zend_class_entry *ce) {
    auto *obj = static_cast<php_log_storage_object *>(zend_object_alloc(sizeof(php_log_storage_object), ce));
    new (&obj->state) std::shared_ptr<log_storage_state>();
    zend_object_std_init(&obj->std, ce);
    object_properties_init(&obj->std, ce);
    obj->std.handlers = &log_storage_handlers;
    return &obj->std;
}

static void log_storage_free(zend_object *obj) {
    log_storage_object(obj)->state.~shared_ptr();
    zend_object_std_dtor(obj);
}

void duckdb_register_log_storage_class(zend_class_entry *ce) {
    duckdb_log_storage_ce = ce;
    ce->create_object = log_storage_create;
    ce->ce_flags |= ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;
    memcpy(&log_storage_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    log_storage_handlers.offset = offsetof(php_log_storage_object, std);
    log_storage_handlers.free_obj = log_storage_free;
    log_storage_handlers.clone_obj = nullptr;
}

static std::shared_ptr<log_storage_state> log_storage_from_zval(zval *value) {
    auto state = log_storage_object(Z_OBJ_P(value))->state;
    if (!duckdb_initialized_guard(static_cast<bool>(state), "DuckDB\\LogStorage")) {
        return nullptr;
    }
    return state;
}

/* Bound scans before allocating, including for an individual oversized entry. */
static bool log_string_length(const char *value, size_t &length, size_t &remaining) noexcept {
    length = 0;
    if (!value) {
        return true;
    }
    while (length < remaining && value[length] != '\0') {
        ++length;
    }
    if (value[length] != '\0') {
        return false;
    }
    remaining -= length;
    return true;
}

static void log_storage_write(void *extra_data, duckdb_timestamp *timestamp, const char *level,
                              const char *type, const char *message) noexcept {
    auto *state = static_cast<log_storage_state *>(extra_data);
    try {
        size_t remaining = log_storage_max_bytes - sizeof(native_log_entry);
        size_t level_length;
        size_t type_length;
        size_t message_length;
        if (!log_string_length(level, level_length, remaining) ||
            !log_string_length(type, type_length, remaining) ||
            !log_string_length(message, message_length, remaining)) {
            state->drop();
            return;
        }

        native_log_entry entry{timestamp ? timestamp->micros : 0,
                               std::string(level ? level : "", level_length),
                               std::string(type ? type : "", type_length),
                               std::string(message ? message : "", message_length)};
        const size_t bytes = entry.bytes();
        std::unique_lock<std::mutex> lock(state->mutex, std::try_to_lock);
        if (!lock.owns_lock()) {
            state->drop();
            return;
        }

        while (state->entries.size() >= state->capacity || state->bytes > log_storage_max_bytes - bytes) {
            state->bytes -= state->entries.front().bytes();
            state->entries.pop_front();
            state->drop();
        }
        state->entries.push_back(std::move(entry));
        state->bytes += bytes;
    } catch (...) {
        /* No exception may cross the C callback or touch Zend on a worker. */
        state->drop();
    }
}

PHP_METHOD(DuckDB_LogStorage, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long capacity = 1024;
    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(capacity)
    ZEND_PARSE_PARAMETERS_END();

    auto *obj = log_storage_object(Z_OBJ_P(ZEND_THIS));
    if (obj->state) {
        zend_throw_error(nullptr, "DuckDB\\LogStorage object is already initialized");
        RETURN_THROWS();
    }
    if (capacity <= 0) {
        zend_argument_value_error(1, "must be greater than zero");
        RETURN_THROWS();
    }

    try {
        obj->state = std::make_shared<log_storage_state>(static_cast<size_t>(capacity));
    } catch (const std::exception &error) {
        duckdb_throw_msg(error.what());
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_LogStorage, drain) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto state = log_storage_from_zval(ZEND_THIS);
    if (!state) {
        RETURN_THROWS();
    }

    try {
        std::deque<native_log_entry> entries;
        {
            std::lock_guard<std::mutex> lock(state->mutex);
            entries.swap(state->entries);
            state->bytes = 0;
        }

        array_init_size(return_value, static_cast<uint32_t>(entries.size()));
        for (const auto &entry : entries) {
            zval row;
            array_init_size(&row, 4);
            add_assoc_long(&row, "timestamp", static_cast<zend_long>(entry.timestamp));
            add_assoc_stringl(&row, "level", entry.level.data(), entry.level.size());
            add_assoc_stringl(&row, "type", entry.type.data(), entry.type.size());
            add_assoc_stringl(&row, "message", entry.message.data(), entry.message.size());
            add_next_index_zval(return_value, &row);
        }
    } catch (const std::exception &error) {
        duckdb_throw_msg(error.what());
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_LogStorage, droppedCount) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_NONE();
    auto state = log_storage_from_zval(ZEND_THIS);
    if (!state) {
        RETURN_THROWS();
    }
    RETURN_LONG(state->dropped.load(std::memory_order_relaxed));
}

PHP_METHOD(DuckDB_Database, registerLogStorage) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_string *name;
    zval *storage;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(name)
        Z_PARAM_OBJECT_OF_CLASS(storage, duckdb_log_storage_ce)
    ZEND_PARSE_PARAMETERS_END();

    auto *intern = Z_DUCKDB_DATABASE_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->inner), "DuckDB\\Database")) {
        RETURN_THROWS();
    }
    auto state = log_storage_from_zval(storage);
    if (!state) {
        RETURN_THROWS();
    }
    if (ZSTR_LEN(name) == 0 || memchr(ZSTR_VAL(name), '\0', ZSTR_LEN(name))) {
        zend_argument_value_error(1, "must be non-empty and contain no NUL byte");
        RETURN_THROWS();
    }

    try {
        std::string lower(ZSTR_VAL(name), ZSTR_LEN(name));
        for (auto &c : lower) {
            if (c >= 'A' && c <= 'Z') {
                c = static_cast<char>(c - 'A' + 'a');
            }
        }
        if (lower == "memory" || lower == "stdout" || lower == "file") {
            zend_argument_value_error(1, "must not name a built-in log storage");
            RETURN_THROWS();
        }

        auto &db = *intern->inner;
        std::lock_guard<std::mutex> lock(db.logging_mutex);
        if (db.log_storages.find(lower) != db.log_storages.end()) {
            zend_argument_value_error(1, "is already registered in this database");
            RETURN_THROWS();
        }

        duckdb_scoped<duckdb_log_storage, duckdb_destroy_log_storage> native(duckdb_create_log_storage());
        if (!native) {
            throw std::bad_alloc();
        }
        duckdb_log_storage_set_name(native.get(), lower.c_str());
        duckdb_log_storage_set_write_log_entry(native.get(), log_storage_write);
        /* The database map owns this state through duckdb_close. No C delete
         * callback: the pinned SDK double-deletes extra data on registration
         * failure if both the wrapper and temporary storage own it. */
        duckdb_log_storage_set_extra_data(native.get(), state.get(), nullptr);

        /* Allocate map storage before publishing its pointer to DuckDB. */
        auto inserted = db.log_storages.emplace(lower, state);
        duckdb_state status;
        try {
            status = duckdb_register_log_storage(db.db, native.get());
        } catch (...) {
            db.log_storages.erase(inserted.first);
            throw;
        }
        if (status != DuckDBSuccess) {
            db.log_storages.erase(inserted.first);
            duckdb_throw_msg("Failed to register custom log storage");
            RETURN_THROWS();
        }
    } catch (const std::exception &error) {
        duckdb_throw_msg(error.what());
        RETURN_THROWS();
    }
}
