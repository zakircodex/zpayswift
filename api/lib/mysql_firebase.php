<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/mysql.php';

const MYSQL_FB_OBJECT = 1;
const MYSQL_FB_ARRAY = 2;
const MYSQL_FB_SCALAR = 3;

function mysql_fb_normalize_path(string $path): string
{
    $path = trim(str_replace('\\', '/', $path), '/');
    if (str_contains($path, "\0")) {
        throw new InvalidArgumentException('Firebase path contains an invalid byte.');
    }
    if (strlen($path) > 1024) {
        throw new InvalidArgumentException('Firebase path is too long.');
    }

    return $path;
}

function mysql_fb_join_path(string $base, string $child): string
{
    $base = mysql_fb_normalize_path($base);
    $child = mysql_fb_normalize_path($child);

    if ($base === '') {
        return $child;
    }
    if ($child === '') {
        return $base;
    }

    return $base . '/' . $child;
}

function mysql_fb_parent_path(string $path): string
{
    $path = mysql_fb_normalize_path($path);
    if ($path === '' || !str_contains($path, '/')) {
        return '';
    }

    return substr($path, 0, (int)strrpos($path, '/'));
}

function mysql_fb_node_key(string $path): string
{
    $path = mysql_fb_normalize_path($path);
    if ($path === '') {
        return '';
    }

    $offset = strrpos($path, '/');
    return $offset === false ? $path : substr($path, $offset + 1);
}

function mysql_fb_depth(string $path): int
{
    $path = mysql_fb_normalize_path($path);
    return $path === '' ? 0 : substr_count($path, '/') + 1;
}

function mysql_fb_ancestors(string $path, bool $includeSelf = true): array
{
    $path = mysql_fb_normalize_path($path);
    $segments = $path === '' ? [] : explode('/', $path);
    $ancestors = [''];
    $current = '';

    foreach ($segments as $segment) {
        $current = mysql_fb_join_path($current, $segment);
        $ancestors[] = $current;
    }

    if (!$includeSelf && $ancestors !== []) {
        array_pop($ancestors);
    }

    return array_values(array_unique($ancestors));
}

function mysql_fb_descendant_bounds(string $path): array
{
    $path = mysql_fb_normalize_path($path);
    if ($path === '') {
        return ['', ''];
    }

    return [$path . '/', $path . '0'];
}

function mysql_fb_json_encode(mixed $value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Unable to encode datastore value.');
    }

    return $encoded;
}

function mysql_fb_json_decode(?string $value): mixed
{
    if ($value === null || $value === '') {
        return null;
    }

    $decoded = json_decode($value, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Stored datastore JSON is invalid.');
    }

    return $decoded;
}

function mysql_fb_flatten_value(string $path, mixed $value): array
{
    $path = mysql_fb_normalize_path($path);

    if ($value === null) {
        return [];
    }

    if (!is_array($value)) {
        return [[
            'path' => $path,
            'parent_path' => mysql_fb_parent_path($path),
            'node_key' => mysql_fb_node_key($path),
            'depth' => mysql_fb_depth($path),
            'node_type' => MYSQL_FB_SCALAR,
            'value_json' => mysql_fb_json_encode($value),
        ]];
    }

    if ($value === []) {
        // Realtime Database does not persist empty containers.
        return [];
    }

    $isList = array_is_list($value);
    $children = [];
    foreach ($value as $key => $childValue) {
        if ($childValue === null) {
            continue;
        }
        $childPath = mysql_fb_join_path($path, (string)$key);
        array_push($children, ...mysql_fb_flatten_value($childPath, $childValue));
    }

    if ($children === []) {
        return [];
    }

    $row = [
        'path' => $path,
        'parent_path' => mysql_fb_parent_path($path),
        'node_key' => mysql_fb_node_key($path),
        'depth' => mysql_fb_depth($path),
        'node_type' => $isList ? MYSQL_FB_ARRAY : MYSQL_FB_OBJECT,
        'value_json' => $isList ? mysql_fb_json_encode(count($value)) : null,
    ];

    return array_merge([$row], $children);
}

function mysql_fb_delete_subtree(PDO $pdo, string $path): void
{
    $nodes = zpay_mysql_table('firebase_nodes');
    $path = mysql_fb_normalize_path($path);

    if ($path === '') {
        $pdo->exec("DELETE FROM {$nodes}");
        return;
    }

    [$lower, $upper] = mysql_fb_descendant_bounds($path);
    $statement = $pdo->prepare(
        "DELETE FROM {$nodes} WHERE path = :path OR (path >= :lower AND path < :upper)"
    );
    $statement->execute([
        ':path' => $path,
        ':lower' => $lower,
        ':upper' => $upper,
    ]);
}

function mysql_fb_insert_rows(PDO $pdo, array $rows): void
{
    if ($rows === []) {
        return;
    }

    $nodes = zpay_mysql_table('firebase_nodes');
    $statement = $pdo->prepare(
        "INSERT INTO {$nodes}
            (path, parent_path, node_key, depth, node_type, value_json, updated_at)
         VALUES
            (:path, :parent_path, :node_key, :depth, :node_type, :value_json, UTC_TIMESTAMP(6))
         ON DUPLICATE KEY UPDATE
            parent_path = VALUES(parent_path),
            node_key = VALUES(node_key),
            depth = VALUES(depth),
            node_type = VALUES(node_type),
            value_json = VALUES(value_json),
            updated_at = VALUES(updated_at)"
    );

    foreach ($rows as $row) {
        $statement->execute([
            ':path' => (string)$row['path'],
            ':parent_path' => (string)$row['parent_path'],
            ':node_key' => (string)$row['node_key'],
            ':depth' => (int)$row['depth'],
            ':node_type' => (int)$row['node_type'],
            ':value_json' => $row['value_json'],
        ]);
    }
}

function mysql_fb_ensure_ancestors(PDO $pdo, string $path): void
{
    $ancestors = mysql_fb_ancestors($path, false);
    if ($ancestors === []) {
        return;
    }

    $nodes = zpay_mysql_table('firebase_nodes');
    $statement = $pdo->prepare(
        "INSERT INTO {$nodes}
            (path, parent_path, node_key, depth, node_type, value_json, updated_at)
         VALUES
            (:path, :parent_path, :node_key, :depth, :node_type, NULL, UTC_TIMESTAMP(6))
         ON DUPLICATE KEY UPDATE
            value_json = IF(node_type = " . MYSQL_FB_OBJECT . ", value_json, NULL),
            node_type = VALUES(node_type),
            updated_at = UTC_TIMESTAMP(6)"
    );

    foreach ($ancestors as $ancestor) {
        $statement->execute([
            ':path' => $ancestor,
            ':parent_path' => mysql_fb_parent_path($ancestor),
            ':node_key' => mysql_fb_node_key($ancestor),
            ':depth' => mysql_fb_depth($ancestor),
            ':node_type' => MYSQL_FB_OBJECT,
        ]);
    }
}

function mysql_fb_prune_empty_ancestors(PDO $pdo, array $paths): void
{
    $candidates = [];
    foreach ($paths as $path) {
        foreach (mysql_fb_ancestors((string)$path) as $ancestor) {
            $candidates[$ancestor] = true;
        }
    }

    $candidates = array_keys($candidates);
    usort($candidates, static function (string $left, string $right): int {
        $depth = mysql_fb_depth($right) <=> mysql_fb_depth($left);
        return $depth !== 0 ? $depth : strcmp($right, $left);
    });

    $nodes = zpay_mysql_table('firebase_nodes');
    $hasChildren = $pdo->prepare(
        "SELECT 1
         FROM {$nodes}
         WHERE parent_path = :parent_path AND path <> :self_path
         LIMIT 1"
    );
    $delete = $pdo->prepare(
        "DELETE FROM {$nodes}
         WHERE path = :path AND node_type <> " . MYSQL_FB_SCALAR
    );

    foreach ($candidates as $candidate) {
        $hasChildren->execute([
            ':parent_path' => $candidate,
            ':self_path' => $candidate,
        ]);
        if ($hasChildren->fetchColumn() === false) {
            $delete->execute([':path' => $candidate]);
        }
    }
}

function mysql_fb_version_etag(int $version): string
{
    return '"' . max(0, $version) . '"';
}

function mysql_fb_etag_version(string $etag): ?int
{
    $etag = trim($etag);
    if (str_starts_with($etag, 'W/')) {
        $etag = substr($etag, 2);
    }
    $etag = trim($etag, "\"' ");
    if ($etag === 'null_etag') {
        return 0;
    }
    if ($etag === '' || preg_match('/^[0-9]+$/', $etag) !== 1) {
        return null;
    }

    return (int)$etag;
}

function mysql_fb_lock_versions(PDO $pdo, array $paths): array
{
    $versions = zpay_mysql_table('firebase_versions');
    $lockPaths = [];
    foreach ($paths as $path) {
        foreach (mysql_fb_ancestors((string)$path) as $ancestor) {
            $lockPaths[$ancestor] = true;
        }
    }
    $lockPaths = array_keys($lockPaths);
    sort($lockPaths, SORT_STRING);

    $insert = $pdo->prepare(
        "INSERT IGNORE INTO {$versions} (path, version, updated_at)
         VALUES (:path, 0, UTC_TIMESTAMP(6))"
    );
    $select = $pdo->prepare(
        "SELECT version FROM {$versions} WHERE path = :path FOR UPDATE"
    );
    $locked = [];

    foreach ($lockPaths as $path) {
        $insert->execute([':path' => $path]);
        $select->execute([':path' => $path]);
        $locked[$path] = (int)($select->fetchColumn() ?: 0);
    }

    return $locked;
}

function mysql_fb_bump_versions(PDO $pdo, array $targets): void
{
    $versions = zpay_mysql_table('firebase_versions');
    $descendants = $pdo->prepare(
        "UPDATE {$versions}
         SET version = version + 1, updated_at = UTC_TIMESTAMP(6)
         WHERE path = :path OR (path >= :lower AND path < :upper)"
    );
    $all = $pdo->prepare(
        "UPDATE {$versions} SET version = version + 1, updated_at = UTC_TIMESTAMP(6)"
    );
    $ancestor = $pdo->prepare(
        "INSERT INTO {$versions} (path, version, updated_at)
         VALUES (:path, 1, UTC_TIMESTAMP(6))
         ON DUPLICATE KEY UPDATE version = version + 1, updated_at = UTC_TIMESTAMP(6)"
    );

    foreach ($targets as $target) {
        $target = mysql_fb_normalize_path((string)$target);
        if ($target === '') {
            $all->execute();
            continue;
        }

        [$lower, $upper] = mysql_fb_descendant_bounds($target);
        $descendants->execute([
            ':path' => $target,
            ':lower' => $lower,
            ':upper' => $upper,
        ]);

        foreach (mysql_fb_ancestors($target, false) as $parent) {
            $ancestor->execute([':path' => $parent]);
        }
    }
}

function mysql_fb_read_rows(PDO $pdo, string $path): array
{
    $nodes = zpay_mysql_table('firebase_nodes');
    $path = mysql_fb_normalize_path($path);

    if ($path === '') {
        $statement = $pdo->query(
            "SELECT path, parent_path, node_key, depth, node_type, value_json
             FROM {$nodes} ORDER BY depth ASC, path ASC"
        );
        return $statement->fetchAll();
    }

    [$lower, $upper] = mysql_fb_descendant_bounds($path);
    $statement = $pdo->prepare(
        "SELECT path, parent_path, node_key, depth, node_type, value_json
         FROM {$nodes}
         WHERE path = :path OR (path >= :lower AND path < :upper)
         ORDER BY depth ASC, path ASC"
    );
    $statement->execute([
        ':path' => $path,
        ':lower' => $lower,
        ':upper' => $upper,
    ]);

    return $statement->fetchAll();
}

function mysql_fb_rebuild_rows(array $rows, string $path): mixed
{
    $path = mysql_fb_normalize_path($path);
    if ($rows === []) {
        return null;
    }

    $byPath = [];
    $children = [];
    foreach ($rows as $row) {
        $rowPath = (string)$row['path'];
        $byPath[$rowPath] = $row;
        $parent = (string)$row['parent_path'];
        if ($rowPath !== $parent) {
            $children[$parent][] = $rowPath;
        }
    }

    if (!isset($byPath[$path])) {
        return null;
    }

    $build = function (string $nodePath) use (&$build, $byPath, $children): mixed {
        $row = $byPath[$nodePath] ?? null;
        if (!is_array($row)) {
            return null;
        }

        $type = (int)$row['node_type'];
        if ($type === MYSQL_FB_SCALAR) {
            return mysql_fb_json_decode($row['value_json'] === null ? null : (string)$row['value_json']);
        }

        $childPaths = $children[$nodePath] ?? [];
        if ($type === MYSQL_FB_ARRAY) {
            $length = max(0, (int)mysql_fb_json_decode((string)($row['value_json'] ?? '0')));
            $value = array_fill(0, $length, null);
            foreach ($childPaths as $childPath) {
                $key = mysql_fb_node_key($childPath);
                if (preg_match('/^(0|[1-9][0-9]*)$/', $key) !== 1) {
                    continue;
                }
                $value[(int)$key] = $build($childPath);
            }
            return $value;
        }

        $value = [];
        foreach ($childPaths as $childPath) {
            $value[mysql_fb_node_key($childPath)] = $build($childPath);
        }
        return $value;
    };

    return $build($path);
}

function mysql_fb_read_path(PDO $pdo, string $path): mixed
{
    return mysql_fb_rebuild_rows(mysql_fb_read_rows($pdo, $path), $path);
}

function mysql_fb_read_node_row(PDO $pdo, string $path): ?array
{
    $nodes = zpay_mysql_table('firebase_nodes');
    $statement = $pdo->prepare(
        "SELECT path, parent_path, node_key, depth, node_type, value_json
         FROM {$nodes} WHERE path = :path LIMIT 1"
    );
    $statement->execute([':path' => mysql_fb_normalize_path($path)]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function mysql_fb_shallow_path(PDO $pdo, string $path): mixed
{
    $nodes = zpay_mysql_table('firebase_nodes');
    $path = mysql_fb_normalize_path($path);
    $statement = $pdo->prepare(
        "SELECT node_key
         FROM {$nodes}
         WHERE parent_path = :path AND path <> parent_path
         ORDER BY node_key ASC"
    );
    $statement->execute([':path' => $path]);
    $keys = $statement->fetchAll(PDO::FETCH_COLUMN);
    if ($keys === []) {
        return null;
    }

    $result = [];
    foreach ($keys as $key) {
        $result[(string)$key] = true;
    }

    return $result;
}

function mysql_fb_query_decode(mixed $value): mixed
{
    if (!is_string($value)) {
        return $value;
    }

    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
}

function mysql_fb_order_value(mixed $value, string $orderBy): mixed
{
    if ($orderBy === '$value') {
        return $value;
    }
    if ($orderBy === '$key') {
        return null;
    }

    $current = $value;
    foreach (explode('/', trim($orderBy, '/')) as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return null;
        }
        $current = $current[$segment];
    }

    return $current;
}

function mysql_fb_value_rank(mixed $value): int
{
    if ($value === null) {
        return 0;
    }
    if ($value === false) {
        return 1;
    }
    if ($value === true) {
        return 2;
    }
    if (is_int($value) || is_float($value)) {
        return 3;
    }
    if (is_string($value)) {
        return 4;
    }

    return 5;
}

function mysql_fb_compare_values(mixed $left, mixed $right): int
{
    $leftRank = mysql_fb_value_rank($left);
    $rightRank = mysql_fb_value_rank($right);
    if ($leftRank !== $rightRank) {
        return $leftRank <=> $rightRank;
    }

    if ($leftRank === 3) {
        return ((float)$left) <=> ((float)$right);
    }
    if ($leftRank === 4) {
        return strcmp((string)$left, (string)$right);
    }

    return 0;
}

function mysql_fb_apply_query(mixed $value, array $query): mixed
{
    if (!is_array($value) || $value === [] || $query === []) {
        return $value;
    }

    $orderBy = mysql_fb_query_order_by($query);
    $entries = [];
    foreach ($value as $key => $entryValue) {
        $key = (string)$key;
        $entries[] = [
            'key' => $key,
            'value' => $entryValue,
            'order' => $orderBy === '$key' ? $key : mysql_fb_order_value($entryValue, $orderBy),
        ];
    }

    $entries = mysql_fb_filter_query_entries($entries, $query);

    if ($entries === []) {
        return null;
    }

    $result = [];
    foreach ($entries as $entry) {
        $result[(string)$entry['key']] = $entry['value'];
    }
    // Firebase REST selects the window using orderBy, then serializes object
    // properties in key order. Match the PHP array order seen by this app.
    ksort($result, SORT_STRING);

    return $result;
}

function mysql_fb_query_order_by(array $query): string
{
    return isset($query['orderBy'])
        ? (string)mysql_fb_query_decode($query['orderBy'])
        : '$key';
}

function mysql_fb_query_is_bounded(array $query): bool
{
    foreach (['equalTo', 'startAt', 'endAt', 'limitToFirst', 'limitToLast'] as $parameter) {
        if (array_key_exists($parameter, $query)) {
            return true;
        }
    }

    return false;
}

function mysql_fb_filter_query_entries(array $entries, array $query): array
{
    usort($entries, static function (array $left, array $right): int {
        $order = mysql_fb_compare_values($left['order'], $right['order']);
        return $order !== 0 ? $order : strcmp((string)$left['key'], (string)$right['key']);
    });

    if (array_key_exists('equalTo', $query)) {
        $equalTo = mysql_fb_query_decode($query['equalTo']);
        $entries = array_values(array_filter(
            $entries,
            static fn(array $entry): bool => mysql_fb_compare_values($entry['order'], $equalTo) === 0
        ));
    } else {
        if (array_key_exists('startAt', $query)) {
            $startAt = mysql_fb_query_decode($query['startAt']);
            $entries = array_values(array_filter(
                $entries,
                static fn(array $entry): bool => mysql_fb_compare_values($entry['order'], $startAt) >= 0
            ));
        }
        if (array_key_exists('endAt', $query)) {
            $endAt = mysql_fb_query_decode($query['endAt']);
            $entries = array_values(array_filter(
                $entries,
                static fn(array $entry): bool => mysql_fb_compare_values($entry['order'], $endAt) <= 0
            ));
        }
    }

    if (isset($query['limitToFirst'])) {
        $entries = array_slice($entries, 0, max(0, (int)$query['limitToFirst']));
    }
    if (isset($query['limitToLast'])) {
        $limit = max(0, (int)$query['limitToLast']);
        $entries = $limit === 0 ? [] : array_slice($entries, -$limit);
    }

    return $entries;
}

function mysql_fb_query_row_value(?array $row, string $typeKey, string $valueKey): mixed
{
    if ($row === null || !isset($row[$typeKey])) {
        return null;
    }

    return (int)$row[$typeKey] === MYSQL_FB_SCALAR
        ? mysql_fb_json_decode($row[$valueKey] === null ? null : (string)$row[$valueKey])
        : [];
}

function mysql_fb_query_candidate_entries(PDO $pdo, string $path, array $query): array
{
    $nodes = zpay_mysql_table('firebase_nodes');
    $path = mysql_fb_normalize_path($path);
    $orderBy = mysql_fb_query_order_by($query);
    $selectOrder = '';
    $joinOrder = '';
    $parameters = [':parent_path' => $path];
    $conditions = ['child.parent_path = :parent_path', 'child.path <> child.parent_path'];
    $orderClause = '';
    $limitClause = '';

    if ($orderBy === '$key') {
        if (array_key_exists('equalTo', $query)) {
            $equalTo = mysql_fb_query_decode($query['equalTo']);
            if (!is_string($equalTo)) {
                return [];
            }
            $conditions[] = 'child.node_key = :key_equal';
            $parameters[':key_equal'] = $equalTo;
        } else {
            if (array_key_exists('startAt', $query)) {
                $startAt = mysql_fb_query_decode($query['startAt']);
                if (is_string($startAt)) {
                    $conditions[] = 'child.node_key >= :key_start';
                    $parameters[':key_start'] = $startAt;
                }
            }
            if (array_key_exists('endAt', $query)) {
                $endAt = mysql_fb_query_decode($query['endAt']);
                if (is_string($endAt)) {
                    $conditions[] = 'child.node_key <= :key_end';
                    $parameters[':key_end'] = $endAt;
                }
            }
        }

        $hasFirst = isset($query['limitToFirst']);
        $hasLast = isset($query['limitToLast']);
        if ($hasFirst xor $hasLast) {
            $limit = max(0, (int)($hasFirst ? $query['limitToFirst'] : $query['limitToLast']));
            $orderClause = ' ORDER BY child.node_key ' . ($hasLast ? 'DESC' : 'ASC');
            $limitClause = ' LIMIT ' . $limit;
        }
    }

    if (!in_array($orderBy, ['$key', '$value'], true) && trim($orderBy, '/') !== '') {
        $joinOrder = " LEFT JOIN {$nodes} ordered ON ordered.path = CONCAT(child.path, '/', :order_path)";
        $selectOrder = ', ordered.node_type AS order_node_type, ordered.value_json AS order_value_json';
        $parameters[':order_path'] = mysql_fb_normalize_path($orderBy);
    }

    $childValue = $orderBy === '$value' ? 'child.value_json' : 'NULL';

    $statement = $pdo->prepare(
        "SELECT child.path, child.node_key, child.node_type, {$childValue} AS value_json{$selectOrder}
         FROM {$nodes} child{$joinOrder}
         WHERE " . implode(' AND ', $conditions) . $orderClause . $limitClause
    );
    $statement->execute($parameters);
    $rows = $statement->fetchAll();
    $entries = [];

    foreach ($rows as $row) {
        $key = (string)$row['node_key'];
        if ($orderBy === '$key') {
            $order = $key;
        } elseif ($orderBy === '$value') {
            $order = mysql_fb_query_row_value($row, 'node_type', 'value_json');
        } elseif (trim($orderBy, '/') === '') {
            $order = null;
        } else {
            $order = mysql_fb_query_row_value($row, 'order_node_type', 'order_value_json');
        }
        $entries[] = [
            'key' => $key,
            'path' => (string)$row['path'],
            'order' => $order,
        ];
    }

    return $entries;
}

function mysql_fb_read_selected_children(PDO $pdo, string $parentPath, array $entries): mixed
{
    if ($entries === []) {
        return null;
    }

    $nodes = zpay_mysql_table('firebase_nodes');
    $parentPath = mysql_fb_normalize_path($parentPath);
    $selected = [];
    foreach ($entries as $entry) {
        $selected[(string)$entry['path']] = (string)$entry['key'];
    }
    $result = [];

    foreach (array_chunk(array_keys($selected), 200) as $chunk) {
        $conditions = [];
        $parameters = [];
        foreach ($chunk as $index => $childPath) {
            [$lower, $upper] = mysql_fb_descendant_bounds($childPath);
            $conditions[] = "(path = :path{$index} OR (path >= :lower{$index} AND path < :upper{$index}))";
            $parameters[":path{$index}"] = $childPath;
            $parameters[":lower{$index}"] = $lower;
            $parameters[":upper{$index}"] = $upper;
        }
        $statement = $pdo->prepare(
            "SELECT path, parent_path, node_key, depth, node_type, value_json
             FROM {$nodes}
             WHERE " . implode(' OR ', $conditions) . '
             ORDER BY depth ASC, path ASC'
        );
        $statement->execute($parameters);

        $groupedRows = [];
        foreach ($statement->fetchAll() as $row) {
            $rowPath = (string)$row['path'];
            $relative = $parentPath === ''
                ? $rowPath
                : substr($rowPath, strlen($parentPath) + 1);
            $separator = strpos($relative, '/');
            $childKey = $separator === false ? $relative : substr($relative, 0, $separator);
            $childPath = mysql_fb_join_path($parentPath, $childKey);
            if (isset($selected[$childPath])) {
                $groupedRows[$childPath][] = $row;
            }
        }

        foreach ($chunk as $childPath) {
            if (!isset($groupedRows[$childPath])) {
                continue;
            }
            $result[$selected[$childPath]] = mysql_fb_rebuild_rows($groupedRows[$childPath], $childPath);
        }
    }
    if ($result === []) {
        return null;
    }
    ksort($result, SORT_STRING);

    return $result;
}

function mysql_fb_get_path_from_pdo(PDO $pdo, string $path, array $query = []): mixed
{
    if (isset($query['shallow']) && in_array(strtolower((string)$query['shallow']), ['1', 'true'], true)) {
        return mysql_fb_shallow_path($pdo, $path);
    }

    if (mysql_fb_query_is_bounded($query)) {
        $node = mysql_fb_read_node_row($pdo, $path);
        if ($node === null) {
            return null;
        }
        if ((int)$node['node_type'] === MYSQL_FB_SCALAR) {
            return mysql_fb_json_decode($node['value_json'] === null ? null : (string)$node['value_json']);
        }

        $entries = mysql_fb_query_candidate_entries($pdo, $path, $query);
        return mysql_fb_read_selected_children(
            $pdo,
            $path,
            mysql_fb_filter_query_entries($entries, $query)
        );
    }

    return mysql_fb_apply_query(mysql_fb_read_path($pdo, $path), $query);
}

function mysql_fb_get_path(string $path, array $query = []): mixed
{
    if (mysql_fb_query_is_bounded($query)) {
        return zpay_mysql_transaction(
            static fn(PDO $pdo): mixed => mysql_fb_get_path_from_pdo($pdo, $path, $query)
        );
    }

    return mysql_fb_get_path_from_pdo(zpay_mysql_pdo(), $path, $query);
}

function mysql_fb_get_path_with_etag(string $path, array $query = []): array
{
    $path = mysql_fb_normalize_path($path);

    return zpay_mysql_transaction(static function (PDO $pdo) use ($path, $query): array {
        // Locking the version path before reading nodes serializes every overlapping
        // mutation and also registers paths that have never been read before.
        $versions = mysql_fb_lock_versions($pdo, [$path]);

        return [
            'value' => mysql_fb_get_path_from_pdo($pdo, $path, $query),
            'etag' => mysql_fb_version_etag((int)($versions[$path] ?? 0)),
        ];
    });
}

function mysql_fb_validate_targets(array $targets): void
{
    $paths = array_keys($targets);
    sort($paths, SORT_STRING);
    $count = count($paths);

    for ($index = 0; $index < $count; $index++) {
        for ($other = $index + 1; $other < $count; $other++) {
            if ($paths[$index] === '') {
                throw new InvalidArgumentException('A multi-location update cannot overlap the database root.');
            }
            if (str_starts_with($paths[$other], $paths[$index] . '/')) {
                throw new InvalidArgumentException('A multi-location update contains overlapping paths.');
            }
        }
    }
}

function mysql_fb_patch_targets(string $basePath, array $data): array
{
    $targets = [];
    foreach ($data as $key => $value) {
        $target = mysql_fb_join_path($basePath, (string)$key);
        $targets[$target] = $value;
    }
    mysql_fb_validate_targets($targets);

    return $targets;
}

function mysql_fb_write_targets(array $targets, ?string $ifMatch = null): array
{
    if ($targets === []) {
        return ['ok' => true, 'status' => 200, 'value' => null, 'etag' => null];
    }

    $normalized = [];
    foreach ($targets as $path => $value) {
        $normalized[mysql_fb_normalize_path((string)$path)] = $value;
    }
    mysql_fb_validate_targets($normalized);
    $paths = array_keys($normalized);
    $casPath = count($paths) === 1 ? $paths[0] : null;

    return zpay_mysql_transaction(static function (PDO $pdo) use ($normalized, $paths, $casPath, $ifMatch): array {
        $locked = mysql_fb_lock_versions($pdo, $paths);

        if ($ifMatch !== null) {
            $expected = mysql_fb_etag_version($ifMatch);
            $current = $casPath === null ? null : (int)($locked[$casPath] ?? 0);
            if ($expected === null || $current === null || $expected !== $current) {
                return [
                    'ok' => false,
                    'status' => 412,
                    'value' => $casPath === null ? null : mysql_fb_read_path($pdo, $casPath),
                    'etag' => mysql_fb_version_etag($current ?? 0),
                ];
            }
        }

        foreach ($normalized as $path => $value) {
            mysql_fb_delete_subtree($pdo, $path);
            $rows = mysql_fb_flatten_value($path, $value);
            if ($rows !== []) {
                mysql_fb_ensure_ancestors($pdo, $path);
                mysql_fb_insert_rows($pdo, $rows);
            }
        }
        mysql_fb_prune_empty_ancestors($pdo, $paths);
        mysql_fb_bump_versions($pdo, $paths);

        $etag = null;
        if ($casPath !== null) {
            $versions = zpay_mysql_table('firebase_versions');
            $statement = $pdo->prepare("SELECT version FROM {$versions} WHERE path = :path");
            $statement->execute([':path' => $casPath]);
            $etag = mysql_fb_version_etag((int)($statement->fetchColumn() ?: 0));
        }

        return [
            'ok' => true,
            'status' => 200,
            'value' => count($normalized) === 1 ? reset($normalized) : $normalized,
            'etag' => $etag,
        ];
    });
}

function mysql_fb_put_path(string $path, mixed $value, ?string $ifMatch = null): array
{
    return mysql_fb_write_targets([mysql_fb_normalize_path($path) => $value], $ifMatch);
}

function mysql_fb_patch_path(string $path, array $value): array
{
    return mysql_fb_write_targets(mysql_fb_patch_targets($path, $value));
}

function mysql_fb_headers_map(array $headers): array
{
    $mapped = [];
    foreach ($headers as $header) {
        if (!is_string($header) || !str_contains($header, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $header, 2);
        $mapped[strtolower(trim($name))] = trim($value);
    }

    return $mapped;
}

function mysql_fb_response(bool $ok, int $status, mixed $value, array $headers = [], ?string $error = null): array
{
    return [
        'ok' => $ok,
        'status' => $status,
        'headers' => $headers,
        'body' => mysql_fb_json_encode($value),
        'json' => $value,
        'error' => $error,
    ];
}

function mysql_fb_request(
    string $method,
    string $path,
    mixed $data = null,
    array $query = [],
    array $headers = [],
    bool $includeHeaders = false
): array {
    unset($includeHeaders);
    $method = strtoupper(trim($method));
    $path = mysql_fb_normalize_path($path);
    $headerMap = mysql_fb_headers_map($headers);

    try {
        zpay_mysql_assert_expected_environment();

        if ($method === 'GET') {
            $responseHeaders = [];
            if (isset($headerMap['x-firebase-etag'])) {
                $read = mysql_fb_get_path_with_etag($path, $query);
                $value = $read['value'];
                $responseHeaders['etag'] = (string)$read['etag'];
            } else {
                $value = mysql_fb_get_path($path, $query);
            }
            return mysql_fb_response(true, 200, $value, $responseHeaders);
        }

        if ($method === 'PUT') {
            $write = mysql_fb_put_path($path, $data, $headerMap['if-match'] ?? null);
            return mysql_fb_response(
                (bool)$write['ok'],
                (int)$write['status'],
                $write['value'],
                $write['etag'] === null ? [] : ['etag' => (string)$write['etag']],
                $write['ok'] ? null : 'ETag mismatch'
            );
        }

        if ($method === 'PATCH') {
            if (!is_array($data)) {
                return mysql_fb_response(false, 400, null, [], 'PATCH requires an object payload');
            }
            $write = mysql_fb_patch_path($path, $data);
            return mysql_fb_response((bool)$write['ok'], (int)$write['status'], $data);
        }

        if ($method === 'DELETE') {
            $write = mysql_fb_put_path($path, null, $headerMap['if-match'] ?? null);
            return mysql_fb_response(
                (bool)$write['ok'],
                (int)$write['status'],
                $write['value'],
                $write['etag'] === null ? [] : ['etag' => (string)$write['etag']],
                $write['ok'] ? null : 'ETag mismatch'
            );
        }

        return mysql_fb_response(false, 405, null, [], 'Unsupported datastore method');
    } catch (Throwable $error) {
        error_log('Z-Pay Swift MySQL datastore request failed: ' . $error->getMessage());
        return mysql_fb_response(false, 503, null, [], 'MySQL datastore unavailable');
    }
}
