<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function device_expect(bool $condition, string $message): void
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

require_once $root . '/api/znews/lib/device_specs.php';

$details = znews_validate_device_details('MOBILE_PRICING', 'laptop', [
    ['label' => 'RAM', 'value' => '  16 GB  '],
    ['label' => 'Storage / ROM', 'value' => '1 TB'],
], true);
device_expect($details['device_type'] === 'LAPTOP', 'Device type was not normalized.');
device_expect($details['device_specs'] === [
    ['label' => 'RAM', 'value' => '16 GB'],
    ['label' => 'Storage / ROM', 'value' => '1 TB'],
], 'Device specifications were not normalized in order.');

$notDevice = znews_validate_device_details('SPORTS', 'MOBILE', [
    ['label' => 'RAM', 'value' => '12 GB'],
], true);
device_expect($notDevice === ['device_type' => '', 'device_specs' => []], 'Non-device posts retained device fields.');

$rejected = false;
try {
    znews_validate_device_details('MOBILE_PRICING', '', [], true);
} catch (RuntimeException $error) {
    $rejected = str_contains($error->getMessage(), 'ZNEWS_DEVICE_DETAILS_REQUIRED');
}
device_expect($rejected, 'An explicitly submitted empty device profile was accepted.');

$legacy = znews_validate_device_details('MOBILE_PRICING', '', [], false);
device_expect($legacy === ['device_type' => '', 'device_specs' => []], 'Legacy device post compatibility changed.');

$formatted = znews_device_details_from_post([
    'category' => 'MOBILE_PRICING',
    'device_type' => 'camera',
    'device_specs' => ['Camera' => '24 MP', 'Empty' => ''],
]);
device_expect($formatted === [
    'device_type' => 'CAMERA',
    'device_specs' => [['label' => 'Camera', 'value' => '24 MP']],
], 'Stored device details were not projected safely.');

$web = (string)file_get_contents($root . '/znews/assets/znews-device-specs.js');
foreach (['BD_NEWS', 'INTERNATIONAL_NEWS', 'HEALTH', 'SPORTS', 'ISLAMIC', 'JOKES'] as $category) {
    device_expect(str_contains($web, "['{$category}'"), "Web catalog is missing {$category}.");
}
device_expect(str_contains($web, "const DEVICE_CATEGORY = 'MOBILE_PRICING'"), 'Web catalog is missing MOBILE_PRICING.');
device_expect(str_contains($web, 'Device Specs & Pricing'), 'Premium device category label is missing on Web.');

echo "PASS: {$assertions} Z Sky device-spec assertions.\n";
