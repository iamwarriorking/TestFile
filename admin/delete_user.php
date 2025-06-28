<?php
require_once '../config/database.php';
require_once '../config/globals.php';
require_once '../config/mail.php';
require_once '../email/send.php';
require_once '../middleware/csrf.php';

startApplicationSession();

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['user_id'] ?? null;
$action = $input['action'] ?? '';
$otp = $input['otp'] ?? null;

// Get admin email
$adminStmt = $pdo->prepare("SELECT email FROM admins WHERE id = ?");
$adminStmt->execute([$_SESSION['admin_id']]);
$adminEmail = $adminStmt->fetchColumn();

if (!$userId || !$action) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

if ($action === 'request_otp') {
    // Generate OTP
    $otp = sprintf("%06d", random_int(0, 999999));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

    $stmt = $pdo->prepare("INSERT INTO otps (email, otp, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$adminEmail, $otp, $expiresAt]);

    $subject = "Your User Deletion Verification Code";
    $message = file_get_contents('../email/templates/otp_email.php');
    $message = str_replace('{{otp}}', $otp, $message);
    sendEmail($adminEmail, $subject, $message, 'otp');

    file_put_contents('../logs/admin.log', "[" . date('Y-m-d H:i:s') . "] OTP sent to $adminEmail for user deletion\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => 'OTP sent to admin email']);
    exit;
}

if ($action === 'verify_otp') {
    if (!$otp) {
        echo json_encode(['status' => 'error', 'message' => 'OTP is required']);
        exit;
    }

    // Verify OTP
    $stmt = $pdo->prepare("SELECT * FROM otps WHERE email = ? AND otp = ? AND expires_at > NOW()");
    $stmt->execute([$adminEmail, $otp]);
    if (!$stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP']);
        exit;
    }

    // Delete user and related data
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("DELETE FROM user_products WHERE user_id = ?");
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare("DELETE FROM user_requests WHERE user_id = ?");
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare("DELETE FROM user_behavior WHERE user_id = ?");
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare("DELETE FROM email_subscriptions WHERE email = (SELECT email FROM users WHERE id = ?)");
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ?");
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare("DELETE FROM otps WHERE email = ?");
        $stmt->execute([$adminEmail]);

        $pdo->commit();
        file_put_contents('../logs/admin.log', "[" . date('Y-m-d H:i:s') . "] User $userId deleted by admin $adminEmail\n", FILE_APPEND);
        echo json_encode(['status' => 'success', 'message' => 'User deleted successfully']);
    } catch (Exception $e) {
        $pdo->rollBack();
        file_put_contents('../logs/admin.log', "[" . date('Y-m-d H:i:s') . "] Error deleting user $userId: " . $e->getMessage() . "\n", FILE_APPEND);
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete user']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
?>