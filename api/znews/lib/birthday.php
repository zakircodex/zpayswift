<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function birthday_now(): int
{
    return znews_now();
}

function birthday_path(string $node, string $id = ''): string
{
    $allowed = [
        'UNIVERSES', 'DRAFTS', 'SLUGS', 'STAR_IDS', 'IDEMPOTENCY', 'OWNERS',
        'MEDIA', 'MUSIC', 'TEMPLATE_SETTINGS', 'SETTINGS', 'EVENTS',
        'DAILY_ANALYTICS', 'VIEW_DEDUP', 'RATE_LIMITS', 'REPORTS', 'AD_EVENTS',
        'CLEANUP_LEASES',
    ];
    $node = strtoupper(trim($node));
    if (!in_array($node, $allowed, true)) {
        throw new InvalidArgumentException('Invalid Birthday Universe data node.');
    }
    $path = 'ZNEWS_BIRTHDAY_' . $node;
    return $id === '' ? $path : $path . '/' . znews_firebase_key($id, strtolower($node) . '_id', 200);
}

function birthday_setting_int(string $name, int $fallback, int $minimum, int $maximum): int
{
    $constant = 'BIRTHDAY_UNIVERSE_' . strtoupper($name);
    $value = defined($constant) ? (int)constant($constant) : $fallback;
    return max($minimum, min($maximum, $value));
}

function birthday_default_settings(): array
{
    return [
        'enabled' => true,
        'retention_days' => birthday_setting_int('RETENTION_DAYS', 90, 7, 3650),
        'renewal_days' => birthday_setting_int('RENEWAL_DAYS', 90, 7, 3650),
        'generation_per_hour' => birthday_setting_int('GENERATION_PER_HOUR', 5, 1, 50),
        'draft_ttl_seconds' => birthday_setting_int('DRAFT_TTL_SECONDS', 86400, 3600, 604800),
        'allow_public_indexing' => true,
        'default_locale' => 'en',
    ];
}

function birthday_settings(): array
{
    $defaults = birthday_default_settings();
    $stored = fb_get(birthday_path('SETTINGS') . '/PUBLIC');
    if (!is_array($stored)) {
        return $defaults;
    }
    return [
        'enabled' => znews_bool($stored['enabled'] ?? $defaults['enabled'], true),
        'retention_days' => max(7, min(3650, (int)($stored['retention_days'] ?? $defaults['retention_days']))),
        'renewal_days' => max(7, min(3650, (int)($stored['renewal_days'] ?? $defaults['renewal_days']))),
        'generation_per_hour' => max(1, min(50, (int)($stored['generation_per_hour'] ?? $defaults['generation_per_hour']))),
        'draft_ttl_seconds' => max(3600, min(604800, (int)($stored['draft_ttl_seconds'] ?? $defaults['draft_ttl_seconds']))),
        'allow_public_indexing' => znews_bool($stored['allow_public_indexing'] ?? true, true),
        'default_locale' => birthday_locale($stored['default_locale'] ?? 'en'),
    ];
}

function birthday_template_registry(): array
{
    return [
        'cosmic' => [
            'id' => 'cosmic',
            'name' => 'Cosmic',
            'description' => 'A cinematic galaxy, moon and personal star experience.',
            'preview_image' => '/znews/birthday/assets/images/birthday-universe-cosmic.webp',
            'renderer' => 'cosmic',
            'default_active' => true,
            'configuration' => ['accent' => '#63e6be', 'secondary' => '#f6c86b'],
        ],
        'dreamy' => [
            'id' => 'dreamy',
            'name' => 'Dreamy',
            'description' => 'Soft moonlight, floating wishes and an intimate birthday reveal.',
            'preview_image' => '/znews/birthday/assets/images/birthday-universe-cosmic.webp',
            'renderer' => 'dreamy',
            'default_active' => true,
            'configuration' => ['accent' => '#ff8fa3', 'secondary' => '#8ee3cf'],
        ],
        'celebration' => [
            'id' => 'celebration',
            'name' => 'Celebration',
            'description' => 'A bright cosmic celebration with confetti, stars and warm color.',
            'preview_image' => '/znews/birthday/assets/images/birthday-universe-cosmic.webp',
            'renderer' => 'celebration',
            'default_active' => true,
            'configuration' => ['accent' => '#ff6b6b', 'secondary' => '#ffd43b'],
        ],
    ];
}

function birthday_templates(bool $includeInactive = false): array
{
    $stored = fb_get(birthday_path('TEMPLATE_SETTINGS'));
    $stored = is_array($stored) ? $stored : [];
    $items = [];
    foreach (birthday_template_registry() as $id => $definition) {
        $override = is_array($stored[$id] ?? null) ? (array)$stored[$id] : [];
        $active = array_key_exists('active', $override)
            ? znews_bool($override['active'], true)
            : !empty($definition['default_active']);
        if (!$includeInactive && !$active) {
            continue;
        }
        $configuration = (array)$definition['configuration'];
        foreach (['accent', 'secondary'] as $colorField) {
            $candidate = trim((string)($override[$colorField] ?? ''));
            if (preg_match('/^#[A-Fa-f0-9]{6}$/D', $candidate) === 1) {
                $configuration[$colorField] = strtolower($candidate);
            }
        }
        $items[] = [
            'id' => $id,
            'name' => birthday_bounded_text($override['name'] ?? $definition['name'], 1, 50, 'template name'),
            'description' => birthday_bounded_text($override['description'] ?? $definition['description'], 1, 180, 'template description'),
            'preview_image' => (string)$definition['preview_image'],
            'renderer' => (string)$definition['renderer'],
            'configuration' => $configuration,
            'active' => $active,
        ];
    }
    return $items;
}

function birthday_template(string $id, bool $includeInactive = false): ?array
{
    $id = strtolower(trim($id));
    foreach (birthday_templates($includeInactive) as $template) {
        if ((string)$template['id'] === $id) {
            return $template;
        }
    }
    return null;
}

function birthday_music(bool $includeInactive = false): array
{
    $rows = fb_get(birthday_path('MUSIC'));
    if (!is_array($rows)) {
        return [];
    }
    $items = [];
    foreach ($rows as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $active = znews_bool($row['active'] ?? false, false);
        if (!$includeInactive && !$active) {
            continue;
        }
        $safeId = preg_match('/^[A-Za-z0-9_-]{1,160}$/D', (string)$id) === 1 ? (string)$id : '';
        if ($safeId === '') {
            continue;
        }
        $items[] = [
            'id' => $safeId,
            'name' => trim((string)($row['name'] ?? 'Birthday music')),
            'duration' => max(0, (int)($row['duration'] ?? 0)),
            'size_bytes' => max(0, (int)($row['size_bytes'] ?? 0)),
            'created_at' => max(0, (int)($row['created_at'] ?? 0)),
            'active' => $active,
            'url' => '/api/znews/birthday/music.php?id=' . rawurlencode($safeId),
        ];
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));
    return $items;
}

function birthday_locale($value): string
{
    return strtolower(trim((string)$value)) === 'bn' ? 'bn' : 'en';
}

function birthday_visibility($value, ?array $settings = null): string
{
    $requested = strtoupper(trim((string)$value));
    $settings = $settings ?? birthday_settings();
    if ($requested === 'PUBLIC' && !empty($settings['allow_public_indexing'])) {
        return 'PUBLIC';
    }
    return 'UNLISTED';
}

function birthday_bounded_text($value, int $minimum, int $maximum, string $field): string
{
    $text = znews_normalize_text($value);
    $length = znews_text_length($text);
    if ($length < $minimum) {
        api_response(false, 'BIRTHDAY_FIELD_REQUIRED', ucfirst($field) . ' is required.', ['field' => $field], 422);
    }
    if ($length > $maximum) {
        api_response(false, 'BIRTHDAY_FIELD_TOO_LONG', ucfirst($field) . ' is too long.', [
            'field' => $field,
            'max_length' => $maximum,
        ], 422);
    }
    return $text;
}

function birthday_optional_text($value, int $maximum, string $field): string
{
    $text = znews_normalize_text($value);
    if (znews_text_length($text) > $maximum) {
        api_response(false, 'BIRTHDAY_FIELD_TOO_LONG', ucfirst($field) . ' is too long.', [
            'field' => $field,
            'max_length' => $maximum,
        ], 422);
    }
    return $text;
}

function birthday_content_allowed(string $text): bool
{
    if ($text === '') {
        return true;
    }
    if (preg_match_all('#https?://|www\.#iu', $text) > 1) {
        return false;
    }
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $blocked = [
        '/\b(?:buy followers|crypto giveaway|free money|casino bonus|porn|nude)\b/iu',
        '/(?:ফ্রি টাকা|ক্যাসিনো বোনাস|অশ্লীল)/u',
    ];
    foreach ($blocked as $pattern) {
        if (preg_match($pattern, $normalized) === 1) {
            return false;
        }
    }
    return true;
}

function birthday_validate_payload(array $body): array
{
    $settings = birthday_settings();
    if (empty($settings['enabled'])) {
        api_response(false, 'BIRTHDAY_DISABLED', 'Birthday Universe is temporarily unavailable.', [], 503);
    }
    $name = birthday_bounded_text($body['name'] ?? '', 1, 50, 'name');
    $senderName = birthday_optional_text($body['sender_name'] ?? '', 50, 'sender name');
    $message = birthday_optional_text($body['message'] ?? '', 500, 'message');
    if (!birthday_content_allowed($name . "\n" . $senderName . "\n" . $message)) {
        api_response(false, 'BIRTHDAY_CONTENT_REJECTED', 'Please revise the message and try again.', [], 422);
    }

    $day = filter_var($body['birthday_day'] ?? null, FILTER_VALIDATE_INT);
    $month = filter_var($body['birthday_month'] ?? null, FILTER_VALIDATE_INT);
    $yearRaw = trim((string)($body['birthday_year'] ?? ''));
    $year = $yearRaw === '' ? null : filter_var($yearRaw, FILTER_VALIDATE_INT);
    if ($day === false || $month === false || $day < 1 || $month < 1 || $month > 12) {
        api_response(false, 'BIRTHDAY_DATE_INVALID', 'Please enter a valid birthday.', [], 422);
    }
    if ($year !== null && ($year === false || $year < 1900 || $year > (int)date('Y'))) {
        api_response(false, 'BIRTHDAY_YEAR_INVALID', 'Please enter a valid birth year or leave it empty.', [], 422);
    }
    $validationYear = is_int($year) ? $year : 2000;
    if (!checkdate((int)$month, (int)$day, $validationYear)) {
        api_response(false, 'BIRTHDAY_DATE_INVALID', 'Please enter a valid birthday.', [], 422);
    }

    $templateId = strtolower(trim((string)($body['template_id'] ?? 'cosmic')));
    if (birthday_template($templateId) === null) {
        api_response(false, 'BIRTHDAY_TEMPLATE_INVALID', 'Please choose an available template.', [], 422);
    }
    $musicId = trim((string)($body['music_id'] ?? ''));
    if ($musicId !== '') {
        $found = false;
        foreach (birthday_music() as $music) {
            if ((string)$music['id'] === $musicId) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            api_response(false, 'BIRTHDAY_MUSIC_INVALID', 'Please choose an available music track.', [], 422);
        }
    }

    return [
        'name' => $name,
        'birthday_day' => (int)$day,
        'birthday_month' => (int)$month,
        'birthday_year' => is_int($year) ? $year : 0,
        'sender_name' => $senderName,
        'message' => $message,
        'template_id' => $templateId,
        'music_id' => $musicId,
        'locale' => birthday_locale($body['locale'] ?? $settings['default_locale']),
        'visibility' => birthday_visibility($body['visibility'] ?? 'UNLISTED', $settings),
        'share_photo' => znews_bool($body['share_photo'] ?? false, false),
        'consent_confirmed' => znews_bool($body['consent_confirmed'] ?? false, false),
    ];
}

function birthday_require_consent(array $payload): void
{
    if (empty($payload['consent_confirmed'])) {
        api_response(false, 'BIRTHDAY_CONSENT_REQUIRED', 'Please confirm that you have permission to publish this content.', [], 422);
    }
}

function birthday_same_origin(): void
{
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return;
    }
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $requestHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if (str_contains($requestHost, ':')) {
        $requestHost = strtolower((string)parse_url('https://' . $requestHost, PHP_URL_HOST));
    }
    if ($originHost === '' || $requestHost === '' || !hash_equals($requestHost, $originHost)) {
        api_response(false, 'BIRTHDAY_ORIGIN_INVALID', 'This request could not be verified.', [], 403);
    }
}

function birthday_request_ip_hash(): string
{
    $ip = function_exists('security_client_ip') ? security_client_ip() : trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (function_exists('security_ip_hash')) {
        return security_ip_hash($ip);
    }
    $secret = defined('SECURITY_HASH_SECRET') ? (string)constant('SECURITY_HASH_SECRET') : __FILE__;
    return hash_hmac('sha256', $ip, $secret);
}

function birthday_rate_limit(string $bucket, int $maximum, int $windowSeconds, bool $failClosed = true): array
{
    $bucket = preg_replace('/[^A-Za-z0-9_-]/', '', $bucket) ?: 'general';
    $now = birthday_now();
    $window = (int)floor($now / max(1, $windowSeconds));
    $identity = birthday_request_ip_hash();
    $path = birthday_path('RATE_LIMITS') . '/' . $bucket . '/' . hash('sha256', $identity . '|' . $window);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            if ($failClosed) {
                api_response(false, 'BIRTHDAY_RATE_LIMIT_UNAVAILABLE', 'Please try again shortly.', [], 503);
            }
            return ['allowed' => true, 'remaining' => $maximum];
        }
        $row = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        $count = max(0, (int)($row['count'] ?? 0));
        if ($count >= $maximum) {
            api_response(false, 'BIRTHDAY_RATE_LIMITED', 'Too many requests. Please try again later.', [
                'retry_after' => (($window + 1) * $windowSeconds) - $now,
            ], 429);
        }
        $next = [
            'count' => $count + 1,
            'window' => $window,
            'expires_at' => ($window + 2) * $windowSeconds,
            'updated_at' => $now,
        ];
        $write = fb_put_if_match($path, $next, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(25000);
            continue;
        }
        if (empty($write['ok'])) {
            if ($failClosed) {
                api_response(false, 'BIRTHDAY_RATE_LIMIT_UNAVAILABLE', 'Please try again shortly.', [], 503);
            }
            return ['allowed' => true, 'remaining' => max(0, $maximum - $count)];
        }
        return ['allowed' => true, 'remaining' => max(0, $maximum - $count - 1)];
    }
    if ($failClosed) {
        api_response(false, 'BIRTHDAY_RATE_LIMIT_BUSY', 'Please try again shortly.', [], 503);
    }
    return ['allowed' => true, 'remaining' => 0];
}

function birthday_token_hash(string $token): string
{
    return hash('sha256', trim($token));
}

function birthday_draft_token(): string
{
    return strtoupper(bin2hex(random_bytes(16)));
}

function birthday_create_draft(array $payload, string $draftToken): array
{
    birthday_rate_limit('draft', 12, 3600, true);
    $draftToken = strtoupper(trim($draftToken));
    if (preg_match('/^[A-F0-9]{32,128}$/D', $draftToken) !== 1) {
        api_response(false, 'BIRTHDAY_DRAFT_TOKEN_INVALID', 'Draft security token is invalid.', [], 422);
    }
    $now = birthday_now();
    $settings = birthday_settings();
    $draftId = znews_make_id('ZBD');
    $row = array_merge($payload, [
        'id' => $draftId,
        'draft_token_hash' => birthday_token_hash($draftToken),
        'photo_media_id' => '',
        'status' => 'DRAFT',
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $now + (int)$settings['draft_ttl_seconds'],
    ]);
    if (!fb_put(birthday_path('DRAFTS', $draftId), $row)) {
        api_response(false, 'BIRTHDAY_DRAFT_CREATE_FAILED', 'Your draft could not be saved. Please try again.', [], 503);
    }
    return birthday_public_draft($row);
}

function birthday_load_draft(string $draftId, string $draftToken): array
{
    $draftId = znews_firebase_key($draftId, 'draft_id');
    $draftToken = strtoupper(trim($draftToken));
    $row = fb_get(birthday_path('DRAFTS', $draftId));
    if (!is_array($row)
        || !hash_equals((string)($row['draft_token_hash'] ?? ''), birthday_token_hash($draftToken))) {
        api_response(false, 'BIRTHDAY_DRAFT_NOT_FOUND', 'Draft not found or access expired.', [], 404);
    }
    if ((int)($row['expires_at'] ?? 0) <= birthday_now() || strtoupper((string)($row['status'] ?? '')) !== 'DRAFT') {
        api_response(false, 'BIRTHDAY_DRAFT_EXPIRED', 'This draft has expired.', [], 410);
    }
    return $row;
}

function birthday_public_draft(array $row): array
{
    $template = birthday_template((string)($row['template_id'] ?? 'cosmic'));
    $music = birthday_music_by_id((string)($row['music_id'] ?? ''));
    return [
        'id' => (string)($row['id'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'birthday_day' => (int)($row['birthday_day'] ?? 0),
        'birthday_month' => (int)($row['birthday_month'] ?? 0),
        'birthday_year' => (int)($row['birthday_year'] ?? 0),
        'sender_name' => (string)($row['sender_name'] ?? ''),
        'message' => (string)($row['message'] ?? ''),
        'template' => $template,
        'music' => $music,
        'locale' => birthday_locale($row['locale'] ?? 'en'),
        'visibility' => birthday_visibility($row['visibility'] ?? 'UNLISTED'),
        'share_photo' => !empty($row['share_photo']),
        'photo_media_id' => (string)($row['photo_media_id'] ?? ''),
        'photo_url' => (string)($row['photo_media_id'] ?? '') !== ''
            ? '/api/znews/birthday/media.php?id=' . rawurlencode((string)$row['photo_media_id']) . '&draft=' . rawurlencode((string)$row['id'])
            : '',
        'expires_at' => (int)($row['expires_at'] ?? 0),
    ];
}

function birthday_music_by_id(string $musicId): ?array
{
    if ($musicId === '') {
        return null;
    }
    foreach (birthday_music() as $music) {
        if ((string)$music['id'] === $musicId) {
            return $music;
        }
    }
    return null;
}

function birthday_random_code(int $length): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $output = '';
    $bytes = random_bytes($length);
    $count = strlen($alphabet);
    for ($index = 0; $index < $length; $index++) {
        $output .= $alphabet[ord($bytes[$index]) % $count];
    }
    return $output;
}

function birthday_name_prefix(string $name): string
{
    $ascii = $name;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if (is_string($converted) && trim($converted) !== '') {
            $ascii = $converted;
        }
    }
    $ascii = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $ascii) ?? '');
    return $ascii !== '' ? substr($ascii, 0, 3) : 'ZST';
}

function birthday_release_reservation(string $node, string $key, string $universeId): void
{
    $path = birthday_path($node) . '/' . znews_firebase_key($key, strtolower($node) . '_key');
    $snapshot = fb_get_with_etag($path);
    if (!empty($snapshot['ok'])
        && is_string($snapshot['etag'] ?? null)
        && hash_equals((string)($snapshot['value'] ?? ''), $universeId)) {
        fb_delete_if_match($path, (string)$snapshot['etag']);
    }
}

function birthday_reserve_identifier(string $node, string $key, string $universeId): bool
{
    $path = birthday_path($node) . '/' . znews_firebase_key($key, strtolower($node) . '_key');
    $snapshot = fb_get_with_etag($path);
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null) || $snapshot['value'] !== null) {
        return false;
    }
    $write = fb_put_if_match($path, $universeId, (string)$snapshot['etag']);
    return !empty($write['ok']);
}

function birthday_identifiers(array $draft, string $universeId): array
{
    $prefix = birthday_name_prefix((string)$draft['name']);
    $dateCode = (string)((int)$draft['birthday_day']) . (string)((int)$draft['birthday_month']);
    for ($attempt = 0; $attempt < 24; $attempt++) {
        $starId = $prefix . '-' . $dateCode . '-' . birthday_random_code(4);
        $slug = strtolower($prefix . '-' . $dateCode . '-' . birthday_random_code(12));
        if (!birthday_reserve_identifier('SLUGS', $slug, $universeId)) {
            continue;
        }
        if (birthday_reserve_identifier('STAR_IDS', $starId, $universeId)) {
            return ['slug' => $slug, 'star_id' => $starId];
        }
        birthday_release_reservation('SLUGS', $slug, $universeId);
    }
    api_response(false, 'BIRTHDAY_IDENTIFIER_UNAVAILABLE', 'A unique Universe could not be created. Please try again.', [], 503);
}

function birthday_recovery_code(string $value): string
{
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($value)) ?? '');
    if (preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{20}$/D', $code) !== 1) {
        api_response(false, 'BIRTHDAY_RECOVERY_CODE_INVALID', 'Recovery code is invalid.', [], 422);
    }
    return $code;
}

function birthday_idempotency_path(string $key): string
{
    $key = znews_idempotency_key($key);
    return birthday_path('IDEMPOTENCY') . '/' . hash('sha256', $key);
}

function birthday_generate(string $draftId, string $draftToken, string $recoveryCode, string $idempotencyKey): array
{
    $draftId = znews_firebase_key($draftId, 'draft_id');
    $draftToken = strtoupper(trim($draftToken));
    $idempotencyPath = birthday_idempotency_path($idempotencyKey);
    $fingerprint = hash('sha256', $draftId . '|' . birthday_token_hash($draftToken));
    $snapshot = fb_get_with_etag($idempotencyPath);
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        api_response(false, 'BIRTHDAY_GENERATION_UNAVAILABLE', 'Your Universe could not be generated. Please try again.', [], 503);
    }
    if (is_array($snapshot['value'] ?? null)) {
        $existing = (array)$snapshot['value'];
        if (!hash_equals((string)($existing['fingerprint'] ?? ''), $fingerprint)) {
            api_response(false, 'BIRTHDAY_IDEMPOTENCY_CONFLICT', 'This generation request conflicts with another draft.', [], 409);
        }
        if (strtoupper((string)($existing['status'] ?? '')) === 'COMPLETE') {
            $universe = birthday_universe_by_id((string)($existing['universe_id'] ?? ''));
            if (is_array($universe)) {
                return ['universe' => birthday_public_universe($universe), 'idempotent_replay' => true];
            }
        }
        if (strtoupper((string)($existing['status'] ?? '')) === 'PROCESSING'
            && (int)($existing['expires_at'] ?? 0) > birthday_now()) {
            api_response(false, 'BIRTHDAY_GENERATION_IN_PROGRESS', 'This Universe is already being generated. Please retry shortly.', [], 409);
        }
    }

    $draft = birthday_load_draft($draftId, $draftToken);
    birthday_rate_limit('generate', (int)birthday_settings()['generation_per_hour'], 3600, true);
    $recoveryCode = birthday_recovery_code($recoveryCode);
    $now = birthday_now();
    $universeId = znews_make_id('ZBU');
    $claim = [
        'status' => 'PROCESSING',
        'fingerprint' => $fingerprint,
        'universe_id' => $universeId,
        'created_at' => $now,
        'expires_at' => $now + 3600,
    ];
    $claimed = fb_put_if_match($idempotencyPath, $claim, (string)$snapshot['etag']);
    if (empty($claimed['ok'])) {
        api_response(false, 'BIRTHDAY_GENERATION_BUSY', 'This Universe is already being generated. Please retry shortly.', [], 409);
    }

    $identifiers = birthday_identifiers($draft, $universeId);
    $settings = birthday_settings();
    $recoveryHash = password_hash($recoveryCode, PASSWORD_DEFAULT);
    if (!is_string($recoveryHash) || $recoveryHash === '') {
        birthday_release_reservation('SLUGS', $identifiers['slug'], $universeId);
        birthday_release_reservation('STAR_IDS', $identifiers['star_id'], $universeId);
        api_response(false, 'BIRTHDAY_GENERATION_UNAVAILABLE', 'Your Universe could not be generated. Please try again.', [], 503);
    }
    $row = [
        'id' => $universeId,
        'slug' => $identifiers['slug'],
        'star_id' => $identifiers['star_id'],
        'name' => (string)$draft['name'],
        'birthday_day' => (int)$draft['birthday_day'],
        'birthday_month' => (int)$draft['birthday_month'],
        'birthday_year' => (int)$draft['birthday_year'],
        'sender_name' => (string)$draft['sender_name'],
        'message' => (string)$draft['message'],
        'photo_media_id' => (string)($draft['photo_media_id'] ?? ''),
        'template_id' => (string)$draft['template_id'],
        'music_id' => (string)$draft['music_id'],
        'locale' => birthday_locale($draft['locale'] ?? 'en'),
        'visibility' => birthday_visibility($draft['visibility'] ?? 'UNLISTED', $settings),
        'share_photo' => !empty($draft['share_photo']),
        'status' => 'ACTIVE',
        'owner_uid' => '',
        'recovery_hash' => $recoveryHash,
        'claimed_at' => 0,
        'view_count' => 0,
        'share_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $now + ((int)$settings['retention_days'] * 86400),
    ];
    $complete = array_merge($claim, [
        'status' => 'COMPLETE',
        'slug' => $identifiers['slug'],
        'star_id' => $identifiers['star_id'],
        'completed_at' => $now,
        'expires_at' => $now + 86400,
    ]);
    $updates = [
        birthday_path('UNIVERSES', $universeId) => $row,
        birthday_path('DRAFTS', $draftId) . '/status' => 'GENERATED',
        birthday_path('DRAFTS', $draftId) . '/universe_id' => $universeId,
        birthday_path('DRAFTS', $draftId) . '/updated_at' => $now,
        $idempotencyPath => $complete,
    ];
    $mediaId = (string)($row['photo_media_id'] ?? '');
    if ($mediaId !== '') {
        $updates[birthday_path('MEDIA', $mediaId) . '/status'] = 'ACTIVE';
        $updates[birthday_path('MEDIA', $mediaId) . '/universe_id'] = $universeId;
        $updates[birthday_path('MEDIA', $mediaId) . '/updated_at'] = $now;
    }
    if (!fb_patch('', $updates)) {
        birthday_release_reservation('SLUGS', $identifiers['slug'], $universeId);
        birthday_release_reservation('STAR_IDS', $identifiers['star_id'], $universeId);
        fb_patch($idempotencyPath, ['status' => 'FAILED', 'updated_at' => $now]);
        api_response(false, 'BIRTHDAY_GENERATION_FAILED', 'Your Universe could not be generated. Please try again.', [], 503);
    }
    birthday_increment_counter(birthday_path('DAILY_ANALYTICS') . '/' . gmdate('Y-m-d', $now) . '/CREATED');
    if (function_exists('system_log')) {
        system_log('BIRTHDAY_UNIVERSE_CREATED', $universeId, 'Birthday Universe created', [
            'template_id' => $row['template_id'],
            'visibility' => $row['visibility'],
        ]);
    }
    return ['universe' => birthday_public_universe($row), 'idempotent_replay' => false];
}

function birthday_universe_by_id(string $id): ?array
{
    if (preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $id) !== 1) {
        return null;
    }
    $row = fb_get(birthday_path('UNIVERSES', $id));
    return is_array($row) ? $row : null;
}

function birthday_universe_by_slug(string $slug, bool $includeUnavailable = false): ?array
{
    $slug = strtolower(trim($slug));
    if (preg_match('/^[a-z0-9-]{8,100}$/D', $slug) !== 1) {
        return null;
    }
    $id = fb_get(birthday_path('SLUGS') . '/' . $slug);
    if (!is_string($id) || $id === '') {
        return null;
    }
    $row = birthday_universe_by_id($id);
    if (!is_array($row)) {
        return null;
    }
    if (!$includeUnavailable) {
        if (strtoupper((string)($row['status'] ?? '')) !== 'ACTIVE'
            || (int)($row['expires_at'] ?? 0) <= birthday_now()) {
            return null;
        }
    }
    return $row;
}

function birthday_public_universe(array $row, ?array $templateLookup = null, ?array $musicLookup = null): array
{
    $slug = (string)($row['slug'] ?? '');
    $mediaId = (string)($row['photo_media_id'] ?? '');
    $templateId = (string)($row['template_id'] ?? 'cosmic');
    $musicId = (string)($row['music_id'] ?? '');
    $template = $templateLookup === null
        ? birthday_template($templateId, true)
        : ($templateLookup[$templateId] ?? null);
    $music = $musicLookup === null
        ? birthday_music_by_id($musicId)
        : ($musicLookup[$musicId] ?? null);
    return [
        'slug' => $slug,
        'url' => 'https://zsky24.com/u/' . rawurlencode($slug),
        'star_id' => (string)($row['star_id'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'birthday_day' => (int)($row['birthday_day'] ?? 0),
        'birthday_month' => (int)($row['birthday_month'] ?? 0),
        'birthday_year' => (int)($row['birthday_year'] ?? 0),
        'sender_name' => (string)($row['sender_name'] ?? ''),
        'message' => (string)($row['message'] ?? ''),
        'photo_url' => $mediaId !== '' ? '/api/znews/birthday/media.php?id=' . rawurlencode($mediaId) : '',
        'template' => $template,
        'music' => $music,
        'locale' => birthday_locale($row['locale'] ?? 'en'),
        'visibility' => birthday_visibility($row['visibility'] ?? 'UNLISTED'),
        'share_photo' => !empty($row['share_photo']),
        'view_count' => max(0, (int)($row['view_count'] ?? 0)),
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
        'expires_at' => (int)($row['expires_at'] ?? 0),
        'claimed' => trim((string)($row['owner_uid'] ?? '')) !== '',
    ];
}

function birthday_increment_counter(string $path, int $amount = 1): bool
{
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return false;
        }
        $current = is_numeric($snapshot['value'] ?? null) ? (int)$snapshot['value'] : 0;
        $write = fb_put_if_match($path, max(0, $current + $amount), (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(20000);
            continue;
        }
        return !empty($write['ok']);
    }
    return false;
}

function birthday_record_event(array $universe, string $eventType, array $metadata = []): array
{
    $allowed = ['VIEWED', 'TEMPLATE_SELECTED', 'SHARE_CLICKED', 'QR_DOWNLOADED', 'MUSIC_PLAYED', 'AD_DELIVERED'];
    $eventType = strtoupper(trim($eventType));
    if (!in_array($eventType, $allowed, true)) {
        api_response(false, 'BIRTHDAY_EVENT_INVALID', 'Invalid analytics event.', [], 422);
    }
    birthday_rate_limit('event', 120, 3600, false);
    $id = (string)$universe['id'];
    $now = birthday_now();
    $ipHash = birthday_request_ip_hash();
    $counted = true;
    if ($eventType === 'VIEWED') {
        $day = gmdate('Y-m-d', $now);
        $dedupPath = birthday_path('VIEW_DEDUP') . '/' . znews_firebase_key($id, 'universe_id') . '/' . $day . '/' . $ipHash;
        $snapshot = fb_get_with_etag($dedupPath);
        if (!empty($snapshot['ok']) && is_string($snapshot['etag'] ?? null)) {
            if ($snapshot['value'] !== null) {
                $counted = false;
            } else {
                $write = fb_put_if_match($dedupPath, ['created_at' => $now, 'expires_at' => $now + 172800], (string)$snapshot['etag']);
                $counted = !empty($write['ok']);
            }
        } else {
            $counted = false;
        }
        if ($counted) {
            birthday_increment_counter(birthday_path('UNIVERSES', $id) . '/view_count');
        }
    } elseif ($eventType === 'SHARE_CLICKED') {
        birthday_increment_counter(birthday_path('UNIVERSES', $id) . '/share_count');
    }
    $eventId = znews_make_id('ZBE');
    $safeMetadata = [];
    foreach (['channel', 'template_id', 'music_id'] as $field) {
        $value = trim((string)($metadata[$field] ?? ''));
        if ($value !== '' && strlen($value) <= 80) {
            $safeMetadata[$field] = preg_replace('/[^A-Za-z0-9_-]/', '', $value) ?? '';
        }
    }
    fb_put(birthday_path('EVENTS', $eventId), [
        'id' => $eventId,
        'universe_id' => $id,
        'event_type' => $eventType,
        'metadata' => $safeMetadata,
        'counted' => $counted,
        'created_at' => $now,
    ]);
    if ($eventType === 'AD_DELIVERED') {
        $adEventId = znews_make_id('ZBA');
        fb_put(birthday_path('AD_EVENTS', $adEventId), [
            'id' => $adEventId,
            'context_id' => $id,
            'slot' => 'birthday_public',
            'provider' => strtoupper((string)($safeMetadata['channel'] ?? 'ADSTERRA')),
            'event_type' => 'DELIVERED',
            'rewarded' => false,
            'created_at' => $now,
        ]);
    }
    if ($counted) {
        birthday_increment_counter(birthday_path('DAILY_ANALYTICS') . '/' . gmdate('Y-m-d', $now) . '/' . $eventType);
    }
    return ['recorded' => true, 'counted' => $counted];
}

function birthday_require_account(): array
{
    $auth = auth_require_user(true);
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $uid = trim((string)($user['uid'] ?? ''));
    $role = strtoupper(trim((string)($user['role'] ?? '')));
    if ($uid === '' || !in_array($role, ['USER', 'RETAILER'], true)) {
        api_response(false, 'BIRTHDAY_ACCOUNT_REQUIRED', 'A Z-Pay user account is required.', [], 403);
    }
    return $auth;
}

function birthday_claim(string $slug, string $recoveryCode, array $auth): array
{
    birthday_rate_limit('claim_' . substr(hash('sha256', $slug), 0, 16), 5, 900, true);
    $row = birthday_universe_by_slug($slug, true);
    if (!is_array($row) || strtoupper((string)($row['status'] ?? '')) !== 'ACTIVE') {
        api_response(false, 'BIRTHDAY_UNIVERSE_NOT_FOUND', 'Universe not found.', [], 404);
    }
    $uid = znews_firebase_key((string)($auth['user']['uid'] ?? ''), 'uid');
    $ownerUid = trim((string)($row['owner_uid'] ?? ''));
    if ($ownerUid !== '') {
        if (hash_equals($ownerUid, $uid)) {
            return birthday_public_universe($row);
        }
        api_response(false, 'BIRTHDAY_ALREADY_CLAIMED', 'This Universe already belongs to another account.', [], 409);
    }
    $recoveryCode = birthday_recovery_code($recoveryCode);
    $storedHash = (string)($row['recovery_hash'] ?? '');
    if ($storedHash === '' || !password_verify($recoveryCode, $storedHash)) {
        api_response(false, 'BIRTHDAY_RECOVERY_CODE_INCORRECT', 'Recovery code is incorrect.', [], 403);
    }
    $path = birthday_path('UNIVERSES', (string)$row['id']);
    $snapshot = fb_get_with_etag($path);
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null) || !is_array($snapshot['value'] ?? null)) {
        api_response(false, 'BIRTHDAY_CLAIM_FAILED', 'Ownership could not be verified. Please try again.', [], 503);
    }
    $current = (array)$snapshot['value'];
    if (trim((string)($current['owner_uid'] ?? '')) !== '') {
        api_response(false, 'BIRTHDAY_ALREADY_CLAIMED', 'This Universe has already been claimed.', [], 409);
    }
    $now = birthday_now();
    $current['owner_uid'] = $uid;
    $current['recovery_hash'] = '';
    $current['claimed_at'] = $now;
    $current['updated_at'] = $now;
    $write = fb_put_if_match($path, $current, (string)$snapshot['etag']);
    if (empty($write['ok'])) {
        api_response(false, 'BIRTHDAY_CLAIM_CONFLICT', 'Ownership changed while this request was being processed.', [], 409);
    }
    fb_put(birthday_path('OWNERS') . '/' . $uid . '/' . (string)$row['id'], [
        'universe_id' => (string)$row['id'],
        'slug' => (string)$row['slug'],
        'claimed_at' => $now,
    ]);
    return birthday_public_universe($current);
}

function birthday_owned_universe(string $slug, array $auth): array
{
    $row = birthday_universe_by_slug($slug, true);
    $uid = trim((string)($auth['user']['uid'] ?? ''));
    if (!is_array($row) || $uid === '' || !hash_equals(trim((string)($row['owner_uid'] ?? '')), $uid)) {
        api_response(false, 'BIRTHDAY_OWNER_REQUIRED', 'You do not have permission to manage this Universe.', [], 403);
    }
    return $row;
}

function birthday_update_owned(array $row, array $payload): array
{
    $now = birthday_now();
    $oldStar = (string)$row['star_id'];
    $identityChanged = (string)$row['name'] !== (string)$payload['name']
        || (int)$row['birthday_day'] !== (int)$payload['birthday_day']
        || (int)$row['birthday_month'] !== (int)$payload['birthday_month'];
    if ($identityChanged) {
        $temporary = $payload;
        $newIdentifiers = null;
        for ($attempt = 0; $attempt < 24; $attempt++) {
            $star = birthday_name_prefix((string)$temporary['name'])
                . '-' . (int)$temporary['birthday_day'] . (int)$temporary['birthday_month']
                . '-' . birthday_random_code(4);
            if (birthday_reserve_identifier('STAR_IDS', $star, (string)$row['id'])) {
                $newIdentifiers = $star;
                break;
            }
        }
        if ($newIdentifiers === null) {
            api_response(false, 'BIRTHDAY_STAR_ID_UNAVAILABLE', 'A new star ID could not be created.', [], 503);
        }
        $row['star_id'] = $newIdentifiers;
    }
    foreach (['name', 'birthday_day', 'birthday_month', 'birthday_year', 'sender_name', 'message', 'template_id', 'music_id', 'locale', 'visibility', 'share_photo'] as $field) {
        $row[$field] = $payload[$field];
    }
    $row['updated_at'] = $now;
    if (!fb_put(birthday_path('UNIVERSES', (string)$row['id']), $row)) {
        if ($identityChanged) {
            birthday_release_reservation('STAR_IDS', (string)$row['star_id'], (string)$row['id']);
            $row['star_id'] = $oldStar;
        }
        api_response(false, 'BIRTHDAY_UPDATE_FAILED', 'Changes could not be saved. Please try again.', [], 503);
    }
    if ($identityChanged) {
        birthday_release_reservation('STAR_IDS', $oldStar, (string)$row['id']);
    }
    return birthday_public_universe($row);
}

function birthday_renew_owned(array $row): array
{
    if (strtoupper((string)($row['status'] ?? '')) !== 'ACTIVE') {
        api_response(false, 'BIRTHDAY_RENEW_NOT_ALLOWED', 'Only an active Universe can be renewed.', [], 409);
    }
    $now = birthday_now();
    $base = max($now, (int)($row['expires_at'] ?? 0));
    $row['expires_at'] = $base + ((int)birthday_settings()['renewal_days'] * 86400);
    $row['updated_at'] = $now;
    if (!fb_put(birthday_path('UNIVERSES', (string)$row['id']), $row)) {
        api_response(false, 'BIRTHDAY_RENEW_FAILED', 'Universe could not be renewed. Please try again.', [], 503);
    }
    return birthday_public_universe($row);
}

function birthday_remove_photo_owned(array $row): array
{
    $mediaId = trim((string)($row['photo_media_id'] ?? ''));
    if ($mediaId === '') {
        return birthday_public_universe($row);
    }
    $now = birthday_now();
    $row['photo_media_id'] = '';
    $row['share_photo'] = false;
    $row['updated_at'] = $now;
    $updates = [
        birthday_path('UNIVERSES', (string)$row['id']) => $row,
        birthday_path('MEDIA', $mediaId) . '/status' => 'REPLACED',
        birthday_path('MEDIA', $mediaId) . '/updated_at' => $now,
    ];
    if (!fb_patch('', $updates)) {
        api_response(false, 'BIRTHDAY_PHOTO_REMOVE_FAILED', 'Photo could not be removed. Please try again.', [], 503);
    }
    return birthday_public_universe($row);
}

function birthday_delete_owned(array $row, string $actorUid): void
{
    $now = birthday_now();
    $row['status'] = 'DELETED';
    $row['deleted_at'] = $now;
    $row['deleted_by'] = $actorUid;
    $row['updated_at'] = $now;
    if (!fb_put(birthday_path('UNIVERSES', (string)$row['id']), $row)) {
        api_response(false, 'BIRTHDAY_DELETE_FAILED', 'Universe could not be deleted. Please try again.', [], 503);
    }
}

function birthday_report(array $universe, array $body): array
{
    birthday_rate_limit('report', 8, 3600, true);
    $reason = strtoupper(trim((string)($body['reason'] ?? 'OTHER')));
    $allowed = ['PRIVACY', 'ABUSE', 'SPAM', 'COPYRIGHT', 'OTHER'];
    if (!in_array($reason, $allowed, true)) {
        $reason = 'OTHER';
    }
    $details = birthday_optional_text($body['details'] ?? '', 300, 'report details');
    $id = znews_make_id('ZBR');
    $now = birthday_now();
    $row = [
        'id' => $id,
        'universe_id' => (string)$universe['id'],
        'slug' => (string)$universe['slug'],
        'reason' => $reason,
        'details' => $details,
        'status' => 'OPEN',
        'created_at' => $now,
        'updated_at' => $now,
    ];
    if (!fb_put(birthday_path('REPORTS', $id), $row)) {
        api_response(false, 'BIRTHDAY_REPORT_FAILED', 'Your report could not be submitted.', [], 503);
    }
    return ['report_id' => $id];
}
