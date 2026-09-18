<?php
declare(strict_types=1);

$assertions = 0;
$smsDb = [];
$smsQueries = [];
$testNow = 1789000100;

function sms_archive_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fb_get(string $path, array $query = []): mixed
{
    global $smsDb, $smsQueries;
    if ($path !== 'WORKER_SMS_ARCHIVE') {
        $archiveId = substr($path, strlen('WORKER_SMS_ARCHIVE/'));
        return $smsDb[$archiveId] ?? null;
    }

    $smsQueries[] = $query;
    $rows = $smsDb;
    ksort($rows, SORT_STRING);
    $endAt = isset($query['endAt']) ? json_decode((string)$query['endAt'], true) : '';
    if (is_string($endAt) && $endAt !== '') {
        $rows = array_filter(
            $rows,
            static fn($_row, $key): bool => strcmp((string)$key, $endAt) <= 0,
            ARRAY_FILTER_USE_BOTH
        );
    }
    $limit = max(1, (int)($query['limitToLast'] ?? count($rows)));
    return array_slice($rows, -$limit, null, true);
}

function fb_put(string $path, $data): bool
{
    global $smsDb;
    $archiveId = substr($path, strlen('WORKER_SMS_ARCHIVE/'));
    $smsDb[$archiveId] = $data;
    return true;
}

function fb_delete(string $path): bool
{
    global $smsDb;
    $archiveId = substr($path, strlen('WORKER_SMS_ARCHIVE/'));
    unset($smsDb[$archiveId]);
    return true;
}

function now_ts(): int
{
    global $testNow;
    return $testNow;
}

require_once dirname(__DIR__) . '/api/lib/worker_sms_archive.php';

$fixtureId = worker_sms_archive_id('phone_1', 1789000000123, 2, 'NAGAD', 'Success');
sms_archive_expect(
    $fixtureId === '1789000000123-dcb5d13f801ad10635e523f4',
    'PHP archive key must match the Android fixture'
);

$validInput = [
    'archive_id' => $fixtureId,
    'device_id' => 'phone_1',
    'received_at' => 1789000000123,
    'sender' => 'NAGAD',
    'body' => 'Success',
    'subscription_id' => 2,
    'request_id' => 'TP-100',
    'assigned_slot' => 'SIM2',
    'sim_mode' => 'NAGAD',
];
$record = worker_sms_archive_record($validInput);
sms_archive_expect($record !== [], 'A valid worker SMS payload must be accepted');
sms_archive_expect(worker_sms_archive_record(array_replace($validInput, ['archive_id' => str_repeat('0', 38)])) === [], 'A forged archive ID must be rejected');
$sanitized = worker_sms_archive_record(array_replace($validInput, [
    'request_id' => 'invalid request value',
    'assigned_slot' => 'SIM9',
    'sim_mode' => 'UNKNOWN',
]));
sms_archive_expect(
    ($sanitized['request_id'] ?? null) === '' &&
    ($sanitized['assigned_slot'] ?? null) === '' &&
    ($sanitized['sim_mode'] ?? null) === '',
    'Invalid optional correlation metadata must not reject an otherwise valid SMS'
);

sms_archive_expect(worker_sms_archive_store($record), 'First SMS upload must be stored');
$storedAt = (int)($smsDb[$fixtureId]['stored_at'] ?? 0);
$testNow++;
sms_archive_expect(worker_sms_archive_store($record), 'Duplicate SMS upload must remain idempotently successful');
sms_archive_expect((int)($smsDb[$fixtureId]['stored_at'] ?? 0) === $storedAt, 'Duplicate upload must preserve the original stored timestamp');
sms_archive_expect((int)($smsDb[$fixtureId]['last_uploaded_at'] ?? 0) === $testNow, 'Duplicate upload must update its last-upload timestamp');

$smsDb = [];
for ($i = 1; $i <= 25; $i++) {
    $receivedAt = 1789000200000 + $i;
    $body = 'Worker message ' . $i;
    $archiveId = worker_sms_archive_id('phone_1', $receivedAt, 1, 'RETAILER', $body);
    $smsDb[$archiveId] = [
        'archive_id' => $archiveId,
        'device_id' => 'phone_1',
        'received_at' => $receivedAt,
        'sender' => 'RETAILER',
        'body' => $body,
        'subscription_id' => 1,
        'request_id' => 'TP-' . $i,
        'assigned_slot' => 'SIM1',
        'sim_mode' => 'RETAILER',
        'stored_at' => $testNow,
    ];
}

$first = worker_sms_archive_page(10);
$second = worker_sms_archive_page(10, (string)$first['pagination']['next_cursor']);
$third = worker_sms_archive_page(10, (string)$second['pagination']['next_cursor']);
sms_archive_expect(count($first['items']) === 10, 'First SMS page must contain ten rows');
sms_archive_expect(($first['items'][0]['body'] ?? '') === 'Worker message 25', 'SMS list must show newest records first');
sms_archive_expect(count($second['items']) === 10, 'Second SMS page must contain ten rows');
sms_archive_expect(count($third['items']) === 5, 'Last SMS page must contain the remaining rows');
sms_archive_expect(empty($third['pagination']['has_more']), 'Last SMS page must not expose another cursor');

$allIds = array_map(
    static fn(array $row): string => (string)$row['archive_id'],
    array_merge($first['items'], $second['items'], $third['items'])
);
sms_archive_expect(count($allIds) === count(array_unique($allIds)), 'SMS cursor pages must not duplicate rows');
sms_archive_expect(empty(worker_sms_archive_page(10, 'bad-cursor')['ok']), 'Invalid SMS cursors must be rejected');

$deleteId = (string)$first['items'][0]['archive_id'];
$deleted = worker_sms_archive_delete($deleteId);
sms_archive_expect(!empty($deleted['deleted']) && !isset($smsDb[$deleteId]), 'Admin delete must remove the selected SMS');
sms_archive_expect(empty(worker_sms_archive_delete($deleteId)['deleted']), 'Deleting an absent SMS must be safely idempotent');

foreach ($smsQueries as $query) {
    sms_archive_expect(json_decode((string)($query['orderBy'] ?? ''), true) === '$key', 'SMS pages must use Firebase key ordering');
    sms_archive_expect((int)($query['limitToLast'] ?? 0) <= 12, 'SMS page reads must stay bounded');
}

echo "Worker SMS archive tests passed ({$assertions} assertions).\n";
