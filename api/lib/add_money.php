<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/admin_pagination.php';

function add_money_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function add_money_request_id(): string
{
    return 'AM' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function add_money_token(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}

function add_money_receipt_token_ttl_seconds(): int
{
    $ttl = defined('RECEIPT_TOKEN_TTL_SECONDS')
        ? (int)constant('RECEIPT_TOKEN_TTL_SECONDS')
        : 30 * 24 * 60 * 60;

    return max(60 * 60, min(365 * 24 * 60 * 60, $ttl));
}

function add_money_receipt_token_metadata(int $issuedAt): array
{
    return [
        'receipt_token_version' => 2,
        'issued_at' => $issuedAt,
        'expires_at' => $issuedAt + add_money_receipt_token_ttl_seconds(),
        'status' => 'ACTIVE',
    ];
}

function add_money_receipt_token_access(array $tokenRow, ?int $now = null): array
{
    $hasVersion = array_key_exists('receipt_token_version', $tokenRow);
    $version = (int)($tokenRow['receipt_token_version'] ?? 1);

    // Historical capabilities predate expiry metadata. Keep them readable without
    // inventing dates or rewriting production records.
    if (!$hasVersion || $version === 1) {
        return ['ok' => true, 'legacy' => true, 'version' => 1, 'code' => 'LEGACY_RECEIPT_TOKEN'];
    }

    if ($version !== 2) {
        return ['ok' => false, 'legacy' => false, 'version' => $version, 'code' => 'RECEIPT_TOKEN_INVALID'];
    }

    $status = strtoupper(trim((string)($tokenRow['status'] ?? '')));
    if ($status !== 'ACTIVE') {
        return [
            'ok' => false,
            'legacy' => false,
            'version' => $version,
            'code' => in_array($status, ['REVOKED', 'DISABLED'], true)
                ? 'RECEIPT_TOKEN_REVOKED'
                : 'RECEIPT_TOKEN_INVALID',
        ];
    }

    $issuedAt = (int)($tokenRow['issued_at'] ?? 0);
    $expiresAt = (int)($tokenRow['expires_at'] ?? 0);
    if ($issuedAt <= 0 || $expiresAt <= $issuedAt) {
        return ['ok' => false, 'legacy' => false, 'version' => $version, 'code' => 'RECEIPT_TOKEN_INVALID'];
    }

    if (($now ?? add_money_now()) >= $expiresAt) {
        return ['ok' => false, 'legacy' => false, 'version' => $version, 'code' => 'RECEIPT_TOKEN_EXPIRED'];
    }

    return [
        'ok' => true,
        'legacy' => false,
        'version' => $version,
        'code' => 'RECEIPT_TOKEN_VALID',
        'issued_at' => $issuedAt,
        'expires_at' => $expiresAt,
    ];
}

function add_money_receipt_token_matches_request(string $token, array $tokenRow, array $requestRow): bool
{
    $pairs = [
        [trim((string)($tokenRow['request_id'] ?? '')), trim((string)($requestRow['request_id'] ?? ''))],
        [trim((string)($tokenRow['uid'] ?? '')), trim((string)($requestRow['uid'] ?? ''))],
        [trim((string)($tokenRow['path'] ?? '')), trim((string)($requestRow['receipt_path'] ?? ''))],
        [trim((string)($tokenRow['hash'] ?? '')), trim((string)($requestRow['receipt_hash'] ?? ''))],
    ];

    if ($token === '' || !hash_equals($token, trim((string)($requestRow['receipt_token'] ?? '')))) {
        return false;
    }

    foreach ($pairs as [$indexValue, $requestValue]) {
        if ($indexValue === '' || $requestValue === '' || !hash_equals($indexValue, $requestValue)) {
            return false;
        }
    }

    return true;
}

function add_money_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function add_money_round($value): float
{
    if (is_string($value)) {
        $value = str_replace(',', '', trim($value));
    }

    return round((float)$value, 2);
}

function add_money_country_for_user(array $user, array $wallet = []): string
{
    if (function_exists('auth_pricing_country_from_user')) {
        return auth_pricing_country_from_user($user, $wallet);
    }

    foreach (['pricing_country', 'market_country', 'service_country', 'country_code', 'country'] as $key) {
        $country = strtoupper(trim((string)($user[$key] ?? $wallet[$key] ?? '')));
        if (in_array($country, ['BD', 'MY'], true)) {
            return $country;
        }
    }

    $currency = strtoupper(trim((string)($wallet['wallet_currency'] ?? $wallet['currency'] ?? $user['currency'] ?? '')));
    return in_array($currency, ['MYR', 'RM'], true) ? 'MY' : 'BD';
}

function add_money_currency_for_country(string $country): string
{
    $country = strtoupper(trim($country));
    return $country === 'MY' ? 'MYR' : 'BDT';
}

function add_money_currency_label(string $currency): string
{
    $currency = strtoupper(trim($currency));
    return in_array($currency, ['MYR', 'RM'], true) ? 'RM' : 'BDT';
}

function add_money_mask_account_number($value): string
{
    $clean = preg_replace('/\s+/', '', trim((string)$value));
    if ($clean === '') {
        return '';
    }

    $length = strlen($clean);
    if ($length <= 4) {
        return str_repeat('*', $length);
    }

    $visible = min(5, $length - 1);
    return str_repeat('*', max(4, $length - $visible)) . substr($clean, -$visible);
}

function add_money_idempotency_key(string $raw): string
{
    $key = trim($raw);
    if ($key === '') {
        return '';
    }

    $key = preg_replace('/[^A-Za-z0-9._:-]/', '', $key);
    return substr((string)$key, 0, 128);
}

function add_money_idempotency_path(string $uid, string $key): string
{
    return 'ADD_MONEY_IDEMPOTENCY/' . rawurlencode(trim($uid)) . '/' . hash('sha256', $key);
}

function add_money_delete_receipt_file(array $receipt): void
{
    $relative = ltrim((string)($receipt['path'] ?? ''), '/\\');
    if ($relative === '') {
        return;
    }

    $base = realpath(dirname(__DIR__) . '/storage/add_money');
    $target = realpath(dirname(__DIR__) . '/' . $relative);
    if ($base === false || $target === false) {
        return;
    }

    $basePrefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($target, $basePrefix) === 0 && is_file($target)) {
        @unlink($target);
    }
}

function add_money_cleanup_idempotency(string $path): void
{
    $path = trim($path);
    if ($path !== '') {
        fb_delete($path);
    }
}

function add_money_unique_index_claim(string $path, string $uid, string $requestId, array $payload, bool $allowStale = false): array
{
    $path = trim($path, '/');
    $uid = trim($uid);
    $requestId = trim($requestId);
    if ($path === '' || $uid === '' || $requestId === '') {
        return ['ok' => false, 'code' => 'INDEX_CLAIM_INVALID'];
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'INDEX_READ_FAILED'];
        }

        $current = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        if ($current !== []) {
            $currentUid = trim((string)($current['uid'] ?? ''));
            $currentRequestId = trim((string)($current['request_id'] ?? ''));
            if ($currentRequestId !== '' && hash_equals($currentRequestId, $requestId) && hash_equals($currentUid, $uid)) {
                return ['ok' => true, 'claimed' => false, 'duplicate' => true, 'row' => $current];
            }

            $updatedAt = (int)($current['updated_at'] ?? $current['created_at'] ?? 0);
            $requestMissing = false;
            if ($currentRequestId !== '') {
                $requestSnapshot = fb_get_with_etag('ADD_MONEY_REQUESTS/' . $currentRequestId);
                if (empty($requestSnapshot['ok']) || !is_string($requestSnapshot['etag'] ?? null)) {
                    return ['ok' => false, 'code' => 'INDEX_REQUEST_CHECK_FAILED'];
                }
                $requestMissing = ($requestSnapshot['value'] ?? null) === null;
            }
            $stale = $allowStale && $requestMissing && $updatedAt > 0 && $updatedAt <= add_money_now() - 600;
            if (!$stale) {
                return ['ok' => false, 'code' => 'INDEX_ALREADY_CLAIMED', 'conflict' => true, 'row' => $current];
            }
        } elseif (($snapshot['value'] ?? null) !== null) {
            return ['ok' => false, 'code' => 'INDEX_INVALID_VALUE', 'conflict' => true];
        }

        $row = array_merge($payload, [
            'uid' => $uid,
            'request_id' => $requestId,
            'updated_at' => add_money_now(),
        ]);
        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if (!empty($write['ok'])) {
            return ['ok' => true, 'claimed' => true, 'duplicate' => false, 'row' => $row];
        }
        if ((int)($write['status'] ?? 0) !== 412) {
            return ['ok' => false, 'code' => 'INDEX_WRITE_FAILED'];
        }
    }

    return ['ok' => false, 'code' => 'INDEX_CLAIM_CONFLICT', 'conflict' => true];
}

function add_money_unique_index_release(string $path, string $uid, string $requestId): bool
{
    $path = trim($path, '/');
    $snapshot = fb_get_with_etag($path);
    $row = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        return false;
    }
    if ($row === []) {
        return true;
    }
    if (!hash_equals(trim((string)($row['uid'] ?? '')), trim($uid))
        || !hash_equals(trim((string)($row['request_id'] ?? '')), trim($requestId))) {
        return false;
    }

    $delete = fb_delete_if_match($path, (string)$snapshot['etag']);
    return !empty($delete['ok']);
}

function add_money_unique_index_finalize(string $path, string $uid, string $requestId, array $patch): bool
{
    $path = trim($path, '/');
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        $row = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null) || $row === []) {
            return false;
        }
        if (!hash_equals(trim((string)($row['uid'] ?? '')), trim($uid))
            || !hash_equals(trim((string)($row['request_id'] ?? '')), trim($requestId))) {
            return false;
        }

        $updated = array_merge($row, $patch, ['updated_at' => add_money_now()]);
        $write = fb_put_if_match($path, $updated, (string)$snapshot['etag']);
        if (!empty($write['ok'])) {
            return true;
        }
        if ((int)($write['status'] ?? 0) !== 412) {
            return false;
        }
    }

    return false;
}

function add_money_release_unique_claims(array $paths, string $uid, string $requestId): void
{
    foreach (array_reverse(array_values(array_unique($paths))) as $path) {
        @add_money_unique_index_release((string)$path, $uid, $requestId);
    }
}

function add_money_default_settings(): array
{
    return [
        'BD' => [
            'enabled' => false,
            'bkash_number' => '',
            'bkash_account_type' => 'Personal',
            'nagad_number' => '',
            'nagad_account_type' => 'Personal',
            'instruction' => 'Send money first, then submit transaction ID and sender number.',
        ],
        'MY' => [
            'enabled' => false,
            'bank_name' => '',
            'account_holder' => '',
            'account_number' => '',
            'instruction' => 'Transfer to the bank account, then upload your receipt.',
        ],
    ];
}

function add_money_settings(): array
{
    $defaults = add_money_default_settings();
    $row = fb_get('CONFIG/ADD_MONEY');
    $row = is_array($row) ? $row : [];

    foreach (['BD', 'MY'] as $country) {
        $cfg = is_array($row[$country] ?? null) ? $row[$country] : [];
        $defaults[$country] = array_merge($defaults[$country], $cfg);
        $defaults[$country]['enabled'] = !empty($defaults[$country]['enabled']);
    }

    return $defaults;
}

function add_money_save_settings(array $body, string $adminUid): array
{
    $now = add_money_now();
    $settings = add_money_settings();

    $bd = is_array($body['BD'] ?? null) ? $body['BD'] : (is_array($body['bd'] ?? null) ? $body['bd'] : []);
    $my = is_array($body['MY'] ?? null) ? $body['MY'] : (is_array($body['my'] ?? null) ? $body['my'] : []);

    $next = [
        'BD' => [
            'enabled' => !empty($bd['enabled']),
            'bkash_number' => trim((string)($bd['bkash_number'] ?? $settings['BD']['bkash_number'] ?? '')),
            'bkash_account_type' => trim((string)($bd['bkash_account_type'] ?? $settings['BD']['bkash_account_type'] ?? 'Personal')),
            'nagad_number' => trim((string)($bd['nagad_number'] ?? $settings['BD']['nagad_number'] ?? '')),
            'nagad_account_type' => trim((string)($bd['nagad_account_type'] ?? $settings['BD']['nagad_account_type'] ?? 'Personal')),
            'instruction' => trim((string)($bd['instruction'] ?? $settings['BD']['instruction'] ?? '')),
        ],
        'MY' => [
            'enabled' => !empty($my['enabled']),
            'bank_name' => trim((string)($my['bank_name'] ?? $settings['MY']['bank_name'] ?? '')),
            'account_holder' => trim((string)($my['account_holder'] ?? $settings['MY']['account_holder'] ?? '')),
            'account_number' => trim((string)($my['account_number'] ?? $settings['MY']['account_number'] ?? '')),
            'instruction' => trim((string)($my['instruction'] ?? $settings['MY']['instruction'] ?? '')),
        ],
        'updated_at' => $now,
        'updated_by' => $adminUid,
    ];

    if (!fb_put('CONFIG/ADD_MONEY', $next)) {
        return ['ok' => false, 'code' => 'SAVE_FAILED', 'message' => 'Failed to save add money settings'];
    }

    return ['ok' => true, 'code' => 'SUCCESS', 'message' => 'Add money settings saved', 'data' => $next];
}

function add_money_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int)$value === 1;
    }

    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on', 'active'], true);
}

function add_money_payment_account_id(): string
{
    return 'AMA' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function add_money_normalize_method(string $method, string $country = ''): string
{
    $method = strtoupper(trim($method));
    $country = strtoupper(trim($country));
    $allowed = ['BKASH', 'NAGAD', 'BANK', 'EWALLET'];

    if (str_contains($method, 'TOUCH') || str_contains($method, 'TNG') || str_contains($method, 'EWALLET') || str_contains($method, 'E-WALLET')) {
        return 'EWALLET';
    }
    if (str_contains($method, 'BKASH')) {
        return 'BKASH';
    }
    if (str_contains($method, 'NAGAD')) {
        return 'NAGAD';
    }
    if (str_contains($method, 'BANK') || str_contains($method, 'RHB')) {
        return 'BANK';
    }

    if (!in_array($method, $allowed, true)) {
        return $country === 'BD' ? 'BKASH' : 'BANK';
    }

    return $method;
}

function add_money_normalize_payment_account(array $row, string $id = ''): array
{
    $country = strtoupper(trim((string)($row['country'] ?? '')));
    $country = $country === 'MY' ? 'MY' : 'BD';
    $currency = strtoupper(trim((string)($row['currency'] ?? add_money_currency_for_country($country))));
    $currency = in_array($currency, ['MYR', 'RM'], true) ? 'MYR' : 'BDT';
    $accountId = trim((string)($row['account_id'] ?? $id));

    $displayName = trim((string)($row['display_name'] ?? $row['name'] ?? ''));
    $accountHolder = trim((string)($row['account_holder'] ?? $row['holder_name'] ?? ''));
    $logoUrl = trim((string)($row['logo_url'] ?? $row['logo'] ?? $row['image_url'] ?? $row['icon_url'] ?? ''));

    return [
        'id' => $accountId,
        'account_id' => $accountId,
        'country' => $country,
        'currency' => $currency,
        'method' => add_money_normalize_method((string)($row['method'] ?? ''), $country),
        'name' => $displayName,
        'display_name' => $displayName,
        'holder_name' => $accountHolder,
        'account_holder' => $accountHolder,
        'account_number' => trim((string)($row['account_number'] ?? '')),
        'instruction' => trim((string)($row['instruction'] ?? '')),
        'logo' => $logoUrl,
        'logo_url' => $logoUrl,
        'active' => add_money_bool($row['active'] ?? false),
        'sort_order' => (int)($row['sort_order'] ?? 100),
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
    ];
}

function add_money_list_payment_accounts(?string $country = null, bool $includeInactive = false): array
{
    $country = strtoupper(trim((string)$country));
    $rows = fb_get('CONFIG/ADD_MONEY_ACCOUNTS');
    $rows = is_array($rows) ? $rows : [];
    $items = [];

    foreach ($rows as $id => $row) {
        if (!is_array($row)) {
            continue;
        }

        $item = add_money_normalize_payment_account($row, (string)$id);
        if ($country !== '' && $item['country'] !== $country) {
            continue;
        }
        if (!$includeInactive && empty($item['active'])) {
            continue;
        }

        $items[] = $item;
    }

    usort($items, static function (array $a, array $b): int {
        $sort = (int)($a['sort_order'] ?? 100) <=> (int)($b['sort_order'] ?? 100);
        if ($sort !== 0) {
            return $sort;
        }

        return strcasecmp((string)($a['display_name'] ?? ''), (string)($b['display_name'] ?? ''));
    });

    return $items;
}

function add_money_has_configured_payment_accounts(string $country): bool
{
    return count(add_money_list_payment_accounts($country, true)) > 0;
}

function add_money_legacy_payment_accounts(string $country, array $settings = []): array
{
    $country = strtoupper(trim($country));
    $settings = $settings ?: add_money_settings();
    $cfg = is_array($settings[$country] ?? null) ? $settings[$country] : [];
    if (empty($cfg['enabled'])) {
        return [];
    }

    $now = (int)($settings['updated_at'] ?? 0);
    $instruction = trim((string)($cfg['instruction'] ?? ''));
    $items = [];

    if ($country === 'MY') {
        $accountNumber = trim((string)($cfg['account_number'] ?? ''));
        if ($accountNumber !== '') {
            $items[] = add_money_normalize_payment_account([
                'account_id' => 'legacy_my_bank',
                'country' => 'MY',
                'currency' => 'MYR',
                'method' => 'BANK',
                'display_name' => trim((string)($cfg['bank_name'] ?? '')) ?: 'Bank Transfer',
                'account_holder' => trim((string)($cfg['account_holder'] ?? '')),
                'account_number' => $accountNumber,
                'instruction' => $instruction,
                'active' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $items;
    }

    $bkashNumber = trim((string)($cfg['bkash_number'] ?? ''));
    if ($bkashNumber !== '') {
        $items[] = add_money_normalize_payment_account([
            'account_id' => 'legacy_bd_bkash',
            'country' => 'BD',
            'currency' => 'BDT',
            'method' => 'BKASH',
            'display_name' => 'bKash ' . (trim((string)($cfg['bkash_account_type'] ?? '')) ?: 'Payment'),
            'account_holder' => trim((string)($cfg['bkash_account_type'] ?? '')),
            'account_number' => $bkashNumber,
            'instruction' => $instruction,
            'active' => true,
            'sort_order' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $nagadNumber = trim((string)($cfg['nagad_number'] ?? ''));
    if ($nagadNumber !== '') {
        $items[] = add_money_normalize_payment_account([
            'account_id' => 'legacy_bd_nagad',
            'country' => 'BD',
            'currency' => 'BDT',
            'method' => 'NAGAD',
            'display_name' => 'Nagad ' . (trim((string)($cfg['nagad_account_type'] ?? '')) ?: 'Payment'),
            'account_holder' => trim((string)($cfg['nagad_account_type'] ?? '')),
            'account_number' => $nagadNumber,
            'instruction' => $instruction,
            'active' => true,
            'sort_order' => 20,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return $items;
}

function add_money_payment_accounts_for_country(string $country, array $settings = []): array
{
    $country = strtoupper(trim($country)) === 'MY' ? 'MY' : 'BD';
    if (add_money_has_configured_payment_accounts($country)) {
        return add_money_list_payment_accounts($country, false);
    }

    return add_money_legacy_payment_accounts($country, $settings);
}

function add_money_country_enabled(string $country, array $settings = []): bool
{
    $country = strtoupper(trim($country)) === 'MY' ? 'MY' : 'BD';
    if (add_money_has_configured_payment_accounts($country)) {
        return count(add_money_list_payment_accounts($country, false)) > 0;
    }

    $settings = $settings ?: add_money_settings();
    if (!empty($settings[$country]['enabled'])) {
        return true;
    }

    return count(add_money_payment_accounts_for_country($country, $settings)) > 0;
}

function add_money_save_payment_account(array $body, string $adminUid): array
{
    $now = add_money_now();
    $accountId = trim((string)($body['account_id'] ?? ''));
    $existing = [];
    if ($accountId !== '') {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $accountId)) {
            return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Invalid payment account ID'];
        }
        $row = fb_get('CONFIG/ADD_MONEY_ACCOUNTS/' . $accountId);
        $existing = is_array($row) ? $row : [];
    } else {
        $accountId = add_money_payment_account_id();
    }

    $country = strtoupper(trim((string)($body['country'] ?? $existing['country'] ?? '')));
    if (!in_array($country, ['BD', 'MY'], true)) {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Country must be BD or MY'];
    }

    $method = add_money_normalize_method((string)($body['method'] ?? $existing['method'] ?? ''), $country);
    $displayName = trim((string)($body['display_name'] ?? $existing['display_name'] ?? ''));
    $accountHolder = trim((string)($body['account_holder'] ?? $existing['account_holder'] ?? ''));
    $accountNumber = trim((string)($body['account_number'] ?? $existing['account_number'] ?? ''));

    if ($displayName === '' || $accountHolder === '' || $accountNumber === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Payment name, account holder and account number are required'];
    }

    $row = add_money_normalize_payment_account([
        'account_id' => $accountId,
        'country' => $country,
        'currency' => add_money_currency_for_country($country),
        'method' => $method,
        'display_name' => $displayName,
        'account_holder' => $accountHolder,
        'account_number' => $accountNumber,
        'instruction' => trim((string)($body['instruction'] ?? $existing['instruction'] ?? '')),
        'logo_url' => trim((string)($body['logo_url'] ?? $existing['logo_url'] ?? '')),
        'active' => add_money_bool($body['active'] ?? $existing['active'] ?? true),
        'sort_order' => (int)($body['sort_order'] ?? $existing['sort_order'] ?? 100),
        'created_at' => (int)($existing['created_at'] ?? 0) ?: $now,
        'updated_at' => $now,
    ]);
    $row['updated_by'] = $adminUid;

    if (!fb_put('CONFIG/ADD_MONEY_ACCOUNTS/' . $accountId, $row)) {
        return ['ok' => false, 'code' => 'SAVE_FAILED', 'message' => 'Failed to save payment account'];
    }

    return ['ok' => true, 'code' => 'SUCCESS', 'message' => 'Payment account saved', 'data' => $row];
}

function add_money_user_payload(array $user, array $wallet = []): array
{
    $country = add_money_country_for_user($user, $wallet);
    $currency = add_money_currency_for_country($country);
    $settings = add_money_settings();
    $accounts = add_money_payment_accounts_for_country($country, $settings);
    $countrySettings = is_array($settings[$country] ?? null) ? $settings[$country] : [];
    $countrySettings['enabled'] = add_money_country_enabled($country, $settings);

    return [
        'pricing_country' => $country,
        'currency' => $currency,
        'currency_label' => add_money_currency_label($currency),
        'settings' => $countrySettings,
        'accounts' => $accounts,
    ];
}

function add_money_safe_key(string $value): string
{
    return hash('sha256', strtoupper(trim($value)));
}

function add_money_receipt_dir(): string
{
    return dirname(__DIR__) . '/storage/add_money/' . date('Y-m');
}

function add_money_detect_upload_mime(string $tmpPath): string
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    return (string)$finfo->file($tmpPath);
}

function add_money_store_receipt(array $file, string $requestId, string $uid): array
{
    if (empty($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'code' => 'RECEIPT_REQUIRED', 'message' => 'Receipt upload is required'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'code' => 'INVALID_RECEIPT', 'message' => 'Invalid receipt upload'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        return ['ok' => false, 'code' => 'INVALID_RECEIPT_SIZE', 'message' => 'Receipt file must be 5 MB or smaller'];
    }

    $mime = add_money_detect_upload_mime($tmp);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'code' => 'INVALID_RECEIPT_TYPE', 'message' => 'Only JPG, PNG, WEBP or PDF receipt files are allowed'];
    }

    $hash = hash_file('sha256', $tmp);
    if ($hash === '') {
        return ['ok' => false, 'code' => 'RECEIPT_UPLOAD_FAILED', 'message' => 'Receipt upload failed. Please try again.'];
    }

    $existingHash = fb_get('ADD_MONEY_RECEIPT_HASHES/' . $hash);
    if ($existingHash !== null) {
        $existingRequestId = is_array($existingHash)
            ? trim((string)($existingHash['request_id'] ?? ''))
            : trim((string)$existingHash);
        if ($existingRequestId !== '' && add_money_find_request($existingRequestId) !== []) {
            return ['ok' => false, 'code' => 'DUPLICATE_RECEIPT', 'message' => 'This receipt has already been submitted.'];
        }
    }

    $dir = add_money_receipt_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'code' => 'RECEIPT_UPLOAD_FAILED', 'message' => 'Receipt upload failed. Please try again.'];
    }

    $ext = $allowed[$mime];
    $relative = 'storage/add_money/' . date('Y-m') . '/' . $requestId . '.' . $ext;
    $target = dirname(__DIR__) . '/' . $relative;

    if (!move_uploaded_file($tmp, $target)) {
        return ['ok' => false, 'code' => 'RECEIPT_UPLOAD_FAILED', 'message' => 'Receipt upload failed. Please try again.'];
    }

    $base = realpath(dirname(__DIR__) . '/storage/add_money');
    $realTarget = realpath($target);
    if ($base === false || $realTarget === false || strpos($realTarget, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0 || !is_file($realTarget) || filesize($realTarget) <= 0) {
        if (is_file($target)) {
            @unlink($target);
        }
        return ['ok' => false, 'code' => 'RECEIPT_UPLOAD_FAILED', 'message' => 'Receipt upload failed. Please try again.'];
    }

    $token = add_money_token(18);
    $url = function_exists('app_api_url') ? app_api_url('add_money/receipt.php?t=' . rawurlencode($token)) : '';

    return [
        'ok' => true,
        'hash' => $hash,
        'mime' => $mime,
        'path' => $relative,
        'token' => $token,
        'url' => $url,
        'size' => $size,
    ];
}

function add_money_find_request(string $requestId): array
{
    $requestId = trim($requestId);
    if ($requestId === '') {
        return [];
    }

    $row = fb_get('ADD_MONEY_REQUESTS/' . $requestId);
    return is_array($row) ? $row : [];
}

function add_money_patch_request(string $requestId, array $patch): bool
{
    $requestId = trim($requestId);
    if ($requestId === '') {
        return false;
    }

    $patch['updated_at'] = add_money_now();
    $ok1 = fb_patch('ADD_MONEY_REQUESTS/' . $requestId, $patch);

    $row = add_money_find_request($requestId);
    $uid = trim((string)($row['uid'] ?? $patch['uid'] ?? ''));
    $ok2 = true;
    if ($uid !== '') {
        $ok2 = fb_patch('ADD_MONEY_BY_USER/' . $uid . '/' . $requestId, $patch);
    }

    return $ok1 && $ok2;
}

function add_money_sync_user_request(array $row): bool
{
    $uid = trim((string)($row['uid'] ?? ''));
    $requestId = trim((string)($row['request_id'] ?? ''));
    if ($uid === '' || $requestId === '') {
        return false;
    }

    return fb_put('ADD_MONEY_BY_USER/' . $uid . '/' . $requestId, $row);
}

function add_money_finalize_request(string $requestId, string $expectedStatus, array $patch): array
{
    $requestId = trim($requestId);
    $expectedStatus = strtoupper(trim($expectedStatus));
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $path = 'ADD_MONEY_REQUESTS/' . $requestId;
        $snapshot = fb_get_with_etag($path);
        $row = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null) || $row === []) {
            return ['ok' => false, 'code' => 'REQUEST_NOT_FOUND'];
        }

        $status = strtoupper(trim((string)($row['status'] ?? '')));
        $targetStatus = strtoupper(trim((string)($patch['status'] ?? '')));
        if ($targetStatus !== '' && $status === $targetStatus) {
            return [
                'ok' => add_money_sync_user_request($row),
                'row' => $row,
                'duplicate' => true,
                'code' => 'SUCCESS',
            ];
        }
        if ($status !== $expectedStatus) {
            return ['ok' => false, 'code' => 'REQUEST_STATUS_CONFLICT', 'row' => $row];
        }

        $final = array_merge($row, $patch, ['updated_at' => add_money_now()]);
        $write = fb_put_if_match($path, $final, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            continue;
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'REQUEST_FINALIZATION_FAILED', 'row' => $row];
        }
        if (!add_money_sync_user_request($final)) {
            return ['ok' => false, 'code' => 'REQUEST_INDEX_FINALIZATION_FAILED', 'row' => $final];
        }

        return ['ok' => true, 'code' => 'SUCCESS', 'row' => $final];
    }

    return ['ok' => false, 'code' => 'REQUEST_FINALIZATION_CONFLICT'];
}

function add_money_telegram_bot_token(): string
{
    return defined('TELEGRAM_BOT_TOKEN') ? trim((string)TELEGRAM_BOT_TOKEN) : '';
}

function add_money_telegram_chat_id(): string
{
    return defined('TELEGRAM_CHAT_ID') ? trim((string)TELEGRAM_CHAT_ID) : '';
}

function add_money_action_key(): string
{
    if (defined('TELEGRAM_ADD_MONEY_ACTION_KEY') && trim((string)TELEGRAM_ADD_MONEY_ACTION_KEY) !== '') {
        return trim((string)TELEGRAM_ADD_MONEY_ACTION_KEY);
    }

    return defined('APP_KEY') ? trim((string)APP_KEY) : '';
}

function add_money_telegram_enabled(): bool
{
    return add_money_telegram_bot_token() !== ''
        && add_money_telegram_chat_id() !== ''
        && add_money_action_key() !== '';
}

function add_money_action_details(string $action): array
{
    $code = strtolower(trim($action));
    $map = [
        'a' => 'APPROVE',
        'r' => 'REJECT',
        'v' => 'VIEW',
    ];

    return isset($map[$code])
        ? ['ok' => true, 'code' => $code, 'action' => $map[$code]]
        : ['ok' => false, 'code' => '', 'action' => ''];
}

function add_money_signature(string $requestId, string $action): string
{
    $details = add_money_action_details($action);
    $code = (string)($details['code'] ?? '');
    if ($requestId === '' || $code === '' || add_money_action_key() === '') {
        return '';
    }

    return substr(hash_hmac('sha256', $code . '|' . $requestId, add_money_action_key()), 0, 16);
}

function add_money_callback_data(string $action, string $requestId): string
{
    $requestId = trim($requestId);
    $details = add_money_action_details($action);
    if (empty($details['ok']) || $requestId === '') {
        return '';
    }

    $code = (string)$details['code'];
    $data = 'am|' . $code . '|' . $requestId . '|' . add_money_signature($requestId, $code);
    return strlen($data) <= 64 ? $data : '';
}

function add_money_parse_callback_data(string $callbackData): array
{
    $parts = explode('|', trim($callbackData));
    if (count($parts) !== 4 || strtolower((string)$parts[0]) !== 'am') {
        return ['ok' => false, 'code' => 'INVALID_CALLBACK', 'message' => 'Invalid add money callback'];
    }

    $details = add_money_action_details((string)$parts[1]);
    $requestId = trim((string)$parts[2]);
    $signature = trim((string)$parts[3]);

    if (empty($details['ok']) || $requestId === '') {
        return ['ok' => false, 'code' => 'INVALID_CALLBACK', 'message' => 'Invalid add money action'];
    }

    $expected = add_money_signature($requestId, (string)$details['code']);
    if ($signature === '' || $expected === '' || !hash_equals($expected, $signature)) {
        return ['ok' => false, 'code' => 'INVALID_SIGNATURE', 'message' => 'Invalid add money callback signature'];
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'action' => (string)$details['action'],
        'request_id' => $requestId,
    ];
}

function add_money_keyboard(string $requestId, bool $showReceipt = false, string $receiptUrl = ''): array
{
    $rows = [
        [
            ['text' => '✅ Approve', 'callback_data' => add_money_callback_data('a', $requestId)],
            ['text' => '❌ Reject', 'callback_data' => add_money_callback_data('r', $requestId)],
        ],
        [
            $showReceipt && trim($receiptUrl) !== ''
                ? ['text' => '👁 View Receipt', 'url' => trim($receiptUrl)]
                : ['text' => '👁 View', 'callback_data' => add_money_callback_data('v', $requestId)],
        ],
    ];

    return ['inline_keyboard' => $rows];
}

function add_money_message(array $row): string
{
    $country = strtoupper(trim((string)($row['pricing_country'] ?? 'BD')));
    $currency = add_money_currency_label((string)($row['currency'] ?? add_money_currency_for_country($country)));
    $title = $country === 'MY' ? 'New MY Add Money Request' : 'New BD Add Money Request';
    $method = strtoupper(trim((string)($row['method'] ?? '')));
    $receiptUrl = trim((string)($row['receipt_url'] ?? ''));
    $sentTo = trim((string)($row['selected_payment_account_name'] ?? $row['payment_account_name'] ?? ''));
    $sentTo = $sentTo !== '' ? $sentTo : 'Not selected';
    $accountMasked = trim((string)($row['selected_payment_account_masked'] ?? ''));

    $text = "<b>" . add_money_h($title) . "</b>\n\n"
        . "Request ID: <code>" . add_money_h($row['request_id'] ?? '') . "</code>\n"
        . "Name: <b>" . add_money_h($row['name'] ?? '-') . "</b>\n"
        . "Phone: <code>" . add_money_h($row['phone'] ?? '-') . "</code>\n"
        . "Role: <b>" . add_money_h($row['role'] ?? '-') . "</b>\n"
        . "Sent To: <b>" . add_money_h($sentTo) . "</b>\n"
        . "Method: <b>" . add_money_h($method) . "</b>\n"
        . "Amount: <b>" . add_money_h($currency) . " " . number_format((float)($row['amount'] ?? 0), 2) . "</b>\n"
        . "Status: <b>" . add_money_h($row['status'] ?? 'PENDING') . "</b>\n";

    if ($accountMasked !== '') {
        $text .= "Account: <code>" . add_money_h($accountMasked) . "</code>\n";
    }

    $status = strtoupper(trim((string)($row['status'] ?? 'PENDING')));
    if (in_array($status, ['APPROVED', 'REJECTED'], true)) {
        $processedBy = $status === 'APPROVED'
            ? trim((string)($row['approved_by'] ?? ''))
            : trim((string)($row['rejected_by'] ?? ''));
        $processedRole = $status === 'APPROVED'
            ? trim((string)($row['approved_by_role'] ?? ''))
            : trim((string)($row['rejected_by_role'] ?? ''));
        $processedAt = $status === 'APPROVED'
            ? (int)($row['approved_at'] ?? 0)
            : (int)($row['rejected_at'] ?? 0);

        $processedLabel = trim($processedRole . ($processedBy !== '' ? ' / ' . $processedBy : ''));
        if ($processedLabel !== '') {
            $text .= "Processed By: <b>" . add_money_h($processedLabel) . "</b>\n";
        }
        if ($processedAt > 0) {
            $text .= "Processed Time: " . date('Y-m-d H:i:s', $processedAt) . "\n";
        }
    }

    if ($country === 'BD') {
        $text .= "Transaction ID: <code>" . add_money_h($row['transaction_id'] ?? '-') . "</code>\n"
            . "Sender Number: <code>" . add_money_h($row['sender_number'] ?? '-') . "</code>\n";
    } else {
        $text .= "Receipt: " . ($receiptUrl !== '' ? '<a href="' . add_money_h($receiptUrl) . '">Open receipt</a>' : '-') . "\n";
    }

    $text .= "Created: " . date('Y-m-d H:i:s', (int)($row['created_at'] ?? add_money_now()));

    return $text;
}

function add_money_telegram_api(string $method, array $payload): array
{
    if (!add_money_telegram_enabled()) {
        return ['ok' => false, 'code' => 'TELEGRAM_DISABLED', 'message' => 'Telegram add money config missing'];
    }

    $ch = curl_init('https://api.telegram.org/bot' . add_money_telegram_bot_token() . '/' . ltrim($method, '/'));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'code' => 'TELEGRAM_ERROR', 'message' => $err ?: 'Telegram request failed'];
    }

    $json = json_decode((string)$raw, true);
    if (!is_array($json) || empty($json['ok'])) {
        return [
            'ok' => false,
            'code' => 'TELEGRAM_ERROR',
            'message' => (string)($json['description'] ?? 'Telegram request failed'),
            'http_status' => $status,
        ];
    }

    return ['ok' => true, 'code' => 'SUCCESS', 'message' => 'Telegram sent', 'data' => (array)($json['result'] ?? [])];
}

function add_money_notify_telegram(array $row): array
{
    $requestId = trim((string)($row['request_id'] ?? ''));
    if ($requestId === '') {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'message' => 'Request ID missing'];
    }

    if (!add_money_telegram_enabled()) {
        add_money_patch_request($requestId, [
            'telegram_sent' => false,
            'telegram_error' => 'Telegram add money config missing',
        ]);
        return ['ok' => false, 'code' => 'TELEGRAM_DISABLED', 'message' => 'Telegram add money config missing'];
    }

    $res = add_money_telegram_api('sendMessage', [
        'chat_id' => add_money_telegram_chat_id(),
        'text' => add_money_message($row),
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => false,
        'reply_markup' => json_encode(add_money_keyboard($requestId, trim((string)($row['receipt_url'] ?? '')) !== '', (string)($row['receipt_url'] ?? '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    if (empty($res['ok'])) {
        add_money_patch_request($requestId, [
            'telegram_sent' => false,
            'telegram_error' => substr((string)($res['message'] ?? 'Telegram send failed'), 0, 400),
        ]);
        return $res;
    }

    $data = (array)($res['data'] ?? []);
    add_money_patch_request($requestId, [
        'telegram_sent' => true,
        'telegram_error' => '',
        'telegram_message_id' => (int)($data['message_id'] ?? 0),
        'telegram_chat_id' => (string)($data['chat']['id'] ?? add_money_telegram_chat_id()),
        'telegram_sent_at' => add_money_now(),
    ]);

    return $res;
}

function add_money_sync_processed_telegram_message(array $row): void
{
    $status = strtoupper(trim((string)($row['status'] ?? '')));
    if (!in_array($status, ['APPROVED', 'REJECTED'], true)) {
        return;
    }

    $chatId = trim((string)($row['telegram_chat_id'] ?? ''));
    $messageId = (int)($row['telegram_message_id'] ?? 0);
    if ($chatId === '' || $messageId <= 0) {
        return;
    }

    add_money_telegram_api('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => add_money_message($row),
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => false,
        'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function add_money_create_request(string $uid, array $user, array $wallet, array $body, array $files = []): array
{
    $uid = trim($uid);
    $country = add_money_country_for_user($user, $wallet);
    $currency = add_money_currency_for_country($country);
    $settings = add_money_settings();

    if (!add_money_country_enabled($country, $settings)) {
        return ['ok' => false, 'code' => 'ADD_MONEY_DISABLED', 'message' => 'Add money is not available for your account right now'];
    }

    $requestId = add_money_request_id();
    $now = add_money_now();
    $method = strtoupper(trim((string)($body['method'] ?? '')));
    $amount = add_money_round($body['amount'] ?? $body['amount_bdt'] ?? $body['amount_rm'] ?? 0);
    $paymentAccountId = trim((string)($body['payment_account_id'] ?? $body['account_id'] ?? ''));
    $idempotencyKey = add_money_idempotency_key((string)($body['idempotency_key'] ?? ''));
    $idempotencyPath = $idempotencyKey !== '' ? add_money_idempotency_path($uid, $idempotencyKey) : '';
    if ($idempotencyPath !== '') {
        $existingIdempotency = fb_get($idempotencyPath);
        if (is_array($existingIdempotency)) {
            $existingStatus = strtoupper(trim((string)($existingIdempotency['status'] ?? '')));
            $existingRequestId = trim((string)($existingIdempotency['request_id'] ?? ''));
            if ($existingRequestId !== '') {
                $existingRequest = add_money_find_request($existingRequestId);
                if ($existingRequest !== []) {
                    if ($existingStatus !== 'SUCCESS') {
                        @add_money_unique_index_finalize($idempotencyPath, $uid, $existingRequestId, ['status' => 'SUCCESS']);
                    }
                    return [
                        'ok' => true,
                        'code' => 'SUCCESS',
                        'message' => 'Add money request submitted. Please wait for approval.',
                        'data' => [
                            'request' => $existingRequest,
                            'telegram_sent' => !empty($existingRequest['telegram_sent']),
                            'idempotent_replay' => true,
                        ],
                    ];
                }
            }

            $updatedAt = (int)($existingIdempotency['updated_at'] ?? $existingIdempotency['created_at'] ?? 0);
            if ($existingStatus === 'PROCESSING' && $updatedAt > add_money_now() - 600) {
                return ['ok' => false, 'code' => 'REQUEST_IN_PROGRESS', 'message' => 'This add money request is still processing. Please wait.'];
            }
        }
    }

    $transactionId = trim((string)($body['transaction_id'] ?? ''));
    $senderNumber = trim((string)($body['sender_number'] ?? ''));
    $note = trim((string)($body['note'] ?? $body['reference'] ?? ''));
    $receipt = [];

    if ($amount <= 0) {
        return ['ok' => false, 'code' => 'INVALID_AMOUNT', 'message' => 'Amount must be greater than zero'];
    }

    $activeAccounts = add_money_payment_accounts_for_country($country, $settings);
    $matchedAccount = [];

    if ($paymentAccountId === '') {
        return ['ok' => false, 'code' => 'PAYMENT_ACCOUNT_REQUIRED', 'message' => 'Please select the account you sent money to.'];
    }

    foreach ($activeAccounts as $account) {
        $accountId = trim((string)($account['account_id'] ?? $account['id'] ?? ''));
        if ($accountId !== '' && hash_equals($accountId, $paymentAccountId)) {
            $matchedAccount = $account;
            break;
        }
    }

    if ($matchedAccount === [] || empty($matchedAccount['active'])) {
        return ['ok' => false, 'code' => 'PAYMENT_ACCOUNT_INVALID', 'message' => 'Selected payment account is not available.'];
    }

    $matchedCountry = strtoupper(trim((string)($matchedAccount['country'] ?? '')));
    if ($matchedCountry !== $country) {
        return ['ok' => false, 'code' => 'PAYMENT_ACCOUNT_INVALID', 'message' => 'Selected payment account is not available.'];
    }

    $method = add_money_normalize_method((string)($matchedAccount['method'] ?? $method), $country);
    $matchedAccount['method'] = $method;
    $matchedAccount['currency'] = add_money_currency_for_country($country);
    $idempotencyStarted = false;
    $claimedUniqueIndexes = [];
    $txnIndexPath = '';

    if ($country === 'BD') {
        if (!in_array($method, ['BKASH', 'NAGAD'], true)) {
            return ['ok' => false, 'code' => 'PAYMENT_ACCOUNT_INVALID', 'message' => 'Selected payment account is not available.'];
        }

        if ($transactionId === '') {
            return ['ok' => false, 'code' => 'TXN_REQUIRED', 'message' => 'Transaction ID is required'];
        }
        if ($senderNumber === '') {
            return ['ok' => false, 'code' => 'SENDER_REQUIRED', 'message' => 'Sender number is required'];
        }

        $txnKey = add_money_safe_key($method . '|' . $transactionId);
        $txnIndexPath = 'ADD_MONEY_TXN_IDS/' . $method . '/' . $txnKey;
        $existingTxn = fb_get($txnIndexPath);
        if (is_array($existingTxn)) {
            $existingTxnRequestId = trim((string)($existingTxn['request_id'] ?? ''));
            $existingTxnUpdatedAt = (int)($existingTxn['updated_at'] ?? $existingTxn['created_at'] ?? 0);
            if (($existingTxnRequestId !== '' && add_money_find_request($existingTxnRequestId) !== [])
                || $existingTxnUpdatedAt > add_money_now() - 600) {
                return ['ok' => false, 'code' => 'DUPLICATE_TXN_ID', 'message' => 'This transaction ID has already been submitted.'];
            }
        } elseif ($existingTxn !== null) {
            return ['ok' => false, 'code' => 'DUPLICATE_TXN_ID', 'message' => 'This transaction ID has already been submitted.'];
        }
    } else {
        if (!in_array($method, ['BANK', 'EWALLET'], true)) {
            return ['ok' => false, 'code' => 'PAYMENT_ACCOUNT_INVALID', 'message' => 'Selected payment account is not available.'];
        }
    }

    if ($idempotencyPath !== '') {
        $claim = add_money_unique_index_claim($idempotencyPath, $uid, $requestId, [
            'status' => 'PROCESSING',
            'created_at' => $now,
        ], true);
        if (empty($claim['ok'])) {
            return [
                'ok' => false,
                'code' => !empty($claim['conflict']) ? 'REQUEST_IN_PROGRESS' : 'SAVE_FAILED',
                'message' => !empty($claim['conflict'])
                    ? 'This add money request is still processing. Please wait.'
                    : 'Failed to start add money request',
            ];
        }
        $idempotencyStarted = !empty($claim['claimed']);
        if ($idempotencyStarted) {
            $claimedUniqueIndexes[] = $idempotencyPath;
        }
    }

    if ($txnIndexPath !== '') {
        $txnClaim = add_money_unique_index_claim($txnIndexPath, $uid, $requestId, [
            'transaction_id' => $transactionId,
            'method' => $method,
            'status' => 'RESERVED',
            'created_at' => $now,
        ], true);
        if (empty($txnClaim['ok'])) {
            add_money_release_unique_claims($claimedUniqueIndexes, $uid, $requestId);
            return ['ok' => false, 'code' => 'DUPLICATE_TXN_ID', 'message' => 'This transaction ID has already been submitted.'];
        }
        if (!empty($txnClaim['claimed'])) {
            $claimedUniqueIndexes[] = $txnIndexPath;
        }
    }

    if ($country !== 'BD') {
        $receiptFile = is_array($files['receipt_upload'] ?? null) ? $files['receipt_upload'] : [];
        $receipt = add_money_store_receipt($receiptFile, $requestId, $uid);
        if (empty($receipt['ok'])) {
            add_money_release_unique_claims($claimedUniqueIndexes, $uid, $requestId);
            return $receipt;
        }

        if (!empty($receipt['hash'])) {
            $receiptHashPath = 'ADD_MONEY_RECEIPT_HASHES/' . $receipt['hash'];
            $receiptClaim = add_money_unique_index_claim($receiptHashPath, $uid, $requestId, [
                'status' => 'RESERVED',
                'created_at' => $now,
            ], true);
            if (empty($receiptClaim['ok'])) {
                add_money_delete_receipt_file($receipt);
                add_money_release_unique_claims($claimedUniqueIndexes, $uid, $requestId);
                return ['ok' => false, 'code' => 'DUPLICATE_RECEIPT', 'message' => 'This receipt has already been submitted.'];
            }
            if (!empty($receiptClaim['claimed'])) {
                $claimedUniqueIndexes[] = $receiptHashPath;
            }
        }
    }

    $receiptTokenMetadata = !empty($receipt['token'])
        ? add_money_receipt_token_metadata($now)
        : [];

    $row = [
        'request_id' => $requestId,
        'uid' => $uid,
        'name' => (string)($user['name'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'role' => strtoupper(trim((string)($user['role'] ?? 'USER'))),
        'pricing_country' => $country,
        'currency' => $currency,
        'method' => $method,
        'payment_account_id' => (string)($matchedAccount['account_id'] ?? $paymentAccountId),
        'payment_account_name' => (string)($matchedAccount['display_name'] ?? ''),
        'selected_payment_account_id' => (string)($matchedAccount['account_id'] ?? $paymentAccountId),
        'selected_payment_account_name' => (string)($matchedAccount['display_name'] ?? ''),
        'selected_payment_method' => $method,
        'selected_payment_country' => $country,
        'selected_payment_currency' => $currency,
        'selected_payment_account_masked' => add_money_mask_account_number($matchedAccount['account_number'] ?? ''),
        'amount' => $amount,
        'transaction_id' => $transactionId,
        'sender_number' => $senderNumber,
        'receipt_url' => (string)($receipt['url'] ?? ''),
        'receipt_token' => (string)($receipt['token'] ?? ''),
        'receipt_path' => (string)($receipt['path'] ?? ''),
        'receipt_mime' => (string)($receipt['mime'] ?? ''),
        'receipt_hash' => (string)($receipt['hash'] ?? ''),
        'note' => $note,
        'status' => 'PENDING',
        'created_at' => $now,
        'updated_at' => $now,
        'approved_by' => '',
        'approved_at' => 0,
        'rejected_by' => '',
        'rejected_at' => 0,
        'reject_reason' => '',
        'balance_before' => 0,
        'balance_after' => 0,
    ];

    if ($receiptTokenMetadata !== []) {
        $row['receipt_token_version'] = (int)$receiptTokenMetadata['receipt_token_version'];
        $row['receipt_token_issued_at'] = (int)$receiptTokenMetadata['issued_at'];
        $row['receipt_token_expires_at'] = (int)$receiptTokenMetadata['expires_at'];
        $row['receipt_token_status'] = (string)$receiptTokenMetadata['status'];
    }

    if (!fb_put('ADD_MONEY_REQUESTS/' . $requestId, $row)) {
        add_money_delete_receipt_file($receipt);
        add_money_release_unique_claims($claimedUniqueIndexes, $uid, $requestId);
        return ['ok' => false, 'code' => 'SAVE_FAILED', 'message' => 'Failed to save add money request'];
    }

    if (!fb_put('ADD_MONEY_BY_USER/' . $uid . '/' . $requestId, $row)) {
        fb_delete('ADD_MONEY_REQUESTS/' . $requestId);
        add_money_delete_receipt_file($receipt);
        add_money_release_unique_claims($claimedUniqueIndexes, $uid, $requestId);
        return ['ok' => false, 'code' => 'SAVE_FAILED', 'message' => 'Failed to save add money history'];
    }

    if ($country === 'BD') {
        if (!add_money_unique_index_finalize($txnIndexPath, $uid, $requestId, ['status' => 'SUCCESS'])) {
            fb_delete('ADD_MONEY_REQUESTS/' . $requestId);
            fb_delete('ADD_MONEY_BY_USER/' . $uid . '/' . $requestId);
            add_money_release_unique_claims($claimedUniqueIndexes, $uid, $requestId);
            return ['ok' => false, 'code' => 'SAVE_FAILED', 'message' => 'Failed to save transaction duplicate index'];
        }
    } elseif (!empty($receipt['hash'])) {
        $receiptHashPath = 'ADD_MONEY_RECEIPT_HASHES/' . $receipt['hash'];
        $hashSaved = add_money_unique_index_finalize($receiptHashPath, $uid, $requestId, ['status' => 'SUCCESS']);
        $tokenSaved = fb_put('ADD_MONEY_RECEIPT_TOKENS/' . $receipt['token'], array_merge([
            'request_id' => $requestId,
            'uid' => $uid,
            'path' => $receipt['path'],
            'mime' => $receipt['mime'],
            'hash' => $receipt['hash'],
            'created_at' => $now,
        ], $receiptTokenMetadata));

        if (!$hashSaved || !$tokenSaved) {
            fb_delete('ADD_MONEY_REQUESTS/' . $requestId);
            fb_delete('ADD_MONEY_BY_USER/' . $uid . '/' . $requestId);
            @add_money_unique_index_release($receiptHashPath, $uid, $requestId);
            fb_delete('ADD_MONEY_RECEIPT_TOKENS/' . $receipt['token']);
            add_money_delete_receipt_file($receipt);
            add_money_release_unique_claims($claimedUniqueIndexes, $uid, $requestId);
            return ['ok' => false, 'code' => 'SAVE_FAILED', 'message' => 'Failed to save receipt duplicate index'];
        }
    }

    if ($idempotencyPath !== '' && !add_money_unique_index_finalize($idempotencyPath, $uid, $requestId, ['status' => 'SUCCESS'])) {
        return ['ok' => false, 'code' => 'SAVE_FAILED', 'message' => 'Request saved but idempotency finalization must be retried'];
    }

    $telegram = add_money_notify_telegram($row);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Add money request submitted. Please wait for approval.',
        'data' => [
            'request' => $row,
            'telegram_sent' => !empty($telegram['ok']),
        ],
    ];
}

function add_money_list_user_history(string $uid, int $limit = 100): array
{
    $rows = fb_get('ADD_MONEY_BY_USER/' . trim($uid));
    $rows = is_array($rows) ? $rows : [];
    $items = [];
    foreach ($rows as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['request_id'] = (string)($row['request_id'] ?? $id);
        $items[] = $row;
    }

    usort($items, static fn(array $a, array $b): int => (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0));
    return array_slice($items, 0, max(1, min(300, $limit)));
}

function add_money_public_request_row(array $row): array
{
    foreach ([
        'receipt_path',
        'receipt_token',
        'receipt_hash',
        'receipt_token_version',
        'receipt_token_issued_at',
        'receipt_token_expires_at',
        'receipt_token_status',
        'telegram_chat_id',
        'telegram_message_id',
        'telegram_error',
        'processing_by',
        'processing_error',
    ] as $key) {
        unset($row[$key]);
    }

    return $row;
}

function add_money_public_request_rows(array $rows): array
{
    return array_map(
        static fn($row): array => is_array($row) ? add_money_public_request_row($row) : [],
        $rows
    );
}

function add_money_list_admin_page(array $filters = [], string $cursor = '', int $limit = 10): array
{
    $status = strtoupper(trim((string)($filters['status'] ?? '')));
    $country = strtoupper(trim((string)($filters['country'] ?? '')));
    $method = strtoupper(trim((string)($filters['method'] ?? '')));

    return admin_firebase_cursor_page(
        'ADD_MONEY_REQUESTS',
        $limit,
        $cursor,
        static function (array $row) use ($status, $country, $method): bool {
        $rowStatus = strtoupper(trim((string)($row['status'] ?? 'PENDING')));
        $rowCountry = strtoupper(trim((string)($row['pricing_country'] ?? '')));
        $rowMethod = strtoupper(trim((string)($row['method'] ?? '')));

        if ($status !== '' && $status !== 'ALL' && $rowStatus !== $status) {
                return false;
        }
        if ($country !== '' && $country !== 'ALL' && $rowCountry !== $country) {
                return false;
        }
        if ($method !== '' && $method !== 'ALL' && $rowMethod !== $method) {
                return false;
        }

            return true;
        },
        static function (array $row, string $id): array {
            $row['request_id'] = (string)($row['request_id'] ?? $id);
            return $row;
        }
    );
}

function add_money_list_admin(array $filters = [], int $limit = 10, string $cursor = ''): array
{
    $page = add_money_list_admin_page($filters, $cursor, $limit);
    return (array)($page['items'] ?? []);
}

function add_money_repair_approved_operation(array $row): bool
{
    $requestId = trim((string)($row['request_id'] ?? ''));
    $uid = trim((string)($row['uid'] ?? ''));
    $amount = add_money_round($row['amount'] ?? 0);
    $currency = wallet_normalize_currency_code((string)($row['currency'] ?? $row['wallet_currency'] ?? ''));
    if ($currency === '') {
        $currency = add_money_currency_for_country((string)($row['pricing_country'] ?? 'BD'));
    }
    if ($requestId === '' || $uid === '' || $amount <= 0 || $currency === '') {
        return false;
    }

    $operationRef = 'ADD_MONEY_APPROVE:' . hash('sha256', $requestId);
    $existingOperation = fb_get(wallet_financial_operation_scope_path($operationRef, 'REQUEST_FINAL'));
    if (!is_array($existingOperation)) {
        return true;
    }
    $operation = wallet_financial_operation_begin(
        $operationRef,
        'ADD_MONEY_APPROVAL_CREDIT',
        'REQUEST_FINAL',
        $uid,
        $amount,
        $currency,
        ['request_id' => $requestId, 'source' => 'TERMINAL_REPLAY_REPAIR']
    );
    if (!empty($operation['duplicate']) && !empty($operation['completed'])) {
        return true;
    }
    if (empty($operation['ok']) || empty($operation['claim'])) {
        return false;
    }

    $financialClaim = (array)$operation['claim'];
    if (!wallet_financial_operation_claim_wallet_applied($financialClaim)) {
        wallet_financial_operation_mark_reconciliation_required(
            $financialClaim,
            'APPROVED_REQUEST_WALLET_EVIDENCE_MISSING',
            'Approved Add Money request has no reliable wallet mutation evidence',
            ['request_finalized' => true, 'request_id' => $requestId]
        );
        return false;
    }

    $ledgerId = wallet_financial_operation_ledger_id($operationRef, 'ADD_MONEY_APPROVAL_CREDIT');
    $repair = wallet_credit_available($uid, $amount, $operationRef, 'ADD_MONEY', 'Manual add money approved', [
        'ledger_id' => $ledgerId,
        'source' => 'ADD_MONEY_REQUEST',
        'request_id' => $requestId,
        'ref_id' => $requestId,
        'currency' => $currency,
        'wallet_currency' => $currency,
        'status' => 'SUCCESS',
    ], [
        'financial_operation' => $financialClaim,
    ]);
    if (empty($repair['ok'])) {
        return false;
    }

    return wallet_financial_operation_mark_completed($financialClaim, [
        'wallet_applied' => true,
        'ledger_written' => true,
        'request_finalized' => true,
        'history_written' => true,
        'request_id' => $requestId,
        'ledger_id' => (string)($repair['ledger_id'] ?? $row['ledger_id'] ?? $ledgerId),
        'result_data' => $row,
    ]);
}

function add_money_process_request(string $requestId, string $action, string $actorUid, string $actorRole = 'ADMIN', string $reason = ''): array
{
    $requestId = trim($requestId);
    $action = strtoupper(trim($action));
    $actorUid = trim($actorUid);
    $now = add_money_now();

    if ($requestId === '' || !in_array($action, ['APPROVE', 'REJECT'], true)) {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Invalid add money action'];
    }

    $res = fb_get_with_etag('ADD_MONEY_REQUESTS/' . $requestId);
    if (!$res['ok'] || !is_array($res['value']) || empty($res['etag'])) {
        return ['ok' => false, 'code' => 'NOT_FOUND', 'message' => 'Add money request not found'];
    }

    $row = $res['value'];
    $status = strtoupper(trim((string)($row['status'] ?? 'PENDING')));
    if ($status === 'APPROVED' && $action === 'APPROVE') {
        if (!add_money_sync_user_request($row)) {
            return ['ok' => false, 'code' => 'REQUEST_INDEX_FINALIZATION_FAILED', 'message' => 'Approved request history requires repair', 'data' => $row];
        }
        if (!add_money_repair_approved_operation($row)) {
            return ['ok' => false, 'code' => 'FINANCIAL_OPERATION_FINALIZATION_FAILED', 'message' => 'Approved operation finalization requires retry', 'data' => $row];
        }
        return ['ok' => true, 'code' => 'SUCCESS', 'message' => 'Add money request approved', 'data' => $row, 'idempotent_replay' => true];
    }
    if ($status === 'REJECTED' && $action === 'REJECT') {
        if (!add_money_sync_user_request($row)) {
            return ['ok' => false, 'code' => 'REQUEST_INDEX_FINALIZATION_FAILED', 'message' => 'Rejected request history requires repair', 'data' => $row];
        }
        return ['ok' => true, 'code' => 'SUCCESS', 'message' => 'Add money request rejected', 'data' => $row, 'idempotent_replay' => true];
    }

    $lockStatus = $action === 'APPROVE' ? 'APPROVING' : 'REJECTING';
    $freshLock = false;
    if ($status === 'PENDING') {
        $locked = $row;
        $locked['status'] = $lockStatus;
        $locked['processing_by'] = $actorUid;
        $locked['processing_role'] = $actorRole;
        $locked['processing_at'] = $now;
        $locked['updated_at'] = $now;

        $save = fb_put_if_match('ADD_MONEY_REQUESTS/' . $requestId, $locked, (string)$res['etag']);
        if (!($save['ok'] ?? false)) {
            return ['ok' => false, 'code' => 'REQUEST_BUSY', 'message' => 'Request is already being processed'];
        }
        $row = $locked;
        $status = $lockStatus;
        $freshLock = true;
    } elseif ($status === $lockStatus) {
        $row['processing_by'] = (string)($row['processing_by'] ?? $actorUid);
        $row['processing_role'] = (string)($row['processing_role'] ?? $actorRole);
    } else {
        return ['ok' => false, 'code' => 'ALREADY_PROCESSED', 'message' => 'Request already processed.', 'data' => $row];
    }

    if ($freshLock) {
        add_money_patch_request($requestId, ['status' => $lockStatus, 'uid' => (string)($row['uid'] ?? '')]);
        notification_emit_request_status_notification(
            'ADD_MONEY',
            $requestId,
            (string)($row['uid'] ?? ''),
            'PENDING',
            $lockStatus,
            $row,
            'ADD_MONEY_PROCESSING'
        );
    }

    if ($action === 'REJECT') {
        $patch = [
            'status' => 'REJECTED',
            'rejected_by' => $actorUid,
            'rejected_by_role' => $actorRole,
            'rejected_at' => $now,
            'reject_reason' => $reason,
        ];
        $finalize = add_money_finalize_request($requestId, 'REJECTING', $patch);
        if (empty($finalize['ok'])) {
            return [
                'ok' => false,
                'code' => (string)($finalize['code'] ?? 'REQUEST_FINALIZATION_FAILED'),
                'message' => 'Add money rejection could not be finalized. Please retry.',
                'data' => (array)($finalize['row'] ?? $row),
            ];
        }
        $final = (array)($finalize['row'] ?? array_merge($row, $patch, ['updated_at' => $now]));
        add_money_sync_processed_telegram_message($final);
        notification_emit_request_status_notification(
            'ADD_MONEY',
            $requestId,
            (string)($final['uid'] ?? ''),
            $lockStatus,
            'REJECTED',
            $final,
            'ADD_MONEY_REJECTED'
        );
        return ['ok' => true, 'code' => 'SUCCESS', 'message' => 'Add money request rejected', 'data' => $final];
    }

    $uid = trim((string)($row['uid'] ?? ''));
    $amount = add_money_round($row['amount'] ?? 0);
    $currency = wallet_normalize_currency_code((string)($row['currency'] ?? $row['wallet_currency'] ?? ''));
    if ($currency === '') {
        $currency = add_money_currency_for_country((string)($row['pricing_country'] ?? 'BD'));
    }

    $operationRef = 'ADD_MONEY_APPROVE:' . hash('sha256', $requestId);
    $operation = wallet_financial_operation_begin(
        $operationRef,
        'ADD_MONEY_APPROVAL_CREDIT',
        'REQUEST_FINAL',
        $uid,
        $amount,
        $currency,
        [
            'request_id' => $requestId,
            'actor_uid' => $actorUid,
            'actor_role' => $actorRole,
            'method' => (string)($row['method'] ?? ''),
        ]
    );
    if (!empty($operation['duplicate']) && !empty($operation['completed'])) {
        $resultData = is_array($operation['operation']['result_data'] ?? null) ? $operation['operation']['result_data'] : $row;
        return ['ok' => true, 'code' => 'SUCCESS', 'message' => 'Add money request approved', 'data' => $resultData, 'idempotent_replay' => true];
    }
    if (empty($operation['ok']) || empty($operation['claim'])) {
        return [
            'ok' => false,
            'code' => (string)($operation['code'] ?? 'FINANCIAL_OPERATION_UNAVAILABLE'),
            'message' => (string)($operation['message'] ?? 'Wallet operation is unavailable'),
            'data' => $row,
        ];
    }
    $financialClaim = (array)$operation['claim'];

    $credit = wallet_credit_available($uid, $amount, $operationRef, 'ADD_MONEY', 'Manual add money approved', [
        'ledger_id' => wallet_financial_operation_ledger_id($operationRef, 'ADD_MONEY_APPROVAL_CREDIT'),
        'source' => 'ADD_MONEY_REQUEST',
        'amount' => $amount,
        'method' => (string)($row['method'] ?? ''),
        'request_id' => $requestId,
        'ref_id' => $requestId,
        'currency' => $currency,
        'wallet_currency' => $currency,
        'approved_by' => $actorUid,
        'approved_by_role' => $actorRole,
        'approved_at' => $now,
        'reference' => (string)($row['transaction_id'] ?? $row['receipt_hash'] ?? ''),
        'note' => (string)($row['note'] ?? ''),
        'status' => 'SUCCESS',
    ], [
        'financial_operation' => $financialClaim,
    ]);

    if (empty($credit['ok'])) {
        $creditCode = (string)($credit['code'] ?? 'WALLET_CREDIT_FAILED');
        wallet_financial_operation_mark_failed($financialClaim, $creditCode, (string)($credit['message'] ?? 'Wallet credit failed'));
        $nextStatus = in_array($creditCode, ['LEDGER_WRITE_FAILED', 'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED'], true)
            ? 'APPROVING'
            : 'PENDING';
        add_money_patch_request($requestId, [
            'status' => $nextStatus,
            'processing_error' => (string)($credit['message'] ?? 'Wallet credit failed'),
            'uid' => $uid,
        ]);
        return $credit;
    }

    $patch = [
        'status' => 'APPROVED',
        'approved_by' => $actorUid,
        'approved_by_role' => $actorRole,
        'approved_at' => $now,
        'ledger_id' => (string)($credit['ledger_id'] ?? ''),
        'balance_before' => (float)($credit['before_available'] ?? 0),
        'balance_after' => (float)($credit['after_available'] ?? 0),
    ];
    $finalize = add_money_finalize_request($requestId, 'APPROVING', $patch);
    if (empty($finalize['ok'])) {
        wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_FINALIZATION_FAILED', 'Add money request could not be finalized after wallet credit', [
            'wallet_applied' => true,
            'ledger_written' => true,
            'request_id' => $requestId,
            'request_finalized' => false,
        ]);
        return ['ok' => false, 'code' => 'REQUEST_FINALIZATION_FAILED', 'message' => 'Add money request could not be finalized after wallet credit', 'data' => $row];
    }

    $final = (array)($finalize['row'] ?? array_merge($row, $patch, ['updated_at' => $now]));
    add_money_sync_processed_telegram_message($final);
    notification_emit_request_status_notification(
        'ADD_MONEY',
        $requestId,
        (string)($final['uid'] ?? ''),
        $lockStatus,
        'APPROVED',
        $final,
        'ADD_MONEY_APPROVED'
    );

    if (!wallet_financial_operation_mark_completed($financialClaim, [
        'wallet_applied' => true,
        'ledger_written' => true,
        'request_finalized' => true,
        'history_written' => true,
        'notification_written' => true,
        'request_id' => $requestId,
        'ledger_id' => (string)($credit['ledger_id'] ?? ''),
        'result_data' => $final,
    ])) {
        return ['ok' => false, 'code' => 'FINANCIAL_OPERATION_FINALIZATION_FAILED', 'message' => 'Add money approval finalization must be retried', 'data' => $final];
    }

    return ['ok' => true, 'code' => 'SUCCESS', 'message' => 'Add money request approved', 'data' => $final];
}
