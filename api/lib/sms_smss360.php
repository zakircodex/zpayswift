<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/otp_templates.php';

function smss360_xml_value(string $xml, array $names): string
{
    if (function_exists('simplexml_load_string')) {
        $previous = libxml_use_internal_errors(true);
        $node = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($node !== false) {
            $flat = [];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveArrayIterator(json_decode(json_encode($node), true) ?: [])
            );

            foreach ($iterator as $key => $value) {
                $flat[strtolower((string)$key)] = trim((string)$value);
            }

            foreach ($names as $name) {
                $key = strtolower($name);
                if (isset($flat[$key]) && $flat[$key] !== '') {
                    return $flat[$key];
                }
            }
        }
    }

    foreach ($names as $name) {
        if (preg_match(
            '/<' . preg_quote($name, '/') . '\b[^>]*>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*<\/' . preg_quote($name, '/') . '>/is',
            $xml,
            $match
        )) {
            return trim(strip_tags((string)$match[1]));
        }
    }

    return '';
}

function smss360_send_sms(string $phone, string $message, string $referenceId): array
{
    $phone = function_exists('normalize_phone_by_country')
        ? normalize_phone_by_country($phone, 'MY')
        : (preg_replace('/\D+/', '', trim($phone)) ?? '');

    $message = trim($message);

    if ($phone === '' || $message === '') {
        return [
            'ok' => false,
            'code' => 'LOCAL_INVALID_INPUT',
            'message' => 'Invalid Malaysia SMS input',
            'reference_id' => $referenceId,
            'raw' => '',
        ];
    }

    if (!str_starts_with($message, 'RM0 ') || !otp_my_message_is_approved($message)) {
        return [
            'ok' => false,
            'code' => 'LOCAL_TEMPLATE_REJECTED',
            'message' => 'Malaysia SMS must use an approved OTP template',
            'reference_id' => $referenceId,
            'raw' => '',
        ];
    }

    if (
        !defined('SMSS360_API_URL')
        || !defined('SMSS360_EMAIL')
        || !defined('SMSS360_API_KEY')
        || trim((string)SMSS360_EMAIL) === ''
        || trim((string)SMSS360_API_KEY) === ''
    ) {
        return [
            'ok' => false,
            'code' => 'LOCAL_CONFIG_MISSING',
            'message' => 'SMS360 config missing',
            'reference_id' => $referenceId,
            'raw' => '',
        ];
    }

    $url = rtrim((string)SMSS360_API_URL, '?') . '?' . http_build_query([
        'email' => (string)SMSS360_EMAIL,
        'key' => (string)SMSS360_API_KEY,
        'recipient' => $phone,
        'message' => $message,
        'referenceID' => $referenceId,
    ], '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPGET => true,
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return [
            'ok' => false,
            'code' => 'LOCAL_CURL_ERROR',
            'message' => $error !== '' ? $error : 'SMS360 request failed',
            'reference_id' => $referenceId,
            'raw' => '',
            'http_status' => $httpStatus,
        ];
    }

    $raw = trim((string)$raw);
    $statusCode = smss360_xml_value($raw, ['statuscode', 'status_code', 'code']);
    $statusMessage = smss360_xml_value($raw, ['statusmessage', 'status_message', 'message', 'status']);
    $providerReference = smss360_xml_value($raw, ['referenceID', 'reference_id', 'reference']);

    return [
        'ok' => $statusCode === '1606',
        'code' => $statusCode !== '' ? $statusCode : 'SMS360_UNKNOWN_RESPONSE',
        'message' => $statusMessage !== '' ? $statusMessage : ($statusCode === '1606' ? 'SMS submitted successfully' : 'SMS360 request failed'),
        'reference_id' => $providerReference !== '' ? $providerReference : $referenceId,
        'raw' => substr($raw, 0, 1000),
        'http_status' => $httpStatus,
    ];
}
