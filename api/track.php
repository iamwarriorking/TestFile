<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/telegram.php';
require_once __DIR__ . '/marketplaces/amazon.php';
require_once __DIR__ . '/marketplaces/flipkart.php';

header('Content-Type: application/json');

function logTrackError($message, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/track_api.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logData = array_merge([
            'timestamp' => $timestamp,
            'message' => $message
        ], $data);
        
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log("Track API logging error: " . $e->getMessage());
    }
}

function resolveAmazonShortUrl($url) {
    // Improved regex patterns with case insensitive matching
    $shortUrlPatterns = [
        '/amzn\.(in|to|com)\/d\/([a-zA-Z0-9]+)/i',
        '/amzn\.(in|to|com)\/([a-zA-Z0-9]+)/i'
    ];
    
    $isShortUrl = false;
    foreach ($shortUrlPatterns as $pattern) {
        if (preg_match($pattern, $url)) {
            $isShortUrl = true;
            break;
        }
    }
    
    if ($isShortUrl) {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,  // Increased redirects
                CURLOPT_TIMEOUT => 15,    // Increased timeout
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                CURLOPT_NOBODY => true,
                CURLOPT_HEADER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.5',
                    'Accept-Encoding: gzip, deflate',
                    'Connection: keep-alive',
                    'Upgrade-Insecure-Requests: 1'
                ]
            ]);
            
            $response = curl_exec($ch);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            logTrackError('Short URL resolution attempt', [
                'original_url' => $url,
                'final_url' => $finalUrl,
                'http_code' => $httpCode,
                'curl_error' => $error
            ]);
            
            // Check for various success conditions
            if ($finalUrl && ($httpCode >= 200 && $httpCode < 400)) {
                return $finalUrl;
            }
            
            // If direct resolution fails, try alternative method
            if (!$finalUrl || $httpCode >= 400) {
                return resolveViaGetRequest($url);
            }
            
        } catch (Exception $e) {
            logTrackError('Exception in short URL resolution', ['error' => $e->getMessage()]);
            return resolveViaGetRequest($url);
        }
    }
    
    return $url;
}

// Add this new function after resolveAmazonShortUrl function
function resolveViaGetRequest($url) {
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AmezPrice/1.0; +https://amezprice.com)',
            CURLOPT_NOBODY => false,  // Get full response
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($finalUrl && $httpCode === 200) {
            return $finalUrl;
        }
        
    } catch (Exception $e) {
        logTrackError('Alternative resolution failed', ['error' => $e->getMessage()]);
    }
    
    return $url;
}

function extractAmazonASIN($url) {
    $resolvedUrl = resolveAmazonShortUrl($url);
    $decodedUrl = urldecode($resolvedUrl);
    
    logTrackError('ASIN Extraction Debug', [
        'original_url' => $url,
        'resolved_url' => $resolvedUrl,
        'decoded_url' => $decodedUrl
    ]);
    
    // Comprehensive ASIN patterns
    $patterns = [
        '/\/dp\/([A-Z0-9]{10})/i',              // Standard /dp/ format
        '/\/gp\/product\/([A-Z0-9]{10})/i',     // /gp/product/ format
        '/\/product\/([A-Z0-9]{10})/i',         // /product/ format
        '/\/asin\/([A-Z0-9]{10})/i',            // /asin/ format
        '/[?&]asin=([A-Z0-9]{10})/i',           // Query parameter
        '/\/([A-Z0-9]{10})(?:\/|\?|$)/i',       // Direct ASIN in path
        '/\/dp\/[^\/]+\/([A-Z0-9]{10})/i',      // DP with extra path
        '/ref=([A-Z0-9]{10})/i',                // Reference parameter
        '/amazon\.in\/.*\/([A-Z0-9]{10})/i'     // Any Amazon.in URL with ASIN
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $decodedUrl, $matches)) {
            $asin = strtoupper($matches[1]);
            if (strlen($asin) === 10 && preg_match('/^[A-Z0-9]{10}$/', $asin)) {
                logTrackError('ASIN extracted successfully', [
                    'pattern' => $pattern,
                    'asin' => $asin,
                    'from_url' => $decodedUrl
                ]);
                return $asin;
            }
        }
    }
    
    logTrackError('Failed to extract ASIN', [
        'original_url' => $url,
        'resolved_url' => $resolvedUrl,
        'decoded_url' => $decodedUrl
    ]);
    
    return '';
}

function generateStandardProductUrl($asin, $merchant) {
    if ($merchant === 'amazon') {
        return "https://www.amazon.in/dp/{$asin}";
    } elseif ($merchant === 'flipkart') {
        return "https://www.flipkart.com/product/p/pid={$asin}";
    }
    return '';
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $telegramUserId = $input['user_id'] ?? null;
    $productUrl = filter_var($input['product_url'] ?? '', FILTER_VALIDATE_URL);
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

    if (empty($telegramConfig) || !isset($telegramConfig['api_key']) || $apiKey !== $telegramConfig['api_key']) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid API key']);
        exit;
    }

    if (!$telegramUserId || !$productUrl) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegramUserId]);
    $userId = $stmt->fetchColumn();

    if (!$userId) {
        echo json_encode(['status' => 'error', 'message' => 'User not found. Please start the bot first.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM user_requests 
        WHERE user_id = ? 
        AND created_at > NOW() - INTERVAL 1 HOUR
    ");
    $stmt->execute([$userId]);
    $hourlyRequests = $stmt->fetchColumn();

    if ($hourlyRequests >= 5) {
        $pdo->prepare("DELETE FROM user_requests WHERE created_at < NOW() - INTERVAL 24 HOUR")->execute();
        echo json_encode(['status' => 'error', 'message' => 'You\'ve reached your limit of 5 products per hour. Try again later!']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE user_id = ?");
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() >= 50) {
        echo json_encode(['status' => 'error', 'message' => 'You are already tracking 50 products.']);
        exit;
    }

    $merchant = '';
    $asin = '';

    if (preg_match('/amazon\.|amzn\.(in|to)/i', $productUrl)) {
        $merchant = 'amazon';
        $asin = extractAmazonASIN($productUrl);
    } elseif (preg_match('/flipkart\./i', $productUrl)) {
        $merchant = 'flipkart';
        preg_match('/[?&]pid=([A-Z0-9]+)/i', $productUrl, $matches);
        $asin = $matches[1] ?? '';
    }

    if (!$merchant) {
        echo json_encode(['status' => 'error', 'message' => 'Unsupported marketplace. Please use Amazon India or Flipkart URLs.']);
        exit;
    }

    if (!$asin) {
        echo json_encode(['status' => 'error', 'message' => 'Could not extract product ID from URL. Please check the URL format.']);
        exit;
    }

    if ($merchant === 'amazon' && (strlen($asin) !== 10 || !preg_match('/^[A-Z0-9]{10}$/', $asin))) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Amazon product ID format. Please check the URL.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM user_products WHERE user_id = ? AND product_asin = ?");
    $stmt->execute([$userId, $asin]);
    if ($stmt->fetchColumn()) {
        echo json_encode(['status' => 'error', 'message' => 'You are already tracking this product!']);
        exit;
    }

    $standardProductUrl = generateStandardProductUrl($asin, $merchant);

    $stmt = $pdo->prepare("SELECT * FROM products WHERE asin = ? AND merchant = ?");
    $stmt->execute([$asin, $merchant]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        $fetchFunction = $merchant === 'amazon' ? 'fetchAmazonProduct' : 'fetchFlipkartProduct';
        $result = $fetchFunction($asin);

        if ($result['status'] !== 'success') {
            echo json_encode(['status' => 'error', 'message' => $result['message']]);
            exit;
        }

        $productName = $result['title'] ?? $result['name'] ?? 'Unknown Product';
        $currentPrice = (float)($result['current_price'] ?? 0);
        $imageUrl = $result['image_url'] ?? '';
        
        // 🔥 FIX: Correct image URL mapping
        $amazonImageUrl = $merchant === 'amazon' ? $imageUrl : null;  // Amazon की original image URL
        $localImagePath = null;  // Local downloaded image path (initially null)
        
        $stockStatus = $result['stock_status'] ?? 'in_stock';
        $stockQuantity = (int)($result['stock_quantity'] ?? 10);
        
        if ($stockQuantity <= 0) {
            $stockStatus = 'out_of_stock';
            $stockQuantity = 0;
        } elseif ($stockQuantity > 0 && $stockStatus === 'out_of_stock') {
            $stockStatus = 'in_stock';
        }
        
        // Explicit rating handling
        $rating = (float)($result['rating'] ?? 0.0);
        $ratingCount = (int)($result['rating_count'] ?? 0);
        
        $category = $result['category'] ?? 'general';
        $subcategory = $result['subcategory'] ?? 'general';
        $brand = $result['brand'] ?? 'Generic';

        $affiliateLink = $standardProductUrl;
        if ($merchant === 'amazon') {
            global $amazonConfig;
            $associateTag = $amazonConfig['associate_tag'] ?? 'amezprice-21';
            $affiliateLink = "https://www.amazon.in/dp/{$asin}?tag={$associateTag}";
        }

        // 🔥 FIX: Corrected INSERT statement - image_path should store Amazon original URL
        $stmt = $pdo->prepare("
            INSERT INTO products (
                asin, merchant, name, category, subcategory, brand, current_price, original_price, highest_price, lowest_price, 
                website_url, affiliate_link, local_image_path, image_path, stock_status, stock_quantity, 
                rating, rating_count, tracking_count, created_at, last_updated
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
        ");

        $success = $stmt->execute([
            $asin,
            $merchant,
            $productName,
            $category,
            $subcategory,
            $brand,
            $currentPrice,
            $result['original_price'] ?? $currentPrice,
            $currentPrice,
            $currentPrice,
            "https://amezprice.com/product/{$merchant}/pid={$asin}",
            $affiliateLink,
            $localImagePath,
            $amazonImageUrl,
            $stockStatus,
            $stockQuantity,
            $rating,
            $ratingCount
        ]);

        // Add to price_history table
        if ($success) {
            $priceHistoryStmt = $pdo->prepare("
                INSERT INTO price_history (product_asin, price, date_recorded) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE price = VALUES(price)
            ");
            $priceHistoryStmt->execute([$asin, $currentPrice, date('Y-m-d')]);
        }

        // 🔥 FIX: Call image download API after product is saved
        if ($merchant === 'amazon' && $amazonImageUrl) {
            try {
                $imageDownloadUrl = 'https://amezprice.com/api/download_image.php';
                $imageData = [
                    'merchant' => $merchant,
                    'asin' => $asin,
                    'image_url' => $amazonImageUrl,
                    'update_db' => true,
                    'force_download' => false
                ];

                $ch = curl_init($imageDownloadUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($imageData),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen(json_encode($imageData))
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);

                $imageResponse = curl_exec($ch);
                $imageHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($imageHttpCode === 200) {
                    $imageResult = json_decode($imageResponse, true);
                    if ($imageResult && $imageResult['status'] === 'success') {
                        logTrackError('Image download initiated successfully', [
                            'asin' => $asin,
                            'image_action' => $imageResult['action'] ?? 'unknown',
                            'local_path' => $imageResult['path'] ?? null
                        ]);
                    }
                }
            } catch (Exception $e) {
                logTrackError('Image download API call failed', [
                    'error' => $e->getMessage(),
                    'asin' => $asin
                ]);
            }
        }

        $product = [
            'asin' => $asin,
            'merchant' => $merchant,
            'name' => $productName,
            'current_price' => $currentPrice,
            'original_price' => $originalprice,
            'highest_price' => $currentPrice,
            'lowest_price' => $currentPrice,
            'website_url' => "https://amezprice.com/product/{$merchant}/pid={$asin}",
            'affiliate_link' => $affiliateLink,
            'category' => $category,
            'subcategory' => $subcategory,
            'brand' => $brand,
            'rating' => $rating,
            'rating_count' => $ratingCount,
            'stock_status' => $stockStatus,
            'stock_quantity' => $stockQuantity,
            'local_image_path' => $localImagePath,
            'image_path' => $amazonImageUrl
        ];

        logTrackError('New product added successfully', [
            'asin' => $asin,
            'merchant' => $merchant,
            'name' => substr($productName, 0, 50) . '...',
            'price' => $currentPrice,
            'category' => $category,
            'subcategory' => $subcategory,
            'brand' => $brand,
            'stock_quantity' => $stockQuantity,
            'stock_status' => $stockStatus,
            'rating' => $rating,
            'rating_count' => $ratingCount,
            'amazon_image_url' => $amazonImageUrl
        ]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO user_products (user_id, product_asin, product_url, price_history_url, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $userId,
        $asin,
        $standardProductUrl,
        $product['website_url']
    ]);

    $stmt = $pdo->prepare("INSERT INTO user_requests (user_id, asin, created_at) VALUES (?, ?, NOW())");
    $stmt->execute([$userId, $asin]);

    $stmt = $pdo->prepare("
        UPDATE products 
        SET tracking_count = (SELECT COUNT(*) FROM user_products WHERE product_asin = ?),
            last_updated = NOW()
        WHERE asin = ? AND merchant = ?
    ");
    $stmt->execute([$asin, $asin, $merchant]);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
    $stmt->execute([$asin]);
    $trackerCount = $stmt->fetchColumn();

    // 🔥 FIX: Use correct image URL for response
    $responseImageUrl = '';
    if ($merchant === 'amazon') {
        // First try local image path, then Amazon original URL
        $responseImageUrl = $product['local_image_path'] ?? $product['image_path'] ?? '';
    } else {
        $responseImageUrl = $product['local_image_path'] ?? '';
    }

    echo json_encode([
        'status' => 'success',
        'asin' => $asin,
        'merchant' => $merchant,
        'product_name' => $product['name'],
        'current_price' => $product['current_price'],
        'highest_price' => $product['highest_price'],
        'lowest_price' => $product['lowest_price'],
        'history_url' => $product['website_url'],
        'affiliate_link' => $product['affiliate_link'],
        'image_url' => $responseImageUrl,
        'tracker_count' => $trackerCount,
        'category' => $product['category'],
        'subcategory' => $product['subcategory'],
        'brand' => $product['brand'],
        'rating' => $product['rating'] ?? 0.0,
        'rating_count' => $product['rating_count'] ?? 0,
        'stock_status' => $product['stock_status'] ?? 'in_stock',
        'stock_quantity' => $product['stock_quantity'] ?? 10,
        'message' => 'Product tracking started successfully!'
    ]);

} catch (Exception $e) {
    logTrackError('Exception in track.php', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    echo json_encode([
        'status' => 'error', 
        'message' => 'Internal server error. Please try again later.'
    ]);
}
?>