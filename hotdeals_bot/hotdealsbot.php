<?php
require_once '../config/database.php';
require_once '../config/telegram.php';

// Enhanced logging function
function logHotDeals($message, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/hotdeals_bot.log';
        $timestamp = date('Y-m-d H:i:s');
        $logData = array_merge([
            'timestamp' => $timestamp,
            'message' => $message,
            'user_id' => $data['user_id'] ?? null
        ], $data);
        
        // Ensure log directory exists
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log("HotDeals logging error: " . $e->getMessage());
    }
}

// ADDED: Duplicate request prevention
function checkDuplicateRequest($chatId, $message, $windowSeconds = 5) {
    static $requestCache = [];
    $currentTime = time();
    $key = md5($chatId . '|' . $message);
    
    // Clean old entries
    foreach ($requestCache as $k => $timestamp) {
        if ($currentTime - $timestamp > $windowSeconds) {
            unset($requestCache[$k]);
        }
    }
    
    // Check if this is a duplicate
    if (isset($requestCache[$key])) {
        return false; // Duplicate request
    }
    
    $requestCache[$key] = $currentTime;
    return true; // New request
}

// Function to download and save product image
function downloadProductImage($imageUrl, $asin) {
    try {
        if (empty($imageUrl)) return null;
        
        $imageDir = '../uploads/product_images/';
        if (!is_dir($imageDir)) {
            mkdir($imageDir, 0755, true);
        }
        
        $imageExtension = pathinfo($imageUrl, PATHINFO_EXTENSION) ?: 'jpg';
        $imagePath = $imageDir . $asin . '.' . $imageExtension;
        
        $ch = curl_init($imageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $imageData) {
            file_put_contents($imagePath, $imageData);
            return $imagePath;
        }
        
        return null;
    } catch (Exception $e) {
        logBot('Image download error', ['error' => $e->getMessage(), 'asin' => $asin]);
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

/**
 * Enhanced price validation with dynamic category-based pricing
 */
function validatePriceRange($category, $price, $pdo) {
    try {
        // Get average price from recent products for this category (FIXED: changed from deals to products)
        $stmt = $pdo->prepare("
            SELECT 
                AVG(current_price) as avg_price,
                MIN(current_price) as min_price,
                MAX(current_price) as max_price,
                COUNT(*) as product_count
            FROM products 
            WHERE category = ? 
            AND last_updated > DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND current_price > 0
        ");
        $stmt->execute([$category]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Category-specific price ranges
        $categoryRanges = [
            'smartphones' => ['default' => 15000, 'min_factor' => 0.1, 'max_factor' => 5.0],
            'laptops' => ['default' => 50000, 'min_factor' => 0.2, 'max_factor' => 4.0],
            'televisions' => ['default' => 25000, 'min_factor' => 0.15, 'max_factor' => 3.5],
            'headphones' => ['default' => 3000, 'min_factor' => 0.1, 'max_factor' => 10.0],
            'smartwatches' => ['default' => 8000, 'min_factor' => 0.1, 'max_factor' => 6.0]
        ];
        
        $config = $categoryRanges[$category] ?? ['default' => 10000, 'min_factor' => 0.2, 'max_factor' => 3.0];
        
        // Use actual data if available, otherwise use defaults
        if ($result && $result['product_count'] > 5) {
            $avgPrice = $result['avg_price'];
            $minPrice = max(500, $avgPrice * $config['min_factor']);
            $maxPrice = min(500000, $avgPrice * $config['max_factor']);
        } else {
            $avgPrice = $config['default'];
            $minPrice = max(500, $avgPrice * $config['min_factor']);
            $maxPrice = min(500000, $avgPrice * $config['max_factor']);
        }
        
        logHotDeals('Price validation', [
            'category' => $category,
            'input_price' => $price,
            'avg_price' => $avgPrice,
            'min_allowed' => $minPrice,
            'max_allowed' => $maxPrice,
            'is_valid' => ($price >= $minPrice && $price <= $maxPrice)
        ]);
        
        return $price >= $minPrice && $price <= $maxPrice;
        
    } catch (Exception $e) {
        logHotDeals('Price validation error', ['error' => $e->getMessage()]);
        // Fallback to basic validation
        return $price >= 100 && $price <= 500000;
    }
}

/**
 * Enhanced message sending with retry mechanism
 */
function sendHotDealsMessage($chatId, $text, $replyMarkup = null, $retries = 3) {
    global $telegramConfig;
    
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        try {
            logHotDeals('Sending message attempt', [
                'chatId' => $chatId,
                'attempt' => $attempt,
                'text_length' => strlen($text)
            ]);

            $url = "https://api.telegram.org/bot{$telegramConfig['hotdealsbot_token']}/sendMessage";
            
            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true
            ];

            if ($replyMarkup) {
                $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'AmezPrice-HotDealsBot/1.2',
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
                    logHotDeals('Message sent successfully', [
                        'chatId' => $chatId,
                        'attempt' => $attempt
                    ]);
                    return true;
                } else {
                    throw new Exception("Telegram API error: " . ($result['description'] ?? 'Unknown error'));
                }
            } else {
                throw new Exception("HTTP error: $httpCode");
            }

        } catch (Exception $e) {
            logHotDeals('Message send error', [
                'chatId' => $chatId,
                'attempt' => $attempt,
                'error' => $e->getMessage()
            ]);
            
            if ($attempt === $retries) {
                return false;
            }
            
            // Exponential backoff
            sleep(pow(2, $attempt - 1));
        }
    }
    
    return false;
}

/**
 * Enhanced user registration with validation (FIXED: Table name corrected)
 */
function registerHotDealsUser($chatId, $username, $firstName, $lastName = null) {
    global $pdo;
    
    try {
        // Validate inputs
        if (!$chatId || !is_numeric($chatId)) {
            throw new Exception('Invalid chat ID');
        }
        
        if (empty($firstName)) {
            $firstName = 'User';
        }
        
        // Check if user already exists (FIXED: Table name)
        $stmt = $pdo->prepare("SELECT telegram_id FROM hotdealsbot WHERE telegram_id = ?");
        $stmt->execute([$chatId]);
        
        if ($stmt->fetch()) {
            // Update existing user (FIXED: Table name and added is_active)
            $stmt = $pdo->prepare("
                UPDATE hotdealsbot 
                SET username = ?, first_name = ?, last_name = ?, is_active = TRUE, last_interaction = NOW() 
                WHERE telegram_id = ?
            ");
            $stmt->execute([$username, $firstName, $lastName, $chatId]);
            logHotDeals('User updated', ['user_id' => $chatId]);
        } else {
            // Insert new user (FIXED: Table name and added required fields)
            $stmt = $pdo->prepare("
                INSERT INTO hotdealsbot (telegram_id, username, first_name, last_name, category, merchant, is_active, created_at, last_interaction) 
                VALUES (?, ?, ?, ?, null, null, 1, NOW(), NOW())
            ");
            $stmt->execute([$chatId, $username, $firstName, $lastName]);
            logHotDeals('New user registered', ['user_id' => $chatId]);
        }
        
        return true;
        
    } catch (Exception $e) {
        logHotDeals('User registration error', [
            'user_id' => $chatId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Get user categories from JSON field
 */
function getUserCategories($chatId, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT category FROM hotdealsbot WHERE telegram_id = ? AND is_active = TRUE");
        $stmt->execute([$chatId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return [];
        }
        
        // If category contains comma-separated values or JSON
        $categories = $result['category'];
        if (strpos($categories, ',') !== false) {
            return array_map('trim', explode(',', $categories));
        } elseif (strpos($categories, '[') === 0) {
            return json_decode($categories, true) ?: [$categories];
        } else {
            return [$categories];
        }
    } catch (Exception $e) {
        logHotDeals('Error getting user categories', ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Update user categories
 */
function updateUserCategories($chatId, $categories, $pdo) {
    try {
        // Get existing categories first
        $stmt = $pdo->prepare("SELECT category FROM hotdealsbot WHERE telegram_id = ? AND is_active = 1");
        $stmt->execute([$chatId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Prepare new category string
        if ($result && !empty($result['category'])) {
            // If existing categories, add new ones
            $existingCategories = explode(',', $result['category']);
            // Remove empty values and trim
            $existingCategories = array_filter(array_map('trim', $existingCategories));
            // Add new categories
            $newCategories = is_array($categories) ? $categories : [$categories];
            // Merge, remove duplicates, and remove empty values
            $allCategories = array_filter(array_unique(array_merge($existingCategories, $newCategories)));
            $categoryString = implode(',', $allCategories);
        } else {
            // If no existing categories, use new one
            $categoryString = is_array($categories) ? implode(',', $categories) : $categories;
        }
        
        // Update the database
        $stmt = $pdo->prepare("
            UPDATE hotdealsbot 
            SET category = ?, last_interaction = NOW() 
            WHERE telegram_id = ?
        ");
        $stmt->execute([$categoryString, $chatId]);
        
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        logHotDeals('Error updating user categories', ['error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Enhanced message handler with improved error handling (FIXED: All table references)
 */
function handleHotDealsMessage($chatId, $message, $user, $input = null) {
    global $pdo, $telegramConfig;

    try {
        // FIXED: Check for duplicate requests
        if (!checkDuplicateRequest($chatId, $message)) {
            logHotDeals('Duplicate request ignored', [
                'user_id' => $chatId,
                'message' => $message
            ]);
            return true; // Return success but don't process
        }

        // Register/update user
        registerHotDealsUser(
            $chatId, 
            $user['username'] ?? null, 
            $user['first_name'] ?? 'User', 
            $user['last_name'] ?? null
        );

        // Handle /start command
        if ($message === '/start') {
            $buttons = array_filter($telegramConfig['buttons']['hotdeals'] ?? [], fn($btn) => $btn['enabled'] ?? true);
            $replyMarkup = [
                'inline_keyboard' => array_map(fn($btn) => [['text' => $btn['text'], 'url' => $btn['url']]], $buttons)
            ];

            $welcomeMessage = sprintf(
                "🎉 %s Welcome to Hot Deals Bot! Great to see you!\n\n" .
                "I help you discover amazing deals from Amazon and Flipkart.\n\n" .
                "Available commands:\n" .
                "• /startalert - Start getting deal alerts\n" .
                "• /stopalert - Stop receiving alerts\n" .
                "• /help - Show all commands\n\n" .
                "You can track deals in these categories:\n" .
                "• Smartphones 📱\n" .
                "• Laptops 💻\n" .
                "• Televisions 📺\n" .
                "• Headphones 🎧\n" .
                "• Smartwatches ⌚\n\n" .
                "Use /startalert to begin!",
                $user['username'] ? "@{$user['username']}" : ($user['first_name'] ?? 'User')
            );

            sendHotDealsMessage($chatId, $welcomeMessage, $replyMarkup);

            // Send quick guide
            $guideMessage = 
                "Quick guide to get started:\n\n" .
                "1️⃣ Use /startalert\n" .
                "2️⃣ Choose your favorite categories\n" .
                "3️⃣ Set price range (optional)\n" .
                "4️⃣ Get deal alerts automatically!\n\n" .
                "Ready? Type /startalert now!";

            sendHotDealsMessage($chatId, $guideMessage);
            return;
        }

        // Handle /help command
        if ($message === '/help') {
            $helpMessage = "🌟 *Hot Deals Bot Help Guide* 🌟\n\n" .
                          "Welcome to your deal-finding assistant! Here's how to use me:\n\n" .
                          "📝 *Main Commands*\n" .
                          "• /start - Start the bot\n" .
                          "• /startalert - Set up new deal alerts\n" .
                          "• /stopalert - Stop receiving alerts\n" .
                          "• /mystatus - Check your current alerts\n" .
                          "• /help - Show this help message\n\n" .
                          "🛍️ *Available Categories*\n";
            // Get categories from database
            $stmt = $pdo->prepare("
                SELECT DISTINCT category, COUNT(*) as count 
                FROM products 
                WHERE category IS NOT NULL AND category != '' 
                GROUP BY category 
                ORDER BY count DESC, category 
                LIMIT 10
            ");
            $stmt->execute();
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($categories as $cat) {
                $helpMessage .= "• " . ucfirst($cat['category']) . " ({$cat['count']} products)\n";
            }
            
            $helpMessage .= "\n💡 *Tips*\n" .
                          "• You can track up to 5 categories\n" .
                          "• Set price limits for better deals\n" .
                          "• Choose Amazon, Flipkart or both\n" .
                          "• Get instant notifications for deals\n\n" .
                          "🔄 *How It Works*\n" .
                          "1. Use /startalert to begin\n" .
                          "2. Choose your categories\n" .
                          "3. Set optional price limits\n" .
                          "4. Get deal notifications!\n\n" .
                          "❓ *Need More Help?*\n" .
                          "Visit: https://amezprice.com/pages/faq.php\n" .
                          "Contact: @AmezPriceSupport\n\n" .
                          "Happy Deal Hunting! 🎯";

            sendHotDealsMessage($chatId, $helpMessage);

            // Send quick action buttons
            $actionButtons = [
                'inline_keyboard' => [
                    [
                        ['text' => '🚀 Start Alerts', 'callback_data' => 'command_startalert'],
                        ['text' => '🛑 Stop Alerts', 'callback_data' => 'command_stopalert']
                    ],
                    [
                        ['text' => '📊 My Status', 'callback_data' => 'command_mystatus'],
                    ],
                    [
                        ['text' => '🛍️ Visit AmezPrice', 'url' => 'https://amezprice.com'],
                        ['text' => '📱 Join Channel', 'url' => $telegramConfig['channels']['hotdeals'] ?? 'https://t.me/amezprice']
                    ]
                ]
            ];

            sendHotDealsMessage($chatId, "Quick Actions:", $actionButtons);
            return;
        }

        // Handle /mystatus command (FIXED: Updated for single table)
        if ($message === '/mystatus') {
            $stmt = $pdo->prepare("
                SELECT category, merchant, price_range, created_at 
                FROM hotdealsbot 
                WHERE telegram_id = ? AND is_active = TRUE
            ");
            $stmt->execute([$chatId]);
            $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$userInfo) {
                sendHotDealsMessage($chatId, 
                    "📊 *Your Deal Alerts Status*\n\n" .
                    "❌ No active alerts found\n\n" .
                    "Use /startalert to set up deal notifications!"
                );
            } else {
                $categories = getUserCategories($chatId, $pdo);
                $priceText = $userInfo['price_range'] ? "up to ₹" . number_format($userInfo['price_range'], 0, '.', ',') : "any price";
                
                $statusMessage = "📊 *Your Deal Alerts Status*\n\n";
                $statusMessage .= "✅ *Categories*: " . implode(', ', array_map('ucfirst', $categories)) . "\n";
                $statusMessage .= "🛍️ *Platform*: " . ucfirst($userInfo['merchant']) . "\n";
                $statusMessage .= "💰 *Price Range*: " . $priceText . "\n";
                $statusMessage .= "📅 *Since*: " . date('M j, Y', strtotime($userInfo['created_at'])) . "\n\n";
                $statusMessage .= "Use /stopalert to modify your alerts.";
                
                sendHotDealsMessage($chatId, $statusMessage);
            }
            return;
        }

        // Handle /startalert command
        if ($message === '/startalert') {
            // Get available categories from database
            $stmt = $pdo->prepare("
                SELECT DISTINCT category, COUNT(*) as product_count
                FROM products 
                WHERE category IS NOT NULL AND category != '' 
                GROUP BY category 
                HAVING product_count > 5
                ORDER BY product_count DESC, category
            ");
            $stmt->execute();
            $dbCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $buttons = array_filter($telegramConfig['buttons']['hotdeals'] ?? [], fn($btn) => $btn['enabled'] ?? true);
            $replyMarkup = [
                'inline_keyboard' => array_map(fn($btn) => [['text' => $btn['text'], 'url' => $btn['url']]], $buttons)
            ];

            $categoryList = "";
            foreach ($dbCategories as $cat) {
                $categoryList .= "• " . ucfirst($cat['category']) . " ({$cat['product_count']} products)\n";
            }

            sendHotDealsMessage($chatId, "🎉 Welcome to AmezPrice Hot Deals!\n\n" .
                                        "I'm your go-to bot for finding the hottest deals on Amazon and Flipkart, delivering alerts for your favorite categories.\n\n" .
                                        "Choose a category to get started, and I'll send you the best offers tailored to your preferences.\n\n" .
                                        "Available categories:\n" . $categoryList, $replyMarkup);
            sendHotDealsMessage($chatId, "Which categories would you like deal alerts for? Just type them and send!");
            return;
        }

        // Handle /stopalert command (FIXED: Updated for single table)
        if ($message === '/stopalert') {
            $stmt = $pdo->prepare("
                SELECT telegram_id, category, merchant, price_range 
                FROM hotdealsbot 
                WHERE telegram_id = ? AND is_active = TRUE
            ");
            $stmt->execute([$chatId]);
            $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$userInfo) {
                sendHotDealsMessage($chatId, "You don't have any active deal alerts to stop.\n\nUse /startalert to set up notifications!");
                return;
            }

            $categories = getUserCategories($chatId, $pdo);
            $priceText = $userInfo['price_range'] ? " (₹" . number_format($userInfo['price_range'], 0, '.', ',') . ")" : "";
            
            $buttons = [
                [['text' => '🛑 Stop All Alerts', 'callback_data' => "stop_all_alerts"]],
                [['text' => '⚙️ Modify Settings', 'callback_data' => "modify_alerts"]]
            ];

            sendHotDealsMessage($chatId, 
                "Current Alerts:\n" .
                "Categories: " . implode(', ', array_map('ucfirst', $categories)) . "\n" .
                "Platform: " . ucfirst($userInfo['merchant']) . $priceText . "\n\n" .
                "What would you like to do?", 
                ['inline_keyboard' => $buttons]
            );
            return;
        }

        // Handle category selection - Get categories from database
        $category = strtolower(trim($message));

        // Get available categories from products table
        $stmt = $pdo->prepare("
            SELECT DISTINCT category 
            FROM products 
            WHERE category IS NOT NULL AND category != '' 
            GROUP BY category 
            HAVING COUNT(*) > 0
            ORDER BY category
        ");
        $stmt->execute();
        $dbCategories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $availableCategories = array_map('strtolower', $dbCategories);
        
        if (in_array($category, $availableCategories)) {
            // Check if user already has this category
            $userCategories = getUserCategories($chatId, $pdo);
            
            if (in_array($category, array_map('strtolower', $userCategories))) {
                sendHotDealsMessage($chatId, "You're already tracking *{$category}* deals! Use /stopalert to modify your alerts.");
                return;
            }

            // Check category limit
            if (count($userCategories) >= 5) {
                sendHotDealsMessage($chatId, "You can only track 5 categories. Remove some using /stopalert first!");
                return;
            }

            // Add new category to existing ones
            $userCategories[] = $category;
            updateUserCategories($chatId, $userCategories, $pdo);

            sendHotDealsMessage($chatId, "Great choice! Select deal alerts from:", [
                'inline_keyboard' => [
                    [
                        ['text' => 'Amazon 🛍️', 'callback_data' => "merchant_{$category}_amazon"],
                        ['text' => 'Flipkart 🛒', 'callback_data' => "merchant_{$category}_flipkart"]
                    ],
                    [
                        ['text' => 'Both Platforms 🔥', 'callback_data' => "merchant_{$category}_both"]
                    ]
                ]
            ]);
            return;
        }

        // Handle price input (FIXED: Updated for single table)
        if (preg_match('/^\d+$/', $message)) {
            $stmt = $pdo->prepare("
                SELECT telegram_id, category, merchant 
                FROM hotdealsbot 
                WHERE telegram_id = ? AND price_range IS NULL AND is_active = TRUE
                LIMIT 1"
            );
            $stmt->execute([$chatId]);
            $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$userInfo) {
                sendHotDealsMessage($chatId, "Please select a category first using /startalert");
                return;
            }

            $price = (float)$message;
            $categories = getUserCategories($chatId, $pdo);
            $lastCategory = end($categories); // Get last category
            
            if (!validatePriceRange($lastCategory, $price, $pdo)) {
                sendHotDealsMessage($chatId, 
                    "Please enter a reasonable price for *{$lastCategory}* products!\n\n" .
                    "💡 *Suggested price ranges:*\n" .
                    "• Smartphones: ₹1,500 - ₹75,000\n" .
                    "• Laptops: ₹10,000 - ₹2,00,000\n" .
                    "• Televisions: ₹3,750 - ₹87,500\n" .
                    "• Headphones: ₹300 - ₹30,000\n" .
                    "• Smartwatches: ₹800 - ₹48,000"
                );
                return;
            }

            $stmt = $pdo->prepare("UPDATE hotdealsbot SET price_range = ?, last_interaction = NOW() WHERE telegram_id = ?");
            $stmt->execute([$price, $chatId]);

            sendHotDealsMessage(
                $chatId, 
                "✅ Price alert set for *{$lastCategory}* deals up to ₹" . number_format($price, 0, '.', ',') . "!\n\n" .
                "You'll get notifications when great deals are available.\n\n" .
                "Use /mystatus to see all your alerts!"
            );
            return;
        }

        // Handle callback queries
        if (isset($input['callback_query'])) {
            $callback = $input['callback_query'];
            $data = $callback['data'];

            // Handle command callbacks
            if (strpos($data, 'command_') === 0) {
                $command = substr($data, 8); // Remove 'command_' prefix
                switch ($command) {
                    case 'startalert':
                        handleHotDealsMessage($chatId, '/startalert', $user, null);
                        return;
                    case 'stopalert':
                        handleHotDealsMessage($chatId, '/stopalert', $user, null);
                        return;
                    case 'mystatus':
                        handleHotDealsMessage($chatId, '/mystatus', $user, null);
                        return;
                }
            }

            // Handle merchant selection (FIXED: Updated for single table)
            if (preg_match('/^merchant_(.+)_(.+)$/', $data, $matches)) {
                if (count($matches) < 3) {
                    sendHotDealsMessage($chatId, "Invalid selection. Please try again.");
                    return;
                }
                
                $category = $matches[1];
                $merchant = $matches[2];

                if (!in_array($merchant, ['amazon', 'flipkart', 'both'])) {
                    sendHotDealsMessage($chatId, "Invalid merchant selection.");
                    return;
                }

                try {
                    $stmt = $pdo->prepare("
                        UPDATE hotdealsbot 
                        SET merchant = ?, last_interaction = NOW() 
                        WHERE telegram_id = ?
                    ");
                    $stmt->execute([$merchant, $chatId]);

                    sendHotDealsMessage($chatId, 
                        "✅ *{$category}* alerts from *" . ucfirst($merchant) . "* are now active!\n\n" .
                        "Want to set a maximum price? Send me a number (e.g., 5000) or send /skip to get all deals regardless of price."
                    );

                } catch (Exception $e) {
                    logHotDeals('Database error in merchant selection', [
                        'user_id' => $chatId,
                        'error' => $e->getMessage()
                    ]);
                    sendHotDealsMessage($chatId, "Sorry, something went wrong. Please try again.");
                }
                return;
            }

            // Handle stop all alerts
            if ($data === 'stop_all_alerts') {
                $stmt = $pdo->prepare("
                    UPDATE hotdealsbot 
                    SET is_active = 0, last_interaction = NOW() 
                    WHERE telegram_id = ?
                ");
                $stmt->execute([$chatId]);
                
                if ($stmt->rowCount() > 0) {
                    sendHotDealsMessage($chatId, "✅ All deal alerts have been stopped!");
                } else {
                    sendHotDealsMessage($chatId, "No active alerts found.");
                }
                return;
            }

            // Handle modify alerts
            if ($data === 'modify_alerts') {
                sendHotDealsMessage($chatId, "Use /startalert to set up new alerts or contact support for advanced modifications.");
                return;
            }

            if ($data === 'cancel_stop') {
                sendHotDealsMessage($chatId, "👍 Great! Your deal alerts will continue as before.");
                return;
            }
        }

        // Handle /skip command for price setting
        if ($message === '/skip') {
            $stmt = $pdo->prepare("
                SELECT telegram_id, category 
                FROM hotdealsbot 
                WHERE telegram_id = ? AND price_range IS NULL AND is_active = TRUE
                LIMIT 1
            ");
            $stmt->execute([$chatId]);
            $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($userInfo) {
                $stmt = $pdo->prepare("UPDATE hotdealsbot SET last_interaction = NOW() WHERE telegram_id = ?");
                $stmt->execute([$chatId]);
                
                $categories = getUserCategories($chatId, $pdo);
                $categoryText = implode(', ', array_map('ucfirst', $categories));
                
                sendHotDealsMessage($chatId, 
                    "✅ Perfect! You'll get all *{$categoryText}* deals regardless of price!\n\n" .
                    "Use /mystatus to see all your active alerts."
                );
            } else {
                sendHotDealsMessage($chatId, "No pending price setting found. Use /startalert to set up new alerts!");
            }
            return;
        }

        // Default response
        sendHotDealsMessage($chatId, 
            "Welcome to Hot Deals Bot! 🎉\n\n" .
            "Available commands:\n" .
            "• /start - Start the bot\n" .
            "• /startalert - Set up deal alerts\n" .
            "• /stopalert - Stop alerts\n" .
            "• /mystatus - Check your alerts\n" .
            "• /help - Show all commands\n\n" .
            "Or just send a category name (smartphones, laptops, etc.) to get started!"
        );

    } catch (Exception $e) {
        logHotDeals('Message handling error', [
            'user_id' => $chatId,
            'message' => $message,
            'error' => $e->getMessage()
        ]);
        
        sendHotDealsMessage($chatId, 
            "Sorry, something went wrong while processing your request. Please try again in a moment.\n\n" .
            "If the problem persists, contact support at @AmezPriceSupport"
        );
    }
}

/**
 * Handle callback queries
 */
function handleHotDealsCallback($chatId, $data, $user, $callbackId) {
    global $pdo;
    
    try {
        logHotDeals('Processing callback query', [
            'chatId' => $chatId,
            'data' => $data
        ]);

        // Process the callback through the main message handler
        $fakeInput = ['callback_query' => ['data' => $data, 'from' => $user, 'id' => $callbackId]];
        return handleHotDealsMessage($chatId, $data, $user, $fakeInput);

    } catch (Exception $e) {
        logHotDeals('Error in handleHotDealsCallback', [
            'error' => $e->getMessage(),
            'chatId' => $chatId ?? null,
            'data' => $data ?? null
        ]);
        return false;
    }
}

/**
 * Answer callback queries
 */
function answerHotDealsCallback($callbackQueryId, $text = null) {
    global $telegramConfig;
    
    try {
        $url = "https://api.telegram.org/bot{$telegramConfig['hotdealsbot_token']}/answerCallbackQuery";
        
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
        
        logHotDeals('Callback query answered', [
            'callback_id' => $callbackQueryId,
            'success' => ($httpCode === 200)
        ]);
        
        return ($httpCode === 200);
        
    } catch (Exception $e) {
        logHotDeals('Error answering callback query', [
            'callback_id' => $callbackQueryId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}
?>