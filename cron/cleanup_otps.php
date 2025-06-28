<?php
require_once __DIR__ . '/../config/database.php';

try {
    // Delete expired OTPs
    $stmt = $pdo->prepare("DELETE FROM otps WHERE expires_at < NOW()");
    $stmt->execute();
    
    $deletedCount = $stmt->rowCount();
    file_put_contents(__DIR__ . '/../logs/cleanup.log', 
        "[" . date('Y-m-d H:i:s') . "] Deleted $deletedCount expired OTPs\n", 
        FILE_APPEND);
        
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/../logs/cleanup.log', 
        "[" . date('Y-m-d H:i:s') . "] Error cleaning up OTPs: " . $e->getMessage() . "\n", 
        FILE_APPEND);
}