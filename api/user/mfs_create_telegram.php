<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mfs.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function zpay_mfs_create_out(bool $ok, string $code, string $message, array $data = [], int $http = 200): void
{
    http_response_code($http);
    echo json_encode(['ok' => $ok, 'code' => $code, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function zpay_mfs_create_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_name('zawtopup_user');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function zpay_mfs_create_token(): string { return mfs_telegram_bot_token(); }
function zpay_mfs_create_chat(): string { return mfs_telegram_chat_id(); }
function zpay_mfs_create_key(): string
{
    return mfs_telegram_action_key();
}
function zpay_mfs_create_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function zpay_mfs_create_money($v): string { return number_format((float)$v, 2, '.', ''); }
function zpay_mfs_create_sig(string $id, string $act): string { return mfs_telegram_signature($id, $act); }
function zpay_mfs_create_cb(string $act, string $id): string { return mfs_telegram_callback_data($act, $id); }
function zpay_mfs_create_keyboard(string $id): array
{
    return ['inline_keyboard' => [
        [['text' => '🔄 Processing', 'callback_data' => zpay_mfs_create_cb('p', $id)]],
        [['text' => '✅ Success', 'callback_data' => zpay_mfs_create_cb('s', $id)], ['text' => '❌ Failed', 'callback_data' => zpay_mfs_create_cb('f', $id)]],
    ]];
}
function zpay_mfs_create_tg_api(string $method, array $payload): array
{
    $token = zpay_mfs_create_token();
    if ($token === '') return ['ok' => false, 'http' => 0, 'raw' => '', 'error' => 'TELEGRAM_BOT_TOKEN missing'];
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . ltrim($method, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return ['ok' => $http >= 200 && $http < 300 && is_array($json) && !empty($json['ok']), 'http' => $http, 'raw' => is_string($raw) ? substr($raw, 0, 1200) : '', 'error' => $err];
}
function zpay_mfs_create_text(array $row): string
{
    $currency = strtoupper((string)($row['wallet_currency'] ?? 'BDT'));
    $requestId = (string)($row['request_id'] ?? '-');
    $amountRm = (float)($row['amount_rm'] ?? $row['amount_myr'] ?? 0);
    $isRemittance = strtoupper((string)($row['service_mode'] ?? '')) === 'REMITTANCE'
        || strtoupper((string)($row['country_code'] ?? '')) === 'MY'
        || $amountRm > 0;
    $rate = (float)($row['exchange_rate'] ?? $row['rate_myr_to_bdt'] ?? 0);
    if ($isRemittance && $amountRm <= 0 && $rate > 0 && (float)($row['amount_bdt'] ?? 0) > 0) {
        $amountRm = round((float)$row['amount_bdt'] / $rate, 2);
    }
    $fee = $currency === 'MYR'
        ? 'RM ' . zpay_mfs_create_money($row['fee_rm'] ?? $row['fee_myr'] ?? 0)
        : 'BDT ' . zpay_mfs_create_money($row['fee_bdt'] ?? 0);
    if ($isRemittance && $currency !== 'MYR') {
        $feeRm = (float)($row['fee_rm'] ?? $row['fee_myr'] ?? 0);
        if ($feeRm <= 0 && $rate > 0 && (float)($row['fee_bdt'] ?? 0) > 0) {
            $feeRm = round((float)$row['fee_bdt'] / $rate, 2);
        }
        $fee = 'RM ' . zpay_mfs_create_money($feeRm);
    }
    $totalRm = (float)($row['total_debit_rm'] ?? $row['total_pay_myr'] ?? 0);
    if ($isRemittance && $totalRm <= 0) {
        $totalRm = $amountRm + (float)($row['fee_rm'] ?? $row['fee_myr'] ?? 0);
        if ($totalRm <= $amountRm && $rate > 0 && (float)($row['fee_bdt'] ?? 0) > 0) {
            $totalRm = $amountRm + round((float)$row['fee_bdt'] / $rate, 2);
        }
    }
    $total = $isRemittance
        ? 'RM ' . zpay_mfs_create_money($totalRm)
        : 'BDT ' . zpay_mfs_create_money($row['total_debit_bdt'] ?? $row['total_debit'] ?? $row['wallet_hold_amount'] ?? $row['held_amount'] ?? 0);
    $text = "🔔 <b>New MFS Request</b>\n\n" .
        "<b>Request ID:</b> <code>" . zpay_mfs_create_h($requestId) . "</code>\n" .
        "<b>UID:</b> <code>" . zpay_mfs_create_h($row['uid'] ?? '-') . "</code>\n" .
        "<b>User Phone:</b> <code>" . zpay_mfs_create_h($row['user_phone'] ?? '-') . "</code>\n" .
        "<b>Role:</b> " . zpay_mfs_create_h($row['user_role'] ?? $row['role'] ?? 'USER') . "\n\n" .
        "<b>Provider:</b> <b>" . zpay_mfs_create_h($row['provider_name'] ?? $row['provider'] ?? '-') . "</b>\n" .
        "<b>Country:</b> " . zpay_mfs_create_h($row['country_code'] ?? '-') . "\n" .
        "<b>Mode:</b> " . zpay_mfs_create_h($row['service_mode'] ?? '-') . "\n" .
        "<b>Type:</b> " . zpay_mfs_create_h($row['service_type'] ?? 'SEND_MONEY') . "\n" .
        "<b>Receiver Number:</b> <code>" . zpay_mfs_create_h($row['receiver_number'] ?? $row['number'] ?? '-') . "</code>\n" .
        "<b>Received Amount:</b> <b>BDT " . zpay_mfs_create_money($row['amount_bdt'] ?? 0) . "</b>\n";
    if ($amountRm > 0) $text .= "<b>Send Amount:</b> <b>RM " . zpay_mfs_create_money($amountRm) . "</b>\n";
    $text .=
        "<b>Fee:</b> <b>" . zpay_mfs_create_h($fee) . "</b>\n" .
        "<b>Total Paid:</b> <b>" . zpay_mfs_create_h($total) . "</b>\n";
    if ($isRemittance) $text .= "<b>Rate:</b> RM 1 = BDT " . zpay_mfs_create_money($row['exchange_rate'] ?? $row['rate_myr_to_bdt'] ?? 0) . "\n";
    $text .= "<b>Reference:</b> " . zpay_mfs_create_h($row['reference'] ?? '-') . "\n" .
        "<b>Status:</b> <b>PENDING</b>";
    return $text;
}
function zpay_mfs_create_patch_telegram(string $requestId, array $tg): void
{
    $patch = ['telegram_sent' => !empty($tg['ok']), 'telegram_sent_at' => time(), 'telegram_http_status' => (int)($tg['http'] ?? 0), 'telegram_error' => (string)($tg['error'] ?? ''), 'updated_at' => time()];
    foreach (['PENDING', 'PROCESSING', 'DONE'] as $bucket) {
        $row = mfs_fb_get('MFS_REQUESTS/' . $bucket . '/' . $requestId);
        if (is_array($row)) {
            mfs_fb_patch('MFS_REQUESTS/' . $bucket . '/' . $requestId, $patch);
            break;
        }
    }
    mfs_fb_put('MFS_TELEGRAM_LOGS/' . $requestId, ['request_id' => $requestId, 'http_status' => (int)($tg['http'] ?? 0), 'raw' => (string)($tg['raw'] ?? ''), 'error' => (string)($tg['error'] ?? ''), 'sent_at' => time(), 'with_buttons' => true]);
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') zpay_mfs_create_out(false, 'METHOD_NOT_ALLOWED', 'POST required', [], 405);
zpay_mfs_create_start_session();
$sessionUser = $_SESSION['user_user'] ?? [];
$uid = is_array($sessionUser) ? trim((string)($sessionUser['uid'] ?? '')) : '';
if ($uid === '') zpay_mfs_create_out(false, 'UNAUTHORIZED', 'User session required', [], 401);
$body = json_decode((string)file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
$res = mfs_create_request($uid, $body, 'USER_PANEL', 'PANEL', ['uid' => $uid, 'role' => (string)($sessionUser['role'] ?? 'USER')]);
if (empty($res['ok'])) {
    $code = (string)($res['code'] ?? 'SERVER_ERROR');
    $http = in_array($code, ['VALIDATION_ERROR','INSUFFICIENT_BALANCE','MFS_DISABLED','PROVIDER_DISABLED','SERVICE_NOT_ALLOWED','COUNTRY_MISSING','WALLET_CURRENCY_MISSING','COUNTRY_CURRENCY_MISMATCH','UNSUPPORTED_COUNTRY_CURRENCY'], true) ? 422 : (in_array($code, ['ACCOUNT_INACTIVE','INVALID_PIN'], true) ? 403 : ($code === 'USER_NOT_FOUND' ? 404 : 500));
    zpay_mfs_create_out(false, $code, (string)($res['message'] ?? 'Failed to create MFS request'), (array)($res['data'] ?? []), $http);
}
$data = (array)($res['data'] ?? []);
$requestId = trim((string)($data['request_id'] ?? ''));
$row = $requestId !== '' ? mfs_find_request($requestId) : [];
if (!$row) $row = $data;
$tg = ['ok' => false, 'http' => 0, 'raw' => '', 'error' => 'Telegram config missing'];
if ($requestId !== '' && zpay_mfs_create_token() !== '' && zpay_mfs_create_chat() !== '' && zpay_mfs_create_key() !== '') {
    $tg = zpay_mfs_create_tg_api('sendMessage', ['chat_id' => zpay_mfs_create_chat(), 'text' => zpay_mfs_create_text($row), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true, 'reply_markup' => zpay_mfs_create_keyboard($requestId)]);
}
if ($requestId !== '') {
    zpay_mfs_create_patch_telegram($requestId, $tg);
}
$data['telegram'] = ['ok' => !empty($tg['ok']), 'http_status' => (int)($tg['http'] ?? 0), 'with_buttons' => true];
zpay_mfs_create_out(true, 'SUCCESS', 'MFS request created successfully', $data, 200);
