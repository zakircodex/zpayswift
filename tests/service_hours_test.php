<?php
declare(strict_types=1);

$serviceHoursAssertions = 0;

function service_hours_expect(bool $condition, string $message): void
{
    global $serviceHoursAssertions;
    $serviceHoursAssertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function auth_pricing_country_from_user(array $user, array $wallet = []): string
{
    return strtoupper(trim((string)($user['pricing_country'] ?? '')));
}

function service_hours_timestamp(string $localTime, string $timezone): int
{
    return (new DateTimeImmutable($localTime, new DateTimeZone($timezone)))->getTimestamp();
}

$root = dirname(__DIR__);
require_once $root . '/api/lib/service_hours.php';

foreach ([
    'BD' => SERVICE_HOURS_BD_TIMEZONE,
    'MY' => SERVICE_HOURS_MY_TIMEZONE,
] as $country => $timezone) {
    $user = ['pricing_country' => $country];
    $beforeMidnight = service_hours_manual_service_state(
        $user,
        [],
        service_hours_timestamp('2026-09-11 23:59:59', $timezone)
    );
    $midnight = service_hours_manual_service_state(
        $user,
        [],
        service_hours_timestamp('2026-09-12 00:00:00', $timezone)
    );
    $beforeOpen = service_hours_manual_service_state(
        $user,
        [],
        service_hours_timestamp('2026-09-12 07:59:59', $timezone)
    );
    $atOpen = service_hours_manual_service_state(
        $user,
        [],
        service_hours_timestamp('2026-09-12 08:00:00', $timezone)
    );

    service_hours_expect(empty($beforeMidnight['closed']), "{$country} must remain open through 11:59:59 PM.");
    service_hours_expect(!empty($midnight['closed']), "{$country} must close exactly at midnight.");
    service_hours_expect(!empty($beforeOpen['closed']), "{$country} must remain closed through 7:59:59 AM.");
    service_hours_expect(empty($atOpen['closed']), "{$country} must open exactly at 8:00 AM.");
    service_hours_expect(
        (string)$midnight['timezone'] === $timezone,
        "{$country} must use its account-country timezone."
    );
    service_hours_expect(
        !empty($midnight['transfer_available']),
        "{$country} closure payload must state that Transfer remains available."
    );
}

$restriction = service_hours_manual_service_restriction(
    ['pricing_country' => 'MY'],
    [],
    'TOPUP',
    service_hours_timestamp('2026-09-12 01:00:00', SERVICE_HOURS_MY_TIMEZONE)
);
service_hours_expect(($restriction['code'] ?? '') === 'SERVICE_HOURS_CLOSED', 'Closed manual service must use the stable API code.');
service_hours_expect((int)($restriction['http_status'] ?? 0) === 503, 'Closed manual service must return HTTP 503.');
service_hours_expect(str_contains((string)($restriction['message'] ?? ''), 'Transfer remains available 24/7'), 'Closure copy must direct users to Transfer.');

$guardedEndpoints = [
    'api/add_money/submit.php',
    'api/mfs/preview.php',
    'api/mfs/create.php',
    'api/topup/preview.php',
    'api/topup/submit.php',
    'api/bundle/preview.php',
    'api/bundle/submit.php',
];
foreach ($guardedEndpoints as $relativePath) {
    $source = (string)file_get_contents($root . '/' . $relativePath);
    $combinedSource = $relativePath === 'api/add_money/submit.php'
        ? $source . (string)file_get_contents($root . '/api/lib/add_money.php')
        : $source;
    service_hours_expect(
        str_contains($combinedSource, 'service_hours_'),
        "{$relativePath} must enforce the shared service-hours policy."
    );
}

foreach (['api/transfer/preview.php', 'api/transfer/create.php'] as $relativePath) {
    $source = (string)file_get_contents($root . '/' . $relativePath);
    service_hours_expect(
        !str_contains($source, 'service_hours_require_manual_service_available')
            && !str_contains($source, 'service_hours_manual_service_restriction'),
        "{$relativePath} must remain outside the manual service-hours lock."
    );
}

echo "Service hours tests passed ({$serviceHoursAssertions} assertions).\n";
