<?php
declare(strict_types=1);

$assertions = 0;
$testNow = 1790000000;
$firebase = [];
$failRootPatchOnce = false;

final class BirthdayTestApiException extends RuntimeException
{
    public function __construct(public readonly string $apiCode, public readonly int $apiStatus, string $message)
    {
        parent::__construct($message);
    }
}

function birthday_test_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Birthday Universe test failed: ' . $message);
    }
}

function api_response(bool $ok, string $code, string $message, array $data = [], int $status = 200): never
{
    throw new BirthdayTestApiException($code, $status, $message);
}

function now_ts(): int
{
    global $testNow;
    return $testNow;
}

function security_client_ip(): string
{
    return '203.0.113.25';
}

function security_ip_hash(string $ip): string
{
    return hash('sha256', 'birthday-test|' . $ip);
}

function system_log(string $event, string $subject, string $message, array $metadata = []): void
{
}

function birthday_media_delete_files(array $media): void
{
}

function birthday_test_segments(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
}

function birthday_test_get(string $path): mixed
{
    global $firebase;
    if (trim($path, '/') === '') {
        return $firebase;
    }
    $value = $firebase;
    foreach (birthday_test_segments($path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }
    return $value;
}

function birthday_test_set(string $path, mixed $value): void
{
    global $firebase;
    $segments = birthday_test_segments($path);
    if ($segments === []) {
        $firebase = is_array($value) ? $value : [];
        return;
    }
    $cursor =& $firebase;
    foreach ($segments as $index => $segment) {
        if ($index === count($segments) - 1) {
            $cursor[$segment] = $value;
            return;
        }
        if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
            $cursor[$segment] = [];
        }
        $cursor =& $cursor[$segment];
    }
}

function birthday_test_delete(string $path): void
{
    global $firebase;
    $segments = birthday_test_segments($path);
    if ($segments === []) {
        $firebase = [];
        return;
    }
    $cursor =& $firebase;
    foreach ($segments as $index => $segment) {
        if ($index === count($segments) - 1) {
            unset($cursor[$segment]);
            return;
        }
        if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
            return;
        }
        $cursor =& $cursor[$segment];
    }
}

function birthday_test_etag(mixed $value): string
{
    return '"' . hash('sha256', serialize($value)) . '"';
}

function fb_get(string $path, array $query = []): mixed
{
    return birthday_test_get($path);
}

function fb_put(string $path, mixed $data): bool
{
    birthday_test_set($path, $data);
    return true;
}

function fb_patch(string $path, array $data): bool
{
    global $failRootPatchOnce;
    if ($path === '' && $failRootPatchOnce) {
        $failRootPatchOnce = false;
        return false;
    }
    if ($path === '') {
        foreach ($data as $relative => $value) {
            birthday_test_set((string)$relative, $value);
        }
        return true;
    }
    foreach ($data as $relative => $value) {
        birthday_test_set(rtrim($path, '/') . '/' . ltrim((string)$relative, '/'), $value);
    }
    return true;
}

function fb_delete(string $path): bool
{
    birthday_test_delete($path);
    return true;
}

function fb_get_with_etag(string $path): array
{
    $value = birthday_test_get($path);
    return ['ok' => true, 'status' => 200, 'etag' => birthday_test_etag($value), 'value' => $value, 'error' => null];
}

function fb_put_if_match(string $path, mixed $data, string $etag): array
{
    $current = birthday_test_get($path);
    if (!hash_equals(birthday_test_etag($current), $etag)) {
        return ['ok' => false, 'status' => 412, 'json' => null, 'headers' => [], 'error' => 'etag'];
    }
    birthday_test_set($path, $data);
    return ['ok' => true, 'status' => 200, 'json' => $data, 'headers' => [], 'error' => null];
}

function fb_delete_if_match(string $path, string $etag): array
{
    $current = birthday_test_get($path);
    if (!hash_equals(birthday_test_etag($current), $etag)) {
        return ['ok' => false, 'status' => 412, 'json' => null, 'headers' => [], 'error' => 'etag'];
    }
    birthday_test_delete($path);
    return ['ok' => true, 'status' => 200, 'json' => null, 'headers' => [], 'error' => null];
}

require_once dirname(__DIR__) . '/api/znews/lib/common.php';
require_once dirname(__DIR__) . '/api/znews/lib/birthday.php';
require_once dirname(__DIR__) . '/api/znews/lib/birthday_cleanup.php';

$invalidConsent = birthday_validate_payload([
    'name' => 'Mim', 'birthday_day' => 24, 'birthday_month' => 7,
    'message' => '<img src=x onerror=alert(1)>Happy birthday',
    'template_id' => 'cosmic', 'consent_confirmed' => false,
]);
birthday_test_expect(!str_contains($invalidConsent['message'], '<'), 'HTML tags must be removed from stored user text.');
try {
    birthday_require_consent($invalidConsent);
    birthday_test_expect(false, 'Consent must be required server-side.');
} catch (BirthdayTestApiException $error) {
    birthday_test_expect($error->apiCode === 'BIRTHDAY_CONSENT_REQUIRED', 'Missing consent must return the consent error.');
}

$payload = birthday_validate_payload([
    'name' => 'Mim', 'birthday_day' => 24, 'birthday_month' => 7,
    'sender_name' => 'Zakir', 'message' => "Happy birthday!\nKeep shining.",
    'template_id' => 'cosmic', 'locale' => 'bn', 'visibility' => 'UNLISTED',
    'share_photo' => false, 'consent_confirmed' => true,
]);
birthday_require_consent($payload);
$token = str_repeat('AB', 16);
$draft = birthday_create_draft($payload, strtolower($token));
birthday_test_expect(str_starts_with($draft['id'], 'ZBD'), 'Draft IDs must use the Birthday draft namespace.');
birthday_test_expect(birthday_load_draft($draft['id'], strtolower($token))['name'] === 'Mim', 'Draft tokens must normalize consistently.');
$draftReplay = birthday_create_draft($payload, strtolower($token));
birthday_test_expect($draftReplay['id'] === $draft['id'], 'A retried draft request must return the same draft without creating a duplicate.');
birthday_test_expect(str_starts_with(birthday_path('MEDIA_BLOBS', 'test-media'), 'ZNEWS_BIRTHDAY_MEDIA_BLOBS/'), 'Private media fallback data must use its isolated Firebase namespace.');

$recovery = '23456789ABCDEFGHJKLM';
$created = birthday_generate($draft['id'], strtolower($token), $recovery, 'birthday-test-first');
$universe = $created['universe'];
birthday_test_expect(empty($created['idempotent_replay']), 'First generation must not be marked as a replay.');
birthday_test_expect((bool)preg_match('/^mim-247-[a-z0-9]{12}$/', $universe['slug']), 'Public slug must be readable and collision-resistant.');
birthday_test_expect((bool)preg_match('/^MIM-247-[A-Z0-9]{4}$/', $universe['star_id']), 'Personal star ID must use the normalized name and birthday.');
birthday_test_expect(!array_key_exists('id', $universe) && !array_key_exists('recovery_hash', $universe), 'Public output must not expose database IDs or recovery hashes.');

$replayed = birthday_generate($draft['id'], $token, $recovery, 'birthday-test-first');
birthday_test_expect(!empty($replayed['idempotent_replay']), 'A completed generation must replay after the draft is marked generated.');
birthday_test_expect($replayed['universe']['slug'] === $universe['slug'], 'Idempotent replay must return the original Universe.');

$secondDraft = birthday_create_draft($payload, str_repeat('CD', 16));
$second = birthday_generate($secondDraft['id'], str_repeat('CD', 16), $recovery, 'birthday-test-second');
birthday_test_expect($second['universe']['slug'] !== $universe['slug'], 'Public slugs must remain unique.');
birthday_test_expect($second['universe']['star_id'] !== $universe['star_id'], 'Personal star IDs must remain unique.');

$retryDraft = birthday_create_draft($payload, str_repeat('EF', 16));
$failRootPatchOnce = true;
try {
    birthday_generate($retryDraft['id'], str_repeat('EF', 16), $recovery, 'birthday-test-retry');
    birthday_test_expect(false, 'The simulated atomic write failure must surface.');
} catch (BirthdayTestApiException $error) {
    birthday_test_expect($error->apiCode === 'BIRTHDAY_GENERATION_FAILED', 'Atomic write failure must return a generation failure.');
}
$retried = birthday_generate($retryDraft['id'], str_repeat('EF', 16), $recovery, 'birthday-test-retry');
birthday_test_expect(empty($retried['idempotent_replay']), 'A failed idempotency claim must be safely retryable.');

$photoUniverse = birthday_universe_by_slug($universe['slug'], true);
$photoUniverse['photo_media_id'] = 'test-photo';
$photoUniverse['share_photo'] = true;
fb_put(birthday_path('UNIVERSES', (string)$photoUniverse['id']), $photoUniverse);
fb_put(birthday_path('MEDIA', 'test-photo'), ['id' => 'test-photo', 'status' => 'ACTIVE']);
$withoutPhoto = birthday_remove_photo_owned($photoUniverse);
birthday_test_expect($withoutPhoto['photo_url'] === '' && empty($withoutPhoto['share_photo']), 'Owners must be able to remove a photo and its social-preview flag.');
birthday_test_expect(birthday_test_get(birthday_path('MEDIA', 'test-photo') . '/status') === 'REPLACED', 'Removed photos must enter deferred cleanup.');

birthday_rate_limit('single_use_test', 1, 3600, true);
try {
    birthday_rate_limit('single_use_test', 1, 3600, true);
    birthday_test_expect(false, 'Rate limiting must reject requests over the configured maximum.');
} catch (BirthdayTestApiException $error) {
    birthday_test_expect($error->apiCode === 'BIRTHDAY_RATE_LIMITED' && $error->apiStatus === 429, 'Rate limiting must return HTTP 429.');
}

$firebase = [
    'ZNEWS_BIRTHDAY_UNIVERSES' => [
        'active-first' => [
            'id' => 'active-first', 'slug' => 'active-first-abcdefgh', 'star_id' => 'ACTIVE-11-ABCD',
            'status' => 'ACTIVE', 'expires_at' => $testNow + 86400,
        ],
        'deleted-after-active' => [
            'id' => 'deleted-after-active', 'slug' => 'deleted-after-active-abc', 'star_id' => 'DELETE-11-ABCD',
            'status' => 'DELETED', 'deleted_at' => $testNow - 8 * 86400,
            'updated_at' => $testNow - 8 * 86400, 'expires_at' => $testNow + 30 * 86400,
        ],
    ],
    'ZNEWS_BIRTHDAY_DRAFTS' => [
        'expired-draft' => [
            'id' => 'expired-draft', 'status' => 'DRAFT', 'expires_at' => $testNow - 60,
            'photo_media_id' => '',
        ],
    ],
    'ZNEWS_BIRTHDAY_MEDIA' => [
        'replaced-photo' => [
            'id' => 'replaced-photo', 'status' => 'REPLACED', 'storage_key' => '',
            'updated_at' => $testNow - 2 * 86400,
        ],
    ],
    'ZNEWS_BIRTHDAY_IDEMPOTENCY' => [
        'stale-request' => ['status' => 'COMPLETE', 'expires_at' => $testNow - 1],
    ],
];
$cleanup = birthday_cleanup_run(false, 1);
birthday_test_expect($cleanup['deleted_universes'] === 1, 'Active Universes must not starve an actionable deleted Universe.');
birthday_test_expect($cleanup['deleted_drafts'] === 1, 'Universe cleanup limits must not starve expired drafts.');
birthday_test_expect($cleanup['deleted_media'] === 1, 'Replaced photo records must be cleaned after their safety window.');
birthday_test_expect($cleanup['deleted_transient'] === 1, 'Expired idempotency records must be cleaned.');
birthday_test_expect(birthday_test_get('ZNEWS_BIRTHDAY_UNIVERSES/active-first') !== null, 'Cleanup must preserve active Universes.');

$source = file_get_contents(dirname(__DIR__) . '/api/znews/lib/birthday_media.php');
$sharedMediaSource = file_get_contents(dirname(__DIR__) . '/api/znews/lib/media.php');
birthday_test_expect(is_string($source) && is_string($sharedMediaSource) && str_contains($sharedMediaSource, 'getimagesize') && str_contains($source, 'INVALID_SIGNATURE'), 'Media code must validate decoded images and audio signatures.');
$templateSource = file_get_contents(dirname(__DIR__) . '/znews/birthday/assets/birthday-templates.js');
birthday_test_expect(is_string($templateSource) && str_contains($templateSource, 'textContent') && !str_contains($templateSource, 'innerHTML'), 'Public template rendering must not inject user HTML.');
$routes = file_get_contents(dirname(__DIR__) . '/.htaccess');
birthday_test_expect(is_string($routes) && str_contains($routes, 'birthday/preview/') && str_contains($routes, 'birthday/public.php?slug=$1'), 'Clean preview and public Universe routes must be deployed.');
$adSource = file_get_contents(dirname(__DIR__) . '/api/znews/birthday/ad.php');
birthday_test_expect(is_string($adSource) && str_contains($adSource, "'event_type' => 'OFFERED'") && !str_contains($adSource, "'event_type' => 'DELIVERED'"), 'Ad capability checks must not fabricate delivery events.');
$apiClientSource = file_get_contents(dirname(__DIR__) . '/znews/birthday/assets/birthday-api.js');
birthday_test_expect(is_file(dirname(__DIR__) . '/api/znews/birthday/catalog.php') && is_string($apiClientSource) && str_contains($apiClientSource, "request('catalog.php'"), 'Public catalog configuration must use a deployable, tracked endpoint name.');
birthday_test_expect(is_string($apiClientSource) && str_contains($apiClientSource, 'networkRetries: 1'), 'Critical Birthday requests must retry one transient network failure.');
$publicClientSource = file_get_contents(dirname(__DIR__) . '/znews/birthday/assets/birthday-public.js');
birthday_test_expect(is_string($publicClientSource) && str_contains($publicClientSource, "toDataURL('image/png')"), 'QR downloads must contain real PNG data.');
$appClientSource = file_get_contents(dirname(__DIR__) . '/znews/birthday/assets/birthday-app.js');
birthday_test_expect(is_string($appClientSource) && !str_contains($appClientSource, 'localStorage.setItem(`birthday_recovery_'), 'Recovery codes must not persist in localStorage.');
$birthdayCsp = file_get_contents(dirname(__DIR__) . '/znews/.htaccess');
birthday_test_expect(is_string($birthdayCsp) && str_contains($birthdayCsp, "style-src 'self';") && !str_contains($birthdayCsp, "style-src 'self' 'unsafe-inline'"), 'Birthday pages must retain the strict style CSP.');
$birthdayAdminSource = file_get_contents(dirname(__DIR__) . '/api/admin/birthday_admin.php');
birthday_test_expect(is_string($birthdayAdminSource) && str_contains($birthdayAdminSource, "if (\$action === 'music_edit')"), 'Admin must support validated licensed-music metadata edits.');

echo "Z Sky 24 Birthday Universe tests passed ({$assertions} assertions).\n";
