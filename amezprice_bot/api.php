<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once '../middleware/csrf.php';

// Enhanced error reporting
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Enhanced security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Referrer-Policy: strict-origin-when-cross-origin');

/**
 * Enhanced rate limiting with Redis fallback to file-based storage
 */
function checkRateLimit($clientIP, $limit = 120, $window = 3600) {
    $rateLimitFile = __DIR__ . '/../storage/rate_limit/amezprice_bot.json';
    $rateLimitDir = dirname($rateLimitFile);
    
    // Ensure cache directory exists
    if (!is_dir($rateLimitDir)) {
        mkdir($rateLimitDir, 0755, true);
    }
    
    $now = time();
    $limits = [];
    
    // Load existing limits
    if (file_exists($rateLimitFile)) {
        $content = file_get_contents($rateLimitFile);
        $limits = $content ? json_decode($content, true) : [];
        if (!is_array($limits)) {
            $limits = [];
        }
    }
    
    // Clean old entries
    $limits = array_filter($limits, function($data) use ($now, $window) {
        return isset($data['timestamp']) && ($now - $data['timestamp']) < $window;
    });
    
    // Check current IP
    $clientKey = hash('sha256', $clientIP);
    $clientLimits = array_filter($limits, function($data) use ($clientKey) {
        return isset($data['ip']) && $data['ip'] === $clientKey;
    });
    
    if (count($clientLimits) >= $limit) {
        return false;
    }
    
    // Add current request
    $limits[] = [
        'ip' => $clientKey,
        'timestamp' => $now,
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)
    ];
    
    // Save limits with error handling
    $encoded = json_encode($limits);
    if ($encoded !== false) {
        file_put_contents($rateLimitFile, $encoded, LOCK_EX);
    }
    
    return true;
}

/**
 * Make an HTTP request with enhanced error handling and retry logic
 */
function makeRequest($url, $payload, $headers = [], $timeout = 30) {
    $maxRetries = 3;
    $retryDelay = 1;
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'AmezPrice-API/1.2',
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'Accept: application/json'
            ], $headers)
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            logApiActivity('curl_error', [
                'attempt' => $attempt,
                'error' => $error,
                'url' => $url
            ]);
            
            if ($attempt < $maxRetries) {
                sleep($retryDelay * $attempt);
                continue;
            }
            throw new Exception("Network error: $error");
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            } else {
                throw new Exception("Invalid JSON response: " . json_last_error_msg());
            }
        }

        if ($httpCode >= 500 && $attempt < $maxRetries) {
            logApiActivity('server_error_retry', [
                'attempt' => $attempt,
                'http_code' => $httpCode,
                'url' => $url
            ]);
            sleep($retryDelay * $attempt);
            continue;
        }

        throw new Exception("HTTP error $httpCode: $response");
    }
    
    throw new Exception("Max retries exceeded");
}

/**
 * Enhanced API activity logging
 */
function logApiActivity($action, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/amezprice_api.log';
        $logDir = dirname($logFile);
        
        // Ensure log directory exists
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Log rotation
        if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
            $rotatedFile = $logFile . '.' . date('Y-m-d-H-i-s');
            rename($logFile, $rotatedFile);
            
            // Keep only last 5 rotated files
            $logFiles = glob($logFile . '.*');
            if (count($logFiles) > 5) {
                array_map('unlink', array_slice($logFiles, 0, -5));
            }
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logData = array_merge([
            'timestamp' => $timestamp,
            'action' => $action,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'memory_usage' => memory_get_usage(true)
        ], $data);
        
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log("AmezPrice API logging error: " . $e->getMessage());
    }
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    // Enhanced IP detection and rate limiting
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $forwardedIPs = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $realIP = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    
    // Use the most specific IP available
    if (!empty($realIP)) {
        $actualIP = $realIP;
    } elseif (!empty($forwardedIPs)) {
        $ipList = explode(',', $forwardedIPs);
        $actualIP = trim($ipList[0]);
    } else {
        $actualIP = $clientIP;
    }
    
    // Validate IP format
    if (!filter_var($actualIP, FILTER_VALIDATE_IP)) {
        $actualIP = $clientIP;
    }
    
    // Apply rate limiting
    if (!checkRateLimit($actualIP, 120, 3600)) { // 120 requests per hour
        logApiActivity('rate_limit_exceeded', ['ip' => $actualIP]);
        throw new Exception('Rate limit exceeded. Please try again later.', 429);
    }
    
    // Read and validate input
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        throw new Exception('Empty request body', 400);
    }

    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload: ' . json_last_error_msg(), 400);
    }

    // Enhanced API key validation
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_API_KEY'] ?? '';
    if (empty($apiKey)) {
        logApiActivity('missing_api_key', ['ip' => $actualIP]);
        throw new Exception('API key required', 401);
    }
    
    if (!isset($telegramConfig['api_key']) || !hash_equals($telegramConfig['api_key'], $apiKey)) {
        logApiActivity('invalid_api_key', ['ip' => $actualIP]);
        throw new Exception('Invalid API key', 401);
    }

    // Validate required fields
    $action = $input['action'] ?? '';
    if (empty($action)) {
        throw new Exception('Action is required', 400);
    }

    $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId) {
        throw new Exception('Invalid user ID', 400);
    }

    // Validate allowed actions
    $allowedActions = ['track', 'remove', 'list', 'stats', 'health'];
    if (!in_array($action, $allowedActions)) {
        throw new Exception('Invalid action. Allowed: ' . implode(', ', $allowedActions), 400);
    }

    // Log start of request
    logApiActivity($action, [
        'user_id' => $userId,
        'status' => 'started',
        'ip' => $actualIP
    ]);

    // Process different actions
    switch ($action) {
        case 'track':
            // Validate product URL
            if (empty($input['product_url'])) {
                throw new Exception('Product URL is required', 400);
            }

            if (!filter_var($input['product_url'], FILTER_VALIDATE_URL)) {
                throw new Exception('Invalid product URL format', 400);
            }

            // Enhanced URL validation
            $supportedDomains = [
                'amazon.in', 'www.amazon.in', 
                'flipkart.com', 'www.flipkart.com',
                'amzn.in', 'amzn.to', 'amzn.com'
            ];
            $urlHost = strtolower(parse_url($input['product_url'], PHP_URL_HOST) ?? '');
            
            if (!in_array($urlHost, $supportedDomains)) {
                throw new Exception('Only Amazon India, Flipkart URLs are supported', 400);
            }

            // Additional URL pattern validation
            if (strpos($urlHost, 'amazon') !== false || in_array($urlHost, ['amzn.in', 'amzn.to', 'amzn.com', 'a.co'])) {
                // Accept both regular and short URLs
                if (!preg_match('/\/dp\/[A-Z0-9]{10}/', $input['product_url']) && 
                    !preg_match('/\/gp\/product\/[A-Z0-9]{10}/', $input['product_url']) &&
                    !preg_match('/amzn\.(in|to|com)\/d\/[a-zA-Z0-9]+/', $input['product_url'])) {
                    throw new Exception('Invalid Amazon product URL format.', 400);
                }
            } elseif (strpos($urlHost, 'flipkart') !== false) {
                if (!preg_match('/\/p\/[a-zA-Z0-9-]+/', $input['product_url'])) {
                    throw new Exception('Invalid Flipkart product URL format', 400);
                }
            }

            // Prepare enhanced tracking payload
            $trackPayload = [
                'user_id' => $userId,
                'username' => $input['username'] ?? null,
                'first_name' => $input['first_name'] ?? '',
                'last_name' => $input['last_name'] ?? null,
                'product_url' => $input['product_url'],
                'source' => 'telegram_bot',
                'timestamp' => date('Y-m-d H:i:s'),
                'client_ip' => $actualIP,
                'notification_preferences' => [
                    'price_drop' => true,
                    'low_stock' => $input['notify_low_stock'] ?? true,
                    'back_in_stock' => $input['notify_back_in_stock'] ?? true,
                    'deal_alerts' => $input['notify_deals'] ?? true
                ]
            ];

            // Make API request with enhanced retry mechanism
            $maxRetries = 3;
            $retryCount = 0;
            $lastError = null;

            while ($retryCount < $maxRetries) {
                try {
                    $response = makeRequest(
                        'https://amezprice.com/api/track.php',
                        $trackPayload,
                        ['X-API-Key: ' . $telegramConfig['api_key']],
                        30
                    );

                    // Enhanced response processing
                    if (isset($response['asin'])) {
                        // Update local database
                        $stmt = $pdo->prepare("
                            INSERT INTO user_products (user_id, product_asin, created_at, updated_at) 
                            VALUES (?, ?, NOW(), NOW()) 
                            ON DUPLICATE KEY UPDATE updated_at = NOW()
                        ");
                        $stmt->execute([$userId, $response['asin']]);
                    }

                    // Add enhanced tracking metrics
                    $response['tracking_stats'] = [
                        'total_users' => $response['tracker_count'] ?? 0,
                        'price_change_24h' => $response['price_change_24h'] ?? 0,
                        'tracking_since' => $response['first_tracked'] ?? date('Y-m-d H:i:s'),
                        'current_price' => $response['current_price'] ?? 0,
                        'highest_price' => $response['highest_price'] ?? 0,
                        'lowest_price' => $response['lowest_price'] ?? 0,
                        'average_price' => $response['average_price'] ?? 0,
                        'price_drop_percentage' => $response['price_drop_percentage'] ?? 0
                    ];

                    // Add user tracking info
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as user_product_count 
                        FROM user_products 
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$userId]);
                    $userStats = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $response['user_stats'] = [
                        'total_tracked_products' => $userStats['user_product_count'] ?? 0,
                        'tracking_limit_reached' => ($userStats['user_product_count'] ?? 0) >= 50
                    ];

                    // Log successful tracking
                    logApiActivity('track_success', [
                        'user_id' => $userId,
                        'product_id' => $response['asin'] ?? null,
                        'status' => 'completed',
                        'response_time' => microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))
                    ]);

                    echo json_encode($response);
                    break;

                } catch (Exception $e) {
                    $lastError = $e;
                    $retryCount++;
                    
                    logApiActivity('track_retry', [
                        'user_id' => $userId,
                        'attempt' => $retryCount,
                        'error' => $e->getMessage()
                    ]);
                    
                    if ($retryCount < $maxRetries) {
                        sleep($retryCount); // Progressive delay
                        continue;
                    }
                    throw $e;
                }
            }
            break;

        case 'remove':
            // Validate ASIN
            $asin = $input['asin'] ?? '';
            if (empty($asin) || !preg_match('/^[A-Z0-9]{10}$/', $asin)) {
                throw new Exception('Invalid ASIN format', 400);
            }

            // Verify if user is actually tracking this product
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count, 
                       (SELECT name FROM products WHERE asin = ?) as product_name
                FROM user_products 
                WHERE user_id = ? AND product_asin = ?
            ");
            $stmt->execute([$asin, $userId, $asin]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result || $result['count'] == 0) {
                throw new Exception('Product not found in your tracking list', 404);
            }

            // Prepare removal payload
            $removePayload = [
                'user_id' => $userId,
                'asin' => $asin,
                'source' => 'telegram_bot',
                'timestamp' => date('Y-m-d H:i:s'),
                'reason' => $input['reason'] ?? 'user_request',
                'client_ip' => $actualIP
            ];

            // Make API request with retry mechanism
            $maxRetries = 3;
            $retryCount = 0;
            $lastError = null;

            while ($retryCount < $maxRetries) {
                try {
                    $response = makeRequest(
                        'https://amezprice.com/api/remove.php',
                        $removePayload,
                        ['X-API-Key: ' . $telegramConfig['api_key']]
                    );

                    // Update local database
                    $stmt = $pdo->prepare("
                        DELETE FROM user_products 
                        WHERE user_id = ? AND product_asin = ?
                    ");
                    $removeResult = $stmt->execute([$userId, $asin]);

                    // Log successful removal
                    logApiActivity('remove_success', [
                        'user_id' => $userId,
                        'product_id' => $asin,
                        'product_name' => $result['product_name'],
                        'status' => 'completed'
                    ]);

                    // Add removal confirmation
                    $response['removal_confirmation'] = [
                        'timestamp' => date('Y-m-d H:i:s'),
                        'success' => $removeResult,
                        'product_name' => $result['product_name']
                    ];

                    echo json_encode($response);
                    break;

                } catch (Exception $e) {
                    $lastError = $e;
                    $retryCount++;
                    
                    logApiActivity('remove_retry', [
                        'user_id' => $userId,
                        'attempt' => $retryCount,
                        'error' => $e->getMessage()
                    ]);
                    
                    if ($retryCount < $maxRetries) {
                        sleep($retryCount);
                        continue;
                    }
                    throw $e;
                }
            }
            break;

        case 'list':
            // Get user's tracked products with enhanced information
            $limit = min(50, max(1, (int)($input['limit'] ?? 20)));
            $offset = max(0, (int)($input['offset'] ?? 0));

            $stmt = $pdo->prepare("
                SELECT 
                    p.asin,
                    p.name,
                    p.current_price,
                    p.highest_price,
                    p.lowest_price,
                    p.affiliate_link,
                    up.price_threshold,
                    up.created_at as tracking_since,
                    up.updated_at as last_updated,
                    CASE 
                        WHEN up.price_threshold IS NOT NULL AND p.current_price <= up.price_threshold 
                        THEN TRUE 
                        ELSE FALSE 
                    END as alert_triggered
                FROM user_products up 
                JOIN products p ON up.product_asin = p.asin 
                WHERE up.user_id = ? 
                ORDER BY up.created_at DESC 
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$userId, $limit, $offset]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get total count
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total_count 
                FROM user_products 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $totalCount = $stmt->fetch(PDO::FETCH_ASSOC)['total_count'];

            // Add calculated fields
            foreach ($products as &$product) {
                $product['price_drop_percentage'] = 0;
                if ($product['highest_price'] > 0) {
                    $product['price_drop_percentage'] = round(
                        (($product['highest_price'] - $product['current_price']) / $product['highest_price']) * 100, 
                        2
                    );
                }
                
                $product['savings_potential'] = max(0, $product['current_price'] - $product['lowest_price']);
                $product['tracking_duration_days'] = floor(
                    (time() - strtotime($product['tracking_since'])) / 86400
                );
            }

            logApiActivity('list_success', [
                'user_id' => $userId,
                'total_products' => $totalCount,
                'returned_products' => count($products)
            ]);

            echo json_encode([
                'status' => 'success',
                'products' => $products,
                'pagination' => [
                    'total_count' => $totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $totalCount
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;

        case 'stats':
            // Get comprehensive user statistics
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(DISTINCT up.product_asin) as total_tracked,
                    AVG(p.current_price) as avg_price_tracked,
                    SUM(CASE WHEN up.price_threshold IS NOT NULL AND p.current_price <= up.price_threshold THEN 1 ELSE 0 END) as alerts_triggered,
                    MIN(up.created_at) as first_tracking_date,
                    MAX(up.updated_at) as last_activity
                FROM user_products up
                JOIN products p ON up.product_asin = p.asin
                WHERE up.user_id = ?
            ");
            $stmt->execute([$userId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get user info
            $stmt = $pdo->prepare("
                SELECT username, first_name, created_at 
                FROM users 
                WHERE telegram_id = ?
            ");
            $stmt->execute([$userId]);
            $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            logApiActivity('stats_success', [
                'user_id' => $userId,
                'total_tracked' => $stats['total_tracked']
            ]);

            echo json_encode([
                'status' => 'success',
                'user_info' => $userInfo,
                'tracking_stats' => $stats,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;

        case 'health':
            // API health check
            try {
                // Test database connection
                $stmt = $pdo->query("SELECT 1 as test");
                $dbStatus = $stmt ? 'healthy' : 'error';
                
                // Check main API endpoint
                $apiStatus = 'healthy';
                try {
                    $ch = curl_init('https://amezprice.com/api/health.php');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                        CURLOPT_NOBODY => true
                    ]);
                    $result = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($httpCode !== 200) {
                        $apiStatus = 'degraded';
                    }
                } catch (Exception $e) {
                    $apiStatus = 'error';
                }
                
                // Check file system
                $logDir = __DIR__ . '/../logs';
                $fsStatus = is_writable($logDir) ? 'healthy' : 'error';
                
                $overallStatus = ($dbStatus === 'healthy' && $apiStatus !== 'error' && $fsStatus === 'healthy') 
                    ? 'healthy' : 'degraded';
                
                echo json_encode([
                    'status' => 'success',
                    'service' => 'AmezPrice Bot API',
                    'version' => '1.2.0',
                    'overall_status' => $overallStatus,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'checks' => [
                        'database' => $dbStatus,
                        'main_api' => $apiStatus,
                        'file_system' => $fsStatus,
                        'memory_usage' => memory_get_usage(true),
                        'peak_memory' => memory_get_peak_usage(true)
                    ],
                    'build_info' => [
                        'php_version' => PHP_VERSION,
                        'server_time' => date('Y-m-d H:i:s'),
                        'timezone' => date_default_timezone_get()
                    ]
                ]);
            } catch (Exception $e) {
                throw new Exception('Health check failed: ' . $e->getMessage(), 503);
            }
            break;

        default:
            throw new Exception('Unknown action: ' . $action, 400);
    }

} catch (Exception $e) {
    // Enhanced error logging
    logApiActivity('error', [
        'error_message' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'action' => $input['action'] ?? 'unknown',
        'user_id' => $input['user_id'] ?? null,
        'stack_trace' => $e->getTraceAsString()
    ]);
    
    // Send appropriate HTTP status code
    $statusCode = $e->getCode() ?: 500;
    http_response_code($statusCode);
    
    // Return enhanced error response
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'code' => $statusCode,
        'timestamp' => date('Y-m-d H:i:s'),
        'request_id' => uniqid('ap_', true),
        'support_info' => [
            'contact' => 'support@amezprice.com',
        ]
    ]);
}

// Clean up resources
if (isset($pdo)) {
    $pdo = null;
}
?>