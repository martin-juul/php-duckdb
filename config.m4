dnl config.m4 for the duckdb PHP extension

PHP_ARG_WITH([duckdb],
  [whether to enable duckdb support],
  [AS_HELP_STRING([--with-duckdb@<:@=DIR@:>@],
    [Include duckdb support. DIR is the DuckDB install prefix containing include/duckdb.h and lib/libduckdb])],
  [no])

if test "$PHP_DUCKDB" != "no"; then
  SEARCH_PATH="/usr/local /opt/duckdb /usr"
  SEARCH_FOR="include/duckdb.h"

  if test -r "$PHP_DUCKDB/$SEARCH_FOR"; then
    DUCKDB_DIR=$PHP_DUCKDB
  else
    AC_MSG_CHECKING([for duckdb files in default path])
    for i in $SEARCH_PATH; do
      if test -r "$i/$SEARCH_FOR"; then
        DUCKDB_DIR=$i
        AC_MSG_RESULT([found in $i])
        break
      fi
    done
    if test -z "$DUCKDB_DIR"; then
      AC_MSG_RESULT([not found])
      AC_MSG_ERROR([Please install the DuckDB C API library, or use --with-duckdb=DIR])
    fi
  fi

  PHP_ADD_INCLUDE([$DUCKDB_DIR/include])

  LIBNAME=duckdb
  LIBSYMBOL=duckdb_open
  PHP_CHECK_LIBRARY([$LIBNAME], [$LIBSYMBOL],
    [PHP_ADD_LIBRARY_WITH_PATH([$LIBNAME], [$DUCKDB_DIR/lib], [DUCKDB_SHARED_LIBADD])],
    [AC_MSG_ERROR([not found. Try --with-duckdb=DIR or check that libduckdb is in $DUCKDB_DIR/lib])],
    [-L$DUCKDB_DIR/lib])

  PHP_REQUIRE_CXX()
  PHP_ADD_LIBRARY([stdc++], [1], [DUCKDB_SHARED_LIBADD])
  CXXFLAGS="$CXXFLAGS -std=c++17 -Wall -Wextra"

  PHP_SUBST([DUCKDB_SHARED_LIBADD])
  PHP_ADD_BUILD_DIR([$ext_builddir/src])
  PHP_NEW_EXTENSION([duckdb],
    [duckdb.cpp src/values.cpp src/result.cpp src/statement.cpp src/pending.cpp src/suspend_swoole.cpp src/suspend_true_async.cpp src/suspend_amphp.cpp src/suspend_reactphp.cpp src/appender.cpp],
    [$ext_shared],, [-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1], [cxx])
fi
