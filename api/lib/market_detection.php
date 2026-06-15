<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function market_iso_country_code($value): string
{
    $country = strtoupper(trim((string)$value));

    if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
        return '';
    }

    return $country;
}

function market_forwarding_key(): string
{
    return function_exists('auth_country_forwarding_key')
        ? auth_country_forwarding_key()
        : '';
}

function market_forwarding_signature(string $scope, string $value): string
{
    $scope = strtolower(trim($scope));
    $value = trim($value);
    $key = market_forwarding_key();

    if ($scope === '' || $value === '' || $key === '') {
        return '';
    }

    return hash_hmac('sha256', 'market-' . $scope . '|' . $value, $key);
}

function market_trusted_forwarded_ip(): string
{
    $ip = trim((string)($_SERVER['HTTP_X_ZPAY_CLIENT_IP'] ?? ''));
    $signature = trim((string)($_SERVER['HTTP_X_ZPAY_CLIENT_IP_SIGNATURE'] ?? ''));

    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false || $signature === '') {
        return '';
    }

    $expected = market_forwarding_signature('ip', $ip);

    return $expected !== '' && hash_equals($expected, $signature) ? $ip : '';
}

function market_trusted_forwarded_ip_country(): string
{
    $country = market_iso_country_code($_SERVER['HTTP_X_ZPAY_IP_COUNTRY'] ?? '');
    $signature = trim((string)($_SERVER['HTTP_X_ZPAY_IP_COUNTRY_SIGNATURE'] ?? ''));

    if ($country === '' || $signature === '') {
        return '';
    }

    $expected = market_forwarding_signature('ip-country', $country);

    return $expected !== '' && hash_equals($expected, $signature) ? $country : '';
}

function market_request_ip(): string
{
    $forwarded = market_trusted_forwarded_ip();
    if ($forwarded !== '') {
        return $forwarded;
    }

    if (function_exists('security_client_ip')) {
        return security_client_ip();
    }

    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function market_request_ip_country(array $body = []): string
{
    $forwarded = market_trusted_forwarded_ip_country();
    if ($forwarded !== '') {
        return $forwarded;
    }

    foreach ([
        $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '',
        $_SERVER['GEOIP_COUNTRY_CODE'] ?? '',
    ] as $candidate) {
        $country = market_iso_country_code($candidate);
        if ($country !== '') {
            return $country;
        }
    }

    return '';
}

function market_float_value($value): ?float
{
    if (!is_numeric($value)) {
        return null;
    }

    $number = (float)$value;

    return is_finite($number) ? $number : null;
}

function market_point_in_polygon(float $lat, float $lng, array $polygon): bool
{
    $inside = false;
    $count = count($polygon);

    if ($count < 3) {
        return false;
    }

    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        [$latI, $lngI] = $polygon[$i];
        [$latJ, $lngJ] = $polygon[$j];

        $crosses = (($latI > $lat) !== ($latJ > $lat))
            && ($lng < (($lngJ - $lngI) * ($lat - $latI) / (($latJ - $latI) ?: 0.0000001)) + $lngI);

        if ($crosses) {
            $inside = !$inside;
        }
    }

    return $inside;
}

function market_country_from_coordinates(float $lat, float $lng): string
{
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return '';
    }

    $bangladesh = [
        [20.45, 92.65],
        [23.65, 92.65],
        [25.25, 91.90],
        [26.65, 89.85],
        [26.35, 88.00],
        [23.85, 88.05],
        [21.60, 89.00],
        [20.45, 91.00],
    ];

    $peninsularMalaysia = [
        [1.15, 103.45],
        [1.75, 104.35],
        [3.00, 104.85],
        [6.55, 103.80],
        [7.45, 101.10],
        [6.55, 99.55],
        [4.05, 100.00],
        [1.75, 102.20],
    ];

    $sarawak = [
        [0.85, 109.45],
        [1.60, 111.20],
        [2.60, 111.95],
        [3.20, 113.20],
        [4.95, 115.60],
        [4.10, 114.00],
        [2.20, 110.20],
    ];

    $sabah = [
        [4.00, 115.30],
        [4.90, 116.20],
        [5.35, 119.35],
        [7.45, 117.10],
        [6.15, 115.40],
    ];

    if (market_point_in_polygon($lat, $lng, $bangladesh)) {
        return 'BD';
    }

    // Keep Singapore coordinates outside the Malaysia classifier.
    if ($lat >= 1.15 && $lat <= 1.50 && $lng >= 103.55 && $lng <= 104.10) {
        return 'SG';
    }

    if (
        market_point_in_polygon($lat, $lng, $peninsularMalaysia)
        || market_point_in_polygon($lat, $lng, $sarawak)
        || market_point_in_polygon($lat, $lng, $sabah)
    ) {
        return 'MY';
    }

    return 'OTHER';
}

function market_registration_decision(array $body, string $phoneCountry): array
{
    $phoneCountry = auth_normalize_country_code($phoneCountry);
    $lat = market_float_value($body['gps_lat'] ?? null);
    $lng = market_float_value($body['gps_lng'] ?? null);
    $accuracy = market_float_value($body['gps_accuracy'] ?? null);

    if ($lat === null || $lng === null || $accuracy === null || $accuracy < 0) {
        return [
            'ok' => false,
            'code' => 'LOCATION_REQUIRED',
            'message' => 'Location permission is required to create an account.',
        ];
    }

    $gpsCountry = market_country_from_coordinates($lat, $lng);
    $ipCountry = market_request_ip_country($body);
    $createdIp = market_request_ip();
    $pricingCountry = in_array($gpsCountry, ['BD', 'MY'], true) ? $gpsCountry : 'MY';
    $accountStatus = 'ACTIVE';
    $vpnSuspected = false;
    $reviewReasons = [];
    $detectionSource = 'BROWSER_GPS';

    if (!in_array($gpsCountry, ['BD', 'MY'], true)) {
        $accountStatus = 'REVIEW';
        $reviewReasons[] = 'GPS_OUTSIDE_SUPPORTED_MARKET';
        $detectionSource = 'BROWSER_GPS_UNSUPPORTED';
    }

    if ($ipCountry === '') {
        $accountStatus = 'REVIEW';
        $reviewReasons[] = 'IP_COUNTRY_UNKNOWN';
        $detectionSource = 'BROWSER_GPS_IP_UNKNOWN';
    }

    if (
        in_array($gpsCountry, ['BD', 'MY'], true)
        && $ipCountry !== ''
        && $gpsCountry !== $ipCountry
    ) {
        $accountStatus = 'REVIEW';
        $vpnSuspected = true;
        $reviewReasons[] = 'GPS_IP_COUNTRY_MISMATCH';
        $detectionSource = 'BROWSER_GPS_IP_MISMATCH';
    } elseif (
        in_array($gpsCountry, ['BD', 'MY'], true)
        && $ipCountry === $gpsCountry
    ) {
        $detectionSource = 'BROWSER_GPS_IP_MATCH';
    }

    $maxAccuracy = defined('REGISTRATION_GPS_MAX_ACCURACY_METERS')
        ? max(1000, (float)REGISTRATION_GPS_MAX_ACCURACY_METERS)
        : 50000.0;

    if ($accuracy > $maxAccuracy) {
        $accountStatus = 'REVIEW';
        $reviewReasons[] = 'GPS_ACCURACY_TOO_LOW';
    }

    $risk = [
        'verdict' => 'UNKNOWN',
        'blocked' => false,
        'risk_type' => 'UNKNOWN',
        'risk_score' => 0,
        'source' => 'UNAVAILABLE',
        'reason' => '',
    ];

    if (
        $createdIp !== ''
        && filter_var($createdIp, FILTER_VALIDATE_IP) !== false
        && function_exists('security_detect_ip_risk')
    ) {
        $risk = security_detect_ip_risk($createdIp);
    }

    $riskType = strtoupper(trim((string)($risk['risk_type'] ?? 'UNKNOWN')));
    $suspiciousRiskTypes = [
        'VPN',
        'PROXY',
        'TOR',
        'DATACENTER',
        'ANONYMOUS',
        'HIGH_RISK',
        'BLOCKLIST',
    ];

    if (!empty($risk['blocked']) || in_array($riskType, $suspiciousRiskTypes, true)) {
        $accountStatus = 'REVIEW';
        $vpnSuspected = true;
        $reviewReasons[] = 'IP_RISK_' . ($riskType !== '' ? $riskType : 'UNKNOWN');
    }

    $countryMismatch = $phoneCountry !== '' && $phoneCountry !== $pricingCountry;
    $reason = implode(', ', array_values(array_unique($reviewReasons)));

    return [
        'ok' => true,
        'code' => $accountStatus === 'ACTIVE' ? 'MARKET_VERIFIED' : 'MARKET_REVIEW_REQUIRED',
        'message' => $accountStatus === 'ACTIVE'
            ? 'Registration market verified'
            : 'Account will require admin review',
        'phone_country' => $phoneCountry,
        'pricing_country' => $pricingCountry,
        'market_country' => $pricingCountry,
        'service_country' => $pricingCountry,
        'currency' => auth_country_currency($pricingCountry),
        'local_mode' => $pricingCountry === 'BD',
        'gps_lat' => round($lat, 7),
        'gps_lng' => round($lng, 7),
        'gps_accuracy' => round($accuracy, 2),
        'gps_country' => $gpsCountry,
        'ip_country' => $ipCountry,
        'created_ip' => $createdIp,
        'country_mismatch' => $countryMismatch,
        'vpn_suspected' => $vpnSuspected,
        'market_detection_source' => $detectionSource,
        'account_review_reason' => $reason,
        'account_status' => $accountStatus,
        'review_required' => $accountStatus !== 'ACTIVE',
        'requires_admin_review' => $accountStatus !== 'ACTIVE',
        'ip_risk_type' => $riskType,
        'ip_risk_score' => (int)($risk['risk_score'] ?? 0),
        'ip_risk_source' => (string)($risk['source'] ?? ''),
        'ip_risk_reason' => (string)($risk['reason'] ?? ''),
    ];
}
