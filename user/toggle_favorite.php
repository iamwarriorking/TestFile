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
$isFavorite = filter_var($input['is_favorite'], FILTER_VALIDATE_BOOLEAN);

if (!$productId) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid product ID']);
    exit;
}

$userId = $_SESSION['user_id'];

if ($isFavorite) {
    // Check favorite limit
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE user_id = ? AND is_favorite = 1");
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() >= 200) {
        echo json_encode(['status' => 'error', 'message' => 'You can only add up to 200 products to your favorites']);
        exit;
    }
}

// 🔥 FIX: Get both affiliate_link and merchant to create proper URLs
$stmt = $pdo->prepare("SELECT asin, affiliate_link, merchant FROM products WHERE asin = ?");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo json_encode(['status' => 'error', 'message' => 'Product not found in database']);
    exit;
}

$pdo->beginTransaction();

try {
    // Check if user_products entry exists
    $stmt = $pdo->prepare("SELECT user_id, email_alert, push_alert FROM user_products WHERE user_id = ? AND product_asin = ?");
    $stmt->execute([$userId, $productId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($isFavorite) {
        // Adding to favorites
        if ($existing) {
            // Update existing entry with complete data
            $stmt = $pdo->prepare("
                UPDATE user_products 
                SET is_favorite = 1, 
                    product_url = ?, 
                    price_history_url = ?,
                    updated_at = NOW()
                WHERE user_id = ? AND product_asin = ?
            ");
            $stmt->execute([
                $product['affiliate_link'],  // ✅ FIX: Amazon actual URL
                "https://amezprice.com/product/" . $product['merchant'] . "/pid=" . $productId,  // ✅ FIX: Complete price history URL
                $userId, 
                $productId
            ]);
        } else {
            // Insert new entry with complete data
            $stmt = $pdo->prepare("
                INSERT INTO user_products 
                (user_id, product_asin, product_url, price_history_url, is_favorite, email_alert, push_alert, created_at, updated_at) 
                VALUES (?, ?, ?, ?, 1, 0, 0, NOW(), NOW())
            ");
            $stmt->execute([
                $userId, 
                $productId, 
                $product['affiliate_link'],  // ✅ FIX: Amazon actual URL
                "https://amezprice.com/product/" . $product['merchant'] . "/pid=" . $productId  // ✅ FIX: Complete price history URL
            ]);
        }
        $action = 'added';
    } else {
        // Removing from favorites
        if ($existing) {
            // Check if alerts are active
            if ($existing['email_alert'] || $existing['push_alert']) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Cannot remove from favorites while alerts are active. Please disable alerts first.']);
                exit;
            }
            
            // ✅ FIX: Completely delete the record instead of just updating is_favorite
            $stmt = $pdo->prepare("DELETE FROM user_products WHERE user_id = ? AND product_asin = ?");
            $stmt->execute([$userId, $productId]);
            
            // Update tracking count in products table
            $stmt = $pdo->prepare("
                UPDATE products 
                SET tracking_count = (SELECT COUNT(*) FROM user_products WHERE product_asin = ?) 
                WHERE asin = ?
            ");
            $stmt->execute([$productId, $productId]);
        }
        $action = 'removed';
    }

    $pdo->commit();

    // Log user behavior
    $stmt = $pdo->prepare("INSERT INTO user_behavior (user_id, asin, is_favorite, interaction_type) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $productId, $isFavorite ? 1 : 0, 'favorite']);

    file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] User ID $userId $action product $productId to/from favorites\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => 'Favorite updated']);

} catch (Exception $e) {
    $pdo->rollBack();
    file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Error updating favorite for user $userId, product $productId: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => 'Failed to update favorite']);
}
?>