<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$htaccess = file_get_contents($root . '/.htaccess');
$apiIni = file_get_contents($root . '/api/.user.ini');
$zskyHtaccess = file_get_contents($root . '/znews/.htaccess');

function security_headers_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

security_headers_expect(is_string($htaccess), 'Root .htaccess could not be read.');
security_headers_expect(is_string($apiIni), 'API PHP configuration could not be read.');
security_headers_expect(is_string($zskyHtaccess), 'Z Sky header configuration could not be read.');

security_headers_expect(
    str_contains($htaccess, 'E=ZPAY_MAIN_HOST:1')
        && str_contains($htaccess, 'zpayswift\\.com'),
    'Main-host security-header scope is missing.'
);
security_headers_expect(
    str_contains($htaccess, 'Strict-Transport-Security "max-age=31536000" env=ZPAY_MAIN_HOST'),
    'Domain-only HSTS is missing.'
);
security_headers_expect(
    !str_contains(strtolower($htaccess), 'includesubdomains')
        && !str_contains(strtolower($htaccess), 'preload'),
    'HSTS must not include unverified subdomains or preload.'
);
security_headers_expect(
    str_contains($htaccess, 'X-Content-Type-Options "nosniff" env=ZPAY_MAIN_HOST'),
    'nosniff protection is missing.'
);
security_headers_expect(
    str_contains($htaccess, 'X-Frame-Options "SAMEORIGIN" env=ZPAY_MAIN_HOST'),
    'Legacy same-origin frame protection is missing.'
);

$csp = "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'";
security_headers_expect(
    str_contains($htaccess, 'Content-Security-Policy "' . $csp . '" env=ZPAY_MAIN_HOST'),
    'Compatible enforced CSP is missing.'
);
security_headers_expect(
    !str_contains($csp, '*') && !str_contains($csp, "'unsafe-inline'"),
    'The enforced CSP must not use wildcard or unsafe-inline sources.'
);
security_headers_expect(
    str_contains($htaccess, 'Referrer-Policy "strict-origin-when-cross-origin" env=ZPAY_MAIN_HOST')
        && str_contains($htaccess, 'Referrer-Policy "no-referrer"'),
    'General or sensitive-endpoint referrer protection is missing.'
);
security_headers_expect(
    str_contains($htaccess, 'geolocation=(self)')
        && str_contains($htaccess, 'camera=(self)')
        && str_contains($htaccess, 'microphone=()'),
    'Permissions Policy must preserve same-origin KYC features and block the microphone.'
);
security_headers_expect(
    str_contains($htaccess, 'Header always unset X-Powered-By')
        && str_contains($htaccess, 'Header always unset X-Turbo-Charged-By'),
    'Runtime implementation headers are not removed.'
);
security_headers_expect(
    str_contains($apiIni, 'display_errors = Off')
        && str_contains($apiIni, 'display_startup_errors = Off')
        && str_contains($apiIni, 'html_errors = Off')
        && str_contains($apiIni, 'log_errors = On')
        && str_contains($apiIni, 'expose_php = Off'),
    'Production PHP disclosure settings are incomplete.'
);
security_headers_expect(
    str_contains($zskyHtaccess, 'Content-Security-Policy')
        && str_contains($zskyHtaccess, 'camera=(), microphone=(), geolocation=()'),
    'Z Sky must retain its isolated header policy.'
);
security_headers_expect(
    !str_contains($htaccess, 'Access-Control-Allow-Origin'),
    'Root headers must not introduce global CORS.'
);
security_headers_expect(
    substr_count($htaccess, 'Header always set Cache-Control') === 1,
    'Static assets must not inherit a new global no-store policy.'
);

echo "security headers configuration test passed\n";
