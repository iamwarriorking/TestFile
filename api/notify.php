<?php
require_once '../config/database.php';
require_once '../config/telegram.php';
require_once '../middleware/csrf.php';
require_once '../email/send.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$userIds = $input['user_ids'] ?? [];
$asin = $input['asin'] ?? '';
$messageType = $input['message_type'] ?? '';
$details = $input['details'] ?? [];

if (empty($userIds) || !$asin || !$messageType || !$details) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM products WHERE asin = ?");
$stmt->execute([$asin]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo json_encode(['status' => 'error', 'message' => 'Product not found']);
    exit;
}

$botToken = $telegramConfig['amezpricebot_token'];
$baseUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";

$emailSuccessCount = 0;
$telegramSuccessCount = 0;
$errors = [];

foreach ($userIds as $userId) {
    // Get user email for email notifications
    $userStmt = $pdo->prepare("SELECT email FROM users WHERE telegram_id = ? AND email IS NOT NULL");
    $userStmt->execute([$userId]);
    $userEmail = $userStmt->fetchColumn();

    $message = '';
    $buttons = [
        ['text' => 'Buy Now ✅', 'url' => $product['affiliate_link']],
        ['text' => 'Stop Tracking 🔴', 'callback_data' => "stop_{$asin}"],
        ['text' => 'Price History 📈', 'url' => $product['website_url']],
        ['text' => 'Today\'s Deals 🛍️', 'url' => $telegramConfig['channels']['amezprice']]
    ];

    switch ($messageType) {
        case 'price_drop':
            $percentage = round(($details['previous_price'] - $details['current_price']) / $details['previous_price'] * 100);
            $message = "⬇️ The product price decreased by ₹" . number_format($details['previous_price'] - $details['current_price'], 0, '.', ',') . " ({$percentage}% off)\n\n"
                . "[{$product['name']}]({$product['affiliate_link']})\n\n"
                . "Previous Price: ₹" . number_format($details['previous_price'], 0, '.', ',') . "\n\n"
                . "**Current Price: ₹" . number_format($details['current_price'], 0, '.', ',') . "**\n\n"
                . "🔥 {$details['tracker_count']} users are tracking this!\n\n"
                . "⌚ Updated at " . date('d M Y, h:i A', strtotime($product['last_updated']));
            
            // Send email notification for price alerts
            if ($userEmail) {
                $emailSubject = "Price Drop Alert - {$product['name']}";
                $emailBody = generatePriceAlertEmail($product, $details, 'price_drop');
                if (sendEmail($userEmail, $emailSubject, $emailBody, 'alert')) {
                    $emailSuccessCount++;
                }
            }
            break;

        case 'price_increase':
            $percentage = round(($details['current_price'] - $details['previous_price']) / $details['previous_price'] * 100);
            $message = "⬆️ The product price increased by ₹" . number_format($details['current_price'] - $details['previous_price'], 0, '.', ',') . " ({$percentage}% up)\n\n"
                . "[{$product['name']}]({$product['affiliate_link']})\n\n"
                . "Previous Price: ₹" . number_format($details['previous_price'], 0, '.', ',') . "\n\n"
                . "**Current Price: ₹" . number_format($details['current_price'], 0, '.', ',') . "**\n\n"
                . "🔥 {$details['tracker_count']} users are tracking this!\n\n"
                . "⌚ Updated at " . date('d M Y, h:i A', strtotime($product['last_updated']));
            
            // Send email notification for price alerts  
            if ($userEmail) {
                $emailSubject = "Price Increase Alert - {$product['name']}";
                $emailBody = generatePriceAlertEmail($product, $details, 'price_increase');
                if (sendEmail($userEmail, $emailSubject, $emailBody, 'alert')) {
                    $emailSuccessCount++;
                }
            }
            break;

        case 'low_stock':
            $message = "⚠️ Product is running low on stock! Only {$details['quantity']} left\n\n"
                . "[{$product['name']}]({$product['affiliate_link']})\n\n"
                . "**Current Price: ₹" . number_format($details['current_price'], 0, '.', ',') . "**\n\n"
                . "🔥 {$details['tracker_count']} users are tracking this!\n\n"
                . "⌚ Updated at " . date('d M Y, h:i A', strtotime($product['last_updated']));
            
            // Send email notification for stock alerts
            if ($userEmail) {
                $emailSubject = "Low Stock Alert - {$product['name']}";
                $emailBody = generateStockAlertEmail($product, $details, 'low_stock');
                if (sendEmail($userEmail, $emailSubject, $emailBody, 'stock')) {
                    $emailSuccessCount++;
                }
            }
            break;

        case 'out_of_stock':
            $message = "😔 Product is now out of stock\n\n"
                . "[{$product['name']}]({$product['affiliate_link']})\n\n"
                . "Last Price: ₹" . number_format($details['previous_price'], 0, '.', ',') . "\n\n"
                . "🔥 {$details['tracker_count']} users are tracking this!\n\n"
                . "⌚ Updated at " . date('d M Y, h:i A', strtotime($product['last_updated']));
            
            // Send email notification for stock alerts
            if ($userEmail) {
                $emailSubject = "Out of Stock Alert - {$product['name']}";
                $emailBody = generateStockAlertEmail($product, $details, 'out_of_stock');
                if (sendEmail($userEmail, $emailSubject, $emailBody, 'stock')) {
                    $emailSuccessCount++;
                }
            }
            break;

        case 'in_stock':
            $message = "🎉 Product is back in stock!\n\n"
                . "[{$product['name']}]({$product['affiliate_link']})\n\n"
                . "**Current Price: ₹" . number_format($details['current_price'], 0, '.', ',') . "**\n\n"
                . "🔥 {$details['tracker_count']} users are tracking this!\n\n"
                . "⌚ Updated at " . date('d M Y, h:i A', strtotime($product['last_updated']));
            
            // Send email notification for stock alerts
            if ($userEmail) {
                $emailSubject = "Back in Stock - {$product['name']}";
                $emailBody = generateStockAlertEmail($product, $details, 'in_stock');
                if (sendEmail($userEmail, $emailSubject, $emailBody, 'stock')) {
                    $emailSuccessCount++;
                }
            }
            break;
    }

    // Send Telegram notification
    $payload = [
        'chat_id' => $userId,
        'text' => $message,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true,
        'reply_markup' => json_encode(['inline_keyboard' => array_chunk($buttons, 2)])
    ];

    $ch = curl_init($baseUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Changed to true for security

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$error && $httpCode === 200) {
        $telegramSuccessCount++;
    } else {
        $errors[] = "Telegram notification failed for user $userId: $error";
    }
}

// Function to generate price alert email using existing template
function generatePriceAlertEmail($product, $details, $alertType) {
    $templatePath = '../email/templates/alert_email.php';
    
    if (!file_exists($templatePath)) {
        return '';
    }
    
    // Calculate percentage change
    if ($alertType === 'price_drop') {
        $change = round(($details['previous_price'] - $details['current_price']) / $details['previous_price'] * 100);
        $direction = 'down';
    } else {
        $change = round(($details['current_price'] - $details['previous_price']) / $details['previous_price'] * 100);
        $direction = 'up';
    }
    
    ob_start();
    
    $template_vars = array(
        '{{name}}' => $product['name'],
        '{{image_path}}' => 'https://amezprice.com' . $product['image_path'],
        '{{previous_price}}' => number_format($details['previous_price'], 0, '.', ','),
        '{{current_price}}' => number_format($details['current_price'], 0, '.', ','),
        '{{change}}' => $change,
        '{{direction}}' => $direction,
        '{{tracker_count}}' => $details['tracker_count'],
        '{{affiliate_link}}' => $product['affiliate_link'],
        '{{history_url}}' => $product['website_url']
    );
    
    $content = file_get_contents($templatePath);
    $content = str_replace(array_keys($template_vars), array_values($template_vars), $content);
    
    return $content;
}

// Function to generate stock alert email using existing template
function generateStockAlertEmail($product, $details, $stockType) {
    $templatePath = '../email/templates/stock_email.php';
    
    if (!file_exists($templatePath)) {
        return '';
    }
    
    ob_start();
    
    $template_vars = array(
        '{{name}}' => $product['name'],
        '{{image_path}}' => 'https://amezprice.com' . $product['image_path'],
        '{{current_price}}' => number_format($details['current_price'] ?? $details['previous_price'], 0, '.', ','),
        '{{last_price}}' => number_format($details['previous_price'], 0, '.', ','),
        '{{lowest_price}}' => number_format($product['lowest_price'] ?? $details['current_price'], 0, '.', ','),
        '{{highest_price}}' => number_format($product['highest_price'] ?? $details['current_price'], 0, '.', ','),
        '{{quantity}}' => $details['quantity'] ?? 0,
        '{{tracker_count}}' => $details['tracker_count'],
        '{{last_updated}}' => date('d M Y, h:i A', strtotime($product['last_updated'])),
        '{{affiliate_link}}' => $product['affiliate_link'],
        '{{history_url}}' => $product['website_url'],
        '{{unsubscribe_url}}' => 'https://amezprice.com/unsubscribe/' . urlencode($product['asin'])
    );
    
    $content = file_get_contents($templatePath);
    $content = str_replace(array_keys($template_vars), array_values($template_vars), $content);
    
    return $content;
}

echo json_encode([
    'status' => 'success', 
    'message' => 'Notifications sent',
    'telegram_sent' => $telegramSuccessCount,
    'email_sent' => $emailSuccessCount,
    'total_users' => count($userIds),
    'errors' => $errors
]);
?>