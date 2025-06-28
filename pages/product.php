<?php
require_once '../config/database.php';
require_once '../config/globals.php';
require_once '../middleware/csrf.php';

startApplicationSession();

$merchant = $_GET['merchant'] ?? '';
$pid = $_GET['pid'] ?? '';

if (!in_array($merchant, ['amazon', 'flipkart']) || empty($pid)) {
    header('Location: /');
    exit;
}

try {
    // Fetch product details
    $stmt = $pdo->prepare("SELECT * FROM products WHERE asin = ? AND merchant = ?");
    $stmt->execute([$pid, $merchant]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        header('Location: /');
        exit;
    }

    // Check if product is favorited
    $isFavorite = false;
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT is_favorite FROM user_products WHERE user_id = ? AND product_asin = ?");
        $stmt->execute([$_SESSION['user_id'], $pid]);
        $isFavorite = $stmt->fetchColumn();
    }

    // Check if product is out of stock
    $isOutOfStock = ($product['stock_status'] === 'out_of_stock' || $product['stock_quantity'] == 0);

    // Advanced price history processing for graph
    $historyStmt = $pdo->prepare("
        SELECT date_recorded, price 
        FROM price_history 
        WHERE product_asin = ? 
        ORDER BY date_recorded ASC
    ");
    $historyStmt->execute([$product['asin']]);
    $historyData = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert to the same format as before
    $rawHistory = [];
    foreach ($historyData as $record) {
        $rawHistory[$record['date_recorded']] = (float)$record['price'];
    }
    
    // Separate past months and current month
    $pastMonthsData = [];
    $currentMonthData = [];
    
    foreach ($rawHistory as $date => $price) {
        $month = substr($date, 0, 7);
        
        if ($month === $currentMonth) {
            $currentMonthData[$date] = $price;
        } else {
            if (!isset($pastMonthsData[$month])) {
                $pastMonthsData[$month] = ['highest' => $price, 'lowest' => $price];
            } else {
                $pastMonthsData[$month]['highest'] = max($pastMonthsData[$month]['highest'], $price);
                $pastMonthsData[$month]['lowest'] = min($pastMonthsData[$month]['lowest'], $price);
            }
        }
    }
    
    // Prepare data for chart
    $chartLabels = [];
    $chartHighestData = [];
    $chartLowestData = [];
    
    // Add last 23 months
    $sortedPastMonths = array_keys($pastMonthsData);
    rsort($sortedPastMonths);
    $last23Months = array_slice($sortedPastMonths, 0, 23);
    rsort($last23Months);
    
    foreach ($last23Months as $month) {
        $chartLabels[] = date('M Y', strtotime($month . '-01'));
        $chartHighestData[] = $pastMonthsData[$month]['highest'];
        $chartLowestData[] = $pastMonthsData[$month]['lowest'];
    }
    
    // Add current month day-wise data
    ksort($currentMonthData);
    foreach ($currentMonthData as $date => $price) {
        $day = date('j M', strtotime($date));
        $chartLabels[] = $day;
        
        if ($isOutOfStock && $date === date('Y-m-d')) {
            $chartHighestData[] = 0;
            $chartLowestData[] = 0;
        } else {
            $chartHighestData[] = $price;
            $chartLowestData[] = $price;
        }
    }
    
    // If current month has no data but product exists, add today's data
    if (empty($currentMonthData) && !$isOutOfStock) {
        $today = date('j M');
        $chartLabels[] = $today;
        $chartHighestData[] = $product['current_price'];
        $chartLowestData[] = $product['current_price'];
    } elseif (empty($currentMonthData) && $isOutOfStock) {
        $today = date('j M');
        $chartLabels[] = $today;
        $chartHighestData[] = 0;
        $chartLowestData[] = 0;
    }

    // Create price history for table
    $priceHistory = $pastMonthsData;
    if (!empty($currentMonthData)) {
        $priceHistory[$currentMonth] = [
            'highest' => max($currentMonthData),
            'lowest' => min($currentMonthData)
        ];
    }

    // Calculate discount percentage, Check if product has sufficient price history (at least 3 months)
    $hasEnoughHistory = false;
    $historyMonthsCount = 0;

    if (!empty($rawHistory)) {
        $historyMonths = [];
        foreach ($rawHistory as $date => $price) {
            $month = substr($date, 0, 7);
            $historyMonths[$month] = true;
        }
        $historyMonthsCount = count($historyMonths);
        $hasEnoughHistory = $historyMonthsCount >= 3;
    }

    // Buy suggestion logic
    $buySuggestion = '';
    $buyIcon = '';
    $buyColor = '';

    if ($isOutOfStock) {
        $buySuggestion = 'Purchase recommendation is not available as the product is Out of Stock';
        $buyIcon = 'fa-exclamation';
        $buyColor = '#ff0000';
    } elseif (!$hasEnoughHistory) {
        $buySuggestion = 'We don\'t have enough price history data for this product to provide a reliable recommendation.';
        $buyIcon = 'fa-info-circle';
        $buyColor = '#ffcc00';
    } else {
        // Calculate discount percentage only when we have enough history
        $discountPercent = ($product['highest_price'] - $product['current_price']) / max($product['highest_price'] - $product['lowest_price'], 1) * 100;
        
        if ($discountPercent <= 20) {
            $buySuggestion = 'Do not buy this product now. The current price is high compared to its historical prices.';
            $buyIcon = 'fa-thumbs-down';
            $buyColor = '#ff0000';
        } elseif ($discountPercent <= 60) {
            $buySuggestion = 'You can buy or maybe wait. The current price is within the normal range.';
            $buyIcon = 'fa-thumbs-up';
            $buyColor = 'linear-gradient(#ffcc00, #ff6600)';
        } else {
            $buySuggestion = 'You can buy this product now. The current price is low compared to its historical prices.';
            $buyIcon = 'fa-hands-clapping';
            $buyColor = '#00cc00';
        }
    }

    // Fetch price predictions
    $predictions = [];
    if (!$isOutOfStock) {
        $predictionsStmt = $pdo->prepare("SELECT predicted_price, period FROM predictions WHERE asin = ? AND prediction_date >= CURDATE() LIMIT 4");
        $predictionsStmt->execute([$pid]);
        $predictions = $predictionsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch price drop pattern
    $patternStmt = $pdo->prepare("SELECT pattern_description FROM patterns WHERE asin = ? ORDER BY created_at DESC LIMIT 1");
    $patternStmt->execute([$pid]);
    $pattern = $patternStmt->fetchColumn() ?: 'No Price Drop Pattern Detected';

    // Fetch related deals
    $relatedStmt = $pdo->prepare("
        SELECT p.*, COUNT(up.id) as tracker_count
        FROM products p
        LEFT JOIN user_products up ON p.asin = up.product_asin
        WHERE p.category = ? AND p.asin != ? AND p.merchant = ?
        GROUP BY p.asin
        ORDER BY (p.highest_price - p.current_price) / p.highest_price DESC
        LIMIT 8
    ");
    $relatedStmt->execute([$product['category'], $pid, $merchant]);
    $relatedDeals = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch AI recommendations
    $recommendedProducts = [];
    if (isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
        
        try {
            $clusterStmt = $pdo->prepare("SELECT cluster FROM users WHERE id = ?");
            $clusterStmt->execute([$userId]);
            $userCluster = $clusterStmt->fetchColumn();
            
            if ($userCluster !== false) {
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
} catch (Exception $e) {
    file_put_contents('../logs/database.log', "[" . date('Y-m-d H:i:s') . "] Database error in product.php: " . $e->getMessage() . "\n", FILE_APPEND);
    echo "<script>alert('An error occurred while loading the product data. Please try again later.');</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Track the price history of <?php echo htmlspecialchars($product['name']); ?> on AmezPrice. View trends, predictions, and get the best deals on <?php echo htmlspecialchars($merchant); ?>.">
    <meta name="keywords" content="price history, <?php echo htmlspecialchars($product['name']); ?>, AmezPrice, <?php echo htmlspecialchars($merchant); ?>, deals, price tracking">
    <meta property="og:title" content="<?php echo htmlspecialchars($product['name']); ?> - Price History">
    <meta property="og:description" content="Track the price history of <?php echo htmlspecialchars($product['name']); ?> on AmezPrice. View trends, predictions, and get the best deals on <?php echo htmlspecialchars($merchant); ?>.">
    <meta property="og:image" content="<?php echo htmlspecialchars($product['image_path']); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($product['website_url']); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <title><?php echo htmlspecialchars($product['name']); ?> - Price History | AmezPrice</title>
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/search.css">
    <link rel="stylesheet" href="/assets/css/product.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    
    <main class="container">
        <?php include '../search/template.php'; ?>
        
        <section class="product-main-section" role="region" aria-labelledby="price-history-result">
            <p id="price-history-result" class="section-label">PRICE HISTORY RESULT</p>
            
            <div class="product-header">
                <h2><?php echo htmlspecialchars($product['name']); ?></h2>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <i class="fas fa-heart favorite-btn" 
                       data-product-id="<?php echo htmlspecialchars($pid); ?>" 
                       data-is-favorite="<?php echo $isFavorite ? 'true' : 'false'; ?>"
                       role="button" 
                       aria-label="<?php echo $isFavorite ? 'Remove from favorites' : 'Add to favorites'; ?>" 
                       tabindex="0"></i>
                <?php else: ?>
                    <i class="fas fa-heart favorite-btn guest" 
                       role="button" 
                       aria-label="Add to favorites (requires login)" 
                       tabindex="0"></i>
                <?php endif; ?>
            </div>
            
            <!-- Current Price Display with Info Icon -->
            <div class="current-price-section">
                <?php if ($isOutOfStock): ?>
                    <p class="out-of-stock">Out of Stock</p>
                <?php else: ?>
                    <p class="current-price">
                        ₹<?php echo number_format($product['current_price'], 0, '.', ','); ?> 
                        <s class="original-price">₹<?php echo number_format($product['original_price'], 0, '.', ','); ?></s>
                        <i class="price-info-icon fas fa-info-circle" 
                           data-tooltip="Product prices and availability are subject to change. Any price and availability information displayed on the store (Amazon or Flipkart) at the time of purchase will apply to the purchase of this product"></i>
                    </p>
                <?php endif; ?>
            </div>
            
            <div class="product-details">
                <div class="product-image">
                    <img src="<?php echo htmlspecialchars($product['image_path']); ?>" 
                         alt="<?php echo htmlspecialchars($product['name']); ?>" 
                         loading="lazy">
                </div>
                
                <div class="product-info">
                    <div class="price-range-card">
                        <span class="highest-price">Highest Price: ₹<?php echo number_format($product['highest_price'], 0, '.', ','); ?></span>
                        <span class="lowest-price">Lowest Price: ₹<?php echo number_format($product['lowest_price'], 0, '.', ','); ?></span>
                    </div>
                    <div class="buy-suggestion-card">
                        <h3>Is it a right time to buy?</h3>
                        <p class="suggestion-text" data-color="<?php echo $buyColor; ?>">
                            <i class="fas <?php echo $buyIcon; ?>" aria-hidden="true"></i> 
                            <?php echo $buySuggestion; ?>
                            <?php if ($isOutOfStock): ?>
                                <span class="stock-notice">Check back later for availability updates.</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <div class="action-buttons">
                        <?php if ($isOutOfStock): ?>
                            <button class="btn btn-primary btn-disabled" disabled>Currently Out of Stock</button>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars($product['affiliate_link']); ?>" 
                               class="btn btn-primary" 
                               target="_blank" 
                               rel="noopener"
                               aria-label="Buy <?php echo htmlspecialchars($product['name']); ?> now">
                                Buy Now on <?php echo ucfirst($merchant); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Price Chart -->
            <div class="chart-container">
                <h3 class="chart-title">Price History Graph (Last 23 Months + Current Month Daily)</h3>
                <div class="chart-wrapper">
                    <canvas id="priceChart" role="img" aria-label="Price history graph showing highest and lowest prices over time"></canvas>
                </div>
            </div>
            
            <!-- Price Predictions -->
            <?php if (!$isOutOfStock && !empty($predictions)): ?>
            <div class="predictions-section">
                <h3>Future Price Prediction Powered by Advanced AI & Machine Learning</h3>
                <div class="predictions-grid">
                    <?php foreach ($predictions as $pred): ?>
                        <div class="prediction-item">
                            <p class="prediction-period"><?php echo htmlspecialchars($pred['period']); ?></p>
                            <p class="prediction-price" data-trend="<?php echo $pred['predicted_price'] > $product['current_price'] ? 'up' : 'down'; ?>">
                                ₹<?php echo number_format($pred['predicted_price'], 0, '.', ','); ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="prediction-summary" data-trend="<?php echo $predictions[0]['predicted_price'] > $product['current_price'] ? 'up' : ($predictions[0]['predicted_price'] < $product['current_price'] ? 'down' : 'same'); ?>">
                    Price will <?php echo $predictions[0]['predicted_price'] > $product['current_price'] ? 'increase' : ($predictions[0]['predicted_price'] < $product['current_price'] ? 'decrease' : 'remain stable'); ?>
                    <i class="fas <?php echo $predictions[0]['predicted_price'] > $product['current_price'] ? 'fa-thumbs-down' : ($predictions[0]['predicted_price'] < $product['current_price'] ? 'fa-thumbs-up' : 'fa-minus'); ?>"></i>
                </p>
            </div>
            <?php endif; ?>
            
            <!-- Price Drop Pattern -->
            <div class="pattern-section">
                <h3>Price Drop Pattern</h3>
                <p class="pattern-text <?php echo $pattern === 'No Price Drop Pattern Detected' ? 'no-pattern' : 'has-pattern'; ?>">
                    <?php echo htmlspecialchars($pattern); ?>
                </p>
            </div>
            
            <!-- Price History Table -->
            <div class="history-table-section">
                <h3>Price History Table</h3>
                <div class="table-wrapper">
                    <table class="price-history-table" role="grid">
                        <thead>
                            <tr>
                                <th scope="col">Month/Year</th>
                                <th scope="col">Highest Price</th>
                                <th scope="col">Current Price</th>
                                <th scope="col">% Drop</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $months = array_slice(array_reverse(array_keys($priceHistory)), 0, 12);
                            foreach ($months as $month):
                                $highest = $priceHistory[$month]['highest'];
                                $current = $month === date('Y-m') ? ($isOutOfStock ? null : $product['current_price']) : null;
                                $drop = $current ? round(($highest - $current) / $highest * 100) : null;
                            ?>
                                <tr>
                                    <td><?php echo date('F Y', strtotime($month)); ?></td>
                                    <td>₹<?php echo number_format($highest, 0, '.', ','); ?></td>
                                    <td>
                                        <?php 
                                        if ($month === date('Y-m') && $isOutOfStock) {
                                            echo '<span class="no-stock">No Stock</span>';
                                        } else {
                                            echo $current ? '₹' . number_format($current, 0, '.', ',') : '-';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo $drop ? '<span class="drop-percent">' . $drop . '%</span>' : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Additional Product Info -->
            <div class="additional-info-section">
                <h3>Additional Product Info</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Brand:</span>
                        <span class="info-value"><?php echo htmlspecialchars($product['brand'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Category:</span>
                        <span class="info-value"><?php echo htmlspecialchars($product['subcategory'] ?? 'N/A'); ?></span>
                    </div>
                     <div class="info-item">
                        <span class="info-label">Rating:</span>
                        <span class="info-value"><?php echo htmlspecialchars($product['rating'] ?? 'N/A'); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Disclaimer -->
            <div class="disclaimer-section">
                <p>Disclaimer: The price history shown here is designed for user convenience to explore past pricing trends and does not predict future prices. The most recent product price is available on the merchant's website.</p>
            </div>
        </section>
        
        <!-- Related Deals Section with Modified Card Structure -->
        <section class="related-deals-section">
            <h2>Related Deals</h2>
            <div class="product-grid">
                <?php foreach ($relatedDeals as $deal):
                    $discount = round(($deal['highest_price'] - $deal['current_price']) / $deal['highest_price'] * 100);
                ?>
                    <article class="product-card">
                        <div class="product-image">
                            <img src="<?php echo htmlspecialchars($deal['image_path']); ?>" 
                                 alt="<?php echo htmlspecialchars($deal['name']); ?>" 
                                 loading="lazy">
                        </div>
                        <!-- Merchant Logo between image and title -->
                        <div class="merchant-logo-container">
                            <img src="/assets/images/logos/<?php echo htmlspecialchars($deal['merchant']); ?>.svg" 
                                 alt="<?php echo htmlspecialchars($deal['merchant']); ?> Logo" 
                                 class="merchant-logo-centered">
                        </div>
                        <h3><?php echo htmlspecialchars($deal['name']); ?></h3>
                        <p class="price">
                            ₹<?php echo number_format($deal['current_price'], 0, '.', ','); ?>
                            <s>₹<?php echo number_format($deal['highest_price'], 0, '.', ','); ?></s>
                        </p>
                        <p class="trackers">🔥 <?php echo $deal['tracker_count']; ?> users tracking</p>
                        <span class="discount-badge"><?php echo $discount; ?>% Off</span>
                        <div class="card-actions">
                            <a href="<?php echo htmlspecialchars($deal['affiliate_link']); ?>" 
                               class="btn btn-primary" 
                               target="_blank" 
                               rel="noopener"
                               aria-label="Buy <?php echo htmlspecialchars($deal['name']); ?> now">Buy Now</a>
                            <a href="<?php echo htmlspecialchars($deal['website_url']); ?>" 
                               class="btn btn-secondary" 
                               aria-label="View price history for <?php echo htmlspecialchars($deal['name']); ?>">Price History</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        
        <!-- AI Recommendations Section with Modified Card Structure -->
        <?php if (isset($_SESSION['user_id'])): ?>
            <section class="ai-recommendations-section">
                <h2>AI Recommendations</h2>
                <?php if (!empty($recommendedProducts)): ?>
                    <div class="recommendations-container">
                        <!-- First Row -->
                        <div class="recommendation-row">
                            <div class="product-carousel" id="ai-row-1">
                                <?php 
                                $firstRowProducts = array_slice($recommendedProducts, 0, 10);
                                foreach ($firstRowProducts as $product):
                                    $discount = round(($product['highest_price'] - $product['current_price']) / $product['highest_price'] * 100);
                                ?>
                                    <article class="carousel-product-card">
                                        <div class="carousel-product-image">
                                            <img src="<?php echo htmlspecialchars($product['image_path']); ?>" 
                                                 alt="<?php echo htmlspecialchars($product['name']); ?>">
                                        </div>
                                        <!-- Merchant Logo between image and title -->
                                        <div class="carousel-merchant-logo-container">
                                            <img src="/assets/images/logos/<?php echo htmlspecialchars($product['merchant']); ?>.svg" 
                                                 alt="<?php echo htmlspecialchars($product['merchant']); ?>" 
                                                 class="carousel-merchant-logo-centered">
                                        </div>
                                        <h3><?php echo htmlspecialchars(substr($product['name'], 0, 80)) . (strlen($product['name']) > 80 ? '...' : ''); ?></h3>
                                        <div class="carousel-price-section">
                                            <span class="carousel-current-price">₹<?php echo number_format($product['current_price'], 0, '.', ','); ?></span>
                                            <?php if ($product['highest_price'] > $product['current_price']): ?>
                                                <span class="carousel-original-price">₹<?php echo number_format($product['highest_price'], 0, '.', ','); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="carousel-tracker-info">
                                            <i class="fas fa-users"></i>
                                            <?php echo $product['tracker_count']; ?> tracking
                                        </div>
                                        <div class="carousel-product-actions">
                                            <a href="<?php echo htmlspecialchars($product['affiliate_link'] ?? '#'); ?>" 
                                               class="btn-small btn-primary-small" 
                                               target="_blank" 
                                               rel="noopener">
                                                <i class="fas fa-shopping-cart"></i> Buy
                                            </a>
                                            <a href="/product/<?php echo htmlspecialchars($product['merchant']); ?>/pid=<?php echo htmlspecialchars($product['asin']); ?>" 
                                               class="btn-small btn-secondary-small">
                                                <i class="fas fa-chart-line"></i> Track
                                            </a>
                                        </div>
                                        <?php if ($discount > 0): ?>
                                            <span class="carousel-discount-badge"><?php echo $discount; ?>% Off</span>
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
                        <div class="recommendation-row">
                            <div class="product-carousel" id="ai-row-2">
                                <?php 
                                $secondRowProducts = array_slice($recommendedProducts, 10, 10);
                                foreach ($secondRowProducts as $product):
                                    $discount = round(($product['highest_price'] - $product['current_price']) / $product['highest_price'] * 100);
                                ?>
                                    <article class="carousel-product-card">
                                        <div class="carousel-product-image">
                                            <img src="<?php echo htmlspecialchars($product['image_path']); ?>" 
                                                 alt="<?php echo htmlspecialchars($product['name']); ?>">
                                        </div>
                                        <!-- Merchant Logo between image and title -->
                                        <div class="carousel-merchant-logo-container">
                                            <img src="/assets/images/logos/<?php echo htmlspecialchars($product['merchant']); ?>.svg" 
                                                 alt="<?php echo htmlspecialchars($product['merchant']); ?>" 
                                                 class="carousel-merchant-logo-centered">
                                        </div>
                                        <h3><?php echo htmlspecialchars(substr($product['name'], 0, 80)) . (strlen($product['name']) > 80 ? '...' : ''); ?></h3>
                                        <div class="carousel-price-section">
                                            <span class="carousel-current-price">₹<?php echo number_format($product['current_price'], 0, '.', ','); ?></span>
                                            <?php if ($product['highest_price'] > $product['current_price']): ?>
                                                <span class="carousel-original-price">₹<?php echo number_format($product['highest_price'], 0, '.', ','); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="carousel-tracker-info">
                                            <i class="fas fa-users"></i>
                                            <?php echo $product['tracker_count']; ?> tracking
                                        </div>
                                        <div class="carousel-product-actions">
                                            <a href="<?php echo htmlspecialchars($product['affiliate_link'] ?? '#'); ?>" 
                                               class="btn-small btn-primary-small" 
                                               target="_blank" 
                                               rel="noopener">
                                                <i class="fas fa-shopping-cart"></i> Buy
                                            </a>
                                            <a href="/product/<?php echo htmlspecialchars($product['merchant']); ?>/pid=<?php echo htmlspecialchars($product['asin']); ?>" 
                                               class="btn-small btn-secondary-small">
                                                <i class="fas fa-chart-line"></i> Track
                                            </a>
                                        </div>
                                        <?php if ($discount > 0): ?>
                                            <span class="carousel-discount-badge"><?php echo $discount; ?>% Off</span>
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
            <section class="ai-recommendations-section">
                <h2>AI Recommendations</h2>
                <div class="login-prompt">
                    <h3>Login to See AI-Powered Recommendations</h3>
                    <p>Get personalized deal suggestions based on your preferences and behavior</p>
                    <a href="/user/login.php" class="btn btn-primary">Login Now</a>
                </div>
            </section>
        <?php endif; ?>
    </main>
    
    <?php include '../include/footer.php'; ?>
    
    <!-- Tooltip for price info -->
    <div id="price-tooltip" class="price-tooltip">
        <div class="price-tooltip-content"></div>
    </div>
    
    <!-- Popups -->
    <div id="login-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('login-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="favorite-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('favorite-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div class="popup-overlay" style="display: none;"></div>
    
    <!-- JavaScript Files -->
    <script src="/assets/js/main.js"></script>
    <script src="/assets/js/product.js"></script>
    
    <!-- Chart Data for JavaScript -->
    <script>
        window.chartData = {
            labels: <?php echo json_encode($chartLabels); ?>,
            highest: <?php echo json_encode($chartHighestData); ?>,
            lowest: <?php echo json_encode($chartLowestData); ?>,
            productId: '<?php echo htmlspecialchars($pid); ?>',
            isFavorite: <?php echo isset($_SESSION['user_id']) ? ($isFavorite ? 'true' : 'false') : 'false'; ?>
        };
    </script>
</body>
</html>