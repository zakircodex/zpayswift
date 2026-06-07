<?php
/**
 * Z-Pay Swift Admin Dashboard wrapper.
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/dashboard.php';
$html = (string) ob_get_clean();

$html = strtr($html, [
    '<h2>Admin Dashboard</h2>' => '<h2>Z-Pay Swift Admin Dashboard</h2>',
    '<p>Secure session-based operations panel.</p>' => '<p>Secure operations panel for topup, bundle and bKash/Nagad.</p>',
]);

if (strpos($html, 'assets/admin-ux.css') === false) {
    $html = str_replace('</head>', '<link rel="stylesheet" href="/api/admin/assets/admin-ux.css?v=2"></head>', $html);
}

if (strpos($html, 'admin-dashboard-ux.js') === false) {
    $html = str_replace('</body>', '<script src="/api/admin/assets/admin-dashboard-ux.js?v=2"></script></body>', $html);
}

echo $html;
