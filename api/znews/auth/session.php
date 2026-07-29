<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/auth_android.php';

api_require_method('GET');
api_require_app_key();

$auth = znews_require_creator(true);
$user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
$uid = trim((string)($user['uid'] ?? ''));
$creator = znews_public_creator_snapshot($user);
$profilePhoto = trim((string)($creator['profile_photo_url'] ?? ''));
if ($profilePhoto === '') {
    $profilePhoto = trim((string)($user['PROFILE'] ?? $user['profile'] ?? ''));
}

api_response(true, 'ZNEWS_CREATOR_SESSION_OK', 'Z News creator access is active.', [
    'access_mode' => 'CREATOR',
    'user' => array_merge(auth_app_user_payload($uid, $user), [
        'profile_photo_url' => $profilePhoto,
        'PROFILE' => $profilePhoto,
    ]),
]);
