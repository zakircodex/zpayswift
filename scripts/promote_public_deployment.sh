#!/usr/bin/env bash
set -euo pipefail

PACKAGE_ROOT="${1:-}"
PUBLIC_ROOT="${2:-}"

if [[ -z "$PACKAGE_ROOT" || -z "$PUBLIC_ROOT" || "$PUBLIC_ROOT" != /* || "$PUBLIC_ROOT" == "/" ]]; then
  echo "Usage: promote_public_deployment.sh PACKAGE_ROOT ABSOLUTE_PUBLIC_ROOT" >&2
  exit 2
fi
PACKAGE_ROOT="$(cd "$PACKAGE_ROOT" && pwd -P)"
mkdir -p "$PUBLIC_ROOT"
PUBLIC_ROOT="$(cd "$PUBLIC_ROOT" && pwd -P)"
if [[ "$PACKAGE_ROOT" == "$PUBLIC_ROOT" ]]; then
  echo "Deployment package and public root must differ." >&2
  exit 2
fi

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

test -f "$PACKAGE_ROOT/.htaccess"
test -f "$PACKAGE_ROOT/.deploy-manifest"
test -f "$PACKAGE_ROOT/deploy_version.txt"

SCRIPT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PHP_BIN="$(resolve_php)" || {
  echo "No cPanel PHP CLI binary is available for deployment validation." >&2
  exit 127
}
"$PHP_BIN" "$SCRIPT_ROOT/deployment_manifest_diff.php" \
  /dev/null "$PACKAGE_ROOT/.deploy-manifest" >/dev/null
if [[ -f "$PUBLIC_ROOT/.deploy-manifest" ]]; then
  "$PHP_BIN" "$SCRIPT_ROOT/deployment_manifest_diff.php" \
    "$PUBLIC_ROOT/.deploy-manifest" \
    "$PACKAGE_ROOT/.deploy-manifest" > "$PACKAGE_ROOT/.stale-deploy-files"
else
  : > "$PACKAGE_ROOT/.stale-deploy-files"
fi

# Install the lock-aware rewrite policy before entering maintenance mode.
cp -f "$PACKAGE_ROOT/.htaccess" "$PUBLIC_ROOT/.htaccess.next"
mv -f "$PUBLIC_ROOT/.htaccess.next" "$PUBLIC_ROOT/.htaccess"
printf '%s\n' "$(head -n1 "$PACKAGE_ROOT/deploy_version.txt")" > "$PUBLIC_ROOT/.deploy-in-progress"

# A failed promotion deliberately leaves the lock in place. Rerunning a known-good
# release completes promotion without exposing a mixed set of files.
if command -v rsync >/dev/null 2>&1; then
  rsync -a --delay-updates \
    --exclude='deploy_version.txt' \
    --exclude='.deploy-manifest' \
    --exclude='.stale-deploy-files' \
    "$PACKAGE_ROOT/" "$PUBLIC_ROOT/"
else
  echo "rsync is unavailable; promoting files from the validated manifest."
  pending_copy=""
  cleanup_pending_copy() {
    [[ -z "$pending_copy" ]] || rm -f -- "$pending_copy"
  }
  trap cleanup_pending_copy EXIT

  while IFS= read -r relative_path; do
    relative_path="${relative_path%$'\r'}"
    [[ -n "$relative_path" ]] || continue
    case "$relative_path" in
      .htaccess|deploy_version.txt|.deploy-manifest|.stale-deploy-files)
        continue
        ;;
    esac

    source_path="$PACKAGE_ROOT/$relative_path"
    target_path="$PUBLIC_ROOT/$relative_path"
    [[ -f "$source_path" ]] || {
      echo "Deployment package file is missing: $relative_path" >&2
      exit 1
    }
    mkdir -p "$(dirname -- "$target_path")"
    pending_copy="$target_path.deploy-next.$$"
    rm -f -- "$pending_copy"
    cp -a "$source_path" "$pending_copy"
    mv -f "$pending_copy" "$target_path"
    pending_copy=""
  done < "$PACKAGE_ROOT/.deploy-manifest"

  trap - EXIT
fi

while IFS= read -r stale_path; do
  stale_path="${stale_path%$'\r'}"
  [[ -n "$stale_path" ]] || continue
  target="$PUBLIC_ROOT/$stale_path"
  if [[ -f "$target" || -L "$target" ]]; then
    rm -f -- "$target"
  fi
done < "$PACKAGE_ROOT/.stale-deploy-files"

cp -f "$PACKAGE_ROOT/.deploy-manifest" "$PUBLIC_ROOT/.deploy-manifest.next"
mv -f "$PUBLIC_ROOT/.deploy-manifest.next" "$PUBLIC_ROOT/.deploy-manifest"
cp -f "$PACKAGE_ROOT/deploy_version.txt" "$PUBLIC_ROOT/deploy_version.txt.next"
mv -f "$PUBLIC_ROOT/deploy_version.txt.next" "$PUBLIC_ROOT/deploy_version.txt"
rm -f -- "$PUBLIC_ROOT/.deploy-in-progress"
