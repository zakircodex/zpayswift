<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function my_site_now_iso(int $ts = null): string
{
    return date('c', $ts ?? time());
}

function my_site_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    if ($value === '') {
        $value = 'site-' . strtolower(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    return substr($value, 0, 64);
}

function my_site_make_tenant_id(): string
{
    return 'TENANT_' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function my_site_public_tenant(array $tenant): array
{
    return [
        'tenant_id' => (string)($tenant['tenant_id'] ?? ''),
        'owner_uid' => (string)($tenant['owner_uid'] ?? ''),
        'plan' => (string)($tenant['plan'] ?? 'FREE_TRIAL'),
        'subscription_status' => (string)($tenant['subscription_status'] ?? 'TRIALING'),
        'subscription_expires_at' => (string)($tenant['subscription_expires_at'] ?? ''),
        'site_name' => (string)($tenant['site_name'] ?? 'Z-Pay Swift Site'),
        'site_slug' => (string)($tenant['site_slug'] ?? ''),
        'free_url' => (string)($tenant['free_url'] ?? ''),
        'custom_domain' => (string)($tenant['custom_domain'] ?? ''),
        'domain_status' => (string)($tenant['domain_status'] ?? 'NOT_CONFIGURED'),
        'logo_url' => (string)($tenant['logo_url'] ?? ''),
        'primary_color' => (string)($tenant['primary_color'] ?? '#0b5cff'),
        'service_country' => 'BD',
        'currency' => 'BDT',
        'sms_brand_name' => (string)($tenant['sms_brand_name'] ?? ($tenant['site_name'] ?? 'Z-Pay Swift Site')),
        'status' => (string)($tenant['status'] ?? 'ACTIVE'),
        'features' => is_array($tenant['features'] ?? null) ? $tenant['features'] : [],
        'commission' => is_array($tenant['commission'] ?? null) ? $tenant['commission'] : [],
        'created_at' => $tenant['created_at'] ?? null,
        'updated_at' => $tenant['updated_at'] ?? null,
    ];
}

function my_site_subscription_state(array $tenant): array
{
    $status = strtoupper((string)($tenant['subscription_status'] ?? 'EXPIRED'));
    $siteStatus = strtoupper((string)($tenant['status'] ?? 'ACTIVE'));
    $expiresRaw = (string)($tenant['subscription_expires_at'] ?? '');
    $expiresTs = $expiresRaw !== '' ? strtotime($expiresRaw) : false;
    $expiredByDate = $expiresTs !== false && $expiresTs < time();
    $open = in_array($status, ['ACTIVE', 'TRIALING'], true) && $siteStatus === 'ACTIVE' && !$expiredByDate;

    $reason = 'OK';
    if ($siteStatus !== 'ACTIVE') {
        $open = false;
        $reason = 'SITE_NOT_ACTIVE';
    } elseif (!in_array($status, ['ACTIVE', 'TRIALING'], true)) {
        $reason = 'SUBSCRIPTION_' . $status;
    } elseif ($expiredByDate) {
        $open = false;
        $reason = 'SUBSCRIPTION_EXPIRED_DATE';
    }

    return [
        'open' => $open,
        'reason' => $reason,
        'subscription_status' => $expiredByDate ? 'EXPIRED' : $status,
        'status_label' => $open ? ($status === 'TRIALING' ? 'Trial Active' : 'Active') : 'Expired',
        'expires_at' => $expiresRaw,
        'days_left' => ($open && $expiresTs !== false) ? max(0, (int)ceil(($expiresTs - time()) / 86400)) : 0,
    ];
}

function my_site_find_tenant(array $query): ?array
{
    $tenantId = trim((string)($query['tenant_id'] ?? ''));
    $slug = trim((string)($query['slug'] ?? $query['site_slug'] ?? ''));
    $domain = strtolower(trim((string)($query['domain'] ?? '')));

    if ($tenantId !== '') {
        $tenant = fb_get('TENANTS/' . $tenantId);
        return is_array($tenant) ? $tenant : null;
    }

    if ($slug !== '') {
        $mapped = fb_get('TENANT_SLUGS/' . my_site_slugify($slug));
        $mappedId = is_array($mapped) ? (string)($mapped['tenant_id'] ?? '') : (string)$mapped;
        if ($mappedId !== '') {
            $tenant = fb_get('TENANTS/' . $mappedId);
            return is_array($tenant) ? $tenant : null;
        }
    }

    if ($domain !== '') {
        $hash = hash('sha256', $domain);
        $mapped = fb_get('TENANT_DOMAINS/' . $hash);
        $mappedId = is_array($mapped) ? (string)($mapped['tenant_id'] ?? '') : '';
        if ($mappedId !== '') {
            $tenant = fb_get('TENANTS/' . $mappedId);
            return is_array($tenant) ? $tenant : null;
        }
    }

    return null;
}
