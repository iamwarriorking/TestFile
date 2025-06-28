<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/globals.php';
require_once __DIR__ . '/../email/send.php';
require_once __DIR__ . '/../middleware/csrf.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/auth_check.php';

// Session start
startApplicationSession();

// Log form rendering
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Login form rendered, Session ID: " . session_id() . "\n", FILE_APPEND);
}

// Handle POST requests for login processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Set JSON response headers
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    // Prevent any output before JSON response
    ob_clean();
    
    try {
        // Get input data
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data']);
            exit;
        }
        
        $identifier = trim($data['identifier'] ?? '');
        $password = trim($data['password'] ?? '');
        $otp = $data['otp'] ?? null;

        // Validate required fields
        if (!$identifier || !$password) {
            echo json_encode(['status' => 'error', 'message' => 'Please enter username/email and password']);
            exit;
        }

        // Check admin account
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? OR email = ?");
        $stmt->execute([$identifier, $identifier]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check user account
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verify account exists
        if (!$admin && !$user) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid username/email']);
            exit;
        }

        $account = $admin ?: $user;
        $isAdmin = (bool)$admin;

        // Verify password
        if (!password_verify($password, $account['password'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid password']);
            exit;
        }

        // If no OTP provided, generate and send OTP
        if (!$otp) {
            // Generate 6-digit OTP
            $otp = sprintf("%06d", random_int(0, 999999));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            // Store OTP in database
            $stmt = $pdo->prepare("INSERT INTO otps (email, otp, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE otp = VALUES(otp), expires_at = VALUES(expires_at)");
            $stmt->execute([$account['email'], $otp, $expiresAt]);

            // Send OTP email
            $subject = "Your Login Verification Code";
            $message = file_get_contents(__DIR__ . '/../email/templates/otp_email.php');
            $message = str_replace('{{otp}}', $otp, $message);
            sendEmail($account['email'], $subject, $message, 'otp');

            // Log OTP generation
            file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] OTP sent to {$account['email']}\n", FILE_APPEND);
            
            echo json_encode(['status' => 'success', 'message' => 'OTP sent to your email', 'requires_otp' => true]);
            exit;
        }

        // Verify OTP
        $stmt = $pdo->prepare("SELECT * FROM otps WHERE email = ? AND otp = ? AND expires_at > NOW()");
        $stmt->execute([$account['email'], $otp]);
        $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$otpRecord) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP']);
            exit;
        }
        
        $stmt = $pdo->prepare("DELETE FROM otps WHERE email = ?");
        $stmt->execute([$account['email']]);

        // Set session variables
        // After successful OTP verification
        if ($isAdmin) {
            $_SESSION['admin_id'] = $account['id'];
            $_SESSION['is_admin'] = true;
        } else {
            $_SESSION['user_id'] = $account['id'];
            $_SESSION['is_admin'] = false;
        }

        $_SESSION['username'] = $account['username'];
        $_SESSION['email'] = $account['email'];
        $_SESSION['user_type'] = $isAdmin ? 'admin' : 'user';
        $_SESSION['authenticated'] = true;

        // Generate JWT token
        $jwtPayload = [
            'user_id' => $account['id'],
            'email' => $account['email'],
            'username' => $account['username'],
            'is_admin' => $isAdmin,
            'exp' => time() + $securityConfig['jwt']['timeout'],
            'iat' => time()
        ];

        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode($jwtPayload);

        $base64Header = base64_encode($header);
        $base64Payload = base64_encode($payload);

        $signature = hash_hmac('sha256', 
            $base64Header . "." . $base64Payload, 
            $securityConfig['jwt']['secret'], 
            true
        );
        $base64Signature = base64_encode($signature);

        $jwt = $base64Header . "." . $base64Payload . "." . $base64Signature;
        $_SESSION['jwt'] = $jwt;

        // Log successful JWT generation
        error_log("[" . date('Y-m-d H:i:s') . "] JWT token generated and stored in session");

        session_write_close();

        // Send response
        echo json_encode([
            'status' => 'success',
            'redirect' => $isAdmin ? '/admin/dashboard.php' : '/user/dashboard.php',
            'message' => 'Login successful',
            'user_type' => $isAdmin ? 'admin' : 'user',
            'is_admin' => $isAdmin
        ]);
        
        exit;
        
    } catch (Exception $e) {
        // After successful login
        file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Login successful - Session data: " . print_r($_SESSION, true) . "\n", FILE_APPEND);
        file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Login error: {$e->getMessage()}\n", FILE_APPEND);
        echo json_encode(['status' => 'error', 'message' => 'An error occurred. Please try again.']);
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
    <title>Login - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
    <?php include __DIR__ . '/../include/navbar.php'; ?>
    <main class="container">
        <div class="auth-card">
            <h2>Login</h2>
            <form id="login-form" method="post" action="" onsubmit="return false;">
                <input type="text" name="identifier" placeholder="Enter username or email" required aria-label="Username or Email">
                <input type="password" name="password" placeholder="Enter password" required aria-label="Password">
                <button type="submit" class="btn btn-primary">Login</button>
                <div class="auth-links">
                    <a href="/auth/signup.php">Sign Up</a>
                    <a href="/auth/forgot-password.php">Forgot Password?</a>
                </div>
            </form>
            <noscript>
                <p style="color: red;">JavaScript is disabled. Please enable JavaScript to use the login form.</p>
            </noscript>
        </div>
    </main>
    <?php include __DIR__ . '/../include/footer.php'; ?>
    
    <!-- OTP Popup -->
    <div id="otp-popup" class="popup" style="display: none;">
    <i class="fas fa-times popup-close" aria-label="Close OTP Popup" onclick="Popup.hide('otp-popup')"></i>
    <div class="popup-content">
        <form id="otp-verification-form" onsubmit="return false;">
            <h3>Enter OTP</h3>
            <p>OTP sent to your email</p>
            <input type="text" id="otp-input" name="otp" placeholder="Enter OTP" required aria-label="OTP">
            <input type="hidden" id="otp-identifier" name="identifier">
            <input type="hidden" id="otp-password" name="password">
            <div class="button-group">
                <button type="button" class="btn btn-primary" onclick="Auth.verifyOtp()">Verify</button>
                <button type="button" class="btn btn-secondary" onclick="Popup.hide('otp-popup')">Cancel</button>
            </div>
            <div class="otp-resend">
                <p id="resend-timer">Resend OTP in <span id="timer">30</span>s</p>
                <a href="#" id="resend-otp" onclick="Auth.resendOtp()">Resend OTP</a>
            </div>
        </form>
    </div>
</div>

    <!-- Error Popup -->
    <div id="error-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" aria-label="Close Error Popup"></i>
        <div class="popup-content"></div>
    </div>
    
    <div class="popup-overlay" style="display: none;"></div>
    
    <script src="../assets/js/main.js?v=<?php echo time(); ?>"></script>
    <script>
        // Debug script loading
        console.log('Login page script loaded at:', new Date().toISOString());
        
        // Check if main.js loaded properly
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
                    '<p style="color: red; text-align: center;">Error: JavaScript not loaded (' + error.message + '). Please check browser settings.</p>'
                );
            });
            
        // Verify Auth module is available
        if (typeof Auth === 'undefined') {
            console.error('Auth module not loaded. Check main.js path or browser settings.');
            document.body.insertAdjacentHTML('beforeend', 
                '<p style="color: red; text-align: center;">Error: JavaScript not loaded. Please enable JavaScript or check browser settings.</p>'
            );
        } else {
            console.log('Auth module available');
        }
    </script>
</body>
</html>