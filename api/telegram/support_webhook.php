<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/support.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function tg_support_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
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

function tg_support_secret(): string
{
    return defined('SUPPORT_TELEGRAM_WEBHOOK_SECRET') ? trim((string)SUPPORT_TELEGRAM_WEBHOOK_SECRET) : '';
}

function tg_support_verify_secret(): void
{
    $expected = tg_support_secret();
    $querySecret = trim((string)($_GET['key'] ?? ''));
    $headerSecret = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
    if ($expected === '') {
        tg_support_response(false, 'CONFIG_ERROR', 'SUPPORT_TELEGRAM_WEBHOOK_SECRET missing', [], 500);
    }
    if (($querySecret !== '' && hash_equals($expected, $querySecret))
        || ($headerSecret !== '' && hash_equals($expected, $headerSecret))) {
        return;
    }
    tg_support_response(false, 'FORBIDDEN', 'Invalid Telegram webhook secret', [], 403);
}

function tg_support_api(string $method, array $payload): array
{
    $token = support_telegram_bot_token();
    if ($token === '') {
        return ['ok' => false, 'code' => 'SUPPORT_TELEGRAM_TOKEN_MISSING', 'message' => 'Support Telegram token is not configured.'];
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
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
        return ['ok' => true, 'code' => 'OK', 'message' => 'Telegram request sent.', 'data' => $json];
    }

    return [
        'ok' => false,
        'code' => 'TELEGRAM_API_ERROR',
        'message' => is_array($json) ? (string)($json['description'] ?? 'Telegram request failed.') : 'Telegram request failed.',
        'status' => $status,
    ];
}

function tg_support_answer(string $callbackId, string $text, bool $alert = false): array
{
    if ($callbackId === '') {
        return ['ok' => false, 'code' => 'CALLBACK_ID_MISSING', 'message' => 'Callback id missing.'];
    }
    return tg_support_api('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $alert,
    ]);
}

function tg_support_send(string $chatId, string $text, array $replyMarkup = []): array
{
    if ($chatId === '') {
        return ['ok' => false, 'code' => 'CHAT_ID_MISSING', 'message' => 'Chat id missing.'];
    }
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ];
    if ($replyMarkup !== []) {
        $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_SLASHES);
    }
    return tg_support_api('sendMessage', $payload);
}

function tg_support_callback_allowed(array $callback): bool
{
    return support_telegram_actor_allowed(
        (string)($callback['message']['chat']['id'] ?? ''),
        (string)($callback['from']['id'] ?? '')
    );
}

function tg_support_message_allowed(array $message): bool
{
    return support_telegram_actor_allowed(
        (string)($message['chat']['id'] ?? ''),
        (string)($message['from']['id'] ?? '')
    );
}

function tg_support_edit_callback_message(array $callback, array $ticket): void
{
    $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
    $chatId = (string)($message['chat']['id'] ?? '');
    $messageId = (string)($message['message_id'] ?? '');
    if ($chatId === '' || $messageId === '') {
        return;
    }
    tg_support_api('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => support_telegram_ticket_message($ticket),
        'reply_markup' => json_encode(support_telegram_keyboard((string)($ticket['ticket_id'] ?? '')), JSON_UNESCAPED_SLASHES),
        'disable_web_page_preview' => true,
    ]);
}

function tg_notice_id(): string
{
    return 'NTC' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function tg_notice_context_key(string $chatId, string $fromId): string
{
    return hash('sha256', trim($chatId) . '|' . trim($fromId));
}

function tg_notice_context_path(string $chatId, string $fromId): string
{
    return 'ADMIN_NOTICE_CONTEXT/' . tg_notice_context_key($chatId, $fromId);
}

function tg_notice_context(string $chatId, string $fromId): array
{
    $row = fb_get(tg_notice_context_path($chatId, $fromId));
    if (!is_array($row)) {
        return [];
    }
    if ((int)($row['expires_at'] ?? 0) < time()) {
        fb_delete(tg_notice_context_path($chatId, $fromId));
        return [];
    }
    return $row;
}

function tg_notice_save_context(string $chatId, string $fromId, array $context): void
{
    $context['chat_id'] = $chatId;
    $context['from_id'] = $fromId;
    $context['updated_at'] = time();
    $context['expires_at'] = time() + 1800;
    fb_put(tg_notice_context_path($chatId, $fromId), $context);
}

function tg_notice_clear_context(string $chatId, string $fromId): void
{
    fb_delete(tg_notice_context_path($chatId, $fromId));
}

function tg_notice_menu_keyboard(): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => 'Send Notice', 'callback_data' => 'notice|start'],
            ],
        ],
    ];
}

function tg_notice_audience_keyboard(): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => 'All Users', 'callback_data' => 'notice|aud|ALL'],
                ['text' => 'BD Users', 'callback_data' => 'notice|aud|BD'],
            ],
            [
                ['text' => 'MY Users', 'callback_data' => 'notice|aud|MY'],
                ['text' => 'Specific User', 'callback_data' => 'notice|aud|SPECIFIC'],
            ],
            [
                ['text' => 'Cancel', 'callback_data' => 'notice|cancel'],
            ],
        ],
    ];
}

function tg_notice_preview_keyboard(): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => 'Send', 'callback_data' => 'notice|send'],
                ['text' => 'Edit', 'callback_data' => 'notice|edit'],
            ],
            [
                ['text' => 'Cancel', 'callback_data' => 'notice|cancel'],
            ],
        ],
    ];
}

function tg_notice_start(string $chatId, string $fromId): void
{
    tg_notice_clear_context($chatId, $fromId);
    tg_support_send($chatId, "Send Notice\n\nChoose audience:", tg_notice_audience_keyboard());
}

function tg_notice_preview_text(array $context): string
{
    $audience = (string)($context['audience'] ?? 'ALL');
    if ($audience === 'SPECIFIC') {
        $audience .= ' (' . (string)($context['specific_uid'] ?? '') . ')';
    }
    $body = trim((string)($context['body'] ?? ''));
    return "Notice Preview\n\n"
        . "Audience: " . $audience . "\n"
        . "Title: " . (string)($context['title'] ?? '') . "\n"
        . "Message: " . ($body !== '' ? $body : '[image only]') . "\n"
        . "Image: " . ((string)($context['image_id'] ?? '') !== '' ? 'Yes' : 'No') . "\n\n"
        . "Send this notice?";
}

function tg_notice_show_preview(string $chatId, array $context): void
{
    tg_support_send($chatId, tg_notice_preview_text($context), tg_notice_preview_keyboard());
}

function tg_notice_download_photo(string $fileId): array
{
    $fileId = trim($fileId);
    $token = support_telegram_bot_token();
    if ($fileId === '' || $token === '') {
        return ['ok' => false, 'code' => 'NOTICE_PHOTO_MISSING'];
    }
    $file = tg_support_api('getFile', ['file_id' => $fileId]);
    $filePath = (string)($file['data']['result']['file_path'] ?? '');
    if (empty($file['ok']) || $filePath === '') {
        return ['ok' => false, 'code' => 'NOTICE_PHOTO_FILE_FAILED'];
    }
    $url = 'https://api.telegram.org/file/bot' . $token . '/' . ltrim($filePath, '/');
    $ch = curl_init($url);
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

function tg_notice_largest_photo_id(array $message): string
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

function tg_notice_handle_callback(array $callback, string $callbackData): bool
{
    if (strncasecmp($callbackData, 'notice|', 7) !== 0) {
        return false;
    }
    $callbackId = (string)($callback['id'] ?? '');
    $chatId = (string)($callback['message']['chat']['id'] ?? '');
    $fromId = (string)($callback['from']['id'] ?? '');
    if (!support_telegram_actor_allowed($chatId, $fromId)) {
        tg_support_answer($callbackId, 'You are not authorized to perform this action.', true);
        tg_support_response(true, 'IGNORED', 'Unauthorized notice callback ignored', [], 200);
    }
    $parts = explode('|', trim($callbackData));
    $action = strtolower((string)($parts[1] ?? ''));
    if ($action === 'start') {
        tg_notice_start($chatId, $fromId);
        tg_support_answer($callbackId, 'Notice started');
        tg_support_response(true, 'NOTICE_START_OK', 'Notice flow started.', [], 200);
    }
    if ($action === 'aud') {
        $audience = strtoupper((string)($parts[2] ?? 'ALL'));
        if (!in_array($audience, ['ALL', 'BD', 'MY', 'SPECIFIC'], true)) {
            $audience = 'ALL';
        }
        $context = [
            'notice_id' => tg_notice_id(),
            'audience' => $audience,
            'step' => $audience === 'SPECIFIC' ? 'UID' : 'TITLE',
            'created_by' => $fromId,
            'created_at' => time(),
        ];
        tg_notice_save_context($chatId, $fromId, $context);
        tg_support_answer($callbackId, 'Audience selected');
        tg_support_send($chatId, $audience === 'SPECIFIC' ? 'Send the target user UID.' : 'Send notice title.');
        tg_support_response(true, 'NOTICE_AUDIENCE_OK', 'Notice audience selected.', [], 200);
    }
    if ($action === 'cancel') {
        tg_notice_clear_context($chatId, $fromId);
        tg_support_answer($callbackId, 'Notice cancelled');
        tg_support_send($chatId, 'Notice cancelled.');
        tg_support_response(true, 'NOTICE_CANCELLED', 'Notice cancelled.', [], 200);
    }
    $context = tg_notice_context($chatId, $fromId);
    if ($context === []) {
        tg_support_answer($callbackId, 'Notice session expired. Start again.', true);
        tg_support_response(true, 'NOTICE_CONTEXT_MISSING', 'Notice context missing.', [], 200);
    }
    if ($action === 'preview') {
        tg_notice_show_preview($chatId, $context);
        tg_support_answer($callbackId, 'Preview ready');
        tg_support_response(true, 'NOTICE_PREVIEW_OK', 'Notice preview shown.', [], 200);
    }
    if ($action === 'edit') {
        $context['step'] = 'TITLE';
        tg_notice_save_context($chatId, $fromId, $context);
        tg_support_answer($callbackId, 'Edit notice');
        tg_support_send($chatId, 'Send the new notice title.');
        tg_support_response(true, 'NOTICE_EDIT_OK', 'Notice edit started.', [], 200);
    }
    if ($action === 'send') {
        if (trim((string)($context['title'] ?? '')) === '' || (trim((string)($context['body'] ?? '')) === '' && trim((string)($context['image_id'] ?? '')) === '')) {
            tg_support_answer($callbackId, 'Title and message or image are required.', true);
            tg_support_response(true, 'NOTICE_INVALID', 'Notice invalid.', [], 200);
        }
        $result = notification_broadcast_admin_notice($context);
        if (!empty($result['ok'])) {
            tg_notice_clear_context($chatId, $fromId);
            tg_support_answer($callbackId, 'Notice sent');
            tg_support_send($chatId, 'Notice sent successfully. Users: ' . (int)($result['sent'] ?? 0));
            tg_support_response(true, 'NOTICE_SENT', 'Notice sent.', $result, 200);
        }
        tg_support_answer($callbackId, 'Notice could not be sent.', true);
        tg_support_response(true, 'NOTICE_SEND_FAILED', 'Notice send failed.', [], 200);
    }
    tg_support_answer($callbackId, 'Unsupported notice action');
    tg_support_response(true, 'NOTICE_UNSUPPORTED', 'Unsupported notice action.', [], 200);
}

function tg_notice_handle_message(array $message): bool
{
    $chatId = (string)($message['chat']['id'] ?? '');
    $fromId = (string)($message['from']['id'] ?? '');
    $text = trim((string)($message['text'] ?? ''));
    if (!support_telegram_actor_allowed($chatId, $fromId)) {
        return false;
    }
    if (preg_match('/^\/start(?:@\w+)?(?:\s|$)/i', $text) === 1) {
        tg_support_send($chatId, 'Z-Pay Swift Support Admin Menu', tg_notice_menu_keyboard());
        tg_support_response(true, 'NOTICE_MENU_OK', 'Admin menu shown.', [], 200);
    }
    if (preg_match('/^\/notice(?:@\w+)?(?:\s|$)/i', $text) === 1 || strcasecmp($text, 'Send Notice') === 0) {
        tg_notice_start($chatId, $fromId);
        tg_support_response(true, 'NOTICE_START_OK', 'Notice flow started.', [], 200);
    }
    $context = tg_notice_context($chatId, $fromId);
    if ($context === []) {
        return false;
    }
    if (preg_match('/^\/cancel(?:@\w+)?(?:\s|$)/i', $text) === 1) {
        tg_notice_clear_context($chatId, $fromId);
        tg_support_send($chatId, 'Notice cancelled.');
        tg_support_response(true, 'NOTICE_CANCELLED', 'Notice cancelled.', [], 200);
    }
    $step = strtoupper((string)($context['step'] ?? ''));
    if ($step === 'UID') {
        $uid = trim($text);
        if ($uid === '' || !is_array(fb_get('USERS/' . $uid))) {
            tg_support_send($chatId, 'User was not found. Send a valid UID or /cancel.');
            tg_support_response(true, 'NOTICE_UID_INVALID', 'Notice UID invalid.', [], 200);
        }
        $context['specific_uid'] = $uid;
        $context['step'] = 'TITLE';
        tg_notice_save_context($chatId, $fromId, $context);
        tg_support_send($chatId, 'Send notice title.');
        tg_support_response(true, 'NOTICE_UID_OK', 'Notice UID saved.', [], 200);
    }
    if ($step === 'TITLE') {
        if ($text === '') {
            tg_support_send($chatId, 'Title is required. Send notice title or /cancel.');
            tg_support_response(true, 'NOTICE_TITLE_REQUIRED', 'Notice title required.', [], 200);
        }
        $context['title'] = notification_clean_text($text, 100);
        $context['step'] = 'BODY';
        tg_notice_save_context($chatId, $fromId, $context);
        tg_support_send($chatId, 'Send notice message, or send a photo with optional caption.');
        tg_support_response(true, 'NOTICE_TITLE_OK', 'Notice title saved.', [], 200);
    }
    if ($step === 'BODY' || $step === 'IMAGE') {
        $photoId = tg_notice_largest_photo_id($message);
        if ($photoId !== '') {
            $stored = tg_notice_download_photo($photoId);
            if (empty($stored['ok'])) {
                tg_support_send($chatId, 'Image could not be saved. Please send another image or /cancel.');
                tg_support_response(true, 'NOTICE_IMAGE_FAILED', 'Notice image failed.', [], 200);
            }
            foreach (['image_id', 'image_path', 'image_mime', 'image_name'] as $key) {
                $context[$key] = (string)($stored[$key] ?? '');
            }
            $caption = trim((string)($message['caption'] ?? ''));
            if ($caption !== '') {
                $context['body'] = notification_clean_text($caption, 4000);
            }
            $context['step'] = 'PREVIEW';
            tg_notice_save_context($chatId, $fromId, $context);
            tg_notice_show_preview($chatId, $context);
            tg_support_response(true, 'NOTICE_IMAGE_OK', 'Notice image saved.', [], 200);
        }
        if ($text !== '' && strcasecmp($text, '/skip') !== 0) {
            $context['body'] = notification_clean_text($text, 4000);
            $context['step'] = 'IMAGE';
            tg_notice_save_context($chatId, $fromId, $context);
            tg_support_send($chatId, 'Optional: send an image now, or tap Preview.', [
                'inline_keyboard' => [
                    [['text' => 'Preview', 'callback_data' => 'notice|preview']],
                    [['text' => 'Cancel', 'callback_data' => 'notice|cancel']],
                ],
            ]);
            tg_support_response(true, 'NOTICE_BODY_OK', 'Notice body saved.', [], 200);
        }
        if (strcasecmp($text, '/skip') === 0 && (string)($context['image_id'] ?? '') !== '') {
            $context['step'] = 'PREVIEW';
            tg_notice_save_context($chatId, $fromId, $context);
            tg_notice_show_preview($chatId, $context);
            tg_support_response(true, 'NOTICE_PREVIEW_OK', 'Notice preview shown.', [], 200);
        }
        tg_support_send($chatId, 'Send message text, image, or /cancel.');
        tg_support_response(true, 'NOTICE_WAITING_INPUT', 'Notice waiting for input.', [], 200);
    }
    return true;
}

tg_support_verify_secret();

$raw = isset($GLOBALS['TELEGRAM_UPDATE_RAW'])
    ? (string)$GLOBALS['TELEGRAM_UPDATE_RAW']
    : (string)file_get_contents('php://input');
$update = trim($raw) !== '' ? json_decode($raw, true) : null;
if (!is_array($update)) {
    tg_support_response(true, 'IGNORED', 'Invalid Telegram update', [], 200);
}

$callback = $update['callback_query'] ?? null;
if (is_array($callback)) {
    $callbackId = (string)($callback['id'] ?? '');
    $callbackData = (string)($callback['data'] ?? '');
    tg_notice_handle_callback($callback, $callbackData);
    $parsed = support_telegram_parse_callback_data($callbackData);
    if ($parsed === []) {
        if (strncasecmp($callbackData, 'support|r|', 10) === 0) {
            $parts = explode('|', trim($callbackData));
            support_telegram_reply_diag('support_reply_signature_fail', support_clean_code($parts[2] ?? ''), [
                'has_callback_id' => $callbackId !== '',
            ]);
        }
        tg_support_answer($callbackId, 'Invalid support action', false);
        tg_support_response(true, 'IGNORED', 'Invalid support callback', [], 200);
    }

    $action = (string)$parsed['action'];
    $ticketId = (string)$parsed['ticket_id'];
    $chatId = (string)($callback['message']['chat']['id'] ?? support_telegram_chat_id());
    $fromId = (string)($callback['from']['id'] ?? '');
    $ticket = support_read_ticket($ticketId);
    if ($ticket === []) {
        if ($action === 'r') {
            support_telegram_reply_diag('support_reply_ticket_missing', $ticketId, [
                'signature_valid' => true,
            ]);
        }
        tg_support_answer($callbackId, 'Support ticket was not found.', true);
        tg_support_response(true, 'SUPPORT_NOT_FOUND', 'Support ticket was not found.', ['ticket_id' => $ticketId], 200);
    }
    $isReplyAction = $action === 'r';
    if ($isReplyAction) {
        support_telegram_reply_diag('support_reply_signature_pass', $ticketId, [
            'signature_valid' => true,
        ]);
        support_telegram_reply_diag('support_reply_callback_received', $ticketId, [
            'has_callback_id' => $callbackId !== '',
            'private_chat' => $chatId !== '' && $chatId === $fromId,
            'has_from_id' => $fromId !== '',
            'ticket_found' => true,
        ]);
    }

    if (!support_telegram_ticket_callback_allowed($ticket, $chatId, $fromId)) {
        $answer = tg_support_answer($callbackId, 'You are not authorized to perform this action.', true);
        if ($isReplyAction) {
            support_telegram_reply_diag('support_reply_authorization_fail', $ticketId, [
                'answer_ok' => !empty($answer['ok']),
                'answer_code' => (string)($answer['code'] ?? ''),
                'private_chat' => $chatId !== '' && $chatId === $fromId,
            ]);
        }
        tg_support_response(true, 'IGNORED', 'Unauthorized support callback ignored', [], 200);
    }
    if ($isReplyAction) {
        support_telegram_reply_diag('support_reply_authorization_pass', $ticketId, [
            'private_chat' => $chatId !== '' && $chatId === $fromId,
        ]);
    }

    if ($action === 'v') {
        $payload = support_details_payload($ticket);
        tg_support_send($chatId, support_telegram_ticket_summary($ticket, (array)($payload['messages'] ?? [])), support_telegram_keyboard($ticketId));
        tg_support_answer($callbackId, 'Ticket loaded');
    } elseif ($action === 'r') {
        $result = support_telegram_set_reply_context($ticketId, $chatId, $fromId);
        if (empty($result['ok'])) {
            $answer = tg_support_answer($callbackId, (string)($result['message'] ?? 'Reply mode unavailable'), true);
            support_telegram_reply_diag('support_reply_context_write_fail', $ticketId, [
                'code' => (string)($result['code'] ?? ''),
                'answer_ok' => !empty($answer['ok']),
                'answer_code' => (string)($answer['code'] ?? ''),
            ]);
        } else {
            support_telegram_reply_diag('support_reply_context_write_success', $ticketId, [
                'expires_at' => (int)($result['context']['expires_at'] ?? 0),
            ]);
            $sent = tg_support_send(
                $chatId,
                'Reply mode enabled for ticket ' . $ticketId . ".\nSend your reply message or tap Cancel.",
                support_telegram_cancel_keyboard($ticketId)
            );
            if (empty($sent['ok'])) {
                $answer = tg_support_answer($callbackId, 'Unable to start reply mode. Please try again.', true);
                support_telegram_reply_diag('support_reply_confirmation_fail', $ticketId, [
                    'send_code' => (string)($sent['code'] ?? ''),
                    'send_status' => (int)($sent['status'] ?? 0),
                    'answer_ok' => !empty($answer['ok']),
                    'answer_code' => (string)($answer['code'] ?? ''),
                ]);
            } else {
                $answer = tg_support_answer($callbackId, 'Reply mode enabled');
                support_telegram_reply_diag('support_reply_confirmation_success', $ticketId, [
                    'send_ok' => true,
                    'answer_ok' => !empty($answer['ok']),
                    'answer_code' => (string)($answer['code'] ?? ''),
                ]);
            }
        }
    } elseif ($action === 'x') {
        support_telegram_clear_reply_context($chatId, $fromId);
        tg_support_send($chatId, 'Reply mode cancelled.');
        tg_support_answer($callbackId, 'Reply cancelled');
    } elseif ($action === 'p' || $action === 'c') {
        $status = $action === 'p' ? 'PENDING' : 'CLOSED';
        $result = support_admin_set_status($ticketId, $status, ['uid' => 'TELEGRAM']);
        if (!empty($result['ok'])) {
            $updatedTicket = support_read_ticket($ticketId);
            if ($updatedTicket !== []) {
                tg_support_edit_callback_message($callback, $updatedTicket);
            }
        }
        tg_support_answer($callbackId, !empty($result['ok']) ? ($action === 'p' ? 'Ticket marked pending' : 'Ticket closed') : (string)($result['message'] ?? 'Action failed'), empty($result['ok']));
    } else {
        tg_support_answer($callbackId, 'Unsupported support action');
        tg_support_response(true, 'IGNORED', 'Unsupported support action', [], 200);
    }

    tg_support_response(true, 'SUPPORT_CALLBACK_OK', 'Support callback handled.', [
        'ticket_id' => $ticketId,
        'action' => $action,
    ]);
}

$message = $update['message'] ?? null;
if (is_array($message)) {
    $chatId = (string)($message['chat']['id'] ?? '');
    $fromId = (string)($message['from']['id'] ?? '');
    if (!tg_support_message_allowed($message) && !support_telegram_message_has_reply_context($message)) {
        tg_support_response(true, 'IGNORED', 'Unauthorized support message ignored', [], 200);
    }

    $text = trim((string)($message['text'] ?? ''));
    if (preg_match('/^\/cancel(?:@\w+)?(?:\s|$)/i', $text) === 1) {
        support_telegram_clear_reply_context($chatId, $fromId);
        tg_notice_clear_context($chatId, $fromId);
        tg_support_send($chatId, 'Reply mode cancelled.');
        tg_support_response(true, 'SUPPORT_REPLY_CANCELLED', 'Support reply mode cancelled.', [], 200);
    }

    tg_notice_handle_message($message);

    $updateId = (int)($update['update_id'] ?? 0);
    $result = support_telegram_save_reply_from_message($message, $updateId);
    if (!empty($result['ok'])) {
        if (empty($result['duplicate'])) {
            tg_support_send($chatId, 'Reply sent successfully.');
        }
        support_telegram_reply_diag('support_reply_canonical_save_success', (string)($result['ticket_id'] ?? ''), [
            'duplicate' => !empty($result['duplicate']),
        ]);
        tg_support_response(true, 'SUPPORT_REPLY_SAVED', 'Support reply saved.', [
            'ticket_id' => (string)($result['ticket_id'] ?? ''),
            'duplicate' => !empty($result['duplicate']),
        ]);
    }

    tg_support_send($chatId, (string)($result['message'] ?? 'Reply could not be saved.'));
    support_telegram_reply_diag('support_reply_canonical_save_fail', '', [
        'code' => (string)($result['code'] ?? 'SUPPORT_REPLY_FAILED'),
    ]);
    tg_support_response(true, (string)($result['code'] ?? 'SUPPORT_REPLY_FAILED'), 'Support reply not saved.', [], 200);
}

tg_support_response(true, 'IGNORED', 'Unsupported Telegram update', [], 200);
