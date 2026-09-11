<?php
declare(strict_types=1);

const SERVICE_HOURS_OPEN_HOUR = 8;
const SERVICE_HOURS_BD_TIMEZONE = 'Asia/Dhaka';
const SERVICE_HOURS_MY_TIMEZONE = 'Asia/Kuala_Lumpur';

function service_hours_account_country(array $user, array $wallet = []): string
{
    if (function_exists('auth_pricing_country_from_user')) {
        $country = strtoupper(trim((string)auth_pricing_country_from_user($user, $wallet)));
        if (in_array($country, ['BD', 'MY'], true)) {
            return $country;
        }
    }

    foreach ([
        $user['pricing_country'] ?? '',
        $user['market_country'] ?? '',
        $user['service_country'] ?? '',
        $user['country'] ?? '',
    ] as $candidate) {
        $country = strtoupper(trim((string)$candidate));
        if (in_array($country, ['BD', 'MY'], true)) {
            return $country;
        }
    }

    foreach ([
        $wallet['currency'] ?? '',
        $wallet['wallet_currency'] ?? '',
        $user['wallet_currency'] ?? '',
        $user['currency'] ?? '',
    ] as $candidate) {
        $currency = strtoupper(trim((string)$candidate));
        if (in_array($currency, ['MYR', 'RM', 'RINGGIT'], true)) {
            return 'MY';
        }
        if (in_array($currency, ['BDT', 'TK', 'TAKA'], true)) {
            return 'BD';
        }
    }

    return 'BD';
}

function service_hours_timezone(string $country): DateTimeZone
{
    return new DateTimeZone(strtoupper(trim($country)) === 'MY'
        ? SERVICE_HOURS_MY_TIMEZONE
        : SERVICE_HOURS_BD_TIMEZONE);
}

function service_hours_manual_service_state(array $user, array $wallet = [], ?int $timestamp = null): array
{
    $country = service_hours_account_country($user, $wallet);
    $timezone = service_hours_timezone($country);
    $timestamp = $timestamp ?? (function_exists('now_ts') ? (int)now_ts() : time());
    $localNow = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
    $closed = (int)$localNow->format('G') < SERVICE_HOURS_OPEN_HOUR;
    $nextOpen = $closed
        ? $localNow->setTime(SERVICE_HOURS_OPEN_HOUR, 0, 0)
        : $localNow->modify('+1 day')->setTime(SERVICE_HOURS_OPEN_HOUR, 0, 0);

    return [
        'closed' => $closed,
        'account_country' => $country,
        'country_name' => $country === 'MY' ? 'Malaysia' : 'Bangladesh',
        'timezone' => $timezone->getName(),
        'local_time' => $localNow->format(DateTimeInterface::ATOM),
        'closed_from' => '00:00',
        'opens_at' => '08:00',
        'next_open_at' => $nextOpen->format(DateTimeInterface::ATOM),
        'retry_after_seconds' => $closed ? max(1, $nextOpen->getTimestamp() - $timestamp) : 0,
        'transfer_available' => true,
    ];
}

function service_hours_service_label(string $service): string
{
    $key = strtoupper(trim($service));
    $labels = [
        'ADD_MONEY' => 'Add Money',
        'BKASH' => 'bKash',
        'NAGAD' => 'Nagad',
        'MFS' => 'bKash and Nagad',
        'BUNDLE' => 'Bundle',
        'TOPUP' => 'Top-Up',
        'TOP_UP' => 'Top-Up',
    ];

    return $labels[$key] ?? 'This service';
}

function service_hours_manual_service_restriction(
    array $user,
    array $wallet = [],
    string $service = '',
    ?int $timestamp = null
): array {
    $state = service_hours_manual_service_state($user, $wallet, $timestamp);
    if (empty($state['closed'])) {
        return [];
    }

    $label = service_hours_service_label($service);
    return [
        'ok' => false,
        'code' => 'SERVICE_HOURS_CLOSED',
        'message' => $label . ' is available daily from 8:00 AM until midnight ('
            . (string)$state['country_name'] . ' time). Transfer remains available 24/7.',
        'data' => $state,
        'http_status' => 503,
    ];
}

function service_hours_require_manual_service_available(
    array $user,
    array $wallet = [],
    string $service = ''
): void {
    $restriction = service_hours_manual_service_restriction($user, $wallet, $service);
    if ($restriction === []) {
        return;
    }

    api_response(
        false,
        (string)$restriction['code'],
        (string)$restriction['message'],
        (array)$restriction['data'],
        (int)$restriction['http_status']
    );
}
