<?php
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/globals.php';
require_once '../../middleware/csrf.php';

startApplicationSession();

// Fix: Properly load the mail config file
$mailConfig = require_once '../../config/mail.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $mail = $input['mail'] ?? [];

    // Update Mail config
    $mailConfig = [
        'otp' => [
            'email' => $mail['otp']['email'] ?? $mailConfig['otp']['email'],
            'server' => $mail['otp']['server'] ?? $mailConfig['otp']['server'],
            'port' => (int)($mail['otp']['port'] ?? $mailConfig['otp']['port']),
            'username' => $mail['otp']['username'] ?? $mailConfig['otp']['username'],
            'password' => $mail['otp']['password'] ?? $mailConfig['otp']['password']
        ],
        'deals' => [
            'email' => $mail['deals']['email'] ?? $mailConfig['deals']['email'],
            'server' => $mail['deals']['server'] ?? $mailConfig['deals']['server'],
            'port' => (int)($mail['deals']['port'] ?? $mailConfig['deals']['port']),
            'username' => $mail['deals']['username'] ?? $mailConfig['deals']['username'],
            'password' => $mail['deals']['password'] ?? $mailConfig['deals']['password']
        ],
        'alerts' => [
            'email' => $mail['alerts']['email'] ?? $mailConfig['alerts']['email'],
            'server' => $mail['alerts']['server'] ?? $mailConfig['alerts']['server'],
            'port' => (int)($mail['alerts']['port'] ?? $mailConfig['alerts']['port']),
            'username' => $mail['alerts']['username'] ?? $mailConfig['alerts']['username'],
            'password' => $mail['alerts']['password'] ?? $mailConfig['alerts']['password']
        ],
        'offers' => [
            'email' => $mail['offers']['email'] ?? $mailConfig['offers']['email'],
            'server' => $mail['offers']['server'] ?? $mailConfig['offers']['server'],
            'port' => (int)($mail['offers']['port'] ?? $mailConfig['offers']['port']),
            'username' => $mail['offers']['username'] ?? $mailConfig['offers']['username'],
            'password' => $mail['offers']['password'] ?? $mailConfig['offers']['password']
        ],
        'retry_attempts' => $mailConfig['retry_attempts'],
        'retry_delay' => $mailConfig['retry_delay']
    ];

    file_put_contents('../../config/mail.php', "<?php\nreturn " . var_export($mailConfig, true) . ";\n?>");
    file_put_contents('../../logs/admin.log', "[" . date('Y-m-d H:i:s') . "] Mail settings updated by admin ID {$_SESSION['admin_id']}\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => 'Mail settings updated']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mail Settings - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include '../../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <div class="settings-submenu">
                <a href="/admin/settings/api_ui.php">API & UI</a>
                <a href="/admin/settings/category.php">Category</a>
                <a href="/admin/settings/telegram.php">Telegram</a>
                <a href="/admin/settings/social_security.php">Social & Security</a>
                <a href="/admin/settings/mail.php" class="active">Mail</a>
            </div>
            <h1>Mail Settings</h1>
            <div class="card">
                <form id="mail-form">
                    <h2>OTP Email</h2>
                    <label for="otp_email">Email</label>
                    <input type="email" name="otp[email]" value="<?php echo htmlspecialchars($mailConfig['otp']['email']); ?>" required>
                    <label for="otp_server">Server</label>
                    <input type="text" name="otp[server]" value="<?php echo htmlspecialchars($mailConfig['otp']['server']); ?>" required>
                    <label for="otp_port">Port</label>
                    <input type="number" name="otp[port]" value="<?php echo htmlspecialchars($mailConfig['otp']['port']); ?>" required>
                    <label for="otp_username">Username</label>
                    <input type="text" name="otp[username]" value="<?php echo htmlspecialchars($mailConfig['otp']['username']); ?>" required>
                    <label for="otp_password">Password</label>
                    <input type="password" name="otp[password]" value="<?php echo htmlspecialchars($mailConfig['otp']['password']); ?>" required>

                    <h2>Deals Email</h2>
                    <label for="deals_email">Email</label>
                    <input type="email" name="deals[email]" value="<?php echo htmlspecialchars($mailConfig['deals']['email']); ?>" required>
                    <label for="deals_server">Server</label>
                    <input type="text" name="deals[server]" value="<?php echo htmlspecialchars($mailConfig['deals']['server']); ?>" required>
                    <label for="deals_port">Port</label>
                    <input type="number" name="deals[port]" value="<?php echo htmlspecialchars($mailConfig['deals']['port']); ?>" required>
                    <label for="deals_username">Username</label>
                    <input type="text" name="deals[username]" value="<?php echo htmlspecialchars($mailConfig['deals']['username']); ?>" required>
                    <label for="deals_password">Password</label>
                    <input type="password" name="deals[password]" value="<?php echo htmlspecialchars($mailConfig['deals']['password']); ?>" required>

                    <h2>Alerts Email</h2>
                    <label for="alerts_email">Email</label>
                    <input type="email" name="alerts[email]" value="<?php echo htmlspecialchars($mailConfig['alerts']['email']); ?>" required>
                    <label for="alerts_server">Server</label>
                    <input type="text" name="alerts[server]" value="<?php echo htmlspecialchars($mailConfig['alerts']['server']); ?>" required>
                    <label for="alerts_port">Port</label>
                    <input type="number" name="alerts[port]" value="<?php echo htmlspecialchars($mailConfig['alerts']['port']); ?>" required>
                    <label for="alerts_username">Username</label>
                    <input type="text" name="alerts[username]" value="<?php echo htmlspecialchars($mailConfig['alerts']['username']); ?>" required>
                    <label for="alerts_password">Password</label>
                    <input type="password" name="alerts[password]" value="<?php echo htmlspecialchars($mailConfig['alerts']['password']); ?>" required>

                    <h2>Offers Email</h2>
                    <label for="offers_email">Email</label>
                    <input type="email" name="offers[email]" value="<?php echo htmlspecialchars($mailConfig['offers']['email']); ?>" required>
                    <label for="offers_server">Server</label>
                    <input type="text" name="offers[server]" value="<?php echo htmlspecialchars($mailConfig['offers']['server']); ?>" required>
                    <label for="offers_port">Port</label>
                    <input type="number" name="offers[port]" value="<?php echo htmlspecialchars($mailConfig['offers']['port']); ?>" required>
                    <label for="offers_username">Username</label>
                    <input type="text" name="offers[username]" value="<?php echo htmlspecialchars($mailConfig['offers']['username']); ?>" required>
                    <label for="offers_password">Password</label>
                    <input type="password" name="offers[password]" value="<?php echo htmlspecialchars($mailConfig['offers']['password']); ?>" required>

                    <button type="submit" class="btn btn-primary">Save Mail Settings</button>
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
    <script>
        document.getElementById('mail-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const data = {
                mail: {
                    otp: {
                        email: formData.get('otp[email]'),
                        server: formData.get('otp[server]'),
                        port: formData.get('otp[port]'),
                        username: formData.get('otp[username]'),
                        password: formData.get('otp[password]')
                    },
                    deals: {
                        email: formData.get('deals[email]'),
                        server: formData.get('deals[server]'),
                        port: formData.get('deals[port]'),
                        username: formData.get('deals[username]'),
                        password: formData.get('deals[password]')
                    },
                    alerts: {
                        email: formData.get('alerts[email]'),
                        server: formData.get('alerts[server]'),
                        port: formData.get('alerts[port]'),
                        username: formData.get('alerts[username]'),
                        password: formData.get('alerts[password]')
                    },
                    offers: {
                        email: formData.get('offers[email]'),
                        server: formData.get('offers[server]'),
                        port: formData.get('offers[port]'),
                        username: formData.get('offers[username]'),
                        password: formData.get('offers[password]')
                    }
                }
            };

            const response = await fetch('/admin/settings/mail.php', {
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
        });
    </script>
</body>
</html>