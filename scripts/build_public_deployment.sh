#!/usr/bin/env bash
set -euo pipefail

SOURCE_ROOT="${1:-}"
TARGET_ROOT="${2:-}"
RELEASE_SHA="${3:-}"

if [[ -z "$SOURCE_ROOT" || -z "$TARGET_ROOT" || -z "$RELEASE_SHA" ]]; then
  echo "Usage: build_public_deployment.sh SOURCE_ROOT TARGET_ROOT RELEASE_SHA" >&2
  exit 2
fi
if [[ ! "$RELEASE_SHA" =~ ^[a-fA-F0-9]{40}$ ]]; then
  echo "Release SHA must be a full Git commit hash." >&2
  exit 2
fi

SOURCE_ROOT="$(cd "$SOURCE_ROOT" && pwd -P)"
if [[ "$TARGET_ROOT" != /* || "$TARGET_ROOT" == "/" || "$TARGET_ROOT" == "$SOURCE_ROOT" ]]; then
  echo "Deployment package target is unsafe." >&2
  exit 2
fi

TARGET_PARENT="$(dirname -- "$TARGET_ROOT")"
mkdir -p "$TARGET_PARENT"
TARGET_PARENT="$(cd "$TARGET_PARENT" && pwd -P)"
TARGET_ROOT="$TARGET_PARENT/$(basename -- "$TARGET_ROOT")"
case "$TARGET_ROOT" in
  "$SOURCE_ROOT/deployment"|*/.zpayswift-deploy/"$RELEASE_SHA") ;;
  *)
    echo "Deployment package target is outside an approved staging directory." >&2
    exit 2
    ;;
esac

copy_tree() {
  local source_root="$1"
  local target_root="$2"
  mkdir -p "$target_root"
  if command -v rsync >/dev/null 2>&1; then
    rsync -a --exclude-from="$SOURCE_ROOT/.cpanel-deploy-exclude" \
      "$source_root/" "$target_root/"
  else
    echo "rsync is unavailable; using the cPanel-compatible copy fallback."
    cp -a "$source_root/." "$target_root/"
  fi
}

prune_api_tree() {
  local api_root="$1"
  [[ -d "$api_root" ]] || return 0

  find "$api_root" -type d \( \
    -name '.git' -o -name '.github' -o -name 'private' -o \
    -name 'logs' -o -name 'node_modules' -o -name 'vendor' -o \
    -name 'docs' -o -name 'downloads' -o -name 'cgi-bin' -o \
    -name 'zawtopup' -o -name 'zpayswift' -o -name '.venv' -o \
    -name '__pycache__' \
  \) -prune -exec rm -rf -- {} +

  find "$api_root" -type f \( \
    -name '.env' -o -name 'error_log' -o -name 'config.example.php' -o \
    -name 'SECURITY_AUDIT.md' -o -name '*.log' -o -name '*.sql' -o \
    -name '*.bak' -o -name '*.old' -o -name '*.zip' -o \
    -name '*.tar' -o -name '*.gz' -o -name '*.pyc' -o -name '*.pyo' \
  \) -delete

  find "$api_root" -type f -name 'config.php' \
    ! -path "$api_root/support/config.php" \
    ! -path "$api_root/admin/support/config.php" \
    -delete
}

resolve_php() {
  local candidate
  for candidate in "$(command -v php 2>/dev/null || true)" \
    /usr/local/bin/php /usr/bin/php \
    /opt/cpanel/ea-php*/root/usr/bin/php \
    /opt/cloudlinux/alt-php*/root/usr/bin/php \
    /usr/local/cpanel/3rdparty/bin/php; do
    if [[ -n "$candidate" && -x "$candidate" ]]; then
      printf '%s\n' "$candidate"
      return 0
    fi
  done
  return 1
}

rm -rf -- "$TARGET_ROOT"
mkdir -p "$TARGET_ROOT/api"
cd "$SOURCE_ROOT"
test -f "$SOURCE_ROOT/.well-known/assetlinks.json"

for path in .htaccess .well-known index.html download.php track.html privacy.html terms.html apply-subadmin.html assets docs images logo znews; do
  if [[ -e "$path" ]]; then
    cp -a "$path" "$TARGET_ROOT/"
  fi
done
test -f "$TARGET_ROOT/.well-known/assetlinks.json"

copy_tree "$SOURCE_ROOT/api" "$TARGET_ROOT/api"
if [[ -d document-ai ]]; then
  copy_tree "$SOURCE_ROOT/document-ai" "$TARGET_ROOT/api/document-ai"
fi
prune_api_tree "$TARGET_ROOT/api"

printf '%s\n%s\n' "$RELEASE_SHA" "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" > "$TARGET_ROOT/deploy_version.txt"

if find "$TARGET_ROOT" -type f \
  ! -path "$TARGET_ROOT/api/support/config.php" \
  ! -path "$TARGET_ROOT/api/admin/support/config.php" \( \
  -name '.env' -o -name 'config.php' -o -name 'error_log' -o \
  -name '*.log' -o -name '*.sql' -o -name '*.bak' -o \
  -name '*.zip' -o -name '*.tar' -o -name '*.gz' \
\) -print -quit | grep -q .; then
  echo "Deployment package contains a forbidden private file." >&2
  exit 1
fi

test ! -e "$TARGET_ROOT/private"
test ! -e "$TARGET_ROOT/.git"
test ! -e "$TARGET_ROOT/.github"
if find "$TARGET_ROOT" -type l -print -quit | grep -q .; then
  echo "Deployment package must not contain symbolic links." >&2
  exit 1
fi

(
  cd "$TARGET_ROOT"
  find . -type f ! -name '.deploy-manifest' -printf '%P\n' | LC_ALL=C sort
) > "$TARGET_ROOT/.deploy-manifest"

PHP_BIN="$(resolve_php)" || {
  echo "No cPanel PHP CLI binary is available for deployment validation." >&2
  exit 127
}
"$PHP_BIN" "$SOURCE_ROOT/scripts/deployment_manifest_diff.php" \
  /dev/null "$TARGET_ROOT/.deploy-manifest" >/dev/null
