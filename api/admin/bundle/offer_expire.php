<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/bundle.php';

api_require_method('POST');

$actor = auth_require_admin_session(true);
if (!is_array($actor)) {
    $actor = [];
}

$body = api_read_json_body();
$offerId = trim((string)($body['offer_id'] ?? ''));

if ($offerId === '') {
    api_response(false, 'VALIDATION_ERROR', 'offer_id is required', [
        'field' => 'offer_id',
    ], 422);
}

$offer = fb_get('BUNDLE_OFFERS/' . $offerId);

if (!is_array($offer)) {
    api_response(false, 'NOT_FOUND', 'Bundle offer not found', [
        'offer_id' => $offerId,
    ], 404);
}

$currentStatus = strtoupper(trim((string)($offer['status'] ?? 'ACTIVE')));

if ($currentStatus === 'DELETED' || !empty($offer['deleted'])) {
    api_response(false, 'INVALID_STATUS', 'Deleted bundle offer cannot be expired', [
        'offer_id' => $offerId,
        'status' => 'DELETED',
    ], 422);
}

if ($currentStatus === 'EXPIRED' || !empty($offer['expired'])) {
    api_response(true, 'SUCCESS', 'Bundle offer already expired', [
        'offer_id' => $offerId,
        'status' => 'EXPIRED',
        'active' => false,
        'expired' => true,
        'expired_at' => (int)($offer['expired_at'] ?? 0),
    ]);
}

$now = function_exists('bundle_now') ? (int)bundle_now() : time();
$expiredAt = $now - 60;
$actorUid = trim((string)($actor['uid'] ?? $actor['user']['uid'] ?? ''));
$actorRole = strtoupper(trim((string)($actor['role'] ?? $actor['user']['role'] ?? 'ADMIN')));

$patch = [
    'active' => false,
    'status' => 'EXPIRED',
    'expired' => true,
    'expires_at' => $expiredAt,
    'expire_at' => $expiredAt,
    'expired_at' => $now,
    'expired_by_uid' => $actorUid,
    'expired_by_role' => $actorRole,
    'updated_at' => $now,
    'updated_by_uid' => $actorUid,
    'updated_by_role' => $actorRole,
];

if (!fb_patch('BUNDLE_OFFERS/' . $offerId, $patch)) {
    api_response(false, 'SERVER_ERROR', 'Failed to expire bundle offer', [
        'offer_id' => $offerId,
    ], 500);
}

if (function_exists('system_log')) {
    system_log('ADMIN_BUNDLE_OFFER_EXPIRE', $offerId, 'Admin expired bundle offer', [
        'offer_id' => $offerId,
        'bundle_name' => (string)($offer['bundle_name'] ?? $offer['name'] ?? ''),
        'operator' => (string)($offer['operator'] ?? ''),
        'previous_status' => $currentStatus,
        'actor_uid' => $actorUid,
        'actor_role' => $actorRole,
        'expired_at' => $now,
    ]);
}

api_response(true, 'SUCCESS', 'Bundle offer expired successfully', [
    'offer_id' => $offerId,
    'status' => 'EXPIRED',
    'active' => false,
    'expired' => true,
    'expired_at' => $now,
]);
