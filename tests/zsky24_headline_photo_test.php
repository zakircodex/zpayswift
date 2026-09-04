<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function headline_source(string $relative): string
{
    $contents = file_get_contents(dirname(__DIR__) . '/' . $relative);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$relative}");
    }
    return $contents;
}

function headline_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$index = headline_source('znews/index.html');
$app = headline_source('znews/assets/znews.js');
$apiClient = headline_source('znews/assets/znews-api.js');
$creator = headline_source('znews/assets/znews-creator.js');
$profile = headline_source('znews/assets/znews-profile.js');
$premium = headline_source('znews/assets/znews-premium.css');
$createEndpoint = headline_source('api/znews/posts/create.php');
$updateEndpoint = headline_source('api/znews/posts/update.php');
$createService = headline_source('api/znews/lib/post_media_create.php');
$updateService = headline_source('api/znews/lib/post_media_update.php');
$common = headline_source('api/znews/lib/post_media_common.php');
$posts = headline_source('api/znews/lib/posts.php');

headline_expect(str_contains($index, 'id="postTitle"'), 'Headline input is missing.');
headline_expect(str_contains($index, 'maxlength="160"'), 'Headline length limit is missing.');
headline_expect(str_contains($index, 'placeholder="Add a clear headline" required'), 'Web headline must be required.');
headline_expect(str_contains($index, 'composer-title-field'), 'Headline and body are not visually separated.');
headline_expect(str_contains($index, 'composer-body-field'), 'Post details field is missing.');
headline_expect(str_contains($index, '>Add photo</strong>'), 'Photo-only action label is missing.');
headline_expect(!str_contains($index, 'Photos/videos'), 'Video wording remains in the composer.');
headline_expect(!str_contains($index, 'Public <span aria-hidden="true">⌄</span>'), 'Inactive Public dropdown indicator remains.');
headline_expect(!str_contains($index, ' multiple'), 'Composer must allow only one selected photo.');
headline_expect(str_contains($index, 'accept="image/jpeg,image/png,image/webp"'), 'Image MIME allowlist is missing.');

headline_expect(str_contains($common, 'function znews_post_validate_title'), 'Backend headline validation is missing.');
headline_expect(str_contains($common, "'ZNEWS_POST_TITLE_TOO_LONG'"), 'Headline overflow error is missing.');
headline_expect(str_contains($createEndpoint, "\$body['title'] ?? ''"), 'Create API is not backward-compatible with title-less clients.');
headline_expect(str_contains($updateEndpoint, "array_key_exists('title', \$body)"), 'Update API title-presence handling is missing.');
headline_expect(str_contains($createService, "'title' => \$title"), 'Headline is not stored on create.');
headline_expect(str_contains($updateService, "\$updated['title'] = \$targetTitle"), 'Headline is not stored on update.');
headline_expect(str_contains($posts, "'title' => trim((string)(\$post['title'] ?? ''))"), 'Existing title-less posts are not safely formatted.');
headline_expect(str_contains($createService, 'trim($title . "\\n" . $text)'), 'Headline is not included in create moderation.');
headline_expect(str_contains($updateService, 'trim($targetTitle . "\\n" . $text)'), 'Headline is not included in update moderation.');

headline_expect(str_contains($apiClient, "createPost({ title = '', text = '', boldRanges = [], formattingRuns = [], mediaId = '', category = '' })"), 'Web API client does not send headlines/categories/formatting.');
headline_expect(
    str_contains($app, 'title: postTitle')
    && str_contains($app, 'text: postText')
    && str_contains($app, 'boldRanges: parsedText.boldRanges')
    && str_contains($app, 'formattingRuns: parsedText.formattingRuns')
    && str_contains($app, 'mediaId')
    && str_contains($app, 'category'),
    'Canonical Create request does not send the headline/category/formatting.'
);
headline_expect(str_contains($creator, 'id="creatorEditTitle"'), 'Creator edit headline input is missing.');
headline_expect(str_contains($app, "['image/jpeg', 'image/png', 'image/webp'].includes(file.type)"), 'Create photo MIME validation is missing.');
headline_expect(str_contains($creator, "['image/jpeg', 'image/png', 'image/webp'].includes(replacement.type)"), 'Edit photo MIME validation is missing.');
headline_expect(str_contains($app, 'class="post-title"'), 'Feed and reader headline rendering is missing.');
headline_expect(str_contains($profile, 'class="profile-post-open post-title"'), 'Creator profile headline rendering is missing.');

headline_expect(str_contains($premium, '.composer-writing-fields{display:grid;gap:12px'), 'Headline/body field separation styles are missing.');
headline_expect(str_contains($premium, '.composer-card .image-preview{position:relative;width:min(calc(100% - 32px),420px);margin:'), 'Responsive Create preview is missing.');
headline_expect(str_contains($premium, '.composer-card .image-preview img{display:block;width:100%;height:auto;object-fit:contain}'), 'Selected photo must preserve its real aspect ratio.');
headline_expect(str_contains($premium, '.composer-card .image-preview{height:auto}'), 'Mobile preview must not force a fixed media height.');
headline_expect(str_contains($premium, '.post-media-frame .post-media{position:relative;z-index:1;width:100%;height:auto;max-height:none;object-fit:contain'), 'Published photos must remain full-width and uncropped.');
headline_expect(str_contains($premium, '.composer-topbar{position:fixed'), 'Mobile composer header must remain fixed.');
headline_expect(str_contains($premium, '.composer-bottom-action{position:fixed'), 'Mobile Post action must remain fixed.');
headline_expect(str_contains($premium, '.composer-bottom-submit{min-height:52px;border-radius:17px'), 'Mobile Post button polish is missing.');
headline_expect(str_contains($premium, '.composer-add-row{border-radius:18px'), 'Add photo control polish is missing.');
headline_expect(str_contains($app, "behavior: next === 'create' ? 'auto' : 'smooth'"), 'Create route must align immediately without a smooth-scroll gap.');
headline_expect(str_contains($app, "backdrop.className = 'composer-image-backdrop'"), 'Composer preview backdrop is missing.');
headline_expect(str_contains($app, 'feed-media-frame media-pending${mediaFrameClass}'), 'Aspect-ratio-aware feed photo frame is missing.');
headline_expect(str_contains($profile, 'post-media-button post-media-frame'), 'Creator profile photo frame is missing.');

fwrite(STDOUT, "Z Sky 24 headline and single-photo audit passed.\n");
