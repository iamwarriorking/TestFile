<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../middleware/csrf.php';
require_once '../middleware/auth.php';
require_once '../config/session.php';

startApplicationSession();
requireAdminAuth();

// Debug logging
file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Dashboard access attempt, Session ID: " . session_id() . "\n", FILE_APPEND);
file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Dashboard session data: " . print_r($_SESSION, true) . "\n", FILE_APPEND);

// Check admin authentication
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Dashboard access denied - No admin session or not authenticated\n", FILE_APPEND);
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Dashboard access granted for admin: " . $_SESSION['admin_id'] . "\n", FILE_APPEND);

// Dashboard stats
$stats = [
    'users' => $pdo->query("SELECT COUNT(DISTINCT id) FROM users WHERE telegram_id IS NOT NULL OR email IS NOT NULL")->fetchColumn(),
    'website_users' => $pdo->query("SELECT COUNT(*) FROM users WHERE email IS NOT NULL")->fetchColumn(),
    'telegram_users' => $pdo->query("SELECT COUNT(*) FROM users WHERE telegram_id IS NOT NULL")->fetchColumn(),
    'amazon_users' => $pdo->query("SELECT COUNT(DISTINCT up.user_id) FROM user_products up JOIN products p ON up.product_asin = p.asin WHERE p.merchant = 'amazon'")->fetchColumn(),
    'flipkart_users' => $pdo->query("SELECT COUNT(DISTINCT up.user_id) FROM user_products up JOIN products p ON up.product_asin = p.asin WHERE p.merchant = 'flipkart'")->fetchColumn(),
    'hotdeals_users' => $pdo->query("SELECT COUNT(DISTINCT telegram_id) FROM hotdealsbot WHERE is_active = TRUE")->fetchColumn(),
    'tracking_products' => $pdo->query("SELECT COUNT(DISTINCT product_asin) FROM user_products")->fetchColumn(),
    'website_products' => $pdo->query("SELECT COUNT(DISTINCT product_asin) FROM user_products WHERE user_id IN (SELECT id FROM users WHERE email IS NOT NULL)")->fetchColumn(),
    'telegram_products' => $pdo->query("SELECT COUNT(DISTINCT product_asin) FROM user_products WHERE user_id IN (SELECT id FROM users WHERE telegram_id IS NOT NULL)")->fetchColumn(),
    'amazon_products' => $pdo->query("SELECT COUNT(*) FROM products WHERE merchant = 'amazon'")->fetchColumn(),
    'flipkart_products' => $pdo->query("SELECT COUNT(*) FROM products WHERE merchant = 'flipkart'")->fetchColumn(),
    'non_tracking_products' => $pdo->query("SELECT COUNT(*) FROM products WHERE asin NOT IN (SELECT product_asin FROM user_products)")->fetchColumn(),
    'oos_products' => $pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity = 0")->fetchColumn(),
    'hot_deals_categories' => $pdo->query("SELECT COUNT(DISTINCT category) FROM hotdealsbot WHERE is_active = TRUE")->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <h1>Dashboard</h1>
            <div class="dashboard-grid">
                <div class="dashboard-card">
                    <div class="dashboard-card-icon"><i class="fas fa-users"></i></div>
                    <div class="dashboard-card-content">
                        <div class="dashboard-card-title">Users</div>
                        <div class="dashboard-card-data"><?php echo $stats['users']; ?> <span class="dashboard-card-subtitle">(Website + Telegram)</span></div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-icon"><i class="fas fa-globe"></i></div>
                    <div class="dashboard-card-content">
                        <div class="dashboard-card-title">Website Users</div>
                        <div class="dashboard-card-data"><?php echo $stats['website_users']; ?> <span class="dashboard-card-subtitle">(Website)</span></div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-icon"><i class="fab fa-telegram"></i></div>
                    <div class="dashboard-card-content">
                        <div class="dashboard-card-title">Telegram Users</div>
                        <div class="dashboard-card-data"><?php echo $stats['telegram_users']; ?> <span class="dashboard-card-subtitle">(Telegram)</span></div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-icon"><i class="fab fa-amazon"></i></div>
                    <div class="dashboard-card-content">
                        <div class="dashboard-card-title">Amazon Users</div>
                        <div class="dashboard-card-data"><?php echo $stats['amazon_users']; ?> <span class="dashboard-card-subtitle">(Amazon)</span></div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-icon"><i class="fas fa-shopping-cart"></i></div>
                    <div class="dashboard-card-content">
                        <div class="dashboard-card-title">Flipkart Users</div>
                        <div class="dashboard-card-data"><?php echo $stats['flipkart_users']; ?> <span class="dashboard-card-subtitle">(Flipkart)</span></div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-icon"><i class="fas fa-fire"></i></div>
                    <div class="dashboard-card-content">
                        <div class="dashboard-card-title">Telegram HotDeals Users</div>
                        <div class="dashboard-card-data"><?php echo $stats['hotdeals_users']; ?> <span class="dashboard-card-subtitle">(HotDeals)</span></div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-icon"><i class="fas fa-box"></i></div>
                    <div class="dashboard-card-content">
                        <div class="dashboard-card-title">Tracking Products</div>
                        <div class="dashboard-card-data"><?php echo $stats['tracking_products']; ?> <span class="dashboard-card-subtitle">(Website + Telegram)</span></div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-icon"><i class="fas fa-globe"></i></div>
                    <div class="dashboard-card-content">
                        <div class="dashboard-card-title">Website Products</div>
                        <div class="dashboard-card-data"><?php echo $stats['website_products']; ?> <span class="dashboard-card-subtitle">(Website Tracking)</span></div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-icon"><i class="fab fa-telegram"></i></div>
                    <div class="dashboard-card-content">
                        <div class="dashboard-card-title">Telegram Products</div>
                        <div class="dashboard-card-data"><?php echo $stats['telegram_products']; ?> <span class="dashboard-card-subtitle">(Telegram Tracking)</span></div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-icon"><i class="fab fa-amazon"></i></div>
                    <div class="dashboard-card-content">
                        <div class="dashboard-card-title">Amazon Products</div>
                        <div class="dashboard-card-data"><?php echo $stats['amazon_products']; ?> <span class="dashboard-card-subtitle">(Amazon)</span></div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-icon"><i class="fas fa-shopping-cart"></i></div>
                    <div class="dashboard-card-content">
                        <div class="dashboard-card-title">Flipkart Products</div>
                        <div class="dashboard-card-data"><?php echo $stats['flipkart_products']; ?> <span class="dashboard-card-subtitle">(Flipkart)</span></div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-icon"><i class="fas fa-box-open"></i></div>
                    <div class="dashboard-card-content">
                        <div class="dashboard-card-title">Non Tracking Products</div>
                        <div class="dashboard-card-data"><?php echo $stats['non_tracking_products']; ?> <span class="dashboard-card-subtitle">(Not Tracked)</span></div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-icon"><i class="fas fa-ban"></i></div>
                    <div class="dashboard-card-content">
                        <div class="dashboard-card-title">OOS Products</div>
                        <div class="dashboard-card-data"><?php echo $stats['oos_products']; ?> <span class="dashboard-card-subtitle">(Out of Stock)</span></div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-icon"><i class="fas fa-list"></i></div>
                    <div class="dashboard-card-content">
                        <div class="dashboard-card-title">Hot Deals Categories</div>
                        <div class="dashboard-card-data"><?php echo $stats['hot_deals_categories']; ?> <span class="dashboard-card-subtitle">(HotDeals)</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include '../include/footer.php'; ?>
    <script src="/assets/js/admin.js"></script>
</body>
</html>