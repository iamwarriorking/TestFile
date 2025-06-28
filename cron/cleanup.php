<?php
require_once '../config/database.php';
require_once '../config/globals.php';

$pdo->beginTransaction();
try {
    // Delete products out of stock for 6 months
    $stmt = $pdo->prepare("DELETE FROM products WHERE stock_status = 'out_of_stock' AND out_of_stock_since < NOW() - INTERVAL 6 MONTH");
    $stmt->execute();
    $oosDeleted = $stmt->rowCount();

    // Delete products untracked for 12 months
    $stmt = $pdo->prepare("
        DELETE FROM products 
        WHERE asin NOT IN (SELECT product_asin FROM user_products) 
        AND last_updated < NOW() - INTERVAL 12 MONTH
    ");
    $stmt->execute();
    $untrackedDeleted = $stmt->rowCount();

    $pdo->commit();
    file_put_contents('../logs/cleanup.log', "[" . date('Y-m-d H:i:s') . "] Cleanup cron executed: Removed $oosDeleted OOS products and $untrackedDeleted untracked products\n", FILE_APPEND);
} catch (Exception $e) {
    $pdo->rollBack();
    file_put_contents('../logs/cleanup.log', "[" . date('Y-m-d H:i:s') . "] Cleanup cron failed: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>