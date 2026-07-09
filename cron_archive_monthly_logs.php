<?php
/**
 * Monthly Log Archive Cron
 *
 * Moves all previous-month log/csv files from logs/ into logs/archive/YYYY-MM/.
 *
 * Usage:
 *   php cron_archive_monthly_logs.php [--month=YYYY-MM]
 */

if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

$logsDir = __DIR__ . '/logs';
$archiveBaseDir = $logsDir . '/archive';
$logFile = $logsDir . '/archive/cron/cron_archive_' . date('Y-m-d') . '.log';

function archive_log($message) {
    global $logFile;
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    echo $entry;
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

function parse_archive_args($argv) {
    $month = null;

    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '--month=') === 0) {
            $candidate = substr($arg, 8);
            if (preg_match('/^\d{4}-\d{2}$/', $candidate) === 1) {
                $month = $candidate;
            }
        }
    }

    return $month;
}

$targetMonth = parse_archive_args($argv);
if ($targetMonth === null) {
    $targetMonth = date('Y-m', strtotime('first day of last month'));
}

archive_log('Monthly archive started for month: ' . $targetMonth);

if (!is_dir($logsDir)) {
    archive_log('ERROR: Logs directory not found: ' . $logsDir);
    exit(1);
}

if (!is_dir($archiveBaseDir) && !@mkdir($archiveBaseDir, 0755, true)) {
    archive_log('ERROR: Failed to create archive base directory: ' . $archiveBaseDir);
    exit(1);
}

$targetArchiveDir = $archiveBaseDir . '/' . $targetMonth;
if (!is_dir($targetArchiveDir) && !@mkdir($targetArchiveDir, 0755, true)) {
    archive_log('ERROR: Failed to create target archive directory: ' . $targetArchiveDir);
    exit(1);
}

$monthPattern = '/^.+'. preg_quote($targetMonth, '/') . '-\d{2}\.(log|csv)$/i';

$moved = 0;
$skipped = 0;
$errors = 0;

foreach (new DirectoryIterator($logsDir) as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }

    $filename = $fileInfo->getFilename();
    if (preg_match($monthPattern, $filename) !== 1) {
        continue;
    }

    $sourcePath = $fileInfo->getPathname();
    $destinationPath = $targetArchiveDir . '/' . $filename;

    if (file_exists($destinationPath)) {
        archive_log('SKIP: Destination already exists: ' . $filename);
        $skipped++;
        continue;
    }

    if (@rename($sourcePath, $destinationPath)) {
        archive_log('ARCHIVED: ' . $filename . ' -> archive/' . $targetMonth . '/' . $filename);
        $moved++;
    } else {
        archive_log('ERROR: Failed to archive file: ' . $filename);
        $errors++;
    }
}

archive_log('Monthly archive finished. moved=' . $moved . ', skipped=' . $skipped . ', errors=' . $errors);
exit($errors > 0 ? 1 : 0);
