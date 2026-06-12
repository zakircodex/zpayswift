<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

/*
|--------------------------------------------------------------------------
| Z-Pay Swift - Common Security Layer
|--------------------------------------------------------------------------
| File: /api/lib/security.php
|
| কাজ:
| 1) Safe client IP detect
| 2) VPN / Proxy / Tor / Datacenter risk base
| 3) Firebase whitelist / blocklist / cache
| 4) Full site/API level blocking helper
| 5) Country + wallet currency lock helper
|
| Important:
| - External VPN detection 100% perfect না।
| - Strong VPN block এর জন্য Cloudflare WAF / paid IP reputation API better।
| - এই file provider-ready, অর্থাৎ config এ external endpoint দিলে auto check করবে।
|--------------------------------------------------------------------------
*/

function security_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function security_enabled(): bool
{
    if (defined('SECURITY_ENABLED')) {
        return (bool)SECURITY_ENABLED;
    }

    return true;
}

function security_bool_constant(string $name, bool $default): bool
{
    if (!defined($name)) {
        return $default;
    }

    $value = constant($name);

    if (is_bool($value)) {
        return $value;
    }

    $s = strtoupper(trim((string)$value));

    if (in_array($s, ['1', 'TRUE', 'YES', 'ON', 'ENABLED'], true)) {
        return true;
    }

    if (in_array($s, ['0', 'FALSE', 'NO', 'OFF', 'DISABLED'], true)) {
        return false;
    }

    return $default;
}

function security_int_constant(string $name, int $default): int
{
    if (!defined($name)) {
        return $default;
    }

    $value = constant($name);

    if (!is_numeric($value)) {
        return $default;
    }

    return (int)$value;
}

function security_string_constant(string $name, string $default = ''): string
{
    if (!defined($name)) {
        return $default;
    }

    return trim((string)constant($name));
}

function security_array_constant(string $name): array
{
    if (!defined($name)) {
        return [];
    }

    $value = constant($name);

    if (is_array($value)) {
        return $value;
    }

    if (is_string($value) && trim($value) !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    return [];
}

function security_request_method(): string
{
    return strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')));
}

function security_request_path(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');

    if ($uri === '') {
        return '';
    }

    $path = parse_url($uri, PHP_URL_PATH);

    return is_string($path) ? $path : $uri;
}

function security_user_agent(): string
{
    return substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300);
}

function security_is_cli(): bool
{
    return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
}

function security_is_valid_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

function security_is_public_ip(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

function security_is_private_or_reserved_ip(string $ip): bool
{
    if (!security_is_valid_ip($ip)) {
        return true;
    }

    return !security_is_public_ip($ip);
}

function security_client_ip(): string
{
    $candidates = [];

    /*
     * Cloudflare থাকলে CF-Connecting-IP best.
     * তবে server অবশ্যই Cloudflare-only access এ রাখা better.
     */
    $cfIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cfIp !== '') {
        $candidates[] = $cfIp;
    }

    /*
     * Proxy / Load balancer fallback.
     */
    $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($xff !== '') {
        foreach (explode(',', $xff) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $candidates[] = $part;
            }
        }
    }

    $realIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($realIp !== '') {
        $candidates[] = $realIp;
    }

    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteAddr !== '') {
        $candidates[] = $remoteAddr;
    }

    /*
     * First valid public IP priority.
     */
    foreach ($candidates as $ip) {
        if (security_is_valid_ip($ip) && security_is_public_ip($ip)) {
            return $ip;
        }
    }

    /*
     * Public না পেলে first valid IP.
     */
    foreach ($candidates as $ip) {
        if (security_is_valid_ip($ip)) {
            return $ip;
        }
    }

    return '';
}

function security_ip_family(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return 'IPv4';
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return 'IPv6';
    }

    return 'UNKNOWN';
}

function security_secret_for_hash(): string
{
    $candidates = [
        'SECURITY_HASH_SECRET',
        'APP_KEY',
        'APP_API_KEY',
        'FIREBASE_AUTH',
        'FIREBASE_DB_SECRET',
    ];

    foreach ($candidates as $name) {
        $value = security_string_constant($name);
        if ($value !== '') {
            return $value;
        }
    }

    return 'zawtopup-security-default-secret-change-me';
}

function security_ip_hash(string $ip): string
{
    return hash_hmac('sha256', trim($ip), security_secret_for_hash());
}

function security_fb_get(string $path)
{
    if (!function_exists('fb_get')) {
        return null;
    }

    try {
        return fb_get($path);
    } catch (Throwable $e) {
        return null;
    }
}

function security_fb_put(string $path, array $data): bool
{
    if (!function_exists('fb_put')) {
        return false;
    }

    try {
        return (bool)fb_put($path, $data);
    } catch (Throwable $e) {
        return false;
    }
}

function security_fb_patch(string $path, array $data): bool
{
    if (!function_exists('fb_patch')) {
        return false;
    }

    try {
        return (bool)fb_patch($path, $data);
    } catch (Throwable $e) {
        return false;
    }
}

function security_month_key(?int $ts = null): string
{
    if ($ts === null && function_exists('month_key')) {
        return (string)month_key();
    }

    return date('Y-m', $ts ?? security_now());
}

function security_make_event_id(string $prefix = 'SEC'): string
{
    try {
        return $prefix . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    } catch (Throwable $e) {
        return $prefix . date('YmdHis') . strtoupper(substr(md5((string)microtime(true)), 0, 8));
    }
}

function security_ip_in_cidr(string $ip, string $cidr): bool
{
    $ip = trim($ip);
    $cidr = trim($cidr);

    if ($ip === '' || $cidr === '') {
        return false;
    }

    if (strpos($cidr, '/') === false) {
        return hash_equals($ip, $cidr);
    }

    [$network, $prefix] = explode('/', $cidr, 2);

    $network = trim($network);
    $prefix = (int)trim($prefix);

    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($network);

    if ($ipBin === false || $netBin === false) {
        return false;
    }

    if (strlen($ipBin) !== strlen($netBin)) {
        return false;
    }

    $maxBits = strlen($ipBin) * 8;

    if ($prefix < 0 || $prefix > $maxBits) {
        return false;
    }

    $bytes = intdiv($prefix, 8);
    $bits = $prefix % 8;

    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
        return false;
    }

    if ($bits === 0) {
        return true;
    }

    $mask = chr((0xFF << (8 - $bits)) & 0xFF);

    return (($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask));
}

function security_ip_matches_list(string $ip, array $list): bool
{
    foreach ($list as $item) {
        $item = trim((string)$item);

        if ($item === '') {
            continue;
        }

        if (security_ip_in_cidr($ip, $item)) {
            return true;
        }
    }

    return false;
}

function security_whitelist_hit(string $ip): bool
{
    if ($ip === '') {
        return false;
    }

    $localList = security_array_constant('SECURITY_IP_WHITELIST');

    if ($localList && security_ip_matches_list($ip, $localList)) {
        return true;
    }

    $ipHash = security_ip_hash($ip);
    $row = security_fb_get('SECURITY_IP_WHITELIST/' . $ipHash);

    if (is_array($row)) {
        $status = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));
        $active = array_key_exists('active', $row) ? (bool)$row['active'] : true;

        return $active && $status === 'ACTIVE';
    }

    if ($row === true || $row === 'true' || $row === 1 || $row === '1') {
        return true;
    }

    return false;
}

function security_blocklist_hit(string $ip): bool
{
    if ($ip === '') {
        return false;
    }

    $localList = security_array_constant('SECURITY_IP_BLOCKLIST');

    if ($localList && security_ip_matches_list($ip, $localList)) {
        return true;
    }

    $ipHash = security_ip_hash($ip);
    $row = security_fb_get('SECURITY_IP_BLOCKLIST/' . $ipHash);

    if (is_array($row)) {
        $status = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));
        $active = array_key_exists('active', $row) ? (bool)$row['active'] : true;

        return $active && $status === 'ACTIVE';
    }

    if ($row === true || $row === 'true' || $row === 1 || $row === '1') {
        return true;
    }

    return false;
}

function security_cache_ttl_seconds(): int
{
    return max(60, security_int_constant('SECURITY_IP_CACHE_TTL_SECONDS', 86400));
}

function security_cache_load(string $ip): array
{
    if ($ip === '') {
        return [];
    }

    $row = security_fb_get('SECURITY_IP_CACHE/' . security_ip_hash($ip));

    if (!is_array($row)) {
        return [];
    }

    $expiresAt = (int)($row['expires_at'] ?? 0);

    if ($expiresAt > 0 && $expiresAt < security_now()) {
        return [];
    }

    return $row;
}

function security_cache_save(string $ip, array $result): void
{
    if ($ip === '') {
        return;
    }

    $now = security_now();
    $ttl = security_cache_ttl_seconds();

    $data = [
        'ip_hash' => security_ip_hash($ip),
        'ip_family' => security_ip_family($ip),
        'verdict' => (string)($result['verdict'] ?? 'UNKNOWN'),
        'blocked' => (bool)($result['blocked'] ?? false),
        'risk_type' => (string)($result['risk_type'] ?? 'UNKNOWN'),
        'risk_score' => (int)($result['risk_score'] ?? 0),
        'source' => (string)($result['source'] ?? 'LOCAL'),
        'reason' => (string)($result['reason'] ?? ''),
        'created_at' => $now,
        'checked_at' => $now,
        'expires_at' => $now + $ttl,
    ];

    security_fb_put('SECURITY_IP_CACHE/' . security_ip_hash($ip), $data);
}

function security_flatten_keys(array $arr, string $prefix = ''): array
{
    $out = [];

    foreach ($arr as $key => $value) {
        $k = strtolower(trim((string)$key));
        $full = $prefix === '' ? $k : $prefix . '.' . $k;

        if (is_array($value)) {
            $out += security_flatten_keys($value, $full);
        } else {
            $out[$full] = $value;
            $out[$k] = $value;
        }
    }

    return $out;
}

function security_truthy($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (float)$value > 0;
    }

    $s = strtoupper(trim((string)$value));

    return in_array($s, ['1', 'TRUE', 'YES', 'Y', 'ON', 'VPN', 'PROXY', 'TOR', 'HOSTING', 'DATACENTER'], true);
}

function security_external_lookup_enabled(): bool
{
    return security_bool_constant('SECURITY_EXTERNAL_IP_LOOKUP_ENABLED', false);
}

function security_external_ip_lookup(string $ip): array
{
    if (!security_external_lookup_enabled()) {
        return [
            'ok' => false,
            'source' => 'DISABLED',
            'message' => 'External IP lookup disabled',
            'data' => [],
        ];
    }

    $endpoint = security_string_constant('SECURITY_IP_RISK_ENDPOINT');

    if ($endpoint === '') {
        return [
            'ok' => false,
            'source' => 'NO_ENDPOINT',
            'message' => 'SECURITY_IP_RISK_ENDPOINT missing',
            'data' => [],
        ];
    }

    $url = str_replace('{IP}', rawurlencode($ip), $endpoint);

    $headers = [
        'Accept: application/json',
        'User-Agent: ZPaySwiftSecurity/1.0',
    ];

    $apiKey = security_string_constant('SECURITY_IP_RISK_API_KEY');
    $authHeaderTemplate = security_string_constant('SECURITY_IP_RISK_AUTH_HEADER');

    if ($apiKey !== '') {
        if ($authHeaderTemplate !== '') {
            $headers[] = str_replace('{KEY}', $apiKey, $authHeaderTemplate);
        } else {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
    }

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        return [
            'ok' => false,
            'source' => 'CURL',
            'message' => $err ?: 'Empty response',
            'http' => $http,
            'data' => [],
        ];
    }

    $json = json_decode((string)$raw, true);

    if (!is_array($json)) {
        return [
            'ok' => false,
            'source' => 'INVALID_JSON',
            'message' => 'Invalid JSON from IP risk provider',
            'http' => $http,
            'raw' => substr((string)$raw, 0, 500),
            'data' => [],
        ];
    }

    return [
        'ok' => $http >= 200 && $http < 300,
        'source' => 'EXTERNAL',
        'message' => 'External lookup complete',
        'http' => $http,
        'data' => $json,
    ];
}

function security_parse_external_risk(array $lookup): array
{
    if (empty($lookup['ok']) || !is_array($lookup['data'] ?? null)) {
        return [
            'verdict' => 'UNKNOWN',
            'blocked' => false,
            'risk_type' => 'UNKNOWN',
            'risk_score' => 0,
            'source' => (string)($lookup['source'] ?? 'EXTERNAL'),
            'reason' => (string)($lookup['message'] ?? 'External lookup failed'),
        ];
    }

    $flat = security_flatten_keys((array)$lookup['data']);

    $riskType = 'NONE';
    $riskScore = 0;
    $blocked = false;
    $reasons = [];

    $flagKeys = [
        'vpn' => 'VPN',
        'is_vpn' => 'VPN',
        'active_vpn' => 'VPN',
        'anonymous_vpn' => 'VPN',

        'proxy' => 'PROXY',
        'is_proxy' => 'PROXY',
        'active_proxy' => 'PROXY',
        'anonymous_proxy' => 'PROXY',

        'tor' => 'TOR',
        'is_tor' => 'TOR',
        'active_tor' => 'TOR',

        'hosting' => 'DATACENTER',
        'host' => 'DATACENTER',
        'datacenter' => 'DATACENTER',
        'data_center' => 'DATACENTER',
        'is_datacenter' => 'DATACENTER',

        'anonymous' => 'ANONYMOUS',
        'privacy' => 'ANONYMOUS',
    ];

    foreach ($flagKeys as $key => $type) {
        if (array_key_exists($key, $flat) && security_truthy($flat[$key])) {
            $riskType = $type;
            $reasons[] = $type . ' flag detected';
            break;
        }
    }

    $scoreKeys = [
        'risk_score',
        'fraud_score',
        'score',
        'risk',
        'confidence',
        'threat_score',
    ];

    foreach ($scoreKeys as $key) {
        if (array_key_exists($key, $flat) && is_numeric($flat[$key])) {
            $riskScore = max($riskScore, (int)round((float)$flat[$key]));
        }
    }

    $blockVpn = security_bool_constant('SECURITY_BLOCK_VPN', true);
    $blockProxy = security_bool_constant('SECURITY_BLOCK_PROXY', true);
    $blockTor = security_bool_constant('SECURITY_BLOCK_TOR', true);
    $blockDatacenter = security_bool_constant('SECURITY_BLOCK_DATACENTER', true);
    $blockAnonymous = security_bool_constant('SECURITY_BLOCK_ANONYMOUS', true);

    if ($riskType === 'VPN' && $blockVpn) {
        $blocked = true;
    } elseif ($riskType === 'PROXY' && $blockProxy) {
        $blocked = true;
    } elseif ($riskType === 'TOR' && $blockTor) {
        $blocked = true;
    } elseif ($riskType === 'DATACENTER' && $blockDatacenter) {
        $blocked = true;
    } elseif ($riskType === 'ANONYMOUS' && $blockAnonymous) {
        $blocked = true;
    }

    $highRiskThreshold = security_int_constant('SECURITY_HIGH_RISK_SCORE_BLOCK_AT', 85);
    $blockHighRisk = security_bool_constant('SECURITY_BLOCK_HIGH_RISK_SCORE', true);

    if ($blockHighRisk && $riskScore >= $highRiskThreshold) {
        $blocked = true;
        if ($riskType === 'NONE') {
            $riskType = 'HIGH_RISK';
        }
        $reasons[] = 'High risk score ' . $riskScore;
    }

    return [
        'verdict' => $blocked ? 'BLOCK' : 'ALLOW',
        'blocked' => $blocked,
        'risk_type' => $riskType,
        'risk_score' => $riskScore,
        'source' => 'EXTERNAL',
        'reason' => $reasons ? implode('; ', array_unique($reasons)) : 'No risky flag detected',
    ];
}

function security_detect_ip_risk(string $ip): array
{
    if ($ip === '' || !security_is_valid_ip($ip)) {
        return [
            'verdict' => 'BLOCK',
            'blocked' => true,
            'risk_type' => 'INVALID_IP',
            'risk_score' => 100,
            'source' => 'LOCAL',
            'reason' => 'Invalid client IP',
        ];
    }

    if (security_whitelist_hit($ip)) {
        return [
            'verdict' => 'ALLOW',
            'blocked' => false,
            'risk_type' => 'WHITELIST',
            'risk_score' => 0,
            'source' => 'WHITELIST',
            'reason' => 'IP is whitelisted',
        ];
    }

    if (security_blocklist_hit($ip)) {
        return [
            'verdict' => 'BLOCK',
            'blocked' => true,
            'risk_type' => 'BLOCKLIST',
            'risk_score' => 100,
            'source' => 'BLOCKLIST',
            'reason' => 'IP is blocklisted',
        ];
    }

    if (security_is_private_or_reserved_ip($ip)) {
        return [
            'verdict' => 'ALLOW',
            'blocked' => false,
            'risk_type' => 'LOCAL_OR_RESERVED',
            'risk_score' => 0,
            'source' => 'LOCAL',
            'reason' => 'Private/reserved IP skipped',
        ];
    }

    $cached = security_cache_load($ip);

    if ($cached) {
        return [
            'verdict' => (string)($cached['verdict'] ?? 'UNKNOWN'),
            'blocked' => (bool)($cached['blocked'] ?? false),
            'risk_type' => (string)($cached['risk_type'] ?? 'UNKNOWN'),
            'risk_score' => (int)($cached['risk_score'] ?? 0),
            'source' => 'CACHE',
            'reason' => (string)($cached['reason'] ?? ''),
        ];
    }

    $lookup = security_external_ip_lookup($ip);
    $result = security_parse_external_risk($lookup);

    /*
     * External provider না থাকলে default allow।
     * কারণ ভুল করে পুরো site বন্ধ হয়ে যাওয়া ঠিক না।
     */
    if (($result['verdict'] ?? '') === 'UNKNOWN') {
        $allowUnknown = security_bool_constant('SECURITY_ALLOW_UNKNOWN_IP_RISK', true);

        $result['verdict'] = $allowUnknown ? 'ALLOW' : 'BLOCK';
        $result['blocked'] = !$allowUnknown;
        $result['risk_type'] = 'UNKNOWN';
        $result['risk_score'] = $allowUnknown ? 0 : 80;
    }

    security_cache_save($ip, $result);

    return $result;
}

function security_should_skip_ip_risk_for_endpoint(): bool
{
    $path = strtolower(security_request_path());

    if ($path === '') {
        return false;
    }

    /*
     * Telegram webhook নিজে secret/key verify করবে।
     * এখানে শুধু VPN/IP risk skip হবে।
     */
    $skipPrefixes = [
        '/api/telegram/',
        '/api/telegram/',
    ];

    foreach ($skipPrefixes as $prefix) {
        if (str_contains($path, $prefix)) {
            return true;
        }
    }

    /*
     * Server cron/worker health check route থাকলে config থেকে add করা যাবে।
     */
    $customSkips = security_array_constant('SECURITY_IP_RISK_SKIP_PATHS');

    foreach ($customSkips as $skip) {
        $skip = strtolower(trim((string)$skip));

        if ($skip !== '' && str_contains($path, $skip)) {
            return true;
        }
    }

    return false;
}

function security_log_event(string $event, string $message, array $data = []): void
{
    $now = security_now();
    $eventId = security_make_event_id('SEC');

    $payload = [
        'event_id' => $eventId,
        'event' => $event,
        'message' => $message,
        'path' => security_request_path(),
        'method' => security_request_method(),
        'user_agent' => security_user_agent(),
        'created_at' => $now,
        'data' => $data,
    ];

    security_fb_put('SECURITY_EVENTS/' . security_month_key($now) . '/' . $eventId, $payload);

    if (function_exists('system_log')) {
        try {
            system_log($event, $eventId, $message, $data);
        } catch (Throwable $e) {
            // ignore logging failure
        }
    }
}

function security_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

function security_enforce_request(array $context = []): void
{
    if (!security_enabled()) {
        return;
    }

    if (security_is_cli()) {
        return;
    }

    if (security_request_method() === 'OPTIONS') {
        return;
    }

    if (security_should_skip_ip_risk_for_endpoint()) {
        return;
    }

    $ip = security_client_ip();

    $result = security_detect_ip_risk($ip);

    if (!empty($result['blocked'])) {
        $ipHash = $ip !== '' ? security_ip_hash($ip) : '';

        security_log_event('SECURITY_BLOCKED_IP', 'Request blocked by security layer', [
            'ip_hash' => $ipHash,
            'ip_family' => $ip !== '' ? security_ip_family($ip) : 'UNKNOWN',
            'verdict' => (string)($result['verdict'] ?? 'BLOCK'),
            'risk_type' => (string)($result['risk_type'] ?? 'UNKNOWN'),
            'risk_score' => (int)($result['risk_score'] ?? 0),
            'source' => (string)($result['source'] ?? ''),
            'reason' => (string)($result['reason'] ?? ''),
            'context' => $context,
        ]);

        security_response(false, 'BLOCKED_VPN_PROXY', 'VPN, proxy, Tor or risky network is not allowed.', [
            'risk_type' => (string)($result['risk_type'] ?? 'UNKNOWN'),
            'risk_score' => (int)($result['risk_score'] ?? 0),
            'support_code' => substr($ipHash, 0, 12),
        ], 403);
    }
}

/* =========================================================
   Country / Wallet Currency Lock Helpers
========================================================= */

function security_normalize_country_code(string $country): string
{
    $country = strtoupper(trim($country));

    $map = [
        'BD' => 'BD',
        'BGD' => 'BD',
        'BANGLADESH' => 'BD',

        'MY' => 'MY',
        'MYS' => 'MY',
        'MALAYSIA' => 'MY',
    ];

    return $map[$country] ?? '';
}

function security_normalize_currency(string $currency): string
{
    $currency = strtoupper(trim($currency));

    $map = [
        'BDT' => 'BDT',
        'TK' => 'BDT',
        'TAKA' => 'BDT',

        'MYR' => 'MYR',
        'RM' => 'MYR',
        'RINGGIT' => 'MYR',
    ];

    return $map[$currency] ?? '';
}

function security_expected_currency_for_country(string $countryCode): string
{
    $countryCode = security_normalize_country_code($countryCode);

    if ($countryCode === 'BD') {
        return 'BDT';
    }

    if ($countryCode === 'MY') {
        return 'MYR';
    }

    return '';
}

function security_service_mode_from_currency(string $walletCurrency): string
{
    $walletCurrency = security_normalize_currency($walletCurrency);

    if ($walletCurrency === 'BDT') {
        return 'LOCAL';
    }

    if ($walletCurrency === 'MYR') {
        return 'REMITTANCE';
    }

    return 'UNKNOWN';
}

function security_user_country_code(array $user): string
{
    return security_normalize_country_code((string)(
        $user['pricing_country']
        ?? $user['service_country']
        ?? $user['country_code']
        ?? $user['country']
        ?? $user['user_country']
        ?? ''
    ));
}

function security_user_wallet_currency(array $user, array $wallet = []): string
{
    $currency = security_normalize_currency((string)(
        $user['wallet_currency']
        ?? $user['currency']
        ?? ''
    ));

    if ($currency !== '') {
        return $currency;
    }

    return security_normalize_currency((string)(
        $wallet['currency']
        ?? $wallet['wallet_currency']
        ?? ''
    ));
}

function security_validate_country_wallet_lock(array $user, array $wallet = []): array
{
    $countryCode = security_user_country_code($user);
    $walletCurrency = security_user_wallet_currency($user, $wallet);
    $expectedCurrency = security_expected_currency_for_country($countryCode);
    $countryLocked = array_key_exists('country_locked', $user) ? (bool)$user['country_locked'] : false;
    $countryVerified = array_key_exists('country_verified', $user) ? (bool)$user['country_verified'] : false;
    $kycStatus = strtoupper(trim((string)($user['kyc_status'] ?? '')));

    if ($countryCode === '') {
        return [
            'ok' => false,
            'code' => 'COUNTRY_MISSING',
            'message' => 'User country is not set',
            'country_code' => '',
            'wallet_currency' => $walletCurrency,
            'expected_currency' => '',
            'service_mode' => security_service_mode_from_currency($walletCurrency),
        ];
    }

    if ($walletCurrency === '') {
        return [
            'ok' => false,
            'code' => 'WALLET_CURRENCY_MISSING',
            'message' => 'Wallet currency is not set',
            'country_code' => $countryCode,
            'wallet_currency' => '',
            'expected_currency' => $expectedCurrency,
            'service_mode' => 'UNKNOWN',
        ];
    }

    if ($expectedCurrency !== '' && $walletCurrency !== $expectedCurrency) {
        return [
            'ok' => false,
            'code' => 'COUNTRY_CURRENCY_MISMATCH',
            'message' => 'User country and wallet currency mismatch',
            'country_code' => $countryCode,
            'wallet_currency' => $walletCurrency,
            'expected_currency' => $expectedCurrency,
            'service_mode' => security_service_mode_from_currency($walletCurrency),
        ];
    }

    return [
        'ok' => true,
        'code' => 'COUNTRY_WALLET_OK',
        'message' => 'Country and wallet currency valid',
        'country_code' => $countryCode,
        'wallet_currency' => $walletCurrency,
        'expected_currency' => $expectedCurrency,
        'country_locked' => $countryLocked,
        'country_verified' => $countryVerified,
        'kyc_status' => $kycStatus,
        'service_mode' => security_service_mode_from_currency($walletCurrency),
    ];
}

function security_require_country_wallet_lock(array $user, array $wallet = []): array
{
    $check = security_validate_country_wallet_lock($user, $wallet);

    if (!($check['ok'] ?? false)) {
        security_response(false, (string)$check['code'], (string)$check['message'], $check, 403);
    }

    return $check;
}
