<?php
declare(strict_types=1);

$baseUrl = rtrim(trim((string)getenv('ZNEWS_SMOKE_BASE_URL')), '/');
if ($baseUrl === '') {
    echo "Z News HTTP smoke test skipped: ZNEWS_SMOKE_BASE_URL is not configured.\n";
    exit(0);
}

function znews_smoke_request(string $url, array $headers = []): array
{
    $headers[] = 'Accept: application/json';
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', (string)$responseHeaders[0], $matches) === 1) {
        $status = (int)$matches[1];
    }

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'headers' => $responseHeaders,
    ];
}

function znews_smoke_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_smoke_json(array $response, string $label): array
{
    znews_smoke_expect($response['status'] >= 200 && $response['status'] < 300, $label . ' returned HTTP ' . $response['status']);
    $decoded = json_decode((string)$response['body'], true);
    znews_smoke_expect(is_array($decoded), $label . ' did not return JSON');
    znews_smoke_expect(!empty($decoded['ok']) || !empty($decoded['success']), $label . ' returned a failed API envelope');
    return $decoded;
}

$feed = znews_smoke_json(
    znews_smoke_request($baseUrl . '/api/znews/public/feed.php?limit=1'),
    'public feed'
);
znews_smoke_expect(isset($feed['data']) && is_array($feed['data']), 'public feed data is missing');

$postId = trim((string)getenv('ZNEWS_SMOKE_PUBLIC_POST_ID'));
if ($postId !== '') {
    $post = znews_smoke_json(
        znews_smoke_request($baseUrl . '/api/znews/public/post.php?post_id=' . rawurlencode($postId)),
        'public post'
    );
    znews_smoke_expect(isset($post['data']['post']) && is_array($post['data']['post']), 'public post payload is missing');
}

$appKey = trim((string)getenv('ZNEWS_SMOKE_APP_KEY'));
$sessionToken = trim((string)getenv('ZNEWS_SMOKE_SESSION_TOKEN'));
if ($appKey !== '' && $sessionToken !== '') {
    $balance = znews_smoke_json(
        znews_smoke_request($baseUrl . '/api/znews/balance/summary.php', [
            'X-APP-KEY: ' . $appKey,
            'X-SESSION-TOKEN: ' . $sessionToken,
        ]),
        'creator balance summary'
    );
    znews_smoke_expect(isset($balance['data']['balances']) && is_array($balance['data']['balances']), 'creator balance list is missing');
}

echo "Z News read-only HTTP smoke test passed.\n";
