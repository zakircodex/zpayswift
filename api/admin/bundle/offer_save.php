<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/bundle.php';

api_require_method('POST');

$actor = auth_require_admin_session(true);
if (!is_array($actor)) {
    $actor = [];
}

$body = api_read_json_body();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function admin_bundle_save_now(): int
{
    if (function_exists('bundle_now')) {
        return (int)bundle_now();
    }

    if (function_exists('now_ts')) {
        return (int)now_ts();
    }

    return time();
}

function admin_bundle_save_float($value): float
{
    if (is_string($value)) {
        $value = str_replace(',', '', trim($value));
    }

    if (!is_numeric($value)) {
        return 0.0;
    }

    return round((float)$value, 2);
}

function admin_bundle_save_bool($value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if ($value === null) {
        return $default;
    }

    $s = strtoupper(trim((string)$value));

    if (in_array($s, ['1', 'TRUE', 'YES', 'ON', 'ACTIVE', 'ENABLED'], true)) {
        return true;
    }

    if (in_array($s, ['0', 'FALSE', 'NO', 'OFF', 'INACTIVE', 'DISABLED'], true)) {
        return false;
    }

    return $default;
}

function admin_bundle_save_unit(string $unit): string
{
    $unit = strtoupper(trim($unit));

    if ($unit === '') {
        return 'DAY';
    }

    $map = [
        'M' => 'MINUTE',
        'MIN' => 'MINUTE',
        'MINS' => 'MINUTE',
        'MINUTE' => 'MINUTE',
        'MINUTES' => 'MINUTE',

        'H' => 'HOUR',
        'HR' => 'HOUR',
        'HRS' => 'HOUR',
        'HOUR' => 'HOUR',
        'HOURS' => 'HOUR',

        'D' => 'DAY',
        'DAY' => 'DAY',
        'DAYS' => 'DAY',

        'W' => 'WEEK',
        'WEEK' => 'WEEK',
        'WEEKS' => 'WEEK',

        'MON' => 'MONTH',
        'MONTH' => 'MONTH',
        'MONTHS' => 'MONTH',
    ];

    return $map[$unit] ?? $unit;
}

function admin_bundle_save_duration_seconds(float $durationValue, string $durationUnit, int $durationSecondsFromBody = 0): int
{
    if ($durationSecondsFromBody > 0) {
        return $durationSecondsFromBody;
    }

    if ($durationValue <= 0) {
        return 0;
    }

    $unit = admin_bundle_save_unit($durationUnit);

    if ($unit === 'MINUTE') {
        return (int)round($durationValue * 60);
    }

    if ($unit === 'HOUR') {
        return (int)round($durationValue * 3600);
    }

    if ($unit === 'DAY') {
        return (int)round($durationValue * 86400);
    }

    if ($unit === 'WEEK') {
        return (int)round($durationValue * 604800);
    }

    if ($unit === 'MONTH') {
        return (int)round($durationValue * 2592000);
    }

    return 0;
}

function admin_bundle_save_make_offer_id(): string
{
    if (function_exists('bundle_make_offer_id')) {
        return (string)bundle_make_offer_id();
    }

    if (function_exists('make_bundle_request_id')) {
        return 'OFFER_' . (string)make_bundle_request_id();
    }

    if (function_exists('make_uid')) {
        return 'OFFER_' . (string)make_uid();
    }

    return 'OFFER_' . date('YmdHis') . '_' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function admin_bundle_save_load_old_offer(string $offerId): array
{
    $offerId = trim($offerId);

    if ($offerId === '') {
        return [];
    }

    if (function_exists('bundle_load_offer')) {
        $row = bundle_load_offer($offerId);
        if (is_array($row)) {
            return $row;
        }
    }

    $row = fb_get('BUNDLE_OFFERS/' . $offerId);
    return is_array($row) ? $row : [];
}

function admin_bundle_save_direct_fallback(array $payload): bool
{
    $offerId = trim((string)($payload['offer_id'] ?? ''));

    if ($offerId === '') {
        return false;
    }

    return (bool)fb_put('BUNDLE_OFFERS/' . $offerId, $payload);
}

/*
|--------------------------------------------------------------------------
| Input normalize
|--------------------------------------------------------------------------
| amount = actual bundle price / offer price
| admin_commission = total commission pool set by admin
|--------------------------------------------------------------------------
*/

$offerId = trim((string)($body['offer_id'] ?? ''));

$isNewOffer = $offerId === '';
if ($isNewOffer) {
    $offerId = admin_bundle_save_make_offer_id();
}

$oldOffer = admin_bundle_save_load_old_offer($offerId);

$rawAmount = $body['amount']
    ?? $body['price_amount']
    ?? $body['offer_price']
    ?? $body['price']
    ?? $body['cost']
    ?? ($oldOffer['amount'] ?? 0);

$rawAdminCommission = $body['admin_commission']
    ?? $body['commission']
    ?? $body['commission_amount']
    ?? ($oldOffer['admin_commission'] ?? 0);

$bundleName = trim((string)(
    $body['bundle_name']
    ?? $body['package_name']
    ?? $body['plan_name']
    ?? $body['name']
    ?? ($oldOffer['bundle_name'] ?? '')
));

$description = trim((string)(
    $body['description']
    ?? $body['note']
    ?? ($oldOffer['description'] ?? '')
));

$packageValidityText = trim((string)(
    $body['validity_text']
    ?? $body['package_validity']
    ?? $body['bundle_validity']
    ?? ($oldOffer['validity_text'] ?? $oldOffer['package_validity'] ?? $oldOffer['bundle_validity'] ?? '')
));

$operator = trim((string)(
    $body['operator']
    ?? ($oldOffer['operator'] ?? '')
));

if (function_exists('normalize_operator')) {
    $operator = (string)normalize_operator($operator);
} else {
    $operator = strtoupper(trim($operator));
}

$durationValue = admin_bundle_save_float(
    $body['duration_value']
    ?? $body['validity_value']
    ?? $body['expire_after_value']
    ?? ($oldOffer['duration_value'] ?? 0)
);

$durationUnit = admin_bundle_save_unit((string)(
    $body['duration_unit']
    ?? $body['validity_unit']
    ?? $body['expire_after_unit']
    ?? ($oldOffer['duration_unit'] ?? 'DAY')
));

$durationSecondsInput = (int)(
    $body['duration_seconds']
    ?? $body['validity_seconds']
    ?? $body['expire_after_seconds']
    ?? 0
);

$durationSeconds = admin_bundle_save_duration_seconds($durationValue, $durationUnit, $durationSecondsInput);

$status = strtoupper(trim((string)($body['status'] ?? 'ACTIVE')));

if (!in_array($status, ['ACTIVE', 'INACTIVE', 'EXPIRED', 'DELETED'], true)) {
    $status = 'ACTIVE';
}

$active = array_key_exists('active', $body)
    ? admin_bundle_save_bool($body['active'], $status === 'ACTIVE')
    : ($status === 'ACTIVE');

if ($status === 'ACTIVE') {
    $active = true;
}

if ($status === 'INACTIVE' || $status === 'EXPIRED' || $status === 'DELETED') {
    $active = false;
}

$visibility = strtoupper(trim((string)(
    $body['visibility']
    ?? $body['scope']
    ?? ($oldOffer['visibility'] ?? 'GLOBAL')
)));

if (!in_array($visibility, ['GLOBAL', 'SUBADMIN_ONLY', 'SUBADMIN', 'PRIVATE'], true)) {
    $visibility = 'GLOBAL';
}

if ($visibility === 'SUBADMIN') {
    $visibility = 'SUBADMIN_ONLY';
}

$amount = admin_bundle_save_float($rawAmount);
$adminCommission = admin_bundle_save_float($rawAdminCommission);
$now = admin_bundle_save_now();

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

if ($operator === '') {
    api_response(false, 'VALIDATION_ERROR', 'operator is required', [
        'field' => 'operator',
    ], 422);
}

if ($bundleName === '') {
    api_response(false, 'VALIDATION_ERROR', 'bundle_name is required', [
        'field' => 'bundle_name',
    ], 422);
}

if ($amount <= 0) {
    api_response(false, 'VALIDATION_ERROR', 'amount must be greater than zero', [
        'field' => 'amount',
    ], 422);
}

if ($adminCommission < 0) {
    api_response(false, 'VALIDATION_ERROR', 'admin_commission cannot be negative', [
        'field' => 'admin_commission',
    ], 422);
}

if ($adminCommission > $amount) {
    api_response(false, 'VALIDATION_ERROR', 'admin_commission cannot be higher than bundle price', [
        'field' => 'admin_commission',
        'amount' => $amount,
        'admin_commission' => $adminCommission,
    ], 422);
}

if ($durationValue < 0 || $durationSeconds < 0) {
    api_response(false, 'VALIDATION_ERROR', 'duration cannot be negative', [
        'field' => 'duration',
    ], 422);
}

if ($status === 'ACTIVE' && $durationSeconds <= 0) {
    api_response(false, 'VALIDATION_ERROR', 'duration is required for active bundle offer', [
        'field' => 'duration',
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Expiry rule
|--------------------------------------------------------------------------
| ACTIVE করলে নতুন expires_at তৈরি হবে।
| অর্থাৎ expired offer edit করে active করলে old expired date থাকবে না।
|--------------------------------------------------------------------------
*/

$expiresAt = 0;
$expired = false;
$deleted = false;

if ($status === 'ACTIVE') {
    $expiresAt = $now + $durationSeconds;
    $expired = false;
    $deleted = false;
} elseif ($status === 'EXPIRED') {
    $expiresAt = (int)($body['expires_at'] ?? 0);
    if ($expiresAt <= 0 || $expiresAt > $now) {
        $expiresAt = $now - 60;
    }
    $expired = true;
    $deleted = false;
} elseif ($status === 'DELETED') {
    $expiresAt = (int)($oldOffer['expires_at'] ?? $body['expires_at'] ?? 0);
    $expired = false;
    $deleted = true;
} else {
    $expiresAt = (int)($body['expires_at'] ?? $oldOffer['expires_at'] ?? 0);
    $expired = false;
    $deleted = false;
}

/*
|--------------------------------------------------------------------------
| Payload
|--------------------------------------------------------------------------
*/

$actorUid = trim((string)($actor['uid'] ?? ''));
$actorRole = strtoupper(trim((string)($actor['role'] ?? 'ADMIN')));

$payload = [
    'offer_id' => $offerId,

    'operator' => $operator,

    'bundle_name' => $bundleName,
    'package_name' => $bundleName,
    'plan_name' => $bundleName,
    'name' => $bundleName,

    'description' => $description,
    'note' => $description,

    'validity_text' => $packageValidityText,
    'package_validity' => $packageValidityText,
    'bundle_validity' => $packageValidityText,

    /*
     * Actual offer price. Commission subtract করা যাবে না।
     */
    'amount' => $amount,
    'price_amount' => $amount,
    'offer_price' => $amount,
    'price' => $amount,
    'cost' => $amount,

    /*
     * Admin total commission pool.
     */
    'admin_commission' => $adminCommission,
    'commission' => $adminCommission,
    'commission_amount' => $adminCommission,

    /*
     * Default user commission 0 রাখা হচ্ছে।
     * Subadmin custom commission আলাদা node থেকে আসবে।
     */
    'user_commission' => admin_bundle_save_float($body['user_commission'] ?? ($oldOffer['user_commission'] ?? 0)),
    'customer_commission' => admin_bundle_save_float($body['customer_commission'] ?? ($oldOffer['customer_commission'] ?? 0)),
    'subadmin_profit' => admin_bundle_save_float($body['subadmin_profit'] ?? ($oldOffer['subadmin_profit'] ?? 0)),

    'duration_value' => $durationValue,
    'validity_value' => $durationValue,
    'expire_after_value' => $durationValue,

    'duration_unit' => $durationUnit,
    'validity_unit' => $durationUnit,
    'expire_after_unit' => $durationUnit,

    'duration_seconds' => $durationSeconds,
    'validity_seconds' => $durationSeconds,
    'expire_after_seconds' => $durationSeconds,

    'expires_at' => $expiresAt,
    'expire_at' => $expiresAt,

    'status' => $status,
    'active' => $active,
    'expired' => $expired,
    'deleted' => $deleted,

    'visibility' => $visibility,
    'scope' => $visibility,

    'updated_at' => $now,
    'updated_by_uid' => $actorUid,
    'updated_by_role' => $actorRole,
];

if ($isNewOffer) {
    $payload['created_at'] = $now;
    $payload['created_by_uid'] = $actorUid;
    $payload['created_by_role'] = $actorRole;
} else {
    $payload['created_at'] = (int)($oldOffer['created_at'] ?? $now);
    $payload['created_by_uid'] = (string)($oldOffer['created_by_uid'] ?? '');
    $payload['created_by_role'] = (string)($oldOffer['created_by_role'] ?? '');
}

/*
|--------------------------------------------------------------------------
| Important cleanup when re-activating deleted/expired offer
|--------------------------------------------------------------------------
*/

if ($status === 'ACTIVE') {
    $payload['deleted_at'] = 0;
    $payload['expired_at'] = 0;
    $payload['reactivated_at'] = $now;
    $payload['reactivated_by_uid'] = $actorUid;
}

if ($status === 'EXPIRED') {
    $payload['expired_at'] = $now;
    $payload['expired_by_uid'] = $actorUid;
}

if ($status === 'DELETED') {
    $payload['deleted_at'] = (int)($oldOffer['deleted_at'] ?? $now);
    $payload['deleted_by_uid'] = (string)($oldOffer['deleted_by_uid'] ?? $actorUid);
}

/*
|--------------------------------------------------------------------------
| Save
|--------------------------------------------------------------------------
*/

$res = [
    'ok' => false,
    'code' => 'SERVER_ERROR',
    'message' => 'Failed to save bundle offer',
    'data' => [],
];

if (function_exists('bundle_admin_save_offer')) {
    $res = bundle_admin_save_offer($payload, $actor);
} else {
    $saved = admin_bundle_save_direct_fallback($payload);

    $res = [
        'ok' => $saved,
        'code' => $saved ? 'SUCCESS' : 'SERVER_ERROR',
        'message' => $saved ? 'Bundle offer saved successfully' : 'Failed to save bundle offer',
        'data' => $saved ? $payload : [],
    ];
}

if (!($res['ok'] ?? false)) {
    $code = (string)($res['code'] ?? 'SERVER_ERROR');

    $httpStatus = 500;
    if ($code === 'VALIDATION_ERROR') {
        $httpStatus = 422;
    } elseif ($code === 'FORBIDDEN') {
        $httpStatus = 403;
    } elseif ($code === 'NOT_FOUND') {
        $httpStatus = 404;
    }

    api_response(
        false,
        $code,
        (string)($res['message'] ?? 'Failed to save bundle offer'),
        (array)($res['data'] ?? []),
        $httpStatus
    );
}

$offer = (array)($res['data'] ?? []);

if (!$offer) {
    $offer = $payload;
}

/*
|--------------------------------------------------------------------------
| System log
|--------------------------------------------------------------------------
*/

if (function_exists('system_log')) {
    system_log(
        $isNewOffer ? 'ADMIN_BUNDLE_OFFER_CREATE' : 'ADMIN_BUNDLE_OFFER_UPDATE',
        $offerId,
        $isNewOffer ? 'Admin created bundle offer' : 'Admin updated bundle offer',
        [
            'offer_id' => $offerId,
            'operator' => $operator,
            'status' => $status,
            'active' => $active,
            'amount' => $amount,
            'admin_commission' => $adminCommission,
            'duration_value' => $durationValue,
            'duration_unit' => $durationUnit,
            'duration_seconds' => $durationSeconds,
            'expires_at' => $expiresAt,
            'actor_uid' => $actorUid,
        ]
    );
}

api_response(true, 'SUCCESS', 'Bundle offer saved successfully', [
    'offer' => $offer,
    'offer_id' => $offerId,
    'status' => $status,
    'active' => $active,
    'expires_at' => $expiresAt,
]);
