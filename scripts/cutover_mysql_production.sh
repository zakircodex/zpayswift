#!/usr/bin/env bash
set -euo pipefail

REPOSITORY_ROOT="/home/zedpayhe/repositories/zpayswift"
PRIVATE_ROOT="/home/zedpayhe/private/zpayswift"
PUBLIC_ROOT="/home/zedpayhe/public_html"
MIGRATION_CONFIG="${1:-$PRIVATE_ROOT/migration.mysql-production.php}"
RUNTIME_CANDIDATE="${2:-$PRIVATE_ROOT/config.mysql-candidate.php}"
CURRENT_CONFIG="$PRIVATE_ROOT/config.php"
MAINTENANCE_MARKER="$PUBLIC_ROOT/.deploy-in-progress"

fail() {
    printf 'Production MySQL cutover stopped: %s\n' "$1" >&2
    exit 1
}

resolve_php() {
    local candidate
    for candidate in \
        /usr/local/cpanel/3rdparty/bin/php \
        /opt/cpanel/ea-php*/root/usr/bin/php \
        /usr/local/bin/php \
        /usr/bin/php
    do
        if [[ -x "$candidate" ]]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done
    return 1
}

[[ "${ZPAY_CONFIRM_PRODUCTION_CUTOVER:-}" == "ACTIVATE_PRODUCTION_MYSQL" ]] \
    || fail 'set ZPAY_CONFIRM_PRODUCTION_CUTOVER=ACTIVATE_PRODUCTION_MYSQL for this one run'
[[ -d "$REPOSITORY_ROOT/.git" ]] || fail 'production repository is unavailable'
[[ "$(/usr/bin/git -C "$REPOSITORY_ROOT" rev-parse --show-toplevel)" == "$REPOSITORY_ROOT" ]] \
    || fail 'repository root mismatch'
[[ "$(/usr/bin/git -C "$REPOSITORY_ROOT" branch --show-current)" == "main" ]] \
    || fail 'production repository must be on main'
[[ "$(/usr/bin/git -C "$REPOSITORY_ROOT" rev-parse HEAD)" == "$(/usr/bin/git -C "$REPOSITORY_ROOT" rev-parse origin/main)" ]] \
    || fail 'production repository must exactly match origin/main'
[[ -z "$(/usr/bin/git -C "$REPOSITORY_ROOT" status --porcelain --untracked-files=no)" ]] \
    || fail 'production repository has tracked changes'
[[ -f "$MIGRATION_CONFIG" && ! -L "$MIGRATION_CONFIG" ]] || fail 'migration config is unavailable'
[[ -f "$RUNTIME_CANDIDATE" && ! -L "$RUNTIME_CANDIDATE" ]] || fail 'runtime candidate is unavailable'
[[ -f "$CURRENT_CONFIG" && ! -L "$CURRENT_CONFIG" ]] || fail 'current production config is unavailable'
[[ ! -e "$MAINTENANCE_MARKER" ]] || fail 'a maintenance or deployment lock already exists'

PHP_BINARY="$(resolve_php)" || fail 'hosting PHP CLI is unavailable'

"$PHP_BINARY" "$REPOSITORY_ROOT/scripts/verify_mysql_production_runtime.php" "$RUNTIME_CANDIDATE"

(
    set -o noclobber
    printf 'Production datastore cutover in progress.\n' > "$MAINTENANCE_MARKER"
) || fail 'unable to create the maintenance marker'
chmod 0644 "$MAINTENANCE_MARKER"

cutover_complete=0
leave_marker_on_failure() {
    if [[ "$cutover_complete" -ne 1 ]]; then
        printf 'Cutover did not complete; maintenance marker remains at %s\n' "$MAINTENANCE_MARKER" >&2
    fi
}
trap leave_marker_on_failure EXIT

"$PHP_BINARY" "$REPOSITORY_ROOT/api/tools/migrate_firebase_to_mysql.php" \
    --config="$MIGRATION_CONFIG" \
    --execute \
    --mode=final-delta \
    --confirm-production \
    --confirm-final-delta

"$PHP_BINARY" "$REPOSITORY_ROOT/api/tools/verify_firebase_mysql_queries.php" \
    --config="$MIGRATION_CONFIG"

"$PHP_BINARY" "$REPOSITORY_ROOT/api/tools/activate_production_mysql_config.php" \
    --current="$CURRENT_CONFIG" \
    --candidate="$RUNTIME_CANDIDATE" \
    --maintenance-marker="$MAINTENANCE_MARKER" \
    --confirm-production-cutover

"$PHP_BINARY" "$REPOSITORY_ROOT/scripts/verify_mysql_production_runtime.php" "$CURRENT_CONFIG"

rm -f -- "$MAINTENANCE_MARKER"
cutover_complete=1
trap - EXIT
printf 'Production MySQL cutover completed successfully.\n'
