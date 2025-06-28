<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/globals.php';
require_once __DIR__ . '/../email/send.php';
require_once __DIR__ . '/../middleware/csrf.php';
require_once __DIR__ . '/../config/session.php';
startApplicationSession();

// Log form rendering
file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Forgot password form rendered, Session ID: " . session_id() . "\n", FILE_APPEND);

// Prevent raw JSON display for GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Render form (already below)
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug logging
    file_put_contents(__DIR__ . '/../logs/auth.log', 
        "[" . date('Y-m-d H:i:s') . "] POST request received for forgot password\n" .
        "Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set') . "\n" .
        "Accept: " . ($_SERVER['HTTP_ACCEPT'] ?? 'not set') . "\n", 
        FILE_APPEND);
    
    // Ensure JSON response
    header('Content-Type: application/json');
    
    try {
        $rawInput = file_get_contents('php://input');
        file_put_contents(__DIR__ . '/../logs/auth.log', 
            "[" . date('Y-m-d H:i:s') . "] Raw input: " . $rawInput . "\n", 
            FILE_APPEND);
        
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input: ' . json_last_error_msg());
        }
        
        $email = trim($input['email'] ?? '');
        $otp = $input['otp'] ?? null;
        $newPassword = $input['new_password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';

        file_put_contents(__DIR__ . '/../logs/auth.log', 
            "[" . date('Y-m-d H:i:s') . "] Parsed data: email=$email, otp=" . ($otp ? 'provided' : 'empty') . ", new_password=" . ($newPassword ? 'provided' : 'empty') . "\n", 
            FILE_APPEND);

        if (!$email) {
            echo json_encode(['status' => 'error', 'message' => 'Email is required']);
            exit;
        }

        // Check admin or user
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ?");
        $stmt->execute([$email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin && !$user) {
            echo json_encode(['status' => 'error', 'message' => 'Email not found']);
            exit;
        }

        $account = $admin ?: $user;
        $table = $admin ? 'admins' : 'users';

        if (!$otp && !$newPassword) {
            // Generate OTP
            $otp = sprintf("%06d", random_int(0, 999999));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            
            // Add rate limiting
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM otps WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 5) {
                echo json_encode(['status' => 'error', 'message' => 'Too many OTP requests. Please try again later.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO otps (email, otp, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$email, $otp, $expiresAt]);

            $subject = "Your Password Reset Verification Code";
            $message = file_get_contents(__DIR__ . '/../email/templates/otp_email.php');
            $message = str_replace('{{otp}}', $otp, $message);
            sendEmail($email, $subject, $message, 'otp');

            file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] OTP sent to $email for password reset\n", FILE_APPEND);
            echo json_encode(['status' => 'success', 'message' => 'OTP sent to your email']);
            exit;
        }

        if ($otp && !$newPassword) {
            // Verify OTP
            $stmt = $pdo->prepare("SELECT * FROM otps WHERE email = ? AND otp = ? AND expires_at > NOW()");
            $stmt->execute([$email, $otp]);
            if (!$stmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP']);
                exit;
            }

            echo json_encode(['status' => 'success', 'message' => 'OTP verified, please enter new password']);
            exit;
        }
        

        if ($otp && $newPassword && $confirmPassword) {
            // Verify OTP again
            $stmt = $pdo->prepare("SELECT * FROM otps WHERE email = ? AND otp = ? AND expires_at > NOW()");
            $stmt->execute([$email, $otp]);
            if (!$stmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP']);
                exit;
            }

            if ($newPassword !== $confirmPassword) {
                echo json_encode(['status' => 'error', 'message' => 'Passwords do not match']);
                exit;
            }

            if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword) || !preg_match('/[!@#$%^&*]/', $newPassword)) {
                echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters, include an uppercase letter, a number, and a special character']);
                exit;
            }

            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE $table SET password = ? WHERE email = ?");
            $stmt->execute([$hashedPassword, $email]);

            $stmt = $pdo->prepare("DELETE FROM otps WHERE email = ?");
            $stmt->execute([$email]);

            file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Password reset successful for $email\n", FILE_APPEND);
            echo json_encode(['status' => 'success', 'redirect' => '/auth/login.php']);
            exit;
        }
    

    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/../logs/auth.log', 
            "[" . date('Y-m-d H:i:s') . "] Exception in forgot password: " . $e->getMessage() . "\n", 
            FILE_APPEND);
        echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
    <?php include __DIR__ . '/../include/navbar.php'; ?>
    <main class="container">
        <div class="auth-card">
            <h2 class="auth-title">Forgot Password</h2>
            
            <!-- Initial Email Form -->
            <form id="forgot-password-form" class="auth-form" method="post" action="" onsubmit="return false;">
                <div class="form-group">
                    <input type="email" name="email" class="form-control" placeholder="Enter your email" required aria-label="Email">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Send OTP</button>
            </form>

            <!-- OTP Verification Form -->
            <form id="otp-form" class="auth-form" style="display: none;" onsubmit="return false;">
                <div class="form-group">
                    <input type="text" name="otp" class="form-control" placeholder="Enter OTP" required aria-label="OTP">
                </div>
                <div class="form-group">
                    <input type="password" name="new_password" class="form-control" placeholder="Enter new password" required aria-label="New Password">
                    <small class="form-text text-muted">Password must be at least 8 characters long and include uppercase, number, and special character.</small>
                </div>
                <div class="form-group">
                    <input type="password" name="confirm_password" class="form-control" placeholder="Confirm new password" required aria-label="Confirm Password">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Save Password</button>
            </form>

            <!-- OTP Popup -->
            <div id="otp-popup" class="popup" style="display: none;">
                <div class="popup-content">
                    <i class="fas fa-times popup-close" onclick="hidePopup('otp-popup')" aria-label="Close"></i>
                    <h3 class="popup-title">Reset Password</h3>
                    <p class="popup-description">Please enter the OTP sent to your email and set your new password.</p>
                    
                    <form id="reset-password-form" class="auth-form" onsubmit="return false;">
                        <div class="form-group">
                            <input type="text" id="otp-input" class="form-control" placeholder="Enter OTP" required>
                        </div>
                        <div class="form-group">
                            <input type="password" id="new-password" class="form-control" placeholder="Enter New Password" required>
                            <small class="form-text text-muted">Password must be at least 8 characters long and include uppercase, number, and special character.</small>
                        </div>
                        <div class="form-group">
                            <input type="password" id="confirm-password" class="form-control" placeholder="Confirm New Password" required>
                        </div>
                        <div class="button-group">
                            <button type="submit" class="btn btn-primary" onclick="submitPasswordReset()">Submit</button>
                            <button type="button" class="btn btn-secondary" onclick="hidePopup('otp-popup')">Cancel</button>
                        </div>
                        <div id="resend-timer" class="resend-timer" style="display: none;">
                            <p>Resend OTP in <span id="timer">30</span> seconds</p>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Success Popup -->
            <div id="success-popup" class="popup success-popup" style="display: none;">
                <div class="popup-content">
                    <i class="fas fa-times popup-close" onclick="hidePopup('success-popup')" aria-label="Close"></i>
                    <i class="fas fa-check-circle success-icon"></i>
                    <h3 class="popup-title">Password Updated Successfully!</h3>
                    <p class="popup-description">Your password has been reset successfully. You can now login with your new password.</p>
                    <button class="btn btn-primary btn-block" onclick="window.location.href='/auth/login.php'">Login Now</button>
                </div>
            </div>

            <!-- Error Popup -->
            <div id="error-popup" class="popup error-popup" style="display: none;">
                <div class="popup-content">
                    <i class="fas fa-times popup-close" onclick="hidePopup('error-popup')" aria-label="Close"></i>
                    <div class="error-message"></div>
                </div>
            </div>

            <div class="auth-links">
                <a href="/auth/login.php">Back to Login</a>
            </div>

            <noscript>
                <p class="error-message">JavaScript is disabled. Please enable JavaScript to use the forgot password form.</p>
            </noscript>
        </div>
    </main>

    <?php include __DIR__ . '/../include/footer.php'; ?>
    <div class="popup-overlay" style="display: none;"></div>

    <script src="../assets/js/main.js?v=<?php echo time(); ?>"></script>
    <script>
        // Debug script loading
        console.log('Forgot password page script loaded at:', new Date().toISOString());
        
        // Function to show popup
        function showPopup(popupId) {
            document.getElementById(popupId).style.display = 'block';
            document.querySelector('.popup-overlay').style.display = 'block';
        }

        // Function to hide popup
        function hidePopup(popupId) {
            document.getElementById(popupId).style.display = 'none';
            document.querySelector('.popup-overlay').style.display = 'none';
        }

        // Function to show error message
        function showError(message) {
            const errorPopup = document.getElementById('error-popup');
            const errorMessage = errorPopup.querySelector('.error-message');
            errorMessage.textContent = message;
            showPopup('error-popup');
        }

        // Function to start OTP timer
        function startOTPTimer() {
            const timerElement = document.getElementById('timer');
            const resendTimer = document.getElementById('resend-timer');
            let timeLeft = 30;

            resendTimer.style.display = 'block';
            
            const countdown = setInterval(() => {
                timeLeft--;
                timerElement.textContent = timeLeft;
                
                if (timeLeft <= 0) {
                    clearInterval(countdown);
                    resendTimer.style.display = 'none';
                }
            }, 1000);
        }

        // Hide all popups on page load
        document.addEventListener('DOMContentLoaded', function() {
            const popups = document.querySelectorAll('.popup');
            popups.forEach(popup => popup.style.display = 'none');
            document.querySelector('.popup-overlay').style.display = 'none';
        });

        // Form submission handling
        document.getElementById('forgot-password-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const email = this.querySelector('input[name="email"]').value;
            
            // Add your email validation and API call here
            // On success:
            startOTPTimer();
            showPopup('otp-popup');
        });

        // Password reset form submission
        document.getElementById('reset-password-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const otp = document.getElementById('otp-input').value;
            const newPassword = document.getElementById('new-password').value;
            const confirmPassword = document.getElementById('confirm-password').value;

            // Add your password validation and API call here
            // On success:
            hidePopup('otp-popup');
            showPopup('success-popup');
            // On error:
            // showError('Your error message here');
        });

        // Close popups when clicking overlay
        document.querySelector('.popup-overlay').addEventListener('click', function() {
            const popups = document.querySelectorAll('.popup');
            popups.forEach(popup => popup.style.display = 'none');
            this.style.display = 'none';
        });

        // Fetch and validate main.js
        fetch('../assets/js/main.js?v=<?php echo time(); ?>')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to load main.js: ' + response.status);
                }
                console.log('main.js fetched successfully');
            })
            .catch(error => {
                console.error('Error loading main.js:', error);
                document.body.insertAdjacentHTML('beforeend', 
                    '<p class="error-message">Error: JavaScript not loaded (' + error.message + 
                    '). Please check browser settings or server path.</p>'
                );
            });

        if (typeof Auth === 'undefined') {
            console.error('Auth module not loaded. Check main.js path or browser settings.');
            document.body.insertAdjacentHTML('beforeend', 
                '<p class="error-message">Error: JavaScript not loaded. ' +
                'Please enable JavaScript or check browser settings.</p>'
            );
        } else {
            console.log('Auth module available');
        }
    </script>
</body>
</html>