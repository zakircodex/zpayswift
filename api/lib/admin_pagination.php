<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function admin_pagination_encode_cursor(string $key, bool $exclusive = false): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }

    $json = json_encode(['key' => $key, 'exclusive' => $exclusive], JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return '';
    }

    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

function admin_pagination_decode_cursor(string $cursor): array
{
    $cursor = trim($cursor);
    if ($cursor === '') {
        return ['key' => '', 'exclusive' => false];
    }

    if (!preg_match('/^[A-Za-z0-9_-]{1,512}$/', $cursor)) {
        return ['key' => '', 'exclusive' => false];
    }

    $padding = strlen($cursor) % 4;
    if ($padding > 0) {
        $cursor .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
    $payload = is_string($decoded) ? json_decode($decoded, true) : null;
    $key = is_array($payload) ? trim((string)($payload['key'] ?? '')) : '';

    if ($key === '' || !preg_match('/^[A-Za-z0-9_-]{1,180}$/', $key)) {
        return ['key' => '', 'exclusive' => false];
    }

    return [
        'key' => $key,
        'exclusive' => !empty($payload['exclusive']),
    ];
}

/**
 * Read a newest-first Admin page without loading an entire Firebase collection.
 * Sparse compound filters continue through small key-ordered chunks and expose
 * an opaque continuation cursor instead of calculating a full-tree total.
 */
function admin_firebase_cursor_page(
    string $path,
    int $limit = 10,
    string $cursor = '',
    ?callable $accept = null,
    ?callable $normalize = null,
    int $maxBatches = 6
): array {
    $path = trim($path, '/');
    $limit = max(1, min(10, $limit));
    $chunkLimit = $limit + 1;
    $maxBatches = max(1, min(20, $maxBatches));
    $cursorState = admin_pagination_decode_cursor($cursor);
    $queryKey = (string)$cursorState['key'];
    $exclusiveKey = !empty($cursorState['exclusive']) ? $queryKey : '';
    $items = [];
    $scanned = 0;
    $batches = 0;
    $lastScannedKey = '';
    $exhausted = false;
    $nextCursor = '';

    while ($batches < $maxBatches && !$exhausted && $nextCursor === '') {
        $query = [
            'orderBy' => json_encode('$key'),
            'limitToLast' => $chunkLimit,
        ];
        if ($queryKey !== '') {
            $query['endAt'] = json_encode($queryKey);
        }

        $rows = fb_get($path, $query);
        $rows = is_array($rows) ? $rows : [];
        $rawCount = count($rows);
        $batches++;

        if ($rows === []) {
            $exhausted = true;
            break;
        }

        ksort($rows, SORT_STRING);
        $keys = array_reverse(array_keys($rows));
        $processedThisBatch = 0;

        foreach ($keys as $key) {
            $key = (string)$key;
            if ($exclusiveKey !== '' && hash_equals($exclusiveKey, $key)) {
                $exclusiveKey = '';
                continue;
            }

            $row = $rows[$key] ?? null;
            $lastScannedKey = $key;
            $processedThisBatch++;
            $scanned++;

            if (!is_array($row)) {
                continue;
            }
            if ($accept !== null && !$accept($row, $key)) {
                continue;
            }

            $item = $normalize !== null ? $normalize($row, $key) : $row;
            if (!is_array($item)) {
                continue;
            }

            if (count($items) < $limit) {
                $items[] = $item;
                continue;
            }

            $nextCursor = admin_pagination_encode_cursor($key, false);
            break;
        }

        if ($nextCursor !== '') {
            break;
        }

        if ($rawCount < $chunkLimit) {
            $exhausted = true;
            break;
        }

        if ($processedThisBatch === 0 || $lastScannedKey === '') {
            $exhausted = true;
            break;
        }

        $queryKey = $lastScannedKey;
        $exclusiveKey = $lastScannedKey;
    }

    $scanLimited = !$exhausted && $nextCursor === '' && $lastScannedKey !== '';
    if ($scanLimited) {
        $nextCursor = admin_pagination_encode_cursor($lastScannedKey, true);
    }

    return [
        'items' => array_values($items),
        'pagination' => [
            'limit' => $limit,
            'count' => count($items),
            'has_more' => $nextCursor !== '',
            'cursor' => trim($cursor),
            'next_cursor' => $nextCursor,
            'scanned' => $scanned,
            'scan_limited' => $scanLimited,
        ],
    ];
}
