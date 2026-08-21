<?php
declare(strict_types=1);

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);

$retiredPaths = [
    'z-builder',
    'z-builder-worker',
    'my-site',
    'api/my_site',
    '.github/workflows/z-builder-worker-build.yml',
];

foreach ($retiredPaths as $path) {
    assert_true(!file_exists($root . '/' . $path), "retired Builder path must be absent: {$path}");
}

$requiredMainPaths = [
    'api/user/index.php',
    'api/admin/dashboard.php',
    'api/subadmin/dashboard.php',
    'api/worker/claim.php',
    'api/worker/heartbeat.php',
    'api/worker/result.php',
    'api/worker/active.php',
    'api/worker/start_dial.php',
    'api/lib/worker.php',
];

foreach ($requiredMainPaths as $path) {
    assert_true(is_file($root . '/' . $path), "main application contract must remain present: {$path}");
}

$deployExclude = (string)file_get_contents($root . '/.cpanel-deploy-exclude');
foreach (['z-builder/', 'z-builder-worker/', 'my-site/'] as $deadEntry) {
    assert_true(!str_contains($deployExclude, $deadEntry), "deploy excludes must not retain dead entry: {$deadEntry}");
}

$activeRouteFiles = [
    '.htaccess',
    'api/endpoints.php',
    'api/admin/dashboard.php',
    'api/subadmin/dashboard.php',
    'api/user/index.php',
];
$deadRouteMarkers = ['/z-builder/', '/my-site/', '/api/my_site/', 'z-builder-worker-build.yml'];

foreach ($activeRouteFiles as $path) {
    $contents = (string)file_get_contents($root . '/' . $path);
    foreach ($deadRouteMarkers as $marker) {
        assert_true(!str_contains($contents, $marker), "active route file must not reference retired Builder route: {$path}");
    }
}

$rootRewrite = (string)file_get_contents($root . '/.htaccess');
assert_true(
    str_contains($rootRewrite, 'RewriteRule ^(?:z-builder|z-builder-worker|my-site)(?:/|$) - [G,L,NC]'),
    'retired Builder frontends must return HTTP 410 even if stale deployment files remain'
);
assert_true(
    str_contains($rootRewrite, 'RewriteRule ^api/my_site(?:/|$) - [G,L,NC]'),
    'retired Builder APIs must return HTTP 410 even if stale deployment files remain'
);

$workerLibrary = (string)file_get_contents($root . '/api/lib/worker.php');
assert_true(str_contains($workerLibrary, 'function worker_claim_request('), 'main Worker claim contract must remain intact');
assert_true(str_contains($workerLibrary, 'function worker_request_is_z_builder('), 'legacy Builder rows must remain isolated from the main Worker queue');

$endpointDocs = (string)file_get_contents($root . '/api/endpoints.php');
foreach (['worker.heartbeat', 'worker.claim', 'worker.result'] as $workerEndpoint) {
    assert_true(str_contains($endpointDocs, $workerEndpoint), "main Worker endpoint registration must remain intact: {$workerEndpoint}");
}

echo "Z Builder retirement tests passed\n";
