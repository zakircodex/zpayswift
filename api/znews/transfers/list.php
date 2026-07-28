<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/transfers.php';

api_require_method('GET');
api_require_app_key();
$auth = znews_require_creator(true);
$user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
$uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
$limit = znews_limit($_GET['limit'] ?? 20, 20, 50);
$cursor = znews_transfer_cursor_decode($_GET['cursor'] ?? '');

api_response(
    true,
    'ZNEWS_TRANSFERS_OK',
    'Transfer requests loaded.',
    znews_transfer_user_list($uid, $limit, $cursor)
);
