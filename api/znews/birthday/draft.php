<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/birthday.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $draftId = trim((string)($_GET['id'] ?? ''));
    $token = trim((string)(api_get_header('X-Draft-Token') ?? ''));
    $draft = birthday_load_draft($draftId, $token);
    api_response(true, 'BIRTHDAY_DRAFT_OK', 'Draft loaded.', ['draft' => birthday_public_draft($draft)]);
}
if ($method !== 'POST') {
    api_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method.', [], 405);
}
birthday_same_origin();
$body = api_read_json_body();
$payload = birthday_validate_payload($body);
birthday_require_consent($payload);
$token = trim((string)($body['draft_token'] ?? ''));
$draft = birthday_create_draft($payload, $token);
api_response(true, 'BIRTHDAY_DRAFT_CREATED', 'Draft saved securely.', ['draft' => $draft], 201);
