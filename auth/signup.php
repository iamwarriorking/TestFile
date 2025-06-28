<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/globals.php';
require_once __DIR__ . '/../email/send.php';
require_once __DIR__ . '/../middleware/csrf.php';
require_once __DIR__ . '/../config/session.php';
startApplicationSession();

// Log form rendering
file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Signup form rendered, Session ID: " . session_id() . "\n", FILE_APPEND);

// Prevent raw JSON display for GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Render form (already below)
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $firstName = trim($input['first_name'] ?? '');
    $lastName = trim($input['last_name'] ?? '');
    $username = trim($input['username'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $otp = $input['otp'] ?? null;

    if (!$firstName || !$lastName || !$username || !$email || !$password) {
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

    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[!@#$%^&*]/', $password)) {
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters, include an uppercase letter, a number, and a special character']);
        exit;
    }

    // Check for existing username or email
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Username or email already exists']);
        exit;
    }

    if (!$otp) {
        // Generate OTP
        $otp = sprintf("%06d", random_int(0, 999999));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        $stmt = $pdo->prepare("INSERT INTO otps (email, otp, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$email, $otp, $expiresAt]);

        $subject = "Your Signup Verification Code";
        $message = file_get_contents(__DIR__ . '/../email/templates/otp_email.php');
        $message = str_replace('{{otp}}', $otp, $message);
        sendEmail($email, $subject, $message, 'otp');

        file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] OTP sent to $email for signup\n", FILE_APPEND);
        echo json_encode(['status' => 'success', 'message' => 'OTP sent to your email']);
        exit;
    }

    // Verify OTP
    $stmt = $pdo->prepare("SELECT * FROM otps WHERE email = ? AND otp = ? AND expires_at > NOW()");
    $stmt->execute([$email, $otp]);
    if (!$stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP']);
        exit;
    }

    try {
        // Begin transaction for data consistency
        $pdo->beginTransaction();

        // Create user
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO users (first_name, last_name, username, email, password)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$firstName, $lastName, $username, $email, $hashedPassword]);

        // Add to email subscriptions - Use INSERT IGNORE or ON DUPLICATE KEY UPDATE
        $stmt = $pdo->prepare("
            INSERT INTO email_subscriptions (email, subscribed) 
            VALUES (?, 'yes') 
            ON DUPLICATE KEY UPDATE subscribed = 'yes'
        ");
        $stmt->execute([$email]);

        // Delete used OTP
        $stmt = $pdo->prepare("DELETE FROM otps WHERE email = ?");
        $stmt->execute([$email]);

        // Commit transaction
        $pdo->commit();

        file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] User created successfully: $email\n", FILE_APPEND);
        echo json_encode(['status' => 'success', 'redirect' => '/auth/login.php']);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $pdo->rollback();
        
        // Log the error
        file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Signup error for $email: " . $e->getMessage() . "\n", FILE_APPEND);
        
        // Return user-friendly error
        echo json_encode(['status' => 'error', 'message' => 'Failed to create account. Please try again.']);
    }
    exit;
}
?>
<!-- Rest of your HTML remains the same -->
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
    <?php include __DIR__ . '/../include/navbar.php'; ?>
    <main class="container">
        <div class="auth-card">
            <h2>Sign Up</h2>
            <form id="signup-form" method="post" action="" onsubmit="return false;">
                <input type="text" name="first_name" placeholder="Enter first name" required aria-label="First Name">
                <input type="text" name="last_name" placeholder="Enter last name" required aria-label="Last Name">
                <input type="text" name="username" placeholder="Enter username" required aria-label="Username">
                <input type="email" name="email" placeholder="Enter email" required aria-label="Email">
                <input type="password" name="password" placeholder="Enter password" required aria-label="Password">
                <button type="submit" class="btn btn-primary">Sign Up</button>
                <div class="auth-links">
                    <a href="/auth/login.php">Already have an account? Login</a>
                </div>
            </form>
            <noscript>
                <p style="color: red;">JavaScript is disabled. Please enable JavaScript to use the signup form.</p>
            </noscript>
        </div>
    </main>
    <?php include __DIR__ . '/../include/footer.php'; ?>
    <div id="otp-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" aria-label="Close OTP Popup"></i>
        <div class="popup-content">
            <h3>Enter OTP</h3>
            <p>OTP sent to your email.</p>
            <input type="text" id="otp-input" placeholder="Enter OTP" aria-label="OTP">
            <button class="btn btn-primary" onclick="Auth.submitOtp()">Submit</button>
            <button class="btn btn-secondary" onclick="Popup.hide('otp-popup')">Cancel</button>
            <p id="resend-timer" style="display: none;">Resend in <span id="timer">30</span> seconds</p>
            <a href="#" id="resend-otp" style="display: none;" onclick="Auth.resendOtp()">Resend OTP</a>
        </div>
    </div>
    <div id="error-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" aria-label="Close Error Popup"></i>
        <div class="popup-content"></div>
    </div>
    <!-- Success Popup -->
    <div id="success-popup" class="popup success-popup" style="display: none;">
        <div class="popup-content">
            <i class="fas fa-times popup-close" onclick="hidePopup('success-popup')" aria-label="Close"></i>
            <i class="fas fa-check-circle success-icon"></i>
            <h3 class="popup-title">Account Created Successfully!</h3>
            <p class="popup-description">Your account has been created successfully. You can now login with your credentials.</p>
            <button class="btn btn-primary btn-block" onclick="window.location.href='/auth/login.php'">Login Now</button>
        </div>
    </div>
    <div class="popup-overlay" style="display: none;"></div>
    <script src="../assets/js/main.js?v=<?php echo time(); ?>"></script>
    <script>
        // Debug script loading
        console.log('Signup page script loaded at:', new Date().toISOString());
        fetch('../assets/js/main.js?v=<?php echo time(); ?>')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to load main.js: ' + response.status);
                }
                console.log('main.js fetched successfully');
            })
            .catch(error => {
                console.error('Error loading main.js:', error);
                document.body.insertAdjacentHTML('beforeend', '<p style="color: red; text-align: center;">Error: JavaScript not loaded (' + error.message + '). Please check browser settings or refresh the page.</p>');
            });
        if (typeof Auth === 'undefined') {
            console.error('Auth module not loaded. Check main.js path or browser settings.');
            document.body.insertAdjacentHTML('beforeend', '<p style="color: red; text-align: center;">Error: JavaScript not loaded. Please enable JavaScript or check browser settings.</p>');
        } else {
            console.log('Auth module available');
        }
    </script>
</body>
</html>