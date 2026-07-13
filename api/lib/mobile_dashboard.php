<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/notifications.php';

function zpay_dash_bool($value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if ($value === null || $value === '') {
        return $default;
    }

    $text = strtoupper(trim((string)$value));
    if (in_array($text, ['1', 'TRUE', 'YES', 'ON', 'ACTIVE', 'ENABLED'], true)) {
        return true;
    }
    if (in_array($text, ['0', 'FALSE', 'NO', 'OFF', 'INACTIVE', 'DISABLED'], true)) {
        return false;
    }

    return $default;
}

function zpay_dash_clean_string($value, int $max = 200): string
{
    $text = trim((string)$value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', '', $text) ?? '';
    if ($max > 0 && strlen($text) > $max) {
        $text = substr($text, 0, $max);
    }
    return $text;
}

function zpay_dash_default_theme(): array
{
    return [
        'theme_name' => 'Z-Pay Swift',
        'mode' => 'dark',
        'primary_color' => '#082A5E',
        'secondary_color' => '#0E3A78',
        'accent_color' => '#2FE88B',
        'gradient_start' => '#082A5E',
        'gradient_end' => '#01A884',
        'card_color' => '#123866',
        'surface_color' => '#0B244A',
        'text_color' => '#FFFFFF',
        'muted_text_color' => '#C7D3E8',
        'button_color' => '#2FE88B',
    ];
}

function zpay_dash_valid_hex_color($value): string
{
    $color = strtoupper(trim((string)$value));
    return preg_match('/^#[0-9A-F]{6}$/', $color) === 1 ? $color : '';
}

function zpay_dash_theme(array $row = []): array
{
    $defaults = zpay_dash_default_theme();
    $themeRow = is_array($row['theme'] ?? null) ? $row['theme'] : [];
    $theme = $defaults;

    $themeName = zpay_dash_clean_string($themeRow['theme_name'] ?? $row['theme_name'] ?? $defaults['theme_name'], 80);
    $theme['theme_name'] = $themeName !== '' ? $themeName : $defaults['theme_name'];

    $mode = strtolower(zpay_dash_clean_string($themeRow['mode'] ?? $row['mode'] ?? $defaults['mode'], 20));
    $theme['mode'] = in_array($mode, ['dark', 'light'], true) ? $mode : $defaults['mode'];

    foreach ([
        'primary_color',
        'secondary_color',
        'accent_color',
        'gradient_start',
        'gradient_end',
        'card_color',
        'surface_color',
        'text_color',
        'muted_text_color',
        'button_color',
    ] as $key) {
        $color = zpay_dash_valid_hex_color($themeRow[$key] ?? $row[$key] ?? '');
        $theme[$key] = $color !== '' ? $color : $defaults[$key];
    }

    return $theme;
}

function zpay_dash_theme_from_input(array $body, array $existing = []): array
{
    $base = zpay_dash_theme($existing);
    $input = is_array($body['theme'] ?? null) ? array_merge($body, $body['theme']) : $body;
    return zpay_dash_theme(array_merge($base, $input));
}

function zpay_dash_allowed_mobile_role(string $role): bool
{
    $role = function_exists('auth_status_value') ? auth_status_value($role) : strtoupper(trim($role));
    return in_array($role, ['USER', 'RETAILER'], true);
}

function zpay_dash_require_mobile_user(bool $touchSession = true): array
{
    $auth = auth_require_user($touchSession);
    $user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
    $role = function_exists('auth_status_value')
        ? auth_status_value($user['role'] ?? '')
        : strtoupper(trim((string)($user['role'] ?? '')));

    if (!zpay_dash_allowed_mobile_role($role)) {
        api_response(false, 'ROLE_NOT_ALLOWED', 'This account type is not allowed in this app.', [], 403);
    }

    $auth['user']['role'] = $role;
    return $auth;
}

function zpay_dash_require_admin_or_subadmin(bool $touchSession = true): array
{
    $auth = auth_require_user($touchSession);
    $user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
    $role = function_exists('auth_status_value')
        ? auth_status_value($user['role'] ?? '')
        : strtoupper(trim((string)($user['role'] ?? '')));

    if (!in_array($role, ['ADMIN', 'SUBADMIN'], true)) {
        api_response(false, 'FORBIDDEN', 'Only ADMIN or SUBADMIN can manage dashboard settings.', [], 403);
    }

    $auth['user']['role'] = $role;
    return $auth;
}

function zpay_dash_mask_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
    $len = strlen($digits);
    if ($len <= 4) {
        return $digits;
    }
    if ($len <= 7) {
        return substr($digits, 0, 2) . str_repeat('*', max(1, $len - 4)) . substr($digits, -2);
    }
    return substr($digits, 0, 3) . str_repeat('*', max(3, $len - 6)) . substr($digits, -3);
}

function zpay_dash_mask_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    if (function_exists('mb_substr') && function_exists('mb_strlen')) {
        return mb_substr($name, 0, 1) . str_repeat('*', max(2, min(8, mb_strlen($name) - 1)));
    }
    return substr($name, 0, 1) . str_repeat('*', max(2, min(8, strlen($name) - 1)));
}

function zpay_dash_config(): array
{
    $row = fb_get('DASHBOARD_CONFIG');
    $row = is_array($row) ? $row : [];
    if (!array_key_exists('notice_text', $row)) {
        $row['notice_text'] = 'টাকা পাঠানোর সব থেকে সহজ উপায় "Z-Pay Swift"';
    }

    return [
        'notice_active' => zpay_dash_bool($row['notice_active'] ?? true, true),
        'notice_text' => zpay_dash_clean_string(
            $row['notice_text'] ?? 'টাকা পাঠানোর সব থেকে সহজ উপায় “Z-Pay Swift”',
            300
        ),
        'dashboard_active' => zpay_dash_bool($row['dashboard_active'] ?? true, true),
        'theme' => zpay_dash_theme($row),
        'updated_at' => (int)($row['updated_at'] ?? 0),
        'updated_by' => zpay_dash_clean_string($row['updated_by'] ?? '', 80),
    ];
}

function zpay_dash_default_services(): array
{
    return [
        'ADD_MONEY' => [
            'service_key' => 'ADD_MONEY',
            'title' => 'Add Money',
            'icon_key' => 'add_money',
            'active' => true,
            'sort_order' => 10,
            'action_type' => 'SCREEN',
            'action_value' => 'ADD_MONEY',
            'allowed_roles' => ['USER', 'RETAILER'],
        ],
        'TRANSFER' => [
            'service_key' => 'TRANSFER',
            'title' => 'Transfer',
            'icon_key' => 'transfer',
            'active' => true,
            'sort_order' => 20,
            'action_type' => 'SCREEN',
            'action_value' => 'TRANSFER',
            'allowed_roles' => ['USER', 'RETAILER'],
        ],
        'TOPUP' => [
            'service_key' => 'TOPUP',
            'title' => 'Top-Up',
            'icon_key' => 'topup',
            'active' => true,
            'sort_order' => 30,
            'action_type' => 'SCREEN',
            'action_value' => 'TOPUP',
            'allowed_roles' => ['USER', 'RETAILER'],
        ],
        'BKASH' => [
            'service_key' => 'BKASH',
            'title' => 'BKash',
            'icon_key' => 'bkash',
            'active' => true,
            'sort_order' => 40,
            'action_type' => 'SCREEN',
            'action_value' => 'BKASH',
            'allowed_roles' => ['USER', 'RETAILER'],
        ],
        'NAGAD' => [
            'service_key' => 'NAGAD',
            'title' => 'Nagad',
            'icon_key' => 'nagad',
            'active' => true,
            'sort_order' => 50,
            'action_type' => 'SCREEN',
            'action_value' => 'NAGAD',
            'allowed_roles' => ['USER', 'RETAILER'],
        ],
        'HISTORY' => [
            'service_key' => 'HISTORY',
            'title' => 'History',
            'icon_key' => 'history',
            'active' => true,
            'sort_order' => 60,
            'action_type' => 'SCREEN',
            'action_value' => 'HISTORY',
            'allowed_roles' => ['USER', 'RETAILER'],
        ],
        'CONTACT_US' => [
            'service_key' => 'CONTACT_US',
            'title' => 'Contact Us',
            'icon_key' => 'contact',
            'active' => true,
            'sort_order' => 70,
            'action_type' => 'SCREEN',
            'action_value' => 'CONTACT_US',
            'allowed_roles' => ['USER', 'RETAILER'],
        ],
        'INFO' => [
            'service_key' => 'INFO',
            'title' => 'Info',
            'icon_key' => 'info',
            'active' => true,
            'sort_order' => 80,
            'action_type' => 'SCREEN',
            'action_value' => 'INFO',
            'allowed_roles' => ['USER', 'RETAILER'],
        ],
        'PROFILE' => [
            'service_key' => 'PROFILE',
            'title' => 'Profile',
            'icon_key' => 'profile',
            'active' => true,
            'sort_order' => 90,
            'action_type' => 'SCREEN',
            'action_value' => 'PROFILE',
            'allowed_roles' => ['USER', 'RETAILER'],
        ],
    ];
}

function zpay_dash_allowed_roles($value): array
{
    if (is_array($value)) {
        $items = $value;
    } else {
        $items = preg_split('/[\s,|]+/', strtoupper(trim((string)$value))) ?: [];
    }

    $roles = [];
    foreach ($items as $item) {
        $role = strtoupper(trim((string)$item));
        if (in_array($role, ['USER', 'RETAILER'], true)) {
            $roles[] = $role;
        }
    }

    $roles = array_values(array_unique($roles));
    return $roles ?: ['USER', 'RETAILER'];
}

function zpay_dash_normalize_service(array $row, string $key = ''): array
{
    $key = strtoupper(zpay_dash_clean_string($row['service_key'] ?? $key, 60));
    $key = preg_replace('/[^A-Z0-9_]+/', '_', $key) ?? '';
    $key = trim($key, '_');

    $actionType = strtoupper(zpay_dash_clean_string($row['action_type'] ?? 'SCREEN', 20));
    if (!in_array($actionType, ['SCREEN', 'URL', 'NONE'], true)) {
        $actionType = 'SCREEN';
    }

    return [
        'service_key' => $key,
        'title' => zpay_dash_clean_string($row['title'] ?? $key, 80),
        'icon_key' => zpay_dash_clean_string($row['icon_key'] ?? strtolower($key), 60),
        'active' => zpay_dash_bool($row['active'] ?? true, true),
        'sort_order' => (int)($row['sort_order'] ?? 999),
        'action_type' => $actionType,
        'action_value' => zpay_dash_clean_string($row['action_value'] ?? $key, 200),
        'allowed_roles' => zpay_dash_allowed_roles($row['allowed_roles'] ?? ['USER', 'RETAILER']),
        'updated_at' => (int)($row['updated_at'] ?? 0),
    ];
}

function zpay_dash_all_services(): array
{
    $defaults = zpay_dash_default_services();
    $rows = fb_get('DASHBOARD_SERVICES');
    $rows = is_array($rows) ? $rows : [];

    foreach ($rows as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalized = zpay_dash_normalize_service($row, (string)$key);
        if ($normalized['service_key'] !== '') {
            $defaults[$normalized['service_key']] = array_replace(
                $defaults[$normalized['service_key']] ?? [],
                $normalized
            );
        }
    }

    $items = array_values(array_map(
        static fn(array $row): array => zpay_dash_normalize_service($row, (string)($row['service_key'] ?? '')),
        $defaults
    ));

    usort($items, static fn(array $a, array $b): int =>
        ((int)($a['sort_order'] ?? 999) <=> (int)($b['sort_order'] ?? 999))
        ?: strcmp((string)$a['service_key'], (string)$b['service_key'])
    );

    return $items;
}

function zpay_dash_services_for_role(string $role): array
{
    $role = strtoupper(trim($role));
    $items = [];
    foreach (zpay_dash_all_services() as $service) {
        if (empty($service['active'])) {
            continue;
        }
        if (!in_array($role, $service['allowed_roles'] ?? [], true)) {
            continue;
        }
        $items[] = $service;
    }
    return $items;
}

function zpay_dash_banner_row(array $row, string $bannerId = ''): array
{
    $bannerId = zpay_dash_clean_string($row['banner_id'] ?? $bannerId, 80);
    $actionType = strtoupper(zpay_dash_clean_string($row['action_type'] ?? 'NONE', 20));
    if (!in_array($actionType, ['NONE', 'URL', 'SCREEN'], true)) {
        $actionType = 'NONE';
    }

    return [
        'banner_id' => $bannerId,
        'title' => zpay_dash_clean_string($row['title'] ?? '', 120),
        'image_url' => zpay_dash_clean_string($row['image_url'] ?? '', 300),
        'active' => zpay_dash_bool($row['active'] ?? true, true),
        'sort_order' => (int)($row['sort_order'] ?? 999),
        'action_type' => $actionType,
        'action_value' => zpay_dash_clean_string($row['action_value'] ?? '', 300),
        'start_at' => (int)($row['start_at'] ?? 0),
        'end_at' => (int)($row['end_at'] ?? 0),
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
        'created_by' => zpay_dash_clean_string($row['created_by'] ?? '', 80),
        'updated_by' => zpay_dash_clean_string($row['updated_by'] ?? '', 80),
    ];
}

function zpay_dash_all_banners(bool $activeOnly = false): array
{
    $rows = fb_get('DASHBOARD_BANNERS');
    $rows = is_array($rows) ? $rows : [];
    $now = now_ts();
    $items = [];

    foreach ($rows as $bannerId => $row) {
        if (!is_array($row)) {
            continue;
        }
        $item = zpay_dash_banner_row($row, (string)$bannerId);
        if ($item['banner_id'] === '') {
            continue;
        }
        if ($activeOnly) {
            if (empty($item['active']) || $item['image_url'] === '') {
                continue;
            }
            if ($item['start_at'] > 0 && $item['start_at'] > $now) {
                continue;
            }
            if ($item['end_at'] > 0 && $item['end_at'] < $now) {
                continue;
            }
        }
        $items[] = $item;
    }

    usort($items, static fn(array $a, array $b): int =>
        ((int)$a['sort_order'] <=> (int)$b['sort_order'])
        ?: ((int)$b['updated_at'] <=> (int)$a['updated_at'])
    );

    return $items;
}

function zpay_dash_stats_for_user(string $uid): array
{
    $month = month_key();
    $count = 0;
    foreach ([
        'TOPUP_HISTORY/' . $uid . '/' . $month,
        'BUNDLE_HISTORY/' . $uid . '/' . $month,
        'MFS_HISTORY/' . $uid . '/' . $month,
        'USER_WALLET_HISTORY/' . $uid . '/' . $month,
    ] as $path) {
        $rows = fb_get($path);
        if (is_array($rows)) {
            $count += count($rows);
        }
    }

    return ['this_month_requests' => $count];
}

function zpay_dash_wallet_payload(string $uid, array $user): array
{
    $wallet = fb_get('USER_WALLETS/' . $uid);
    $wallet = is_array($wallet) ? $wallet : [];
    $currency = function_exists('wallet_account_currency')
        ? wallet_account_currency($user, $wallet)
        : strtoupper((string)($wallet['wallet_currency'] ?? $wallet['currency'] ?? 'BDT'));

    return [
        'currency' => $currency !== '' ? $currency : 'BDT',
        'balance' => (float)($wallet['available_balance'] ?? 0),
        'hold_balance' => (float)($wallet['hold_balance'] ?? 0),
    ];
}

function zpay_dash_notification_count(string $uid): int
{
    if ($uid === '') {
        return 0;
    }
    return min(99, notification_unread_count($uid));
}

function zpay_dash_dashboard_payload(array $auth): array
{
    $user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
    $uid = (string)($user['uid'] ?? '');
    $role = strtoupper(trim((string)($user['role'] ?? 'USER'))) ?: 'USER';
    $wallet = zpay_dash_wallet_payload($uid, $user);
    $rate = function_exists('wallet_myr_to_bdt_rate') ? wallet_myr_to_bdt_rate() : 31.00;
    $config = zpay_dash_config();
    $noticeText = $config['notice_active'] ? $config['notice_text'] : '';

    return [
        'user' => [
            'uid' => $uid,
            'name' => (string)($user['name'] ?? ''),
            'status' => (string)($user['status'] ?? ''),
            'role' => $role,
            'account_type' => $role,
            'phone_masked' => zpay_dash_mask_phone((string)($user['phone'] ?? '')),
        ],
        'wallet' => $wallet,
        'rate' => [
            'myr_to_bdt' => (float)$rate,
            'text' => 'RM 1 = ' . number_format((float)$rate, 2, '.', '') . ' BDT',
        ],
        'stats' => zpay_dash_stats_for_user($uid),
        'notice' => [
            'active' => $config['notice_active'] && $noticeText !== '',
            'text' => $noticeText,
        ],
        'services' => zpay_dash_services_for_role($role),
        'banners' => zpay_dash_all_banners(true),
        'notification_count' => zpay_dash_notification_count($uid),
        'theme' => $config['theme'],
        'server_time' => date('c', now_ts()),
    ];
}

function zpay_dash_request_data(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'multipart/form-data')) {
        return $_POST;
    }
    return api_read_json_body();
}

function zpay_dash_public_root_dir(): string
{
    return dirname(__DIR__, 2);
}

function zpay_dash_banner_upload_dir(): string
{
    return rtrim(zpay_dash_public_root_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'dashboard' . DIRECTORY_SEPARATOR . 'banners';
}

function zpay_dash_ensure_banner_upload_dir(): bool
{
    $dir = zpay_dash_banner_upload_dir();
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

function zpay_dash_save_banner_upload(string $field = 'image'): array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return ['ok' => true, 'uploaded' => false, 'image_url' => ''];
    }

    $file = $_FILES[$field];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'uploaded' => false, 'image_url' => ''];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'code' => 'UPLOAD_FAILED', 'message' => 'Banner image upload failed.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 3 * 1024 * 1024) {
        return ['ok' => false, 'code' => 'INVALID_IMAGE_SIZE', 'message' => 'Banner image must be 3MB or smaller.'];
    }

    $original = (string)($file['name'] ?? 'banner');
    $lower = strtolower($original);
    if (preg_match('/\.(php|phtml|phar|cgi|pl|py|sh|asp|aspx)(\.|$)/i', $lower) === 1) {
        return ['ok' => false, 'code' => 'INVALID_IMAGE_TYPE', 'message' => 'Invalid banner image type.'];
    }

    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'code' => 'INVALID_IMAGE_TYPE', 'message' => 'Only jpg, jpeg, png and webp are allowed.'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'code' => 'UPLOAD_FAILED', 'message' => 'Uploaded image could not be read.'];
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
        return ['ok' => false, 'code' => 'INVALID_IMAGE_TYPE', 'message' => 'Invalid banner image content.'];
    }

    if (!zpay_dash_ensure_banner_upload_dir()) {
        return ['ok' => false, 'code' => 'UPLOAD_DIR_UNAVAILABLE', 'message' => 'Banner upload folder is not writable.'];
    }

    $fileName = 'banner_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $target = zpay_dash_banner_upload_dir() . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($tmp, $target)) {
        return ['ok' => false, 'code' => 'UPLOAD_FAILED', 'message' => 'Failed to save banner image.'];
    }
    @chmod($target, 0644);

    return [
        'ok' => true,
        'uploaded' => true,
        'image_url' => '/uploads/dashboard/banners/' . $fileName,
    ];
}
