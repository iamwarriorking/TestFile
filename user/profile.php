<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/globals.php';
require_once '../email/send.php';
require_once '../middleware/csrf.php';

startApplicationSession();

// Debug logging
error_log("Session data on user profile: " . print_r($_SESSION, true));
error_log("JWT token present: " . (isset($_SESSION['jwt']) ? 'yes' : 'no'));

if (!isset($_SESSION['user_id'])) {
    error_log("No user_id in session, redirecting to login");
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT first_name, last_name, username, email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'update_profile') {
        $firstName = trim($input['first_name'] ?? '');
        $lastName = trim($input['last_name'] ?? '');
        $username = trim($input['username'] ?? '');
        $email = trim($input['email'] ?? '');
        $otp = $input['otp'] ?? null;

        if (!$firstName || !$lastName || !$username || !$email) {
            echo json_encode(['status' => 'error', 'message' => 'All fields are required']);
            exit;
        }

        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $username)) {
            echo json_encode(['status' => 'error', 'message' => 'Username must start with a letter and contain only letters and numbers']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid email format']);
            exit;
        }

        // Check for existing username or email (excluding current user)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $stmt->execute([$username, $email, $userId]);
        if ($stmt->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Username or email already exists']);
            exit;
        }

        if ($email !== $user['email'] && !$otp) {
            // Generate OTP for email change
            $otp = sprintf("%06d", random_int(0, 999999));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            $stmt = $pdo->prepare("INSERT INTO otps (email, otp, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$email, $otp, $expiresAt]);

            $subject = "Your Email Verification Code";
            $message = file_get_contents('../email/templates/otp_email.php');
            $message = str_replace('{{otp}}', $otp, $message);
            sendEmail($email, $subject, $message, 'otp');

            file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] OTP sent to $email for profile update\n", FILE_APPEND);
            echo json_encode(['status' => 'success', 'message' => 'OTP sent to new email']);
            exit;
        }

        if ($email !== $user['email'] && $otp) {
            // Verify OTP
            $stmt = $pdo->prepare("SELECT * FROM otps WHERE email = ? AND otp = ? AND expires_at > NOW()");
            $stmt->execute([$email, $otp]);
            if (!$stmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP']);
                exit;
            }

            // Update email subscription
            $stmt = $pdo->prepare("UPDATE email_subscriptions SET email = ? WHERE email = ?");
            $stmt->execute([$email, $user['email']]);
        }

        // Update profile
        $stmt = $pdo->prepare("
            UPDATE users 
            SET first_name = ?, last_name = ?, username = ?, email = ? 
            WHERE id = ?
        ");
        $stmt->execute([$firstName, $lastName, $username, $email, $userId]);

        $stmt = $pdo->prepare("DELETE FROM otps WHERE email = ?");
        $stmt->execute([$email]);

        file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Profile updated for user ID $userId\n", FILE_APPEND);
        echo json_encode(['status' => 'success', 'message' => 'Profile updated']);
        exit;
    }

    if ($action === 'update_password') {
        $oldPassword = $input['old_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';
        $otp = $input['otp'] ?? null;

        if (!$oldPassword || !$newPassword || !$confirmPassword) {
            echo json_encode(['status' => 'error', 'message' => 'All password fields are required']);
            exit;
        }

        if ($newPassword !== $confirmPassword) {
            echo json_encode(['status' => 'error', 'message' => 'New passwords do not match']);
            exit;
        }

        if (strlen($newPassword) < 8) {
            echo json_encode(['status' => 'error', 'message' => 'New password must be at least 8 characters']);
            exit;
        }

        // Verify old password
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        if (!password_verify($oldPassword, $stmt->fetchColumn())) {
            echo json_encode(['status' => 'error', 'message' => 'Incorrect old password']);
            exit;
        }

        if (!$otp) {
            // Generate OTP
            $otp = sprintf("%06d", random_int(0, 999999));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            $stmt = $pdo->prepare("INSERT INTO otps (email, otp, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$user['email'], $otp, $expiresAt]);

            $subject = "Your Password Change Verification Code";
            $message = file_get_contents('../email/templates/otp_email.php');
            $message = str_replace('{{otp}}', $otp, $message);
            sendEmail($user['email'], $subject, $message, 'otp');

            file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] OTP sent to {$user['email']} for password change\n", FILE_APPEND);
            echo json_encode(['status' => 'success', 'message' => 'OTP sent to your email']);
            exit;
        }

        // Verify OTP
        $stmt = $pdo->prepare("SELECT * FROM otps WHERE email = ? AND otp = ? AND expires_at > NOW()");
        $stmt->execute([$user['email'], $otp]);
        if (!$stmt->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP']);
            exit;
        }
        
        // Update password
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $userId]);
            
            $stmt = $pdo->prepare("DELETE FROM otps WHERE email = ?");
            $stmt->execute([$user['email']]);
            
            $pdo->commit();
            file_put_contents(__DIR__ . '/../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Password updated for user ID $userId\n", FILE_APPEND);
            echo json_encode(['status' => 'success', 'message' => 'Password updated']);
        } catch (Exception $e) {
            $pdo->rollBack();
            file_put_contents(__DIR__ . '/../logs/error.log', "[" . date('Y-m-d H:i:s') . "] Error updating password: " . $e->getMessage() . "\n", FILE_APPEND);
            echo json_encode(['status' => 'error', 'message' => 'An error occurred. Please try again.']);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/user.css">
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <div class="user-container">
        <?php include '../include/user_sidebar.php'; ?>
        <div class="user-content">
            <h1>My Profile</h1>
            <div style="display: flex; gap: 24px;">
                <div class="card" style="flex: 1;">
                    <h2>Update Profile</h2>
                    <form id="profile-form">
                        <label for="first_name">First Name</label>
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                        <label for="last_name">Last Name</label>
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                        <label for="username">Username</label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        <label for="email">Email</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        <button type="submit" class="btn btn-primary">Save Profile</button>
                    </form>
                </div>
                <div class="card" style="flex: 1;">
                    <h2>Update Password</h2>
                    <form id="password-form">
                        <label for="old_password">Old Password</label>
                        <input type="password" name="old_password" required>
                        <label for="new_password">New Password</label>
                        <input type="password" name="new_password" required>
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" name="confirm_password" required>
                        <button type="submit" class="btn btn-primary">Save Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php include '../include/footer.php'; ?>
    <div id="otp-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('otp-popup')"></i>
        <div class="popup-content">
            <h3>Enter OTP</h3>
            <p>OTP sent to your email.</p>
            <input type="text" id="otp-input" placeholder="Enter OTP">
            <button class="btn btn-primary" onclick="submitOtp()">Submit</button>
            <button class="btn btn-secondary" onclick="hidePopup('otp-popup')">Cancel</button>
            <p id="resend-timer" style="display: none;">Resend in <span id="timer">30</span> seconds</p>
            <a href="#" id="resend-otp" style="display: none;" onclick="resendOtp()">Resend OTP</a>
        </div>
    </div>
    <div id="success-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('success-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="error-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('error-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div class="popup-overlay" style="display: none;"></div>
    <script src="/assets/js/user.js"></script>
    <script>
        let currentForm = null;
        let formData = null;

        document.getElementById('profile-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            currentForm = 'profile';
            formData = new FormData(e.target);
            const data = {
                action: 'update_profile',
                first_name: formData.get('first_name'),
                last_name: formData.get('last_name'),
                username: formData.get('username'),
                email: formData.get('email')
            };

            await submitProfile(data);
        });

        document.getElementById('password-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            currentForm = 'password';
            formData = new FormData(e.target);
            const data = {
                action: 'update_password',
                old_password: formData.get('old_password'),
                new_password: formData.get('new_password'),
                confirm_password: formData.get('confirm_password')
            };

            await submitProfile(data);
        });

        async function submitProfile(data) {
            const response = await fetch('/user/profile.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(data)
            });
            const result = await response.json();

            if (result.status === 'success' && result.message.includes('OTP')) {
                showPopup('otp-popup', document.querySelector('#otp-popup .popup-content').innerHTML);
                startResendTimer();
            } else if (result.status === 'success') {
                showPopup('success-popup', `<h3>Success</h3><p>${result.message}</p>`);
            } else {
                showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
            }
        }

        async function submitOtp() {
            const otp = document.getElementById('otp-input').value;
            const data = {
                action: currentForm === 'profile' ? 'update_profile' : 'update_password',
                first_name: formData.get('first_name'),
                last_name: formData.get('last_name'),
                username: formData.get('username'),
                email: formData.get('email'),
                old_password: formData.get('old_password'),
                new_password: formData.get('new_password'),
                confirm_password: formData.get('confirm_password'),
                otp: otp
            };

            const response = await fetch('/user/profile.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(data)
            });
            const result = await response.json();

            if (result.status === 'success') {
                showPopup('success-popup', `<h3>Success</h3><p>${result.message}</p>`);
            } else {
                showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
            }
        }

        async function resendOtp() {
            const data = {
                action: currentForm === 'profile' ? 'update_profile' : 'update_password',
                first_name: formData.get('first_name'),
                last_name: formData.get('last_name'),
                username: formData.get('username'),
                email: formData.get('email'),
                old_password: formData.get('old_password'),
                new_password: formData.get('new_password'),
                confirm_password: formData.get('confirm_password')
            };

            const response = await fetch('/user/profile.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(data)
            });
            const result = await response.json();

            if (result.status === 'success') {
                startResendTimer();
            } else {
                showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
            }
        }

        function startResendTimer() {
            let timeLeft = 30;
            const timerEl = document.getElementById('timer');
            const resendEl = document.getElementById('resend-otp');
            const timerContainer = document.getElementById('resend-timer');
            timerContainer.style.display = 'block';
            resendEl.style.display = 'none';

            const interval = setInterval(() => {
                timeLeft--;
                timerEl.textContent = timeLeft;
                if (timeLeft <= 0) {
                    clearInterval(interval);
                    timerContainer.style.display = 'none';
                    resendEl.style.display = 'block';
                }
            }, 1000);
        }
    </script>
</body>
</html>