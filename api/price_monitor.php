<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once 'marketplaces/amazon.php';
require_once 'marketplaces/flipkart.php';

// Define constant for repeated query
const USER_TRACKER_QUERY = "SELECT user_id FROM user_products WHERE product_asin = ?";

// Helper function to send notifications
function sendNotification($userIds, $asin, $messageType, $details, $telegramConfig) {
    $payload = [
        'user_ids' => $userIds,
        'asin' => $asin,
        'message_type' => $messageType,
        'details' => $details
    ];

    $ch = curl_init('https://amezprice.com/api/notify.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $telegramConfig['api_key']]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// Get products grouped by merchant
$stmt = $pdo->query("SELECT * FROM products ORDER BY merchant, last_updated ASC");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group products by merchant
$productsByMerchant = [];
foreach ($products as $product) {
    $productsByMerchant[$product['merchant']][] = $product;
}

// Process Amazon products in batches of 10
if (isset($productsByMerchant['amazon'])) {
    $amazonProducts = $productsByMerchant['amazon'];
    $batches = array_chunk($amazonProducts, 10);
    
    foreach ($batches as $batch) {
        $asins = array_column($batch, 'asin');
        echo "Processing Amazon batch: " . implode(', ', $asins) . "\n";
        
        // Use batch fetch function
        $batchResults = fetchAmazonProductsBatch($asins);
        
        if ($batchResults['status'] === 'success') {
            foreach ($batch as $product) {
                $asin = $product['asin'];
                
                if (isset($batchResults['products'][$asin])) {
                    $result = $batchResults['products'][$asin];
                    
                    // Update product details
                    $stmt = $pdo->prepare("
                        UPDATE products 
                        SET 
                            current_price = ?,
                            highest_price = GREATEST(highest_price, ?),
                            lowest_price = LEAST(lowest_price, ?),
                            stock_status = ?,
                            stock_quantity = ?,
                            rating = ?,
                            rating_count = ?,
                            last_updated = NOW()
                        WHERE asin = ?
                    ");
                    $stmt->execute([
                        $result['current_price'],
                        $result['current_price'],
                        $result['current_price'] > 0 ? $result['current_price'] : $product['current_price'],
                        $result['stock_status'],
                        $result['stock_quantity'],
                        $result['rating'],
                        $result['rating_count'],
                        $product['asin']
                    ]);

                    // Add to price_history table
                    $priceHistoryStmt = $pdo->prepare("
                        INSERT INTO price_history (product_asin, price, date_recorded) 
                        VALUES (?, ?, ?) 
                        ON DUPLICATE KEY UPDATE price = VALUES(price)
                    ");
                    $priceHistoryStmt->execute([$product['asin'], $result['current_price'], date('Y-m-d')]);

                    // Check for price change
                    if ($result['current_price'] != $product['current_price']) {
                        $previousPrice = $product['current_price'];
                        $currentPrice = $result['current_price'];
                        $trackerStmt = $pdo->prepare(USER_TRACKER_QUERY);
                        $trackerStmt->execute([$product['asin']]);
                        $userIds = $trackerStmt->fetchAll(PDO::FETCH_COLUMN);

                        $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
                        $trackerCountStmt->execute([$product['asin']]);
                        $trackerCount = $trackerCountStmt->fetchColumn();

                        $details = [
                            'previous_price' => $previousPrice,
                            'current_price' => $currentPrice,
                            'tracker_count' => $trackerCount
                        ];

                        sendNotification(
                            $userIds, 
                            $product['asin'], 
                            $currentPrice < $previousPrice ? 'price_drop' : 'price_increase', 
                            $details, 
                            $telegramConfig
                        );

                        // Check for price threshold alerts
                        $thresholdStmt = $pdo->prepare("
                            SELECT user_id, price_threshold 
                            FROM user_products 
                            WHERE product_asin = ? AND price_threshold IS NOT NULL AND price_threshold >= ?
                        ");
                        $thresholdStmt->execute([$product['asin'], $currentPrice]);
                        $thresholdUsers = $thresholdStmt->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($thresholdUsers as $user) {
                            sendNotification(
                                [$user['user_id']], 
                                $product['asin'], 
                                'price_drop', 
                                $details, 
                                $telegramConfig
                            );

                            // Clear threshold
                            $clearStmt = $pdo->prepare("UPDATE user_products SET price_threshold = NULL WHERE user_id = ? AND product_asin = ?");
                            $clearStmt->execute([$user['user_id'], $product['asin']]);
                        }
                    }

                    // Check stock status changes
                    if ($result['stock_status'] === 'out_of_stock' && $product['stock_status'] === 'in_stock') {
                        $trackerStmt = $pdo->prepare(USER_TRACKER_QUERY);
                        $trackerStmt->execute([$product['asin']]);
                        $userIds = $trackerStmt->fetchAll(PDO::FETCH_COLUMN);

                        $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
                        $trackerCountStmt->execute([$product['asin']]);
                        $trackerCount = $trackerCountStmt->fetchColumn();

                        $details = [
                            'previous_price' => $product['current_price'],
                            'current_price' => $result['current_price'],
                            'tracker_count' => $trackerCount
                        ];

                        sendNotification($userIds, $product['asin'], 'out_of_stock', $details, $telegramConfig);

                        $stmt = $pdo->prepare("UPDATE products SET out_of_stock_since = NOW() WHERE asin = ?");
                        $stmt->execute([$product['asin']]);
                    } elseif ($result['stock_status'] === 'in_stock' && $product['stock_status'] === 'out_of_stock') {
                        $trackerStmt = $pdo->prepare(USER_TRACKER_QUERY);
                        $trackerStmt->execute([$product['asin']]);
                        $userIds = $trackerStmt->fetchAll(PDO::FETCH_COLUMN);

                        $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
                        $trackerCountStmt->execute([$product['asin']]);
                        $trackerCount = $trackerCountStmt->fetchColumn();

                        $details = [
                            'previous_price' => $product['current_price'],
                            'current_price' => $result['current_price'],
                            'tracker_count' => $trackerCount
                        ];

                        sendNotification($userIds, $product['asin'], 'in_stock', $details, $telegramConfig);

                        $stmt = $pdo->prepare("UPDATE products SET out_of_stock_since = NULL WHERE asin = ?");
                        $stmt->execute([$product['asin']]);
                    } elseif ($result['stock_quantity'] <= 7 && $product['stock_quantity'] > 7) {
                        $trackerStmt = $pdo->prepare(USER_TRACKER_QUERY);
                        $trackerStmt->execute([$product['asin']]);
                        $userIds = $trackerStmt->fetchAll(PDO::FETCH_COLUMN);

                        $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
                        $trackerCountStmt->execute([$product['asin']]);
                        $trackerCount = $trackerCountStmt->fetchColumn();

                        $details = [
                            'quantity' => $result['stock_quantity'],
                            'previous_price' => $product['current_price'],
                            'current_price' => $result['current_price'],
                            'tracker_count' => $trackerCount
                        ];

                        sendNotification($userIds, $product['asin'], 'low_stock', $details, $telegramConfig);
                    }
                } else {
                    echo "Failed to get data for ASIN: " . $asin . "\n";
                }
            }
        } else {
            echo "Batch processing failed: " . ($batchResults['message'] ?? 'Unknown error') . "\n";
        }
        
        // Rate limiting: Wait 1 second between batches
        sleep(1);
        echo "Waiting 1 second before next batch...\n";
    }
}

// Process Flipkart products individually (if any)
if (isset($productsByMerchant['flipkart'])) {
    $flipkartProducts = $productsByMerchant['flipkart'];
    
    foreach ($flipkartProducts as $product) {
        $result = fetchFlipkartProduct($product['asin']);
        
        if ($result['status'] === 'success') {
            // Same processing logic as above for Flipkart
            // Update product details
            $stmt = $pdo->prepare("
                UPDATE products 
                SET 
                    current_price = ?,
                    highest_price = GREATEST(highest_price, ?),
                    lowest_price = LEAST(lowest_price, ?),
                    stock_status = ?,
                    stock_quantity = ?,
                    rating = ?,
                    rating_count = ?,
                    last_updated = NOW()
                WHERE asin = ?
            ");
            $stmt->execute([
                $result['current_price'],
                $result['current_price'],
                $result['current_price'],
                $result['stock_status'],
                $result['stock_quantity'],
                $result['rating'],
                $result['rating_count'],
                $product['asin']
            ]);

            $priceHistoryStmt = $pdo->prepare("
                INSERT INTO price_history (product_asin, price, date_recorded) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE price = VALUES(price)
            ");
            $priceHistoryStmt->execute([$product['asin'], $result['current_price'], date('Y-m-d')]);
            
            // Add same notification logic here for Flipkart if needed
        }
        
        // Add delay for Flipkart too
        sleep(1);
    }
}

echo "Price monitoring completed.\n";
?>