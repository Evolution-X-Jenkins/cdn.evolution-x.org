<?php
/**
 * Generate File Hashes Script
 * 
 * Scans all files in the base directory and generates SQL INSERT statements
 * for importing file hashes into the database in production.
 * 
 * Usage: php generate_hashes.php [output_file.sql] [--mysql|--sqlite]
 */

require_once __DIR__ . '/modules/setup/config.php';

// Configuration
$basePath = BASE_PATH;
$dbType = 'mysql'; // Default to MySQL
$outputFile = null;

// Parse command line arguments
$args = array_slice($argv, 1);
foreach ($args as $arg) {
    if ($arg === '--mysql') {
        $dbType = 'mysql';
    } elseif ($arg === '--sqlite') {
        $dbType = 'sqlite';
    } elseif (strpos($arg, '--') !== 0) {
        $outputFile = $arg;
    }
}

if (!$outputFile) {
    $outputFile = "file_hashes_{$dbType}_" . date('Y-m-d_H-i-s') . '.sql';
}

// Counters
$filesProcessed = 0;
$filesSkipped = 0;
$startTime = microtime(true);

echo "=== File Hash Generation Script ===\n";
echo "Base Path: $basePath\n";
echo "Output File: $outputFile\n";
echo "Database Type: " . strtoupper($dbType) . "\n";
echo "Start Time: " . date('Y-m-d H:i:s') . "\n";
echo "\n";

// Open output file for writing
$sqlFile = fopen($outputFile, 'w');
if (!$sqlFile) {
    echo "ERROR: Cannot open $outputFile for writing\n";
    exit(1);
}

// Write SQL header
fwrite($sqlFile, "-- File Hashes Import Script\n");
fwrite($sqlFile, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
fwrite($sqlFile, "-- Base Path: $basePath\n");
fwrite($sqlFile, "-- Database Type: " . strtoupper($dbType) . "\n");
fwrite($sqlFile, "\n");

// Write table creation based on database type
if ($dbType === 'mysql') {
    fwrite($sqlFile, "-- Create download_stat table if it doesn't exist (MySQL)\n");
    fwrite($sqlFile, "CREATE TABLE IF NOT EXISTS download_stat (\n");
    fwrite($sqlFile, "    `key` VARCHAR(1024) PRIMARY KEY,\n");
    fwrite($sqlFile, "    `count` INT DEFAULT 0,\n");
    fwrite($sqlFile, "    `sha256` VARCHAR(64),\n");
    fwrite($sqlFile, "    `md5` VARCHAR(32),\n");
    fwrite($sqlFile, "    `file_size` BIGINT,\n");
    fwrite($sqlFile, "    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n");
    fwrite($sqlFile, ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n");
} else {
    fwrite($sqlFile, "-- Create download_stat table if it doesn't exist (SQLite)\n");
    fwrite($sqlFile, "CREATE TABLE IF NOT EXISTS download_stat (\n");
    fwrite($sqlFile, "    key_path TEXT PRIMARY KEY,\n");
    fwrite($sqlFile, "    count INTEGER DEFAULT 0,\n");
    fwrite($sqlFile, "    sha256 TEXT,\n");
    fwrite($sqlFile, "    md5 TEXT,\n");
    fwrite($sqlFile, "    file_size INTEGER,\n");
    fwrite($sqlFile, "    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n");
    fwrite($sqlFile, ");\n\n");
}

// Write data import section
fwrite($sqlFile, "-- File Hash Data\n");
if ($dbType === 'mysql') {
    fwrite($sqlFile, "START TRANSACTION;\n\n");
} else {
    fwrite($sqlFile, "BEGIN TRANSACTION;\n\n");
}

// Batch insert for better performance
$batchSize = 100;
$currentBatch = 0;
$buffer = '';

/**
 * Recursively scan directory and generate hashes
 */
function scanAndHashFiles($dir, $basePath, $sqlFile, &$filesProcessed, &$filesSkipped, $dbType, &$buffer, &$batchSize, &$currentBatch) {
    static $fileCount = 0;
    static $lastReport = 0;
    
    if (!is_dir($dir)) {
        return;
    }
    
    try {
        $items = @scandir($dir);
    } catch (Exception $e) {
        echo "[WARN] Cannot read directory $dir: " . $e->getMessage() . "\n";
        $filesSkipped++;
        return;
    }
    
    if (!$items) {
        return;
    }
    
    foreach ($items as $item) {
        // Skip dot files
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $fullPath = $dir . '/' . $item;
        $relPath = substr($fullPath, strlen($basePath) + 1);
        
        if (is_dir($fullPath)) {
            // Recursively process subdirectories
            scanAndHashFiles($fullPath, $basePath, $sqlFile, $filesProcessed, $filesSkipped, $dbType, $buffer, $batchSize, $currentBatch);
        } elseif (is_file($fullPath)) {
            // Process file
            $fileCount++;
            
            // Report progress every 100 files
            if ($fileCount - $lastReport >= 100) {
                echo "[*] Processing... $fileCount files scanned, $filesProcessed hashed\n";
                $lastReport = $fileCount;
            }
            
            $sql = generateFileHashSQL($fullPath, $relPath, $dbType);
            if ($sql) {
                $buffer .= $sql;
                $currentBatch++;
                $filesProcessed++;
                
                // Flush buffer every N statements
                if ($currentBatch >= $batchSize) {
                    fwrite($sqlFile, $buffer);
                    $buffer = '';
                    $currentBatch = 0;
                }
            } else {
                $filesSkipped++;
            }
        }
    }
}

/**
 * Generate hash for a single file and return SQL INSERT statement
 */
function generateFileHashSQL($fullPath, $relPath, $dbType) {
    // Check if file is readable
    if (!is_readable($fullPath)) {
        return null;
    }
    
    // Get file size
    $fileSize = filesize($fullPath);
    if ($fileSize === false) {
        return null;
    }
    
    // Skip files larger than 5GB (won't be efficient to hash)
    if ($fileSize > 5 * 1024 * 1024 * 1024) {
        echo "[SKIP] File too large (>5GB): $relPath\n";
        return null;
    }
    
    // Calculate hashes
    $sha256 = @hash_file('sha256', $fullPath);
    $md5 = @hash_file('md5', $fullPath);
    
    // Skip if no hashes could be computed
    if ($sha256 === false && $md5 === false) {
        return null;
    }
    
    // Prepare SQL values based on database type
    if ($dbType === 'mysql') {
        $keyPath = addslashes($relPath);
        $sha256Val = $sha256 !== false ? "'" . addslashes($sha256) . "'" : 'NULL';
        $md5Val = $md5 !== false ? "'" . addslashes($md5) . "'" : 'NULL';
        
        $sql = "INSERT INTO download_stat (`key`, `sha256`, `md5`, `file_size`, `last_updated`) ";
        $sql .= "VALUES ('$keyPath', $sha256Val, $md5Val, $fileSize, NOW()) ";
        $sql .= "ON DUPLICATE KEY UPDATE `sha256`=$sha256Val, `md5`=$md5Val, `file_size`=$fileSize, `last_updated`=NOW();\n";
    } else {
        $keyPath = str_replace("'", "''", $relPath);
        $sha256Val = $sha256 !== false ? "'" . str_replace("'", "''", $sha256) . "'" : 'NULL';
        $md5Val = $md5 !== false ? "'" . str_replace("'", "''", $md5) . "'" : 'NULL';
        
        $sql = "INSERT OR REPLACE INTO download_stat (key, sha256, md5, file_size, last_updated) ";
        $sql .= "VALUES ('$keyPath', $sha256Val, $md5Val, $fileSize, CURRENT_TIMESTAMP);\n";
    }
    
    return $sql;
}

// Start scanning
echo "Scanning directory structure...\n\n";
scanAndHashFiles($basePath, $basePath, $sqlFile, $filesProcessed, $filesSkipped, $dbType, $buffer, $batchSize, $currentBatch);

// Flush any remaining buffer
if (!empty($buffer)) {
    fwrite($sqlFile, $buffer);
}

// Write SQL footer
fwrite($sqlFile, "\nCOMMIT;\n");

// Close file
fclose($sqlFile);

// Report summary
$endTime = microtime(true);
$duration = $endTime - $startTime;

echo "\n=== Summary ===\n";
echo "Files Processed: $filesProcessed\n";
echo "Files Skipped: $filesSkipped\n";
echo "Duration: " . number_format($duration, 2) . " seconds\n";
if ($duration > 0) {
    echo "Average: " . number_format($filesProcessed / $duration, 0) . " files/sec\n";
}
echo "\n";
echo "SQL file saved to: $outputFile\n";
echo "File size: " . number_format(filesize($outputFile) / 1024 / 1024, 2) . " MB\n";
echo "\n";

echo "=== Import Instructions ===\n";
if ($dbType === 'mysql') {
    echo "To import into MySQL database:\n";
    echo "  mysql -h HOST -u USER -p DATABASE < $outputFile\n";
    echo "\n";
    echo "Or from production server:\n";
    echo "  ssh user@production-server 'mysql -h DB_HOST -u DB_USER -p DB_NAME < /path/to/$outputFile'\n";
} else {
    echo "To import into SQLite database:\n";
    echo "  sqlite3 filebrowser.db < $outputFile\n";
}
echo "\n";

// Optional: Show first few lines of generated SQL
echo "=== First 15 Lines of Generated SQL ===\n";
$fp = fopen($outputFile, 'r');
$count = 0;
while (($line = fgets($fp)) !== false && $count < 15) {
    echo $line;
    $count++;
}
fclose($fp);

echo "\n";
echo "Completed successfully!\n";
?>

