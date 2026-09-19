/*
  +----------------------------------------------------------------------+
  | duckdb - native DuckDB driver for PHP                                |
  +----------------------------------------------------------------------+
  | Copyright (c) Martin Juul Christiansen (https://juul.xyz)            |
  +----------------------------------------------------------------------+
  | This source file is subject to the MIT license that is bundled with  |
  | this package in the file LICENSE.                                    |
  +----------------------------------------------------------------------+
*/

/*
  C++ compatibility shim for the PHP headers — included in place of
  "php.h" by every translation unit of this extension.

  PHP headers up to 8.3.6 declare zend_enum_get_case_by_value() with a
  parameter named `try`, which is a keyword in C++ (Zend/zend_enum.h).
  Upstream renamed it to try_from during the 8.2/8.3 patch series, but
  distributions that freeze a base version and backport only security
  fixes — e.g. Ubuntu 24.04 LTS, pinned at 8.3.6 — ship the broken
  header for the lifetime of the release.

  The rename is token-level, so defining `try` away for the duration of
  the PHP header inclusion is safe on both affected and fixed headers
  (on fixed headers the macro simply matches nothing). The libstdc++
  headers used by the PHP header chain (cmath via zend_portability.h)
  are included first so their include guards shield them from the macro:
  their __try/__catch macros expand to the `try` keyword and would
  otherwise be corrupted as well.
*/

#ifndef PHP_DUCKDB_CXX_COMPAT_H
#define PHP_DUCKDB_CXX_COMPAT_H

#include "php_version.h"

#if defined(__cplusplus) && PHP_VERSION_ID < 80400
#include <cmath>
#include <cstdint>
#include <cstdlib>
#include <cstring>
#include <new>
#include <system_error>
#define try try_from
#endif

#include "php.h"

#if defined(__cplusplus) && PHP_VERSION_ID < 80400
/* php_duckdb.h includes zend_enum.h explicitly, after this shim has
   restored the keyword; pull it in here while the rename is still
   active and let its include guard neutralise the later inclusion. */
#include "zend_enum.h"
#undef try
#endif

#endif /* PHP_DUCKDB_CXX_COMPAT_H */
