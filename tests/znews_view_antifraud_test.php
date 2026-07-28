<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function znews_view_test_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_view_test_read(string $path): string
{
    znews_view_test_expect(is_file($path), 'missing file: ' . $path);
    $source = file_get_contents($path);
    znews_view_test_expect($source !== false, 'unable to read: ' . $path);
    return (string)$source;
}

$files = [
    'api/znews/lib/views.php',
    'api/znews/lib/views_v2.php',
    'api/znews/views/start.php',
    'api/znews/views/heartbeat.php',
    'api/znews/views/complete.php',
    'api/znews/posts/analytics.php',
    'api/admin/znews/views/risk_queue.php',
    'api/admin/znews/views/details.php',
];

foreach ($files as $relative) {
    $source = znews_view_test_read($root . '/' . $relative);
    znews_view_test_expect(str_contains($source, 'declare(strict_types=1);'), "{$relative} missing strict types");
    znews_view_test_expect(
        !str_contains($source, 'lib/wallet.php')
        && !str_contains($source, 'USER_WALLETS/')
        && !str_contains($source, 'WALLET_LEDGER/')
        && !str_contains($source, 'wallet_credit_available')
        && !str_contains($source, 'wallet_debit_available'),
        "{$relative} touches existing wallet business logic"
    );
}

$views = znews_view_test_read($root . '/api/znews/lib/views.php');
$v2 = znews_view_test_read($root . '/api/znews/lib/views_v2.php');
znews_view_test_expect(str_contains($views, 'security_client_ip'), 'safe client IP helper is not reused');
znews_view_test_expect(str_contains($views, 'security_ip_hash'), 'IP hashing is missing');
znews_view_test_expect(str_contains($views, "'httponly' => true"), 'visitor cookie is not HttpOnly');
znews_view_test_expect(str_contains($views, "'samesite' => 'Lax'"), 'visitor cookie SameSite protection missing');
znews_view_test_expect(str_contains($views, "'PUBLIC'") && str_contains($views, 'SIGNED_SESSION'), 'public and signed-session viewers are not separated');
znews_view_test_expect(str_contains($views, 'znews_view_is_bot'), 'bot detection missing');
znews_view_test_expect(str_contains($views, 'ZNEWS_VIEW_SESSIONS/'), 'view session namespace missing');
znews_view_test_expect(str_contains($views, 'ZNEWS_POST_VIEWS/'), 'post view index missing');
znews_view_test_expect(str_contains($views, 'ZNEWS_VIEW_DEDUP/'), 'view dedup namespace missing');
znews_view_test_expect(str_contains($views, 'ZNEWS_VIEW_RATE/'), 'view rate namespace missing');
znews_view_test_expect(str_contains($views, 'ZNEWS_VIEW_RISK/'), 'view risk namespace missing');
znews_view_test_expect(str_contains($views, 'ZNEWS_VIEW_RISK_QUEUE/'), 'risk review queue missing');
znews_view_test_expect(str_contains($views, 'ZNEWS_ANALYTICS/'), 'analytics namespace missing');
znews_view_test_expect(str_contains($views, 'ZNEWS_UNIQUE_VIEWERS/'), 'unique-viewer namespace missing');
znews_view_test_expect(str_contains($views, 'fb_get_with_etag') && str_contains($views, 'fb_put_if_match'), 'CAS protection missing');
znews_view_test_expect(str_contains($views, 'total_opens') && str_contains($views, 'valid_views') && str_contains($views, 'invalid_views'), 'core analytics counters missing');
znews_view_test_expect(str_contains($views, 'suspicious_views') && str_contains($views, 'unique_viewers') && str_contains($views, 'average_read_seconds'), 'risk or read analytics missing');
znews_view_test_expect(str_contains($views, 'MINUTE_RATE_EXCEEDED') && str_contains($views, 'HOURLY_RATE_EXCEEDED'), 'rate-limit risk states missing');
znews_view_test_expect(str_contains($views, 'DEDUPLICATE_WINDOW_MATCH'), 'duplicate-view risk state missing');
znews_view_test_expect(str_contains($views, 'HEARTBEAT_GAP_TOO_LARGE'), 'heartbeat gap risk state missing');
znews_view_test_expect(str_contains($views, 'READ_TIME_TOO_SHORT') && str_contains($views, 'ACTIVE_TIME_TOO_SHORT') && str_contains($views, 'HEARTBEAT_REQUIRED'), 'valid-view completion rules missing');
znews_view_test_expect(str_contains($views, "'VALID'") && str_contains($views, "'INVALID'") && str_contains($views, "'SUSPICIOUS'"), 'view result states missing');
znews_view_test_expect(str_contains($views, "'earning_eligible' => false"), 'view completion can incorrectly credit earnings');
znews_view_test_expect(!str_contains($views, "['duration']") && !str_contains($views, "['read_seconds']"), 'client duration appears to be trusted');
znews_view_test_expect(str_contains($views, "'token_hash' => hash('sha256'"), 'view token is not hashed at rest');
znews_view_test_expect(str_contains($views, 'hash_equals($stored, hash'), 'view token comparison is not timing-safe');
znews_view_test_expect(str_contains($views, 'reconciliation_required'), 'partial failure reconciliation evidence missing');
znews_view_test_expect(str_contains($views, "unset(\$row['token_hash'], \$row['session_hash'], \$row['visitor_hash'])"), 'admin details leak sensitive hashes');

znews_view_test_expect(str_contains($v2, 'znews_view_analytics_apply_once'), 'exact-once analytics helper missing');
znews_view_test_expect(str_contains($v2, "'applied_events'"), 'analytics event ledger missing');
znews_view_test_expect(str_contains($v2, "'OPEN|' . \$viewId") && str_contains($v2, "'COMPLETE|' . \$viewId"), 'open/complete event keys missing');
znews_view_test_expect(str_contains($v2, 'znews_view_open_sync'), 'view-start reconciliation helper missing');
znews_view_test_expect(str_contains($v2, 'znews_view_complete_sync'), 'view-completion reconciliation helper missing');
znews_view_test_expect(
    str_contains($v2, "\$deltas['invalid_views'] = 1")
    && str_contains($v2, "\$deltas['suspicious_views'] = 1"),
    'blocked starts are not counted as invalid/suspicious'
);
znews_view_test_expect(str_contains($v2, 'znews_view_unique_owner'), 'retry-safe unique-view ownership missing');
znews_view_test_expect(str_contains($v2, 'znews_view_start_v2') && str_contains($v2, 'znews_view_complete_v2'), 'exact-once start/complete flows missing');

foreach (['start.php', 'heartbeat.php', 'complete.php'] as $name) {
    $source = znews_view_test_read($root . '/api/znews/views/' . $name);
    znews_view_test_expect(str_contains($source, "api_require_method('POST')"), "{$name} is not POST-only");
    znews_view_test_expect(!str_contains($source, 'api_require_app_key();'), "{$name} incorrectly requires app key for public web reading");
}

$start = znews_view_test_read($root . '/api/znews/views/start.php');
$heartbeat = znews_view_test_read($root . '/api/znews/views/heartbeat.php');
$complete = znews_view_test_read($root . '/api/znews/views/complete.php');
znews_view_test_expect(str_contains($start, 'views_v2.php') && str_contains($start, 'znews_view_start_v2'), 'start endpoint bypasses exact-once flow');
znews_view_test_expect(str_contains($heartbeat, 'X-ZNEWS-VIEW-TOKEN') && str_contains($heartbeat, 'view_token'), 'heartbeat view-token protection missing');
znews_view_test_expect(str_contains($complete, 'X-ZNEWS-VIEW-TOKEN') && str_contains($complete, 'view_token'), 'completion view-token protection missing');
znews_view_test_expect(str_contains($complete, 'views_v2.php') && str_contains($complete, 'znews_view_complete_v2'), 'complete endpoint bypasses exact-once flow');

$analytics = znews_view_test_read($root . '/api/znews/posts/analytics.php');
znews_view_test_expect(str_contains($analytics, 'api_require_app_key();') && str_contains($analytics, 'znews_require_creator(true)'), 'creator analytics lacks authentication');
znews_view_test_expect(str_contains($analytics, 'znews_post_owner_snapshot'), 'creator analytics lacks ownership validation');

foreach (['risk_queue.php', 'details.php'] as $name) {
    $source = znews_view_test_read($root . '/api/admin/znews/views/' . $name);
    znews_view_test_expect(str_contains($source, 'auth_require_admin_session(true)'), "{$name} lacks admin session protection");
    znews_view_test_expect(str_contains($source, "api_require_method('GET')"), "{$name} is not GET-only");
}

echo "Z News view anti-fraud tests passed ({$assertions} assertions).\n";
