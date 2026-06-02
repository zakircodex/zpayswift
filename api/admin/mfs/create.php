<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/mfs.php';

api_require_method('POST');

$auth = auth_require_admin_session(true);

if (!function_exists('mfs_create_request')) {
    api_response(false, 'MFS_FUNCTION_MISSING', 'Required MFS function missing: mfs_create_request', [
        'function' => 'mfs_create_request',
    ], 500);
}

$body = api_read_json_body();
$target = trim((string)($body['uid'] ?? $body['target_uid'] ?? $body['phone'] ?? $body['number'] ?? ''));

if ($target === '') {
    api_response(false, 'VALIDATION_ERROR', 'User / Subadmin UID or registered phone is required', [], 422);
}

$uid = $target;
$targetUser = fb_get('USERS/' . $uid);

if (!is_array($targetUser)) {
    $phone = normalize_login_phone($target);
    $indexedUid = $phone !== '' ? fb_get('USER_INDEX/PHONE/' . $phone) : null;

    if (is_string($indexedUid) && trim($indexedUid) !== '') {
        $uid = trim($indexedUid);
        $targetUser = fb_get('USERS/' . $uid);
    }
}

if (!is_array($targetUser)) {
    api_response(false, 'USER_NOT_FOUND', 'Target user not found', [], 404);
}

$actorUser = is_array($auth) ? $auth : [];

$actor = [
    'uid' => (string)($actorUser['uid'] ?? $actorUser['user']['uid'] ?? ''),
    'role' => 'ADMIN',
    'skip_pin_validation' => true,
];

$res = mfs_create_request($uid, $body, 'ADMIN_PANEL', 'ADMIN', $actor);

if (empty($res['ok'])) {
    $code = (string)($res['code'] ?? 'SERVER_ERROR');
    $httpStatus = 500;

    if (in_array($code, [
        'VALIDATION_ERROR',
        'INSUFFICIENT_BALANCE',
        'MFS_DISABLED',
        'PROVIDER_DISABLED',
        'SERVICE_NOT_ALLOWED',
        'COUNTRY_MISSING',
        'WALLET_CURRENCY_MISSING',
        'COUNTRY_CURRENCY_MISMATCH',
        'UNSUPPORTED_COUNTRY_CURRENCY',
    ], true)) {
        $httpStatus = 422;
    } elseif ($code === 'ACCOUNT_INACTIVE') {
        $httpStatus = 403;
    } elseif ($code === 'USER_NOT_FOUND') {
        $httpStatus = 404;
    }

    api_response(false, $code, (string)($res['message'] ?? 'Failed to create MFS request'), (array)($res['data'] ?? []), $httpStatus);
}

api_response(true, 'SUCCESS', 'MFS request created successfully', (array)($res['data'] ?? []));
