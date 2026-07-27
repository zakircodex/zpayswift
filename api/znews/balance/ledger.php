<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/settlements.php';

api_require_method('GET');
api_require_app_key();

$auth = znews_require_creator(true);
$user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
$uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
$currency = znews_ad_currency($_GET['currency'] ?? '');
$limit = znews_limit($_GET['limit'] ?? 20, 20, 50);
$cursor = znews_settlement_cursor_decode($_GET['cursor'] ?? '');

api_response(
    true,
    'ZNEWS_BALANCE_LEDGER_OK',
    'Z News balance activity loaded.',
    znews_creator_ledger($uid, $currency, $limit, $cursor)
);
