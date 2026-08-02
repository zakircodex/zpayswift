<?php
declare(strict_types=1);

$source = (string)@file_get_contents(dirname(__DIR__) . '/tools/zsky24_controlled_ad_test.php');
$assertions = 0;
$expect = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$expect($source !== '', 'controlled test utility missing');
$expect(str_contains($source, "PHP_SAPI !== 'cli'"), 'utility is not CLI-only');
$expect(str_contains($source, "'confirm-live-test'"), 'explicit confirmation missing');
$expect(str_contains($source, "'https://'"), 'HTTPS enforcement missing');
$expect(str_contains($source, 'CURLOPT_SSL_VERIFYPEER => true'), 'TLS peer verification missing');
$expect(str_contains($source, 'CURLOPT_SSL_VERIFYHOST => 2'), 'TLS host verification missing');
$expect(str_contains($source, "hash_hmac('sha256'"), 'HMAC-SHA256 signing missing');
$expect(str_contains($source, "hash('sha256', \$raw)"), 'payload hash missing');
$expect(str_contains($source, 'random_bytes(16)'), 'strong nonce missing');
$expect(str_contains($source, 'ZNEWS_AD_TEST_SECRET'), 'secret input missing');
$expect(!str_contains($source, 'echo $secret'), 'secret may be printed');
$expect(str_contains($source, '$revenueMicros < 10000') && str_contains($source, '$revenueMicros > 30000'), 'BDT 0.01-0.03 guard missing');
$expect(str_contains($source, '/api/znews/ads/impressions/ingest.php'), 'ingestion endpoint missing');
$expect(!str_contains($source, '/settle.php'), 'utility can settle credit');
echo "PASS: Z Sky 24 controlled ad test utility ({$assertions} assertions).\n";
