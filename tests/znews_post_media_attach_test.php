<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function znews_attach_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_attach_read(string $path): string
{
    $source = file_get_contents($path);
    znews_attach_expect($source !== false, "unable to read {$path}");
    return (string)$source;
}

$files = [
    'api/znews/lib/post_media_attach.php',
    'api/znews/lib/post_media_common.php',
    'api/znews/lib/post_media_claims.php',
    'api/znews/lib/post_media_create.php',
    'api/znews/lib/post_media_update.php',
    'api/znews/lib/instant_publish.php',
    'api/znews/lib/media_policy.php',
    'api/znews/lib/moderation_media.php',
    'api/znews/posts/create.php',
    'api/znews/posts/update.php',
    'api/znews/posts/details.php',
    'api/admin/znews/media.php',
    'api/admin/znews/details.php',
    'api/admin/znews/approve.php',
    'api/admin/znews/reject.php',
    'api/znews/public/media.php',
];

foreach ($files as $relative) {
    $source = znews_attach_read($root . '/' . $relative);
    znews_attach_expect(str_contains($source, 'declare(strict_types=1);'), "{$relative} missing strict types");
    znews_attach_expect(
        !str_contains($source, 'lib/wallet.php')
        && !str_contains($source, 'USER_WALLETS/')
        && !str_contains($source, 'WALLET_LEDGER/'),
        "{$relative} touches wallet business logic"
    );
}

$attach = znews_attach_read($root . '/api/znews/lib/post_media_attach.php')
    . znews_attach_read($root . '/api/znews/lib/post_media_common.php')
    . znews_attach_read($root . '/api/znews/lib/post_media_claims.php')
    . znews_attach_read($root . '/api/znews/lib/post_media_create.php')
    . znews_attach_read($root . '/api/znews/lib/post_media_update.php')
    . znews_attach_read($root . '/api/znews/lib/instant_publish.php');
znews_attach_expect(str_contains($attach, 'znews_post_create_claim_v2'), 'lease-aware create idempotency missing');
znews_attach_expect(str_contains($attach, 'legacyFreshUntil'), 'legacy stuck create claim recovery missing');
znews_attach_expect(str_contains($attach, 'ZNEWS_POST_CONTENT_REQUIRED'), 'image-only/text-only validation missing');
znews_attach_expect(str_contains($attach, "'IMAGE' : 'TEXT_IMAGE'"), 'content type selection missing');
znews_attach_expect(str_contains($attach, "status'] = 'ATTACHING'"), 'media reservation state missing');
znews_attach_expect(str_contains($attach, 'fb_put_if_match'), 'media attachment lacks optimistic concurrency');
znews_attach_expect(str_contains($attach, 'hash_equals($ownerUid, $uid)'), 'media ownership check is not timing-safe');
znews_attach_expect(str_contains($attach, "status'] = 'ATTACHED'"), 'final media attachment state missing');
znews_attach_expect(str_contains($attach, "status'] = 'DETACHED'"), 'replaced media detachment state missing');
znews_attach_expect(str_contains($attach, 'ZNEWS_MEDIA_NOT_AVAILABLE'), 'single-use media guard missing');
znews_attach_expect(str_contains($attach, 'image_media_id'), 'post-media reference missing');
znews_attach_expect(str_contains($attach, 'znews_public_feed_index_updates_for_post($updated)'), 'edited post publication index is not policy-driven or cache-preserving');
znews_attach_expect(str_contains($attach, 'znews_post_publication_decision'), 'instant publication decision is missing');
znews_attach_expect(str_contains($attach, 'AUTOMATED_RISK_REVIEW'), 'near-duplicate safety fallback is missing');
znews_attach_expect(str_contains($attach, 'expectedUpdatedAt'), 'version protection missing');
znews_attach_expect(str_contains($attach, 'znews_mutation_claim'), 'update idempotency missing');
znews_attach_expect(str_contains($attach, 'reconciliation_required'), 'partial failure reconciliation state missing');
znews_attach_expect(str_contains($attach, 'image_preview_url'), 'owner preview URL missing');
znews_attach_expect(str_contains($attach, 'image_review_url'), 'admin review URL missing');

$create = znews_attach_read($root . '/api/znews/posts/create.php');
znews_attach_expect(str_contains($create, 'znews_post_validate_content'), 'create does not accept image-only posts');
znews_attach_expect(str_contains($create, 'znews_create_post_with_media'), 'create is not media-aware');
znews_attach_expect(str_contains($create, 'published_immediately'), 'create response lacks publication state');
znews_attach_expect(str_contains($create, 'api_require_app_key();'), 'create lacks app key protection');

$update = znews_attach_read($root . '/api/znews/posts/update.php');
znews_attach_expect(str_contains($update, "array_key_exists('text'"), 'update cannot preserve omitted text');
znews_attach_expect(str_contains($update, "array_key_exists('media_id'"), 'update cannot distinguish preserve/remove media');
znews_attach_expect(str_contains($update, 'znews_update_post_with_media'), 'update is not media-aware');
znews_attach_expect(str_contains($update, 'expected_updated_at'), 'update lacks version input');
znews_attach_expect(str_contains($update, 'published_immediately'), 'update response lacks publication state');

$policy = znews_attach_read($root . '/api/znews/lib/media_policy.php');
znews_attach_expect(str_contains($policy, "moderationStatus !== 'APPROVED'"), 'public media lacks approval gate');
znews_attach_expect(str_contains($policy, "'AUTO_CLEARED'"), 'instant-published media verdict is not allowlisted');
znews_attach_expect(str_contains($policy, 'znews_media_public_approved_verdicts'), 'public media lacks copyright verdict allowlist');
znews_attach_expect(str_contains($policy, 'fb_put_if_match'), 'media moderation sync lacks concurrency protection');

$moderationMedia = znews_attach_read($root . '/api/znews/lib/moderation_media.php');
znews_attach_expect(str_contains($moderationMedia, 'znews_admin_moderate_post_with_media'), 'post and media moderation wrapper missing');
znews_attach_expect(str_contains($moderationMedia, 'post_moderation_saved'), 'media reconciliation evidence missing');

$publicMedia = znews_attach_read($root . '/api/znews/public/media.php');
znews_attach_expect(str_contains($publicMedia, 'znews_media_public_record_strict'), 'public endpoint bypasses strict media policy');

$adminMedia = znews_attach_read($root . '/api/admin/znews/media.php');
znews_attach_expect(str_contains($adminMedia, 'auth_require_admin_session(true)'), 'admin media lacks admin protection');
znews_attach_expect(str_contains($adminMedia, 'znews_media_stream'), 'admin media does not use secure streamer');

echo "Z News post media attachment tests passed ({$assertions} assertions).\n";
