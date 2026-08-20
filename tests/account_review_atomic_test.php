<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;
$arDb = [];
$arVersions = [];
$arBeforeCas = null;
$arFailRead = false;
$arFailWrite = false;
$arAdminLogs = [];
$arSystemLogs = [];
$arWrites = [];
$arNow = 1787200000;

function ar_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function ar_user(string $status = 'REVIEW', array $extra = []): array
{
    return array_replace([
        'uid' => 'USERREVIEW01',
        'name' => 'Review Fixture',
        'status' => $status,
        'account_status' => $status,
        'review_required' => $status === 'REVIEW',
        'requires_admin_review' => $status === 'REVIEW',
        'review_status' => $status === 'REVIEW' ? 'PENDING' : '',
        'phone_country' => 'BD',
        'pricing_country' => 'MY',
        'currency' => 'MYR',
        'gps_country' => 'MY',
        'ip_country' => 'US',
        'ip_source' => 'CLOUDFLARE',
        'gps_lat' => 3.139,
        'gps_lng' => 101.6869,
        'gps_accuracy' => 12.5,
        'country_mismatch' => true,
        'vpn_suspected' => true,
        'account_review_reason' => 'GPS_IP_COUNTRY_MISMATCH',
        'KYC' => ['status' => 'PENDING_REVIEW', 'document_id' => 'PRIVATE_FIXTURE'],
        'created_at' => 1787100000,
        'updated_at' => 1787100000,
    ], $extra);
}

function ar_reset(array $user): void
{
    global $arDb, $arVersions, $arBeforeCas, $arFailRead, $arFailWrite;
    global $arAdminLogs, $arSystemLogs, $arWrites, $arNow;

    $arDb = ['USERS/' . (string)$user['uid'] => $user];
    $arVersions = ['USERS/' . (string)$user['uid'] => 1];
    $arBeforeCas = null;
    $arFailRead = false;
    $arFailWrite = false;
    $arAdminLogs = [];
    $arSystemLogs = [];
    $arWrites = [];
    $arNow = 1787200000;
}

function ar_version(string $path): int
{
    global $arVersions;
    return (int)($arVersions[$path] ?? 0);
}

function ar_notification_count(): int
{
    global $arDb;
    $count = 0;
    foreach (array_keys($arDb) as $path) {
        if (str_starts_with($path, 'USER_NOTIFICATIONS/')) {
            $count++;
        }
    }
    return $count;
}

function now_ts(): int
{
    global $arNow;
    return $arNow++;
}

function fb_get(string $path, array $query = []): mixed
{
    global $arDb;
    return $arDb[$path] ?? null;
}

function fb_put(string $path, mixed $data): bool
{
    global $arDb, $arVersions, $arWrites;
    $arDb[$path] = $data;
    $arVersions[$path] = ar_version($path) + 1;
    $arWrites[] = ['path' => $path, 'type' => 'PUT'];
    return true;
}

function fb_get_with_etag(string $path): array
{
    global $arDb, $arFailRead;
    if ($arFailRead) {
        return ['ok' => false, 'status' => 500, 'etag' => null, 'value' => null, 'error' => 'fixture'];
    }

    return [
        'ok' => true,
        'status' => 200,
        'etag' => '"v' . ar_version($path) . '"',
        'value' => $arDb[$path] ?? null,
        'error' => null,
    ];
}

function fb_put_if_match(string $path, mixed $data, string $etag): array
{
    global $arDb, $arVersions, $arBeforeCas, $arFailWrite, $arWrites;

    if (is_callable($arBeforeCas)) {
        $hook = $arBeforeCas;
        $arBeforeCas = null;
        $hook();
    }

    if ($arFailWrite) {
        return ['ok' => false, 'status' => 500, 'json' => null, 'error' => 'fixture'];
    }

    $currentEtag = '"v' . ar_version($path) . '"';
    if (!hash_equals($currentEtag, $etag)) {
        return ['ok' => false, 'status' => 412, 'json' => $arDb[$path] ?? null, 'error' => null];
    }

    $arDb[$path] = $data;
    $arVersions[$path] = ar_version($path) + 1;
    $arWrites[] = ['path' => $path, 'type' => 'CAS'];
    return ['ok' => true, 'status' => 200, 'json' => $data, 'error' => null];
}

function admin_action_log(string $actionType, string $targetId, string $note, array $context = []): void
{
    global $arAdminLogs;
    $arAdminLogs[] = compact('actionType', 'targetId', 'note', 'context');
}

function system_log(string $type, string $refId, string $message, array $context = []): void
{
    global $arSystemLogs;
    $arSystemLogs[] = compact('type', 'refId', 'message', 'context');
}

require_once $root . '/api/lib/account_review.php';

$uid = 'USERREVIEW01';
$path = 'USERS/' . $uid;

ar_reset(ar_user());
$approve = account_review_apply($uid, 'APPROVE', 'ADMIN001', 'ADMIN');
$approved = fb_get($path);
ar_expect(!empty($approve['ok']) && ($approve['data']['status'] ?? '') === 'ACTIVE', 'REVIEW -> ACTIVE failed');
ar_expect(($approved['status'] ?? '') === 'ACTIVE' && ($approved['account_status'] ?? '') === 'ACTIVE', 'canonical status fields were not updated together');
ar_expect(($approved['review_status'] ?? '') === 'APPROVED', 'approval review_status missing');
ar_expect(($approved['reviewed_by_uid'] ?? '') === 'ADMIN001' && ($approved['reviewed_by_role'] ?? '') === 'ADMIN', 'approval winner metadata is incorrect');
ar_expect((int)($approved['reviewed_at'] ?? 0) > 0 && (int)($approved['approved_at'] ?? 0) > 0, 'approval timestamps missing');
ar_expect(empty($approved['review_required']) && empty($approved['requires_admin_review']), 'review flags were not cleared');
foreach (['phone_country', 'pricing_country', 'currency', 'gps_country', 'ip_country', 'gps_lat', 'gps_lng', 'gps_accuracy', 'country_mismatch', 'vpn_suspected', 'account_review_reason', 'KYC'] as $field) {
    ar_expect($approved[$field] === ar_user()[$field], "{$field} changed during account review");
}
ar_expect(count($arAdminLogs) === 1 && count($arSystemLogs) === 1 && ar_notification_count() === 1, 'approval side effects did not run exactly once');

$approvedMetadata = [$approved['reviewed_by_uid'], $approved['reviewed_at'], $approved['approved_at']];
$approveAgain = account_review_apply($uid, 'APPROVE', 'ADMIN999', 'ADMIN');
$approvedAgain = fb_get($path);
ar_expect(!empty($approveAgain['ok']) && ($approveAgain['code'] ?? '') === 'ALREADY_ACTIVE', 'repeated Approve is not idempotent');
ar_expect(!empty($approveAgain['data']['idempotent_replay']), 'Approve replay is not identified');
ar_expect([$approvedAgain['reviewed_by_uid'], $approvedAgain['reviewed_at'], $approvedAgain['approved_at']] === $approvedMetadata, 'Approve replay overwrote winner metadata');
ar_expect(count($arAdminLogs) === 1 && count($arSystemLogs) === 1 && ar_notification_count() === 1, 'Approve replay duplicated side effects');
$rejectAfterApprove = account_review_apply($uid, 'REJECT', 'ADMIN999', 'ADMIN');
ar_expect(empty($rejectAfterApprove['ok']) && ($rejectAfterApprove['code'] ?? '') === 'ACCOUNT_REVIEW_ALREADY_DECIDED', 'Reject overwrote ACTIVE decision');
ar_expect(account_review_http_status($rejectAfterApprove) === 409 && account_review_canonical_status(fb_get($path)) === 'ACTIVE', 'terminal approval conflict was not preserved');

ar_reset(ar_user());
$reject = account_review_apply($uid, 'REJECT', 'ADMIN002', 'ADMIN');
$rejected = fb_get($path);
ar_expect(!empty($reject['ok']) && ($reject['data']['status'] ?? '') === 'REJECTED', 'REVIEW -> REJECTED failed');
ar_expect(($rejected['status'] ?? '') === 'REJECTED' && ($rejected['account_status'] ?? '') === 'REJECTED', 'rejection canonical fields are inconsistent');
ar_expect(($rejected['review_status'] ?? '') === 'REJECTED' && ($rejected['reviewed_by_uid'] ?? '') === 'ADMIN002', 'rejection winner metadata is incorrect');
$rejectedMetadata = [$rejected['reviewed_by_uid'], $rejected['reviewed_at'], $rejected['rejected_at']];
$rejectAgain = account_review_apply($uid, 'REJECT', 'ADMIN999', 'ADMIN');
ar_expect(!empty($rejectAgain['ok']) && ($rejectAgain['code'] ?? '') === 'ALREADY_REJECTED', 'repeated Reject is not idempotent');
ar_expect([$rejectedMetadata[0], $rejectedMetadata[1], $rejectedMetadata[2]] === [fb_get($path)['reviewed_by_uid'], fb_get($path)['reviewed_at'], fb_get($path)['rejected_at']], 'Reject replay overwrote winner metadata');
$approveAfterReject = account_review_apply($uid, 'APPROVE', 'ADMIN999', 'ADMIN');
ar_expect(empty($approveAfterReject['ok']) && ($approveAfterReject['code'] ?? '') === 'ACCOUNT_REVIEW_ALREADY_DECIDED', 'Approve overwrote REJECTED decision');
ar_expect(count($arAdminLogs) === 1 && count($arSystemLogs) === 1 && ar_notification_count() === 1, 'rejection replay/conflict duplicated side effects');

ar_reset(ar_user());
$arBeforeCas = static function () use ($uid): void {
    $GLOBALS['arConcurrentResult'] = account_review_apply($uid, 'REJECT', 'TG10001', 'TELEGRAM_ADMIN');
};
$approveRace = account_review_apply($uid, 'APPROVE', 'ADMIN003', 'ADMIN');
$raceUser = fb_get($path);
ar_expect(!empty($GLOBALS['arConcurrentResult']['ok']), 'concurrent Reject winner did not commit');
ar_expect(empty($approveRace['ok']) && ($approveRace['code'] ?? '') === 'ACCOUNT_REVIEW_ALREADY_DECIDED', 'stale Approve did not lose CAS race');
ar_expect(account_review_canonical_status($raceUser) === 'REJECTED', 'stale Approve overwrote concurrent Reject');
ar_expect(($raceUser['reviewed_by_uid'] ?? '') === 'TG10001' && ($raceUser['reviewed_by_role'] ?? '') === 'TELEGRAM_ADMIN', 'Reject winner metadata was overwritten');
ar_expect(count($arAdminLogs) === 1 && count($arSystemLogs) === 1 && ar_notification_count() === 1, 'Approve/Reject race produced contradictory side effects');

ar_reset(ar_user());
$arBeforeCas = static function () use ($uid): void {
    $GLOBALS['arConcurrentResult'] = account_review_apply($uid, 'APPROVE', 'ADMIN004', 'ADMIN');
};
$rejectRace = account_review_apply($uid, 'REJECT', 'TG10002', 'TELEGRAM_ADMIN');
$raceUser = fb_get($path);
ar_expect(!empty($GLOBALS['arConcurrentResult']['ok']), 'concurrent Approve winner did not commit');
ar_expect(empty($rejectRace['ok']) && ($rejectRace['code'] ?? '') === 'ACCOUNT_REVIEW_ALREADY_DECIDED', 'stale Reject did not lose CAS race');
ar_expect(account_review_canonical_status($raceUser) === 'ACTIVE', 'stale Reject overwrote concurrent Approve');
ar_expect(($raceUser['reviewed_by_uid'] ?? '') === 'ADMIN004', 'Approve winner metadata was overwritten');
ar_expect(count($arAdminLogs) === 1 && count($arSystemLogs) === 1 && ar_notification_count() === 1, 'Reject/Approve race produced contradictory side effects');

ar_reset(ar_user());
$snapshot = fb_get_with_etag($path);
$first = account_review_apply($uid, 'APPROVE', 'ADMIN005', 'ADMIN');
$staleWrite = fb_put_if_match($path, ar_user('REJECTED'), (string)$snapshot['etag']);
ar_expect(!empty($first['ok']) && empty($staleWrite['ok']) && (int)($staleWrite['status'] ?? 0) === 412, 'stale ETag was allowed to overwrite ACTIVE');
ar_expect(account_review_canonical_status(fb_get($path)) === 'ACTIVE', 'stale ETag changed ACTIVE state');

ar_reset(ar_user());
$snapshot = fb_get_with_etag($path);
$first = account_review_apply($uid, 'REJECT', 'ADMIN006', 'ADMIN');
$staleWrite = fb_put_if_match($path, ar_user('ACTIVE'), (string)$snapshot['etag']);
ar_expect(!empty($first['ok']) && empty($staleWrite['ok']) && (int)($staleWrite['status'] ?? 0) === 412, 'stale ETag was allowed to overwrite REJECTED');
ar_expect(account_review_canonical_status(fb_get($path)) === 'REJECTED', 'stale ETag changed REJECTED state');

ar_reset(ar_user());
$arBeforeCas = static function () use ($path): void {
    $row = fb_get($path);
    $row['non_review_note'] = 'fresh concurrent value';
    fb_put($path, $row);
};
$retry = account_review_apply($uid, 'APPROVE', 'ADMIN007', 'ADMIN');
ar_expect(!empty($retry['ok']) && (fb_get($path)['non_review_note'] ?? '') === 'fresh concurrent value', 'safe CAS retry lost a concurrent non-review field');

ar_reset(ar_user());
$invalidUid = account_review_apply('../bad', 'APPROVE', 'ADMIN001', 'ADMIN');
$invalidAction = account_review_apply($uid, 'DELETE', 'ADMIN001', 'ADMIN');
$invalidRole = account_review_apply($uid, 'APPROVE', 'USER001', 'USER');
ar_expect(($invalidUid['code'] ?? '') === 'VALIDATION_ERROR' && ($invalidAction['code'] ?? '') === 'VALIDATION_ERROR', 'malformed UID/action was accepted');
ar_expect(($invalidRole['code'] ?? '') === 'FORBIDDEN' && account_review_http_status($invalidRole) === 403, 'non-Admin direct decision was accepted');

ar_reset(ar_user());
$arFailRead = true;
$readFailure = account_review_apply($uid, 'APPROVE', 'ADMIN001', 'ADMIN');
ar_expect(empty($readFailure['ok']) && ($readFailure['code'] ?? '') === 'SERVER_ERROR', 'storage read failure reported success');
ar_expect(count($arAdminLogs) === 0 && count($arSystemLogs) === 0 && ar_notification_count() === 0, 'read failure executed success side effects');

ar_reset(ar_user());
$arFailWrite = true;
$writeFailure = account_review_apply($uid, 'APPROVE', 'ADMIN001', 'ADMIN');
ar_expect(empty($writeFailure['ok']) && ($writeFailure['code'] ?? '') === 'SERVER_ERROR', 'storage write failure reported success');
ar_expect(account_review_canonical_status(fb_get($path)) === 'REVIEW', 'storage failure changed canonical state');
ar_expect(count($arAdminLogs) === 0 && count($arSystemLogs) === 0 && ar_notification_count() === 0, 'write failure executed success side effects');

$reviewSource = file_get_contents($root . '/api/lib/account_review.php') ?: '';
$approveSource = file_get_contents($root . '/api/admin/users/approve.php') ?: '';
$rejectSource = file_get_contents($root . '/api/admin/users/reject.php') ?: '';
$updateSource = file_get_contents($root . '/api/admin/users/update.php') ?: '';
$telegramSource = file_get_contents($root . '/api/telegram/account_review_webhook.php') ?: '';
$dashboardSource = file_get_contents($root . '/api/admin/assets/dashboard.js') ?: '';
$applySource = strstr($reviewSource, 'function account_review_apply') ?: '';

ar_expect(str_contains($applySource, 'fb_get_with_etag') && str_contains($applySource, 'fb_put_if_match'), 'canonical review function does not use Firebase CAS');
ar_expect(!str_contains($applySource, "fb_patch('USERS/'"), 'canonical review still uses an unconditional user patch');
ar_expect(str_contains($approveSource, 'account_review_apply') && str_contains($rejectSource, 'account_review_apply'), 'Admin Approve/Reject do not share canonical transition');
ar_expect(str_contains($telegramSource, 'account_review_apply') && str_contains($telegramSource, 'account_review_parse_callback_data'), 'Telegram review does not share canonical transition/callback contract');
ar_expect(str_contains($updateSource, 'account_review_apply') && str_contains($updateSource, 'ACCOUNT_REVIEW_ACTION_REQUIRED'), 'generic Admin status update can bypass canonical review transition');
ar_expect(str_contains($approveSource, 'auth_require_admin_session(true)') && str_contains($rejectSource, 'auth_require_admin_session(true)'), 'Admin review endpoints lost canonical authorization');
ar_expect(str_contains($dashboardSource, 'ACCOUNT_REVIEW_ALREADY_DECIDED') && str_contains($dashboardSource, 'loadUsers'), 'Admin CAS loser does not reload canonical state');
ar_expect(str_contains($telegramSource, "'acct|'" ) === false, 'Telegram callback payload was unexpectedly redefined in webhook');
ar_expect(strpos($applySource, 'fb_put_if_match') < strpos($applySource, "account_review_run_side_effect('admin_action_log'"), 'success side effects can run before the state CAS');
ar_expect(!str_contains($applySource, "phone_country']") && !str_contains($applySource, 'market_registration_decision'), 'review decision recalculates market/pricing state');

echo "Account Review atomic CAS tests passed ({$assertions} assertions).\n";
