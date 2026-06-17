<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

function zb_plan_defaults_for_select(): array {
    $config = fb_get('Z_BUILDER_PLAN_CONFIG');
    $defaults = [
        'FREE_TRIAL' => ['title' => 'Free Trial', 'months' => 0, 'trial_days' => 7, 'price' => 0, 'discount_percent' => 0, 'tag' => 'Free'],
        'SUBSCRIPTION_3M' => ['title' => 'Starter', 'months' => 3, 'price' => 0, 'discount_percent' => 0, 'tag' => '3 Months'],
        'SUBSCRIPTION_6M' => ['title' => 'Business', 'months' => 6, 'price' => 0, 'discount_percent' => 0, 'tag' => '6 Months'],
        'SUBSCRIPTION_12M' => ['title' => 'Professional', 'months' => 12, 'price' => 0, 'discount_percent' => 0, 'tag' => '1 Year'],
    ];
    $plans = is_array($config['plans'] ?? null) ? $config['plans'] : [];
    foreach ($defaults as $code => $def) {
        $plans[$code] = array_replace_recursive($def, is_array($plans[$code] ?? null) ? $plans[$code] : []);
        $plans[$code]['code'] = $code;
    }
    return $plans;
}

$ctx = zb_require_owner_session();
$ownerId = (string)($ctx['owner']['owner_id'] ?? '');
$body = api_read_json_body();
$code = strtoupper(trim((string)($body['plan_code'] ?? $body['code'] ?? '')));
$plans = zb_plan_defaults_for_select();
if (!isset($plans[$code])) { api_response(false, 'INVALID_PLAN', 'Invalid plan selected', [], 422); }
$plan = $plans[$code];
if (isset($plan['active']) && !$plan['active']) { api_response(false, 'PLAN_INACTIVE', 'Selected plan is not active', [], 422); }

$now = time();
$selection = [
    'owner_id' => $ownerId,
    'plan_code' => $code,
    'title' => (string)($plan['title'] ?? $code),
    'months' => (int)($plan['months'] ?? 0),
    'price' => (float)($plan['price'] ?? 0),
    'discount_percent' => (float)($plan['discount_percent'] ?? 0),
    'status' => $code === 'FREE_TRIAL' ? 'FREE_SELECTED' : 'WAITING_PAYMENT',
    'selected_at' => zb_now_iso($now),
    'updated_at' => zb_now_iso($now),
];
fb_put('Z_BUILDER_OWNER_PLAN_SELECTIONS/' . $ownerId, $selection);

if ($code === 'FREE_TRIAL') {
    $expires = $now + ((int)($plan['trial_days'] ?? 7) * 86400);
    $active = [
        'owner_id' => $ownerId,
        'plan_code' => $code,
        'title' => (string)($plan['title'] ?? 'Free Trial'),
        'type' => 'FREE',
        'status' => 'FREE_ACTIVE',
        'price' => 0,
        'discount_percent' => 0,
        'started_at' => zb_now_iso($now),
        'expires_at' => zb_now_iso($expires),
        'updated_at' => zb_now_iso($now),
    ];
    fb_put('Z_BUILDER_OWNER_PLANS/' . $ownerId, $active);
    api_response(true, 'FREE_ACTIVE', 'Free trial activated', [
        'plan' => $active,
        'next_url' => '/z-builder/setup/index.html',
    ]);
}

api_response(true, 'PAYMENT_REQUIRED', 'Payment required for selected plan', [
    'selection' => $selection,
    'next_url' => '/z-builder/payment/index.html?plan=' . rawurlencode($code),
]);
