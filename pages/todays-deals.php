<?php
require_once '../config/database.php';
require_once '../middleware/csrf.php';

startApplicationSession();

// Initialize variables
$category = filter_input(INPUT_GET, 'category', FILTER_SANITIZE_STRING) ?? '';
$subcategory = filter_input(INPUT_GET, 'subcategory', FILTER_SANITIZE_STRING) ?? '';
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?? 1);
$perPage = 32;
$offset = ($page - 1) * $perPage;

// Fetch categories and subcategories
$categoriesStmt = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL ORDER BY category ASC");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch subcategories based on selected category
$subcategories = [];
if ($category) {
    $subcategoriesStmt = $pdo->prepare("SELECT DISTINCT subcategory FROM products WHERE category = ? AND subcategory IS NOT NULL ORDER BY subcategory ASC");
    $subcategoriesStmt->execute([$category]);
    $subcategories = $subcategoriesStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Build query - Show ALL products, prioritize discounted ones
$query = "
    SELECT p.*, COUNT(up.id) as tracking_count,
    CASE 
        WHEN p.highest_price > p.current_price THEN (p.highest_price - p.current_price) / p.highest_price * 100 
        ELSE 0 
    END as discount_percentage
    FROM products p 
    LEFT JOIN user_products up ON p.asin = up.product_asin 
    WHERE p.current_price > 0 AND p.name IS NOT NULL
";
$params = [];

// Apply category filter if selected
if ($category) {
    $query .= " AND p.category = ?";
    $params[] = $category;
    
    if ($subcategory) {
        $query .= " AND p.subcategory = ?";
        $params[] = $subcategory;
    }
}

$query .= " GROUP BY p.asin ORDER BY discount_percentage DESC, tracking_count DESC, p.created_at DESC";
$query .= " LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count total
$totalQuery = "SELECT COUNT(DISTINCT p.asin) FROM products p WHERE p.current_price > 0 AND p.name IS NOT NULL";
$totalParams = [];
if ($category) {
    $totalQuery .= " AND p.category = ?";
    $totalParams[] = $category;
    if ($subcategory) {
        $totalQuery .= " AND p.subcategory = ?";  
        $totalParams[] = $subcategory;
    }
}
$totalStmt = $pdo->prepare($totalQuery);
$totalStmt->execute($totalParams);
$total = $totalStmt->fetchColumn();
$totalPages = min(10, ceil($total / $perPage));

// SEO meta tags
$metaTitle = $category ? "Best Deals on $category - AmezPrice" : "Today's Deals - AmezPrice";
$metaDescription = $category ? "Discover the best deals on $category at AmezPrice. Save big with our curated discounts!" : "Explore today's top deals on Amazon and Flipkart at AmezPrice. Find the best prices and track your favorite products.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($metaTitle); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <meta name="robots" content="index, follow">
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/product.css">
    <style>
        /* Override product.css grid for Today's Deals */
        .todays-deals-grid {
            display: grid !important;
            grid-template-columns: repeat(4, 1fr) !important;
            gap: 20px !important;
            justify-content: center;
        }
        
        .todays-deals-grid .product-card {
            width: 100% !important;
            min-width: auto !important;
            max-width: none !important;
        }
        
        /* Responsive grid */
        @media (max-width: 1200px) {
            .todays-deals-grid {
                grid-template-columns: repeat(3, 1fr) !important;
            }
        }
        
        @media (max-width: 768px) {
            .todays-deals-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 15px !important;
            }
        }
        
        @media (max-width: 480px) {
            .todays-deals-grid {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "ItemList",
        "itemListElement": [
            <?php foreach ($products as $index => $product): ?>
                {
                    "@type": "Product",
                    "name": "<?php echo htmlspecialchars($product['name']); ?>",
                    "image": "<?php echo htmlspecialchars($product['image_path']); ?>",
                    "url": "<?php echo htmlspecialchars($product['website_url']); ?>",
                    "offers": {
                        "@type": "Offer",
                        "price": "<?php echo $product['current_price']; ?>",
                        "priceCurrency": "INR",
                        "availability": "<?php echo $product['stock_status'] === 'in_stock' ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock'; ?>"
                    }
                }<?php echo $index < count($products) - 1 ? ',' : ''; ?>
            <?php endforeach; ?>
        ]
    }
    </script>
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <main class="container">
        <div class="deals-header">
            <h1 class="deals-title">
                <i class="fas fa-fire"></i> Today's Deals
            </h1>
            <p class="deals-subtitle">Discover amazing discounts on top products from Amazon and Flipkart</p>
        </div>
        
        <?php if (!empty($products)): ?>
        <div class="product-stats">
            Showing <?php echo count($products); ?> products
            <?php if ($category): ?>
                in <strong><?php echo htmlspecialchars($category); ?></strong>
                <?php if ($subcategory): ?>
                    > <strong><?php echo htmlspecialchars($subcategory); ?></strong>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="filter-section">
            <div class="card filter-card">
                <form id="deal-filters" aria-label="Filter deals">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="category">Category</label>
                            <select id="category" name="category" onchange="loadSubcategories()" aria-label="Choose a category">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="subcategory">Subcategory</label>
                            <select id="subcategory" name="subcategory" onchange="applyFilters()" aria-label="Choose a subcategory">
                                <option value="">All Subcategories</option>
                                <?php foreach ($subcategories as $subcat): ?>
                                    <option value="<?php echo htmlspecialchars($subcat); ?>" <?php echo $subcategory === $subcat ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subcat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Product Grid using Related Deals Design with 4 columns -->
        <section class="related-deals-section">
            <div class="todays-deals-grid" id="product-grid" aria-live="polite">
                <?php if (empty($products)): ?>
                    <div class="no-deals" style="grid-column: 1 / -1;">
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <h2>No Products Found</h2>
                        <p>Try selecting a different category or check if products exist in the database!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($products as $product): 
                        $discount = round($product['discount_percentage']);
                        $hasOriginalPrice = isset($product['original_price']) && $product['original_price'] > $product['current_price'];
                    ?>
                        <article class="product-card">
                            <div class="product-image">
                                <img src="<?php echo htmlspecialchars($product['image_path']); ?>" 
                                     alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                     loading="lazy">
                            </div>
                            
                            <!-- Merchant Logo between image and title -->
                            <div class="merchant-logo-container">
                                <img src="/assets/images/logos/<?php echo htmlspecialchars($product['merchant']); ?>.svg" 
                                     alt="<?php echo htmlspecialchars($product['merchant']); ?> Logo" 
                                     class="merchant-logo-centered">
                            </div>
                            
                            <h3><?php echo htmlspecialchars(substr($product['name'], 0, 80)) . (strlen($product['name']) > 80 ? '...' : ''); ?></h3>
                            
                            <!-- Fixed Price Display with Cut Price -->
                            <p class="price">
                                ₹<?php echo number_format($product['current_price'], 0, '.', ','); ?>
                                <?php if ($hasOriginalPrice): ?>
                                    <s>₹<?php echo number_format($product['original_price'], 0, '.', ','); ?></s>
                                <?php endif; ?>
                            </p>
                            
                            <p class="trackers">🔥 <?php echo $product['tracking_count']; ?> users tracking</p>
                            
                            <?php if ($discount > 0): ?>
                                <span class="discount-badge"><?php echo $discount; ?>% Off</span>
                            <?php endif; ?>
                            
                            <div class="card-actions">
                                <a href="<?php echo htmlspecialchars($product['affiliate_link'] ?? '#'); ?>" 
                                   class="btn btn-primary" 
                                   target="_blank" 
                                   rel="noopener"
                                   aria-label="Buy <?php echo htmlspecialchars($product['name']); ?> now">Buy Now</a>
                                <a href="/product/<?php echo htmlspecialchars($product['merchant']); ?>/pid=<?php echo htmlspecialchars($product['asin']); ?>" 
                                   class="btn btn-secondary" 
                                   aria-label="View price history for <?php echo htmlspecialchars($product['name']); ?>">Price History</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination" aria-label="Pagination">
            <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(['category' => $category, 'subcategory' => $subcategory, 'page' => $page - 1]); ?>" class="btn btn-secondary" aria-label="Previous page">Previous</a>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?<?php echo http_build_query(['category' => $category, 'subcategory' => $subcategory, 'page' => $i]); ?>" class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>" aria-label="Page <?php echo $i; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a href="?<?php echo http_build_query(['category' => $category, 'subcategory' => $subcategory, 'page' => $page + 1]); ?>" class="btn btn-secondary" aria-label="Next page">Next</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
    <?php include '../include/footer.php'; ?>
    <script>
        // Load subcategories when category changes
        function loadSubcategories() {
            const category = document.getElementById('category').value;
            const subcategorySelect = document.getElementById('subcategory');
            
            if (!category) {
                subcategorySelect.innerHTML = '<option value="">All Subcategories</option>';
                applyFilters();
                return;
            }
            
            // Fetch subcategories via AJAX
            fetch(`/api/get-subcategories.php?category=${encodeURIComponent(category)}`)
                .then(response => response.json())
                .then(data => {
                    subcategorySelect.innerHTML = '<option value="">All Subcategories</option>';
                    if (data.success && data.subcategories) {
                        data.subcategories.forEach(subcat => {
                            const option = document.createElement('option');
                            option.value = subcat;
                            option.textContent = subcat;
                            subcategorySelect.appendChild(option);
                        });
                    }
                    applyFilters();
                })
                .catch(error => {
                    console.error('Error fetching subcategories:', error);
                    applyFilters();
                });
        }
        
        // Apply filters and reload page
        function applyFilters() {
            const category = document.getElementById('category').value;
            const subcategory = document.getElementById('subcategory').value;
            
            const params = new URLSearchParams();
            if (category) params.set('category', category);
            if (subcategory) params.set('subcategory', subcategory);
            params.set('page', '1');
            
            window.location.href = '?' + params.toString();
        }
    </script>
</body>
</html>