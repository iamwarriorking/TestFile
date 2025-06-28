<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/marketplaces.php';
require_once __DIR__ . '/../config/session.php';
startApplicationSession();

// Debug logging for session status
error_log("Navbar - Session status - ID: " . session_id() . ", Data: " . print_r($_SESSION, true));

// Check Goldbox availability
$goldboxStmt = $pdo->query("SELECT COUNT(*) as count, MAX(last_updated) as last_updated FROM goldbox_products");
$goldboxData = $goldboxStmt->fetch(PDO::FETCH_ASSOC);
$showGoldbox = $marketplaces['amazon'] === 'active' && $goldboxData['count'] > 0 && strtotime($goldboxData['last_updated']) > strtotime('-24 hours');

// Check Flipbox availability
$flipboxStmt = $pdo->query("SELECT COUNT(*) as count, MAX(last_updated) as last_updated FROM flipbox_products");
$flipboxData = $flipboxStmt->fetch(PDO::FETCH_ASSOC);
$showFlipbox = $marketplaces['flipkart'] === 'active' && $flipboxData['count'] > 0 && strtotime($flipboxData['last_updated']) > strtotime('-24 hours');
?>

<nav class="navbar">
    <!-- Left section - Navigation Buttons -->
    <div class="navbar-left">
        <!-- Today's Deals Button -->
        <a href="/pages/todays-deals.php" class="btn-deals tooltip-btn" aria-label="Today's Deals" >
            <i class="fas fa-fire"></i> Today's Deals
        </a>

    <!-- Center section - Logo -->
    <div class="navbar-center">
        <a href="/" class="navbar-logo">
            <img src="/assets/images/logos/website-logo.png" alt="Website Logo">
        </a>
    </div>

    <!-- Right section - User Buttons -->
    <div class="navbar-right">
        <?php
        $current_page = $_SERVER['REQUEST_URI'] ?? '/';
        $is_logged_in = isset($_SESSION['user_id']) || isset($_SESSION['admin_id']);
        
        // Show logout button only in admin/user sections if logged in
        if ($is_logged_in) {
            $show_logout = (strpos($current_page, '/admin/') === 0 || strpos($current_page, '/user/') === 0);
            if ($show_logout) {
                echo '<a href="/auth/logout.php" class="btn logout-btn" aria-label="Logout">
                        <i class="fas fa-sign-out-alt"></i> Logout
                      </a>';
            }
        }
        
        // Check if we're on dashboard pages
        $is_dashboard_page = (strpos($current_page, '/admin/') !== false || 
                            strpos($current_page, '/user/') !== false);
        
        // Show appropriate button based on login status and page
        if (!$is_dashboard_page) {
            if (isset($_SESSION['admin_id'])) {
                echo '<a href="/admin/dashboard.php" class="btn dashboard-btn" aria-label="Admin Dashboard">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                      </a>';
            } elseif (isset($_SESSION['user_id'])) {
                echo '<a href="/user/dashboard.php" class="btn dashboard-btn" aria-label="Dashboard">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                      </a>';
            } elseif (!$is_logged_in) {
                echo '<a href="/auth/login.php" class="btn login-btn" aria-label="Login">
                        <i class="fas fa-sign-in-alt"></i> Login
                      </a>';
            }
        }
        ?>
    </div>
</nav>