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
$type = $input['type'] ?? null;
$productId = $input['product_id'] ?? null;
$details = $input['details'] ?? [];

if (!$type || !in_array($type, [
    'favorite',
    'notification_received',
    'notification_dismissed',
    'notification_buy_now',
    'notification_price_history',
    'notification_track',
    'notification_share',
    'notification_clicked',
    'buy_now'
])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid interaction type']);
    exit;
}

$userId = $_SESSION['user_id'];
$isFavorite = ($type === 'favorite' && isset($details['is_favorite'])) ? ($details['is_favorite'] ? 1 : 0) : 0;

try {
    $stmt = $pdo->prepare("
        INSERT INTO user_behavior (user_id, asin, is_favorite, interaction_type, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$userId, $productId, $isFavorite, $type]);

    file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Interaction logged: user_id=$userId, type=$type, product_id=$productId\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => 'Interaction logged']);
} catch (Exception $e) {
    file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Error logging interaction: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => 'Failed to log interaction']);
}
?>