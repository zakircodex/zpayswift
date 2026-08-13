<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['REMOTE_ADDR'] = '198.51.100.50';

define('SECURITY_CLOUDFLARE_IP_COUNTRY_ENABLED', true);
define('SECURITY_REQUIRE_CLOUDFLARE_FOR_COUNTRY', true);
define('SECURITY_CLOUDFLARE_TRUSTED_PROXY_CIDRS', []);
define('SECURITY_CLOUDFLARE_ORIGIN_LOCKED', false);

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

$decision = market_registration_decision([
    'gps_lat' => 3.1390,
    'gps_lng' => 101.6869,
    'gps_accuracy' => 15,
], 'MY');

if (
    $decision['ip_country'] !== 'UNKNOWN'
    || $decision['country_mismatch'] !== false
    || $decision['vpn_suspected'] !== false
    || $decision['account_status'] !== 'REVIEW'
    || !str_contains((string)$decision['account_review_reason'], 'IP_COUNTRY_UNKNOWN')
) {
    fwrite(STDERR, "FAIL: required Cloudflare-country policy did not review an unknown IP country\n");
    exit(1);
}

fwrite(STDOUT, "Required Cloudflare-country registration policy passed.\n");
