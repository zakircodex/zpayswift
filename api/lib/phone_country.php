<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function auth_normalize_country_code(?string $country): string
{
    $country = strtoupper(trim((string)$country));
    $map = [
        'BD' => 'BD',
        'BGD' => 'BD',
        'BANGLADESH' => 'BD',
        '+880' => 'BD',
        '880' => 'BD',
        'MY' => 'MY',
        'MYS' => 'MY',
        'MALAYSIA' => 'MY',
        '+60' => 'MY',
        '60' => 'MY',
    ];

    return $map[$country] ?? '';
}

function auth_country_currency(string $country): string
{
    return auth_normalize_country_code($country) === 'MY' ? 'MYR' : 'BDT';
}

function auth_registration_pricing_country(string $phoneCountry, string $ipCountry): string
{
    $phoneCountry = auth_normalize_country_code($phoneCountry);
    $ipCountry = auth_normalize_country_code($ipCountry);

    return $phoneCountry === 'MY' || $ipCountry === 'MY' ? 'MY' : 'BD';
}

function normalize_phone_by_country(string $phone, string $country): string
{
    $country = auth_normalize_country_code($country);
    $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';

    if ($country === 'BD') {
        if (preg_match('/^8801[3-9]\d{8}$/', $digits) === 1) {
            return $digits;
        }

        if (preg_match('/^01[3-9]\d{8}$/', $digits) === 1) {
            return '88' . $digits;
        }

        if (preg_match('/^1[3-9]\d{8}$/', $digits) === 1) {
            return '880' . $digits;
        }

        return '';
    }

    if ($country === 'MY') {
        if (preg_match('/^60(?:11\d{8}|1[02-9]\d{7})$/', $digits) === 1) {
            return $digits;
        }

        if (preg_match('/^0(?:11\d{8}|1[02-9]\d{7})$/', $digits) === 1) {
            return '6' . $digits;
        }

        if (preg_match('/^(?:11\d{8}|1[02-9]\d{7})$/', $digits) === 1) {
            return '60' . $digits;
        }
    }

    return '';
}

function detect_phone_country(string $phone): string
{
    $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';

    if (preg_match('/^8801[3-9]\d{8}$/', $digits) === 1) {
        return 'BD';
    }

    if (preg_match('/^60(?:11\d{8}|1[02-9]\d{7})$/', $digits) === 1) {
        return 'MY';
    }

    if (preg_match('/^0(?:11\d{8}|1[02-9]\d{7})$/', $digits) === 1) {
        return 'MY';
    }

    if (preg_match('/^01[3-9]\d{8}$/', $digits) === 1) {
        return 'BD';
    }

    return '';
}

function auth_phone_country_from_user(array $user): string
{
    $country = auth_normalize_country_code((string)($user['phone_country'] ?? ''));
    if ($country !== '') {
        return $country;
    }

    $detected = detect_phone_country((string)($user['phone'] ?? ''));
    if ($detected !== '') {
        return $detected;
    }

    // Old rows had one country field for both phone and pricing.
    foreach ([
        $user['country_code'] ?? '',
        $user['country'] ?? '',
        $user['service_country'] ?? '',
        $user['pricing_country'] ?? '',
    ] as $legacyCountry) {
        $country = auth_normalize_country_code((string)$legacyCountry);
        if ($country !== '') {
            return $country;
        }
    }

    return 'BD';
}

function auth_pricing_country_from_user(array $user, array $wallet = []): string
{
    foreach ([
        $user['pricing_country'] ?? '',
        $user['service_country'] ?? '',
        $user['country_code'] ?? '',
        $user['country'] ?? '',
        $user['user_country'] ?? '',
    ] as $candidate) {
        $country = auth_normalize_country_code((string)$candidate);
        if ($country !== '') {
            return $country;
        }
    }

    $currency = strtoupper(trim((string)(
        $user['wallet_currency']
        ?? $user['currency']
        ?? $wallet['wallet_currency']
        ?? $wallet['currency']
        ?? ''
    )));

    if (in_array($currency, ['MYR', 'RM', 'RINGGIT'], true)) {
        return 'MY';
    }

    if (in_array($currency, ['BDT', 'TK', 'TAKA'], true)) {
        return 'BD';
    }

    return 'BD';
}

function auth_phone_index_candidates(string $phoneE164, string $country): array
{
    $country = auth_normalize_country_code($country);
    $phoneE164 = normalize_phone_by_country($phoneE164, $country);

    if ($phoneE164 === '') {
        return [];
    }

    $candidates = [$phoneE164];

    if ($country === 'BD' && str_starts_with($phoneE164, '880')) {
        $candidates[] = '0' . substr($phoneE164, 3);
    } elseif ($country === 'MY' && str_starts_with($phoneE164, '60')) {
        $candidates[] = '0' . substr($phoneE164, 2);
    }

    return array_values(array_unique(array_filter($candidates)));
}

function auth_find_uid_by_phone_country(string $phoneE164, string $country): string
{
    if (!function_exists('fb_get')) {
        return '';
    }

    foreach (auth_phone_index_candidates($phoneE164, $country) as $candidate) {
        $row = fb_get('USER_INDEX/PHONE/' . $candidate);

        if (is_string($row) && trim($row) !== '') {
            return trim($row);
        }

        if (is_array($row)) {
            $uid = trim((string)($row['uid'] ?? $row['value'] ?? ''));
            if ($uid !== '') {
                return $uid;
            }
        }
    }

    return '';
}

function auth_request_ip(array $body = []): string
{
    $forwarded = trim((string)($body['client_ip'] ?? $body['created_ip'] ?? ''));
    if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
        return $forwarded;
    }

    if (function_exists('security_client_ip')) {
        return security_client_ip();
    }

    if (function_exists('client_ip')) {
        return (string)client_ip();
    }

    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function auth_request_ip_country(array $body = []): string
{
    foreach ([
        $body['ip_country'] ?? '',
        $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '',
        $_SERVER['HTTP_X_COUNTRY_CODE'] ?? '',
        $_SERVER['GEOIP_COUNTRY_CODE'] ?? '',
    ] as $candidate) {
        $country = auth_normalize_country_code((string)$candidate);
        if ($country !== '') {
            return $country;
        }
    }

    return '';
}

function auth_request_user_agent(array $body = []): string
{
    $value = trim((string)($body['user_agent'] ?? ''));
    if ($value === '') {
        $value = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    return substr($value, 0, 300);
}

function auth_request_browser_timezone(array $body = []): string
{
    return substr(trim((string)($body['browser_timezone'] ?? '')), 0, 80);
}

function auth_request_metadata(array $body = []): array
{
    return [
        'created_ip' => auth_request_ip($body),
        'ip_country' => auth_request_ip_country($body),
        'user_agent' => auth_request_user_agent($body),
        'browser_timezone' => auth_request_browser_timezone($body),
    ];
}

function auth_phone_validation_message(string $country): string
{
    return auth_normalize_country_code($country) === 'MY'
        ? 'Invalid Malaysia number'
        : 'Invalid Bangladesh number';
}
