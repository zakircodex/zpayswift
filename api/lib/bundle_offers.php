<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

/**
 * Bundle Offer System
 *
 * Main DB:
 * BUNDLE_OFFERS/{offer_id}
 *
 * Subadmin custom DB:
 * SUBADMIN_BUNDLE_OFFERS/{subadmin_uid}/{offer_id}
 *
 * Rule:
 * - Admin commission = maximum commission pool.
 * - If subadmin does not customize, user gets full admin commission and subadmin profit = 0.
 * - If subadmin customizes user_commission lower than admin_commission,
 *   subadmin profit = admin_commission - user_commission.
 */

function bundle_offer_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function bundle_offer_make_id(): string
{
    return 'BO' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function bundle_offer_float_value(mixed $value, float $default = 0.0): float
{
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return $default;
    }

    $value = str_replace(',', '', $value);
    return is_numeric($value) ? (float)$value : $default;
}

function bundle_offer_money(float $amount): float
{
    return round($amount, 2);
}

function bundle_offer_bool_value(mixed $value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
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

function bundle_offer_clean_text(mixed $value): string
{
    $text = trim((string)$value);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}

function bundle_offer_normalize_operator_value(mixed $operator): string
{
    $operator = trim((string)$operator);

    if ($operator === '') {
        return '';
    }

    if (function_exists('normalize_operator')) {
        return (string)normalize_operator($operator);
    }

    $operator = strtoupper($operator);

    $map = [
        'GRAMEENPHONE' => 'GP',
        'GP' => 'GP',
        'ROBI' => 'ROBI',
        'RB' => 'ROBI',
        'BANGLALINK' => 'BL',
        'BANGLA LINK' => 'BL',
        'BL' => 'BL',
        'AIRTEL' => 'AIRTEL',
        'AT' => 'AIRTEL',
        'TELETALK' => 'TT',
        'TT' => 'TT',
    ];

    return $map[$operator] ?? $operator;
}

function bundle_offer_make_expires_at(mixed $expiresAt, mixed $durationValue, mixed $durationUnit): int
{
    $now = bundle_offer_now();

    $directExpiresAt = (int)bundle_offer_float_value($expiresAt, 0);
    if ($directExpiresAt > $now) {
        return $directExpiresAt;
    }

    $value = (int)bundle_offer_float_value($durationValue, 0);
    if ($value <= 0) {
        return 0;
    }

    $unit = strtolower(trim((string)$durationUnit));
    if ($unit === '') {
        $unit = 'days';
    }

    switch ($unit) {
        case 'minute':
        case 'minutes':
        case 'min':
        case 'mins':
            $seconds = $value * 60;
            break;

        case 'hour':
        case 'hours':
        case 'hr':
        case 'hrs':
            $seconds = $value * 3600;
            break;

        case 'day':
        case 'days':
            $seconds = $value * 86400;
            break;

        case 'week':
        case 'weeks':
            $seconds = $value * 604800;
            break;

        case 'month':
        case 'months':
            $seconds = $value * 2592000;
            break;

        default:
            $seconds = $value * 86400;
            break;
    }

    return $now + $seconds;
}

function bundle_offer_is_expired(array $row, ?int $now = null): bool
{
    $now = $now ?? bundle_offer_now();
    $expiresAt = (int)($row['expires_at'] ?? 0);

    return $expiresAt > 0 && $expiresAt <= $now;
}

function bundle_offer_is_global_active(array $row, ?int $now = null): bool
{
    if ((bool)($row['deleted'] ?? false)) {
        return false;
    }

    if (!bundle_offer_bool_value($row['active'] ?? true, true)) {
        return false;
    }

    if (bundle_offer_is_expired($row, $now)) {
        return false;
    }

    return true;
}

function bundle_offer_load(string $offerId): array
{
    $offerId = trim($offerId);
    if ($offerId === '') {
        return [];
    }

    $row = fb_get('BUNDLE_OFFERS/' . $offerId);
    if (!is_array($row)) {
        return [];
    }

    $row['offer_id'] = (string)($row['offer_id'] ?? $offerId);
    return $row;
}

function bundle_offer_load_custom(string $subadminUid, string $offerId): array
{
    $subadminUid = trim($subadminUid);
    $offerId = trim($offerId);

    if ($subadminUid === '' || $offerId === '') {
        return [];
    }

    $row = fb_get('SUBADMIN_BUNDLE_OFFERS/' . $subadminUid . '/' . $offerId);
    if (!is_array($row)) {
        return [];
    }

    $row['subadmin_uid'] = (string)($row['subadmin_uid'] ?? $subadminUid);
    $row['offer_id'] = (string)($row['offer_id'] ?? $offerId);

    return $row;
}

function bundle_offer_load_user(string $uid): array
{
    $uid = trim($uid);
    if ($uid === '') {
        return [];
    }

    $row = fb_get('USERS/' . $uid);
    return is_array($row) ? $row : [];
}

function bundle_offer_get_parent_subadmin_uid(array $user): string
{
    return trim((string)($user['parent_subadmin_uid'] ?? ''));
}

function bundle_offer_build_public_item(array $offer, array $user = []): array
{
    $offerId = (string)($offer['offer_id'] ?? '');
    if ($offerId === '') {
        return [];
    }

    if (!bundle_offer_is_global_active($offer)) {
        return [];
    }

    $parentSubadminUid = bundle_offer_get_parent_subadmin_uid($user);

    $amount = bundle_offer_money(bundle_offer_float_value($offer['amount'] ?? 0));
    $adminCommission = bundle_offer_money(bundle_offer_float_value($offer['admin_commission'] ?? 0));
    $userCommission = $adminCommission;
    $subadminProfit = 0.0;
    $customApplied = false;
    $customActive = true;

    if ($parentSubadminUid !== '') {
        $custom = bundle_offer_load_custom($parentSubadminUid, $offerId);

        if ($custom) {
            $customApplied = true;
            $customActive = bundle_offer_bool_value($custom['active'] ?? true, true);

            if (!$customActive) {
                return [];
            }

            $customUserCommission = bundle_offer_money(bundle_offer_float_value($custom['user_commission'] ?? $adminCommission));

            if ($customUserCommission < 0) {
                $customUserCommission = 0.0;
            }

            if ($customUserCommission > $adminCommission) {
                $customUserCommission = $adminCommission;
            }

            $userCommission = $customUserCommission;
            $subadminProfit = bundle_offer_money(max(0, $adminCommission - $userCommission));
        }
    }

    return [
        'offer_id' => $offerId,
        'operator' => (string)($offer['operator'] ?? ''),
        'bundle_name' => (string)($offer['bundle_name'] ?? ''),
        'description' => (string)($offer['description'] ?? ''),
        'amount' => $amount,
        'admin_commission' => $adminCommission,
        'user_commission' => $userCommission,
        'subadmin_profit' => $subadminProfit,
        'custom_applied' => $customApplied,
        'parent_subadmin_uid' => $parentSubadminUid,
        'validity_text' => (string)($offer['validity_text'] ?? ''),
        'duration_value' => (int)($offer['duration_value'] ?? 0),
        'duration_unit' => (string)($offer['duration_unit'] ?? ''),
        'expires_at' => (int)($offer['expires_at'] ?? 0),
        'active' => true,
        'created_at' => (int)($offer['created_at'] ?? 0),
        'updated_at' => (int)($offer['updated_at'] ?? 0),
    ];
}

function bundle_offer_get_effective_for_user(string $offerId, string $uid): array
{
    $offer = bundle_offer_load($offerId);
    if (!$offer) {
        return [
            'ok' => false,
            'code' => 'OFFER_NOT_FOUND',
            'message' => 'Bundle offer not found',
            'data' => [],
        ];
    }

    $user = bundle_offer_load_user($uid);
    if (!$user) {
        return [
            'ok' => false,
            'code' => 'USER_NOT_FOUND',
            'message' => 'User not found',
            'data' => [],
        ];
    }

    $publicItem = bundle_offer_build_public_item($offer, $user);
    if (!$publicItem) {
        return [
            'ok' => false,
            'code' => 'OFFER_NOT_AVAILABLE',
            'message' => 'Bundle offer is not available',
            'data' => [],
        ];
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle offer loaded',
        'data' => $publicItem,
    ];
}

function bundle_offer_list_all(bool $includeInactive = false): array
{
    $items = fb_get('BUNDLE_OFFERS');
    if (!is_array($items)) {
        return [];
    }

    $list = [];
    $now = bundle_offer_now();

    foreach ($items as $offerId => $row) {
        if (!is_array($row)) {
            continue;
        }

        $row['offer_id'] = (string)($row['offer_id'] ?? $offerId);

        if (!$includeInactive && !bundle_offer_is_global_active($row, $now)) {
            continue;
        }

        $list[] = [
            'offer_id' => (string)$row['offer_id'],
            'operator' => (string)($row['operator'] ?? ''),
            'bundle_name' => (string)($row['bundle_name'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'amount' => bundle_offer_money(bundle_offer_float_value($row['amount'] ?? 0)),
            'admin_commission' => bundle_offer_money(bundle_offer_float_value($row['admin_commission'] ?? 0)),
            'validity_text' => (string)($row['validity_text'] ?? ''),
            'duration_value' => (int)($row['duration_value'] ?? 0),
            'duration_unit' => (string)($row['duration_unit'] ?? ''),
            'expires_at' => (int)($row['expires_at'] ?? 0),
            'active' => bundle_offer_bool_value($row['active'] ?? true, true),
            'deleted' => (bool)($row['deleted'] ?? false),
            'expired' => bundle_offer_is_expired($row, $now),
            'created_by_uid' => (string)($row['created_by_uid'] ?? ''),
            'created_by_role' => (string)($row['created_by_role'] ?? ''),
            'created_at' => (int)($row['created_at'] ?? 0),
            'updated_at' => (int)($row['updated_at'] ?? 0),
        ];
    }

    usort($list, static function (array $a, array $b): int {
        $aTs = (int)(($a['updated_at'] ?? 0) ?: ($a['created_at'] ?? 0));
        $bTs = (int)(($b['updated_at'] ?? 0) ?: ($b['created_at'] ?? 0));
        return $bTs <=> $aTs;
    });

    return array_values($list);
}

function bundle_offer_list_for_user(string $uid): array
{
    $user = bundle_offer_load_user($uid);
    if (!$user) {
        return [];
    }

    $offers = bundle_offer_list_all(false);
    $list = [];

    foreach ($offers as $offer) {
        $item = bundle_offer_build_public_item($offer, $user);
        if ($item) {
            $list[] = $item;
        }
    }

    return array_values($list);
}

function bundle_offer_list_for_subadmin_panel(string $subadminUid): array
{
    $subadminUid = trim($subadminUid);
    if ($subadminUid === '') {
        return [];
    }

    $offers = bundle_offer_list_all(false);
    $list = [];

    foreach ($offers as $offer) {
        $offerId = (string)($offer['offer_id'] ?? '');
        if ($offerId === '') {
            continue;
        }

        $custom = bundle_offer_load_custom($subadminUid, $offerId);

        $adminCommission = bundle_offer_money(bundle_offer_float_value($offer['admin_commission'] ?? 0));
        $customUserCommission = $custom
            ? bundle_offer_money(bundle_offer_float_value($custom['user_commission'] ?? $adminCommission))
            : $adminCommission;

        if ($customUserCommission < 0) {
            $customUserCommission = 0.0;
        }

        if ($customUserCommission > $adminCommission) {
            $customUserCommission = $adminCommission;
        }

        $subadminProfit = $custom
            ? bundle_offer_money(max(0, $adminCommission - $customUserCommission))
            : 0.0;

        $list[] = [
            'offer_id' => $offerId,
            'operator' => (string)($offer['operator'] ?? ''),
            'bundle_name' => (string)($offer['bundle_name'] ?? ''),
            'description' => (string)($offer['description'] ?? ''),
            'amount' => bundle_offer_money(bundle_offer_float_value($offer['amount'] ?? 0)),
            'admin_commission' => $adminCommission,
            'user_commission' => $customUserCommission,
            'subadmin_profit' => $subadminProfit,
            'custom_applied' => (bool)$custom,
            'custom_active' => $custom ? bundle_offer_bool_value($custom['active'] ?? true, true) : true,
            'validity_text' => (string)($offer['validity_text'] ?? ''),
            'expires_at' => (int)($offer['expires_at'] ?? 0),
            'active' => true,
            'updated_at' => $custom ? (int)($custom['updated_at'] ?? 0) : (int)($offer['updated_at'] ?? 0),
        ];
    }

    return array_values($list);
}

function bundle_offer_validate_admin_input(array $input): array
{
    $operator = bundle_offer_normalize_operator_value($input['operator'] ?? '');
    $bundleName = bundle_offer_clean_text($input['bundle_name'] ?? '');
    $description = trim((string)($input['description'] ?? ''));
    $validityText = bundle_offer_clean_text($input['validity_text'] ?? '');

    $amount = bundle_offer_money(bundle_offer_float_value($input['amount'] ?? 0));
    $adminCommission = bundle_offer_money(bundle_offer_float_value($input['admin_commission'] ?? 0));

    $durationValue = (int)bundle_offer_float_value($input['duration_value'] ?? 0);
    $durationUnit = strtolower(trim((string)($input['duration_unit'] ?? 'days')));

    $expiresAt = bundle_offer_make_expires_at(
        $input['expires_at'] ?? 0,
        $durationValue,
        $durationUnit
    );

    $active = bundle_offer_bool_value($input['active'] ?? true, true);

    if ($operator === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'operator is required',
            'data' => [],
        ];
    }

    if (function_exists('is_valid_operator') && !is_valid_operator($operator)) {
        return [
            'ok' => false,
            'code' => 'INVALID_OPERATOR',
            'message' => 'Invalid operator',
            'data' => [],
        ];
    }

    if ($bundleName === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'bundle_name is required',
            'data' => [],
        ];
    }

    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'amount must be greater than zero',
            'data' => [],
        ];
    }

    if ($adminCommission < 0) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'admin_commission cannot be negative',
            'data' => [],
        ];
    }

    if ($adminCommission > $amount) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'admin_commission cannot be greater than amount',
            'data' => [],
        ];
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Valid bundle offer input',
        'data' => [
            'operator' => $operator,
            'bundle_name' => $bundleName,
            'description' => $description,
            'amount' => $amount,
            'admin_commission' => $adminCommission,
            'validity_text' => $validityText,
            'duration_value' => $durationValue,
            'duration_unit' => $durationUnit,
            'expires_at' => $expiresAt,
            'active' => $active,
        ],
    ];
}

function bundle_offer_save_admin_offer(array $input, array $actor = []): array
{
    $validated = bundle_offer_validate_admin_input($input);
    if (!($validated['ok'] ?? false)) {
        return $validated;
    }

    $data = (array)$validated['data'];
    $now = bundle_offer_now();

    $offerId = trim((string)($input['offer_id'] ?? ''));
    $existing = [];

    if ($offerId !== '') {
        $existing = bundle_offer_load($offerId);
    }

    if ($offerId === '') {
        $offerId = bundle_offer_make_id();
    }

    $createdAt = $existing ? (int)($existing['created_at'] ?? $now) : $now;
    $createdByUid = $existing
        ? (string)($existing['created_by_uid'] ?? '')
        : (string)($actor['uid'] ?? '');

    $createdByRole = $existing
        ? (string)($existing['created_by_role'] ?? '')
        : (string)($actor['role'] ?? '');

    $row = [
        'offer_id' => $offerId,
        'operator' => (string)$data['operator'],
        'bundle_name' => (string)$data['bundle_name'],
        'description' => (string)$data['description'],
        'amount' => (float)$data['amount'],
        'admin_commission' => (float)$data['admin_commission'],
        'validity_text' => (string)$data['validity_text'],
        'duration_value' => (int)$data['duration_value'],
        'duration_unit' => (string)$data['duration_unit'],
        'expires_at' => (int)$data['expires_at'],
        'active' => (bool)$data['active'],
        'deleted' => false,
        'created_by_uid' => $createdByUid,
        'created_by_role' => $createdByRole,
        'updated_by_uid' => (string)($actor['uid'] ?? ''),
        'updated_by_role' => (string)($actor['role'] ?? ''),
        'created_at' => $createdAt,
        'updated_at' => $now,
    ];

    $ok = fb_put('BUNDLE_OFFERS/' . $offerId, $row);

    if (!$ok) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to save bundle offer',
            'data' => [],
        ];
    }

    if (function_exists('system_log')) {
        system_log($existing ? 'BUNDLE_OFFER_UPDATE' : 'BUNDLE_OFFER_CREATE', $offerId, 'Bundle offer saved', [
            'offer_id' => $offerId,
            'operator' => $row['operator'],
            'amount' => $row['amount'],
            'admin_commission' => $row['admin_commission'],
            'actor_uid' => (string)($actor['uid'] ?? ''),
            'actor_role' => (string)($actor['role'] ?? ''),
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => $existing ? 'Bundle offer updated successfully' : 'Bundle offer created successfully',
        'data' => $row,
    ];
}

function bundle_offer_delete_admin_offer(string $offerId, array $actor = []): array
{
    $offerId = trim($offerId);

    if ($offerId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'offer_id is required',
            'data' => [],
        ];
    }

    $existing = bundle_offer_load($offerId);
    if (!$existing) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'Bundle offer not found',
            'data' => [],
        ];
    }

    $now = bundle_offer_now();

    $ok = fb_patch('BUNDLE_OFFERS/' . $offerId, [
        'active' => false,
        'deleted' => true,
        'deleted_at' => $now,
        'deleted_by_uid' => (string)($actor['uid'] ?? ''),
        'deleted_by_role' => (string)($actor['role'] ?? ''),
        'updated_at' => $now,
    ]);

    if (!$ok) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to delete bundle offer',
            'data' => [],
        ];
    }

    if (function_exists('system_log')) {
        system_log('BUNDLE_OFFER_DELETE', $offerId, 'Bundle offer deleted', [
            'offer_id' => $offerId,
            'actor_uid' => (string)($actor['uid'] ?? ''),
            'actor_role' => (string)($actor['role'] ?? ''),
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle offer deleted successfully',
        'data' => [
            'offer_id' => $offerId,
            'deleted_at' => $now,
        ],
    ];
}

function bundle_offer_save_subadmin_custom(
    string $subadminUid,
    string $offerId,
    float $userCommission,
    bool $active = true,
    array $actor = []
): array {
    $subadminUid = trim($subadminUid);
    $offerId = trim($offerId);
    $userCommission = bundle_offer_money($userCommission);

    if ($subadminUid === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'subadmin_uid is required',
            'data' => [],
        ];
    }

    if ($offerId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'offer_id is required',
            'data' => [],
        ];
    }

    $offer = bundle_offer_load($offerId);
    if (!$offer || !bundle_offer_is_global_active($offer)) {
        return [
            'ok' => false,
            'code' => 'OFFER_NOT_AVAILABLE',
            'message' => 'Bundle offer is not available',
            'data' => [],
        ];
    }

    $adminCommission = bundle_offer_money(bundle_offer_float_value($offer['admin_commission'] ?? 0));

    if ($userCommission < 0) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'user_commission cannot be negative',
            'data' => [],
        ];
    }

    if ($userCommission > $adminCommission) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'user_commission cannot be greater than admin commission',
            'data' => [
                'admin_commission' => $adminCommission,
            ],
        ];
    }

    $now = bundle_offer_now();
    $existing = bundle_offer_load_custom($subadminUid, $offerId);

    $row = [
        'offer_id' => $offerId,
        'subadmin_uid' => $subadminUid,
        'active' => $active,
        'user_commission' => $userCommission,
        'admin_commission' => $adminCommission,
        'subadmin_profit' => bundle_offer_money(max(0, $adminCommission - $userCommission)),
        'created_at' => $existing ? (int)($existing['created_at'] ?? $now) : $now,
        'updated_at' => $now,
        'updated_by_uid' => (string)($actor['uid'] ?? $subadminUid),
        'updated_by_role' => (string)($actor['role'] ?? 'SUBADMIN'),
    ];

    $ok = fb_put('SUBADMIN_BUNDLE_OFFERS/' . $subadminUid . '/' . $offerId, $row);

    if (!$ok) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to save subadmin bundle customization',
            'data' => [],
        ];
    }

    if (function_exists('system_log')) {
        system_log('SUBADMIN_BUNDLE_CUSTOM_SAVE', $offerId, 'Subadmin bundle customization saved', [
            'subadmin_uid' => $subadminUid,
            'offer_id' => $offerId,
            'admin_commission' => $adminCommission,
            'user_commission' => $userCommission,
            'subadmin_profit' => $row['subadmin_profit'],
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle customization saved successfully',
        'data' => $row,
    ];
}