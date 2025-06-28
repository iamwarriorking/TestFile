<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once '../middleware/csrf.php';

// Enable error reporting for development
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Set response headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

/**
 * Format currency amount
 * @param float $amount
 * @return string
 */
function formatCurrency($amount) {
    return number_format($amount, 0, '.', ',');
}

/**
 * Generate notification message
 * @param array $product
 * @param array $details
 * @param string $messageType
 * @return string
 */
function generateMessage($product, $details, $messageType) {
    $message = '';
    switch ($messageType) {
        case 'price_drop':
            $decrease = $details['previous_price'] - $details['current_price'];
            $percentage = round(($decrease / $details['previous_price']) * 100);
            $message = sprintf(
                "⬇️ Price Drop Alert! ₹%s (%d%% off)\n\n" .
                "[%s](%s)\n\n" .
                "Previous: ₹%s\n" .
                "**Current: ₹%s**\n\n" .
                "💰 Lowest Ever: ₹%s\n" .
                "📈 Highest: ₹%s\n\n" .
                "🔥 %d users tracking\n" .
                "⌚ Updated: %s",
                formatCurrency($decrease),
                $percentage,
                $product['name'],
                $product['affiliate_link'],
                formatCurrency($details['previous_price']),
                formatCurrency($details['current_price']),
                formatCurrency($details['lowest_price'] ?? $details['current_price']),
                formatCurrency($details['highest_price'] ?? $details['previous_price']),
                $details['tracker_count'],
                date('d M Y, h:i A', strtotime($product['last_updated']))
            );
            break;

        case 'price_increase':
            $increase = $details['current_price'] - $details['previous_price'];
            $percentage = round(($increase / $details['previous_price']) * 100);
            $message = sprintf(
                "⬆️ Price Increase Alert! ₹%s (%d%% up)\n\n" .
                "[%s](%s)\n\n" .
                "Previous: ₹%s\n" .
                "**Current: ₹%s**\n\n" .
                "💰 Lowest Ever: ₹%s\n" .
                "📈 Highest: ₹%s\n\n" .
                "🔥 %d users tracking\n" .
                "⌚ Updated: %s",
                formatCurrency($increase),
                $percentage,
                $product['name'],
                $product['affiliate_link'],
                formatCurrency($details['previous_price']),
                formatCurrency($details['current_price']),
                formatCurrency($details['lowest_price'] ?? $details['previous_price']),
                formatCurrency($details['highest_price'] ?? $details['current_price']),
                $details['tracker_count'],
                date('d M Y, h:i A', strtotime($product['last_updated']))
            );
            break;

        case 'low_stock':
            $message = sprintf(
                "⚠️ Low Stock Alert! Only %d left\n\n" .
                "[%s](%s)\n\n" .
                "**Current Price: ₹%s**\n\n" .
                "💰 Lowest Ever: ₹%s\n" .
                "📈 Highest: ₹%s\n\n" .
                "🔥 %d users tracking\n" .
                "⌚ Updated: %s",
                $details['quantity'],
                $product['name'],
                $product['affiliate_link'],
                formatCurrency($details['current_price']),
                formatCurrency($details['lowest_price'] ?? $details['current_price']),
                formatCurrency($details['highest_price'] ?? $details['current_price']),
                $details['tracker_count'],
                date('d M Y, h:i A', strtotime($product['last_updated']))
            );
            break;

        case 'out_of_stock':
            $message = sprintf(
                "😔 Product Out of Stock\n\n" .
                "[%s](%s)\n\n" .
                "Last Price: ₹%s\n\n" .
                "💰 Lowest Ever: ₹%s\n" .
                "📈 Highest: ₹%s\n\n" .
                "🔥 %d users tracking\n" .
                "⌚ Updated: %s",
                $product['name'],
                $product['affiliate_link'],
                formatCurrency($details['previous_price']),
                formatCurrency($details['lowest_price'] ?? $details['previous_price']),
                formatCurrency($details['highest_price'] ?? $details['previous_price']),
                $details['tracker_count'],
                date('d M Y, h:i A', strtotime($product['last_updated']))
            );
            break;

        case 'in_stock':
            $message = sprintf(
                "🎉 Product Back in Stock!\n\n" .
                "[%s](%s)\n\n" .
                "**Current Price: ₹%s**\n\n" .
                "💰 Lowest Ever: ₹%s\n" .
                "📈 Highest: ₹%s\n\n" .
                "🔥 %d users tracking\n" .
                "⌚ Updated: %s",
                $product['name'],
                $product['affiliate_link'],
                formatCurrency($details['current_price']),
                formatCurrency($details['lowest_price'] ?? $details['current_price']),
                formatCurrency($details['highest_price'] ?? $details['current_price']),
                $details['tracker_count'],
                date('d M Y, h:i A', strtotime($product['last_updated']))
            );
            break;
    }
    return $message;
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    // Rate limiting setup (optional)
    $maxNotificationsPerMinute = 1000;
    $currentRate = 0; // Implement rate tracking if needed

    // Read and validate input
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        throw new Exception('Empty request body', 400);
    }

    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload: ' . json_last_error_msg(), 400);
    }

    // Validate required fields
    $userIds = $input['user_ids'] ?? [];
    $asin = $input['asin'] ?? '';
    $messageType = $input['message_type'] ?? '';
    $details = $input['details'] ?? [];

    if (empty($userIds) || !is_array($userIds)) {
        throw new Exception('User IDs must be a non-empty array', 400);
    }

    if (empty($asin) || !preg_match('/^[A-Z0-9]{10}$/', $asin)) {
        throw new Exception('Invalid ASIN', 400);
    }

    $validMessageTypes = ['price_drop', 'price_increase', 'low_stock', 'out_of_stock', 'in_stock'];
    if (!in_array($messageType, $validMessageTypes)) {
        throw new Exception('Invalid message type', 400);
    }

    if (empty($details)) {
        throw new Exception('Details are required', 400);
    }

    // Get product details
    $stmt = $pdo->prepare("
        SELECT p.*, 
               MIN(ph.price) as lowest_price,
               MAX(ph.price) as highest_price
        FROM products p
        LEFT JOIN price_history ph ON p.asin = ph.product_asin
        WHERE p.asin = ?
        GROUP BY p.asin
    ");
    $stmt->execute([$asin]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        throw new Exception('Product not found', 404);
    }

    // Prepare notification buttons
    $buttons = [
        ['text' => '🛒 Buy Now', 'url' => $product['affiliate_link']],
        ['text' => '🔴 Stop Tracking', 'callback_data' => "stop_{$asin}"],
        ['text' => '📈 Price History', 'url' => $product['website_url']],
        ['text' => '🛍️ More Deals', 'url' => $telegramConfig['channels']['amezprice']]
    ];

    $botToken = $telegramConfig['amezpricebot_token'];
    $baseUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";

    // Initialize metrics
    $metrics = [
        'total' => count($userIds),
        'success' => 0,
        'failed' => 0,
        'errors' => [],
        'start_time' => microtime(true)
    ];

    // Process notifications in batches
    $batchSize = 100;
    $batches = array_chunk($userIds, $batchSize);

    foreach ($batches as $batch) {
        foreach ($batch as $userId) {
            try {
                // Generate notification message
                $message = generateMessage($product, $details, $messageType);
                if (empty($message)) {
                    throw new Exception('Failed to generate message');
                }

                // Prepare and send notification
                $payload = [
                    'chat_id' => $userId,
                    'text' => $message,
                    'parse_mode' => 'Markdown',
                    'disable_web_page_preview' => true,
                    'reply_markup' => json_encode([
                        'inline_keyboard' => array_chunk($buttons, 2)
                    ])
                ];

                $ch = curl_init($baseUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query($payload),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);

                $response = curl_exec($ch);
                $error = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($error || $httpCode !== 200) {
                    throw new Exception("Failed to send notification: $error (HTTP $httpCode)");
                }

                $metrics['success']++;

            } catch (Exception $e) {
                $metrics['failed']++;
                $metrics['errors'][] = [
                    'user_id' => $userId,
                    'error' => $e->getMessage()
                ];

                // Limit error collection
                if (count($metrics['errors']) >= 10) {
                    $metrics['errors'] = array_slice($metrics['errors'], 0, 10);
                }
            }
        }
        // Rate limiting
        usleep(100000); // 100ms delay between batches
    }

    // Calculate metrics
    $metrics['duration'] = round(microtime(true) - $metrics['start_time'], 2);
    $metrics['rate'] = round($metrics['total'] / $metrics['duration'], 2);

    // Return success response with metrics
    echo json_encode([
        'status' => 'success',
        'message' => 'Notifications processed',
        'metrics' => [
            'total' => $metrics['total'],
            'success' => $metrics['success'],
            'failed' => $metrics['failed'],
            'duration' => $metrics['duration'] . 's',
            'rate' => $metrics['rate'] . ' notifications/second',
            'errors' => $metrics['errors']
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    // Log the error
    error_log("Notification error: {$e->getMessage()}");
    
    // Send appropriate HTTP status code
    $statusCode = $e->getCode() ?: 500;
    http_response_code($statusCode);
    
    // Return error response
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'code' => $statusCode,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>