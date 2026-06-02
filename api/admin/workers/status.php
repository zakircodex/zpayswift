<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('GET');
auth_require_admin_session(true);

function worker_bool($v): bool
{
    if (is_bool($v)) return $v;
    if (is_int($v) || is_float($v)) return ((int)$v) === 1;

    $s = strtolower(trim((string)$v));
    return in_array($s, ['1', 'true', 'yes', 'on', 'online'], true);
}

function worker_ms($v): int
{
    $n = (int)$v;
    if ($n <= 0) return 0;

    // seconds হলে ms-এ convert
    return $n < 100000000000 ? ($n * 1000) : $n;
}

function worker_last_heartbeat_ms(array $row): int
{
    foreach ([
        'last_heartbeat_at',
        'heartbeat_at',
        'last_seen_at',
        'updated_at',
        'claimed_at',
    ] as $key) {
        if (!empty($row[$key])) {
            $ms = worker_ms($row[$key]);
            if ($ms > 0) return $ms;
        }
    }

    return 0;
}

function worker_sim_summary(array $row): string
{
    $slots = $row['sim_slots'] ?? $row['slots'] ?? [];
    if (!is_array($slots) || !$slots) {
        return '';
    }

    $parts = [];
    foreach ($slots as $slot) {
        if (!is_array($slot)) continue;

        $slotNo = (string)($slot['slot'] ?? $slot['slot_index'] ?? '');
        $operator = strtoupper(trim((string)($slot['operator'] ?? $slot['carrier'] ?? '-')));
        $active = worker_bool($slot['active'] ?? true) ? 'ON' : 'OFF';

        $label = $slotNo !== '' ? ('SIM' . $slotNo) : 'SIM';
        $parts[] = $label . ': ' . $operator . ' (' . $active . ')';
    }

    return implode(' • ', $parts);
}

$rows = fb_get('WORKER_DEVICES');

$items = [];
$summary = [
    'total'   => 0,
    'online'  => 0,
    'busy'    => 0,
    'idle'    => 0,
    'offline' => 0,
];

$nowMs = (int) round(microtime(true) * 1000);
$onlineWindowMs = 120 * 1000; // 120 sec

if (is_array($rows)) {
    foreach ($rows as $deviceId => $row) {
        if (!is_array($row)) continue;

        $summary['total']++;

        $lastHeartbeatMs = worker_last_heartbeat_ms($row);
        $recentHeartbeat = $lastHeartbeatMs > 0 && (($nowMs - $lastHeartbeatMs) <= $onlineWindowMs);
        $flagOnline = worker_bool($row['online'] ?? false);

        $isOnline = $flagOnline || $recentHeartbeat;

        $status = strtoupper(trim((string)($row['current_status'] ?? '')));
        if ($status === '') {
            $status = 'IDLE';
        }
        if (!$isOnline) {
            $status = 'OFFLINE';
        }

        if ($isOnline) {
            $summary['online']++;
            if ($status === 'BUSY' || $status === 'PROCESSING') {
                $summary['busy']++;
            } else {
                $summary['idle']++;
            }
        } else {
            $summary['offline']++;
        }

        $items[] = [
            'device_id'              => (string)$deviceId,
            'device_name'            => (string)($row['device_name'] ?? $row['name'] ?? $deviceId),
            'app_version'            => (string)($row['app_version'] ?? '-'),
            'worker_enabled'         => worker_bool($row['worker_enabled'] ?? false),
            'accessibility_enabled'  => worker_bool($row['accessibility_enabled'] ?? false),
            'is_online'              => $isOnline,
            'current_status'         => $status,
            'last_heartbeat_at'      => $lastHeartbeatMs,
            'sim_summary'            => worker_sim_summary($row),
            'raw'                    => $row,
        ];
    }
}

usort($items, static function (array $a, array $b): int {
    if (($a['is_online'] ?? false) !== ($b['is_online'] ?? false)) {
        return ($a['is_online'] ?? false) ? -1 : 1;
    }

    $aBusy = strtoupper((string)($a['current_status'] ?? '')) === 'BUSY';
    $bBusy = strtoupper((string)($b['current_status'] ?? '')) === 'BUSY';
    if ($aBusy !== $bBusy) {
        return $aBusy ? -1 : 1;
    }

    return ((int)($b['last_heartbeat_at'] ?? 0)) <=> ((int)($a['last_heartbeat_at'] ?? 0));
});

api_response(true, 'SUCCESS', 'Worker status loaded', [
    'summary' => $summary,
    'items'   => $items,
]);
