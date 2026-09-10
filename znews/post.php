<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/znews/bootstrap.php';
require_once dirname(__DIR__) . '/api/znews/lib/posts.php';
require_once dirname(__DIR__) . '/api/znews/lib/post_access.php';
require_once dirname(__DIR__) . '/api/znews/lib/share_metadata.php';

$template = file_get_contents(__DIR__ . '/index.html');
if (!is_string($template)) {
    http_response_code(500);
    exit('Z Sky 24 is temporarily unavailable.');
}

$postId = trim((string)($_GET['post_id'] ?? ''));
$post = null;
if (preg_match('/^[A-Za-z0-9_-]{1,160}$/', $postId) === 1) {
    try {
        $post = znews_public_post_by_id($postId);
    } catch (Throwable $error) {
        $post = null;
    }
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=600');
header('Vary: Accept-Encoding');

echo is_array($post)
    ? znews_share_render_document($template, $post, $postId)
    : $template;
