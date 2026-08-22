<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function admin_users_list_account_status(array $user): string
{
    $accountStatus = strtoupper(trim((string)($user['account_status'] ?? '')));
    $userStatus = strtoupper(trim((string)($user['status'] ?? '')));

    if ($accountStatus !== '' && $accountStatus !== 'ACTIVE') {
        return $accountStatus;
    }
    if ($userStatus !== '' && $userStatus !== 'ACTIVE') {
        return $userStatus;
    }

    return $accountStatus !== '' ? $accountStatus : ($userStatus !== '' ? $userStatus : 'ACTIVE');
}

function admin_users_list_matches(
    array $user,
    string $uid,
    string $roleFilter = '',
    string $statusFilter = 'ACTIVE',
    string $search = ''
): bool {
    $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
    $roleFilter = strtoupper(trim($roleFilter));
    $statusFilter = strtoupper(trim($statusFilter));
    $search = strtolower(trim($search));

    if ($roleFilter !== '' && $role !== $roleFilter) {
        return false;
    }

    $status = admin_users_list_account_status($user);
    if ($statusFilter !== 'ALL') {
        $statusMatches = $statusFilter === 'BLOCKED_INACTIVE'
            ? in_array($status, ['BLOCKED', 'INACTIVE'], true)
            : $status === $statusFilter;
        if (!$statusMatches) {
            return false;
        }
    }

    if ($search === '') {
        return true;
    }

    $haystack = strtolower(implode(' ', [
        $uid,
        (string)($user['name'] ?? ''),
        (string)($user['phone'] ?? ''),
        (string)($user['email'] ?? ''),
        $role,
        (string)($user['status'] ?? ''),
        (string)($user['account_status'] ?? ''),
    ]));

    return str_contains($haystack, $search);
}
