<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 3) . '/znews/lib/views.php';

api_require_method('GET');
auth_require_admin_session(true);

$viewId = znews_firebase_key($_GET['view_id'] ?? '', 'view_id');
api_response(
    true,
    'ZNEWS_ADMIN_VIEW_DETAILS_OK',
    'View session details loaded.',
    znews_view_admin_details($viewId)
);
