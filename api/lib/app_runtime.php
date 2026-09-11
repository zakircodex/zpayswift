<?php
declare(strict_types=1);

const APP_RUNTIME_DEFAULT_ANDROID_VERSION_CODE = 2;
const APP_RUNTIME_DEFAULT_ANDROID_VERSION_NAME = '1.1';
const APP_RUNTIME_DEFAULT_ANDROID_UPDATE_URL = 'https://zpayswift.com/download.php';
const APP_RUNTIME_DEFAULT_ANDROID_UPDATE_MESSAGE = 'A new version of Z-Pay Swift is ready. Update now to continue.';

function app_runtime_positive_int($value, int $default = 1): int
{
    if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
        $number = (int)$value;
        if ($number > 0) {
            return $number;
        }
    }

    return max(1, $default);
}

function app_runtime_version_name($value, string $default = APP_RUNTIME_DEFAULT_ANDROID_VERSION_NAME): string
{
    $name = trim((string)$value);
    if ($name === '' || strlen($name) > 40 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
        return $default;
    }

    return $name;
}

function app_runtime_https_url($value, string $default = APP_RUNTIME_DEFAULT_ANDROID_UPDATE_URL): string
{
    $url = trim((string)$value);
    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return $default;
    }

    $parts = parse_url($url);
    if (strtolower((string)($parts['scheme'] ?? '')) !== 'https' || trim((string)($parts['host'] ?? '')) === '') {
        return $default;
    }

    return $url;
}

function app_runtime_update_message($value, string $default = APP_RUNTIME_DEFAULT_ANDROID_UPDATE_MESSAGE): string
{
    $message = trim((string)$value);
    $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
    if ($message === '' || $length > 240 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message) === 1) {
        return $default;
    }

    return $message;
}

function app_runtime_android_update(array $config, int $currentVersionCode = 0, string $currentVersionName = ''): array
{
    $latestVersionCode = app_runtime_positive_int(
        $config['android_latest_version_code'] ?? APP_RUNTIME_DEFAULT_ANDROID_VERSION_CODE,
        APP_RUNTIME_DEFAULT_ANDROID_VERSION_CODE
    );
    $latestVersionName = app_runtime_version_name(
        $config['android_latest_version_name'] ?? APP_RUNTIME_DEFAULT_ANDROID_VERSION_NAME
    );
    $currentVersionCode = max(0, $currentVersionCode);

    return [
        'required' => $currentVersionCode > 0 && $currentVersionCode < $latestVersionCode,
        'current_version_code' => $currentVersionCode,
        'current_version_name' => app_runtime_version_name($currentVersionName, ''),
        'latest_version_code' => $latestVersionCode,
        'latest_version_name' => $latestVersionName,
        'update_url' => app_runtime_https_url($config['android_update_url'] ?? APP_RUNTIME_DEFAULT_ANDROID_UPDATE_URL),
        'message' => app_runtime_update_message($config['android_update_message'] ?? APP_RUNTIME_DEFAULT_ANDROID_UPDATE_MESSAGE),
    ];
}
