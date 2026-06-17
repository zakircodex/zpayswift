<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

function zb_payment_plan_config(): array {
    $config = fb_get('Z_BUILDER_PLAN_CONFIG');
    $plans = is_array($config['plans'] ?? null) ? $config['plans'] : [];
    $defaults = [
        'SUBSCRIPTION_3M' => ['title' => 'Starter', 'months' => 3, 'price' => 0, 'discount_percent' => 0],
        'SUBSCRIPTION_6M' => ['title' => 'Business', 'months' => 6, 'price' => 0, 'discount_percent' => 0],
        'SUBSCRIPTION_12M' => ['title' => 'Professional', 'months' => 12, 'price' => 0, 'discount_percent' => 0],
    ];
    foreach ($defaults as $code => $def) {
        $plans[$code] = array_replace_recursive($def, is_array($plans[$code] ?? null) ? $plans[$code] : []);
        $plans[$code]['code'] = $code;
    }
    return ['plans' => $plans, 'currency' => (string)($config['currency'] ?? 'BDT')];
}

$ctx = zb_require_owner_session();
$owner = $ctx['owner'];
$ownerId = (string)($owner['owner_id'] ?? '');
$body = api_read_json_body();
$code = strtoupper(trim((string)($body['plan_code'] ?? '')));
$method = strtoupper(trim((string)($body['method'] ?? '')));
$trxId = strtoupper(trim((string)($body['trx_id'] ?? $body['transaction_id'] ?? '')));
$sender = trim((string)($body['sender_number'] ?? ''));
$note = trim((string)($body['note'] ?? ''));

$config = zb_payment_plan_config();
$plans = $config['plans'];
if (!isset($plans[$code]) || $code === 'FREE_TRIAL') { api_response(false, 'INVALID_PLAN', 'Paid plan required', [], 422); }
if (!in_array($method, ['BKASH', 'NAGAD'], true)) { api_response(false, 'INVALID_METHOD', 'Payment method must be bKash or Nagad', [], 422); }
if ($trxId === '' || strlen($trxId) < 5 || strlen($trxId) > 40) { api_response(false, 'TRX_ID_REQUIRED', 'Valid TRX ID required', [], 422); }

$plan = $plans[$code];
$amount = (float)($plan['price'] ?? 0);
if ($amount <= 0) { api_response(false, 'PRICE_NOT_CONFIGURED', 'Plan price is not configured yet', [], 422); }

$paymentId = 'ZBPAY_' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$now = time();
$payment = [
    'payment_id' => $paymentId,
    'owner_id' => $ownerId,
    'owner_name' => (string)($owner['name'] ?? ''),
    'owner_phone' => (string)($owner['phone_local'] ?? ''),
    'plan_code' => $code,
    'plan_title' => (string)($plan['title'] ?? $code),
    'months' => (int)($plan['months'] ?? 0),
    'amount' => $amount,
    'currency' => (string)($config['currency'] ?? 'BDT'),
    'discount_percent' => (float)($plan['discount_percent'] ?? 0),
    'method' => $method,
    'trx_id' => $trxId,
    'sender_number' => $sender,
    'note' => $note,
    'status' => 'PAYMENT_PENDING',
    'created_at' => zb_now_iso($now),
    'updated_at' => zb_now_iso($now),
    'ip' => client_ip(),
];

fb_put('Z_BUILDER_PAYMENTS/' . $paymentId, $payment);
fb_put('Z_BUILDER_OWNER_PAYMENTS/' . $ownerId . '/' . $paymentId, $payment);
fb_put('Z_BUILDER_OWNER_LATEST_PAYMENT/' . $ownerId, $payment);
fb_put('Z_BUILDER_OWNER_PLANS/' . $ownerId, [
    'owner_id' => $ownerId,
    'plan_code' => $code,
    'title' => (string)($plan['title'] ?? $code),
    'type' => 'PAID',
    'status' => 'PAYMENT_PENDING',
    'price' => $amount,
    'currency' => (string)($config['currency'] ?? 'BDT'),
    'discount_percent' => (float)($plan['discount_percent'] ?? 0),
    'payment_id' => $paymentId,
    'selected_at' => zb_now_iso($now),
    'updated_at' => zb_now_iso($now),
]);

api_response(true, 'PAYMENT_PENDING', 'Payment submitted for admin approval', ['payment' => $payment]);
