<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/globals.php';
require_once '../middleware/csrf.php';
require_once '../middleware/auth.php';

startApplicationSession();
requireUserAuth();

// Debug logging
error_log("Session data on user dashboard: " . print_r($_SESSION, true));
error_log("JWT token present: " . (isset($_SESSION['jwt']) ? 'yes' : 'no'));

if (!isset($_SESSION['user_id'])) {
    error_log("No user_id in session, redirecting to login");
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

$userId = $_SESSION['user_id'];


// Dashboard stats - FIXED tracking count to only include products with alerts enabled
$stats = [
    'favorites' => $pdo->query("SELECT COUNT(*) FROM user_products WHERE user_id = $userId AND is_favorite = 1")->fetchColumn(),
    'tracking' => $pdo->query("SELECT COUNT(*) FROM user_products WHERE user_id = $userId AND (email_alert = 1 OR push_alert = 1)")->fetchColumn(),
    'email_alerts' => $pdo->query("SELECT COUNT(*) FROM user_products WHERE user_id = $userId AND email_alert = 1")->fetchColumn(),
    'push_alerts' => $pdo->query("SELECT COUNT(*) FROM user_products WHERE user_id = $userId AND push_alert = 1")->fetchColumn()
];

// Recent activity
$stmt = $pdo->prepare("
    SELECT p.name, p.current_price, ub.interaction_type, ub.created_at 
    FROM user_behavior ub 
    JOIN products p ON ub.asin = p.asin 
    WHERE ub.user_id = ? 
    ORDER BY ub.created_at DESC 
    LIMIT 5
");
$stmt->execute([$userId]);
$recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/user.css">
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <div class="user-container">
        <?php include '../include/user_sidebar.php'; ?>
        <div id="permission-popup" class="popup" style="display: none;">
            <i class="fas fa-times popup-close" aria-label="Close Permission Popup" onclick="hidePopup('permission-popup')"></i>
            <div class="popup-content">
            </div>
        </div>
        <div class="user-content">
            <h1>Welcome Back!</h1>
            <div class="user-dashboard-grid">
                <div class="user-dashboard-card">
                    <div class="user-dashboard-card-icon"><i class="fas fa-heart"></i></div>
                    <div class="user-dashboard-card-content">
                        <div class="user-dashboard-card-title">Favorites</div>
                        <div class="user-dashboard-card-data"><?php echo $stats['favorites']; ?> Products</div>
                    </div>
                </div>
                <div class="user-dashboard-card">
                    <div class="user-dashboard-card-icon"><i class="fas fa-eye"></i></div>
                    <div class="user-dashboard-card-content">
                        <div class="user-dashboard-card-title">Tracking</div>
                        <div class="user-dashboard-card-data"><?php echo $stats['tracking']; ?> Products</div>
                    </div>
                </div>
                <div class="user-dashboard-card">
                    <div class="user-dashboard-card-icon"><i class="fas fa-envelope"></i></div>
                    <div class="user-dashboard-card-content">
                        <div class="user-dashboard-card-title">Email Alerts</div>
                        <div class="user-dashboard-card-data"><?php echo $stats['email_alerts']; ?> Active</div>
                    </div>
                </div>
                <div class="user-dashboard-card">
                    <div class="user-dashboard-card-icon"><i class="fas fa-bell"></i></div>
                    <div class="user-dashboard-card-content">
                        <div class="user-dashboard-card-title">Push Alerts</div>
                        <div class="user-dashboard-card-data"><?php echo $stats['push_alerts']; ?> Active</div>
                    </div>
                </div>
            </div>
            <h2>Recent Activity</h2>
            <div class="user-table">
                <table>
                    <thead>
                        <tr>
                            <th class="sortable">Product</th>
                            <th class="sortable">Price</th>
                            <th class="sortable">Action</th>
                            <th class="sortable">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentActivity as $activity): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($activity['name']); ?></td>
                                <td>₹<?php echo number_format($activity['current_price'], 0, '.', ','); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $activity['interaction_type']))); ?></td>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($activity['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php include '../include/footer.php'; ?>
    <script src="/assets/js/user.js"></script>
</body>
</html>