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

function market_ip_country_code($value): string
{
    $country = market_iso_country_code($value);

    if ($country === '' || in_array($country, ['A1', 'A2', 'AP', 'EU', 'T1', 'XX', 'ZZ'], true)) {
        return '';
    }

    return $country;
}

function market_ip_country_source($value): string
{
    $source = strtoupper(trim((string)$value));

    return in_array($source, ['CLOUDFLARE', 'SERVER_GEOIP', 'UNKNOWN'], true) ? $source : '';
}

function market_bool_constant(string $name, bool $default): bool
{
    if (!defined($name)) {
        return $default;
    }

    $value = constant($name);

    if (is_bool($value)) {
        return $value;
    }

    $value = strtoupper(trim((string)$value));

    return in_array($value, ['1', 'TRUE', 'YES', 'Y', 'ON'], true);
}

function market_cloudflare_country_enabled(): bool
{
    return market_bool_constant('SECURITY_CLOUDFLARE_IP_COUNTRY_ENABLED', true);
}

function market_require_cloudflare_country(): bool
{
    return market_bool_constant('SECURITY_REQUIRE_CLOUDFLARE_FOR_COUNTRY', false);
}

function market_cloudflare_trusted_proxy_cidrs(): array
{
    $hasConfiguredRanges = defined('SECURITY_CLOUDFLARE_TRUSTED_PROXY_CIDRS')
        || defined('SECURITY_TRUSTED_PROXY_CIDRS');
    $configured = defined('SECURITY_CLOUDFLARE_TRUSTED_PROXY_CIDRS')
        ? constant('SECURITY_CLOUDFLARE_TRUSTED_PROXY_CIDRS')
        : (defined('SECURITY_TRUSTED_PROXY_CIDRS') ? constant('SECURITY_TRUSTED_PROXY_CIDRS') : []);

    if (is_string($configured)) {
        $configured = preg_split('/[\s,]+/', trim($configured)) ?: [];
    }

    if ($hasConfiguredRanges && is_array($configured)) {
        $configured = array_values(array_filter(array_map('trim', $configured), static function (string $cidr): bool {
            return $cidr !== '';
        }));

        if ($configured !== []) {
            return $configured;
        }
    }

    // Official lists: https://www.cloudflare.com/ips-v4/ and /ips-v6/ (checked 2026-08-13).
    return [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];
}

function market_server_cloudflare_marker_trusted(): bool
{
    foreach ($_SERVER as $key => $value) {
        if (
            preg_match('/^(?:REDIRECT_)*ZPAY_TRUSTED_CLOUDFLARE$/', (string)$key) === 1
            && market_bool_constant_value($value)
        ) {
            return true;
        }
    }

    return false;
}

function market_bool_constant_value($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtoupper(trim((string)$value)), ['1', 'TRUE', 'YES', 'Y', 'ON'], true);
}

function market_ip_in_cidr(string $ip, string $cidr): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $parts = explode('/', trim($cidr), 2);
    $network = trim((string)($parts[0] ?? ''));
    $packedIp = @inet_pton($ip);
    $packedNetwork = @inet_pton($network);

    if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
        return false;
    }

    $maxBits = strlen($packedIp) * 8;
    $prefix = isset($parts[1]) && $parts[1] !== '' ? (int)$parts[1] : $maxBits;
    if ($prefix < 0 || $prefix > $maxBits) {
        return false;
    }

    $wholeBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if ($wholeBytes > 0 && substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

    return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
}

function market_cloudflare_request_trusted(): bool
{
    if (!market_cloudflare_country_enabled()) {
        return false;
    }

    if (market_bool_constant('SECURITY_CLOUDFLARE_ORIGIN_LOCKED', false)) {
        return true;
    }

    if (market_server_cloudflare_marker_trusted()) {
        return true;
    }

    $remoteIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    foreach (market_cloudflare_trusted_proxy_cidrs() as $cidr) {
        if (market_ip_in_cidr($remoteIp, $cidr)) {
            return true;
        }
    }

    return false;
}

function market_gps_ip_country_mismatch($gpsCountry, $ipCountry): bool
{
    $gpsCountry = market_ip_country_code($gpsCountry);
    $ipCountry = market_ip_country_code($ipCountry);

    return $gpsCountry !== '' && $ipCountry !== '' && $gpsCountry !== $ipCountry;
}

function market_mismatch_marks_vpn_suspected(): bool
{
    return market_bool_constant('REGISTRATION_GPS_IP_MISMATCH_VPN_SUSPECTED', true);
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

function market_trusted_forwarded_ip_country_details(): array
{
    $rawCountry = strtoupper(trim((string)($_SERVER['HTTP_X_ZPAY_IP_COUNTRY'] ?? '')));
    $country = $rawCountry === 'UNKNOWN' ? 'UNKNOWN' : market_ip_country_code($rawCountry);
    $signature = trim((string)($_SERVER['HTTP_X_ZPAY_IP_COUNTRY_SIGNATURE'] ?? ''));

    if ($country === '' || $signature === '') {
        return ['trusted' => false, 'country' => ''];
    }

    $expected = market_forwarding_signature('ip-country', $country);

    $trusted = $expected !== '' && hash_equals($expected, $signature);
    $source = market_ip_country_source($_SERVER['HTTP_X_ZPAY_IP_SOURCE'] ?? '');
    $sourceSignature = trim((string)($_SERVER['HTTP_X_ZPAY_IP_SOURCE_SIGNATURE'] ?? ''));
    $expectedSourceSignature = market_forwarding_signature('ip-source', $source);
    $sourceTrusted = $trusted
        && $source !== ''
        && $sourceSignature !== ''
        && $expectedSourceSignature !== ''
        && hash_equals($expectedSourceSignature, $sourceSignature);

    return [
        'trusted' => $trusted,
        'country' => $country,
        'source' => $sourceTrusted ? $source : 'SIGNED_FORWARD',
    ];
}

function market_trusted_forwarded_ip_country(): string
{
    $details = market_trusted_forwarded_ip_country_details();

    return !empty($details['trusted']) ? (string)$details['country'] : '';
}

function market_request_ip(): string
{
    $forwarded = market_trusted_forwarded_ip();
    if ($forwarded !== '') {
        return $forwarded;
    }

    $cfIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if (market_cloudflare_request_trusted() && $cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP) !== false) {
        return $cfIp;
    }

    $remoteIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    return filter_var($remoteIp, FILTER_VALIDATE_IP) !== false ? $remoteIp : '';
}

function market_request_ip_country(array $body = []): string
{
    $details = market_request_ip_country_details($body);

    return (string)$details['country'];
}

function market_request_ip_country_details(array $body = []): array
{
    $forwarded = market_trusted_forwarded_ip_country_details();
    if (!empty($forwarded['trusted'])) {
        return [
            'country' => (string)$forwarded['country'],
            'source' => (string)($forwarded['source'] ?? 'SIGNED_FORWARD'),
        ];
    }

    if (market_cloudflare_request_trusted()) {
        $cfCountry = market_ip_country_code($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '');

        if ($cfCountry !== '') {
            return [
                'country' => $cfCountry,
                'source' => 'CLOUDFLARE',
            ];
        }

    }

    foreach ([
        $_SERVER['GEOIP_COUNTRY_CODE'] ?? '',
    ] as $candidate) {
        $country = market_ip_country_code($candidate);
        if ($country !== '') {
            return [
                'country' => $country,
                'source' => 'SERVER_GEOIP',
            ];
        }
    }

    return [
        'country' => 'UNKNOWN',
        'source' => 'UNKNOWN',
    ];
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
            'message' => 'Please allow location permission to continue.',
        ];
    }

    $gpsCountry = market_country_from_coordinates($lat, $lng);
    $ipCountryDetails = market_request_ip_country_details($body);
    $ipCountry = (string)$ipCountryDetails['country'];
    $ipSource = (string)$ipCountryDetails['source'];
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

    if ($ipCountry === '' || $ipCountry === 'UNKNOWN') {
        $accountStatus = 'REVIEW';
        $reviewReasons[] = 'IP_COUNTRY_UNKNOWN';
        $detectionSource = 'BROWSER_GPS_IP_UNKNOWN';
    }

    $gpsIpCountryMismatch = market_gps_ip_country_mismatch($gpsCountry, $ipCountry);

    if ($gpsIpCountryMismatch) {
        $accountStatus = 'REVIEW';
        $vpnSuspected = market_mismatch_marks_vpn_suspected();
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
        'source' => $ipSource !== '' ? $ipSource : 'UNAVAILABLE',
        'reason' => '',
    ];

    if (
        !market_cloudflare_country_enabled()
        &&
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
        'ip_source' => $ipSource,
        'created_ip' => $createdIp,
        'country_mismatch' => $gpsIpCountryMismatch,
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
