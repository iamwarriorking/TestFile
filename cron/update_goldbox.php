<?php
require_once '../config/database.php';
require_once '../config/globals.php';

try {
    echo "[" . date('Y-m-d H:i:s') . "] Starting Goldbox update cron\n";
    
    // Include the goldbox scraper
    require_once '../api/amazon_goldbox.php';
    
    // Fetch and save goldbox deals
    $deals = fetchGoldboxDeals($amazonConfig, $pdo);
    
    if (!empty($deals)) {
        $logMessage = "[" . date('Y-m-d H:i:s') . "] Goldbox update cron executed successfully - " . count($deals) . " deals updated\n";
        echo $logMessage;
        file_put_contents('../logs/cron.log', $logMessage, FILE_APPEND);
    } else {
        $logMessage = "[" . date('Y-m-d H:i:s') . "] Goldbox update cron completed - No new deals found\n";
        echo $logMessage;
        file_put_contents('../logs/cron.log', $logMessage, FILE_APPEND);
    }
    
} catch (Exception $e) {
    $errorMessage = "[" . date('Y-m-d H:i:s') . "] Goldbox update cron failed: " . $e->getMessage() . "\n";
    echo $errorMessage;
    file_put_contents('../logs/cron.log', $errorMessage, FILE_APPEND);
    
    // Send error notification if needed
    error_log("Goldbox cron error: " . $e->getMessage());
}
?>