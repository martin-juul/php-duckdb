/*
 * VARIANT result rendering adapted from DuckDB v1.5.5/v1.5.6:
 * src/common/types/variant/variant.cpp, variant_visitor.hpp,
 * src/function/scalar/variant/variant_utils.cpp,
 * src/function/cast/variant/to_json.cpp, and src/common/types/geometry.cpp.
 * https://github.com/duckdb/duckdb/tree/v1.5.6/src/common/types/variant
 * Only the public duckdb.h C API is used. No DuckDB C++ client dependency.
 * The output matches CAST(value AS JSON)::VARCHAR, not CAST(value AS VARCHAR).
 * SQL NULL is PHP NULL; a valid VARIANT null is the JSON string "null".
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
#include "variant_decode.h"
#include "php_duckdb.h"
#include "Zend/zend_strtod.h"

#include <algorithm>
#include <cmath>
#include <cstdint>
#include <cstring>
#include <limits>
#include <stdexcept>
#include <string>
#include <unordered_map>
#include <vector>

namespace {
constexpr unsigned maximum_depth = 64;
[[noreturn]] void invalid(const char *message) { throw std::runtime_error(message); }

struct Reader {
    const uint8_t *data;
    size_t size;
    size_t position = 0;
    Reader(const char *bytes, size_t length) : data(reinterpret_cast<const uint8_t *>(bytes)), size(length) {
        if (!data) invalid("Missing VARIANT payload");
    }
    size_t remaining() const { return size - position; }
    void offset(size_t value) {
        if (value > size) invalid("VARIANT byte offset is out of bounds");
        position = value;
    }
    const uint8_t *take(size_t count) {
        if (count > remaining()) invalid("Truncated VARIANT payload");
        const auto *result = data + position;
        position += count;
        return result;
    }
    template<class T> T native() {
        T result;
        std::memcpy(&result, take(sizeof(T)), sizeof(T));
        return result;
    }
    template<class T> T Read() {
        const auto *bytes = take(sizeof(T));
        uint8_t native_bytes[sizeof(T)];
        const uint16_t marker = 1;
        if (*reinterpret_cast<const uint8_t *>(&marker)) std::memcpy(native_bytes, bytes, sizeof(T));
        else std::reverse_copy(bytes, bytes + sizeof(T), native_bytes);
        T result;
        std::memcpy(&result, native_bytes, sizeof(T));
        return result;
    }
    size_t GetPosition() const { return position; }
    uint32_t varint() {
        uint32_t value = 0;
        for (unsigned shift = 0; shift <= 28; shift += 7) {
            const auto byte = native<uint8_t>();
            if (shift == 28 && (byte & 0xf0)) invalid("Invalid VARIANT varint");
            value |= uint32_t(byte & 127) << shift;
            if (!(byte & 128)) return value;
        }
        invalid("Invalid VARIANT varint");
    }
};

/* Both yyjson and fmt use a shortest round-trip mantissa. They differ in
 * the fixed-notation range, exponent sign, and integral-real suffix. */
std::string real(double value, bool geometry = false) {
    if (std::isnan(value)) return geometry ? "nan" : "NaN";
    if (std::isinf(value)) return geometry ? (value < 0 ? "-inf" : "inf") : (value < 0 ? "-Infinity" : "Infinity");
    if (value == 0) return std::signbit(value) ? (geometry ? "-0" : "-0.0") : (geometry ? "0" : "0.0");
    /* PHP's dtoa mode 0 produces the shortest round-trip digits without
     * floating std::to_chars, which requires macOS 13.3 with Apple libc++.
     * The extension's existing macOS deployment floor is 12.0. */
    int point;
    bool negative;
    char *end;
    char *raw = zend_dtoa(value, 0, 0, &point, &negative, &end);
    if (!raw) invalid("Unable to render VARIANT floating point value");
    std::string digits;
    try { digits.assign(raw, end); }
    catch (...) { zend_freedtoa(raw); throw; }
    zend_freedtoa(raw);
    if (digits.empty()) invalid("Unexpected floating point representation");
    const int exponent = point - 1;
    std::string result = negative ? "-" : "";
    if (exponent >= (geometry ? -4 : -6) && exponent < (geometry ? 16 : 21)) {
        const int point = exponent + 1;
        if (point <= 0) result += "0." + std::string(size_t(-point), '0') + digits;
        else if (size_t(point) >= digits.size()) {
            result += digits + std::string(size_t(point) - digits.size(), '0');
            if (!geometry) result += ".0";
        } else result += digits.substr(0, size_t(point)) + "." + digits.substr(size_t(point));
    } else {
        result += digits[0];
        if (digits.size() > 1) result += "." + digits.substr(1);
        result += 'e';
        if (geometry && exponent >= 0) result += '+';
        if (geometry && std::abs(exponent) < 10) result += exponent < 0 ? "-0" : "0";
        else if (exponent < 0) result += '-';
        result += std::to_string(std::abs(exponent));
    }
    return result;
}

void quote(std::string &out, const char *bytes, size_t length) {
    static const char hex[] = "0123456789abcdef";
    out += '"';
    for (size_t i = 0; i < length; ++i) {
        const uint8_t c = static_cast<uint8_t>(bytes[i]);
        switch (c) {
            case '"': out += "\\\""; break;
            case '\\': out += "\\\\"; break;
            case '\b': out += "\\b"; break;
            case '\f': out += "\\f"; break;
            case '\n': out += "\\n"; break;
            case '\r': out += "\\r"; break;
            case '\t': out += "\\t"; break;
            default:
                if (c < 32) { out += "\\u00"; out += hex[c >> 4]; out += hex[c & 15]; }
                else out += char(c);
        }
    }
    out += '"';
}

std::string scalar(duckdb_value value) {
    scoped_duckdb_value owner(value);
    if (!owner) invalid("Unable to construct VARIANT scalar");
    char *text = duckdb_get_varchar(owner.get());
    if (!text) invalid("Unable to render VARIANT scalar");
    try {
        std::string result(text);
        duckdb_free(text);
        return result;
    } catch (...) { duckdb_free(text); throw; }
}

struct TextWriter {
    std::string buffer;
    void Write(const char *text) { buffer += text; }
    void Write(char c) { buffer += c; }
    void Write(double value) { buffer += real(value, true); }
};
enum class GeometryType : uint32_t { INVALID=0, POINT=1, LINESTRING=2, POLYGON=3, MULTIPOINT=4, MULTILINESTRING=5, MULTIPOLYGON=6, GEOMETRYCOLLECTION=7 };
void ToStringRecursive(Reader &reader, TextWriter &writer, unsigned depth, bool parent_has_z, bool parent_has_m) {
	if (depth == maximum_depth) {
		invalid("Invalid VARIANT geometry payload");
	}

	// Read the byte order (should always be 1 for little-endian)
	auto byte_order = reader.Read<uint8_t>();
	if (byte_order != 1) {
		invalid("Invalid VARIANT geometry payload");
	}

	const auto meta = reader.Read<uint32_t>();
	const auto type = static_cast<GeometryType>((meta & 0x0000FFFF) % 1000);
	const auto flag = (meta & 0x0000FFFF) / 1000;
	const auto has_z = (flag & 0x01) != 0;
	const auto has_m = (flag & 0x02) != 0;

	if ((depth != 0) && ((parent_has_z != has_z) || (parent_has_m != has_m))) {
		invalid("Invalid VARIANT geometry payload");
	}

	const uint32_t dims = 2 + (has_z ? 1 : 0) + (has_m ? 1 : 0);
	const auto flag_str = has_z ? (has_m ? " ZM " : " Z ") : (has_m ? " M " : " ");

	switch (type) {
	case GeometryType::POINT: {
		writer.Write("POINT");
		writer.Write(flag_str);

		double vert[4] = {0, 0, 0, 0};
		auto all_nan = true;
		for (uint32_t d_idx = 0; d_idx < dims; d_idx++) {
			vert[d_idx] = reader.Read<double>();
			all_nan &= std::isnan(vert[d_idx]);
		}
		if (all_nan) {
			writer.Write("EMPTY");
			return;
		}
		writer.Write('(');
		for (uint32_t d_idx = 0; d_idx < dims; d_idx++) {
			if (d_idx > 0) {
				writer.Write(' ');
			}
			writer.Write(vert[d_idx]);
		}
		writer.Write(')');
	} break;
	case GeometryType::LINESTRING: {
		writer.Write("LINESTRING");
		;
		writer.Write(flag_str);
		const auto vert_count = reader.Read<uint32_t>();
		if (vert_count == 0) {
			writer.Write("EMPTY");
			return;
		}
		writer.Write('(');
		for (uint32_t vert_idx = 0; vert_idx < vert_count; vert_idx++) {
			if (vert_idx > 0) {
				writer.Write(", ");
			}
			for (uint32_t d_idx = 0; d_idx < dims; d_idx++) {
				if (d_idx > 0) {
					writer.Write(' ');
				}
				auto value = reader.Read<double>();
				writer.Write(value);
			}
		}
		writer.Write(')');
	} break;
	case GeometryType::POLYGON: {
		writer.Write("POLYGON");
		writer.Write(flag_str);
		const auto ring_count = reader.Read<uint32_t>();
		if (ring_count == 0) {
			writer.Write("EMPTY");
			return;
		}
		writer.Write('(');
		for (uint32_t ring_idx = 0; ring_idx < ring_count; ring_idx++) {
			if (ring_idx > 0) {
				writer.Write(", ");
			}
			const auto vert_count = reader.Read<uint32_t>();
			if (vert_count == 0) {
				writer.Write("EMPTY");
				continue;
			}
			writer.Write('(');
			for (uint32_t vert_idx = 0; vert_idx < vert_count; vert_idx++) {
				if (vert_idx > 0) {
					writer.Write(", ");
				}
				for (uint32_t d_idx = 0; d_idx < dims; d_idx++) {
					if (d_idx > 0) {
						writer.Write(' ');
					}
					auto value = reader.Read<double>();
					writer.Write(value);
				}
			}
			writer.Write(')');
		}
		writer.Write(')');
	} break;
	case GeometryType::MULTIPOINT: {
		writer.Write("MULTIPOINT");
		writer.Write(flag_str);
		const auto part_count = reader.Read<uint32_t>();
		if (part_count == 0) {
			writer.Write("EMPTY");
			return;
		}
		writer.Write('(');
		for (uint32_t part_idx = 0; part_idx < part_count; part_idx++) {
			const auto part_byte_order = reader.Read<uint8_t>();
			if (part_byte_order != 1) {
				invalid("Invalid VARIANT geometry payload");
			}
			const auto part_meta = reader.Read<uint32_t>();
			const auto part_type = static_cast<GeometryType>((part_meta & 0x0000FFFF) % 1000);
			const auto part_flag = (part_meta & 0x0000FFFF) / 1000;
			const auto part_has_z = (part_flag & 0x01) != 0;
			const auto part_has_m = (part_flag & 0x02) != 0;

			if (part_type != GeometryType::POINT) {
				invalid("Invalid VARIANT geometry payload");
			}

			if ((has_z != part_has_z) || (has_m != part_has_m)) {
				invalid("Invalid VARIANT geometry payload");
			}
			if (part_idx > 0) {
				writer.Write(", ");
			}
			double vert[4] = {0, 0, 0, 0};
			auto all_nan = true;
			for (uint32_t d_idx = 0; d_idx < dims; d_idx++) {
				vert[d_idx] = reader.Read<double>();
				all_nan &= std::isnan(vert[d_idx]);
			}
			if (all_nan) {
				writer.Write("EMPTY");
				continue;
			}
			// writer.Write('(');
			for (uint32_t d_idx = 0; d_idx < dims; d_idx++) {
				if (d_idx > 0) {
					writer.Write(' ');
				}
				writer.Write(vert[d_idx]);
			}
			// writer.Write(')');
		}
		writer.Write(')');

	} break;
	case GeometryType::MULTILINESTRING: {
		writer.Write("MULTILINESTRING");
		writer.Write(flag_str);
		const auto part_count = reader.Read<uint32_t>();
		if (part_count == 0) {
			writer.Write("EMPTY");
			return;
		}
		writer.Write('(');
		for (uint32_t part_idx = 0; part_idx < part_count; part_idx++) {
			const auto part_byte_order = reader.Read<uint8_t>();
			if (part_byte_order != 1) {
				invalid("Invalid VARIANT geometry payload");
			}
			const auto part_meta = reader.Read<uint32_t>();
			const auto part_type = static_cast<GeometryType>((part_meta & 0x0000FFFF) % 1000);
			const auto part_flag = (part_meta & 0x0000FFFF) / 1000;
			const auto part_has_z = (part_flag & 0x01) != 0;
			const auto part_has_m = (part_flag & 0x02) != 0;

			if (part_type != GeometryType::LINESTRING) {
				invalid("Invalid VARIANT geometry payload");
			}
			if ((has_z != part_has_z) || (has_m != part_has_m)) {
				invalid("Invalid VARIANT geometry payload");
			}
			if (part_idx > 0) {
				writer.Write(", ");
			}
			const auto vert_count = reader.Read<uint32_t>();
			if (vert_count == 0) {
				writer.Write("EMPTY");
				continue;
			}
			writer.Write('(');
			for (uint32_t vert_idx = 0; vert_idx < vert_count; vert_idx++) {
				if (vert_idx > 0) {
					writer.Write(", ");
				}
				for (uint32_t d_idx = 0; d_idx < dims; d_idx++) {
					if (d_idx > 0) {
						writer.Write(' ');
					}
					auto value = reader.Read<double>();
					writer.Write(value);
				}
			}
			writer.Write(')');
		}
		writer.Write(')');
	} break;
	case GeometryType::MULTIPOLYGON: {
		writer.Write("MULTIPOLYGON");
		writer.Write(flag_str);
		const auto part_count = reader.Read<uint32_t>();
		if (part_count == 0) {
			writer.Write("EMPTY");
			return;
		}
		writer.Write('(');
		for (uint32_t part_idx = 0; part_idx < part_count; part_idx++) {
			if (part_idx > 0) {
				writer.Write(", ");
			}

			const auto part_byte_order = reader.Read<uint8_t>();
			if (part_byte_order != 1) {
				invalid("Invalid VARIANT geometry payload");
			}
			const auto part_meta = reader.Read<uint32_t>();
			const auto part_type = static_cast<GeometryType>((part_meta & 0x0000FFFF) % 1000);
			const auto part_flag = (part_meta & 0x0000FFFF) / 1000;
			const auto part_has_z = (part_flag & 0x01) != 0;
			const auto part_has_m = (part_flag & 0x02) != 0;
			if (part_type != GeometryType::POLYGON) {
				invalid("Invalid VARIANT geometry payload");
			}
			if ((has_z != part_has_z) || (has_m != part_has_m)) {
				invalid("Invalid VARIANT geometry payload");
			}

			const auto ring_count = reader.Read<uint32_t>();
			if (ring_count == 0) {
				writer.Write("EMPTY");
				continue;
			}
			writer.Write('(');
			for (uint32_t ring_idx = 0; ring_idx < ring_count; ring_idx++) {
				if (ring_idx > 0) {
					writer.Write(", ");
				}
				const auto vert_count = reader.Read<uint32_t>();
				if (vert_count == 0) {
					writer.Write("EMPTY");
					continue;
				}
				writer.Write('(');
				for (uint32_t vert_idx = 0; vert_idx < vert_count; vert_idx++) {
					if (vert_idx > 0) {
						writer.Write(", ");
					}
					for (uint32_t d_idx = 0; d_idx < dims; d_idx++) {
						if (d_idx > 0) {
							writer.Write(' ');
						}
						auto value = reader.Read<double>();
						writer.Write(value);
					}
				}
				writer.Write(')');
			}
			writer.Write(')');
		}
		writer.Write(')');
	} break;
	case GeometryType::GEOMETRYCOLLECTION: {
		writer.Write("GEOMETRYCOLLECTION");
		writer.Write(flag_str);
		const auto part_count = reader.Read<uint32_t>();
		if (part_count == 0) {
			writer.Write("EMPTY");
			return;
		}
		writer.Write('(');
		for (uint32_t part_idx = 0; part_idx < part_count; part_idx++) {
			if (part_idx > 0) {
				writer.Write(", ");
			}
			// Recursively parse the geometry inside the collection
			ToStringRecursive(reader, writer, depth + 1, has_z, has_m);
		}
		writer.Write(')');
	} break;
	default:
		invalid("Invalid VARIANT geometry payload");
	}
}

std::string geometry(const uint8_t *data, size_t length) {
    Reader reader(reinterpret_cast<const char *>(data), length);
    TextWriter writer;
    ToStringRecursive(reader, writer, 0, false, false);
    if (reader.remaining()) invalid("Trailing VARIANT geometry bytes");
    return writer.buffer;
}

void require_type(duckdb_vector vector, duckdb_type expected) {
    if (!vector) invalid("Missing VARIANT child vector");
    scoped_duckdb_logical_type type(duckdb_vector_get_column_type(vector));
    if (!type || duckdb_get_type_id(type.get()) != expected) invalid("Unsupported VARIANT child layout");
}

struct List {
    duckdb_vector vector;
    duckdb_vector child;
    duckdb_list_entry entry;
    List(duckdb_vector input, idx_t row, duckdb_type expected) : vector(input) {
        require_type(vector, DUCKDB_TYPE_LIST);
        auto *mask = duckdb_vector_get_validity(vector);
        if (mask && !duckdb_validity_row_is_valid(mask, row)) invalid("NULL VARIANT metadata list");
        auto *entries = static_cast<duckdb_list_entry *>(duckdb_vector_get_data(vector));
        if (!entries) invalid("Missing VARIANT metadata list data");
        entry = entries[row];
        const idx_t length = duckdb_list_vector_get_size(vector);
        if (entry.offset > length || entry.length > length - entry.offset) invalid("VARIANT list range is out of bounds");
        child = duckdb_list_vector_get_child(vector);
        require_type(child, expected);
    }
    idx_t index(uint32_t relative) const {
        if (relative >= entry.length) invalid("VARIANT index is out of bounds");
        return entry.offset + relative;
    }
};

duckdb_vector field(duckdb_vector vector, idx_t index, duckdb_type expected) {
    scoped_duckdb_logical_type type(duckdb_vector_get_column_type(vector));
    if (!type || duckdb_get_type_id(type.get()) != DUCKDB_TYPE_STRUCT || duckdb_struct_type_child_count(type.get()) != 2)
        invalid("Unsupported VARIANT entry layout");
    auto result = duckdb_struct_vector_get_child(vector, index);
    require_type(result, expected);
    return result;
}

template<class T> T cell(duckdb_vector vector, idx_t row) {
    auto *mask = duckdb_vector_get_validity(vector);
    if (mask && !duckdb_validity_row_is_valid(mask, row)) invalid("NULL VARIANT metadata value");
    auto *data = static_cast<T *>(duckdb_vector_get_data(vector));
    if (!data) invalid("Missing VARIANT metadata value");
    return data[row];
}

struct Decoder {
    List keys, children, values;
    duckdb_vector keys_index, values_index, type_id, byte_offset;
    Reader blob;
    std::vector<uint32_t> active;
    explicit Decoder(duckdb_vector vector, idx_t row)
        : keys(duckdb_struct_vector_get_child(vector, 0), row, DUCKDB_TYPE_VARCHAR),
          children(duckdb_struct_vector_get_child(vector, 1), row, DUCKDB_TYPE_STRUCT),
          values(duckdb_struct_vector_get_child(vector, 2), row, DUCKDB_TYPE_STRUCT),
          keys_index(field(children.child, 0, DUCKDB_TYPE_UINTEGER)),
          values_index(field(children.child, 1, DUCKDB_TYPE_UINTEGER)),
          type_id(field(values.child, 0, DUCKDB_TYPE_UTINYINT)),
          byte_offset(field(values.child, 1, DUCKDB_TYPE_UINTEGER)),
          blob(payload(vector, row)) { }
    static Reader payload(duckdb_vector vector, idx_t row) {
        auto child = duckdb_struct_vector_get_child(vector, 3);
        require_type(child, DUCKDB_TYPE_BLOB);
        auto *data = static_cast<duckdb_string_t *>(duckdb_vector_get_data(child));
        if (!data) invalid("Missing VARIANT blob data");
        const auto *mask = duckdb_vector_get_validity(child);
        if (mask && !duckdb_validity_row_is_valid(const_cast<uint64_t *>(mask), row)) invalid("NULL VARIANT blob data");
        // Inline strings point into their duckdb_string_t cell: never copy it
        // to a stack local before returning the payload pointer.
        return Reader(duckdb_string_t_data(&data[row]), duckdb_string_t_length(data[row]));
    }
    std::string bignum(const uint8_t *bytes, size_t size) {
        if (size < 4 || size - 3 > 0x7fffff) invalid("Invalid VARIANT BIGNUM length");
        const bool negative = !(bytes[0] & 128);
        auto magnitude = [negative](uint8_t b) { return negative ? uint8_t(~b) : b; };
        const size_t declared = (size_t(magnitude(bytes[0]) & 127) << 16) | (size_t(magnitude(bytes[1])) << 8) | magnitude(bytes[2]);
        if (declared != size - 3 || (declared > 1 && !magnitude(bytes[3])) || (negative && declared == 1 && !magnitude(bytes[3])))
            invalid("Invalid VARIANT BIGNUM header");
        std::vector<uint8_t> data(declared);
        for (size_t i = 0; i < declared; ++i) data[i] = magnitude(bytes[i + 3]);
        return scalar(duckdb_create_bignum({data.data(), idx_t(data.size()), negative}));
    }
    std::string render(uint32_t index, unsigned depth = 0) {
        if (depth > maximum_depth) invalid("VARIANT value exceeds nesting limit");
        if (std::find(active.begin(), active.end(), index) != active.end()) invalid("Cyclic VARIANT value indexes");
        active.push_back(index);
        struct Pop { std::vector<uint32_t> &a; ~Pop() { a.pop_back(); } } pop{active};
        const auto row = values.index(index);
        const uint8_t tag = cell<uint8_t>(type_id, row);
        Reader reader = blob;
        reader.offset(cell<uint32_t>(byte_offset, row));
        std::string text;
        bool quoted = false;
        switch (tag) {
            case 0: return "null";
            case 1: return "true";
            case 2: return "false";
            case 3: return std::to_string(reader.native<int8_t>());
            case 4: return std::to_string(reader.native<int16_t>());
            case 5: return std::to_string(reader.native<int32_t>());
            case 6: return std::to_string(reader.native<int64_t>());
            case 7: return scalar(duckdb_create_hugeint(reader.native<duckdb_hugeint>()));
            case 8: return std::to_string(reader.native<uint8_t>());
            case 9: return std::to_string(reader.native<uint16_t>());
            case 10: return std::to_string(reader.native<uint32_t>());
            case 11: return std::to_string(reader.native<uint64_t>());
            case 12: return scalar(duckdb_create_uhugeint(reader.native<duckdb_uhugeint>()));
            case 13: return real(double(reader.native<float>()));
            case 14: return real(reader.native<double>());
            case 15: {
                const auto width = reader.varint(), scale = reader.varint();
                if (!width || width > 38 || scale > width) invalid("Invalid VARIANT decimal width/scale");
                duckdb_hugeint number;
                if (width > 18) number = reader.native<duckdb_hugeint>();
                else {
                    const int64_t small = width > 9 ? reader.native<int64_t>() : width > 4 ? reader.native<int32_t>() : reader.native<int16_t>();
                    number = {uint64_t(small), small < 0 ? -1 : 0};
                }
                return scalar(duckdb_create_decimal({uint8_t(width), uint8_t(scale), number}));
            }
            case 16: case 17: case 31: case 32: case 33: {
                const uint32_t length = reader.varint();
                const uint8_t *data = reader.take(length);
                if (tag == 16) text.assign(reinterpret_cast<const char *>(data), length);
                else if (tag == 17) text = scalar(duckdb_create_blob(data, length));
                else if (tag == 31) return bignum(data, length);
                else if (tag == 32) {
                    if (length < 2 || data[0] > 7) invalid("Invalid VARIANT bitstring");
                    text = scalar(duckdb_create_bit({const_cast<uint8_t *>(data), length}));
                } else text = geometry(data, length);
                quoted = true;
                break;
            }
            case 18: {
                auto uuid = reader.native<duckdb_uhugeint>();
                uuid.upper ^= uint64_t(1) << 63;
                text = scalar(duckdb_create_uuid(uuid)); quoted = true; break;
            }
            case 19: text = scalar(duckdb_create_date(reader.native<duckdb_date>())); quoted = true; break;
            case 20: text = scalar(duckdb_create_time(reader.native<duckdb_time>())); quoted = true; break;
            case 21: text = scalar(duckdb_create_time_ns(reader.native<duckdb_time_ns>())); quoted = true; break;
            case 22: text = scalar(duckdb_create_timestamp_s(reader.native<duckdb_timestamp_s>())); quoted = true; break;
            case 23: text = scalar(duckdb_create_timestamp_ms(reader.native<duckdb_timestamp_ms>())); quoted = true; break;
            case 24: text = scalar(duckdb_create_timestamp(reader.native<duckdb_timestamp>())); quoted = true; break;
            case 25: text = scalar(duckdb_create_timestamp_ns(reader.native<duckdb_timestamp_ns>())); quoted = true; break;
            case 26: text = scalar(duckdb_create_time_tz_value(reader.native<duckdb_time_tz>())); quoted = true; break;
            case 27: text = scalar(duckdb_create_timestamp_tz(reader.native<duckdb_timestamp>())); quoted = true; break;
            case 28: text = scalar(duckdb_create_interval(reader.native<duckdb_interval>())); quoted = true; break;
            case 29: case 30: {
                const uint32_t count = reader.varint();
                const uint32_t first = count ? reader.varint() : 0;
                if (first > children.entry.length || count > children.entry.length - first) invalid("VARIANT child range is out of bounds");
                std::string result = tag == 29 ? "{" : "[";
                std::vector<std::pair<std::string, std::string>> fields;
                std::unordered_map<std::string, size_t> positions;
                for (uint32_t i = 0; i < count; ++i) {
                    const auto child_row = children.index(first + i);
                    auto value = render(cell<uint32_t>(values_index, child_row), depth + 1);
                    if (tag == 30) { if (i) result += ','; result += value; }
                    else {
                        const auto key_row = keys.index(cell<uint32_t>(keys_index, child_row));
                        auto key_cell = cell<duckdb_string_t>(keys.child, key_row);
                        std::string key(duckdb_string_t_data(&key_cell), duckdb_string_t_length(key_cell));
                        auto found = positions.find(key);
                        if (found == positions.end()) { positions.emplace(key, fields.size()); fields.emplace_back(std::move(key), std::move(value)); }
                        else fields[found->second].second = std::move(value);
                    }
                }
                if (tag == 29) for (size_t i = 0; i < fields.size(); ++i) {
                    if (i) result += ',';
                    quote(result, fields[i].first.data(), fields[i].first.size());
                    result += ':'; result += fields[i].second;
                }
                result += tag == 29 ? '}' : ']';
                return result;
            }
            default: invalid("Unsupported VARIANT type tag");
        }
        if (quoted) { std::string result; quote(result, text.data(), text.size()); return result; }
        invalid("Unhandled VARIANT type");
    }
};
} // namespace

bool duckdb_decode_variant(duckdb_vector vector, idx_t row, zval *out) {
    try {
        static const bool supported_format = [] {
            const char *version = duckdb_library_version();
            return version && (std::strcmp(version, "v1.5.5") == 0
                               || std::strcmp(version, "1.5.5") == 0
                               || std::strcmp(version, "v1.5.6") == 0
                               || std::strcmp(version, "1.5.6") == 0);
        }();
        if (!supported_format)
            invalid("VARIANT result decoding requires verified DuckDB 1.5.5 or 1.5.6 layout");
        require_type(vector, DUCKDB_TYPE_VARIANT);
        const auto *validity = duckdb_vector_get_validity(vector);
        if (validity && !duckdb_validity_row_is_valid(const_cast<uint64_t *>(validity), row)) { ZVAL_NULL(out); return true; }
        Decoder decoder(vector, row);
        auto result = decoder.render(0);
        ZVAL_STRINGL(out, result.data(), result.size());
        return true;
    } catch (const std::exception &error) {
        duckdb_throw_error(DUCKDB_ERROR_CONVERSION, error.what());
        return false;
    } catch (...) {
        duckdb_throw_error(DUCKDB_ERROR_CONVERSION, "Unknown VARIANT result conversion failure");
        return false;
    }
}
