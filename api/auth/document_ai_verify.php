<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('POST');
api_require_app_key();

api_response(
    false,
    'DOCUMENT_AI_RETIRED',
    'Document AI verification is no longer available.',
    [],
    410
);
