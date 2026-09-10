<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function share_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require_once $root . '/api/znews/lib/share_metadata.php';

$template = (string)file_get_contents($root . '/znews/index.html');
$post = [
    'title' => 'A <new> phone',
    'text' => 'Full review & camera test',
    'creator_name' => 'Creator',
    'image_url' => '/api/znews/public/media.php?media_id=MEDIA_1',
];
$document = znews_share_render_document($template, $post, 'POST_1');
share_expect(str_contains($document, 'property="og:type" content="article"'), 'Shared post is not marked as an article.');
share_expect(str_contains($document, 'property="og:image" content="https://zsky24.com/api/znews/public/media.php?media_id=MEDIA_1"'), 'Shared post image is not absolute.');
share_expect(str_contains($document, 'property="og:title" content="A phone | Z Sky 24"'), 'Shared post title is not safely rendered.');
share_expect(str_contains($document, 'name="twitter:card" content="summary_large_image"'), 'Large Twitter preview metadata is missing.');
share_expect(str_contains($document, 'rel="canonical" href="https://zsky24.com/post/POST_1"'), 'Canonical post URL is incorrect.');

$fallback = znews_share_render_document($template, ['title' => 'Text only'], 'POST_2');
share_expect(str_contains($fallback, 'property="og:image" content="https://zsky24.com/assets/brand/zpay-icon.png"'), 'Text-only post does not retain the logo fallback.');

$rootRules = (string)file_get_contents($root . '/.htaccess');
$nestedRules = (string)file_get_contents($root . '/znews/.htaccess');
share_expect(str_contains($rootRules, '/znews/post.php?post_id=$1'), 'Canonical host post route is not server rendered.');
share_expect(str_contains($nestedRules, 'post.php?post_id=$1'), 'Nested post route is not server rendered.');

echo "PASS: {$assertions} Z Sky share metadata assertions.\n";
