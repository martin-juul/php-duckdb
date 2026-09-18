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
#   -DDUCKDB_DIR=/opt/duckdb           DuckDB install prefix (or $DUCKDB_DIR)

cmake_minimum_required(VERSION 3.16)
project(duckdb_php LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)          # mirrors CXXFLAGS in config.m4
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS OFF)
set(CMAKE_EXPORT_COMPILE_COMMANDS ON)   # compile_commands.json for clang-tidy / IDEs

# ------------------------------------------------------------------ PHP

set(PHP_CONFIG "" CACHE FILEPATH "Path to php-config (default: search PATH)")
if (PHP_CONFIG STREQUAL "")
    find_program(PHP_CONFIG NAMES php-config)
endif()
if (NOT PHP_CONFIG)
    message(FATAL_ERROR
        "php-config not found. Install the PHP development package "
        "(php-dev / php-devel) or pass -DPHP_CONFIG=/path/to/php-config.")
endif()

execute_process(
    COMMAND ${PHP_CONFIG} --includes
    OUTPUT_VARIABLE _php_includes_raw
    OUTPUT_STRIP_TRAILING_WHITESPACE
    COMMAND_ERROR_IS_FATAL ANY)
execute_process(
    COMMAND ${PHP_CONFIG} --vernum
    OUTPUT_VARIABLE _php_vernum
    OUTPUT_STRIP_TRAILING_WHITESPACE
    COMMAND_ERROR_IS_FATAL ANY)

# php-config --includes returns "-I/dir -I/dir ..."; strip the flags.
separate_arguments(_php_include_flags UNIX_COMMAND "${_php_includes_raw}")
set(PHP_INCLUDE_DIRS "")
foreach (_flag IN LISTS _php_include_flags)
    string(REGEX REPLACE "^-I" "" _dir "${_flag}")
    list(APPEND PHP_INCLUDE_DIRS "${_dir}")
endforeach()
list(LENGTH PHP_INCLUDE_DIRS _php_include_count)
if (_php_include_count EQUAL 0)
    message(FATAL_ERROR "php-config --includes returned no include directories")
endif()

# ZTS builds need -DZTS for the module to compile against the right
# thread-safety structs; detect it from the target PHP's own headers.
list(GET PHP_INCLUDE_DIRS 0 _php_main_include)
set(PHP_ZTS OFF)
if (EXISTS "${_php_main_include}/main/php_config.h")
    file(STRINGS "${_php_main_include}/main/php_config.h" _zts_line REGEX "^#define ZTS ")
    if (_zts_line)
        set(PHP_ZTS ON)
    endif()
endif()

math(EXPR _php_major "${_php_vernum} / 10000")
math(EXPR _php_minor "(${_php_vernum} / 100) % 100")
message(STATUS "Targeting PHP ${_php_major}.${_php_minor} (php-config: ${PHP_CONFIG}, ZTS: ${PHP_ZTS})")

# ------------------------------------------------------------------ DuckDB

if (NOT DEFINED DUCKDB_DIR OR DUCKDB_DIR STREQUAL "")
    if (DEFINED ENV{DUCKDB_DIR} AND NOT "$ENV{DUCKDB_DIR}" STREQUAL "")
        set(DUCKDB_DIR "$ENV{DUCKDB_DIR}")
    else()
        set(DUCKDB_DIR "/opt/duckdb")
    endif()
endif()
set(DUCKDB_DIR "${DUCKDB_DIR}" CACHE PATH "DuckDB install prefix (include/duckdb.h, lib/libduckdb)")

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

# ------------------------------------------------------------------ extension

# Source list mirrors PHP_NEW_EXTENSION() in config.m4.
set(DUCKDB_SOURCES
    duckdb.cpp
    src/values.cpp
    src/result.cpp
    src/statement.cpp
    src/pending.cpp
    src/suspend_swoole.cpp
    src/suspend_true_async.cpp
    src/suspend_amphp.cpp
    src/suspend_reactphp.cpp
    src/appender.cpp)

add_library(duckdb MODULE ${DUCKDB_SOURCES})

# PHP extensions are loaded by name: produce duckdb.so, not libduckdb_php.so.
set_target_properties(duckdb PROPERTIES PREFIX "")

target_include_directories(duckdb PRIVATE
    ${PHP_INCLUDE_DIRS}
    ${DUCKDB_INCLUDE_DIR}
    ${CMAKE_CURRENT_SOURCE_DIR})

# ZEND_ENABLE_STATIC_TSRMLS_CACHE matches the phpize build (config.m4).
target_compile_definitions(duckdb PRIVATE ZEND_ENABLE_STATIC_TSRMLS_CACHE=1)
if (PHP_ZTS)
    target_compile_definitions(duckdb PRIVATE ZTS)
endif()

if (CMAKE_CXX_COMPILER_ID MATCHES "GNU|Clang")
    target_compile_options(duckdb PRIVATE -Wall -Wextra)   # mirrors config.m4
endif()

target_link_libraries(duckdb PRIVATE ${DUCKDB_LIBRARY})

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
