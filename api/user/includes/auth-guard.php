<?php
declare(strict_types=1);

function user_page_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $https = true;
    }

    session_name('zawtopup_user');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function user_page_require_auth(): void
{
    user_page_start_session();

    if (trim((string)($_SESSION['user_session_token'] ?? '')) !== '') {
        return;
    }

    header('Location: /user/', true, 302);
    exit;
}

