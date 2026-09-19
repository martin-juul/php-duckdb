# syntax=docker/dockerfile:1

# Multi-stage build for the duckdb PHP extension.
# Produces a php:cli image with ext-duckdb and libduckdb preinstalled.
#
#   docker build --build-arg PHP_VERSION=8.4 -t php-duckdb:8.4 .
#
# Platforms: linux/amd64 and linux/arm64 — the architectures DuckDB ships
# prebuilt libduckdb archives for.

ARG PHP_VERSION=8.4

# ==================================================================== #
# Build stage: compile the extension against the matching libduckdb.   #
# ==================================================================== #
FROM php:${PHP_VERSION}-cli-bookworm AS build

ARG DUCKDB_VERSION=v1.5.5
ARG TARGETPLATFORM

RUN apt-get update \
 && apt-get install -y --no-install-recommends $PHPIZE_DEPS unzip curl ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# libduckdb ships per-arch prebuilt archives; select by target platform.
RUN set -eux; \
    case "$TARGETPLATFORM" in \
        linux/amd64) duckdb_arch=amd64 ;; \
        linux/arm64) duckdb_arch=arm64 ;; \
        *) echo "unsupported platform: $TARGETPLATFORM" >&2; exit 1 ;; \
    esac; \
    curl -fsSL "https://github.com/duckdb/duckdb/releases/download/${DUCKDB_VERSION}/libduckdb-linux-${duckdb_arch}.zip" -o /tmp/libduckdb.zip; \
    mkdir -p /opt/duckdb/include /opt/duckdb/lib; \
    unzip -o /tmp/libduckdb.zip -d /opt/duckdb; \
    mv /opt/duckdb/duckdb.h /opt/duckdb/include/; \
    mv /opt/duckdb/libduckdb.so /opt/duckdb/lib/

WORKDIR /src
COPY config.m4 duckdb.cpp php_duckdb.h php_duckdb_cxx_compat.h duckdb_arginfo.h ./
COPY src/ ./src/

RUN phpize \
 && ./configure --with-duckdb=/opt/duckdb \
 && make -j"$(nproc)"

# Smoke test at build time: proves the module loads and queries on THIS
# architecture — under QEMU for cross builds, so a broken arm64 build
# fails the pipeline instead of shipping a broken manifest.
RUN LD_LIBRARY_PATH=/opt/duckdb/lib php -d extension=/src/modules/duckdb.so -r \
    '$c = (new DuckDB\Database())->connect(); $row = $c->query("SELECT 42 AS x")->fetchRow(); if ($row["x"] !== 42) { fwrite(STDERR, "smoke query failed\n"); exit(1); } printf("smoke OK: duckdb %s on PHP %s\n", DuckDB\version(), PHP_VERSION);'

RUN mkdir -p /dist \
 && cp modules/duckdb.so /dist/duckdb.so \
 && cp /opt/duckdb/lib/libduckdb.so /dist/libduckdb.so

# ==================================================================== #
# Final stage: the extension + libduckdb on a clean PHP CLI image.     #
# ==================================================================== #
FROM php:${PHP_VERSION}-cli-bookworm

COPY --from=build /dist/libduckdb.so /usr/local/lib/libduckdb.so
COPY --from=build /dist/duckdb.so /tmp/duckdb.so

RUN set -eux; \
    mv /tmp/duckdb.so "$(php-config --extension-dir)/duckdb.so"; \
    ldconfig; \
    docker-php-ext-enable duckdb

# Re-run the smoke test against the final image exactly as shipped
# (extension enabled via ini, libduckdb resolved through ldconfig).
RUN php -r 'printf("php-duckdb %s ready (PHP %s)\n", DuckDB\version(), PHP_VERSION);' \
 && php -r '$c = (new DuckDB\Database())->connect(); if ($c->query("SELECT 42 AS x")->fetchRow()["x"] !== 42) { exit(1); }'
