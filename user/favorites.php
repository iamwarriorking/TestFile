<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../middleware/csrf.php';

startApplicationSession();

if (!isset($_SESSION['user_id'])) {
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

$userId = $_SESSION['user_id'];
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get favorites with price history data
$stmt = $pdo->prepare("
    SELECT p.asin, p.name, p.current_price, p.lowest_price, p.highest_price, p.image_path, p.website_url, p.affiliate_link, up.is_favorite, up.email_alert, up.push_alert
    FROM user_products up
    JOIN products p ON up.product_asin = p.asin
    WHERE up.user_id = ? AND up.is_favorite = 1
    ORDER BY p.name ASC
    LIMIT ? OFFSET ?
");
$stmt->execute([$userId, $perPage, $offset]);
$favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE user_id = ? AND is_favorite = 1");
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
    <title>Favorites - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/user.css">
    <style>
        /* Styles for favorites page similar to tracking */
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
        
        .bulk-actions {
            margin-bottom: 16px;
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .bulk-checkbox, #select-all-header {
            cursor: pointer;
        }
        
        .delete-btn {
            color: #ff0000;
            cursor: pointer;
            font-size: 18px;
            transition: color 0.2s;
        }
        
        .delete-btn:hover {
            color: #cc0000;
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
            <h1>Favorite Products</h1>
            <p style="color: #666; margin-bottom: 24px;">Your favorite products collection. Enable alerts to start tracking price changes.</p>
            
            <?php if (empty($favorites)): ?>
                <div class="card" style="text-align: center; padding: 48px;">
                    <i class="fas fa-heart" style="font-size: 48px; color: #ccc; margin-bottom: 16px;"></i>
                    <h3 style="color: #666; margin-bottom: 16px;">No Favorite Products</h3>
                    <p style="color: #999;">Start adding products to your favorites by clicking the heart icon on product pages.</p>
                </div>
            <?php else: ?>
                <!-- Bulk Actions - Simplified -->
                <div class="bulk-actions">
                    <button class="btn btn-delete" onclick="confirmBulkFavoriteRemoval()">Remove Selected</button>
                </div>
                
                <div class="user-table">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <label>
                                        <input type="checkbox" id="select-all-header">
                                    </label>
                                </th>
                                <th>Thumbnail</th>
                                <th class="sortable">Product Name</th>
                                <th class="sortable">Price Info</th>
                                <th>Email Alert</th>
                                <th>Push Alert</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($favorites as $product): ?>
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
                                <tr data-product-id="<?php echo htmlspecialchars($product['asin']); ?>">
                                    <td>
                                        <input type="checkbox" class="bulk-checkbox" data-product-id="<?php echo htmlspecialchars($product['asin']); ?>">
                                    </td>
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
                                    <td>
                                        <i class="fas fa-trash delete-btn" 
                                           onclick="confirmDeleteFavorite('<?php echo htmlspecialchars($product['asin']); ?>')"
                                           title="Remove from favorites">
                                        </i>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="pagination" style="text-align: center; margin-top: 24px;">
                    <?php if ($page > 1): ?>
                        <a href="/user/favorites.php?page=<?php echo $page - 1; ?>" class="btn btn-secondary">Prev</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="/user/favorites.php?page=<?php echo $i; ?>" 
                           class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="/user/favorites.php?page=<?php echo $page + 1; ?>" class="btn btn-secondary">Next</a>
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
    
    <script>
        // Select all functionality
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllHeader = document.getElementById('select-all-header');
            const bulkCheckboxes = document.querySelectorAll('.bulk-checkbox');
            
            // Handle select all checkbox
            if (selectAllHeader) {
                selectAllHeader.addEventListener('change', function() {
                    bulkCheckboxes.forEach(cb => cb.checked = this.checked);
                });
            }
            
            // Update select-all state when individual checkboxes change
            bulkCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const allChecked = Array.from(bulkCheckboxes).every(cb => cb.checked);
                    const someChecked = Array.from(bulkCheckboxes).some(cb => cb.checked);
                    
                    if (selectAllHeader) {
                        selectAllHeader.checked = allChecked;
                        selectAllHeader.indeterminate = someChecked && !allChecked;
                    }
                });
            });
        });
        
        // Bulk delete confirmation
        function confirmBulkFavoriteRemoval() {
            const checkedBoxes = document.querySelectorAll('.bulk-checkbox:checked');
            if (checkedBoxes.length === 0) {
                showToast('Please select products to remove', 'error');
                return;
            }
            
            showPopup('delete-product-popup', `
                <h3>Remove Selected Favorites</h3>
                <p>Are you sure you want to remove ${checkedBoxes.length} product(s) from your favorites?</p>
                <div style="margin-top: 20px;">
                    <button class="btn btn-delete" onclick="bulkDeleteFavorites()">Yes, Remove All</button>
                    <button class="btn btn-secondary" onclick="hidePopup('delete-product-popup')" style="margin-left: 10px;">Cancel</button>
                </div>
            `);
        }
        
        // Bulk delete function
        async function bulkDeleteFavorites() {
            const checkedBoxes = document.querySelectorAll('.bulk-checkbox:checked');
            const productIds = Array.from(checkedBoxes).map(cb => cb.dataset.productId);
            
            try {
                // Show loading state
                const popup = document.getElementById('delete-product-popup');
                popup.querySelector('.popup-content').innerHTML = `
                    <h3>Removing Products...</h3>
                    <p>Please wait while we remove ${productIds.length} product(s) from your favorites.</p>
                    <div class="loading-spinner"></div>
                `;
                
                // Process deletions
                const promises = productIds.map(productId => 
                    fetch('/user/toggle_favorite.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        },
                        body: JSON.stringify({ product_id: productId, is_favorite: false })
                    })
                );
                
                const responses = await Promise.all(promises);
                
                // Check if all requests were successful
                let successCount = 0;
                for (const response of responses) {
                    if (response.ok) {
                        const result = await response.json();
                        if (result.status === 'success') {
                            successCount++;
                        }
                    }
                }
                
                // Remove successful deletions from DOM
                if (successCount > 0) {
                    productIds.slice(0, successCount).forEach(productId => {
                        const row = document.querySelector(`tr[data-product-id="${productId}"]`);
                        if (row) {
                            row.style.transition = 'opacity 0.3s ease';
                            row.style.opacity = '0';
                            setTimeout(() => row.remove(), 300);
                        }
                    });
                    
                    // Check if no favorites left
                    setTimeout(() => {
                        const remainingRows = document.querySelectorAll('tbody tr[data-product-id]');
                        if (remainingRows.length === 0) {
                            location.reload(); // Show empty state
                        }
                    }, 400);
                    
                    showToast(`${successCount} product(s) removed from favorites`, 'success');
                }
                
                if (successCount < productIds.length) {
                    showToast(`${productIds.length - successCount} product(s) could not be removed`, 'error');
                }
                
                hidePopup('delete-product-popup');
                
                // Reset select all checkbox
                const selectAllHeader = document.getElementById('select-all-header');
                if (selectAllHeader) {
                    selectAllHeader.checked = false;
                    selectAllHeader.indeterminate = false;
                }
                
            } catch (error) {
                console.error('Error in bulk delete:', error);
                showPopup('delete-product-popup', `
                    <h3>Error</h3>
                    <p>Failed to remove favorites. Please try again.</p>
                    <button class="btn btn-secondary" onclick="hidePopup('delete-product-popup')">OK</button>
                `);
            }
        }
        
        // Individual delete function
        function confirmDeleteFavorite(productId) {
            showPopup('delete-product-popup', `
                <h3>Remove from Favorites</h3>
                <p>Are you sure you want to remove this product from your favorites?</p>
                <div style="margin-top: 20px;">
                    <button class="btn btn-delete" onclick="deleteFavorite('${productId}')">Yes, Remove</button>
                    <button class="btn btn-secondary" onclick="hidePopup('delete-product-popup')" style="margin-left: 10px;">Cancel</button>
                </div>
            `);
        }
        
        // Delete favorite function with DOM removal
        async function deleteFavorite(productId) {
            try {
                const response = await fetch('/user/toggle_favorite.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({ product_id: productId, is_favorite: false })
                });
                
                const result = await response.json();

                if (result.status === 'success') {
                    // Remove the row from DOM
                    const row = document.querySelector(`tr[data-product-id="${productId}"]`);
                    if (row) {
                        row.style.transition = 'opacity 0.3s ease';
                        row.style.opacity = '0';
                        setTimeout(() => {
                            row.remove();
                            // Check if no favorites left and show empty state
                            const remainingRows = document.querySelectorAll('tbody tr[data-product-id]');
                            if (remainingRows.length === 0) {
                                location.reload(); // Reload to show empty state
                            }
                        }, 300);
                    }
                    
                    showToast('Product removed from favorites', 'success');
                    hidePopup('delete-product-popup');
                } else {
                    showPopup('delete-product-popup', `
                        <h3>Error</h3>
                        <p>${result.message || 'Failed to remove favorite'}</p>
                        <button class="btn btn-secondary" onclick="hidePopup('delete-product-popup')">OK</button>
                    `);
                }
            } catch (error) {
                console.error('Error removing favorite:', error);
                showPopup('delete-product-popup', `
                    <h3>Error</h3>
                    <p>Failed to remove favorite. Please try again.</p>
                    <button class="btn btn-secondary" onclick="hidePopup('delete-product-popup')">OK</button>
                `);
            }
        }
    </script>
</body>
</html>