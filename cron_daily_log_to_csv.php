<?php
/**
 * Daily App Log to CSV Cron
 *
 * Converts yesterday's app log into CSV format.
 *
 * Usage:
 *   php cron_daily_log_to_csv.php [--date=YYYY-MM-DD] [--force]
 */

if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

$logsDir = __DIR__ . '/logs';
$logFile = $logsDir . '/archive/cron/cron_csv_' . date('Y-m-d') . '.log';

function csv_cron_log($message) {
    global $logFile;
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    echo $entry;
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

function parse_csv_args($argv) {
    $options = [
        'date' => date('Y-m-d', strtotime('-1 day')),
        'force' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--force') {
            $options['force'] = true;
            continue;
        }

        if (strpos($arg, '--date=') === 0) {
            $candidate = substr($arg, 7);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate) === 1) {
                $options['date'] = $candidate;
            }
        }
    }

    return $options;
}

function parse_app_log_line($line) {
    $trimmed = trim($line);
    if ($trimmed === '') {
        return null;
    }

    $pattern = '/^\[([^\]]+)\] \[([^\]]+)\] \[([^\]]+)\] (.+)$/';
    if (preg_match($pattern, $trimmed, $matches) !== 1) {
        return [
            'timestamp' => '',
            'ip_address' => '',
            'user_id' => '',
            'action' => '',
            'path' => '',
            'raw_line' => $trimmed,
        ];
    }

    $message = $matches[4];
    $action = $message;
    $path = '';

    if (strpos($message, ' - ') !== false) {
        $parts = explode(' - ', $message, 2);
        $action = $parts[0];
        $path = $parts[1];
    }

    return [
        'timestamp' => $matches[1],
        'ip_address' => $matches[2],
        'user_id' => $matches[3],
        'action' => $action,
        'path' => $path,
        'raw_line' => $trimmed,
    ];
}

$options = parse_csv_args($argv);
$targetDate = $options['date'];

if (!is_dir($logsDir) && !@mkdir($logsDir, 0755, true)) {
    csv_cron_log('ERROR: Unable to create logs directory: ' . $logsDir);
    exit(1);
}

$inputFile = $logsDir . '/app-' . $targetDate . '.log';
$outputFile = $logsDir . '/app-' . $targetDate . '.csv';
$tempFile = $outputFile . '.tmp';

csv_cron_log('CSV conversion started for date: ' . $targetDate);

if (!file_exists($inputFile)) {
    csv_cron_log('INFO: Source log file does not exist, nothing to convert: app-' . $targetDate . '.log');
    exit(0);
}

if (file_exists($outputFile) && !$options['force']) {
    csv_cron_log('INFO: CSV already exists, skipping. Use --force to regenerate: app-' . $targetDate . '.csv');
    exit(0);
}

$inputHandle = @fopen($inputFile, 'r');
if ($inputHandle === false) {
    csv_cron_log('ERROR: Failed to open input log file: ' . $inputFile);
    exit(1);
}

$outputHandle = @fopen($tempFile, 'w');
if ($outputHandle === false) {
    fclose($inputHandle);
    csv_cron_log('ERROR: Failed to open temp CSV file: ' . $tempFile);
    exit(1);
}

fputcsv($outputHandle, ['timestamp', 'ip_address', 'user_id', 'action', 'path', 'raw_line']);

$parsedLines = 0;
$emptyLines = 0;

while (($line = fgets($inputHandle)) !== false) {
    $record = parse_app_log_line($line);
    if ($record === null) {
        $emptyLines++;
        continue;
    }

    fputcsv($outputHandle, [
        $record['timestamp'],
        $record['ip_address'],
        $record['user_id'],
        $record['action'],
        $record['path'],
        $record['raw_line'],
    ]);

    $parsedLines++;
}

fclose($inputHandle);
fclose($outputHandle);

if (!@rename($tempFile, $outputFile)) {
    @unlink($tempFile);
    csv_cron_log('ERROR: Failed to finalize CSV file: ' . $outputFile);
    exit(1);
}

csv_cron_log('CSV conversion finished. rows=' . $parsedLines . ', empty_lines=' . $emptyLines . ', output=app-' . $targetDate . '.csv');
exit(0);
