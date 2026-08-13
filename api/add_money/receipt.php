<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/add_money.php';

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

function add_money_receipt_error_page(string $title, string $message, int $status): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');

    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $safeTitle . ' | Z-Pay Swift</title>'
        . '<style>html,body{margin:0;min-height:100%;background:#061a31;color:#fff;font-family:Arial,sans-serif}'
        . 'body{display:grid;place-items:center;padding:24px;box-sizing:border-box}.card{width:min(100%,440px);padding:32px 24px;'
        . 'box-sizing:border-box;text-align:center;background:#123b6b;border:1px solid #36709a;border-radius:24px}'
        . 'h1{margin:0 0 12px;font-size:28px}p{margin:0 0 24px;color:#c8d7e8;line-height:1.55}'
        . 'a{display:block;padding:15px 20px;border-radius:16px;background:#2ee489;color:#061a31;font-weight:700;text-decoration:none}</style>'
        . '</head><body><main class="card"><h1>' . $safeTitle . '</h1><p>' . $safeMessage . '</p>'
        . '<a href="/user">Open Z-Pay Swift</a></main></body></html>';
    exit;
}

$token = trim((string)($_GET['t'] ?? ''));
if ($token === '' || preg_match('/^[a-f0-9]{24,64}$/i', $token) !== 1) {
    add_money_receipt_error_page('Receipt Not Found', 'This receipt link is invalid or unavailable.', 404);
}

$row = fb_get('ADD_MONEY_RECEIPT_TOKENS/' . $token);
if (!is_array($row)) {
    add_money_receipt_error_page('Receipt Not Found', 'This receipt link is invalid or unavailable.', 404);
}

$access = add_money_receipt_token_access($row);
if (empty($access['ok'])) {
    if (($access['code'] ?? '') === 'RECEIPT_TOKEN_EXPIRED') {
        add_money_receipt_error_page('Receipt Link Expired', 'This receipt link has expired for security reasons.', 410);
    }
    add_money_receipt_error_page('Receipt Unavailable', 'This receipt link is no longer available.', 410);
}

if (empty($access['legacy'])) {
    $requestId = trim((string)($row['request_id'] ?? ''));
    $request = $requestId !== '' ? fb_get('ADD_MONEY_REQUESTS/' . $requestId) : null;
    if (!is_array($request) || !add_money_receipt_token_matches_request($token, $row, $request)) {
        add_money_receipt_error_page('Receipt Not Found', 'This receipt link is invalid or unavailable.', 404);
    }
}

$relative = trim((string)($row['path'] ?? ''));
$mime = trim((string)($row['mime'] ?? 'application/octet-stream'));
if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
    add_money_receipt_error_page('Receipt Not Found', 'This receipt link is invalid or unavailable.', 404);
}

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
if (!in_array($mime, $allowedMimes, true)) {
    add_money_receipt_error_page('Receipt Not Found', 'This receipt link is invalid or unavailable.', 404);
}

$file = dirname(__DIR__) . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
$base = realpath(dirname(__DIR__) . '/storage/add_money');
$real = realpath($file);

if ($base === false || $real === false || !str_starts_with($real, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
    add_money_receipt_error_page('Receipt Not Found', 'This receipt link is invalid or unavailable.', 404);
}

if (!is_file($real)) {
    add_money_receipt_error_page('Receipt Not Found', 'This receipt link is invalid or unavailable.', 404);
}

$actualMime = (new finfo(FILEINFO_MIME_TYPE))->file($real);
if (!is_string($actualMime) || !hash_equals($mime, $actualMime)) {
    add_money_receipt_error_page('Receipt Not Found', 'This receipt link is invalid or unavailable.', 404);
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
readfile($real);
