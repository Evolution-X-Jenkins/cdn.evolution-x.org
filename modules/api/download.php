<?php
/**
 * Download Statistics API Module
 */

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/database.php';

function handleDownloadStatsApi($method, $pathParts) {
    $db = Database::getInstance();
    
    switch ($method) {
        case 'GET':
            // Get download statistics
            $stats = $db->getDownloadStats();
            return [
                'success' => true,
                'data' => $stats
            ];

        case 'POST':
            // Record a download
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input || !isset($input['file_path'])) {
                return [
                    'error' => 'Missing file_path parameter',
                    'success' => false
                ];
            }
            
            $db->recordDownload($input['file_path'], $input['user_agent'] ?? '', $input['ip'] ?? $_SERVER['REMOTE_ADDR']);
            return ['success' => true];

        default:
            return [
                'error' => 'Method not allowed',
                'success' => false
            ];
    }
}

function handleDownloadStatisticsApi($method, $pathParts) {
    if ($method !== 'GET') {
        return [
            'status' => 'error',
            'APICode' => 'T-0004',
            'message' => 'Method not allowed'
        ];
    }
    
    $db = Database::getInstance();
    
    // Parse query parameters
    $filenameFilter = $_GET['filename'] ?? null;
    $folderFilter = $_GET['folder'] ?? null;
    $timeStart = $_GET['timeStart'] ?? null;
    $timeEnd = $_GET['timeEnd'] ?? null;
    $limit = min((int)($_GET['limit'] ?? 50), 1000);
    $sortBy = $_GET['sort'] ?? 'downloads';
    $format = $_GET['format'] ?? 'summary';
    
    // Parse and validate date parameters
    $startDate = null;
    $endDate = null;
    
    if ($timeStart) {
        $startDate = DateTime::createFromFormat('Y-m-d', $timeStart);
        if (!$startDate) {
            return [
                'status' => 'error',
                'APICode' => 'T-0002',
                'message' => 'Invalid timeStart format. Use YYYY-MM-DD'
            ];
        }
        $startDate->setTime(0, 0, 0);
    }
    
    if ($timeEnd) {
        $endDate = DateTime::createFromFormat('Y-m-d', $timeEnd);
        if (!$endDate) {
            return [
                'status' => 'error',
                'APICode' => 'T-0002',
                'message' => 'Invalid timeEnd format. Use YYYY-MM-DD'
            ];
        }
        $endDate->setTime(23, 59, 59);
    }
    
    if ($startDate && $endDate && $startDate > $endDate) {
        return [
            'status' => 'error',
            'APICode' => 'T-0002',
            'message' => 'timeStart cannot be later than timeEnd'
        ];
    }
    
    // Check different query types
    $noFilters = (!$filenameFilter && !$folderFilter && !$startDate && !$endDate);
    $singleFilterTotal = (($filenameFilter && !$folderFilter) || (!$filenameFilter && $folderFilter)) && !$startDate && !$endDate;
    $timeOnlyFilter = (!$filenameFilter && !$folderFilter && ($startDate || $endDate));
    
    if ($noFilters) {
        // Return overall statistics using download_stat table
        try {
            $totalFiles = $db->getConnection()->query('SELECT COUNT(*) FROM download_stat')->fetchColumn();
            $totalDownloads = $db->getConnection()->query('SELECT SUM(count) FROM download_stat')->fetchColumn() ?: 0;
            
            $oldestDownload = $db->getConnection()->query('SELECT MIN(download_time) FROM download_stats')->fetchColumn();
            $oldestDate = $oldestDownload ? date('Y-m-d', strtotime($oldestDownload)) : null;
            $todayDate = date('Y-m-d');
            
            return [[
                'folder' => 'ALL_DOWNLOADS',
                'downloadCount' => (int)$totalDownloads,
                'timeStart' => $oldestDate,
                'timeEnd' => $todayDate,
                'individualFiles' => [[
                    'filename' => "TOTAL_FILES_$totalFiles",
                    'downloadCount' => (int)$totalDownloads
                ]]
            ]];
        } catch (Exception $e) {
            error_log("Download statistics error: " . $e->getMessage());
            return [
                'status' => 'error',
                'APICode' => 'T-0001',
                'message' => 'Failed to retrieve download statistics'
            ];
        }
    }
    
    if ($singleFilterTotal) {
        // Handle single filter scenarios (filename OR folder only, no dates)
        try {
            if ($filenameFilter && !$folderFilter) {
                // Get total for all files matching filename filter
                $stmt = $db->getConnection()->prepare('
                    SELECT SUM(ds.`count`) as total_count
                    FROM download_stat ds 
                    WHERE ds.`key` LIKE ?
                ');
                $stmt->execute(['%' . $filenameFilter . '%']);
                $totalCount = $stmt->fetchColumn() ?: 0;
                
                return [[
                    'folder' => 'FILENAME_FILTER_' . strtoupper($filenameFilter),
                    'downloadCount' => (int)$totalCount,
                    'timeStart' => null,
                    'timeEnd' => null,
                    'individualFiles' => [[
                        'filename' => "MATCHING_FILES_" . $filenameFilter,
                        'downloadCount' => (int)$totalCount
                    ]]
                ]];
            } elseif ($folderFilter && !$filenameFilter) {
                // Get total for specific folder
                $stmt = $db->getConnection()->prepare('
                    SELECT SUM(ds.`count`) as total_count
                    FROM download_stat ds 
                    WHERE ds.`key` LIKE ?
                ');
                $stmt->execute([$folderFilter . '/%']);
                $totalCount = $stmt->fetchColumn() ?: 0;
                
                return [[
                    'folder' => $folderFilter,
                    'downloadCount' => (int)$totalCount,
                    'timeStart' => null,
                    'timeEnd' => null,
                    'individualFiles' => [[
                        'filename' => "FOLDER_TOTAL",
                        'downloadCount' => (int)$totalCount
                    ]]
                ]];
            }
        } catch (Exception $e) {
            error_log("Download statistics error: " . $e->getMessage());
            return [
                'status' => 'error',
                'APICode' => 'T-0001',
                'message' => 'Failed to retrieve download statistics'
            ];
        }
    }
    
    if ($timeOnlyFilter) {
        // Handle time-only filters (no filename/folder, just date range)
        try {
            $whereConditions = ['1=1'];
            $params = [];
            
            if ($startDate) {
                $whereConditions[] = 'download_time >= ?';
                $params[] = $startDate->format('Y-m-d H:i:s');
            }
            
            if ($endDate) {
                $whereConditions[] = 'download_time <= ?';
                $params[] = $endDate->format('Y-m-d H:i:s');
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            $stmt = $db->getConnection()->prepare("
                SELECT COUNT(*) as total_downloads
                FROM download_stats 
                WHERE $whereClause
            ");
            $stmt->execute($params);
            $totalCount = $stmt->fetchColumn() ?: 0;
            
            return [[
                'folder' => 'TIME_RANGE_FILTER',
                'downloadCount' => (int)$totalCount,
                'timeStart' => $startDate ? $startDate->format('Y-m-d') : null,
                'timeEnd' => $endDate ? $endDate->format('Y-m-d') : null,
                'individualFiles' => [[
                    'filename' => "DOWNLOADS_IN_RANGE",
                    'downloadCount' => (int)$totalCount
                ]]
            ]];
        } catch (Exception $e) {
            error_log("Download statistics error: " . $e->getMessage());
            return [
                'status' => 'error',
                'APICode' => 'T-0001',
                'message' => 'Failed to retrieve download statistics'
            ];
        }
    }
    
    // For filtered results, query download_stats and download_stat tables
    try {
        // Build query with filters using new table structure
        $whereConditions = ['1=1'];
        $params = [];
        
        if ($filenameFilter) {
            $whereConditions[] = 'filename LIKE ?';
            $params[] = '%' . $filenameFilter . '%';
        }
        
        if ($folderFilter) {
            $whereConditions[] = 'folder LIKE ?';
            $params[] = $folderFilter . '%';
        }
        
        if ($startDate) {
            $whereConditions[] = 'download_time >= ?';
            $params[] = $startDate->format('Y-m-d H:i:s');
        }
        
        if ($endDate) {
            $whereConditions[] = 'download_time <= ?';
            $params[] = $endDate->format('Y-m-d H:i:s');
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Query individual downloads and group by folder/filename
        $stmt = $db->getConnection()->prepare("
            SELECT 
                filename,
                folder,
                COUNT(*) as download_count
            FROM download_stats 
            WHERE $whereClause
            GROUP BY folder, filename 
            ORDER BY download_count DESC 
            LIMIT ?
        ");
        
        $params[] = $limit;
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group results by folder
        $folderGroups = [];
        
        foreach ($results as $row) {
            $folder = $row['folder'] ?: '';
            $filename = $row['filename'];
            
            if (!isset($folderGroups[$folder])) {
                $folderGroups[$folder] = [
                    'folder' => $folder,
                    'downloadCount' => 0,
                    'timeStart' => $startDate ? $startDate->format('Y-m-d') : null,
                    'timeEnd' => $endDate ? $endDate->format('Y-m-d') : null,
                    'individualFiles' => []
                ];
            }
            
            $folderGroups[$folder]['individualFiles'][] = [
                'filename' => $filename,
                'downloadCount' => (int)$row['download_count']
            ];
            $folderGroups[$folder]['downloadCount'] += (int)$row['download_count'];
        }
        
        return array_values($folderGroups);
        
    } catch (Exception $e) {
        error_log("Download statistics error: " . $e->getMessage());
        return [
            'status' => 'error',
            'APICode' => 'T-0001',
            'message' => 'Failed to retrieve download statistics'
        ];
    }
}