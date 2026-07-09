<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/support.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function tg_support_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
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

function tg_support_secret(): string
{
    return defined('TELEGRAM_WEBHOOK_SECRET') ? trim((string)TELEGRAM_WEBHOOK_SECRET) : '';
}

function tg_support_verify_secret(): void
{
    $expected = tg_support_secret();
    $querySecret = trim((string)($_GET['key'] ?? ''));
    $headerSecret = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
    if ($expected === '') {
        tg_support_response(false, 'CONFIG_ERROR', 'TELEGRAM_WEBHOOK_SECRET missing', [], 500);
    }
    if (($querySecret !== '' && hash_equals($expected, $querySecret))
        || ($headerSecret !== '' && hash_equals($expected, $headerSecret))) {
        return;
    }
    tg_support_response(false, 'FORBIDDEN', 'Invalid Telegram webhook secret', [], 403);
}

function tg_support_api(string $method, array $payload): void
{
    if (TELEGRAM_BOT_TOKEN === '') {
        return;
    }
    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

tg_support_verify_secret();

$raw = isset($GLOBALS['TELEGRAM_UPDATE_RAW'])
    ? (string)$GLOBALS['TELEGRAM_UPDATE_RAW']
    : (string)file_get_contents('php://input');
$update = trim($raw) !== '' ? json_decode($raw, true) : null;
if (!is_array($update)) {
    tg_support_response(true, 'IGNORED', 'Invalid Telegram update', [], 200);
}

$callback = $update['callback_query'] ?? null;
if (!is_array($callback)) {
    tg_support_response(true, 'IGNORED', 'Unsupported Telegram update', [], 200);
}

$callbackId = (string)($callback['id'] ?? '');
$parsed = support_telegram_parse_callback_data((string)($callback['data'] ?? ''));
if ($parsed === []) {
    if ($callbackId !== '') {
        tg_support_api('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => 'Invalid support action',
            'show_alert' => false,
        ]);
    }
    tg_support_response(true, 'IGNORED', 'Invalid support callback', [], 200);
}

$action = (string)$parsed['action'];
$ticketId = (string)$parsed['ticket_id'];
$status = '';
$answer = 'Ticket loaded';
if ($action === 'p') {
    $status = 'PENDING';
    $answer = 'Ticket marked pending';
} elseif ($action === 'c') {
    $status = 'CLOSED';
    $answer = 'Ticket closed';
} elseif ($action !== 'v') {
    tg_support_response(true, 'IGNORED', 'Unsupported support action', [], 200);
}

$ticket = support_read_ticket($ticketId);
$result = $ticket === []
    ? ['ok' => false, 'message' => 'Support ticket was not found.']
    : ($status === ''
        ? (['ok' => true] + support_details_payload($ticket))
        : support_admin_set_status($ticketId, $status, ['uid' => 'TELEGRAM']));

if ($callbackId !== '') {
    tg_support_api('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => !empty($result['ok']) ? $answer : (string)($result['message'] ?? 'Action failed'),
        'show_alert' => false,
    ]);
}

tg_support_response(true, 'SUPPORT_CALLBACK_OK', 'Support callback handled.', [
    'ticket_id' => $ticketId,
    'action' => $action,
]);
