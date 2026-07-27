<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/settlements.php';

api_require_method('GET');
api_require_app_key();

$auth = znews_require_creator(true);
$user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
$uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');

api_response(
    true,
    'ZNEWS_BALANCE_SUMMARY_OK',
    'Z News balance loaded.',
    znews_creator_balance_summary($uid)
);
