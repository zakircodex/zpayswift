<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/topup_config.php';

function bundle_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function bundle_month_key(?int $ts = null): string
{
    if (function_exists('month_key') && $ts === null) {
        return (string)month_key();
    }

    return date('Y-m', $ts ?? bundle_now());
}

function bundle_round_money(float $amount): float
{
    return round($amount, 2);
}

function bundle_normalize_currency_label(string $currency): string
{
    $clean = strtoupper(trim($currency));
    if ($clean === 'RM') {
        return 'MYR';
    }

    return $clean === '' ? 'BDT' : $clean;
}

function bundle_financial_aliases(array $row): array
{
    $serviceAmount = bundle_round_money((float)(
        $row['service_amount_bdt']
        ?? $row['service_amount']
        ?? $row['price_amount']
        ?? $row['offer_price']
        ?? $row['amount']
        ?? 0
    ));
    $bundleCommission = bundle_round_money((float)(
        $row['bundle_commission']
        ?? $row['user_commission']
        ?? $row['customer_commission']
        ?? $row['user_discount']
        ?? 0
    ));
    $walletCurrency = bundle_normalize_currency_label((string)(
        $row['wallet_debit_currency']
        ?? $row['wallet_currency']
        ?? 'BDT'
    ));
    $walletDebit = bundle_round_money((float)(
        $row['wallet_debit_amount']
        ?? $row['wallet_hold_amount']
        ?? $row['held_amount']
        ?? $row['payable_amount']
        ?? $row['you_pay']
        ?? $serviceAmount
    ));
    $rate = bundle_round_money((float)(
        $row['rate_snapshot']
        ?? $row['rate_used']
        ?? $row['rate']
        ?? 0
    ));
    $walletDebitBdt = $walletCurrency === 'MYR'
        ? bundle_round_money((float)($row['wallet_debit_bdt'] ?? $row['payable_amount_bdt'] ?? 0))
        : $walletDebit;
    $walletDebitMyr = $walletCurrency === 'MYR'
        ? $walletDebit
        : bundle_round_money((float)($row['wallet_debit_myr'] ?? 0));

    return [
        'service_amount' => $serviceAmount,
        'service_amount_bdt' => $serviceAmount,
        'service_currency' => 'BDT',
        'bundle_commission' => $bundleCommission,
        'commission_currency' => 'BDT',
        'wallet_debit_amount' => $walletDebit,
        'wallet_debit_currency' => $walletCurrency,
        'wallet_currency' => $walletCurrency,
        'wallet_debit_bdt' => $walletDebitBdt,
        'wallet_debit_myr' => $walletDebitMyr,
        'rate_used' => $rate,
        'rate_snapshot' => $rate > 0 ? $rate : null,
        'rate_applicable' => $walletCurrency === 'MYR' && $rate > 0,
    ];
}

function bundle_with_financial_aliases(array $row): array
{
    return array_merge($row, bundle_financial_aliases($row));
}

function bundle_public_history_row(array $row): array
{
    $row = bundle_with_financial_aliases($row);
    $internalNote = trim((string)($row['note'] ?? ''));
    if ($internalNote !== '' && stripos($internalNote, 'created from android') !== false) {
        $row['internal_note'] = $internalNote;
        unset($row['note']);
    }

    return $row;
}

function bundle_preview_token_hash(string $token): string
{
    return hash('sha256', trim($token));
}

function bundle_create_preview_token(array $data): string
{
    $token = function_exists('random_token') ? random_token(32) : bin2hex(random_bytes(32));
    $hash = bundle_preview_token_hash($token);
    $now = bundle_now();
    $data['preview_token_hash'] = $hash;
    $data['created_at'] = $now;
    $data['expires_at'] = (int)($data['expires_at'] ?? ($now + 300));
    $data['used'] = false;
    $data['used_at'] = 0;
    $data['status'] = 'READY';

    if (!fb_put('BUNDLE_PREVIEWS/' . $hash, $data)) {
        return '';
    }

    return $token;
}

function bundle_token_error(string $code, string $message, array $data = [], int $httpStatus = 422): array
{
    return [
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'data' => $data,
        'http_status' => $httpStatus,
    ];
}

function bundle_claim_preview_token(string $tokenHash, string $uid): array
{
    $tokenHash = trim($tokenHash);
    if ($tokenHash === '') {
        return bundle_token_error('BUNDLE_PREVIEW_REQUIRED', 'Bundle preview token is required.');
    }

    $path = 'BUNDLE_PREVIEWS/' . $tokenHash;
    for ($i = 0; $i < 5; $i++) {
        $res = fb_get_with_etag($path);
        if (!($res['ok'] ?? false) || !is_array($res['value'] ?? null) || empty($res['etag'])) {
            return bundle_token_error('BUNDLE_PREVIEW_INVALID', 'Bundle preview is invalid. Please validate again.');
        }

        $row = $res['value'];
        $status = strtoupper((string)($row['status'] ?? 'READY'));
        if (!empty($row['used']) || $status === 'USED') {
            $requestId = trim((string)($row['request_id'] ?? ''));
            if ($requestId !== '') {
                $row['_token_hash'] = $tokenHash;
                return [
                    'ok' => true,
                    'duplicate' => true,
                    'request_id' => $requestId,
                    'preview' => $row,
                ];
            }

            return bundle_token_error('BUNDLE_ALREADY_SUBMITTED', 'This bundle preview was already submitted.');
        }
        if ($status === 'PROCESSING') {
            return bundle_token_error('BUNDLE_ALREADY_SUBMITTED', 'This bundle request is already being submitted.', [], 409);
        }
        if ((int)($row['expires_at'] ?? 0) < bundle_now()) {
            @fb_patch($path, [
                'status' => 'EXPIRED',
                'updated_at' => bundle_now(),
            ]);
            return bundle_token_error('BUNDLE_PREVIEW_EXPIRED', 'Bundle preview expired. Please validate again.');
        }
        if ((string)($row['uid'] ?? '') !== $uid) {
            return bundle_token_error('BUNDLE_PREVIEW_INVALID', 'Bundle preview does not belong to this account.', [], 403);
        }
        if (!in_array($status, ['READY', 'ACTIVE', 'FAILED'], true)) {
            return bundle_token_error('BUNDLE_ALREADY_SUBMITTED', 'This bundle request is already being submitted.', [], 409);
        }

        $claimed = $row;
        $claimed['status'] = 'PROCESSING';
        $claimed['processing_at'] = bundle_now();
        $claimed['updated_at'] = bundle_now();

        $save = fb_put_if_match($path, $claimed, (string)$res['etag']);
        if (($save['status'] ?? 0) === 412) {
            usleep(150000);
            continue;
        }
        if (!($save['ok'] ?? false)) {
            return bundle_token_error('BUNDLE_PREVIEW_CLAIM_FAILED', 'Bundle preview could not be locked. Please try again.', [], 409);
        }

        $claimed['_token_hash'] = $tokenHash;
        return ['ok' => true, 'preview' => $claimed];
    }

    return bundle_token_error('BUNDLE_ALREADY_SUBMITTED', 'This bundle request is already being submitted.', [], 409);
}

function bundle_mark_preview_used(string $tokenHash, string $requestId): void
{
    $tokenHash = trim($tokenHash);
    if ($tokenHash === '') {
        return;
    }

    @fb_patch('BUNDLE_PREVIEWS/' . $tokenHash, [
        'used' => true,
        'used_at' => bundle_now(),
        'status' => 'USED',
        'request_id' => $requestId,
        'updated_at' => bundle_now(),
    ]);
}

function bundle_mark_preview_failed(string $tokenHash, string $code, string $message = ''): void
{
    $tokenHash = trim($tokenHash);
    if ($tokenHash === '') {
        return;
    }

    @fb_patch('BUNDLE_PREVIEWS/' . $tokenHash, [
        'status' => 'FAILED',
        'failed_code' => bundle_clean_string($code),
        'failed_message' => bundle_clean_string($message),
        'updated_at' => bundle_now(),
    ]);
}

function bundle_notification_amount_text(array $row): string
{
    $amount = bundle_round_money((float)($row['you_pay'] ?? $row['payable_amount'] ?? $row['amount'] ?? 0));
    return number_format($amount, 2, '.', '') . ' BDT';
}

function bundle_record_user_notification(array $row, string $requestId, string $status): bool
{
    $uid = trim((string)($row['uid'] ?? ''));
    $requestId = trim($requestId);
    if ($uid === '' || $requestId === '') {
        return false;
    }
    $res = notification_emit_request_status_notification(
        'BUNDLE',
        $requestId,
        $uid,
        (string)($row['previous_status'] ?? 'WAITING_ADMIN'),
        strtoupper($status),
        $row,
        'bundle_status'
    );
    return !empty($res['ok']) || !empty($res['notification_id']);
}

function bundle_wallet_breakdown(
    string $uid,
    float $payableAmountBdt,
    array $user = [],
    array $wallet = []
): array {
    $payableAmountBdt = bundle_round_money(max(0, $payableAmountBdt));

    if (function_exists('wallet_service_bdt_to_native')) {
        $native = wallet_service_bdt_to_native($uid, $payableAmountBdt, $user, $wallet);

        return [
            'payable_amount_bdt' => $payableAmountBdt,
            'wallet_hold_amount' => (float)($native['wallet_amount'] ?? $payableAmountBdt),
            'wallet_currency' => (string)($native['wallet_currency'] ?? 'BDT'),
            'rate_used' => (float)($native['rate_used'] ?? 0),
        ];
    }

    return [
        'payable_amount_bdt' => $payableAmountBdt,
        'wallet_hold_amount' => $payableAmountBdt,
        'wallet_currency' => 'BDT',
        'rate_used' => 0.0,
    ];
}

function bundle_clean_string(mixed $value): string
{
    return trim((string)$value);
}

function bundle_make_offer_id(): string
{
    return 'BO' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function bundle_make_request_id(): string
{
    if (function_exists('make_bundle_request_id')) {
        return (string)make_bundle_request_id();
    }

    return 'BR' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function bundle_make_ledger_id(string $prefix = 'WLB'): string
{
    return $prefix . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

/*
|--------------------------------------------------------------------------
| TELEGRAM BUNDLE NOTIFICATION + BUTTON CONTROL HELPERS
|--------------------------------------------------------------------------
| Config.php এ এগুলো থাকতে হবে:
|
| define('TELEGRAM_BOT_TOKEN', 'YOUR_BOT_TOKEN');
| define('TELEGRAM_CHAT_ID', 'YOUR_CHAT_ID');
| define('TELEGRAM_WEBHOOK_SECRET', 'YOUR_RANDOM_SECRET');
| define('TELEGRAM_BUNDLE_ACTION_KEY', APP_KEY);
|--------------------------------------------------------------------------
*/

function bundle_telegram_bot_token(): string
{
    if (defined('TELEGRAM_BOT_TOKEN')) {
        return trim((string)TELEGRAM_BOT_TOKEN);
    }

    if (defined('ZAW_TELEGRAM_BOT_TOKEN')) {
        return trim((string)ZAW_TELEGRAM_BOT_TOKEN);
    }

    return '';
}

function bundle_telegram_chat_id(): string
{
    if (defined('TELEGRAM_CHAT_ID')) {
        return trim((string)TELEGRAM_CHAT_ID);
    }

    if (defined('TELEGRAM_BUNDLE_CHAT_ID')) {
        return trim((string)TELEGRAM_BUNDLE_CHAT_ID);
    }

    if (defined('ZAW_TELEGRAM_CHAT_ID')) {
        return trim((string)ZAW_TELEGRAM_CHAT_ID);
    }

    return '';
}

function bundle_telegram_action_key(): string
{
    if (defined('TELEGRAM_BUNDLE_ACTION_KEY')) {
        return trim((string)TELEGRAM_BUNDLE_ACTION_KEY);
    }

    return defined('APP_KEY') ? trim((string)APP_KEY) : '';
}

function bundle_telegram_enabled(): bool
{
    return bundle_telegram_bot_token() !== ''
        && bundle_telegram_chat_id() !== ''
        && bundle_telegram_action_key() !== '';
}

function bundle_telegram_h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bundle_telegram_money($value): string
{
    return number_format((float)$value, 2, '.', '');
}

function bundle_telegram_signature(string $requestId, string $actionCode): string
{
    return substr(hash_hmac('sha256', $actionCode . '|' . trim($requestId), bundle_telegram_action_key()), 0, 16);
}

function bundle_telegram_callback_data(string $requestId, string $actionCode): string
{
    $requestId = trim($requestId);
    $actionCode = strtolower(trim($actionCode));

    return 'bndl|' . $actionCode . '|' . $requestId . '|' . bundle_telegram_signature($requestId, $actionCode);
}

function bundle_telegram_parse_callback_data(string $callbackData): array
{
    $callbackData = trim($callbackData);
    $parts = explode('|', $callbackData);

    if (count($parts) !== 4 || $parts[0] !== 'bndl') {
        return [
            'ok' => false,
            'code' => 'INVALID_CALLBACK',
            'message' => 'Invalid callback data',
            'data' => [],
        ];
    }

    $actionCode = strtolower(trim($parts[1]));
    $requestId = trim($parts[2]);
    $signature = trim($parts[3]);

    if (!in_array($actionCode, ['s', 'f'], true)) {
        return [
            'ok' => false,
            'code' => 'INVALID_ACTION',
            'message' => 'Invalid bundle action',
            'data' => [],
        ];
    }

    if ($requestId === '') {
        return [
            'ok' => false,
            'code' => 'INVALID_REQUEST_ID',
            'message' => 'Request ID missing',
            'data' => [],
        ];
    }

    $expected = bundle_telegram_signature($requestId, $actionCode);

    if ($signature === '' || !hash_equals($expected, $signature)) {
        return [
            'ok' => false,
            'code' => 'INVALID_SIGNATURE',
            'message' => 'Invalid Telegram action signature',
            'data' => [],
        ];
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Callback parsed',
        'data' => [
            'request_id' => $requestId,
            'action_code' => $actionCode,
            'action' => $actionCode === 's' ? 'SUCCESS' : 'FAILED',
        ],
    ];
}

function bundle_telegram_api(string $method, array $payload): array
{
    if (!bundle_telegram_enabled()) {
        return [
            'ok' => false,
            'code' => 'TELEGRAM_DISABLED',
            'message' => 'Telegram config missing',
            'data' => [],
        ];
    }

    $url = 'https://api.telegram.org/bot' . bundle_telegram_bot_token() . '/' . ltrim($method, '/');

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = is_string($raw) ? json_decode($raw, true) : null;

    if ($http >= 200 && $http < 300 && is_array($json) && !empty($json['ok'])) {
        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Telegram API success',
            'data' => $json,
        ];
    }

    return [
        'ok' => false,
        'code' => 'TELEGRAM_API_FAILED',
        'message' => $err ?: 'Telegram API failed',
        'data' => [
            'http' => $http,
            'raw' => is_string($raw) ? substr($raw, 0, 800) : '',
            'json' => is_array($json) ? $json : null,
        ],
    ];
}

function bundle_telegram_effective_user_commission(array $row): float
{
    $priceAmount = bundle_round_money((float)(
        $row['price_amount']
        ?? $row['offer_price']
        ?? $row['price']
        ?? $row['amount']
        ?? 0
    ));

    $payableAmount = bundle_round_money((float)(
        $row['payable_amount']
        ?? $row['you_pay']
        ?? $row['wallet_hold_amount']
        ?? $row['held_amount']
        ?? 0
    ));

    $customerCommission = bundle_round_money((float)(
        $row['customer_commission']
        ?? $row['user_discount']
        ?? 0
    ));

    $userCommission = bundle_round_money((float)($row['user_commission'] ?? 0));

    if ($customerCommission > 0) {
        return $customerCommission;
    }

    if ($userCommission > 0) {
        return $userCommission;
    }

    if ($priceAmount > 0 && $payableAmount > 0 && $payableAmount <= $priceAmount) {
        return bundle_round_money($priceAmount - $payableAmount);
    }

    return 0.0;
}

function bundle_telegram_build_request_message(array $row): string
{
    $requestId = (string)($row['request_id'] ?? '-');
    $uid = (string)($row['uid'] ?? '-');
    $userPhone = (string)($row['user_phone'] ?? '-');
    $bundleNumber = (string)($row['bundle_number'] ?? $row['topup_number'] ?? $row['number'] ?? '-');
    $operator = (string)($row['operator'] ?? '-');
    $bundleName = (string)($row['bundle_name'] ?? $row['name'] ?? '-');

    $priceAmount = bundle_round_money((float)($row['price_amount'] ?? $row['offer_price'] ?? $row['amount'] ?? 0));
    $userCommission = bundle_telegram_effective_user_commission($row);
    $userPay = bundle_round_money((float)($row['you_pay'] ?? $row['payable_amount'] ?? $row['wallet_hold_amount'] ?? $row['held_amount'] ?? max(0, $priceAmount - $userCommission)));

    $createdAt = (int)($row['created_at'] ?? bundle_now());

    return
        "🟢 <b>New Bundle Request</b>\n\n" .
        "Request ID: <code>" . bundle_telegram_h($requestId) . "</code>\n" .
        "UID: <code>" . bundle_telegram_h($uid) . "</code>\n" .
        "User Phone: <code>" . bundle_telegram_h($userPhone) . "</code>\n\n" .

        "📞 <b>Bundle Number:</b>\n" .
        "<code>" . bundle_telegram_h($bundleNumber) . "</code>\n\n" .

        "Operator: <b>" . bundle_telegram_h($operator) . "</b>\n" .
        "Bundle: " . bundle_telegram_h($bundleName) . "\n\n" .

        "Price: BDT " . bundle_telegram_money($priceAmount) . "\n" .
        "User Commission: BDT " . bundle_telegram_money($userCommission) . "\n" .
        "User Pay: BDT " . bundle_telegram_money($userPay) . "\n\n" .

        "Status: <b>WAITING_ADMIN</b>\n" .
        "Created: " . bundle_telegram_h(date('Y-m-d H:i:s', $createdAt)) . "\n\n" .
        "নিচের button থেকে Success অথবা Failed করতে পারবেন।";
}

function bundle_telegram_build_done_message(array $row, string $status, string $message): string
{
    $requestId = (string)($row['request_id'] ?? '-');
    $bundleNumber = (string)($row['bundle_number'] ?? $row['topup_number'] ?? $row['number'] ?? '-');
    $operator = (string)($row['operator'] ?? '-');
    $bundleName = (string)($row['bundle_name'] ?? '-');

    $priceAmount = bundle_round_money((float)($row['price_amount'] ?? $row['offer_price'] ?? $row['amount'] ?? 0));
    $userCommission = bundle_telegram_effective_user_commission($row);
    $userPay = bundle_round_money((float)($row['you_pay'] ?? $row['payable_amount'] ?? $row['wallet_hold_amount'] ?? $row['held_amount'] ?? max(0, $priceAmount - $userCommission)));

    $icon = strtoupper($status) === 'SUCCESS' ? '✅' : '❌';

    return
        $icon . " <b>Bundle " . bundle_telegram_h(strtoupper($status)) . "</b>\n\n" .
        "Request ID: <code>" . bundle_telegram_h($requestId) . "</code>\n\n" .

        "📞 <b>Bundle Number:</b>\n" .
        "<code>" . bundle_telegram_h($bundleNumber) . "</code>\n\n" .

        "Operator: <b>" . bundle_telegram_h($operator) . "</b>\n" .
        "Bundle: " . bundle_telegram_h($bundleName) . "\n\n" .

        "Price: BDT " . bundle_telegram_money($priceAmount) . "\n" .
        "User Commission: BDT " . bundle_telegram_money($userCommission) . "\n" .
        "User Pay: BDT " . bundle_telegram_money($userPay) . "\n\n" .

        "Message: " . bundle_telegram_h($message !== '' ? $message : strtoupper($status)) . "\n" .
        "Updated: " . bundle_telegram_h(date('Y-m-d H:i:s', bundle_now()));
}

function bundle_telegram_request_keyboard(string $requestId): array
{
    return [
        'inline_keyboard' => [
            [
                [
                    'text' => '✅ Success',
                    'callback_data' => bundle_telegram_callback_data($requestId, 's'),
                ],
                [
                    'text' => '❌ Failed',
                    'callback_data' => bundle_telegram_callback_data($requestId, 'f'),
                ],
            ],
        ],
    ];
}

function bundle_notify_telegram_bundle_request(array $row): array
{
    if (!bundle_telegram_enabled()) {
        return [
            'ok' => false,
            'code' => 'TELEGRAM_DISABLED',
            'message' => 'Telegram config missing',
            'data' => [],
        ];
    }

    $requestId = trim((string)($row['request_id'] ?? ''));
    if ($requestId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'request_id missing for Telegram message',
            'data' => [],
        ];
    }

    $res = bundle_telegram_api('sendMessage', [
        'chat_id' => bundle_telegram_chat_id(),
        'text' => bundle_telegram_build_request_message($row),
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => bundle_telegram_request_keyboard($requestId),
    ]);

    if (!($res['ok'] ?? false)) {
        return $res;
    }

    $data = (array)($res['data'] ?? []);
    $result = (array)($data['result'] ?? []);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Telegram message sent',
        'data' => [
            'message_id' => (int)($result['message_id'] ?? 0),
            'chat_id' => (string)($result['chat']['id'] ?? bundle_telegram_chat_id()),
        ],
    ];
}

function bundle_telegram_answer_callback(string $callbackQueryId, string $text, bool $alert = false): void
{
    $callbackQueryId = trim($callbackQueryId);

    if ($callbackQueryId === '' || !bundle_telegram_enabled()) {
        return;
    }

    bundle_telegram_api('answerCallbackQuery', [
        'callback_query_id' => $callbackQueryId,
        'text' => $text,
        'show_alert' => $alert,
    ]);
}

function bundle_telegram_edit_request_message(array $row, string $status, string $message): void
{
    if (!bundle_telegram_enabled()) {
        return;
    }

    $chatId = trim((string)($row['telegram_chat_id'] ?? ''));
    $messageId = (int)($row['telegram_message_id'] ?? 0);

    if ($chatId === '' || $messageId <= 0) {
        return;
    }

    bundle_telegram_api('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => bundle_telegram_build_done_message($row, $status, $message),
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => [
            'inline_keyboard' => [],
        ],
    ]);
}

function bundle_duration_to_seconds(float $durationValue, string $durationUnit): int
{
    $durationValue = max(0, $durationValue);
    $durationUnit = strtoupper(trim($durationUnit));

    if ($durationValue <= 0) {
        return 0;
    }

    switch ($durationUnit) {
        case 'MINUTE':
        case 'MINUTES':
            return (int)round($durationValue * 60);

        case 'HOUR':
        case 'HOURS':
            return (int)round($durationValue * 3600);

        case 'DAY':
        case 'DAYS':
            return (int)round($durationValue * 86400);

        default:
            return (int)round($durationValue);
    }
}

function bundle_offer_price(array $offer): float
{
    return bundle_round_money((float)(
        $offer['price_amount']
        ?? $offer['offer_price']
        ?? $offer['price']
        ?? $offer['amount']
        ?? 0
    ));
}

function bundle_is_expired(array $offer, ?int $now = null): bool
{
    $now = $now ?? bundle_now();
    $expiresAt = (int)($offer['expires_at'] ?? 0);

    return $expiresAt > 0 && $expiresAt <= $now;
}

function bundle_is_active_offer(array $offer, ?int $now = null): bool
{
    $status = strtoupper(trim((string)($offer['status'] ?? 'ACTIVE')));
    $active = (bool)($offer['active'] ?? true);

    if (!$active || $status !== 'ACTIVE') {
        return false;
    }

    if (bundle_is_expired($offer, $now)) {
        return false;
    }

    return true;
}

function bundle_load_user(string $uid): array
{
    $row = fb_get('USERS/' . trim($uid));
    return is_array($row) ? $row : [];
}

function bundle_load_wallet(string $uid): array
{
    $row = fb_get('USER_WALLETS/' . trim($uid));
    return is_array($row) ? $row : [];
}

function bundle_user_parent_subadmin_uid(array $user): string
{
    return trim((string)($user['parent_subadmin_uid'] ?? $user['created_by_uid'] ?? ''));
}

function bundle_effective_subadmin_uid_for_user(array $user): string
{
    $role = strtoupper(trim((string)($user['role'] ?? '')));
    $uid = trim((string)($user['uid'] ?? ''));

    if ($role === 'SUBADMIN') {
        return $uid;
    }

    return bundle_user_parent_subadmin_uid($user);
}

function bundle_actor_can_access_target(array $actor, array $targetUser): bool
{
    $actorRole = strtoupper(trim((string)($actor['role'] ?? '')));
    $actorUid = trim((string)($actor['uid'] ?? ''));

    if ($actorRole === 'ADMIN') {
        return true;
    }

    if ($actorRole === 'SUBADMIN') {
        return bundle_user_parent_subadmin_uid($targetUser) === $actorUid;
    }

    return false;
}

function bundle_normalize_offer_payload(array $input, array $existing = []): array
{
    $now = bundle_now();

    $offerId = trim((string)($existing['offer_id'] ?? $input['offer_id'] ?? ''));
    if ($offerId === '') {
        $offerId = bundle_make_offer_id();
    }

    $operator = strtoupper(trim((string)($input['operator'] ?? $existing['operator'] ?? '')));
    $bundleName = trim((string)($input['bundle_name'] ?? $input['name'] ?? $existing['bundle_name'] ?? ''));
    $description = trim((string)($input['description'] ?? $input['note'] ?? $existing['description'] ?? ''));

    $rawPrice = $input['price_amount']
        ?? $input['offer_price']
        ?? $input['price']
        ?? $input['amount']
        ?? $existing['price_amount']
        ?? $existing['offer_price']
        ?? $existing['price']
        ?? $existing['amount']
        ?? 0;

    $priceAmount = bundle_round_money((float)$rawPrice);
    $adminCommission = bundle_round_money((float)($input['admin_commission'] ?? $existing['admin_commission'] ?? 0));

    if ($adminCommission < 0) {
        $adminCommission = 0.0;
    }

    if ($priceAmount > 0 && $adminCommission > $priceAmount) {
        $adminCommission = $priceAmount;
    }

    $durationValue = (float)($input['duration_value'] ?? $existing['duration_value'] ?? 0);
    $durationUnit = strtoupper(trim((string)($input['duration_unit'] ?? $existing['duration_unit'] ?? 'HOURS')));
    $durationSeconds = (int)($input['duration_seconds'] ?? $existing['duration_seconds'] ?? 0);

    if ($durationSeconds <= 0 && $durationValue > 0) {
        $durationSeconds = bundle_duration_to_seconds($durationValue, $durationUnit);
    }

    $createdAt = (int)($existing['created_at'] ?? $now);
    $expiresAt = (int)($input['expires_at'] ?? 0);

    if ($expiresAt <= 0 && $durationSeconds > 0) {
        $expiresAt = $now + $durationSeconds;
    } elseif ($expiresAt <= 0) {
        $expiresAt = (int)($existing['expires_at'] ?? 0);
    }

    $active = array_key_exists('active', $input)
        ? (bool)$input['active']
        : (bool)($existing['active'] ?? true);

    $status = strtoupper(trim((string)($input['status'] ?? $existing['status'] ?? 'ACTIVE')));
    if (!in_array($status, ['ACTIVE', 'INACTIVE', 'DELETED', 'EXPIRED'], true)) {
        $status = 'ACTIVE';
    }

    return [
        'offer_id' => $offerId,
        'operator' => $operator,
        'bundle_name' => $bundleName,
        'name' => $bundleName,
        'description' => $description,

        'amount' => $priceAmount,
        'price_amount' => $priceAmount,
        'offer_price' => $priceAmount,

        'admin_commission' => $adminCommission,

        'duration_value' => $durationValue,
        'duration_unit' => $durationUnit,
        'duration_seconds' => max(0, $durationSeconds),
        'expires_at' => $expiresAt,
        'active' => $active,
        'status' => $status,
        'created_at' => $createdAt,
        'updated_at' => $now,
    ];
}

function bundle_validate_offer(array $offer): array
{
    if (trim((string)($offer['operator'] ?? '')) === '') {
        return [false, 'operator is required'];
    }

    if (trim((string)($offer['bundle_name'] ?? '')) === '') {
        return [false, 'bundle_name is required'];
    }

    $priceAmount = bundle_offer_price($offer);
    $adminCommission = (float)($offer['admin_commission'] ?? 0);

    if ($priceAmount <= 0) {
        return [false, 'amount must be greater than zero'];
    }

    if ($adminCommission < 0) {
        return [false, 'admin_commission cannot be negative'];
    }

    if ($adminCommission > $priceAmount) {
        return [false, 'admin_commission cannot be higher than bundle price'];
    }

    return [true, 'OK'];
}

function bundle_admin_save_offer(array $input, array $actor = []): array
{
    $offerId = trim((string)($input['offer_id'] ?? ''));
    $existing = [];

    if ($offerId !== '') {
        $old = fb_get('BUNDLE_OFFERS/' . $offerId);
        $existing = is_array($old) ? $old : [];
    }

    $offer = bundle_normalize_offer_payload($input, $existing);

    if (!empty($actor)) {
        if (empty($offer['created_by_uid'])) {
            $offer['created_by_uid'] = (string)($actor['uid'] ?? '');
        }

        if (empty($offer['created_by_role'])) {
            $offer['created_by_role'] = (string)($actor['role'] ?? '');
        }

        $offer['updated_by_uid'] = (string)($actor['uid'] ?? '');
        $offer['updated_by_role'] = (string)($actor['role'] ?? '');
    }

    [$valid, $message] = bundle_validate_offer($offer);
    if (!$valid) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => $message,
            'data' => [],
        ];
    }

    $ok = fb_put('BUNDLE_OFFERS/' . $offer['offer_id'], $offer);
    if (!$ok) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to save bundle offer',
            'data' => [],
        ];
    }

    if (function_exists('system_log')) {
        system_log('BUNDLE_OFFER_SAVE', (string)$offer['offer_id'], 'Bundle offer saved', [
            'offer_id' => (string)$offer['offer_id'],
            'operator' => (string)$offer['operator'],
            'price_amount' => (float)$offer['price_amount'],
            'admin_commission' => (float)$offer['admin_commission'],
            'actor_uid' => (string)($actor['uid'] ?? ''),
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle offer saved successfully',
        'data' => $offer,
    ];
}

function bundle_admin_delete_offer(string $offerId, array $actor = []): array
{
    $offerId = trim($offerId);
    if ($offerId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'offer_id is required',
            'data' => [],
        ];
    }

    $existing = fb_get('BUNDLE_OFFERS/' . $offerId);
    if (!is_array($existing)) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'Bundle offer not found',
            'data' => [],
        ];
    }

    $now = bundle_now();
    $ok = fb_patch('BUNDLE_OFFERS/' . $offerId, [
        'active' => false,
        'status' => 'DELETED',
        'deleted' => true,
        'deleted_at' => $now,
        'updated_at' => $now,
        'deleted_by_uid' => (string)($actor['uid'] ?? ''),
        'deleted_by_role' => (string)($actor['role'] ?? ''),
    ]);

    if (!$ok) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to delete bundle offer',
            'data' => [],
        ];
    }

    if (function_exists('system_log')) {
        system_log('BUNDLE_OFFER_DELETE', $offerId, 'Bundle offer deleted', [
            'offer_id' => $offerId,
            'actor_uid' => (string)($actor['uid'] ?? ''),
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle offer deleted successfully',
        'data' => [
            'offer_id' => $offerId,
            'deleted_at' => $now,
        ],
    ];
}

function bundle_admin_list_offers(bool $includeInactive = true): array
{
    bundle_expire_old_offers();

    $items = fb_get('BUNDLE_OFFERS');
    if (!is_array($items)) {
        return [];
    }

    $now = bundle_now();
    $out = [];

    foreach ($items as $offerId => $row) {
        if (!is_array($row)) {
            continue;
        }

        $row['offer_id'] = (string)($row['offer_id'] ?? $offerId);
        $row['expired'] = bundle_is_expired($row, $now);

        $priceAmount = bundle_offer_price($row);
        $row['amount'] = $priceAmount;
        $row['price_amount'] = $priceAmount;
        $row['offer_price'] = $priceAmount;

        if (!$includeInactive && !bundle_is_active_offer($row, $now)) {
            continue;
        }

        $out[] = $row;
    }

    usort($out, static function (array $a, array $b): int {
        return (int)($b['updated_at'] ?? $b['created_at'] ?? 0) <=> (int)($a['updated_at'] ?? $a['created_at'] ?? 0);
    });

    return array_values($out);
}

function bundle_expire_old_offers(): void
{
    $items = fb_get('BUNDLE_OFFERS');
    if (!is_array($items)) {
        return;
    }

    $now = bundle_now();

    foreach ($items as $offerId => $row) {
        if (!is_array($row)) {
            continue;
        }

        $status = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));
        if ($status !== 'ACTIVE') {
            continue;
        }

        if (bundle_is_expired($row, $now)) {
            fb_patch('BUNDLE_OFFERS/' . $offerId, [
                'active' => false,
                'status' => 'EXPIRED',
                'expired' => true,
                'expired_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

function bundle_load_offer(string $offerId): array
{
    $offerId = trim($offerId);
    if ($offerId === '') {
        return [];
    }

    $row = fb_get('BUNDLE_OFFERS/' . $offerId);
    return is_array($row) ? $row : [];
}

function bundle_load_subadmin_custom_offer(string $subadminUid, string $offerId): array
{
    $subadminUid = trim($subadminUid);
    $offerId = trim($offerId);

    if ($subadminUid === '' || $offerId === '') {
        return [];
    }

    $row = fb_get('SUBADMIN_BUNDLE_OFFERS/' . $subadminUid . '/' . $offerId);
    return is_array($row) ? $row : [];
}

function bundle_subadmin_save_custom_offer(string $subadminUid, string $offerId, float $userCommission, bool $active = true): array
{
    $subadminUid = trim($subadminUid);
    $offerId = trim($offerId);
    $userCommission = bundle_round_money($userCommission);

    if ($subadminUid === '' || $offerId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'subadmin uid and offer_id are required',
            'data' => [],
        ];
    }

    $offer = bundle_load_offer($offerId);
    if (!$offer) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'Bundle offer not found',
            'data' => [],
        ];
    }

    if (!bundle_is_active_offer($offer)) {
        return [
            'ok' => false,
            'code' => 'OFFER_INACTIVE',
            'message' => 'Bundle offer is not active',
            'data' => [],
        ];
    }

    $priceAmount = bundle_offer_price($offer);
    $adminCommission = bundle_round_money((float)($offer['admin_commission'] ?? 0));

    if ($adminCommission < 0) {
        $adminCommission = 0.0;
    }

    if ($adminCommission > $priceAmount) {
        $adminCommission = $priceAmount;
    }

    if ($userCommission < 0) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'User commission cannot be negative',
            'data' => [],
        ];
    }

    if ($userCommission > $adminCommission) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'User commission cannot be higher than admin commission',
            'data' => [
                'admin_commission' => $adminCommission,
                'max_user_commission' => $adminCommission,
            ],
        ];
    }

    $now = bundle_now();
    $profit = bundle_round_money($adminCommission - $userCommission);

    $oldCreatedAt = fb_get('SUBADMIN_BUNDLE_OFFERS/' . $subadminUid . '/' . $offerId . '/created_at');
    $createdAt = is_numeric($oldCreatedAt) ? (int)$oldCreatedAt : $now;

    $row = [
        'offer_id' => $offerId,
        'subadmin_uid' => $subadminUid,
        'admin_commission' => $adminCommission,
        'user_commission' => $userCommission,
        'subadmin_profit' => $profit,
        'active' => $active,
        'status' => $active ? 'ACTIVE' : 'INACTIVE',
        'created_at' => $createdAt,
        'updated_at' => $now,
    ];

    $ok = fb_put('SUBADMIN_BUNDLE_OFFERS/' . $subadminUid . '/' . $offerId, $row);
    if (!$ok) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to save custom bundle offer',
            'data' => [],
        ];
    }

    if (function_exists('system_log')) {
        system_log('SUBADMIN_BUNDLE_CUSTOM_SAVE', $offerId, 'Subadmin customized bundle commission', [
            'subadmin_uid' => $subadminUid,
            'offer_id' => $offerId,
            'admin_commission' => $adminCommission,
            'user_commission' => $userCommission,
            'subadmin_profit' => $profit,
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Custom bundle offer saved successfully',
        'data' => $row,
    ];
}

function bundle_subadmin_disable_custom_offer(string $subadminUid, string $offerId): array
{
    $subadminUid = trim($subadminUid);
    $offerId = trim($offerId);

    if ($subadminUid === '' || $offerId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'subadmin uid and offer_id are required',
            'data' => [],
        ];
    }

    $row = bundle_load_subadmin_custom_offer($subadminUid, $offerId);
    if (!$row) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'Custom bundle offer not found',
            'data' => [],
        ];
    }

    $now = bundle_now();

    $ok = fb_patch('SUBADMIN_BUNDLE_OFFERS/' . $subadminUid . '/' . $offerId, [
        'active' => false,
        'status' => 'INACTIVE',
        'updated_at' => $now,
    ]);

    if (!$ok) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to disable custom bundle offer',
            'data' => [],
        ];
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Custom bundle offer disabled successfully',
        'data' => [
            'subadmin_uid' => $subadminUid,
            'offer_id' => $offerId,
            'updated_at' => $now,
        ],
    ];
}

function bundle_build_visible_offer_for_user(array $baseOffer, array $user): array
{
    $offerId = (string)($baseOffer['offer_id'] ?? '');
    $subadminUid = bundle_effective_subadmin_uid_for_user($user);
    $custom = bundle_load_subadmin_custom_offer($subadminUid, $offerId);

    $priceAmount = bundle_offer_price($baseOffer);
    $adminCommission = bundle_round_money((float)($baseOffer['admin_commission'] ?? 0));

    if ($adminCommission < 0) {
        $adminCommission = 0.0;
    }

    if ($adminCommission > $priceAmount) {
        $adminCommission = $priceAmount;
    }

    $userCommission = $adminCommission;
    $customized = false;

    if ($subadminUid !== '' && is_array($custom) && !empty($custom)) {
        $customActive = (bool)($custom['active'] ?? true);
        $customStatus = strtoupper(trim((string)($custom['status'] ?? 'ACTIVE')));

        if ($customActive && $customStatus === 'ACTIVE') {
            $customized = true;
            $userCommission = bundle_round_money((float)($custom['user_commission'] ?? $adminCommission));
        }
    }

    if ($userCommission < 0) {
        $userCommission = 0.0;
    }

    if ($userCommission > $adminCommission) {
        $userCommission = $adminCommission;
    }

    $subadminProfit = 0.0;
    if ($subadminUid !== '') {
        $subadminProfit = bundle_round_money(max(0, $adminCommission - $userCommission));
    }

    $youPay = bundle_round_money(max(0, $priceAmount - $userCommission));

    $baseOffer['amount'] = $priceAmount;
    $baseOffer['price_amount'] = $priceAmount;
    $baseOffer['offer_price'] = $priceAmount;

    $baseOffer['admin_commission'] = $adminCommission;
    $baseOffer['user_commission'] = $userCommission;
    $baseOffer['subadmin_profit'] = $subadminProfit;

    $baseOffer['net_cost_after_commission'] = $youPay;
    $baseOffer['you_pay'] = $youPay;
    $baseOffer['payable_amount'] = $youPay;
    $baseOffer['wallet_hold_amount'] = $youPay;

    $baseOffer['customized_by_subadmin'] = $customized;
    $baseOffer['subadmin_uid'] = $subadminUid;
    $baseOffer['max_user_commission'] = $adminCommission;

    return $baseOffer;
}

function bundle_list_visible_offers_for_user(string $uid): array
{
    bundle_expire_old_offers();

    $user = bundle_load_user($uid);
    if (!$user) {
        return [];
    }

    $user['uid'] = (string)($user['uid'] ?? $uid);

    $items = fb_get('BUNDLE_OFFERS');
    if (!is_array($items)) {
        return [];
    }

    $now = bundle_now();
    $out = [];

    foreach ($items as $offerId => $offer) {
        if (!is_array($offer)) {
            continue;
        }

        $offer['offer_id'] = (string)($offer['offer_id'] ?? $offerId);

        if (!bundle_is_active_offer($offer, $now)) {
            continue;
        }

        $out[] = bundle_build_visible_offer_for_user($offer, $user);
    }

    usort($out, static function (array $a, array $b): int {
        return (int)($b['updated_at'] ?? $b['created_at'] ?? 0) <=> (int)($a['updated_at'] ?? $a['created_at'] ?? 0);
    });

    return array_values($out);
}

function bundle_public_offer(array $item): array
{
    $validityValue = (float)($item['validity_value'] ?? 0);
    if ($validityValue <= 0) {
        $validityValue = (float)($item['package_validity_value'] ?? 0);
    }
    if ($validityValue <= 0) {
        $validityValue = (float)($item['bundle_validity_value'] ?? 0);
    }

    $validityUnit = trim((string)($item['validity_unit'] ?? ''));
    if ($validityUnit === '') {
        $validityUnit = trim((string)($item['package_validity_unit'] ?? ''));
    }
    if ($validityUnit === '') {
        $validityUnit = (string)($item['bundle_validity_unit'] ?? '');
    }

    $validitySeconds = (int)($item['validity_seconds'] ?? 0);
    if ($validitySeconds <= 0) {
        $validitySeconds = (int)($item['package_validity_seconds'] ?? 0);
    }
    if ($validitySeconds <= 0) {
        $validitySeconds = (int)($item['bundle_validity_seconds'] ?? 0);
    }

    $validityText = trim((string)($item['validity_text'] ?? ''));
    foreach (['package_validity', 'bundle_validity', 'validity', 'duration_text'] as $validityKey) {
        if ($validityText !== '') {
            break;
        }
        $validityText = trim((string)($item[$validityKey] ?? ''));
    }

    return [
        'offer_id' => (string)($item['offer_id'] ?? ''),
        'operator' => (string)($item['operator'] ?? ''),
        'operator_name' => (string)($item['operator_name'] ?? $item['operator'] ?? ''),
        'bundle_name' => (string)($item['bundle_name'] ?? $item['name'] ?? ''),
        'name' => (string)($item['name'] ?? $item['bundle_name'] ?? ''),
        'description' => (string)($item['description'] ?? ''),
        'internet' => (string)($item['internet'] ?? $item['data'] ?? $item['data_text'] ?? $item['internet_text'] ?? ''),
        'data' => (string)($item['data'] ?? ''),
        'data_text' => (string)($item['data_text'] ?? ''),
        'internet_text' => (string)($item['internet_text'] ?? ''),
        'minutes' => (string)($item['minutes'] ?? $item['minute'] ?? ''),
        'minute' => (string)($item['minute'] ?? ''),
        'sms' => (string)($item['sms'] ?? ''),
        'category' => (string)($item['category'] ?? $item['type'] ?? $item['bundle_type'] ?? $item['offer_type'] ?? ''),
        'type' => (string)($item['type'] ?? ''),
        'bundle_type' => (string)($item['bundle_type'] ?? ''),
        'offer_type' => (string)($item['offer_type'] ?? ''),
        'amount' => (float)($item['amount'] ?? $item['price_amount'] ?? $item['offer_price'] ?? 0),
        'price_amount' => (float)($item['price_amount'] ?? $item['amount'] ?? $item['offer_price'] ?? 0),
        'offer_price' => (float)($item['offer_price'] ?? $item['price_amount'] ?? $item['amount'] ?? 0),
        'user_commission' => (float)($item['user_commission'] ?? 0),
        'net_cost_after_commission' => (float)($item['net_cost_after_commission'] ?? $item['payable_amount'] ?? 0),
        'you_pay' => (float)($item['you_pay'] ?? $item['payable_amount'] ?? 0),
        'payable_amount' => (float)($item['payable_amount'] ?? $item['you_pay'] ?? 0),
        'wallet_hold_amount' => (float)($item['wallet_hold_amount'] ?? $item['payable_amount'] ?? $item['you_pay'] ?? 0),
        'duration_value' => (float)($item['duration_value'] ?? 0),
        'duration_unit' => (string)($item['duration_unit'] ?? ''),
        'duration_seconds' => (int)($item['duration_seconds'] ?? 0),
        'validity_value' => $validityValue,
        'validity_unit' => $validityUnit,
        'validity_seconds' => $validitySeconds,
        'validity_text' => $validityText,
        'validity' => (string)($item['validity'] ?? ''),
        'duration_text' => (string)($item['duration_text'] ?? ''),
        'expires_at' => (int)($item['expires_at'] ?? 0),
        'expired' => (bool)($item['expired'] ?? false),
        'status' => (string)($item['status'] ?? 'ACTIVE'),
        'active' => (bool)($item['active'] ?? true),
        'created_at' => (int)($item['created_at'] ?? 0),
        'updated_at' => (int)($item['updated_at'] ?? 0),
    ];
}

function bundle_visible_offer_for_user(string $uid, string $offerId, array $user = []): array
{
    $uid = trim($uid);
    $offerId = trim($offerId);

    if ($uid === '' || $offerId === '') {
        return [];
    }

    if (!$user) {
        $user = bundle_load_user($uid);
    }

    if (!$user) {
        return [];
    }

    $user['uid'] = (string)($user['uid'] ?? $uid);
    $offer = bundle_load_offer($offerId);

    if (!$offer || !bundle_is_active_offer($offer)) {
        return [];
    }

    $offer['offer_id'] = (string)($offer['offer_id'] ?? $offerId);
    return bundle_build_visible_offer_for_user($offer, $user);
}

function bundle_preview_for_user(string $uid, string $offerId, string $bundleNumber, array $user = [], array $wallet = []): array
{
    $uid = trim($uid);
    $offerId = trim($offerId);
    $bundleNumber = function_exists('normalize_bd_topup_number')
        ? normalize_bd_topup_number($bundleNumber)
        : preg_replace('/\D+/', '', $bundleNumber);

    if ($uid === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'uid is required', 'data' => []];
    }
    if ($offerId === '') {
        return ['ok' => false, 'code' => 'BUNDLE_OFFER_NOT_FOUND', 'message' => 'Bundle offer is required', 'data' => []];
    }
    if (!function_exists('is_valid_bd_topup_number') || !is_valid_bd_topup_number($bundleNumber)) {
        return ['ok' => false, 'code' => 'BUNDLE_NUMBER_INVALID', 'message' => 'Invalid bundle number', 'data' => ['field' => 'bundle_number']];
    }

    if (!$user) {
        $user = bundle_load_user($uid);
    }
    if (!$user) {
        return ['ok' => false, 'code' => 'USER_NOT_FOUND', 'message' => 'User not found', 'data' => []];
    }
    $user['uid'] = (string)($user['uid'] ?? $uid);

    $status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
    if ($status !== 'ACTIVE') {
        return ['ok' => false, 'code' => 'ACCOUNT_INACTIVE', 'message' => 'Account is inactive', 'data' => []];
    }

    $offer = bundle_visible_offer_for_user($uid, $offerId, $user);
    if (!$offer) {
        return ['ok' => false, 'code' => 'BUNDLE_OFFER_INACTIVE', 'message' => 'Bundle offer is unavailable', 'data' => ['offer_id' => $offerId]];
    }

    $operator = strtoupper(trim((string)($offer['operator'] ?? '')));
    if ($operator === '') {
        return ['ok' => false, 'code' => 'BUNDLE_OFFER_NOT_FOUND', 'message' => 'Bundle operator is unavailable', 'data' => ['offer_id' => $offerId]];
    }

    $numberSuggestion = function_exists('topup_suggest_operator_by_number')
        ? topup_suggest_operator_by_number('BD', $bundleNumber)
        : [];
    $normalizedOperator = function_exists('normalize_operator') ? normalize_operator($operator) : $operator;
    $normalizedNumberOperators = array_values(array_filter(array_map(
        static fn($value): string => function_exists('normalize_operator')
            ? normalize_operator((string)$value)
            : strtoupper(trim((string)$value)),
        is_array($numberSuggestion['candidates'] ?? null) ? $numberSuggestion['candidates'] : []
    )));
    if ($normalizedNumberOperators && !in_array($normalizedOperator, $normalizedNumberOperators, true)) {
        return [
            'ok' => false,
            'code' => 'BUNDLE_OPERATOR_MISMATCH',
            'message' => 'Mobile number does not match the selected bundle operator',
            'data' => ['field' => 'bundle_number'],
        ];
    }

    if (function_exists('require_active_operator')) {
        require_active_operator($operator);
    }

    $priceAmount = bundle_round_money((float)($offer['price_amount'] ?? $offer['amount'] ?? $offer['offer_price'] ?? 0));
    $payableAmount = bundle_round_money((float)($offer['payable_amount'] ?? $offer['you_pay'] ?? max(0, $priceAmount - (float)($offer['user_commission'] ?? 0))));
    if ($priceAmount <= 0 || $payableAmount <= 0) {
        return ['ok' => false, 'code' => 'BUNDLE_OFFER_NOT_FOUND', 'message' => 'Bundle offer price is invalid', 'data' => ['offer_id' => $offerId]];
    }

    $appConfig = fb_get('APP_CONFIG');
    if (is_array($appConfig)) {
        if (!(bool)($appConfig['bundle_enabled'] ?? true) || (bool)($appConfig['maintenance_mode'] ?? false)) {
            return ['ok' => false, 'code' => 'BUNDLE_DISABLED', 'message' => 'Bundle service is currently disabled', 'data' => []];
        }

        $min = bundle_round_money((float)($appConfig['min_bundle_amount'] ?? 0));
        $max = bundle_round_money((float)($appConfig['max_bundle_amount'] ?? 0));
        if ($min > 0 && $priceAmount < $min) {
            return ['ok' => false, 'code' => 'BUNDLE_MINIMUM_NOT_MET', 'message' => 'Amount is below minimum limit', 'data' => ['min_bundle_amount' => $min]];
        }
        if ($max > 0 && $priceAmount > $max) {
            return ['ok' => false, 'code' => 'BUNDLE_MAXIMUM_EXCEEDED', 'message' => 'Amount exceeds maximum limit', 'data' => ['max_bundle_amount' => $max]];
        }
    }

    if (!$wallet) {
        $wallet = bundle_load_wallet($uid);
    }

    $financials = bundle_wallet_breakdown($uid, $payableAmount, $user, $wallet);
    $walletDebit = bundle_round_money((float)($financials['wallet_hold_amount'] ?? $payableAmount));
    $available = bundle_round_money((float)($wallet['available_balance'] ?? 0));
    $balanceAfter = bundle_round_money($available - $walletDebit);

    if ($available < $walletDebit) {
        return [
            'ok' => false,
            'code' => 'INSUFFICIENT_BALANCE',
            'message' => 'Not enough balance',
            'data' => [
                'available_balance' => $available,
                'required_amount' => $walletDebit,
                'wallet_currency' => (string)($financials['wallet_currency'] ?? 'BDT'),
            ],
        ];
    }

    $preview = [
        'offer' => bundle_public_offer($offer),
        'offer_id' => $offerId,
        'operator' => $operator,
        'operator_name' => (string)($offer['operator_name'] ?? $operator),
        'bundle_number' => $bundleNumber,
        'bundle_name' => (string)($offer['bundle_name'] ?? $offer['name'] ?? ''),
        'service_amount' => $priceAmount,
        'service_amount_bdt' => $priceAmount,
        'amount' => $priceAmount,
        'price_amount' => $priceAmount,
        'bundle_commission' => (float)($offer['user_commission'] ?? 0),
        'user_commission' => (float)($offer['user_commission'] ?? 0),
        'payable_amount' => $payableAmount,
        'you_pay' => $payableAmount,
        'wallet_debit_amount' => $walletDebit,
        'wallet_hold_amount' => $walletDebit,
        'wallet_debit_currency' => (string)($financials['wallet_currency'] ?? 'BDT'),
        'wallet_currency' => (string)($financials['wallet_currency'] ?? 'BDT'),
        'rate_used' => (float)($financials['rate_used'] ?? 0),
        'available_balance' => $available,
        'balance_after' => $balanceAfter,
        'status' => 'WAITING_ADMIN',
        'display_status' => 'Pending',
    ];
    $preview = bundle_with_financial_aliases($preview);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle preview ready',
        'data' => $preview,
    ];
}

function bundle_list_visible_offers_for_subadmin(string $subadminUid): array
{
    $subadminUid = trim($subadminUid);

    if ($subadminUid === '') {
        return [];
    }

    $subadmin = bundle_load_user($subadminUid);
    if (!$subadmin) {
        return [];
    }

    $subadmin['uid'] = (string)($subadmin['uid'] ?? $subadminUid);
    $subadmin['role'] = 'SUBADMIN';

    bundle_expire_old_offers();

    $items = fb_get('BUNDLE_OFFERS');
    if (!is_array($items)) {
        return [];
    }

    $now = bundle_now();
    $out = [];

    foreach ($items as $offerId => $offer) {
        if (!is_array($offer)) {
            continue;
        }

        $offer['offer_id'] = (string)($offer['offer_id'] ?? $offerId);

        if (!bundle_is_active_offer($offer, $now)) {
            continue;
        }

        $out[] = bundle_build_visible_offer_for_user($offer, $subadmin);
    }

    usort($out, static function (array $a, array $b): int {
        return (int)($b['updated_at'] ?? $b['created_at'] ?? 0) <=> (int)($a['updated_at'] ?? $a['created_at'] ?? 0);
    });

    return array_values($out);
}

function create_bundle_pending_request(
    string $requestId,
    string $uid,
    string $userPhone,
    string $bundleNumber,
    string $operator,
    string $bundleName,
    float $amount,
    string $note,
    bool $telegramSent = false,
    string $telegramQueueId = '',
    array $extra = []
): bool {
    $now = bundle_now();

    $priceAmount = bundle_round_money((float)(
        $extra['price_amount']
        ?? $extra['offer_price']
        ?? $extra['amount']
        ?? $amount
    ));

    $adminCommission = bundle_round_money((float)($extra['admin_commission'] ?? 0));
    if ($adminCommission < 0) {
        $adminCommission = 0.0;
    }

    if ($priceAmount > 0 && $adminCommission > $priceAmount) {
        $adminCommission = $priceAmount;
    }

    $userCommission = array_key_exists('user_commission', $extra)
        ? bundle_round_money((float)$extra['user_commission'])
        : $adminCommission;

    if ($userCommission < 0) {
        $userCommission = 0.0;
    }

    if ($userCommission > $adminCommission) {
        $userCommission = $adminCommission;
    }

    $subadminUid = trim((string)($extra['subadmin_uid'] ?? ''));

    $subadminProfit = array_key_exists('subadmin_profit', $extra)
        ? bundle_round_money((float)$extra['subadmin_profit'])
        : ($subadminUid !== '' ? bundle_round_money(max(0, $adminCommission - $userCommission)) : 0.0);

    if ($subadminProfit < 0) {
        $subadminProfit = 0.0;
    }

    if ($subadminUid === '') {
        $subadminProfit = 0.0;
    }

    if (($userCommission + $subadminProfit) > $adminCommission) {
        $subadminProfit = bundle_round_money(max(0, $adminCommission - $userCommission));
    }

    $payableAmount = bundle_round_money((float)(
        $extra['you_pay']
        ?? $extra['payable_amount']
        ?? max(0, $priceAmount - $userCommission)
    ));
    $walletHoldAmount = bundle_round_money((float)(
        $extra['wallet_hold_amount']
        ?? $extra['held_amount']
        ?? $payableAmount
    ));
    $walletCurrency = (string)($extra['wallet_currency'] ?? $extra['wallet_debit_currency'] ?? 'BDT');
    $rateUsed = bundle_round_money((float)($extra['rate_used'] ?? 0));

    if ($payableAmount < 0) {
        $payableAmount = 0.0;
    }

    $row = [
        'request_id' => $requestId,
        'uid' => $uid,
        'user_phone' => $userPhone,
        'bundle_number' => $bundleNumber,
        'operator' => strtoupper(trim($operator)),
        'bundle_name' => $bundleName,

        'amount' => $priceAmount,
        'price_amount' => $priceAmount,
        'offer_price' => $priceAmount,

        'you_pay' => $payableAmount,
        'payable_amount' => $payableAmount,

        'note' => $note,
        'payable_amount_bdt' => (float)($extra['payable_amount_bdt'] ?? $payableAmount),
        'wallet_hold_amount' => $walletHoldAmount,
        'held_amount' => $walletHoldAmount,
        'wallet_debit_amount' => $walletHoldAmount,
        'wallet_debit_currency' => $walletCurrency,
        'wallet_currency' => $walletCurrency,
        'rate_used' => $rateUsed,
        'hold_settled_at' => 0,
        'hold_settlement_status' => 'PENDING',
        'status' => 'WAITING_ADMIN',

        'telegram_sent' => $telegramSent,
        'telegram_queue_id' => $telegramQueueId,
        'telegram_message_id' => 0,
        'telegram_chat_id' => '',
        'telegram_sent_at' => 0,
        'telegram_error' => '',

        'offer_id' => (string)($extra['offer_id'] ?? ''),
        'offer_source' => (string)($extra['offer_source'] ?? ''),
        'subadmin_uid' => $subadminUid,
        'customized_by_subadmin' => (bool)($extra['customized_by_subadmin'] ?? false),

        'admin_commission' => $adminCommission,
        'user_commission' => $userCommission,
        'subadmin_profit' => $subadminProfit,

        'commission_status' => 'PENDING',
        'commission_credited_at' => 0,
        'user_commission_credited' => false,
        'subadmin_profit_credited' => false,

        'created_at' => $now,
        'updated_at' => $now,
    ];
    $row = bundle_with_financial_aliases($row);

    foreach ($extra as $key => $value) {
        if (array_key_exists($key, $row)) {
            continue;
        }

        if (is_scalar($value) || is_array($value) || $value === null) {
            $row[$key] = $value;
        }
    }

    $ok = fb_put('BUNDLE_REQUESTS/PENDING/' . $requestId, $row);

    if (!$ok) {
        return false;
    }

    /*
     * Telegram notification request create আটকাবে না।
     * Telegram fail হলেও request save থাকবে।
     */
    $skipTelegram = !empty($extra['telegram_skip']) || $telegramSent === true;

    if (!$skipTelegram) {
        $tg = bundle_notify_telegram_bundle_request($row);
        $patchNow = bundle_now();

        if (!empty($tg['ok'])) {
            fb_patch('BUNDLE_REQUESTS/PENDING/' . $requestId, [
                'telegram_sent' => true,
                'telegram_sent_at' => $patchNow,
                'telegram_message_id' => (int)($tg['data']['message_id'] ?? 0),
                'telegram_chat_id' => (string)($tg['data']['chat_id'] ?? ''),
                'telegram_error' => '',
                'updated_at' => $patchNow,
            ]);
        } else {
            fb_patch('BUNDLE_REQUESTS/PENDING/' . $requestId, [
                'telegram_sent' => false,
                'telegram_error' => (string)($tg['message'] ?? $tg['code'] ?? 'Telegram send failed'),
                'updated_at' => $patchNow,
            ]);
        }
    }

    return true;
}

function bundle_write_history(array $done): bool
{
    $uid = (string)($done['uid'] ?? '');
    $requestId = (string)($done['request_id'] ?? '');

    if ($uid === '' || $requestId === '') {
        return false;
    }

    $priceAmount = bundle_round_money((float)($done['price_amount'] ?? $done['amount'] ?? 0));
    $payableAmount = bundle_round_money((float)($done['payable_amount'] ?? $done['you_pay'] ?? $done['wallet_hold_amount'] ?? $priceAmount));
    $walletHoldAmount = bundle_round_money((float)($done['wallet_hold_amount'] ?? $done['held_amount'] ?? $payableAmount));

    $historyRow = [
        'request_id' => $requestId,
        'offer_id' => (string)($done['offer_id'] ?? ''),
        'bundle_number' => (string)($done['bundle_number'] ?? ''),
        'operator' => (string)($done['operator'] ?? ''),
        'bundle_name' => (string)($done['bundle_name'] ?? ''),
        'amount' => $priceAmount,
        'price_amount' => $priceAmount,
        'offer_price' => $priceAmount,
        'you_pay' => $payableAmount,
        'payable_amount' => $payableAmount,
        'payable_amount_bdt' => (float)($done['payable_amount_bdt'] ?? $payableAmount),
        'wallet_hold_amount' => $walletHoldAmount,
        'wallet_debit_amount' => (float)($done['wallet_debit_amount'] ?? $walletHoldAmount),
        'wallet_debit_currency' => (string)($done['wallet_debit_currency'] ?? $done['wallet_currency'] ?? 'BDT'),
        'wallet_currency' => (string)($done['wallet_currency'] ?? $done['wallet_debit_currency'] ?? 'BDT'),
        'rate_used' => (float)($done['rate_used'] ?? 0),
        'admin_commission' => (float)($done['admin_commission'] ?? 0),
        'user_commission' => (float)($done['user_commission'] ?? 0),
        'subadmin_profit' => (float)($done['subadmin_profit'] ?? 0),
        'commission_status' => (string)($done['commission_status'] ?? ''),
        'commission_credited_at' => (int)($done['commission_credited_at'] ?? 0),
        'user_commission_credited' => (bool)($done['user_commission_credited'] ?? false),
        'subadmin_profit_credited' => (bool)($done['subadmin_profit_credited'] ?? false),
        'status' => (string)($done['status'] ?? ''),
        'message' => (string)($done['final_message'] ?? ''),
        'created_at' => (int)($done['created_at'] ?? bundle_now()),
        'completed_at' => (int)($done['completed_at'] ?? bundle_now()),
    ];

    return fb_put('BUNDLE_HISTORY/' . $uid . '/' . bundle_month_key() . '/' . $requestId, bundle_with_financial_aliases($historyRow));
}

function bundle_update_subadmin_request_log(array $row, string $status, string $message): void
{
    $uid = trim((string)($row['uid'] ?? ''));
    $requestId = trim((string)($row['request_id'] ?? ''));

    if ($uid === '' || $requestId === '') {
        return;
    }

    $now = bundle_now();
    $finalStatus = strtoupper(trim($status));

    $priceAmount = bundle_round_money((float)(
        $row['price_amount']
        ?? $row['offer_price']
        ?? $row['price']
        ?? $row['amount']
        ?? 0
    ));

    $payableAmount = bundle_round_money((float)(
        $row['payable_amount']
        ?? $row['you_pay']
        ?? $row['wallet_hold_amount']
        ?? $row['held_amount']
        ?? $priceAmount
    ));
    $walletHoldAmount = bundle_round_money((float)(
        $row['wallet_debit_amount']
        ?? $row['wallet_hold_amount']
        ?? $row['held_amount']
        ?? $payableAmount
    ));
    $walletCurrency = (string)($row['wallet_debit_currency'] ?? $row['wallet_currency'] ?? 'BDT');

    $patch = [
        'request_id' => $requestId,
        'uid' => $uid,

        'key_id' => (string)($row['source_key_id'] ?? $row['key_id'] ?? ''),
        'action' => (string)($row['action'] ?? 'BUNDLE_CREATE'),
        'request_type' => 'BUNDLE',
        'type' => 'BUNDLE',

        'status' => $finalStatus,
        'request_status' => $finalStatus,

        'operator' => (string)($row['operator'] ?? ''),
        'bundle_number' => (string)($row['bundle_number'] ?? ''),
        'topup_number' => (string)($row['bundle_number'] ?? ''),
        'number' => (string)($row['bundle_number'] ?? ''),

        'offer_id' => (string)($row['offer_id'] ?? ''),
        'bundle_name' => (string)($row['bundle_name'] ?? ''),

        'amount' => $priceAmount,
        'price_amount' => $priceAmount,
        'offer_price' => $priceAmount,

        'you_pay' => $payableAmount,
        'payable_amount' => $payableAmount,
        'payable_amount_bdt' => (float)($row['payable_amount_bdt'] ?? $payableAmount),
        'wallet_hold_amount' => $walletHoldAmount,
        'wallet_debit_amount' => $walletHoldAmount,
        'wallet_debit_currency' => $walletCurrency,
        'wallet_currency' => $walletCurrency,
        'rate_used' => (float)($row['rate_used'] ?? 0),

        'admin_commission' => (float)($row['admin_commission'] ?? 0),
        'user_commission' => (float)($row['user_commission'] ?? 0),
        'subadmin_profit' => (float)($row['subadmin_profit'] ?? 0),
        'subadmin_commission' => (float)($row['subadmin_profit'] ?? $row['subadmin_commission'] ?? 0),

        'commission_status' => (string)($row['commission_status'] ?? ''),
        'commission_credited_at' => (int)($row['commission_credited_at'] ?? 0),
        'user_commission_credited' => (bool)($row['user_commission_credited'] ?? false),
        'subadmin_profit_credited' => (bool)($row['subadmin_profit_credited'] ?? false),

        'message' => $message,
        'final_message' => $message,

        'updated_at' => $now,
        'completed_at' => $now,
    ];
    $patch = bundle_with_financial_aliases($patch);

    /*
     * User dashboard history সাধারণত এখান থেকে আসে।
     */
    fb_patch('USER_API_REQUESTS/' . $uid . '/' . $requestId, $patch);

    /*
     * Future compatibility: কোনো user panel যদি USER_REQUESTS path ব্যবহার করে।
     */
    fb_patch('USER_REQUESTS/' . $uid . '/' . $requestId, $patch);

    /*
     * Global request status sync.
     */
    fb_patch('REQUEST_STATUS/' . $requestId, [
        'request_id' => $requestId,
        'uid' => $uid,
        'request_type' => 'BUNDLE',
        'status' => $finalStatus,
        'message' => $message,
        'updated_at' => $now,
        'completed_at' => $now,
    ]);

    /*
     * Bundle history sync.
     */
    fb_patch('BUNDLE_HISTORY/' . $uid . '/' . bundle_month_key($now) . '/' . $requestId, $patch);
}

function bundle_wallet_credit_available_fallback(
    string $uid,
    float $amount,
    string $requestId,
    string $type,
    string $note,
    array $meta = []
): array {
    $uid = trim($uid);
    $requestId = trim($requestId);
    $amount = bundle_round_money($amount);

    if ($uid === '' || $requestId === '' || $amount <= 0) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Invalid wallet credit data',
        ];
    }

    $now = bundle_now();
    $wallet = bundle_load_wallet($uid);

    $beforeAvailable = bundle_round_money((float)($wallet['available_balance'] ?? 0));
    $beforeHold = bundle_round_money((float)($wallet['hold_balance'] ?? 0));
    $afterAvailable = bundle_round_money($beforeAvailable + $amount);

    $totalKey = $type === 'BUNDLE_SUBADMIN_PROFIT'
        ? 'total_bundle_profit'
        : 'total_bundle_commission';

    $oldTotal = bundle_round_money((float)($wallet[$totalKey] ?? 0));
    $newTotal = bundle_round_money($oldTotal + $amount);

    $patch = [
        'available_balance' => $afterAvailable,
        'hold_balance' => $beforeHold,
        $totalKey => $newTotal,
        'updated_at' => $now,
    ];

    $okWallet = fb_patch('USER_WALLETS/' . $uid, $patch);
    if (!$okWallet) {
        return [
            'ok' => false,
            'code' => 'WALLET_CREDIT_FAILED',
            'message' => 'Failed to credit wallet',
        ];
    }

    $ledgerId = bundle_make_ledger_id();
    $ledgerMonth = bundle_month_key($now);

    $ledger = [
        'ledger_id' => $ledgerId,
        'uid' => $uid,
        'type' => $type,
        'direction' => 'CREDIT',
        'amount' => $amount,
        'currency' => 'BDT',
        'before_available' => $beforeAvailable,
        'after_available' => $afterAvailable,
        'before_hold' => $beforeHold,
        'after_hold' => $beforeHold,
        'ref_id' => $requestId,
        'request_id' => $requestId,
        'note' => $note,
        'created_at' => $now,
        'created_by_uid' => 'SYSTEM',
        'created_by_role' => 'SYSTEM',
    ];

    foreach ($meta as $key => $value) {
        if (array_key_exists($key, $ledger)) {
            continue;
        }

        if (is_scalar($value) || is_array($value) || $value === null) {
            $ledger[$key] = $value;
        }
    }

    fb_put('WALLET_LEDGER/' . $uid . '/' . $ledgerMonth . '/' . $ledgerId, $ledger);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Wallet credited successfully',
        'ledger_id' => $ledgerId,
        'available_balance' => $afterAvailable,
    ];
}

function bundle_credit_user_commission_wallet(string $uid, float $amount, string $requestId, array $meta = []): array
{
    if (function_exists('wallet_credit_bundle_user_commission')) {
        return wallet_credit_bundle_user_commission($uid, $amount, $requestId, $meta);
    }

    return bundle_wallet_credit_available_fallback(
        $uid,
        $amount,
        $requestId,
        'BUNDLE_USER_COMMISSION',
        'Bundle user commission credited after success',
        $meta
    );
}

function bundle_credit_subadmin_profit_wallet(string $subadminUid, float $amount, string $requestId, array $meta = [], array $options = []): array
{
    if (function_exists('wallet_credit_bundle_subadmin_profit')) {
        return wallet_credit_bundle_subadmin_profit($subadminUid, $amount, $requestId, $meta, $options);
    }

    return bundle_wallet_credit_available_fallback(
        $subadminUid,
        $amount,
        $requestId,
        'BUNDLE_SUBADMIN_PROFIT',
        'Bundle subadmin profit credited after success',
        $meta
    );
}

function bundle_credit_commissions_after_success(array &$done): array
{
    $uid = trim((string)($done['uid'] ?? ''));
    $requestId = trim((string)($done['request_id'] ?? ''));

    if ($uid === '' || $requestId === '') {
        return [
            'ok' => false,
            'code' => 'INVALID_REQUEST',
            'message' => 'Missing uid or request id',
        ];
    }

    if ((int)($done['commission_credited_at'] ?? 0) > 0) {
        return [
            'ok' => true,
            'code' => 'ALREADY_CREDITED',
            'message' => 'Commission already credited',
        ];
    }

    $priceAmount = bundle_round_money((float)(
        $done['price_amount']
        ?? $done['offer_price']
        ?? $done['price']
        ?? $done['amount']
        ?? 0
    ));

    $payableAmount = bundle_round_money((float)(
        $done['payable_amount']
        ?? $done['you_pay']
        ?? $done['wallet_hold_amount']
        ?? $done['held_amount']
        ?? $priceAmount
    ));
    $walletHoldAmount = bundle_round_money((float)(
        $done['wallet_debit_amount']
        ?? $done['wallet_hold_amount']
        ?? $done['held_amount']
        ?? $payableAmount
    ));

    $adminCommission = bundle_round_money((float)($done['admin_commission'] ?? 0));

    if ($priceAmount < 0) {
        $priceAmount = 0.0;
    }

    if ($payableAmount < 0) {
        $payableAmount = 0.0;
    }

    if ($adminCommission < 0) {
        $adminCommission = 0.0;
    }

    if ($priceAmount > 0 && $adminCommission > $priceAmount) {
        $adminCommission = $priceAmount;
    }

    $userCommission = 0.0;
    if ($priceAmount > 0 && $payableAmount <= $priceAmount) {
        $userCommission = bundle_round_money($priceAmount - $payableAmount);
    } else {
        $userCommission = bundle_round_money((float)($done['user_commission'] ?? 0));
    }

    if ($userCommission < 0) {
        $userCommission = 0.0;
    }

    if ($userCommission > $adminCommission) {
        $userCommission = $adminCommission;
    }

    $subadminProfit = bundle_round_money(max(0, $adminCommission - $userCommission));
    $subadminUid = trim((string)($done['subadmin_uid'] ?? ''));
    $subadminProfitWalletAmount = $subadminProfit;
    $subadminProfitWalletCurrency = 'BDT';
    $subadminProfitRateUsed = 0.0;

    if ($subadminUid === '' || $subadminProfit <= 0) {
        $subadminProfit = 0.0;
        $subadminProfitWalletAmount = 0.0;
    } elseif (function_exists('wallet_service_bdt_to_native')) {
        $subadminProfitNative = wallet_service_bdt_to_native($subadminUid, $subadminProfit);
        $subadminProfitWalletAmount = bundle_round_money((float)($subadminProfitNative['wallet_amount'] ?? $subadminProfit));
        $subadminProfitWalletCurrency = (string)($subadminProfitNative['wallet_currency'] ?? 'BDT');
        $subadminProfitRateUsed = bundle_round_money((float)($subadminProfitNative['rate_used'] ?? 0));
    }

    $meta = [
        'offer_id' => (string)($done['offer_id'] ?? ''),
        'bundle_name' => (string)($done['bundle_name'] ?? ''),
        'operator' => (string)($done['operator'] ?? ''),
        'price_amount' => $priceAmount,
        'payable_amount' => $payableAmount,
        'you_pay' => $payableAmount,
        'admin_commission' => $adminCommission,
        'user_commission' => $userCommission,
        'subadmin_profit' => $subadminProfit,
        'subadmin_profit_bdt' => $subadminProfit,
        'subadmin_profit_wallet_amount' => $subadminProfitWalletAmount,
        'subadmin_profit_wallet_currency' => $subadminProfitWalletCurrency,
        'subadmin_profit_rate_used' => $subadminProfitRateUsed,
        'target_uid' => $uid,
        'bundle_number' => (string)($done['bundle_number'] ?? ''),
        'commission_rule' => 'SUBADMIN_PROFIT_ONLY_AFTER_SUCCESS',
    ];

    $creditedSubadmin = false;
    $commissionClaim = [];

    if ($subadminProfitWalletAmount > 0 && $subadminUid !== '') {
        $commissionOperation = function_exists('wallet_financial_operation_begin')
            ? wallet_financial_operation_begin($requestId, 'BUNDLE_COMMISSION_CREDIT', 'BUNDLE_COMMISSION', $subadminUid, $subadminProfitWalletAmount, $subadminProfitWalletCurrency, [
                'request_type' => 'BUNDLE',
                'target_uid' => $uid,
                'subadmin_profit_bdt' => $subadminProfit,
            ])
            : ['ok' => true, 'claim' => []];

        if (empty($commissionOperation['ok'])) {
            return [
                'ok' => false,
                'code' => (string)($commissionOperation['code'] ?? 'BUNDLE_COMMISSION_BUSY'),
                'message' => (string)($commissionOperation['message'] ?? 'Bundle commission is already being processed'),
            ];
        }
        if (!empty($commissionOperation['duplicate'])) {
            $operationRow = is_array($commissionOperation['operation'] ?? null) ? $commissionOperation['operation'] : [];
            $done['price_amount'] = $priceAmount;
            $done['offer_price'] = $priceAmount;
            $done['you_pay'] = $payableAmount;
            $done['payable_amount'] = $payableAmount;
            $done['wallet_hold_amount'] = $walletHoldAmount;
            $done['held_amount'] = $walletHoldAmount;
            $done['wallet_debit_amount'] = $walletHoldAmount;
            $done['admin_commission'] = $adminCommission;
            $done['user_commission'] = $userCommission;
            $done['customer_commission'] = $userCommission;
            $done['user_discount'] = $userCommission;
            $done['subadmin_profit'] = $subadminProfit;
            $done['subadmin_commission'] = $subadminProfit;
            $done['subadmin_profit_bdt'] = $subadminProfit;
            $done['subadmin_profit_wallet_amount'] = $subadminProfitWalletAmount;
            $done['subadmin_profit_wallet_currency'] = $subadminProfitWalletCurrency;
            $done['subadmin_profit_rate_used'] = $subadminProfitRateUsed;
            $done['commission_status'] = 'CREDITED';
            $done['commission_credited_at'] = (int)($operationRow['completed_at'] ?? $operationRow['updated_at'] ?? bundle_now());
            $done['user_commission_credited'] = false;
            $done['subadmin_profit_credited'] = true;
            return [
                'ok' => true,
                'code' => 'ALREADY_CREDITED',
                'message' => 'Bundle commission already credited',
            ];
        }

        $commissionClaim = (array)($commissionOperation['claim'] ?? []);
        $creditSub = bundle_credit_subadmin_profit_wallet(
            $subadminUid,
            $subadminProfitWalletAmount,
            $requestId,
            $meta,
            ['financial_operation' => $commissionClaim]
        );

        if (!($creditSub['ok'] ?? false)) {
            if (function_exists('wallet_financial_operation_mark_failed')) {
                wallet_financial_operation_mark_failed($commissionClaim, (string)($creditSub['code'] ?? 'SUBADMIN_PROFIT_FAILED'), (string)($creditSub['message'] ?? 'Failed to credit subadmin bundle profit'));
            }
            return [
                'ok' => false,
                'code' => (string)($creditSub['code'] ?? 'SUBADMIN_PROFIT_FAILED'),
                'message' => (string)($creditSub['message'] ?? 'Failed to credit subadmin bundle profit'),
            ];
        }

        $creditedSubadmin = true;
        $done['subadmin_profit_ledger_id'] = (string)($creditSub['ledger_id'] ?? '');
    }

    $now = bundle_now();

    $done['price_amount'] = $priceAmount;
    $done['offer_price'] = $priceAmount;
    $done['you_pay'] = $payableAmount;
    $done['payable_amount'] = $payableAmount;
    $done['wallet_hold_amount'] = $walletHoldAmount;
    $done['held_amount'] = $walletHoldAmount;
    $done['wallet_debit_amount'] = $walletHoldAmount;

    $done['admin_commission'] = $adminCommission;
    $done['user_commission'] = $userCommission;
    $done['customer_commission'] = $userCommission;
    $done['user_discount'] = $userCommission;
    $done['subadmin_profit'] = $subadminProfit;
    $done['subadmin_commission'] = $subadminProfit;
    $done['subadmin_profit_bdt'] = $subadminProfit;
    $done['subadmin_profit_wallet_amount'] = $subadminProfitWalletAmount;
    $done['subadmin_profit_wallet_currency'] = $subadminProfitWalletCurrency;
    $done['subadmin_profit_rate_used'] = $subadminProfitRateUsed;

    $done['commission_status'] = $creditedSubadmin ? 'CREDITED' : 'NO_COMMISSION';
    $done['commission_credited_at'] = $now;

    $done['user_commission_credited'] = false;
    $done['subadmin_profit_credited'] = $creditedSubadmin;
    if ($commissionClaim !== [] && function_exists('wallet_financial_operation_mark_completed')) {
        wallet_financial_operation_mark_completed($commissionClaim, [
            'final_status' => 'CREDITED',
            'target_uid' => $uid,
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle commission processed',
        'user_commission_credited' => false,
        'subadmin_profit_credited' => $creditedSubadmin,
        'user_commission' => $userCommission,
        'subadmin_profit' => $subadminProfit,
    ];
}

function bundle_mark_success(string $requestId, string $message): array
{
    $requestId = trim($requestId);

    $pending = fb_get('BUNDLE_REQUESTS/PENDING/' . $requestId);
    if (!is_array($pending)) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'Bundle request not found',
        ];
    }

    $uid = (string)($pending['uid'] ?? '');

    $settleAmount = bundle_round_money((float)(
        $pending['wallet_hold_amount']
        ?? $pending['payable_amount']
        ?? $pending['you_pay']
        ?? $pending['amount']
        ?? 0
    ));
    $walletCurrency = (string)($pending['wallet_debit_currency'] ?? $pending['wallet_currency'] ?? 'BDT');
    $operation = function_exists('wallet_financial_operation_begin')
        ? wallet_financial_operation_begin($requestId, 'BUNDLE_SUCCESS', 'REQUEST_FINAL', $uid, $settleAmount, $walletCurrency, [
            'request_type' => 'BUNDLE',
            'status' => (string)($pending['status'] ?? 'WAITING_ADMIN'),
        ])
        : ['ok' => true, 'claim' => []];

    if (empty($operation['ok'])) {
        return [
            'ok' => false,
            'code' => (string)($operation['code'] ?? 'FINANCIAL_OPERATION_FAILED'),
            'message' => (string)($operation['message'] ?? 'Bundle financial operation is already being processed'),
        ];
    }
    if (!empty($operation['duplicate'])) {
        return [
            'ok' => true,
            'code' => 'BUNDLE_SUCCESS',
            'message' => 'Bundle request already completed',
            'data' => [
                'request_id' => $requestId,
                'status' => 'SUCCESS',
                'settle_amount' => $settleAmount,
            ],
        ];
    }
    $financialClaim = (array)($operation['claim'] ?? []);

    if (!function_exists('wallet_settle_hold_bundle')) {
        if (function_exists('wallet_financial_operation_mark_failed')) {
            wallet_financial_operation_mark_failed($financialClaim, 'MISSING_WALLET_HELPER', 'wallet_settle_hold_bundle helper missing');
        }
        return [
            'ok' => false,
            'code' => 'MISSING_WALLET_HELPER',
            'message' => 'wallet_settle_hold_bundle helper missing',
        ];
    }

    $settle = wallet_settle_hold_bundle($uid, $settleAmount, $requestId, 'BUNDLE_SETTLE', [
        'financial_operation' => $financialClaim,
    ]);
    if (!($settle['ok'] ?? false)) {
        if (function_exists('wallet_financial_operation_mark_failed')) {
            wallet_financial_operation_mark_failed($financialClaim, (string)($settle['code'] ?? 'BUNDLE_SETTLE_FAILED'), (string)($settle['message'] ?? 'Bundle wallet settle failed'));
        }
        return $settle;
    }

    $done = $pending;
    $done['status'] = 'SUCCESS';
    $done['final_message'] = $message;
    $done['completed_at'] = bundle_now();
    $done['updated_at'] = bundle_now();

    $commission = bundle_credit_commissions_after_success($done);
    if (!($commission['ok'] ?? false)) {
        if (function_exists('wallet_financial_operation_mark_failed')) {
            wallet_financial_operation_mark_failed($financialClaim, (string)($commission['code'] ?? 'BUNDLE_COMMISSION_FAILED'), (string)($commission['message'] ?? 'Bundle commission processing failed'), [
                'wallet_applied' => true,
                'ledger_written' => true,
                'request_finalized' => false,
            ]);
        }
        return $commission;
    }

    if (!fb_put('BUNDLE_REQUESTS/DONE/' . $requestId, $done)) {
        if (function_exists('wallet_financial_operation_mark_failed')) {
            wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_FINALIZATION_FAILED', 'Failed to move bundle request to done bucket', [
                'wallet_applied' => true,
                'ledger_written' => true,
                'request_finalized' => false,
            ]);
        }
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to move bundle request to done bucket',
        ];
    }

    if (!fb_delete('BUNDLE_REQUESTS/PENDING/' . $requestId)) {
        if (function_exists('wallet_financial_operation_mark_failed')) {
            wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_FINALIZATION_FAILED', 'Failed to remove pending bundle request bucket', [
                'wallet_applied' => true,
                'ledger_written' => true,
                'request_finalized' => false,
            ]);
        }
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to move bundle request to done bucket',
        ];
    }

    if (function_exists('wallet_financial_operation_mark_applied')) {
        wallet_financial_operation_mark_applied($financialClaim, [
            'wallet_applied' => true,
            'ledger_written' => true,
            'request_finalized' => true,
            'final_status' => 'SUCCESS',
            'completed_bucket' => 'DONE',
        ]);
    }

    $historyWritten = bundle_write_history($done);
    bundle_update_subadmin_request_log($done, 'SUCCESS', $message);
    bundle_telegram_edit_request_message($done, 'SUCCESS', $message);

    if (function_exists('update_request_status')) {
        update_request_status($requestId, 'SUCCESS', $message);
    }

    if (function_exists('system_log')) {
        system_log('BUNDLE_SUCCESS', $requestId, 'Bundle marked as success', [
            'uid' => $uid,
            'settle_amount' => $settleAmount,
            'offer_id' => (string)($done['offer_id'] ?? ''),
            'price_amount' => (float)($done['price_amount'] ?? $done['amount'] ?? 0),
            'you_pay' => (float)($done['you_pay'] ?? $settleAmount),
            'user_commission' => (float)($done['user_commission'] ?? 0),
            'subadmin_profit' => (float)($done['subadmin_profit'] ?? 0),
            'commission_status' => (string)($done['commission_status'] ?? ''),
        ]);
    }
    $notificationWritten = bundle_record_user_notification($done, $requestId, 'SUCCESS');
    if (function_exists('wallet_financial_operation_mark_completed')) {
        wallet_financial_operation_mark_completed($financialClaim, [
            'final_status' => 'SUCCESS',
            'completed_bucket' => 'DONE',
            'request_finalized' => true,
            'history_written' => $historyWritten,
            'notification_written' => $notificationWritten,
        ]);
    }

    return [
        'ok' => true,
        'code' => 'BUNDLE_SUCCESS',
        'message' => 'Bundle marked as success',
        'data' => [
            'request_id' => $requestId,
            'status' => 'SUCCESS',
            'amount' => (float)($done['amount'] ?? 0),
            'price_amount' => (float)($done['price_amount'] ?? $done['amount'] ?? 0),
            'offer_price' => (float)($done['offer_price'] ?? $done['amount'] ?? 0),
            'you_pay' => (float)($done['you_pay'] ?? $settleAmount),
            'payable_amount' => (float)($done['payable_amount'] ?? $settleAmount),
            'settle_amount' => $settleAmount,
            'user_commission' => (float)($done['user_commission'] ?? 0),
            'subadmin_profit' => (float)($done['subadmin_profit'] ?? 0),
            'commission_status' => (string)($done['commission_status'] ?? ''),
        ],
    ];
}

function bundle_mark_failed(string $requestId, string $message): array
{
    $requestId = trim($requestId);

    $pending = fb_get('BUNDLE_REQUESTS/PENDING/' . $requestId);
    if (!is_array($pending)) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'Bundle request not found',
        ];
    }

    $uid = (string)($pending['uid'] ?? '');

    $refundAmount = bundle_round_money((float)(
        $pending['wallet_hold_amount']
        ?? $pending['payable_amount']
        ?? $pending['you_pay']
        ?? $pending['amount']
        ?? 0
    ));
    $walletCurrency = (string)($pending['wallet_debit_currency'] ?? $pending['wallet_currency'] ?? 'BDT');
    $operation = function_exists('wallet_financial_operation_begin')
        ? wallet_financial_operation_begin($requestId, 'BUNDLE_REFUND', 'REQUEST_FINAL', $uid, $refundAmount, $walletCurrency, [
            'request_type' => 'BUNDLE',
            'status' => (string)($pending['status'] ?? 'WAITING_ADMIN'),
        ])
        : ['ok' => true, 'claim' => []];

    if (empty($operation['ok'])) {
        return [
            'ok' => false,
            'code' => (string)($operation['code'] ?? 'FINANCIAL_OPERATION_FAILED'),
            'message' => (string)($operation['message'] ?? 'Bundle financial operation is already being processed'),
        ];
    }
    if (!empty($operation['duplicate'])) {
        return [
            'ok' => true,
            'code' => 'BUNDLE_FAILED',
            'message' => 'Bundle request already completed',
            'data' => [
                'request_id' => $requestId,
                'status' => 'FAILED',
                'refund_amount' => $refundAmount,
            ],
        ];
    }
    $financialClaim = (array)($operation['claim'] ?? []);

    if (!function_exists('wallet_refund_hold')) {
        if (function_exists('wallet_financial_operation_mark_failed')) {
            wallet_financial_operation_mark_failed($financialClaim, 'MISSING_WALLET_HELPER', 'wallet_refund_hold helper missing');
        }
        return [
            'ok' => false,
            'code' => 'MISSING_WALLET_HELPER',
            'message' => 'wallet_refund_hold helper missing',
        ];
    }

    $refund = wallet_refund_hold($uid, $refundAmount, $requestId, 'BUNDLE_REFUND', [
        'financial_operation' => $financialClaim,
    ]);
    if (!($refund['ok'] ?? false)) {
        if (function_exists('wallet_financial_operation_mark_failed')) {
            wallet_financial_operation_mark_failed($financialClaim, (string)($refund['code'] ?? 'BUNDLE_REFUND_FAILED'), (string)($refund['message'] ?? 'Bundle wallet refund failed'));
        }
        return $refund;
    }

    $done = $pending;
    $done['status'] = 'FAILED';
    $done['final_message'] = $message;
    $done['completed_at'] = bundle_now();
    $done['updated_at'] = bundle_now();
    $done['commission_status'] = 'CANCELLED_FAILED';
    $done['commission_credited_at'] = 0;
    $done['user_commission_credited'] = false;
    $done['subadmin_profit_credited'] = false;

    if (!fb_put('BUNDLE_REQUESTS/DONE/' . $requestId, $done)) {
        if (function_exists('wallet_financial_operation_mark_failed')) {
            wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_FINALIZATION_FAILED', 'Failed to move bundle request to done bucket', [
                'wallet_applied' => true,
                'ledger_written' => true,
                'request_finalized' => false,
            ]);
        }
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to move bundle request to done bucket',
        ];
    }

    if (!fb_delete('BUNDLE_REQUESTS/PENDING/' . $requestId)) {
        if (function_exists('wallet_financial_operation_mark_failed')) {
            wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_FINALIZATION_FAILED', 'Failed to remove pending bundle request bucket', [
                'wallet_applied' => true,
                'ledger_written' => true,
                'request_finalized' => false,
            ]);
        }
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to move bundle request to done bucket',
        ];
    }

    if (function_exists('wallet_financial_operation_mark_applied')) {
        wallet_financial_operation_mark_applied($financialClaim, [
            'wallet_applied' => true,
            'ledger_written' => true,
            'request_finalized' => true,
            'final_status' => 'FAILED',
            'completed_bucket' => 'DONE',
        ]);
    }

    $historyWritten = bundle_write_history($done);
    bundle_update_subadmin_request_log($done, 'FAILED', $message);
    bundle_telegram_edit_request_message($done, 'FAILED', $message);

    if (function_exists('update_request_status')) {
        update_request_status($requestId, 'FAILED', $message);
    }

    if (function_exists('system_log')) {
        system_log('BUNDLE_FAILED', $requestId, 'Bundle marked as failed and refunded', [
            'uid' => $uid,
            'refund_amount' => $refundAmount,
            'offer_id' => (string)($done['offer_id'] ?? ''),
            'price_amount' => (float)($done['price_amount'] ?? $done['amount'] ?? 0),
            'you_pay' => (float)($done['you_pay'] ?? $refundAmount),
        ]);
    }
    $notificationWritten = bundle_record_user_notification($done, $requestId, 'FAILED');
    if (function_exists('wallet_financial_operation_mark_completed')) {
        wallet_financial_operation_mark_completed($financialClaim, [
            'final_status' => 'FAILED',
            'completed_bucket' => 'DONE',
            'request_finalized' => true,
            'history_written' => $historyWritten,
            'notification_written' => $notificationWritten,
        ]);
    }

    return [
        'ok' => true,
        'code' => 'BUNDLE_FAILED',
        'message' => 'Bundle marked as failed',
        'data' => [
            'request_id' => $requestId,
            'status' => 'FAILED',
            'amount' => (float)($done['amount'] ?? 0),
            'price_amount' => (float)($done['price_amount'] ?? $done['amount'] ?? 0),
            'offer_price' => (float)($done['offer_price'] ?? $done['amount'] ?? 0),
            'you_pay' => (float)($done['you_pay'] ?? $refundAmount),
            'payable_amount' => (float)($done['payable_amount'] ?? $refundAmount),
            'refund_amount' => $refundAmount,
        ],
    ];
}
