<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/media.php';

api_require_method('GET');
$mediaId = znews_firebase_key($_GET['media_id'] ?? '', 'media_id');
$row = znews_media_public_record($mediaId);
znews_media_stream($row, true);
