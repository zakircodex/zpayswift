<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_helpers.php';

api_require_method('GET');

$tenant = my_site_find_tenant($_GET);
if (!$tenant) {
    api_response(false, 'TENANT_NOT_FOUND', 'Tenant not found', [], 404);
}

api_response(true, 'SUCCESS', 'Tenant loaded', [
    'tenant' => my_site_public_tenant($tenant),
    'subscription' => my_site_subscription_state($tenant),
]);
