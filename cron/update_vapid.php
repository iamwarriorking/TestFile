<?php
require_once '../config/database.php';
require_once '../config/globals.php';
require_once '../push_notification/web-push.php';

// Check if we need new keys (based on age)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM vapid_keys WHERE created_at > NOW() - INTERVAL 15 DAY");
$stmt->execute();
if ($stmt->fetchColumn() > 0) {
    // Skip if recent keys exist
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] VAPID keys are recent, skipping update\n", FILE_APPEND);
    exit;
}

$pdo->beginTransaction();
try {
    // Generate new VAPID keys
    $vapid = WebPushService::generateVapidKeys();
    
    // Keep old keys for a transition period (don't delete immediately)
    // Only delete keys older than 2 months
    $stmt = $pdo->prepare("DELETE FROM vapid_keys WHERE created_at < NOW() - INTERVAL 2 MONTH");
    $stmt->execute();
    $deletedCount = $stmt->rowCount();

    $pdo->commit();
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] VAPID keys updated: Deleted $deletedCount old keys\n", FILE_APPEND);
} catch (Exception $e) {
    $pdo->rollBack();
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] VAPID update failed: " . $e->getMessage() . "\n", FILE_APPEND);
}

// Run cleanup for stale subscriptions while we're at it
try {
    $cleanup = WebPushService::cleanupStaleSubscriptions();
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Stale subscription cleanup: " . ($cleanup['status'] === 'success' ? "{$cleanup['deleted_count']} removed" : $cleanup['message']) . "\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Subscription cleanup failed: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>