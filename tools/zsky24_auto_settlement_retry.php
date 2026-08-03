<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/api/znews/lib/settlements_auto.php';

$limit = 50;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = max(1, min(200, (int)substr($argument, 8)));
    }
}

$queue = fb_get('ZNEWS_AUTO_SETTLEMENT_RETRIES');
if (!is_array($queue)) {
    echo "No automatic settlement retries are pending.\n";
    exit(0);
}

$now = now_ts();
$processed = 0;
$credited = 0;
$failed = 0;
foreach ($queue as $impressionId => $item) {
    if ($processed >= $limit || !is_array($item)) {
        continue;
    }
    if (strtoupper(trim((string)($item['status'] ?? 'PENDING'))) !== 'PENDING'
        || (int)($item['next_attempt_at'] ?? 0) > $now) {
        continue;
    }

    $result = znews_auto_settle_impression_with_retry((string)$impressionId);
    $processed++;
    if (!empty($result['ok'])) {
        if (empty($result['skipped'])) {
            $credited++;
        }
    } else {
        $failed++;
    }
}

echo sprintf(
    "Automatic settlement retry completed: processed=%d credited=%d failed=%d\n",
    $processed,
    $credited,
    $failed
);
exit($failed > 0 ? 2 : 0);
