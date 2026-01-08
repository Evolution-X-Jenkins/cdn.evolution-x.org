<?php
/**
 * Database setup and management for file browser
 */

require_once __DIR__ . '/config.php';

// Database configuration
define('DB_FILE', __DIR__ . '/../../data/filebrowser.db');

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        $this->connect();
        $this->createTables();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function connect() {
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            // MySQL connection
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ]);
        } else {
            // SQLite connection (default)
            // Ensure data directory exists
            $dataDir = dirname(DB_FILE);
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }
            
            $this->pdo = new PDO('sqlite:' . DB_FILE, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            
            // Enable WAL mode for better concurrency (SQLite only)
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo->exec('PRAGMA cache_size = 1000');
            $this->pdo->exec('PRAGMA temp_store = MEMORY');
        }
    }
    
    private function createTables() {
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            // For MySQL, create cache table if it doesn't exist
            $sql = "
                CREATE TABLE IF NOT EXISTS download_stats_cache (
                    filename VARCHAR(255) PRIMARY KEY,
                    downloads_7day INT DEFAULT 0,
                    downloads_alltime INT DEFAULT 0,
                    cached_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_cached_at (cached_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            try {
                $this->pdo->exec($sql);
            } catch (Exception $e) {
                error_log("Cache table creation failed (may already exist): " . $e->getMessage());
            }
            return;
        }
        
        // SQLite table creation (local development)
        $sql = "
            -- Download statistics (individual downloads)
            CREATE TABLE IF NOT EXISTS download_stats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename VARCHAR(255) NOT NULL,
                folder VARCHAR(255),
                ip_address VARCHAR(45) NOT NULL,
                user_agent TEXT,
                download_time DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            
            -- Download statistics (aggregated with hash management)
            CREATE TABLE IF NOT EXISTS download_stat (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key_path VARCHAR(512) UNIQUE NOT NULL,
                count INTEGER DEFAULT 0,
                sha256 VARCHAR(64),
                md5 VARCHAR(32),
                file_size INTEGER
            );
            
            -- Download statistics cache (pre-computed for fast page loads)
            CREATE TABLE IF NOT EXISTS download_stats_cache (
                filename VARCHAR(255) PRIMARY KEY,
                downloads_7day INTEGER DEFAULT 0,
                downloads_alltime INTEGER DEFAULT 0,
                cached_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            -- Create indexes for better performance
            CREATE INDEX IF NOT EXISTS idx_download_stats_filename ON download_stats(filename);
            CREATE INDEX IF NOT EXISTS idx_download_stats_folder ON download_stats(folder);
            CREATE INDEX IF NOT EXISTS idx_download_stats_time ON download_stats(download_time);
            CREATE INDEX IF NOT EXISTS idx_download_stat_key ON download_stat(key_path);
            CREATE INDEX IF NOT EXISTS idx_cache_time ON download_stats_cache(cached_at);
        ";
        
        $this->pdo->exec($sql);
        
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    // Download statistics methods
    public function recordDownload($filePath, $userId = null, $ipAddress = '', $userAgent = '', $referer = '', $fileSize = 0, $success = true) {
        // Parse filename and folder from path
        $pathParts = explode('/', trim($filePath, '/'));
        $filename = end($pathParts);
        $folder = count($pathParts) > 1 ? implode('/', array_slice($pathParts, 0, -1)) : '';
        
        // Always anonymize IP addresses for privacy
        $recordedIp = $this->anonymizeIp($ipAddress);
        
        $stmt = $this->pdo->prepare('
            INSERT INTO download_stats 
            (filename, folder, ip_address, user_agent) 
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$filename, $folder, $recordedIp, $userAgent ?: 'Unknown']);
        
        // Update aggregate statistics (always needed for download counts)
        $this->updateDownloadStat($filePath, $fileSize);
    }
    
    private function anonymizeIp($ipAddress) {
        // Anonymize IP by removing last octet for IPv4 or last 64 bits for IPv6
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ipAddress);
            $parts[3] = '0';
            return implode('.', $parts);
        } elseif (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // For IPv6, keep only the first 64 bits (network portion)
            $expanded = inet_pton($ipAddress);
            if ($expanded !== false) {
                // Zero out the last 64 bits
                for ($i = 8; $i < 16; $i++) {
                    $expanded[$i] = "\0";
                }
                return inet_ntop($expanded);
            }
        }
        return 'anonymous';
    }
    
    public function updateDownloadStat($filePath, $fileSize = null) {
        // Use full path as key
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            // MySQL syntax
            $stmt = $this->pdo->prepare('
                INSERT INTO download_stat (`key`, count, file_size) 
                VALUES (?, 1, ?) 
                ON DUPLICATE KEY UPDATE 
                    count = count + 1,
                    file_size = COALESCE(?, file_size)
            ');
        } else {
            // SQLite syntax
            $stmt = $this->pdo->prepare('
                INSERT INTO download_stat (key_path, count, file_size) 
                VALUES (?, 1, ?) 
                ON CONFLICT(key_path) DO UPDATE SET 
                    count = count + 1,
                    file_size = COALESCE(?, file_size)
            ');
        }
        $stmt->execute([$filePath, $fileSize, $fileSize]);
    }
    
    public function getDownloadStats($filePath = null, $days = null, $limit = 100, $offset = 0) {
        if ($filePath) {
            if ($days) {
                // Get stats for specific file within time range
                // Extract filename from file path
                $filename = basename($filePath);
                $folder = dirname($filePath);
                if ($folder === '.' || $folder === '') {
                    $folder = '/';
                } else {
                    $folder = '/' . trim($folder, '/');
                }
                
                // Prepare folder condition (empty string for root files)
                $folderCondition = ($folder === '/') ? '' : trim($folder, '/');
                
                // Determine date filter based on database type
                if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
                    $dateFilter = "download_time >= DATE_SUB(NOW(), INTERVAL " . intval($days) . " DAY)";
                } else {
                    $dateFilter = "download_time >= datetime('now', '-" . intval($days) . " days')";
                }
                
                // Get total downloads in the period
                $stmt = $this->pdo->prepare('
                    SELECT COUNT(*) as total_downloads
                    FROM download_stats 
                    WHERE filename = ? AND folder = ?
                    AND ' . $dateFilter
                );
                $stmt->execute([$filename, $folderCondition]);
                $total = $stmt->fetch();
                
                // Get daily breakdown
                $stmt = $this->pdo->prepare('
                    SELECT DATE(download_time) as download_date, COUNT(*) as downloads
                    FROM download_stats 
                    WHERE filename = ? AND folder = ?
                    AND ' . $dateFilter . '
                    GROUP BY DATE(download_time)
                    ORDER BY download_date DESC
                ');
                $stmt->execute([$filename, $folderCondition]);
                $daily = $stmt->fetchAll();
                
                return [
                    'total_downloads' => $total['total_downloads'],
                    'daily_downloads' => $daily
                ];
            } else {
                // Get all-time stats for specific file
                // Use download_stat table which has the accurate count
                $filePath_lookup = ltrim($filePath, '/');
                
                // Determine which column name to use based on DB type
                $keyColumn = (defined('DB_TYPE') && DB_TYPE === 'mysql') ? '`key`' : 'key_path';
                
                $stmt = $this->pdo->prepare('
                    SELECT count as total_downloads,
                           file_size,
                           sha256,
                           md5
                    FROM download_stat
                    WHERE ' . $keyColumn . ' = ?
                ');
                $stmt->execute([$filePath_lookup]);
                $result = $stmt->fetch();
                
                // If no download_stat entry, return zeros
                if (!$result) {
                    return [
                        'total_downloads' => 0,
                        'file_size' => null,
                        'sha256' => null,
                        'md5' => null
                    ];
                }
                
                return $result;
            }
        } else {
            // Get all file stats from download_stat table
            $stmt = $this->pdo->prepare('
                SELECT `key` as file_path, count as downloads, 
                       file_size, sha256, md5
                FROM download_stat 
                ORDER BY count DESC 
                LIMIT ? OFFSET ?
            ');
            $stmt->execute([$limit, $offset]);
            return $stmt->fetchAll();
        }
    }
    
    // Cache methods
    public function getCache($key) {
        $stmt = $this->pdo->prepare('
            SELECT cache_value FROM cache_entries 
            WHERE cache_key = ? AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
        ');
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? json_decode($result['cache_value'], true) : null;
    }
    
    public function setCache($key, $value, $ttl = 3600) {
        $expiresAt = $ttl ? date('Y-m-d H:i:s', time() + $ttl) : null;
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO cache_entries 
            (cache_key, cache_value, expires_at, updated_at) 
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([$key, json_encode($value), $expiresAt]);
    }
    
    public function deleteCache($key) {
        $stmt = $this->pdo->prepare('DELETE FROM cache_entries WHERE cache_key = ?');
        $stmt->execute([$key]);
    }
    
    public function clearExpiredCache() {
        $stmt = $this->pdo->prepare('DELETE FROM cache_entries WHERE expires_at < CURRENT_TIMESTAMP');
        return $stmt->execute();
    }
    
    // File operations methods
    public function createFileOperation($operationType, $sourcePath, $destPath = null) {
        $stmt = $this->pdo->prepare('
            INSERT INTO file_operations 
            (operation_type, source_path, dest_path, status) 
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$operationType, $sourcePath, $destPath, 'pending']);
        return $this->pdo->lastInsertId();
    }
    
    public function updateFileOperation($id, $status, $progress = null, $errorMessage = null) {
        $stmt = $this->pdo->prepare('
            UPDATE file_operations 
            SET status = ?, progress = COALESCE(?, progress), 
                error_message = COALESCE(?, error_message), 
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ');
        $stmt->execute([$status, $progress, $errorMessage, $id]);
    }
    
    public function getFileOperation($id) {
        $stmt = $this->pdo->prepare('SELECT * FROM file_operations WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function getFileOperations($status = null, $limit = 50) {
        if ($status) {
            $stmt = $this->pdo->prepare('
                SELECT * FROM file_operations 
                WHERE status = ? 
                ORDER BY created_at DESC 
                LIMIT ?
            ');
            $stmt->execute([$status, $limit]);
        } else {
            $stmt = $this->pdo->prepare('
                SELECT * FROM file_operations 
                ORDER BY created_at DESC 
                LIMIT ?
            ');
            $stmt->execute([$limit]);
        }
        return $stmt->fetchAll();
    }
    
    // Release management methods
    public function createRelease($releaseData, $releaseType = 'stable') {
        $stmt = $this->pdo->prepare('
            INSERT INTO releases 
            (release_name, release_type, version, description, metadata, status) 
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $releaseData['name'] ?? null,
            $releaseType,
            $releaseData['version'] ?? null,
            $releaseData['description'] ?? null,
            json_encode($releaseData['metadata'] ?? []),
            'pending'
        ]);
        return $this->pdo->lastInsertId();
    }
    
    public function updateReleaseStatus($releaseId, $status, $errorMessage = null) {
        $stmt = $this->pdo->prepare('
            UPDATE releases 
            SET status = ?, error_message = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ');
        $stmt->execute([$status, $errorMessage, $releaseId]);
    }
    
    public function addReleaseFile($releaseId, $filePath, $fileData) {
        $stmt = $this->pdo->prepare('
            INSERT INTO release_files 
            (release_id, file_path, source_path, file_size, checksum, status) 
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $releaseId,
            $filePath,
            $fileData['source_path'] ?? null,
            $fileData['size'] ?? null,
            $fileData['checksum'] ?? null,
            $fileData['status'] ?? 'pending'
        ]);
        return $this->pdo->lastInsertId();
    }
    
    public function getReleaseStatus($releaseId) {
        $stmt = $this->pdo->prepare('
            SELECT r.*, 
                   COUNT(rf.id) as total_files,
                   COUNT(CASE WHEN rf.status = "completed" THEN 1 END) as completed_files
            FROM releases r 
            LEFT JOIN release_files rf ON r.id = rf.release_id 
            WHERE r.id = ? 
            GROUP BY r.id
        ');
        $stmt->execute([$releaseId]);
        $release = $stmt->fetch();
        
        if ($release) {
            // Get file details
            $stmt = $this->pdo->prepare('SELECT * FROM release_files WHERE release_id = ? ORDER BY created_at');
            $stmt->execute([$releaseId]);
            $release['files'] = $stmt->fetchAll();
        }
        
        return $release;
    }
    
    public function getRecentReleases($limit = 10) {
        $stmt = $this->pdo->prepare('
            SELECT r.*, 
                   COUNT(rf.id) as total_files,
                   COUNT(CASE WHEN rf.status = "completed" THEN 1 END) as completed_files
            FROM releases r 
            LEFT JOIN release_files rf ON r.id = rf.release_id 
            GROUP BY r.id 
            ORDER BY r.created_at DESC 
            LIMIT ?
        ');
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    // Upload session methods for chunked uploads
    public function createUploadSession($filePath, $totalSize, $chunkSize = 1048576) {
        $sessionId = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // 24 hours
        
        $stmt = $this->pdo->prepare('
            INSERT INTO upload_sessions 
            (session_id, file_path, total_size, chunk_size, expires_at) 
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$sessionId, $filePath, $totalSize, $chunkSize, $expiresAt]);
        
        return $sessionId;
    }
    
    public function getUploadSession($sessionId) {
        $stmt = $this->pdo->prepare('
            SELECT * FROM upload_sessions 
            WHERE session_id = ? AND expires_at > CURRENT_TIMESTAMP
        ');
        $stmt->execute([$sessionId]);
        return $stmt->fetch();
    }
    
    public function updateUploadProgress($sessionId, $uploadedSize, $status = null) {
        if ($status) {
            $stmt = $this->pdo->prepare('
                UPDATE upload_sessions 
                SET uploaded_size = ?, status = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE session_id = ?
            ');
            $stmt->execute([$uploadedSize, $status, $sessionId]);
        } else {
            $stmt = $this->pdo->prepare('
                UPDATE upload_sessions 
                SET uploaded_size = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE session_id = ?
            ');
            $stmt->execute([$uploadedSize, $sessionId]);
        }
    }
    
    public function cleanupExpiredUploadSessions() {
        $stmt = $this->pdo->prepare('DELETE FROM upload_sessions WHERE expires_at < CURRENT_TIMESTAMP');
        $stmt->execute();
        return $stmt->rowCount();
    }
    
    // Download stats cache methods
    public function getDownloadStatsFromCache($filename) {
        try {
            $stmt = $this->pdo->prepare('
                SELECT downloads_7day, downloads_alltime, cached_at
                FROM download_stats_cache 
                WHERE filename = ?
                AND cached_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ');
            $stmt->execute([$filename]);
            return $stmt->fetch();
        } catch (Exception $e) {
            // Cache table may not exist yet, return null
            return null;
        }
    }
    
    public function getCachedTopDownloads($limit = 20) {
        try {
            $stmt = $this->pdo->prepare('
                SELECT filename, downloads_7day, downloads_alltime, cached_at
                FROM download_stats_cache 
                ORDER BY downloads_alltime DESC
                LIMIT ?
            ');
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }
}
?>