<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_helpers.php';

api_require_method('GET');

$tenant = my_site_find_tenant($_GET);
if (!$tenant) {
    api_response(false, 'TENANT_NOT_FOUND', 'Tenant not found', [
        'open' => false,
        'reason' => 'TENANT_NOT_FOUND',
    ], 404);
}

$subscription = my_site_subscription_state($tenant);
api_response(true, 'SUCCESS', 'Subscription checked', [
    'tenant_id' => (string)($tenant['tenant_id'] ?? ''),
    'site_name' => (string)($tenant['site_name'] ?? ''),
    'subscription' => $subscription,
]);
