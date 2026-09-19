<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function birthday_admin_response(bool $ok, string $code, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    echo json_encode([
        'ok' => $ok,
        'success' => $ok,
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    session_name('zawtopup_admin_v3');
    session_start();
}
$token = trim((string)($_SESSION['admin_session_token'] ?? ''));
$expiresAt = (int)($_SESSION['admin_session_expires_at'] ?? 0);
$csrf = trim((string)($_SESSION['admin_csrf'] ?? ''));
if ($token === '' || $expiresAt <= time()) {
    birthday_admin_response(false, 'SESSION_EXPIRED', 'Admin session expired.', [], 401);
}
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    birthday_admin_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method.', [], 405);
}
if ($method === 'POST') {
    $provided = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($csrf === '' || $provided === '' || !hash_equals($csrf, $provided)) {
        birthday_admin_response(false, 'CSRF_INVALID', 'Security token mismatch.', [], 403);
    }
}
$_SERVER['HTTP_X_SESSION_TOKEN'] = $token;
@session_write_close();

require_once dirname(__DIR__) . '/znews/bootstrap.php';
require_once dirname(__DIR__) . '/znews/lib/birthday.php';
require_once dirname(__DIR__) . '/znews/lib/birthday_media.php';

$auth = auth_require_admin_session(true);
$adminUid = trim((string)($auth['user']['uid'] ?? ''));
$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'summary')));

function birthday_admin_rows(string $node): array
{
    $rows = fb_get(birthday_path($node));
    return is_array($rows) ? $rows : [];
}

function birthday_admin_universe_items(): array
{
    $items = [];
    $templateLookup = [];
    foreach (birthday_templates(true) as $template) {
        $templateLookup[(string)$template['id']] = $template;
    }
    $musicLookup = [];
    foreach (birthday_music(true) as $music) {
        $musicLookup[(string)$music['id']] = $music;
    }
    foreach (birthday_admin_rows('UNIVERSES') as $row) {
        if (!is_array($row)) {
            continue;
        }
        $public = birthday_public_universe($row, $templateLookup, $musicLookup);
        $public['id'] = (string)($row['id'] ?? '');
        $public['status'] = strtoupper((string)($row['status'] ?? 'ACTIVE'));
        $public['owner_uid_masked'] = trim((string)($row['owner_uid'] ?? '')) === ''
            ? ''
            : substr(hash('sha256', (string)$row['owner_uid']), 0, 12);
        $items[] = $public;
    }
    usort($items, static fn(array $a, array $b): int => (int)$b['created_at'] <=> (int)$a['created_at']);
    return $items;
}

if ($method === 'GET' && $action === 'summary') {
    $universes = birthday_admin_universe_items();
    $today = gmdate('Y-m-d');
    $month = gmdate('Y-m');
    $totalViews = 0;
    $active = 0;
    $todayCount = 0;
    $monthCount = 0;
    $templates = [];
    $musicUsage = [];
    foreach ($universes as $item) {
        $totalViews += max(0, (int)$item['view_count']);
        $active += $item['status'] === 'ACTIVE' ? 1 : 0;
        $created = (int)$item['created_at'];
        $todayCount += gmdate('Y-m-d', $created) === $today ? 1 : 0;
        $monthCount += gmdate('Y-m', $created) === $month ? 1 : 0;
        $template = (string)($item['template']['id'] ?? 'unknown');
        $templates[$template] = ($templates[$template] ?? 0) + 1;
        $music = (string)($item['music']['id'] ?? 'none');
        $musicUsage[$music] = ($musicUsage[$music] ?? 0) + 1;
    }
    $storageBytes = 0;
    foreach (birthday_admin_rows('MEDIA') as $media) {
        if (is_array($media)) {
            $storageBytes += max(0, (int)($media['size_bytes'] ?? 0));
        }
    }
    foreach (birthday_admin_rows('MUSIC') as $music) {
        if (is_array($music)) {
            $storageBytes += max(0, (int)($music['size_bytes'] ?? 0));
        }
    }
    birthday_admin_response(true, 'BIRTHDAY_ADMIN_SUMMARY_OK', 'Birthday Universe summary loaded.', [
        'metrics' => [
            'total_universes' => count($universes),
            'active_universes' => $active,
            'universes_today' => $todayCount,
            'universes_this_month' => $monthCount,
            'total_views' => $totalViews,
            'storage_bytes' => $storageBytes,
            'ad_events' => count(birthday_admin_rows('AD_EVENTS')),
            'open_reports' => count(array_filter(birthday_admin_rows('REPORTS'), static fn($row): bool => is_array($row) && strtoupper((string)($row['status'] ?? 'OPEN')) === 'OPEN')),
        ],
        'top_templates' => $templates,
        'top_music' => $musicUsage,
        'settings' => birthday_settings(),
        'templates' => birthday_templates(true),
        'music' => birthday_music(true),
    ]);
}

if ($method === 'GET' && $action === 'universes') {
    $status = strtoupper(trim((string)($_GET['status'] ?? 'ALL')));
    $search = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string)($_GET['search'] ?? '')), 'UTF-8')
        : strtolower(trim((string)($_GET['search'] ?? '')));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(5, min(50, (int)($_GET['limit'] ?? 20)));
    $items = array_values(array_filter(birthday_admin_universe_items(), static function (array $item) use ($status, $search): bool {
        if ($status !== 'ALL' && (string)$item['status'] !== $status) {
            return false;
        }
        if ($search === '') {
            return true;
        }
        $haystack = implode(' ', [(string)$item['name'], (string)$item['slug'], (string)$item['star_id']]);
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
        return str_contains($haystack, $search);
    }));
    $offset = ($page - 1) * $limit;
    birthday_admin_response(true, 'BIRTHDAY_ADMIN_UNIVERSES_OK', 'Universe records loaded.', [
        'items' => array_slice($items, $offset, $limit),
        'page' => $page,
        'limit' => $limit,
        'total' => count($items),
        'has_more' => $offset + $limit < count($items),
    ]);
}

if ($method === 'GET' && $action === 'reports') {
    $items = [];
    foreach (birthday_admin_rows('REPORTS') as $row) {
        if (is_array($row)) {
            $items[] = $row;
        }
    }
    usort($items, static fn(array $a, array $b): int => (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0));
    birthday_admin_response(true, 'BIRTHDAY_ADMIN_REPORTS_OK', 'Reports loaded.', ['items' => array_slice($items, 0, 100)]);
}

if ($method !== 'POST') {
    birthday_admin_response(false, 'BIRTHDAY_ADMIN_ACTION_INVALID', 'Invalid Birthday Universe admin action.', [], 404);
}

$contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
$body = str_contains($contentType, 'application/json') ? api_read_json_body() : $_POST;

if ($action === 'settings_save') {
    $existing = birthday_settings();
    $row = [
        'enabled' => znews_bool($body['enabled'] ?? $existing['enabled'], true),
        'retention_days' => max(7, min(3650, (int)($body['retention_days'] ?? $existing['retention_days']))),
        'renewal_days' => max(7, min(3650, (int)($body['renewal_days'] ?? $existing['renewal_days']))),
        'generation_per_hour' => max(1, min(50, (int)($body['generation_per_hour'] ?? $existing['generation_per_hour']))),
        'draft_ttl_seconds' => max(3600, min(604800, (int)($body['draft_ttl_seconds'] ?? $existing['draft_ttl_seconds']))),
        'allow_public_indexing' => znews_bool($body['allow_public_indexing'] ?? $existing['allow_public_indexing'], true),
        'default_locale' => birthday_locale($body['default_locale'] ?? $existing['default_locale']),
        'updated_by' => $adminUid,
        'updated_at' => birthday_now(),
    ];
    if (!fb_put(birthday_path('SETTINGS') . '/PUBLIC', $row)) {
        birthday_admin_response(false, 'BIRTHDAY_SETTINGS_SAVE_FAILED', 'Settings could not be saved.', [], 503);
    }
    birthday_admin_response(true, 'BIRTHDAY_SETTINGS_SAVED', 'Birthday Universe settings saved.', ['settings' => birthday_settings()]);
}

if ($action === 'template_save') {
    $id = strtolower(trim((string)($body['id'] ?? '')));
    if (!isset(birthday_template_registry()[$id])) {
        birthday_admin_response(false, 'BIRTHDAY_TEMPLATE_INVALID', 'Unknown deployed template.', [], 422);
    }
    $name = birthday_bounded_text($body['name'] ?? birthday_template_registry()[$id]['name'], 1, 50, 'template name');
    $description = birthday_bounded_text($body['description'] ?? birthday_template_registry()[$id]['description'], 1, 180, 'template description');
    $accent = trim((string)($body['accent'] ?? ''));
    $secondary = trim((string)($body['secondary'] ?? ''));
    if (preg_match('/^#[A-Fa-f0-9]{6}$/D', $accent) !== 1 || preg_match('/^#[A-Fa-f0-9]{6}$/D', $secondary) !== 1) {
        birthday_admin_response(false, 'BIRTHDAY_TEMPLATE_COLOR_INVALID', 'Template colors must be six-digit hex values.', [], 422);
    }
    $row = [
        'name' => $name,
        'description' => $description,
        'accent' => strtolower($accent),
        'secondary' => strtolower($secondary),
        'active' => znews_bool($body['active'] ?? true, true),
        'updated_by' => $adminUid,
        'updated_at' => birthday_now(),
    ];
    if (!fb_put(birthday_path('TEMPLATE_SETTINGS') . '/' . $id, $row)) {
        birthday_admin_response(false, 'BIRTHDAY_TEMPLATE_SAVE_FAILED', 'Template settings could not be saved.', [], 503);
    }
    birthday_admin_response(true, 'BIRTHDAY_TEMPLATE_SAVED', 'Template settings saved.', ['templates' => birthday_templates(true)]);
}

if ($action === 'music_upload') {
    if (!znews_bool($_POST['rights_confirmed'] ?? false, false)) {
        birthday_admin_response(false, 'BIRTHDAY_MUSIC_RIGHTS_REQUIRED', 'Confirm that this track is licensed for platform use.', [], 422);
    }
    $file = is_array($_FILES['music'] ?? null) ? (array)$_FILES['music'] : [];
    if (!$file) {
        birthday_admin_response(false, 'BIRTHDAY_MUSIC_REQUIRED', 'Choose a music file.', [], 422);
    }
    $validated = birthday_music_validate($file);
    $row = birthday_music_store(
        $validated,
        trim((string)($_POST['name'] ?? '')),
        max(0, (int)($_POST['duration'] ?? 0)),
        $adminUid
    );
    birthday_admin_response(true, 'BIRTHDAY_MUSIC_CREATED', 'Licensed music track uploaded.', ['music' => $row], 201);
}

if ($action === 'music_status') {
    $id = znews_firebase_key($body['id'] ?? '', 'music_id');
    $row = fb_get(birthday_path('MUSIC', $id));
    if (!is_array($row)) {
        birthday_admin_response(false, 'BIRTHDAY_MUSIC_NOT_FOUND', 'Music track not found.', [], 404);
    }
    $row['active'] = znews_bool($body['active'] ?? false, false);
    $row['updated_at'] = birthday_now();
    $row['updated_by'] = $adminUid;
    if (!fb_put(birthday_path('MUSIC', $id), $row)) {
        birthday_admin_response(false, 'BIRTHDAY_MUSIC_UPDATE_FAILED', 'Music status could not be changed.', [], 503);
    }
    birthday_admin_response(true, 'BIRTHDAY_MUSIC_UPDATED', 'Music status updated.', ['music' => birthday_music(true)]);
}

if ($action === 'music_edit') {
    $id = znews_firebase_key($body['id'] ?? '', 'music_id');
    $row = fb_get(birthday_path('MUSIC', $id));
    if (!is_array($row)) {
        birthday_admin_response(false, 'BIRTHDAY_MUSIC_NOT_FOUND', 'Music track not found.', [], 404);
    }
    $row['name'] = birthday_bounded_text($body['name'] ?? '', 1, 80, 'music name');
    $row['duration'] = max(0, min(7200, (int)($body['duration'] ?? 0)));
    $row['updated_at'] = birthday_now();
    $row['updated_by'] = $adminUid;
    if (!fb_put(birthday_path('MUSIC', $id), $row)) {
        birthday_admin_response(false, 'BIRTHDAY_MUSIC_UPDATE_FAILED', 'Music details could not be changed.', [], 503);
    }
    birthday_admin_response(true, 'BIRTHDAY_MUSIC_UPDATED', 'Music details updated.', ['music' => birthday_music(true)]);
}

if ($action === 'universe_status') {
    $id = znews_firebase_key($body['id'] ?? '', 'universe_id');
    $status = strtoupper(trim((string)($body['status'] ?? '')));
    if (!in_array($status, ['ACTIVE', 'BLOCKED', 'DELETED', 'EXPIRED'], true)) {
        birthday_admin_response(false, 'BIRTHDAY_STATUS_INVALID', 'Invalid Universe status.', [], 422);
    }
    $row = birthday_universe_by_id($id);
    if (!is_array($row)) {
        birthday_admin_response(false, 'BIRTHDAY_UNIVERSE_NOT_FOUND', 'Universe not found.', [], 404);
    }
    $row['status'] = $status;
    $row['updated_at'] = birthday_now();
    $row['moderated_by'] = $adminUid;
    $row['moderated_at'] = birthday_now();
    if ($status === 'DELETED') {
        $row['deleted_at'] = birthday_now();
    } elseif ($status === 'EXPIRED') {
        $row['expired_at'] = birthday_now();
    } elseif ($status === 'ACTIVE') {
        unset($row['deleted_at'], $row['expired_at']);
    }
    if (!fb_put(birthday_path('UNIVERSES', $id), $row)) {
        birthday_admin_response(false, 'BIRTHDAY_STATUS_UPDATE_FAILED', 'Universe status could not be changed.', [], 503);
    }
    birthday_admin_response(true, 'BIRTHDAY_STATUS_UPDATED', 'Universe status updated.', ['universe' => birthday_public_universe($row)]);
}

if ($action === 'report_resolve') {
    $id = znews_firebase_key($body['id'] ?? '', 'report_id');
    $row = fb_get(birthday_path('REPORTS', $id));
    if (!is_array($row)) {
        birthday_admin_response(false, 'BIRTHDAY_REPORT_NOT_FOUND', 'Report not found.', [], 404);
    }
    $row['status'] = 'RESOLVED';
    $row['resolved_by'] = $adminUid;
    $row['resolved_at'] = birthday_now();
    $row['updated_at'] = birthday_now();
    if (!fb_put(birthday_path('REPORTS', $id), $row)) {
        birthday_admin_response(false, 'BIRTHDAY_REPORT_UPDATE_FAILED', 'Report could not be resolved.', [], 503);
    }
    birthday_admin_response(true, 'BIRTHDAY_REPORT_RESOLVED', 'Report resolved.', ['report' => $row]);
}

birthday_admin_response(false, 'BIRTHDAY_ADMIN_ACTION_INVALID', 'Invalid Birthday Universe admin action.', [], 404);
