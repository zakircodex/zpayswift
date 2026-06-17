<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');
api_require_admin_key();

$body = api_read_json_body();
$plans = is_array($body['plans'] ?? null) ? $body['plans'] : [];
$methods = is_array($body['payment_methods'] ?? null) ? $body['payment_methods'] : [];
$allowed = ['FREE_TRIAL', 'SUBSCRIPTION_3M', 'SUBSCRIPTION_6M', 'SUBSCRIPTION_12M'];
$outPlans = [];
foreach ($allowed as $code) {
    $row = is_array($plans[$code] ?? null) ? $plans[$code] : [];
    $outPlans[$code] = [
        'code' => $code,
        'title' => trim((string)($row['title'] ?? '')),
        'tag' => trim((string)($row['tag'] ?? '')),
        'months' => max(0, (int)($row['months'] ?? ($code === 'SUBSCRIPTION_3M' ? 3 : ($code === 'SUBSCRIPTION_6M' ? 6 : ($code === 'SUBSCRIPTION_12M' ? 12 : 0))))),
        'trial_days' => max(0, (int)($row['trial_days'] ?? 7)),
        'price' => max(0, (float)($row['price'] ?? 0)),
        'discount_percent' => max(0, min(100, (float)($row['discount_percent'] ?? 0))),
        'best_for' => trim((string)($row['best_for'] ?? '')),
        'features' => is_array($row['features'] ?? null) ? array_values($row['features']) : [],
        'active' => !array_key_exists('active', $row) || (bool)$row['active'],
    ];
}
$out = [
    'currency' => 'BDT',
    'plans' => $outPlans,
    'payment_methods' => [
        'BKASH' => [
            'enabled' => (bool)($methods['BKASH']['enabled'] ?? true),
            'label' => 'bKash',
            'number' => preg_replace('/[^0-9+]/', '', (string)($methods['BKASH']['number'] ?? '')),
            'account_type' => trim((string)($methods['BKASH']['account_type'] ?? 'Personal')),
        ],
        'NAGAD' => [
            'enabled' => (bool)($methods['NAGAD']['enabled'] ?? true),
            'label' => 'Nagad',
            'number' => preg_replace('/[^0-9+]/', '', (string)($methods['NAGAD']['number'] ?? '')),
            'account_type' => trim((string)($methods['NAGAD']['account_type'] ?? 'Personal')),
        ],
    ],
    'updated_at' => zb_now_iso(),
    'updated_by' => 'ADMIN_KEY',
];
fb_put('Z_BUILDER_PLAN_CONFIG', $out);
api_response(true, 'SUCCESS', 'Z Builder plan config saved', ['config' => $out]);
