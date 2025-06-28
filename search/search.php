<?php
require_once '../config/database.php';
require_once '../config/marketplaces.php';

header('Content-Type: application/json');

// Ensure $marketplaces is defined
if (!isset($marketplaces) || !is_array($marketplaces)) {
    $marketplaces = [
        'amazon' => 'active',
        'flipkart' => 'active'
    ];
}

// Error Logging Function
function logSearchError($message, $context = [], $level = 'ERROR') {
    $logFile = __DIR__ . '/../logs/search.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown IP';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown User Agent';
    
    $logEntry = [
        'timestamp' => $timestamp,
        'level' => $level,
        'ip' => $ip,
        'user_agent' => $userAgent,
        'message' => $message,
        'context' => $context,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? ''
    ];
    
    $logLine = json_encode($logEntry) . PHP_EOL;
    
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    
    if ($level === 'CRITICAL' || $level === 'ERROR') {
        error_log("Search Error: " . $message . " | Context: " . json_encode($context));
    }
}

// Enhanced Amazon short URL resolution with fallback methods
function resolveAmazonShortUrl($url) {
    try {
        // Check if it's a short URL that needs resolution
        if (preg_match('/amzn\.(in|to|com)/i', $url)) {
            
            logSearchError('Resolving Amazon short URL', ['original_url' => $url], 'INFO');
            
            // Method 1: HEAD request
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                CURLOPT_NOBODY => true,
                CURLOPT_HEADER => true,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $response = curl_exec($ch);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            // If HEAD request successful
            if (!$curlError && $httpCode >= 200 && $httpCode < 400 && $finalUrl) {
                logSearchError('Short URL resolved via HEAD', [
                    'original_url' => $url, 
                    'resolved_url' => $finalUrl
                ], 'INFO');
                return $finalUrl;
            }
            
            // Method 2: GET request fallback
            logSearchError('HEAD request failed, trying GET request', [
                'url' => $url,
                'curl_error' => $curlError,
                'http_code' => $httpCode
            ], 'WARNING');
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                CURLOPT_NOBODY => false,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $response = curl_exec($ch);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if (!$curlError && $httpCode >= 200 && $httpCode < 400 && $finalUrl) {
                logSearchError('Short URL resolved via GET', [
                    'original_url' => $url, 
                    'resolved_url' => $finalUrl
                ], 'INFO');
                return $finalUrl;
            }
            
            logSearchError('All resolution methods failed', [
                'url' => $url,
                'curl_error' => $curlError,
                'http_code' => $httpCode
            ], 'ERROR');
            
            return $url; // Return original URL instead of false
        }
        return $url;
    } catch (Exception $e) {
        logSearchError('Error resolving short URL', ['error' => $e->getMessage()], 'ERROR');
        return $url; // Return original URL instead of false
    }
}

// Enhanced ASIN extraction function with short URL support
function extractAmazonASIN($url) {
    // Enhanced ASIN extraction patterns
    $patterns = [
        '/\/dp\/([A-Z0-9]{10})/i',
        '/\/gp\/product\/([A-Z0-9]{10})/i',
        '/\/product\/([A-Z0-9]{10})/i',
        '/[?&]asin=([A-Z0-9]{10})/i',
        '/\/([A-Z0-9]{10})(?:\/|\?|$)/i'
    ];
    
    $decodedUrl = urldecode($url);
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $decodedUrl, $matches)) {
            logSearchError('ASIN extracted', ['url' => $url, 'asin' => $matches[1]], 'INFO');
            return strtoupper($matches[1]);
        }
    }
    
    logSearchError('ASIN extraction failed', ['url' => $url], 'WARNING');
    return '';
}

// Enhanced URL validation and cleaning function
function validateAndCleanUrl($url) {
    // Remove whitespace and decode URL
    $url = trim($url);
    
    // Add https:// if missing
    if (!preg_match('/^https?:\/\//', $url)) {
        $url = 'https://' . $url;
    }
    
    // First try to resolve short URLs
    if (preg_match('/amzn\.(in|to|com)/i', $url)) {
        $resolvedUrl = resolveAmazonShortUrl($url);
        if ($resolvedUrl && $resolvedUrl !== $url) {
            $url = $resolvedUrl;
            logSearchError('Using resolved URL', ['resolved_url' => $url], 'INFO');
        } else {
            logSearchError('Short URL resolution failed, continuing with original', ['url' => $url], 'WARNING');
            // Continue with original URL - will be handled in merchant detection
        }
    }
    
    // Supported domains
    $supportedDomains = [
        'amazon.in', 'www.amazon.in',
        'flipkart.com', 'www.flipkart.com',
        'amzn.in',
        'amzn.to',
        'amzn.com',
    ];
    
    // Basic URL validation
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    $parsedUrl = parse_url($url);
    $host = strtolower($parsedUrl['host'] ?? '');
    
    // Check if domain is supported (after resolution)
    if (!in_array($host, $supportedDomains)) {
        return false;
    }
    
    return $url;
}

$startTime = microtime(true);

try {
    // Validate input
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logSearchError('Invalid JSON input', ['json_error' => json_last_error_msg()], 'ERROR');
        echo json_encode(['status' => 'error', 'message' => 'Invalid request format']);
        exit;
    }

    // Enhanced URL validation
    $rawUrl = $input['url'] ?? '';
    if (empty($rawUrl)) {
        logSearchError('Empty URL provided', [], 'WARNING');
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid Amazon or Flipkart URL']);
        exit;
    }

    // Clean and validate URL (this will now handle short URLs)
    $productUrl = validateAndCleanUrl($rawUrl);
    if (!$productUrl) {
        logSearchError('Invalid or unsupported URL provided', ['input_url' => $rawUrl], 'WARNING');
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid Amazon or Flipkart URL']);
        exit;
    }

    // Enhanced URL detection - now handles both resolved and unresolved short URLs
    $merchant = '';
    $asin = '';

    // Amazon URLs (including short URLs)
    if (preg_match('/amazon\.(in|com)/i', $productUrl) || preg_match('/amzn\.(in|to|com)/i', $productUrl)) {
        $merchant = 'amazon';
        $asin = extractAmazonASIN($productUrl);
        
        // If normal extraction failed and it's still a short URL, show proper error
        if (empty($asin) && preg_match('/amzn\.(in|to|com)/i', $productUrl)) {
            logSearchError('Cannot extract ASIN from unresolved short URL', [
                'url' => $productUrl,
                'original_url' => $rawUrl
            ], 'ERROR');
            echo json_encode([
                'status' => 'error', 
                'message' => 'This Amazon short URL appears to be invalid or expired. Please use a direct Amazon product URL or try a different link.'
            ]);
            exit;
        }
    } elseif (preg_match('/flipkart\./i', $productUrl)) {
        $merchant = 'flipkart';
        preg_match('/[?&]pid=([A-Z0-9]+)/i', $productUrl, $matches);
        $asin = $matches[1] ?? '';
    }

    if (!$merchant || !$asin) {
        logSearchError('Invalid product URL or ASIN/PID not found', [
            'url' => $productUrl,
            'original_url' => $rawUrl,
            'merchant' => $merchant,
            'asin' => $asin
        ], 'ERROR');
        echo json_encode(['status' => 'error', 'message' => 'Invalid product URL. Please use Amazon or Flipkart URLs']);
        exit;
    }

    // Rest of your code remains the same...
    // Check marketplace status
    if (!isset($marketplaces[$merchant]) || $marketplaces[$merchant] !== 'active') {
        logSearchError('Marketplace service unavailable', ['merchant' => $merchant], 'WARNING');
        echo json_encode(['status' => 'error', 'message' => "Service for {$merchant} is temporarily unavailable"]);
        exit;
    }

    // Load marketplace-specific files
    if ($merchant === 'amazon') {
        require_once '../api/marketplaces/amazon.php';
    } elseif ($merchant === 'flipkart') {
        require_once '../api/marketplaces/flipkart.php';
    }

    // Check database for existing product
    try {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE asin = ? AND merchant = ?");
        $stmt->execute([$asin, $merchant]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logSearchError('Database error during product lookup', [
            'asin' => $asin,
            'merchant' => $merchant,
            'error' => $e->getMessage()
        ], 'CRITICAL');
        echo json_encode(['status' => 'error', 'message' => 'Database error occurred']);
        exit;
    }

    if ($product) {
        // Fetch current data to update price if needed
        $fetchFunction = $merchant === 'amazon' ? 'fetchAmazonProduct' : 'fetchFlipkartProduct';
        try {
            $result = $fetchFunction($asin);
            if ($result['status'] === 'success' && $product['current_price'] != $result['current_price']) {
                $stmt = $pdo->prepare("
                    UPDATE products 
                    SET 
                        current_price = ?,
                        original_price = ?,
                        highest_price = GREATEST(highest_price, ?),
                        lowest_price = LEAST(lowest_price, ?),
                        last_updated = NOW()
                    WHERE asin = ?
                ");
                $stmt->execute([
                    $result['current_price'],
                    $result['original_price'] ?? $result['current_price'],
                    $result['current_price'],
                    $result['current_price'],
                    $asin
                ]);

                // Add to price_history table
                $priceHistoryStmt = $pdo->prepare("
                    INSERT INTO price_history (product_asin, price, date_recorded) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE price = VALUES(price)
                ");
                $priceHistoryStmt->execute([$asin, $result['current_price'], date('Y-m-d')]);
            }
        } catch (Exception $e) {
            logSearchError('Error fetching current price', [
                'asin' => $asin,
                'merchant' => $merchant,
                'error' => $e->getMessage()
            ], 'WARNING');
        }

        $executionTime = (microtime(true) - $startTime) * 1000;
        logSearchError('Search completed (existing product)', [
            'asin' => $asin,
            'merchant' => $merchant,
            'execution_time_ms' => $executionTime
        ], 'INFO');

        echo json_encode([
            'status' => 'success',
            'product' => [
                'name' => $product['name'],
                'current_price' => $product['current_price'],
                'original_price' => $product['original_price'] ?? $product['current_price'],
                'highest_price' => $product['highest_price'],
                'lowest_price' => $product['lowest_price'],
                'image_path' => $product['local_image_path'] ?? $product['image_path'] ?? '',
                'website_url' => $product['website_url'],
                'affiliate_link' => $product['affiliate_link'],
                'asin' => $asin,
                'merchant' => $merchant,
                'rating' => $product['rating'] ?? 0.0,
                'rating_count' => $product['rating_count'] ?? 0,
                'stock_status' => $product['stock_status'] ?? 'in_stock',
                'stock_quantity' => $product['stock_quantity'] ?? 10,
                'category' => $product['category'] ?? 'General',
                'subcategory' => $product['subcategory'] ?? 'General',
                'brand' => $product['brand'] ?? 'Generic'
            ]
        ]);
        exit;
    }

    // Fetch new product from marketplace
    $fetchFunction = $merchant === 'amazon' ? 'fetchAmazonProduct' : 'fetchFlipkartProduct';
    try {
        $result = $fetchFunction($asin);
        if ($result['status'] !== 'success') {
            logSearchError('Product fetch failed', [
                'asin' => $asin,
                'merchant' => $merchant,
                'result' => $result
            ], 'ERROR');
            echo json_encode(['status' => 'error', 'message' => $result['message']]);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO products (
                asin, merchant, name, current_price, original_price, highest_price, lowest_price, 
                website_url, affiliate_link, image_path, stock_status, stock_quantity, 
                rating, rating_count, category, subcategory, brand, created_at, last_updated
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $asin,
            $merchant,
            $result['title'] ?? $result['name'] ?? 'Unknown Product',
            $result['current_price'],
            $result['original_price'] ?? $result['current_price'],
            $result['current_price'],
            $result['current_price'],
            "https://amezprice.com/product/{$merchant}/pid={$asin}",
            $result['affiliate_link'] ?? ($merchant === 'amazon' ? "https://www.amazon.in/dp/{$asin}" : "https://www.flipkart.com/product/p/pid={$asin}"),
            $result['image_url'] ?? $result['image_path'] ?? '',
            $result['stock_status'] ?? 'in_stock',
            $result['stock_quantity'] ?? 10,
            $result['rating'] ?? 0.0,
            $result['rating_count'] ?? 0,
            $result['category'] ?? 'General',
            $result['subcategory'] ?? 'General',
            $result['brand'] ?? 'Generic'
        ]);

        // Add initial price to price_history table
       $priceHistoryStmt = $pdo->prepare("
            INSERT INTO price_history (product_asin, price, date_recorded) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE price = VALUES(price)
        ");
        $priceHistoryStmt->execute([$asin, $result['current_price'], date('Y-m-d')]);

        $executionTime = (microtime(true) - $startTime) * 1000;
        logSearchError('New product added', [
            'asin' => $asin,
            'merchant' => $merchant,
            'product_name' => $result['name'] ?? $result['title'],
            'execution_time_ms' => $executionTime
        ], 'INFO');

        echo json_encode([
            'status' => 'success',
            'product' => [
                'name' => $result['title'] ?? $result['name'],
                'current_price' => $result['current_price'],
                'original_price' => $result['original_price'] ?? $result['current_price'],
                'highest_price' => $result['current_price'],
                'lowest_price' => $result['current_price'],
                'image_path' => $result['image_url'] ?? $result['image_path'] ?? '',
                'website_url' => "https://amezprice.com/product/{$merchant}/pid={$asin}",
                'affiliate_link' => $result['affiliate_link'] ?? ($merchant === 'amazon' ? "https://www.amazon.in/dp/{$asin}" : "https://www.flipkart.com/product/p/pid={$asin}"),
                'asin' => $asin,
                'merchant' => $merchant,
                'rating' => $result['rating'] ?? 0.0,
                'rating_count' => $result['rating_count'] ?? 0,
                'stock_status' => $result['stock_status'] ?? 'in_stock',
                'stock_quantity' => $result['stock_quantity'] ?? 10,
                'category' => $result['category'] ?? 'General',
                'subcategory' => $result['subcategory'] ?? 'General',
                'brand' => $result['brand'] ?? 'Generic'
            ]
        ]);

    } catch (Exception $e) {
        logSearchError('Failed to fetch or save new product', [
            'asin' => $asin,
            'merchant' => $merchant,
            'error' => $e->getMessage()
        ], 'CRITICAL');
        echo json_encode(['status' => 'error', 'message' => 'Failed to retrieve product data']);
        exit;
    }

} catch (Exception $e) {
    logSearchError('Unexpected error', ['error' => $e->getMessage()], 'CRITICAL');
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred']);
}
?>