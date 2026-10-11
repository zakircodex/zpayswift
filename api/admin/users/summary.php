<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('GET');
auth_require_admin_session();

$response = fb_request('GET', 'USERS', null, ['shallow' => 'true']);
if (empty($response['ok'])) {
    api_response(false, 'USER_SUMMARY_UNAVAILABLE', 'User summary could not be loaded', [], 503);
}

$rows = is_array($response['json'] ?? null) ? $response['json'] : [];
$userKeys = array_filter(
    array_keys($rows),
    static fn($key): bool => trim((string)$key) !== '' && !str_starts_with((string)$key, '_')
);

api_response(true, 'SUCCESS', 'User summary loaded', [
    'total_users' => count($userKeys),
]);
