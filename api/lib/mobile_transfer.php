<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function zpay_transfer_money($value): float
{
    if (is_int($value) || is_float($value)) {
        return round((float)$value, 2);
    }

    $clean = str_replace(',', '', trim((string)$value));
    return is_numeric($clean) ? round((float)$clean, 2) : 0.0;
}

function zpay_transfer_input_phone(array $body): string
{
    return trim((string)(
        $body['recipient_account']
        ?? $body['recipient_phone']
        ?? $body['account']
        ?? $body['phone']
        ?? ''
    ));
}

function zpay_transfer_find_user_by_account(string $account, string $preferredCountry = ''): array
{
    $account = trim($account);
    if ($account === '') {
        return [];
    }

    $countries = [];
    $preferredCountry = function_exists('auth_normalize_country_code')
        ? auth_normalize_country_code($preferredCountry)
        : strtoupper(trim($preferredCountry));
    if ($preferredCountry !== '') {
        $countries[] = $preferredCountry;
    }
    foreach (['MY', 'BD'] as $country) {
        if (!in_array($country, $countries, true)) {
            $countries[] = $country;
        }
    }

    foreach ($countries as $country) {
        $phone = normalize_phone_by_country($account, $country);
        if ($phone === '') {
            continue;
        }
        $uid = auth_find_uid_by_phone_country($phone, $country);
        if ($uid === '') {
            continue;
        }
        $user = fb_get('USERS/' . $uid);
        if (!is_array($user)) {
            continue;
        }
        $user['uid'] = $uid;
        return [
            'uid' => $uid,
            'phone' => normalize_phone_by_country((string)($user['phone'] ?? $phone), auth_phone_country_from_user($user)) ?: $phone,
            'phone_country' => auth_phone_country_from_user($user),
            'user' => $user,
        ];
    }

    return [];
}

function zpay_transfer_public_recipient(array $account, bool $canTransfer, string $reason = ''): array
{
    $user = is_array($account['user'] ?? null) ? $account['user'] : [];
    $role = strtoupper(trim((string)($user['role'] ?? '')));

    return [
        'exists' => !empty($account),
        'can_transfer' => $canTransfer,
        'reason' => $reason,
        'receiver_name_masked' => zpay_dash_mask_name((string)($user['name'] ?? '')),
        'receiver_phone_masked' => zpay_dash_mask_phone((string)($account['phone'] ?? $user['phone'] ?? '')),
        'receiver_role' => $role,
    ];
}

function zpay_transfer_guard_recipient(array $senderAuth, string $recipientAccount): array
{
    $senderUser = is_array($senderAuth['user'] ?? null) ? $senderAuth['user'] : [];
    $senderUid = (string)($senderUser['uid'] ?? '');
    $preferredCountry = auth_phone_country_from_user($senderUser);
    $recipient = zpay_transfer_find_user_by_account($recipientAccount, $preferredCountry);

    if (!$recipient) {
        api_response(false, 'RECIPIENT_NOT_FOUND', 'Recipient account not found.', [
            'recipient' => zpay_transfer_public_recipient([], false, 'NOT_FOUND'),
        ], 404);
    }

    $receiverUser = is_array($recipient['user'] ?? null) ? $recipient['user'] : [];
    $receiverUid = (string)($recipient['uid'] ?? '');
    if ($receiverUid === '' || $receiverUid === $senderUid) {
        api_response(false, 'SELF_TRANSFER_NOT_ALLOWED', 'You cannot transfer to your own account.', [], 422);
    }

    $status = function_exists('auth_status_value')
        ? auth_status_value($receiverUser['status'] ?? 'INACTIVE')
        : strtoupper(trim((string)($receiverUser['status'] ?? 'INACTIVE')));
    $accountStatus = function_exists('auth_status_value')
        ? auth_status_value($receiverUser['account_status'] ?? $status)
        : strtoupper(trim((string)($receiverUser['account_status'] ?? $status)));
    $role = function_exists('auth_status_value')
        ? auth_status_value($receiverUser['role'] ?? '')
        : strtoupper(trim((string)($receiverUser['role'] ?? '')));

    if ($status !== 'ACTIVE' || $accountStatus !== 'ACTIVE') {
        api_response(false, 'RECIPIENT_INACTIVE', 'Recipient account is not active.', [
            'recipient' => zpay_transfer_public_recipient($recipient, false, 'INACTIVE'),
        ], 422);
    }

    if (!zpay_dash_allowed_mobile_role($role)) {
        api_response(false, 'RECIPIENT_ROLE_NOT_ALLOWED', 'Recipient account type is not supported.', [
            'recipient' => zpay_transfer_public_recipient($recipient, false, 'ROLE_NOT_ALLOWED'),
        ], 422);
    }

    $receiverUser['uid'] = $receiverUid;
    $receiverUser['status'] = $status;
    $receiverUser['account_status'] = $accountStatus;
    $receiverUser['role'] = $role;
    $recipient['user'] = $receiverUser;

    return $recipient;
}

function zpay_transfer_idempotency_key(string $raw): string
{
    $key = trim($raw);
    $key = preg_replace('/[^A-Za-z0-9._:-]+/', '', $key) ?? '';
    return strlen($key) > 120 ? substr($key, 0, 120) : $key;
}

function zpay_transfer_idempotency_path(string $senderUid, string $key): string
{
    return 'TRANSFER_IDEMPOTENCY/' . $senderUid . '/' . hash('sha256', $senderUid . '|' . $key);
}

function zpay_transfer_existing_response(string $transferId): void
{
    $row = fb_get('TRANSFERS/' . $transferId);
    $row = is_array($row) ? $row : [];
    api_response(true, 'TRANSFER_ALREADY_PROCESSED', 'Transfer already processed for this idempotency key.', [
        'transfer' => zpay_transfer_public_row($row),
    ]);
}

function zpay_transfer_acquire_idempotency(string $senderUid, string $key, string $transferId): string
{
    if ($key === '' || strlen($key) < 8) {
        api_response(false, 'IDEMPOTENCY_KEY_REQUIRED', 'idempotency_key is required.', [], 422);
    }

    $path = zpay_transfer_idempotency_path($senderUid, $key);
    for ($i = 0; $i < 3; $i++) {
        $res = fb_get_with_etag($path);
        if (!($res['ok'] ?? false) || empty($res['etag'])) {
            api_response(false, 'IDEMPOTENCY_UNAVAILABLE', 'Transfer safety check is unavailable.', [], 500);
        }

        $value = $res['value'] ?? null;
        if (is_array($value)) {
            $existingTransferId = trim((string)($value['transfer_id'] ?? ''));
            $status = strtoupper(trim((string)($value['status'] ?? '')));
            if ($existingTransferId !== '' && $status === 'SUCCESS') {
                zpay_transfer_existing_response($existingTransferId);
            }
            api_response(false, 'TRANSFER_PROCESSING', 'This transfer request is already processing.', [
                'transfer_id' => $existingTransferId,
            ], 409);
        }

        $lock = [
            'sender_uid' => $senderUid,
            'idempotency_key_hash' => hash('sha256', $key),
            'transfer_id' => $transferId,
            'status' => 'PROCESSING',
            'created_at' => now_ts(),
            'updated_at' => now_ts(),
        ];
        $save = fb_put_if_match($path, $lock, (string)$res['etag']);
        if (($save['status'] ?? 0) === 412) {
            usleep(100000);
            continue;
        }
        if (!($save['ok'] ?? false)) {
            api_response(false, 'IDEMPOTENCY_LOCK_FAILED', 'Could not lock transfer request.', [], 500);
        }
        return $path;
    }

    api_response(false, 'TRANSFER_CONFLICT', 'Transfer request conflict. Please retry.', [], 409);
}

function zpay_transfer_public_row(array $row): array
{
    if (!$row) {
        return [];
    }

    $direction = (string)($row['direction'] ?? '');
    return [
        'transfer_id' => (string)($row['transfer_id'] ?? ''),
        'direction' => $direction,
        'amount' => (float)($row['amount'] ?? 0),
        'currency' => (string)($row['currency'] ?? ''),
        'fee' => (float)($row['fee'] ?? 0),
        'commission' => (float)($row['commission'] ?? 0),
        'status' => (string)($row['status'] ?? ''),
        'note' => (string)($row['note'] ?? ''),
        'sender_name' => zpay_dash_mask_name((string)($row['sender_name'] ?? '')),
        'sender_account' => zpay_dash_mask_phone((string)($row['sender_account'] ?? $row['sender_phone'] ?? '')),
        'receiver_name' => zpay_dash_mask_name((string)($row['receiver_name'] ?? '')),
        'receiver_account' => zpay_dash_mask_phone((string)($row['receiver_account'] ?? $row['receiver_phone'] ?? '')),
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
    ];
}

function zpay_transfer_user_can_view(array $row, string $uid): bool
{
    return $uid !== '' && (
        (string)($row['sender_uid'] ?? '') === $uid
        || (string)($row['receiver_uid'] ?? '') === $uid
    );
}

function zpay_transfer_user_history(string $uid, int $limit = 50): array
{
    $rows = fb_get('TRANSFER_HISTORY/' . $uid);
    $rows = is_array($rows) ? $rows : [];
    $items = [];
    foreach ($rows as $transferId => $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['transfer_id'] = (string)($row['transfer_id'] ?? $transferId);
        $items[] = zpay_transfer_public_row($row);
    }

    usort($items, static fn(array $a, array $b): int =>
        (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0)
    );

    return array_slice($items, 0, max(1, min(100, $limit)));
}
