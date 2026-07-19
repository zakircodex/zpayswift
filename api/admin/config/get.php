<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('GET');
$auth = auth_require_admin_session(true);

$row = fb_get('APP_CONFIG');
if (!is_array($row)) {
    $row = [];
}

api_response(true, 'SUCCESS', 'App config loaded', [
    'topup_enabled' => (bool)($row['topup_enabled'] ?? true),
    'bundle_enabled' => (bool)($row['bundle_enabled'] ?? true),
    'maintenance_mode' => (bool)($row['maintenance_mode'] ?? false),
    'privacy_policy_url' => trim((string)($row['privacy_policy_url'] ?? '')),
    'terms_conditions_url' => trim((string)($row['terms_conditions_url'] ?? '')),

    'min_topup_amount' => (float)($row['min_topup_amount'] ?? 0),
    'max_topup_amount' => (float)($row['max_topup_amount'] ?? 0),

    'min_bundle_amount' => (float)($row['min_bundle_amount'] ?? 0),
    'max_bundle_amount' => (float)($row['max_bundle_amount'] ?? 0),

    'updated_at' => (int)($row['updated_at'] ?? 0),
    'updated_by_admin_uid' => (string)($row['updated_by_admin_uid'] ?? ''),
]);
