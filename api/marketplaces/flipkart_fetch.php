<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/flipkart.php';

$flipkartConfig = require __DIR__ . '/../../config/flipkart.php';

// Check if Flipkart API is active
if ($flipkartConfig['api_status'] !== 'active') {
    echo "Flipkart API is disabled in config\n";
    exit;
}

try {
    // Get total count of products that need updating
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE merchant = 'flipkart' AND (last_updated < NOW() - INTERVAL 24 HOUR OR last_updated IS NULL)");
    $countStmt->execute();
    $totalProducts = $countStmt->fetchColumn();
    
    if ($totalProducts == 0) {
        echo "No Flipkart products to update.\n";
        exit;
    }
    
    echo "Total Flipkart products to update: $totalProducts\n";
    
    $batchSize = 10; // Process in batches to avoid memory issues
    $totalBatches = ceil($totalProducts / $batchSize);
    $processedCount = 0;
    
    // Initialize Flipkart client once
    $client = initializeFlipkartClient();
    if (!$client) {
        throw new Exception('Failed to initialize Flipkart client');
    }
    
    // Process all products in batches
    for ($batch = 0; $batch < $totalBatches; $batch++) {
        $offset = $batch * $batchSize;
        
        echo "Processing batch " . ($batch + 1) . " of $totalBatches (offset: $offset)\n";
        
        // Get product IDs for current batch
        $stmt = $pdo->prepare("SELECT asin FROM products WHERE merchant = 'flipkart' AND (last_updated < NOW() - INTERVAL 24 HOUR OR last_updated IS NULL) LIMIT $batchSize OFFSET $offset");
        $stmt->execute();
        $productIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($productIds)) {
            echo "No more product IDs found in this batch.\n";
            break;
        }

        // Process each product in the batch
        foreach ($productIds as $productId) {
            try {
                $productData = $client->getProductDetails($productId);
                
                if (!$productData || !isset($productData['productId'])) {
                    echo "No data received for Flipkart product ID: $productId\n";
                    continue;
                }

                // Extract product data
                $title = $productData['productName'] ?? '';
                $price = isset($productData['price']) ? (float)$productData['price'] : 0;
                $imageUrl = $productData['imageUrls']['large'] ?? $productData['imageUrls']['medium'] ?? $productData['imageUrls']['small'] ?? '';
                $availability = $productData['availability'] ?? '';
                $rating = isset($productData['rating']) ? (float)$productData['rating'] : null;
                $reviewCount = isset($productData['reviewCount']) ? (int)$productData['reviewCount'] : null;
                $affiliateUrl = $productData['productUrl'] ?? '';

                // Update database
                $stmt = $pdo->prepare("
                    UPDATE products SET 
                        name = ?, 
                        current_price = ?, 
                        affiliate_link = ?, 
                        image_path = ?,
                        availability = ?,
                        rating = ?,
                        review_count = ?,
                        last_updated = NOW() 
                    WHERE asin = ? AND merchant = 'flipkart'
                ");

                $stmt->execute([
                    $title,
                    $price,
                    $affiliateUrl,
                    $imageUrl,
                    $availability,
                    $rating,
                    $reviewCount,
                    $productId
                ]);

                $processedCount++;
                echo "Updated Flipkart product: $productId - $title (Progress: $processedCount/$totalProducts)\n";

                // Small delay between individual product requests
                usleep(100000); // 0.1 second delay

            } catch (Exception $e) {
                echo "Error processing Flipkart product $productId: " . $e->getMessage() . "\n";
                continue;
            }
        }
        
        echo "Batch " . ($batch + 1) . " completed. Processed " . count($productIds) . " products in this batch.\n";
        
        // Rate limiting between batches
        if ($batch < $totalBatches - 1) {
            echo "Waiting 1 second before next batch...\n";
            sleep(1);
        }
    }

    echo "Flipkart update completed successfully. Total processed: $processedCount products.\n";

} catch (Exception $e) {
    echo "Flipkart processing error: " . $e->getMessage() . "\n";
}
?>
