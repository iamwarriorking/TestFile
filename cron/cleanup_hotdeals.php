<?php
require_once '../config/database.php';
require_once '../config/globals.php';

$pdo->beginTransaction();
try {
    // Clean up inactive records older than 30 days
    $stmt = $pdo->prepare("
        UPDATE hotdealsbot 
        SET is_active = FALSE 
        WHERE last_interaction < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $updatedCount = $stmt->rowCount();

    $pdo->commit();
    file_put_contents(
        '../logs/cleanup.log', 
        "[" . date('Y-m-d H:i:s') . "] HotDeals cleanup: Deactivated $updatedCount records\n", 
        FILE_APPEND
    );
} catch (Exception $e) {
    $pdo->rollBack();
    file_put_contents(
        '../logs/cleanup.log', 
        "[" . date('Y-m-d H:i:s') . "] HotDeals cleanup failed: " . $e->getMessage() . "\n", 
        FILE_APPEND
    );
}
?>