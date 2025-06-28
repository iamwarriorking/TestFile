<?php
require_once '../config/database.php';
require_once '../middleware/csrf.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$subscription = $input['subscription'] ?? null;
$productId = isset($input['product_id']) ? filter_var($input['product_id'], FILTER_SANITIZE_STRING) : null;
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    echo json_encode(['status' => 'error', 'message' => 'User not authenticated']);
    exit;
}

if (!$subscription) {
    echo json_encode(['status' => 'error', 'message' => 'Subscription data is required']);
    exit;
}

try {
    $subscriptionData = json_decode(json_encode($subscription), true);
    if (!isset($subscriptionData['endpoint']) || !isset($subscriptionData['keys'])) {
        throw new Exception("Invalid subscription format");
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid subscription data']);
    exit;
}

try {
    if ($productId) {
        $stmt = $pdo->prepare("
            INSERT INTO push_subscriptions (user_id, subscription, product_asin)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE subscription = ?, product_asin = ?
        ");
        $stmt->execute([
            $userId,
            json_encode($subscription),
            $productId,
            json_encode($subscription),
            $productId
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO push_subscriptions (user_id, subscription)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE subscription = ?
        ");
        $stmt->execute([
            $userId,
            json_encode($subscription),
            json_encode($subscription)
        ]);
    }
    
    echo json_encode(['status' => 'success', 'message' => 'Subscription saved']);
} catch (PDOException $e) {
    file_put_contents('../logs/push_errors.log', "[" . date('Y-m-d H:i:s') . "] Subscribe error: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => 'Failed to save subscription']);
}
?>