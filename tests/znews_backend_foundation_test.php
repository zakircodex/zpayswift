<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function znews_test_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;

    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_test_read(string $path): string
{
    znews_test_expect(is_file($path), 'missing file: ' . $path);
    $source = file_get_contents($path);
    znews_test_expect($source !== false, 'unable to read: ' . $path);

    return (string)$source;
}

$moduleBootstrap = znews_test_read($root . '/api/znews/bootstrap.php');
$common = znews_test_read($root . '/api/znews/lib/common.php');
$posts = znews_test_read($root . '/api/znews/lib/posts.php');
$create = znews_test_read($root . '/api/znews/posts/create.php');

znews_test_expect(
    str_contains($moduleBootstrap, "require_once dirname(__DIR__) . '/bootstrap.php';"),
    'Z News bootstrap does not reuse the shared API bootstrap'
);
znews_test_expect(
    str_contains($moduleBootstrap, "require_once __DIR__ . '/lib/common.php';"),
    'Z News common helpers are not loaded centrally'
);
znews_test_expect(
    str_contains($moduleBootstrap, "header('X-Content-Type-Options: nosniff');"),
    'Z News bootstrap does not set nosniff protection'
);

foreach (['USER_WALLETS', 'WALLET_LEDGER', 'wallet_credit_available', 'wallet_debit_available'] as $forbidden) {
    znews_test_expect(
        !str_contains($moduleBootstrap . $common . $posts . $create, $forbidden),
        'Z News foundation unexpectedly references existing wallet business logic: ' . $forbidden
    );
}

znews_test_expect(
    str_contains($create, "api_require_method('POST');")
    && str_contains($create, 'api_require_app_key();')
    && str_contains($create, 'znews_require_creator(true);'),
    'Post creation does not enforce method, app key and authenticated creator checks'
);
znews_test_expect(
    !str_contains($create, "['creator_uid']")
    && !str_contains($create, "['uid']"),
    'Post creation appears to trust a client-supplied creator identifier'
);
znews_test_expect(
    str_contains($create, 'znews_idempotency_key(')
    && str_contains($posts, 'fb_get_with_etag(')
    && str_contains($posts, 'fb_put_if_match('),
    'Post creation does not enforce the idempotency claim flow'
);
znews_test_expect(
    str_contains($posts, "'status' => 'REVIEW'")
    && str_contains($posts, "'moderation_status' => 'PENDING'")
    && str_contains($posts, "'copyright_status' => 'PENDING'"),
    'New posts are not held for moderation and copyright review'
);
znews_test_expect(
    str_contains($posts, "fb_patch('', \$updates)"),
    'Post, user index and idempotency completion are not written as one root update'
);
znews_test_expect(
    str_contains($common, "return 'ZNEWS_POSTS/'")
    && str_contains($common, "return 'ZNEWS_USER_POSTS/'")
    && str_contains($common, "return 'ZNEWS_IDEMPOTENCY/'"),
    'Z News Firebase namespaces are not isolated'
);

echo "Z News backend foundation tests passed ({$assertions} assertions).\n";
