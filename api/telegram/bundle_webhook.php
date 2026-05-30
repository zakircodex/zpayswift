<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/bundle.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/*
|--------------------------------------------------------------------------
| Telegram Bundle Webhook
|--------------------------------------------------------------------------
| Webhook URL example:
| https://zpayswift.com/zawtopup/api/telegram/bundle_webhook.php?key=webhook_zpayswift_zakir_atik_123
|
| Button callback format:
| bndl|s|REQUEST_ID|SIGNATURE  = SUCCESS
| bndl|f|REQUEST_ID|SIGNATURE  = FAILED
|--------------------------------------------------------------------------
*/

function tg_bundle_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
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

function tg_bundle_bot_token(): string
{
    return defined('TELEGRAM_BOT_TOKEN') ? trim((string)TELEGRAM_BOT_TOKEN) : '';
}

function tg_bundle_chat_id(): string
{
    return defined('TELEGRAM_CHAT_ID') ? trim((string)TELEGRAM_CHAT_ID) : '';
}

function tg_bundle_webhook_secret(): string
{
    return defined('TELEGRAM_WEBHOOK_SECRET') ? trim((string)TELEGRAM_WEBHOOK_SECRET) : '';
}

function tg_bundle_action_key(): string
{
    if (defined('TELEGRAM_BUNDLE_ACTION_KEY')) {
        return trim((string)TELEGRAM_BUNDLE_ACTION_KEY);
    }

    return defined('APP_KEY') ? trim((string)APP_KEY) : '';
}

function tg_bundle_require_config(): void
{
    $missing = [];

    if (tg_bundle_bot_token() === '') {
        $missing[] = 'TELEGRAM_BOT_TOKEN';
    }

    if (tg_bundle_chat_id() === '') {
        $missing[] = 'TELEGRAM_CHAT_ID';
    }

    if (tg_bundle_webhook_secret() === '') {
        $missing[] = 'TELEGRAM_WEBHOOK_SECRET';
    }

    if (tg_bundle_action_key() === '') {
        $missing[] = 'TELEGRAM_BUNDLE_ACTION_KEY';
    }

    if ($missing) {
        tg_bundle_response(false, 'CONFIG_ERROR', 'Telegram config missing', [
            'missing' => $missing,
        ], 500);
    }
}

function tg_bundle_verify_telegram_secret(): void
{
    $expected = tg_bundle_webhook_secret();

    /*
     * Supported security methods:
     * 1) URL query key:
     *    bundle_webhook.php?key=YOUR_SECRET
     *
     * 2) Telegram secret_token header:
     *    X-Telegram-Bot-Api-Secret-Token
     */
    $querySecret = trim((string)($_GET['key'] ?? ''));
    $headerSecret = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));

    if ($expected === '') {
        tg_bundle_response(false, 'CONFIG_ERROR', 'TELEGRAM_WEBHOOK_SECRET is missing', [], 500);
    }

    if ($querySecret !== '' && hash_equals($expected, $querySecret)) {
        return;
    }

    if ($headerSecret !== '' && hash_equals($expected, $headerSecret)) {
        return;
    }

    tg_bundle_response(false, 'FORBIDDEN', 'Invalid Telegram webhook secret', [
        'has_query_key' => $querySecret !== '',
        'has_header_secret' => $headerSecret !== '',
    ], 403);
}

function tg_bundle_read_update(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        tg_bundle_response(true, 'IGNORED', 'Empty Telegram update body', [], 200);
    }

    $json = json_decode($raw, true);

    if (!is_array($json)) {
        tg_bundle_response(true, 'IGNORED', 'Invalid Telegram update JSON', [], 200);
    }

    return $json;
}

function tg_bundle_api(string $method, array $payload): array
{
    $token = tg_bundle_bot_token();
    $url = 'https://api.telegram.org/bot' . $token . '/' . ltrim($method, '/');

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = is_string($raw) ? json_decode($raw, true) : null;

    return [
        'ok' => $http >= 200 && $http < 300 && is_array($json) && !empty($json['ok']),
        'http' => $http,
        'json' => is_array($json) ? $json : null,
        'error' => $err,
        'raw' => is_string($raw) ? substr($raw, 0, 800) : '',
    ];
}

function tg_bundle_answer_callback(string $callbackId, string $text, bool $alert = false): void
{
    if ($callbackId === '') {
        return;
    }

    tg_bundle_api('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $alert,
    ]);
}

function tg_bundle_h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tg_bundle_money($value): string
{
    return number_format((float)$value, 2, '.', '');
}

function tg_bundle_short_time(?int $ts = null): string
{
    $ts = $ts ?: (function_exists('now_ts') ? (int)now_ts() : time());
    return date('Y-m-d H:i:s', $ts);
}

function tg_bundle_signature(string $requestId, string $actionCode): string
{
    $requestId = trim($requestId);
    $actionCode = strtolower(trim($actionCode));
    $key = tg_bundle_action_key();

    return substr(hash_hmac('sha256', $actionCode . '|' . $requestId, $key), 0, 16);
}

function tg_bundle_verify_signature(string $requestId, string $actionCode, string $sig): bool
{
    $expected = tg_bundle_signature($requestId, $actionCode);
    return $sig !== '' && hash_equals($expected, $sig);
}

function tg_bundle_parse_callback_data(string $data): array
{
    $data = trim($data);
    $parts = explode('|', $data);

    if (count($parts) !== 4 || $parts[0] !== 'bndl') {
        return [
            'ok' => false,
            'message' => 'Invalid callback data',
        ];
    }

    $actionCode = strtolower(trim($parts[1]));
    $requestId = trim($parts[2]);
    $sig = trim($parts[3]);

    if (!in_array($actionCode, ['s', 'f'], true)) {
        return [
            'ok' => false,
            'message' => 'Invalid bundle action',
        ];
    }

    if (!preg_match('/^[A-Za-z0-9_-]{3,100}$/', $requestId)) {
        return [
            'ok' => false,
            'message' => 'Invalid request id',
        ];
    }

    if (!tg_bundle_verify_signature($requestId, $actionCode, $sig)) {
        return [
            'ok' => false,
            'message' => 'Invalid action signature',
        ];
    }

    return [
        'ok' => true,
        'action_code' => $actionCode,
        'action' => $actionCode === 's' ? 'success' : 'failed',
        'request_id' => $requestId,
    ];
}

function tg_bundle_callback_chat_allowed(array $callback): bool
{
    $allowedChatId = tg_bundle_chat_id();

    if ($allowedChatId === '') {
        return false;
    }

    $messageChatId = trim((string)($callback['message']['chat']['id'] ?? ''));
    $fromId = trim((string)($callback['from']['id'] ?? ''));

    if ($messageChatId !== '' && hash_equals($allowedChatId, $messageChatId)) {
        return true;
    }

    if ($fromId !== '' && hash_equals($allowedChatId, $fromId)) {
        return true;
    }

    return false;
}

function tg_bundle_load_any_request(string $requestId): array
{
    $requestId = trim($requestId);

    if ($requestId === '') {
        return [];
    }

    $pending = fb_get('BUNDLE_REQUESTS/PENDING/' . $requestId);
    if (is_array($pending)) {
        $pending['_bucket'] = 'PENDING';
        $pending['request_id'] = (string)($pending['request_id'] ?? $requestId);
        return $pending;
    }

    $done = fb_get('BUNDLE_REQUESTS/DONE/' . $requestId);
    if (is_array($done)) {
        $done['_bucket'] = 'DONE';
        $done['request_id'] = (string)($done['request_id'] ?? $requestId);
        return $done;
    }

    return [];
}

function tg_bundle_status_text(array $row, string $status, string $message): string
{
    $requestId = (string)($row['request_id'] ?? '-');
    $uid = (string)($row['uid'] ?? '-');
    $userPhone = (string)($row['user_phone'] ?? $row['phone'] ?? '-');
    $bundleNumber = (string)($row['bundle_number'] ?? $row['topup_number'] ?? $row['number'] ?? '-');
    $operator = (string)($row['operator'] ?? '-');
    $bundleName = (string)($row['bundle_name'] ?? $row['name'] ?? '-');
    $offerId = (string)($row['offer_id'] ?? '-');

    $price = (float)($row['price_amount'] ?? $row['offer_price'] ?? $row['amount'] ?? 0);
    $userCommission = (float)($row['user_commission'] ?? $row['customer_commission'] ?? $row['user_discount'] ?? 0);
    $userPay = (float)($row['you_pay'] ?? $row['payable_amount'] ?? $row['wallet_hold_amount'] ?? $row['held_amount'] ?? $price);

    $upperStatus = strtoupper($status);
    $icon = $upperStatus === 'SUCCESS' ? '✅' : '❌';
    $title = $upperStatus === 'SUCCESS' ? 'Bundle Request SUCCESS' : 'Bundle Request FAILED';

    return
        $icon . ' <b>' . tg_bundle_h($title) . '</b>' . "\n\n" .

        '<b>Request ID:</b>' . "\n" .
        '<code>' . tg_bundle_h($requestId) . '</code>' . "\n" .
        '<b>UID:</b> <code>' . tg_bundle_h($uid) . '</code>' . "\n" .
        '<b>User Phone:</b> <code>' . tg_bundle_h($userPhone) . '</code>' . "\n\n" .

        '📞 <b>Bundle Number:</b>' . "\n" .
        '<code>' . tg_bundle_h($bundleNumber) . '</code>' . "\n\n" .

        '<b>Operator:</b> <b>' . tg_bundle_h($operator) . '</b>' . "\n" .
        '<b>Bundle:</b> ' . tg_bundle_h($bundleName) . "\n" .
        '<b>Offer ID:</b>' . "\n" .
        '<code>' . tg_bundle_h($offerId) . '</code>' . "\n\n" .

        '<b>Price:</b> BDT ' . tg_bundle_money($price) . "\n" .
        '<b>User Commission:</b> BDT ' . tg_bundle_money($userCommission) . "\n" .
        '<b>User Pay:</b> BDT ' . tg_bundle_money($userPay) . "\n\n" .

        '<b>Status:</b> <b>' . tg_bundle_h($upperStatus) . '</b>' . "\n" .
        '<b>Message:</b> ' . tg_bundle_h($message) . "\n" .
        '<b>Updated:</b> ' . tg_bundle_h(tg_bundle_short_time());
}

function tg_bundle_edit_callback_message(array $callback, string $text): void
{
    $messageId = $callback['message']['message_id'] ?? null;
    $chatId = $callback['message']['chat']['id'] ?? null;
    $inlineMessageId = $callback['inline_message_id'] ?? null;

    $payload = [
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => [
            'inline_keyboard' => [],
        ],
    ];

    if ($inlineMessageId) {
        $payload['inline_message_id'] = $inlineMessageId;
    } elseif ($chatId && $messageId) {
        $payload['chat_id'] = $chatId;
        $payload['message_id'] = $messageId;
    } else {
        return;
    }

    tg_bundle_api('editMessageText', $payload);
}

/* =========================
   Main
========================= */

tg_bundle_require_config();
tg_bundle_verify_telegram_secret();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tg_bundle_response(true, 'OK', 'Telegram bundle webhook is ready', [
        'time' => tg_bundle_short_time(),
    ], 200);
}

$update = tg_bundle_read_update();

$callback = $update['callback_query'] ?? null;
if (!is_array($callback)) {
    tg_bundle_response(true, 'IGNORED', 'No callback query found', [], 200);
}

$callbackId = trim((string)($callback['id'] ?? ''));

if (!tg_bundle_callback_chat_allowed($callback)) {
    tg_bundle_answer_callback($callbackId, 'Unauthorized Telegram account', true);

    tg_bundle_response(true, 'IGNORED', 'Unauthorized Telegram chat ignored', [
        'from_id' => (string)($callback['from']['id'] ?? ''),
        'chat_id' => (string)($callback['message']['chat']['id'] ?? ''),
    ], 200);
}

$callbackData = trim((string)($callback['data'] ?? ''));
$parsed = tg_bundle_parse_callback_data($callbackData);

if (empty($parsed['ok'])) {
    tg_bundle_answer_callback($callbackId, (string)($parsed['message'] ?? 'Invalid action'), true);

    tg_bundle_response(true, 'IGNORED', (string)($parsed['message'] ?? 'Invalid callback'), [
        'callback_data' => $callbackData,
    ], 200);
}

$requestId = (string)$parsed['request_id'];
$action = (string)$parsed['action'];

$current = tg_bundle_load_any_request($requestId);

if (!$current) {
    tg_bundle_answer_callback($callbackId, 'Bundle request not found', true);

    tg_bundle_response(true, 'NOT_FOUND', 'Bundle request not found', [
        'request_id' => $requestId,
    ], 200);
}

if (($current['_bucket'] ?? '') === 'DONE') {
    $doneStatus = strtoupper(trim((string)($current['status'] ?? 'DONE')));

    tg_bundle_answer_callback($callbackId, 'Already ' . $doneStatus, true);

    $text = tg_bundle_status_text(
        $current,
        $doneStatus,
        (string)($current['final_message'] ?? $current['message'] ?? 'Already completed')
    );

    tg_bundle_edit_callback_message($callback, $text);

    tg_bundle_response(true, 'ALREADY_DONE', 'Bundle request already completed', [
        'request_id' => $requestId,
        'status' => $doneStatus,
    ], 200);
}

if ($action === 'success') {
    $finalMessage = 'Bundle completed successfully';
    $res = bundle_mark_success($requestId, $finalMessage);
    $finalStatus = 'SUCCESS';
} else {
    $finalMessage = 'Bundle request failed';
    $res = bundle_mark_failed($requestId, $finalMessage);
    $finalStatus = 'FAILED';
}

if (!($res['ok'] ?? false)) {
    $errMsg = (string)($res['message'] ?? 'Bundle action failed');
    $errCode = (string)($res['code'] ?? 'ACTION_FAILED');

    tg_bundle_answer_callback($callbackId, $errMsg, true);

    tg_bundle_response(true, $errCode, $errMsg, [
        'request_id' => $requestId,
        'result' => $res,
    ], 200);
}

$doneRow = tg_bundle_load_any_request($requestId);

if (!$doneRow) {
    $doneRow = $current;
    $doneRow['status'] = $finalStatus;
    $doneRow['final_message'] = $finalMessage;
}

$text = tg_bundle_status_text($doneRow, $finalStatus, $finalMessage);

tg_bundle_edit_callback_message($callback, $text);

tg_bundle_answer_callback(
    $callbackId,
    $finalStatus === 'SUCCESS' ? 'Bundle marked SUCCESS' : 'Bundle marked FAILED',
    false
);

tg_bundle_response(true, 'SUCCESS', 'Telegram bundle action completed', [
    'request_id' => $requestId,
    'status' => $finalStatus,
], 200);