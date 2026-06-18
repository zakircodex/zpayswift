<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/add_money.php';

function tg_add_money_json(bool $ok, string $code, string $message, array $data = [], int $status = 200): void
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

function tg_add_money_secret(): string
{
    return defined('TELEGRAM_WEBHOOK_SECRET') ? trim((string)TELEGRAM_WEBHOOK_SECRET) : '';
}

function tg_add_money_verify_secret(): void
{
    $expected = tg_add_money_secret();
    if ($expected === '') {
        return;
    }

    $query = trim((string)($_GET['key'] ?? ''));
    $header = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
    if (
        ($query !== '' && hash_equals($expected, $query))
        || ($header !== '' && hash_equals($expected, $header))
    ) {
        return;
    }

    tg_add_money_json(false, 'UNAUTHORIZED', 'Invalid Telegram webhook secret', [], 401);
}

function tg_add_money_read_update(): array
{
    $raw = (string)($GLOBALS['TELEGRAM_UPDATE_RAW'] ?? '');
    if ($raw === '') {
        $raw = (string)file_get_contents('php://input');
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function tg_add_money_answer(string $callbackId, string $text, bool $alert = false): void
{
    if ($callbackId === '') {
        return;
    }

    add_money_telegram_api('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $alert ? 'true' : 'false',
    ]);
}

function tg_add_money_edit($chatId, $messageId, array $row, bool $showButtons): void
{
    if ($chatId === '' || $messageId === '') {
        return;
    }

    add_money_telegram_api('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => add_money_message($row),
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => false,
        'reply_markup' => $showButtons
            ? json_encode(add_money_keyboard((string)($row['request_id'] ?? ''), trim((string)($row['receipt_url'] ?? '')) !== '', (string)($row['receipt_url'] ?? '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function tg_add_money_chat_allowed(array $callback): bool
{
    $allowed = add_money_telegram_chat_id();
    if ($allowed === '') {
        return false;
    }

    $chatId = trim((string)($callback['message']['chat']['id'] ?? ''));
    $fromId = trim((string)($callback['from']['id'] ?? ''));

    return $chatId === $allowed || $fromId === $allowed;
}

tg_add_money_verify_secret();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tg_add_money_json(true, 'OK', 'Telegram add money webhook is ready');
}

$update = tg_add_money_read_update();
$callback = $update['callback_query'] ?? null;
if (!is_array($callback)) {
    tg_add_money_json(true, 'IGNORED', 'Add money webhook only handles callback queries');
}

$callbackId = trim((string)($callback['id'] ?? ''));
$chatId = (string)($callback['message']['chat']['id'] ?? '');
$messageId = (string)($callback['message']['message_id'] ?? '');

if (!tg_add_money_chat_allowed($callback)) {
    tg_add_money_answer($callbackId, 'Unauthorized Telegram account', true);
    tg_add_money_json(true, 'IGNORED', 'Unauthorized Telegram account ignored');
}

$parsed = add_money_parse_callback_data((string)($callback['data'] ?? ''));
if (empty($parsed['ok'])) {
    tg_add_money_answer($callbackId, 'This add money button is invalid or expired.', true);
    tg_add_money_json(true, 'INVALID_CALLBACK', 'Invalid add money callback');
}

$requestId = (string)$parsed['request_id'];
$action = (string)$parsed['action'];
$row = add_money_find_request($requestId);
if ($row === []) {
    tg_add_money_answer($callbackId, 'Add money request not found', true);
    tg_add_money_json(true, 'NOT_FOUND', 'Add money request not found');
}

if ($action === 'VIEW') {
    tg_add_money_answer($callbackId, 'Add money request refreshed');
    tg_add_money_edit($chatId, $messageId, $row, strtoupper((string)($row['status'] ?? '')) === 'PENDING');
    tg_add_money_json(true, 'SUCCESS', 'Add money request refreshed', ['request_id' => $requestId]);
}

$actorUid = trim((string)($callback['from']['id'] ?? 'TELEGRAM_ADMIN'));
$result = add_money_process_request($requestId, $action, $actorUid, 'TELEGRAM_ADMIN', 'Rejected from Telegram');
if (empty($result['ok'])) {
    tg_add_money_answer($callbackId, (string)($result['message'] ?? 'Unable to update request'), true);
    tg_add_money_json(true, (string)($result['code'] ?? 'ERROR'), (string)($result['message'] ?? 'Unable to update request'));
}

$updated = add_money_find_request($requestId);
tg_add_money_edit($chatId, $messageId, $updated, false);
tg_add_money_answer($callbackId, $action === 'APPROVE' ? 'Add money approved' : 'Add money rejected');
tg_add_money_json(true, 'SUCCESS', (string)($result['message'] ?? 'Add money request updated'), ['request_id' => $requestId]);
