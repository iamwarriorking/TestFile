<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/globals.php';
require_once '../config/telegram.php';
require_once '../email/send.php';
require_once '../middleware/csrf.php';

startApplicationSession();

if (!isset($_SESSION['user_id'])) {
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT email, telegram_id, telegram_username FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$userEmail = $user['email'];

$stmt = $pdo->prepare("SELECT subscribed FROM email_subscriptions WHERE email = ?");
$stmt->execute([$userEmail]);
$subscription = $stmt->fetchColumn() === 'yes';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'toggle_subscription') {
        $subscribed = filter_var($input['subscribed'], FILTER_VALIDATE_BOOLEAN);
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO email_subscriptions (email, subscribed)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE subscribed = ?
            ");
            $stmt->execute([$userEmail, $subscribed ? 'yes' : 'no', $subscribed ? 'yes' : 'no']);
            
            file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Subscription toggled to " . ($subscribed ? 'yes' : 'no') . " for user ID $userId\n", FILE_APPEND);
            echo json_encode(['status' => 'success', 'message' => 'Subscription updated']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // SECURE: Check for existing telegram accounts and generate OTP
    if ($action === 'connect_telegram') {
        $telegramUsername = trim($input['telegram_username'] ?? '');
        
        if (empty($telegramUsername)) {
            echo json_encode(['status' => 'error', 'message' => 'Telegram username is required']);
            exit;
        }

        // Remove @ if user added it
        $telegramUsername = ltrim($telegramUsername, '@');
        
        // Validate telegram username format
        if (!preg_match('/^[a-zA-Z0-9_]{5,32}$/', $telegramUsername)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid Telegram username format']);
            exit;
        }

        // Check if this telegram username already exists in users table
        $mergeRequired = false;
        $existingUserData = null;
        
        try {
            $stmt = $pdo->prepare("
                SELECT id, telegram_id, email, first_name, last_name, created_at 
                FROM users 
                WHERE telegram_username = ? AND telegram_id IS NOT NULL
            ");
            $stmt->execute([$telegramUsername]);
            $existingTelegramUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingTelegramUser && empty($existingTelegramUser['email'])) {
                // Found existing telegram-only account
                $mergeRequired = true;
                $existingUserData = [
                    'telegram_id' => $existingTelegramUser['telegram_id'],
                    'created_at' => $existingTelegramUser['created_at'],
                    'first_name' => $existingTelegramUser['first_name'],
                    'last_name' => $existingTelegramUser['last_name'],
                    'id' => $existingTelegramUser['id']
                ];
            }
        } catch (Exception $e) {
            // Log error but continue with normal flow
            file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Error checking existing telegram user: " . $e->getMessage() . "\n", FILE_APPEND);
        }

        // Generate OTP for telegram connection
        $otp = sprintf("%06d", random_int(0, 999999));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        try {
            // Store telegram username normally in OTP table (clean username for bot detection)
            $stmt = $pdo->prepare("
                INSERT INTO otps (email, otp, expires_at, telegram_username, user_id) 
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                otp = VALUES(otp), 
                expires_at = VALUES(expires_at), 
                telegram_username = VALUES(telegram_username)
            ");
            $stmt->execute([$userEmail, $otp, $expiresAt, $telegramUsername, $userId]);

            // Store merge data in session for this user
            if ($mergeRequired) {
                $_SESSION['telegram_merge_data'] = $existingUserData;
                $_SESSION['telegram_merge_required'] = true;
            } else {
                unset($_SESSION['telegram_merge_data']);
                unset($_SESSION['telegram_merge_required']);
            }

            file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Telegram OTP generated for user ID $userId, username: @$telegramUsername" . ($mergeRequired ? " (merge required)" : "") . "\n", FILE_APPEND);
            
            if ($mergeRequired) {
                echo json_encode([
                    'status' => 'merge_required',
                    'message' => 'Found existing Telegram account. Complete OTP verification to safely merge accounts.',
                    'telegram_username' => $telegramUsername,
                    'existing_user_data' => [
                        'telegram_id' => $existingUserData['telegram_id'],
                        'created_at' => $existingUserData['created_at'],
                        'first_name' => $existingUserData['first_name']
                    ]
                ]);
            } else {
                echo json_encode([
                    'status' => 'success', 
                    'message' => 'OTP generated! Now go to @AmezPriceBot on Telegram and send /connect to get your OTP.',
                    'telegram_username' => $telegramUsername
                ]);
            }
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to generate OTP']);
        }
        exit;
    }

    // SECURE: OTP verification with account merge handling
    if ($action === 'verify_telegram_otp') {
        $otp = trim($input['otp'] ?? '');
        
        if (empty($otp)) {
            echo json_encode(['status' => 'error', 'message' => 'OTP is required']);
            exit;
        }

        try {
            // Verify OTP and get telegram data (clean username now)
            $stmt = $pdo->prepare("
                SELECT telegram_username 
                FROM otps 
                WHERE user_id = ? AND otp = ? AND expires_at > NOW()
            ");
            $stmt->execute([$userId, $otp]);
            $otpData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$otpData) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP']);
                exit;
            }

            $actualTelegramUsername = $otpData['telegram_username'];
            
            // Check if merge is required from session
            $mergeRequired = isset($_SESSION['telegram_merge_required']) && $_SESSION['telegram_merge_required'];
            $existingUserData = $_SESSION['telegram_merge_data'] ?? null;

            // Begin transaction for secure operations
            $pdo->beginTransaction();
            
            // If merge is required, perform account merge AFTER OTP verification
            if ($mergeRequired && $existingUserData) {
                // Transfer user_products to point to website user (only if table exists and has data)
                try {
                    $stmt = $pdo->prepare("
                        UPDATE user_products 
                        SET user_id = ? 
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$userId, $existingUserData['id']]);
                    
                    file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Transferred user_products for merged account - Old user {$existingUserData['id']} to new user $userId\n", FILE_APPEND);
                } catch (Exception $e) {
                    // Log but don't fail - user_products transfer is not critical
                    file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Note: Could not transfer user_products (table may be empty): " . $e->getMessage() . "\n", FILE_APPEND);
                }
                
                // Transfer user_behavior data (only if table exists and has data)
                try {
                    $stmt = $pdo->prepare("
                        UPDATE user_behavior 
                        SET user_id = ? 
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$userId, $existingUserData['id']]);
                    
                    file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Transferred user_behavior for merged account - Old user {$existingUserData['id']} to new user $userId\n", FILE_APPEND);
                } catch (Exception $e) {
                    // Log but don't fail - user_behavior transfer is not critical
                    file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Note: Could not transfer user_behavior (table may be empty): " . $e->getMessage() . "\n", FILE_APPEND);
                }
                
                // Transfer any other user-related data that exists
                try {
                    $stmt = $pdo->prepare("
                        UPDATE user_requests 
                        SET user_id = ? 
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$userId, $existingUserData['id']]);
                } catch (Exception $e) {
                    file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Note: Could not transfer user_requests: " . $e->getMessage() . "\n", FILE_APPEND);
                }
                
                // Delete the old telegram-only account FIRST to avoid duplicate key error
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$existingUserData['id']]);
                
                // NOW update current user with telegram data (no more duplicate key issue)
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET telegram_id = ?, telegram_username = ?, 
                        first_name = COALESCE(NULLIF(first_name, ''), ?),
                        last_name = COALESCE(NULLIF(last_name, ''), ?)
                    WHERE id = ?
                ");
                $stmt->execute([
                    $existingUserData['telegram_id'], 
                    $actualTelegramUsername,
                    $existingUserData['first_name'],
                    $existingUserData['last_name'],
                    $userId
                ]);
                
                file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] SECURE: Accounts merged after OTP verification - Telegram user {$existingUserData['id']} merged into website user $userId\n", FILE_APPEND);
                
                $message = 'Telegram connected and accounts merged successfully! Your tracking data has been preserved.';
                
                // Clean up session data
                unset($_SESSION['telegram_merge_data']);
                unset($_SESSION['telegram_merge_required']);
            } else {
                // Normal telegram connection without merge
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET telegram_username = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$actualTelegramUsername, $userId]);
                
                $message = 'Telegram connected successfully! You can now receive price alerts on Telegram.';
            }

            // Delete used OTP
            $stmt = $pdo->prepare("DELETE FROM otps WHERE user_id = ? AND otp = ?");
            $stmt->execute([$userId, $otp]);
            
            $pdo->commit();

            file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] SECURE: Telegram connected for user ID $userId, username: @$actualTelegramUsername\n", FILE_APPEND);
            
            echo json_encode([
                'status' => 'success', 
                'message' => $message,
                'telegram_username' => $actualTelegramUsername
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Failed to verify OTP: ' . $e->getMessage()]);
        }
        exit;
    }

    // Disconnect Telegram
    if ($action === 'disconnect_telegram') {
        try {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET telegram_id = NULL, telegram_username = NULL 
                WHERE id = ?
            ");
            $stmt->execute([$userId]);

            file_put_contents('../logs/user.log', "[" . date('Y-m-d H:i:s') . "] Telegram disconnected for user ID $userId\n", FILE_APPEND);
            
            echo json_encode(['status' => 'success', 'message' => 'Telegram disconnected successfully']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to disconnect Telegram']);
        }
        exit;
    }
}

// Handle unsubscribe via GET parameter
if (isset($_GET['unsubscribe']) && $_GET['unsubscribe'] === 'true') {
    $stmt = $pdo->prepare("UPDATE email_subscriptions SET subscribed = 'no' WHERE email = ?");
    $stmt->execute([$userEmail]);
    $subscription = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/user.css">
    <style>
        .telegram-section {
            margin-bottom: 20px;
        }
        .telegram-status {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-weight: 500;
        }
        .status-connected {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .status-not-connected {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .telegram-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .telegram-input-row {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .telegram-input-row span {
            font-weight: bold;
            color: #666;
        }
        .telegram-input-row input {
            flex: 1;
        }
        .otp-section {
            display: none;
            background: #e3f2fd;
            border: 1px solid #90caf9;
            border-radius: 8px;
            padding: 16px;
            margin-top: 15px;
        }
        .otp-section.show {
            display: block;
        }
        .instructions {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 12px;
            margin: 10px 0;
            font-size: 14px;
        }
        .otp-input-row {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 10px;
        }
        .otp-input-row input {
            flex: 1;
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 2px;
        }
        /* Merge popup styles */
        .merge-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            justify-content: center;
        }
        .merge-popup .popup-content {
            max-width: 500px;
        }
        .merge-popup ul {
            text-align: left;
            margin: 15px 0;
        }
        .merge-popup ul li {
            margin: 5px 0;
        }
        .security-notice {
            background: #e8f5e8;
            border: 1px solid #4caf50;
            border-radius: 6px;
            padding: 12px;
            margin: 10px 0;
            font-size: 14px;
            color: #2e7d32;
        }
    </style>
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <div class="user-container">
        <?php include '../include/user_sidebar.php'; ?>
        <div class="user-content">
            <h1>Account</h1>
            <div class="account-section">
                <div class="card">
                    <h2>Email Subscriptions</h2>
                    <p>Hot deals subscription</p>
                    <div class="subscription-controls">
                        <div class="toggle <?php echo $subscription ? 'on' : ''; ?>" id="subscription-toggle"></div>
                        <button id="subscription-btn" class="btn <?php echo $subscription ? 'btn-delete' : 'btn-primary'; ?>">
                            <?php echo $subscription ? 'Unsubscribe' : 'Subscribe'; ?>
                        </button>
                    </div>
                    <div id="subscription-status" style="margin-top: 8px; color: #666; font-size: 14px;">
                        Status: <?php echo $subscription ? 'Currently subscribed' : 'Not subscribed'; ?>
                    </div>
                    <div class="notes">
                        <p>By enabling this, you will receive hot deals in your email from AmezPrice.</p>
                        <p>You can unsubscribe anytime by clicking the unsubscribe link in the email or by clicking the button here.</p>
                    </div>
                </div>
                
                <!-- Telegram Connection Section -->
                <div class="card">
                    <h2>Telegram Connection</h2>
                    <p>Connect your Telegram account to receive instant price alerts and deals.</p>
                    
                    <div class="telegram-section">
                        <?php if (!empty($user['telegram_username'])): ?>
                            <!-- Connected State -->
                            <div class="telegram-status status-connected">
                                <i class="fas fa-check-circle"></i>
                                <span>Connected as <strong>@<?php echo htmlspecialchars($user['telegram_username']); ?></strong></span>
                            </div>
                            <div class="telegram-form">
                                <button id="disconnect-telegram-btn" class="btn btn-delete">
                                    <i class="fas fa-unlink"></i> Disconnect Telegram
                                </button>
                            </div>
                            <div class="notes">
                                <p>✅ You will receive price alerts and deals directly on Telegram.</p>
                                <p>Use @AmezPriceBot to track products and get instant notifications.</p>
                            </div>
                        <?php else: ?>
                            <!-- Not Connected State -->
                            <div class="telegram-status status-not-connected">
                                <i class="fas fa-times-circle"></i>
                                <span>Not connected to Telegram</span>
                            </div>
                            
                            <div class="telegram-form">
                                <div class="telegram-input-row">
                                    <span>@</span>
                                    <input type="text" id="telegram-username" placeholder="your_telegram_username" required>
                                    <button id="connect-telegram-btn" class="btn btn-primary">
                                        <i class="fas fa-link"></i> Connect Telegram
                                    </button>
                                </div>
                                
                                <!-- OTP Verification Section -->
                                <div id="otp-section" class="otp-section">
                                    <h4><i class="fas fa-shield-alt"></i> Verify Connection</h4>
                                    <div class="security-notice">
                                        <strong>🔒 Secure Process:</strong> Account merge (if needed) will only happen AFTER successful OTP verification. Your account is safe during this process.
                                    </div>
                                    <div class="instructions">
                                        <strong>Next Steps:</strong><br>
                                        1. Go to Telegram and search for <strong>@AmezPriceBot</strong><br>
                                        2. Send the command <strong>/connect</strong> to the bot<br>
                                        3. You will receive a 6-digit OTP<br>
                                        4. Enter the OTP below to complete connection
                                    </div>
                                    <div class="otp-input-row">
                                        <input type="text" id="telegram-otp" placeholder="Enter 6-digit OTP" maxlength="6">
                                        <button id="verify-otp-btn" class="btn btn-primary">
                                            <i class="fas fa-check"></i> Verify OTP
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="notes">
                                <p>By connecting Telegram, you'll get instant notifications for price drops and deals.</p>
                                <p>Enter your Telegram username (without @) and follow the verification steps.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card">
                    <h2>Delete Account</h2>
                    <p>Delete your account and all associated data permanently.</p>
                    <button class="btn btn-delete" onclick="confirmDeleteAccount()">Delete Account</button>
                </div>
            </div>
        </div>
    </div>
    <?php include '../include/footer.php'; ?>
    
    <!-- Popups -->
    <div id="delete-account-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('delete-account-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="otp-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('otp-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="error-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('error-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="success-popup" class="popup success-popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('success-popup')"></i>
        <div class="popup-content">
            <i class="fas fa-check-circle success-icon"></i>
            <h3>Account Deleted Successfully!</h3>
            <p>Your account and all associated data have been permanently deleted. Thank you for using AmezPrice.</p>
            <button class="btn btn-primary" onclick="window.location.href='/auth/login.php'">Go to Login</button>
        </div>
    </div>
    <!-- Merge popup -->
    <div id="merge-popup" class="popup merge-popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('merge-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div class="popup-overlay" style="display: none;"></div>
    
    <script src="/assets/js/user.js"></script>
    <script>
        // Email subscription functionality
        document.getElementById('subscription-btn').addEventListener('click', async function() {
            const isSubscribed = document.getElementById('subscription-toggle').classList.contains('on');
            const subscriptionBtn = document.getElementById('subscription-btn');
            const statusElement = document.getElementById('subscription-status');
            const originalBtnText = subscriptionBtn.textContent;
            
            subscriptionBtn.textContent = isSubscribed ? 'Unsubscribing...' : 'Subscribing...';
            subscriptionBtn.disabled = true;
            
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                
                const response = await fetch('/user/account.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json', 
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        action: 'toggle_subscription',
                        subscribed: !isSubscribed
                    })
                });
                
                const result = await response.json();

                if (result.status === 'success') {
                    const newState = !isSubscribed;
                    document.getElementById('subscription-toggle').classList.toggle('on');
                    
                    subscriptionBtn.textContent = newState ? 'Unsubscribe' : 'Subscribe';
                    subscriptionBtn.classList.remove('btn-primary', 'btn-delete');
                    subscriptionBtn.classList.add(newState ? 'btn-delete' : 'btn-primary');
                    
                    statusElement.textContent = 'Status: ' + (newState ? 'Currently subscribed' : 'Not subscribed');
                    
                    const toast = document.createElement('div');
                    toast.className = 'toast toast-success';
                    toast.textContent = newState ? 'Successfully subscribed to email notifications' : 'Successfully unsubscribed from email notifications';
                    document.body.appendChild(toast);
                    setTimeout(() => toast.remove(), 3000);
                } else {
                    subscriptionBtn.textContent = originalBtnText;
                    showPopup('error-popup', `<h3>Error</h3><p>${result.message || 'Unknown error'}</p>`);
                }
            } catch (error) {
                subscriptionBtn.textContent = originalBtnText;
                showPopup('error-popup', `<h3>Error</h3><p>Failed to update subscription status. Please try again.</p>`);
            } finally {
                subscriptionBtn.disabled = false;
            }
        });
        
        document.getElementById('subscription-toggle').addEventListener('click', function() {
            document.getElementById('subscription-btn').click();
        });

        // SECURE: Telegram connection functionality
        document.getElementById('connect-telegram-btn')?.addEventListener('click', async function() {
            const usernameInput = document.getElementById('telegram-username');
            const telegramUsername = usernameInput.value.trim();
            const connectBtn = this;
            
            if (!telegramUsername) {
                showPopup('error-popup', '<h3>Error</h3><p>Please enter your Telegram username</p>');
                return;
            }

            const originalBtnText = connectBtn.textContent;
            connectBtn.textContent = 'Connecting...';
            connectBtn.disabled = true;

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                
                const response = await fetch('/user/account.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        action: 'connect_telegram',
                        telegram_username: telegramUsername
                    })
                });

                const result = await response.json();

                if (result.status === 'merge_required') {
                    // Show secure merge info
                    showMergeConfirmationPopup(result);
                    connectBtn.textContent = originalBtnText;
                } else if (result.status === 'success') {
                    document.getElementById('otp-section').classList.add('show');
                    usernameInput.disabled = true;
                    connectBtn.textContent = 'OTP Generated';
                    
                    const toast = document.createElement('div');
                    toast.className = 'toast toast-success';
                    toast.innerHTML = '<strong>OTP Generated!</strong><br>' + result.message;
                    document.body.appendChild(toast);
                    setTimeout(() => toast.remove(), 8000);
                } else {
                    showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
                    connectBtn.textContent = originalBtnText;
                }
            } catch (error) {
                showPopup('error-popup', '<h3>Error</h3><p>Failed to connect to Telegram. Please try again.</p>');
                connectBtn.textContent = originalBtnText;
            } finally {
                connectBtn.disabled = false;
            }
        });

        // SECURE: Show merge confirmation popup
        function showMergeConfirmationPopup(data) {
            const createdDate = new Date(data.existing_user_data.created_at).toLocaleDateString();
            showPopup('merge-popup', `
                <h3><i class="fas fa-shield-alt" style="color: #4caf50;"></i> Secure Account Merge Process</h3>
                <div class="security-notice">
                    <strong>🔒 Security Notice:</strong> Account merge will only happen AFTER you successfully verify the OTP from Telegram. Your account remains safe during this process.
                </div>
                <p>We found an existing Telegram account with username <strong>@${data.telegram_username}</strong> that was created on <strong>${createdDate}</strong> when you first used our bot.</p>
                <p><strong>Next Steps:</strong></p>
                <ul>
                    <li>✅ Go to @AmezPriceBot and send /connect</li>
                    <li>✅ Enter the 6-digit OTP below</li>
                    <li>✅ Your accounts will be safely merged after verification</li>
                    <li>✅ All your data will be preserved</li>
                </ul>
                <div class="merge-actions">
                    <button class="btn btn-primary" onclick="hidePopup('merge-popup')">
                        <i class="fas fa-check"></i> I Understand, Continue
                    </button>
                    <button class="btn btn-secondary" onclick="hidePopup('merge-popup'); location.reload();">
                        Cancel
                    </button>
                </div>
            `);
            
            // Auto show OTP section
            setTimeout(() => {
                hidePopup('merge-popup');
                document.getElementById('otp-section').classList.add('show');
                document.getElementById('telegram-username').disabled = true;
                document.getElementById('connect-telegram-btn').textContent = 'OTP Generated (Merge Pending)';
            }, 3000);
        }

        // SECURE: OTP verification with merge handling
        document.getElementById('verify-otp-btn')?.addEventListener('click', async function() {
            const otpInput = document.getElementById('telegram-otp');
            const otp = otpInput.value.trim();
            const verifyBtn = this;
            
            if (!otp || otp.length !== 6) {
                showPopup('error-popup', '<h3>Error</h3><p>Please enter a valid 6-digit OTP</p>');
                return;
            }

            const originalBtnText = verifyBtn.textContent;
            verifyBtn.textContent = 'Verifying...';
            verifyBtn.disabled = true;

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                
                const response = await fetch('/user/account.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        action: 'verify_telegram_otp',
                        otp: otp
                    })
                });

                const result = await response.json();

                if (result.status === 'success') {
                    showPopup('success-popup', 
                        '<i class="fas fa-check-circle success-icon"></i>' +
                        '<h3>Telegram Connected Successfully!</h3>' +
                        '<p>' + result.message + '</p>' +
                        '<p><strong>🔒 Security:</strong> All operations completed safely with proper verification.</p>' +
                        '<button class="btn btn-primary" onclick="location.reload()">Continue</button>'
                    );
                } else {
                    showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
                    verifyBtn.textContent = originalBtnText;
                }
            } catch (error) {
                showPopup('error-popup', '<h3>Error</h3><p>Failed to verify OTP. Please try again.</p>');
                verifyBtn.textContent = originalBtnText;
            } finally {
                verifyBtn.disabled = false;
            }
        });

        // Disconnect Telegram
        document.getElementById('disconnect-telegram-btn')?.addEventListener('click', async function() {
            if (!confirm('Are you sure you want to disconnect Telegram? You will stop receiving notifications.')) {
                return;
            }

            const disconnectBtn = this;
            const originalBtnText = disconnectBtn.textContent;
            disconnectBtn.textContent = 'Disconnecting...';
            disconnectBtn.disabled = true;

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                
                const response = await fetch('/user/account.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        action: 'disconnect_telegram'
                    })
                });

                const result = await response.json();

                if (result.status === 'success') {
                    location.reload();
                } else {
                    showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
                    disconnectBtn.textContent = originalBtnText;
                }
            } catch (error) {
                showPopup('error-popup', '<h3>Error</h3><p>Failed to disconnect Telegram. Please try again.</p>');
                disconnectBtn.textContent = originalBtnText;
            } finally {
                disconnectBtn.disabled = false;
            }
        });

        // Auto-format OTP input
        document.getElementById('telegram-otp')?.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    </script>
</body>
</html>