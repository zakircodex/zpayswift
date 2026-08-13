<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

define('SECURITY_CLOUDFLARE_IP_COUNTRY_ENABLED', true);
define('SECURITY_REQUIRE_CLOUDFLARE_FOR_COUNTRY', false);
// An empty override must safely fall back to Cloudflare's published ranges.
define('SECURITY_CLOUDFLARE_TRUSTED_PROXY_CIDRS', []);
define('SECURITY_CLOUDFLARE_ORIGIN_LOCKED', false);
define('REGISTRATION_GPS_IP_MISMATCH_VPN_SUSPECTED', true);

$marketAssertions = 0;

function market_test_expect(bool $condition, string $message): void
{
    global $marketAssertions;
    $marketAssertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function auth_country_forwarding_key(): string
{
    return 'registration-market-test-key';
}

function auth_country_currency(string $country): string
{
    return strtoupper($country) === 'BD' ? 'BDT' : 'MYR';
}

function auth_normalize_country_code(string $country): string
{
    $country = strtoupper(trim($country));

    return in_array($country, ['BD', 'MY'], true) ? $country : '';
}

require_once dirname(__DIR__) . '/api/lib/market_detection.php';
require_once dirname(__DIR__) . '/api/lib/account_review.php';

function market_test_server(array $values): void
{
    foreach ([
        'FRONTEND_CDN',
        'REDIRECT_FRONTEND_CDN',
        'ZPAY_TRUSTED_CLOUDFLARE',
        'REDIRECT_ZPAY_TRUSTED_CLOUDFLARE',
        'REDIRECT_REDIRECT_ZPAY_TRUSTED_CLOUDFLARE',
    ] as $key) {
        putenv($key);
    }

    foreach (array_keys($_SERVER) as $key) {
        if (
            str_starts_with($key, 'HTTP_CF_')
            || str_starts_with($key, 'HTTP_X_FORWARDED_')
            || str_starts_with($key, 'HTTP_X_ZPAY_')
            || str_contains($key, 'ZPAY_TRUSTED_CLOUDFLARE')
            || str_contains($key, 'FRONTEND_CDN')
            || $key === 'REMOTE_ADDR'
            || $key === 'GEOIP_COUNTRY_CODE'
        ) {
            unset($_SERVER[$key]);
        }
    }

    foreach ($values as $key => $value) {
        $_SERVER[$key] = $value;
    }
}

function market_test_decision(float $lat, float $lng, string $phoneCountry = 'MY'): array
{
    return market_registration_decision([
        'gps_lat' => $lat,
        'gps_lng' => $lng,
        'gps_accuracy' => 15,
    ], $phoneCountry);
}

market_test_expect(market_ip_in_cidr('173.245.48.10', '173.245.48.0/20'), 'IPv4 CIDR matching failed');
market_test_expect(market_ip_in_cidr('2400:cb00::1234', '2400:cb00::/32'), 'IPv6 CIDR matching failed');
market_test_expect(!market_ip_in_cidr('198.51.100.10', '173.245.48.0/20'), 'untrusted IPv4 matched Cloudflare CIDR');
market_test_expect(market_cloudflare_trusted_proxy_cidrs() !== [], 'empty Cloudflare CIDR override disabled official safe defaults');

market_test_server([
    'REMOTE_ADDR' => '173.245.48.10',
    'HTTP_CF_CONNECTING_IP' => '2001:4860:4860::8888',
    'HTTP_CF_IPCOUNTRY' => 'MY',
]);
$trustedCf = market_request_ip_country_details();
market_test_expect($trustedCf['country'] === 'MY' && $trustedCf['source'] === 'CLOUDFLARE', 'trusted Cloudflare country was not accepted');
market_test_expect(market_request_ip() === '2001:4860:4860::8888', 'trusted Cloudflare IPv6 visitor IP was not selected');

market_test_server([
    'REMOTE_ADDR' => '203.0.113.18',
    'HTTP_CF_CONNECTING_IP' => '203.0.113.18',
    'HTTP_CF_IPCOUNTRY' => 'MY',
    'REDIRECT_ZPAY_TRUSTED_CLOUDFLARE' => '1',
]);
$restoredCf = market_request_ip_country_details();
market_test_expect(
    $restoredCf['country'] === 'MY' && $restoredCf['source'] === 'CLOUDFLARE',
    'LiteSpeed restored visitor IP lost the server-owned Cloudflare trust marker'
);
market_test_expect(market_request_ip() === '203.0.113.18', 'restored Cloudflare visitor IP was not selected');

market_test_server([
    'REMOTE_ADDR' => '203.0.113.19',
    'HTTP_CF_CONNECTING_IP' => '203.0.113.19',
    'HTTP_CF_IPCOUNTRY' => 'BD',
]);
putenv('FRONTEND_CDN=CF');
$litespeedEnvCf = market_request_ip_country_details();
market_test_expect(
    $litespeedEnvCf['country'] === 'BD' && $litespeedEnvCf['source'] === 'CLOUDFLARE',
    'LiteSpeed process environment did not establish trusted Cloudflare country'
);
putenv('FRONTEND_CDN');

market_test_server([
    'REMOTE_ADDR' => '173.245.48.10',
    'HTTP_CF_CONNECTING_IP' => '2001:4860:4860::8888',
    'HTTP_CF_IPCOUNTRY' => 'MY',
]);

$myMatch = market_test_decision(3.1390, 101.6869, 'MY');
market_test_expect($myMatch['gps_country'] === 'MY' && $myMatch['pricing_country'] === 'MY', 'Malaysia GPS pricing changed');
market_test_expect($myMatch['country_mismatch'] === false, 'GPS MY plus IP MY must not mismatch');
market_test_expect($myMatch['account_status'] === 'ACTIVE', 'matching Malaysia request should remain active');

market_test_server([
    'REMOTE_ADDR' => '173.245.48.11',
    'HTTP_CF_CONNECTING_IP' => '8.8.8.8',
    'HTTP_CF_IPCOUNTRY' => 'BD',
]);
$bdMatch = market_test_decision(23.8103, 90.4125, 'BD');
market_test_expect($bdMatch['gps_country'] === 'BD' && $bdMatch['pricing_country'] === 'BD', 'Bangladesh GPS pricing changed');
market_test_expect($bdMatch['country_mismatch'] === false, 'GPS BD plus IP BD must not mismatch');

market_test_server([
    'REMOTE_ADDR' => '173.245.48.12',
    'HTTP_CF_CONNECTING_IP' => '8.8.4.4',
    'HTTP_CF_IPCOUNTRY' => 'US',
]);
$myUs = market_test_decision(3.1390, 101.6869, 'MY');
market_test_expect($myUs['country_mismatch'] === true, 'GPS MY plus IP US must set country_mismatch');
market_test_expect(str_contains($myUs['account_review_reason'], 'GPS_IP_COUNTRY_MISMATCH'), 'GPS/IP mismatch reason missing');
market_test_expect($myUs['vpn_suspected'] === true, 'configured mismatch VPN policy was not preserved');
market_test_expect($myUs['account_status'] === 'REVIEW', 'GPS/IP mismatch must require review');

$myPhoneBd = market_test_decision(3.1390, 101.6869, 'BD');
market_test_expect($myPhoneBd['pricing_country'] === 'MY' && $myPhoneBd['currency'] === 'MYR', 'phone/IP must not override Malaysia GPS pricing');

market_test_server([
    'REMOTE_ADDR' => '173.245.48.13',
    'HTTP_CF_CONNECTING_IP' => '1.1.1.1',
    'HTTP_CF_IPCOUNTRY' => 'XX',
]);
$unknown = market_test_decision(3.1390, 101.6869, 'MY');
market_test_expect($unknown['ip_country'] === 'UNKNOWN' && $unknown['ip_source'] === 'UNKNOWN', 'unknown Cloudflare country must stay explicit');
market_test_expect($unknown['country_mismatch'] === false, 'unknown IP must not become a contradictory mismatch');
market_test_expect(str_contains($unknown['account_review_reason'], 'IP_COUNTRY_UNKNOWN'), 'unknown IP review reason missing');
market_test_expect($unknown['ip_country'] !== 'US', 'unknown country defaulted to US');

market_test_server([
    'REMOTE_ADDR' => '173.245.48.14',
    'HTTP_CF_CONNECTING_IP' => '1.0.0.1',
    'HTTP_CF_IPCOUNTRY' => 'USA',
]);
$invalid = market_request_ip_country_details();
market_test_expect($invalid['country'] === 'UNKNOWN', 'invalid Cloudflare country must not be accepted');

market_test_server([
    'REMOTE_ADDR' => '198.51.100.20',
    'HTTP_CF_CONNECTING_IP' => '8.8.8.8',
    'HTTP_CF_IPCOUNTRY' => 'MY',
    'HTTP_X_FORWARDED_FOR' => '1.1.1.1',
]);
$spoofed = market_request_ip_country_details();
market_test_expect($spoofed['country'] === 'UNKNOWN', 'untrusted direct request controlled country with spoofed Cloudflare headers');
market_test_expect(market_request_ip() === '198.51.100.20', 'untrusted X-Forwarded-For or Cloudflare header controlled visitor IP');

market_test_server([
    'REMOTE_ADDR' => '198.51.100.21',
    'HTTP_CF_CONNECTING_IP' => '8.8.4.4',
    'HTTP_CF_IPCOUNTRY' => 'BD',
    'HTTP_ZPAY_TRUSTED_CLOUDFLARE' => '1',
]);
$spoofedMarker = market_request_ip_country_details();
market_test_expect($spoofedMarker['country'] === 'UNKNOWN', 'client header spoofed the server-owned Cloudflare marker');

market_test_server([
    'REMOTE_ADDR' => '198.51.100.22',
    'HTTP_CF_CONNECTING_IP' => '8.8.8.8',
    'HTTP_CF_IPCOUNTRY' => 'MY',
    'HTTP_FRONTEND_CDN' => 'CF',
]);
$spoofedFrontendCdn = market_request_ip_country_details();
market_test_expect($spoofedFrontendCdn['country'] === 'UNKNOWN', 'client Frontend-CDN header spoofed LiteSpeed environment trust');

market_test_server([
    'REMOTE_ADDR' => '203.0.113.22',
    'REDIRECT_ZPAY_TRUSTED_CLOUDFLARE' => '1',
    'GEOIP_COUNTRY_CODE' => 'BD',
]);
$serverGeoIp = market_request_ip_country_details();
market_test_expect(
    $serverGeoIp['country'] === 'BD' && $serverGeoIp['source'] === 'SERVER_GEOIP',
    'server GeoIP did not safely resolve a missing Cloudflare country header'
);

$rootRewrite = (string)file_get_contents(dirname(__DIR__) . '/.htaccess');
market_test_expect(
    str_contains($rootRewrite, 'RewriteCond %{ENV:FRONTEND_CDN} ^CF$ [NC]')
        && str_contains($rootRewrite, '%{CONN_REMOTE_ADDR} -ipmatch')
        && str_contains($rootRewrite, 'E=ZPAY_TRUSTED_CLOUDFLARE:1'),
    'raw Cloudflare connection-peer trust is not forwarded to PHP'
);
foreach (market_cloudflare_trusted_proxy_cidrs() as $cloudflareCidr) {
    market_test_expect(
        str_contains($rootRewrite, "'" . $cloudflareCidr . "'"),
        'missing raw-peer rewrite rule for a configured Cloudflare CIDR'
    );
}

market_test_server([
    'REMOTE_ADDR' => '173.245.48.15',
    'HTTP_CF_CONNECTING_IP' => '9.9.9.9',
    'HTTP_CF_IPCOUNTRY' => 'US',
    'HTTP_X_ZPAY_CLIENT_IP' => '2001:db8::45',
    'HTTP_X_ZPAY_CLIENT_IP_SIGNATURE' => market_forwarding_signature('ip', '2001:db8::45'),
    'HTTP_X_ZPAY_IP_COUNTRY' => 'MY',
    'HTTP_X_ZPAY_IP_COUNTRY_SIGNATURE' => market_forwarding_signature('ip-country', 'MY'),
    'HTTP_X_ZPAY_IP_SOURCE' => 'CLOUDFLARE',
    'HTTP_X_ZPAY_IP_SOURCE_SIGNATURE' => market_forwarding_signature('ip-source', 'CLOUDFLARE'),
]);
$signed = market_request_ip_country_details();
market_test_expect($signed['country'] === 'MY' && $signed['source'] === 'CLOUDFLARE', 'signed first-hop country/source did not beat second-hop Cloudflare country');
market_test_expect(market_request_ip() === '2001:db8::45', 'signed first-hop visitor IP did not beat second-hop Cloudflare IP');

market_test_server([
    'REMOTE_ADDR' => '173.245.48.16',
    'HTTP_CF_CONNECTING_IP' => '9.9.9.9',
    'HTTP_CF_IPCOUNTRY' => 'US',
    'HTTP_X_ZPAY_IP_COUNTRY' => 'UNKNOWN',
    'HTTP_X_ZPAY_IP_COUNTRY_SIGNATURE' => market_forwarding_signature('ip-country', 'UNKNOWN'),
]);
$signedUnknown = market_request_ip_country_details();
market_test_expect($signedUnknown['country'] === 'UNKNOWN' && $signedUnknown['source'] === 'SIGNED_FORWARD', 'signed unknown first-hop country was replaced by second-hop country');

$reviewRow = [
    'uid' => 'U_TEST_REVIEW',
    'name' => 'Test User',
    'phone_e164' => '6012****789',
    'phone_country' => 'MY',
    'gps_country' => $myUs['gps_country'],
    'ip_country' => $myUs['ip_country'],
    'ip_source' => $myUs['ip_source'],
    'pricing_country' => $myUs['pricing_country'],
    'currency' => $myUs['currency'],
    'country_mismatch' => $myUs['country_mismatch'],
    'vpn_suspected' => $myUs['vpn_suspected'],
    'account_review_reason' => $myUs['account_review_reason'],
    'account_status' => $myUs['account_status'],
    'created_at' => 1,
];
$telegram = account_review_message($reviewRow);
foreach ([
    'GPS Country: <b>MY</b>',
    'IP Country: <b>US</b>',
    'IP Source: <b>CLOUDFLARE</b>',
    'Pricing Country: <b>MY</b>',
    'Currency: <b>MYR</b>',
    'Mismatch: <b>Yes</b>',
    'VPN Suspected: <b>Yes</b>',
    'Review Reason: GPS_IP_COUNTRY_MISMATCH',
    'Status: <b>REVIEW</b>',
] as $expected) {
    market_test_expect(str_contains($telegram, $expected), 'Telegram review does not match stored value: ' . $expected);
}

echo "OK registration market detection ({$marketAssertions} assertions)\n";
