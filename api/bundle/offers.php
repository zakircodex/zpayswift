<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/bundle.php';

api_require_method('GET');
api_require_app_key();

$auth = auth_require_user(true);
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$uid = trim((string)($user['uid'] ?? ''));
$operatorFilter = strtoupper(trim((string)($_GET['operator'] ?? '')));
$operatorFilter = $operatorFilter !== '' && function_exists('normalize_operator')
    ? normalize_operator($operatorFilter)
    : $operatorFilter;

$status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
if ($status !== 'ACTIVE') {
    api_response(false, 'ACCOUNT_INACTIVE', 'Account is inactive', [], 403);
}

$offers = [];
foreach (bundle_list_visible_offers_for_user($uid) as $offer) {
    if (!is_array($offer)) {
        continue;
    }
    $operator = strtoupper(trim((string)($offer['operator'] ?? '')));
    if ($operatorFilter !== '' && $operator !== $operatorFilter) {
        continue;
    }
    $offers[] = bundle_public_offer($offer);
}

api_response(true, 'SUCCESS', 'Bundle offers loaded', [
    'operator' => $operatorFilter,
    'total' => count($offers),
    'items' => array_values($offers),
]);
