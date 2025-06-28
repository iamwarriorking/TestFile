<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once '../middleware/csrf.php';
require_once 'hotdealsbot.php';

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

// Rate limiting storage (Redis recommended, file-based fallback)
function checkRateLimit($clientIP, $limit = 120, $window = 3600) {
    $rateLimitFile = __DIR__ . '/../storage/rate_limit/hotdeals_bot.json';
    $rateLimitDir = dirname($rateLimitFile);
    
    // Ensure cache directory exists
    if (!is_dir($rateLimitDir)) {
        mkdir($rateLimitDir, 0755, true);
    }
    
    $now = time();
    $limits = [];
    
    // Load existing limits
    if (file_exists($rateLimitFile)) {
        $limits = json_decode(file_get_contents($rateLimitFile), true) ?: [];
    }
    
    // Clean old entries
    $limits = array_filter($limits, function($data) use ($now, $window) {
        return ($now - $data['timestamp']) < $window;
    });
    
    // Check current IP
    $clientKey = hash('sha256', $clientIP);
    $clientLimits = array_filter($limits, function($data) use ($clientKey) {
        return $data['ip'] === $clientKey;
    });
    
    if (count($clientLimits) >= $limit) {
        return false;
    }
    
    // Add current request
    $limits[] = [
        'ip' => $clientKey,
        'timestamp' => $now
    ];
    
    // Save limits
    file_put_contents($rateLimitFile, json_encode($limits), LOCK_EX);
    
    return true;
}

// Enhanced logging function
function logApi($message, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/hotdeals_api.log';
        $timestamp = date('Y-m-d H:i:s');
        $logData = array_merge([
            'timestamp' => $timestamp,
            'message' => $message,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ], $data);
        
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log("HotDeals API logging error: " . $e->getMessage());
    }
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    // Enhanced rate limiting
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $forwardedIP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $realIP = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    
    // Use the most specific IP available
    $actualIP = $realIP ?: ($forwardedIP ? explode(',', $forwardedIP)[0] : $clientIP);
    $actualIP = trim($actualIP);
    
    if (!checkRateLimit($actualIP, 100, 3600)) { // 100 requests per hour
        throw new Exception('Rate limit exceeded. Please try again later.', 429);
    }
    
    // Read and validate input
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        throw new Exception('Empty request body', 400);
    }

    // Validate JSON
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload: ' . json_last_error_msg(), 400);
    }

    // Enhanced API key validation
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_API_KEY'] ?? '';
    if (empty($apiKey)) {
        throw new Exception('API key required', 401);
    }
    
    if (!isset($telegramConfig['api_key']) || !hash_equals($telegramConfig['api_key'], $apiKey)) {
        logApi('Invalid API key attempt', ['ip' => $actualIP]);
        throw new Exception('Invalid API key', 401);
    }

    // Log the request
    logApi('API request received', [
        'action' => $input['action'] ?? 'unknown',
        'has_user_id' => isset($input['user_id']),
        'ip' => $actualIP
    ]);

    // Validate required fields
    $action = $input['action'] ?? '';
    if (empty($action)) {
        throw new Exception('Action is required', 400);
    }

    // Enhanced action validation
    $allowedActions = ['webhook', 'notify', 'user_stats', 'health_check'];
    if (!in_array($action, $allowedActions)) {
        throw new Exception('Invalid action', 400);
    }

    // Process different actions
    switch ($action) {
        case 'webhook':
            // Handle Telegram webhook
            if (!isset($input['message']) && !isset($input['callback_query'])) {
                throw new Exception('Invalid webhook payload', 400);
            }

            $chatId = null;
            $user = null;
            $message = null;

            if (isset($input['message'])) {
                $chatId = $input['message']['chat']['id'] ?? null;
                $user = $input['message']['from'] ?? null;
                $message = $input['message']['text'] ?? '';
            } elseif (isset($input['callback_query'])) {
                $chatId = $input['callback_query']['message']['chat']['id'] ?? null;
                $user = $input['callback_query']['from'] ?? null;
                $message = ''; // Will be handled in callback processing
            }

            if (!$chatId || !$user) {
                throw new Exception('Invalid webhook data', 400);
            }

            // Enhanced user validation
            if (!isset($user['id']) || !is_numeric($user['id'])) {
                throw new Exception('Invalid user ID', 400);
            }

            logApi('Processing webhook', [
                'user_id' => $chatId,
                'message_type' => isset($input['message']) ? 'message' : 'callback',
                'message_length' => strlen($message)
            ]);

            // Process the message
            $result = handleHotDealsMessage($chatId, $message, $user, $input);

            echo json_encode([
                'status' => 'success',
                'message' => 'Webhook processed successfully',
                'timestamp' => date('Y-m-d H:i:s'),
                'user_id' => $chatId
            ]);
            break;

        case 'notify':
            // Handle deal notifications
            $requiredFields = ['category', 'deal_info'];
            foreach ($requiredFields as $field) {
                if (!isset($input[$field])) {
                    throw new Exception("Field '$field' is required for notify action", 400);
                }
            }

            // Validate deal info structure
            $dealInfo = $input['deal_info'];
            $requiredDealFields = ['title', 'url', 'current_price', 'original_price', 'merchant'];
            foreach ($requiredDealFields as $field) {
                if (!isset($dealInfo[$field])) {
                    throw new Exception("Deal info field '$field' is required", 400);
                }
            }

            // Validate prices
            if (!is_numeric($dealInfo['current_price']) || !is_numeric($dealInfo['original_price'])) {
                throw new Exception('Invalid price format', 400);
            }

            if ($dealInfo['current_price'] >= $dealInfo['original_price']) {
                throw new Exception('Current price must be less than original price', 400);
            }

            // Validate merchant
            if (!in_array(strtolower($dealInfo['merchant']), ['amazon', 'flipkart'])) {
                throw new Exception('Invalid merchant. Only Amazon and Flipkart are supported', 400);
            }

            // Process notification (assuming notify.php exists and works)
            $notifyFile = __DIR__ . '/notify.php';
            if (!file_exists($notifyFile)) {
                throw new Exception('Notification service not available', 503);
            }

            // Include and process notification
            $notificationResult = include $notifyFile;

            logApi('Notification processed', [
                'category' => $input['category'],
                'merchant' => $dealInfo['merchant'],
                'price' => $dealInfo['current_price']
            ]);

            echo json_encode([
                'status' => 'success',
                'message' => 'Notification sent successfully',
                'category' => $input['category'],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;

        case 'user_stats':
            // Get user statistics
            $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$userId) {
                throw new Exception('Valid user ID is required', 400);
            }

            $stmt = $pdo->prepare("
                SELECT 
                    telegram_id,
                    username,
                    first_name,
                    created_at,
                    COUNT(DISTINCT category) as active_categories
                FROM hotdealsbot
                WHERE telegram_id = ? AND is_active = TRUE
                GROUP BY telegram_id
            ");
            $stmt->execute([$userId]);
            $userStats = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$userStats) {
                throw new Exception('User not found', 404);
            }

            // Get category details
            $stmt = $pdo->prepare("
                SELECT category, merchant, price_range, created_at
                FROM hotdealsbot
                WHERE telegram_id = ? AND is_active = TRUE
                ORDER BY created_at DESC
            ");
            $stmt->execute([$userId]);
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'user_stats' => $userStats,
                'categories' => $categories,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;

        case 'health_check':
            // API health check
            try {
                // Test database connection
                $stmt = $pdo->query("SELECT 1");
                $dbStatus = $stmt ? 'ok' : 'error';
                
                // Test Telegram API (basic check)
                $telegramStatus = !empty($telegramConfig['hotdealsbot_token']) ? 'ok' : 'error';
                
                // Check log directory
                $logDir = __DIR__ . '/../logs';
                $logStatus = is_writable($logDir) ? 'ok' : 'error';
                
                echo json_encode([
                    'status' => 'success',
                    'service' => 'HotDeals Bot API',
                    'version' => '1.2.0',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'checks' => [
                        'database' => $dbStatus,
                        'telegram' => $telegramStatus,
                        'logging' => $logStatus
                    ]
                ]);
            } catch (Exception $e) {
                throw new Exception('Health check failed: ' . $e->getMessage(), 503);
            }
            break;

        default:
            throw new Exception('Unknown action', 400);
    }

} catch (Exception $e) {
    // Enhanced error logging
    logApi('API error', [
        'error_message' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'action' => $input['action'] ?? 'unknown'
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
        'request_id' => uniqid('hd_', true)
    ]);
}
?>