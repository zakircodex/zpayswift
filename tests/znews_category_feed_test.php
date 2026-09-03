<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function category_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function api_response(bool $success, string $code, string $message, array $data = [], int $status = 200): never
{
    throw new RuntimeException($code . ':' . $status . ':' . $message);
}

require_once $root . '/api/znews/lib/categories.php';

category_expect(znews_active_categories() === ['INTERNATIONAL_NEWS', 'BD_NEWS', 'MOBILE_PRICING'], 'Canonical category allowlist changed.');
category_expect(znews_normalize_category('bd_news', false) === 'BD_NEWS', 'Category normalization failed.');
category_expect(znews_normalize_category('', true) === '', 'Legacy empty category compatibility failed.');
category_expect(znews_category_created_at('MOBILE_PRICING', 42) === 'MOBILE_PRICING|000000000042', 'Composite category index value is unstable.');

foreach (['MICRO_JOB', 'SPORTS', '', 'BD NEWS'] as $invalid) {
    $rejected = false;
    try {
        znews_normalize_category($invalid, false);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'ZNEWS_POST_CATEGORY_INVALID');
    }
    category_expect($rejected, 'Invalid/create-disabled category was accepted: ' . ($invalid === '' ? '(empty)' : $invalid));
}

$index = file_get_contents($root . '/znews/index.html');
$app = file_get_contents($root . '/znews/assets/znews.js');
$api = file_get_contents($root . '/znews/assets/znews-api.js');
$feedEndpoint = file_get_contents($root . '/api/znews/public/feed.php');
$createEndpoint = file_get_contents($root . '/api/znews/posts/create.php');
$updateEndpoint = file_get_contents($root . '/api/znews/posts/update.php');
$projection = file_get_contents($root . '/api/znews/lib/public_projection.php');
$rules = json_decode((string)file_get_contents($root . '/database.rules.json'), true);

foreach (['News feed all', 'International news', 'BD news', 'Mobile pricing', 'Micro job', 'Coming soon'] as $label) {
    category_expect(str_contains((string)$index, $label), 'Category UI label is missing: ' . $label);
}
category_expect(str_contains((string)$app, "if (category === 'MICRO_JOB')"), 'Micro Job does not stay local/disabled.');
category_expect(str_contains((string)$api, 'params: { limit, cursor, category }'), 'Web feed does not send optional category.');
category_expect(str_contains((string)$feedEndpoint, "\$_GET['category'] ?? ''"), 'Feed API lacks optional category input.');
category_expect(str_contains((string)$createEndpoint, "array_key_exists('category', \$body)"), 'Create API lacks category compatibility/validation.');
category_expect(str_contains((string)$updateEndpoint, "array_key_exists('category', \$body)"), 'Edit API cannot preserve omitted legacy category.');
category_expect(str_contains((string)$projection, "'category_created_at'"), 'Public projection lacks category index field.');
category_expect(
    ($rules['rules']['ZNEWS_PUBLIC_FEED']['.indexOn'] ?? null) === ['created_at', 'category_created_at'],
    'Required category_created_at RTDB index is missing.'
);
category_expect(($rules['rules']['.read'] ?? null) === false && ($rules['rules']['.write'] ?? null) === false, 'RTDB root deny policy changed.');

echo "PASS: {$assertions} Z Sky category assertions.\n";

