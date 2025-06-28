<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/flipkart.php';
require_once __DIR__ . '/../../config/globals.php';

// Add logError function similar to Amazon API
function logFlipkartError($message, Exception $e) {
    $logEntry = sprintf(
        "[%s] %s: %s\nStack trace:\n%s\n",
        date('Y-m-d H:i:s'),
        $message,
        $e->getMessage(),
        $e->getTraceAsString()
    );
    
    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logDir . '/flipkart_errors.log', $logEntry, FILE_APPEND);
}

function fetchFlipkartProduct($id) {
    global $pdo, $flipkartConfig;

    if ($flipkartConfig['api_status'] !== 'active') {
        return ['status' => 'error', 'message' => 'Flipkart API is temporarily unavailable'];
    }

    try {
        $url = "https://affiliate-api.flipkart.net/products/{$id}";
        $headers = [
            "Fk-Affiliate-Id: {$flipkartConfig['affiliate_id']}",
            "Fk-Affiliate-Token: {$flipkartConfig['token']}"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $price = (float)($data['productBaseInfo']['productAttributes']['sellingPrice']['amount'] ?? 0);
            $imageUrl = (string)($data['productBaseInfo']['productAttributes']['imageUrls']['400x400'] ?? '');
            $stockStatus = $data['productBaseInfo']['productAttributes']['inStock'] ? 'in_stock' : 'out_of_stock';
            $stockQuantity = $stockStatus === 'in_stock' ? (int)($data['productBaseInfo']['productAttributes']['maximumPurchaseQuantity'] ?? 10) : 0;
            $rating = (float)($data['productBaseInfo']['productAttributes']['rating'] ?? 0);
            $ratingCount = (int)($data['productBaseInfo']['productAttributes']['ratingCount'] ?? 0);

            // Download and convert image to WebP
            $imagePath = "/assets/images/products/{$id}.webp";
            $fullImagePath = $_SERVER['DOCUMENT_ROOT'] . $imagePath;
            
            // Create directory if it doesn't exist
            $directory = dirname($fullImagePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            
            // Create temp directory if it doesn't exist
            $tempPath = $_SERVER['DOCUMENT_ROOT'] . "/assets/images/products/temp/{$id}.jpg";
            $tempDirectory = dirname($tempPath);
            if (!is_dir($tempDirectory)) {
                mkdir($tempDirectory, 0755, true);
            }
            
            if ($imageUrl && !file_exists($fullImagePath)) {
                file_put_contents($tempPath, file_get_contents($imageUrl));
                $image = imagecreatefromjpeg($tempPath);
                imagewebp($image, $fullImagePath, 75);
                imagedestroy($image);
                unlink($tempPath); // Clean up temp file
                
                $logDir = __DIR__ . '/../../logs';
                if (!is_dir($logDir)) {
                    mkdir($logDir, 0755, true);
                }
                file_put_contents($logDir . '/images.log', "[" . date('Y-m-d H:i:s') . "] Cached image for ID $id\n", FILE_APPEND);
            }

            return [
                'status' => 'success',
                'name' => (string)$data['productBaseInfo']['productAttributes']['title'],
                'current_price' => $price,
                'affiliate_link' => (string)$data['productBaseInfo']['productAttributes']['productUrl'],
                'image_path' => $imagePath,
                'stock_status' => $stockStatus,
                'stock_quantity' => $stockQuantity,
                'rating' => $rating,
                'rating_count' => $ratingCount
            ];
        } else {
            throw new Exception("HTTP $httpCode");
        }
    } catch (Exception $e) {
        logFlipkartError("Error fetching ID $id", $e);
        return ['status' => 'error', 'message' => 'Failed to fetch product data'];
    }
}
?>