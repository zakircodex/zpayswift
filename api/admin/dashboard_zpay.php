<?php
/**
 * Z-Pay Swift Admin Dashboard wrapper.
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/dashboard.php';
$html = (string)ob_get_clean();

$replacements = [
    '<title>ZawTopup Admin Dashboard</title>' => '<title>Z-Pay Swift Admin Dashboard</title>',
    '<h1>ZawTopup Admin</h1>' => '<h1>Z-Pay Swift Admin</h1>',
    '<h2>Admin Dashboard</h2>' => '<h2>Z-Pay Swift Admin Dashboard</h2>',
    '<p>Secure session-based operations panel.</p>' => '<p>Secure operations panel for topup, bundle and bKash/Nagad.</p>',
];

$html = strtr($html, $replacements);

if (strpos($html, 'assets/admin-ux.css') === false) {
    $html = str_replace('</head>', '<link rel="stylesheet" href="assets/mfs-panel.css?v=1"><link rel="stylesheet" href="assets/admin-ux.css?v=1"></head>', $html);
}

if (strpos($html, 'zpayAdminMfsNav') === false) {
    $html = str_replace('<button class="nav-btn" data-section="bundleSection">Bundles <span>›</span></button>', '<button class="nav-btn" data-section="bundleSection">Bundles <span>›</span></button><a id="zpayAdminMfsNav" class="nav-btn zpay-admin-mfs-link" href="mfs.php">bKash / Nagad <span>›</span></a>', $html);
}

if (strpos($html, 'zpayAdminMfsTopLink') === false) {
    $html = str_replace('<button class="btn brand" id="directTopupBtn">Direct Topup</button>', '<a id="zpayAdminMfsTopLink" class="btn brand zpay-admin-mfs-toplink" href="mfs.php">bKash / Nagad</a><button class="btn brand" id="directTopupBtn">Direct Topup</button>', $html);
}

if (strpos($html, 'admin-dashboard-ux.js') === false) {
    $html = str_replace('</body>', '<script src="assets/admin-dashboard-ux.js?v=1"></script></body>', $html);
}

echo $html;
