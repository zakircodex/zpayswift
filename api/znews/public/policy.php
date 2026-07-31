<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/transfers.php';

api_require_method('GET');

api_response(
    true,
    'ZNEWS_CREATOR_POLICY_OK',
    'Z Sky 24 creator policy loaded.',
    [
        'creator_ad_payout_policy' => znews_settlement_creator_ad_payout_policy(),
        'minimum_bdt_micros' => znews_transfer_threshold_bdt_micros(),
        'minimum_bdt' => znews_transfer_micros_to_decimal(znews_transfer_threshold_bdt_micros()),
        'transfer_requires_admin_approval' => true,
        'test_ads_payable' => false,
        'client_submitted_value_allowed' => false,
    ]
);
