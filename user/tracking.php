<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../middleware/csrf.php';

startApplicationSession();

// Debug logging
error_log("Session data on user tracking: " . print_r($_SESSION, true));
error_log("JWT token present: " . (isset($_SESSION['jwt']) ? 'yes' : 'no'));

if (!isset($_SESSION['user_id'])) {
    error_log("No user_id in session, redirecting to login");
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

$userId = $_SESSION['user_id'];
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Modified query to only show products with email_alert OR push_alert enabled
// This prevents favorites from automatically showing in tracking
$stmt = $pdo->prepare("
    SELECT p.asin, p.name, p.current_price, p.lowest_price, p.highest_price, p.image_path, p.website_url, p.affiliate_link, up.is_favorite, up.email_alert, up.push_alert
    FROM user_products up
    JOIN products p ON up.product_asin = p.asin
    WHERE up.user_id = ? AND (up.email_alert = 1 OR up.push_alert = 1)
    ORDER BY p.name ASC
    LIMIT ? OFFSET ?
");
$stmt->execute([$userId, $perPage, $offset]);
$tracking = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Updated total count query
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE user_id = ? AND (email_alert = 1 OR push_alert = 1)");
$totalStmt->execute([$userId]);
$total = $totalStmt->fetchColumn();
$totalPages = ceil($total / $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tracking - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/user.css">
    <style>
        /* Additional styles for tracking page */
        .product-name-cell {
            max-width: 300px;
            position: relative;
        }
        
        .product-name-truncated {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.4;
            max-height: 2.8em; /* 2 lines * 1.4 line-height */
            word-wrap: break-word;
        }
        
        .price-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .current-price {
            font-weight: 600;
            color: #2A3AFF;
        }
        
        .price-comparison {
            font-size: 12px;
            color: #666;
        }
        
        .price-drop {
            color: #00cc00;
        }
        
        .price-rise {
            color: #ff6b6b;
        }
        
        .alert-buttons {
            display: flex;
            gap: 12px;
            align-items: center;
        }
    </style>
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
            <h1>Price Tracking</h1>
            <p style="color: #666; margin-bottom: 24px;">Products you're actively tracking for price changes. Only products with email or push alerts enabled appear here.</p>
            
            <?php if (empty($tracking)): ?>
                <div class="card" style="text-align: center; padding: 48px;">
                    <i class="fas fa-chart-line" style="font-size: 48px; color: #ccc; margin-bottom: 16px;"></i>
                    <h3 style="color: #666; margin-bottom: 16px;">No Products Being Tracked</h3>
                    <p style="color: #999;">Go to your <a href="/user/favorites.php" style="color: #2A3AFF;">favorites</a> and enable email or push alerts to start tracking price changes.</p>
                </div>
            <?php else: ?>
                <div class="user-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Thumbnail</th>
                                <th class="sortable">Product Name</th>
                                <th class="sortable">Price Info</th>
                                <th>Email Alert</th>
                                <th>Push Alert</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tracking as $product): ?>
                                <?php
                                    $currentPrice = $product['current_price'];
                                    $lowestPrice = $product['lowest_price'];
                                    $highestPrice = $product['highest_price'];
                                    $priceChange = '';
                                    $priceChangeClass = '';
                                    
                                    if ($currentPrice < $lowestPrice) {
                                        $priceChange = 'New Low!';
                                        $priceChangeClass = 'price-drop';
                                    } elseif ($currentPrice == $lowestPrice) {
                                        $priceChange = 'Lowest Price';
                                        $priceChangeClass = 'price-drop';
                                    } elseif ($currentPrice > $highestPrice * 0.9) {
                                        $priceChange = 'Near High';
                                        $priceChangeClass = 'price-rise';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <img src="<?php echo htmlspecialchars($product['image_path']); ?>" 
                                             alt="<?php echo htmlspecialchars($product['name']); ?>">
                                    </td>
                                    <td class="product-name-cell">
                                        <div class="product-name-truncated">
                                            <a href="<?php echo htmlspecialchars($product['website_url']); ?>" 
                                               target="_blank" 
                                               title="<?php echo htmlspecialchars($product['name']); ?>">
                                                <?php echo htmlspecialchars($product['name']); ?>
                                            </a>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="price-info">
                                            <div class="current-price">
                                                ₹<?php echo number_format($currentPrice, 0, '.', ','); ?>
                                            </div>
                                            <div class="price-comparison">
                                                <span>Low: ₹<?php echo number_format($lowestPrice, 0, '.', ','); ?></span> | 
                                                <span>High: ₹<?php echo number_format($highestPrice, 0, '.', ','); ?></span>
                                            </div>
                                            <?php if ($priceChange): ?>
                                                <div class="price-comparison <?php echo $priceChangeClass; ?>">
                                                    <?php echo $priceChange; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="alert-buttons">
                                            <div class="toggle <?php echo $product['email_alert'] ? 'on' : ''; ?>" 
                                                 data-product-id="<?php echo htmlspecialchars($product['asin']); ?>" 
                                                 data-type="email"
                                                 title="Toggle email alerts">
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="alert-buttons">
                                            <div class="toggle <?php echo $product['push_alert'] ? 'on' : ''; ?>" 
                                                 data-product-id="<?php echo htmlspecialchars($product['asin']); ?>" 
                                                 data-type="push"
                                                 title="Toggle push alerts">
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="pagination" style="text-align: center; margin-top: 24px;">
                    <?php if ($page > 1): ?>
                        <a href="/user/tracking.php?page=<?php echo $page - 1; ?>" class="btn btn-secondary">Prev</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="/user/tracking.php?page=<?php echo $i; ?>" 
                           class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="/user/tracking.php?page=<?php echo $page + 1; ?>" class="btn btn-secondary">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include '../include/footer.php'; ?>
    
    <div id="favorite-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('favorite-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="delete-product-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('delete-product-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="permission-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('permission-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div class="popup-overlay" style="display: none;"></div>
    <script src="/assets/js/user.js"></script>
</body>
</html>