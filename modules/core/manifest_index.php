<?php
/**
 * Listing Manifest Utilities
 */

require_once __DIR__ . '/../setup/config.php';

// Backward compatibility: if config.php has not yet been updated with listing
// manifest constants, provide safe defaults to avoid fatal errors.
if (!defined('LISTING_HIDDEN_NAMES')) {
    define('LISTING_HIDDEN_NAMES', [
        '.bash_history',
        '.cache',
        '.config',
    ]);
}

if (!defined('LISTING_MANIFEST_DIR')) {
    define('LISTING_MANIFEST_DIR', __DIR__ . '/../../data/manifests');
}

if (!defined('LISTING_MANIFEST_FILE')) {
    define('LISTING_MANIFEST_FILE', LISTING_MANIFEST_DIR . '/current.json');
}

if (!defined('LISTING_MANIFEST_STATUS_FILE')) {
    define('LISTING_MANIFEST_STATUS_FILE', LISTING_MANIFEST_DIR . '/status.json');
}

if (!defined('LISTING_MANIFEST_ARCHIVE_DIR')) {
    define('LISTING_MANIFEST_ARCHIVE_DIR', LISTING_MANIFEST_DIR . '/archive');
}

if (!defined('LISTING_ENABLE_DB_FALLBACK')) {
    define('LISTING_ENABLE_DB_FALLBACK', true);
}

if (!defined('LISTING_ENABLE_LIVE_SCAN_FALLBACK')) {
    define('LISTING_ENABLE_LIVE_SCAN_FALLBACK', true);
}

if (!defined('LISTING_MANIFEST_CACHE_TTL')) {
    define('LISTING_MANIFEST_CACHE_TTL', 1800);
}

function listing_parse_epoch($value) {
    if (is_int($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int)$value;
    }

    if (is_string($value) && $value !== '') {
        $ts = strtotime($value);
        if ($ts !== false) {
            return (int)$ts;
        }
    }

    return 0;
}

function listing_parse_modified_epoch($value) {
    return listing_parse_epoch($value);
}

function listing_normalize_relative_path($path) {
    $trimmed = trim((string)$path);
    if ($trimmed === '' || $trimmed === '/') {
        return '/';
    }

    $normalized = '/' . trim($trimmed, '/');
    return preg_replace('#/+#', '/', $normalized);
}

function listing_should_hide_name($name) {
    static $hidden = null;
    if ($hidden === null) {
        $hidden = [];
        foreach (LISTING_HIDDEN_NAMES as $item) {
            $hidden[(string)$item] = true;
        }
    }

    return isset($hidden[$name]);
}

function listing_sort_items(array &$items) {
    usort($items, function($a, $b) {
        if ((bool)$a['is_dir'] !== (bool)$b['is_dir']) {
            return ((int)$b['is_dir']) - ((int)$a['is_dir']);
        }

        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
}

function listing_live_scan_items($relativePath) {
    $relativePath = listing_normalize_relative_path($relativePath);
    $requested = $relativePath === '/' ? '' : ltrim($relativePath, '/');
    $fullPath = sanitize_path($requested, BASE_PATH);

    $items = [];
    if (!is_dir($fullPath)) {
        return $items;
    }

    $files = scandir($fullPath);
    if (!is_array($files)) {
        return $items;
    }

    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || listing_should_hide_name($file)) {
            continue;
        }

        $filePath = $fullPath . '/' . $file;
        $stat = @stat($filePath);
        $isDir = $stat !== false && (($stat['mode'] & 0170000) === 0040000);
        $isFile = $stat !== false && (($stat['mode'] & 0170000) === 0100000);
        $fileSize = $isFile ? (int)$stat['size'] : 0;
        $modifiedEpoch = $stat !== false ? (int)$stat['mtime'] : 0;

        $itemPath = ($relativePath === '/' ? '' : $relativePath) . '/' . $file;
        $itemPath = '/' . ltrim($itemPath, '/');

        $items[] = [
            'name' => $file,
            'path' => $itemPath,
            'is_dir' => $isDir,
            'size' => $fileSize,
            'modified' => $modifiedEpoch,
            'modified_at' => $modifiedEpoch,
            'first_seen_at' => 0,
            'icon' => get_file_icon($file, $isDir),
        ];
    }

    listing_sort_items($items);
    return $items;
}

function listing_load_manifest_tree() {
    if (!file_exists(LISTING_MANIFEST_FILE)) {
        return null;
    }

    $raw = @file_get_contents(LISTING_MANIFEST_FILE);
    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}

function listing_find_manifest_node($node, $relativePath) {
    $relativePath = listing_normalize_relative_path($relativePath);

    if (!is_array($node)) {
        return null;
    }

    $nodePath = listing_normalize_relative_path((string)($node['path'] ?? '/'));
    if ($nodePath === $relativePath) {
        return $node;
    }

    $contents = $node['contents'] ?? [];
    if (!is_array($contents)) {
        return null;
    }

    foreach ($contents as $child) {
        if (!is_array($child) || empty($child['is_dir'])) {
            continue;
        }

        $result = listing_find_manifest_node($child, $relativePath);
        if ($result !== null) {
            return $result;
        }
    }

    return null;
}

function listing_manifest_node_to_items($node) {
    $contents = $node['contents'] ?? [];
    if (!is_array($contents)) {
        return [];
    }

    $items = [];
    foreach ($contents as $child) {
        if (!is_array($child)) {
            continue;
        }

        $name = (string)($child['name'] ?? '');
        if ($name === '' || listing_should_hide_name($name)) {
            continue;
        }

        $isDir = !empty($child['is_dir']);
        $modifiedEpoch = listing_parse_modified_epoch($child['modified_at'] ?? 0);

        $items[] = [
            'name' => $name,
            'path' => listing_normalize_relative_path((string)($child['path'] ?? '')),
            'is_dir' => $isDir,
            'size' => (int)($child['size'] ?? 0),
            'modified' => $modifiedEpoch,
            'modified_at' => $modifiedEpoch,
            'first_seen_at' => listing_parse_epoch($child['first_seen_at'] ?? 0),
            'icon' => get_file_icon($name, $isDir),
        ];
    }

    listing_sort_items($items);
    return $items;
}

function listing_load_items_from_manifest($relativePath, &$metadata = null) {
    $metadata = [
        'source' => 'manifest_json',
        'generated_at' => 0,
    ];

    $tree = listing_load_manifest_tree();
    if ($tree === null) {
        return null;
    }

    $metadata['generated_at'] = listing_parse_epoch($tree['generated_at'] ?? 0);

    $node = listing_find_manifest_node($tree, $relativePath);
    if ($node === null) {
        return null;
    }

    return listing_manifest_node_to_items($node);
}

function listing_manifest_table_exists($pdo) {
    try {
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'listing_manifest_entries' LIMIT 1");
            $stmt->execute();
            return (bool)$stmt->fetchColumn();
        }

        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'listing_manifest_entries' LIMIT 1");
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function listing_ensure_manifest_tables($pdo) {
    if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
        $sql = "
            CREATE TABLE IF NOT EXISTS listing_manifest_entries (
                path VARCHAR(768) PRIMARY KEY,
                parent_path VARCHAR(768) NOT NULL,
                name VARCHAR(512) NOT NULL,
                is_dir TINYINT(1) NOT NULL,
                size BIGINT NOT NULL DEFAULT 0,
                modified_at BIGINT NULL,
                first_seen_at BIGINT NOT NULL,
                generated_at BIGINT NOT NULL,
                INDEX idx_manifest_parent (parent_path),
                INDEX idx_manifest_generated (generated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        $pdo->exec($sql);

        // Migrate older schemas to epoch-backed columns.
        try {
            $pdo->exec("ALTER TABLE listing_manifest_entries MODIFY COLUMN modified_at BIGINT NULL");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE listing_manifest_entries MODIFY COLUMN first_seen_at BIGINT NOT NULL");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE listing_manifest_entries MODIFY COLUMN generated_at BIGINT NOT NULL");
        } catch (Exception $e) {
        }

        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS listing_manifest_entries (
            path TEXT PRIMARY KEY,
            parent_path TEXT NOT NULL,
            name TEXT NOT NULL,
            is_dir INTEGER NOT NULL,
            size INTEGER NOT NULL DEFAULT 0,
            modified_at INTEGER,
            first_seen_at INTEGER NOT NULL,
            generated_at INTEGER NOT NULL
        )
    ";

    $pdo->exec($sql);
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_manifest_parent ON listing_manifest_entries(parent_path)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_manifest_generated ON listing_manifest_entries(generated_at)");
}

function listing_load_items_from_db($relativePath, $pdo, &$metadata = null) {
    $metadata = [
        'source' => 'manifest_db',
        'generated_at' => 0,
    ];

    if (!listing_manifest_table_exists($pdo)) {
        return null;
    }

    $relativePath = listing_normalize_relative_path($relativePath);

    try {
        $generatedStmt = $pdo->query('SELECT MAX(generated_at) FROM listing_manifest_entries');
        $metadata['generated_at'] = listing_parse_epoch($generatedStmt->fetchColumn());

        $stmt = $pdo->prepare('SELECT path, name, is_dir, size, modified_at, first_seen_at FROM listing_manifest_entries WHERE parent_path = ? ORDER BY is_dir DESC, LOWER(name) ASC');
        $stmt->execute([$relativePath]);
        $rows = $stmt->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $name = (string)($row['name'] ?? '');
            if ($name === '' || listing_should_hide_name($name)) {
                continue;
            }

            $isDir = (int)($row['is_dir'] ?? 0) === 1;
            $modifiedEpoch = listing_parse_modified_epoch($row['modified_at'] ?? 0);

            $items[] = [
                'name' => $name,
                'path' => listing_normalize_relative_path((string)($row['path'] ?? '')),
                'is_dir' => $isDir,
                'size' => (int)($row['size'] ?? 0),
                'modified' => $modifiedEpoch,
                'modified_at' => $modifiedEpoch,
                'first_seen_at' => listing_parse_epoch($row['first_seen_at'] ?? 0),
                'icon' => get_file_icon($name, $isDir),
            ];
        }

        return $items;
    } catch (Exception $e) {
        return null;
    }
}

function listing_collect_first_seen_map($node, &$map) {
    if (!is_array($node)) {
        return;
    }

    $path = listing_normalize_relative_path((string)($node['path'] ?? '/'));
    $firstSeenAt = listing_parse_epoch($node['first_seen_at'] ?? 0);
    if ($firstSeenAt > 0) {
        $map[$path] = $firstSeenAt;
    }

    if (empty($node['is_dir'])) {
        return;
    }

    $contents = $node['contents'] ?? [];
    if (!is_array($contents)) {
        return;
    }

    foreach ($contents as $child) {
        listing_collect_first_seen_map($child, $map);
    }
}

function listing_flatten_tree_for_db($node, $generatedAt, &$rows) {
    if (!is_array($node)) {
        return;
    }

    $path = listing_normalize_relative_path((string)($node['path'] ?? '/'));
    $name = (string)($node['name'] ?? '/');
    $isDir = !empty($node['is_dir']);

    if ($path !== '/') {
        $parentPath = dirname($path);
        if ($parentPath === '\\' || $parentPath === '.' || $parentPath === '') {
            $parentPath = '/';
        }

        $rows[] = [
            'path' => $path,
            'parent_path' => listing_normalize_relative_path($parentPath),
            'name' => $name,
            'is_dir' => $isDir ? 1 : 0,
            'size' => (int)($node['size'] ?? 0),
            'modified_at' => listing_parse_modified_epoch($node['modified_at'] ?? 0),
            'first_seen_at' => listing_parse_epoch($node['first_seen_at'] ?? $generatedAt),
            'generated_at' => listing_parse_epoch($generatedAt),
        ];
    }

    if (!$isDir) {
        return;
    }

    $contents = $node['contents'] ?? [];
    if (!is_array($contents)) {
        return;
    }

    foreach ($contents as $child) {
        listing_flatten_tree_for_db($child, $generatedAt, $rows);
    }
}

function listing_store_tree_in_db($pdo, $tree, $generatedAt) {
    listing_ensure_manifest_tables($pdo);

    $rows = [];
    listing_flatten_tree_for_db($tree, $generatedAt, $rows);

    $dedupedRows = [];
    foreach ($rows as $row) {
        $path = listing_normalize_relative_path((string)($row['path'] ?? '/'));
        if ($path === '' || $path === '/') {
            continue;
        }

        if (isset($dedupedRows[$path])) {
            continue;
        }

        $dedupedRows[$path] = $row;
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM listing_manifest_entries');

        $stmt = $pdo->prepare('INSERT INTO listing_manifest_entries (path, parent_path, name, is_dir, size, modified_at, first_seen_at, generated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($dedupedRows as $row) {
            $stmt->execute([
                $row['path'],
                $row['parent_path'],
                $row['name'],
                $row['is_dir'],
                $row['size'],
                $row['modified_at'] > 0 ? $row['modified_at'] : null,
                $row['first_seen_at'],
                $row['generated_at'],
            ]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function listing_write_status_file($status) {
    if (!is_dir(LISTING_MANIFEST_DIR)) {
        @mkdir(LISTING_MANIFEST_DIR, 0755, true);
    }

    $tmp = LISTING_MANIFEST_STATUS_FILE . '.tmp';
    $encoded = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }

    if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) {
        return false;
    }

    return @rename($tmp, LISTING_MANIFEST_STATUS_FILE);
}

function listing_read_status_file() {
    if (!file_exists(LISTING_MANIFEST_STATUS_FILE)) {
        return null;
    }

    $raw = @file_get_contents(LISTING_MANIFEST_STATUS_FILE);
    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function listing_find_entry_in_tree($node, $targetPath) {
    if (!is_array($node)) {
        return null;
    }

    $nodePath = listing_normalize_relative_path((string)($node['path'] ?? '/'));
    if ($nodePath === $targetPath) {
        return $node;
    }

    $contents = $node['contents'] ?? [];
    if (!is_array($contents)) {
        return null;
    }

    foreach ($contents as $child) {
        $match = listing_find_entry_in_tree($child, $targetPath);
        if ($match !== null) {
            return $match;
        }
    }

    return null;
}

function listing_find_manifest_entry_by_path($path, &$metadata = null) {
    $metadata = [
        'source' => 'manifest_json',
        'generated_at' => 0,
    ];

    $tree = listing_load_manifest_tree();
    if ($tree === null) {
        return null;
    }

    $metadata['generated_at'] = listing_parse_epoch($tree['generated_at'] ?? 0);
    $targetPath = listing_normalize_relative_path($path);
    $entry = listing_find_entry_in_tree($tree, $targetPath);
    return is_array($entry) ? $entry : null;
}

function listing_find_db_entry_by_path($path, $pdo, &$metadata = null) {
    $metadata = [
        'source' => 'manifest_db',
        'generated_at' => 0,
    ];

    if (!listing_manifest_table_exists($pdo)) {
        return null;
    }

    $generatedStmt = $pdo->query('SELECT MAX(generated_at) FROM listing_manifest_entries');
    $metadata['generated_at'] = listing_parse_epoch($generatedStmt->fetchColumn());

    $targetPath = listing_normalize_relative_path($path);
    $stmt = $pdo->prepare('SELECT path, parent_path, name, is_dir, size, modified_at, first_seen_at FROM listing_manifest_entries WHERE path = ? LIMIT 1');
    $stmt->execute([$targetPath]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'path' => listing_normalize_relative_path((string)$row['path']),
        'name' => (string)$row['name'],
        'is_dir' => (int)$row['is_dir'] === 1,
        'size' => (int)$row['size'],
        'modified_at' => listing_parse_modified_epoch($row['modified_at'] ?? 0),
        'first_seen_at' => listing_parse_epoch($row['first_seen_at'] ?? 0),
    ];
}

function listing_lookup_path_metadata($path, $pdo = null, &$metadata = null) {
    $metadata = [
        'source' => null,
        'generated_at' => 0,
    ];

    $manifestMeta = [];
    $entry = listing_find_manifest_entry_by_path($path, $manifestMeta);
    if (is_array($entry)) {
        $metadata = $manifestMeta;
        return [
            'path' => listing_normalize_relative_path((string)($entry['path'] ?? '/')),
            'name' => (string)($entry['name'] ?? ''),
            'is_dir' => !empty($entry['is_dir']),
            'size' => (int)($entry['size'] ?? 0),
            'modified_at' => listing_parse_modified_epoch($entry['modified_at'] ?? 0),
            'first_seen_at' => listing_parse_epoch($entry['first_seen_at'] ?? 0),
        ];
    }

    if ($pdo !== null) {
        $dbMeta = [];
        $dbEntry = listing_find_db_entry_by_path($path, $pdo, $dbMeta);
        if (is_array($dbEntry)) {
            $metadata = $dbMeta;
            return $dbEntry;
        }
    }

    return null;
}
