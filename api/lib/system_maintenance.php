<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function system_maintenance_message(): string
{
    return 'Z-Pay Swift is temporarily under maintenance. Please try again later.';
}

function system_maintenance_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtoupper(trim((string)$value)), ['1', 'TRUE', 'YES', 'ON'], true);
}

function system_maintenance_state(): array
{
    return [
        'enabled' => system_maintenance_bool(fb_get('APP_CONFIG/maintenance_mode')),
    ];
}

function system_maintenance_active(): bool
{
    return !empty(system_maintenance_state()['enabled']);
}

function system_maintenance_applies_to_role(string $role): bool
{
    return in_array(strtoupper(trim($role)), ['USER', 'RETAILER'], true);
}

function system_user_service_is_blocked(?array $user = null): bool
{
    if (is_array($user)) {
        $role = (string)($user['role'] ?? '');
        if (!system_maintenance_applies_to_role($role)) {
            return false;
        }
    }

    return system_maintenance_active();
}

function system_require_user_service_available(?array $user = null): void
{
    if (!system_user_service_is_blocked($user)) {
        return;
    }

    api_response(false, 'MAINTENANCE', system_maintenance_message(), [], 503);
}
