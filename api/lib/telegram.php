<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function make_telegram_queue_id(): string
{
    return 'TG' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function telegram_queue_create(string $type, string $refId, string $message): string
{
    $queueId = make_telegram_queue_id();

    fb_put('TELEGRAM_QUEUE/' . $queueId, [
        'queue_id' => $queueId,
        'type' => $type,
        'ref_id' => $refId,
        'message' => $message,
        'status' => 'PENDING',
        'created_at' => now_ts(),
        'sent_at' => 0,
        'error' => '',
    ]);

    return $queueId;
}

function telegram_queue_mark_sent(string $queueId): void
{
    fb_patch('TELEGRAM_QUEUE/' . $queueId, [
        'status' => 'SENT',
        'sent_at' => now_ts(),
        'error' => '',
    ]);
}

function telegram_queue_mark_failed(string $queueId, string $error): void
{
    fb_patch('TELEGRAM_QUEUE/' . $queueId, [
        'status' => 'FAILED',
        'sent_at' => 0,
        'error' => $error,
    ]);
}

function telegram_send_message(string $message): array
{
    if (TELEGRAM_BOT_TOKEN === '' || TELEGRAM_CHAT_ID === '') {
        return [
            'ok' => false,
            'message' => 'Telegram token/chat id not configured',
        ];
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';

    $payload = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $message,
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
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
        return [
            'ok' => false,
            'message' => $err ?: 'Telegram request failed',
        ];
    }

    $json = json_decode($raw, true);
    if ($status >= 200 && $status < 300 && is_array($json) && !empty($json['ok'])) {
        return [
            'ok' => true,
            'message' => 'Telegram sent',
            'data' => $json,
        ];
    }

    return [
        'ok' => false,
        'message' => is_array($json) ? (string)($json['description'] ?? 'Telegram send failed') : 'Telegram send failed',
    ];
}

function build_bundle_telegram_message(array $bundle, array $user): string
{
    $lines = [
        "📦 New Bundle Request",
        "",
        "Request ID: " . (string)($bundle['request_id'] ?? ''),
        "User UID: " . (string)($bundle['uid'] ?? ''),
        "User Name: " . (string)($user['name'] ?? ''),
        "User Phone: " . (string)($user['phone'] ?? ''),
        "Bundle Number: " . (string)($bundle['bundle_number'] ?? ''),
        "Operator: " . (string)($bundle['operator'] ?? ''),
        "Bundle Name: " . (string)($bundle['bundle_name'] ?? ''),
        "Amount: " . (string)($bundle['amount'] ?? ''),
        "Note: " . (string)($bundle['note'] ?? ''),
        "Time: " . date('Y-m-d H:i:s', (int)($bundle['created_at'] ?? now_ts())),
        "",
        "Action: Send manually, then mark success/failed from admin panel.",
    ];

    return implode("\n", $lines);
}
