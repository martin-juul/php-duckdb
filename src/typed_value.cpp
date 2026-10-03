/*
 * Standalone immutable typed inputs. Declarations are parsed without a
 * database; the consuming connection resolves catalog names and performs every
 * SQL cast. Native values are captured in a private scalar bind callback,
 * avoiding the lossy PHP result decoder (notably for UNION, VARIANT and typed
 * NULL).
 */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include "php_duckdb_cxx_compat.h"
extern "C" {
#include "ext/date/php_date.h"
#include "ext/json/php_json.h"
}
#include "php_duckdb.h"

#if defined(ZTS) && defined(COMPILE_DL_DUCKDB)
#define DUCKDB_TSRMLS_CACHE_UPDATE() ZEND_TSRMLS_CACHE_UPDATE()
#else
#define DUCKDB_TSRMLS_CACHE_UPDATE()
#endif
#include <atomic>
#include <cctype>
#include <cstddef>
#include <set>
#include <stdexcept>
#include <unordered_map>

namespace {
struct value_object {
  zend_string *type;
  zval input;
  zend_object std;
};
zend_class_entry *value_ce;
zend_object_handlers value_handlers;
value_object *obj(zend_object *o) {
  return reinterpret_cast<value_object *>(reinterpret_cast<char *>(o) -
                                          offsetof(value_object, std));
}
std::string quote(const std::string &s, char q = '"') {
  std::string r(1, q);
  for (char c : s) {
    r += c;
    if (c == q)
      r += c;
  }
  r += q;
  return r;
}
struct token {
  std::string text;
  char kind;
};
/* This grammar admits type declarations only. Input data is always bound. */
struct parser {
  std::vector<token> ts;
  size_t pos = 0;
  explicit parser(const char *s, size_t n) {
    if (memchr(s, 0, n))
      throw std::runtime_error("NUL in type declaration");
    for (size_t i = 0; i < n;) {
      unsigned char c = s[i];
      if (std::isspace(c)) {
        i++;
        continue;
      }
      if (c == '\0')
        throw std::runtime_error("NUL in type declaration");
      if (c == '\'' || c == '"') {
        char q = c;
        std::string t;
        i++;
        bool done = false;
        while (i < n) {
          char x = s[i++];
          if (x == q) {
            if (i < n && s[i] == q) {
              i++;
              t += q;
            } else {
              done = true;
              break;
            }
          } else
            t += x;
        }
        if (!done)
          throw std::runtime_error("Unterminated quoted token");
        ts.push_back({t, q});
      } else if (std::isalpha(c) || c == '_' || c >= 128) {
        size_t st = i++;
        while (i < n && (std::isalnum((unsigned char)s[i]) || s[i] == '_' ||
                         s[i] == '$' || (unsigned char)s[i] >= 128))
          i++;
        std::string t(s + st, i - st);
        ts.push_back({t, 'i'});
      } else if (std::isdigit(c)) {
        size_t st = i++;
        while (i < n && std::isdigit((unsigned char)s[i]))
          i++;
        ts.push_back({std::string(s + st, i - st), 'n'});
      } else if (c == '(' || c == ')' || c == '[' || c == ']' || c == ',' ||
                 c == '.') {
        ts.push_back({std::string(1, c), char(c)});
        i++;
      } else
        throw std::runtime_error("Invalid token in type declaration");
    }
  }
  bool at(char k) { return pos < ts.size() && ts[pos].kind == k; }
  bool word(const char *s) {
    return pos < ts.size() && ts[pos].kind == 'i' && upper(ts[pos].text) == s;
  }
  static std::string upper(std::string s) {
    for (char &c : s)
      c = std::toupper((unsigned char)c);
    return s;
  }
  std::string ident() {
    if (!at('i') && !at('"'))
      throw std::runtime_error("Expected type or field name");
    auto t = ts[pos++];
    return t.kind == '"' ? quote(t.text) : t.text;
  }
  void need(char k) {
    if (!at(k))
      throw std::runtime_error("Malformed type declaration");
    pos++;
  }
  std::string type(unsigned depth = 0) {
    if (depth > 64)
      throw std::runtime_error("Type declaration exceeds nesting limit");
    std::string name = ident();
    if (name[0] != '"')
      name = upper(name);
    static const std::set<std::string> internal = {
        "INVALID",        "ANY",    "UNKNOWN", "SQLNULL", "INTEGER_LITERAL",
        "STRING_LITERAL", "LAMBDA", "TABLE",   "POINTER", "VALIDITY"};
    if (internal.count(name))
      throw std::runtime_error("Internal DuckDB type is not a SQL value type");
    while (at('.')) {
      pos++;
      name += '.';
      name += ident();
    }
    if (name == "DOUBLE" && word("PRECISION")) {
      pos++;
      name += " PRECISION";
    }
    if ((name == "TIME" || name == "TIMESTAMP") &&
        (word("WITH") || word("WITHOUT"))) {
      name += ' ';
      name += upper(ts[pos++].text);
      if (!word("TIME"))
        throw std::runtime_error("Expected TIME ZONE");
      pos++;
      if (!word("ZONE"))
        throw std::runtime_error("Expected TIME ZONE");
      pos++;
      name += " TIME ZONE";
    }
    if (name == "NATIONAL") {
      if (!word("CHAR") && !word("CHARACTER"))
        throw std::runtime_error("Expected CHAR or CHARACTER");
      name += " " + upper(ts[pos++].text);
    }
    if ((name == "CHARACTER" || name == "CHAR" || name == "NCHAR" ||
         name == "NATIONAL CHAR" || name == "NATIONAL CHARACTER") &&
        word("VARYING")) {
      pos++;
      name += " VARYING";
    }
    if (name == "INTERVAL") {
      const std::set<std::string> units = {"YEAR", "MONTH",  "DAY",
                                           "HOUR", "MINUTE", "SECOND"};
      if (pos < ts.size() && ts[pos].kind == 'i' &&
          units.count(upper(ts[pos].text))) {
        name += " " + upper(ts[pos++].text);
        if (word("TO")) {
          pos++;
          if (pos >= ts.size() || ts[pos].kind != 'i' ||
              !units.count(upper(ts[pos].text)))
            throw std::runtime_error("Expected INTERVAL unit");
          name += " TO " + upper(ts[pos++].text);
        }
      }
    }
    if (at('(')) {
      pos++;
      name += '(';
      bool first = true;
      while (!at(')')) {
        if (!first) {
          need(',');
          name += ", ";
        }
        first = false;
        std::string base = name.substr(0, name.find('('));
        if (base == "STRUCT" || base == "UNION") {
          name += ident();
          name += ' ';
          name += type(depth + 1);
        } else if (base == "MAP" || base == "LIST" || base == "ARRAY") {
          if (at('n'))
            name += ts[pos++].text;
          else
            name += type(depth + 1);
        } else if (at('\'')) {
          name += quote(ts[pos++].text, '\'');
        } else if (at('n'))
          name += ts[pos++].text;
        else
          throw std::runtime_error("Expected type parameter");
        if (pos >= ts.size())
          throw std::runtime_error("Unterminated type parameters");
      }
      if (first)
        throw std::runtime_error("Empty type parameters");
      pos++;
      name += ')';
    }
    while (at('[')) {
      if (++depth > 64)
        throw std::runtime_error("Type declaration exceeds nesting limit");
      pos++;
      name += '[';
      if (at('n'))
        name += ts[pos++].text;
      need(']');
      name += ']';
    }
    return name;
  }
};
bool snapshot(zval *src, zval *dst, unsigned depth,
              std::set<HashTable *> &seen) {
  ZVAL_DEREF(src);
  if (depth > 64) {
    zend_value_error("Value exceeds nesting limit");
    return false;
  }
  if (Z_TYPE_P(src) == IS_ARRAY) {
    auto h = Z_ARRVAL_P(src);
    if (!seen.insert(h).second) {
      zend_value_error("Cyclic Value input");
      return false;
    }
    array_init_size(dst, zend_hash_num_elements(h));
    zend_string *k;
    zend_ulong i;
    zval *v;
    ZEND_HASH_FOREACH_KEY_VAL(h, i, k, v) {
      zval copy;
      if (!snapshot(v, &copy, depth + 1, seen)) {
        zval_ptr_dtor(dst);
        ZVAL_UNDEF(dst);
        seen.erase(h);
        return false;
      }
      if (k)
        zend_hash_add_new(Z_ARRVAL_P(dst), k, &copy);
      else
        zend_hash_index_add_new(Z_ARRVAL_P(dst), i, &copy);
    }
    ZEND_HASH_FOREACH_END();
    seen.erase(h);
    return true;
  }
  if (Z_TYPE_P(src) == IS_OBJECT) {
    if (instanceof_function(Z_OBJCE_P(src), value_ce)) {
      if (!obj(Z_OBJ_P(src))->type) {
        zend_type_error(
            "Cannot snapshot an uninitialized DuckDB\\Value object");
        return false;
      }
      ZVAL_COPY(dst, src);
      return true;
    }
    if (instanceof_function(Z_OBJCE_P(src), duckdb_interval_ce)) {
      duckdb_interval_instantiate(dst, Z_DUCKDB_INTERVAL_P(src)->interval);
      return true;
    }
    if (instanceof_function(Z_OBJCE_P(src), php_date_get_date_ce()) ||
        instanceof_function(Z_OBJCE_P(src), php_date_get_immutable_ce())) {
      auto *date = Z_PHPDATE_P(src);
      if (!date->time) {
        zend_type_error("Cannot snapshot an uninitialized DateTime object");
        return false;
      }
      object_init_ex(dst, php_date_get_immutable_ce());
      char epoch[64];
      int length =
          snprintf(epoch, sizeof(epoch), "%lld.%06lld",
                   (long long)date->time->sse, (long long)date->time->us);
      if (!php_date_initialize(Z_PHPDATE_P(dst), epoch, length, "U.u", nullptr,
                               PHP_DATE_INIT_FORMAT)) {
        zval_ptr_dtor(dst);
        ZVAL_UNDEF(dst);
        zend_type_error("Cannot snapshot DateTime input");
        return false;
      }
      return true;
    }
    zend_type_error("Unsupported object in DuckDB\\Value: %s",
                    ZSTR_VAL(Z_OBJCE_P(src)->name));
    return false;
  }
  if (Z_TYPE_P(src) == IS_RESOURCE) {
    zend_type_error("Unsupported resource in DuckDB\\Value");
    return false;
  }
  ZVAL_COPY(dst, src);
  return true;
}
zend_object *create(zend_class_entry *ce) {
  auto *v = (value_object *)zend_object_alloc(sizeof(value_object), ce);
  v->type = nullptr;
  ZVAL_UNDEF(&v->input);
  zend_object_std_init(&v->std, ce);
  object_properties_init(&v->std, ce);
  v->std.handlers = &value_handlers;
  return &v->std;
}
HashTable *value_gc(zend_object *o, zval **table, int *n) {
  auto *v = obj(o);
  *table = &v->input;
  *n = Z_ISUNDEF(v->input) ? 0 : 1;
  return zend_std_get_properties(o);
}
void free_value(zend_object *o) {
  auto *v = obj(o);
  if (v->type)
    zend_string_release(v->type);
  if (!Z_ISUNDEF(v->input))
    zval_ptr_dtor(&v->input);
  zend_object_std_dtor(o);
}
} // namespace
zend_class_entry *duckdb_value_class_entry() { return value_ce; }
bool duckdb_canonicalize_type(const std::string &declaration,
                              std::string &canonical) {
  try {
    parser p(declaration.data(), declaration.size());
    canonical = p.type();
    if (p.pos != p.ts.size())
      throw std::runtime_error("Trailing tokens in type declaration");
  } catch (const std::exception &e) {
    zend_value_error("%s", e.what());
    return false;
  }
  return true;
}
bool duckdb_value_initialize(zval *object, const std::string &type,
                             zval *input) {
  auto *v = obj(Z_OBJ_P(object));
  if (v->type) {
    zend_throw_error(nullptr, "DuckDB\\Value is immutable");
    return false;
  }
  std::string canonical;
  if (!duckdb_canonicalize_type(type, canonical))
    return false;
  std::set<HashTable *> seen;
  if (!snapshot(input, &v->input, 0, seen))
    return false;
  v->type = zend_string_init(canonical.data(), canonical.size(), false);
  return true;
}
PHP_METHOD(DuckDB_Value, __construct) {
  DUCKDB_TSRMLS_CACHE_UPDATE();
  zend_string *type;
  zval *input;
  ZEND_PARSE_PARAMETERS_START(2, 2)
  Z_PARAM_STR(type) Z_PARAM_ZVAL(input) ZEND_PARSE_PARAMETERS_END();
  if (!duckdb_value_initialize(
          ZEND_THIS, std::string(ZSTR_VAL(type), ZSTR_LEN(type)), input))
    RETURN_THROWS();
}
PHP_METHOD(DuckDB_Value, getType) {
  DUCKDB_TSRMLS_CACHE_UPDATE();
  ZEND_PARSE_PARAMETERS_NONE();
  auto *v = obj(Z_OBJ_P(ZEND_THIS));
  if (!v->type) {
    zend_throw_error(nullptr, "Uninitialized DuckDB\\Value");
    RETURN_THROWS();
  }
  RETURN_STR_COPY(v->type);
}
ZEND_BEGIN_ARG_INFO_EX(value_construct_args, 0, 0, 2)
ZEND_ARG_TYPE_INFO(0, type, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(value_type_args, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()
void duckdb_register_value_class() {
  static const zend_function_entry methods[] = {
      PHP_ME(DuckDB_Value, __construct, value_construct_args, ZEND_ACC_PUBLIC)
          PHP_ME(DuckDB_Value, getType, value_type_args,
                 ZEND_ACC_PUBLIC | ZEND_ACC_FINAL) PHP_FE_END};
  zend_class_entry ce;
  INIT_NS_CLASS_ENTRY(ce, "DuckDB", "Value", methods);
  value_ce = zend_register_internal_class(&ce);
  value_ce->ce_flags |=
      ZEND_ACC_NOT_SERIALIZABLE | ZEND_ACC_NO_DYNAMIC_PROPERTIES;
  value_ce->create_object = create;
  memcpy(&value_handlers, zend_get_std_object_handlers(),
         sizeof(value_handlers));
  value_handlers.offset = offsetof(value_object, std);
  value_handlers.free_obj = free_value;
  value_handlers.get_gc = value_gc;
  value_handlers.clone_obj = nullptr;
}
bool duckdb_value_contains_typed(zval *root) {
  std::set<HashTable *> seen;
  std::vector<zval *> pending{root};
  while (!pending.empty()) {
    zval *v = pending.back();
    pending.pop_back();
    ZVAL_DEREF(v);
    if (Z_TYPE_P(v) == IS_OBJECT && instanceof_function(Z_OBJCE_P(v), value_ce))
      return true;
    if (Z_TYPE_P(v) != IS_ARRAY || !seen.insert(Z_ARRVAL_P(v)).second)
      continue;
    zval *x;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(v), x) { pending.push_back(x); }
    ZEND_HASH_FOREACH_END();
  }
  return false;
}

namespace {
std::string take(char *s) {
  std::string r = s ? s : "";
  duckdb_free(s);
  return r;
}
std::string sql_type(duckdb_logical_type t, unsigned depth = 0) {
  if (depth > 64)
    throw std::runtime_error("Type exceeds nesting limit");
  auto id = duckdb_get_type_id(t);
  switch (id) {
  case DUCKDB_TYPE_DECIMAL:
    return "DECIMAL(" + std::to_string(duckdb_decimal_width(t)) + "," +
           std::to_string(duckdb_decimal_scale(t)) + ")";
  case DUCKDB_TYPE_LIST: {
    scoped_duckdb_logical_type c(duckdb_list_type_child_type(t));
    return sql_type(c.get(), depth + 1) + "[]";
  }
  case DUCKDB_TYPE_ARRAY: {
    scoped_duckdb_logical_type c(duckdb_array_type_child_type(t));
    return sql_type(c.get(), depth + 1) + "[" +
           std::to_string(duckdb_array_type_array_size(t)) + "]";
  }
  case DUCKDB_TYPE_MAP: {
    scoped_duckdb_logical_type k(duckdb_map_type_key_type(t)),
        v(duckdb_map_type_value_type(t));
    return "MAP(" + sql_type(k.get(), depth + 1) + ", " +
           sql_type(v.get(), depth + 1) + ")";
  }
  case DUCKDB_TYPE_STRUCT:
  case DUCKDB_TYPE_UNION: {
    bool un = id == DUCKDB_TYPE_UNION;
    std::string s = un ? "UNION(" : "STRUCT(";
    idx_t n = un ? duckdb_union_type_member_count(t)
                 : duckdb_struct_type_child_count(t);
    for (idx_t i = 0; i < n; i++) {
      if (i)
        s += ", ";
      s += quote(take(un ? duckdb_union_type_member_name(t, i)
                         : duckdb_struct_type_child_name(t, i)));
      s += ' ';
      scoped_duckdb_logical_type c(un ? duckdb_union_type_member_type(t, i)
                                      : duckdb_struct_type_child_type(t, i));
      s += sql_type(c.get(), depth + 1);
    }
    return s + ")";
  }
  case DUCKDB_TYPE_ENUM: {
    std::string s = "ENUM(";
    for (idx_t i = 0; i < duckdb_enum_dictionary_size(t); i++) {
      if (i)
        s += ", ";
      s += quote(take(duckdb_enum_dictionary_value(t, i)), '\'');
    }
    return s + ")";
  }
  case DUCKDB_TYPE_GEOMETRY: {
    char *crs = duckdb_geometry_type_get_crs(t);
    std::string s = "GEOMETRY";
    if (crs) {
      s += "(" + quote(take(crs), '\'') + ")";
    }
    return s;
  }
  default: {
    char *a = duckdb_logical_type_get_alias(t);
    if (a) {
      std::string alias = take(a);
      if (alias == "JSON")
        return "JSON";
    }
    return duckdb_type_name(id);
  }
  }
}
struct operation {
  duckdb_error_type error_type = DUCKDB_ERROR_INVALID;
  std::string error_message;
  std::vector<scoped_duckdb_value> values;
};
/* Folding exposes serialized engine exceptions through INVALID_INPUT.
 * Decode them only after native callbacks have returned to the PHP thread. */
void throw_operation_error(const operation &op) {
  duckdb_error_type type = op.error_type;
  std::string message = op.error_message;
  if (!message.empty() && message.front() == '{') {
    zval decoded;
    ZVAL_UNDEF(&decoded);
    if (php_json_decode_ex(&decoded, message.data(), message.size(),
                           PHP_JSON_OBJECT_AS_ARRAY, 512) == SUCCESS &&
        Z_TYPE(decoded) == IS_ARRAY) {
      zval *category =
          zend_hash_str_find(Z_ARRVAL(decoded), "exception_type", 14);
      zval *detail =
          zend_hash_str_find(Z_ARRVAL(decoded), "exception_message", 17);
      if (category && detail && Z_TYPE_P(category) == IS_STRING &&
          Z_TYPE_P(detail) == IS_STRING) {
        std::string prefix(Z_STRVAL_P(category), Z_STRLEN_P(category));
        prefix += " Error: ";
        auto classified = duckdb_classify_error_message(prefix.c_str());
        if (classified != DUCKDB_ERROR_INVALID)
          type = classified;
        message = prefix + std::string(Z_STRVAL_P(detail), Z_STRLEN_P(detail));
      }
    }
    if (!Z_ISUNDEF(decoded))
      zval_ptr_dtor(&decoded);
  }
  duckdb_throw_error(type, message.c_str());
}
/* One catalog callback per database. Active captures live on each consuming
 * connection's stack; the map only routes callbacks by native connection id.
 * A connection's execution mutex protects its operation lifetime. The registry
 * mutex is released before folding, so independent connections can proceed. */
struct capture_state {
  std::string name;
  std::mutex mutex;
  std::unordered_map<idx_t, operation *> active;
};
void capture_bind(duckdb_bind_info info) {
  auto *state = static_cast<std::shared_ptr<capture_state> *>(
      duckdb_scalar_function_bind_get_extra_info(info));
  if (!state)
    return;
  duckdb_client_context ctx = nullptr;
  duckdb_scalar_function_get_client_context(info, &ctx);
  idx_t id = duckdb_client_context_get_connection_id(ctx);
  operation *active = nullptr;
  {
    std::lock_guard<std::mutex> lock((*state)->mutex);
    auto found = (*state)->active.find(id);
    if (found != (*state)->active.end())
      active = found->second;
  }
  if (!active) {
    duckdb_destroy_client_context(&ctx);
    return;
  }
  idx_t count = duckdb_scalar_function_bind_get_argument_count(info);
  std::vector<scoped_duckdb_value> captured;
  bool complete = true;
  for (idx_t i = 0; i < count; i++) {
    duckdb_expression expr = duckdb_scalar_function_bind_get_argument(info, i);
    if (!duckdb_expression_is_foldable(expr)) {
      complete = false;
      duckdb_destroy_expression(&expr);
      break;
    }
    duckdb_value v = nullptr;
    duckdb_error_data err = duckdb_expression_fold(ctx, expr, &v);
    duckdb_destroy_expression(&expr);
    if (duckdb_error_data_has_error(err)) {
      active->error_type = duckdb_error_data_error_type(err);
      active->error_message = duckdb_error_data_message(err);
      duckdb_scalar_function_bind_set_error(info,
                                            duckdb_error_data_message(err));
      duckdb_destroy_error_data(&err);
      if (v)
        duckdb_destroy_value(&v);
      complete = false;
      break;
    }
    duckdb_destroy_error_data(&err);
    captured.emplace_back(v);
  }
  duckdb_destroy_client_context(&ctx);
  if (complete)
    active->values = std::move(captured);
}
void capture_execute(duckdb_function_info, duckdb_data_chunk input,
                     duckdb_vector output) {
  auto *p = (bool *)duckdb_vector_get_data(output);
  for (idx_t i = 0; i < duckdb_data_chunk_get_size(input); i++)
    p[i] = true;
}
bool initialize_registry(db_inner *database) {
  std::lock_guard<std::mutex> lock(database->typed_capture_mutex);
  if (database->typed_capture_state)
    return true;
  /* Register infrastructure outside user transactions. Actual type resolution
   * and captures always run on the original connection below. */
  duckdb_scoped<duckdb_connection, duckdb_disconnect> infrastructure;
  if (duckdb_connect(database->db, infrastructure.out()) == DuckDBError) {
    duckdb_throw_error(
        DUCKDB_ERROR_CONNECTION,
        "Unable to create typed conversion infrastructure connection");
    return false;
  }
  static std::atomic<uint64_t> sequence{0};
  auto state = std::make_shared<capture_state>();
  state->name = "__php_duckdb_value_capture_" +
                std::to_string(reinterpret_cast<uintptr_t>(database)) + "_" +
                std::to_string(++sequence);
  duckdb_scalar_function f = duckdb_create_scalar_function();
  duckdb_scalar_function_set_name(f, state->name.c_str());
  scoped_duckdb_logical_type any(duckdb_create_logical_type(DUCKDB_TYPE_ANY)),
      boolean(duckdb_create_logical_type(DUCKDB_TYPE_BOOLEAN));
  duckdb_scalar_function_set_varargs(f, any.get());
  duckdb_scalar_function_set_return_type(f, boolean.get());
  duckdb_scalar_function_set_special_handling(f);
  duckdb_scalar_function_set_bind(f, capture_bind);
  duckdb_scalar_function_set_function(f, capture_execute);
  duckdb_scalar_function_set_extra_info(
      f, new std::shared_ptr<capture_state>(state),
      [](void *p) { delete static_cast<std::shared_ptr<capture_state> *>(p); });
  auto status = duckdb_register_scalar_function(infrastructure.get(), f);
  duckdb_destroy_scalar_function(&f);
  if (status == DuckDBError) {
    duckdb_throw_msg("Unable to register private typed conversion function");
    return false;
  }
  database->typed_capture_state = state;
  return true;
}
struct builder {
  conn_inner *conn;
  std::vector<scoped_duckdb_value> params;
  std::string parameter(duckdb_value value) {
    if (!value)
      throw std::runtime_error("PHP input conversion failed");
    params.emplace_back(value);
    return "$" + std::to_string(params.size());
  }
  std::string cast(const std::string &s, const std::string &t) {
    return "CAST(" + s + " AS " + t + ")";
  }
  scoped_duckdb_logical_type resolve(const std::string &type) {
    scoped_duckdb_prepared p;
    if (duckdb_prepare(conn->conn,
                       ("SELECT CAST(NULL AS " + type + ")").c_str(),
                       p.out()) == DuckDBError) {
      duckdb_throw_prepare_error(duckdb_prepare_error(p.get()));
      throw std::runtime_error("Type resolution failed");
    }
    return scoped_duckdb_logical_type(
        duckdb_prepared_statement_column_logical_type(p.get(), 0));
  }
  std::string inferred(zval *v, unsigned depth) {
    ZVAL_DEREF(v);
    if (depth > 64) {
      zend_value_error("Value exceeds nesting limit");
      throw std::runtime_error("depth");
    }
    if (Z_TYPE_P(v) == IS_OBJECT &&
        instanceof_function(Z_OBJCE_P(v), value_ce)) {
      auto *w = obj(Z_OBJ_P(v));
      if (!w->type) {
        zend_throw_error(nullptr, "Uninitialized DuckDB\\Value");
        throw std::runtime_error("uninitialized");
      }
      std::string t(ZSTR_VAL(w->type), ZSTR_LEN(w->type));
      auto metadata = resolve(t);
      return typed(&w->input, metadata.get(), t, depth + 1);
    }
    if (Z_TYPE_P(v) == IS_ARRAY && duckdb_value_contains_typed(v)) {
      bool list = zend_array_is_list(Z_ARRVAL_P(v));
      std::string s = list ? "list_value(" : "struct_pack(";
      zend_string *k;
      zend_ulong ix;
      zval *x;
      bool first = true;
      ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(v), ix, k, x) {
        if (!first)
          s += ", ";
        first = false;
        if (!list) {
          s += quote(k ? std::string(ZSTR_VAL(k), ZSTR_LEN(k))
                       : std::to_string(ix));
          s += " := ";
        }
        s += inferred(x, depth + 1);
      }
      ZEND_HASH_FOREACH_END();
      return s + ")";
    }
    return parameter(duckdb_php_to_duckdb_value(v));
  }
  std::string variant_source(zval *v, unsigned depth) {
    ZVAL_DEREF(v);
    if (depth > 64) {
      zend_value_error("Value exceeds nesting limit");
      throw std::runtime_error("depth");
    }
    if (Z_TYPE_P(v) != IS_ARRAY)
      return cast(inferred(v, depth + 1), "VARIANT");
    bool list = zend_array_is_list(Z_ARRVAL_P(v));
    std::string expr = list ? "list_value(" : "struct_pack(";
    zval *x;
    zend_string *k;
    zend_ulong ix;
    bool first = true;
    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(v), ix, k, x) {
      if (!first)
        expr += ", ";
      first = false;
      if (!list)
        expr += quote(k ? std::string(ZSTR_VAL(k), ZSTR_LEN(k))
                        : std::to_string(ix)) +
                " := ";
      expr += variant_source(x, depth + 1);
    }
    ZEND_HASH_FOREACH_END();
    return cast(expr + ")", "VARIANT");
  }
  void shape(bool ok, const char *message) {
    if (!ok) {
      zend_value_error("%s", message);
      throw std::runtime_error(message);
    }
  }
  std::string typed(zval *v, duckdb_logical_type t, const std::string &decl,
                    unsigned depth) {
    ZVAL_DEREF(v);
    if (depth > 64) {
      zend_value_error("Value exceeds nesting limit");
      throw std::runtime_error("depth");
    }
    if (Z_TYPE_P(v) == IS_NULL)
      return cast(parameter(duckdb_create_null_value()), decl);
    if (Z_TYPE_P(v) == IS_OBJECT &&
        instanceof_function(Z_OBJCE_P(v), value_ce)) {
      auto source = inferred(v, depth + 1);
      if (duckdb_get_type_id(t) == DUCKDB_TYPE_GEOMETRY) {
        auto *w = obj(Z_OBJ_P(v));
        auto st = resolve(std::string(ZSTR_VAL(w->type), ZSTR_LEN(w->type)));
        source = duckdb_get_type_id(st.get()) == DUCKDB_TYPE_BLOB
                     ? "ST_GeomFromWKB(" + source + ")"
                     : cast(source, "GEOMETRY");
      }
      return cast(source, decl);
    }
    auto id = duckdb_get_type_id(t);
    std::string expr;
    if (id == DUCKDB_TYPE_LIST || id == DUCKDB_TYPE_ARRAY) {
      shape(Z_TYPE_P(v) == IS_ARRAY && zend_array_is_list(Z_ARRVAL_P(v)),
            "LIST/ARRAY input must be a sequential PHP array");
      if (id == DUCKDB_TYPE_ARRAY)
        shape(zend_hash_num_elements(Z_ARRVAL_P(v)) ==
                  duckdb_array_type_array_size(t),
              "Fixed ARRAY input has the wrong length");
      scoped_duckdb_logical_type child(id == DUCKDB_TYPE_LIST
                                           ? duckdb_list_type_child_type(t)
                                           : duckdb_array_type_child_type(t));
      std::string ct = sql_type(child.get());
      expr = "list_value(";
      zval *x;
      bool first = true;
      ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(v), x) {
        if (!first)
          expr += ", ";
        first = false;
        expr += typed(x, child.get(), ct, depth + 1);
      }
      ZEND_HASH_FOREACH_END();
      expr += ')';
    } else if (id == DUCKDB_TYPE_STRUCT) {
      shape(Z_TYPE_P(v) == IS_ARRAY,
            "STRUCT input must be an associative PHP array");
      idx_t n = duckdb_struct_type_child_count(t);
      shape(zend_hash_num_elements(Z_ARRVAL_P(v)) == n,
            "STRUCT input has missing or unknown fields");
      expr = "struct_pack(";
      for (idx_t i = 0; i < n; i++) {
        std::string name = take(duckdb_struct_type_child_name(t, i));
        zval *x =
            zend_symtable_str_find(Z_ARRVAL_P(v), name.data(), name.size());
        shape(x != nullptr, "STRUCT input has missing or unknown fields");
        if (i)
          expr += ", ";
        scoped_duckdb_logical_type child(duckdb_struct_type_child_type(t, i));
        expr += quote(name) + " := " +
                typed(x, child.get(), sql_type(child.get()), depth + 1);
      }
      expr += ')';
    } else if (id == DUCKDB_TYPE_MAP) {
      shape(Z_TYPE_P(v) == IS_ARRAY && zend_array_is_list(Z_ARRVAL_P(v)),
            "MAP input must be a sequential array of key/value pairs");
      scoped_duckdb_logical_type kt(duckdb_map_type_key_type(t)),
          vt(duckdb_map_type_value_type(t));
      std::string keys = "list_value(", vals = "list_value(";
      zval *x;
      bool first = true;
      ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(v), x) {
        ZVAL_DEREF(x);
        shape(Z_TYPE_P(x) == IS_ARRAY &&
                  zend_hash_num_elements(Z_ARRVAL_P(x)) == 2,
              "MAP entries must contain key and value");
        zval *k = zend_hash_str_find(Z_ARRVAL_P(x), "key", 3),
             *vv = zend_hash_str_find(Z_ARRVAL_P(x), "value", 5);
        shape(k && vv, "MAP entries must contain key and value");
        if (!first) {
          keys += ", ";
          vals += ", ";
        }
        first = false;
        keys += typed(k, kt.get(), sql_type(kt.get()), depth + 1);
        vals += typed(vv, vt.get(), sql_type(vt.get()), depth + 1);
      }
      ZEND_HASH_FOREACH_END();
      expr = "map(" + cast(keys + ")", sql_type(kt.get()) + "[]") + ", " +
             cast(vals + ")", sql_type(vt.get()) + "[]") + ")";
    } else if (id == DUCKDB_TYPE_UNION) {
      shape(Z_TYPE_P(v) == IS_ARRAY &&
                zend_hash_num_elements(Z_ARRVAL_P(v)) == 2,
            "UNION input requires tag and value");
      zval *tag = zend_hash_str_find(Z_ARRVAL_P(v), "tag", 3),
           *payload = zend_hash_str_find(Z_ARRVAL_P(v), "value", 5);
      shape(tag && payload, "UNION input requires tag and value");
      ZVAL_DEREF(tag);
      shape(Z_TYPE_P(tag) == IS_STRING, "UNION tag must be a string");
      bool found = false;
      for (idx_t i = 0; i < duckdb_union_type_member_count(t); i++) {
        std::string name = take(duckdb_union_type_member_name(t, i));
        if (name == std::string(Z_STRVAL_P(tag), Z_STRLEN_P(tag))) {
          found = true;
          scoped_duckdb_logical_type child(duckdb_union_type_member_type(t, i));
          expr = "union_value(" + quote(name) + " := " +
                 typed(payload, child.get(), sql_type(child.get()), depth + 1) +
                 ")";
          break;
        }
      }
      shape(found, "Unknown UNION tag");
    } else if (id == DUCKDB_TYPE_VARIANT && Z_TYPE_P(v) == IS_ARRAY) {
      return variant_source(v, depth + 1);
    } else if (id == DUCKDB_TYPE_BLOB && Z_TYPE_P(v) == IS_STRING)
      expr = parameter(
          duckdb_create_blob((const uint8_t *)Z_STRVAL_P(v), Z_STRLEN_P(v)));
    else
      expr = inferred(v, depth + 1);
    return cast(expr, decl);
  }
};
} // namespace
bool duckdb_initialize_typed_registry(db_inner *database) {
  return initialize_registry(database);
}
bool duckdb_convert_values(conn_inner *c, const std::vector<zval *> &inputs,
                           std::vector<scoped_duckdb_value> &out) {
  bool typed = false;
  for (auto *v : inputs)
    if (duckdb_value_contains_typed(v)) {
      typed = true;
      break;
    }
  if (!typed) {
    std::vector<scoped_duckdb_value> values;
    for (auto *v : inputs) {
      values.emplace_back(duckdb_php_to_duckdb_value(v));
      if (!values.back())
        return false;
    }
    out = std::move(values);
    return true;
  }
  c->execution_epoch.fetch_add(1, std::memory_order_acq_rel);
  if (!duckdb_initialize_typed_registry(c->db.get()))
    return false;
  auto state =
      std::static_pointer_cast<capture_state>(c->db->typed_capture_state);
  operation op;
  duckdb_client_context context = nullptr;
  duckdb_connection_get_client_context(c->conn, &context);
  idx_t id = duckdb_client_context_get_connection_id(context);
  duckdb_destroy_client_context(&context);
  struct active_guard {
    capture_state *s;
    idx_t id;
    ~active_guard() {
      std::lock_guard<std::mutex> lock(s->mutex);
      s->active.erase(id);
    }
  } guard{state.get(), id};
  try {
    {
      std::lock_guard<std::mutex> lock(state->mutex);
      state->active.emplace(id, &op);
    }
    builder b{c, {}};
    std::string sql = "SELECT " + quote(state->name) + "(";
    for (size_t i = 0; i < inputs.size(); i++) {
      if (i)
        sql += ", ";
      sql += b.inferred(inputs[i], 0);
    }
    /* A bare ANY parameter forces DuckDB to rebind after values are supplied,
     * even when every explicit cast already inferred its parameter type. */
    sql += ", " + b.parameter(duckdb_create_int64(0)) + ")";
    scoped_duckdb_prepared p;
    if (duckdb_prepare(c->conn, sql.c_str(), p.out()) == DuckDBError) {
      if (!op.error_message.empty())
        throw_operation_error(op);
      else
        duckdb_throw_prepare_error(duckdb_prepare_error(p.get()));
      return false;
    }
    for (size_t i = 0; i < b.params.size(); i++)
      if (duckdb_bind_value(p.get(), i + 1, b.params[i].get()) == DuckDBError) {
        duckdb_throw_prepare_error(duckdb_prepare_error(p.get()));
        return false;
      }
    duckdb_result result{};
    if (duckdb_execute_prepared(p.get(), &result) == DuckDBError) {
      if (!op.error_message.empty()) {
        duckdb_destroy_result(&result);
        throw_operation_error(op);
      } else
        duckdb_throw_result_error(&result);
      return false;
    }
    duckdb_destroy_result(&result);
    if (op.values.size() != inputs.size() + 1) {
      duckdb_throw_msg("Typed conversion did not capture every native value");
      return false;
    }
    op.values.pop_back();
    out = std::move(op.values);
    return true;
  } catch (const std::exception &e) {
    if (!EG(exception))
      zend_value_error("%s", e.what());
    return false;
  }
}
