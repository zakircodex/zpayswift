<?php
/**
 * Z-Pay Swift - Telegram webhook for normal topup fallback actions.
 *
 * Button callback format:
 * - topup|p|REQUEST_ID|SIGNATURE = PROCESSING
 * - topup|s|REQUEST_ID|SIGNATURE = SUCCESS
 * - topup|f|REQUEST_ID|SIGNATURE = FAILED
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/topup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function tg_topup_json(bool $ok, string $code, string $message, array $data = [], int $http = 200): void
{
    http_response_code($http);
    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tg_topup_secret(): string
{
    return defined('TELEGRAM_WEBHOOK_SECRET') ? trim((string)TELEGRAM_WEBHOOK_SECRET) : '';
}

function tg_topup_require_config(): void
{
    $missing = [];

    if (topup_telegram_bot_token() === '') {
        $missing[] = 'TELEGRAM_BOT_TOKEN';
    }

    if (topup_telegram_chat_id() === '') {
        $missing[] = 'TELEGRAM_CHAT_ID';
    }

    if (tg_topup_secret() === '') {
        $missing[] = 'TELEGRAM_WEBHOOK_SECRET';
    }

    if (topup_telegram_action_key() === '') {
        $missing[] = 'TELEGRAM_TOPUP_ACTION_KEY_OR_APP_KEY';
    }

    if ($missing) {
        tg_topup_json(false, 'CONFIG_ERROR', 'Telegram topup config missing', [
            'missing' => $missing,
        ], 500);
    }
}

function tg_topup_verify_secret(): void
{
    $expected = tg_topup_secret();
    $querySecret = trim((string)($_GET['key'] ?? ''));
    $headerSecret = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));

    if ($expected === '') {
        tg_topup_json(false, 'CONFIG_ERROR', 'TELEGRAM_WEBHOOK_SECRET missing', [], 500);
    }

    if ($querySecret !== '' && hash_equals($expected, $querySecret)) {
        return;
    }

    if ($headerSecret !== '' && hash_equals($expected, $headerSecret)) {
        return;
    }

    tg_topup_json(false, 'FORBIDDEN', 'Invalid Telegram webhook secret', [], 403);
}

function tg_topup_read_update(): array
{
    $raw = isset($GLOBALS['TELEGRAM_UPDATE_RAW'])
        ? (string)$GLOBALS['TELEGRAM_UPDATE_RAW']
        : file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        tg_topup_json(true, 'IGNORED', 'Empty Telegram update body', [], 200);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        tg_topup_json(true, 'IGNORED', 'Invalid Telegram update JSON', [], 200);
    }

    return $json;
}

function tg_topup_answer(string $callbackId, string $text, bool $alert = false): void
{
    if ($callbackId === '') {
        return;
    }

    topup_telegram_api('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $alert,
    ]);
}

function tg_topup_edit($chatId, $messageId, string $text, array $replyMarkup = []): void
{
    if ((string)$chatId === '' || (string)$messageId === '') {
        return;
    }

    topup_telegram_api('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => $replyMarkup,
    ]);
}

function tg_topup_callback_chat_allowed(array $callback): bool
{
    $allowedChatId = topup_telegram_chat_id();
    if ($allowedChatId === '') {
        return false;
    }

    $messageChatId = trim((string)($callback['message']['chat']['id'] ?? ''));
    $fromId = trim((string)($callback['from']['id'] ?? ''));

    return ($messageChatId !== '' && hash_equals($allowedChatId, $messageChatId))
        || ($fromId !== '' && hash_equals($allowedChatId, $fromId));
}

function tg_topup_done_status(array $row): string
{
    return strtoupper(trim((string)($row['status'] ?? 'DONE')));
}

tg_topup_require_config();
tg_topup_verify_secret();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tg_topup_json(true, 'OK', 'Telegram topup webhook is ready', [], 200);
}

$update = tg_topup_read_update();
$callback = $update['callback_query'] ?? null;
if (!is_array($callback)) {
    tg_topup_json(true, 'IGNORED', 'Topup webhook only handles callback queries', [], 200);
}

$callbackId = (string)($callback['id'] ?? '');
$chatId = (string)($callback['message']['chat']['id'] ?? '');
$messageId = (string)($callback['message']['message_id'] ?? '');

if (!tg_topup_callback_chat_allowed($callback)) {
    tg_topup_answer($callbackId, 'Unauthorized Telegram account', true);
    tg_topup_json(true, 'IGNORED', 'Unauthorized Telegram chat ignored', [], 200);
}

$parsed = topup_telegram_parse_callback_data((string)($callback['data'] ?? ''));
if (!($parsed['ok'] ?? false)) {
    if (function_exists('system_log')) {
        system_log('TOPUP_CALLBACK_INVALID', '', 'Invalid topup Telegram callback', [
            'reason' => (string)($parsed['data']['reason'] ?? $parsed['code'] ?? 'unknown'),
        ]);
    }

    tg_topup_answer($callbackId, 'This topup button is invalid or expired.', true);
    tg_topup_json(true, 'INVALID_CALLBACK', 'Invalid topup callback data', [], 200);
}

$requestId = (string)($parsed['data']['request_id'] ?? '');
$action = (string)($parsed['data']['action'] ?? '');
$row = topup_find_request($requestId);

if (!is_array($row)) {
    tg_topup_answer($callbackId, 'Topup request not found', true);
    tg_topup_json(true, 'NOT_FOUND', 'Topup request not found', [
        'request_id' => $requestId,
    ], 200);
}

$bucket = (string)($row['_bucket'] ?? '');
if ($bucket === 'DONE') {
    $status = tg_topup_done_status($row);
    $msg = (string)($row['final_message'] ?? $row['message'] ?? 'Topup request already completed');
    tg_topup_answer($callbackId, 'Already completed', true);
    tg_topup_edit($chatId, $messageId, topup_telegram_build_status_message($row, $status, $msg), ['inline_keyboard' => []]);
    tg_topup_json(true, 'ALREADY_DONE', 'Topup request already completed', [
        'request_id' => $requestId,
        'status' => $status,
    ], 200);
}

if ($action === 'PROCESSING') {
    $message = 'Topup request is processing manually from Telegram';
    $res = topup_mark_processing($requestId, $message);
    $latest = topup_find_request($requestId);

    if (!($res['ok'] ?? false)) {
        if (($res['code'] ?? '') === 'ALREADY_DONE' && is_array($latest)) {
            $status = tg_topup_done_status($latest);
            $msg = (string)($latest['final_message'] ?? $latest['message'] ?? 'Topup request already completed');
            tg_topup_answer($callbackId, 'Already completed', true);
            tg_topup_edit($chatId, $messageId, topup_telegram_build_status_message($latest, $status, $msg), ['inline_keyboard' => []]);
            tg_topup_json(true, 'ALREADY_DONE', 'Topup request already completed', [
                'request_id' => $requestId,
                'status' => $status,
            ], 200);
        }

        tg_topup_answer($callbackId, (string)($res['message'] ?? 'Unable to mark processing'), true);
        tg_topup_json(true, (string)($res['code'] ?? 'SERVER_ERROR'), (string)($res['message'] ?? 'Unable to mark processing'), [
            'request_id' => $requestId,
        ], 200);
    }

    $latest = is_array($latest) ? $latest : $row;
    tg_topup_answer($callbackId, 'Marked PROCESSING');
    tg_topup_edit($chatId, $messageId, topup_telegram_build_status_message($latest, 'PROCESSING', $message), topup_telegram_keyboard_after_processing($requestId));
    tg_topup_json(true, 'SUCCESS', 'Topup marked processing', [
        'request_id' => $requestId,
        'status' => 'PROCESSING',
    ], 200);
}

if ($action === 'SUCCESS') {
    $message = 'Topup completed manually from Telegram';
    $res = topup_mark_success($requestId, $message);
    $latest = topup_find_request($requestId);

    if (!($res['ok'] ?? false) && ($res['code'] ?? '') !== 'ALREADY_DONE') {
        tg_topup_answer($callbackId, (string)($res['message'] ?? 'Unable to mark success'), true);
        tg_topup_json(true, (string)($res['code'] ?? 'SERVER_ERROR'), (string)($res['message'] ?? 'Unable to mark success'), [
            'request_id' => $requestId,
        ], 200);
    }

    $latest = is_array($latest) ? $latest : $row;
    $status = tg_topup_done_status($latest);
    $msg = (string)($latest['final_message'] ?? $message);
    if (($res['code'] ?? '') === 'ALREADY_DONE') {
        tg_topup_answer($callbackId, 'Already completed', true);
        tg_topup_edit($chatId, $messageId, topup_telegram_build_status_message($latest, $status, $msg), ['inline_keyboard' => []]);
        tg_topup_json(true, 'ALREADY_DONE', 'Topup request already completed', [
            'request_id' => $requestId,
            'status' => $status,
        ], 200);
    }

    tg_topup_answer($callbackId, 'Marked SUCCESS');
    tg_topup_edit($chatId, $messageId, topup_telegram_build_status_message($latest, $status === 'FAILED' ? 'FAILED' : 'SUCCESS', $msg), ['inline_keyboard' => []]);
    tg_topup_json(true, 'SUCCESS', 'Topup marked success', [
        'request_id' => $requestId,
        'status' => $status,
    ], 200);
}

if ($action === 'FAILED') {
    $message = 'Topup failed manually from Telegram';
    $res = topup_mark_failed($requestId, $message);
    $latest = topup_find_request($requestId);

    if (!($res['ok'] ?? false) && ($res['code'] ?? '') !== 'ALREADY_DONE') {
        tg_topup_answer($callbackId, (string)($res['message'] ?? 'Unable to mark failed'), true);
        tg_topup_json(true, (string)($res['code'] ?? 'SERVER_ERROR'), (string)($res['message'] ?? 'Unable to mark failed'), [
            'request_id' => $requestId,
        ], 200);
    }

    $latest = is_array($latest) ? $latest : $row;
    $status = tg_topup_done_status($latest);
    $msg = (string)($latest['final_message'] ?? $message);
    if (($res['code'] ?? '') === 'ALREADY_DONE') {
        tg_topup_answer($callbackId, 'Already completed', true);
        tg_topup_edit($chatId, $messageId, topup_telegram_build_status_message($latest, $status, $msg), ['inline_keyboard' => []]);
        tg_topup_json(true, 'ALREADY_DONE', 'Topup request already completed', [
            'request_id' => $requestId,
            'status' => $status,
        ], 200);
    }

    tg_topup_answer($callbackId, 'Marked FAILED');
    tg_topup_edit($chatId, $messageId, topup_telegram_build_status_message($latest, $status === 'SUCCESS' ? 'SUCCESS' : 'FAILED', $msg), ['inline_keyboard' => []]);
    tg_topup_json(true, 'SUCCESS', 'Topup marked failed', [
        'request_id' => $requestId,
        'status' => $status,
    ], 200);
}

tg_topup_answer($callbackId, 'Unsupported topup action', true);
tg_topup_json(true, 'IGNORED', 'Unsupported topup callback action', [
    'request_id' => $requestId,
    'action' => $action,
], 200);
