<?php
/**
 * Daily Downloads API Module
 * Provides download statistics for the last day
 */

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/database.php';

function handleDailyDownloadsApi($method, $pathParts) {
    if ($method !== 'GET') {
        return [
            'error' => 'Method not allowed',
            'allowed_methods' => ['GET']
        ];
    }
    
    try {
        $db = Database::getInstance();
        
        // Get yesterday's date (or specific date if provided)
        $targetDate = isset($pathParts[0]) ? $pathParts[0] : date('Y-m-d', strtotime('-1 day'));
        
        // Validate date format
        if (!DateTime::createFromFormat('Y-m-d', $targetDate)) {
            return [
                'error' => 'Invalid date format. Use YYYY-MM-DD',
                'provided_date' => $targetDate
            ];
        }
        
        // Query download statistics for the specific date
        $stmt = $db->getConnection()->prepare('
            SELECT 
                CASE 
                    WHEN folder = "" OR folder IS NULL THEN filename
                    ELSE folder || "/" || filename
                END as file_path,
                COUNT(*) as downloads
            FROM download_stats 
            WHERE DATE(download_time) = ?
            GROUP BY file_path
            ORDER BY downloads DESC, file_path ASC
        ');
        
        $stmt->execute([$targetDate]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the response
        $individual_files = [];
        $total_downloads = 0;
        
        foreach ($results as $row) {
            $individual_files[] = [
                'filename' => $row['file_path'],
                'downloads' => (int)$row['downloads']
            ];
            $total_downloads += (int)$row['downloads'];
        }
        
        // Get additional statistics
        $stmt = $db->getConnection()->prepare('
            SELECT 
                COUNT(DISTINCT CASE 
                    WHEN folder = "" OR folder IS NULL THEN filename
                    ELSE folder || "/" || filename
                END) as unique_files,
                COUNT(*) as total_download_events
            FROM download_stats 
            WHERE DATE(download_time) = ?
        ');
        
        $stmt->execute([$targetDate]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'fordate' => $targetDate,
            'summary' => [
                'total_downloads' => $total_downloads,
                'unique_files_downloaded' => (int)$summary['unique_files'],
                'total_download_events' => (int)$summary['total_download_events']
            ],
            'individual_files' => $individual_files
        ];
        
    } catch (Exception $e) {
        error_log('Daily Downloads API Error: ' . $e->getMessage());
        return [
            'error' => 'Internal server error',
            'message' => 'Failed to retrieve download statistics'
        ];
    }
}

function handleDailyDownloadsSummaryApi($method, $pathParts) {
    if ($method !== 'GET') {
        return [
            'error' => 'Method not allowed', 
            'allowed_methods' => ['GET']
        ];
    }
    
    try {
        $db = Database::getInstance();
        
        // Get last 7 days of download summaries
        $days = isset($pathParts[0]) && is_numeric($pathParts[0]) ? (int)$pathParts[0] : 7;
        $days = max(1, min($days, 30)); // Limit between 1 and 30 days
        
        $stmt = $db->getConnection()->prepare('
            SELECT 
                DATE(download_time) as date,
                COUNT(DISTINCT CASE 
                    WHEN folder = "" OR folder IS NULL THEN filename
                    ELSE folder || "/" || filename
                END) as unique_files,
                COUNT(*) as total_downloads
            FROM download_stats 
            WHERE DATE(download_time) >= DATE("now", "-' . $days . ' days")
            GROUP BY DATE(download_time)
            ORDER BY date DESC
        ');
        
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format results
        $daily_summary = [];
        foreach ($results as $row) {
            $daily_summary[] = [
                'date' => $row['date'],
                'unique_files' => (int)$row['unique_files'],
                'total_downloads' => (int)$row['total_downloads']
            ];
        }
        
        return [
            'period' => $days . ' days',
            'daily_summary' => $daily_summary
        ];
        
    } catch (Exception $e) {
        error_log('Daily Downloads Summary API Error: ' . $e->getMessage());
        return [
            'error' => 'Internal server error',
            'message' => 'Failed to retrieve download summary'
        ];
    }
}