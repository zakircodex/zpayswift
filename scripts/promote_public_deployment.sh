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

test -f "$PACKAGE_ROOT/.htaccess"
test -f "$PACKAGE_ROOT/.deploy-manifest"
test -f "$PACKAGE_ROOT/deploy_version.txt"

SCRIPT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
php "$SCRIPT_ROOT/deployment_manifest_diff.php" /dev/null "$PACKAGE_ROOT/.deploy-manifest" >/dev/null
if [[ -f "$PUBLIC_ROOT/.deploy-manifest" ]]; then
  php "$SCRIPT_ROOT/deployment_manifest_diff.php" \
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
rsync -a --delay-updates \
  --exclude='deploy_version.txt' \
  --exclude='.deploy-manifest' \
  --exclude='.stale-deploy-files' \
  "$PACKAGE_ROOT/" "$PUBLIC_ROOT/"

while IFS= read -r stale_path; do
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
