<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/ad_impressions_common.php';
require_once __DIR__ . '/ad_impressions_signature.php';
require_once __DIR__ . '/ad_impressions_analytics.php';
require_once __DIR__ . '/ad_impressions_reconcile.php';
require_once __DIR__ . '/ad_impressions_ingest.php';
