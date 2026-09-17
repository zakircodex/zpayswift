<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assetLinksPath = $root . '/.well-known/assetlinks.json';
$rewritePath = $root . '/.htaccess';
$cpanelDeploymentPath = $root . '/.cpanel.yml';
$deploymentExcludePath = $root . '/.cpanel-deploy-exclude';
$pushWorkflowPath = $root . '/.github/workflows/cpanel-production-deploy.yml';
$buildScriptPath = $root . '/scripts/build_public_deployment.sh';
$expectedFingerprint = '34:BD:DD:99:05:1F:70:9A:4E:66:05:39:DF:A3:A7:AC:28:97:F1:60:CB:49:05:D9:73:13:58:EA:B8:C8:C7:80';

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

$cpanelDeployment = (string) file_get_contents($cpanelDeploymentPath);
$deploymentExclude = (string) file_get_contents($deploymentExcludePath);
$pushWorkflow = (string) file_get_contents($pushWorkflowPath);
$buildScript = (string) file_get_contents($buildScriptPath);
app_links_expect(
    str_contains($cpanelDeployment, 'build_public_deployment.sh')
        && preg_match('/for path in [^;]*\.well-known/', $buildScript) === 1,
    'cPanel Git deployment must publish the .well-known directory.'
);
app_links_expect(
    !preg_match('/^\.well-known\/?$/m', $deploymentExclude),
    'Deployment excludes must not remove the .well-known directory.'
);
app_links_expect(
    str_contains($pushWorkflow, 'build_public_deployment.sh')
        && preg_match('/for path in [^;]*\.well-known/', $buildScript) === 1,
    'FTPS deployment must publish the .well-known directory.'
);
app_links_expect(
    str_contains($pushWorkflow, 'test -f deployment/.well-known/assetlinks.json'),
    'FTPS deployment must fail when assetlinks.json is missing from the package.'
);
app_links_expect(
    str_contains($buildScript, 'test -f "$SOURCE_ROOT/.well-known/assetlinks.json"')
        && str_contains($buildScript, 'test -f "$TARGET_ROOT/.well-known/assetlinks.json"'),
    'cPanel Git deployment must fail when assetlinks.json was not published.'
);
app_links_expect(
    str_contains($pushWorkflow, 'verify_assetlinks()')
        && substr_count($pushWorkflow, 'verify_assetlinks "$') === 2
        && str_contains($pushWorkflow, $expectedFingerprint),
    'FTPS deployment must verify the production fingerprint on every configured live host.'
);

fwrite(STDOUT, "Z Sky 24 Asset Links contract passed.\n");
