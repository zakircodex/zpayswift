<?php
/**
 * Z-Pay Swift - Telegram webhook for MFS requests.
 *
 * Button behavior:
 * - MFS_PROCESSING|REQUEST_ID => mark processing
 * - MFS_FAILED|REQUEST_ID     => mark failed, no last digit needed
 * - MFS_SUCCESS|REQUEST_ID    => ask sender last digit first; success only after digit reply
 *
 * Signed format is also supported:
 * - mfs|p|REQUEST_ID|SIGNATURE
 * - mfs|s|REQUEST_ID|SIGNATURE
 * - mfs|f|REQUEST_ID|SIGNATURE
 *
 * TRXID is optional. Sender last digit is required for success.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mfs.php';

function mfs_tg_json(bool $ok, string $code, string $message, array $data = [], int $http = 200): void
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

function mfs_tg_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function mfs_tg_token(): string
{
    return defined('TELEGRAM_BOT_TOKEN') ? trim((string)TELEGRAM_BOT_TOKEN) : '';
}

function mfs_tg_chat_id(): string
{
    return defined('TELEGRAM_CHAT_ID') ? trim((string)TELEGRAM_CHAT_ID) : '';
}

function mfs_tg_secret(): string
{
    return defined('TELEGRAM_WEBHOOK_SECRET') ? trim((string)TELEGRAM_WEBHOOK_SECRET) : '';
}

function mfs_tg_action_key(): string
{
    if (defined('TELEGRAM_MFS_ACTION_KEY') && trim((string)TELEGRAM_MFS_ACTION_KEY) !== '') {
        return trim((string)TELEGRAM_MFS_ACTION_KEY);
    }

    if (defined('TELEGRAM_BUNDLE_ACTION_KEY') && trim((string)TELEGRAM_BUNDLE_ACTION_KEY) !== '') {
        return trim((string)TELEGRAM_BUNDLE_ACTION_KEY);
    }

    return defined('APP_KEY') ? trim((string)APP_KEY) : '';
}

function mfs_tg_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mfs_tg_money($value): string
{
    return number_format((float)$value, 2, '.', '');
}

function mfs_tg_time(?int $ts = null): string
{
    return date('Y-m-d H:i:s', $ts ?: mfs_tg_now());
}

function mfs_tg_require_config(): void
{
    $missing = [];

    if (mfs_tg_token() === '') {
        $missing[] = 'TELEGRAM_BOT_TOKEN';
    }

    if (mfs_tg_chat_id() === '') {
        $missing[] = 'TELEGRAM_CHAT_ID';
    }

    if (mfs_tg_secret() === '') {
        $missing[] = 'TELEGRAM_WEBHOOK_SECRET';
    }

    if (mfs_tg_action_key() === '') {
        $missing[] = 'TELEGRAM_MFS_ACTION_KEY_OR_FALLBACK';
    }

    if ($missing) {
        mfs_tg_json(false, 'CONFIG_ERROR', 'Telegram config missing', [
            'missing' => $missing,
        ], 500);
    }
}

function mfs_tg_verify_secret(): void
{
    $secret = mfs_tg_secret();
    $query = trim((string)($_GET['key'] ?? ''));
    $header = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));

    if ($secret === '') {
        mfs_tg_json(false, 'CONFIG_ERROR', 'TELEGRAM_WEBHOOK_SECRET missing', [], 500);
    }

    if ($query !== '' && hash_equals($secret, $query)) {
        return;
    }

    if ($header !== '' && hash_equals($secret, $header)) {
        return;
    }

    mfs_tg_json(false, 'FORBIDDEN', 'Invalid Telegram webhook secret', [], 403);
}

function mfs_tg_api(string $method, array $payload): array
{
    $ch = curl_init('https://api.telegram.org/bot' . mfs_tg_token() . '/' . ltrim($method, '/'));

    curl_setopt_array($ch, [
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
        'json' => is_array($json) ? $json : [],
        'error' => $err,
        'raw' => is_string($raw) ? substr($raw, 0, 800) : '',
    ];
}

function mfs_tg_answer(string $callbackId, string $text, bool $alert = false): void
{
    if ($callbackId === '') {
        return;
    }

    mfs_tg_api('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $alert,
    ]);
}

function mfs_tg_send($chatId, string $text, array $extra = []): void
{
    if ((string)$chatId === '') {
        return;
    }

    mfs_tg_api('sendMessage', array_merge([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], $extra));
}

function mfs_tg_edit($chatId, $messageId, string $text, array $replyMarkup = []): void
{
    if ((string)$chatId === '' || (string)$messageId === '') {
        return;
    }

    mfs_tg_api('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => $replyMarkup ?: ['inline_keyboard' => []],
    ]);
}

function mfs_tg_allowed($chatId, $fromId = ''): bool
{
    $allowed = mfs_tg_chat_id();
    $chatId = trim((string)$chatId);
    $fromId = trim((string)$fromId);

    return $allowed !== ''
        && (($chatId !== '' && hash_equals($allowed, $chatId)) || ($fromId !== '' && hash_equals($allowed, $fromId)));
}

function mfs_tg_signature(string $requestId, string $action): string
{
    return substr(hash_hmac('sha256', strtolower(trim($action)) . '|' . trim($requestId), mfs_tg_action_key()), 0, 16);
}

function mfs_tg_callback_data(string $action, string $requestId): string
{
    $action = strtolower(trim($action));
    $requestId = trim($requestId);

    return 'mfs|' . $action . '|' . $requestId . '|' . mfs_tg_signature($requestId, $action);
}

function mfs_tg_parse_action(string $data): array
{
    $data = trim($data);
    $parts = explode('|', $data);

    if (count($parts) === 2) {
        $map = [
            'MFS_PROCESSING' => 'PROCESSING',
            'MFS_SUCCESS' => 'SUCCESS',
            'MFS_FAILED' => 'FAILED',
        ];

        $action = $map[strtoupper(trim($parts[0]))] ?? '';
        $requestId = trim($parts[1]);

        if ($action === '') {
            return ['ok' => false, 'message' => 'Invalid MFS action'];
        }

        if (!preg_match('/^[A-Za-z0-9_-]{3,120}$/', $requestId)) {
            return ['ok' => false, 'message' => 'Invalid request id'];
        }

        return [
            'ok' => true,
            'action' => $action,
            'request_id' => $requestId,
            'legacy' => true,
        ];
    }

    if (count($parts) === 4 && strtolower(trim($parts[0])) === 'mfs') {
        $actionCode = strtolower(trim($parts[1]));
        $requestId = trim($parts[2]);
        $signature = trim($parts[3]);
        $map = ['p' => 'PROCESSING', 's' => 'SUCCESS', 'f' => 'FAILED'];

        if (!isset($map[$actionCode])) {
            return ['ok' => false, 'message' => 'Invalid MFS action'];
        }

        if (!preg_match('/^[A-Za-z0-9_-]{3,120}$/', $requestId)) {
            return ['ok' => false, 'message' => 'Invalid request id'];
        }

        if (!hash_equals(mfs_tg_signature($requestId, $actionCode), $signature)) {
            return ['ok' => false, 'message' => 'Invalid action signature'];
        }

        return [
            'ok' => true,
            'action' => $map[$actionCode],
            'request_id' => $requestId,
            'legacy' => false,
        ];
    }

    return ['ok' => false, 'message' => 'Invalid callback data'];
}

function mfs_tg_actor(array $from): array
{
    return [
        'uid' => 'TELEGRAM:' . (string)($from['id'] ?? ''),
        'role' => 'TELEGRAM_ADMIN',
    ];
}

function mfs_tg_keyboard_active(string $requestId): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => '🔄 Processing', 'callback_data' => mfs_tg_callback_data('p', $requestId)],
            ],
            [
                ['text' => '✅ Success', 'callback_data' => mfs_tg_callback_data('s', $requestId)],
                ['text' => '❌ Failed', 'callback_data' => mfs_tg_callback_data('f', $requestId)],
            ],
        ],
    ];
}

function mfs_tg_keyboard_waiting(string $requestId): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => '❌ Failed', 'callback_data' => mfs_tg_callback_data('f', $requestId)],
            ],
        ],
    ];
}

function mfs_tg_text(array $row, string $status, string $message): string
{
    $currency = strtoupper((string)($row['wallet_currency'] ?? 'BDT'));
    $amountRm = (float)($row['amount_rm'] ?? $row['amount_myr'] ?? 0);
    $fee = $currency === 'MYR'
        ? 'RM ' . mfs_tg_money($row['fee_rm'] ?? $row['fee_myr'] ?? 0)
        : 'BDT ' . mfs_tg_money($row['fee_bdt'] ?? 0);
    $total = ($currency === 'MYR' ? 'RM ' : 'BDT ')
        . mfs_tg_money($row['total_debit'] ?? $row['wallet_hold_amount'] ?? $row['held_amount'] ?? 0);
    $last = (string)($row['sender_last_digit'] ?? $row['last_digit'] ?? '');
    $icon = $status === 'SUCCESSFUL' ? '✅' : ($status === 'FAILED' ? '❌' : ($status === 'PROCESSING' ? '🔄' : '🔢'));

    $text = $icon . ' <b>MFS Request ' . mfs_tg_h($status) . '</b>' . "\n\n" .
        '<b>Request ID:</b> <code>' . mfs_tg_h($row['request_id'] ?? '-') . '</code>' . "\n" .
        '<b>UID:</b> <code>' . mfs_tg_h($row['uid'] ?? '-') . '</code>' . "\n" .
        '<b>User Phone:</b> <code>' . mfs_tg_h($row['user_phone'] ?? '-') . '</code>' . "\n\n" .
        '<b>Provider:</b> <b>' . mfs_tg_h($row['provider_name'] ?? $row['provider'] ?? '-') . '</b>' . "\n" .
        '<b>Mode:</b> ' . mfs_tg_h($row['service_mode'] ?? '-') . "\n" .
        '<b>Type:</b> ' . mfs_tg_h($row['service_type'] ?? $row['service_name'] ?? 'SEND_MONEY') . "\n" .
        '<b>Receiver Number:</b> <code>' . mfs_tg_h($row['receiver_number'] ?? $row['number'] ?? '-') . '</code>' . "\n" .
        '<b>Amount BDT:</b> <b>BDT ' . mfs_tg_money($row['amount_bdt'] ?? 0) . '</b>' . "\n";

    if ($amountRm > 0) {
        $text .= '<b>Amount RM:</b> <b>RM ' . mfs_tg_money($amountRm) . '</b>' . "\n";
    }

    $text .=
        '<b>Fee:</b> <b>' . mfs_tg_h($fee) . '</b>' . "\n" .
        '<b>Pay / Total Hold:</b> <b>' . mfs_tg_h($total) . '</b>' . "\n" .
        '<b>Reference:</b> ' . mfs_tg_h($row['reference'] ?? '-');

    if ($currency === 'MYR') {
        $text .= "\n" . '<b>Rate:</b> RM 1 = BDT ' . mfs_tg_money($row['exchange_rate'] ?? 0);
    }

    if ($last !== '') {
        $text .= "\n\n" . '<b>Sender Last Digit:</b> <code>' . mfs_tg_h($last) . '</code>';
    }

    return $text . "\n\n" .
        '<b>Status:</b> <b>' . mfs_tg_h($status) . '</b>' . "\n" .
        '<b>Message:</b> ' . mfs_tg_h($message) . "\n" .
        '<b>Updated:</b> ' . mfs_tg_time();
}

function mfs_tg_pending_path($fromId): string
{
    return 'MFS_TELEGRAM_PENDING_SUCCESS/' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$fromId);
}

function mfs_tg_set_pending($fromId, string $requestId, $chatId, $messageId): void
{
    $now = mfs_tg_now();

    fb_put(mfs_tg_pending_path($fromId), [
        'request_id' => $requestId,
        'chat_id' => (string)$chatId,
        'message_id' => (string)$messageId,
        'status' => 'WAITING_LAST_DIGIT',
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $now + 600,
    ]);
}

function mfs_tg_get_pending($fromId): array
{
    $row = fb_get(mfs_tg_pending_path($fromId));
    return is_array($row) ? $row : [];
}

function mfs_tg_clear_pending($fromId): void
{
    fb_delete(mfs_tg_pending_path($fromId));
}

function mfs_tg_digit(string $text): string
{
    $text = trim($text);

    return preg_match('/^\d$/', $text) ? $text : '';
}

function mfs_tg_save_digit(string $requestId, string $digit): void
{
    $row = mfs_find_request($requestId);

    if (!$row) {
        return;
    }

    $bucket = (string)($row['_bucket'] ?? '');

    if (!in_array($bucket, ['PENDING', 'PROCESSING'], true)) {
        return;
    }

    fb_patch('MFS_REQUESTS/' . $bucket . '/' . $requestId, [
        'sender_last_digit' => $digit,
        'sender_last_number_digit' => $digit,
        'last_digit' => $digit,
        'updated_at' => mfs_tg_now(),
    ]);
}

function mfs_tg_handle_callback(array $callback): void
{
    $callbackId = trim((string)($callback['id'] ?? ''));
    $chatId = $callback['message']['chat']['id'] ?? '';
    $messageId = $callback['message']['message_id'] ?? '';
    $from = is_array($callback['from'] ?? null) ? $callback['from'] : [];
    $fromId = (string)($from['id'] ?? '');

    if (!mfs_tg_allowed($chatId, $fromId)) {
        mfs_tg_answer($callbackId, 'Unauthorized Telegram account', true);
        mfs_tg_json(true, 'IGNORED', 'Unauthorized Telegram account', [], 200);
    }

    $parsed = mfs_tg_parse_action((string)($callback['data'] ?? ''));

    if (empty($parsed['ok'])) {
        mfs_tg_answer($callbackId, (string)($parsed['message'] ?? 'Invalid action'), true);
        mfs_tg_json(true, 'IGNORED', (string)($parsed['message'] ?? 'Invalid action'), [], 200);
    }

    $requestId = (string)$parsed['request_id'];
    $action = (string)$parsed['action'];
    $row = mfs_find_request($requestId);

    if (!$row) {
        mfs_tg_answer($callbackId, 'MFS request not found', true);
        mfs_tg_json(true, 'NOT_FOUND', 'MFS request not found', ['request_id' => $requestId], 200);
    }

    $actor = mfs_tg_actor($from);

    if ($action === 'PROCESSING') {
        $res = mfs_mark_processing($requestId, 'MFS request is processing', $actor);

        if (empty($res['ok'])) {
            mfs_tg_answer($callbackId, (string)($res['message'] ?? 'Processing failed'), true);
            mfs_tg_json(true, (string)($res['code'] ?? 'FAILED'), (string)($res['message'] ?? 'Processing failed'), ['result' => $res], 200);
        }

        $updated = mfs_find_request($requestId) ?: $row;
        mfs_tg_edit($chatId, $messageId, mfs_tg_text($updated, 'PROCESSING', 'MFS request is processing'), mfs_tg_keyboard_active($requestId));
        mfs_tg_answer($callbackId, 'Marked PROCESSING');
        mfs_tg_json(true, 'SUCCESS', 'MFS request marked processing', ['request_id' => $requestId], 200);
    }

    if ($action === 'FAILED') {
        $res = mfs_mark_failed($requestId, 'MFS request failed', $actor);

        if (empty($res['ok'])) {
            mfs_tg_answer($callbackId, (string)($res['message'] ?? 'Failed action failed'), true);
            mfs_tg_json(true, (string)($res['code'] ?? 'FAILED'), (string)($res['message'] ?? 'Failed action failed'), ['result' => $res], 200);
        }

        $updated = mfs_find_request($requestId) ?: $row;
        mfs_tg_clear_pending($fromId);
        mfs_tg_edit($chatId, $messageId, mfs_tg_text($updated, 'FAILED', 'MFS request failed'));
        mfs_tg_answer($callbackId, 'Marked FAILED');
        mfs_tg_json(true, 'SUCCESS', 'MFS request marked failed', ['request_id' => $requestId], 200);
    }

    if ($action === 'SUCCESS') {
        if (($row['_bucket'] ?? '') === 'DONE') {
            mfs_tg_answer($callbackId, 'Already completed', true);
            mfs_tg_json(true, 'ALREADY_COMPLETED', 'MFS request already completed', ['request_id' => $requestId], 200);
        }

        mfs_tg_set_pending($fromId, $requestId, $chatId, $messageId);
        mfs_tg_edit($chatId, $messageId, mfs_tg_text($row, 'WAITING_LAST_DIGIT', 'Sender number last digit required'), mfs_tg_keyboard_waiting($requestId));
        mfs_tg_send($chatId, '🔢 <b>Sender number last digit দিন</b>' . "\n\n" . 'Request ID: <code>' . mfs_tg_h($requestId) . '</code>' . "\n" . 'Example: <code>5</code>' . "\n\n" . 'Last digit না দিলে success হবে না।', [
            'reply_markup' => [
                'force_reply' => true,
                'input_field_placeholder' => 'Sender last digit',
            ],
        ]);
        mfs_tg_answer($callbackId, 'Send sender last digit first', true);
        mfs_tg_json(true, 'WAITING_LAST_DIGIT', 'Waiting for sender last digit', ['request_id' => $requestId], 200);
    }

    mfs_tg_json(true, 'IGNORED', 'No action performed', [], 200);
}

function mfs_tg_handle_message(array $message): void
{
    $chatId = $message['chat']['id'] ?? '';
    $from = is_array($message['from'] ?? null) ? $message['from'] : [];
    $fromId = (string)($from['id'] ?? '');
    $text = trim((string)($message['text'] ?? ''));

    if (!mfs_tg_allowed($chatId, $fromId)) {
        mfs_tg_json(true, 'IGNORED', 'Unauthorized Telegram message', [], 200);
    }

    $pending = mfs_tg_get_pending($fromId);

    if (!$pending) {
        mfs_tg_json(true, 'IGNORED', 'No pending MFS success confirmation', [], 200);
    }

    if ((int)($pending['expires_at'] ?? 0) < mfs_tg_now()) {
        mfs_tg_clear_pending($fromId);
        mfs_tg_send($chatId, '⏰ Last digit confirmation expired. Please press Successful again.');
        mfs_tg_json(true, 'EXPIRED', 'Pending confirmation expired', [], 200);
    }

    $digit = mfs_tg_digit($text);

    if ($digit === '') {
        mfs_tg_send($chatId, '❌ শুধু sender number-এর <b>একটা last digit</b> দিন। Example: <code>5</code>');
        mfs_tg_json(true, 'INVALID_LAST_DIGIT', 'Invalid last digit', [], 200);
    }

    $requestId = trim((string)($pending['request_id'] ?? ''));

    if ($requestId === '') {
        mfs_tg_clear_pending($fromId);
        mfs_tg_json(true, 'INVALID_PENDING', 'Pending request id missing', [], 200);
    }

    mfs_tg_save_digit($requestId, $digit);

    $finalMessage = 'Transaction successful. Sender last digit: ' . $digit;
    $res = mfs_mark_success($requestId, $finalMessage, '', mfs_tg_actor($from));

    if (empty($res['ok'])) {
        mfs_tg_send($chatId, '❌ ' . mfs_tg_h((string)($res['message'] ?? 'Failed to mark successful')));
        mfs_tg_json(true, (string)($res['code'] ?? 'ACTION_FAILED'), (string)($res['message'] ?? 'Failed'), ['result' => $res], 200);
    }

    mfs_tg_clear_pending($fromId);

    $done = mfs_find_request($requestId) ?: [
        'request_id' => $requestId,
        'sender_last_digit' => $digit,
    ];

    mfs_tg_edit($pending['chat_id'] ?? $chatId, $pending['message_id'] ?? '', mfs_tg_text($done, 'SUCCESSFUL', $finalMessage));
    mfs_tg_send($chatId, '✅ MFS request successful করা হয়েছে।' . "\n" . 'Request ID: <code>' . mfs_tg_h($requestId) . '</code>' . "\n" . 'Sender Last Digit: <code>' . mfs_tg_h($digit) . '</code>');
    mfs_tg_json(true, 'SUCCESS', 'MFS request marked successful', [
        'request_id' => $requestId,
        'sender_last_digit' => $digit,
    ], 200);
}

mfs_tg_require_config();
mfs_tg_verify_secret();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mfs_tg_json(true, 'OK', 'Telegram MFS webhook is ready', ['time' => mfs_tg_time()], 200);
}

$raw = file_get_contents('php://input');
$update = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;

if (!is_array($update)) {
    mfs_tg_json(true, 'IGNORED', 'Invalid or empty Telegram update', [], 200);
}

if (isset($update['callback_query']) && is_array($update['callback_query'])) {
    mfs_tg_handle_callback($update['callback_query']);
}

if (isset($update['message']) && is_array($update['message'])) {
    mfs_tg_handle_message($update['message']);
}

mfs_tg_json(true, 'IGNORED', 'Unsupported Telegram update', [], 200);
