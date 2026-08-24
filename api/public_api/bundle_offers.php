<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/subadmin_api.php';
require_once dirname(__DIR__) . '/lib/bundle.php';

api_require_method('GET');

$auth = subapi_authenticate_request();

$uid = trim((string)($auth['uid'] ?? ''));
$user = (array)($auth['user'] ?? []);
$roleSettings = (array)($auth['role_settings'] ?? []);

if ($uid === '') {
    api_response(false, 'AUTH_ERROR', 'Invalid authenticated API user', [], 401);
}

$bundleEnabled = (bool)($roleSettings['bundle_enabled'] ?? false);
if (!$bundleEnabled) {
    api_response(false, 'BUNDLE_DISABLED', 'Bundle is disabled for this account', [], 403);
}

$operatorFilter = strtoupper(trim((string)($_GET['operator'] ?? '')));
$statusFilter = strtoupper(trim((string)($_GET['status'] ?? 'ACTIVE')));

$result = subapi_panel_bundle_offers(
    $uid,
    $operatorFilter,
    $statusFilter,
    trim((string)($_GET['cursor'] ?? '')),
    min(10, max(1, (int)($_GET['limit'] ?? 10)))
);

if (empty($result['ok'])) {
    $code = (string)($result['code'] ?? 'SERVER_ERROR');
    $http = in_array($code, ['ROLE_NOT_ALLOWED', 'ACCOUNT_INACTIVE', 'BUNDLE_DISABLED'], true) ? 403 : 500;
    api_response(false, $code, (string)($result['message'] ?? 'Unable to load bundle offers'), [], $http);
}

$data = (array)($result['data'] ?? []);
api_response(true, 'SUCCESS', 'Bundle offers loaded successfully', [
    'uid' => $uid,
    'total' => count((array)($data['items'] ?? [])),
    'items' => array_values((array)($data['items'] ?? [])),
    'pagination' => (array)($data['pagination'] ?? []),
]);
