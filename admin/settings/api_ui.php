<?php
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/globals.php';
require_once '../../middleware/csrf.php';

startApplicationSession();

// Load config files correctly
$amazonConfig = require_once '../../config/amazon.php';
$flipkartConfig = require_once '../../config/flipkart.php';
$marketplacesConfig = require_once '../../config/marketplaces.php';

// Debug logging
error_log("Session data on admin api settings: " . print_r($_SESSION, true));
error_log("JWT token present: " . (isset($_SESSION['jwt']) ? 'yes' : 'no'));

if (!isset($_SESSION['admin_id'])) {
    error_log("No admin_id in session, redirecting to login");
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $amazon = $input['amazon'] ?? [];
    $flipkart = $input['flipkart'] ?? [];
    $marketplaces = $input['marketplaces'] ?? [];

    // Update Amazon config - Keep all existing fields and add new ones
    $amazonConfig = [
        'access_key' => $amazon['access_key'] ?? $amazonConfig['access_key'],
        'secret_key' => $amazon['secret_key'] ?? $amazonConfig['secret_key'],
        'associate_tag' => $amazon['associate_tag'] ?? $amazonConfig['associate_tag'],
        'api_status' => $amazon['api_status'] ?? $amazonConfig['api_status'],
    ];
    
    // Format the config file properly
    $configContent = "<?php\nreturn [\n";
    foreach ($amazonConfig as $key => $value) {
        if (is_string($value)) {
            $configContent .= "    '$key' => '$value',\n";
        } elseif (is_numeric($value)) {
            $configContent .= "    '$key' => $value,\n";
        } else {
            $configContent .= "    '$key' => " . var_export($value, true) . ",\n";
        }
    }
    $configContent .= "];\n?>";
    
    file_put_contents('../../config/amazon.php', $configContent);

    // Update Flipkart config
    $flipkartConfig = [
        'affiliate_id' => $flipkart['affiliate_id'] ?? $flipkartConfig['affiliate_id'],
        'token' => $flipkart['token'] ?? $flipkartConfig['token'],
        'api_status' => $flipkart['api_status'] ?? $flipkartConfig['api_status']
    ];
    file_put_contents('../../config/flipkart.php', "<?php\nreturn " . var_export($flipkartConfig, true) . ";\n?>");

    // Update Marketplaces config
    $marketplacesConfig = [
        'amazon' => $marketplaces['amazon'] ?? $marketplacesConfig['amazon'],
        'flipkart' => $marketplaces['flipkart'] ?? $marketplacesConfig['flipkart']
    ];
    file_put_contents('../../config/marketplaces.php', "<?php\nreturn " . var_export($marketplacesConfig, true) . ";\n?>");

    file_put_contents('../../logs/admin.log', "[" . date('Y-m-d H:i:s') . "] API & UI settings updated by admin ID {$_SESSION['admin_id']}\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => 'Settings updated']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API & UI Settings - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include '../../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <div class="settings-submenu">
                <a href="/admin/settings/api_ui.php" class="active">API & UI</a>
                <a href="/admin/settings/category.php">Category</a>
                <a href="/admin/settings/telegram.php">Telegram</a>
                <a href="/admin/settings/social_security.php">Social & Security</a>
                <a href="/admin/settings/mail.php">Mail</a>
            </div>
            <h1>API & UI Settings</h1>
            <div class="card">
                <div style="margin-bottom: 30px;">
        <h2>Marketplaces UI</h2>
        <form id="marketplaces-form" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div>
                <label for="marketplace_amazon">Amazon UI</label>
                <select name="amazon" style="width: 100%;">
                    <option value="active" <?php echo $marketplacesConfig['amazon'] === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $marketplacesConfig['amazon'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div>
                <label for="marketplace_flipkart">Flipkart UI</label>
                <select name="flipkart" style="width: 100%;">
                    <option value="active" <?php echo $marketplacesConfig['flipkart'] === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $marketplacesConfig['flipkart'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div style="grid-column: span 2;">
                <button type="submit" class="btn btn-primary">Save Marketplaces</button>
            </div>
        </form>
    </div>

    <!-- Amazon Section -->
    <div style="margin-bottom: 30px;">
        <h2>Amazon API</h2>
        <form id="amazon-form" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div>
                <label for="amazon_access_key">Access Key</label>
                <input type="text" name="access_key" value="<?php echo htmlspecialchars($amazonConfig['access_key']); ?>" required>
            </div>
            <div>
                <label for="amazon_secret_key">Secret Key</label>
                <input type="text" name="secret_key" value="<?php echo htmlspecialchars($amazonConfig['secret_key']); ?>" required>
            </div>
            <div>
                <label for="amazon_associate_tag">Associate Tag</label>
                <input type="text" name="associate_tag" value="<?php echo htmlspecialchars($amazonConfig['associate_tag']); ?>" required>
            </div>
            <div>
                <label for="amazon_api_status">API Status</label>
                <select name="api_status" style="width: 100%;">
                    <option value="active" <?php echo $amazonConfig['api_status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $amazonConfig['api_status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div style="grid-column: span 2;">
                <button type="submit" class="btn btn-primary">Save Amazon</button>
            </div>
        </form>
    </div>

    <!-- Flipkart Section -->
    <div>
        <h2>Flipkart API</h2>
        <form id="flipkart-form" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div>
                <label for="flipkart_affiliate_id">Affiliate ID</label>
                <input type="text" name="affiliate_id" value="<?php echo htmlspecialchars($flipkartConfig['affiliate_id']); ?>" required>
            </div>
            <div>
                <label for="flipkart_token">Token</label>
                <input type="text" name="token" value="<?php echo htmlspecialchars($flipkartConfig['token']); ?>" required>
            </div>
            <div>
                <label for="flipkart_api_status">API Status</label>
                <select name="api_status" style="width: 100%;">
                    <option value="active" <?php echo $flipkartConfig['api_status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $flipkartConfig['api_status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div style="grid-column: span 2;">
                <button type="submit" class="btn btn-primary">Save Flipkart</button>
            </div>
        </form>
    </div>
</div>
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
        async function saveSettings(formId, section) {
            const form = document.getElementById(formId);
            const formData = new FormData(form);
            const data = { [section]: Object.fromEntries(formData) };

            const response = await fetch('/admin/settings/api_ui.php', {
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

        document.getElementById('amazon-form').addEventListener('submit', (e) => {
            e.preventDefault();
            saveSettings('amazon-form', 'amazon');
        });

        document.getElementById('flipkart-form').addEventListener('submit', (e) => {
            e.preventDefault();
            saveSettings('flipkart-form', 'flipkart');
        });

        document.getElementById('marketplaces-form').addEventListener('submit', (e) => {
            e.preventDefault();
            saveSettings('marketplaces-form', 'marketplaces');
        });
    </script>
</body>
</html>