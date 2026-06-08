<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('GET');
auth_require_admin_session();

$operator = normalize_operator($_GET['operator'] ?? '');
if (!is_valid_operator($operator)) {
    api_response(false, 'INVALID_OPERATOR', 'Invalid operator', [], 422);
}

$r = fb_get('OPERATOR_RUNTIME/' . $operator);
$p = fb_get('OPERATOR_PRIVATE/' . $operator);

if (!is_array($r)) {
    $r = [];
}
if (!is_array($p)) {
    $p = [];
}

api_response(true, 'SUCCESS', 'Operator loaded', [
    'operator' => $operator,
    'name' => (string)($r['name'] ?? $operator),
    'active' => (bool)($r['active'] ?? false),
    'dial_template' => (string)($r['dial_template'] ?? ''),
    'masked_template' => (string)($r['masked_template'] ?? ''),
    'requires_secret_pin' => (bool)($r['requires_secret_pin'] ?? true),
    'retailer_secret_pin_set' => trim((string)($p['retailer_secret_pin'] ?? '')) !== '',
    'retailer_secret_pin_masked' => trim((string)($p['retailer_secret_pin'] ?? '')) !== '' ? '********' : '',
    'updated_at' => (int)max((int)($r['updated_at'] ?? 0), (int)($p['updated_at'] ?? 0)),
]);
