<?php
require_once '../config/globals.php';

$logDir = '../logs/';
$files = glob($logDir . '*.log');

try {
    $deletedCount = 0;
    foreach ($files as $file) {
        if (filemtime($file) < strtotime('-3 months')) {
            if (unlink($file)) {
                $deletedCount++;
                file_put_contents('../logs/cleanup.log', "[" . date('Y-m-d H:i:s') . "] Deleted old log: $file\n", FILE_APPEND);
            } else {
                throw new Exception("Failed to delete log: $file");
            }
        }
    }
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Log cleanup cron executed: Deleted $deletedCount logs\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Log cleanup cron failed: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>