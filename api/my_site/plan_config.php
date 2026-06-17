<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('GET');

function zb_plan_default_config(): array {
    return [
        'currency' => 'BDT',
        'payment_methods' => [
            'BKASH' => ['enabled' => true, 'label' => 'bKash', 'number' => '', 'account_type' => 'Personal'],
            'NAGAD' => ['enabled' => true, 'label' => 'Nagad', 'number' => '', 'account_type' => 'Personal'],
        ],
        'plans' => [
            'FREE_TRIAL' => [
                'code' => 'FREE_TRIAL',
                'title' => 'Free Trial',
                'months' => 0,
                'trial_days' => 7,
                'price' => 0,
                'discount_percent' => 0,
                'tag' => 'Free',
                'best_for' => 'Test your site for 7 days',
                'features' => ['Basic site', 'User panel preview', 'Paid options locked'],
                'active' => true,
            ],
            'SUBSCRIPTION_3M' => [
                'code' => 'SUBSCRIPTION_3M',
                'title' => 'Starter',
                'months' => 3,
                'price' => 0,
                'discount_percent' => 0,
                'tag' => '3 Months',
                'best_for' => 'New owner / small business',
                'features' => ['Custom branding', 'Control panel', 'Reports', 'Worker setup'],
                'active' => true,
            ],
            'SUBSCRIPTION_6M' => [
                'code' => 'SUBSCRIPTION_6M',
                'title' => 'Business',
                'months' => 6,
                'price' => 0,
                'discount_percent' => 0,
                'tag' => '6 Months',
                'best_for' => 'Regular business',
                'features' => ['More validity', 'Custom domain', 'Advanced control', 'Telegram setup'],
                'active' => true,
            ],
            'SUBSCRIPTION_12M' => [
                'code' => 'SUBSCRIPTION_12M',
                'title' => 'Professional',
                'months' => 12,
                'price' => 0,
                'discount_percent' => 0,
                'tag' => '1 Year',
                'best_for' => 'Best value / professional owner',
                'features' => ['Full year access', 'Best value', 'Full owner tools', 'Priority setup'],
                'active' => true,
            ],
        ],
        'updated_at' => null,
    ];
}

function zb_plan_merge_defaults(array $saved, array $defaults): array {
    $out = array_replace_recursive($defaults, $saved);
    foreach ($defaults['plans'] as $code => $plan) {
        $out['plans'][$code] = array_replace_recursive($plan, is_array($saved['plans'][$code] ?? null) ? $saved['plans'][$code] : []);
        $out['plans'][$code]['code'] = $code;
    }
    return $out;
}

$saved = fb_get('Z_BUILDER_PLAN_CONFIG');
$config = zb_plan_merge_defaults(is_array($saved) ? $saved : [], zb_plan_default_config());
api_response(true, 'SUCCESS', 'Plan config loaded', ['config' => $config]);
