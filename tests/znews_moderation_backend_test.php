<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function znews_moderation_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_moderation_read(string $path): string
{
    $source = file_get_contents($path);
    znews_moderation_expect($source !== false, "unable to read {$path}");
    return (string)$source;
}

$files = [
    'api/znews/lib/moderation.php',
    'api/admin/znews/queue.php',
    'api/admin/znews/details.php',
    'api/admin/znews/approve.php',
    'api/admin/znews/reject.php',
];

foreach ($files as $relative) {
    $source = znews_moderation_read($root . '/' . $relative);
    znews_moderation_expect(str_contains($source, 'declare(strict_types=1);'), "{$relative} missing strict types");
    znews_moderation_expect(
        !str_contains($source, "lib/wallet.php")
        && !str_contains($source, 'USER_WALLETS/')
        && !str_contains($source, 'WALLET_LEDGER/'),
        "{$relative} touches wallet business logic"
    );
}

$moderation = znews_moderation_read($root . '/api/znews/lib/moderation.php');
znews_moderation_expect(str_contains($moderation, 'auth_require_admin_session') === false, 'library should not start auth side effects');
znews_moderation_expect(str_contains($moderation, 'fb_put_if_match'), 'moderation does not use optimistic concurrency');
znews_moderation_expect(str_contains($moderation, 'ZNEWS_ADMIN_IDEMPOTENCY/'), 'admin idempotency is missing');
znews_moderation_expect(str_contains($moderation, 'lease_expires_at'), 'admin action lease is missing');
znews_moderation_expect(str_contains($moderation, "status'] = $approve ? 'ACTIVE' : 'BLOCKED'"), 'approve/reject status transition is missing');
znews_moderation_expect(str_contains($moderation, 'ZNEWS_COPYRIGHT_CHECKS/'), 'copyright audit records are missing');
znews_moderation_expect(str_contains($moderation, 'ZNEWS_MODERATION_ACTIONS/'), 'moderation audit records are missing');
znews_moderation_expect(str_contains($moderation, 'znews_path_public_feed($postId) => $publicIndex'), 'public feed activation/removal is missing');
znews_moderation_expect(str_contains($moderation, "'method' => 'ADMIN_MANUAL_REVIEW'"), 'copyright method is not explicit');
znews_moderation_expect(str_contains($moderation, "status !== 'REVIEW'"), 'moderation is not limited to pending review');

foreach (['queue.php', 'details.php', 'approve.php', 'reject.php'] as $name) {
    $source = znews_moderation_read($root . '/api/admin/znews/' . $name);
    znews_moderation_expect(str_contains($source, 'auth_require_admin_session(true)'), "{$name} lacks admin session protection");
}

foreach (['approve.php', 'reject.php'] as $name) {
    $source = znews_moderation_read($root . '/api/admin/znews/' . $name);
    znews_moderation_expect(str_contains($source, "api_require_method('POST')"), "{$name} is not POST-only");
    znews_moderation_expect(str_contains($source, 'znews_idempotency_key'), "{$name} lacks idempotency");
    znews_moderation_expect(str_contains($source, 'expected_updated_at'), "{$name} lacks version protection");
}

foreach (['queue.php', 'details.php'] as $name) {
    $source = znews_moderation_read($root . '/api/admin/znews/' . $name);
    znews_moderation_expect(str_contains($source, "api_require_method('GET')"), "{$name} is not GET-only");
}

echo "Z News moderation backend tests passed ({$assertions} assertions).\n";
