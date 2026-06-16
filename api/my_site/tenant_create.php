<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_helpers.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

$siteName = trim((string)($body['site_name'] ?? ''));
if ($siteName === '' || mb_strlen($siteName) < 3 || mb_strlen($siteName) > 80) {
    api_response(false, 'INVALID_SITE_NAME', 'Site name must be 3 to 80 characters', [], 422);
}

$plan = strtoupper(trim((string)($body['plan'] ?? 'FREE_TRIAL')));
if (!in_array($plan, ['FREE_TRIAL', 'SUBSCRIPTION'], true)) {
    api_response(false, 'INVALID_PLAN', 'Invalid tenant plan', [], 422);
}

$ownerUid = trim((string)($body['owner_uid'] ?? ''));
$siteSlug = my_site_slugify((string)($body['site_slug'] ?? $siteName));
$existingSlug = fb_get('TENANT_SLUGS/' . $siteSlug);
if ($existingSlug !== null) {
    api_response(false, 'SITE_SLUG_EXISTS', 'Site slug already exists', ['site_slug' => $siteSlug], 409);
}

$customDomain = strtolower(trim((string)($body['custom_domain'] ?? '')));
if ($customDomain !== '' && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $customDomain)) {
    api_response(false, 'INVALID_DOMAIN', 'Invalid custom domain format', [], 422);
}

if ($customDomain !== '') {
    $domainHash = hash('sha256', $customDomain);
    if (fb_get('TENANT_DOMAINS/' . $domainHash) !== null) {
        api_response(false, 'DOMAIN_EXISTS', 'Custom domain already exists', [], 409);
    }
}

$topupCommission = max(0, (float)($body['commission']['topup_per_1000'] ?? $body['topup_per_1000'] ?? 18));
$bundleCommission = max(0, (float)($body['commission']['bundle_per_1000'] ?? $body['bundle_per_1000'] ?? 0));
$now = time();
$tenantId = my_site_make_tenant_id();
$expiresAt = $plan === 'SUBSCRIPTION'
    ? my_site_now_iso($now + (30 * 86400))
    : my_site_now_iso($now + (7 * 86400));
$subscriptionStatus = $plan === 'SUBSCRIPTION' ? 'ACTIVE' : 'TRIALING';

$tenant = [
    'tenant_id' => $tenantId,
    'owner_uid' => $ownerUid,
    'plan' => $plan,
    'subscription_status' => $subscriptionStatus,
    'subscription_expires_at' => $expiresAt,
    'site_name' => $siteName,
    'site_slug' => $siteSlug,
    'free_url' => 'https://zpayswift.com/site/' . $siteSlug,
    'custom_domain' => $customDomain,
    'domain_status' => $customDomain !== '' ? 'PENDING_VERIFICATION' : 'NOT_CONFIGURED',
    'logo_url' => trim((string)($body['logo_url'] ?? '')),
    'primary_color' => trim((string)($body['primary_color'] ?? '#0b5cff')),
    'service_country' => 'BD',
    'currency' => 'BDT',
    'sms_brand_name' => trim((string)($body['sms_brand_name'] ?? $siteName)),
    'status' => 'ACTIVE',
    'features' => [
        'topup' => true,
        'bundle' => true,
        'mfs' => true,
        'tracking' => true,
        'worker' => (bool)($body['features']['worker'] ?? $body['worker_enabled'] ?? true),
        'telegram' => (bool)($body['features']['telegram'] ?? $body['telegram_enabled'] ?? false),
    ],
    'commission' => [
        'topup_per_1000' => $topupCommission,
        'bundle_per_1000' => $bundleCommission,
        'mfs_fee_mode' => 'OWNER_CONFIGURED',
    ],
    'created_at' => my_site_now_iso($now),
    'updated_at' => my_site_now_iso($now),
    'created_ip' => client_ip(),
];

$settings = [
    'tenant_id' => $tenantId,
    'sms_branding' => [
        'country' => 'BD',
        'site_name' => $tenant['sms_brand_name'],
        'my_sms_enabled' => false,
    ],
    'worker' => [
        'phase' => 'EXISTING_ZPAY_WORKER_APP',
        'link_mode' => 'QR_OR_TOKEN',
        'enabled' => $tenant['features']['worker'],
    ],
    'telegram' => [
        'enabled' => $tenant['features']['telegram'],
        'secret_storage' => 'PRIVATE_CONFIG_ONLY',
    ],
    'created_at' => my_site_now_iso($now),
];

$subscription = [
    'tenant_id' => $tenantId,
    'plan' => $plan,
    'status' => $subscriptionStatus,
    'starts_at' => my_site_now_iso($now),
    'expires_at' => $expiresAt,
    'created_at' => my_site_now_iso($now),
];

$ok = fb_put('TENANTS/' . $tenantId, $tenant)
    && fb_put('TENANT_SETTINGS/' . $tenantId, $settings)
    && fb_put('TENANT_SUBSCRIPTIONS/' . $tenantId . '/current', $subscription)
    && fb_put('TENANT_SLUGS/' . $siteSlug, ['tenant_id' => $tenantId, 'site_slug' => $siteSlug, 'created_at' => my_site_now_iso($now)]);

if ($ok && $customDomain !== '') {
    $ok = fb_put('TENANT_DOMAINS/' . hash('sha256', $customDomain), [
        'tenant_id' => $tenantId,
        'domain' => $customDomain,
        'status' => 'PENDING_VERIFICATION',
        'created_at' => my_site_now_iso($now),
    ]);
}

if (!$ok) {
    api_response(false, 'TENANT_CREATE_FAILED', 'Failed to create tenant', [], 500);
}

system_log('MY_SITE_TENANT_CREATED', $tenantId, 'My-Site tenant created', [
    'site_slug' => $siteSlug,
    'plan' => $plan,
]);

api_response(true, 'SUCCESS', 'Tenant created', [
    'tenant' => my_site_public_tenant($tenant),
    'subscription' => my_site_subscription_state($tenant),
]);
