#!/usr/bin/env bash
set -euo pipefail

REPOSITORY_ROOT="/home/zedpayhe/repositories/zpayswift-stage"
PUBLIC_ROOT="/home/zedpayhe/stage.zpayswift.com"
PRIVATE_ROOT="/home/zedpayhe/private/zpayswift-stage"
RUNTIME_CONFIG="$PRIVATE_ROOT/config.php"
AUTH_FILE="/home/zedpayhe/.htpasswds/stage.zpayswift.com/passwd"
EXPECTED_BRANCH="cpanel/mysql-stage"
EXPECTED_UPSTREAM="origin/codex/mysql-stage"

fail() {
  printf 'Stage deployment refused: %s\n' "$1" >&2
  exit 1
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

[[ -e "$REPOSITORY_ROOT/.git" ]] || fail 'the isolated repository is unavailable'
[[ "$(/usr/bin/git -C "$REPOSITORY_ROOT" rev-parse --is-inside-work-tree)" == "true" ]] \
  || fail 'the isolated repository is not a Git worktree'
[[ "$(/usr/bin/git -C "$REPOSITORY_ROOT" rev-parse --show-toplevel)" == "$REPOSITORY_ROOT" ]] \
  || fail 'the Git worktree root does not match the isolated repository'
[[ "$(cd "$REPOSITORY_ROOT" && pwd -P)" == "$REPOSITORY_ROOT" ]] \
  || fail 'the repository path did not resolve to the isolated checkout'
[[ "$(/usr/bin/git -C "$REPOSITORY_ROOT" branch --show-current)" == "$EXPECTED_BRANCH" ]] \
  || fail 'the isolated MySQL branch is not checked out'
[[ "$(/usr/bin/git -C "$REPOSITORY_ROOT" rev-parse --abbrev-ref '@{upstream}')" == "$EXPECTED_UPSTREAM" ]] \
  || fail 'the isolated MySQL branch does not track the approved remote branch'
[[ "$PUBLIC_ROOT" == "/home/zedpayhe/stage.zpayswift.com" ]] \
  || fail 'the public target is not the stage document root'
[[ "$PUBLIC_ROOT" != "/home/zedpayhe/public_html" ]] \
  || fail 'production must never be a stage deployment target'
[[ -f "$RUNTIME_CONFIG" && ! -L "$RUNTIME_CONFIG" ]] \
  || fail 'the private stage runtime configuration is unavailable'
[[ -s "$AUTH_FILE" && ! -L "$AUTH_FILE" ]] \
  || fail 'the stage Basic Auth password file is unavailable'

PHP_BIN="$(resolve_php)" || fail 'no PHP CLI binary is available'
"$PHP_BIN" -r '
  require $argv[1];
  require $argv[2];
  $valid = defined("APP_ENVIRONMENT")
    && constant("APP_ENVIRONMENT") === "stage"
    && defined("APP_PUBLIC_ORIGIN")
    && constant("APP_PUBLIC_ORIGIN") === "https://stage.zpayswift.com"
    && defined("DATASTORE_DRIVER")
    && constant("DATASTORE_DRIVER") === "mysql"
    && defined("FIREBASE_DB_URL")
    && constant("FIREBASE_DB_URL") === "https://invalid.local";
  if (!$valid) {
    fwrite(STDERR, "Stage runtime guard mismatch.\n");
    exit(1);
  }
  $outbound = [
    "BULKSMSBD_API_KEY", "BULKSMSBD_SENDER_ID", "SMSS360_EMAIL", "SMSS360_API_KEY",
    "TELEGRAM_BOT_TOKEN", "TELEGRAM_CHAT_ID", "TELEGRAM_WEBHOOK_SECRET",
    "TELEGRAM_BUNDLE_ACTION_KEY", "TELEGRAM_MFS_ACTION_KEY", "TELEGRAM_TOPUP_ACTION_KEY",
    "TELEGRAM_ACCOUNT_REVIEW_ACTION_KEY", "TELEGRAM_ADD_MONEY_ACTION_KEY",
    "TELEGRAM_SUPPORT_ADMIN_IDS", "TELEGRAM_BUNDLE_CHAT_ID", "ZAW_TELEGRAM_BOT_TOKEN",
    "ZAW_TELEGRAM_CHAT_ID", "NOTIFICATION_BOT_TOKEN",
    "NOTIFICATION_TELEGRAM_WEBHOOK_SECRET", "NOTIFICATION_TELEGRAM_ADMIN_IDS"
  ];
  foreach ($outbound as $name) {
    if (defined($name) && trim((string) constant($name)) !== "") {
      fwrite(STDERR, "Live outbound integration is enabled in stage.\n");
      exit(1);
    }
  }
  zpay_mysql_assert_environment("STAGE");
' "$RUNTIME_CONFIG" "$REPOSITORY_ROOT/api/lib/mysql.php" \
  || fail 'the stage runtime or MySQL environment guard failed'

SHA="$(/usr/bin/git -C "$REPOSITORY_ROOT" rev-parse HEAD)"
[[ "$SHA" =~ ^[a-f0-9]{40}$ ]] || fail 'the release commit is invalid'
PACKAGE_ROOT="$REPOSITORY_ROOT/deployment"

cleanup() {
  rm -rf -- "$PACKAGE_ROOT"
}
trap cleanup EXIT

umask 022
"$REPOSITORY_ROOT/scripts/build_public_deployment.sh" \
  "$REPOSITORY_ROOT" "$PACKAGE_ROOT" "$SHA"

# Authentication is injected into the package before promotion, so even the
# atomic .htaccess swap cannot briefly expose staging without a password.
HTACCESS_BODY="$PACKAGE_ROOT/.htaccess.stage-body"
HTACCESS_NEXT="$PACKAGE_ROOT/.htaccess.stage-next"
awk '
  $0 == "# BEGIN ZPAY STAGE AUTH" { skip = 1; next }
  $0 == "# END ZPAY STAGE AUTH" { skip = 0; next }
  !skip { print }
  END { if (skip) exit 1 }
' "$PACKAGE_ROOT/.htaccess" > "$HTACCESS_BODY" \
  || fail 'the stage authentication block is malformed'
{
  cat <<'AUTH'
# BEGIN ZPAY STAGE AUTH
AuthType Basic
AuthName "Z-Pay Swift Stage"
AuthUserFile /home/zedpayhe/.htpasswds/stage.zpayswift.com/passwd
Require valid-user
# END ZPAY STAGE AUTH
AUTH
  cat "$HTACCESS_BODY"
} > "$HTACCESS_NEXT"
mv -f -- "$HTACCESS_NEXT" "$PACKAGE_ROOT/.htaccess"
rm -f -- "$HTACCESS_BODY"

find "$PACKAGE_ROOT" -type d -exec chmod 755 {} +
find "$PACKAGE_ROOT" -type f -exec chmod 644 {} +

"$REPOSITORY_ROOT/scripts/promote_public_deployment.sh" \
  "$PACKAGE_ROOT" "$PUBLIC_ROOT"

# Only deployment-owned paths are made web-readable. Server-only uploads and
# private files are deliberately not traversed or changed.
chmod 755 "$PUBLIC_ROOT"
while IFS= read -r relative_path; do
  relative_path="${relative_path%$'\r'}"
  [[ -n "$relative_path" ]] || continue
  [[ -f "$PUBLIC_ROOT/$relative_path" ]] \
    || fail "a deployed file is missing: $relative_path"
  chmod 644 "$PUBLIC_ROOT/$relative_path"
done < "$PUBLIC_ROOT/.deploy-manifest"
find "$PACKAGE_ROOT" -type d -printf '%P\0' | while IFS= read -r -d '' relative_dir; do
  [[ -n "$relative_dir" ]] || continue
  [[ -d "$PUBLIC_ROOT/$relative_dir" ]] && chmod 755 "$PUBLIC_ROOT/$relative_dir"
done
chmod 644 "$PUBLIC_ROOT/.htaccess" "$PUBLIC_ROOT/.deploy-manifest" "$PUBLIC_ROOT/deploy_version.txt"
[[ ! -e "$PUBLIC_ROOT/.stage-not-ready" ]] || chmod 600 "$PUBLIC_ROOT/.stage-not-ready"
chmod 755 "$(dirname -- "$AUTH_FILE")"
chmod 644 "$AUTH_FILE"

[[ "$(grep -c '^# BEGIN ZPAY STAGE AUTH$' "$PUBLIC_ROOT/.htaccess")" == "1" ]] \
  || fail 'the deployed authentication policy is missing or duplicated'
[[ "$(head -n 1 "$PUBLIC_ROOT/deploy_version.txt")" == "$SHA" ]] \
  || fail 'the deployed commit marker does not match the checkout'

printf 'Stage deployment completed at %s.\n' "$SHA"
