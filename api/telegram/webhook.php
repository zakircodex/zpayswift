<?php
/**
 * ZawTopup / Z-Pay Swift - Telegram Webhook Router
 *
 * Use this single URL for the Telegram bot webhook:
 * /zawtopup/api/telegram/webhook.php?key=YOUR_TELEGRAM_WEBHOOK_SECRET
 *
 * Routes:
 * - bndl|... callbacks go to bundle_webhook.php
 * - MFS_... callbacks go to mfs_webhook.php
 * - mfs|... callbacks go to mfs_webhook.php
 * - normal Telegram messages go to mfs_webhook.php for sender last digit replies
 */

declare(strict_types=1);

function tg_router_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tg_router_response(true, 'OK', 'Telegram router webhook is ready', [
        'routes' => [
            'bundle' => 'callback_data starts with bndl|',
            'mfs' => 'callback_data starts with MFS_ or mfs|, plus normal messages',
        ],
        'time' => date('Y-m-d H:i:s'),
    ], 200);
}

$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    tg_router_response(true, 'IGNORED', 'Empty Telegram update body', [], 200);
}

$update = json_decode($raw, true);
if (!is_array($update)) {
    tg_router_response(true, 'IGNORED', 'Invalid Telegram update JSON', [], 200);
}

$callback = $update['callback_query'] ?? null;
if (is_array($callback)) {
    $data = trim((string)($callback['data'] ?? ''));

    if (strpos($data, 'bndl|') === 0) {
        require __DIR__ . '/bundle_webhook.php';
        exit;
    }

    if (strpos($data, 'MFS_') === 0 || strpos($data, 'mfs|') === 0) {
        require __DIR__ . '/mfs_webhook.php';
        exit;
    }

    tg_router_response(true, 'IGNORED', 'Unknown Telegram callback route', [
        'callback_data' => $data,
    ], 200);
}

if (isset($update['message']) && is_array($update['message'])) {
    require __DIR__ . '/mfs_webhook.php';
    exit;
}

tg_router_response(true, 'IGNORED', 'Unsupported Telegram update type', [], 200);
