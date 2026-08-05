<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 3) . '/znews/lib/creator_payout_batches.php';

api_require_method('POST');
auth_require_admin_session(true);
$body = api_read_json_body();

$creatorUids = $body['creator_uids'] ?? [];
if (!is_array($creatorUids)) {
    api_response(false, 'ZNEWS_PAYOUT_CREATOR_LIST_INVALID', 'creator_uids must be an array.', [], 422);
}

$result = znews_creator_payout_batch_preflight($creatorUids);
api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'ZNEWS_PAYOUT_PREFLIGHT_FAILED'),
    (string)($result['message'] ?? 'Creator payout batch failed preflight.'),
    $result,
    (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 422))
);
