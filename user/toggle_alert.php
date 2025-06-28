<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/globals.php';
require_once '../middleware/csrf.php';

startApplicationSession();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
$productId = $input['product_id'] ?? null;
$type = $input['type'] ?? null;
$enabled = filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN);

if (!$productId || !in_array($type, ['email', 'push'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

// 🔴 PUSH NOTIFICATION SERVICE TEMPORARILY DISABLED
if ($type === 'push') {
    echo json_encode([
        'status' => 'error', 
        'message' => 'Push notification service is temporarily unavailable. You can receive alerts via email instead.',
        'service_disabled' => true
    ]);
    exit;
}

$userId = $_SESSION['user_id'];
$column = $type === 'email' ? 'email_alert' : 'push_alert';

try {
    $pdo->beginTransaction();
    
    // Check current alert status BEFORE update
    $stmt = $pdo->prepare("SELECT email_alert, push_alert FROM user_products WHERE user_id = ? AND product_asin = ?");
    $stmt->execute([$userId, $productId]);
    $currentAlerts = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentAlerts) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Product not found in user favorites']);
        exit;
    }
    
    // Check if user had ANY alerts active before the change
    $wasTracking = ($currentAlerts['email_alert'] == 1 || $currentAlerts['push_alert'] == 1);
    
    // Update the specific alert type
    $stmt = $pdo->prepare("UPDATE user_products SET $column = ? WHERE user_id = ? AND product_asin = ?");
    $stmt->execute([$enabled ? 1 : 0, $userId, $productId]);
    
    if ($stmt->rowCount() > 0) {
        // Get NEW alert status AFTER update
        $stmt = $pdo->prepare("SELECT email_alert, push_alert FROM user_products WHERE user_id = ? AND product_asin = ?");
        $stmt->execute([$userId, $productId]);
        $newAlerts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if user has ANY alerts active after the change
        $isNowTracking = ($newAlerts['email_alert'] == 1 || $newAlerts['push_alert'] == 1);
        
        // Update tracking count in products table only if tracking status changed
        if ($wasTracking != $isNowTracking) {
            if ($isNowTracking && !$wasTracking) {
                // User started tracking (no alerts -> has alerts)
                $stmt = $pdo->prepare("UPDATE products SET tracking_count = tracking_count + 1 WHERE asin = ?");
                $stmt->execute([$productId]);
            } elseif (!$isNowTracking && $wasTracking) {
                // User stopped tracking (had alerts -> no alerts)
                $stmt = $pdo->prepare("UPDATE products SET tracking_count = GREATEST(0, tracking_count - 1) WHERE asin = ?");
                $stmt->execute([$productId]);
            }
        }
        
        $pdo->commit();
        
        file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] User ID $userId " . ($enabled ? 'enabled' : 'disabled') . " $type alert for product $productId (tracking status changed from $wasTracking to $isNowTracking)\n", FILE_APPEND);
        
        echo json_encode([
            'status' => 'success', 
            'message' => 'Alert updated',
            'tracking_status' => $isNowTracking
        ]);
    } else {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'No changes made']);
    }
    
} catch (Exception $e) {
    $pdo->rollBack();
    file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Error updating alert for user $userId, product $productId: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred']);
}
?>