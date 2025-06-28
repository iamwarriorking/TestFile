<?php
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/telegram.php';
require_once '../../config/globals.php';
require_once '../../middleware/csrf.php';

startApplicationSession();

if (!isset($_SESSION['admin_id'])) {
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $telegram = $input['telegram'] ?? [];

    // Update Telegram config
    $newConfig = [
        'amezpricebot_token' => $telegram['amezpricebot_token'] ?? $telegramConfig['amezpricebot_token'],
        'hotdealsbot_token' => $telegram['hotdealsbot_token'] ?? $telegramConfig['hotdealsbot_token'],
        'channels' => [
            'amezprice' => $telegram['channels']['amezprice'] ?? $telegramConfig['channels']['amezprice'],
            'hotdeals' => $telegram['channels']['hotdeals'] ?? $telegramConfig['channels']['hotdeals'],
            'updates' => $telegram['channels']['updates'] ?? $telegramConfig['channels']['updates']
        ],
        'buttons' => [
            'amezprice' => array_map(function($btn) {
                return [
                    'text' => $btn['text'] ?? 'Default',
                    'url' => $btn['url'] ?? '',
                    'enabled' => isset($btn['enabled']) ? filter_var($btn['enabled'], FILTER_VALIDATE_BOOLEAN) : true
                ];
            }, $telegram['buttons']['amezprice'] ?? $telegramConfig['buttons']['amezprice']),
            'hotdeals' => array_map(function($btn) {
                return [
                    'text' => $btn['text'] ?? 'Default',
                    'url' => $btn['url'] ?? '',
                    'enabled' => isset($btn['enabled']) ? filter_var($btn['enabled'], FILTER_VALIDATE_BOOLEAN) : true
                ];
            }, $telegram['buttons']['hotdeals'] ?? $telegramConfig['buttons']['hotdeals'])
        ],
        'api_key' => $telegram['api_key'] ?? $telegramConfig['api_key']
    ];

    file_put_contents('../../config/telegram.php', "<?php\nreturn " . var_export($newConfig, true) . ";\n?>");
    file_put_contents('../logs/admin.log', "[" . date('Y-m-d H:i:s') . "] Telegram settings updated by admin ID {$_SESSION['admin_id']}\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => 'Telegram settings updated']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telegram Settings - AmezPrice</title>
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
                <a href="/admin/settings/telegram.php" class="active">Telegram</a>
                <a href="/admin/settings/social_security.php">Social & Security</a>
                <a href="/admin/settings/mail.php">Mail</a>
            </div>
            <h1>Telegram Settings</h1>
            <div class="card">
                <form id="telegram-form">
                    <h2>Bot Tokens</h2>
                    <label for="amezpricebot_token">AmezPrice Bot Token</label>
                    <input type="text" name="amezpricebot_token" value="<?php echo htmlspecialchars($telegramConfig['amezpricebot_token']); ?>" readonly required>
                    <label for="hotdealsbot_token">HotDeals Bot Token</label>
                    <input type="text" name="hotdealsbot_token" value="<?php echo htmlspecialchars($telegramConfig['hotdealsbot_token']); ?>" readonly required>
                    
                    <h2>Channels</h2>
                    <label for="channel_amezprice">AmezPrice Channel</label>
                    <input type="text" name="channels[amezprice]" value="<?php echo htmlspecialchars($telegramConfig['channels']['amezprice']); ?>" readonly required>
                    <label for="channel_hotdeals">HotDeals Channel</label>
                    <input type="text" name="channels[hotdeals]" value="<?php echo htmlspecialchars($telegramConfig['channels']['hotdeals']); ?>" readonly required>
                    <label for="channel_updates">Updates Channel</label>
                    <input type="text" name="channels[updates]" value="<?php echo htmlspecialchars($telegramConfig['channels']['updates']); ?>" readonly required>
                    
                    <h2>AmezPrice Bot Buttons</h2>
                    <div id="amezprice-buttons">
                        <?php foreach ($telegramConfig['buttons']['amezprice'] as $index => $btn): ?>
                            <div class="button-row" style="display: flex; gap: 16px; margin-bottom: 16px;">
                                <input type="text" name="buttons[amezprice][<?php echo $index; ?>][text]" value="<?php echo htmlspecialchars($btn['text']); ?>" placeholder="Button Text" required>
                                <input type="text" name="buttons[amezprice][<?php echo $index; ?>][url]" value="<?php echo htmlspecialchars($btn['url']); ?>" placeholder="Button URL" required>
                                <select name="buttons[amezprice][<?php echo $index; ?>][enabled]">
                                    <option value="true" <?php echo $btn['enabled'] ? 'selected' : ''; ?>>Enabled</option>
                                    <option value="false" <?php echo !$btn['enabled'] ? 'selected' : ''; ?>>Disabled</option>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <h2>HotDeals Bot Buttons</h2>
                    <div id="hotdeals-buttons">
                        <?php foreach ($telegramConfig['buttons']['hotdeals'] as $index => $btn): ?>
                            <div class="button-row" style="display: flex; gap: 16px; margin-bottom: 16px;">
                                <input type="text" name="buttons[hotdeals][<?php echo $index; ?>][text]" value="<?php echo htmlspecialchars($btn['text']); ?>" placeholder="Button Text" required>
                                <input type="text" name="buttons[hotdeals][<?php echo $index; ?>][url]" value="<?php echo htmlspecialchars($btn['url']); ?>" placeholder="Button URL" required>
                                <select name="buttons[hotdeals][<?php echo $index; ?>][enabled]">
                                    <option value="true" <?php echo $btn['enabled'] ? 'selected' : ''; ?>>Enabled</option>
                                    <option value="false" <?php echo !$btn['enabled'] ? 'selected' : ''; ?>>Disabled</option>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <h2>API Key</h2>
                    <label for="api_key">API Key</label>
                    <input type="text" name="api_key" value="<?php echo htmlspecialchars($telegramConfig['api_key']); ?>" required>
                    
                    <button type="submit" class="btn btn-primary">Save Telegram Settings</button>
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
        document.getElementById('telegram-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const data = {
                telegram: {
                    amezpricebot_token: formData.get('amezpricebot_token'),
                    hotdealsbot_token: formData.get('hotdealsbot_token'),
                    channels: {
                        amezprice: formData.get('channels[amezprice]'),
                        hotdeals: formData.get('channels[hotdeals]'),
                        updates: formData.get('channels[updates]')
                    },
                    buttons: {
                        amezprice: [
                            {
                                text: formData.get('buttons[amezprice][0][text]'),
                                url: formData.get('buttons[amezprice][0][url]'),
                                enabled: formData.get('buttons[amezprice][0][enabled]') === 'true'
                            },
                            {
                                text: formData.get('buttons[amezprice][1][text]'),
                                url: formData.get('buttons[amezprice][1][url]'),
                                enabled: formData.get('buttons[amezprice][1][enabled]') === 'true'
                            }
                        ],
                        hotdeals: [
                            {
                                text: formData.get('buttons[hotdeals][0][text]'),
                                url: formData.get('buttons[hotdeals][0][url]'),
                                enabled: formData.get('buttons[hotdeals][0][enabled]') === 'true'
                            },
                            {
                                text: formData.get('buttons[hotdeals][1][text]'),
                                url: formData.get('buttons[hotdeals][1][url]'),
                                enabled: formData.get('buttons[hotdeals][1][enabled]') === 'true'
                            }
                        ]
                    },
                    api_key: formData.get('api_key')
                }
            };

            const response = await fetch('/admin/settings/telegram.php', {
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