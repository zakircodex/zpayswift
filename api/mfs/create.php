<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Z-Pay Swift - MFS Create Endpoint
|--------------------------------------------------------------------------
| File: /api/mfs/create.php
|
| POST JSON:
| {
|   "provider": "BKASH" / "NAGAD",
|   "service_type": "SEND_MONEY" / "CASH_OUT",
|   "account_type": "PERSONAL" / "AGENT",
|   "receiver_number": "01XXXXXXXXX",
|   "amount": 100,
|   "currency": "BDT" / "MYR",
|   "amount_bdt": 3100,
|   "amount_rm": 100,
|   "reference": "optional",
|   "pin": "1234",
|   "note": "optional"
| }
|--------------------------------------------------------------------------
*/

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mfs.php';

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(true);

$user = (array)($auth['user'] ?? []);
$session = (array)($auth['session'] ?? []);

$uid = trim((string)($user['uid'] ?? ''));

if ($uid === '') {
    api_response(false, 'UNAUTHORIZED', 'User session invalid', [], 401);
}

$body = api_read_json_body();

function mfs_create_endpoint_client_ip(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];

    foreach ($headers as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));

        if ($value === '') {
            continue;
        }

        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = explode(',', $value);
            $value = trim((string)($parts[0] ?? ''));
        }

        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function mfs_create_endpoint_telegram_enabled(): bool
{
    return mfs_telegram_bot_token() !== ''
        && mfs_telegram_chat_id() !== ''
        && mfs_telegram_action_key() !== '';
}

function mfs_create_endpoint_telegram_send_message(string $text, array $replyMarkup = []): array
{
    if (!mfs_create_endpoint_telegram_enabled()) {
        return [
            'ok' => false,
            'code' => 'TELEGRAM_DISABLED',
            'message' => 'Telegram config missing',
            'raw' => '',
        ];
    }

    $url = 'https://api.telegram.org/bot' . rawurlencode(mfs_telegram_bot_token()) . '/sendMessage';

    $payload = [
        'chat_id' => mfs_telegram_chat_id(),
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    if ($replyMarkup) {
        $payload['reply_markup'] = $replyMarkup;
    }

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return [
            'ok' => false,
            'code' => 'CURL_ERROR',
            'message' => $err ?: 'Telegram curl error',
            'raw' => '',
            'http' => $http,
        ];
    }

    $json = json_decode((string)$raw, true);

    return [
        'ok' => $http >= 200 && $http < 300 && is_array($json) && !empty($json['ok']),
        'code' => 'TELEGRAM_RESPONSE',
        'message' => is_array($json) ? (string)($json['description'] ?? 'Telegram response') : 'Invalid Telegram JSON',
        'raw' => substr((string)$raw, 0, 800),
        'http' => $http,
        'json' => is_array($json) ? $json : [],
    ];
}

function mfs_create_endpoint_h(float $value, string $currency): string
{
    $currency = strtoupper(trim($currency));

    if ($currency === 'MYR') {
        return 'RM ' . number_format($value, 2, '.', '');
    }

    return 'BDT ' . number_format($value, 2, '.', '');
}

function mfs_create_endpoint_build_telegram_text(array $data, array $user): string
{
    $provider = htmlspecialchars((string)($data['provider_name'] ?? $data['provider'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $service = htmlspecialchars((string)($data['service_name'] ?? $data['service_type'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $accountType = htmlspecialchars((string)($data['account_type'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $number = htmlspecialchars((string)($data['receiver_number'] ?? $data['number'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $requestId = htmlspecialchars((string)($data['request_id'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $reference = htmlspecialchars((string)($data['reference'] ?? '-'), ENT_QUOTES, 'UTF-8');

    $userName = htmlspecialchars((string)($user['name'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $userPhone = htmlspecialchars((string)($user['phone'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $uid = htmlspecialchars((string)($user['uid'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $role = htmlspecialchars((string)($data['user_role'] ?? $data['role'] ?? $user['role'] ?? 'USER'), ENT_QUOTES, 'UTF-8');

    $country = htmlspecialchars((string)($data['country_code'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $mode = htmlspecialchars((string)($data['service_mode'] ?? '-'), ENT_QUOTES, 'UTF-8');
    $currency = strtoupper(trim((string)($data['wallet_currency'] ?? 'BDT')));

    $amountBdt = (float)($data['amount_bdt'] ?? 0);
    $amountRm = (float)($data['amount_rm'] ?? 0);
    $feeBdt = (float)($data['fee_bdt'] ?? 0);
    $feeRm = (float)($data['fee_rm'] ?? 0);
    $totalDebit = (float)($data['total_debit'] ?? 0);
    $rate = (float)($data['exchange_rate'] ?? 0);
    $isRemittance = strtoupper((string)($data['service_mode'] ?? '')) === 'REMITTANCE'
        || strtoupper((string)($data['country_code'] ?? '')) === 'MY'
        || $amountRm > 0;
    if ($isRemittance && $amountRm <= 0 && $rate > 0 && $amountBdt > 0) {
        $amountRm = round($amountBdt / $rate, 2);
    }
    if ($isRemittance && $feeRm <= 0 && $rate > 0 && $feeBdt > 0) {
        $feeRm = round($feeBdt / $rate, 2);
    }

    $totalRm = (float)($data['total_debit_rm'] ?? $data['total_pay_myr'] ?? 0);
    if ($isRemittance && $totalRm <= 0) {
        $totalRm = $amountRm + $feeRm;
    }

    $amountLine = $isRemittance
        ? 'Received Amount: <b>BDT ' . number_format($amountBdt, 2, '.', '') . '</b>' . "\n" . 'Send Amount: <b>' . mfs_create_endpoint_h($amountRm, 'MYR') . '</b>'
        : 'Received Amount: <b>' . mfs_create_endpoint_h($amountBdt, 'BDT') . '</b>';

    $feeLine = $isRemittance
        ? 'Fee: <b>' . mfs_create_endpoint_h($feeRm, 'MYR') . '</b>'
        : 'Fee: <b>' . mfs_create_endpoint_h($feeBdt, 'BDT') . '</b>';

    $totalLine = $isRemittance
        ? 'Total Paid: <b>' . mfs_create_endpoint_h($totalRm, 'MYR') . '</b>'
        : 'Total Paid: <b>' . mfs_create_endpoint_h($totalDebit, $currency) . '</b>';

    $rateLine = $isRemittance
        ? "\nRate: <b>RM 1 = BDT " . number_format($rate, 2, '.', '') . '</b>'
        : '';

    return
        "📲 <b>New MFS Request</b>\n\n" .
        "Request ID: <code>{$requestId}</code>\n" .
        "Provider: <b>{$provider}</b>\n" .
        "Service: <b>{$service}</b>\n" .
        "Account: <b>{$accountType}</b>\n" .
        "Number: <code>{$number}</code>\n" .
        "Reference: <code>{$reference}</code>\n\n" .
        "{$amountLine}\n" .
        "{$feeLine}\n" .
        "{$totalLine}{$rateLine}\n\n" .
        "Country: <b>{$country}</b>\n" .
        "Mode: <b>{$mode}</b>\n" .
        "Currency: <b>{$currency}</b>\n\n" .
        "User: <b>{$userName}</b>\n" .
        "Phone: <code>{$userPhone}</code>\n" .
        "Role: <b>{$role}</b>\n" .
        "UID: <code>{$uid}</code>\n\n" .
        "Status: <b>PENDING</b>";
}

function mfs_create_endpoint_notify_telegram(array $data, array $user): void
{
    $requestId = trim((string)($data['request_id'] ?? ''));

    if ($requestId === '') {
        return;
    }

    $text = mfs_create_endpoint_build_telegram_text($data, $user);

    $replyMarkup = [
        'inline_keyboard' => [
            [
                [
                    'text' => '🔄 Processing',
                    'callback_data' => mfs_telegram_callback_data('p', $requestId),
                ],
            ],
            [
                [
                    'text' => '✅ Success',
                    'callback_data' => mfs_telegram_callback_data('s', $requestId),
                ],
                [
                    'text' => '❌ Failed',
                    'callback_data' => mfs_telegram_callback_data('f', $requestId),
                ],
            ],
        ],
    ];

    $tg = mfs_create_endpoint_telegram_send_message($text, $replyMarkup);

    $patch = [
        'telegram_sent' => !empty($tg['ok']),
        'telegram_status' => !empty($tg['ok']) ? 'SENT' : 'FAILED',
        'telegram_updated_at' => mfs_now(),
    ];

    if (!empty($tg['json']['result']['message_id'])) {
        $patch['telegram_message_id'] = (int)$tg['json']['result']['message_id'];
    }

    if (empty($tg['ok'])) {
        $patch['telegram_error'] = (string)($tg['message'] ?? 'Telegram failed');
        $patch['telegram_raw'] = (string)($tg['raw'] ?? '');
    }

    foreach (['PENDING', 'PROCESSING', 'DONE'] as $bucket) {
        $row = mfs_fb_get('MFS_REQUESTS/' . $bucket . '/' . $requestId);

        if (is_array($row)) {
            mfs_fb_patch('MFS_REQUESTS/' . $bucket . '/' . $requestId, $patch);
            break;
        }
    }
}

$source = 'USER_API';

if (!empty($body['source'])) {
    $requestedSource = strtoupper(trim((string)$body['source']));

    if (in_array($requestedSource, ['USER_API', 'USER_PANEL', 'APP'], true)) {
        $source = $requestedSource === 'APP' ? 'USER_API' : $requestedSource;
    }
}

$res = mfs_create_request(
    $uid,
    $body,
    $source,
    'PANEL',
    [
        'uid' => $uid,
        'role' => (string)($user['role'] ?? 'USER'),
        'ip' => mfs_create_endpoint_client_ip(),
        'session_id' => (string)($session['_session_hash'] ?? ''),
    ]
);

if (!($res['ok'] ?? false)) {
    $code = (string)($res['code'] ?? 'SERVER_ERROR');

    $httpStatus = 500;

    if (in_array($code, [
        'VALIDATION_ERROR',
        'INSUFFICIENT_BALANCE',
        'SERVICE_NOT_ALLOWED',
        'PROVIDER_DISABLED',
        'MFS_DISABLED',
        'COUNTRY_MISSING',
        'WALLET_CURRENCY_MISSING',
        'COUNTRY_CURRENCY_MISMATCH',
        'UNSUPPORTED_COUNTRY_CURRENCY',
    ], true)) {
        $httpStatus = 422;
    } elseif (in_array($code, ['ACCOUNT_INACTIVE', 'INVALID_PIN'], true)) {
        $httpStatus = 403;
    } elseif ($code === 'USER_NOT_FOUND') {
        $httpStatus = 404;
    } elseif ($code === 'UNAUTHORIZED') {
        $httpStatus = 401;
    }

    api_response(
        false,
        $code,
        (string)($res['message'] ?? 'Failed to create MFS request'),
        (array)($res['data'] ?? []),
        $httpStatus
    );
}

$data = (array)($res['data'] ?? []);

mfs_create_endpoint_notify_telegram($data, array_merge($user, ['uid' => $uid]));

api_response(
    true,
    (string)($res['code'] ?? 'SUCCESS'),
    (string)($res['message'] ?? 'MFS request created successfully'),
    $data,
    200
);
