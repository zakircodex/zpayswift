<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/wallet.php';
require_once dirname(__DIR__, 2) . '/lib/topup.php';
require_once dirname(__DIR__, 2) . '/lib/bundle.php';

api_require_method('POST');
auth_require_admin_session();

$body = api_read_json_body();

$requestId = trim((string)($body['request_id'] ?? ''));
$message = trim((string)($body['message'] ?? 'Failed to send bundle'));

if ($requestId === '') {
    api_response(false, 'VALIDATION_ERROR', 'request_id is required', [], 422);
}

$res = bundle_mark_failed($requestId, $message);

if (!($res['ok'] ?? false)) {
    api_response(false, (string)($res['code'] ?? 'SERVER_ERROR'), (string)($res['message'] ?? 'Failed to mark bundle failed'), [], 500);
}

api_response(true, 'BUNDLE_FAILED', 'Bundle marked as failed', [
    'request_id' => $requestId,
]);