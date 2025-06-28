<?php
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/globals.php';
require_once '../../middleware/csrf.php';

startApplicationSession();

$socialConfig = require_once '../../config/social.php';
$fontawesomeConfig = require_once '../../config/fontawesome.php';

// Debug logging
error_log("Session data on admin social settings: " . print_r($_SESSION, true));
error_log("JWT token present: " . (isset($_SESSION['jwt']) ? 'yes' : 'no'));

if (!isset($_SESSION['admin_id'])) {
    error_log("No admin_id in session, redirecting to login");
    header("Location: " . (defined('LOGIN_REDIRECT') ? LOGIN_REDIRECT : '/admin/login.php'));
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if it's JSON data or form data
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'application/json') !== false) {
        // Handle JSON data (original implementation)
        $input = json_decode(file_get_contents('php://input'), true);
        $social = $input['social'] ?? [];
        $security = $input['security'] ?? [];

        // Update Social config
        $socialConfig = [
            'instagram' => $social['instagram'] ?? $socialConfig['instagram'],
            'twitter' => $social['twitter'] ?? $socialConfig['twitter'],
            'telegram' => $social['telegram'] ?? $socialConfig['telegram'],
            'facebook' => $social['facebook'] ?? $socialConfig['facebook'],
        ];
        file_put_contents('../../config/social.php', "<?php\nreturn " . var_export($socialConfig, true) . ";\n?>");

        // Update FontAwesome config
        $fontawesomeConfig = [
            'kit_id' => $security['fontawesome_kit_id'] ?? $fontawesomeConfig['kit_id']
        ];
        file_put_contents('../../config/fontawesome.php', "<?php\nreturn " . var_export($fontawesomeConfig, true) . ";\n?>");

        // Update Security config (JWT only)
        $securityConfig['jwt']['secret'] = $security['jwt_secret'] ?? $securityConfig['jwt']['secret'];
        file_put_contents('../../config/security.php', "<?php\nreturn " . var_export($securityConfig, true) . ";\n?>");

        file_put_contents('../../logs/admin.log', "[" . date('Y-m-d H:i:s') . "] Social & Security settings updated by admin ID {$_SESSION['admin_id']}\n", FILE_APPEND);
        echo json_encode(['status' => 'success', 'message' => 'Settings updated']);
        exit;
    } else {
        // Handle form data (new implementation with CSRF)
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
            exit;
        }

        // Handle Social Media settings
        if (isset($_POST['form_type']) && $_POST['form_type'] === 'social') {
            $socialConfig = [
                'instagram' => $_POST['instagram'] ?? '',
                'twitter' => $_POST['twitter'] ?? '',
                'telegram' => $_POST['telegram'] ?? '',
                'facebook' => $_POST['facebook'] ?? ''
            ];

            // Save Social config
            file_put_contents('../../config/social.php', "<?php\nreturn " . var_export($socialConfig, true) . ";\n");
            
            // Log the update
            $logMessage = "[" . date('Y-m-d H:i:s') . "] Social Media settings updated by admin ID {$_SESSION['admin_id']}\n";
            file_put_contents('../../logs/admin.log', $logMessage, FILE_APPEND);
            
            echo json_encode(['status' => 'success', 'message' => 'Social Media settings updated successfully']);
            exit;
        }

        // Handle Security settings
        if (isset($_POST['form_type']) && $_POST['form_type'] === 'security') {
            // Update FontAwesome config
            if (!empty($_POST['fontawesome_kit_id'])) {
                $fontawesomeConfig = [
                    'kit_id' => $_POST['fontawesome_kit_id']
                ];
                file_put_contents('../../config/fontawesome.php', "<?php\nreturn " . var_export($fontawesomeConfig, true) . ";\n");
            }

            // Update Security config (JWT only)
            if (!empty($_POST['jwt_secret'])) {
                $securityConfig['jwt']['secret'] = $_POST['jwt_secret'];
                file_put_contents('../../config/security.php', "<?php\nreturn " . var_export($securityConfig, true) . ";\n");
            }

            // Log the update
            $logMessage = "[" . date('Y-m-d H:i:s') . "] Security & FontAwesome settings updated by admin ID {$_SESSION['admin_id']}\n";
            file_put_contents('../../logs/admin.log', $logMessage, FILE_APPEND);
            
            echo json_encode(['status' => 'success', 'message' => 'Security settings updated successfully']);
            exit;
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Social & Security Settings - AmezPrice Admin</title>
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
                <a href="/admin/settings/social_security.php" class="active">Social & Security</a>
                <a href="/admin/settings/mail.php">Mail</a>
            </div>
            
            <h1>Social & Security Settings</h1>
            
            <div class="alert alert-success" id="success-alert"></div>
            <div class="alert alert-error" id="error-alert"></div>
            
            <div class="settings-container">
                <!-- Social Media Settings -->
                <div class="settings-card">
                    <h2><i class="fab fa-instagram"></i> Social Media Settings</h2>
                    <form id="social-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="form_type" value="social">
                        
                        <div class="form-group">
                            <label for="instagram"><i class="fab fa-instagram"></i> Instagram URL</label>
                            <input type="url" id="instagram" name="instagram" value="<?php echo htmlspecialchars($socialConfig['instagram']); ?>" placeholder="https://instagram.com/yourprofile">
                        </div>
                        
                        <div class="form-group">
                            <label for="twitter"><i class="fab fa-twitter"></i> Twitter URL</label>
                            <input type="url" id="twitter" name="twitter" value="<?php echo htmlspecialchars($socialConfig['twitter']); ?>" placeholder="https://twitter.com/yourprofile">
                        </div>
                        
                        <div class="form-group">
                            <label for="telegram"><i class="fab fa-telegram"></i> Telegram URL</label>
                            <input type="url" id="telegram" name="telegram" value="<?php echo htmlspecialchars($socialConfig['telegram']); ?>" placeholder="https://t.me/yourchannel">
                        </div>
                        
                        <div class="form-group">
                            <label for="facebook"><i class="fab fa-facebook"></i> Facebook URL</label>
                            <input type="url" id="facebook" name="facebook" value="<?php echo htmlspecialchars($socialConfig['facebook']); ?>" placeholder="https://facebook.com/yourpage">
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Social Media Settings
                        </button>
                    </form>
                </div>

                <!-- Security Settings -->
                <div class="settings-card">
                    <h2><i class="fas fa-shield-alt"></i> Security & FontAwesome Settings</h2>
                    <form id="security-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="form_type" value="security">
                        
                        <div class="form-group">
                            <label for="jwt_secret"><i class="fas fa-key"></i> JWT Secret Key</label>
                            <input type="text" id="jwt_secret" name="jwt_secret" value="<?php echo htmlspecialchars($securityConfig['jwt']['secret']); ?>" required placeholder="Enter JWT secret key">
                            <small>Used for secure token generation</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="fontawesome_kit_id"><i class="fab fa-font-awesome"></i> FontAwesome Kit ID</label>
                            <input type="text" id="fontawesome_kit_id" name="fontawesome_kit_id" value="<?php echo htmlspecialchars($fontawesomeConfig['kit_id']); ?>" required placeholder="Enter FontAwesome Kit ID">
                            <small>
                                Get your Kit ID from <a href="https://fontawesome.com/kits" target="_blank">FontAwesome Kits</a>
                            </small>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Security Settings
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php include '../../include/footer.php'; ?>
    
    <!-- Original popups for backward compatibility -->
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
</body>
</html>