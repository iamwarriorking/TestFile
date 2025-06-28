<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../middleware/csrf.php';

startApplicationSession();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
$productId = $input['product_id'] ?? null;

if (!$productId) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid product ID']);
    exit;
}

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT email_alert, push_alert FROM user_products WHERE user_id = ? AND product_asin = ?");
$stmt->execute([$userId, $productId]);
$alerts = $stmt->fetch(PDO::FETCH_ASSOC);

if ($alerts) {
    $alertsActive = $alerts['email_alert'] || $alerts['push_alert'];
    echo json_encode(['status' => 'success', 'alerts_active' => $alertsActive]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Product not found']);
}
?>