<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/page-bootstrap.php';
$page = user_page_config([
    'key' => 'bkash',
    'title' => 'bKash Send Money',
    'section_id' => 'mfsSection',
    'body_class' => 'user-mfs-page user-bkash-page',
    'page_css' => 'mfs-page.css',
    'page_js' => 'mfs-page.js',
    'active_nav' => '',
    'show_header' => false,
    'show_global_loader' => false,
]);
user_page_begin($page);
$mfsProvider = 'BKASH';
require __DIR__ . '/includes/mfs-flow.php';
user_page_end($page);
