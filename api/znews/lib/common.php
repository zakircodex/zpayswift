<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

/**
 * Shared, side-effect-free helpers for the isolated Z News backend module.
 *
 * This file must not mutate existing Z-Pay users, wallets, ledgers, requests,
 * or configuration. Feature endpoints are responsible for explicit writes.
 */

function znews_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function znews_firebase_key($value, string $field = 'id', int $maxLength = 160): string
{
    $key = trim((string)$value);

    if ($key === '' || strlen($key) > $maxLength) {
        api_response(false, 'ZNEWS_INVALID_IDENTIFIER', 'Invalid ' . $field . '.', [], 422);
    }

    if (preg_match('/[.#$\[\]\/\x00-\x1F\x7F]/u', $key) === 1) {
        api_response(false, 'ZNEWS_INVALID_IDENTIFIER', 'Invalid ' . $field . '.', [], 422);
    }

    return $key;
}

function znews_make_id(string $prefix = 'ZNP'): string
{
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', $prefix) ?? 'ZNP');
    if ($prefix === '') {
        $prefix = 'ZNP';
    }

    return $prefix
        . date('YmdHis', znews_now())
        . strtoupper(bin2hex(random_bytes(8)));
}

function znews_normalize_text($value): string
{
    $text = trim((string)$value);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = strip_tags($text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    $text = preg_replace("/\n{4,}/u", "\n\n\n", $text) ?? $text;

    return trim($text);
}

function znews_text_length(string $text): int
{
    return function_exists('mb_strlen')
        ? (int)mb_strlen($text, 'UTF-8')
        : strlen($text);
}

function znews_validate_post_text($value, int $minLength = 1, int $maxLength = 5000): string
{
    $text = znews_normalize_text($value);
    $length = znews_text_length($text);

    if ($length < $minLength) {
        api_response(false, 'ZNEWS_POST_TEXT_REQUIRED', 'Post text is required.', [], 422);
    }

    if ($length > $maxLength) {
        api_response(
            false,
            'ZNEWS_POST_TEXT_TOO_LONG',
            'Post text must not exceed ' . $maxLength . ' characters.',
            ['max_length' => $maxLength],
            422
        );
    }

    return $text;
}

function znews_normalize_status($value, string $fallback = 'ACTIVE'): string
{
    $status = strtoupper(trim((string)$value));
    $allowed = ['ACTIVE', 'REVIEW', 'BLOCKED', 'DELETED'];

    return in_array($status, $allowed, true) ? $status : $fallback;
}

function znews_limit($value, int $default = 20, int $maximum = 50): int
{
    $limit = filter_var($value, FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 1) {
        return $default;
    }

    return min($maximum, (int)$limit);
}

function znews_bool($value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if ($value === null || $value === '') {
        return $default;
    }

    $normalized = strtoupper(trim((string)$value));
    if (in_array($normalized, ['1', 'TRUE', 'YES', 'ON'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'FALSE', 'NO', 'OFF'], true)) {
        return false;
    }

    return $default;
}

function znews_path_post(string $postId): string
{
    return 'ZNEWS_POSTS/' . znews_firebase_key($postId, 'post_id');
}

function znews_path_user_post(string $uid, string $postId): string
{
    return 'ZNEWS_USER_POSTS/'
        . znews_firebase_key($uid, 'uid')
        . '/'
        . znews_firebase_key($postId, 'post_id');
}

function znews_path_idempotency(string $uid, string $key): string
{
    return 'ZNEWS_IDEMPOTENCY/'
        . znews_firebase_key($uid, 'uid')
        . '/'
        . hash('sha256', trim($key));
}

function znews_public_creator_snapshot(array $user): array
{
    return [
        'uid' => trim((string)($user['uid'] ?? '')),
        'name' => trim((string)($user['name'] ?? $user['NAME'] ?? 'Z-Pay User')),
        'profile_photo_url' => trim((string)(
            $user['profile_photo_url']
            ?? $user['profile_photo']
            ?? $user['photo_url']
            ?? ''
        )),
    ];
}

function znews_require_creator(bool $touchSession = true): array
{
    $auth = auth_require_user($touchSession);
    $user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
    $uid = trim((string)($user['uid'] ?? ''));
    $role = strtoupper(trim((string)($user['role'] ?? '')));

    if ($uid === '') {
        api_response(false, 'ZNEWS_AUTH_REQUIRED', 'Authentication required.', [], 401);
    }

    if (!in_array($role, ['USER', 'RETAILER'], true)) {
        api_response(false, 'ZNEWS_ROLE_NOT_ALLOWED', 'This account cannot publish Z News posts.', [], 403);
    }

    return $auth;
}

function znews_idempotency_key($value, bool $required = true): string
{
    $key = trim((string)$value);

    if ($key === '') {
        if ($required) {
            api_response(
                false,
                'ZNEWS_IDEMPOTENCY_KEY_REQUIRED',
                'idempotency_key is required.',
                [],
                422
            );
        }

        return '';
    }

    if (strlen($key) > 160 || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
        api_response(false, 'ZNEWS_INVALID_IDEMPOTENCY_KEY', 'Invalid idempotency_key.', [], 422);
    }

    return $key;
}
