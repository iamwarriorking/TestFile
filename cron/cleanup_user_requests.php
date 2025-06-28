<?php
require_once '../config/database.php';

try {
    // Keep only last 24 hours for rate limiting
    $stmt = $pdo->prepare("DELETE FROM user_requests WHERE created_at < NOW() - INTERVAL 24 HOUR");
    $stmt->execute();
    $deletedCount = $stmt->rowCount();

    file_put_contents('../logs/cleanup.log', 
        "[" . date('Y-m-d H:i:s') . "] Deleted $deletedCount old user requests (older than 24h)\n", 
        FILE_APPEND
    );
} catch (Exception $e) {
    file_put_contents('../logs/cleanup.log', 
        "[" . date('Y-m-d H:i:s') . "] User requests cleanup failed: " . $e->getMessage() . "\n", 
        FILE_APPEND
    );
}
?>