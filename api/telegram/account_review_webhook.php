<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/account_review.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function tg_account_review_json(
    bool $ok,
    string $code,
    string $message,
    array $data = [],
    int $httpStatus = 200
): void {
    http_response_code($httpStatus);
    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tg_account_review_webhook_secret(): string
{
    return defined('TELEGRAM_WEBHOOK_SECRET') ? trim((string)TELEGRAM_WEBHOOK_SECRET) : '';
}

function tg_account_review_verify_secret(): void
{
    $expected = tg_account_review_webhook_secret();
    $querySecret = trim((string)($_GET['key'] ?? ''));
    $headerSecret = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));

    if ($expected === '') {
        tg_account_review_json(false, 'CONFIG_ERROR', 'Telegram webhook config missing', [], 500);
    }

    if (
        ($querySecret !== '' && hash_equals($expected, $querySecret))
        || ($headerSecret !== '' && hash_equals($expected, $headerSecret))
    ) {
        return;
    }

    tg_account_review_json(false, 'FORBIDDEN', 'Invalid Telegram webhook secret', [], 403);
}

function tg_account_review_read_update(): array
{
    $raw = isset($GLOBALS['TELEGRAM_UPDATE_RAW'])
        ? (string)$GLOBALS['TELEGRAM_UPDATE_RAW']
        : file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        tg_account_review_json(true, 'IGNORED', 'Empty Telegram update body');
    }

    $update = json_decode($raw, true);
    if (!is_array($update)) {
        tg_account_review_json(true, 'IGNORED', 'Invalid Telegram update JSON');
    }

    return $update;
}

function tg_account_review_answer(string $callbackId, string $text, bool $alert = false): void
{
    if ($callbackId === '') {
        return;
    }

    account_review_telegram_api('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $alert,
    ]);
}

function tg_account_review_edit($chatId, $messageId, array $user, bool $showButtons): void
{
    if ((string)$chatId === '' || (string)$messageId === '') {
        return;
    }

    $uid = trim((string)($user['uid'] ?? ''));
    account_review_telegram_api('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => account_review_message($user),
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => $showButtons && $uid !== ''
            ? account_review_keyboard($uid)
            : ['inline_keyboard' => []],
    ]);
}

function tg_account_review_chat_allowed(array $callback): bool
{
    $allowed = account_review_telegram_chat_id();
    if ($allowed === '') {
        return false;
    }

    $chatId = trim((string)($callback['message']['chat']['id'] ?? ''));
    $fromId = trim((string)($callback['from']['id'] ?? ''));

    return ($chatId !== '' && hash_equals($allowed, $chatId))
        || ($fromId !== '' && hash_equals($allowed, $fromId));
}

tg_account_review_verify_secret();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tg_account_review_json(true, 'OK', 'Telegram account review webhook is ready');
}

$update = tg_account_review_read_update();
$callback = $update['callback_query'] ?? null;
if (!is_array($callback)) {
    tg_account_review_json(true, 'IGNORED', 'Account review webhook only handles callback queries');
}

$callbackId = trim((string)($callback['id'] ?? ''));
$chatId = (string)($callback['message']['chat']['id'] ?? '');
$messageId = (string)($callback['message']['message_id'] ?? '');

if (!tg_account_review_chat_allowed($callback)) {
    tg_account_review_answer($callbackId, 'Unauthorized Telegram account', true);
    tg_account_review_json(true, 'IGNORED', 'Unauthorized Telegram account ignored');
}

$parsed = account_review_parse_callback_data((string)($callback['data'] ?? ''));
if (empty($parsed['ok'])) {
    if (function_exists('system_log')) {
        system_log('ACCOUNT_REVIEW_CALLBACK_INVALID', '', 'Invalid account review Telegram callback', [
            'reason' => (string)($parsed['data']['reason'] ?? $parsed['code'] ?? 'unknown'),
        ]);
    }

    tg_account_review_answer($callbackId, 'This account review button is invalid or expired.', true);
    tg_account_review_json(true, 'INVALID_CALLBACK', 'Invalid account review callback');
}

$uid = (string)($parsed['data']['uid'] ?? '');
$action = (string)($parsed['data']['action'] ?? '');
$user = fb_get('USERS/' . $uid);

if (!is_array($user)) {
    tg_account_review_answer($callbackId, 'User account not found', true);
    tg_account_review_json(true, 'NOT_FOUND', 'User account not found', ['uid' => $uid]);
}

if ($action === 'VIEW') {
    $status = strtoupper(trim((string)($user['account_status'] ?? $user['status'] ?? '')));
    tg_account_review_answer($callbackId, 'Review details refreshed');
    tg_account_review_edit($chatId, $messageId, $user, $status === 'REVIEW');
    tg_account_review_json(true, 'SUCCESS', 'Account review details refreshed', [
        'uid' => $uid,
        'status' => $status,
    ]);
}

$actorUid = trim((string)($callback['from']['id'] ?? 'TELEGRAM_ADMIN'));
$result = account_review_apply($uid, $action, $actorUid, 'TELEGRAM_ADMIN');

if (empty($result['ok'])) {
    tg_account_review_answer($callbackId, (string)($result['message'] ?? 'Unable to update account'), true);
    tg_account_review_json(true, (string)($result['code'] ?? 'ERROR'), (string)($result['message'] ?? 'Unable to update account'), [
        'uid' => $uid,
    ]);
}

$latestUser = fb_get('USERS/' . $uid);
$updatedUser = is_array($latestUser) ? $latestUser : $user;
$status = strtoupper(trim((string)($updatedUser['account_status'] ?? $updatedUser['status'] ?? '')));
$answer = $status === 'ACTIVE' ? 'Account approved' : 'Account rejected';

tg_account_review_answer($callbackId, $answer);
tg_account_review_edit($chatId, $messageId, $updatedUser, false);
tg_account_review_json(true, (string)($result['code'] ?? 'SUCCESS'), (string)($result['message'] ?? $answer), [
    'uid' => $uid,
    'status' => $status,
]);
