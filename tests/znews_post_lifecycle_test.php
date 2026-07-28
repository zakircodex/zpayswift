<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function znews_lifecycle_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_lifecycle_read(string $path): string
{
    $source = file_get_contents($path);
    znews_lifecycle_expect($source !== false, "unable to read {$path}");
    return (string)$source;
}

$files = [
    'api/znews/lib/post_access.php',
    'api/znews/lib/post_mutations.php',
    'api/znews/posts/details.php',
    'api/znews/posts/mine.php',
    'api/znews/posts/update.php',
    'api/znews/posts/delete.php',
    'api/znews/public/post.php',
    'api/znews/public/feed.php',
];

foreach ($files as $relative) {
    $path = $root . '/' . $relative;
    znews_lifecycle_expect(is_file($path), "missing {$relative}");
    $source = znews_lifecycle_read($path);
    znews_lifecycle_expect(
        str_contains($source, "declare(strict_types=1);"),
        "{$relative} does not enable strict types"
    );
    znews_lifecycle_expect(
        !str_contains($source, "lib/wallet.php")
        && !str_contains($source, "USER_WALLETS/")
        && !str_contains($source, "WALLET_LEDGER/"),
        "{$relative} touches existing wallet business logic"
    );
}

$access = znews_lifecycle_read($root . '/api/znews/lib/post_access.php');
znews_lifecycle_expect(
    str_contains($access, "ZNEWS_PUBLIC_FEED/")
    && str_contains($access, "znews_post_is_public")
    && str_contains($access, "=== 'ACTIVE'")
    && str_contains($access, "=== 'PUBLIC'"),
    'public feed is not restricted to ACTIVE posts'
);
znews_lifecycle_expect(
    str_contains($access, "hash_equals(\$creatorUid, \$uid)"),
    'post ownership is not verified securely'
);
znews_lifecycle_expect(
    str_contains($access, 'znews_cursor_encode')
    && str_contains($access, 'znews_cursor_decode'),
    'cursor pagination helpers are missing'
);

$mutations = znews_lifecycle_read($root . '/api/znews/lib/post_mutations.php');
znews_lifecycle_expect(
    str_contains($mutations, 'fb_put_if_match')
    && str_contains($mutations, 'ZNEWS_POST_VERSION_CONFLICT'),
    'post mutations do not use optimistic concurrency'
);
znews_lifecycle_expect(
    str_contains($mutations, "status'] = 'REVIEW'")
    && str_contains($mutations, "moderation_status'] = 'PENDING'")
    && str_contains($mutations, "copyright_status'] = 'PENDING'"),
    'edited posts do not return to moderation review'
);
znews_lifecycle_expect(
    str_contains($mutations, "status'] = 'DELETED'")
    && str_contains($mutations, "deleted_at"),
    'delete is not implemented as a soft delete'
);
znews_lifecycle_expect(
    str_contains($mutations, "znews_path_public_feed(\$postId) => null"),
    'edited/deleted posts are not removed from the public feed index'
);
znews_lifecycle_expect(
    str_contains($mutations, 'ZNEWS_MUTATION_IDEMPOTENCY/')
    && str_contains($mutations, 'lease_expires_at'),
    'mutation idempotency or stale-claim recovery is missing'
);

foreach (['details.php', 'mine.php', 'update.php', 'delete.php'] as $name) {
    $source = znews_lifecycle_read($root . '/api/znews/posts/' . $name);
    znews_lifecycle_expect(
        str_contains($source, 'api_require_app_key();')
        && str_contains($source, 'znews_require_creator(true)'),
        "{$name} is not protected by app key and authenticated creator checks"
    );
}

foreach (['post.php', 'feed.php'] as $name) {
    $source = znews_lifecycle_read($root . '/api/znews/public/' . $name);
    znews_lifecycle_expect(
        str_contains($source, "api_require_method('GET');"),
        "public {$name} is not GET-only"
    );
    znews_lifecycle_expect(
        !str_contains($source, 'api_require_app_key();')
        && !str_contains($source, 'auth_require_user('),
        "public {$name} incorrectly requires a Z-Pay account"
    );
}

echo "Z News post lifecycle tests passed ({$assertions} assertions).\n";
