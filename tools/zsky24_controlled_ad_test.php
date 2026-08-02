<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This utility is CLI-only.\n");
    exit(2);
}

function fail(string $message, int $code = 2): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit($code);
}

function option(array $options, string $name, string $default = ''): string
{
    $value = $options[$name] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function clean_key(string $value, string $field): string
{
    if ($value === '' || strlen($value) > 200 || preg_match('/[.#$\[\]\/\x00-\x1F\x7F]/', $value) === 1) {
        fail("Invalid {$field}.");
    }
    return $value;
}

function hidden_secret(): string
{
    $configured = trim((string)getenv('ZNEWS_AD_TEST_SECRET'));
    if ($configured !== '') return $configured;

    fwrite(STDOUT, 'Test ingestion secret (hidden): ');
    $canHide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec');
    if ($canHide) @shell_exec('stty -echo 2>/dev/null');
    try {
        $line = fgets(STDIN);
    } finally {
        if ($canHide) @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }
    return trim(is_string($line ?? null) ? $line : '');
}

function usage(): void
{
    echo <<<'TXT'
Z Sky 24 controlled signed ad-impression test

Required: --post-id=ID --view-id=ID --confirm-live-test
Optional: --base-url=https://zpayswift.com --network=INMOBI_TEST
          --revenue-micros=20000 --ad-unit-id=zsky24-controlled-test

The secret comes from a hidden prompt or ZNEWS_AD_TEST_SECRET. It is never printed.
This creates an impression only; it never settles creator credit.
TXT;
}

$options = getopt('', [
    'help', 'base-url:', 'network:', 'post-id:', 'view-id:', 'revenue-micros:',
    'ad-unit-id:', 'provider-reference:', 'confirm-live-test',
]);
if (isset($options['help'])) { usage(); exit(0); }
if (!isset($options['confirm-live-test'])) { usage(); fail('Refusing to send without --confirm-live-test.'); }
if (!function_exists('curl_init')) fail('PHP cURL extension is required.');

$baseUrl = rtrim(option($options, 'base-url', 'https://zpayswift.com'), '/');
if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower($baseUrl), 'https://')) {
    fail('base-url must be a valid HTTPS URL.');
}
$network = strtoupper(option($options, 'network', 'INMOBI_TEST'));
if (preg_match('/^[A-Z0-9_]{2,40}$/', $network) !== 1) fail('Invalid network.');
$postId = clean_key(option($options, 'post-id'), 'post-id');
$viewId = clean_key(option($options, 'view-id'), 'view-id');
$adUnitId = option($options, 'ad-unit-id', 'zsky24-controlled-test');
if ($adUnitId === '' || strlen($adUnitId) > 160 || preg_match('/[\x00-\x1F\x7F]/', $adUnitId) === 1) fail('Invalid ad-unit-id.');
$providerReference = option($options, 'provider-reference', 'CONTROLLED_TEST_ONLY');
if (strlen($providerReference) > 160 || preg_match('/[\x00-\x1F\x7F]/', $providerReference) === 1) fail('Invalid provider-reference.');
$revenueMicros = filter_var(option($options, 'revenue-micros', '20000'), FILTER_VALIDATE_INT);
if ($revenueMicros === false || $revenueMicros < 10000 || $revenueMicros > 30000) {
    fail('revenue-micros must be between 10000 and 30000 (BDT 0.01-0.03).');
}
$secret = hidden_secret();
if (strlen($secret) < 32) fail('The configured test secret must contain at least 32 characters.');

$timestamp = time();
$eventId = 'controlled-' . gmdate('YmdHis', $timestamp) . '-' . bin2hex(random_bytes(6));
$nonce = bin2hex(random_bytes(16));
$payload = [
    'event_type' => 'IMPRESSION', 'network' => $network, 'event_id' => $eventId,
    'view_id' => $viewId, 'post_id' => $postId, 'ad_unit_id' => $adUnitId,
    'currency' => 'BDT', 'revenue_micros' => (int)$revenueMicros,
    'occurred_at' => $timestamp, 'provider_reference' => $providerReference,
];
$raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$canonical = $network . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $raw);
$signature = hash_hmac('sha256', $canonical, $secret);
$secret = '';

$endpoint = $baseUrl . '/api/znews/ads/impressions/ingest.php';
$curl = curl_init($endpoint);
if ($curl === false) fail('Unable to initialise cURL.');
curl_setopt_array($curl, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json', 'Content-Type: application/json',
        'X-ZNEWS-AD-NETWORK: ' . $network, 'X-ZNEWS-AD-TIMESTAMP: ' . $timestamp,
        'X-ZNEWS-AD-NONCE: ' . $nonce, 'X-ZNEWS-AD-SIGNATURE: ' . $signature,
    ],
]);
$response = curl_exec($curl);
$httpStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$curlError = curl_error($curl);
curl_close($curl);
if (!is_string($response)) fail('Request failed: ' . ($curlError !== '' ? $curlError : 'unknown cURL error'), 1);

$decoded = json_decode($response, true);
echo "HTTP {$httpStatus}\nevent_id: {$eventId}\n";
echo is_array($decoded)
    ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
    : substr($response, 0, 2000) . PHP_EOL;
if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($decoded) || empty($decoded['ok'])) exit(1);
exit(0);
