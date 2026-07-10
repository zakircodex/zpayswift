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
    return defined('TELEGRAM_WEBHOOK_SECRET') ? trim((string)TELEGRAM_WEBHOOK_SECRET) : '';
}

function tg_support_verify_secret(): void
{
    $expected = tg_support_secret();
    $querySecret = trim((string)($_GET['key'] ?? ''));
    $headerSecret = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
    if ($expected === '') {
        tg_support_response(false, 'CONFIG_ERROR', 'TELEGRAM_WEBHOOK_SECRET missing', [], 500);
    }
    if (($querySecret !== '' && hash_equals($expected, $querySecret))
        || ($headerSecret !== '' && hash_equals($expected, $headerSecret))) {
        return;
    }
    tg_support_response(false, 'FORBIDDEN', 'Invalid Telegram webhook secret', [], 403);
}

function tg_support_api(string $method, array $payload): array
{
    if (TELEGRAM_BOT_TOKEN === '') {
        return ['ok' => false, 'code' => 'TELEGRAM_TOKEN_MISSING', 'message' => 'Telegram token is not configured.'];
    }
    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method);
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
    $chatId = (string)($callback['message']['chat']['id'] ?? TELEGRAM_CHAT_ID);
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
        tg_support_send($chatId, 'Reply mode cancelled.');
        tg_support_response(true, 'SUPPORT_REPLY_CANCELLED', 'Support reply mode cancelled.', [], 200);
    }

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
