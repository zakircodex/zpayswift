<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/transfers.php';

api_require_method('GET');
api_require_app_key();
$auth = znews_require_creator(true);
$user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
$uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
$requestId = znews_firebase_key($_GET['request_id'] ?? '', 'request_id');
$row = znews_transfer_owner_request($uid, $requestId);

api_response(true, 'ZNEWS_TRANSFER_DETAILS_OK', 'Transfer request loaded.', [
    'request' => znews_transfer_public($row),
]);
