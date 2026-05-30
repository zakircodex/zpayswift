<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('GET');
auth_require_admin_session();

$runtime = fb_get('OPERATOR_RUNTIME');
$private = fb_get('OPERATOR_PRIVATE');

if (!is_array($runtime)) {
    $runtime = [];
}
if (!is_array($private)) {
    $private = [];
}

$items = [];

foreach (['GP', 'ROBI', 'BL', 'AIRTEL', 'TT'] as $op) {
    $r = is_array($runtime[$op] ?? null) ? $runtime[$op] : [];
    $p = is_array($private[$op] ?? null) ? $private[$op] : [];

    $items[] = [
        'operator' => $op,
        'name' => (string)($r['name'] ?? $op),
        'active' => (bool)($r['active'] ?? false),
        'dial_template' => (string)($r['dial_template'] ?? ''),
        'masked_template' => (string)($r['masked_template'] ?? ''),
        'requires_secret_pin' => (bool)($r['requires_secret_pin'] ?? true),
        'retailer_secret_pin' => (string)($p['retailer_secret_pin'] ?? ''),
        'updated_at' => (int)max((int)($r['updated_at'] ?? 0), (int)($p['updated_at'] ?? 0)),
    ];
}

api_response(true, 'SUCCESS', 'Operator list loaded', [
    'items' => $items,
]);