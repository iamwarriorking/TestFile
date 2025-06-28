<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once 'bot.php';

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
        $logFile = __DIR__ . '/../logs/amezprice_bot.log';
        
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

// FIXED: Rate limiting function with proper directory creation
function checkRateLimit($clientIP) {
    // Use logs directory instead of storage for rate limiting
    $rateLimitDir = __DIR__ . '/../storage/rate_limit';
    $rateLimitFile = $rateLimitDir . '/amezprice_rl_' . md5($clientIP) . '.json';
    
    // Create directory if it doesn't exist
    if (!is_dir($rateLimitDir)) {
        if (!mkdir($rateLimitDir, 0755, true)) {
            logWebhook('Failed to create rate limit directory', ['dir' => $rateLimitDir]);
            return true; // Allow request if we can't create directory
        }
    }
    
    $currentTime = time();
    $windowSize = 60; // 1 minute window
    $maxRequests = 30; // 30 requests per minute
    
    $requests = [];
    if (file_exists($rateLimitFile)) {
        $content = file_get_contents($rateLimitFile);
        $requests = json_decode($content, true) ?: [];
    }
    
    // Remove old requests outside the window
    $requests = array_filter($requests, function($timestamp) use ($currentTime, $windowSize) {
        return ($currentTime - $timestamp) < $windowSize;
    });
    
    if (count($requests) >= $maxRequests) {
        return false;
    }
    
    $requests[] = $currentTime;
    
    try {
        file_put_contents($rateLimitFile, json_encode($requests), LOCK_EX);
    } catch (Exception $e) {
        logWebhook('Rate limit file write error', ['error' => $e->getMessage()]);
    }
    
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

    // Read raw input
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        throw new Exception('Empty request body', 400);
    }

    // Parse and validate JSON
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg(), 400);
    }

    logWebhook('Input parsed successfully', ['data_keys' => array_keys($input)]);

    // Handle both regular messages and callback queries
    $chatId = null;
    $message = '';
    $user = null;
    $callbackQuery = null;
    $messageId = null; // ✅ ADDED: Track message ID for replies

    if (isset($input['callback_query'])) {
        $callbackQuery = $input['callback_query'];
        $chatId = $callbackQuery['message']['chat']['id'];
        $message = $callbackQuery['data'] ?? '';
        $user = $callbackQuery['from'];
        $messageId = $callbackQuery['message']['message_id']; // ✅ ADDED
        
        logWebhook('Processing callback query', [
            'callback_data' => $message,
            'user_id' => $user['id'],
            'message_id' => $messageId
        ]);
    } elseif (isset($input['message'])) {
        $chatId = $input['message']['chat']['id'];
        $message = $input['message']['text'] ?? '';
        $user = $input['message']['from'];
        $messageId = $input['message']['message_id']; // ✅ ADDED
        
        logWebhook('Processing message', [
            'chatId' => $chatId,
            'message' => $message,
            'user_id' => $user['id'],
            'message_id' => $messageId
        ]);
    } else {
        throw new Exception('Invalid update type - no message or callback_query found', 400);
    }

    // Validate required fields
    if (!$chatId || !$user || !isset($user['id'])) {
        logWebhook('Missing required fields', [
            'chatId' => $chatId,
            'user' => $user
        ]);
        throw new Exception('Missing required fields', 400);
    }

    // Store or update user in database with enhanced validation
    try {
        if (!empty($user['id']) && 
            !empty($user['first_name']) && 
            is_numeric($user['id']) &&     // Must be numeric
            $user['id'] > 1000000) {       // Valid Telegram IDs are large numbers
            
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO users (
                    telegram_id,
                    first_name,
                    last_name,
                    username,
                    language_code,
                    last_interaction,
                    created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    NOW(),
                    NOW()
                ) ON DUPLICATE KEY UPDATE 
                    first_name = VALUES(first_name),
                    last_name = VALUES(last_name),
                    username = VALUES(username),
                    language_code = VALUES(language_code),
                    last_interaction = NOW()
            ");

            $stmt->execute([
                $user['id'],
                substr($user['first_name'], 0, 100),
                substr($user['last_name'] ?? '', 0, 100),
                substr($user['username'] ?? '', 0, 50),
                substr($user['language_code'] ?? '', 0, 10)
            ]);

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
        $result = handleCallbackQuery($chatId, $message, $user, $callbackQuery['id']);
        
        // Answer callback query
        answerCallbackQuery($callbackQuery['id'], $result ? "✅ Done!" : null);
    } else {
        // ✅ FIXED: Pass message_id for reply support
        $result = handleMessage($chatId, $message, $user, $messageId);
    }
    
    logWebhook('Message/Callback handled', [
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