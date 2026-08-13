<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

fwrite(STDOUT, "Retired: creator payouts use period review and direct Z-Pay payout; no balances were changed.\n");
exit(0);
