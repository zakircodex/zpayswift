<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mfs.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function zpay_mfs_btn_out(bool $ok, string $code, string $message, array $data = [], int $http = 200): void
{
    http_response_code($http);
    echo json_encode(['ok' => $ok, 'code' => $code, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function zpay_mfs_btn_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_name('zawtopup_user');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function zpay_mfs_btn_token(): string { return mfs_telegram_bot_token(); }
function zpay_mfs_btn_chat(): string { return mfs_telegram_chat_id(); }
function zpay_mfs_btn_key(): string
{
    return mfs_telegram_action_key();
}
function zpay_mfs_btn_sig(string $id, string $act): string { return mfs_telegram_signature($id, $act); }
function zpay_mfs_btn_cb(string $act, string $id): string { return mfs_telegram_callback_data($act, $id); }
function zpay_mfs_btn_keyboard(string $id): array
{
    return ['inline_keyboard' => [
        [['text' => '🔄 Processing', 'callback_data' => zpay_mfs_btn_cb('p', $id)]],
        [['text' => '✅ Success', 'callback_data' => zpay_mfs_btn_cb('s', $id)], ['text' => '❌ Failed', 'callback_data' => zpay_mfs_btn_cb('f', $id)]],
    ]];
}
function zpay_mfs_btn_api(string $method, array $payload): array
{
    $ch = curl_init('https://api.telegram.org/bot' . zpay_mfs_btn_token() . '/' . $method);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return ['ok' => $http >= 200 && $http < 300 && is_array($json) && !empty($json['ok']), 'http' => $http, 'raw' => is_string($raw) ? substr($raw, 0, 800) : '', 'error' => $err];
}
function zpay_mfs_btn_old_message(string $requestId): array
{
    $log = fb_get('MFS_TELEGRAM_LOGS/' . $requestId);
    if (!is_array($log)) return ['', ''];
    $json = json_decode((string)($log['raw'] ?? ''), true);
    if (!is_array($json)) return ['', ''];
    $result = is_array($json['result'] ?? null) ? $json['result'] : [];
    $chat = is_array($result['chat'] ?? null) ? $result['chat'] : [];
    return [(string)($chat['id'] ?? zpay_mfs_btn_chat()), (string)($result['message_id'] ?? '')];
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') zpay_mfs_btn_out(false, 'METHOD_NOT_ALLOWED', 'POST required', [], 405);
zpay_mfs_btn_start_session();
$uid = trim((string)($_SESSION['user_user']['uid'] ?? ''));
if ($uid === '') zpay_mfs_btn_out(false, 'UNAUTHORIZED', 'User session required', [], 401);
$body = json_decode((string)file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
$requestId = trim((string)($body['request_id'] ?? ''));
if ($requestId === '' || !preg_match('/^[A-Za-z0-9_-]{3,120}$/', $requestId)) zpay_mfs_btn_out(false, 'VALIDATION_ERROR', 'Valid request_id is required', [], 422);
$row = mfs_find_request($requestId);
if (!$row) zpay_mfs_btn_out(false, 'NOT_FOUND', 'MFS request not found', [], 404);
if (trim((string)($row['uid'] ?? '')) !== $uid) zpay_mfs_btn_out(false, 'FORBIDDEN', 'Not your request', [], 403);
if (zpay_mfs_btn_token() === '' || zpay_mfs_btn_chat() === '' || zpay_mfs_btn_key() === '') zpay_mfs_btn_out(false, 'CONFIG_ERROR', 'Telegram config missing', [], 500);
$keyboard = zpay_mfs_btn_keyboard($requestId);
[$chatId, $messageId] = zpay_mfs_btn_old_message($requestId);
$mode = 'edit';
$res = null;
if ($chatId !== '' && $messageId !== '') $res = zpay_mfs_btn_api('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => $keyboard]);
if (!$res || empty($res['ok'])) {
    $mode = 'send';
    $res = zpay_mfs_btn_api('sendMessage', ['chat_id' => zpay_mfs_btn_chat(), 'text' => "🔔 MFS action buttons\n\nRequest ID: <code>" . htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') . "</code>", 'parse_mode' => 'HTML', 'reply_markup' => $keyboard]);
}
fb_put('MFS_TELEGRAM_BUTTON_LOGS/' . $requestId, ['request_id' => $requestId, 'uid' => $uid, 'mode' => $mode, 'ok' => !empty($res['ok']), 'http_status' => (int)($res['http'] ?? 0), 'raw' => (string)($res['raw'] ?? ''), 'error' => (string)($res['error'] ?? ''), 'attached_at' => time()]);
zpay_mfs_btn_out(!empty($res['ok']), !empty($res['ok']) ? 'SUCCESS' : 'TELEGRAM_ERROR', !empty($res['ok']) ? 'MFS Telegram buttons attached' : 'Failed to attach MFS Telegram buttons', ['request_id' => $requestId, 'mode' => $mode]);
