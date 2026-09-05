<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_adsterra_provider(): string
{
    return 'ADSTERRA';
}

function znews_adsterra_env(string $name): string
{
    $value = getenv($name);
    return is_string($value) ? trim($value) : '';
}

function znews_adsterra_token(): string
{
    return znews_adsterra_env('ADSTERRA_PUBLISHER_API_TOKEN');
}

function znews_adsterra_domain_id(): string
{
    $value = znews_adsterra_env('ADSTERRA_ZSKY24_DOMAIN_ID');
    return preg_match('/^[0-9]{1,20}$/D', $value) === 1 ? $value : '';
}

function znews_adsterra_timeout_seconds(): int
{
    $raw = znews_adsterra_env('ADSTERRA_PUBLISHER_API_TIMEOUT_SECONDS');
    $value = ctype_digit($raw) ? (int)$raw : 8;
    return max(3, min(15, $value));
}

function znews_adsterra_cache_ttl_seconds(): int
{
    $raw = znews_adsterra_env('ADSTERRA_PUBLISHER_CACHE_TTL_SECONDS');
    $value = ctype_digit($raw) ? (int)$raw : 45;
    return max(15, min(60, $value));
}

function znews_adsterra_max_amount_micros(): int
{
    // Bounds malformed provider responses while leaving ample room for monthly revenue and FX inputs.
    return 1000000000 * 1000000;
}

function znews_adsterra_decimal_micros($value): ?int
{
    if (is_int($value)) {
        return $value >= 0 && $value <= intdiv(znews_adsterra_max_amount_micros(), 1000000)
            ? $value * 1000000
            : null;
    }
    if (is_float($value)) {
        if (!is_finite($value) || $value < 0) {
            return null;
        }
        $value = sprintf('%.9F', $value);
    }

    $value = trim((string)$value);
    if (preg_match('/^(\d{1,15})(?:\.(\d{0,12}))?$/D', $value, $match) !== 1) {
        return null;
    }

    $whole = (int)$match[1];
    if ($whole > intdiv(znews_adsterra_max_amount_micros(), 1000000)) {
        return null;
    }
    $fraction = str_pad((string)($match[2] ?? ''), 7, '0');
    $micros = (int)substr($fraction, 0, 6);
    if ((int)$fraction[6] >= 5) {
        $micros++;
        if ($micros >= 1000000) {
            $whole++;
            $micros = 0;
        }
    }

    $result = ($whole * 1000000) + $micros;
    return $result <= znews_adsterra_max_amount_micros() ? $result : null;
}

function znews_adsterra_decimal(int $micros, int $precision = 6): string
{
    $precision = max(0, min(6, $precision));
    $whole = intdiv(max(0, $micros), 1000000);
    if ($precision === 0) {
        return (string)$whole;
    }
    $fraction = str_pad((string)(max(0, $micros) % 1000000), 6, '0', STR_PAD_LEFT);
    $fraction = substr($fraction, 0, $precision);
    $fraction = rtrim($fraction, '0');
    return (string)$whole . ($fraction === '' ? '' : '.' . $fraction);
}

function znews_adsterra_positive_int($value): ?int
{
    if (is_int($value)) {
        return $value >= 0 ? $value : null;
    }
    $value = trim((string)$value);
    if (!ctype_digit($value)) {
        return null;
    }
    $normalized = ltrim($value, '0');
    $normalized = $normalized === '' ? '0' : $normalized;
    $max = (string)PHP_INT_MAX;
    if (strlen($normalized) > strlen($max)
        || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
        return null;
    }
    return (int)$normalized;
}

function znews_adsterra_scalar($value, int $maxLength = 120): ?string
{
    if ($value === null) {
        return '';
    }
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return null;
    }
    $value = trim((string)$value);
    return strlen($value) <= $maxLength ? $value : null;
}

function znews_adsterra_decimal_metric($value): ?string
{
    $value = znews_adsterra_scalar($value, 40);
    if ($value === null || ($value !== '' && preg_match('/^-?\d{1,15}(?:\.\d{1,12})?$/D', $value) !== 1)) {
        return null;
    }
    return $value;
}

function znews_adsterra_cache_path(string $startDate, string $finishDate, string $domainId): string
{
    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'zsky24-adsterra-cache';
    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }
    return $directory . DIRECTORY_SEPARATOR
        . hash('sha256', implode('|', [$domainId, $startDate, $finishDate])) . '.json';
}

function znews_adsterra_cache_read(string $path, int $now): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $row = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($row)
        || (int)($row['expires_at'] ?? 0) < $now
        || !is_array($row['result'] ?? null)) {
        return null;
    }
    $result = (array)$row['result'];
    $result['cache_hit'] = true;
    return $result;
}

function znews_adsterra_cache_write(string $path, array $result, int $now): void
{
    if (!is_dir(dirname($path)) || !is_writable(dirname($path))) {
        return;
    }
    $payload = json_encode([
        'expires_at' => $now + znews_adsterra_cache_ttl_seconds(),
        'result' => $result,
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return;
    }
    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Throwable $exception) {
        return;
    }
    $temporary = $path . '.' . $suffix . '.tmp';
    if (@file_put_contents($temporary, $payload, LOCK_EX) === false) {
        return;
    }
    @chmod($temporary, 0600);
    @rename($temporary, $path);
}

function znews_adsterra_http_get(string $url, array $headers, int $timeout): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error_code' => 'CURL_UNAVAILABLE'];
    }

    $handle = curl_init($url);
    if ($handle === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error_code' => 'CURL_INIT_FAILED'];
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'ZSky24-Adsterra-Revenue/1.0',
    ]);
    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_errno($handle);
    curl_close($handle);

    return [
        'ok' => is_string($body) && $curlError === 0,
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'error_code' => $curlError === 0 ? '' : 'ADSTERRA_NETWORK_ERROR',
    ];
}

function znews_adsterra_rows(array $decoded, int $depth = 0): array
{
    if ($depth > 3) {
        return [];
    }
    foreach (['items', 'data', 'rows', 'result', 'stats'] as $field) {
        if (isset($decoded[$field]) && is_array($decoded[$field])) {
            return znews_adsterra_rows((array)$decoded[$field], $depth + 1);
        }
    }
    if ($decoded === []) {
        return [];
    }
    if (array_is_list($decoded)) {
        return array_values(array_filter($decoded, 'is_array'));
    }
    foreach (['revenue', 'profit', 'earnings', 'impressions', 'clicks'] as $metric) {
        if (array_key_exists($metric, $decoded)) {
            return [$decoded];
        }
    }
    return array_values(array_filter($decoded, 'is_array'));
}

function znews_adsterra_normalize_report(string $body, string $domainId): array
{
    if (strlen($body) > 2000000) {
        return ['ok' => false, 'code' => 'ADSTERRA_RESPONSE_TOO_LARGE'];
    }
    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    } catch (JsonException $error) {
        return ['ok' => false, 'code' => 'ADSTERRA_RESPONSE_INVALID_JSON'];
    }
    if (!is_array($decoded)) {
        return ['ok' => false, 'code' => 'ADSTERRA_RESPONSE_INVALID'];
    }
    if ((array_key_exists('success', $decoded) && $decoded['success'] === false)
        || (!empty($decoded['error']) && !is_array($decoded['error']))) {
        return ['ok' => false, 'code' => 'ADSTERRA_RESPONSE_ERROR'];
    }

    $rows = znews_adsterra_rows($decoded);
    if (count($rows) > 1000) {
        return ['ok' => false, 'code' => 'ADSTERRA_RESPONSE_TOO_LARGE'];
    }

    $normalized = [];
    $totalRevenue = 0;
    $totalImpressions = 0;
    $totalClicks = 0;
    foreach ($rows as $row) {
        $revenue = null;
        foreach (['revenue', 'profit', 'earnings', 'income'] as $field) {
            if (array_key_exists($field, $row)) {
                $revenue = znews_adsterra_decimal_micros($row[$field]);
                break;
            }
        }
        $impressions = znews_adsterra_positive_int($row['impressions'] ?? $row['impression'] ?? 0);
        $clicks = znews_adsterra_positive_int($row['clicks'] ?? $row['click'] ?? 0);
        if ($revenue === null || $impressions === null || $clicks === null) {
            return ['ok' => false, 'code' => 'ADSTERRA_RESPONSE_METRIC_INVALID'];
        }
        if ($totalRevenue > PHP_INT_MAX - $revenue) {
            return ['ok' => false, 'code' => 'ADSTERRA_REVENUE_OVERFLOW'];
        }
        if ($totalImpressions > PHP_INT_MAX - $impressions || $totalClicks > PHP_INT_MAX - $clicks) {
            return ['ok' => false, 'code' => 'ADSTERRA_TRAFFIC_OVERFLOW'];
        }
        if ($totalRevenue + $revenue > znews_adsterra_max_amount_micros()) {
            return ['ok' => false, 'code' => 'ADSTERRA_REVENUE_LIMIT_EXCEEDED'];
        }

        $date = znews_adsterra_scalar($row['date'] ?? $row['day'] ?? '', 10);
        $rowDomain = znews_adsterra_scalar($row['domain_id'] ?? $row['domain'] ?? $domainId, 120);
        $placement = znews_adsterra_scalar($row['placement_id'] ?? $row['placement'] ?? '', 120);
        $ctr = znews_adsterra_decimal_metric($row['ctr'] ?? '');
        $cpm = znews_adsterra_decimal_metric($row['cpm'] ?? $row['ecpm'] ?? '');
        if ($date === null || ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1)
            || $rowDomain === null || $placement === null || $ctr === null || $cpm === null) {
            return ['ok' => false, 'code' => 'ADSTERRA_RESPONSE_DIMENSION_INVALID'];
        }
        $normalized[] = [
            'date' => $date,
            'domain' => $rowDomain,
            'placement' => $placement,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $ctr,
            'cpm' => $cpm,
            'revenue_usd_micros' => $revenue,
            'revenue_usd' => znews_adsterra_decimal($revenue),
        ];
        $totalRevenue += $revenue;
        $totalImpressions += $impressions;
        $totalClicks += $clicks;
    }

    return [
        'ok' => true,
        'provider' => znews_adsterra_provider(),
        'currency' => 'USD',
        'domain_id' => $domainId,
        'row_count' => count($normalized),
        'impressions' => $totalImpressions,
        'clicks' => $totalClicks,
        'revenue_usd_micros' => $totalRevenue,
        'revenue_usd' => znews_adsterra_decimal($totalRevenue),
        'rows' => $normalized,
    ];
}

function znews_adsterra_fetch_stats(
    string $startDate,
    string $finishDate,
    bool $allowCache = true,
    ?callable $transport = null
): array {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $startDate) !== 1
        || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $finishDate) !== 1
        || $finishDate < $startDate) {
        return ['ok' => false, 'code' => 'ADSTERRA_DATE_RANGE_INVALID'];
    }
    $token = znews_adsterra_token();
    $domainId = znews_adsterra_domain_id();
    if ($token === '' || $domainId === '') {
        return ['ok' => false, 'code' => 'ADSTERRA_PRIVATE_CONFIG_MISSING', 'http_status' => 503];
    }

    $now = time();
    $cachePath = znews_adsterra_cache_path($startDate, $finishDate, $domainId);
    if ($allowCache) {
        $cached = znews_adsterra_cache_read($cachePath, $now);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $query = http_build_query([
        'domain' => $domainId,
        'start_date' => $startDate,
        'finish_date' => $finishDate,
    ], '', '&', PHP_QUERY_RFC3986);
    foreach (['date', 'domain', 'placement'] as $group) {
        $query .= '&group_by%5B%5D=' . rawurlencode($group);
    }
    $url = 'https://api3.adsterratools.com/publisher/stats.json?' . $query;
    $headers = ['Accept: application/json', 'X-API-Key: ' . $token];
    $send = $transport ?? 'znews_adsterra_http_get';
    $last = [];
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $last = $send($url, $headers, znews_adsterra_timeout_seconds());
        $status = (int)($last['status'] ?? 0);
        if (!empty($last['ok']) && $status >= 200 && $status < 300) {
            $result = znews_adsterra_normalize_report((string)($last['body'] ?? ''), $domainId);
            if (empty($result['ok'])) {
                return $result + ['http_status' => 502];
            }
            $result['start_date'] = $startDate;
            $result['finish_date'] = $finishDate;
            $result['reported_at'] = $now;
            $result['cache_hit'] = false;
            if ($allowCache) {
                znews_adsterra_cache_write($cachePath, $result, $now);
            }
            return $result;
        }
        if ($attempt === 1 && ($status === 0 || $status === 429 || $status >= 500)) {
            usleep(150000);
            continue;
        }
        break;
    }

    if (function_exists('system_log')) {
        system_log('ZNEWS_ADSTERRA_SYNC_FAILED', 'ADSTERRA', 'Adsterra publisher statistics request failed', [
            'http_status' => (int)($last['status'] ?? 0),
            'date_range' => $startDate . ':' . $finishDate,
        ]);
    }
    return [
        'ok' => false,
        'code' => 'ADSTERRA_UNAVAILABLE',
        'http_status' => 503,
        'provider_http_status' => (int)($last['status'] ?? 0),
    ];
}
