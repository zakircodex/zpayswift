<?php
declare(strict_types=1);

function bulksmsbd_normalize_number(string $phone): string
{
    $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '880') === 0 && strlen($digits) >= 13) {
        return $digits;
    }

    if (strpos($digits, '0') === 0 && strlen($digits) >= 11) {
        return '88' . $digits;
    }

    if (strpos($digits, '1') === 0 && strlen($digits) >= 10) {
        return '880' . $digits;
    }

    return $digits;
}

function bulksmsbd_code_message(string $code): string
{
    $map = [
        '202' => 'SMS submitted successfully',
        '1001' => 'Invalid number',
        '1002' => 'Sender ID not correct or disabled',
        '1003' => 'Required fields missing',
        '1005' => 'Internal error',
        '1006' => 'Balance validity not available',
        '1007' => 'Balance insufficient',
        '1011' => 'User ID not found',
        '1012' => 'Masking SMS must be in Bengali',
        '1013' => 'Sender ID gateway not found by API key',
        '1014' => 'Sender type name not found using this sender by API key',
        '1015' => 'Valid sender gateway not found by API key',
        '1016' => 'Sender type active price info not found',
        '1017' => 'Sender type price info not found',
        '1018' => 'Owner account disabled',
        '1019' => 'Sender type account price disabled',
        '1020' => 'Parent account not found',
        '1021' => 'Parent active sender price not found',
        '1031' => 'Account not verified',
        '1032' => 'IP not whitelisted',
    ];

    return $map[$code] ?? ('SMS provider returned code ' . $code);
}

function bulksmsbd_http_get(string $url): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
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
            'status' => $status,
            'raw' => '',
            'error' => $err ?: 'Unknown cURL error',
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'raw' => trim((string)$raw),
        'error' => '',
    ];
}

function bulksmsbd_send_sms(string $phone, string $message): array
{
    $number = bulksmsbd_normalize_number($phone);
    if ($number === '') {
        return [
            'ok' => false,
            'code' => 'LOCAL_INVALID_NUMBER',
            'message' => 'Invalid SMS number',
            'raw' => '',
            'normalized_number' => '',
        ];
    }

    if (!defined('BULKSMSBD_API_KEY') || !defined('BULKSMSBD_SENDER_ID') || !defined('BULKSMSBD_SMS_API_URL')) {
        return [
            'ok' => false,
            'code' => 'LOCAL_CONFIG_MISSING',
            'message' => 'BulkSMSBD config missing',
            'raw' => '',
            'normalized_number' => $number,
        ];
    }

    $url = BULKSMSBD_SMS_API_URL . '?' . http_build_query([
        'api_key' => BULKSMSBD_API_KEY,
        'type' => 'text',
        'number' => $number,
        'senderid' => BULKSMSBD_SENDER_ID,
        'message' => $message,
    ], '', '&', PHP_QUERY_RFC3986);

    $res = bulksmsbd_http_get($url);

    if (!($res['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => 'LOCAL_CURL_ERROR',
            'message' => (string)($res['error'] ?? 'SMS request failed'),
            'raw' => (string)($res['raw'] ?? ''),
            'normalized_number' => $number,
        ];
    }

    $raw = trim((string)($res['raw'] ?? ''));
    preg_match('/\d+/', $raw, $m);
    $code = isset($m[0]) ? (string)$m[0] : $raw;

    if ($code === '202') {
        return [
            'ok' => true,
            'code' => '202',
            'message' => bulksmsbd_code_message('202'),
            'raw' => $raw,
            'normalized_number' => $number,
        ];
    }

    return [
        'ok' => false,
        'code' => $code !== '' ? $code : 'SMS_UNKNOWN_ERROR',
        'message' => bulksmsbd_code_message($code !== '' ? $code : 'SMS_UNKNOWN_ERROR'),
        'raw' => $raw,
        'normalized_number' => $number,
    ];
}