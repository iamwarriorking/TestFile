<?php
require_once '../config/database.php';
require_once '../config/globals.php';

$pdo->beginTransaction();
try {
    // Clean up old price history (keep only last 24 months)
    $cutoffDate = date('Y-m-d', strtotime('-24 months'));
    
    $stmt = $pdo->prepare("
        DELETE FROM price_history 
        WHERE date_recorded < ?
    ");
    $stmt->execute([$cutoffDate]);
    
    $deletedRows = $stmt->rowCount();

    // Optional: Remove JSON price_history column from products table
    // Uncomment this line once to completely remove the old column
    // $pdo->exec("ALTER TABLE products DROP COLUMN price_history");

    $pdo->commit();
    
    file_put_contents('../logs/history_cleanup.log', 
        "[" . date('Y-m-d H:i:s') . "] History cleanup completed. Deleted $deletedRows old records from price_history table\n", 
        FILE_APPEND
    );
    
} catch (Exception $e) {
    $pdo->rollBack();
    file_put_contents('../logs/history_cleanup.log', 
        "[" . date('Y-m-d H:i:s') . "] History cleanup failed: " . $e->getMessage() . "\n", 
        FILE_APPEND
    );
}
?>