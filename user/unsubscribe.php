<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once 'unsub_handler.php';

// Get parameters from URL
$email = isset($_GET['email']) ? $_GET['email'] : '';
$token = isset($_GET['token']) ? $_GET['token'] : '';
$confirmed = isset($_GET['confirmed']) && $_GET['confirmed'] === 'true';

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = "Invalid email format.";
}
// Verify token
else if (!verifyUnsubscribeToken($email, $token)) {
    $error = "Invalid or expired unsubscribe token.";
}
// Process unsubscribe if confirmed
else if ($confirmed) {
    try {
        $stmt = $pdo->prepare("UPDATE email_subscriptions SET subscribed = 'no' WHERE email = ?");
        $stmt->execute([$email]);
        $success = "You have been successfully unsubscribed from promotional emails.";
        file_put_contents(__DIR__ . '/../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Email $email unsubscribed via token\n", FILE_APPEND);
    } catch (Exception $e) {
        $error = "Database error. Please try again later.";
        error_log("Unsubscribe error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Subscription - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/user.css">
</head>
<body>
    <?php include 'include/navbar.php'; ?>
    <div class="container" style="max-width: 600px; margin: 50px auto; text-align: center;">
        <div class="card">
            <?php if (isset($success)): ?>
                <i class="fas fa-check-circle" style="color: #4CAF50; font-size: 48px; margin-bottom: 20px;"></i>
                <h2>Successfully Unsubscribed</h2>
                <p><?php echo htmlspecialchars($success); ?></p>
                <p>You will no longer receive deal alerts and promotional emails from AmezPrice.</p>
                <div style="margin-top: 30px;">
                    <a href="https://amezprice.com/" class="btn btn-primary">Go to Homepage</a>
                </div>
            <?php elseif (isset($error)): ?>
                <i class="fas fa-exclamation-circle" style="color: #ff5722; font-size: 48px; margin-bottom: 20px;"></i>
                <h2>Unsubscribe Failed</h2>
                <p><?php echo htmlspecialchars($error); ?></p>
                <div style="margin-top: 30px;">
                    <a href="https://amezprice.com/" class="btn btn-primary">Go to Homepage</a>
                </div>
            <?php else: ?>
                <h2>Unsubscribe from AmezPrice Emails</h2>
                <p>Are you sure you want to unsubscribe <strong><?php echo htmlspecialchars($email); ?></strong> from promotional emails?</p>
                <p>You will no longer receive deal alerts and special offers from AmezPrice.</p>
                <div style="margin-top: 30px;">
                    <a href="unsubscribe.php?email=<?php echo urlencode($email); ?>&token=<?php echo urlencode($token); ?>&confirmed=true" class="btn btn-delete">Yes, Unsubscribe Me</a>
                    <a href="/" class="btn btn-primary">No, Keep Me Subscribed</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include 'include/footer.php'; ?>
</body>
</html>