<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';

function profile_photo_upload_dir(): string
{
    return rtrim(zpay_dash_public_root_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'profile'
        . DIRECTORY_SEPARATOR . 'photos';
}

function profile_photo_ensure_upload_dir(): bool
{
    $dir = profile_photo_upload_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        $rules = "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh|asp|aspx)$\">\n    Require all denied\n</FilesMatch>\n";
        @file_put_contents($htaccess, $rules);
    }

    return is_dir($dir) && is_writable($dir);
}

function profile_photo_file_from_request(): array
{
    foreach (['profile_photo', 'photo', 'avatar', 'file'] as $field) {
        if (!empty($_FILES[$field]) && is_array($_FILES[$field])) {
            return [$field, $_FILES[$field]];
        }
    }

    return ['', []];
}

function profile_photo_safe_public_payload(string $uid, array $user, array $wallet): array
{
    $pricingCountry = auth_pricing_country_from_user($user, $wallet);
    $walletCurrency = function_exists('wallet_account_currency')
        ? wallet_account_currency($user, $wallet)
        : strtoupper((string)($wallet['wallet_currency'] ?? $wallet['currency'] ?? ($pricingCountry === 'MY' ? 'MYR' : 'BDT')));

    return [
        'uid' => $uid,
        'name' => (string)($user['name'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'status' => (string)($user['status'] ?? ''),
        'account_status' => (string)($user['account_status'] ?? $user['status'] ?? ''),
        'role' => auth_status_value($user['role'] ?? ''),
        'phone_country' => auth_phone_country_from_user($user),
        'pricing_country' => $pricingCountry,
        'wallet_currency' => $walletCurrency !== '' ? $walletCurrency : 'BDT',
        'created_at' => (int)($user['created_at'] ?? 0),
        'last_login_at' => (int)($user['last_login_at'] ?? 0),
        'profile_photo_url' => (string)($user['profile_photo_url'] ?? ''),
    ];
}

function profile_photo_old_local_path(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $path = parse_url($url, PHP_URL_PATH);
    $path = is_string($path) ? $path : $url;
    $prefix = '/uploads/profile/photos/';
    if (!str_starts_with($path, $prefix)) {
        return '';
    }

    $fileName = basename($path);
    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        return '';
    }

    return profile_photo_upload_dir() . DIRECTORY_SEPARATOR . $fileName;
}

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(true);
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$uid = trim((string)($user['uid'] ?? ''));
$role = auth_status_value($user['role'] ?? '');

if ($uid === '') {
    api_response(false, 'AUTH_REQUIRED', 'Authentication required.', [], 401);
}

if (!zpay_dash_allowed_mobile_role($role)) {
    api_response(false, 'ROLE_NOT_ALLOWED', 'This account type is not allowed in this app.', [], 403);
}

[$field, $file] = profile_photo_file_from_request();
if ($field === '' || !$file) {
    api_response(false, 'VALIDATION_ERROR', 'Profile photo is required.', [], 422);
}

$error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($error !== UPLOAD_ERR_OK) {
    api_response(false, 'UPLOAD_FAILED', 'Profile photo upload failed.', [], 400);
}

$tmp = (string)($file['tmp_name'] ?? '');
$size = (int)($file['size'] ?? 0);
if ($tmp === '' || !is_uploaded_file($tmp)) {
    api_response(false, 'UPLOAD_INVALID', 'Uploaded image could not be read.', [], 400);
}

if ($size <= 0 || $size > 5 * 1024 * 1024) {
    api_response(false, 'IMAGE_TOO_LARGE', 'The selected image is too large.', [], 422);
}

$originalName = strtolower((string)($file['name'] ?? 'profile.jpg'));
if (preg_match('/\.(php|phtml|phar|cgi|pl|py|sh|asp|aspx)(\.|$)/i', $originalName) === 1) {
    api_response(false, 'UNSUPPORTED_IMAGE', 'Choose a supported image file.', [], 422);
}

$mime = '';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $mime = (string)finfo_file($finfo, $tmp);
        finfo_close($finfo);
    }
}
if ($mime === '' && class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
}

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
if (!isset($allowed[$mime])) {
    api_response(false, 'UNSUPPORTED_IMAGE', 'Choose a supported image file.', [], 422);
}

$imageInfo = @getimagesize($tmp);
if (!is_array($imageInfo) || (int)($imageInfo[0] ?? 0) < 80 || (int)($imageInfo[1] ?? 0) < 80) {
    api_response(false, 'UNSUPPORTED_IMAGE', 'Choose a supported image file.', [], 422);
}

if (!profile_photo_ensure_upload_dir()) {
    api_response(false, 'PROFILE_PHOTO_UPLOAD_FAILED', 'Unable to update profile photo. Please try again.', [], 500);
}

$now = now_ts();
$uidHash = substr(hash('sha256', $uid), 0, 16);
$fileName = 'profile_' . $uidHash . '_' . date('YmdHis', $now) . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
$target = profile_photo_upload_dir() . DIRECTORY_SEPARATOR . $fileName;

if (!move_uploaded_file($tmp, $target)) {
    api_response(false, 'PROFILE_PHOTO_UPLOAD_FAILED', 'Unable to update profile photo. Please try again.', [], 500);
}
@chmod($target, 0644);

$publicUrl = '/uploads/profile/photos/' . $fileName;
$oldPath = profile_photo_old_local_path((string)($user['profile_photo_url'] ?? $user['profile_photo'] ?? $user['photo_url'] ?? ''));
$patch = [
    'profile_photo_url' => $publicUrl,
    'profile_photo_mime' => $mime,
    'profile_photo_size' => $size,
    'profile_photo_updated_at' => $now,
    'updated_at' => $now,
];

if (!fb_patch('USERS/' . $uid, $patch)) {
    @unlink($target);
    api_response(false, 'PROFILE_PHOTO_UPLOAD_FAILED', 'Unable to update profile photo. Please try again.', [], 500);
}

if ($oldPath !== '' && is_file($oldPath) && realpath(dirname($oldPath)) === realpath(profile_photo_upload_dir())) {
    @unlink($oldPath);
}

$freshUser = fb_get('USERS/' . $uid);
$freshUser = is_array($freshUser) ? $freshUser : array_merge($user, $patch);
$wallet = fb_get('USER_WALLETS/' . $uid);
$wallet = is_array($wallet) ? $wallet : [];

system_log('USER_PROFILE_PHOTO_UPDATE', $uid, 'User profile photo updated', [
    'uid' => $uid,
    'mime' => $mime,
    'size' => $size,
]);

api_response(true, 'PROFILE_PHOTO_UPDATED', 'Profile photo updated successfully.', profile_photo_safe_public_payload($uid, $freshUser, $wallet));
