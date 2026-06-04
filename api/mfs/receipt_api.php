<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mfs.php';

api_require_method('GET');

$token = trim((string)($_GET['t'] ?? $_GET['token'] ?? ''));
$receipt = function_exists('mfs_load_receipt_by_token') ? mfs_load_receipt_by_token($token) : [];

if (!$receipt) {
    api_response(false, 'NOT_FOUND', 'Receipt not found or token invalid', [], 404);
}

api_response(true, 'SUCCESS', 'Receipt loaded', mfs_public_receipt($receipt));
