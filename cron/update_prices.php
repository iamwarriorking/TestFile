<?php
require_once '../config/database.php';
require_once '../config/globals.php';

// Initialize logging
$logFile = '../logs/cron.log';
$logMessage = "[" . date('Y-m-d H:i:s') . "] Price update started\n";

try {
    // Process Amazon if config exists
    if (file_exists('../config/amazon.php')) {
        $amazonConfig = require '../config/amazon.php';
        if ($amazonConfig['api_status'] === 'active') {
            $logMessage .= "Processing Amazon products...\n";
            require_once '../api/marketplaces/amazon_fetch.php';
            $logMessage .= "Amazon products processed successfully\n";
        } else {
            $logMessage .= "Amazon API is disabled in config\n";
        }
    }

    // Process Flipkart if config exists
    if (file_exists('../config/flipkart.php')) {
        $flipkartConfig = require '../config/flipkart.php';
        if ($flipkartConfig['api_status'] === 'active') {
            $logMessage .= "Processing Flipkart products...\n";
            require_once '../api/marketplaces/flipkart_fetch.php';
            $logMessage .= "Flipkart products processed successfully\n";
        } else {
            $logMessage .= "Flipkart API is disabled in config\n";
        }
    }

    // Run price monitoring alerts
    require_once '../api/price_monitor.php';
    $logMessage .= "Price monitoring completed\n";

    $logMessage .= "Price update completed successfully\n";
} catch (Exception $e) {
    $logMessage .= "Error: " . $e->getMessage() . "\n";
} finally {
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}
?>