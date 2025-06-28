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

if (!$productId) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid product ID']);
    exit;
}

$userId = $_SESSION['user_id'];

// Begin transaction to ensure both operations succeed
$pdo->beginTransaction();

try {
    // Remove from user_products
    $stmt = $pdo->prepare("DELETE FROM user_products WHERE user_id = ? AND product_asin = ?");
    $result = $stmt->execute([$userId, $productId]);

    if ($stmt->rowCount() > 0) {
        // ✅ FIX: Update tracking count in products table after removal
        $stmt = $pdo->prepare("
            UPDATE products 
            SET tracking_count = (SELECT COUNT(*) FROM user_products WHERE product_asin = ?) 
            WHERE asin = ?
        ");
        $stmt->execute([$productId, $productId]);
        
        $pdo->commit();
        file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] User ID $userId removed product $productId\n", FILE_APPEND);
        echo json_encode(['status' => 'success', 'message' => 'Product removed']);
    } else {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Product not found']);
    }
    
} catch (Exception $e) {
    $pdo->rollBack();
    file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Error removing product $productId for user $userId: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => 'Failed to remove product']);
}
?>