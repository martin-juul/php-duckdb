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

  dnl Check capabilities: distribution SDKs may predate API version macros.
  PHP_CHECK_LIBRARY([duckdb], [duckdb_expression_fold], [],
    [AC_MSG_ERROR([DuckDB expression folding is required (duckdb_expression_fold missing)])],
    [-L$DUCKDB_DIR/lib])
  PHP_CHECK_LIBRARY([duckdb], [duckdb_scalar_function_set_bind], [],
    [AC_MSG_ERROR([DuckDB scalar bind callbacks are required])],
    [-L$DUCKDB_DIR/lib])

  PHP_CHECK_LIBRARY([duckdb], [duckdb_appender_clear], [],
    [AC_MSG_ERROR([DuckDB appender recovery is required (duckdb_appender_clear missing)])],
    [-L$DUCKDB_DIR/lib])

  PHP_CHECK_LIBRARY([duckdb], [duckdb_create_vector], [],
    [AC_MSG_ERROR([DuckDB standalone vectors are required (duckdb_create_vector missing)])],
    [-L$DUCKDB_DIR/lib])

  PHP_REQUIRE_CXX()
  dnl macOS no longer ships libstdc++; the C++ runtime there is libc++.
  case $host_os in
    darwin*) PHP_ADD_LIBRARY([c++], [1], [DUCKDB_SHARED_LIBADD]) ;;
    *)       PHP_ADD_LIBRARY([stdc++], [1], [DUCKDB_SHARED_LIBADD]) ;;
  esac
  CXXFLAGS="$CXXFLAGS -std=c++17 -Wall -Wextra"

  PHP_SUBST([DUCKDB_SHARED_LIBADD])
  PHP_ADD_BUILD_DIR([$ext_builddir/src])
  PHP_NEW_EXTENSION([duckdb],
    [duckdb.cpp src/arrow.cpp src/data_chunk.cpp src/typed_value.cpp src/type_classes.cpp src/values.cpp src/bignum_decode.cpp src/variant_decode.cpp src/result.cpp src/statement.cpp src/pending.cpp src/suspend_swoole.cpp src/suspend_true_async.cpp src/suspend_amphp.cpp src/suspend_reactphp.cpp src/appender.cpp src/vector.cpp src/selection.cpp],
    [$ext_shared],, [-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1], [cxx])
fi
