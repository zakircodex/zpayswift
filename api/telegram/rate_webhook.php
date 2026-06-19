<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/rates.php';

function tg_rate_json(bool $ok, string $code, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tg_rate_secret(): string
{
    return defined('TELEGRAM_WEBHOOK_SECRET') ? trim((string)TELEGRAM_WEBHOOK_SECRET) : '';
}

function tg_rate_bot_token(): string
{
    return defined('TELEGRAM_BOT_TOKEN') ? trim((string)TELEGRAM_BOT_TOKEN) : '';
}

function tg_rate_allowed_chat_id(): string
{
    return defined('TELEGRAM_CHAT_ID') ? trim((string)TELEGRAM_CHAT_ID) : '';
}

function tg_rate_verify_secret(): void
{
    $expected = tg_rate_secret();
    if ($expected === '') {
        tg_rate_json(false, 'CONFIG_ERROR', 'Telegram webhook secret missing', [], 500);
    }

    $query = trim((string)($_GET['key'] ?? ''));
    $header = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
    if (
        ($query !== '' && hash_equals($expected, $query))
        || ($header !== '' && hash_equals($expected, $header))
    ) {
        return;
    }

    tg_rate_json(false, 'UNAUTHORIZED', 'Invalid Telegram webhook secret', [], 401);
}

function tg_rate_read_update(): array
{
    $raw = (string)($GLOBALS['TELEGRAM_UPDATE_RAW'] ?? '');
    if ($raw === '') {
        $raw = (string)file_get_contents('php://input');
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function tg_rate_api(string $method, array $payload): void
{
    $token = tg_rate_bot_token();
    if ($token === '' || !function_exists('curl_init')) {
        return;
    }

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . ltrim($method, '/'));
    if ($ch === false) {
        return;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => $payload,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function tg_rate_reply($chatId, string $message): void
{
    if ((string)$chatId === '') {
        return;
    }

    tg_rate_api('sendMessage', [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);
}

tg_rate_verify_secret();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tg_rate_json(true, 'OK', 'Telegram rate webhook is ready');
}

$update = tg_rate_read_update();
$message = is_array($update['message'] ?? null) ? $update['message'] : [];
$chatId = trim((string)($message['chat']['id'] ?? ''));
$fromId = trim((string)($message['from']['id'] ?? ''));
$allowedChat = tg_rate_allowed_chat_id();

if ($allowedChat === '' || ($chatId !== $allowedChat && $fromId !== $allowedChat)) {
    tg_rate_reply($chatId, 'Unauthorized Telegram account.');
    tg_rate_json(true, 'IGNORED', 'Unauthorized Telegram account ignored');
}

$text = trim((string)($message['text'] ?? ''));
if (!preg_match('/^\/rate(?:@\w+)?\s+([0-9]+(?:\.[0-9]{1,4})?)$/i', $text, $matches)) {
    tg_rate_reply($chatId, 'Usage: /rate 31.20');
    tg_rate_json(true, 'VALIDATION_ERROR', 'Invalid rate command');
}

$rate = round((float)$matches[1], 2);
$valid = zpay_validate_myr_to_bdt_rate($rate);
if (empty($valid['ok'])) {
    tg_rate_reply($chatId, 'Rate must be a number between 20 and 50.');
    tg_rate_json(true, (string)$valid['code'], (string)$valid['message']);
}

$res = zpay_save_myr_to_bdt_rate($rate, $fromId !== '' ? $fromId : $chatId, 'TELEGRAM');
if (empty($res['ok'])) {
    tg_rate_reply($chatId, 'Failed to update Ringgit rate. Please try again.');
    tg_rate_json(false, (string)($res['code'] ?? 'RATE_SAVE_FAILED'), (string)($res['message'] ?? 'Failed to update rate'), [], 500);
}

tg_rate_reply($chatId, 'Ringgit rate updated: 1 RM = ' . number_format($rate, 2, '.', '') . ' BDT');
tg_rate_json(true, 'SUCCESS', 'Ringgit rate updated', (array)($res['data'] ?? []));
