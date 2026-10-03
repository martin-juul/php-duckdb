#!/usr/bin/env bash
# Print comma-separated image tags for one PHP/runtime matrix entry.
set -euo pipefail

: "${IMAGE:?Image repository required}"
: "${PHP_VERSION:?PHP version required}"
: "${DEFAULT_PHP:?Default PHP version required}"
: "${GITHUB_REF:?Git ref required}"

case "${VARIANT:-}" in
  '') suffix='' ;;
  frankenphp) suffix='-frankenphp' ;;
  *) echo "Unsupported image variant: $VARIANT" >&2; exit 1 ;;
esac
if [[ ! "$PHP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
  echo "Invalid PHP version: $PHP_VERSION" >&2
  exit 1
fi
image="${IMAGE,,}"
tags=()
add_tag() { tags+=("${image}:$1${suffix}"); }

release=''
prerelease=''
if [[ "$GITHUB_REF" == refs/tags/* ]]; then
  release="${GITHUB_REF#refs/tags/}"
  release="${release#v}"
  if [[ ! "$release" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-([0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*))?$ ]]; then
    echo "Expected a release tag such as v1.3.1 or v1.4.0-rc.1: $GITHUB_REF" >&2
    exit 1
  fi
  major="${BASH_REMATCH[1]}"
  minor="${BASH_REMATCH[2]}"
  prerelease="${BASH_REMATCH[4]}"
fi

# PR builds compute the same moving tags but the workflow never pushes them.
# A prerelease must not replace stable release or development aliases.
if [[ -z "$prerelease" ]]; then
  add_tag "$PHP_VERSION"
  add_tag "php$PHP_VERSION"
  if [[ "$PHP_VERSION" == "$DEFAULT_PHP" ]]; then
    add_tag latest
  fi
fi

if [[ -n "$release" ]]; then
  versions=("$release")
  if [[ -z "$prerelease" ]]; then
    versions+=("$major.$minor" "$major")
  fi
  for version in "${versions[@]}"; do
    add_tag "$version-php$PHP_VERSION"
    # Only one matrix entry owns release-only aliases.
    if [[ "$PHP_VERSION" == "$DEFAULT_PHP" ]]; then
      add_tag "$version"
    fi
  done
fi

for tag in "${tags[@]}"; do
  if (( ${#tag} - ${#image} - 1 > 128 )); then
    echo "Docker tag exceeds 128 characters: $tag" >&2
    exit 1
  fi
done
(IFS=,; printf '%s\n' "${tags[*]}")
