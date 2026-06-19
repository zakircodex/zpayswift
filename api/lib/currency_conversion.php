<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/rates.php';

function account_currency_country(string $country): string
{
    $country = strtoupper(trim($country));
    return $country === 'MY' ? 'MY' : 'BD';
}

function account_currency_for_country(string $country): string
{
    return account_currency_country($country) === 'MY' ? 'MYR' : 'BDT';
}

function account_currency_round(float $amount): float
{
    return round($amount, 2);
}

function account_currency_rate_required(): array
{
    $rate = zpay_myr_to_bdt_rate(true);

    if ($rate <= 0) {
        return [
            'ok' => false,
            'code' => 'EXCHANGE_RATE_MISSING',
            'message' => 'Exchange rate is missing. Please update Ringgit rate first.',
        ];
    }

    return [
        'ok' => true,
        'rate' => account_currency_round($rate),
    ];
}

function account_currency_detect_country(array $user, array $wallet = []): string
{
    if (function_exists('auth_pricing_country_from_user')) {
        return account_currency_country(auth_pricing_country_from_user($user, $wallet));
    }

    return account_currency_country((string)(
        $user['pricing_country']
        ?? $user['market_country']
        ?? $user['service_country']
        ?? $wallet['pricing_country']
        ?? $wallet['market_country']
        ?? $wallet['service_country']
        ?? 'BD'
    ));
}

function account_currency_convert_amount(float $amount, string $fromCurrency, string $toCurrency, float $rate): float
{
    $fromCurrency = wallet_normalize_currency_code($fromCurrency, 'BDT');
    $toCurrency = wallet_normalize_currency_code($toCurrency, 'BDT');
    $amount = account_currency_round($amount);

    if ($fromCurrency === $toCurrency) {
        return $amount;
    }

    if ($fromCurrency === 'BDT' && $toCurrency === 'MYR') {
        return account_currency_round($amount / $rate);
    }

    if ($fromCurrency === 'MYR' && $toCurrency === 'BDT') {
        return account_currency_round($amount * $rate);
    }

    return $amount;
}

function account_currency_native_pairs(float $available, float $hold, string $currency, float $rate): array
{
    $currency = wallet_normalize_currency_code($currency, 'BDT');

    if ($currency === 'MYR') {
        return [
            'available_balance_myr' => account_currency_round($available),
            'hold_balance_myr' => account_currency_round($hold),
            'available_balance_bdt' => account_currency_round($available * $rate),
            'hold_balance_bdt' => account_currency_round($hold * $rate),
        ];
    }

    return [
        'available_balance_bdt' => account_currency_round($available),
        'hold_balance_bdt' => account_currency_round($hold),
        'available_balance_myr' => account_currency_round($available / $rate),
        'hold_balance_myr' => account_currency_round($hold / $rate),
    ];
}

function account_currency_preview_for_rows(array $user, array $wallet, string $newCountry): array
{
    $newCountry = account_currency_country($newCountry);
    $oldCountry = account_currency_detect_country($user, $wallet);
    $oldCurrency = wallet_normalize_currency_code(
        $wallet['wallet_currency']
        ?? $wallet['currency']
        ?? $user['wallet_currency']
        ?? $user['currency']
        ?? account_currency_for_country($oldCountry),
        account_currency_for_country($oldCountry)
    );
    $newCurrency = account_currency_for_country($newCountry);

    $available = account_currency_round((float)($wallet['available_balance'] ?? $wallet['balance'] ?? 0));
    $hold = account_currency_round((float)($wallet['hold_balance'] ?? $wallet['held_balance'] ?? $wallet['balance_hold'] ?? 0));

    if ($oldCountry === $newCountry && $oldCurrency === $newCurrency) {
        return [
            'ok' => true,
            'conversion_required' => false,
            'old_pricing_country' => $oldCountry,
            'new_pricing_country' => $newCountry,
            'old_currency' => $oldCurrency,
            'new_currency' => $newCurrency,
            'old_balance' => $available,
            'new_balance' => $available,
            'old_hold_balance' => $hold,
            'new_hold_balance' => $hold,
            'rate_used' => 0.0,
        ];
    }

    $rateRes = account_currency_rate_required();
    if (empty($rateRes['ok'])) {
        return $rateRes;
    }

    $rate = (float)$rateRes['rate'];
    $newAvailable = account_currency_convert_amount($available, $oldCurrency, $newCurrency, $rate);
    $newHold = account_currency_convert_amount($hold, $oldCurrency, $newCurrency, $rate);

    return [
        'ok' => true,
        'conversion_required' => true,
        'old_pricing_country' => $oldCountry,
        'new_pricing_country' => $newCountry,
        'old_currency' => $oldCurrency,
        'new_currency' => $newCurrency,
        'old_balance' => $available,
        'new_balance' => $newAvailable,
        'old_hold_balance' => $hold,
        'new_hold_balance' => $newHold,
        'rate_used' => $rate,
    ];
}

function account_currency_preview_for_uid(string $uid, string $newCountry): array
{
    $uid = trim($uid);
    if ($uid === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'uid is required',
        ];
    }

    $user = fb_get('USERS/' . $uid);
    if (!is_array($user)) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'User not found',
        ];
    }

    $wallet = fb_get('USER_WALLETS/' . $uid);
    $wallet = is_array($wallet) ? $wallet : [];

    return account_currency_preview_for_rows($user, $wallet, $newCountry);
}

function account_currency_log_id(string $prefix = 'CCL'): string
{
    if (function_exists('make_uid')) {
        return (string)make_uid();
    }

    return $prefix . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function account_currency_apply_to_wallet(
    string $uid,
    array $oldUser,
    array $oldWallet,
    string $newCountry,
    string $actorUid,
    string $note = ''
): array {
    $preview = account_currency_preview_for_rows($oldUser, $oldWallet, $newCountry);
    if (empty($preview['ok'])) {
        return $preview;
    }

    if (empty($preview['conversion_required'])) {
        return [
            'ok' => true,
            'code' => 'NO_CONVERSION_REQUIRED',
            'message' => 'Currency conversion was not required',
            'data' => $preview,
        ];
    }

    $uid = trim($uid);
    $actorUid = trim($actorUid);
    $now = function_exists('now_ts') ? now_ts() : time();
    $newCountry = account_currency_country($newCountry);
    $newCurrency = account_currency_for_country($newCountry);
    $rate = (float)$preview['rate_used'];

    for ($i = 0; $i < 5; $i++) {
        $res = fb_get_with_etag('USER_WALLETS/' . $uid);
        if (empty($res['ok']) || empty($res['etag'])) {
            return [
                'ok' => false,
                'code' => 'WALLET_NOT_FOUND',
                'message' => 'Wallet not found or unavailable',
            ];
        }

        $wallet = is_array($res['value']) ? $res['value'] : [];
        $latestPreview = account_currency_preview_for_rows($oldUser, $wallet, $newCountry);
        if (empty($latestPreview['ok'])) {
            return $latestPreview;
        }

        $newAvailable = account_currency_round((float)$latestPreview['new_balance']);
        $newHold = account_currency_round((float)$latestPreview['new_hold_balance']);
        $updated = $wallet;
        $updated['available_balance'] = $newAvailable;
        $updated['balance'] = $newAvailable;
        $updated['hold_balance'] = $newHold;
        $updated['held_balance'] = $newHold;
        $updated['balance_hold'] = $newHold;
        $updated['currency'] = $newCurrency;
        $updated['wallet_currency'] = $newCurrency;
        $updated['pricing_country'] = $newCountry;
        $updated['market_country'] = $newCountry;
        $updated['service_country'] = $newCountry;
        $updated['rate_myr_bdt'] = $rate;
        $updated['currency_converted_at'] = $now;
        $updated['updated_at'] = $now;
        $updated = array_merge($updated, account_currency_native_pairs($newAvailable, $newHold, $newCurrency, $rate));

        $save = fb_put_if_match('USER_WALLETS/' . $uid, $updated, (string)$res['etag']);
        if (($save['status'] ?? 0) === 412) {
            usleep(150000);
            continue;
        }

        if (empty($save['ok'])) {
            return [
                'ok' => false,
                'code' => 'WALLET_UPDATE_FAILED',
                'message' => 'Failed to convert wallet currency',
            ];
        }

        $logId = account_currency_log_id();
        $ledgerId = create_wallet_ledger_full($uid, [
            'type' => 'CURRENCY_CONVERSION',
            'direction' => 'ADJUST',
            'amount' => $newAvailable,
            'currency' => $newCurrency,
            'wallet_currency' => $newCurrency,
            'amount_before' => (float)$latestPreview['old_balance'],
            'amount_after' => $newAvailable,
            'currency_before' => (string)$latestPreview['old_currency'],
            'currency_after' => $newCurrency,
            'rate_used' => $rate,
            'balance_before' => (float)$latestPreview['old_balance'],
            'balance_after' => $newAvailable,
            'before_available' => (float)$latestPreview['old_balance'],
            'after_available' => $newAvailable,
            'before_hold' => (float)$latestPreview['old_hold_balance'],
            'after_hold' => $newHold,
            'actor' => $actorUid,
            'actor_uid' => $actorUid,
            'actor_role' => 'ADMIN',
            'reference' => $logId,
            'ref_id' => $logId,
            'note' => $note,
            'created_at' => $now,
        ]);

        $logRow = [
            'log_id' => $logId,
            'uid' => $uid,
            'ledger_id' => $ledgerId,
            'old_pricing_country' => (string)$latestPreview['old_pricing_country'],
            'new_pricing_country' => $newCountry,
            'old_currency' => (string)$latestPreview['old_currency'],
            'new_currency' => $newCurrency,
            'old_balance' => (float)$latestPreview['old_balance'],
            'new_balance' => $newAvailable,
            'old_hold_balance' => (float)$latestPreview['old_hold_balance'],
            'new_hold_balance' => $newHold,
            'rate_used' => $rate,
            'changed_by' => $actorUid,
            'changed_by_role' => 'ADMIN',
            'changed_at' => $now,
            'note' => $note,
            'status' => 'SUCCESS',
        ];

        if (!fb_put('COUNTRY_CHANGE_LOG/' . $uid . '/' . $logId, $logRow) && function_exists('system_log')) {
            system_log('COUNTRY_CHANGE_LOG_WARNING', $uid, 'Failed to write currency conversion log', $logRow);
        }

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Wallet currency converted successfully',
            'data' => array_merge($latestPreview, [
                'ledger_id' => $ledgerId,
                'log_id' => $logId,
                'available_balance' => $newAvailable,
                'hold_balance' => $newHold,
                'currency' => $newCurrency,
                'wallet_currency' => $newCurrency,
            ]),
        ];
    }

    return [
        'ok' => false,
        'code' => 'WALLET_CONFLICT',
        'message' => 'Wallet update conflict, please retry',
    ];
}
