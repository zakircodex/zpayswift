<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

$ctx = zb_require_owner_session();
$owner = $ctx['owner'];
$ownerId = (string)($owner['owner_id'] ?? '');

$body = api_read_json_body();
if (!$body && !empty($_POST)) { $body = $_POST; }
$number = normalize_bd_topup_number((string)($body['topup_number'] ?? $body['number'] ?? ''));
$operator = normalize_operator((string)($body['operator'] ?? ''));
$amount = (float)($body['amount'] ?? 0);

if (!is_valid_bd_topup_number($number)) {
    api_response(false, 'INVALID_NUMBER', 'Valid BD topup number is required', [], 422);
}
if (!is_valid_operator($operator)) {
    api_response(false, 'INVALID_OPERATOR', 'Valid operator is required', [], 422);
}
if ($amount < 500) {
    api_response(false, 'MIN_AMOUNT', 'Minimum topup amount is BDT 500', ['minimum' => 500], 422);
}

$now = now_ts();
$requestId = make_topup_request_id();
$uid = 'ZBU_TEST_' . substr(hash('sha256', $ownerId), 0, 16);
$walletPath = 'USER_WALLETS/' . $uid;
$wallet = fb_get($walletPath);
if (!is_array($wallet)) {
    fb_put($walletPath, [
        'uid' => $uid,
        'owner_id' => $ownerId,
        'type' => 'Z_BUILDER_TEST_WALLET',
        'currency' => 'BDT',
        'wallet_currency' => 'BDT',
        'available_balance' => 0,
        'hold_balance' => $amount,
        'updated_at' => $now,
    ]);
} else {
    fb_patch($walletPath, [
        'hold_balance' => (float)($wallet['hold_balance'] ?? 0) + $amount,
        'updated_at' => $now,
    ]);
}
$row = [
    'request_id' => $requestId,
    'uid' => $uid,
    'z_builder_owner_id' => $ownerId,
    'tenant_owner_id' => $ownerId,
    'source' => 'Z_BUILDER_TEST',
    'request_source' => 'Z_BUILDER_TEST',
    'test_mode' => true,
    'status' => 'PENDING',
    'topup_number' => $number,
    'operator' => $operator,
    'amount' => $amount,
    'amount_bdt' => $amount,
    'wallet_debit_amount' => $amount,
    'wallet_debit_bdt' => $amount,
    'wallet_debit_currency' => 'BDT',
    'wallet_currency' => 'BDT',
    'created_at' => $now,
    'updated_at' => $now,
];

if (!fb_put('TOPUP_REQUESTS/PENDING/' . $requestId, $row)) {
    api_response(false, 'SAVE_FAILED', 'Failed to save topup request', [], 500);
}
fb_put('Z_BUILDER_OWNER_TOPUPS/' . $ownerId . '/' . month_key($now) . '/' . $requestId, $row);
fb_put('REQUEST_STATUS/' . $requestId, [
    'request_id' => $requestId,
    'status' => 'PENDING',
    'message' => 'Waiting for Z Builder worker',
    'updated_at' => $now,
]);

api_response(true, 'TOPUP_QUEUED', 'Topup request queued for worker', [
    'request_id' => $requestId,
    'status' => 'PENDING',
    'topup_number' => $number,
    'operator' => $operator,
    'amount' => $amount,
]);
