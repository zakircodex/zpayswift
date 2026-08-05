<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('GET');
api_require_app_key();
znews_require_creator(true);

api_response(
    true,
    'ZNEWS_CREATOR_BALANCE_LEDGER_DISABLED',
    'Z Sky 24 no longer exposes a creator balance ledger.',
    [
        'revenue_mode' => 'PERIOD_REVIEW_DIRECT_ZPAY_PAYOUT',
        'creator_balance_enabled' => false,
        'items' => [],
        'next_cursor' => '',
        'has_more' => false,
    ]
);
