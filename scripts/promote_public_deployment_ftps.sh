#!/usr/bin/env bash
set -euo pipefail

PACKAGE_ROOT="${1:-deployment}"
: "${FTP_HOST:?FTP_HOST is required}"
: "${FTP_USER:?FTP_USER is required}"
: "${FTP_PASSWORD:?FTP_PASSWORD is required}"
: "${FTP_REMOTE_PATH:?FTP_REMOTE_PATH is required}"

test -d "$PACKAGE_ROOT"
test -f "$PACKAGE_ROOT/.htaccess"
test -f "$PACKAGE_ROOT/.deploy-manifest"
test -f "$PACKAGE_ROOT/deploy_version.txt"
PACKAGE_ROOT="$(cd "$PACKAGE_ROOT" && pwd -P)"

FTP_PORT="${FTP_PORT:-21}"
if [[ ! "$FTP_HOST" =~ ^[A-Za-z0-9.-]+$ ]]; then
  echo "FTP_HOST contains unsupported characters." >&2
  exit 2
fi
if [[ ! "$FTP_PORT" =~ ^[0-9]{1,5}$ ]]; then
  echo "FTP_PORT must be between 1 and 65535." >&2
  exit 2
fi
FTP_PORT_NUMBER=$((10#$FTP_PORT))
if (( FTP_PORT_NUMBER < 1 || FTP_PORT_NUMBER > 65535 )); then
  echo "FTP_PORT must be between 1 and 65535." >&2
  exit 2
fi
FTP_PORT="$FTP_PORT_NUMBER"
if [[ "$FTP_REMOTE_PATH" != "/" && ! "$FTP_REMOTE_PATH" =~ ^/[A-Za-z0-9_./-]+$ ]]; then
  echo "FTP_REMOTE_PATH must be / or an absolute FTP path." >&2
  exit 2
fi
if [[ "$FTP_REMOTE_PATH" == *".."* || ! "$PACKAGE_ROOT" =~ ^[A-Za-z0-9_./@+-]+$ ]]; then
  echo "Deployment source or remote path is unsafe." >&2
  exit 2
fi

SCRIPT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
php "$SCRIPT_ROOT/deployment_manifest_diff.php" /dev/null "$PACKAGE_ROOT/.deploy-manifest" >/dev/null

FTP_USER_URI="$(jq -rn --arg value "$FTP_USER" '$value|@uri')"
FTP_URL="ftp://${FTP_USER_URI}@${FTP_HOST}:${FTP_PORT}"
export LFTP_PASSWORD="$FTP_PASSWORD"
REMOTE_ROOT="${FTP_REMOTE_PATH%/}"
[[ -n "$REMOTE_ROOT" ]] || REMOTE_ROOT=""
WORK_ROOT="$(mktemp -d)"
trap 'rm -rf -- "$WORK_ROOT"' EXIT
PREVIOUS_MANIFEST="$WORK_ROOT/previous-deploy-manifest.txt"
REMOTE_LISTING="$WORK_ROOT/remote-listing.txt"
STALE_FILES="$WORK_ROOT/stale-deploy-files.txt"
LOCK_FILE="$WORK_ROOT/deploy-in-progress.txt"
PROMOTE_SCRIPT="$WORK_ROOT/promote-release.lftp"

lftp --env-password "$FTP_URL" -e "
  set cmd:fail-exit true;
  set net:max-retries 2;
  set net:timeout 20;
  set ftp:ssl-force true;
  set ftp:ssl-protect-data true;
  set ssl:verify-certificate true;
  cls -1a ${REMOTE_ROOT}/;
  bye
" > "$REMOTE_LISTING"

if grep -Eq '(^|/)\.deploy-manifest$' "$REMOTE_LISTING"; then
  lftp --env-password "$FTP_URL" -e "
    set cmd:fail-exit true;
    set net:max-retries 2;
    set net:timeout 20;
    set ftp:ssl-force true;
    set ftp:ssl-protect-data true;
    set ssl:verify-certificate true;
    get ${REMOTE_ROOT}/.deploy-manifest -o ${PREVIOUS_MANIFEST};
    bye
  " >/dev/null
else
  : > "$PREVIOUS_MANIFEST"
fi

php "$SCRIPT_ROOT/deployment_manifest_diff.php" \
  "$PREVIOUS_MANIFEST" \
  "$PACKAGE_ROOT/.deploy-manifest" > "$STALE_FILES"

printf '%s\n' "$(head -n1 "$PACKAGE_ROOT/deploy_version.txt")" > "$LOCK_FILE"
lftp --env-password "$FTP_URL" -e "
  set cmd:fail-exit true;
  set net:max-retries 2;
  set net:timeout 20;
  set ftp:ssl-force true;
  set ftp:ssl-protect-data true;
  set ssl:verify-certificate true;
  put ${PACKAGE_ROOT}/.htaccess -o ${REMOTE_ROOT}/.htaccess.next;
  mv ${REMOTE_ROOT}/.htaccess.next ${REMOTE_ROOT}/.htaccess;
  put ${LOCK_FILE} -o ${REMOTE_ROOT}/.deploy-in-progress;
  bye
"

# The lock remains if this transfer fails, preventing a mixed release from
# serving traffic. A rerun safely resumes and completes the same promotion.
lftp --env-password "$FTP_URL" -e "
  set cmd:fail-exit true;
  set net:max-retries 2;
  set net:timeout 20;
  set ftp:ssl-force true;
  set ftp:ssl-protect-data true;
  set ssl:verify-certificate true;
  mirror --reverse --verbose --parallel=2 \
    --exclude-glob .htaccess \
    --exclude-glob deploy_version.txt \
    --exclude-glob .deploy-manifest \
    ${PACKAGE_ROOT}/ ${REMOTE_ROOT}/;
  bye
"

{
  cat <<EOF
set cmd:fail-exit true;
set net:max-retries 2;
set net:timeout 20;
set ftp:ssl-force true;
set ftp:ssl-protect-data true;
set ssl:verify-certificate true;
EOF
  while IFS= read -r stale_path; do
    [[ -n "$stale_path" ]] || continue
    printf 'rm -f %s/%s;\n' "$REMOTE_ROOT" "$stale_path"
  done < "$STALE_FILES"
  printf 'put %s/.deploy-manifest -o %s/.deploy-manifest.next;\n' "$PACKAGE_ROOT" "$REMOTE_ROOT"
  printf 'rm -f %s/.deploy-manifest;\n' "$REMOTE_ROOT"
  printf 'mv %s/.deploy-manifest.next %s/.deploy-manifest;\n' "$REMOTE_ROOT" "$REMOTE_ROOT"
  printf 'put %s/deploy_version.txt -o %s/deploy_version.txt.next;\n' "$PACKAGE_ROOT" "$REMOTE_ROOT"
  printf 'rm -f %s/deploy_version.txt;\n' "$REMOTE_ROOT"
  printf 'mv %s/deploy_version.txt.next %s/deploy_version.txt;\n' "$REMOTE_ROOT" "$REMOTE_ROOT"
  printf 'rm -f %s/.deploy-in-progress;\n' "$REMOTE_ROOT"
  printf 'bye\n'
} > "$PROMOTE_SCRIPT"

lftp --env-password "$FTP_URL" -e "source \"$PROMOTE_SCRIPT\""
