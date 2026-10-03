#!/usr/bin/env bash
# Publish the existing tag set only after both native images are available.
set -euo pipefail

if (( $# != 1 )); then
  echo "Usage: $0 DIGEST_DIRECTORY" >&2
  exit 2
fi
: "${IMAGE:?Image repository required}"
directory="$1"
image="${IMAGE,,}"

shopt -s nullglob dotglob
files=("$directory"/*)
if (( ${#files[@]} != 2 )); then
  echo "Expected exactly amd64.digest and arm64.digest" >&2
  exit 1
fi

references=()
for architecture in amd64 arm64; do
  file="$directory/$architecture.digest"
  if [[ ! -f "$file" || -L "$file" ]]; then
    echo "Missing regular digest file: $file" >&2
    exit 1
  fi
  digest="$(cat "$file")"
  if [[ ! "$digest" =~ ^sha256:[0-9a-f]{64}$ ]]; then
    echo "Invalid image digest for $architecture" >&2
    exit 1
  fi
  references+=("$image@$digest")
done
if [[ "${references[0]}" == "${references[1]}" ]]; then
  echo "Architecture digests must be distinct" >&2
  exit 1
fi

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
tag_list="$(bash "$script_dir/docker-tags.sh")"
IFS=, read -r -a tags <<< "$tag_list"
arguments=()
for tag in "${tags[@]}"; do
  arguments+=(--tag "$tag")
done

docker buildx imagetools create "${arguments[@]}" "${references[@]}"
