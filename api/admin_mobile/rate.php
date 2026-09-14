<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_mobile.php';
require_once dirname(__DIR__) . '/lib/rates.php';
require_once dirname(__DIR__) . '/lib/mfs_admin_settings.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
if (!in_array($method, ['GET', 'POST'], true)) {
    api_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method.', [], 405);
}
$auth = admin_mobile_require_session(true);

if ($method === 'GET') {
    api_response(true, 'ADMIN_MOBILE_RATE_OK', 'Current rate loaded.', mfs_admin_rate_state());
}

$body = api_read_json_body();
$rate = is_numeric($body['rate_myr_bdt'] ?? null) ? round((float)$body['rate_myr_bdt'], 2) : 0.0;
$result = zpay_save_myr_to_bdt_rate($rate, (string)($auth['user']['uid'] ?? ''), 'ADMIN_MOBILE');
$code = (string)($result['code'] ?? 'RATE_SAVE_FAILED');
$status = !empty($result['ok']) ? 200 : (str_starts_with($code, 'INVALID_') ? 422 : 500);
api_response(!empty($result['ok']), $code, (string)($result['message'] ?? 'Rate could not be updated.'), (array)($result['data'] ?? []), $status);
