<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 4) . '/znews/lib/transfers.php';

api_require_method('GET');
auth_require_admin_session(true);

api_response(
    true,
    'ZNEWS_TRANSFER_RATES_OK',
    'Transfer conversion rates loaded.',
    znews_transfer_rates_list()
);
