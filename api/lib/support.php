<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/fcm.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/admin_pagination.php';

function support_now(): int
{
    return function_exists('now_ts') ? now_ts() : time();
}

function support_clean_text($value, int $max = 500): string
{
    $text = trim((string)$value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $text) ?? $text;
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max);
    }
    return substr($text, 0, $max);
}

function support_clean_code($value): string
{
    $code = strtoupper(trim((string)$value));
    $code = preg_replace('/[^A-Z0-9_]+/', '_', $code) ?? $code;
    return trim($code, '_');
}

function support_bool($value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === null || $value === '') {
        return $default;
    }
    return in_array(strtoupper(trim((string)$value)), ['1', 'TRUE', 'YES', 'ON', 'ENABLED', 'ACTIVE'], true);
}

function support_int($value, int $default, int $min, int $max): int
{
    $num = is_numeric($value) ? (int)$value : $default;
    return max($min, min($max, $num));
}

function support_ticket_id(): string
{
    return 'SP' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function support_message_id(): string
{
    return 'MSG' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function support_attachment_id(): string
{
    return 'ATT' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function support_telegram_action_key(): string
{
    if (defined('SUPPORT_TELEGRAM_ACTION_KEY') && trim((string)SUPPORT_TELEGRAM_ACTION_KEY) !== '') {
        return trim((string)SUPPORT_TELEGRAM_ACTION_KEY);
    }
    if (defined('TELEGRAM_SUPPORT_ACTION_KEY') && trim((string)TELEGRAM_SUPPORT_ACTION_KEY) !== '') {
        return trim((string)TELEGRAM_SUPPORT_ACTION_KEY);
    }
    return defined('APP_KEY') ? trim((string)APP_KEY) : '';
}

function support_telegram_bot_token(): string
{
    return defined('SUPPORT_BOT_TOKEN') ? trim((string)SUPPORT_BOT_TOKEN) : '';
}

function support_telegram_chat_id(): string
{
    foreach (['SUPPORT_TELEGRAM_CHAT_ID', 'TELEGRAM_SUPPORT_CHAT_ID', 'TELEGRAM_CHAT_ID', 'ZAW_TELEGRAM_CHAT_ID'] as $constant) {
        if (defined($constant) && trim((string)constant($constant)) !== '') {
            return trim((string)constant($constant));
        }
    }
    return '';
}

function support_telegram_signature(string $actionCode, string $ticketId): string
{
    $key = support_telegram_action_key();
    if ($key === '') {
        return '';
    }
    return substr(hash_hmac('sha256', support_clean_code($actionCode) . '|' . trim($ticketId), $key), 0, 16);
}

function support_telegram_callback_data(string $actionCode, string $ticketId): string
{
    $actionCode = strtolower(support_clean_code($actionCode));
    return 'support|' . $actionCode . '|' . trim($ticketId) . '|' . support_telegram_signature($actionCode, $ticketId);
}

function support_telegram_allowed_ids(): array
{
    $raw = [];
    foreach (['SUPPORT_TELEGRAM_ADMIN_IDS', 'TELEGRAM_SUPPORT_ADMIN_IDS', 'TELEGRAM_ADMIN_IDS'] as $constant) {
        if (defined($constant)) {
            $value = constant($constant);
            $raw = array_merge($raw, is_array($value) ? $value : preg_split('/[,\s]+/', (string)$value));
        }
    }
    foreach (['SUPPORT_TELEGRAM_CHAT_ID', 'TELEGRAM_SUPPORT_CHAT_ID', 'TELEGRAM_CHAT_ID', 'ZAW_TELEGRAM_CHAT_ID'] as $constant) {
        if (defined($constant)) {
            $raw[] = constant($constant);
        }
    }
    $ids = [];
    foreach ($raw as $id) {
        $id = trim((string)$id);
        if ($id !== '') {
            $ids['id:' . $id] = $id;
        }
    }
    return array_values($ids);
}

function support_telegram_actor_allowed(string $chatId, string $fromId): bool
{
    $allowed = support_telegram_allowed_ids();
    if ($allowed === []) {
        return false;
    }
    foreach ($allowed as $id) {
        if (($chatId !== '' && hash_equals($id, $chatId)) || ($fromId !== '' && hash_equals($id, $fromId))) {
            return true;
        }
    }
    return false;
}

function support_telegram_ticket_callback_allowed(array $ticket, string $chatId, string $fromId): bool
{
    if (support_telegram_actor_allowed($chatId, $fromId)) {
        return true;
    }

    $ticketChatId = trim((string)($ticket['telegram_chat_id'] ?? ''));
    return $ticketChatId !== '' && $chatId !== '' && hash_equals($ticketChatId, $chatId);
}

function support_telegram_reply_diag_enabled(): bool
{
    return defined('SUPPORT_TELEGRAM_REPLY_DIAGNOSTICS')
        && support_bool((string)SUPPORT_TELEGRAM_REPLY_DIAGNOSTICS, false);
}

function support_telegram_safe_suffix(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    return substr($value, -4);
}

function support_telegram_reply_diag(string $event, string $ticketId = '', array $context = []): void
{
    if (!support_telegram_reply_diag_enabled() || !function_exists('system_log')) {
        return;
    }

    $safe = [];
    foreach ($context as $key => $value) {
        $key = support_clean_code($key);
        if ($key === '') {
            continue;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            $safe[strtolower($key)] = $value;
        } elseif (is_string($value)) {
            $safe[strtolower($key)] = support_clean_text($value, 80);
        }
    }
    $safe['ticket_suffix'] = support_telegram_safe_suffix($ticketId);

    system_log(
        'SUPPORT_TELEGRAM_REPLY',
        support_telegram_safe_suffix($ticketId),
        support_clean_text($event, 80),
        $safe
    );
}

function support_admin_ticket_url(string $ticketId): string
{
    $ticketId = support_clean_code($ticketId);
    return 'https://zpayswift.com/admin/?section=support&ticket_id=' . rawurlencode($ticketId);
}

function support_telegram_parse_callback_data(string $data): array
{
    $parts = explode('|', trim($data));
    if (count($parts) !== 4 || strtolower($parts[0]) !== 'support') {
        return [];
    }
    $action = strtolower(support_clean_code($parts[1]));
    $ticketId = support_clean_code($parts[2]);
    $signature = trim($parts[3]);
    $expected = support_telegram_signature($action, $ticketId);
    if ($action === '' || $ticketId === '' || $expected === '' || !hash_equals($expected, $signature)) {
        return [];
    }
    return [
        'action' => $action,
        'ticket_id' => $ticketId,
    ];
}

function support_telegram_keyboard(string $ticketId): array
{
    $ticket = support_read_ticket($ticketId);
    $status = support_clean_code($ticket['status'] ?? 'OPEN') ?: 'OPEN';
    $replyAllowed = !in_array($status, ['CLOSED', 'RESOLVED'], true);
    $firstRow = [
        ['text' => 'View Ticket', 'url' => support_admin_ticket_url($ticketId)],
    ];
    if ($replyAllowed) {
        $firstRow[] = ['text' => 'Reply', 'callback_data' => support_telegram_callback_data('r', $ticketId)];
    }
    $secondRow = [];
    if ($replyAllowed) {
        $secondRow[] = ['text' => 'Mark Pending', 'callback_data' => support_telegram_callback_data('p', $ticketId)];
    }
    if ($status !== 'CLOSED') {
        $secondRow[] = ['text' => 'Close', 'callback_data' => support_telegram_callback_data('c', $ticketId)];
    }

    $rows = [$firstRow];
    if ($secondRow !== []) {
        $rows[] = $secondRow;
    }

    return [
        'inline_keyboard' => $rows,
    ];
}

function support_telegram_cancel_keyboard(string $ticketId): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => 'Cancel Reply', 'callback_data' => support_telegram_callback_data('x', $ticketId)],
            ],
        ],
    ];
}

function support_telegram_ticket_message(array $ticket): string
{
    return "New Support Ticket\n\n"
        . "Ticket: " . (string)($ticket['ticket_id'] ?? '') . "\n"
        . "User: " . support_clean_text($ticket['user_name'] ?? '', 80) . "\n"
        . "UID: " . (string)($ticket['uid'] ?? '') . "\n"
        . "Phone: " . (string)($ticket['user_phone'] ?? '') . "\n"
        . "Category: " . (string)($ticket['category_name'] ?? '') . "\n"
        . "Related Request: " . ((string)($ticket['related_request_id'] ?? '') ?: '-') . "\n"
        . "Subject: " . (string)($ticket['subject'] ?? '') . "\n"
        . "Status: " . support_clean_code($ticket['status'] ?? 'OPEN') . "\n\n"
        . "Message:\n" . support_telegram_message_excerpt((string)($ticket['last_message_preview'] ?? ''), 700);
}

function support_telegram_message_excerpt(string $value, int $max = 600): string
{
    $value = support_clean_text($value, $max);
    return $value === '' ? '-' : $value;
}

function support_message_reply_preview(string $ticketId, string $messageId): array
{
    $ticketId = support_clean_code($ticketId);
    $messageId = support_clean_text($messageId, 80);
    if ($ticketId === '' || $messageId === '') {
        return [];
    }
    $row = fb_get('SUPPORT_MESSAGES/' . $ticketId . '/' . $messageId);
    if (!is_array($row)) {
        return [];
    }
    return [
        'message_id' => (string)($row['message_id'] ?? $messageId),
        'sender_type' => (string)($row['sender_type'] ?? ''),
        'sender_name' => (string)($row['sender_name'] ?? ''),
        'message' => support_telegram_message_excerpt((string)($row['message'] ?? ''), 160),
        'attachment_count' => count((array)($row['attachment_ids'] ?? [])),
        'created_at' => (int)($row['created_at'] ?? 0),
    ];
}

function support_telegram_message_map_key(string $chatId, string $telegramMessageId): string
{
    return hash('sha256', trim($chatId) . '|' . trim($telegramMessageId));
}

function support_telegram_store_message_map(string $chatId, string $telegramMessageId, string $ticketId, string $canonicalMessageId): void
{
    $chatId = trim($chatId);
    $telegramMessageId = trim($telegramMessageId);
    $ticketId = support_clean_code($ticketId);
    $canonicalMessageId = support_clean_text($canonicalMessageId, 80);
    if ($chatId === '' || $telegramMessageId === '' || $ticketId === '' || $canonicalMessageId === '') {
        return;
    }
    fb_put('SUPPORT_TELEGRAM_MESSAGE_MAP/' . support_telegram_message_map_key($chatId, $telegramMessageId), [
        'telegram_message_id' => $telegramMessageId,
        'canonical_message_id' => $canonicalMessageId,
        'ticket_id' => $ticketId,
        'chat_id' => $chatId,
        'created_at' => support_now(),
    ]);
}

function support_telegram_lookup_message_map(string $chatId, string $telegramMessageId): array
{
    $chatId = trim($chatId);
    $telegramMessageId = trim($telegramMessageId);
    if ($chatId === '' || $telegramMessageId === '') {
        return [];
    }
    $row = fb_get('SUPPORT_TELEGRAM_MESSAGE_MAP/' . support_telegram_message_map_key($chatId, $telegramMessageId));
    return is_array($row) ? $row : [];
}

function support_telegram_ticket_summary(array $ticket, array $messages = [], int $limit = 6): string
{
    $lines = [
        'Support Ticket',
        '',
        'Ticket: ' . (string)($ticket['ticket_id'] ?? ''),
        'Status: ' . support_status_label(support_clean_code($ticket['status'] ?? 'OPEN')),
        'User: ' . ((string)($ticket['user_name'] ?? '') ?: '-'),
        'Phone: ' . ((string)($ticket['user_phone'] ?? '') ?: '-'),
        'Category: ' . ((string)($ticket['category_name'] ?? '') ?: '-'),
        'Subject: ' . support_telegram_message_excerpt((string)($ticket['subject'] ?? ''), 180),
        'Related Request: ' . ((string)($ticket['related_request_id'] ?? '') ?: '-'),
        '',
        'Recent conversation:',
        '',
    ];

    $recent = array_slice($messages, -max(1, min(10, $limit)));
    if ($recent === []) {
        $lines[] = 'No messages yet.';
    }
    foreach ($recent as $message) {
        if (!is_array($message)) {
            continue;
        }
        $sender = strtoupper((string)($message['sender_type'] ?? 'USER'));
        $label = in_array($sender, ['ADMIN', 'SUPPORT'], true) ? 'Support' : 'User';
        $lines[] = $label . ':';
        $lines[] = support_telegram_message_excerpt((string)($message['message'] ?? ''), 700);
        $attachmentCount = count((array)($message['attachment_ids'] ?? []));
        if ($attachmentCount > 0) {
            $lines[] = 'Attachments: ' . $attachmentCount;
        }
        $lines[] = '';
    }

    $lines[] = 'Attachments total: ' . (int)($ticket['attachment_count'] ?? 0);
    $lines[] = 'Admin link: ' . support_admin_ticket_url((string)($ticket['ticket_id'] ?? ''));
    return trim(implode("\n", $lines));
}

function support_telegram_context_key(string $chatId, string $fromId): string
{
    return hash('sha256', trim($chatId) . '|' . trim($fromId));
}

function support_telegram_reply_context_path(string $chatId, string $fromId): string
{
    return 'SUPPORT_TELEGRAM_REPLY_CONTEXT/' . support_telegram_context_key($chatId, $fromId);
}

function support_telegram_reply_context_paths(string $chatId, string $fromId, bool $includePrivateMirror = true): array
{
    $paths = [];
    $chatId = trim($chatId);
    $fromId = trim($fromId);
    if ($chatId !== '' && $fromId !== '') {
        $paths[support_telegram_reply_context_path($chatId, $fromId)] = true;
    }
    if ($includePrivateMirror && $fromId !== '') {
        $paths[support_telegram_reply_context_path($fromId, $fromId)] = true;
    }
    return array_keys($paths);
}

function support_telegram_reply_idempotency_key(array $message, int $updateId = 0): string
{
    $chatId = (string)($message['chat']['id'] ?? '');
    $fromId = (string)($message['from']['id'] ?? '');
    $messageId = (string)($message['message_id'] ?? '');
    return hash('sha256', $chatId . '|' . $fromId . '|' . $updateId . '|' . $messageId);
}

function support_telegram_reply_context(string $chatId, string $fromId): array
{
    $includePrivateMirror = trim($chatId) !== '' && trim($chatId) === trim($fromId);
    foreach (support_telegram_reply_context_paths($chatId, $fromId, $includePrivateMirror) as $path) {
        $row = fb_get($path);
        if (!is_array($row) || support_clean_code($row['status'] ?? '') !== 'WAITING_REPLY') {
            continue;
        }
        if ((int)($row['expires_at'] ?? 0) < support_now()) {
            fb_delete($path);
            continue;
        }
        return $row;
    }
    return [];
}

function support_telegram_has_active_reply_context(array $update): bool
{
    $message = $update['message'] ?? null;
    if (!is_array($message)) {
        return false;
    }
    $chatId = (string)($message['chat']['id'] ?? '');
    $fromId = (string)($message['from']['id'] ?? '');
    return $chatId !== '' && $fromId !== '' && support_telegram_reply_context($chatId, $fromId) !== [];
}

function support_telegram_set_reply_context(string $ticketId, string $chatId, string $fromId): array
{
    $ticketId = support_clean_code($ticketId);
    $chatId = trim($chatId);
    $fromId = trim($fromId);
    if ($chatId === '' || $fromId === '') {
        return ['ok' => false, 'code' => 'TELEGRAM_CONTEXT_MISSING', 'message' => 'Reply mode could not identify the Telegram admin.'];
    }
    $ticket = support_read_ticket($ticketId);
    if ($ticket === []) {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_NOT_FOUND', 'message' => 'Support ticket was not found.'];
    }
    $status = support_clean_code($ticket['status'] ?? 'OPEN');
    if ($status === 'CLOSED') {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_CLOSED', 'message' => 'This ticket is closed and cannot receive replies.'];
    }
    if ($status === 'RESOLVED') {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_RESOLVED', 'message' => 'This ticket has been resolved and cannot receive replies.'];
    }
    $now = support_now();
    $row = [
        'ticket_id' => $ticketId,
        'admin_telegram_user_id' => trim($fromId),
        'admin_chat_id' => trim($chatId),
        'status' => 'WAITING_REPLY',
        'created_at' => $now,
        'expires_at' => $now + 600,
    ];
    foreach (support_telegram_reply_context_paths($chatId, $fromId) as $path) {
        if (!fb_put($path, $row)) {
            return ['ok' => false, 'code' => 'TELEGRAM_CONTEXT_SAVE_FAILED', 'message' => 'Reply mode could not be enabled. Please try again.'];
        }
    }
    return ['ok' => true, 'context' => $row, 'ticket' => $ticket];
}

function support_telegram_clear_reply_context(string $chatId, string $fromId): void
{
    $context = support_telegram_reply_context($chatId, $fromId);
    $paths = support_telegram_reply_context_paths($chatId, $fromId);
    $originalChatId = (string)($context['admin_chat_id'] ?? '');
    if ($originalChatId !== '' && $fromId !== '') {
        $paths = array_merge($paths, support_telegram_reply_context_paths($originalChatId, $fromId));
    }
    foreach (array_unique($paths) as $path) {
        fb_delete($path);
    }
}

function support_telegram_message_has_reply_context(array $message): bool
{
    $chatId = (string)($message['chat']['id'] ?? '');
    $fromId = (string)($message['from']['id'] ?? '');
    return $chatId !== '' && $fromId !== '' && support_telegram_reply_context($chatId, $fromId) !== [];
}

function support_telegram_save_reply_from_message(array $message, int $updateId = 0): array
{
    $chatId = (string)($message['chat']['id'] ?? '');
    $fromId = (string)($message['from']['id'] ?? '');
    $text = trim((string)($message['text'] ?? ''));
    if ($chatId === '' || $fromId === '') {
        return ['ok' => false, 'code' => 'TELEGRAM_CONTEXT_MISSING', 'message' => 'Reply mode is not active.'];
    }
    $idem = support_telegram_reply_idempotency_key($message, $updateId);
    $actorAllowed = support_telegram_actor_allowed($chatId, $fromId);
    $replyToTelegramMessageId = (string)($message['reply_to_message']['message_id'] ?? '');
    $telegramReplyMap = $replyToTelegramMessageId === '' ? [] : support_telegram_lookup_message_map($chatId, $replyToTelegramMessageId);
    if (!$actorAllowed) {
        support_telegram_reply_diag('support_reply_context_read_fail', '', [
            'code' => 'TELEGRAM_UNAUTHORIZED',
            'private_chat' => $chatId !== '' && $chatId === $fromId,
        ]);
        return ['ok' => false, 'code' => 'TELEGRAM_UNAUTHORIZED', 'message' => 'Unauthorized Telegram account.'];
    }
    if ($replyToTelegramMessageId !== '' && $telegramReplyMap === []) {
        return ['ok' => false, 'code' => 'TELEGRAM_REPLY_TARGET_UNKNOWN', 'message' => 'The replied message could not be matched to a support ticket.'];
    }
    $context = support_telegram_reply_context($chatId, $fromId);
    if ($context === [] && $telegramReplyMap === []) {
        support_telegram_reply_diag('support_reply_context_read_fail', '', [
            'code' => 'TELEGRAM_CONTEXT_EXPIRED',
            'private_chat' => $chatId !== '' && $chatId === $fromId,
        ]);
        return ['ok' => false, 'code' => 'TELEGRAM_CONTEXT_EXPIRED', 'message' => 'Reply mode expired. Tap Reply again.'];
    }
    $contextTicketId = (string)($context['ticket_id'] ?? '');
    $mappedTicketId = (string)($telegramReplyMap['ticket_id'] ?? '');
    if ($contextTicketId !== '' && $mappedTicketId !== '' && !hash_equals($contextTicketId, $mappedTicketId)) {
        return ['ok' => false, 'code' => 'TELEGRAM_REPLY_WRONG_TICKET', 'message' => 'This reply belongs to a different support ticket.'];
    }
    $ticketId = $mappedTicketId !== '' ? $mappedTicketId : $contextTicketId;
    $replyToMessageId = (string)($telegramReplyMap['canonical_message_id'] ?? '');
    support_telegram_reply_diag('support_reply_context_read_success', $ticketId, [
        'private_chat' => $chatId !== '' && $chatId === $fromId,
        'context_status' => (string)($context['status'] ?? ''),
        'native_reply' => $replyToMessageId !== '',
    ]);
    if ($text === '' || str_starts_with($text, '/')) {
        return ['ok' => false, 'code' => 'SUPPORT_MESSAGE_REQUIRED', 'message' => 'Please send a text reply.'];
    }
    $ticket = support_read_ticket($ticketId);
    if ($ticket === []) {
        support_telegram_clear_reply_context($chatId, $fromId);
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_NOT_FOUND', 'message' => 'Support ticket was not found.'];
    }
    $status = support_clean_code($ticket['status'] ?? 'OPEN');
    if ($status === 'CLOSED') {
        support_telegram_clear_reply_context($chatId, $fromId);
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_CLOSED', 'message' => 'This ticket is closed and cannot receive replies.'];
    }
    if ($status === 'RESOLVED') {
        support_telegram_clear_reply_context($chatId, $fromId);
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_RESOLVED', 'message' => 'This ticket has been resolved and cannot receive replies.'];
    }

    $auth = [
        'uid' => 'TELEGRAM:' . $fromId,
        'user' => [
            'uid' => 'TELEGRAM:' . $fromId,
            'role' => 'ADMIN',
        ],
    ];
    $result = support_reply($auth, $ticketId, $text, [], 'ADMIN', [
        'source' => 'TELEGRAM',
        'sender_telegram_id' => $fromId,
        'admin_chat_id' => $chatId,
        'sender_name' => support_clean_text($message['from']['first_name'] ?? 'Telegram Admin', 80),
        'idempotency_key' => $idem,
        'reply_to_message_id' => $replyToMessageId,
    ]);
    if (empty($result['ok'])) {
        return $result;
    }
    $savedMessageId = (string)($result['message_id'] ?? '');
    support_telegram_store_message_map($chatId, (string)($message['message_id'] ?? ''), $ticketId, $savedMessageId);
    support_telegram_set_reply_context($ticketId, $chatId, $fromId);
    return $result + ['ticket_id' => $ticketId];
}

function support_default_config(): array
{
    return [
        'contact_us_enabled' => true,
        'ticket_enabled' => true,
        'whatsapp_enabled' => false,
        'whatsapp_number' => '',
        'call_enabled' => false,
        'support_phone' => '',
        'email_enabled' => false,
        'support_email' => '',
        'support_hours' => 'Every day, 10:00 AM - 10:00 PM',
        'average_response_text' => 'Average response time: within 24 hours.',
        'support_notice' => 'Never share your password, PIN or OTP with anyone.',
        'attachments_enabled' => true,
        'max_attachments' => 3,
        'max_file_size' => 5 * 1024 * 1024,
        'ticket_rate_limit_seconds' => 20,
        'reopen_allowed' => true,
    ];
}

function support_config(): array
{
    $row = fb_get('SUPPORT_CONFIG');
    $row = is_array($row) ? $row : [];
    $config = array_merge(support_default_config(), $row);
    $config['contact_us_enabled'] = support_bool($config['contact_us_enabled'] ?? true, true);
    $config['ticket_enabled'] = support_bool($config['ticket_enabled'] ?? true, true);
    $config['whatsapp_enabled'] = support_bool($config['whatsapp_enabled'] ?? false);
    $config['call_enabled'] = support_bool($config['call_enabled'] ?? false);
    $config['email_enabled'] = support_bool($config['email_enabled'] ?? false);
    $config['attachments_enabled'] = support_bool($config['attachments_enabled'] ?? true, true);
    $config['max_attachments'] = support_int($config['max_attachments'] ?? 3, 3, 0, 3);
    $config['max_file_size'] = support_int($config['max_file_size'] ?? 5242880, 5242880, 1024, 10 * 1024 * 1024);
    $config['ticket_rate_limit_seconds'] = support_int($config['ticket_rate_limit_seconds'] ?? 20, 20, 0, 3600);
    $config['reopen_allowed'] = support_bool($config['reopen_allowed'] ?? true, true);
    return $config;
}

function support_public_config(): array
{
    $config = support_config();
    return [
        'contact_us_enabled' => (bool)$config['contact_us_enabled'],
        'ticket_enabled' => (bool)$config['ticket_enabled'],
        'whatsapp_enabled' => (bool)$config['whatsapp_enabled'] && support_clean_phone($config['whatsapp_number'] ?? '') !== '',
        'whatsapp_number' => support_clean_phone($config['whatsapp_number'] ?? ''),
        'call_enabled' => (bool)$config['call_enabled'] && support_clean_phone($config['support_phone'] ?? '') !== '',
        'support_phone' => support_clean_phone($config['support_phone'] ?? ''),
        'email_enabled' => (bool)$config['email_enabled'] && filter_var((string)($config['support_email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false,
        'support_email' => support_clean_text($config['support_email'] ?? '', 120),
        'support_hours' => support_clean_text($config['support_hours'] ?? '', 120),
        'average_response_text' => support_clean_text($config['average_response_text'] ?? '', 160),
        'support_notice' => support_clean_text($config['support_notice'] ?? '', 220),
        'attachments_enabled' => (bool)$config['attachments_enabled'],
        'max_attachments' => (int)$config['max_attachments'],
        'max_file_size' => (int)$config['max_file_size'],
        'reopen_allowed' => (bool)$config['reopen_allowed'],
    ];
}

function support_clean_phone($value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }
    $prefix = str_starts_with($raw, '+') ? '+' : '';
    return $prefix . (preg_replace('/\D+/', '', $raw) ?? '');
}

function support_default_categories(): array
{
    $names = [
        'ACCOUNT_LOGIN' => 'Account / Login',
        'ADD_MONEY' => 'Add Money',
        'MOBILE_TOPUP' => 'Mobile Top-Up',
        'BKASH' => 'bKash',
        'NAGAD' => 'Nagad',
        'ZPAY_TRANSFER' => 'Z-Pay Transfer',
        'BUNDLE' => 'Bundle',
        'WALLET_BALANCE' => 'Wallet / Balance',
        'TRANSACTION_ISSUE' => 'Transaction Issue',
        'OTHER' => 'Other',
    ];
    $rows = [];
    $sort = 10;
    foreach ($names as $code => $name) {
        $transaction = !in_array($code, ['ACCOUNT_LOGIN', 'OTHER'], true);
        $rows[$code] = [
            'code' => $code,
            'name' => $name,
            'active' => true,
            'sort_order' => $sort,
            'related_request_enabled' => $transaction,
            'attachment_enabled' => true,
        ];
        $sort += 10;
    }
    return $rows;
}

function support_categories(bool $activeOnly = true): array
{
    $stored = fb_get('SUPPORT_CATEGORIES');
    $rows = is_array($stored) && $stored !== [] ? $stored : support_default_categories();
    $out = [];
    foreach ($rows as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = support_clean_code($row['code'] ?? $key);
        if ($code === '') {
            continue;
        }
        $item = [
            'code' => $code,
            'name' => support_clean_text($row['name'] ?? $code, 80),
            'active' => support_bool($row['active'] ?? true, true),
            'sort_order' => (int)($row['sort_order'] ?? 999),
            'related_request_enabled' => support_bool($row['related_request_enabled'] ?? false),
            'attachment_enabled' => support_bool($row['attachment_enabled'] ?? true, true),
        ];
        if ($activeOnly && !$item['active']) {
            continue;
        }
        $out[] = $item;
    }
    usort($out, static function (array $a, array $b): int {
        return (($a['sort_order'] <=> $b['sort_order']) ?: strcmp($a['name'], $b['name']));
    });
    return $out;
}

function support_category(string $code): array
{
    $code = support_clean_code($code);
    foreach (support_categories(false) as $row) {
        if ($row['code'] === $code) {
            return $row;
        }
    }
    return [];
}

function support_uid_from_auth(array $auth): string
{
    return (string)($auth['uid'] ?? $auth['user']['uid'] ?? '');
}

function support_user_from_auth(array $auth): array
{
    return is_array($auth['user'] ?? null) ? $auth['user'] : [];
}

function support_status_is_final(string $status): bool
{
    return in_array(support_clean_code($status), ['CLOSED', 'RESOLVED'], true);
}

function support_status_is_active(string $status): bool
{
    return !support_status_is_final($status);
}

function support_active_ticket_from_index(string $uid, array $index, ?int $now = null): array
{
    if ($uid === '' || $index === []) {
        return [];
    }
    $now ??= support_now();
    uasort($index, static fn($a, $b) => ((int)($b['updated_at'] ?? $b['reserved_at'] ?? 0) <=> (int)($a['updated_at'] ?? $a['reserved_at'] ?? 0)));
    foreach ($index as $ticketId => $meta) {
        $ticketId = support_clean_code(is_array($meta) ? ($meta['ticket_id'] ?? $ticketId) : $ticketId);
        if ($ticketId === '') {
            continue;
        }
        $meta = is_array($meta) ? $meta : [];
        $metaStatus = support_clean_code($meta['status'] ?? '');
        if ($metaStatus === 'CREATING') {
            $reservedAt = (int)($meta['reserved_at'] ?? $meta['updated_at'] ?? 0);
            if ($reservedAt > 0 && $now - $reservedAt <= 120) {
                return [
                    'ticket_id' => $ticketId,
                    'uid' => $uid,
                    'status' => 'CREATING',
                    'status_label' => 'Creating',
                    'updated_at' => $reservedAt,
                ];
            }
            continue;
        }
        $ticket = support_read_ticket($ticketId);
        if (
            $ticket !== []
            && (string)($ticket['uid'] ?? '') === $uid
            && support_status_is_active((string)($ticket['status'] ?? 'OPEN'))
        ) {
            return support_public_ticket($ticket);
        }
    }
    return [];
}

function support_find_active_ticket_for_uid(string $uid): array
{
    $index = $uid !== '' ? fb_get('SUPPORT_USER_INDEX/' . $uid) : null;
    return support_active_ticket_from_index($uid, is_array($index) ? $index : []);
}

function support_active_ticket_error(array $ticket): array
{
    return [
        'ok' => false,
        'code' => 'SUPPORT_ACTIVE_TICKET_EXISTS',
        'message' => 'You already have an active support conversation.',
        'status' => 409,
        'active_ticket_id' => (string)($ticket['ticket_id'] ?? ''),
        'active_ticket' => $ticket,
    ];
}

function support_claim_active_ticket_slot(string $uid, string $ticketId, int $now, int $maxAttempts = 5): array
{
    $path = 'SUPPORT_USER_INDEX/' . $uid;
    for ($attempt = 0; $attempt < max(1, $maxAttempts); $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || trim((string)($snapshot['etag'] ?? '')) === '') {
            return ['ok' => false, 'code' => 'SUPPORT_ACTIVE_TICKET_CHECK_FAILED'];
        }
        $index = is_array($snapshot['value'] ?? null) ? $snapshot['value'] : [];
        $active = support_active_ticket_from_index($uid, $index, $now);
        if ($active !== [] && (string)($active['ticket_id'] ?? '') !== $ticketId) {
            return ['ok' => false, 'code' => 'SUPPORT_ACTIVE_TICKET_EXISTS', 'active_ticket' => $active];
        }
        $index[$ticketId] = [
            'ticket_id' => $ticketId,
            'updated_at' => $now,
            'reserved_at' => $now,
            'status' => 'CREATING',
        ];
        $saved = fb_put_if_match($path, $index, (string)$snapshot['etag']);
        if (!empty($saved['ok'])) {
            return ['ok' => true, 'ticket_id' => $ticketId];
        }
        if ((int)($saved['status'] ?? 0) !== 412) {
            return ['ok' => false, 'code' => 'SUPPORT_ACTIVE_TICKET_CHECK_FAILED'];
        }
    }
    return ['ok' => false, 'code' => 'SUPPORT_ACTIVE_TICKET_CHECK_FAILED'];
}

function support_release_active_ticket_slot(string $uid, string $ticketId, int $maxAttempts = 3): void
{
    $path = 'SUPPORT_USER_INDEX/' . $uid;
    for ($attempt = 0; $attempt < max(1, $maxAttempts); $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || trim((string)($snapshot['etag'] ?? '')) === '') {
            return;
        }
        $index = is_array($snapshot['value'] ?? null) ? $snapshot['value'] : [];
        $meta = is_array($index[$ticketId] ?? null) ? $index[$ticketId] : [];
        if (support_clean_code($meta['status'] ?? '') !== 'CREATING') {
            return;
        }
        unset($index[$ticketId]);
        $saved = fb_put_if_match($path, $index === [] ? null : $index, (string)$snapshot['etag']);
        if (!empty($saved['ok']) || (int)($saved['status'] ?? 0) !== 412) {
            return;
        }
    }
}

function support_status_label(string $status): string
{
    $status = support_clean_code($status);
    return [
        'OPEN' => 'Open',
        'PENDING' => 'Pending',
        'REPLIED' => 'Replied',
        'RESOLVED' => 'Resolved',
        'CLOSED' => 'Closed',
    ][$status] ?? ucfirst(strtolower($status));
}

function support_public_ticket(array $row): array
{
    $ticketId = (string)($row['ticket_id'] ?? '');
    $status = support_clean_code($row['status'] ?? 'OPEN') ?: 'OPEN';
    return [
        'ticket_id' => $ticketId,
        'uid' => (string)($row['uid'] ?? ''),
        'user_name' => (string)($row['user_name'] ?? ''),
        'user_phone' => (string)($row['user_phone'] ?? ''),
        'user_email' => (string)($row['user_email'] ?? ''),
        'category_code' => (string)($row['category_code'] ?? ''),
        'category_name' => (string)($row['category_name'] ?? $row['category_name_snapshot'] ?? ''),
        'related_type' => (string)($row['related_type'] ?? ''),
        'related_request_id' => (string)($row['related_request_id'] ?? ''),
        'subject' => (string)($row['subject'] ?? ''),
        'status' => $status,
        'status_label' => support_status_label($status),
        'attachment_count' => (int)($row['attachment_count'] ?? 0),
        'last_message_preview' => (string)($row['last_message_preview'] ?? ''),
        'last_message_by' => (string)($row['last_message_by'] ?? ''),
        'admin_unread' => !empty($row['admin_unread']),
        'user_unread' => !empty($row['user_unread']),
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
        'last_message_at' => (int)($row['last_message_at'] ?? $row['updated_at'] ?? 0),
    ];
}

function support_public_message(array $row): array
{
    $out = [
        'message_id' => (string)($row['message_id'] ?? ''),
        'ticket_id' => (string)($row['ticket_id'] ?? ''),
        'sender_type' => (string)($row['sender_type'] ?? ''),
        'sender_uid' => (string)($row['sender_uid'] ?? ''),
        'sender_name' => (string)($row['sender_name'] ?? ''),
        'sender_telegram_id' => (string)($row['sender_telegram_id'] ?? ''),
        'source' => (string)($row['source'] ?? ''),
        'idempotency_key' => (string)($row['idempotency_key'] ?? ''),
        'message' => (string)($row['message'] ?? ''),
        'attachment_ids' => array_values(array_filter((array)($row['attachment_ids'] ?? []), 'is_string')),
        'created_at' => (int)($row['created_at'] ?? 0),
    ];
    $replyTo = (string)($row['reply_to_message_id'] ?? '');
    if ($replyTo !== '') {
        $out['reply_to_message_id'] = $replyTo;
        $preview = $row['reply_preview'] ?? [];
        if (is_array($preview)) {
            $out['reply_preview'] = [
                'message_id' => (string)($preview['message_id'] ?? $replyTo),
                'sender_type' => (string)($preview['sender_type'] ?? ''),
                'sender_name' => (string)($preview['sender_name'] ?? ''),
                'message' => (string)($preview['message'] ?? ''),
                'attachment_count' => (int)($preview['attachment_count'] ?? 0),
                'created_at' => (int)($preview['created_at'] ?? 0),
            ];
        }
    }
    return $out;
}

function support_public_attachment(array $row): array
{
    return [
        'attachment_id' => (string)($row['attachment_id'] ?? ''),
        'ticket_id' => (string)($row['ticket_id'] ?? ''),
        'message_id' => (string)($row['message_id'] ?? ''),
        'original_name' => (string)($row['original_name'] ?? ''),
        'mime' => (string)($row['mime'] ?? ''),
        'size' => (int)($row['size'] ?? 0),
        'download_endpoint' => 'support/attachment.php?ticket_id=' . rawurlencode((string)($row['ticket_id'] ?? '')) . '&attachment_id=' . rawurlencode((string)($row['attachment_id'] ?? '')),
        'created_at' => (int)($row['created_at'] ?? 0),
    ];
}

function support_read_ticket(string $ticketId): array
{
    $ticketId = support_clean_code($ticketId);
    $row = $ticketId !== '' ? fb_get('SUPPORT_TICKETS/' . $ticketId) : null;
    return is_array($row) ? $row : [];
}

function support_user_can_access(array $auth, array $ticket): bool
{
    $uid = support_uid_from_auth($auth);
    return $uid !== '' && $uid === (string)($ticket['uid'] ?? '');
}

function support_private_storage_root(): string
{
    if (defined('SUPPORT_ATTACHMENT_DIR') && trim((string)SUPPORT_ATTACHMENT_DIR) !== '') {
        return rtrim((string)SUPPORT_ATTACHMENT_DIR, DIRECTORY_SEPARATOR);
    }
    $livePrivate = '/home/zedpayhe/private/zpayswift/support_attachments';
    if (PHP_OS_FAMILY !== 'Windows') {
        return $livePrivate;
    }
    if (is_dir(dirname($livePrivate))) {
        return $livePrivate;
    }
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage_private' . DIRECTORY_SEPARATOR . 'support';
}

function support_upload_files(array $files): array
{
    $items = [];
    foreach ($files as $field => $file) {
        if (!is_array($file)) {
            continue;
        }
        if (is_array($file['name'] ?? null)) {
            $count = count((array)$file['name']);
            for ($i = 0; $i < $count; $i++) {
                $items[] = [
                    'name' => $file['name'][$i] ?? '',
                    'type' => $file['type'][$i] ?? '',
                    'tmp_name' => $file['tmp_name'][$i] ?? '',
                    'error' => $file['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $file['size'][$i] ?? 0,
                    'field' => (string)$field,
                ];
            }
            continue;
        }
        $file['field'] = (string)$field;
        $items[] = $file;
    }
    return array_values(array_filter($items, static fn($f) => (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
}

function support_store_attachments(array $files, string $ticketId, string $messageId, string $uid, array $config): array
{
    $uploads = support_upload_files($files);
    $max = (int)($config['max_attachments'] ?? 3);
    if ($uploads === []) {
        return ['ok' => true, 'items' => []];
    }
    if (empty($config['attachments_enabled'])) {
        return ['ok' => false, 'code' => 'SUPPORT_ATTACHMENTS_DISABLED', 'message' => 'Attachments are not available right now.'];
    }
    if (count($uploads) > $max) {
        return ['ok' => false, 'code' => 'SUPPORT_ATTACHMENT_LIMIT', 'message' => 'You can upload up to ' . $max . ' attachments.'];
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $root = support_private_storage_root();
    $dir = $root . DIRECTORY_SEPARATOR . date('Y-m') . DIRECTORY_SEPARATOR . $ticketId;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return ['ok' => false, 'code' => 'SUPPORT_UPLOAD_FAILED', 'message' => 'Attachment upload failed. Please try again.'];
    }

    $stored = [];
    foreach ($uploads as $file) {
        if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            support_cleanup_attachments($stored);
            return ['ok' => false, 'code' => 'SUPPORT_UPLOAD_FAILED', 'message' => 'Attachment upload failed. Please try again.'];
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp) || $size <= 0 || $size > (int)$config['max_file_size']) {
            support_cleanup_attachments($stored);
            return ['ok' => false, 'code' => 'SUPPORT_ATTACHMENT_INVALID', 'message' => 'Attachment file is invalid or too large.'];
        }
        $mime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($tmp);
        }
        if (!isset($allowed[$mime])) {
            support_cleanup_attachments($stored);
            return ['ok' => false, 'code' => 'SUPPORT_ATTACHMENT_TYPE_INVALID', 'message' => 'Only JPG, PNG and WEBP screenshots are allowed.'];
        }
        $imageInfo = @getimagesize($tmp);
        if (!is_array($imageInfo)) {
            support_cleanup_attachments($stored);
            return ['ok' => false, 'code' => 'SUPPORT_ATTACHMENT_TYPE_INVALID', 'message' => 'Only valid image screenshots are allowed.'];
        }
        $id = support_attachment_id();
        $fileName = $id . '.' . $allowed[$mime];
        $target = $dir . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($tmp, $target)) {
            support_cleanup_attachments($stored);
            return ['ok' => false, 'code' => 'SUPPORT_UPLOAD_FAILED', 'message' => 'Attachment upload failed. Please try again.'];
        }
        @chmod($target, 0600);
        $relative = date('Y-m') . '/' . $ticketId . '/' . $fileName;
        $stored[] = [
            'attachment_id' => $id,
            'ticket_id' => $ticketId,
            'message_id' => $messageId,
            'uid' => $uid,
            'original_name' => support_clean_text($file['name'] ?? 'screenshot', 120),
            'mime' => $mime,
            'size' => $size,
            'relative_path' => $relative,
            'created_at' => support_now(),
        ];
    }
    return ['ok' => true, 'items' => $stored];
}

function support_cleanup_attachments(array $items): void
{
    $root = support_private_storage_root();
    foreach ($items as $row) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)($row['relative_path'] ?? ''));
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function support_attachment_absolute_path(array $row): string
{
    $root = realpath(support_private_storage_root());
    $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)($row['relative_path'] ?? ''));
    $path = $root !== false ? $root . DIRECTORY_SEPARATOR . $relative : '';
    $real = $path !== '' ? realpath($path) : false;
    if ($root === false || $real === false || strpos($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
        return '';
    }
    return is_file($real) ? $real : '';
}

function support_attachment_rows_for_ids(string $ticketId, array $ids = []): array
{
    $rows = fb_get('SUPPORT_ATTACHMENTS/' . support_clean_code($ticketId));
    $rows = is_array($rows) ? $rows : [];
    $allowed = [];
    foreach ($ids as $id) {
        $id = support_clean_text($id, 40);
        if ($id !== '') {
            $allowed[$id] = true;
        }
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $attachmentId = (string)($row['attachment_id'] ?? '');
        if ($attachmentId === '' || ($allowed !== [] && empty($allowed[$attachmentId]))) {
            continue;
        }
        if (!in_array((string)($row['mime'] ?? ''), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

function support_record_user_notification(
    string $uid,
    string $ticketId,
    string $title,
    string $message,
    string $type = 'SUPPORT_REPLY',
    string $messageId = ''
): void
{
    $uid = trim($uid);
    $ticketId = support_clean_code($ticketId);
    if ($uid === '' || $ticketId === '') {
        return;
    }
    $type = support_clean_code($type);
    if ($type === '') {
        $type = 'SUPPORT_REPLY';
    }
    $messageId = support_clean_code($messageId);
    $dedupe = $type . ':' . $ticketId . ($messageId !== '' ? ':' . $messageId : '');
    notification_record_user(
        $uid,
        $type,
        $title,
        $message,
        'SUPPORT_TICKET',
        $ticketId,
        $dedupe,
        [
            'ticket_id' => $ticketId,
            'message_id' => $messageId,
        ]
    );
}

function support_mark_user_ticket_notifications_read(string $uid, string $ticketId): void
{
    $uid = trim($uid);
    $ticketId = support_clean_code($ticketId);
    if ($uid === '' || $ticketId === '') {
        return;
    }
    notification_mark_entity_read($uid, 'SUPPORT_TICKET', $ticketId);
    $rows = fb_get('SUPPORT_USER_NOTIFICATIONS/' . $uid);
    if (!is_array($rows)) {
        return;
    }
    foreach ($rows as $id => $row) {
        if (is_array($row) && (string)($row['ticket_id'] ?? '') === $ticketId && empty($row['read'])) {
            fb_patch('SUPPORT_USER_NOTIFICATIONS/' . $uid . '/' . $id, [
                'read' => true,
                'read_at' => support_now(),
            ]);
        }
    }
}

function support_record_admin_notification(string $ticketId, string $title, string $message): void
{
    $ticketId = support_clean_code($ticketId);
    if ($ticketId === '') {
        return;
    }
    $id = 'SAN' . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    fb_put('SUPPORT_ADMIN_NOTIFICATIONS/' . $id, [
        'notification_id' => $id,
        'type' => 'SUPPORT',
        'ticket_id' => $ticketId,
        'title' => support_clean_text($title, 80),
        'message' => support_clean_text($message, 180),
        'read' => false,
        'created_at' => support_now(),
    ]);
}

function support_auto_message_idempotency_path(string $ticketId, string $key): string
{
    return 'SUPPORT_AUTO_MESSAGE_IDEMPOTENCY/' . support_clean_code($ticketId) . '/' . hash('sha256', $key);
}

function support_save_auto_message(string $ticketId, string $idempotencyKey, string $message, bool $patchTicket = false): array
{
    $ticketId = support_clean_code($ticketId);
    $idempotencyKey = support_clean_text($idempotencyKey, 160);
    $message = trim(strip_tags((string)$message));
    if (function_exists('mb_substr')) {
        $message = mb_substr($message, 0, 1200);
    } else {
        $message = substr($message, 0, 1200);
    }
    if ($ticketId === '' || $idempotencyKey === '' || $message === '') {
        return ['ok' => false, 'code' => 'SUPPORT_AUTO_MESSAGE_INVALID'];
    }

    $ticket = support_read_ticket($ticketId);
    if ($ticket === []) {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_NOT_FOUND'];
    }

    $path = support_auto_message_idempotency_path($ticketId, $idempotencyKey);
    $claim = support_reply_claim_operation($path, $ticketId, 'SYSTEM', 'SUPPORT', [
        'idempotency_key' => $idempotencyKey,
        'source' => 'SYSTEM',
        'payload_hash' => hash('sha256', $ticketId . '|SYSTEM|' . $message),
    ]);
    if (empty($claim['ok'])) {
        if (!empty($claim['in_progress'])) {
            $replay = support_reply_wait_for_operation($path, $ticketId);
            if ($replay !== []) {
                return ['ok' => true, 'duplicate' => true, 'message_id' => (string)$replay['message_id']];
            }
        }
        return ['ok' => false, 'code' => (string)($claim['code'] ?? 'SUPPORT_AUTO_MESSAGE_SAVE_FAILED')];
    }
    if (!empty($claim['duplicate'])) {
        return ['ok' => true, 'duplicate' => true, 'message_id' => (string)$claim['message_id']];
    }

    $now = support_now();
    $messageId = (string)$claim['message_id'];
    $row = [
        'message_id' => $messageId,
        'ticket_id' => $ticketId,
        'sender_uid' => 'SYSTEM',
        'sender_type' => 'SUPPORT',
        'sender_name' => 'Z-Pay Swift Support',
        'source' => 'SYSTEM',
        'idempotency_key' => $idempotencyKey,
        'message' => $message,
        'attachment_ids' => [],
        'created_at' => $now,
        'read_by_user' => false,
        'read_by_admin' => true,
        'system' => true,
    ];

    $operation = array_merge((array)($claim['operation'] ?? []), [
        'status' => 'COMPLETED',
        'idempotency_key' => $idempotencyKey,
        'lease_until' => 0,
        'committed_at' => $now,
        'completed_at' => $now,
        'updated_at' => $now,
        'side_effect_status' => 'COMPLETED',
    ]);
    $writes = [
        'SUPPORT_MESSAGES/' . $ticketId . '/' . $messageId => $row,
        $path => $operation,
    ];
    if ($patchTicket) {
        $ticketPatch = [
            'updated_at' => $now,
            'last_message_at' => $now,
            'last_message_by' => 'SUPPORT',
            'last_message_preview' => $message,
            'user_unread' => true,
        ];
        foreach ($ticketPatch as $field => $value) {
            $writes['SUPPORT_TICKETS/' . $ticketId . '/' . $field] = $value;
        }
        $indexPath = 'SUPPORT_USER_INDEX/' . (string)($ticket['uid'] ?? '') . '/' . $ticketId;
        $writes[$indexPath . '/ticket_id'] = $ticketId;
        $writes[$indexPath . '/updated_at'] = $now;
        $writes[$indexPath . '/status'] = support_clean_code($ticket['status'] ?? 'OPEN');
    }

    if (!fb_patch('', $writes)) {
        support_reply_mark_retryable($path, (string)($claim['owner_token'] ?? ''), 'SUPPORT_AUTO_MESSAGE_SAVE_FAILED');
        return ['ok' => false, 'code' => 'SUPPORT_AUTO_MESSAGE_SAVE_FAILED'];
    }

    return ['ok' => true, 'message_id' => $messageId, 'message' => $row];
}

function support_save_auto_first_message(string $ticketId): void
{
    support_save_auto_message(
        $ticketId,
        'AUTO_FIRST_REPLY:' . support_clean_code($ticketId),
        "আপনার সাপোর্ট অনুরোধটি আমরা পেয়েছি।\nআমাদের সাপোর্ট টিম বিষয়টি যাচাই করে যত দ্রুত সম্ভব উত্তর দেবে।",
        false
    );
}

function support_save_auto_close_message(string $ticketId): array
{
    return support_save_auto_message(
        $ticketId,
        'AUTO_CLOSE_REPLY:' . support_clean_code($ticketId),
        "এই সাপোর্ট কথোপকথনটি বন্ধ করা হয়েছে।\nআপনি আগের বার্তাগুলো দেখতে পারবেন, তবে নতুন উত্তর পাঠাতে পারবেন না।",
        true
    );
}

function support_send_user_push(string $uid, string $title, string $body, array $data, string $dedupeKey): void
{
    if ($uid === '' || !function_exists('fcm_send_to_user')) {
        return;
    }
    fcm_send_to_user($uid, $title, $body, $data, $dedupeKey);
}

function support_telegram_clear_ticket_reply_context(string $ticketId): void
{
    $ticketId = support_clean_code($ticketId);
    if ($ticketId === '') {
        return;
    }
    $rows = fb_get('SUPPORT_TELEGRAM_REPLY_CONTEXT');
    if (!is_array($rows)) {
        return;
    }
    foreach ($rows as $key => $row) {
        if (is_array($row) && support_clean_code($row['ticket_id'] ?? '') === $ticketId) {
            fb_delete('SUPPORT_TELEGRAM_REPLY_CONTEXT/' . $key);
        }
    }
}

function support_recent_requests_for_uid(string $uid, int $limit = 30): array
{
    $items = [];
    $month = month_key();
    $sources = [
        ['TOPUP_HISTORY/' . $uid . '/' . $month, 'MOBILE_TOPUP', 'Mobile Top-Up'],
        ['MFS_HISTORY/' . $uid . '/' . $month, 'MFS', 'MFS'],
        ['TRANSFER_HISTORY/' . $uid, 'ZPAY_TRANSFER', 'Z-Pay Transfer'],
        ['ADD_MONEY_BY_USER/' . $uid, 'ADD_MONEY', 'Add Money'],
        ['BUNDLE_HISTORY/' . $uid . '/' . $month, 'BUNDLE', 'Bundle'],
    ];
    foreach ($sources as [$path, $type, $label]) {
        $rows = fb_get($path);
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string)($row['request_id'] ?? $row['transfer_id'] ?? $row['ticket_id'] ?? $key);
            if ($id === '') {
                continue;
            }
            $amount = (string)($row['amount_text'] ?? $row['total_paid_text'] ?? $row['wallet_debit_text'] ?? $row['amount'] ?? '');
            $items[] = [
                'request_id' => $id,
                'type' => (string)($row['type'] ?? $type),
                'label' => $label,
                'title' => $id . ' - ' . $label . ($amount !== '' ? ' - ' . $amount : ''),
                'created_at' => (int)($row['created_at'] ?? $row['updated_at'] ?? 0),
            ];
        }
    }
    usort($items, static fn($a, $b) => ((int)$b['created_at'] <=> (int)$a['created_at']));
    return array_slice($items, 0, max(1, min(100, $limit)));
}

function support_verify_related_request(string $uid, string $requestId): bool
{
    $requestId = trim($requestId);
    if ($requestId === '') {
        return true;
    }
    foreach (support_recent_requests_for_uid($uid, 100) as $row) {
        if ((string)$row['request_id'] === $requestId) {
            return true;
        }
    }
    foreach ([
        'TOPUP_REQUESTS/PENDING/' . $requestId,
        'REQUEST_STATUS/' . $requestId,
        'MFS_REQUESTS/' . $requestId,
        'ADD_MONEY_REQUESTS/' . $requestId,
        'ADD_MONEY_BY_USER/' . $uid . '/' . $requestId,
        'TRANSFERS/' . $requestId,
    ] as $path) {
        $row = fb_get($path);
        if (is_array($row) && in_array($uid, [
            (string)($row['uid'] ?? ''),
            (string)($row['user_uid'] ?? ''),
            (string)($row['sender_uid'] ?? ''),
            (string)($row['receiver_uid'] ?? ''),
        ], true)) {
            return true;
        }
    }
    return false;
}

function support_create_ticket(array $auth, array $body, array $files = []): array
{
    $config = support_config();
    if (empty($config['contact_us_enabled']) || empty($config['ticket_enabled'])) {
        return ['ok' => false, 'code' => 'SUPPORT_DISABLED', 'message' => 'Support requests are not available right now.', 'status' => 503];
    }

    $uid = support_uid_from_auth($auth);
    $user = support_user_from_auth($auth);
    if ($uid === '') {
        return ['ok' => false, 'code' => 'AUTH_REQUIRED', 'message' => 'Login required.', 'status' => 401];
    }

    $idem = support_clean_text($body['idempotency_key'] ?? '', 120);
    if ($idem !== '') {
        $existing = fb_get('SUPPORT_IDEMPOTENCY/' . $uid . '/' . hash('sha256', $idem));
        $existingId = is_array($existing) ? (string)($existing['ticket_id'] ?? '') : (string)$existing;
        if ($existingId !== '') {
            $ticket = support_read_ticket($existingId);
            if ($ticket !== []) {
                return ['ok' => true, 'duplicate' => true, 'ticket' => $ticket];
            }
        }
    }

    $activeTicket = support_find_active_ticket_for_uid($uid);
    if ($activeTicket !== []) {
        return support_active_ticket_error($activeTicket);
    }

    $rate = (int)($config['ticket_rate_limit_seconds'] ?? 0);
    $last = (int)(fb_get('SUPPORT_RATE_LIMIT/' . $uid . '/last_created_at') ?? 0);
    if ($rate > 0 && $last > 0 && support_now() - $last < $rate) {
        return ['ok' => false, 'code' => 'SUPPORT_RATE_LIMITED', 'message' => 'Please wait before submitting another support request.', 'status' => 429];
    }

    $category = support_category((string)($body['category_code'] ?? $body['category'] ?? ''));
    if ($category === [] || empty($category['active'])) {
        return ['ok' => false, 'code' => 'SUPPORT_CATEGORY_INVALID', 'message' => 'Please select a valid support category.', 'status' => 422];
    }
    $subject = support_clean_text($body['subject'] ?? '', 120);
    $message = support_clean_text($body['message'] ?? $body['body'] ?? '', 2500);
    if ($subject === '') {
        return ['ok' => false, 'code' => 'SUPPORT_SUBJECT_REQUIRED', 'message' => 'Please enter a subject.', 'status' => 422];
    }
    if ($message === '' || strlen($message) < 4) {
        return ['ok' => false, 'code' => 'SUPPORT_MESSAGE_REQUIRED', 'message' => 'Please describe your issue.', 'status' => 422];
    }
    $relatedId = support_clean_text($body['related_request_id'] ?? $body['request_id'] ?? '', 80);
    $relatedType = support_clean_code($body['related_type'] ?? '');
    if ($relatedId !== '' && !support_verify_related_request($uid, $relatedId)) {
        return ['ok' => false, 'code' => 'SUPPORT_REQUEST_NOT_OWNED', 'message' => 'The selected request could not be verified.', 'status' => 403];
    }

    $now = support_now();
    $ticketId = support_ticket_id();
    $messageId = support_message_id();
    $stored = support_store_attachments($files, $ticketId, $messageId, $uid, $config);
    if (empty($stored['ok'])) {
        return ['ok' => false, 'code' => $stored['code'], 'message' => $stored['message'], 'status' => 422];
    }
    $attachments = (array)($stored['items'] ?? []);
    $attachmentIds = array_values(array_map(static fn($row) => (string)$row['attachment_id'], $attachments));

    $claim = support_claim_active_ticket_slot($uid, $ticketId, $now);
    if (empty($claim['ok'])) {
        support_cleanup_attachments($attachments);
        $activeTicket = is_array($claim['active_ticket'] ?? null) ? $claim['active_ticket'] : [];
        if ($activeTicket !== []) {
            return support_active_ticket_error($activeTicket);
        }
        return [
            'ok' => false,
            'code' => 'SUPPORT_ACTIVE_TICKET_CHECK_FAILED',
            'message' => 'Support availability could not be confirmed. Please try again.',
            'status' => 503,
        ];
    }

    $ticket = [
        'ticket_id' => $ticketId,
        'uid' => $uid,
        'user_name' => (string)($user['name'] ?? ''),
        'user_phone' => (string)($user['phone'] ?? ''),
        'user_email' => (string)($user['email'] ?? ''),
        'category_code' => $category['code'],
        'category_name' => $category['name'],
        'category_name_snapshot' => $category['name'],
        'related_type' => $relatedType,
        'related_request_id' => $relatedId,
        'subject' => $subject,
        'status' => 'OPEN',
        'attachment_count' => count($attachments),
        'created_at' => $now,
        'updated_at' => $now,
        'last_message_at' => $now,
        'last_message_by' => 'USER',
        'last_message_preview' => $message,
        'admin_unread' => true,
        'user_unread' => false,
    ];
    $msg = [
        'message_id' => $messageId,
        'ticket_id' => $ticketId,
        'sender_uid' => $uid,
        'sender_type' => 'USER',
        'sender_name' => (string)($user['name'] ?? ''),
        'source' => 'ANDROID',
        'idempotency_key' => $idem,
        'message' => $message,
        'attachment_ids' => $attachmentIds,
        'created_at' => $now,
        'read_by_user' => true,
        'read_by_admin' => false,
    ];
    $ok = fb_put('SUPPORT_TICKETS/' . $ticketId, $ticket)
        && fb_put('SUPPORT_USER_INDEX/' . $uid . '/' . $ticketId, ['ticket_id' => $ticketId, 'updated_at' => $now, 'status' => 'OPEN'])
        && fb_put('SUPPORT_MESSAGES/' . $ticketId . '/' . $messageId, $msg);

    if (!$ok) {
        support_cleanup_attachments($attachments);
        support_release_active_ticket_slot($uid, $ticketId);
        return ['ok' => false, 'code' => 'SUPPORT_CREATE_FAILED', 'message' => 'Support request could not be submitted.', 'status' => 500];
    }
    foreach ($attachments as $attachment) {
        fb_put('SUPPORT_ATTACHMENTS/' . $ticketId . '/' . $attachment['attachment_id'], $attachment);
    }
    if ($idem !== '') {
        fb_put('SUPPORT_IDEMPOTENCY/' . $uid . '/' . hash('sha256', $idem), ['ticket_id' => $ticketId, 'created_at' => $now]);
    }
    fb_put('SUPPORT_RATE_LIMIT/' . $uid, ['last_created_at' => $now]);
    support_save_auto_first_message($ticketId);
    support_notify_telegram_new_ticket($ticket, $msg);
    return ['ok' => true, 'ticket' => $ticket];
}

function support_notify_telegram_new_ticket(array $ticket, array $canonicalMessage = []): void
{
    $ticketId = (string)($ticket['ticket_id'] ?? '');
    if ($ticketId === '') {
        return;
    }
    $message = support_telegram_ticket_message($ticket);
    $queueId = telegram_queue_create('SUPPORT_TICKET', $ticketId, $message);
    $attachmentIds = (array)($canonicalMessage['attachment_ids'] ?? []);
    $attachments = support_attachment_rows_for_ids($ticketId, $attachmentIds);
    $sent = support_telegram_send_canonical_alert(
        $ticketId,
        (string)($canonicalMessage['message_id'] ?? ''),
        $message,
        $attachments,
        support_telegram_keyboard($ticketId)
    );
    if (!empty($sent['ok'])) {
        telegram_queue_mark_sent($queueId);
        $tgResult = is_array($sent['data']['result'] ?? null) ? $sent['data']['result'] : [];
        fb_patch('SUPPORT_TICKETS/' . $ticketId, [
            'telegram_sent' => true,
            'telegram_sent_at' => support_now(),
            'telegram_chat_id' => (string)($tgResult['chat']['id'] ?? support_telegram_chat_id()),
            'telegram_message_id' => (string)($tgResult['message_id'] ?? ''),
        ]);
    } else {
        telegram_queue_mark_failed($queueId, (string)($sent['message'] ?? 'Telegram send failed'));
        fb_patch('SUPPORT_TICKETS/' . $ticketId, ['telegram_sent' => false, 'telegram_error' => substr((string)($sent['message'] ?? ''), 0, 200)]);
    }
}

function support_notify_telegram_user_reply(array $ticket, array $message, array $attachments = []): array
{
    $ticketId = (string)($ticket['ticket_id'] ?? '');
    if ($ticketId === '') {
        return ['ok' => false, 'message' => 'Support ticket is unavailable'];
    }
    $text = "New User Reply\n\n"
        . "Ticket: " . $ticketId . "\n"
        . "User: " . support_clean_text($ticket['user_name'] ?? '', 80) . "\n"
        . "Subject: " . support_telegram_message_excerpt((string)($ticket['subject'] ?? ''), 180) . "\n\n"
        . "Message:\n" . support_telegram_message_excerpt((string)($message['message'] ?? ''), 700);
    return support_telegram_send_canonical_alert(
        $ticketId,
        (string)($message['message_id'] ?? ''),
        $text,
        $attachments,
        support_telegram_keyboard($ticketId)
    );
}

function support_telegram_send_message(string $message, array $replyMarkup = []): array
{
    $token = support_telegram_bot_token();
    $chatId = support_telegram_chat_id();
    if ($token === '' || $chatId === '') {
        return [
            'ok' => false,
            'message' => 'Support Telegram token/chat id not configured',
        ];
    }

    $payload = [
        'chat_id' => $chatId,
        'text' => $message,
        'disable_web_page_preview' => true,
    ];
    if ($replyMarkup !== []) {
        $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_SLASHES);
    }

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'message' => $err ?: 'Telegram request failed'];
    }
    $json = json_decode($raw, true);
    if ($status >= 200 && $status < 300 && is_array($json) && !empty($json['ok'])) {
        return ['ok' => true, 'message' => 'Telegram sent', 'data' => $json];
    }
    return ['ok' => false, 'message' => is_array($json) ? (string)($json['description'] ?? 'Telegram send failed') : 'Telegram send failed'];
}

function support_telegram_send_photo(array $attachment, string $caption = ''): array
{
    $token = support_telegram_bot_token();
    $chatId = support_telegram_chat_id();
    if ($token === '' || $chatId === '') {
        return ['ok' => false, 'message' => 'Support Telegram token/chat id not configured'];
    }
    $path = support_attachment_absolute_path($attachment);
    $mime = (string)($attachment['mime'] ?? '');
    if ($path === '' || !is_file($path) || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return ['ok' => false, 'message' => 'Attachment unavailable'];
    }

    $fileName = support_clean_text($attachment['original_name'] ?? 'screenshot', 80) ?: 'screenshot';
    $payload = [
        'chat_id' => $chatId,
        'photo' => new CURLFile($path, $mime, $fileName),
        'caption' => support_telegram_message_excerpt($caption, 900),
    ];

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendPhoto');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'message' => $err ?: 'Telegram photo send failed'];
    }
    $json = json_decode($raw, true);
    if ($status >= 200 && $status < 300 && is_array($json) && !empty($json['ok'])) {
        return ['ok' => true, 'message' => 'Telegram photo sent', 'data' => $json];
    }
    return ['ok' => false, 'message' => is_array($json) ? (string)($json['description'] ?? 'Telegram photo send failed') : 'Telegram photo send failed'];
}

function support_telegram_send_media_group(array $attachments, string $caption = ''): array
{
    $token = support_telegram_bot_token();
    $chatId = support_telegram_chat_id();
    if ($token === '' || $chatId === '') {
        return ['ok' => false, 'message' => 'Support Telegram token/chat id not configured'];
    }

    $payload = ['chat_id' => $chatId];
    $media = [];
    $index = 0;
    foreach (array_slice($attachments, 0, 10) as $attachment) {
        $path = support_attachment_absolute_path($attachment);
        $mime = (string)($attachment['mime'] ?? '');
        if ($path === '' || !is_file($path) || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            continue;
        }
        $field = 'photo' . $index;
        $fileName = support_clean_text($attachment['original_name'] ?? 'screenshot', 80) ?: 'screenshot';
        $payload[$field] = new CURLFile($path, $mime, $fileName);
        $item = [
            'type' => 'photo',
            'media' => 'attach://' . $field,
        ];
        if ($index === 0) {
            $item['caption'] = support_telegram_message_excerpt($caption, 900);
        }
        $media[] = $item;
        $index++;
    }

    if ($media === []) {
        return ['ok' => false, 'message' => 'Attachment unavailable'];
    }
    $payload['media'] = json_encode($media, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMediaGroup');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'message' => $err ?: 'Telegram media group send failed'];
    }
    $json = json_decode($raw, true);
    if ($status >= 200 && $status < 300 && is_array($json) && !empty($json['ok'])) {
        return ['ok' => true, 'message' => 'Telegram media group sent', 'data' => $json];
    }
    return ['ok' => false, 'message' => is_array($json) ? (string)($json['description'] ?? 'Telegram media group send failed') : 'Telegram media group send failed'];
}

function support_telegram_result_rows(array $sent): array
{
    $result = $sent['data']['result'] ?? null;
    if (!is_array($result)) {
        return [];
    }
    if (isset($result['message_id'])) {
        return [$result];
    }
    return array_values(array_filter($result, 'is_array'));
}

function support_telegram_map_sent_messages(array $sent, string $ticketId, string $canonicalMessageId): void
{
    foreach (support_telegram_result_rows($sent) as $row) {
        $messageId = (string)($row['message_id'] ?? '');
        $chatId = (string)($row['chat']['id'] ?? support_telegram_chat_id());
        support_telegram_store_message_map($chatId, $messageId, $ticketId, $canonicalMessageId);
    }
}

function support_telegram_send_action_message(string $ticketId, array $replyMarkup): array
{
    return support_telegram_send_message('Actions for ticket ' . support_clean_code($ticketId), $replyMarkup);
}

function support_telegram_send_canonical_alert(string $ticketId, string $canonicalMessageId, string $text, array $attachments, array $replyMarkup): array
{
    $ticketId = support_clean_code($ticketId);
    $canonicalMessageId = support_clean_text($canonicalMessageId, 80);
    $attachments = array_values($attachments);
    if ($attachments === []) {
        $sent = support_telegram_send_message($text, $replyMarkup);
        if (!empty($sent['ok'])) {
            support_telegram_map_sent_messages($sent, $ticketId, $canonicalMessageId);
        }
        return $sent;
    }

    $mediaSent = count($attachments) === 1
        ? support_telegram_send_photo($attachments[0], $text)
        : support_telegram_send_media_group($attachments, $text);
    if (!empty($mediaSent['ok'])) {
        support_telegram_map_sent_messages($mediaSent, $ticketId, $canonicalMessageId);
        $actionSent = support_telegram_send_action_message($ticketId, $replyMarkup);
        if (!empty($actionSent['ok'])) {
            support_telegram_map_sent_messages($actionSent, $ticketId, $canonicalMessageId);
            return $actionSent + ['media' => $mediaSent];
        }
        return $mediaSent;
    }

    $fallback = support_telegram_send_message($text . "\n\nAttachment unavailable", $replyMarkup);
    if (!empty($fallback['ok'])) {
        support_telegram_map_sent_messages($fallback, $ticketId, $canonicalMessageId);
    }
    return $fallback;
}

function support_telegram_send_attachment_photos(string $ticketId, array $attachmentIds, string $caption): void
{
    foreach (array_slice(support_attachment_rows_for_ids($ticketId, $attachmentIds), 0, 3) as $row) {
        $sent = support_telegram_send_photo($row, $caption);
        if (empty($sent['ok'])) {
            support_telegram_send_message('Attachment unavailable for ticket ' . support_clean_code($ticketId) . '.');
        }
    }
}

function support_telegram_edit_ticket_message(array $ticket): void
{
    $ticketId = (string)($ticket['ticket_id'] ?? '');
    $token = support_telegram_bot_token();
    $chatId = (string)($ticket['telegram_chat_id'] ?? support_telegram_chat_id());
    $messageId = (string)($ticket['telegram_message_id'] ?? '');
    if ($ticketId === '' || $token === '' || $chatId === '' || $messageId === '') {
        return;
    }

    $payload = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => support_telegram_ticket_message($ticket),
        'reply_markup' => json_encode(support_telegram_keyboard($ticketId), JSON_UNESCAPED_SLASHES),
        'disable_web_page_preview' => true,
    ];

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/editMessageText');
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

function support_list_for_uid(string $uid, string $status = '', int $limit = 50): array
{
    $idx = fb_get('SUPPORT_USER_INDEX/' . $uid);
    $rows = [];
    if (is_array($idx)) {
        foreach ($idx as $ticketId => $meta) {
            $ticket = support_read_ticket((string)$ticketId);
            if ($ticket === []) {
                continue;
            }
            if ($status !== '' && support_clean_code($ticket['status'] ?? '') !== support_clean_code($status)) {
                continue;
            }
            $rows[] = support_public_ticket($ticket);
        }
    }
    usort($rows, static fn($a, $b) => ((int)$b['last_message_at'] <=> (int)$a['last_message_at']));
    return array_slice($rows, 0, max(1, min(100, $limit)));
}

function support_details_for_user(array $auth, string $ticketId): array
{
    $ticket = support_read_ticket($ticketId);
    if ($ticket === []) {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_NOT_FOUND', 'message' => 'Support ticket was not found.', 'status' => 404];
    }
    if (!support_user_can_access($auth, $ticket)) {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_FORBIDDEN', 'message' => 'This ticket is not available.', 'status' => 403];
    }
    return ['ok' => true] + support_details_payload($ticket);
}

function support_details_payload(array $ticket): array
{
    $ticketId = (string)$ticket['ticket_id'];
    $messages = fb_get('SUPPORT_MESSAGES/' . $ticketId);
    $attachments = fb_get('SUPPORT_ATTACHMENTS/' . $ticketId);
    $messages = is_array($messages) ? $messages : [];
    $attachments = is_array($attachments) ? $attachments : [];
    uasort($messages, static fn($a, $b) => ((int)($a['created_at'] ?? 0) <=> (int)($b['created_at'] ?? 0)));
    return [
        'ticket' => support_public_ticket($ticket),
        'messages' => array_values(array_map('support_public_message', $messages)),
        'attachments' => array_values(array_map('support_public_attachment', $attachments)),
    ];
}

function support_reply_lease_seconds(): int
{
    $seconds = defined('SUPPORT_REPLY_CLAIM_LEASE_SECONDS')
        ? (int)constant('SUPPORT_REPLY_CLAIM_LEASE_SECONDS')
        : 60;
    return max(15, min(300, $seconds));
}

function support_reply_operation_path(string $uid, string $ticketId, string $senderType, array $meta): string
{
    $idempotencyKey = support_clean_text($meta['idempotency_key'] ?? '', 160);
    if ($idempotencyKey === '') {
        return '';
    }

    $hash = hash('sha256', $idempotencyKey);
    $source = support_clean_code($meta['source'] ?? '');
    if ($source === 'TELEGRAM') {
        // Telegram's canonical key is already a SHA-256 digest. Keep its
        // historical Firebase path stable while still hashing non-canonical input.
        $telegramKey = preg_match('/^[a-f0-9]{64}$/i', $idempotencyKey) === 1
            ? strtolower($idempotencyKey)
            : $hash;
        return 'SUPPORT_TELEGRAM_REPLY_IDEMPOTENCY/' . $telegramKey;
    }
    if (in_array(support_clean_code($senderType), ['ADMIN', 'SUPPORT'], true)) {
        return 'SUPPORT_ADMIN_REPLY_IDEMPOTENCY/' . support_clean_code($ticketId) . '/' . $hash;
    }

    return 'SUPPORT_USER_REPLY_IDEMPOTENCY/' . trim($uid) . '/' . support_clean_code($ticketId) . '/' . $hash;
}

function support_reply_operation_replay(string $ticketId, array $operation): array
{
    $operationTicketId = support_clean_code($operation['ticket_id'] ?? '');
    if ($operationTicketId === '' || !hash_equals(support_clean_code($ticketId), $operationTicketId)) {
        return [];
    }
    $messageId = support_clean_code($operation['message_id'] ?? '');
    $ticket = support_read_ticket($ticketId);
    $message = $messageId !== '' ? fb_get('SUPPORT_MESSAGES/' . support_clean_code($ticketId) . '/' . $messageId) : null;
    if ($ticket === [] || !is_array($message)) {
        return [];
    }

    return [
        'ok' => true,
        'duplicate' => true,
        'idempotent_replay' => true,
        'message_id' => $messageId,
    ] + support_details_payload($ticket);
}

function support_reply_payload_hash(
    string $ticketId,
    string $uid,
    string $senderType,
    string $message,
    array $files,
    array $meta
): string {
    $uploads = [];
    foreach (support_upload_files($files) as $file) {
        $tmp = (string)($file['tmp_name'] ?? '');
        $uploads[] = [
            'size' => (int)($file['size'] ?? 0),
            'hash' => $tmp !== '' && is_file($tmp) ? (string)hash_file('sha256', $tmp) : '',
        ];
    }

    return hash('sha256', (string)json_encode([
        'ticket_id' => support_clean_code($ticketId),
        'uid' => trim($uid),
        'sender_type' => support_clean_code($senderType),
        'source' => support_clean_code($meta['source'] ?? ''),
        'reply_to_message_id' => support_clean_code($meta['reply_to_message_id'] ?? ''),
        'message' => $message,
        'uploads' => $uploads,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function support_reply_claim_operation(
    string $path,
    string $ticketId,
    string $uid,
    string $senderType,
    array $meta
): array {
    if ($path === '') {
        return [
            'ok' => true,
            'claimed' => true,
            'path' => '',
            'owner_token' => '',
            'message_id' => support_message_id(),
            'operation' => [],
        ];
    }

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'SUPPORT_REPLY_CLAIM_FAILED', 'message' => 'Reply could not be sent.', 'status' => 500];
        }

        $current = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        $payloadHash = trim((string)($meta['payload_hash'] ?? ''));
        $existingPayloadHash = trim((string)($current['payload_hash'] ?? ''));
        if ($existingPayloadHash !== '' && ($payloadHash === '' || !hash_equals($existingPayloadHash, $payloadHash))) {
            return [
                'ok' => false,
                'code' => 'SUPPORT_IDEMPOTENCY_CONFLICT',
                'message' => 'This reply key belongs to a different request.',
                'status' => 409,
            ];
        }

        $replay = support_reply_operation_replay($ticketId, $current);
        if ($replay === [] && (string)($current['message_id'] ?? '') !== '' && (string)($current['ticket_id'] ?? '') === '') {
            $replay = support_reply_operation_replay($ticketId, array_merge($current, ['ticket_id' => $ticketId]));
        }
        if ($replay !== []) {
            return $replay + ['claimed' => false, 'path' => $path, 'operation' => $current];
        }

        $now = support_now();
        $status = support_clean_code($current['status'] ?? '');
        $leaseUntil = (int)($current['lease_until'] ?? 0);
        if ($status === 'CLAIMED' && $leaseUntil > $now) {
            return [
                'ok' => false,
                'code' => 'SUPPORT_REPLY_IN_PROGRESS',
                'message' => 'This reply is still being sent.',
                'status' => 409,
                'in_progress' => true,
                'message_id' => support_clean_code($current['message_id'] ?? ''),
                'path' => $path,
                'operation' => $current,
            ];
        }
        if (in_array($status, ['COMMITTED', 'COMPLETED'], true)) {
            return ['ok' => false, 'code' => 'SUPPORT_REPLY_RECOVERY_REQUIRED', 'message' => 'Reply status could not be confirmed.', 'status' => 503];
        }

        $ownerToken = bin2hex(random_bytes(16));
        $messageId = support_clean_code($current['message_id'] ?? '');
        if ($messageId === '') {
            $messageId = support_message_id();
        }
        $operation = array_merge($current, [
            'ticket_id' => support_clean_code($ticketId),
            'message_id' => $messageId,
            'uid' => trim($uid),
            'sender_type' => support_clean_code($senderType),
            'source' => support_clean_code($meta['source'] ?? ''),
            'idempotency_key_hash' => hash('sha256', support_clean_text($meta['idempotency_key'] ?? '', 160)),
            'payload_hash' => $payloadHash,
            'status' => 'CLAIMED',
            'owner_token' => $ownerToken,
            'created_at' => (int)($current['created_at'] ?? 0) > 0 ? (int)$current['created_at'] : $now,
            'claimed_at' => (int)($current['claimed_at'] ?? 0) > 0 ? (int)$current['claimed_at'] : $now,
            'lease_until' => $now + support_reply_lease_seconds(),
            'updated_at' => $now,
            'attempts' => (int)($current['attempts'] ?? 0) + 1,
            'last_error' => '',
        ]);
        if (support_clean_code($meta['source'] ?? '') === 'TELEGRAM') {
            $operation['admin_telegram_user_id'] = support_clean_text($meta['sender_telegram_id'] ?? '', 80);
            $operation['admin_chat_id'] = support_clean_text($meta['admin_chat_id'] ?? '', 80);
        }

        $write = fb_put_if_match($path, $operation, (string)$snapshot['etag']);
        if (!empty($write['ok'])) {
            return [
                'ok' => true,
                'claimed' => true,
                'path' => $path,
                'owner_token' => $ownerToken,
                'message_id' => $messageId,
                'operation' => $operation,
            ];
        }
        if ((int)($write['status'] ?? 0) !== 412) {
            return ['ok' => false, 'code' => 'SUPPORT_REPLY_CLAIM_FAILED', 'message' => 'Reply could not be sent.', 'status' => 500];
        }
    }

    return ['ok' => false, 'code' => 'SUPPORT_REPLY_IN_PROGRESS', 'message' => 'This reply is still being sent.', 'status' => 409];
}

function support_reply_wait_for_operation(string $path, string $ticketId, int $attempts = 40): array
{
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        if ($attempt > 0) {
            usleep(50000);
        }
        $row = fb_get($path);
        $replay = is_array($row) ? support_reply_operation_replay($ticketId, $row) : [];
        if ($replay !== []) {
            return $replay + ['path' => $path, 'operation' => $row];
        }
    }
    return [];
}

function support_reply_renew_operation(string $path, string $ownerToken): array
{
    if ($path === '') {
        return ['ok' => true, 'operation' => []];
    }
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        $row = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)
            || support_clean_code($row['status'] ?? '') !== 'CLAIMED'
            || $ownerToken === ''
            || !hash_equals($ownerToken, (string)($row['owner_token'] ?? ''))) {
            return ['ok' => false, 'code' => 'SUPPORT_REPLY_CLAIM_LOST'];
        }
        $row['lease_until'] = support_now() + support_reply_lease_seconds();
        $row['updated_at'] = support_now();
        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if (!empty($write['ok'])) {
            return ['ok' => true, 'operation' => $row];
        }
        if ((int)($write['status'] ?? 0) !== 412) {
            break;
        }
    }
    return ['ok' => false, 'code' => 'SUPPORT_REPLY_CLAIM_LOST'];
}

function support_reply_mark_retryable(string $path, string $ownerToken, string $errorCode): void
{
    if ($path === '' || $ownerToken === '') {
        return;
    }
    $snapshot = fb_get_with_etag($path);
    $row = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)
        || support_clean_code($row['status'] ?? '') !== 'CLAIMED'
        || !hash_equals($ownerToken, (string)($row['owner_token'] ?? ''))) {
        return;
    }
    $row['status'] = 'FAILED_RETRYABLE';
    $row['lease_until'] = 0;
    $row['updated_at'] = support_now();
    $row['last_error'] = support_clean_code($errorCode);
    fb_put_if_match($path, $row, (string)$snapshot['etag']);
}

function support_reply_claim_side_effects(string $path): bool
{
    if ($path === '') {
        return true;
    }
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        $row = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)
            || support_clean_code($row['status'] ?? '') !== 'COMMITTED'
            || support_clean_code($row['side_effect_status'] ?? '') !== 'PENDING') {
            return false;
        }
        $row['side_effect_status'] = 'PROCESSING';
        $row['side_effect_started_at'] = support_now();
        $row['updated_at'] = support_now();
        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if (!empty($write['ok'])) {
            return true;
        }
        if ((int)($write['status'] ?? 0) !== 412) {
            return false;
        }
    }
    return false;
}

function support_reply_run_side_effects(array $ticket, array $message, array $attachments): array
{
    $messageId = support_clean_code($message['message_id'] ?? '');
    $isAdmin = in_array(support_clean_code($message['sender_type'] ?? ''), ['ADMIN', 'SUPPORT'], true);
    if ($isAdmin) {
        support_telegram_edit_ticket_message($ticket);
        support_record_user_notification(
            (string)($ticket['uid'] ?? ''),
            (string)($ticket['ticket_id'] ?? ''),
            'Support Reply',
            'Support replied to your ticket.',
            'SUPPORT_REPLY',
            $messageId
        );
        support_send_user_push(
            (string)($ticket['uid'] ?? ''),
            'Support Reply',
            'Support replied to your ticket.',
            [
                'type' => 'SUPPORT_REPLY',
                'ticket_id' => (string)($ticket['ticket_id'] ?? ''),
                'message_id' => $messageId,
            ],
            'SUPPORT_REPLY:' . (string)($ticket['ticket_id'] ?? '') . ':' . $messageId
        );
        return ['ok' => true, 'error' => '', 'stage' => 'ADMIN_NOTIFY_COMPLETED'];
    }

    support_record_admin_notification(
        (string)($ticket['ticket_id'] ?? ''),
        'New User Reply',
        'User replied to support ticket ' . (string)($ticket['ticket_id'] ?? '')
    );
    $telegram = support_notify_telegram_user_reply($ticket, $message, $attachments);
    return [
        'ok' => !empty($telegram['ok']),
        'error' => !empty($telegram['ok']) ? '' : support_clean_code($telegram['message'] ?? 'TELEGRAM_SEND_FAILED'),
        'stage' => !empty($telegram['ok']) ? 'TELEGRAM_COMPLETED' : 'TELEGRAM_FAILED',
    ];
}

function support_reply_finish_side_effects(string $path, array $result): void
{
    if ($path === '') {
        return;
    }
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        $row = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)
            || support_clean_code($row['status'] ?? '') !== 'COMMITTED'
            || support_clean_code($row['side_effect_status'] ?? '') !== 'PROCESSING') {
            return;
        }
        $row['status'] = 'COMPLETED';
        $row['side_effect_status'] = !empty($result['ok']) ? 'COMPLETED' : 'FAILED';
        $row['side_effect_error'] = !empty($result['ok']) ? '' : support_clean_code($result['error'] ?? 'SIDE_EFFECT_FAILED');
        $row['side_effect_stage'] = support_clean_code($result['stage'] ?? 'SIDE_EFFECT_COMPLETED');
        $row['side_effect_attempts'] = (int)($row['side_effect_attempts'] ?? 0) + 1;
        $row['completed_at'] = support_now();
        $row['updated_at'] = support_now();
        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if (!empty($write['ok']) || (int)($write['status'] ?? 0) !== 412) {
            return;
        }
    }
}

function support_reply_resume_pending_side_effects(string $path, string $ticketId, array $operation): void
{
    if ($path === ''
        || support_clean_code($operation['status'] ?? '') !== 'COMMITTED'
        || support_clean_code($operation['side_effect_status'] ?? '') !== 'PENDING'
        || !support_reply_claim_side_effects($path)) {
        return;
    }

    $messageId = support_clean_code($operation['message_id'] ?? '');
    $ticket = support_read_ticket($ticketId);
    $message = $messageId !== '' ? fb_get('SUPPORT_MESSAGES/' . support_clean_code($ticketId) . '/' . $messageId) : null;
    if ($ticket === [] || !is_array($message)) {
        support_reply_finish_side_effects($path, [
            'ok' => false,
            'error' => 'CANONICAL_REPLY_UNAVAILABLE',
            'stage' => 'CANONICAL_REPLY_UNAVAILABLE',
        ]);
        return;
    }

    $attachments = support_attachment_rows_for_ids($ticketId, (array)($message['attachment_ids'] ?? []));
    support_reply_finish_side_effects($path, support_reply_run_side_effects($ticket, $message, $attachments));
}

function support_reply(array $auth, string $ticketId, string $message, array $files = [], string $senderType = 'USER', array $meta = []): array
{
    $ticket = support_read_ticket($ticketId);
    if ($ticket === []) {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_NOT_FOUND', 'message' => 'Support ticket was not found.', 'status' => 404];
    }
    $uid = support_uid_from_auth($auth);
    $isAdmin = in_array($senderType, ['ADMIN', 'SUPPORT'], true);
    if (!$isAdmin && !support_user_can_access($auth, $ticket)) {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_FORBIDDEN', 'message' => 'This ticket is not available.', 'status' => 403];
    }
    $message = support_clean_text($message, 2500);
    if ($message === '' && support_upload_files($files) === []) {
        return ['ok' => false, 'code' => 'SUPPORT_MESSAGE_REQUIRED', 'message' => 'Please describe your issue.', 'status' => 422];
    }
    $idempotencyKey = support_clean_text($meta['idempotency_key'] ?? '', 160);
    if ($idempotencyKey === '') {
        return ['ok' => false, 'code' => 'SUPPORT_IDEMPOTENCY_REQUIRED', 'message' => 'Reply request identity is required.', 'status' => 422];
    }
    $meta['idempotency_key'] = $idempotencyKey;
    $meta['payload_hash'] = support_reply_payload_hash(
        (string)$ticket['ticket_id'],
        $uid,
        $senderType,
        $message,
        $files,
        $meta
    );
    $operationPath = support_reply_operation_path($uid, (string)$ticket['ticket_id'], $senderType, $meta);
    $existingOperation = $operationPath !== '' ? fb_get($operationPath) : null;
    if (is_array($existingOperation)) {
        $existingPayloadHash = trim((string)($existingOperation['payload_hash'] ?? ''));
        if ($existingPayloadHash !== '' && !hash_equals($existingPayloadHash, (string)$meta['payload_hash'])) {
            return [
                'ok' => false,
                'code' => 'SUPPORT_IDEMPOTENCY_CONFLICT',
                'message' => 'This reply key belongs to a different request.',
                'status' => 409,
            ];
        }
        $replayOperation = (string)($existingOperation['ticket_id'] ?? '') === ''
            ? array_merge($existingOperation, ['ticket_id' => (string)$ticket['ticket_id']])
            : $existingOperation;
        $replay = support_reply_operation_replay((string)$ticket['ticket_id'], $replayOperation);
        if ($replay !== []) {
            support_reply_resume_pending_side_effects($operationPath, (string)$ticket['ticket_id'], $existingOperation);
            return $replay + ['path' => $operationPath, 'operation' => $existingOperation];
        }
    }
    $status = support_clean_code($ticket['status'] ?? 'OPEN');
    if (!$isAdmin && !in_array($status, ['OPEN', 'PENDING', 'REPLIED'], true)) {
        if ($status === 'RESOLVED') {
            return ['ok' => false, 'code' => 'SUPPORT_TICKET_RESOLVED', 'message' => 'This ticket has been resolved.', 'status' => 409];
        }
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_CLOSED', 'message' => 'This ticket is closed.', 'status' => 409];
    }
    if ($status === 'CLOSED') {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_CLOSED', 'message' => 'This ticket is closed.', 'status' => 409];
    }
    if ($status === 'RESOLVED') {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_RESOLVED', 'message' => 'This ticket has been resolved.', 'status' => 409];
    }
    $claim = support_reply_claim_operation($operationPath, (string)$ticket['ticket_id'], $uid, $senderType, $meta);
    if (empty($claim['ok'])) {
        if (!empty($claim['in_progress']) && $operationPath !== '') {
            $replay = support_reply_wait_for_operation($operationPath, (string)$ticket['ticket_id']);
            if ($replay !== []) {
                support_reply_resume_pending_side_effects(
                    $operationPath,
                    (string)$ticket['ticket_id'],
                    (array)($replay['operation'] ?? [])
                );
                return $replay;
            }
        }
        return $claim;
    }
    if (!empty($claim['duplicate'])) {
        support_reply_resume_pending_side_effects(
            $operationPath,
            (string)$ticket['ticket_id'],
            (array)($claim['operation'] ?? [])
        );
        return $claim;
    }
    $messageId = (string)$claim['message_id'];
    $ownerToken = (string)($claim['owner_token'] ?? '');
    $config = support_config();
    $stored = support_store_attachments($files, (string)$ticket['ticket_id'], $messageId, (string)$ticket['uid'], $config);
    if (empty($stored['ok'])) {
        support_reply_mark_retryable($operationPath, $ownerToken, (string)($stored['code'] ?? 'SUPPORT_UPLOAD_FAILED'));
        return ['ok' => false, 'code' => $stored['code'], 'message' => $stored['message'], 'status' => 422];
    }
    $attachments = (array)($stored['items'] ?? []);
    if ($message === '' && $attachments === []) {
        support_reply_mark_retryable($operationPath, $ownerToken, 'SUPPORT_MESSAGE_REQUIRED');
        return ['ok' => false, 'code' => 'SUPPORT_MESSAGE_REQUIRED', 'message' => 'Please describe your issue.', 'status' => 422];
    }
    $replyToMessageId = support_clean_text($meta['reply_to_message_id'] ?? '', 80);
    $replyPreview = $replyToMessageId === '' ? [] : support_message_reply_preview((string)$ticket['ticket_id'], $replyToMessageId);
    if ($replyToMessageId !== '' && $replyPreview === []) {
        $replyToMessageId = '';
    }
    $now = support_now();
    $newStatus = $isAdmin ? 'REPLIED' : (in_array($status, ['RESOLVED'], true) ? 'OPEN' : 'PENDING');
    $attachmentIds = array_values(array_map(static fn($a) => (string)$a['attachment_id'], $attachments));
    $preview = $message !== '' ? $message : ($attachmentIds !== [] ? 'Photo attached' : '');
    $row = [
        'message_id' => $messageId,
        'ticket_id' => (string)$ticket['ticket_id'],
        'sender_uid' => $uid,
        'sender_type' => $isAdmin ? (support_clean_code($senderType) === 'SUPPORT' ? 'SUPPORT' : 'ADMIN') : 'USER',
        'sender_name' => support_clean_text($meta['sender_name'] ?? '', 80),
        'sender_telegram_id' => support_clean_text($meta['sender_telegram_id'] ?? '', 80),
        'source' => support_clean_code($meta['source'] ?? ($isAdmin ? 'ADMIN_PANEL' : 'ANDROID')),
        'idempotency_key' => support_clean_text($meta['idempotency_key'] ?? '', 160),
        'message' => $message,
        'attachment_ids' => $attachmentIds,
        'created_at' => $now,
        'read_by_user' => !$isAdmin,
        'read_by_admin' => $isAdmin,
    ];
    if ($replyToMessageId !== '') {
        $row['reply_to_message_id'] = $replyToMessageId;
        $row['reply_preview'] = $replyPreview;
    }
    $patch = [
        'status' => $newStatus,
        'updated_at' => $now,
        'last_message_at' => $now,
        'last_message_by' => $isAdmin ? 'ADMIN' : 'USER',
        'last_message_preview' => $preview,
        'attachment_count' => (int)($ticket['attachment_count'] ?? 0) + count($attachments),
        'admin_unread' => !$isAdmin,
        'user_unread' => $isAdmin,
    ];
    $renewed = support_reply_renew_operation($operationPath, $ownerToken);
    if (empty($renewed['ok'])) {
        support_cleanup_attachments($attachments);
        $replay = $operationPath !== '' ? support_reply_wait_for_operation($operationPath, (string)$ticket['ticket_id']) : [];
        return $replay !== []
            ? $replay
            : ['ok' => false, 'code' => 'SUPPORT_REPLY_IN_PROGRESS', 'message' => 'This reply is still being sent.', 'status' => 409];
    }
    $operation = (array)($renewed['operation'] ?? []);
    $userIndexPath = 'SUPPORT_USER_INDEX/' . $ticket['uid'] . '/' . $ticket['ticket_id'];
    $writes = [
        'SUPPORT_MESSAGES/' . $ticket['ticket_id'] . '/' . $messageId => $row,
        $userIndexPath . '/ticket_id' => (string)$ticket['ticket_id'],
        $userIndexPath . '/updated_at' => $now,
        $userIndexPath . '/status' => $newStatus,
    ];
    foreach ($patch as $field => $value) {
        $writes['SUPPORT_TICKETS/' . $ticket['ticket_id'] . '/' . $field] = $value;
    }
    foreach ($attachments as $attachment) {
        $writes['SUPPORT_ATTACHMENTS/' . $ticket['ticket_id'] . '/' . $attachment['attachment_id']] = $attachment;
    }
    if ($operationPath !== '') {
        $operation = array_merge($operation, [
            'status' => 'COMMITTED',
            'lease_until' => 0,
            'committed_at' => $now,
            'updated_at' => $now,
            'side_effect_status' => 'PENDING',
            'side_effect_attempts' => (int)($operation['side_effect_attempts'] ?? 0),
            'last_error' => '',
        ]);
        $writes[$operationPath] = $operation;
    }
    if (!fb_patch('', $writes)) {
        $replay = $operationPath !== ''
            ? support_reply_wait_for_operation($operationPath, (string)$ticket['ticket_id'])
            : [];
        if ($replay !== []) {
            $canonicalMessageId = support_clean_code($replay['message_id'] ?? '');
            $canonicalMessage = $canonicalMessageId !== ''
                ? fb_get('SUPPORT_MESSAGES/' . support_clean_code((string)$ticket['ticket_id']) . '/' . $canonicalMessageId)
                : null;
            if (is_array($canonicalMessage)) {
                $canonicalAttachments = array_fill_keys((array)($canonicalMessage['attachment_ids'] ?? []), true);
                $uncommittedAttachments = array_values(array_filter(
                    $attachments,
                    static fn($attachment) => empty($canonicalAttachments[(string)($attachment['attachment_id'] ?? '')])
                ));
                support_cleanup_attachments($uncommittedAttachments);
            }
            support_reply_resume_pending_side_effects(
                $operationPath,
                (string)$ticket['ticket_id'],
                (array)($replay['operation'] ?? [])
            );
            return $replay;
        }
        support_cleanup_attachments($attachments);
        support_reply_mark_retryable($operationPath, $ownerToken, 'SUPPORT_REPLY_COMMIT_FAILED');
        return ['ok' => false, 'code' => 'SUPPORT_REPLY_FAILED', 'message' => 'Reply could not be sent.', 'status' => 500];
    }
    $ticket = support_read_ticket((string)$ticket['ticket_id']);
    if ($ticket !== [] && support_reply_claim_side_effects($operationPath)) {
        support_reply_finish_side_effects(
            $operationPath,
            support_reply_run_side_effects($ticket, $row, $attachments)
        );
    }
    return ['ok' => true, 'message_id' => $messageId] + support_details_payload($ticket);
}

function support_admin_page(
    string $status = '',
    string $query = '',
    string $cursor = '',
    int $limit = 10
): array
{
    $status = support_clean_code($status);
    $query = strtoupper(trim($query));

    return admin_firebase_cursor_page(
        'SUPPORT_TICKETS',
        $limit,
        $cursor,
        static function (array $row) use ($status, $query): bool {
        if ($status !== '' && support_clean_code($row['status'] ?? '') !== support_clean_code($status)) {
                return false;
        }
        $haystack = strtoupper(implode(' ', [
            $row['ticket_id'] ?? '',
            $row['uid'] ?? '',
            $row['user_name'] ?? '',
            $row['user_phone'] ?? '',
            $row['subject'] ?? '',
            $row['related_request_id'] ?? '',
        ]));
        if ($query !== '' && strpos($haystack, $query) === false) {
                return false;
        }

            return true;
        },
        static function (array $row, string $ticketId): array {
            $row['ticket_id'] = (string)($row['ticket_id'] ?? $ticketId);
            return support_public_ticket($row);
        }
    );
}

function support_admin_list(string $status = '', string $query = '', int $limit = 10, string $cursor = ''): array
{
    $page = support_admin_page($status, $query, $cursor, $limit);
    return (array)($page['items'] ?? []);
}

function support_admin_set_status(string $ticketId, string $status, array $actor = []): array
{
    $allowed = ['OPEN', 'PENDING', 'REPLIED', 'RESOLVED', 'CLOSED'];
    $status = support_clean_code($status);
    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'code' => 'SUPPORT_STATUS_INVALID', 'message' => 'Invalid support status.', 'status' => 422];
    }
    $ticket = support_read_ticket($ticketId);
    if ($ticket === []) {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_NOT_FOUND', 'message' => 'Support ticket was not found.', 'status' => 404];
    }
    $previousStatus = support_clean_code($ticket['status'] ?? 'OPEN');
    $now = support_now();
    $patch = [
        'status' => $status,
        'updated_at' => $now,
        'status_updated_at' => $now,
        'status_updated_by' => (string)($actor['uid'] ?? $actor['user']['uid'] ?? 'ADMIN'),
    ];
    if ($status === 'RESOLVED') {
        $patch['resolved_at'] = $now;
    }
    if ($status === 'CLOSED') {
        $patch['closed_at'] = $now;
    }
    fb_patch('SUPPORT_TICKETS/' . $ticket['ticket_id'], $patch);
    fb_patch('SUPPORT_USER_INDEX/' . $ticket['uid'] . '/' . $ticket['ticket_id'], ['updated_at' => $now, 'status' => $status]);
    if ($status === 'CLOSED' && $previousStatus !== 'CLOSED') {
        $auto = support_save_auto_close_message((string)$ticket['ticket_id']);
        support_telegram_clear_ticket_reply_context((string)$ticket['ticket_id']);
        support_record_user_notification(
            (string)($ticket['uid'] ?? ''),
            (string)($ticket['ticket_id'] ?? ''),
            'Support Ticket Closed',
            'Your support conversation has been closed.',
            'SUPPORT_CLOSED',
            (string)($auto['message_id'] ?? '')
        );
        support_send_user_push(
            (string)($ticket['uid'] ?? ''),
            'Support Ticket Closed',
            'Your support conversation has been closed.',
            [
                'type' => 'SUPPORT_CLOSED',
                'ticket_id' => (string)($ticket['ticket_id'] ?? ''),
                'message_id' => (string)($auto['message_id'] ?? ''),
            ],
            'SUPPORT_CLOSED:' . (string)$ticket['ticket_id']
        );
    }
    $ticket = support_read_ticket((string)$ticket['ticket_id']);
    support_telegram_edit_ticket_message($ticket);
    return ['ok' => true] + support_details_payload($ticket);
}
