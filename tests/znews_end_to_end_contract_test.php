<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function znews_e2e_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_e2e_read(string $root, string $relative): string
{
    $path = $root . '/' . $relative;
    znews_e2e_expect(is_file($path), 'missing file: ' . $relative);
    $source = file_get_contents($path);
    znews_e2e_expect($source !== false, 'unreadable file: ' . $relative);
    return (string)$source;
}

function znews_e2e_php_files(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

$moduleFiles = array_merge(
    znews_e2e_php_files($root . '/api/znews'),
    znews_e2e_php_files($root . '/api/admin/znews')
);
znews_e2e_expect(count($moduleFiles) >= 80, 'unexpectedly small Z News PHP inventory');

foreach ($moduleFiles as $path) {
    $relative = str_replace($root . '/', '', $path);
    $source = (string)file_get_contents($path);
    znews_e2e_expect(str_contains($source, 'declare(strict_types=1);'), $relative . ' missing strict types');
    znews_e2e_expect(
        preg_match('/\b(eval|exec|shell_exec|passthru|proc_open|popen)\s*\(/i', $source) !== 1,
        $relative . ' contains a forbidden process/code execution primitive'
    );
    znews_e2e_expect(
        preg_match('/fb_(put|patch|delete)\s*\([^\n;]*USER_WALLETS\//i', $source) !== 1,
        $relative . ' directly mutates USER_WALLETS instead of using wallet helpers'
    );
}

foreach (znews_e2e_php_files($root . '/api/znews/lib') as $path) {
    $relative = str_replace($root . '/', '', $path);
    $source = (string)file_get_contents($path);
    znews_e2e_expect(
        str_contains($source, "basename(__FILE__) === basename(\$_SERVER['SCRIPT_FILENAME'] ?? '')"),
        $relative . ' lacks direct-execution guard'
    );
}

foreach (znews_e2e_php_files($root . '/api/admin/znews') as $path) {
    $relative = str_replace($root . '/', '', $path);
    $source = (string)file_get_contents($path);
    znews_e2e_expect(str_contains($source, 'api_require_method('), $relative . ' lacks method guard');
    znews_e2e_expect(str_contains($source, 'auth_require_admin_session(true)'), $relative . ' lacks admin-session protection');
}

$creatorEndpoints = [
    'api/znews/posts/create.php',
    'api/znews/posts/update.php',
    'api/znews/posts/delete.php',
    'api/znews/posts/details.php',
    'api/znews/posts/mine.php',
    'api/znews/media/upload.php',
    'api/znews/media/content.php',
    'api/znews/likes/set.php',
    'api/znews/likes/status.php',
    'api/znews/shares/create.php',
    'api/znews/comments/create.php',
    'api/znews/comments/update.php',
    'api/znews/comments/delete.php',
    'api/znews/engagement/summary.php',
    'api/znews/posts/analytics.php',
    'api/znews/posts/ad_analytics.php',
    'api/znews/balance/summary.php',
    'api/znews/balance/ledger.php',
    'api/znews/transfers/preview.php',
    'api/znews/transfers/create.php',
    'api/znews/transfers/list.php',
    'api/znews/transfers/details.php',
];
foreach ($creatorEndpoints as $relative) {
    $source = znews_e2e_read($root, $relative);
    znews_e2e_expect(str_contains($source, 'api_require_method('), $relative . ' lacks method guard');
    znews_e2e_expect(str_contains($source, 'api_require_app_key();'), $relative . ' lacks app-key protection');
    znews_e2e_expect(preg_match('/znews_require_creator\s*\(/', $source) === 1, $relative . ' lacks creator authentication');
}

$publicEndpoints = [
    'api/znews/public/feed.php',
    'api/znews/public/post.php',
    'api/znews/public/media.php',
    'api/znews/comments/list.php',
    'api/znews/views/start.php',
    'api/znews/views/heartbeat.php',
    'api/znews/views/complete.php',
];
foreach ($publicEndpoints as $relative) {
    $source = znews_e2e_read($root, $relative);
    znews_e2e_expect(str_contains($source, 'api_require_method('), $relative . ' lacks method guard');
    znews_e2e_expect(!str_contains($source, 'auth_require_admin_session('), $relative . ' incorrectly requires admin');
    znews_e2e_expect(!str_contains($source, 'znews_require_creator('), $relative . ' incorrectly requires creator login');
}

$bootstrap = znews_e2e_read($root, 'api/znews/bootstrap.php');
znews_e2e_expect(str_contains($bootstrap, "dirname(__DIR__) . '/bootstrap.php'"), 'shared API bootstrap is not loaded');
znews_e2e_expect(str_contains($bootstrap, 'X-Content-Type-Options: nosniff'), 'nosniff header missing');

$postCreate = znews_e2e_read($root, 'api/znews/lib/post_media_create.php');
znews_e2e_expect(str_contains($postCreate, "'REVIEW'"), 'new posts do not enter review');
znews_e2e_expect(str_contains($postCreate, "'PENDING'"), 'new posts do not enter pending moderation');

$moderation = znews_e2e_read($root, 'api/znews/lib/moderation.php');
znews_e2e_expect(str_contains($moderation, 'znews_admin_moderate_post'), 'post moderation service missing');
znews_e2e_expect(str_contains($moderation, "'ACTIVE'") && str_contains($moderation, "'BLOCKED'"), 'moderation terminal post states missing');
znews_e2e_expect(str_contains($moderation, 'znews_path_public_feed('), 'moderation does not maintain public-feed index');

$publicAccess = znews_e2e_read($root, 'api/znews/lib/post_access.php');
znews_e2e_expect(str_contains($publicAccess, "=== 'ACTIVE'"), 'public post gate lacks ACTIVE check');
znews_e2e_expect(str_contains($publicAccess, "=== 'PUBLIC'"), 'public post gate lacks PUBLIC visibility check');

$viewsV2 = znews_e2e_read($root, 'api/znews/lib/views_v2.php');
znews_e2e_expect(str_contains($viewsV2, 'znews_view_analytics_apply_once'), 'view analytics exact-once helper missing');
znews_e2e_expect(str_contains($viewsV2, 'applied_events'), 'view analytics event ledger missing');
znews_e2e_expect(str_contains($viewsV2, 'znews_view_start_v2') && str_contains($viewsV2, 'znews_view_complete_v2'), 'view v2 lifecycle missing');

$adSignature = znews_e2e_read($root, 'api/znews/lib/ad_impressions_signature.php');
znews_e2e_expect(str_contains($adSignature, 'hash_hmac'), 'ad ingestion lacks HMAC verification');
znews_e2e_expect(str_contains($adSignature, 'hash_equals'), 'ad signature comparison is not timing-safe');
$adCommon = znews_e2e_read($root, 'api/znews/lib/ad_impressions_common.php');
znews_e2e_expect(str_contains($adCommon, 'NOT_SETTLED'), 'ad impressions can bypass settlement state');
$adIngest = znews_e2e_read($root, 'api/znews/lib/ad_impressions_ingest.php');
znews_e2e_expect(str_contains($adIngest, "'credit_status' => 'NOT_CREDITED'"), 'ad impressions can bypass credit state');
znews_e2e_expect(str_contains($adIngest, "'earning_eligible' => false"), 'ad impressions can directly enable earnings');

$settlementCommon = znews_e2e_read($root, 'api/znews/lib/settlements_common.php');
znews_e2e_expect(str_contains($settlementCommon, 'return 5000;'), 'creator revenue share is not 50 percent');
znews_e2e_expect(str_contains($settlementCommon, '$platform = $grossMicros - $creator'), 'settlement rounding is not lossless');
$settlementService = znews_e2e_read($root, 'api/znews/lib/settlements_service.php');
znews_e2e_expect(str_contains($settlementService, 'SETTLED'), 'settlement terminal state missing');
znews_e2e_expect(str_contains($settlementService, 'NOT_CREDITED'), 'settlement directly credits main wallet');

$transferCommon = znews_e2e_read($root, 'api/znews/lib/transfers_common.php');
znews_e2e_expect(str_contains($transferCommon, '500 * 1000000'), 'BDT 500 transfer threshold missing');
$transferWallet = znews_e2e_read($root, 'api/znews/lib/transfers_wallet.php');
znews_e2e_expect(str_contains($transferWallet, 'wallet_financial_operation_begin('), 'wallet financial-operation claim missing');
znews_e2e_expect(str_contains($transferWallet, 'wallet_credit_available('), 'official wallet credit helper missing');
znews_e2e_expect(str_contains($transferWallet, 'wallet_financial_operation_mark_completed('), 'wallet completion marker missing');
$transferAdmin = znews_e2e_read($root, 'api/znews/lib/transfers_admin_approve.php');
znews_e2e_expect(str_contains($transferAdmin, 'znews_transfer_consume_balance('), 'approved transfer does not consume reserved balance');

$tests = glob($root . '/tests/znews_*_test.php') ?: [];
znews_e2e_expect(count($tests) >= 10, 'expected Z News regression suites are missing');

echo "Z News end-to-end contract audit passed ({$assertions} assertions, "
    . count($moduleFiles) . " PHP files, " . count($tests) . " test suites).\n";
