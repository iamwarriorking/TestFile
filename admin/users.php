<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../middleware/csrf.php';

startApplicationSession();

// Debug logging
error_log("Session data on admin users: " . print_r($_SESSION, true));
error_log("JWT token present: " . (isset($_SESSION['jwt']) ? 'yes' : 'no'));

if (!isset($_SESSION['admin_id'])) {
    error_log("No admin_id in session, redirecting to login");
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(10, min((int)$_GET['per_page'], 100)) : 50;
$offset = ($page - 1) * $perPage;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$searchCondition = '';
$searchParams = [];

if (!empty($search)) {
    $searchCondition = "WHERE (first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR email LIKE ? OR telegram_id LIKE ?)";
    $searchTerm = "%{$search}%";
    $searchParams = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];
}

if ($userId) {
    // User details view
    $stmt = $pdo->prepare("SELECT first_name, last_name, username, email, telegram_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header('Location: /admin/users.php');
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT p.asin, p.name, p.current_price, p.rating, up.email_alert, up.push_alert, p.image_path
        FROM user_products up
        JOIN products p ON up.product_asin = p.asin
        WHERE up.user_id = ?
        ORDER BY p.name ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$userId, $perPage, $offset]);
    $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE user_id = ?");
    $totalStmt->execute([$userId]);
    $total = $totalStmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
} else {
    // Users list view with search
    $baseQuery = "SELECT id, first_name, last_name, username, email, telegram_id FROM users";
    $stmt = $pdo->prepare("$baseQuery $searchCondition ORDER BY username ASC LIMIT ? OFFSET ?");
    
    $params = array_merge($searchParams, [$perPage, $offset]);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM users $searchCondition");
    $totalStmt->execute($searchParams);
    $total = $totalStmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <style>
        /* User management page styled design similar to products page */
        .user-name-cell {
            max-width: 200px;
            position: relative;
        }
        
        .user-name-truncated {
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.4;
            max-height: 1.4em;
            word-wrap: break-word;
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .user-email {
            font-weight: 600;
            color: #2A3AFF;
        }
        
        .user-details {
            font-size: 12px;
            color: #666;
        }
        
        .telegram-id {
            color: #00cc00;
            font-weight: 500;
        }
        
        /* Search actions - Similar to products page */
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
        
        .search-actions .btn {
            padding: 8px 16px;
            font-size: 14px;
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
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e8f4f8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: #2A3AFF;
            font-size: 14px;
        }
        
        .status-badge {
            background: #e8f4f8;
            color: #2A3AFF;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .alert-status {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .alert-on {
            background: #d4edda;
            color: #155724;
        }
        
        .alert-off {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <div class="admin-container">
        <?php include '../include/admin_sidebar.php'; ?>
        <div class="admin-content">
            <?php if ($userId): ?>
                <div style="display: flex; align-items: center; margin-bottom: 24px;">
                    <a href="/admin/users.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
                    <h1 style="margin-left: 16px;"><?php echo htmlspecialchars($user['first_name']); ?></h1>
                </div>
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
                        <button class="btn btn-delete" onclick="confirmDeleteUser(<?php echo $userId; ?>, '<?php echo htmlspecialchars($user['email']); ?>')">Delete</button>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <p><strong>Username:</strong> <?php echo htmlspecialchars($user['username'] ?: 'N/A'); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email'] ?: 'N/A'); ?></p>
                    </div>
                    <p><strong>Telegram ID:</strong> <?php echo htmlspecialchars($user['telegram_id'] ?: 'N/A'); ?></p>
                </div>
                <h2>Favorite Products</h2>
                
                <?php if (empty($favorites)): ?>
                    <div class="card" style="text-align: center; padding: 48px;">
                        <i class="fas fa-heart" style="font-size: 48px; color: #ccc; margin-bottom: 16px;"></i>
                        <h3 style="color: #666; margin-bottom: 16px;">No Favorite Products</h3>
                        <p style="color: #999;">This user hasn't added any products to their favorites yet.</p>
                    </div>
                <?php else: ?>
                    <div class="admin-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Thumbnail</th>
                                    <th class="sortable">Name</th>
                                    <th class="sortable">Current Price</th>
                                    <th class="sortable">Rating</th>
                                    <th>Alert Settings</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($favorites as $product): ?>
                                    <tr>
                                        <td><img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>"></td>
                                        <td class="user-name-cell">
                                            <div class="user-name-truncated">
                                                <a href="<?php echo htmlspecialchars("https://amezprice.com/product/amazon/pid={$product['asin']}"); ?>" 
                                                   target="_blank"
                                                   title="<?php echo htmlspecialchars($product['name']); ?>">
                                                    <?php echo htmlspecialchars($product['name']); ?>
                                                </a>
                                            </div>
                                        </td>
                                        <td class="user-email">₹<?php echo number_format($product['current_price'], 0, '.', ','); ?></td>
                                        <td><?php echo htmlspecialchars($product['rating'] ?: 'N/A'); ?></td>
                                        <td>
                                            <div class="alert-status">
                                                <span class="status-badge <?php echo $product['email_alert'] ? 'alert-on' : 'alert-off'; ?>">
                                                    Email: <?php echo $product['email_alert'] ? 'On' : 'Off'; ?>
                                                </span>
                                                <span class="status-badge <?php echo $product['push_alert'] ? 'alert-on' : 'alert-off'; ?>">
                                                    Push: <?php echo $product['push_alert'] ? 'On' : 'Off'; ?>
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                
                <div class="pagination" style="text-align: center; margin-top: 24px;">
                    <?php if ($page > 1): ?>
                        <a href="/admin/users.php?user_id=<?php echo $userId; ?>&page=<?php echo $page - 1; ?>" class="btn btn-secondary">Prev</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="/admin/users.php?user_id=<?php echo $userId; ?>&page=<?php echo $i; ?>" class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="/admin/users.php?user_id=<?php echo $userId; ?>&page=<?php echo $page + 1; ?>" class="btn btn-secondary">Next</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <h1>Users</h1>
                <p style="color: #666; margin-bottom: 24px;">Manage all users in the system. View user details and their favorite products.</p>
                
                <?php if (empty($users)): ?>
                    <div class="card" style="text-align: center; padding: 48px;">
                        <i class="fas fa-users" style="font-size: 48px; color: #ccc; margin-bottom: 16px;"></i>
                        <h3 style="color: #666; margin-bottom: 16px;">No Users Found</h3>
                        <p style="color: #999;"><?php echo !empty($search) ? 'No users match your search criteria.' : 'No users are currently registered in the system.'; ?></p>
                        <?php if (!empty($search)): ?>
                            <a href="/admin/users.php" class="btn btn-secondary" style="margin-top: 16px;">Clear Search</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- Search Actions -->
                    <div class="search-actions" id="admin-search-container">
                        <i class="fas fa-search"></i>
                        <form method="GET" style="display: flex; gap: 8px; align-items: center; width: 100%;">
                            <input type="text" 
                                   name="search" 
                                   id="admin-table-search" 
                                   placeholder="Search users by name, username, email, or telegram ID" 
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   aria-label="Search users">
                            <button type="submit" class="btn btn-primary">Search</button>
                            <?php if (!empty($search)): ?>
                                <a href="/admin/users.php" class="btn btn-secondary">Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>
                    
                    <?php if (!empty($search)): ?>
                        <p style="color: #666; margin-bottom: 16px;">
                            Found <?php echo $total; ?> user<?php echo $total !== 1 ? 's' : ''; ?> for "<?php echo htmlspecialchars($search); ?>"
                        </p>
                    <?php endif; ?>
                    
                    <div class="admin-table">
                        <table>
                            <thead>
                                <tr>
                                    <th class="sortable">Name</th>
                                    <th class="sortable">Username</th>
                                    <th class="sortable">Email</th>
                                    <th class="sortable">Telegram</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr data-user-id="<?php echo htmlspecialchars($user['id']); ?>">
                                        <td class="user-name-cell">
                                            <div class="user-info">
                                                <div class="user-email">
                                                    <a href="/admin/users.php?user_id=<?php echo $user['id']; ?>" title="View user details">
                                                        <?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['last_name'] ?: '')); ?>
                                                    </a>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="user-info">
                                                <div class="user-details">
                                                    <?php echo htmlspecialchars($user['username'] ?: 'N/A'); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="user-info">
                                                <div class="user-email">
                                                    <?php echo htmlspecialchars($user['email'] ?: 'N/A'); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($user['telegram_id']): ?>
                                                <span class="status-badge telegram-id">
                                                    <?php echo htmlspecialchars($user['telegram_id']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="user-details">Not connected</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="pagination" style="text-align: center; margin-top: 24px;">
                        <?php if ($page > 1): ?>
                            <a href="/admin/users.php?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="btn btn-secondary">Prev</a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="/admin/users.php?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                               class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="/admin/users.php?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="btn btn-secondary">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php include '../include/footer.php'; ?>
    <div id="delete-user-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('delete-user-popup')"></i>
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
    <div class="popup-overlay" style="display: none;"></div>
    <script src="/assets/js/admin.js"></script>
</body>
</html>