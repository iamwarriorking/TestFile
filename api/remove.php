<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once '../middleware/csrf.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['user_id'] ?? null;
$asin = $input['asin'] ?? '';
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

if ($apiKey !== $telegramConfig['api_key']) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid API key']);
    exit;
}

if (!$userId || !$asin) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

// Begin transaction to ensure both operations succeed
$pdo->beginTransaction();

try {
    // Remove from user_products
    $stmt = $pdo->prepare("DELETE FROM user_products WHERE user_id = ? AND product_asin = ?");
    $result = $stmt->execute([$userId, $asin]);

    if ($stmt->rowCount() > 0) {
        // ✅ FIX: Update tracking count in products table after removal
        $stmt = $pdo->prepare("
            UPDATE products 
            SET tracking_count = (SELECT COUNT(*) FROM user_products WHERE product_asin = ?) 
            WHERE asin = ?
        ");
        $stmt->execute([$asin, $asin]);
        
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Product removed']);
    } else {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Product not found']);
    }
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Failed to remove product']);
}
?>