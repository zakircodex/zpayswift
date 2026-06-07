<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

/*
 * Z-Pay Swift deployment path helper.
 *
 * Goal:
 * - Keep the same code working at domain root, with legacy /zpayswift or /zawtopup compatibility.
 * - Avoid hardcoded legacy public URLs.
 * - Prefer the Z-Pay Swift private config path with old private path fallback.
 */

function app_paths_normalize_path(string $path): string
{
    $path = '/' . trim($path, '/');
    return $path === '/' ? '' : $path;
}

function app_base_path(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    $script = str_replace('\\', '/', $script);

    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $requestPath = parse_url($requestUri, PHP_URL_PATH);
    $requestPath = is_string($requestPath) ? str_replace('\\', '/', $requestPath) : '';

    if (defined('APP_BASE_PATH')) {
        $configured = app_paths_normalize_path((string)APP_BASE_PATH);
        if ($configured !== '') {
            $legacyConfigured = preg_match('#^/(zpayswift|zawtopup)$#i', $configured) === 1;
            $currentUsesConfigured = stripos($script . '/', $configured . '/') === 0
                || stripos($requestPath . '/', $configured . '/') === 0;

            if (!$legacyConfigured || $currentUsesConfigured) {
                return $configured;
            }
        }
    }

    if (preg_match('#^/(zpayswift|zawtopup)(/|$)#i', $script, $m)) {
        return '/' . $m[1];
    }

    if (preg_match('#^/(zpayswift|zawtopup)(/|$)#i', $requestPath, $m)) {
        return '/' . $m[1];
    }

    return '';
}

function app_api_base_path(): string
{
    return app_base_path() . '/api';
}

function app_is_https(): bool
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO'])) === 'https';
    }

    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function app_scheme(): string
{
    return app_is_https() ? 'https' : 'http';
}

function app_host(): string
{
    return trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
}

function app_url(string $path = ''): string
{
    $path = trim($path);

    if ($path === '') {
        return rtrim(app_scheme() . '://' . app_host() . app_base_path(), '/');
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return app_scheme() . '://' . app_host() . app_base_path() . '/' . ltrim($path, '/');
}

function app_api_url(string $path = ''): string
{
    $path = trim($path);

    if ($path === '') {
        return app_scheme() . '://' . app_host() . app_api_base_path();
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return app_scheme() . '://' . app_host() . app_api_base_path() . '/' . ltrim($path, '/');
}

function app_cookie_path(string $subPath = ''): string
{
    $subPath = trim($subPath, '/');
    return app_api_base_path() . ($subPath !== '' ? '/' . $subPath : '');
}

function app_api_root_dir(): string
{
    return dirname(__DIR__);
}

function app_private_config_path(): string
{
    if (defined('APP_PRIVATE_CONFIG_PATH') && trim((string)APP_PRIVATE_CONFIG_PATH) !== '') {
        return trim((string)APP_PRIVATE_CONFIG_PATH);
    }

    $primary = '/home/zedpayhe/private/zpayswift/config.php';
    $legacy = '/home/zedpayhe/private/zawtopup/config.php';

    if (is_file($primary)) {
        return $primary;
    }

    if (is_file($legacy)) {
        return $legacy;
    }

    return $primary;
}

function app_private_sms_bridge_path(): string
{
    if (defined('APP_PRIVATE_SMS_BRIDGE_PATH') && trim((string)APP_PRIVATE_SMS_BRIDGE_PATH) !== '') {
        return trim((string)APP_PRIVATE_SMS_BRIDGE_PATH);
    }

    $primary = '/home/zedpayhe/private/zpayswift/auth_sms_bridge.php';
    $legacy = '/home/zedpayhe/private/zawtopup/auth_sms_bridge.php';

    if (is_file($primary)) {
        return $primary;
    }

    if (is_file($legacy)) {
        return $legacy;
    }

    return $primary;
}
