<?php
require_once '../../config/database.php';
require_once '../../config/mail.php';
require_once '../../config/globals.php';
require_once '../../email/send.php';
require_once '../../config/security.php';
require_once '../../middleware/csrf.php';
require_once '../../user/unsub_handler.php';

startApplicationSession();

// Generate CSRF token
$csrfToken = generateCsrfToken();

if (!isset($_SESSION['admin_id'])) {
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Set proper headers first
    header('Content-Type: application/json');
    
    // CSRF token validation
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 
             $_SERVER['HTTP_X-CSRF-TOKEN'] ?? 
             $_POST['csrf_token'] ?? '';
    
    // Log for debugging
    file_put_contents('../../logs/promotion.log', 
        "[" . date('Y-m-d H:i:s') . "] Email CSRF Token Check - Received: " . ($token ?: 'empty') . 
        ", Expected: " . ($_SESSION['csrf_token'] ?? 'none') . "\n", 
        FILE_APPEND);
    
    if (!$token || !verifyCsrfToken($token)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'CSRF token not found. Please refresh the page and try again.']);
        exit;
    }
    
    // Get raw input and decode
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    // Log the received data for debugging
    file_put_contents('../../logs/promotion.log', 
        "[" . date('Y-m-d H:i:s') . "] Raw input: " . $rawInput . "\n", 
        FILE_APPEND);
    
    // Validate JSON decode
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data received']);
        exit;
    }
    
    $subject = trim($input['subject'] ?? '');
    $message = trim($input['message'] ?? '');

    // Log parsed data
    file_put_contents('../../logs/promotion.log', 
        "[" . date('Y-m-d H:i:s') . "] Parsed - Subject: '$subject', Message length: " . strlen($message) . "\n", 
        FILE_APPEND);

    if (!$subject || !$message) {
        echo json_encode(['status' => 'error', 'message' => 'Subject and message are required']);
        exit;
    }

    // Get subscribed emails
    $stmt = $pdo->query("SELECT email FROM email_subscriptions WHERE subscribed = 'yes'");
    $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($emails)) {
        echo json_encode(['status' => 'error', 'message' => 'No subscribed users found']);
        exit;
    }

    $successCount = 0;
    foreach ($emails as $email) {
        // Get the token and unsubscribe URL
        $token = generateUnsubscribeToken($email);
        $unsubscribe_url = "https://amezprice.com/user/unsubscribe.php?email=".urlencode($email)."&token=".urlencode($token);
        
        // Include the template and extract variables to make them available
        ob_start();
        $message_content = $message; // Store the message content
        include '../../email/templates/offer_email.php'; // This will process the PHP in the template
        $finalMessage = ob_get_clean();
        
        if (sendEmail($email, $subject, $finalMessage, 'offers')) {
            $successCount++;
        }
        usleep(100000);
    }

    file_put_contents('../../logs/promotion.log', "[" . date('Y-m-d H:i:s') . "] Email promotion sent to $successCount users\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => "Promotion sent to $successCount users"]);
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
    <title>Email Promotion - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include '../../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <h1>Email Promotion</h1>
            <div class="card">
                <!-- Changed form ID to avoid conflict with admin.js generic handler -->
                <form id="custom-email-promotion-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <label for="subject">Subject</label>
                    <input type="text" name="subject" id="subject" required>
                    <label for="message">Message (HTML)</label>
                    <textarea name="message" id="message" required rows="10" cols="50"></textarea>
                    <button type="submit" class="btn btn-primary" id="submit-btn">Send Email Promotion</button>
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