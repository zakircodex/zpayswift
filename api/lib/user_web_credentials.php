<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function user_web_password_valid(string $password): bool
{
    return preg_match('/^\d{6}$/D', $password) === 1;
}

function user_web_transaction_pin_valid(string $pin): bool
{
    return preg_match('/^\d{4}$/D', $pin) === 1;
}
