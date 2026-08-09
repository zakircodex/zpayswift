<?php
declare(strict_types=1);

$testStore = [];
$testVersions = [];
$testRacePath = '';
$testRaceTriggered = false;
$assertions = 0;

function support_test_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function support_test_parts(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn($part) => $part !== ''));
}

function support_test_value(string $path): mixed
{
    global $testStore;
    $value = $testStore;
    foreach (support_test_parts($path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
}

function support_test_bump(string $path): void
{
    global $testVersions;
    $parts = support_test_parts($path);
    for ($i = count($parts); $i >= 1; $i--) {
        $parent = implode('/', array_slice($parts, 0, $i));
        $testVersions[$parent] = (int)($testVersions[$parent] ?? 0) + 1;
    }
}

function support_test_set(string $path, mixed $value): void
{
    global $testStore;
    $parts = support_test_parts($path);
    $cursor =& $testStore;
    foreach ($parts as $index => $part) {
        if ($index === count($parts) - 1) {
            if ($value === null) {
                unset($cursor[$part]);
            } else {
                $cursor[$part] = $value;
            }
            break;
        }
        if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
            $cursor[$part] = [];
        }
        $cursor =& $cursor[$part];
    }
    support_test_bump($path);
}

function now_ts(): int
{
    return 1786291200;
}

function fb_get(string $path): mixed
{
    return support_test_value($path);
}

function fb_put(string $path, mixed $data): bool
{
    support_test_set($path, $data);
    return true;
}

function fb_patch(string $path, array $data): bool
{
    $current = support_test_value($path);
    $current = is_array($current) ? $current : [];
    support_test_set($path, array_merge($current, $data));
    return true;
}

function fb_delete(string $path): bool
{
    support_test_set($path, null);
    return true;
}

function fb_get_with_etag(string $path): array
{
    global $testVersions;
    return [
        'ok' => true,
        'status' => 200,
        'etag' => '"v' . (int)($testVersions[$path] ?? 0) . '"',
        'value' => support_test_value($path),
        'error' => '',
    ];
}

function fb_put_if_match(string $path, mixed $data, string $etag): array
{
    global $testVersions, $testRacePath, $testRaceTriggered;
    if ($path === $testRacePath && !$testRaceTriggered) {
        $testRaceTriggered = true;
        support_test_set($path . '/SP_RACE_WINNER', [
            'ticket_id' => 'SP_RACE_WINNER',
            'status' => 'CREATING',
            'reserved_at' => now_ts(),
            'updated_at' => now_ts(),
        ]);
        return ['ok' => false, 'status' => 412];
    }
    $expected = '"v' . (int)($testVersions[$path] ?? 0) . '"';
    if ($etag !== $expected) {
        return ['ok' => false, 'status' => 412];
    }
    support_test_set($path, $data);
    return ['ok' => true, 'status' => 200];
}

require_once dirname(__DIR__) . '/api/lib/support.php';

support_test_expect(support_status_is_active('OPEN'), 'OPEN must remain an active status');
support_test_expect(support_status_is_active('PENDING'), 'PENDING must remain an active status');
support_test_expect(support_status_is_active('REPLIED'), 'REPLIED must remain an active status');
support_test_expect(support_status_is_final('CLOSED'), 'CLOSED must remain a final status');
support_test_expect(support_status_is_final('RESOLVED'), 'RESOLVED must remain a final status');

fb_put('SUPPORT_CONFIG', [
    'contact_us_enabled' => true,
    'ticket_enabled' => true,
    'attachments_enabled' => false,
    'ticket_rate_limit_seconds' => 0,
]);

$auth = [
    'uid' => 'UID_SUPPORT',
    'user' => ['uid' => 'UID_SUPPORT', 'name' => 'Test User', 'phone' => '+60123456789', 'email' => 'test@example.com'],
];
$body = [
    'category_code' => 'ACCOUNT_LOGIN',
    'subject' => 'Login issue',
    'message' => 'Please help with my login issue.',
    'idempotency_key' => 'SUPPORT-TEST-1',
];

$openTicket = [
    'ticket_id' => 'SP_EXISTING',
    'uid' => 'UID_SUPPORT',
    'subject' => 'Existing issue',
    'status' => 'OPEN',
    'created_at' => now_ts() - 60,
    'updated_at' => now_ts() - 30,
    'last_message_at' => now_ts() - 30,
];
fb_put('SUPPORT_TICKETS/SP_EXISTING', $openTicket);
fb_put('SUPPORT_USER_INDEX/UID_SUPPORT/SP_EXISTING', ['ticket_id' => 'SP_EXISTING', 'status' => 'OPEN', 'updated_at' => now_ts() - 30]);

$blocked = support_create_ticket($auth, $body, []);
support_test_expect(empty($blocked['ok']), 'an active ticket must block a second ticket');
support_test_expect(($blocked['code'] ?? '') === 'SUPPORT_ACTIVE_TICKET_EXISTS', 'active ticket must return the canonical code');
support_test_expect(($blocked['active_ticket_id'] ?? '') === 'SP_EXISTING', 'active ticket id must be returned safely');

fb_put('SUPPORT_TICKETS/SP_OTHER_USER', [
    'ticket_id' => 'SP_OTHER_USER',
    'uid' => 'UID_OTHER',
    'subject' => 'Private issue',
    'status' => 'OPEN',
    'updated_at' => now_ts(),
]);
fb_put('SUPPORT_USER_INDEX/UID_SUPPORT/SP_OTHER_USER', ['ticket_id' => 'SP_OTHER_USER', 'status' => 'OPEN', 'updated_at' => now_ts()]);
$ownedActive = support_find_active_ticket_for_uid('UID_SUPPORT');
support_test_expect((string)($ownedActive['ticket_id'] ?? '') === 'SP_EXISTING', 'a mismatched index entry must not expose another user ticket');

fb_patch('SUPPORT_TICKETS/SP_EXISTING', ['status' => 'CLOSED']);
fb_patch('SUPPORT_USER_INDEX/UID_SUPPORT/SP_EXISTING', ['status' => 'CLOSED']);
$created = support_create_ticket($auth, $body, []);
support_test_expect(!empty($created['ok']), 'a closed ticket must allow a new ticket');
$createdId = (string)($created['ticket']['ticket_id'] ?? '');
support_test_expect($createdId !== '' && $createdId !== 'SP_EXISTING', 'new ticket must receive its own canonical id');

$duplicate = support_create_ticket($auth, $body, []);
support_test_expect(!empty($duplicate['ok']) && !empty($duplicate['duplicate']), 'same idempotency key must replay the created ticket');
support_test_expect((string)($duplicate['ticket']['ticket_id'] ?? '') === $createdId, 'idempotent replay must return the same ticket');

$secondBody = $body;
$secondBody['idempotency_key'] = 'SUPPORT-TEST-2';
$doubleCreate = support_create_ticket($auth, $secondBody, []);
support_test_expect(($doubleCreate['code'] ?? '') === 'SUPPORT_ACTIVE_TICKET_EXISTS', 'a different concurrent create identity must still be blocked');
support_test_expect(($doubleCreate['active_ticket_id'] ?? '') === $createdId, 'blocked create must point to the open conversation');

fb_patch('SUPPORT_TICKETS/' . $createdId, ['status' => 'RESOLVED']);
fb_patch('SUPPORT_USER_INDEX/UID_SUPPORT/' . $createdId, ['status' => 'RESOLVED']);
$resolvedBody = $body;
$resolvedBody['idempotency_key'] = 'SUPPORT-TEST-3';
$afterResolved = support_create_ticket($auth, $resolvedBody, []);
support_test_expect(!empty($afterResolved['ok']), 'a resolved ticket must allow a new ticket');

$testRacePath = 'SUPPORT_USER_INDEX/UID_RACE';
$race = support_claim_active_ticket_slot('UID_RACE', 'SP_RACE_LOSER', now_ts());
support_test_expect(empty($race['ok']) && ($race['code'] ?? '') === 'SUPPORT_ACTIVE_TICKET_EXISTS', 'CAS conflict must resolve to the winning active ticket');
support_test_expect((string)($race['active_ticket']['ticket_id'] ?? '') === 'SP_RACE_WINNER', 'CAS race must preserve one active ticket identity');

support_test_expect(function_exists('support_notify_telegram_new_ticket') && function_exists('support_notify_telegram_user_reply'), 'Telegram support functions must remain available');

echo "Support active ticket tests passed ({$assertions} assertions).\n";
