<?php
declare(strict_types=1);

$supportReplyStore = [];
$supportReplyVersions = [];
$supportReplyNow = 1786381200;
$supportReplyRacePath = '';
$supportReplyRaceTicket = '';
$supportReplyRaceTriggered = false;
$supportReplyAssertions = 0;

function reply_test_expect(bool $condition, string $message): void
{
    global $supportReplyAssertions;
    $supportReplyAssertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function reply_test_parts(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn($part) => $part !== ''));
}

function reply_test_value(string $path): mixed
{
    global $supportReplyStore;
    $value = $supportReplyStore;
    foreach (reply_test_parts($path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
}

function reply_test_bump(string $path): void
{
    global $supportReplyVersions;
    $parts = reply_test_parts($path);
    for ($i = count($parts); $i >= 1; $i--) {
        $parent = implode('/', array_slice($parts, 0, $i));
        $supportReplyVersions[$parent] = (int)($supportReplyVersions[$parent] ?? 0) + 1;
    }
}

function reply_test_set(string $path, mixed $value): void
{
    global $supportReplyStore;
    $parts = reply_test_parts($path);
    if ($parts === []) {
        $supportReplyStore = is_array($value) ? $value : [];
        return;
    }
    $cursor =& $supportReplyStore;
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
    reply_test_bump($path);
}

function now_ts(): int
{
    global $supportReplyNow;
    return $supportReplyNow;
}

function fb_get(string $path, array $query = []): mixed
{
    return reply_test_value($path);
}

function fb_put(string $path, mixed $data): bool
{
    reply_test_set($path, $data);
    return true;
}

function fb_patch(string $path, array $data): bool
{
    if (trim($path, '/') === '') {
        $ordered = $data;
        uksort($ordered, static fn($left, $right) => substr_count((string)$left, '/') <=> substr_count((string)$right, '/'));
        foreach ($ordered as $childPath => $value) {
            reply_test_set((string)$childPath, $value);
        }
        return true;
    }
    $current = reply_test_value($path);
    reply_test_set($path, array_merge(is_array($current) ? $current : [], $data));
    return true;
}

function fb_delete(string $path): bool
{
    reply_test_set($path, null);
    return true;
}

function fb_get_with_etag(string $path): array
{
    global $supportReplyVersions;
    return [
        'ok' => true,
        'status' => 200,
        'etag' => '"v' . (int)($supportReplyVersions[$path] ?? 0) . '"',
        'value' => reply_test_value($path),
        'error' => '',
    ];
}

function fb_put_if_match(string $path, mixed $data, string $etag): array
{
    global $supportReplyVersions, $supportReplyRacePath, $supportReplyRaceTicket, $supportReplyRaceTriggered;
    if ($path === $supportReplyRacePath && !$supportReplyRaceTriggered) {
        $supportReplyRaceTriggered = true;
        $messageId = 'MSG_RACE_WINNER';
        $ticket = reply_test_value('SUPPORT_TICKETS/' . $supportReplyRaceTicket);
        reply_test_set('SUPPORT_MESSAGES/' . $supportReplyRaceTicket . '/' . $messageId, [
            'message_id' => $messageId,
            'ticket_id' => $supportReplyRaceTicket,
            'sender_uid' => 'UID_RACE',
            'sender_type' => 'USER',
            'sender_name' => 'Race User',
            'sender_telegram_id' => '',
            'source' => 'WEB',
            'idempotency_key' => 'REPLY-RACE',
            'message' => 'Concurrent reply',
            'attachment_ids' => [],
            'created_at' => now_ts(),
            'read_by_user' => true,
            'read_by_admin' => false,
        ]);
        reply_test_set('SUPPORT_TICKETS/' . $supportReplyRaceTicket, array_merge(is_array($ticket) ? $ticket : [], [
            'status' => 'PENDING',
            'last_message_preview' => 'Concurrent reply',
            'updated_at' => now_ts(),
        ]));
        reply_test_set($path, [
            'ticket_id' => $supportReplyRaceTicket,
            'message_id' => $messageId,
            'uid' => 'UID_RACE',
            'sender_type' => 'USER',
            'source' => 'WEB',
            'payload_hash' => (string)($data['payload_hash'] ?? ''),
            'status' => 'COMPLETED',
            'side_effect_status' => 'COMPLETED',
            'side_effect_attempts' => 1,
            'created_at' => now_ts(),
        ]);
        return ['ok' => false, 'status' => 412];
    }

    $expected = '"v' . (int)($supportReplyVersions[$path] ?? 0) . '"';
    if (!hash_equals($expected, $etag)) {
        return ['ok' => false, 'status' => 412];
    }
    reply_test_set($path, $data);
    return ['ok' => true, 'status' => 200];
}

define('SUPPORT_TELEGRAM_ADMIN_IDS', ['900']);

require_once dirname(__DIR__) . '/api/lib/support.php';

function reply_test_ticket(string $ticketId, string $uid, string $status = 'OPEN'): void
{
    fb_put('SUPPORT_TICKETS/' . $ticketId, [
        'ticket_id' => $ticketId,
        'uid' => $uid,
        'user_name' => 'Support Test User',
        'user_phone' => '+60123456789',
        'user_email' => 'support@example.test',
        'category_code' => 'OTHER',
        'category_name' => 'Other',
        'subject' => 'Support test',
        'status' => $status,
        'attachment_count' => 0,
        'created_at' => now_ts() - 100,
        'updated_at' => now_ts() - 100,
        'last_message_at' => now_ts() - 100,
    ]);
    fb_put('SUPPORT_USER_INDEX/' . $uid . '/' . $ticketId, [
        'ticket_id' => $ticketId,
        'status' => $status,
        'updated_at' => now_ts() - 100,
    ]);
}

function reply_test_message_count(string $ticketId): int
{
    $rows = fb_get('SUPPORT_MESSAGES/' . $ticketId);
    return is_array($rows) ? count($rows) : 0;
}

fb_put('SUPPORT_CONFIG', [
    'contact_us_enabled' => true,
    'ticket_enabled' => true,
    'attachments_enabled' => false,
    'ticket_rate_limit_seconds' => 0,
]);

$userAuth = ['uid' => 'UID_USER', 'user' => ['uid' => 'UID_USER', 'name' => 'Support Test User']];
reply_test_ticket('SP_USER', 'UID_USER');
$userMeta = ['idempotency_key' => 'REPLY-USER-1', 'source' => 'WEB', 'sender_name' => 'Support Test User'];
$first = support_reply($userAuth, 'SP_USER', 'Please help me.', [], 'USER', $userMeta);
$second = support_reply($userAuth, 'SP_USER', 'Please help me.', [], 'USER', $userMeta);
reply_test_expect(!empty($first['ok']) && empty($first['duplicate']), 'normal user reply must be committed');
reply_test_expect(!empty($second['ok']) && !empty($second['duplicate']), 'same user key must replay');
reply_test_expect((string)$first['message_id'] === (string)$second['message_id'], 'user retry must return the canonical message id');
reply_test_expect(reply_test_message_count('SP_USER') === 1, 'duplicate user reply must create one message');
$userOperationPath = support_reply_operation_path('UID_USER', 'SP_USER', 'USER', $userMeta);
$userOperation = fb_get($userOperationPath);
reply_test_expect((int)($userOperation['side_effect_attempts'] ?? 0) === 1, 'user side effects must be attempted once');
reply_test_expect(count((array)fb_get('SUPPORT_ADMIN_NOTIFICATIONS')) === 1, 'admin notification side effect must not duplicate');

$conflict = support_reply($userAuth, 'SP_USER', 'Different content.', [], 'USER', $userMeta);
reply_test_expect(($conflict['code'] ?? '') === 'SUPPORT_IDEMPOTENCY_CONFLICT', 'same key with another payload must be rejected');

$adminAuth = ['uid' => 'UID_ADMIN', 'user' => ['uid' => 'UID_ADMIN', 'role' => 'ADMIN']];
reply_test_ticket('SP_ADMIN', 'UID_ADMIN_TARGET');
$adminMeta = ['idempotency_key' => 'REPLY-ADMIN-1', 'source' => 'ADMIN_PANEL', 'sender_name' => 'Admin'];
$adminFirst = support_reply($adminAuth, 'SP_ADMIN', 'Admin response.', [], 'ADMIN', $adminMeta);
$adminSecond = support_reply($adminAuth, 'SP_ADMIN', 'Admin response.', [], 'ADMIN', $adminMeta);
reply_test_expect(!empty($adminFirst['ok']) && !empty($adminSecond['duplicate']), 'admin duplicate must replay');
reply_test_expect(reply_test_message_count('SP_ADMIN') === 1, 'admin duplicate must create one message');
reply_test_expect((string)(fb_get('SUPPORT_TICKETS/SP_ADMIN')['status'] ?? '') === 'REPLIED', 'admin reply must preserve REPLIED transition');
reply_test_expect(count((array)fb_get('USER_NOTIFICATIONS/UID_ADMIN_TARGET')) === 1, 'user notification must not duplicate');

reply_test_ticket('SP_ATTACHMENT_OWNER', 'UID_USER');
$forgedAttachment = support_reply($userAuth, 'SP_ATTACHMENT_OWNER', 'Message without a new upload.', [], 'USER', [
    'idempotency_key' => 'REPLY-FORGED-ATTACHMENT',
    'source' => 'WEB',
    'attachment_ids' => ['ATT_OTHER_USER'],
]);
$forgedRow = fb_get('SUPPORT_MESSAGES/SP_ATTACHMENT_OWNER/' . (string)($forgedAttachment['message_id'] ?? ''));
reply_test_expect(!empty($forgedAttachment['ok']), 'normal text reply must remain valid');
reply_test_expect((array)($forgedRow['attachment_ids'] ?? []) === [], 'client metadata must not attach an existing or foreign file');

reply_test_ticket('SP_RACE', 'UID_RACE');
$raceMeta = ['idempotency_key' => 'REPLY-RACE', 'source' => 'WEB', 'sender_name' => 'Race User'];
$supportReplyRacePath = support_reply_operation_path('UID_RACE', 'SP_RACE', 'USER', $raceMeta);
$supportReplyRaceTicket = 'SP_RACE';
$race = support_reply(['uid' => 'UID_RACE', 'user' => ['uid' => 'UID_RACE']], 'SP_RACE', 'Concurrent reply', [], 'USER', $raceMeta);
reply_test_expect(!empty($race['ok']) && !empty($race['duplicate']), 'CAS loser must replay the concurrent winner');
reply_test_expect((string)($race['message_id'] ?? '') === 'MSG_RACE_WINNER', 'CAS loser must return winner message id');
reply_test_expect(reply_test_message_count('SP_RACE') === 1, 'concurrent claims must leave one canonical message');

reply_test_ticket('SP_LEASE', 'UID_LEASE');
$leaseMeta = ['idempotency_key' => 'REPLY-LEASE', 'source' => 'WEB', 'sender_name' => 'Lease User'];
$leaseMeta['payload_hash'] = support_reply_payload_hash('SP_LEASE', 'UID_LEASE', 'USER', 'Retry reply', [], $leaseMeta);
$leasePath = support_reply_operation_path('UID_LEASE', 'SP_LEASE', 'USER', $leaseMeta);
$leaseClaim = support_reply_claim_operation($leasePath, 'SP_LEASE', 'UID_LEASE', 'USER', $leaseMeta);
$leaseMessageId = (string)($leaseClaim['message_id'] ?? '');
$supportReplyNow += support_reply_lease_seconds() + 1;
$leaseResult = support_reply(['uid' => 'UID_LEASE', 'user' => ['uid' => 'UID_LEASE']], 'SP_LEASE', 'Retry reply', [], 'USER', $leaseMeta);
reply_test_expect(!empty($leaseResult['ok']), 'expired claim must recover');
reply_test_expect((string)($leaseResult['message_id'] ?? '') === $leaseMessageId, 'claim recovery must reuse message id');
reply_test_expect(reply_test_message_count('SP_LEASE') === 1, 'claim recovery must not duplicate messages');

reply_test_ticket('SP_PENDING_EFFECT', 'UID_PENDING', 'PENDING');
$pendingMeta = ['idempotency_key' => 'REPLY-PENDING-EFFECT', 'source' => 'WEB'];
$pendingHash = support_reply_payload_hash('SP_PENDING_EFFECT', 'UID_PENDING', 'USER', 'Committed reply', [], $pendingMeta);
$pendingPath = support_reply_operation_path('UID_PENDING', 'SP_PENDING_EFFECT', 'USER', $pendingMeta);
fb_put('SUPPORT_MESSAGES/SP_PENDING_EFFECT/MSG_PENDING_EFFECT', [
    'message_id' => 'MSG_PENDING_EFFECT',
    'ticket_id' => 'SP_PENDING_EFFECT',
    'sender_uid' => 'UID_PENDING',
    'sender_type' => 'USER',
    'sender_name' => 'Pending User',
    'sender_telegram_id' => '',
    'source' => 'WEB',
    'idempotency_key' => 'REPLY-PENDING-EFFECT',
    'message' => 'Committed reply',
    'attachment_ids' => [],
    'created_at' => now_ts(),
    'read_by_user' => true,
    'read_by_admin' => false,
]);
fb_put($pendingPath, [
    'ticket_id' => 'SP_PENDING_EFFECT',
    'message_id' => 'MSG_PENDING_EFFECT',
    'uid' => 'UID_PENDING',
    'sender_type' => 'USER',
    'source' => 'WEB',
    'payload_hash' => $pendingHash,
    'status' => 'COMMITTED',
    'side_effect_status' => 'PENDING',
    'side_effect_attempts' => 0,
]);
$notificationsBeforeResume = count((array)fb_get('SUPPORT_ADMIN_NOTIFICATIONS'));
$pendingReplay = support_reply(
    ['uid' => 'UID_PENDING', 'user' => ['uid' => 'UID_PENDING']],
    'SP_PENDING_EFFECT',
    'Committed reply',
    [],
    'USER',
    $pendingMeta
);
$pendingOperation = fb_get($pendingPath);
reply_test_expect(!empty($pendingReplay['duplicate']), 'committed reply retry must replay');
reply_test_expect((int)($pendingOperation['side_effect_attempts'] ?? 0) === 1, 'pending side effect must resume once');
reply_test_expect(count((array)fb_get('SUPPORT_ADMIN_NOTIFICATIONS')) === $notificationsBeforeResume + 1, 'resumed side effect must create one notification');
$pendingAgain = support_reply(
    ['uid' => 'UID_PENDING', 'user' => ['uid' => 'UID_PENDING']],
    'SP_PENDING_EFFECT',
    'Committed reply',
    [],
    'USER',
    $pendingMeta
);
reply_test_expect(!empty($pendingAgain['duplicate']) && count((array)fb_get('SUPPORT_ADMIN_NOTIFICATIONS')) === $notificationsBeforeResume + 1, 'completed side effect must not rerun');

reply_test_ticket('SP_CLOSED', 'UID_USER', 'CLOSED');
reply_test_ticket('SP_RESOLVED', 'UID_USER', 'RESOLVED');
$closed = support_reply($userAuth, 'SP_CLOSED', 'Blocked.', [], 'USER', ['idempotency_key' => 'CLOSED-1', 'source' => 'WEB']);
$resolved = support_reply($userAuth, 'SP_RESOLVED', 'Blocked.', [], 'USER', ['idempotency_key' => 'RESOLVED-1', 'source' => 'WEB']);
reply_test_expect(($closed['code'] ?? '') === 'SUPPORT_TICKET_CLOSED', 'closed ticket replies must remain blocked');
reply_test_expect(($resolved['code'] ?? '') === 'SUPPORT_TICKET_RESOLVED', 'resolved ticket replies must remain blocked');

reply_test_ticket('SP_TELEGRAM', 'UID_TELEGRAM_TARGET');
support_telegram_set_reply_context('SP_TELEGRAM', '900', '900');
$telegramMessage = [
    'chat' => ['id' => '900'],
    'from' => ['id' => '900', 'first_name' => 'Telegram Admin'],
    'message_id' => 77,
    'text' => 'Telegram reply.',
];
$telegramFirst = support_telegram_save_reply_from_message($telegramMessage, 12345);
$telegramSecond = support_telegram_save_reply_from_message($telegramMessage, 12345);
$telegramKey = support_telegram_reply_idempotency_key($telegramMessage, 12345);
reply_test_expect(!empty($telegramFirst['ok']) && !empty($telegramSecond['duplicate']), 'repeated Telegram update must replay');
reply_test_expect(reply_test_message_count('SP_TELEGRAM') === 1, 'repeated Telegram update must create one message');
reply_test_expect(is_array(fb_get('SUPPORT_TELEGRAM_REPLY_IDEMPOTENCY/' . $telegramKey)), 'Telegram dedupe path must remain backward compatible');

reply_test_ticket('SP_AUTO', 'UID_AUTO');
$autoFirst = support_save_auto_message('SP_AUTO', 'AUTO-REPLY-1', 'Automatic support message.', true);
$autoSecond = support_save_auto_message('SP_AUTO', 'AUTO-REPLY-1', 'Automatic support message.', true);
reply_test_expect(!empty($autoFirst['ok']) && !empty($autoSecond['duplicate']), 'automatic support message must be idempotent');
reply_test_expect(reply_test_message_count('SP_AUTO') === 1, 'automatic support message must not duplicate');

$supportSource = (string)file_get_contents(dirname(__DIR__) . '/api/lib/support.php');
$userEndpoint = (string)file_get_contents(dirname(__DIR__) . '/api/support/reply.php');
$adminEndpoint = (string)file_get_contents(dirname(__DIR__) . '/api/admin/support/reply.php');
$webhook = (string)file_get_contents(dirname(__DIR__) . '/api/telegram/support_webhook.php');
reply_test_expect(
    str_contains($supportSource, "'status' => 'CLAIMED'")
    && str_contains($supportSource, "'status' => 'COMMITTED'")
    && str_contains($supportSource, "'side_effect_status' => 'PENDING'")
    && str_contains($supportSource, 'support_reply_claim_side_effects($operationPath)'),
    'claim, commit and side-effect gates must remain in the canonical path'
);
reply_test_expect(
    str_contains($userEndpoint, "'SUPPORT_REPLY_DUPLICATE'")
    && str_contains($adminEndpoint, "'ADMIN_SUPPORT_REPLY_DUPLICATE'"),
    'reply API envelopes must preserve sent and duplicate result codes'
);
reply_test_expect(
    str_contains($webhook, 'if (empty($result[\'duplicate\']))')
    && str_contains($webhook, "'SUPPORT_REPLY_SAVED'"),
    'Telegram confirmation must remain suppressed for duplicate updates'
);

echo "Support reply idempotency tests passed ({$supportReplyAssertions} assertions).\n";
