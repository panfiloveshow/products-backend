#!/usr/bin/env bash
set -euo pipefail

SOURCE_DIR="$(cd "${1:?Source directory required}" && pwd)"
STAGING_DIR="$(cd "${2:?Existing staging directory required}" && pwd)"
case "$STAGING_DIR/" in
  "$SOURCE_DIR/"*) echo 'Staging must be outside the source directory' >&2; exit 1 ;;
esac

export COPYFILE_DISABLE=1
rsync -az --exclude-from="$SOURCE_DIR/scripts/deploy-excludes.txt" "$SOURCE_DIR/" "$STAGING_DIR/"

# External disks can report every source file as 0700. Normalize the artifact
# before uploading; --no-perms alone still creates unreadable new files.
find "$STAGING_DIR" -type d -exec chmod 0755 {} +
find "$STAGING_DIR" -type f -exec chmod 0644 {} +
find "$STAGING_DIR" -type f -name '*.sh' -exec chmod 0755 {} +
if [[ -f "$STAGING_DIR/artisan" ]]; then chmod 0755 "$STAGING_DIR/artisan"; fi
