<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once 'hotdealsbot.php';

// Enable error reporting for development
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Validate database connection
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// Test database connection
try {
    $pdo->query('SELECT 1');
} catch (PDOException $e) {
    logWebhook('Database connection test failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database unavailable']);
    exit;
}

// Add logging function
function logWebhook($message, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/hotdeals_bot.log';
        
        // Create logs directory if it doesn't exist
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
        error_log("Logging error: " . $e->getMessage());
    }
}

// Rate limiting function
function checkRateLimit($clientIP) {
    $rateLimitFile = __DIR__ . '/../logs/hotdeals_rate_limit_' . md5($clientIP) . '.json';
    $currentTime = time();
    $windowSize = 60; // 1 minute window
    $maxRequests = 30; // 30 requests per minute
    
    $requests = [];
    if (file_exists($rateLimitFile)) {
        $requests = json_decode(file_get_contents($rateLimitFile), true) ?: [];
    }
    
    // Remove old requests outside the window
    $requests = array_filter($requests, function($timestamp) use ($currentTime, $windowSize) {
        return ($currentTime - $timestamp) < $windowSize;
    });
    
    if (count($requests) >= $maxRequests) {
        return false;
    }
    
    $requests[] = $currentTime;
    file_put_contents($rateLimitFile, json_encode($requests), LOCK_EX);
    return true;
}

// FIXED: Memory-efficient header collection
function getRequestHeaders() {
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $header = str_replace('HTTP_', '', $key);
            $header = str_replace('_', '-', $header);
            $headers[$header] = substr($value, 0, 200); // Limit header values to 200 chars
        }
    }
    // Only return essential headers
    return array_intersect_key($headers, array_flip(['USER-AGENT', 'CONTENT-TYPE', 'X-FORWARDED-FOR']));
}

try {
    // Check rate limiting
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!checkRateLimit($clientIP)) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Rate limit exceeded']);
        exit;
    }

    // Log incoming request - FIXED: No memory-heavy getallheaders()
    logWebhook('Webhook received', [
        'headers' => getRequestHeaders(),
        'ip' => $clientIP,
        'input_length' => strlen(file_get_contents('php://input'))
    ]);

    // Validate and parse input
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        throw new Exception('Empty request body', 400);
    }

    $input = json_decode($rawInput, true);
    if (!$input || json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON format: ' . json_last_error_msg(), 400);
    }

    // Handle both message and callback_query
    $chatId = null;
    $message = '';
    $user = null;
    $callbackQuery = null;

    if (isset($input['callback_query'])) {
        $callbackQuery = $input['callback_query'];
        $chatId = $callbackQuery['message']['chat']['id'];
        $message = $callbackQuery['data'] ?? '';
        $user = $callbackQuery['from'];
        
        logWebhook('Processing callback query', [
            'callback_data' => $message,
            'user_id' => $user['id']
        ]);
    } elseif (isset($input['message'])) {
        $chatId = $input['message']['chat']['id'];
        $message = $input['message']['text'] ?? '';
        $user = $input['message']['from'];
        
        logWebhook('Processing message', [
            'chatId' => $chatId,
            'message' => $message,
            'user_id' => $user['id']
        ]);
    } else {
        throw new Exception('Invalid update type - no message or callback_query found', 400);
    }

    // Validate required fields
    if (!$chatId || !$user || !isset($user['id'])) {
        throw new Exception('Missing required fields: chatId or user data', 400);
    }

    // Store user in database with better error handling
    try {
        if (!empty($user['id']) && 
            !empty($user['first_name']) && 
            is_numeric($user['id']) &&     
            $user['id'] > 1000000) {       

            $pdo->beginTransaction();

            // First check if user exists
            $stmt = $pdo->prepare("
                SELECT telegram_id 
                FROM hotdealsbot 
                WHERE telegram_id = ? 
                LIMIT 1
            ");
            $stmt->execute([$user['id']]);
            $existingUser = $stmt->fetch();

            if (!$existingUser) {
                // If new user, insert with default category
                $stmt = $pdo->prepare("
                    INSERT INTO hotdealsbot (
                        telegram_id,
                        first_name,
                        last_name,
                        username,
                        language_code,
                        category,
                        merchant,
                        is_active
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?,
                        'both',
                        TRUE
                    )
                ");

                $stmt->execute([
                    $user['id'],
                    $user['first_name'],
                    $user['last_name'] ?? null,
                    $user['username'] ?? null,
                    $user['language_code'] ?? null,
                    null
                ]);
            }

            $pdo->commit();
            
            logWebhook('User stored/updated', ['user_id' => $user['id']]);
        } else {
            logWebhook('Invalid user data, skipping storage', ['user_id' => $user['id'] ?? 'unknown']);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        logWebhook('Error storing user', [
            'user_id' => $user['id'] ?? 'unknown',
            'error' => $e->getMessage()
        ]);
        // Continue processing even if user storage fails
    }

    // Handle the message or callback
    if ($callbackQuery) {
        $result = handleHotDealsCallback($chatId, $message, $user, $callbackQuery['id']);
        
        // Answer callback query
        answerHotDealsCallback($callbackQuery['id']);
    } else {
        $result = handleHotDealsMessage($chatId, $message, $user, $input);
    }
    
    logWebhook('Request handled successfully', [
        'result' => $result,
        'type' => $callbackQuery ? 'callback' : 'message'
    ]);

    // Return success response
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'timestamp' => date('Y-m-d H:i:s'),
        'processed' => $callbackQuery ? 'callback_query' : 'message'
    ]);

} catch (Exception $e) {
    logWebhook('Error in webhook', [
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($statusCode);
    
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'code' => $statusCode,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>