<?php
require_once 'config/database.php';
require_once 'config/category.php';
require_once 'middleware/csrf.php';

startApplicationSession();

// Fetch categories
$categories = include 'config/category.php';

// Fetch AI-powered recommendations for logged-in users
$recommendedProducts = [];
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];

    // Check if we've already run recommendations in this session
    if (!isset($_SESSION['recommendations_generated']) || 
        (time() - $_SESSION['recommendations_generated'] > 10800)) { // Refresh after three hours
        
        // Include deals script only when needed
        require_once 'ai_engine/scripts/deals.php';
        
        // Mark that recommendations have been generated
        $_SESSION['recommendations_generated'] = time();
    }
    
    try {
        // Get user's cluster for better recommendations
        $clusterStmt = $pdo->prepare("SELECT cluster FROM users WHERE id = ?");
        $clusterStmt->execute([$userId]);
        $userCluster = $clusterStmt->fetchColumn();
        
        if ($userCluster !== false) {
            // Get AI-powered recommendations based on user behavior and cluster
            $stmt = $pdo->prepare("
                SELECT DISTINCT p.*, COUNT(up.id) as tracker_count,
                       ub.interaction_type, ub.is_ai_suggested
                FROM products p 
                LEFT JOIN user_products up ON p.asin = up.product_asin 
                JOIN user_behavior ub ON p.asin = ub.asin 
                JOIN users u ON ub.user_id = u.id
                WHERE (u.cluster = ? OR ub.user_id = ?) 
                AND p.current_price <= p.highest_price * 0.7 
                AND p.rating >= 3.5
                AND p.current_price > 0
                AND p.image_path IS NOT NULL
                GROUP BY p.asin
                ORDER BY 
                    CASE WHEN ub.is_ai_suggested = 1 THEN 1 ELSE 0 END DESC,
                    (p.highest_price - p.current_price) / p.highest_price DESC,
                    tracker_count DESC
                LIMIT 20
            ");
            $stmt->execute([$userCluster, $userId]);
            $recommendedProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Fallback to user's tracked products if no cluster recommendations
        if (empty($recommendedProducts)) {
            $stmt = $pdo->prepare("
                SELECT p.*, COUNT(up.id) as tracker_count
                FROM products p 
                LEFT JOIN user_products up ON p.asin = up.product_asin
                JOIN user_behavior ub ON p.asin = ub.asin 
                WHERE ub.user_id = ? AND p.current_price <= p.highest_price * 0.7 AND p.rating >= 3.5
                GROUP BY p.asin
                ORDER BY (p.highest_price - p.current_price) / p.highest_price DESC 
                LIMIT 20
            ");
            $stmt->execute([$userId]);
            $recommendedProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("AI Recommendations Error: " . $e->getMessage());
        $recommendedProducts = [];
    }
}

// Fetch trending products (always visible)
$trendingProducts = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.*, 
            COUNT(up.id) as tracker_count,
            COUNT(ub.id) as interaction_count,
            AVG(CASE WHEN ub.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as recent_activity
        FROM products p 
        LEFT JOIN user_products up ON p.asin = up.product_asin 
        LEFT JOIN user_behavior ub ON p.asin = ub.asin
        WHERE p.current_price > 0 
        AND p.rating >= 3.0
        AND p.image_path IS NOT NULL
        GROUP BY p.asin
        HAVING COUNT(up.id) > 0
        ORDER BY 
            (COUNT(up.id) * 0.4 + COUNT(ub.id) * 0.3 + AVG(CASE WHEN ub.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) * 0.3) DESC,
            COUNT(up.id) DESC
        LIMIT 20
        ");
        $stmt->execute();
        $trendingProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
    error_log("Trending Products Error: " . $e->getMessage());
    $trendingProducts = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="AmezPrice - Track prices for Amazon and Flipkart products, get AI-powered deal recommendations, and save on your purchases.">
    <meta name="keywords" content="price tracking, Amazon deals, Flipkart deals, AI recommendations, online shopping">
    <meta property="og:title" content="AmezPrice - Price Tracking and Deals">
    <meta property="og:description" content="Track prices and get the best deals on Amazon and Flipkart with AI-powered recommendations.">
    <meta property="og:image" content="/assets/images/logos/website-logo.png">
    <meta property="og:url" content="https://amezprice.com">
    <title>AmezPrice - Price Tracking</title>
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/search.css">
    <link rel="stylesheet" href="/assets/css/product.css">
</head>
<body>
    <?php include 'include/navbar.php'; ?>
    <main class="container">
        <?php include 'search/template.php'; ?>
        
        <!-- AI Recommendations Section (Only for logged-in users) -->
        <?php if (isset($_SESSION['user_id'])): ?>
            <section class="ai-recommendations">
                <h2>Recommendations</h2>
                <?php if (!empty($recommendedProducts)): ?>
                    <div class="product-rows">
                        <!-- First Row -->
                        <div class="product-row">
                            <div class="product-carousel auto-scroll" id="ai-row-1">
                                <?php 
                                $firstRowProducts = array_slice($recommendedProducts, 0, 10);
                                foreach ($firstRowProducts as $product):
                                    $discount = round(($product['highest_price'] - $product['current_price']) / $product['highest_price'] * 100);
                                ?>
                                    <article class="carousel-product-card">
                                        <img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                        <img src="/assets/images/logos/<?php echo htmlspecialchars($product['merchant']); ?>.svg" alt="<?php echo htmlspecialchars($product['merchant']); ?>" class="merchant-logo">
                                        <h3><?php echo htmlspecialchars(substr($product['name'], 0, 80)) . (strlen($product['name']) > 80 ? '...' : ''); ?></h3>
                                        <div class="price-section">
                                            <span class="current-price">₹<?php echo number_format($product['current_price'], 0, '.', ','); ?></span>
                                            <?php if (isset($product['original_price']) && $product['original_price'] > $product['current_price']): ?>
                                                <span class="original-price">₹<?php echo number_format($product['original_price'], 0, '.', ','); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="tracker-info">
                                            <i class="fas fa-users"></i>
                                            <?php echo $product['tracker_count']; ?> tracking
                                        </div>
                                        <div class="product-actions">
                                            <a href="<?php echo htmlspecialchars($product['affiliate_link'] ?? '#'); ?>" class="btn-small btn-primary-small" target="_blank">
                                                <i class="fas fa-shopping-cart"></i> Buy
                                            </a>
                                            <a href="/product/<?php echo htmlspecialchars($product['merchant']); ?>/pid=<?php echo htmlspecialchars($product['asin']); ?>" class="btn-small btn-secondary-small">
                                                <i class="fas fa-chart-line"></i> Track
                                            </a>
                                        </div>
                                        <?php if ($discount > 0): ?>
                                            <span class="discount-badge"><?php echo $discount; ?>% Off</span>
                                        <?php endif; ?>
                                        <?php if (isset($product['is_ai_suggested']) && $product['is_ai_suggested']): ?>
                                            <span class="ai-badge">AI Pick</span>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Second Row -->
                        <?php if (count($recommendedProducts) > 10): ?>
                        <div class="product-row">
                            <div class="product-carousel auto-scroll" id="ai-row-2">
                                <?php 
                                $secondRowProducts = array_slice($recommendedProducts, 10, 10);
                                foreach ($secondRowProducts as $product):
                                    $discount = round(($product['highest_price'] - $product['current_price']) / $product['highest_price'] * 100);
                                ?>
                                    <article class="carousel-product-card">
                                        <img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                        <img src="/assets/images/logos/<?php echo htmlspecialchars($product['merchant']); ?>.svg" alt="<?php echo htmlspecialchars($product['merchant']); ?>" class="merchant-logo">
                                        <h3><?php echo htmlspecialchars(substr($product['name'], 0, 80)) . (strlen($product['name']) > 80 ? '...' : ''); ?></h3>
                                        <div class="price-section">
                                            <span class="current-price">₹<?php echo number_format($product['current_price'], 0, '.', ','); ?></span>
                                                <?php if (isset($product['original_price']) && $product['original_price'] > $product['current_price']): ?>
                                                    <span class="original-price">₹<?php echo number_format($product['original_price'], 0, '.', ','); ?></span>
                                                <?php endif; ?>
                                        </div>
                                        <div class="tracker-info">
                                            <i class="fas fa-users"></i>
                                            <?php echo $product['tracker_count']; ?> tracking
                                        </div>
                                        <div class="product-actions">
                                            <a href="<?php echo htmlspecialchars($product['affiliate_link'] ?? '#'); ?>" class="btn-small btn-primary-small" target="_blank">
                                                <i class="fas fa-shopping-cart"></i> Buy
                                            </a>
                                            <a href="/product/<?php echo htmlspecialchars($product['merchant']); ?>/pid=<?php echo htmlspecialchars($product['asin']); ?>" class="btn-small btn-secondary-small">
                                                <i class="fas fa-chart-line"></i> Track
                                            </a>
                                        </div>
                                        <?php if ($discount > 0): ?>
                                            <span class="discount-badge"><?php echo $discount; ?>% Off</span>
                                        <?php endif; ?>
                                        <?php if (isset($product['is_ai_suggested']) && $product['is_ai_suggested']): ?>
                                            <span class="ai-badge">AI Pick</span>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="login-prompt">
                        <h3>No Recommendations Available</h3>
                        <p>Start tracking products to get personalized AI recommendations!</p>
                        <a href="/search" class="btn btn-primary">Find Products</a>
                    </div>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="ai-recommendations">
                <h2>Recommendations</h2>
                <div class="login-prompt">
                    <h3>Login to See AI-Powered Recommendations</h3>
                    <p>Get personalized deal suggestions based on your preferences and behavior</p>
                    <a href="/user/login.php" class="btn btn-primary">Login Now</a>
                </div>
            </section>
        <?php endif; ?>

        <!-- Trending Section (Always visible) -->
        <section class="trending-section">
            <h2>Trending</h2>
            <?php if (!empty($trendingProducts)): ?>
                <div class="product-rows">
                    <!-- First Row -->
                    <div class="product-row">
                        <div class="product-carousel auto-scroll" id="trending-row-1">
                            <?php 
                            $firstRowTrending = array_slice($trendingProducts, 0, 10);
                            foreach ($firstRowTrending as $product):
                                $discount = round(($product['highest_price'] - $product['current_price']) / $product['highest_price'] * 100);
                            ?>
                                <article class="carousel-product-card">
                                    <img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                    <img src="/assets/images/logos/<?php echo htmlspecialchars($product['merchant']); ?>.svg" alt="<?php echo htmlspecialchars($product['merchant']); ?>" class="merchant-logo">
                                    <h3><?php echo htmlspecialchars(substr($product['name'], 0, 80)) . (strlen($product['name']) > 80 ? '...' : ''); ?></h3>
                                    <div class="price-section">
                                        <span class="current-price">₹<?php echo number_format($product['current_price'], 0, '.', ','); ?></span>
                                        <?php if (isset($product['original_price']) && $product['original_price'] > $product['current_price']): ?>
                                            <span class="original-price">₹<?php echo number_format($product['original_price'], 0, '.', ','); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="tracker-info">
                                        <i class="fas fa-fire"></i>
                                        <?php echo $product['tracker_count']; ?> trending
                                    </div>
                                    <div class="product-actions">
                                        <a href="<?php echo htmlspecialchars($product['affiliate_link'] ?? '#'); ?>" class="btn-small btn-primary-small" target="_blank">
                                            <i class="fas fa-shopping-cart"></i> Buy
                                        </a>
                                        <a href="/product/<?php echo htmlspecialchars($product['merchant']); ?>/pid=<?php echo htmlspecialchars($product['asin']); ?>" class="btn-small btn-secondary-small">
                                            <i class="fas fa-chart-line"></i> Track
                                        </a>
                                    </div>
                                    <?php if ($discount > 0): ?>
                                        <span class="discount-badge"><?php echo $discount; ?>% Off</span>
                                    <?php endif; ?>
                                    <span class="trending-badge">Trending</span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Second Row -->
                    <?php if (count($trendingProducts) > 10): ?>
                    <div class="product-row">
                        <div class="product-carousel auto-scroll" id="trending-row-2">
                            <?php 
                            $secondRowTrending = array_slice($trendingProducts, 10, 10);
                            foreach ($secondRowTrending as $product):
                                $discount = round(($product['highest_price'] - $product['current_price']) / $product['highest_price'] * 100);
                            ?>
                                <article class="carousel-product-card">
                                    <img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                    <img src="/assets/images/logos/<?php echo htmlspecialchars($product['merchant']); ?>.svg" alt="<?php echo htmlspecialchars($product['merchant']); ?>" class="merchant-logo">
                                    <h3><?php echo htmlspecialchars(substr($product['name'], 0, 80)) . (strlen($product['name']) > 80 ? '...' : ''); ?></h3>
                                    <div class="price-section">
                                        <span class="current-price">₹<?php echo number_format($product['current_price'], 0, '.', ','); ?></span>
                                        <?php if (isset($product['original_price']) && $product['original_price'] > $product['current_price']): ?>
                                            <span class="original-price">₹<?php echo number_format($product['original_price'], 0, '.', ','); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="tracker-info">
                                        <i class="fas fa-fire"></i>
                                        <?php echo $product['tracker_count']; ?> trending
                                    </div>
                                    <div class="product-actions">
                                        <a href="<?php echo htmlspecialchars($product['affiliate_link'] ?? '#'); ?>" class="btn-small btn-primary-small" target="_blank">
                                            <i class="fas fa-shopping-cart"></i> Buy
                                        </a>
                                        <a href="/product/<?php echo htmlspecialchars($product['merchant']); ?>/pid=<?php echo htmlspecialchars($product['asin']); ?>" class="btn-small btn-secondary-small">
                                            <i class="fas fa-chart-line"></i> Track
                                        </a>
                                    </div>
                                    <?php if ($discount > 0): ?>
                                        <span class="discount-badge"><?php echo $discount; ?>% Off</span>
                                    <?php endif; ?>
                                    <span class="trending-badge">Trending</span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="login-prompt">
                    <h3>No Trending Products</h3>
                    <p>Check back later for trending deals!</p>
                </div>
            <?php endif; ?>
        </section>

        <!-- Existing Category Sections -->
        <?php foreach ($categories as $entry): ?>
            <?php if (!empty($entry['category']) && !empty($entry['platform'])): ?>
                <section class="product-box">
                    <div class="category-header">
                        <h2><?php echo htmlspecialchars($entry['heading']); ?></h2>
                        <a href="/pages/todays-deals.php?category=<?php echo urlencode($entry['category']); ?>" class="category-link">
                            View All <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    <div class="product-carousel" id="carousel-<?php echo md5($entry['category']); ?>">
                        <?php
                        $stmt = $pdo->prepare("
                            SELECT p.*, COUNT(up.id) as tracker_count 
                            FROM products p 
                            LEFT JOIN user_products up ON p.asin = up.product_asin 
                            WHERE p.category = ? AND p.merchant = ? 
                            GROUP BY p.asin
                            ORDER BY (p.highest_price - p.current_price) / p.highest_price DESC 
                            LIMIT 10
                        ");
                        $stmt->execute([$entry['category'], $entry['platform']]);
                        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (empty($products)):
                            // Show skeleton loading if no products
                            for ($i = 0; $i < 6; $i++):
                        ?>
                            <div class="product-card skeleton">
                                <div class="skeleton-image"></div>
                                <div class="skeleton-title"></div>
                                <div class="skeleton-price"></div>
                                <div class="skeleton-button"></div>
                            </div>
                        <?php 
                            endfor;
                        else:
                            foreach ($products as $product):
                                $discount = round(($product['highest_price'] - $product['current_price']) / $product['highest_price'] * 100);
                        ?>
                            <article class="product-card">
                                <img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                <img src="/assets/images/logos/<?php echo htmlspecialchars($product['merchant']); ?>.svg" alt="<?php echo htmlspecialchars($product['merchant']); ?> Logo">
                                <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                                <p class="price">
                                    ₹<?php echo number_format($product['current_price'], 0, '.', ','); ?>
                                    <s>₹<?php echo number_format($product['highest_price'], 0, '.', ','); ?></s>
                                </p>
                                <p class="trackers">🔥 <?php echo $product['tracker_count']; ?> users tracking</p>
                                <span class="discount-badge"><?php echo $discount; ?>% Off</span>
                                <a href="<?php echo htmlspecialchars($product['affiliate_link']); ?>" class="btn btn-primary" target="_blank" aria-label="Buy <?php echo htmlspecialchars($product['name']); ?> now">Buy Now</a>
                                <a href="/product/<?php echo htmlspecialchars($product['merchant']); ?>/pid=<?php echo htmlspecialchars($product['asin']); ?>" class="btn btn-secondary" aria-label="View price history for <?php echo htmlspecialchars($product['name']); ?>"><i class="fas fa-chart-line"></i> Price History</a>
                            </article>
                        <?php 
                            endforeach;
                        endif; 
                        ?>
                    </div>
                    <div class="carousel-nav">
                        <button class="carousel-arrow left" onclick="scrollCarousel('carousel-<?php echo md5($entry['category']); ?>', -1)" aria-label="Previous products">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button class="carousel-arrow right" onclick="scrollCarousel('carousel-<?php echo md5($entry['category']); ?>', 1)" aria-label="Next products">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </section>
            <?php endif; ?>
        <?php endforeach; ?>
    </main>
    
    <?php include 'include/footer.php'; ?>
    <script src="/assets/js/main.js"></script>
</body>
</html>