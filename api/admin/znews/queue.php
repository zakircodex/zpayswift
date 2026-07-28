<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 2) . '/znews/lib/moderation.php';

api_require_method('GET');
auth_require_admin_session(true);

$limit = znews_limit($_GET['limit'] ?? 20, 20, 50);
$cursor = trim((string)($_GET['cursor'] ?? ''));

api_response(true, 'ZNEWS_MODERATION_QUEUE_OK', 'Moderation queue loaded.', znews_admin_queue($limit, $cursor));
