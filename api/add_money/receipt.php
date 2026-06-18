<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$token = trim((string)($_GET['t'] ?? ''));
if ($token === '' || preg_match('/^[a-f0-9]{24,64}$/i', $token) !== 1) {
    http_response_code(404);
    exit('Not Found');
}

$row = fb_get('ADD_MONEY_RECEIPT_TOKENS/' . $token);
if (!is_array($row)) {
    http_response_code(404);
    exit('Not Found');
}

$relative = trim((string)($row['path'] ?? ''));
$mime = trim((string)($row['mime'] ?? 'application/octet-stream'));
if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
    http_response_code(404);
    exit('Not Found');
}

$file = dirname(__DIR__) . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
$base = realpath(dirname(__DIR__) . '/storage/add_money');
$real = realpath($file);

if ($base === false || $real === false || !str_starts_with($real, $base)) {
    http_response_code(404);
    exit('Not Found');
}

if (!is_file($real)) {
    http_response_code(404);
    exit('Not Found');
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($real));
readfile($real);
