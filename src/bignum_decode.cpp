/*
 * BIGNUM decoding adapted from DuckDB v1.5.6:
 * src/common/types/bignum.cpp, Bignum::GetByteArray/Bignum::BignumToVarchar,
 * and src/include/duckdb/common/types/bignum.hpp (format constants).
 * https://github.com/duckdb/duckdb/blob/v1.5.6/src/common/types/bignum.cpp
 * This uses only duckdb.h; no DuckDB C++ client headers or symbols are needed.
 * The format is also validated against DuckDB v1.5.5.
 *
 * Copyright 2018-2025 Stichting DuckDB Foundation
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "bignum_decode.h"
#include "php_duckdb.h"

#include <cstdint>
#include <cstring>
#include <exception>
#include <limits>
#include <vector>

namespace {

constexpr size_t header_size = 3;
constexpr size_t maximum_payload = 8388607; // 23-bit length in the signed header.
constexpr uint32_t decimal_base = 1000000000;
constexpr size_t decimal_shift = 9;

bool invalid_bignum(const char *message) {
    duckdb_throw_msg(message);
    return false;
}
} // namespace

bool duckdb_decode_bignum(duckdb_vector vec, idx_t row, zval *out) {
    /* BIGNUM's byte format is internal, although string/vector access uses the
     * stable C API. Cache the runtime gate, not a promise about future formats. */
    static const bool supported_format = [] {
        const char *version = duckdb_library_version();
        return version &&
               (std::strcmp(version, "v1.5.5") == 0 ||
                std::strcmp(version, "1.5.5") == 0 ||
                std::strcmp(version, "v1.5.6") == 0 ||
                std::strcmp(version, "1.5.6") == 0);
    }();

    if (!supported_format) {
        return invalid_bignum(
            "BIGNUM decoding requires a validated DuckDB 1.5.5 or 1.5.6 storage format");
    }

    auto *cells = static_cast<duckdb_string_t *>(duckdb_vector_get_data(vec));
    if (!cells) {
        return invalid_bignum("BIGNUM vector has no data");
    }

    auto cell = cells[row];
    const size_t length = duckdb_string_t_length(cell);
    if (length <= header_size || length - header_size > maximum_payload) {
        return invalid_bignum("Invalid BIGNUM storage length");
    }

    const auto *data = reinterpret_cast<const uint8_t *>(duckdb_string_t_data(&cell));
    if (!data) {
        return invalid_bignum("BIGNUM cell has no data");
    }

    const bool negative = (data[0] & 0x80) == 0;
    const auto magnitude_byte = [negative](uint8_t byte) -> uint8_t {
        return negative ? static_cast<uint8_t>(~byte) : byte;
    };

    const size_t payload = length - header_size;
    const uint32_t declared = (uint32_t(magnitude_byte(data[0]) & 0x7f) << 16) |
                              (uint32_t(magnitude_byte(data[1])) << 8) | magnitude_byte(data[2]);
    if (declared != payload || (payload > 1 && magnitude_byte(data[3]) == 0) ||
        (negative && payload == 1 && magnitude_byte(data[3]) == 0)) {
        return invalid_bignum("Invalid BIGNUM header or noncanonical payload");
    }

    try {
        /* Since 10^9 > 2^29, this bounds the number of decimal limbs for
         * payload*8 bits. Check arithmetic before allocating native storage. */
        if (payload > (std::numeric_limits<size_t>::max() - 28) / 8) {
            return invalid_bignum("BIGNUM conversion size overflows");
        }
        const size_t maximum_limbs = (payload * 8 + 28) / 29;
        std::vector<uint32_t> digits;
        if (maximum_limbs > digits.max_size()) {
            return invalid_bignum("BIGNUM conversion exceeds native allocation size");
        }
        digits.reserve(maximum_limbs);

        /* Read big-endian 32-bit magnitude words with virtual leading padding,
         * avoiding the upstream byte-array copy. Negative payload bytes are
         * ones-complemented. Decimal limbs are stored least significant first.
         * This is the upstream CPython/Knuth base-conversion algorithm. */
        const size_t padding = (4 - payload % 4) % 4;
        const size_t padded_size = payload + padding;
        for (size_t i = 0; i < padded_size; i += 4) {
            uint32_t hi = 0;
            for (size_t j = 0; j < 4; ++j) {
                const size_t offset = i + j;
                if (offset >= padding) {
                    hi |= uint32_t(magnitude_byte(data[header_size + offset - padding]))
                          << (8 * (3 - j));
                }
            }

            for (auto &digit : digits) {
                // digit < 10^9 and hi <= UINT32_MAX: tmp always fits uint64_t.
                const uint64_t tmp = (uint64_t(digit) << 32) | hi;
                hi = uint32_t(tmp / decimal_base);
                digit = uint32_t(tmp - uint64_t(decimal_base) * hi);
            }

            while (hi) {
                digits.push_back(hi % decimal_base);
                hi /= decimal_base;
            }
        }

        if (digits.empty()) {
            digits.push_back(0);
        }

        size_t leading_digits = 1;
        for (auto top = digits.back(); top >= 10; top /= 10) {
            ++leading_digits;
        }
        const size_t prefix = leading_digits + (negative ? 1 : 0);
        if (digits.size() - 1 > (ZSTR_MAX_LEN - prefix) / decimal_shift) {
            return invalid_bignum("BIGNUM decimal string exceeds PHP allocation size");
        }

        const size_t output_length = (digits.size() - 1) * decimal_shift + prefix;
        zend_string *result = zend_string_alloc(output_length, false);
        char *buffer = ZSTR_VAL(result);
        size_t position = output_length;
        buffer[position] = '\0';

        for (size_t i = 0; i + 1 < digits.size(); ++i) {
            auto remain = digits[i];
            for (size_t j = 0; j < decimal_shift; ++j) {
                buffer[--position] = char('0' + remain % 10);
                remain /= 10;
            }
        }

        auto remain = digits.back();
        do {
            buffer[--position] = char('0' + remain % 10);
            remain /= 10;
        } while (remain);
        if (negative) {
            buffer[--position] = '-';
        }

        ZVAL_STR(out, result);
        return true;
    } catch (const std::exception &) {
        return invalid_bignum("Unable to allocate native BIGNUM conversion storage");
    }
}
