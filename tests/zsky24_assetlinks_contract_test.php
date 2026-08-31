<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assetLinksPath = $root . '/.well-known/assetlinks.json';
$rewritePath = $root . '/.htaccess';
$expectedFingerprint = '52:4C:8C:47:60:48:E8:3F:57:73:0C:A6:E1:82:0B:F2:BE:02:1B:22:54:F4:F0:0D:2B:73:C0:8F:97:26:3F:C9';

function app_links_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

app_links_expect(is_file($assetLinksPath), 'assetlinks.json is missing.');

$assetLinks = json_decode((string) file_get_contents($assetLinksPath), true, 16, JSON_THROW_ON_ERROR);
app_links_expect(is_array($assetLinks) && count($assetLinks) === 1, 'assetlinks must contain one app statement.');

$statement = $assetLinks[0] ?? [];
$target = is_array($statement['target'] ?? null) ? $statement['target'] : [];
app_links_expect(($statement['relation'] ?? null) === ['delegate_permission/common.handle_all_urls'], 'App Link relation changed.');
app_links_expect(($target['namespace'] ?? '') === 'android_app', 'App Link namespace changed.');
app_links_expect(($target['package_name'] ?? '') === 'com.zpayswift.app', 'Android package changed.');
app_links_expect(($target['sha256_cert_fingerprints'] ?? null) === [$expectedFingerprint], 'Production signing fingerprint changed or includes an unintended certificate.');

$rewrite = (string) file_get_contents($rewritePath);
$exactAllow = 'RewriteRule ^\.well-known/assetlinks\.json$ - [L,NC]';
$catchAllDeny = 'RewriteRule ^(?!znews(?:/|$)|api/znews(?:/|$)|assets/brand/';
$allowPosition = strpos($rewrite, $exactAllow);
$denyPosition = strpos($rewrite, $catchAllDeny);

app_links_expect(str_contains($rewrite, 'Options -Indexes'), 'Directory listing protection is missing.');
app_links_expect($allowPosition !== false, 'Exact assetlinks allow rule is missing.');
app_links_expect($denyPosition !== false && $allowPosition < $denyPosition, 'Assetlinks allow rule must run before the Z Sky catch-all deny.');
app_links_expect(!str_contains($rewrite, 'RewriteRule ^\.well-known/ -'), 'The entire .well-known directory must not be broadly allowed.');
app_links_expect(str_contains($rewrite, 'Header always set Content-Type "application/json; charset=utf-8"'), 'Asset Links JSON content type is not enforced.');
app_links_expect(str_contains($rewrite, 'Header always set Cache-Control "public, max-age=3600, must-revalidate"'), 'Asset Links cache policy is missing.');

fwrite(STDOUT, "Z Sky 24 Asset Links contract passed.\n");
