<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';

function user_page_config(array $overrides): array
{
    return array_merge([
        'key' => 'dashboard',
        'title' => 'Z-Pay Swift',
        'section_id' => 'overviewSection',
        'body_class' => 'user-dashboard-page',
        'page_css' => 'dashboard-page.css',
        'page_js' => 'dashboard-page.js',
        'active_nav' => 'dashboard',
        'show_header' => true,
        'show_drawer' => true,
        'show_bottom_nav' => true,
        'back_url' => '/user/dashboard',
        'bootstrap_action' => 'me',
        'bootstrap_params' => [],
    ], $overrides);
}

function user_page_asset_version(string $relativePath): string
{
    $path = dirname(__DIR__) . '/assets/' . ltrim($relativePath, '/');
    $mtime = is_file($path) ? filemtime($path) : false;
    return (string)($mtime ?: 1);
}

function user_page_begin(array $config): void
{
    $userPage = user_page_config($config);
    user_page_require_auth();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    require __DIR__ . '/head.php';
    require __DIR__ . '/drawer.php';
    require __DIR__ . '/header.php';
}

function user_page_end(array $config): void
{
    $userPage = user_page_config($config);
    require __DIR__ . '/bottom-nav.php';
    require __DIR__ . '/common-scripts.php';
}
