<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/adsterra_web_ads.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$verified = znews_adsterra_web_verify_permit(trim((string)($_GET['permit'] ?? '')));
if (empty($verified['ok']) || !is_array($verified['placement'] ?? null)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not Found');
}

$placement = (array)$verified['placement'];
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header(
    "Content-Security-Policy: default-src 'none'; base-uri 'none'; object-src 'none'; "
    . "frame-ancestors 'self'; form-action 'none'; script-src 'unsafe-inline' https:; "
    . "style-src 'unsafe-inline' https:; img-src https: data:; connect-src https:; "
    . "font-src https: data:; frame-src https:"
);

echo znews_adsterra_web_frame_html($placement);
