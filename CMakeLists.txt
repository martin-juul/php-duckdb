# CMake build for the duckdb PHP extension.
#
# This is an auxiliary build: it exists for IDE integration (code
# navigation, compile_commands.json) and static analysis (clang-tidy).
# The supported way to produce a loadable module remains phpize +
# ./configure (config.m4 / config.w32) or the test harness
# (tests/harness.php), which also drives the .phpt suite.
#
# Usage:
#   cmake -B build -DCMAKE_BUILD_TYPE=RelWithDebInfo
#   cmake --build build
#   cmake --build build --target tidy     # run clang-tidy (if installed)
#
# Options:
#   -DPHP_CONFIG=/path/to/php-config   PHP build to target (default: PATH)
#   -DDUCKDB_DIR=/opt/duckdb           Explicit DuckDB SDK prefix (or $DUCKDB_DIR)
#   -DDUCKDB_AUTO_BUILD=OFF            Disable automatic pinned SDK setup
# Requires PHP 8.2+ and the repository's patched DuckDB SDK. This auxiliary
# configuration supports Unix toolchains; Windows builds use config.w32.

cmake_minimum_required(VERSION 3.16)
project(duckdb_php LANGUAGES CXX)

if (WIN32)
    message(FATAL_ERROR "Use the PHP SDK and config.w32 to build this extension on Windows")
endif()

set(CMAKE_CXX_STANDARD 17)          # mirrors CXXFLAGS in config.m4
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS OFF)
set(CMAKE_EXPORT_COMPILE_COMMANDS ON)   # compile_commands.json for clang-tidy / IDEs

# ------------------------------------------------------------------ PHP

set(PHP_CONFIG "" CACHE FILEPATH "Path to php-config (default: search PATH)")
if (PHP_CONFIG STREQUAL "")
    unset(PHP_CONFIG CACHE)
    find_program(PHP_CONFIG NAMES php-config)
endif()
if (NOT PHP_CONFIG)
    message(FATAL_ERROR
        "php-config not found. Install the PHP development package "
        "(php-dev / php-devel) or pass -DPHP_CONFIG=/path/to/php-config.")
endif()

function(php_config_query option output)
    execute_process(
        COMMAND "${PHP_CONFIG}" "${option}"
        RESULT_VARIABLE _status
        OUTPUT_VARIABLE _output
        ERROR_VARIABLE _error
        OUTPUT_STRIP_TRAILING_WHITESPACE)
    if (NOT "${_status}" STREQUAL "0")
        message(FATAL_ERROR "${PHP_CONFIG} ${option} failed (${_status}): ${_error}")
    endif()
    set(${output} "${_output}" PARENT_SCOPE)
endfunction()

php_config_query(--includes _php_includes_raw)
php_config_query(--vernum _php_vernum)
if (NOT _php_vernum MATCHES "^[0-9]+$")
    message(FATAL_ERROR "php-config --vernum returned an invalid version: ${_php_vernum}")
endif()
if (_php_vernum LESS 80200)
    message(FATAL_ERROR "PHP 8.2 or newer is required")
endif()

# php-config --includes returns "-I/dir -I/dir ..."; strip the flags.
separate_arguments(_php_include_flags UNIX_COMMAND "${_php_includes_raw}")
set(PHP_INCLUDE_DIRS "")
foreach (_flag IN LISTS _php_include_flags)
    if (NOT _flag MATCHES "^-I.+")
        message(FATAL_ERROR "Unexpected php-config --includes flag: ${_flag}")
    endif()
    string(REGEX REPLACE "^-I" "" _dir "${_flag}")
    list(APPEND PHP_INCLUDE_DIRS "${_dir}")
endforeach()
list(LENGTH PHP_INCLUDE_DIRS _php_include_count)
if (_php_include_count EQUAL 0)
    message(FATAL_ERROR "php-config --includes returned no include directories")
endif()

# ZTS builds need -DZTS for the module to compile against the right
# thread-safety structs; detect it from the target PHP's own headers.
set(_php_config_header "")
foreach (_dir IN LISTS PHP_INCLUDE_DIRS)
    if (EXISTS "${_dir}/main/php_config.h")
        set(_php_config_header "${_dir}/main/php_config.h")
        break()
    elseif (EXISTS "${_dir}/php_config.h")
        set(_php_config_header "${_dir}/php_config.h")
        break()
    endif()
endforeach()
if (NOT _php_config_header)
    message(FATAL_ERROR "php_config.h was not found in the target PHP include directories")
endif()
set(PHP_ZTS OFF)
file(STRINGS "${_php_config_header}" _zts_line REGEX "^[ \t]*#[ \t]*define[ \t]+ZTS([ \t]|$)")
if (_zts_line)
    set(PHP_ZTS ON)
endif()

math(EXPR _php_major "${_php_vernum} / 10000")
math(EXPR _php_minor "(${_php_vernum} / 100) % 100")
message(STATUS "Targeting PHP ${_php_major}.${_php_minor} (php-config: ${PHP_CONFIG}, ZTS: ${PHP_ZTS})")

# ------------------------------------------------------------------ DuckDB

option(DUCKDB_AUTO_BUILD "Build the pinned patched SDK when no local SDK is configured" ON)

function(setup_duckdb_sdk)
    execute_process(
        COMMAND "${CMAKE_COMMAND}" -E env "CXX=${CMAKE_CXX_COMPILER}"
            sh "${CMAKE_CURRENT_SOURCE_DIR}/packaging/duckdb/build-sdk.sh"
            --prefix "${DUCKDB_DIR}"
            --work-dir "${CMAKE_BINARY_DIR}/duckdb-sdk-build"
        RESULT_VARIABLE _duckdb_setup_status)
    if (NOT "${_duckdb_setup_status}" STREQUAL "0")
        message(FATAL_ERROR "Pinned DuckDB SDK setup failed (${_duckdb_setup_status}); provide DUCKDB_DIR or inspect the builder output")
    endif()
endfunction()

# Migrate the old automatic default in an existing IDE cache. A new untyped
# command-line -D override has its own cache help text (and untyped overrides
# are UNINITIALIZED), so explicit missing paths still fail directly.
get_property(_duckdb_cache_type CACHE DUCKDB_DIR PROPERTY TYPE)
get_property(_duckdb_cache_help CACHE DUCKDB_DIR PROPERTY HELPSTRING)
if (DEFINED DUCKDB_DIR AND DUCKDB_DIR STREQUAL "/opt/duckdb"
        AND _duckdb_cache_type STREQUAL "PATH"
        AND _duckdb_cache_help STREQUAL "DuckDB install prefix (include/duckdb.h, lib/libduckdb)"
        AND NOT EXISTS "${DUCKDB_DIR}/include/duckdb.h"
        AND NOT DEFINED DUCKDB_CONFIGURATION_VERSION)
    message(STATUS "Discarding the missing legacy automatic SDK default /opt/duckdb")
    unset(DUCKDB_DIR CACHE)
    unset(DUCKDB_DIR)
endif()

# Switching the prefix makes SDK lifecycle caller-managed.
if (DEFINED DUCKDB_DISCOVERED_DIR AND DEFINED DUCKDB_DIR
        AND NOT DUCKDB_DIR STREQUAL DUCKDB_DISCOVERED_DIR)
    unset(DUCKDB_DISCOVERED_DIR CACHE)
endif()

# Temporary SDKs selected by discovery may disappear between IDE reloads.
# Retry discovery only for our own cached selection, never an explicit override.
if (DEFINED DUCKDB_DISCOVERED_DIR AND DEFINED DUCKDB_DIR
        AND DUCKDB_DIR STREQUAL DUCKDB_DISCOVERED_DIR
        AND _duckdb_cache_type STREQUAL "PATH"
        AND _duckdb_cache_help STREQUAL "DuckDB install prefix (include/duckdb.h, lib/libduckdb)"
        AND (NOT DEFINED ENV{DUCKDB_DIR} OR "$ENV{DUCKDB_DIR}" STREQUAL "")
        AND (NOT EXISTS "${DUCKDB_DIR}/include/duckdb.h" OR
             (NOT EXISTS "${DUCKDB_DIR}/lib/libduckdb.so" AND
              NOT EXISTS "${DUCKDB_DIR}/lib/libduckdb.dylib")))
    message(STATUS "Automatically selected DuckDB SDK disappeared; retrying discovery")
    unset(DUCKDB_DIR CACHE)
    unset(DUCKDB_DIR)
    unset(DUCKDB_DISCOVERED_DIR CACHE)
endif()

if (NOT DEFINED DUCKDB_DIR OR DUCKDB_DIR STREQUAL "")
    if (DEFINED ENV{DUCKDB_DIR} AND NOT "$ENV{DUCKDB_DIR}" STREQUAL "")
        set(DUCKDB_DIR "$ENV{DUCKDB_DIR}")
    else()
        # Reuse the SDK selected by a repository phpize/harness build. Treat
        # config.nice as data; never execute this generated shell script.
        if (EXISTS "${CMAKE_CURRENT_SOURCE_DIR}/config.nice")
            file(STRINGS "${CMAKE_CURRENT_SOURCE_DIR}/config.nice" _duckdb_configure_lines
                REGEX "--with-duckdb=")
            foreach (_line IN LISTS _duckdb_configure_lines)
                if (_line MATCHES "['\"]--with-duckdb=([^'\"]+)['\"]")
                    get_filename_component(_candidate "${CMAKE_MATCH_1}" ABSOLUTE
                        BASE_DIR "${CMAKE_CURRENT_SOURCE_DIR}")
                    if (EXISTS "${_candidate}/include/duckdb.h" AND
                            (EXISTS "${_candidate}/lib/libduckdb.so" OR
                             EXISTS "${_candidate}/lib/libduckdb.dylib"))
                        set(DUCKDB_DIR "${_candidate}")
                        message(STATUS "Reusing repository-configured DuckDB SDK: ${DUCKDB_DIR}")
                        break()
                    endif()
                endif()
            endforeach()
        endif()
        if (NOT DEFINED DUCKDB_DIR OR DUCKDB_DIR STREQUAL "")
            set(DUCKDB_DIR "${CMAKE_BINARY_DIR}/duckdb-sdk")
            if (DUCKDB_AUTO_BUILD)
                message(STATUS "Setting up the pinned patched DuckDB SDK in ${DUCKDB_DIR}; cold setup downloads and compiles the engine")
                setup_duckdb_sdk()
                set(_duckdb_setup_ran TRUE)
            elseif (NOT EXISTS "${DUCKDB_DIR}/include/duckdb.h")
                message(FATAL_ERROR "No configured DuckDB SDK found and DUCKDB_AUTO_BUILD is OFF; provide -DDUCKDB_DIR=DIR")
            endif()
        endif()
        set(DUCKDB_DISCOVERED_DIR "${DUCKDB_DIR}" CACHE INTERNAL "Automatically selected DuckDB SDK" FORCE)
    endif()
endif()
set(DUCKDB_DIR "${DUCKDB_DIR}" CACHE PATH "DuckDB install prefix (include/duckdb.h, lib/libduckdb)" FORCE)
set(DUCKDB_CONFIGURATION_VERSION 1 CACHE INTERNAL "SDK discovery cache format" FORCE)

# Recheck automatically built artifacts and both patch/source pins on reload.
# Explicit and repository-configured SDKs retain their caller-managed lifecycle.
if (DUCKDB_AUTO_BUILD AND NOT _duckdb_setup_ran
        AND DEFINED DUCKDB_DISCOVERED_DIR
        AND DUCKDB_DIR STREQUAL DUCKDB_DISCOVERED_DIR
        AND DUCKDB_DIR STREQUAL "${CMAKE_BINARY_DIR}/duckdb-sdk")
    message(STATUS "Verifying the automatically built patched DuckDB SDK")
    setup_duckdb_sdk()
endif()

# Reconfiguring with another SDK must not retain paths or ABI checks from the
# previous prefix, including when a different SDK is installed at the same path.
unset(DUCKDB_INCLUDE_DIR CACHE)
unset(DUCKDB_INCLUDE_DIR)
unset(DUCKDB_LIBRARY CACHE)
unset(DUCKDB_LIBRARY)
find_path(DUCKDB_INCLUDE_DIR
    NAMES duckdb.h
    PATHS "${DUCKDB_DIR}/include" "${DUCKDB_DIR}"
    NO_DEFAULT_PATH)
if (NOT DUCKDB_INCLUDE_DIR)
    message(FATAL_ERROR
        "duckdb.h not found under ${DUCKDB_DIR}/include. "
        "Pass -DDUCKDB_DIR=DIR or set the DUCKDB_DIR environment variable.")
endif()

find_library(DUCKDB_LIBRARY
    NAMES duckdb libduckdb
    PATHS "${DUCKDB_DIR}/lib" "${DUCKDB_DIR}"
    NO_DEFAULT_PATH)
if (NOT DUCKDB_LIBRARY)
    message(FATAL_ERROR
        "libduckdb not found under ${DUCKDB_DIR}/lib. "
        "Pass -DDUCKDB_DIR=DIR or set the DUCKDB_DIR environment variable.")
endif()

include(CheckCXXSourceCompiles)
include(CMakePushCheckState)
cmake_push_check_state(RESET)
set(CMAKE_REQUIRED_INCLUDES "${DUCKDB_INCLUDE_DIR}")
set(CMAKE_REQUIRED_LIBRARIES "${DUCKDB_LIBRARY}")
unset(DUCKDB_HAS_TYPED_VALUE_APIS CACHE)
unset(DUCKDB_HAS_TYPED_VALUE_APIS)
check_cxx_source_compiles("#include <duckdb.h>
int main() {
    duckdb_value value = nullptr;
    duckdb_expression_fold(nullptr, nullptr, &value);
    duckdb_scalar_function_set_bind(nullptr, nullptr);
    duckdb_appender_clear(nullptr);
    return 0;
}" DUCKDB_HAS_TYPED_VALUE_APIS)
if (NOT DUCKDB_HAS_TYPED_VALUE_APIS)
    message(FATAL_ERROR "DuckDB expression folding, scalar bind and appender clear APIs are required")
endif()

unset(DUCKDB_HAS_ARROW_APIS CACHE)
unset(DUCKDB_HAS_ARROW_APIS)
check_cxx_source_compiles("#include <duckdb.h>
int main() {
    duckdb_arrow_options options = nullptr;
    duckdb_connection_get_arrow_options(nullptr, &options);
    duckdb_destroy_arrow_options(&options);
    options = duckdb_result_get_arrow_options(nullptr);
    duckdb_arrow_converted_schema schema = nullptr;
    duckdb_schema_from_arrow(nullptr, nullptr, &schema);
    duckdb_data_chunk chunk = nullptr;
    duckdb_data_chunk_from_arrow(nullptr, nullptr, schema, &chunk);
    duckdb_data_chunk_to_arrow(options, chunk, nullptr);
    duckdb_error_data error = duckdb_to_arrow_schema(options, nullptr, nullptr, 0, nullptr);
    duckdb_error_data_has_error(error);
    duckdb_error_data_error_type(error);
    duckdb_error_data_message(error);
    duckdb_destroy_error_data(&error);
    duckdb_destroy_arrow_converted_schema(&schema);
    duckdb_geometry_type_get_crs(nullptr);
    return 0;
}" DUCKDB_HAS_ARROW_APIS)
if (NOT DUCKDB_HAS_ARROW_APIS)
    message(FATAL_ERROR "DuckDB Arrow C Data Interface and geometry CRS APIs are required; use the repository's patched SDK")
endif()

unset(DUCKDB_HAS_VECTOR_APIS CACHE)
unset(DUCKDB_HAS_VECTOR_APIS)
check_cxx_source_compiles("#include <duckdb.h>
int main() {
    duckdb_vector vector = duckdb_create_vector(nullptr, 0);
    duckdb_vector_reference_value(vector, nullptr);
    duckdb_vector_copy_sel(vector, vector, nullptr, 0, 0, 0);
    duckdb_unsafe_vector_assign_string_element_len(vector, 0, nullptr, 0);
    duckdb_error_data error = duckdb_valid_utf8_check(nullptr, 0);
    duckdb_destroy_error_data(&error);
    duckdb_destroy_vector(&vector);
    return 0;
}" DUCKDB_HAS_VECTOR_APIS)
if (NOT DUCKDB_HAS_VECTOR_APIS)
    message(FATAL_ERROR "DuckDB standalone vector and UTF-8 check APIs are required")
endif()
cmake_pop_check_state()

# ------------------------------------------------------------------ extension

# Source list mirrors PHP_NEW_EXTENSION() in config.m4.
set(DUCKDB_SOURCES
    duckdb.cpp
    src/arrow.cpp
    src/data_chunk.cpp
    src/typed_value.cpp
    src/type_classes.cpp
    src/values.cpp
    src/bignum_decode.cpp
    src/variant_decode.cpp
    src/result.cpp
    src/statement.cpp
    src/pending.cpp
    src/suspend_swoole.cpp
    src/suspend_true_async.cpp
    src/suspend_amphp.cpp
    src/suspend_reactphp.cpp
    src/appender.cpp
    src/vector.cpp
    src/selection.cpp
    src/copy_session.cpp)

set(DUCKDB_HEADERS
    php_duckdb.h
    php_duckdb_cxx_compat.h
    duckdb_arginfo.h
    src/arrow.h
    src/data_chunk.h
    src/vector.h
    src/selection.h
    src/copy_session.h
    src/bignum_decode.h
    src/variant_decode.h
    src/type_classes.h
    src/suspend_internal.h)

add_library(duckdb MODULE ${DUCKDB_SOURCES} ${DUCKDB_HEADERS})

# PHP extensions are loaded by name: produce duckdb.so, not libduckdb_php.so.
set_target_properties(duckdb PROPERTIES PREFIX "")

target_include_directories(duckdb PRIVATE
    ${PHP_INCLUDE_DIRS}
    ${DUCKDB_INCLUDE_DIR}
    ${CMAKE_CURRENT_SOURCE_DIR})

# ZEND_ENABLE_STATIC_TSRMLS_CACHE matches the phpize build (config.m4).
target_compile_definitions(duckdb PRIVATE ZEND_ENABLE_STATIC_TSRMLS_CACHE=1 COMPILE_DL_DUCKDB=1)
if (PHP_ZTS)
    target_compile_definitions(duckdb PRIVATE ZTS)
endif()

if (CMAKE_CXX_COMPILER_ID MATCHES "GNU|Clang")
    target_compile_options(duckdb PRIVATE -Wall -Wextra)   # mirrors config.m4
endif()

set(THREADS_PREFER_PTHREAD_FLAG TRUE)
find_package(Threads REQUIRED)
target_link_libraries(duckdb PRIVATE ${DUCKDB_LIBRARY} Threads::Threads)
if (APPLE)
    # Zend/PHP symbols resolve in the PHP process when the module is loaded.
    target_link_options(duckdb PRIVATE "LINKER:-undefined,dynamic_lookup")
endif()

# ------------------------------------------------------------------ clang-tidy

# `cmake --build build --target tidy` lints the extension sources with the
# project configuration in .clang-tidy. duckdb_arginfo.h is generated from
# duckdb.stub.php (gen_stub.php); diagnostics inside it should be fixed in
# the stub, not in the generated file.
find_program(CLANG_TIDY NAMES clang-tidy)
if (CLANG_TIDY)
    add_custom_target(tidy
        COMMAND ${CLANG_TIDY} -p ${CMAKE_BINARY_DIR} ${DUCKDB_SOURCES}
        WORKING_DIRECTORY ${CMAKE_CURRENT_SOURCE_DIR}
        COMMENT "Running clang-tidy (configuration: .clang-tidy)"
        VERBATIM)
else()
    message(STATUS "clang-tidy not found; the 'tidy' target is unavailable")
endif()
