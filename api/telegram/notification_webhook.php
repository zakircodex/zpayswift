<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/notifications.php';
require_once dirname(__DIR__) . '/lib/rates.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function nb_json(bool $ok, string $code, string $message, array $data = [], int $status = 200): void
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

function nb_token(): string
{
    return defined('NOTIFICATION_BOT_TOKEN') ? trim((string)NOTIFICATION_BOT_TOKEN) : '';
}

function nb_secret(): string
{
    return defined('NOTIFICATION_TELEGRAM_WEBHOOK_SECRET') ? trim((string)NOTIFICATION_TELEGRAM_WEBHOOK_SECRET) : '';
}

function nb_normalize_telegram_id($value): string
{
    return trim((string)$value, " \t\n\r\0\x0B'\"");
}

function nb_admin_ids(): array
{
    $raw = defined('NOTIFICATION_TELEGRAM_ADMIN_IDS') ? NOTIFICATION_TELEGRAM_ADMIN_IDS : '';
    $values = is_array($raw) ? $raw : explode(',', (string)$raw);

    $ids = [];
    foreach ($values as $value) {
        $id = nb_normalize_telegram_id($value);
        if ($id !== '') {
            $ids['id:' . $id] = $id;
        }
    }

    return array_values($ids);
}

function nb_message_from_id(array $message): string
{
    return nb_normalize_telegram_id($message['from']['id'] ?? '');
}

function nb_callback_from_id(array $callback): string
{
    return nb_normalize_telegram_id($callback['from']['id'] ?? '');
}

function nb_update_from_id(array $update): string
{
    if (isset($update['message']) && is_array($update['message'])) {
        return nb_message_from_id($update['message']);
    }
    if (isset($update['callback_query']) && is_array($update['callback_query'])) {
        return nb_callback_from_id($update['callback_query']);
    }
    if (isset($update['edited_message']) && is_array($update['edited_message'])) {
        return nb_message_from_id($update['edited_message']);
    }

    return '';
}

function nb_authorized(string $telegramUserId): bool
{
    $telegramUserId = nb_normalize_telegram_id($telegramUserId);
    $ids = nb_admin_ids();
    if ($telegramUserId === '' || $ids === []) {
        return false;
    }

    return in_array($telegramUserId, $ids, true);
}

function nb_verify_secret(): void
{
    $expected = nb_secret();
    $querySecret = trim((string)($_GET['key'] ?? ''));
    $headerSecret = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
    if ($expected === '') {
        nb_json(false, 'CONFIG_ERROR', 'Notification bot webhook secret missing.', [], 500);
    }
    if (($querySecret !== '' && hash_equals($expected, $querySecret))
        || ($headerSecret !== '' && hash_equals($expected, $headerSecret))) {
        return;
    }
    nb_json(false, 'FORBIDDEN', 'Invalid notification bot webhook secret.', [], 403);
}

function nb_api(string $method, array $payload): array
{
    $token = nb_token();
    if ($token === '' || !function_exists('curl_init')) {
        return ['ok' => false, 'code' => 'NOTIFICATION_BOT_TOKEN_MISSING'];
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . ltrim($method, '/'));
    if ($ch === false) {
        return ['ok' => false, 'code' => 'TELEGRAM_CURL_INIT_FAILED'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'code' => 'TELEGRAM_CURL_ERROR', 'message' => $err ?: 'Telegram request failed.'];
    }
    $json = json_decode((string)$raw, true);
    if ($status >= 200 && $status < 300 && is_array($json) && !empty($json['ok'])) {
        return ['ok' => true, 'code' => 'OK', 'data' => $json];
    }
    return ['ok' => false, 'code' => 'TELEGRAM_API_ERROR', 'status' => $status];
}

function nb_answer(string $callbackId, string $text, bool $alert = false): void
{
    if ($callbackId === '') {
        return;
    }
    nb_api('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $alert,
    ]);
}

function nb_send(string $chatId, string $text, array $keyboard = []): void
{
    if ($chatId === '') {
        return;
    }
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ];
    if ($keyboard !== []) {
        $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_SLASHES);
    }
    nb_api('sendMessage', $payload);
}

function nb_context_key(string $chatId, string $fromId): string
{
    return hash('sha256', trim($chatId) . '|' . trim($fromId));
}

function nb_context_path(string $chatId, string $fromId): string
{
    return 'NOTIFICATION_BOT_CONTEXT/' . nb_context_key($chatId, $fromId);
}

function nb_context(string $chatId, string $fromId): array
{
    $path = nb_context_path($chatId, $fromId);
    $row = fb_get($path);
    if (!is_array($row)) {
        return [];
    }
    if ((int)($row['expires_at'] ?? 0) < time()) {
        fb_delete($path);
        return [];
    }
    return $row;
}

function nb_save_context(string $chatId, string $fromId, array $context): void
{
    $context['chat_id'] = $chatId;
    $context['from_id'] = $fromId;
    $context['updated_at'] = time();
    $context['expires_at'] = time() + 600;
    fb_put(nb_context_path($chatId, $fromId), $context);
}

function nb_clear_context(string $chatId, string $fromId): void
{
    fb_delete(nb_context_path($chatId, $fromId));
}

function nb_update_key(array $update): string
{
    if (isset($update['update_id'])) {
        return 'u:' . (string)$update['update_id'];
    }
    if (isset($update['callback_query']['id'])) {
        return 'c:' . (string)$update['callback_query']['id'];
    }
    if (isset($update['message']['chat']['id'], $update['message']['message_id'])) {
        return 'm:' . (string)$update['message']['chat']['id'] . ':' . (string)$update['message']['message_id'];
    }
    return 'raw:' . hash('sha256', json_encode($update));
}

function nb_update_seen(array $update): bool
{
    $path = 'NOTIFICATION_BOT_PROCESSED_UPDATES/' . hash('sha256', nb_update_key($update));
    return is_array(fb_get($path));
}

function nb_mark_update_seen(array $update): void
{
    fb_put('NOTIFICATION_BOT_PROCESSED_UPDATES/' . hash('sha256', nb_update_key($update)), [
        'created_at' => time(),
        'expires_at' => time() + 86400,
    ]);
}

function nb_menu_keyboard(): array
{
    return ['inline_keyboard' => [
        [['text' => 'Send Notification', 'callback_data' => 'nb|notice']],
        [['text' => 'Update Ringgit Rate', 'callback_data' => 'nb|rate']],
        [
            ['text' => 'View Current Rate', 'callback_data' => 'nb|rate_view'],
            ['text' => 'Recent Broadcasts', 'callback_data' => 'nb|recent'],
        ],
        [['text' => 'Cancel', 'callback_data' => 'nb|cancel']],
    ]];
}

function nb_audience_keyboard(): array
{
    return ['inline_keyboard' => [
        [
            ['text' => 'Active Users', 'callback_data' => 'nb|aud|ACTIVE'],
            ['text' => 'Inactive Users', 'callback_data' => 'nb|aud|INACTIVE'],
        ],
        [['text' => 'Specific User', 'callback_data' => 'nb|aud|SPECIFIC']],
        [['text' => 'Cancel', 'callback_data' => 'nb|cancel']],
    ]];
}

function nb_preview_keyboard(): array
{
    return ['inline_keyboard' => [
        [
            ['text' => 'Send Now', 'callback_data' => 'nb|send'],
            ['text' => 'Edit', 'callback_data' => 'nb|edit'],
        ],
        [['text' => 'Cancel', 'callback_data' => 'nb|cancel']],
    ]];
}

function nb_rate_preview_keyboard(): array
{
    return ['inline_keyboard' => [
        [
            ['text' => 'Confirm Update', 'callback_data' => 'nb|rate_confirm'],
            ['text' => 'Edit', 'callback_data' => 'nb|rate'],
        ],
        [['text' => 'Cancel', 'callback_data' => 'nb|cancel']],
    ]];
}

function nb_notice_id(): string
{
    return 'NTC' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function nb_find_user(string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $direct = fb_get('USERS/' . $query);
    if (is_array($direct)) {
        return ['uid' => $query, 'user' => $direct];
    }
    $needlePhone = preg_replace('/\D+/', '', $query) ?? '';
    $needleEmail = strtolower($query);
    $rows = fb_get('USERS');
    if (!is_array($rows)) {
        return [];
    }
    foreach ($rows as $uid => $user) {
        if (!is_array($user)) {
            continue;
        }
        $phone = preg_replace('/\D+/', '', (string)($user['phone'] ?? $user['mobile'] ?? '')) ?? '';
        $email = strtolower(trim((string)($user['email'] ?? '')));
        if (($needlePhone !== '' && $phone !== '' && $phone === $needlePhone)
            || ($needleEmail !== '' && $email !== '' && $email === $needleEmail)) {
            return ['uid' => (string)$uid, 'user' => $user];
        }
    }
    return [];
}

function nb_specific_user_preview(array $found): string
{
    $summary = notification_user_public_summary((string)$found['uid'], (array)$found['user']);
    return "Specific User\n\n"
        . "Name: " . ($summary['name'] !== '' ? $summary['name'] : 'N/A') . "\n"
        . "Phone: " . ($summary['phone_masked'] !== '' ? $summary['phone_masked'] : 'N/A') . "\n"
        . "Status: " . $summary['status'] . "\n"
        . "Pricing Country: " . ($summary['pricing_country'] !== '' ? $summary['pricing_country'] : 'N/A') . "\n"
        . "Currency: " . ($summary['currency'] !== '' ? $summary['currency'] : 'N/A') . "\n\n"
        . "Use this user?";
}

function nb_notice_preview_text(array $context): string
{
    $audience = (string)($context['audience'] ?? 'ACTIVE');
    $specificUid = (string)($context['specific_uid'] ?? '');
    $count = notification_target_count($audience, $specificUid);
    $statuses = notification_audience_statuses($audience);
    $body = trim((string)($context['body'] ?? ''));
    return "Notification Preview\n\n"
        . "Audience: " . notification_audience_label($audience) . ($specificUid !== '' ? ' (' . $specificUid . ')' : '') . "\n"
        . "Included Statuses: " . ($statuses !== [] ? implode(', ', $statuses) : 'N/A') . "\n"
        . "Estimated Recipients: " . $count . "\n"
        . "Title: " . (string)($context['title'] ?? '') . "\n"
        . "Message: " . ($body !== '' ? $body : '[image only]') . "\n"
        . "Image: " . ((string)($context['image_id'] ?? '') !== '' ? 'Yes' : 'No') . "\n\n"
        . "Send this notification?";
}

function nb_show_notice_preview(string $chatId, array $context): void
{
    nb_send($chatId, nb_notice_preview_text($context), nb_preview_keyboard());
}

function nb_largest_photo_id(array $message): string
{
    $photos = $message['photo'] ?? [];
    if (!is_array($photos) || $photos === []) {
        return '';
    }
    $best = '';
    $bestSize = -1;
    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $size = (int)($photo['file_size'] ?? 0);
        if ($size >= $bestSize) {
            $bestSize = $size;
            $best = (string)($photo['file_id'] ?? '');
        }
    }
    return $best;
}

function nb_download_photo(string $fileId): array
{
    $fileId = trim($fileId);
    $token = nb_token();
    if ($fileId === '' || $token === '') {
        return ['ok' => false, 'code' => 'NOTICE_PHOTO_MISSING'];
    }
    $file = nb_api('getFile', ['file_id' => $fileId]);
    $filePath = (string)($file['data']['result']['file_path'] ?? '');
    if (empty($file['ok']) || $filePath === '') {
        return ['ok' => false, 'code' => 'NOTICE_PHOTO_FILE_FAILED'];
    }
    $ch = curl_init('https://api.telegram.org/file/bot' . $token . '/' . ltrim($filePath, '/'));
    if ($ch === false) {
        return ['ok' => false, 'code' => 'NOTICE_PHOTO_DOWNLOAD_FAILED'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $binary = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($binary) || $binary === '' || $status < 200 || $status >= 300) {
        return ['ok' => false, 'code' => 'NOTICE_PHOTO_DOWNLOAD_FAILED'];
    }
    return notification_store_binary_image($binary, basename($filePath));
}

function nb_rate_view_text(): string
{
    $rate = zpay_myr_to_bdt_rate(true);
    return $rate > 0
        ? 'Current Ringgit Rate: RM 1 = ' . number_format($rate, 2, '.', '') . ' BDT'
        : 'Current Ringgit Rate is not configured.';
}

function nb_rate_preview_text(float $newRate): string
{
    $current = zpay_myr_to_bdt_rate(true);
    $diff = $current > 0 ? ($newRate - $current) : 0;
    return "Ringgit Rate Preview\n\n"
        . "Current Rate: " . ($current > 0 ? number_format($current, 2, '.', '') : 'N/A') . " BDT\n"
        . "New Rate: " . number_format($newRate, 2, '.', '') . " BDT\n"
        . "Difference: " . ($current > 0 ? number_format($diff, 2, '.', '') : 'N/A') . "\n"
        . "Notification Audience: Active MY users with MYR wallet\n"
        . "Estimated Recipients: " . notification_rate_target_count() . "\n\n"
        . "Confirm update?";
}

function nb_recent_text(): string
{
    $items = notification_recent_broadcasts(10);
    if ($items === []) {
        return 'No recent broadcasts found.';
    }
    $lines = ['Recent Broadcasts'];
    foreach ($items as $item) {
        $time = (int)$item['created_at'] > 0 ? date('d M H:i', (int)$item['created_at']) : 'N/A';
        $lines[] = "\n" . (string)$item['broadcast_id']
            . "\nType: " . (string)$item['type']
            . "\nAudience: " . (string)$item['audience']
            . "\nTitle: " . ((string)$item['title'] !== '' ? (string)$item['title'] : 'N/A')
            . "\nRows: " . (int)$item['sent'] . " | Push: " . (int)$item['push_sent']
            . "\nTime: " . $time;
    }
    return implode("\n", $lines);
}

function nb_start_notice(string $chatId, string $fromId): void
{
    nb_clear_context($chatId, $fromId);
    nb_send($chatId, 'Choose notification audience:', nb_audience_keyboard());
}

function nb_handle_callback(array $callback): void
{
    $callbackId = (string)($callback['id'] ?? '');
    $data = trim((string)($callback['data'] ?? ''));
    $chatId = (string)($callback['message']['chat']['id'] ?? '');
    $fromId = nb_callback_from_id($callback);
    if (!nb_authorized($fromId)) {
        nb_answer($callbackId, 'You are not authorized.', true);
        nb_json(true, 'IGNORED', 'Unauthorized notification bot callback ignored.');
    }
    if (strncasecmp($data, 'nb|', 3) !== 0) {
        nb_answer($callbackId, 'Unsupported notification bot action.', true);
        nb_json(true, 'IGNORED', 'Unsupported callback.');
    }
    $parts = explode('|', $data);
    $action = strtolower((string)($parts[1] ?? ''));
    if ($action === 'cancel') {
        nb_clear_context($chatId, $fromId);
        nb_answer($callbackId, 'Cancelled');
        nb_send($chatId, 'Cancelled.');
        nb_json(true, 'CANCELLED', 'Notification bot context cancelled.');
    }
    if ($action === 'menu') {
        nb_answer($callbackId, 'Menu');
        nb_send($chatId, 'Z-Pay Swift Notification Bot', nb_menu_keyboard());
        nb_json(true, 'MENU_OK', 'Menu shown.');
    }
    if ($action === 'notice') {
        nb_start_notice($chatId, $fromId);
        nb_answer($callbackId, 'Choose audience');
        nb_json(true, 'NOTICE_START_OK', 'Notification flow started.');
    }
    if ($action === 'aud') {
        $audience = notification_clean_code($parts[2] ?? 'ACTIVE', 20);
        if (!in_array($audience, ['ACTIVE', 'INACTIVE', 'SPECIFIC'], true)) {
            $audience = 'ACTIVE';
        }
        $context = [
            'workflow' => 'NOTICE',
            'notice_id' => nb_notice_id(),
            'audience' => $audience,
            'step' => $audience === 'SPECIFIC' ? 'SPECIFIC_QUERY' : 'TITLE',
            'created_by' => $fromId,
            'created_at' => time(),
        ];
        nb_save_context($chatId, $fromId, $context);
        nb_answer($callbackId, 'Audience selected');
        nb_send($chatId, $audience === 'SPECIFIC'
            ? 'Send UID, phone, or email for the target user.'
            : 'Send notification title.');
        nb_json(true, 'AUDIENCE_OK', 'Audience selected.');
    }
    if ($action === 'rate_view') {
        nb_answer($callbackId, 'Current rate');
        nb_send($chatId, nb_rate_view_text());
        nb_json(true, 'RATE_VIEW_OK', 'Rate shown.');
    }
    if ($action === 'recent') {
        nb_answer($callbackId, 'Recent broadcasts');
        nb_send($chatId, nb_recent_text());
        nb_json(true, 'RECENT_OK', 'Recent broadcasts shown.');
    }
    if ($action === 'rate') {
        nb_save_context($chatId, $fromId, [
            'workflow' => 'RATE',
            'step' => 'RATE_VALUE',
            'created_by' => $fromId,
            'created_at' => time(),
        ]);
        nb_answer($callbackId, 'Send new rate');
        nb_send($chatId, nb_rate_view_text() . "\n\nSend new rate, e.g. 30.90");
        nb_json(true, 'RATE_START_OK', 'Rate update flow started.');
    }
    $context = nb_context($chatId, $fromId);
    if ($context === []) {
        nb_answer($callbackId, 'Session expired. Start again.', true);
        nb_json(true, 'CONTEXT_MISSING', 'Context missing.');
    }
    if ($action === 'specific_ok') {
        $context['specific_uid'] = (string)($context['candidate_uid'] ?? '');
        $context['step'] = 'TITLE';
        unset($context['candidate_uid']);
        nb_save_context($chatId, $fromId, $context);
        nb_answer($callbackId, 'User selected');
        nb_send($chatId, 'Send notification title.');
        nb_json(true, 'SPECIFIC_OK', 'Specific user selected.');
    }
    if ($action === 'preview') {
        $context['step'] = 'PREVIEW';
        nb_save_context($chatId, $fromId, $context);
        nb_answer($callbackId, 'Preview');
        nb_show_notice_preview($chatId, $context);
        nb_json(true, 'PREVIEW_OK', 'Preview shown.');
    }
    if ($action === 'edit') {
        $context['step'] = 'TITLE';
        nb_save_context($chatId, $fromId, $context);
        nb_answer($callbackId, 'Edit');
        nb_send($chatId, 'Send notification title.');
        nb_json(true, 'EDIT_OK', 'Edit started.');
    }
    if ($action === 'send') {
        if ((string)($context['workflow'] ?? '') !== 'NOTICE'
            || trim((string)($context['title'] ?? '')) === ''
            || (trim((string)($context['body'] ?? '')) === '' && trim((string)($context['image_id'] ?? '')) === '')) {
            nb_answer($callbackId, 'Notification content is incomplete.', true);
            nb_json(true, 'NOTICE_INVALID', 'Notification invalid.');
        }
        $result = notification_broadcast_admin_notice($context);
        if (!empty($result['ok'])) {
            nb_clear_context($chatId, $fromId);
            nb_answer($callbackId, 'Sent');
            nb_send($chatId, 'Notification sent. Rows: ' . (int)($result['sent'] ?? 0) . ' | Push: ' . (int)($result['push_sent'] ?? 0));
            nb_json(true, 'NOTICE_SENT', 'Notification sent.', $result);
        }
        nb_answer($callbackId, 'Send failed.', true);
        nb_json(true, 'NOTICE_SEND_FAILED', 'Notification send failed.');
    }
    if ($action === 'rate_confirm') {
        $newRate = (float)($context['new_rate'] ?? 0);
        $valid = zpay_validate_myr_to_bdt_rate($newRate);
        if ((string)($context['workflow'] ?? '') !== 'RATE' || empty($valid['ok'])) {
            nb_answer($callbackId, 'Rate is invalid.', true);
            nb_json(true, 'RATE_INVALID', 'Rate invalid.');
        }
        $result = zpay_save_myr_to_bdt_rate($newRate, $fromId, 'NOTIFICATION_BOT');
        if (!empty($result['ok'])) {
            nb_clear_context($chatId, $fromId);
            nb_answer($callbackId, 'Rate updated');
            $notification = (array)($result['data']['notification'] ?? []);
            nb_send($chatId, 'Ringgit rate updated: RM 1 = ' . number_format($newRate, 2, '.', '') . ' BDT'
                . "\nNotifications: " . (int)($notification['sent'] ?? 0)
                . "\nPush: " . (int)($notification['push_sent'] ?? 0));
            nb_json(true, 'RATE_UPDATED', 'Ringgit rate updated.', (array)($result['data'] ?? []));
        }
        nb_answer($callbackId, 'Rate update failed.', true);
        nb_json(false, 'RATE_UPDATE_FAILED', 'Rate update failed.', [], 500);
    }
    nb_answer($callbackId, 'Unsupported action.', true);
    nb_json(true, 'UNSUPPORTED', 'Unsupported notification bot action.');
}

function nb_handle_message(array $message): void
{
    $chatId = (string)($message['chat']['id'] ?? '');
    $fromId = nb_message_from_id($message);
    $text = trim((string)($message['text'] ?? ''));
    if (!nb_authorized($fromId)) {
        nb_send($chatId, 'Unauthorized Telegram account.');
        nb_json(true, 'IGNORED', 'Unauthorized notification bot message ignored.');
    }
    if (preg_match('/^\/cancel(?:@\w+)?(?:\s|$)/i', $text) === 1) {
        nb_clear_context($chatId, $fromId);
        nb_send($chatId, 'Cancelled.');
        nb_json(true, 'CANCELLED', 'Context cancelled.');
    }
    if (preg_match('/^\/start(?:@\w+)?(?:\s|$)/i', $text) === 1 || strcasecmp($text, 'menu') === 0) {
        nb_send($chatId, 'Z-Pay Swift Notification Bot', nb_menu_keyboard());
        nb_json(true, 'MENU_OK', 'Menu shown.');
    }
    if (strcasecmp($text, 'Send Notification') === 0 || preg_match('/^\/notice(?:@\w+)?(?:\s|$)/i', $text) === 1) {
        nb_start_notice($chatId, $fromId);
        nb_json(true, 'NOTICE_START_OK', 'Notification flow started.');
    }
    if (strcasecmp($text, 'Update Ringgit Rate') === 0 || preg_match('/^\/rate(?:@\w+)?(?:\s|$)/i', $text) === 1) {
        nb_save_context($chatId, $fromId, [
            'workflow' => 'RATE',
            'step' => 'RATE_VALUE',
            'created_by' => $fromId,
            'created_at' => time(),
        ]);
        nb_send($chatId, nb_rate_view_text() . "\n\nSend new rate, e.g. 30.90");
        nb_json(true, 'RATE_START_OK', 'Rate flow started.');
    }
    if (strcasecmp($text, 'View Current Rate') === 0 || preg_match('/^\/current_rate(?:@\w+)?(?:\s|$)/i', $text) === 1) {
        nb_send($chatId, nb_rate_view_text());
        nb_json(true, 'RATE_VIEW_OK', 'Rate shown.');
    }
    if (strcasecmp($text, 'Recent Broadcasts') === 0 || preg_match('/^\/recent(?:@\w+)?(?:\s|$)/i', $text) === 1) {
        nb_send($chatId, nb_recent_text());
        nb_json(true, 'RECENT_OK', 'Recent broadcasts shown.');
    }

    $context = nb_context($chatId, $fromId);
    if ($context === []) {
        nb_send($chatId, 'Choose an action:', nb_menu_keyboard());
        nb_json(true, 'WAITING_MENU', 'No active context.');
    }
    $step = strtoupper((string)($context['step'] ?? ''));
    if ($step === 'SPECIFIC_QUERY') {
        $found = nb_find_user($text);
        if ($found === []) {
            nb_send($chatId, 'User was not found. Send UID, phone, or email again, or /cancel.');
            nb_json(true, 'SPECIFIC_NOT_FOUND', 'Specific user not found.');
        }
        $context['candidate_uid'] = (string)$found['uid'];
        nb_save_context($chatId, $fromId, $context);
        nb_send($chatId, nb_specific_user_preview($found), ['inline_keyboard' => [
            [['text' => 'Use This User', 'callback_data' => 'nb|specific_ok']],
            [['text' => 'Cancel', 'callback_data' => 'nb|cancel']],
        ]]);
        nb_json(true, 'SPECIFIC_PREVIEW_OK', 'Specific user preview shown.');
    }
    if ($step === 'TITLE') {
        if ($text === '') {
            nb_send($chatId, 'Title is required. Send title or /cancel.');
            nb_json(true, 'TITLE_REQUIRED', 'Title required.');
        }
        $context['title'] = notification_clean_text($text, 100);
        $context['step'] = 'BODY';
        nb_save_context($chatId, $fromId, $context);
        nb_send($chatId, 'Send message text, or send an image with optional caption.');
        nb_json(true, 'TITLE_OK', 'Title saved.');
    }
    if ($step === 'BODY' || $step === 'IMAGE') {
        $photoId = nb_largest_photo_id($message);
        if ($photoId !== '') {
            $stored = nb_download_photo($photoId);
            if (empty($stored['ok'])) {
                nb_send($chatId, 'Image could not be saved. Send another image or /cancel.');
                nb_json(true, 'IMAGE_FAILED', 'Image failed.');
            }
            foreach (['image_id', 'image_path', 'image_mime', 'image_name'] as $key) {
                $context[$key] = (string)($stored[$key] ?? '');
            }
            $caption = trim((string)($message['caption'] ?? ''));
            if ($caption !== '') {
                $context['body'] = notification_clean_text($caption, 4000);
            }
            $context['step'] = 'PREVIEW';
            nb_save_context($chatId, $fromId, $context);
            nb_show_notice_preview($chatId, $context);
            nb_json(true, 'IMAGE_OK', 'Image saved.');
        }
        if ($text !== '') {
            $context['body'] = notification_clean_text($text, 4000);
            $context['step'] = 'IMAGE';
            nb_save_context($chatId, $fromId, $context);
            nb_send($chatId, 'Optional: send an image now, or tap Preview.', ['inline_keyboard' => [
                [['text' => 'Preview', 'callback_data' => 'nb|preview']],
                [['text' => 'Cancel', 'callback_data' => 'nb|cancel']],
            ]]);
            nb_json(true, 'BODY_OK', 'Body saved.');
        }
        nb_send($chatId, 'Send message text, image, or /cancel.');
        nb_json(true, 'WAITING_CONTENT', 'Waiting for content.');
    }
    if ($step === 'RATE_VALUE') {
        if (!preg_match('/^([0-9]+(?:\.[0-9]{1,4})?)$/', $text, $matches)) {
            nb_send($chatId, 'Send a valid numeric rate, e.g. 30.90');
            nb_json(true, 'RATE_INVALID_FORMAT', 'Rate format invalid.');
        }
        $newRate = round((float)$matches[1], 2);
        $valid = zpay_validate_myr_to_bdt_rate($newRate);
        if (empty($valid['ok'])) {
            nb_send($chatId, 'Rate must be between 20 and 50.');
            nb_json(true, (string)$valid['code'], (string)$valid['message']);
        }
        $context['new_rate'] = $newRate;
        $context['step'] = 'RATE_PREVIEW';
        nb_save_context($chatId, $fromId, $context);
        nb_send($chatId, nb_rate_preview_text($newRate), nb_rate_preview_keyboard());
        nb_json(true, 'RATE_PREVIEW_OK', 'Rate preview shown.');
    }
    nb_send($chatId, 'Please use the menu or /cancel.');
    nb_json(true, 'IGNORED', 'Message ignored.');
}

nb_verify_secret();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    nb_json(true, 'OK', 'Notification bot webhook is ready.', [
        'menu' => ['Send Notification', 'Update Ringgit Rate', 'View Current Rate', 'Recent Broadcasts', 'Cancel'],
    ]);
}

$raw = (string)file_get_contents('php://input');
$update = trim($raw) !== '' ? json_decode($raw, true) : null;
if (!is_array($update)) {
    nb_json(true, 'IGNORED', 'Invalid Telegram update.');
}

if (nb_update_seen($update)) {
    $callbackId = (string)($update['callback_query']['id'] ?? '');
    nb_answer($callbackId, 'Already processed.');
    nb_json(true, 'DUPLICATE_IGNORED', 'Duplicate Telegram update ignored.');
}
nb_mark_update_seen($update);

if (isset($update['callback_query']) && is_array($update['callback_query'])) {
    nb_handle_callback($update['callback_query']);
}

if (isset($update['message']) && is_array($update['message'])) {
    nb_handle_message($update['message']);
}

if (isset($update['edited_message']) && is_array($update['edited_message'])) {
    nb_handle_message($update['edited_message']);
}

nb_json(true, 'IGNORED', 'Unsupported Telegram update type.');
