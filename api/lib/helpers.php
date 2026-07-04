<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function now_ts(): int
{
    return time();
}

function month_key(?int $ts = null): string
{
    return date('Y-m', $ts ?? now_ts());
}

function date_key(?int $ts = null): string
{
    return date('Y-m-d', $ts ?? now_ts());
}

function digits_only(?string $value): string
{
    return preg_replace('/\D+/', '', (string) $value) ?? '';
}

function normalize_login_phone(?string $phone): string
{
    return digits_only($phone);
}

function normalize_bd_topup_number(?string $number): string
{
    $d = digits_only($number);

    if (str_starts_with($d, '880')) {
        $d = '0' . substr($d, 3);
    }

    if (strlen($d) === 10 && str_starts_with($d, '1')) {
        $d = '0' . $d;
    }

    return $d;
}

function is_valid_bd_topup_number(?string $number): bool
{
    $n = normalize_bd_topup_number($number);
    return preg_match('/^01[3-9]\d{8}$/', $n) === 1;
}

function normalize_operator(?string $operator): string
{
    $op = strtoupper(trim((string) $operator));
    $op = preg_replace('/[\s\-]+/', '_', $op) ?? $op;

    $map = [
        'GRAMEENPHONE' => 'GP',
        'GP' => 'GP',
        'ROBI' => 'ROBI',
        'BANGLALINK' => 'BL',
        'BL' => 'BL',
        'AIRTEL' => 'AIRTEL',
        'TELETALK' => 'TT',
        'TT' => 'TT',
        'SKITTO' => 'SKITTO',
        'CELCOM_XPAX' => 'CELCOM_XPAX',
        'CELCOMXPAX' => 'CELCOM_XPAX',
        'XPAX' => 'CELCOM_XPAX',
        'DIGI' => 'DIGI',
        'MAXIS_HOTLINK' => 'HOTLINK',
        'MAXIS' => 'HOTLINK',
        'HOTLINK' => 'HOTLINK',
        'U_MOBILE' => 'UMOBILE',
        'UMOBILE' => 'UMOBILE',
        'XOX' => 'XOX',
        'TUNE_TALK' => 'TUNETALK',
        'TUNETALK' => 'TUNETALK',
        'YES' => 'YES',
        'YES_PREPAID' => 'YES',
    ];

    return $map[$op] ?? $op;
}

function is_valid_operator(?string $operator): bool
{
    return in_array(normalize_operator($operator), [
        'GP',
        'ROBI',
        'AIRTEL',
        'BL',
        'TT',
        'SKITTO',
        'CELCOM_XPAX',
        'DIGI',
        'HOTLINK',
        'UMOBILE',
        'XOX',
        'TUNETALK',
        'YES',
    ], true);
}

function is_valid_email_or_empty(?string $email): bool
{
    $email = trim((string) $email);
    if ($email === '') {
        return true;
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function is_valid_user_pin(?string $pin): bool
{
    return preg_match('/^\d{' . USER_PIN_LENGTH . '}$/', (string) $pin) === 1;
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function make_uid(): string
{
    return 'U' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function make_session_id(): string
{
    return 'SESS_' . strtoupper(bin2hex(random_bytes(8)));
}

function make_log_id(): string
{
    return 'LOG_' . strtoupper(bin2hex(random_bytes(6)));
}

function make_ledger_id(): string
{
    return 'LEDGER_' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function make_topup_request_id(): string
{
    return 'TP' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function make_bundle_request_id(): string
{
    return 'BDL' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function make_admin_action_id(): string
{
    return 'ACT_' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function client_ip(): string
{
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $value = explode(',', (string) $_SERVER[$key])[0];
            return trim($value);
        }
    }

    return '0.0.0.0';
}

function system_log(string $type, string $refId, string $message, array $context = []): void
{
    $logId = make_log_id();

    $payload = [
        'type' => $type,
        'ref_id' => $refId,
        'message' => $message,
        'context' => $context,
        'created_at' => now_ts(),
    ];

    fb_put('SYSTEM_LOGS/' . date_key() . '/' . $logId, $payload);
}

function admin_action_log(string $actionType, string $targetId, string $note, array $context = []): void
{
    $actionId = make_admin_action_id();

    $payload = [
        'action_id' => $actionId,
        'admin_id' => 'ADMIN',
        'action_type' => $actionType,
        'target_id' => $targetId,
        'note' => $note,
        'context' => $context,
        'created_at' => now_ts(),
    ];

    fb_put('ADMIN_ACTION_LOGS/' . month_key() . '/' . $actionId, $payload);
}
