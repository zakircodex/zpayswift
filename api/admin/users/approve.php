<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('POST');

$auth = auth_require_admin_session(true);
$adminUser = $auth['user'];
$body = api_read_json_body();
$uid = trim((string)($body['uid'] ?? ''));

if ($uid === '') {
    api_response(false, 'VALIDATION_ERROR', 'uid is required', ['field' => 'uid'], 422);
}

$user = fb_get('USERS/' . $uid);
if (!is_array($user)) {
    api_response(false, 'NOT_FOUND', 'User not found', [], 404);
}

$currentStatus = strtoupper(trim((string)($user['account_status'] ?? $user['status'] ?? 'INACTIVE')));

if ($currentStatus === 'ACTIVE') {
    api_response(true, 'ALREADY_ACTIVE', 'Account is already active', [
        'uid' => $uid,
        'status' => 'ACTIVE',
        'account_status' => 'ACTIVE',
    ]);
}

if (!in_array($currentStatus, ['REVIEW', 'BLOCKED', 'INACTIVE'], true)) {
    api_response(false, 'INVALID_STATUS', 'Account cannot be approved from its current status', [
        'current_status' => $currentStatus,
    ], 422);
}

$now = now_ts();
$adminUid = (string)($adminUser['uid'] ?? '');
$patch = [
    'status' => 'ACTIVE',
    'account_status' => 'ACTIVE',
    'review_status' => 'APPROVED',
    'approved_by_uid' => $adminUid,
    'approved_at' => $now,
    'reviewed_by_uid' => $adminUid,
    'reviewed_at' => $now,
    'updated_at' => $now,
];

if (!fb_patch('USERS/' . $uid, $patch)) {
    api_response(false, 'SERVER_ERROR', 'Failed to approve account', [], 500);
}

admin_action_log('APPROVE_USER_ACCOUNT', $uid, 'Admin approved reviewed user account', [
    'uid' => $uid,
    'old_status' => $currentStatus,
    'new_status' => 'ACTIVE',
    'pricing_country' => auth_pricing_country_from_user($user),
    'gps_country' => (string)($user['gps_country'] ?? ''),
    'ip_country' => (string)($user['ip_country'] ?? ''),
    'vpn_suspected' => (bool)($user['vpn_suspected'] ?? false),
    'admin_uid' => $adminUid,
]);

system_log('ADMIN_APPROVE_USER_ACCOUNT', $uid, 'Admin approved reviewed user account', [
    'uid' => $uid,
    'old_status' => $currentStatus,
    'admin_uid' => $adminUid,
]);

api_response(true, 'SUCCESS', 'Account approved successfully', [
    'uid' => $uid,
    'status' => 'ACTIVE',
    'account_status' => 'ACTIVE',
    'approved_at' => $now,
]);
