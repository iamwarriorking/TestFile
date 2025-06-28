<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../middleware/csrf.php';

startApplicationSession();

// Debug logging
error_log("Session data on admin OOS: " . print_r($_SESSION, true));
error_log("JWT token present: " . (isset($_SESSION['jwt']) ? 'yes' : 'no'));

if (!isset($_SESSION['admin_id'])) {
    error_log("No admin_id in session, redirecting to login");
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50; // Changed to match products page
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT p.*, COUNT(up.id) as tracking_users 
    FROM products p 
    LEFT JOIN user_products up ON p.asin = up.product_asin 
    WHERE p.stock_quantity = 0 
    GROUP BY p.asin 
    ORDER BY p.name ASC 
    LIMIT ? OFFSET ?
");
$stmt->execute([$perPage, $offset]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalStmt = $pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity = 0");
$total = $totalStmt->fetchColumn();
$totalPages = ceil($total / $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Out of Stock Products - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <style>
        /* User/favorites page styled design for admin OOS products */
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
            color: #dc3545; /* Red color for out of stock */
        }
        
        .price-comparison {
            font-size: 12px;
            color: #666;
        }
        
        .lowest-price {
            color: #00cc00;
        }
        
        .highest-price {
            color: #ff6b6b;
        }
        
        /* Search actions - Similar to bulk actions in products */
        .search-actions {
            margin-bottom: 16px;
            display: flex;
            gap: 8px;
            align-items: center;
            background: #f8f9fa;
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .search-actions input[type="text"] {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            width: 300px;
            font-size: 14px;
        }
        
        .search-actions input[type="text"]:focus {
            outline: none;
            border-color: #2A3AFF;
            box-shadow: 0 0 0 2px rgba(42, 58, 255, 0.1);
        }
        
        .search-actions i {
            color: #666;
            margin-right: 8px;
        }
        
        /* Admin table styling to match products table exactly */
        .admin-table {
            background: #FFFFFF;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .admin-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .admin-table th, .admin-table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #E5E7EB;
        }
        
        .admin-table th {
            background: #F9FAFB;
            font-weight: 600;
            cursor: pointer;
        }
        
        .admin-table th.sortable:hover {
            background: #E5E7EB;
        }
        
        .admin-table th.asc::after {
            content: ' ↑';
        }
        
        .admin-table th.desc::after {
            content: ' ↓';
        }
        
        .admin-table img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
        }
        
        .tracking-count {
            background: #e8f4f8;
            color: #2A3AFF;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .oos-badge {
            background: #dc3545;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
        }
    </style>
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <h1>Out of Stock Products</h1>
            <p style="color: #666; margin-bottom: 24px;">Manage products that are currently out of stock. Monitor tracking statistics and product details.</p>
            
            <?php if (empty($products)): ?>
                <div class="card" style="text-align: center; padding: 48px;">
                    <i class="fas fa-box-open" style="font-size: 48px; color: #28a745; margin-bottom: 16px;"></i>
                    <h3 style="color: #28a745; margin-bottom: 16px;">All Products In Stock!</h3>
                    <p style="color: #999;">Great news! No products are currently out of stock.</p>
                </div>
            <?php else: ?>
                <!-- Search Actions - Separate from table (like products page) -->
                <div class="search-actions" id="admin-search-container">
                    <i class="fas fa-search"></i>
                    <input type="text" id="admin-table-search" placeholder="Search out of stock products by name or price" aria-label="Search products">
                </div>
                
                <div class="admin-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Thumbnail</th>
                                <th class="sortable">Product Name</th>
                                <th class="sortable">Price Info</th>
                                <th class="sortable">Tracking Users</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr data-product-id="<?php echo htmlspecialchars($product['asin']); ?>">
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
                                            <span class="oos-badge">Out of Stock</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="price-info">
                                            <div class="current-price">
                                                ₹<?php echo number_format($product['current_price'], 0, '.', ','); ?>
                                            </div>
                                            <div class="price-comparison">
                                                <span class="lowest-price">Low: ₹<?php echo number_format($product['lowest_price'], 0, '.', ','); ?></span> | 
                                                <span class="highest-price">High: ₹<?php echo number_format($product['highest_price'], 0, '.', ','); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="tracking-count">
                                            <?php echo $product['tracking_users']; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="pagination" style="text-align: center; margin-top: 24px;">
                    <?php if ($page > 1): ?>
                        <a href="/admin/oos.php?page=<?php echo $page - 1; ?>" class="btn btn-secondary">Prev</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="/admin/oos.php?page=<?php echo $i; ?>" 
                           class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="/admin/oos.php?page=<?php echo $page + 1; ?>" class="btn btn-secondary">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include '../include/footer.php'; ?>
    <script src="/assets/js/admin.js"></script>
</body>
</html>