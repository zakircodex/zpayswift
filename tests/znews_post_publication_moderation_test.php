<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function block_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function block_read(string $relative): string
{
    global $root;
    $path = $root . '/' . $relative;
    block_expect(is_file($path), "missing file: {$relative}");
    $source = file_get_contents($path);
    block_expect(is_string($source), "unreadable file: {$relative}");
    return (string)$source;
}

$service = block_read('api/znews/lib/post_publication_moderation.php');
$wrapper = block_read('api/znews/lib/moderation_media.php');
$rejectEndpoint = block_read('api/admin/znews/reject.php');

foreach ([$service, $wrapper, $rejectEndpoint] as $source) {
    block_expect(str_contains($source, 'declare(strict_types=1);'), 'strict types missing');
    block_expect(
        !str_contains($source, 'USER_WALLETS/')
        && !str_contains($source, 'wallet_credit_available(')
        && !str_contains($source, 'WALLET_LEDGER/'),
        'post-publication moderation touches wallet logic'
    );
}

block_expect(str_contains($service, 'znews_admin_action_claim'), 'admin idempotency claim is missing');
block_expect(str_contains($service, 'znews_admin_replay'), 'exact replay support is missing');
block_expect(str_contains($service, 'fb_get_with_etag'), 'post block lacks ETag read');
block_expect(str_contains($service, 'fb_put_if_match'), 'post block lacks optimistic write');
block_expect(str_contains($service, "status'] = 'BLOCKED'"), 'terminal BLOCKED state is missing');
block_expect(str_contains($service, "moderation_status'] = 'REJECTED'"), 'REJECTED moderation state is missing');
block_expect(str_contains($service, 'znews_path_public_feed($postId) => null'), 'blocked post is not removed from public feed');
block_expect(str_contains($service, 'POST_PUBLICATION_BLOCK'), 'post-publication audit mode is missing');
block_expect(str_contains($service, 'znews_path_moderation_action'), 'moderation audit row is missing');
block_expect(str_contains($service, 'znews_path_copyright_check'), 'copyright audit row is missing');
block_expect(str_contains($service, 'znews_admin_action_finish'), 'moderation claim finalization is missing');
block_expect(str_contains($service, 'reconciliation_required'), 'partial index failure is not surfaced');

block_expect(str_contains($wrapper, "in_array(\$beforeStatus, ['ACTIVE', 'BLOCKED'], true)"), 'live/blocked reject routing is missing');
block_expect(str_contains($wrapper, 'znews_admin_block_published_post'), 'wrapper does not call post-publication block service');
block_expect(str_contains($wrapper, 'znews_media_apply_moderation'), 'image moderation is not synchronized');
block_expect(str_contains($rejectEndpoint, "'REJECT'"), 'admin reject endpoint action changed unexpectedly');
block_expect(str_contains($rejectEndpoint, 'auth_require_admin_session(true)'), 'admin reject endpoint lacks admin authentication');

echo "PASS: {$assertions} post-publication moderation assertions.\n";
