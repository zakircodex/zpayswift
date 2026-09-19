<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/birthday.php';

api_require_app_key();
$auth = birthday_require_account();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$slug = strtolower(trim((string)($_GET['slug'] ?? '')));
if ($method === 'GET') {
    $universe = birthday_owned_universe($slug, $auth);
    api_response(true, 'BIRTHDAY_MANAGE_OK', 'Universe management data loaded.', [
        'universe' => birthday_public_universe($universe),
    ]);
}
if ($method !== 'POST') {
    api_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method.', [], 405);
}
birthday_same_origin();
$body = api_read_json_body();
$action = strtoupper(trim((string)($body['action'] ?? '')));
$slug = strtolower(trim((string)($body['slug'] ?? $slug)));
if ($action === 'CLAIM') {
    $universe = birthday_claim($slug, trim((string)($body['recovery_code'] ?? '')), $auth);
    api_response(true, 'BIRTHDAY_CLAIMED', 'This Universe is now linked to your Z-Pay account.', ['universe' => $universe]);
}
$row = birthday_owned_universe($slug, $auth);
if ($action === 'UPDATE') {
    $payload = birthday_validate_payload(is_array($body['universe'] ?? null) ? (array)$body['universe'] : $body);
    birthday_require_consent($payload);
    api_response(true, 'BIRTHDAY_UPDATED', 'Universe updated.', ['universe' => birthday_update_owned($row, $payload)]);
}
if ($action === 'RENEW') {
    api_response(true, 'BIRTHDAY_RENEWED', 'Universe renewed.', ['universe' => birthday_renew_owned($row)]);
}
if ($action === 'REMOVE_PHOTO') {
    api_response(true, 'BIRTHDAY_PHOTO_REMOVED', 'Photo removed.', ['universe' => birthday_remove_photo_owned($row)]);
}
if ($action === 'DELETE') {
    $uid = trim((string)($auth['user']['uid'] ?? ''));
    birthday_delete_owned($row, $uid);
    api_response(true, 'BIRTHDAY_DELETED', 'Universe deleted.', []);
}
api_response(false, 'BIRTHDAY_ACTION_INVALID', 'Invalid management action.', [], 422);
