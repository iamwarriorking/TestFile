<?php
require_once '../../config/database.php';
require_once '../../config/telegram.php';
require_once '../../config/security.php';
require_once '../../config/globals.php';
require_once '../../middleware/csrf.php';

startApplicationSession();

// Generate CSRF token
$csrfToken = generateCsrfToken();

if (!isset($_SESSION['admin_id'])) {
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Try multiple header formats for CSRF token
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 
             $_SERVER['HTTP_X-CSRF-TOKEN'] ?? 
             $_POST['csrf_token'] ?? '';
    
    // Log for debugging
    file_put_contents('../../logs/promotion.log', 
        "[" . date('Y-m-d H:i:s') . "] CSRF Token Check - Received: " . ($token ?: 'empty') . 
        ", Expected: " . ($_SESSION['csrf_token'] ?? 'none') . "\n", 
        FILE_APPEND);
    
    if (!$token || !verifyCsrfToken($token)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'CSRF token not found. Please refresh the page and try again.']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $channel = $input['channel'] ?? '';
    $message = $input['message'] ?? '';
    $image = $input['image'] ?? null;

    if (!in_array($channel, ['amezprice', 'hotdeals', 'updates']) || !$message) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid channel or message']);
        exit;
    }

    $botToken = $telegramConfig['amezpricebot_token'];
    $chatId = $telegramConfig['channels'][$channel];
    
    // **FIX: Choose correct API endpoint based on whether image exists**
    $tempImagePath = null;
    $hasImage = false;
    
    if ($image && !empty(trim($image))) {
        // Process image
        $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $image));
        if ($imageData !== false && strlen($imageData) > 0) {
            $tempImagePath = "../../assets/images/promotion/" . time() . "_promo.jpg";
            if (file_put_contents($tempImagePath, $imageData) !== false) {
                $hasImage = true;
                file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] Promotion image saved: $tempImagePath\n", FILE_APPEND);
            }
        }
    }
    
    $payload = [
        'chat_id' => $chatId,
        'parse_mode' => 'Markdown'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    if ($hasImage && $tempImagePath && file_exists($tempImagePath)) {
        // Send photo with caption
        $url = "https://api.telegram.org/bot{$botToken}/sendPhoto";
        $payload['photo'] = new CURLFile($tempImagePath);
        $payload['caption'] = $message;
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        
        file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] Sending photo with caption to $channel\n", FILE_APPEND);
    } else {
        // Send text message only
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $payload['text'] = $message;
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        
        file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] Sending text message to $channel\n", FILE_APPEND);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Clean up temporary image
    if ($tempImagePath && file_exists($tempImagePath)) {
        unlink($tempImagePath);
    }

    // Log the full response for debugging
    file_put_contents('../../logs/promotion.log', 
        "[" . date('Y-m-d H:i:s') . "] Telegram API Response - HTTP Code: $httpCode, Response: $response\n", 
        FILE_APPEND);

    if ($error) {
        file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] cURL Error: $error\n", FILE_APPEND);
        echo json_encode(['status' => 'error', 'message' => 'Network error occurred']);
        exit;
    }

    $result = json_decode($response, true);
    
    if ($result && isset($result['ok']) && $result['ok'] === true) {
        file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] Promotion sent successfully to $channel\n", FILE_APPEND);
        echo json_encode(['status' => 'success', 'message' => 'Promotion sent successfully']);
    } else {
        $errorMsg = isset($result['description']) ? $result['description'] : 'Unknown error occurred';
        file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] Failed to send promotion to $channel: $errorMsg\n", FILE_APPEND);
        echo json_encode(['status' => 'error', 'message' => "Failed to send promotion: $errorMsg"]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <title>Channel Promotion - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include '../../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <h1>Channel Promotion</h1>
            <div class="card">
                <form id="promotion-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <label for="channel">Select Channel</label>
                    <select name="channel" id="channel" required>
                        <option value="amezprice">AmezPrice</option>
                        <option value="hotdeals">HotDeals</option>
                        <option value="updates">Updates</option>
                    </select>
                    <label for="message">Message</label>
                    <textarea name="message" id="message" required placeholder="Enter your promotion message here..."></textarea>
                    <label for="image">Image (Optional)</label>
                    <input type="file" name="image" id="image" accept="image/*">
                    <button type="submit" class="btn btn-primary">Send Promotion</button>
                </form>
            </div>
        </div>
    </div>
    <?php include '../../include/footer.php'; ?>
    <div id="success-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('success-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="error-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('error-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div class="popup-overlay" style="display: none;"></div>
    <script src="/assets/js/admin.js"></script>
</body>
</html>