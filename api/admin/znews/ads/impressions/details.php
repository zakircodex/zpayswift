<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 4) . '/znews/lib/ad_impressions.php';

api_require_method('GET');
auth_require_admin_session(true);

$impressionId = znews_firebase_key(
    $_GET['impression_id'] ?? '',
    'impression_id'
);

api_response(
    true,
    'ZNEWS_AD_IMPRESSION_DETAILS_OK',
    'Ad impression details loaded.',
    znews_ad_admin_details($impressionId)
);
