<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$notificationImageAssertions = 0;
$notificationImageDb = [
    'USERS' => [
        'USER_1' => [
            'status' => 'ACTIVE',
            'phone' => '+60123456789',
            'pricing_country' => 'MY',
            'currency' => 'MYR',
        ],
    ],
];
$notificationImagePushes = [];

function notification_image_expect(bool $condition, string $message): void
{
    global $notificationImageAssertions;
    $notificationImageAssertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function notification_image_path_parts(string $path): array
{
    return array_values(array_filter(
        explode('/', trim($path, '/')),
        static fn(string $part): bool => $part !== ''
    ));
}

function fb_get(string $path, array $query = [])
{
    global $notificationImageDb;
    $value = $notificationImageDb;
    foreach (notification_image_path_parts($path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
}

function fb_put(string $path, $value): bool
{
    global $notificationImageDb;
    $parts = notification_image_path_parts($path);
    $node =& $notificationImageDb;
    foreach ($parts as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            $node[$part] = [];
        }
        $node =& $node[$part];
    }
    $node = $value;
    return true;
}

function now_ts(): int
{
    return 1760256000;
}

function fcm_send_to_user(
    string $uid,
    string $title,
    string $body,
    array $data = [],
    string $idempotencyKey = ''
): array {
    global $notificationImagePushes;
    $notificationImagePushes[] = compact('uid', 'title', 'body', 'data', 'idempotencyKey');
    return ['ok' => true, 'sent' => 1, 'failed' => 0, 'code' => 'TEST_FCM'];
}

require_once dirname(__DIR__) . '/api/lib/notifications.php';

$withImage = notification_broadcast_admin_notice([
    'notice_id' => 'NOTICE_WITH_IMAGE',
    'audience' => 'ACTIVE',
    'title' => 'Premium Notice',
    'body' => 'Open the notification to see the full update.',
    'image_id' => 'NI_TEST_IMAGE',
    'image_path' => '/private/notification_images/test.jpg',
    'image_mime' => 'image/jpeg',
    'image_name' => 'update.jpg',
]);

notification_image_expect(!empty($withImage['ok']), 'Image notification broadcast failed.');
notification_image_expect(($withImage['has_image'] ?? null) === true, 'Broadcast result did not retain image state.');
notification_image_expect(count($notificationImagePushes) === 1, 'Expected one image push.');
notification_image_expect(
    ($notificationImagePushes[0]['data']['has_image'] ?? '') === '1',
    'Image push payload did not opt in to secure image rendering.'
);

$notificationId = (string)($notificationImagePushes[0]['data']['notification_id'] ?? '');
$stored = fb_get('USER_NOTIFICATIONS/USER_1/' . $notificationId);
notification_image_expect(is_array($stored), 'User notification row was not stored.');
notification_image_expect(($stored['image_id'] ?? '') === 'NI_TEST_IMAGE', 'Stored image id was lost.');
$public = notification_public_row((array)$stored);
notification_image_expect(!empty($public['has_image']), 'Public notification projection did not expose the image.');
notification_image_expect(
    ($public['image_endpoint'] ?? '') === 'notifications/image.php?notification_id=' . rawurlencode($notificationId),
    'Public notification image endpoint is not the authenticated first-party endpoint.'
);

$withoutImage = notification_broadcast_admin_notice([
    'notice_id' => 'NOTICE_TEXT_ONLY',
    'audience' => 'ACTIVE',
    'title' => 'Text Notice',
    'body' => 'This notification has no image.',
]);

notification_image_expect(!empty($withoutImage['ok']), 'Text notification broadcast failed.');
notification_image_expect(($withoutImage['has_image'] ?? null) === false, 'Text broadcast incorrectly reports an image.');
notification_image_expect(count($notificationImagePushes) === 2, 'Expected one text push after the image push.');
notification_image_expect(
    ($notificationImagePushes[1]['data']['has_image'] ?? '') === '0',
    'Text push payload did not explicitly disable image rendering.'
);

fwrite(STDOUT, "PASS: {$notificationImageAssertions} admin notification image push assertions\n");

