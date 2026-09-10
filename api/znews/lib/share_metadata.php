<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_share_meta_text($value, int $maximum): string
{
    $text = trim(strip_tags((string)$value));
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return '';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length <= $maximum) {
        return $text;
    }
    $short = function_exists('mb_substr')
        ? mb_substr($text, 0, max(1, $maximum - 1), 'UTF-8')
        : substr($text, 0, max(1, $maximum - 1));
    return rtrim((string)$short) . '...';
}

function znews_share_meta_image(array $post): string
{
    $fallback = 'https://zsky24.com/assets/brand/zpay-icon.png';
    $raw = trim((string)($post['image_url'] ?? ''));
    if ($raw === '') {
        return $fallback;
    }
    if (str_starts_with($raw, '/')) {
        return 'https://zsky24.com' . $raw;
    }

    $parts = parse_url($raw);
    $host = strtolower((string)($parts['host'] ?? ''));
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== 'https' || !in_array($host, ['zsky24.com', 'www.zsky24.com', 'zpayswift.com'], true)) {
        return $fallback;
    }
    return $raw;
}

function znews_share_replace_meta(string $html, string $attribute, string $name, string $content): string
{
    $replacement = '<meta ' . $attribute . '="' . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        . '" content="' . htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
    $pattern = '~<meta\s+' . preg_quote($attribute, '~') . '="' . preg_quote($name, '~')
        . '"\s+content="[^"]*"\s*/?>~i';
    $updated = preg_replace_callback($pattern, static fn(): string => $replacement, $html, 1, $count);
    if (!is_string($updated)) {
        return $html;
    }
    if ($count === 0) {
        return str_replace('</head>', '  ' . $replacement . "\n</head>", $updated);
    }
    return $updated;
}

function znews_share_render_document(string $template, array $post, string $postId): string
{
    $headline = znews_share_meta_text($post['title'] ?? '', 90);
    $creator = znews_share_meta_text($post['creator_name'] ?? '', 60);
    $title = $headline !== '' ? $headline . ' | Z Sky 24' : 'Z Sky 24 post';
    $description = znews_share_meta_text($post['text'] ?? '', 190);
    if ($description === '') {
        $description = $headline !== ''
            ? $headline
            : ($creator !== '' ? 'A post by ' . $creator . ' on Z Sky 24.' : 'News, stories and community updates.');
    }
    $url = 'https://zsky24.com/post/' . rawurlencode($postId);
    $image = znews_share_meta_image($post);

    $html = znews_share_replace_meta($template, 'name', 'description', $description);
    $html = znews_share_replace_meta($html, 'property', 'og:title', $title);
    $html = znews_share_replace_meta($html, 'property', 'og:description', $description);
    $html = znews_share_replace_meta($html, 'property', 'og:type', 'article');
    $html = znews_share_replace_meta($html, 'property', 'og:url', $url);
    $html = znews_share_replace_meta($html, 'property', 'og:image', $image);
    $html = znews_share_replace_meta($html, 'name', 'twitter:card', 'summary_large_image');
    $html = znews_share_replace_meta($html, 'name', 'twitter:title', $title);
    $html = znews_share_replace_meta($html, 'name', 'twitter:description', $description);
    $html = znews_share_replace_meta($html, 'name', 'twitter:image', $image);
    $html = preg_replace_callback(
        '~<link\s+rel="canonical"\s+href="[^"]*"\s*/?>~i',
        static fn(): string => '<link rel="canonical" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">',
        $html,
        1
    ) ?? $html;
    return preg_replace_callback(
        '~<title>.*?</title>~is',
        static fn(): string => '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</title>',
        $html,
        1
    ) ?? $html;
}
