<?php
declare(strict_types=1);

function fb_encode_path(string $path): string
{
    $path = trim($path, '/');
    if ($path === '') {
        return '';
    }

    $parts = explode('/', $path);
    $parts = array_map(static fn($p) => rawurlencode($p), $parts);

    return implode('/', $parts);
}

function fb_build_url(string $path, array $query = []): string
{
    $encodedPath = fb_encode_path($path);
    $base = FIREBASE_DB_URL . ($encodedPath === '' ? '' : '/' . $encodedPath) . '.json';

    if (FIREBASE_AUTH !== '') {
        $query['auth'] = FIREBASE_AUTH;
    }

    if (!empty($query)) {
        $base .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $base;
}

function fb_request(
    string $method,
    string $path,
    mixed $data = null,
    array $query = [],
    array $headers = [],
    bool $includeHeaders = false
): array {
    $ch = curl_init();

    $finalHeaders = ['Accept: application/json'];

    if ($data !== null) {
        $finalHeaders[] = 'Content-Type: application/json';
    }

    foreach ($headers as $header) {
        $finalHeaders[] = $header;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => fb_build_url($path, $query),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $finalHeaders,
        CURLOPT_HEADER => $includeHeaders,
    ]);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    curl_close($ch);

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => 0,
            'headers' => [],
            'body' => null,
            'json' => null,
            'error' => $curlErr ?: 'Unknown cURL error',
        ];
    }

    $rawHeaders = '';
    $body = $raw;

    if ($includeHeaders) {
        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
    }

    $parsedHeaders = [];
    if ($rawHeaders !== '') {
        $lines = preg_split("/\r\n|\n|\r/", trim($rawHeaders));
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsedHeaders[strtolower(trim($k))] = trim($v);
            }
        }
    }

    $decoded = null;
    if ($body !== '' && $body !== 'null') {
        $decoded = json_decode($body, true);
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'headers' => $parsedHeaders,
        'body' => $body,
        'json' => $decoded,
        'error' => null,
    ];
}

function fb_get(string $path, array $query = []): mixed
{
    $res = fb_request('GET', $path, null, $query);
    if (!$res['ok']) {
        return null;
    }

    return $res['json'];
}

function fb_put(string $path, mixed $data): bool
{
    $res = fb_request('PUT', $path, $data);
    return $res['ok'];
}

function fb_patch(string $path, array $data): bool
{
    $res = fb_request('PATCH', $path, $data);
    return $res['ok'];
}

function fb_delete(string $path): bool
{
    $res = fb_request('DELETE', $path);
    return $res['ok'];
}

function fb_get_with_etag(string $path): array
{
    $res = fb_request('GET', $path, null, [], ['X-Firebase-ETag: true'], true);

    return [
        'ok' => $res['ok'],
        'status' => $res['status'],
        'etag' => $res['headers']['etag'] ?? null,
        'value' => $res['json'],
        'error' => $res['error'],
    ];
}

function fb_put_if_match(string $path, mixed $data, string $etag): array
{
    return fb_request('PUT', $path, $data, [], ['If-Match: ' . $etag], true);
}