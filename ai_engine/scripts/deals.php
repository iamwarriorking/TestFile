<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/telegram.php';
require_once __DIR__ . '/../config/safety.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Rubix\ML\Datasets\Labeled;

// Security check
if (!restrictAccess(__FILE__)) {
    http_response_code(403);
    exit('Access denied');
}

// Rate limiting class for Telegram API
class TelegramRateLimit {
    private static $lastCall = 0;
    private static $callCount = 0;
    
    public static function throttle() {
        $now = microtime(true);
        if ($now - self::$lastCall < 1) {
            self::$callCount++;
            if (self::$callCount > 30) { // 30 calls per minute
                sleep(2);
                self::$callCount = 0;
            }
        } else {
            self::$callCount = 0;
        }
        self::$lastCall = $now;
    }
}

// Input validation function
function validateProductData($product) {
    if (!isset($product['asin']) || !preg_match('/^[A-Z0-9]{10}$/', $product['asin'])) {
        throw new InvalidArgumentException('Invalid ASIN format');
    }
    
    if (!isset($product['current_price']) || $product['current_price'] <= 0) {
        throw new InvalidArgumentException('Invalid price data');
    }
    
    if (!isset($product['affiliate_link']) || !filter_var($product['affiliate_link'], FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Invalid affiliate link');
    }
    
    return true;
}

try {
    // Start execution time tracking
    $startTime = microtime(true);
    
    // Enhanced query with proper joins and error handling
    $stmt = $pdo->prepare("
        SELECT DISTINCT p.asin, p.name, p.current_price, p.highest_price, p.rating, 
               p.category, p.affiliate_link, p.merchant, ub.user_id, ub.is_ai_suggested 
        FROM products p 
        INNER JOIN user_behavior ub ON p.asin = ub.asin 
        WHERE p.current_price <= p.highest_price * 0.7 
        AND p.rating >= 3.5 
        AND p.current_price > 0
        AND p.affiliate_link IS NOT NULL
        AND p.affiliate_link != ''
        LIMIT 50
    ");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $processedDeals = 0;
    $sentMessages = 0;
    $errors = [];

    foreach ($products as $product) {
        try {
            // Validate product data
            validateProductData($product);
            
            if ($product['is_ai_suggested']) continue;

            // Get tracker count for this product
            $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
            $trackerCountStmt->execute([$product['asin']]);
            $trackerCount = $trackerCountStmt->fetchColumn() ?: 0;

            // Get similar users based on cluster
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE cluster = (SELECT cluster FROM users WHERE id = ?) AND id != ?");
            $stmt->execute([$product['user_id'], $product['user_id']]);
            $similarUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($similarUsers as $userId) {
                try {
                    // Insert AI recommendation
                    $stmt = $pdo->prepare("
                        INSERT INTO user_behavior (user_id, asin, is_ai_suggested, interaction_type, created_at)
                        VALUES (?, ?, TRUE, 'recommended', NOW())
                        ON DUPLICATE KEY UPDATE 
                        is_ai_suggested = TRUE, 
                        interaction_type = 'recommended',
                        updated_at = NOW()
                    ");
                    $stmt->execute([$userId, $product['asin']]);

                    // Apply rate limiting
                    TelegramRateLimit::throttle();

                    // Prepare Telegram message
                    $discountPercent = round(($product['highest_price'] - $product['current_price']) / $product['highest_price'] * 100);
                    
                    $message = "🎉 **Hot deal for you!** 🎉\n\n"
                             . "[" . htmlspecialchars($product['name']) . "](" . $product['affiliate_link'] . ")\n\n"
                             . "Highest Price: ₹" . number_format($product['highest_price'], 0, '.', ',') . "\n\n"
                             . "**Current Price: ₹" . number_format($product['current_price'], 0, '.', ',') . "**\n\n"
                             . $discountPercent . "% off\n\n"
                             . "🔥 {$trackerCount} users are tracking this!\n\n"
                             . "🔔 Updated at " . date('d M Y, h:i A');

                    $payload = [
                        'chat_id' => $userId,
                        'text' => $message,
                        'parse_mode' => 'Markdown',
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [
                                    ['text' => 'Buy Now', 'url' => $product['affiliate_link']],
                                    ['text' => 'Price History', 'url' => "https://amezprice.com/product/{$product['merchant']}/pid={$product['asin']}"],
                                    ['text' => 'Set price alert for this!', 'url' => 'https://t.me/AmezPriceBot?start=alert_' . $product['asin']]
                                ]
                            ]
                        ])
                    ];

                    // Send Telegram message
                    $ch = curl_init("https://api.telegram.org/bot{$telegramConfig['hotdealsbot_token']}/sendMessage");
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($httpCode === 200) {
                        $sentMessages++;
                    } else {
                        $errors[] = "Failed to send message to user {$userId}: HTTP {$httpCode}";
                    }

                } catch (Exception $e) {
                    $errors[] = "Error processing user {$userId}: " . $e->getMessage();
                }
            }

            $processedDeals++;

        } catch (Exception $e) {
            $errors[] = "Error processing product {$product['asin']}: " . $e->getMessage();
        }
    }

    // Log execution results
    $executionTime = microtime(true) - $startTime;
    $logMessage = "[" . date('Y-m-d H:i:s') . "] Deal recommendations completed:\n";
    $logMessage .= "  Products processed: {$processedDeals}\n";
    $logMessage .= "  Messages sent: {$sentMessages}\n";
    $logMessage .= "  Errors: " . count($errors) . "\n";
    $logMessage .= "  Execution time: " . round($executionTime, 2) . " seconds\n";
    $logMessage .= "  Memory usage: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB\n\n";
    
    if (!empty($errors)) {
        $logMessage .= "  Error details:\n";
        foreach ($errors as $error) {
            $logMessage .= "    - {$error}\n";
        }
    }

    $logDir = __DIR__ . '/../logs/';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logDir . 'deals.log', $logMessage, FILE_APPEND | LOCK_EX);

} catch (Exception $e) {
    // Log error
    $errorMessage = "[" . date('Y-m-d H:i:s') . "] Deal recommendations failed: " . $e->getMessage() . "\n";
    file_put_contents('../logs/deals.log', $errorMessage, FILE_APPEND | LOCK_EX);
    error_log("Deal recommendations error: " . $e->getMessage());
    exit(1);
}
?>