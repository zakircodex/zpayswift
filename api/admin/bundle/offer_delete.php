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

function admin_bundle_delete_now(): int
{
    if (function_exists('bundle_now')) {
        return (int)bundle_now();
    }

    if (function_exists('now_ts')) {
        return (int)now_ts();
    }

    return time();
}

function admin_bundle_delete_load_offer(string $offerId): array
{
    $offerId = trim($offerId);

    if ($offerId === '') {
        return [];
    }

    /*
     * Direct Firebase read first.
     * Deleted/expired offer sometimes helper may hide, so direct read is safer.
     */
    $row = fb_get('BUNDLE_OFFERS/' . $offerId);
    if (is_array($row) && $row) {
        $row['offer_id'] = (string)($row['offer_id'] ?? $offerId);
        return $row;
    }

    if (function_exists('bundle_load_offer')) {
        $loaded = bundle_load_offer($offerId);
        if (is_array($loaded) && $loaded) {
            $loaded['offer_id'] = (string)($loaded['offer_id'] ?? $offerId);
            return $loaded;
        }
    }

    return [];
}

function admin_bundle_delete_save_offer(string $offerId, array $patch): bool
{
    $offerId = trim($offerId);

    if ($offerId === '') {
        return false;
    }

    return (bool)fb_patch('BUNDLE_OFFERS/' . $offerId, $patch);
}

$offerId = trim((string)($body['offer_id'] ?? ''));

if ($offerId === '') {
    api_response(false, 'VALIDATION_ERROR', 'offer_id is required', [
        'field' => 'offer_id',
    ], 422);
}

$offer = admin_bundle_delete_load_offer($offerId);

if (!$offer) {
    api_response(false, 'NOT_FOUND', 'Bundle offer not found', [
        'offer_id' => $offerId,
    ], 404);
}

$currentStatus = strtoupper(trim((string)($offer['status'] ?? 'ACTIVE')));

if ($currentStatus === 'DELETED' || !empty($offer['deleted'])) {
    api_response(true, 'SUCCESS', 'Bundle offer already deleted', [
        'offer_id' => $offerId,
        'status' => 'DELETED',
        'deleted' => true,
        'deleted_at' => (int)($offer['deleted_at'] ?? 0),
    ]);
}

$now = admin_bundle_delete_now();

$actorUid = trim((string)($actor['uid'] ?? ''));
$actorRole = strtoupper(trim((string)($actor['role'] ?? 'ADMIN')));

$deleteNote = trim((string)($body['note'] ?? $body['delete_note'] ?? ''));

$patch = [
    'offer_id' => $offerId,

    /*
     * Soft delete flags.
     * Normal offer list/user panel should exclude these.
     */
    'status' => 'DELETED',
    'active' => false,
    'deleted' => true,

    /*
     * Keep expired field false here because this is deleted,
     * not only expired.
     */
    'expired' => false,

    'deleted_at' => $now,
    'deleted_by_uid' => $actorUid,
    'deleted_by_role' => $actorRole,
    'delete_note' => $deleteNote,

    'updated_at' => $now,
    'updated_by_uid' => $actorUid,
    'updated_by_role' => $actorRole,
];

$ok = admin_bundle_delete_save_offer($offerId, $patch);

if (!$ok) {
    api_response(false, 'SERVER_ERROR', 'Failed to delete bundle offer', [
        'offer_id' => $offerId,
    ], 500);
}

if (function_exists('system_log')) {
    system_log('ADMIN_BUNDLE_OFFER_DELETE', $offerId, 'Admin soft deleted bundle offer', [
        'offer_id' => $offerId,
        'bundle_name' => (string)($offer['bundle_name'] ?? $offer['name'] ?? ''),
        'operator' => (string)($offer['operator'] ?? ''),
        'previous_status' => $currentStatus,
        'actor_uid' => $actorUid,
        'actor_role' => $actorRole,
        'deleted_at' => $now,
    ]);
}

api_response(true, 'SUCCESS', 'Bundle offer deleted successfully', [
    'offer_id' => $offerId,
    'status' => 'DELETED',
    'active' => false,
    'deleted' => true,
    'deleted_at' => $now,
]);