<?php
require_once '../config/database.php';
require_once '../config/telegram.php';

// Enhanced logging function with rotation
function logBot($message, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/amezprice_bot.log';
        $logDir = dirname($logFile);
        
        // Ensure log directory exists
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Log rotation - keep files under 10MB
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
            'message' => $message
        ], $data);
        
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log("AmezPrice Bot logging error: " . $e->getMessage());
    }
}

// ✅ UPDATED: Function to download product image via API
function downloadProductImage($imageUrl, $asin, $productUrl = '') {
    global $telegramConfig;
    
    try {
        if (empty($imageUrl)) return null;
        
        // ✅ FIX: Detect merchant from product URL or image URL
        $merchant = 'amazon'; // default
        if (!empty($productUrl)) {
            if (strpos($productUrl, 'flipkart') !== false) {
                $merchant = 'flipkart';
            } elseif (strpos($productUrl, 'amazon') !== false || strpos($productUrl, 'amzn') !== false) {
                $merchant = 'amazon';
            }
        } elseif (!empty($imageUrl)) {
            if (strpos($imageUrl, 'flipkart') !== false || strpos($imageUrl, 'fkcdn') !== false || strpos($imageUrl, 'flixcart') !== false) {
                $merchant = 'flipkart';
            } else {
                $merchant = 'amazon';
            }
        }
        
        logBot('Downloading product image via API', [
            'asin' => $asin,
            'merchant' => $merchant,
            'image_url' => $imageUrl,
            'product_url' => $productUrl
        ]);
        
        // ✅ FIXED: Call the API endpoint with correct parameters
        $ch = curl_init('https://amezprice.com/api/download_image.php');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'merchant' => $merchant,
                'asin' => $asin,
                'image_url' => $imageUrl,
                'update_db' => true,
                'force_download' => false
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            logBot('cURL error in image download API', [
                'error' => $curlError,
                'asin' => $asin,
                'merchant' => $merchant
            ]);
            return null;
        }
        
        if ($httpCode !== 200) {
            logBot('HTTP error in image download API', [
                'http_code' => $httpCode,
                'response' => $response,
                'asin' => $asin,
                'merchant' => $merchant
            ]);
            return null;
        }
        
        $data = json_decode($response, true);
        
        if ($data && $data['status'] === 'success' && !empty($data['path'])) {
            logBot('Image downloaded successfully via API', [
                'asin' => $asin,
                'merchant' => $merchant,
                'action' => $data['action'] ?? 'unknown',
                'image_path' => $data['path']
            ]);
            
            // ✅ FIXED: Return full path for file existence check
            $fullPath = __DIR__ . '/../' . $data['path'];
            return $fullPath;
        } else {
            logBot('Image download API failed', [
                'asin' => $asin,
                'merchant' => $merchant,
                'response' => $data
            ]);
            return null;
        }
        
    } catch (Exception $e) {
        logBot('Image download API error', [
            'error' => $e->getMessage(),
            'asin' => $asin
        ]);
        return null;
    }
}

// Function to send photo message with caption
function sendPhoto($chatId, $photoPath, $caption, $replyMarkup = null) {
    global $telegramConfig;
    
    try {
        $url = "https://api.telegram.org/bot{$telegramConfig['amezpricebot_token']}/sendPhoto";
        
        $postFields = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'photo' => new CURLFile($photoPath)
        ];
        
        if ($replyMarkup) {
            $postFields['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($httpCode === 200);
        
    } catch (Exception $e) {
        logBot('Send photo error', ['error' => $e->getMessage()]);
        return false;
    }
}

// ✅ NEW FUNCTION: Get actual user_id from telegram_id
function getUserIdFromTelegramId($telegramId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        logBot('Error getting user_id from telegram_id', [
            'telegram_id' => $telegramId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * ✅ FIXED: Enhanced message sending with HTML parsing and reply support
 */
function sendMessage($chatId, $text, $replyMarkup = null, $retries = 3, $replyToMessageId = null) {
    global $telegramConfig;
    
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        try {
            logBot('Preparing to send message', [
                'chatId' => $chatId,
                'attempt' => $attempt,
                'text_length' => strlen($text),
                'has_markup' => !is_null($replyMarkup),
                'reply_to_message_id' => $replyToMessageId
            ]);

            $url = "https://api.telegram.org/bot{$telegramConfig['amezpricebot_token']}/sendMessage";

            // ✅ FIXED: Use HTML instead of Markdown + Add reply support
            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',  // Changed from Markdown to HTML
                'disable_web_page_preview' => true
            ];

            // ✅ FIXED: Add reply_to_message_id support
            if ($replyToMessageId) {
                $payload['reply_to_message_id'] = $replyToMessageId;
            }

            if ($replyMarkup) {
                $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
            }

            logBot('Sending request to Telegram', [
                'url' => $url,
                'payload_size' => strlen(json_encode($payload)),
                'attempt' => $attempt
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'AmezPrice-Bot/1.2',
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json'
                ]
            ]);

            $response = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($error) {
                throw new Exception("cURL error: $error");
            }

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                if ($result && $result['ok']) {
                    logBot('Message sent successfully', [
                        'chatId' => $chatId,
                        'attempt' => $attempt,
                        'message_id' => $result['result']['message_id'] ?? null
                    ]);
                    return true;
                } else {
                    $errorDesc = $result['description'] ?? 'Unknown Telegram API error';
                    logBot('Telegram API error details', [
                        'chatId' => $chatId,
                        'error_description' => $errorDesc,
                        'response' => $response,
                        'text_preview' => substr($text, 0, 100)
                    ]);
                    throw new Exception("Telegram API error: $errorDesc");
                }
            } else {
                logBot('HTTP error details', [
                    'chatId' => $chatId,
                    'http_code' => $httpCode,
                    'response' => substr($response, 0, 500),
                    'text_preview' => substr($text, 0, 100)
                ]);
                throw new Exception("HTTP error: $httpCode");
            }

        } catch (Exception $e) {
            logBot('Message send error', [
                'chatId' => $chatId,
                'attempt' => $attempt,
                'error' => $e->getMessage(),
                'will_retry' => ($attempt < $retries)
            ]);

            if ($attempt === $retries) {
                return false;
            }
            
            // ✅ FIXED: Cast to int to avoid float to int precision loss warning
            $sleepTime = (int)min(pow(2, $attempt - 1) + rand(0, 1000) / 1000, 8);
            sleep($sleepTime);
        }
    }

    return false;
}

/**
 * Enhanced user registration with duplicate prevention
 */
function registerUser($chatId, $username, $firstName, $lastName = null) {
    global $pdo;
    
    try {
        // Input validation
        if (!$chatId || !is_numeric($chatId)) {
            throw new Exception('Invalid chat ID');
        }
        
        if (empty($firstName)) {
            $firstName = 'User';
        }
        
        // Use transaction for consistency
        $pdo->beginTransaction();
        
        // Check if user exists
        $stmt = $pdo->prepare("SELECT telegram_id, username, telegram_username, first_name FROM users WHERE telegram_id = ?");
        $stmt->execute([$chatId]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingUser) {
            // Update existing user if data has changed
            if ($existingUser['username'] !== $username || 
                $existingUser['telegram_username'] !== $username || 
                $existingUser['first_name'] !== $firstName) {
                
                // ✅ FIX: Save telegram username in BOTH fields
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET username = ?, telegram_username = ?, first_name = ?, last_name = ?, last_interaction = NOW() 
                    WHERE telegram_id = ?
                ");
                $stmt->execute([$username, $username, $firstName, $lastName, $chatId]);
                
                logBot('User updated with both username fields', [
                    'user_id' => $chatId,
                    'telegram_username' => $username,
                    'changes' => [
                        'username' => $username !== $existingUser['username'],
                        'telegram_username' => $username !== $existingUser['telegram_username'],
                        'first_name' => $firstName !== $existingUser['first_name']
                    ]
                ]);
            }
        } else {
            // Insert new user
            $stmt = $pdo->prepare("
                INSERT INTO users (telegram_id, username, telegram_username, first_name, last_name, created_at, last_interaction) 
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$chatId, $username, $username, $firstName, $lastName]);
            
            logBot('New user registered with both username fields', [
                'user_id' => $chatId,
                'username' => $username,
                'telegram_username' => $username,
                'first_name' => $firstName
            ]);
        }
        
        $pdo->commit();
        return true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        logBot('User registration error', [
            'user_id' => $chatId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Enhanced price validation with dynamic category-based ranges
 */
/**
 * NEW: Smart price threshold calculation based on product history
 */
function getSmartPriceThreshold($asin, $currentPrice) {
    global $pdo;
    
    try {
        // Get product basic info
        $stmt = $pdo->prepare("
            SELECT lowest_price, created_at
            FROM products 
            WHERE asin = ?
        ");
        $stmt->execute([$asin]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            return [
                'min' => max(10, $currentPrice * 0.9),
                'max' => $currentPrice - 1,
                'has_enough_data' => false
            ];
        }
        
        // Check data age (3+ months)
        $createdDate = new DateTime($product['created_at']);
        $now = new DateTime();
        $monthsDiff = $now->diff($createdDate)->m + ($now->diff($createdDate)->y * 12);
        
        // Get price history count from new table
        $historyStmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM price_history 
            WHERE product_asin = ?
        ");
        $historyStmt->execute([$asin]);
        $historyCount = $historyStmt->fetchColumn();
        
        $hasEnoughData = $monthsDiff >= 3 && $historyCount >= 10;
        
        if ($hasEnoughData) {
            return [
                'min' => max(10, $product['lowest_price']),
                'max' => $currentPrice - 1,
                'has_enough_data' => true,
                'lowest_price' => $product['lowest_price']
            ];
        } else {
            return [
                'min' => max(10, $currentPrice * 0.9),
                'max' => $currentPrice - 1,
                'has_enough_data' => false
            ];
        }
        
    } catch (Exception $e) {
        return [
            'min' => max(10, $currentPrice * 0.9),
            'max' => $currentPrice - 1,
            'has_enough_data' => false
        ];
    }
}

/**
 * Get formatted price history from new price_history table
 */
function getPriceHistory($asin) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT date_recorded, price 
            FROM price_history 
            WHERE product_asin = ? 
            ORDER BY date_recorded ASC
        ");
        $stmt->execute([$asin]);
        $historyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $priceHistory = [];
        
        foreach ($historyData as $record) {
            $month = substr($record['date_recorded'], 0, 7);
            $price = (float)$record['price'];
            
            if (!isset($priceHistory[$month])) {
                $priceHistory[$month] = ['highest' => $price, 'lowest' => $price];
            } else {
                $priceHistory[$month]['highest'] = max($priceHistory[$month]['highest'], $price);
                $priceHistory[$month]['lowest'] = min($priceHistory[$month]['lowest'], $price);
            }
        }
        
        return $priceHistory;
        
    } catch (Exception $e) {
        logBot('Error fetching price history', [
            'asin' => $asin,
            'error' => $e->getMessage()
        ]);
        return [];
    }
}

/**
 * Enhanced URL validation for product tracking
 */
function validateProductUrl($url) {
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    // Check if it's from supported domains (INCLUDING SHORT URLs)
    $supportedDomains = [
        'amazon.in',
        'www.amazon.in',
        'flipkart.com',
        'www.flipkart.com',
        'amzn.in',           // Short URL support
        'amzn.to',           // Short URL support  
        'amzn.com',          // Short URL support
    ];
    
    $parsedUrl = parse_url(strtolower($url));
    $host = $parsedUrl['host'] ?? '';
    
    if (!in_array($host, $supportedDomains)) {
        return false;
    }
    
    // Enhanced validation for Amazon URLs (including short URLs)
    if (strpos($host, 'amazon') !== false || strpos($host, 'amzn') !== false) {
        return preg_match('/\/dp\/[A-Z0-9]{10}/i', $url) || 
               preg_match('/\/gp\/product\/[A-Z0-9]{10}/i', $url) ||
               preg_match('/\/product\/[A-Z0-9]{10}/i', $url) ||
               preg_match('/amzn\.(in|to|com)\/d\/[a-zA-Z0-9]+/i', $url) ||
               preg_match('/amzn\.(in|to|com)\/[a-zA-Z0-9]+/i', $url) ||
               preg_match('/\/dp\/[^\/]+\/[A-Z0-9]{10}/i', $url);
    }
    
    // Additional validation for Flipkart URLs
    if (strpos($host, 'flipkart') !== false) {
        return preg_match('/\/p\/[a-zA-Z0-9-]+/', $url);
    }
    
    return true;
}

/**
 * ✅ FIXED: Enhanced message sanitization for HTML format
 */
function sanitizeForHTML($text) {
    // Remove or escape problematic characters for HTML parsing
    $text = strip_tags($text); // Remove HTML tags
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); // Escape HTML entities
    $text = preg_replace('/[^\x20-\x7E\x80-\xFF]/', '', $text); // Remove non-printable chars
    return trim($text);
}

/**
 * ✅ FIXED: Enhanced message handler with proper user_id lookup
 */
function handleMessage($chatId, $message, $user, $messageId = null) {
    global $pdo, $telegramConfig;

    try {
        logBot('Processing message', [
            'chatId' => $chatId,
            'message_length' => strlen($message),
            'message_id' => $messageId,
            'user' => [
                'username' => $user['username'] ?? null,
                'first_name' => $user['first_name'] ?? null
            ]
        ]);

        // Register/update user
        registerUser(
            $chatId,
            $user['username'] ?? null,
            $user['first_name'] ?? 'User',
            $user['last_name'] ?? null
        );

        // ✅ FIX: Get actual user_id from telegram_id for all database queries
        $userId = getUserIdFromTelegramId($chatId);
        if (!$userId) {
            logBot('Failed to get user_id from telegram_id', ['telegram_id' => $chatId]);
            return sendMessage($chatId, "❌ User registration error. Please try /start again.", null, 3, $messageId);
        }

        // Handle /start command
        if ($message === '/start') {
            $buttons = array_filter($telegramConfig['buttons']['amezprice'] ?? [], fn($btn) => $btn['enabled'] ?? true);
            $replyMarkup = [
                'inline_keyboard' => array_map(fn($btn) => [['text' => $btn['text'], 'url' => $btn['url']]], $buttons)
            ];

            // ✅ FIXED: HTML formatting instead of Markdown
            $welcomeMessage = sprintf(
                "🎉 %s Welcome to AmezPrice! Great to see you!\n\n" .
                "I track prices for Amazon and Flipkart products to help you snag the best deals!\n\n" .
                "Just send me a product link to start tracking, or use /deal to explore hot deals, or /price to set price alerts.\n\n" .
                "Click /help to get more info",
                $user['username'] ? "@{$user['username']}" : ($user['first_name'] ?? 'User')
            );

            if (!sendMessage($chatId, $welcomeMessage, $replyMarkup)) {
                logBot('Failed to send welcome message', ['chatId' => $chatId]);
            }

            // ✅ FIXED: HTML formatting
            $guideMessage = 
                "🚀 <b>Quick Start Guide:</b>\n\n" .
                "1️⃣ Send me any Amazon/Flipkart product link\n" .
                "2️⃣ Set your desired price alert (optional)\n" .
                "3️⃣ Get notified when prices drop!\n\n" .
                "Ready to save money? Send me a link! 💰";

            sendMessage($chatId, $guideMessage, null, 3, $messageId);
            return true;
        }

        // Handle /help command
        if ($message === '/help') {
            // ✅ FIXED: HTML formatting
            $helpMessage = "🛠️ <b>AmezPrice Bot Help Guide</b> 🛠️\n\n" .
                         "Welcome to your personal price tracking assistant!\n\n" .
                         "📋 <b>Main Commands</b>\n" .
                         "• /start - Start the bot\n" .
                         "• /help - Show this help message\n" .
                         "• /deal - Explore today's best deals\n" .
                         "• /price - Set custom price alerts\n" .
                         "• /list - View your tracked products\n" .
                         "• /stop - Stop tracking products\n\n" .
                         "🔗 <b>Track Products</b>\n" .
                         "Simply send me an Amazon or Flipkart product link\n" .
                         "✅ Supports SHORT URLs like amzn.in/d/xxxxx\n\n" .
                         "🛑 <b>Remove Product</b>\n" .
                         "Use /stop to stop tracking products\n\n" .
                         "📋 <b>View Tracked Products</b>\n" .
                         "Use /list to see your tracked products\n\n" .
                         "🔥 <b>Hot Deals</b>\n" .
                         "Use /deal to explore today's best deals\n\n" .
                         "⚡ <b>Price Alerts</b>\n" .
                         "Use /price to set custom price alerts\n\n" .
                         "💡 <b>Tips:</b>\n" .
                         "• You can track multiple products\n" .
                         "• Set realistic price targets\n" .
                         "• Check /deal for curated offers\n\n" .
                         "Need more help? Visit https://amezprice.com/pages/faq.php";
            
            return sendMessage($chatId, $helpMessage, null, 3, $messageId);
        }

        // Handle /deal command
        if ($message === '/deal') {
            $replyMarkup = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛍️ Amazon Deals', 'url' => 'https://amezprice.com/pages/goldbox.php'],
                        ['text' => '🎁 Flipkart Deals', 'url' => 'https://amezprice.com/pages/flipbox.php']
                    ],
                    [
                        ['text' => '📦 Today\'s Deals', 'url' => 'https://amezprice.com/pages/todays-deals.php'],
                    ],

                ]
            ];
            return sendMessage($chatId, "🔥 Today's Hot Deals\n\nFind the best offers on Amazon and Flipkart!\nClick below to explore deals by category:", $replyMarkup, 3, $messageId);
        }

        // ✅ FIXED: Handle /list command with proper user_id
        if ($message === '/list') {
            $stmt = $pdo->prepare("
                SELECT p.asin, p.name, p.current_price, p.highest_price, p.lowest_price,
                    up.price_threshold, p.affiliate_link, up.created_at
                FROM user_products up 
                JOIN products p ON up.product_asin = p.asin 
                WHERE up.user_id = ? 
                ORDER BY up.created_at DESC 
                LIMIT 10
            ");
            $stmt->execute([$userId]);  // ✅ Use actual user_id instead of telegram_id
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($products)) {
                return sendMessage($chatId, 
                    "📋 <b>Your Tracked Products</b>\n\n" .
                    "You're not tracking any products yet.\n\n" .
                    "Send me an Amazon or Flipkart product link to start tracking prices!",
                    null, 3, $messageId
                );
            }

            // ✅ FIXED: HTML formatting
            $listMessage = "📋 <b>Your Tracked Products</b> (" . count($products) . ")\n\n";
            
            foreach ($products as $index => $product) {
                $alertText = $product['price_threshold'] 
                    ? "Alert at: ₹" . number_format($product['price_threshold'], 0, '.', ',')
                    : "No price alert set";
                
                // ✅ FIXED: Sanitize product name for HTML
                $productName = sanitizeForHTML($product['name']);
                $shortName = substr($productName, 0, 40) . (strlen($productName) > 40 ? '...' : '');
                
                $listMessage .= sprintf(
                    "%d. <a href=\"%s\">%s</a>\n" .
                    "   💰 Current: ₹%s\n" .
                    "   🎯 %s\n" .
                    "   📅 Added: %s\n\n",
                    $index + 1,
                    $product['affiliate_link'],
                    $shortName,
                    number_format($product['current_price'], 0, '.', ','),
                    $alertText,
                    date('M j, Y', strtotime($product['created_at']))
                );
            }

            $listMessage .= "Use /stop to remove products or /price to set alerts.";
            
            return sendMessage($chatId, $listMessage, null, 3, $messageId);
        }

        // ✅ FIXED: Handle /stop command with proper user_id
        if ($message === '/stop') {
            $stmt = $pdo->prepare("
                SELECT p.asin, p.name, up.created_at
                FROM user_products up 
                JOIN products p ON up.product_asin = p.asin 
                WHERE up.user_id = ? 
                ORDER BY up.created_at DESC 
                LIMIT 10
            ");
            $stmt->execute([$userId]);  // ✅ Use actual user_id instead of telegram_id
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($products)) {
                return sendMessage($chatId, 
                    "🛑 <b>Stop Tracking</b>\n\n" .
                    "You're not tracking any products to stop.\n\n" .
                    "Send me a product link to start tracking!",
                    null, 3, $messageId
                );
            }

            $buttons = [];
            foreach ($products as $product) {
                $productName = sanitizeForHTML($product['name']);
                $shortName = substr($productName, 0, 30) . (strlen($productName) > 30 ? '...' : '');
                $buttons[] = [['text' => $shortName, 'callback_data' => "stop_{$product['asin']}"]];
            }

            $replyMarkup = ['inline_keyboard' => $buttons];
            return sendMessage($chatId, "🛑 <b>Stop Tracking Products</b>\n\nSelect a product to stop tracking:", $replyMarkup, 3, $messageId);
        }

        if ($message === '/connect') {
            // Get telegram username from user data
            $telegramUsername = $user['username'] ?? null;
            
            if (!$telegramUsername) {
                return sendMessage($chatId, 
                    "❌ <b>No Username Found</b>\n\n" .
                    "You need to set a Telegram username to use this feature.\n\n" .
                    "📱 <b>How to set username:</b>\n" .
                    "1. Go to Telegram Settings\n" .
                    "2. Tap on 'Username'\n" .
                    "3. Create a unique username\n" .
                    "4. Come back and try /connect again\n\n" .
                    "ℹ️ Username helps us link your account securely.",
                    null, 3, $messageId
                );
            }

            // Check if there's a pending OTP for this username
            $stmt = $pdo->prepare("
                SELECT otp, user_id, email 
                FROM otps 
                WHERE telegram_username = ? AND expires_at > NOW() 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$telegramUsername]);
            $otpData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$otpData) {
                return sendMessage($chatId,
                    "❌ <b>No Connection Request Found</b>\n\n" .
                    "To connect your Telegram account:\n\n" .
                    "🌐 <b>Step 1:</b> Go to AmezPrice website\n" .
                    "1. Login to your account\n" .
                    "2. Go to Account settings\n" .
                    "3. In Telegram section, enter: <code>{$telegramUsername}</code>\n" .
                    "4. Click 'Connect Telegram'\n\n" .
                    "🤖 <b>Step 2:</b> Come back here and send /connect\n\n" .
                    "🔗 <a href=\"https://amezprice.com/user/account.php\">Open Account Settings</a>",
                    null, 3, $messageId
                );
            }

            // Update user's telegram_id in database when they use /connect
            try {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET telegram_id = ?, telegram_username = ? 
                    WHERE id = ? AND telegram_username = ?
                ");
                $stmt->execute([$chatId, $telegramUsername, $otpData['user_id'], $telegramUsername]);
                
                logBot('Telegram ID and username updated for user', [
                    'user_id' => $otpData['user_id'],
                    'telegram_id' => $chatId,
                    'telegram_username' => $telegramUsername
                ]);
            } catch (Exception $e) {
                logBot('Error updating telegram_id and username', [
                    'error' => $e->getMessage(),
                    'user_id' => $otpData['user_id'],
                    'telegram_id' => $chatId
                ]);
            }

            // Send the OTP with formatted message
            return sendMessage($chatId,
                "🔐 <b>Your Connection Code</b>\n\n" .
                "Your verification code is:\n" .
                "<code>{$otpData['otp']}</code>\n\n" .
                "📱 <b>Next Steps:</b>\n" .
                "1. Go back to AmezPrice website\n" .
                "2. Enter this code in the verification box\n" .
                "3. Click 'Verify OTP'\n\n" .
                "⏰ <b>Important:</b> This code expires in 10 minutes\n" .
                "🔒 Keep this code secure and don't share it\n\n" .
                "🌐 <a href=\"https://amezprice.com/user/account.php\">Complete Connection</a>",
                null, 3, $messageId
            );
        }

        // ✅ FIXED: Handle /price command with proper user_id
        if ($message === '/price') {
            $stmt = $pdo->prepare("
                SELECT p.asin, p.name, p.current_price, up.price_threshold
                FROM user_products up 
                JOIN products p ON up.product_asin = p.asin 
                WHERE up.user_id = ? 
                ORDER BY up.created_at DESC 
                LIMIT 5
            ");
            $stmt->execute([$userId]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($products)) {
                return sendMessage($chatId, 
                    "⚡ <b>Price Alerts</b>\n\n" .
                    "You need to track some products first!\n\n" .
                    "Send me Amazon or Flipkart product links to start tracking, then use /price to set alerts.",
                    null, 3, $messageId
                );
            }

            // ✅ NEW: Create inline buttons for each product (like /stop command)
            $buttons = [];
            foreach ($products as $product) {
                $productName = sanitizeForHTML($product['name']);
                $shortName = substr($productName, 0, 30) . (strlen($productName) > 30 ? '...' : '');
                
                // Add current price and alert status to button text
                $currentAlert = $product['price_threshold'] 
                    ? " (₹" . number_format($product['price_threshold'], 0, '.', ',') . " alert)"
                    : " (No alert)";
                
                $buttonText = $shortName . $currentAlert;
                $buttons[] = [['text' => $buttonText, 'callback_data' => "alert_{$product['asin']}"]];
            }

            $replyMarkup = ['inline_keyboard' => $buttons];
            return sendMessage($chatId, "⚡ <b>Set Price Alerts</b>\n\nChoose a product to set price alert:", $replyMarkup, 3, $messageId);
        }

        // ✅ UPDATED: Enhanced product URL tracking with API-based image download
        if (preg_match('/https?:\/\/(www\.)?(amazon\.in|flipkart\.com|amzn\.(in|to))/', $message)) {
            if (!validateProductUrl($message)) {
                return sendMessage($chatId, 
                    "⚠️ Invalid product URL format.\n\n" .
                    "Please send a valid Amazon India or Flipkart product link.\n\n" .
                    "<b>Examples:</b>\n" .
                    "• <code>https://amazon.in/dp/B08N5WRWNW</code>\n" .
                    "• <code>https://amzn.in/d/4aznEzk</code> ✅ Short URL\n" .
                    "• <code>https://amzn.to/4kOgesJ</code> ✅ Short URL\n" .
                    "• <code>https://flipkart.com/product-name/p/itm123</code>",
                    null, 3, $messageId
                );
            }

            logBot('Processing product URL', [
                'chatId' => $chatId,
                'url' => $message,
                'is_short_url' => (strpos($message, 'amzn.') !== false)
            ]);

            // Make API call to track product
            $ch = curl_init('https://amezprice.com/api/track.php');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'user_id' => $chatId,  // This is telegram_id
                    'username' => $user['username'] ?? null,
                    'first_name' => $user['first_name'] ?? 'User',
                    'last_name' => $user['last_name'] ?? null,
                    'product_url' => $message
                ]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-API-Key: ' . $telegramConfig['api_key']
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                logBot('cURL error in product tracking', [
                    'chatId' => $chatId,
                    'error' => $curlError
                ]);
                return sendMessage($chatId, "⚠️ Network error. Please try again later.", null, 3, $messageId);
            }

            if ($httpCode !== 200) {
                logBot('HTTP error in product tracking', [
                    'chatId' => $chatId,
                    'http_code' => $httpCode,
                    'response' => $response
                ]);
                return sendMessage($chatId, "⚠️ Service temporarily unavailable. Please try again later.", null, 3, $messageId);
            }

            $data = json_decode($response, true);

            if ($data && $data['status'] === 'success') {
                // ✅ FIXED: Use tracking_buttons instead of channels configuration
                $todaysDealsUrl = 'https://amezprice.com/pages/todays-deals.php'; // Default fallback
                
                // Get URL from tracking_buttons configuration
                if (isset($telegramConfig['tracking_buttons']['amezprice']) && 
                    is_array($telegramConfig['tracking_buttons']['amezprice'])) {
                    foreach ($telegramConfig['tracking_buttons']['amezprice'] as $button) {
                        if (isset($button['text']) && $button['text'] === "Today's Deals" && 
                            isset($button['url']) && isset($button['enabled']) && $button['enabled']) {
                            $todaysDealsUrl = $button['url'];
                            break;
                        }
                    }
                }

                $replyMarkup = [
                    'inline_keyboard' => [
                        [
                            ['text' => 'Buy Now ✅', 'url' => $data['affiliate_link']],
                            ['text' => 'Stop Tracking 🔴', 'callback_data' => "stop_{$data['asin']}"]
                        ],
                        [
                            ['text' => 'Price History 📈', 'url' => $data['history_url']],
                            ['text' => "Today's Deals 🛍️", 'url' => $todaysDealsUrl]  // ✅ Fixed: Use proper URL
                        ],
                        [
                            ['text' => 'Set Price Alert 🎯', 'callback_data' => "alert_{$data['asin']}"]
                        ]
                    ]
                ];

                // ✅ UPDATED: Use API for image download instead of internal function
                $imagePath = null;
                if (!empty($data['image_url'])) {
                    $imagePath = downloadProductImage($data['image_url'], $data['asin']);
                }

                $productName = sanitizeForHTML($data['product_name']);

                $trackingMessage = sprintf(
                    "✅ Now tracking this product!\n\n" .
                    "<b>%s</b>\n\n" .
                    "💰 Current Price: ₹%s\n" .
                    "📊 Highest: ₹%s\n" .
                    "📉 Lowest: ₹%s\n\n" .
                    "🔥 %d users tracking this\n" .
                    "⏱️ Updated: %s",
                    $productName,
                    number_format($data['current_price'], 0, '.', ','),
                    number_format($data['highest_price'], 0, '.', ','),
                    number_format($data['lowest_price'], 0, '.', ','),
                    $data['tracker_count'] ?? 1,
                    date('d M Y, h:i A')
                );

                // Send with image if available, otherwise send text message
                if ($imagePath && file_exists($imagePath)) {
                    sendPhoto($chatId, $imagePath, $trackingMessage, $replyMarkup);
                } else {
                    sendMessage($chatId, $trackingMessage, $replyMarkup, 3, $messageId);
                }
                sendMessage($chatId, "I'll notify you when the price drops! Use /list to see all your tracked products 😊");
                return true;
            } else {
                $errorMessage = $data['message'] ?? "Sorry, couldn't process that link. Please try again or send a different product link.";
                return sendMessage($chatId, "⚠️ " . $errorMessage, null, 3, $messageId);
            }
        }

        // ✅ FIXED: Handle price threshold inputs for /price command with proper user_id
                // ✅ FIXED: Handle price threshold inputs for /price command with proper user_id
        if (preg_match('/^(\d+)\s+(\d+)$/', $message, $matches)) {
            $productIndex = (int)$matches[1];
            $targetPrice = (int)$matches[2];

            $stmt = $pdo->prepare("
                SELECT p.asin, p.name, p.current_price 
                FROM user_products up 
                JOIN products p ON up.product_asin = p.asin 
                WHERE up.user_id = ? 
                ORDER BY up.created_at DESC 
                LIMIT 5
            ");
            $stmt->execute([$userId]);  // ✅ Use actual user_id instead of telegram_id
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($productIndex < 1 || $productIndex > count($products)) {
                return sendMessage($chatId, "⚠️ Invalid product number. Please use a number between 1 and " . count($products), null, 3, $messageId);
            }

            $product = $products[$productIndex - 1];
            $thresholdData = getSmartPriceThreshold($product['asin'], $product['current_price']);

            if ($targetPrice >= $thresholdData['min'] && $targetPrice <= $thresholdData['max']) {
                $stmt = $pdo->prepare("
                    UPDATE user_products 
                    SET price_threshold = ? 
                    WHERE user_id = ? AND product_asin = ?
                ");
                $stmt->execute([$targetPrice, $userId, $product['asin']]);  // ✅ Use actual user_id

                $productName = sanitizeForHTML($product['name']);
                
                return sendMessage($chatId, sprintf(
                    "✅ Price alert set!\n\n" .
                    "%s\n" .
                    "🎯 Alert Price: ₹%s\n" .
                    "💰 Current Price: ₹%s\n\n" .
                    "I'll notify you when the price drops to your target!",
                    $productName,
                    number_format($targetPrice, 0, '.', ','),
                    number_format($product['current_price'], 0, '.', ',')
                ), null, 3, $messageId);
            } else {
                $reasonText = $thresholdData['has_enough_data'] 
                    ? "You can set alerts down to the lowest recorded price."
                    : "This product is new on our site, so alerts are limited to 10% below current price.";
                    
                return sendMessage(
                    $chatId,
                    "⚠️ Please set a reasonable price between:\n" .
                    "Maximum: ₹" . number_format($thresholdData['max'], 0, '.', ',') . "\n" .
                    "Minimum: ₹" . number_format($thresholdData['min'], 0, '.', ',') . "\n\n" .
                    $reasonText . "\n\n" .
                    "Don't worry, you'll still get notifications for any price drops!",
                    null, 3, $messageId
                );
            }
        }

        // ✅ FIXED: Handle simple price inputs with proper user_id
                // ✅ FIXED: Handle simple price inputs with proper user_id
        if (preg_match('/^\d+$/', $message)) {
            $stmt = $pdo->prepare("
                SELECT p.asin, p.name, p.current_price, up.price_threshold 
                FROM user_products up 
                JOIN products p ON up.product_asin = p.asin 
                WHERE up.user_id = ? AND up.price_threshold IS NULL 
                ORDER BY up.created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$userId]);  // ✅ Use actual user_id instead of telegram_id
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($product) {
                $priceThreshold = (int)$message;
                $thresholdData = getSmartPriceThreshold($product['asin'], $product['current_price']);

                if ($priceThreshold >= $thresholdData['min'] && $priceThreshold <= $thresholdData['max']) {
                    $stmt = $pdo->prepare("
                        UPDATE user_products 
                        SET price_threshold = ? 
                        WHERE user_id = ? AND product_asin = ?
                    ");
                    $stmt->execute([$priceThreshold, $userId, $product['asin']]);  // ✅ Use actual user_id

                    $productName = sanitizeForHTML($product['name']);
                    
                    return sendMessage($chatId, sprintf(
                        "✅ Price alert set!\n\n" .
                        "%s\n\n" .
                        "🎯 <b>Alert Price: ₹%s</b>\n\n" .
                        "💰 Current Price: ₹%s\n\n" .
                        "I'll notify you when the price drops to your target!",
                        $productName,
                        number_format($priceThreshold, 0, '.', ','),
                        number_format($product['current_price'], 0, '.', ',')
                    ), null, 3, $messageId);
                } else {
                    $reasonText = $thresholdData['has_enough_data'] 
                        ? "You can set alerts down to the lowest recorded price."
                        : "This product is new on our site, so alerts are limited to 10% below current price.";
                        
                    return sendMessage(
                        $chatId,
                        "⚠️ Please set a reasonable price between:\n" .
                        "Maximum: ₹" . number_format($thresholdData['max'], 0, '.', ',') . "\n" .
                        "Minimum: ₹" . number_format($thresholdData['min'], 0, '.', ',') . "\n\n" .
                        "Current price: ₹" . number_format($product['current_price'], 0, '.', ',') . "\n\n" .
                        $reasonText,
                        null, 3, $messageId
                    );
                }
            }
        }

        // Default response for unknown input
        return sendMessage(
            $chatId,
            "I can help you track product prices!\n\n" .
            "• Send me an Amazon or Flipkart product link to start tracking\n" .
            "• Use /help to see all commands\n" .
            "• Use /deal to explore today's best deals\n" .
            "• Use /list to see your tracked products\n" .
            "• Use /price to set price alerts\n\n",
            null, 3, $messageId
        );

    } catch (Exception $e) {
        logBot('Error in handleMessage', [
            'error' => $e->getMessage(),
            'chatId' => $chatId,
            'message' => $message,
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        
        sendMessage($chatId, 
            "Sorry, something went wrong. Please try again later.\n\n" .
            "If the problem persists, contact support at @AmezPriceSupport",
            null, 3, $messageId
        );
        return false;
    }
}

/**
 * ✅ FIXED: Handle callback queries with proper user_id lookup and tracking count update
 */
function handleCallbackQuery($chatId, $data, $user, $callbackId) {
    global $pdo;
    
    try {
        logBot('Processing callback query', [
            'chatId' => $chatId,
            'data' => $data
        ]);

        // ✅ FIX: Get actual user_id from telegram_id for all database queries
        $userId = getUserIdFromTelegramId($chatId);
        if (!$userId) {
            logBot('Failed to get user_id from telegram_id in callback', ['telegram_id' => $chatId]);
            sendMessage($chatId, "❌ User lookup error. Please try /start again.");
            return false;
        }

        // Handle stop tracking
        if (preg_match('/^stop_([A-Z0-9]{10})$/', $data, $matches)) {
            $asin = $matches[1];
            
            // ✅ FIX: Use transaction to ensure both operations succeed
            $pdo->beginTransaction();
            
            try {
                // Remove from user_products
                $stmt = $pdo->prepare("
                    DELETE FROM user_products 
                    WHERE user_id = ? AND product_asin = ?
                ");
                $result = $stmt->execute([$userId, $asin]);  // ✅ Use actual user_id instead of telegram_id

                if ($result && $stmt->rowCount() > 0) {
                    // ✅ FIX: Update tracking count in products table after removal
                    $stmt = $pdo->prepare("
                        UPDATE products 
                        SET tracking_count = (SELECT COUNT(*) FROM user_products WHERE product_asin = ?) 
                        WHERE asin = ?
                    ");
                    $stmt->execute([$asin, $asin]);
                    
                    // Get product name for confirmation
                    $stmt = $pdo->prepare("SELECT name FROM products WHERE asin = ?");
                    $stmt->execute([$asin]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $productName = $product ? sanitizeForHTML(substr($product['name'], 0, 50)) . '...' : 'Product';
                    
                    $pdo->commit();
                    sendMessage($chatId, "✅ Stopped tracking: {$productName}\n\nUse /list to see your remaining tracked products.");
                } else {
                    $pdo->rollBack();
                    sendMessage($chatId, "⚠️ Product not found in your tracking list.");
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                logBot('Error in stop tracking', [
                    'error' => $e->getMessage(),
                    'user_id' => $userId,
                    'asin' => $asin
                ]);
                sendMessage($chatId, "⚠️ Failed to stop tracking. Please try again.");
            }
            return true;
        }

        // Handle price alert setup
        if (preg_match('/^alert_([A-Z0-9]{10})$/', $data, $matches)) {
            $asin = $matches[1];
            
            $stmt = $pdo->prepare("
                SELECT p.name, p.current_price, p.highest_price, p.lowest_price
                FROM products p 
                JOIN user_products up ON p.asin = up.product_asin 
                WHERE up.user_id = ? AND p.asin = ?
            ");
            $stmt->execute([$userId, $asin]);  // ✅ Use actual user_id instead of telegram_id
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($product) {
                $thresholdData = getSmartPriceThreshold($asin, $product['current_price']);
                $productName = sanitizeForHTML($product['name']);
                
                $exampleText = $thresholdData['has_enough_data']
                    ? sprintf("Example: Send <code>%d</code> (lowest recorded) or <code>%d</code> (10%% below current)", 
                             (int)$thresholdData['lowest_price'], 
                             (int)($product['current_price'] * 0.9))
                    : sprintf("Example: Send <code>%d</code> (10%% below current price)",
                             (int)($product['current_price'] * 0.9));
                
                // ✅ FIXED: HTML formatting
                sendMessage($chatId, sprintf(
                    "🎯 <b>Set Price Alert</b>\n\n" .
                    "Product: %s\n\n" .
                    "Current Price: ₹%s\n\n" .
                    "Send me your target price (₹%s - ₹%s):\n\n" .
                    "<b>%s</b>",
                    substr($productName, 0, 50) . '...',
                    number_format($product['current_price'], 0, '.', ','),
                    number_format($thresholdData['max'], 0, '.', ','),
                    number_format($thresholdData['min'], 0, '.', ','),
                    $exampleText
                ));
            } else {
                sendMessage($chatId, "⚠️ Product not found in your tracking list.");
            }
            return true;
        }

        return false;

    } catch (Exception $e) {
        logBot('Error in handleCallbackQuery', [
            'error' => $e->getMessage(),
            'chatId' => $chatId ?? null,
            'data' => $data ?? null
        ]);
        return false;
    }
}

/**
 * Answer callback queries to remove "loading" state
 */
function answerCallbackQuery($callbackQueryId, $text = null) {
    global $telegramConfig;
    
    try {
        $url = "https://api.telegram.org/bot{$telegramConfig['amezpricebot_token']}/answerCallbackQuery";
        
        $payload = ['callback_query_id' => $callbackQueryId];
        if ($text) $payload['text'] = $text;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        logBot('Callback query answered', [
            'callback_id' => $callbackQueryId,
            'success' => ($httpCode === 200)
        ]);
        
        return ($httpCode === 200);
        
    } catch (Exception $e) {
        logBot('Error answering callback query', [
            'callback_id' => $callbackQueryId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}
?>