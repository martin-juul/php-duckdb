/*
  +----------------------------------------------------------------------+
  | duckdb - native DuckDB driver for PHP                                |
  +----------------------------------------------------------------------+
  | Copyright (c) Martin Juul Christiansen (https://juul.xyz)            |
  +----------------------------------------------------------------------+
  | This source file is subject to the MIT license that is bundled with  |
  | this package in the file LICENSE.                                    |
  +----------------------------------------------------------------------+
  | DuckDB\Result and DuckDB\ResultIterator, including the recursive     |
  | vector decoder that converts DuckDB's columnar chunks into PHP       |
  | values.                                                              |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_duckdb_cxx_compat.h"

/* php_date.h predates C++ linkage guards; wrap it ourselves. */
extern "C" {
#include "ext/date/php_date.h"
}

#include "php_duckdb.h"

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif

enum fetch_mode {
    FETCH_MODE_ASSOC,
    FETCH_MODE_NUM,
    FETCH_MODE_BOTH,
};

/* ================================================================== */
/* Integer / decimal formatting helpers                               */
/* ================================================================== */

/* Render an unsigned 128-bit integer (hi:lo) as a decimal string. */
static std::string duckdb_u128_to_string(uint64_t hi, uint64_t lo) {
    if (hi == 0) {
        return std::to_string(lo);
    }
    /* Divide the 4x32-bit limbs by 10^9 repeatedly, collecting
     * little-endian digit groups (a 128-bit value needs at most 5). */
    uint32_t limbs[4] = {
        (uint32_t)lo, (uint32_t)(lo >> 32), (uint32_t)hi, (uint32_t)(hi >> 32)};
    uint32_t groups[5];
    int ngroups = 0;
    while (limbs[0] || limbs[1] || limbs[2] || limbs[3]) {
        uint64_t rem = 0;
        for (int i = 3; i >= 0; i--) {
            uint64_t cur = (rem << 32) | limbs[i];
            limbs[i] = (uint32_t)(cur / 1000000000ULL);
            rem = cur % 1000000000ULL;
        }
        groups[ngroups++] = (uint32_t)rem;
    }
    /* Most significant group unpadded, the rest zero-padded to 9 digits. */
    std::string result = std::to_string(groups[--ngroups]);
    char buf[16];
    while (ngroups > 0) {
        snprintf(buf, sizeof(buf), "%09u", groups[--ngroups]);
        result += buf;
    }
    return result;
}

static std::string duckdb_hugeint_to_string(duckdb_hugeint value) {
    if (value.upper < 0) {
        uint64_t lo = ~value.lower + 1;
        uint64_t hi = ~(uint64_t)value.upper + (lo == 0 ? 1 : 0);
        return "-" + duckdb_u128_to_string(hi, lo);
    }
    return duckdb_u128_to_string((uint64_t)value.upper, value.lower);
}

static std::string duckdb_uhugeint_to_string(duckdb_uhugeint value) {
    return duckdb_u128_to_string(value.upper, value.lower);
}

/* Render a DECIMAL with the given scale exactly, keeping trailing zeros. */
static std::string duckdb_decimal_render(duckdb_logical_type type, void *data, idx_t row) {
    uint8_t scale = duckdb_decimal_scale(type);
    bool negative = false;
    uint64_t hi = 0, lo = 0;

    switch (duckdb_decimal_internal_type(type)) {
        case DUCKDB_TYPE_SMALLINT: {
            int16_t v = ((int16_t *)data)[row];
            negative = v < 0;
            lo = negative ? (uint64_t)(-(int64_t)v) : (uint64_t)v;
            break;
        }
        case DUCKDB_TYPE_INTEGER: {
            int32_t v = ((int32_t *)data)[row];
            negative = v < 0;
            lo = negative ? (uint64_t)(-(int64_t)v) : (uint64_t)v;
            break;
        }
        case DUCKDB_TYPE_BIGINT: {
            int64_t v = ((int64_t *)data)[row];
            negative = v < 0;
            lo = negative ? (uint64_t)(0 - (uint64_t)v) : (uint64_t)v;
            break;
        }
        default: { /* DUCKDB_TYPE_HUGEINT */
            duckdb_hugeint v = ((duckdb_hugeint *)data)[row];
            negative = v.upper < 0;
            if (negative) {
                lo = ~v.lower + 1;
                hi = ~(uint64_t)v.upper + (lo == 0 ? 1 : 0);
            } else {
                lo = v.lower;
                hi = (uint64_t)v.upper;
            }
            break;
        }
    }

    std::string digits = duckdb_u128_to_string(hi, lo);
    if (scale > 0) {
        if (digits.length() <= scale) {
            digits.insert(0, (size_t)scale - digits.length() + 1, '0');
        }
        digits.insert(digits.length() - scale, ".");
    }
    return negative ? "-" + digits : digits;
}

/* Canonical string form of a UUID stored as a hugeint. */
static std::string duckdb_uuid_to_string(duckdb_hugeint value) {
    uint64_t upper = (uint64_t)value.upper ^ (1ULL << 63);
    uint64_t lower = value.lower;

    char buf[37];
    snprintf(buf, sizeof(buf), "%08x-%04x-%04x-%04x-%04x%08x",
             (unsigned int)(upper >> 32),
             (unsigned int)((upper >> 16) & 0xFFFF),
             (unsigned int)(upper & 0xFFFF),
             (unsigned int)(lower >> 48),
             (unsigned int)((lower >> 32) & 0xFFFF),
             (unsigned int)(lower & 0xFFFFFFFFULL));
    return std::string(buf, 36);
}

/* ================================================================== */
/* Date/time helpers                                                  */
/* ================================================================== */

/* Create a \DateTimeImmutable in UTC. Falls back to a string for values
 * outside the range PHP's date parser accepts (e.g. years above 9999). */
static void duckdb_make_datetime(zval *out, int64_t year, int month, int day,
                                 int hour, int minute, int second, int64_t micros) {
    char buf[80];
    int len;
    if (micros != 0) {
        len = snprintf(buf, sizeof(buf), "%04d-%02d-%02d %02d:%02d:%02d.%06d UTC",
                       (int)year, month, day, hour, minute, second, (int)micros);
    } else {
        len = snprintf(buf, sizeof(buf), "%04d-%02d-%02d %02d:%02d:%02d UTC",
                       (int)year, month, day, hour, minute, second);
    }

    php_date_instantiate(php_date_get_immutable_ce(), out);
    if (!php_date_initialize(Z_PHPDATE_P(out), buf, (size_t)len, NULL, NULL, 0)) {
        zval_ptr_dtor(out);
        ZVAL_STRINGL(out, buf, (size_t)len);
    }
}

static void duckdb_make_datetime_from_micros(zval *out, int64_t micros, bool finite) {
    if (!finite) {
        ZVAL_STRING(out, micros > 0 ? "infinity" : "-infinity");
        return;
    }
    duckdb_timestamp_struct ts = duckdb_from_timestamp({micros});
    duckdb_make_datetime(out, ts.date.year, ts.date.month, ts.date.day,
                         ts.time.hour, ts.time.min, ts.time.sec, ts.time.micros);
}

/* Multiply a timestamp magnitude by `factor` to microseconds with
 * overflow detection. */
static bool duckdb_timestamp_scale_to_micros(int64_t magnitude, int64_t factor, int64_t *out) {
    if (magnitude > INT64_MAX / factor || magnitude < INT64_MIN / factor) {
        return false;
    }
    *out = magnitude * factor;
    return true;
}

/* ================================================================== */
/* Vector decoder                                                     */
/* ================================================================== */

struct decode_ctx {
    result_data *data;
    idx_t column_index;  /* top-level result column */
    uint64_t abs_row;    /* absolute row of the current cell */
    uint32_t depth;
};

static bool duckdb_decode_value(decode_ctx *ctx, duckdb_vector vec, duckdb_logical_type type,
                                idx_t row, zval *out);

static inline bool duckdb_vector_row_is_null(duckdb_vector vec, idx_t row) {
    uint64_t *validity = duckdb_vector_get_validity(vec);
    return validity && !duckdb_validity_row_is_valid(validity, row);
}

/* Decode a nested (child) vector value; child types are owned here. */
static bool duckdb_decode_child(decode_ctx *ctx, duckdb_vector vec, duckdb_logical_type child_type,
                                idx_t row, zval *out) {
    if (ctx->depth >= DUCKDB_MAX_NESTING_DEPTH) {
        /* Decoding recurses on the C stack; reject pathologically nested
         * values instead of risking a stack overflow. */
        duckdb_destroy_logical_type(&child_type);
        zend_throw_exception_ex(duckdb_exception_ce, 0,
                                "Value is nested deeper than %u levels and cannot be decoded",
                                DUCKDB_MAX_NESTING_DEPTH);
        return false;
    }
    ctx->depth++;
    bool ok = duckdb_decode_value(ctx, vec, child_type, row, out);
    ctx->depth--;
    duckdb_destroy_logical_type(&child_type);
    return ok;
}

static bool duckdb_decode_list(decode_ctx *ctx, duckdb_vector vec, duckdb_logical_type type,
                               idx_t row, zval *out) {
    duckdb_list_entry *entries = (duckdb_list_entry *)duckdb_vector_get_data(vec);
    duckdb_list_entry entry = entries[row];
    duckdb_vector child = duckdb_list_vector_get_child(vec);

    array_init_size(out, (uint32_t)entry.length);
    for (idx_t i = 0; i < entry.length; i++) {
        zval elem;
        if (!duckdb_decode_child(ctx, child, duckdb_list_type_child_type(type), entry.offset + i, &elem)) {
            zval_ptr_dtor(out);
            return false;
        }
        add_next_index_zval(out, &elem);
    }
    return true;
}

static bool duckdb_decode_array(decode_ctx *ctx, duckdb_vector vec, duckdb_logical_type type,
                                idx_t row, zval *out) {
    idx_t size = duckdb_array_type_array_size(type);
    duckdb_vector child = duckdb_array_vector_get_child(vec);

    array_init_size(out, (uint32_t)size);
    for (idx_t i = 0; i < size; i++) {
        zval elem;
        if (!duckdb_decode_child(ctx, child, duckdb_array_type_child_type(type), row * size + i, &elem)) {
            zval_ptr_dtor(out);
            return false;
        }
        add_next_index_zval(out, &elem);
    }
    return true;
}

static bool duckdb_decode_struct(decode_ctx *ctx, duckdb_vector vec, duckdb_logical_type type,
                                 idx_t row, zval *out) {
    idx_t count = duckdb_struct_type_child_count(type);
    array_init_size(out, (uint32_t)count);

    for (idx_t i = 0; i < count; i++) {
        char *name = duckdb_struct_type_child_name(type, i);
        duckdb_vector child = duckdb_struct_vector_get_child(vec, i);

        zval elem;
        bool ok = duckdb_decode_child(ctx, child, duckdb_struct_type_child_type(type, i), row, &elem);
        if (!ok) {
            if (name) {
                duckdb_free(name);
            }
            zval_ptr_dtor(out);
            return false;
        }
        add_assoc_zval(out, name ? name : "?", &elem);
        if (name) {
            duckdb_free(name);
        }
    }
    return true;
}

static bool duckdb_decode_map(decode_ctx *ctx, duckdb_vector vec, duckdb_logical_type type,
                              idx_t row, zval *out) {
    /* MAP is stored as a LIST of STRUCT{key, value}. */
    duckdb_list_entry *entries = (duckdb_list_entry *)duckdb_vector_get_data(vec);
    duckdb_list_entry entry = entries[row];
    duckdb_vector struct_vec = duckdb_list_vector_get_child(vec);
    duckdb_vector key_vec = duckdb_struct_vector_get_child(struct_vec, 0);
    duckdb_vector value_vec = duckdb_struct_vector_get_child(struct_vec, 1);

    /* PHP arrays only support integer and string keys. Scalars map to a
     * plain associative array; anything else becomes a list of
     * ['key' => k, 'value' => v] pairs. */
    duckdb_type key_id;
    {
        duckdb_logical_type key_type = duckdb_map_type_key_type(type);
        key_id = duckdb_get_type_id(key_type);
        duckdb_destroy_logical_type(&key_type);
    }
    bool scalar_keys;
    switch (key_id) {
        case DUCKDB_TYPE_TINYINT:
        case DUCKDB_TYPE_SMALLINT:
        case DUCKDB_TYPE_INTEGER:
        case DUCKDB_TYPE_BIGINT:
        case DUCKDB_TYPE_UTINYINT:
        case DUCKDB_TYPE_USMALLINT:
        case DUCKDB_TYPE_UINTEGER:
        case DUCKDB_TYPE_UBIGINT:
        case DUCKDB_TYPE_VARCHAR:
        case DUCKDB_TYPE_ENUM:
            scalar_keys = true;
            break;
        default:
            scalar_keys = false;
            break;
    }

    array_init_size(out, (uint32_t)entry.length);
    bool ok = true;
    for (idx_t i = 0; i < entry.length && ok; i++) {
        idx_t child_row = entry.offset + i;
        zval key_zv, value_zv;
        if (!duckdb_decode_child(ctx, key_vec, duckdb_map_type_key_type(type), child_row, &key_zv)) {
            ok = false;
            break;
        }
        if (!duckdb_decode_child(ctx, value_vec, duckdb_map_type_value_type(type), child_row, &value_zv)) {
            zval_ptr_dtor(&key_zv);
            ok = false;
            break;
        }

        if (scalar_keys) {
            if (Z_TYPE(key_zv) == IS_LONG) {
                add_index_zval(out, Z_LVAL(key_zv), &value_zv);
            } else if (Z_TYPE(key_zv) == IS_STRING) {
                add_assoc_zval(out, Z_STRVAL(key_zv), &value_zv);
            } else { /* NULL key */
                add_assoc_zval(out, "", &value_zv);
            }
            zval_ptr_dtor(&key_zv); /* the key was copied into the array */
        } else {
            zval pair;
            array_init_size(&pair, 2);
            add_assoc_zval(&pair, "key", &key_zv);
            add_assoc_zval(&pair, "value", &value_zv);
            add_next_index_zval(out, &pair);
        }
    }

    if (!ok) {
        zval_ptr_dtor(out);
        return false;
    }
    return true;
}

static bool duckdb_decode_union(decode_ctx *ctx, duckdb_vector vec, duckdb_logical_type type,
                                idx_t row, zval *out) {
    /* Union vectors store a UTINYINT tag vector as their first child. */
    duckdb_vector tag_vec = duckdb_struct_vector_get_child(vec, 0);
    uint8_t tag = ((uint8_t *)duckdb_vector_get_data(tag_vec))[row];
    if (tag >= duckdb_union_type_member_count(type)) {
        ZVAL_NULL(out);
        return true;
    }
    duckdb_vector member = duckdb_struct_vector_get_child(vec, tag + 1);
    return duckdb_decode_child(ctx, member, duckdb_union_type_member_type(type, tag), row, out);
}

static void duckdb_decode_bit(duckdb_vector vec, idx_t row, zval *out) {
    duckdb_string_t *data = (duckdb_string_t *)duckdb_vector_get_data(vec);
    duckdb_string_t str = data[row];
    const uint8_t *bytes = (const uint8_t *)duckdb_string_t_data(&str);
    uint32_t len = duckdb_string_t_length(str);

    if (len <= 1) {
        ZVAL_EMPTY_STRING(out);
        return;
    }
    /* Bitstring layout (mirrors duckdb Bit::ToString): byte 0 holds the number
     * of padding bits; those padding bits occupy the HIGH bits of the first
     * data byte (and are set to 1), so they must be skipped, not read. */
    uint8_t padding = bytes[0];
    uint32_t bit_count = (len - 1) * 8 - padding;

    std::string result;
    result.reserve(bit_count);
    uint32_t emitted = 0;
    for (uint32_t bit_idx = padding; bit_idx < 8 && emitted < bit_count; bit_idx++, emitted++) {
        result += ((bytes[1] >> (7 - bit_idx)) & 1) ? '1' : '0';
    }
    for (uint32_t byte_idx = 2; byte_idx < len && emitted < bit_count; byte_idx++) {
        for (uint32_t bit_idx = 0; bit_idx < 8 && emitted < bit_count; bit_idx++, emitted++) {
            result += ((bytes[byte_idx] >> (7 - bit_idx)) & 1) ? '1' : '0';
        }
    }
    ZVAL_STRINGL(out, result.c_str(), result.length());
}

static bool duckdb_decode_value(decode_ctx *ctx, duckdb_vector vec, duckdb_logical_type type,
                                idx_t row, zval *out) {
    if (duckdb_vector_row_is_null(vec, row)) {
        ZVAL_NULL(out);
        return true;
    }

    void *data = duckdb_vector_get_data(vec);

    switch (duckdb_get_type_id(type)) {
        case DUCKDB_TYPE_BOOLEAN:
            ZVAL_BOOL(out, ((bool *)data)[row]);
            return true;
        case DUCKDB_TYPE_TINYINT:
            ZVAL_LONG(out, ((int8_t *)data)[row]);
            return true;
        case DUCKDB_TYPE_SMALLINT:
            ZVAL_LONG(out, ((int16_t *)data)[row]);
            return true;
        case DUCKDB_TYPE_INTEGER:
            ZVAL_LONG(out, ((int32_t *)data)[row]);
            return true;
        case DUCKDB_TYPE_BIGINT:
            ZVAL_LONG(out, (zend_long)((int64_t *)data)[row]);
            return true;
        case DUCKDB_TYPE_UTINYINT:
            ZVAL_LONG(out, ((uint8_t *)data)[row]);
            return true;
        case DUCKDB_TYPE_USMALLINT:
            ZVAL_LONG(out, ((uint16_t *)data)[row]);
            return true;
        case DUCKDB_TYPE_UINTEGER:
            ZVAL_LONG(out, ((uint32_t *)data)[row]);
            return true;
        case DUCKDB_TYPE_UBIGINT: {
            uint64_t v = ((uint64_t *)data)[row];
            if (v <= (uint64_t)ZEND_LONG_MAX) {
                ZVAL_LONG(out, (zend_long)v);
            } else {
                std::string str = std::to_string(v);
                ZVAL_STRINGL(out, str.c_str(), str.length());
            }
            return true;
        }
        case DUCKDB_TYPE_HUGEINT: {
            duckdb_hugeint v = ((duckdb_hugeint *)data)[row];
            if (v.upper == 0 && v.lower <= (uint64_t)ZEND_LONG_MAX) {
                ZVAL_LONG(out, (zend_long)v.lower);
            } else if (v.upper == -1 && v.lower >= ((uint64_t)1 << 63)) {
                ZVAL_LONG(out, (zend_long)v.lower);
            } else {
                std::string str = duckdb_hugeint_to_string(v);
                ZVAL_STRINGL(out, str.c_str(), str.length());
            }
            return true;
        }
        case DUCKDB_TYPE_UHUGEINT: {
            duckdb_uhugeint v = ((duckdb_uhugeint *)data)[row];
            if (v.upper == 0 && v.lower <= (uint64_t)ZEND_LONG_MAX) {
                ZVAL_LONG(out, (zend_long)v.lower);
            } else {
                std::string str = duckdb_uhugeint_to_string(v);
                ZVAL_STRINGL(out, str.c_str(), str.length());
            }
            return true;
        }
        case DUCKDB_TYPE_FLOAT:
            ZVAL_DOUBLE(out, ((float *)data)[row]);
            return true;
        case DUCKDB_TYPE_DOUBLE:
            ZVAL_DOUBLE(out, ((double *)data)[row]);
            return true;
        case DUCKDB_TYPE_DECIMAL: {
            std::string str = duckdb_decimal_render(type, data, row);
            ZVAL_STRINGL(out, str.c_str(), str.length());
            return true;
        }
        case DUCKDB_TYPE_VARCHAR: {
            duckdb_string_t *strings = (duckdb_string_t *)data;
            const char *str = duckdb_string_t_data(&strings[row]);
            ZVAL_STRINGL(out, str, duckdb_string_t_length(strings[row]));
            return true;
        }
        case DUCKDB_TYPE_BLOB:
        case DUCKDB_TYPE_GEOMETRY: {
            duckdb_string_t *strings = (duckdb_string_t *)data;
            const char *str = duckdb_string_t_data(&strings[row]);
            ZVAL_STRINGL(out, str, duckdb_string_t_length(strings[row]));
            return true;
        }
        case DUCKDB_TYPE_BIT:
            duckdb_decode_bit(vec, row, out);
            return true;
        case DUCKDB_TYPE_UUID: {
            std::string str = duckdb_uuid_to_string(((duckdb_hugeint *)data)[row]);
            ZVAL_STRINGL(out, str.c_str(), str.length());
            return true;
        }
        case DUCKDB_TYPE_ENUM: {
            idx_t dictionary_index = 0;
            switch (duckdb_enum_internal_type(type)) {
                case DUCKDB_TYPE_UTINYINT:
                    dictionary_index = ((uint8_t *)data)[row];
                    break;
                case DUCKDB_TYPE_USMALLINT:
                    dictionary_index = ((uint16_t *)data)[row];
                    break;
                default: /* DUCKDB_TYPE_UINTEGER / UBIGINT */
                    dictionary_index = ((uint32_t *)data)[row];
                    break;
            }
            char *str = duckdb_enum_dictionary_value(type, dictionary_index);
            if (str == nullptr) {
                ZVAL_NULL(out);
                return true;
            }
            ZVAL_STRING(out, str);
            duckdb_free(str);
            return true;
        }
        case DUCKDB_TYPE_DATE: {
            duckdb_date date = ((duckdb_date *)data)[row];
            if (!duckdb_is_finite_date(date)) {
                ZVAL_STRING(out, date.days > 0 ? "infinity" : "-infinity");
                return true;
            }
            duckdb_date_struct d = duckdb_from_date(date);
            duckdb_make_datetime(out, d.year, d.month, d.day, 0, 0, 0, 0);
            return true;
        }
        case DUCKDB_TYPE_TIME: {
            duckdb_time_struct t = duckdb_from_time(((duckdb_time *)data)[row]);
            char buf[32];
            int len;
            if (t.micros != 0) {
                len = snprintf(buf, sizeof(buf), "%02d:%02d:%02d.%06d",
                               t.hour, t.min, t.sec, t.micros);
            } else {
                len = snprintf(buf, sizeof(buf), "%02d:%02d:%02d", t.hour, t.min, t.sec);
            }
            ZVAL_STRINGL(out, buf, (size_t)len);
            return true;
        }
        case DUCKDB_TYPE_TIME_TZ: {
            duckdb_time_tz_struct t = duckdb_from_time_tz(((duckdb_time_tz *)data)[row]);
            int offset = t.offset; /* seconds east of UTC */
            char sign = offset < 0 ? '-' : '+';
            if (offset < 0) {
                offset = -offset;
            }
            char buf[48];
            int len;
            if (t.time.micros != 0) {
                len = snprintf(buf, sizeof(buf), "%02d:%02d:%02d.%06d%c%02d:%02d",
                               t.time.hour, t.time.min, t.time.sec, t.time.micros,
                               sign, offset / 3600, (offset / 60) % 60);
            } else {
                len = snprintf(buf, sizeof(buf), "%02d:%02d:%02d%c%02d:%02d",
                               t.time.hour, t.time.min, t.time.sec,
                               sign, offset / 3600, (offset / 60) % 60);
            }
            ZVAL_STRINGL(out, buf, (size_t)len);
            return true;
        }
        case DUCKDB_TYPE_TIME_NS: {
            int64_t ns = ((int64_t *)data)[row];
            int64_t hours = ns / 3600000000000LL;
            int64_t minutes = (ns / 60000000000LL) % 60;
            int64_t seconds = (ns / 1000000000LL) % 60;
            int64_t fraction = ns % 1000000000LL;
            char buf[40];
            int len;
            if (fraction != 0) {
                len = snprintf(buf, sizeof(buf), "%02d:%02d:%02d.%09d",
                               (int)hours, (int)minutes, (int)seconds, (int)fraction);
            } else {
                len = snprintf(buf, sizeof(buf), "%02d:%02d:%02d",
                               (int)hours, (int)minutes, (int)seconds);
            }
            ZVAL_STRINGL(out, buf, (size_t)len);
            return true;
        }
        case DUCKDB_TYPE_TIMESTAMP:
        case DUCKDB_TYPE_TIMESTAMP_TZ: {
            duckdb_timestamp ts = ((duckdb_timestamp *)data)[row];
            duckdb_make_datetime_from_micros(out, ts.micros, duckdb_is_finite_timestamp(ts));
            return true;
        }
        case DUCKDB_TYPE_TIMESTAMP_S: {
            duckdb_timestamp_s ts = ((duckdb_timestamp_s *)data)[row];
            int64_t micros;
            if (!duckdb_timestamp_scale_to_micros(ts.seconds, 1000000LL, &micros)) {
                break; /* out of int64 micros range: string fallback below */
            }
            duckdb_make_datetime_from_micros(out, micros, duckdb_is_finite_timestamp_s(ts));
            return true;
        }
        case DUCKDB_TYPE_TIMESTAMP_MS: {
            duckdb_timestamp_ms ts = ((duckdb_timestamp_ms *)data)[row];
            int64_t micros;
            if (!duckdb_timestamp_scale_to_micros(ts.millis, 1000LL, &micros)) {
                break; /* out of int64 micros range: string fallback below */
            }
            duckdb_make_datetime_from_micros(out, micros, duckdb_is_finite_timestamp_ms(ts));
            return true;
        }
        case DUCKDB_TYPE_TIMESTAMP_NS: {
            duckdb_timestamp_ns ts = ((duckdb_timestamp_ns *)data)[row];
            duckdb_make_datetime_from_micros(out, ts.nanos / 1000LL,
                                             duckdb_is_finite_timestamp_ns(ts));
            return true;
        }
        case DUCKDB_TYPE_INTERVAL:
            duckdb_interval_instantiate(out, ((duckdb_interval *)data)[row]);
            return true;
        case DUCKDB_TYPE_LIST:
            return duckdb_decode_list(ctx, vec, type, row, out);
        case DUCKDB_TYPE_ARRAY:
            return duckdb_decode_array(ctx, vec, type, row, out);
        case DUCKDB_TYPE_STRUCT:
            return duckdb_decode_struct(ctx, vec, type, row, out);
        case DUCKDB_TYPE_MAP:
            return duckdb_decode_map(ctx, vec, type, row, out);
        case DUCKDB_TYPE_UNION:
            return duckdb_decode_union(ctx, vec, type, row, out);
        case DUCKDB_TYPE_SQLNULL:
            ZVAL_NULL(out);
            return true;
        default:
            break;
    }

    /* Types without a public vector layout (VARIANT, ...): fall back to
     * the canonical string rendering, which is only available for
     * materialized results. duckdb_value_varchar is deprecated upstream,
     * but no non-deprecated API renders an arbitrary result cell as a
     * string; kept deliberately, isolated to this call site. */
    if (ctx->depth == 0 && !ctx->data->streaming) {
        char *str = duckdb_value_varchar(&ctx->data->result, ctx->column_index, ctx->abs_row);
        if (str) {
            ZVAL_STRING(out, str);
            duckdb_free(str);
        } else {
            ZVAL_NULL(out);
        }
        return true;
    }

    zend_throw_exception_ex(duckdb_exception_ce, 0,
                            "Values of type %s cannot be fetched from streaming or nested results",
                            duckdb_type_name(duckdb_get_type_id(type)));
    return false;
}

/* ================================================================== */
/* Row fetching                                                       */
/* ================================================================== */

/* Ensure a data chunk with unread rows is available.
 *
 * For streaming results the chunk fetch runs inside DuckDB's execution
 * engine on the connection, so it must hold the connection mutex (an
 * async worker may be using the same connection). Materialized results
 * are self-contained and need no lock. */
static bool duckdb_result_fetch_chunk(result_data *d) {
    if (d->chunk && d->chunk_pos < d->chunk_size) {
        return true;
    }
    if (d->chunk) {
        duckdb_destroy_data_chunk(&d->chunk);
        d->chunk = nullptr;
    }

    if (d->streaming && d->stmt_keepalive) {
        /* DuckDB permits only one open streaming result per connection: a
         * newer execution invalidates this stream and duckdb_fetch_chunk
         * would silently report end-of-data. Detect that via the connection
         * epoch and fail loudly instead of truncating the result. The
         * check runs INSIDE the connection mutex: executions bump the
         * epoch under the same mutex, so a checked-fresh epoch cannot
         * become stale before the fetch runs. */
        std::lock_guard<std::mutex> lk(d->stmt_keepalive->conn->mutex);
        if (d->epoch != d->stmt_keepalive->conn->execution_epoch.load(std::memory_order_relaxed)) {
            duckdb_throw_msg("This streaming result was invalidated by a newer query on the same "
                             "connection (DuckDB allows one open streaming result per connection). "
                             "Use a separate connection per concurrent stream.");
            return false;
        }
        d->chunk = duckdb_fetch_chunk(d->result);
    } else {
        d->chunk = duckdb_fetch_chunk(d->result);
    }

    if (d->chunk == nullptr) {
        /* duckdb_fetch_chunk returns NULL both at end-of-data AND when a
         * streaming query fails mid-flight ("Returns NULL if the result
         * has an error"). Check the result's error state so a failed
         * stream throws instead of silently truncating the result set.
         * The result stays owned by us (the caller may fetch again and
         * re-trigger the same error), so throw without destroying it. */
        if (duckdb_result_error_type(&d->result) != DUCKDB_ERROR_INVALID) {
            const char *err = duckdb_result_error(&d->result);
            duckdb_throw_error(duckdb_result_error_type(&d->result),
                               err ? err : "Query failed during streaming");
            return false;
        }
        d->exhausted = true;
        return false;
    }
    d->chunk_size = duckdb_data_chunk_get_size(d->chunk);
    d->chunk_pos = 0;
    if (d->chunk_size == 0) {
        duckdb_destroy_data_chunk(&d->chunk);
        d->chunk = nullptr;
        d->exhausted = true;
        return false;
    }
    return true;
}

/* Fetch the next row into `row`. Returns false when exhausted; returns
 * false with an exception pending on error (and sets `row` to UNDEF, so
 * callers storing it in a persistent zval never see a dangling value). */
static bool duckdb_result_fetch_row_into(result_data *d, int mode, zval *row) {
    ZVAL_UNDEF(row);
    if (d->exhausted || !duckdb_result_fetch_chunk(d)) {
        return false;
    }

    decode_ctx ctx = {d, 0, d->row_index, 0};
    array_init_size(row, (uint32_t)d->column_count * (mode == FETCH_MODE_BOTH ? 2 : 1));

    for (idx_t col = 0; col < d->column_count; col++) {
        duckdb_vector vec = duckdb_data_chunk_get_vector(d->chunk, col);
        zval val;
        ctx.column_index = col;
        if (!duckdb_decode_value(&ctx, vec, d->column_types[col], d->chunk_pos, &val)) {
            zval_ptr_dtor(row);
            ZVAL_UNDEF(row);
            return false;
        }

        if (mode == FETCH_MODE_ASSOC || mode == FETCH_MODE_BOTH) {
            const char *name = duckdb_column_name(&d->result, col);
            add_assoc_zval(row, name ? name : "?", &val);
        }
        if (mode == FETCH_MODE_NUM) {
            add_next_index_zval(row, &val);
        } else if (mode == FETCH_MODE_BOTH) {
            zval copy;
            ZVAL_COPY(&copy, &val);
            add_next_index_zval(row, &copy);
        }
    }

    d->chunk_pos++;
    d->row_index++;
    return true;
}

static int duckdb_parse_fetch_mode(zend_object *mode_obj) {
    zval *name = zend_read_property(duckdb_fetch_mode_ce, mode_obj, "name",
                                    sizeof("name") - 1, /*silent=*/true, NULL);
    if (name && Z_TYPE_P(name) == IS_STRING) {
        if (zend_string_equals_literal(Z_STR_P(name), "Num")) {
            return FETCH_MODE_NUM;
        }
        if (zend_string_equals_literal(Z_STR_P(name), "Both")) {
            return FETCH_MODE_BOTH;
        }
    }
    return FETCH_MODE_ASSOC;
}

/* ================================================================== */
/* DuckDB\Result                                                      */
/* ================================================================== */

void duckdb_result_instantiate(zval *return_value, duckdb_result *res, bool streaming,
                               std::shared_ptr<stmt_inner> keepalive) {
    object_init_ex(return_value, duckdb_result_ce);
    php_duckdb_result_object *intern = Z_DUCKDB_RESULT_P(return_value);

    auto data = std::make_shared<result_data>();
    data->result = *res; /* take ownership */
    memset(res, 0, sizeof(*res));
    data->streaming = streaming;
    data->stmt_keepalive = keepalive;
    /* Record the connection's execution epoch so a stale stream can be
     * detected (see conn_inner::execution_epoch). */
    data->epoch = (streaming && keepalive)
        ? keepalive->conn->execution_epoch.load(std::memory_order_relaxed)
        : 0;
    data->column_count = duckdb_column_count(&data->result);
    if (data->column_count > 0) {
        data->column_types = new duckdb_logical_type[data->column_count]();
        for (idx_t i = 0; i < data->column_count; i++) {
            data->column_types[i] = duckdb_column_logical_type(&data->result, i);
        }
    }
    intern->data = data;
}

PHP_METHOD(DuckDB_Result, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    zend_throw_error(NULL, "DuckDB\\Result objects are returned by query methods");
}

PHP_METHOD(DuckDB_Result, columnCount) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    php_duckdb_result_object *intern = Z_DUCKDB_RESULT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->data), "DuckDB\\Result")) {
        RETURN_THROWS();
    }
    RETURN_LONG((zend_long)intern->data->column_count);
}

/* Bounds-check a column index against a result. Returns false and throws
 * \ValueError when out of range. */
static bool duckdb_result_check_column(result_data *d, zend_long index) {
    if (index < 0 || (uint64_t)index >= (uint64_t)d->column_count) {
        zend_argument_value_error(1, "must be between 0 and %d",
                                  d->column_count > 0 ? (int)d->column_count - 1 : 0);
        return false;
    }
    return true;
}

PHP_METHOD(DuckDB_Result, columnName) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long index;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(index)
    ZEND_PARSE_PARAMETERS_END();

    result_data *d = Z_DUCKDB_RESULT_P(ZEND_THIS)->data.get();
    if (!duckdb_initialized_guard(d != nullptr, "DuckDB\\Result")) {
        RETURN_THROWS();
    }
    if (!duckdb_result_check_column(d, index)) {
        RETURN_THROWS();
    }
    RETURN_STRING(duckdb_column_name(&d->result, (idx_t)index));
}

PHP_METHOD(DuckDB_Result, columnType) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long index;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(index)
    ZEND_PARSE_PARAMETERS_END();

    result_data *d = Z_DUCKDB_RESULT_P(ZEND_THIS)->data.get();
    if (!duckdb_initialized_guard(d != nullptr, "DuckDB\\Result")) {
        RETURN_THROWS();
    }
    if (!duckdb_result_check_column(d, index)) {
        RETURN_THROWS();
    }
    /* NB: never pass a temporary std::string's c_str() to ZVAL_STRING et al --
     * the macro stores it in a local declaration, which ends the temporary's
     * lifetime before strlen() reads it. Bind to a named local first. */
    std::string type_name = duckdb_logical_type_render(d->column_types[index]);
    RETURN_STRING(type_name.c_str());
}

PHP_METHOD(DuckDB_Result, columns) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    result_data *d = Z_DUCKDB_RESULT_P(ZEND_THIS)->data.get();
    if (!duckdb_initialized_guard(d != nullptr, "DuckDB\\Result")) {
        RETURN_THROWS();
    }
    array_init_size(return_value, (uint32_t)d->column_count);
    for (idx_t i = 0; i < d->column_count; i++) {
        zval column;
        array_init_size(&column, 2);
        const char *name = duckdb_column_name(&d->result, i);
        add_assoc_string(&column, "name", (char *)(name ? name : "?"));
        std::string type_name = duckdb_logical_type_render(d->column_types[i]);
        add_assoc_string(&column, "type", (char *)type_name.c_str());
        add_next_index_zval(return_value, &column);
    }
}

PHP_METHOD(DuckDB_Result, rowCount) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    php_duckdb_result_object *intern = Z_DUCKDB_RESULT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->data), "DuckDB\\Result")) {
        RETURN_THROWS();
    }
    /* duckdb_row_count is deprecated upstream, but there is no
     * non-deprecated API for counting the rows of an already-materialized
     * result. Kept deliberately; isolated to this call site. */
    RETURN_LONG((zend_long)duckdb_row_count(&intern->data->result));
}

PHP_METHOD(DuckDB_Result, rowsChanged) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    php_duckdb_result_object *intern = Z_DUCKDB_RESULT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->data), "DuckDB\\Result")) {
        RETURN_THROWS();
    }
    RETURN_LONG((zend_long)duckdb_rows_changed(&intern->data->result));
}

PHP_METHOD(DuckDB_Result, statementType) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    result_data *d = Z_DUCKDB_RESULT_P(ZEND_THIS)->data.get();
    if (!duckdb_initialized_guard(d != nullptr, "DuckDB\\Result")) {
        RETURN_THROWS();
    }
    RETURN_STRING(duckdb_statement_type_name(duckdb_result_statement_type(d->result)));
}

PHP_METHOD(DuckDB_Result, fetchRow) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_object *mode_obj = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJ_OF_CLASS_OR_NULL(mode_obj, duckdb_fetch_mode_ce)
    ZEND_PARSE_PARAMETERS_END();

    result_data *d = Z_DUCKDB_RESULT_P(ZEND_THIS)->data.get();
    if (!duckdb_initialized_guard(d != nullptr, "DuckDB\\Result")) {
        RETURN_THROWS();
    }
    int mode = mode_obj ? duckdb_parse_fetch_mode(mode_obj) : FETCH_MODE_ASSOC;

    zval row;
    if (!duckdb_result_fetch_row_into(d, mode, &row)) {
        if (EG(exception)) {
            RETURN_THROWS();
        }
        RETURN_NULL();
    }
    RETURN_COPY_VALUE(&row);
}

PHP_METHOD(DuckDB_Result, fetchAll) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_object *mode_obj = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJ_OF_CLASS_OR_NULL(mode_obj, duckdb_fetch_mode_ce)
    ZEND_PARSE_PARAMETERS_END();

    result_data *d = Z_DUCKDB_RESULT_P(ZEND_THIS)->data.get();
    if (!duckdb_initialized_guard(d != nullptr, "DuckDB\\Result")) {
        RETURN_THROWS();
    }
    int mode = mode_obj ? duckdb_parse_fetch_mode(mode_obj) : FETCH_MODE_ASSOC;

    array_init(return_value);
    zval row;
    while (duckdb_result_fetch_row_into(d, mode, &row)) {
        add_next_index_zval(return_value, &row);
    }
    if (EG(exception)) {
        /* Destroying the half-built array leaves return_value pointing at
         * freed memory; UNDEF it so the engine sees "no return value". */
        zval_ptr_dtor(return_value);
        ZVAL_UNDEF(return_value);
        RETURN_THROWS();
    }
}

PHP_METHOD(DuckDB_Result, fetchColumn) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    zend_long column = 0;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(column)
    ZEND_PARSE_PARAMETERS_END();

    result_data *d = Z_DUCKDB_RESULT_P(ZEND_THIS)->data.get();
    if (!duckdb_initialized_guard(d != nullptr, "DuckDB\\Result")) {
        RETURN_THROWS();
    }
    if (!duckdb_result_check_column(d, column)) {
        RETURN_THROWS();
    }

    zval row;
    if (!duckdb_result_fetch_row_into(d, FETCH_MODE_NUM, &row)) {
        if (EG(exception)) {
            RETURN_THROWS();
        }
        RETURN_NULL();
    }

    zval *val = zend_hash_index_find(Z_ARRVAL(row), (zend_ulong)column);
    if (val) {
        ZVAL_COPY(return_value, val);
    } else {
        ZVAL_NULL(return_value);
    }
    zval_ptr_dtor(&row);
}

PHP_METHOD(DuckDB_Result, getIterator) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_result_object *intern = Z_DUCKDB_RESULT_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(intern->data), "DuckDB\\Result")) {
        RETURN_THROWS();
    }
    if (intern->data->iterator_taken) {
        duckdb_throw_msg("DuckDB\\Result is forward-only: an iterator was already created for this result");
        RETURN_THROWS();
    }
    intern->data->iterator_taken = true;

    object_init_ex(return_value, duckdb_result_iterator_ce);
    php_duckdb_result_iterator_object *it = Z_DUCKDB_RESULT_ITERATOR_P(return_value);
    it->data = intern->data;
}

/* ================================================================== */
/* DuckDB\ResultIterator                                              */
/* ================================================================== */

PHP_METHOD(DuckDB_ResultIterator, __construct) {
    DUCKDB_TSRMLS_CACHE_UPDATE();
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    zend_throw_error(NULL, "DuckDB\\ResultIterator objects are returned by DuckDB\\Result::getIterator()");
}

PHP_METHOD(DuckDB_ResultIterator, current) {
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_result_iterator_object *it = Z_DUCKDB_RESULT_ITERATOR_P(ZEND_THIS);
    if (Z_ISUNDEF(it->current)) {
        RETURN_NULL();
    }
    RETURN_COPY(&it->current);
}

PHP_METHOD(DuckDB_ResultIterator, key) {
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG(Z_DUCKDB_RESULT_ITERATOR_P(ZEND_THIS)->key);
}

PHP_METHOD(DuckDB_ResultIterator, next) {
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_result_iterator_object *it = Z_DUCKDB_RESULT_ITERATOR_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(it->data), "DuckDB\\ResultIterator")) {
        RETURN_THROWS();
    }
    zval_ptr_dtor(&it->current);
    ZVAL_UNDEF(&it->current);

    if (!duckdb_result_fetch_row_into(it->data.get(), FETCH_MODE_ASSOC, &it->current)) {
        if (EG(exception)) {
            RETURN_THROWS();
        }
        ZVAL_UNDEF(&it->current);
        return;
    }
    it->key++;
}

PHP_METHOD(DuckDB_ResultIterator, rewind) {
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();

    php_duckdb_result_iterator_object *it = Z_DUCKDB_RESULT_ITERATOR_P(ZEND_THIS);
    if (!duckdb_initialized_guard(static_cast<bool>(it->data), "DuckDB\\ResultIterator")) {
        RETURN_THROWS();
    }
    if (it->started || it->data->row_index > 0 || it->data->exhausted) {
        duckdb_throw_msg("Cannot rewind a DuckDB\\ResultIterator (results are forward-only)");
        RETURN_THROWS();
    }
    it->started = true;
    it->key = 0;
    if (!duckdb_result_fetch_row_into(it->data.get(), FETCH_MODE_ASSOC, &it->current)) {
        if (EG(exception)) {
            RETURN_THROWS();
        }
        ZVAL_UNDEF(&it->current);
    }
}

PHP_METHOD(DuckDB_ResultIterator, valid) {
    ZEND_PARSE_PARAMETERS_START(0, 0)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(!Z_ISUNDEF(Z_DUCKDB_RESULT_ITERATOR_P(ZEND_THIS)->current));
}
