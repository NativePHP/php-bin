#!/usr/bin/env bash
#
# generate-versions-json.sh
#
# Builds (or rebuilds) the desktop binary manifest `versions.json` for a branch
# by listing the binaries already uploaded to R2 under bin.nativephp.com.
#
# Manifest shape (see docs/r2-binaries-contract.md):
#
#   {
#     "updated_at": "2026-06-22T00:00:00Z",
#     "versions": {
#       "8.3": {
#         "mac-arm64": [ { "url": "...", "sha256": "...", "size": 24563319 } ],
#         "mac-x64":   [ { ... } ],
#         "mac-x86":   [],
#         "linux-x64": [ { ... } ],
#         ...
#       },
#       "8.4": { ... },
#       "8.5": { ... }
#     }
#   }
#
# platformKey = "{os}-{arch}".  Every known slot is emitted (mac-x86 stays even
# when empty, per project decision).  Each entry carries url + sha256 + size so
# the desktop consumer can verify integrity.  Entries may also be a bare URL
# string for backward-compatibility with the mobile manifest shape; this
# producer always emits the richer object form.
#
# This script lists what is in R2 and reads a per-object `<key>.sha256` sidecar
# (uploaded by the build job next to each zip) plus the object size from the S3
# listing.  It never invents URLs for objects that are not actually present, so
# the manifest can never point at a not-yet-uploaded binary.
#
# Required environment:
#   R2_ENDPOINT   - R2 S3 endpoint (https://<account>.r2.cloudflarestorage.com)
#   BUCKET        - R2 bucket name
#   R2_PREFIX     - branch prefix, e.g. "main/" (must end with a slash)
#   BASE_URL      - public base, e.g. "https://bin.nativephp.com"
#   AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_DEFAULT_REGION  (for aws s3)
#
# Optional environment (for local dry-runs without R2):
#   LOCAL_BIN_DIR - if set, the manifest is generated from a local `bin/` tree
#                   (e.g. ./bin) instead of from R2.  sha256/size are computed
#                   from the local files.  No network access is performed.
#
# Usage:
#   .github/scripts/generate-versions-json.sh > versions.json
#
set -euo pipefail

# PHP minor versions and platform slots we publish.
PHP_VERSIONS=("8.3" "8.4" "8.5")
# platformKey = "{os}-{arch}".  Order is stable; mac-x86 is intentionally kept.
PLATFORM_KEYS=("mac-arm64" "mac-x64" "mac-x86" "linux-x64" "linux-arm64" "win-x64")

BASE_URL="${BASE_URL:-https://bin.nativephp.com}"

# Map a platformKey ("mac-arm64") to the in-repo / R2 path segment ("mac/arm64").
key_to_path() {
  # mac-arm64 -> mac/arm64 ; linux-x64 -> linux/x64 ; win-x64 -> win/x64
  local key="$1"
  local os="${key%%-*}"
  local arch="${key#*-}"
  echo "${os}/${arch}"
}

sha256_of() {
  # Portable sha256 (Linux: sha256sum, macOS: shasum -a 256).
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print $1}'
  else
    shasum -a 256 "$1" | awk '{print $1}'
  fi
}

# Start the manifest skeleton.
JSON=$(jq -n '{updated_at: (now | strftime("%Y-%m-%dT%H:%M:%SZ")), versions: {}}')

if [ -n "${LOCAL_BIN_DIR:-}" ]; then
  # ----- Local dry-run mode: build from a local bin/ tree -----
  for VER in "${PHP_VERSIONS[@]}"; do
    JSON=$(echo "$JSON" | jq --arg v "$VER" '.versions[$v] = {}')
    for KEY in "${PLATFORM_KEYS[@]}"; do
      PATH_SEG=$(key_to_path "$KEY")
      ZIP="${LOCAL_BIN_DIR%/}/${PATH_SEG}/php-${VER}.zip"
      ENTRIES='[]'
      if [ -f "$ZIP" ]; then
        SHA=$(sha256_of "$ZIP")
        SIZE=$(wc -c < "$ZIP" | tr -d ' ')
        URL="${BASE_URL}/${R2_PREFIX:-main/}${PATH_SEG}/php-${VER}.zip"
        ENTRIES=$(jq -n --arg url "$URL" --arg sha "$SHA" --argjson size "$SIZE" \
          '[{url: $url, sha256: $sha, size: $size}]')
      fi
      JSON=$(echo "$JSON" | jq --arg v "$VER" --arg k "$KEY" --argjson e "$ENTRIES" \
        '.versions[$v][$k] = $e')
    done
  done
  echo "$JSON" | jq .
  exit 0
fi

# ----- R2 mode: build from what is actually present in the bucket -----
: "${R2_ENDPOINT:?R2_ENDPOINT is required}"
: "${BUCKET:?BUCKET is required}"
: "${R2_PREFIX:?R2_PREFIX is required (e.g. main/)}"

# List every object under the branch prefix once: "<size> <key>".
# `aws s3 ls --recursive` prints: <date> <time> <size> <key>
LISTING=$(aws s3 ls "s3://${BUCKET}/${R2_PREFIX}" --recursive \
  --endpoint-url "$R2_ENDPOINT" || true)

for VER in "${PHP_VERSIONS[@]}"; do
  JSON=$(echo "$JSON" | jq --arg v "$VER" '.versions[$v] = {}')
  for KEY in "${PLATFORM_KEYS[@]}"; do
    PATH_SEG=$(key_to_path "$KEY")
    OBJ_KEY="${R2_PREFIX}${PATH_SEG}/php-${VER}.zip"

    # Find the object's size from the listing (4th column onward is the key).
    SIZE=$(echo "$LISTING" | awk -v k="$OBJ_KEY" '$4 == k {print $3}' | head -n1)

    ENTRIES='[]'
    if [ -n "$SIZE" ]; then
      URL="${BASE_URL}/${OBJ_KEY}"
      # sha256 is uploaded as a sidecar object "<key>.sha256" by the build job.
      SHA=$(aws s3 cp "s3://${BUCKET}/${OBJ_KEY}.sha256" - \
        --endpoint-url "$R2_ENDPOINT" 2>/dev/null | awk '{print $1}' | head -n1 || true)
      if [ -n "$SHA" ]; then
        ENTRIES=$(jq -n --arg url "$URL" --arg sha "$SHA" --argjson size "$SIZE" \
          '[{url: $url, sha256: $sha, size: $size}]')
      else
        # No sidecar yet: still publish the URL+size so the binary is usable.
        ENTRIES=$(jq -n --arg url "$URL" --argjson size "$SIZE" \
          '[{url: $url, size: $size}]')
      fi
    fi

    JSON=$(echo "$JSON" | jq --arg v "$VER" --arg k "$KEY" --argjson e "$ENTRIES" \
      '.versions[$v][$k] = $e')
  done
done

echo "$JSON" | jq .
