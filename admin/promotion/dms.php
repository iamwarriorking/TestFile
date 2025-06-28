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
    // CSRF token validation
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 
             $_SERVER['HTTP_X-CSRF-TOKEN'] ?? 
             $_POST['csrf_token'] ?? '';
    
    // Log for debugging
    file_put_contents('../../logs/promotion.log', 
        "[" . date('Y-m-d H:i:s') . "] DM CSRF Token Check - Received: " . ($token ?: 'empty') . 
        ", Expected: " . ($_SESSION['csrf_token'] ?? 'none') . "\n", 
        FILE_APPEND);
    
    if (!$token || !verifyCsrfToken($token)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'CSRF token not found. Please refresh the page and try again.']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $message = $input['message'] ?? '';
    $image = $input['image'] ?? null;
    $botType = $input['bot_type'] ?? 'amezprice';

    if (!$message) {
        echo json_encode(['status' => 'error', 'message' => 'Message is required']);
        exit;
    }

    if (!in_array($botType, ['amezprice', 'hotdeals'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid bot type selected']);
        exit;
    }

    // Bot configuration based on selection
    if ($botType === 'amezprice') {
        $botToken = $telegramConfig['amezpricebot_token'];
        $userTable = 'users';
        $userIdColumn = 'telegram_id';
    } else {
        $botToken = $telegramConfig['hotdealsbot_token'];
        $userTable = 'hotdealsbot';
        $userIdColumn = 'telegram_id';
    }

    $tempImagePath = null;
    if ($image) {
        $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $image));
        $tempImagePath = "../../assets/images/promotion/" . time() . "_promo.jpg";
        file_put_contents($tempImagePath, $imageData);
        file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] DM promotion image saved: $tempImagePath\n", FILE_APPEND);
    }

    // Dynamic query based on bot type
    $stmt = $pdo->query("SELECT $userIdColumn FROM $userTable WHERE $userIdColumn IS NOT NULL AND $userIdColumn != '' AND $userIdColumn != '0'");
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Debug logging
    file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] Selected bot: $botType, Found " . count($users) . " users\n", FILE_APPEND);
    
    if (count($users) > 0) {
        $sampleUsers = array_slice($users, 0, 3);
        foreach ($sampleUsers as $i => $userId) {
            file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] Sample user " . ($i+1) . ": " . substr($userId, 0, 5) . "***\n", FILE_APPEND);
        }
    }

    $successCount = 0;
    $failedCount = 0;
    
    foreach ($users as $userId) {
        // Set URL for each iteration properly
        if ($tempImagePath) {
            $apiUrl = "https://api.telegram.org/bot{$botToken}/sendPhoto";
            $payload = [
                'chat_id' => $userId,
                'caption' => $message,
                'parse_mode' => 'Markdown'
            ];
        } else {
            $apiUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";
            $payload = [
                'chat_id' => $userId,
                'text' => $message,
                'parse_mode' => 'Markdown'
            ];
        }

        $ch = curl_init($apiUrl);
        
        if ($tempImagePath) {
            $payload['photo'] = new CURLFile($tempImagePath);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Better error handling and logging
        if ($curlError) {
            file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] CURL Error for user $userId: $curlError\n", FILE_APPEND);
            $failedCount++;
            continue;
        }
        
        if ($httpCode !== 200) {
            file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] HTTP Error $httpCode for user $userId\n", FILE_APPEND);
            $failedCount++;
            continue;
        }
        
        $result = json_decode($response, true);
        
        if ($result && isset($result['ok']) && $result['ok']) {
            $successCount++;
            file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] Successfully sent to user $userId\n", FILE_APPEND);
        } else {
            $failedCount++;
            $errorMsg = $result['description'] ?? 'Unknown error';
            file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] Failed to send to user $userId: $errorMsg\n", FILE_APPEND);
        }
        
        sleep(0.1);
    }

    if ($tempImagePath) {
        unlink($tempImagePath);
    }

    $totalUsers = count($users);
    file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] DM promotion completed via $botType bot - Total: $totalUsers, Success: $successCount, Failed: $failedCount\n", FILE_APPEND);
    
    echo json_encode([
        'status' => 'success', 
        'message' => "Promotion sent via " . ucfirst($botType) . " Bot to $successCount users (Total: $totalUsers, Failed: $failedCount)"
    ]);
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
    <title>DM Promotion - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include '../../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <h1>DM Promotion</h1>
            <div class="card">
                <form id="dm-promotion-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    
                    <label for="bot_type">Select Bot</label>
                    <select name="bot_type" id="bot_type" required>
                        <option value="amezprice">AmezPrice Bot</option>
                        <option value="hotdeals">Hot Deals Bot</option>
                    </select>
                    
                    <label for="message">Message</label>
                    <textarea name="message" id="message" required></textarea>
                    
                    <label for="image">Image (Optional)</label>
                    <input type="file" name="image" id="image" accept="image/*">
                    
                    <button type="submit" class="btn btn-primary">Send DM Promotion</button>
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