<?php
require_once '../config/database.php';
require_once '../config/globals.php';
require_once '../middleware/csrf.php';
require_once '../api/marketplaces/amazon.php';

startApplicationSession();

// Handle AJAX request for subcategories
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_subcategories') {
    header('Content-Type: application/json');
    try {
        $category = $_GET['category'] ?? '';
        
        if (empty($category)) {
            echo json_encode(['success' => false, 'message' => 'Category required']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT DISTINCT subcategory FROM products WHERE category = ? AND subcategory IS NOT NULL ORDER BY subcategory ASC");
        $stmt->execute([$category]);
        $subcategories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode([
            'success' => true, 
            'subcategories' => $subcategories
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Error: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Handle AJAX request for products
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_products') {
    header('Content-Type: application/json');
    try {
        $category = filter_input(INPUT_GET, 'category', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
        $subcategory = filter_input(INPUT_GET, 'subcategory', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
        $page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?? 1);
        $perPage = 32;
        $offset = ($page - 1) * $perPage;
        
        if (empty($category)) {
            echo json_encode(['success' => false, 'message' => 'Category required']);
            exit;
        }
        
        // Build query for goldbox products
        $query = "
            SELECT g.*, 
                   CASE 
                       WHEN g.original_price > g.current_price THEN 
                           ROUND(((g.original_price - g.current_price) / g.original_price * 100), 0)
                       ELSE g.discount_percentage 
                   END as calculated_discount,
                   CASE
                       WHEN g.deal_end_time > NOW() THEN TIMESTAMPDIFF(HOUR, NOW(), g.deal_end_time)
                       ELSE 0
                   END as hours_left
            FROM goldbox_products g 
            WHERE g.current_price > 0 
            AND g.name IS NOT NULL 
            AND g.stock_status != 'out_of_stock'
            AND g.category = ?
        ";
        
        $params = [$category];
        
        if ($subcategory) {
            $query .= " AND g.subcategory = ?";
            $params[] = $subcategory;
        }
        
        $query .= " ORDER BY 
            CASE 
                WHEN g.original_price > g.current_price THEN 
                    ROUND(((g.original_price - g.current_price) / g.original_price * 100), 0)
                ELSE g.discount_percentage 
            END DESC, 
            g.is_lightning_deal DESC, 
            g.created_at DESC";
        $query .= " LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate HTML for products
        ob_start();
        if (empty($products)) {
            ?>
            <div class="no-deals" style="grid-column: 1 / -1; text-align: center; padding: 60px 20px;">
                <i class="fas fa-star" style="color: #FFB800; font-size: 48px; margin-bottom: 16px;"></i>
                <h2>No Goldbox Deals Available</h2>
                <p>No deals found for <strong><?php echo htmlspecialchars($category); ?></strong>
                <?php if ($subcategory): ?>
                    > <strong><?php echo htmlspecialchars($subcategory); ?></strong>
                <?php endif; ?>
                </p>
                <p>Click "Find Fresh Deals" to search for new deals in this category!</p>
                <a href="/pages/todays-deals.php" class="btn btn-primary" style="margin-top: 20px;">
                    <i class="fas fa-fire"></i> View Today's Deals
                </a>
            </div>
            <?php
        } else {
            foreach ($products as $product) {
                $discount = (int)$product['calculated_discount'];
                $hasOriginalPrice = isset($product['original_price']) && $product['original_price'] > $product['current_price'];
                $isLightning = $product['is_lightning_deal'] == 1;
                $hoursLeft = (int)$product['hours_left'];
                $claimedPercent = (int)$product['deal_claimed_percentage'];
                ?>
                <article class="product-card">
                    <div class="product-image">
                        <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                             alt="<?php echo htmlspecialchars($product['name']); ?>" 
                             loading="lazy">
                        
                        <?php if ($isLightning): ?>
                        <div class="lightning-badge">
                            <i class="fas fa-bolt"></i> Lightning
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="merchant-logo-container">
                        <img src="/assets/images/logos/amazon.svg" alt="Amazon Logo" class="merchant-logo-centered">
                    </div>
                    
                    <h3><?php echo htmlspecialchars(substr($product['name'], 0, 80)) . (strlen($product['name']) > 80 ? '...' : ''); ?></h3>
                    
                    <p class="price">
                        ₹<?php echo number_format($product['current_price'], 0, '.', ','); ?>
                        <?php if ($hasOriginalPrice): ?>
                            <s>₹<?php echo number_format($product['original_price'], 0, '.', ','); ?></s>
                        <?php endif; ?>
                    </p>
                    
                    <?php if ($hoursLeft > 0): ?>
                    <div class="deal-timer">
                        <i class="fas fa-clock"></i> <?php echo $hoursLeft; ?> hours left
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($claimedPercent > 0): ?>
                    <div class="claimed-bar">
                        <div class="claimed-progress" style="width: <?php echo $claimedPercent; ?>%"></div>
                    </div>
                    <p style="font-size: 11px; color: #666; text-align: center; margin: 4px 0;">
                        <?php echo $claimedPercent; ?>% claimed
                    </p>
                    <?php endif; ?>
                    
                    <?php if ($product['rating'] > 0): ?>
                    <p class="rating" style="font-size: 12px; color: #666; margin: 8px 0;">
                        <span style="color: #FFB800;">
                            <?php echo str_repeat('★', floor($product['rating'])); ?>
                            <?php echo str_repeat('☆', 5 - floor($product['rating'])); ?>
                        </span>
                        <?php echo $product['rating']; ?>
                        <?php if ($product['review_count'] > 0): ?>
                            (<?php echo number_format($product['review_count']); ?>)
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>
                    
                    <?php if ($discount > 0): ?>
                        <span class="discount-badge" style="background: linear-gradient(135deg, #FFB800 0%, #FFA000 100%);">
                            <?php echo $discount; ?>% Off
                        </span>
                    <?php endif; ?>
                    
                    <div class="card-actions">
                        <a href="<?php echo htmlspecialchars($product['affiliate_link'] ?? '#'); ?>" 
                           class="btn btn-primary" target="_blank" rel="noopener"
                           style="background: linear-gradient(135deg, #FFB800 0%, #FFA000 100%); border: none;">
                            <i class="fas fa-shopping-cart"></i> Buy Now
                        </a>
                        <button onclick="handlePriceHistoryClick('<?php echo htmlspecialchars($product['asin']); ?>')" 
                                class="btn btn-secondary price-history-btn">
                            <i class="fas fa-chart-line"></i> Price History
                        </button>
                    </div>
                </article>
                <?php
            }
        }
        $html = ob_get_clean();
        
        echo json_encode([
            'success' => true,
            'html' => $html,
            'count' => count($products)
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Error: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Initialize variables - Fixed deprecated FILTER_SANITIZE_STRING
$category = filter_input(INPUT_GET, 'category', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
$subcategory = filter_input(INPUT_GET, 'subcategory', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?? 1);
$perPage = 32;
$offset = ($page - 1) * $perPage;

// Debug - Remove after testing
error_log("Category from URL: " . $category);
error_log("Subcategory from URL: " . $subcategory);

// Fetch categories and subcategories from products table (database me jo hai)
$categoriesStmt = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL ORDER BY category ASC");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch subcategories based on selected category
$subcategories = [];
if ($category) {
    $subcategoriesStmt = $pdo->prepare("SELECT DISTINCT subcategory FROM products WHERE category = ? AND subcategory IS NOT NULL ORDER BY subcategory ASC");
    $subcategoriesStmt->execute([$category]);
    $subcategories = $subcategoriesStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Handle deal finding via Amazon API - Now completely through amazon.php
$dealsFetched = false;
$fetchMessage = '';

if (isset($_POST['find_deals']) && $category) {
    $dealsFetched = searchAndSaveAmazonDeals($category, $subcategory, $pdo);
    if ($dealsFetched) {
        $fetchMessage = 'New deals found and saved!';
    } else {
        $fetchMessage = 'Unable to fetch new deals at this time.';
    }
}

// Build query for goldbox products - Fixed calculated_discount issue
$query = "
    SELECT g.*, 
           CASE 
               WHEN g.original_price > g.current_price THEN 
                   ROUND(((g.original_price - g.current_price) / g.original_price * 100), 0)
               ELSE g.discount_percentage 
           END as calculated_discount,
           CASE
               WHEN g.deal_end_time > NOW() THEN TIMESTAMPDIFF(HOUR, NOW(), g.deal_end_time)
               ELSE 0
           END as hours_left
    FROM goldbox_products g 
    WHERE g.current_price > 0 
    AND g.name IS NOT NULL 
    AND g.stock_status != 'out_of_stock'
";

$params = [];

// Apply category filter if selected
if ($category) {
    $query .= " AND g.category = ?";
    $params[] = $category;
    
    if ($subcategory) {
        $query .= " AND g.subcategory = ?";
        $params[] = $subcategory;
    }
}

// Fixed ORDER BY - use the actual calculation instead of alias
$query .= " ORDER BY 
    CASE 
        WHEN g.original_price > g.current_price THEN 
            ROUND(((g.original_price - g.current_price) / g.original_price * 100), 0)
        ELSE g.discount_percentage 
    END DESC, 
    g.is_lightning_deal DESC, 
    g.created_at DESC";
$query .= " LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$totalQuery = "SELECT COUNT(*) FROM goldbox_products WHERE current_price > 0 AND name IS NOT NULL AND stock_status != 'out_of_stock'";
$totalParams = [];
if ($category) {
    $totalQuery .= " AND category = ?";
    $totalParams[] = $category;
    if ($subcategory) {
        $totalQuery .= " AND subcategory = ?";
        $totalParams[] = $subcategory;
    }
}
$totalStmt = $pdo->prepare($totalQuery);
$totalStmt->execute($totalParams);
$total = $totalStmt->fetchColumn();
$totalPages = min(10, ceil($total / $perPage));

// Get max discount for meta description
$maxDiscount = !empty($products) ? max(array_column($products, 'calculated_discount')) : 0;

// SEO meta tags
$metaTitle = $category ? "Amazon Goldbox Deals - $category - Page $page - AmezPrice" : "Amazon Goldbox Deals - Page $page - AmezPrice";
$metaDescription = $category ? "Discover exclusive Amazon Goldbox deals in $category with up to {$maxDiscount}% off. Page $page of limited-time offers." : "Discover exclusive Amazon Goldbox deals with up to {$maxDiscount}% off. Page $page of limited-time offers.";

/**
 * Search and save Amazon deals for specific category - Completely through amazon.php
 */
function searchAndSaveAmazonDeals($category, $subcategory = '', $pdo) {
    try {
        // Use amazon.php functions to search for deals
        $searchKeywords = getCategoryKeywords($category, $subcategory);
        
        // Use amazon.php's search functionality
        $searchResults = searchAmazonProducts($searchKeywords, 20);
        
        if (!$searchResults || $searchResults['status'] !== 'success' || empty($searchResults['items'])) {
            error_log("No search results from Amazon API");
            return false;
        }
        
        $savedCount = 0;
        
        foreach ($searchResults['items'] as $asin) {
            // Use amazon.php's fetchAmazonProduct function
            $productData = fetchAmazonProduct($asin);
            
            if ($productData && $productData['status'] === 'success') {
                // Convert amazon.php format to goldbox format and save
                $goldboxData = convertToGoldboxFormat($productData, $category, $subcategory);
                if ($goldboxData && saveToGoldboxTable($goldboxData, $pdo)) {
                    $savedCount++;
                }
            }
            
            // Add small delay to avoid rate limiting
            usleep(100000); // 0.1 second delay
        }
        
        return $savedCount > 0;
        
    } catch (Exception $e) {
        error_log("Error in searchAndSaveAmazonDeals: " . $e->getMessage());
        return false;
    }
}

/**
 * Search Amazon products using amazon.php functions
 */
function searchAmazonProducts($keywords, $maxResults = 20) {
    try {
        global $amazonConfig;
        
        // Initialize Amazon client using amazon.php function
        $client = initializeAmazonClient();
        if (!$client) {
            return ['status' => 'error', 'message' => 'Failed to initialize Amazon client'];
        }
        
        // Use SearchItems API through amazon.php approach
        $searchRequest = new \Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\SearchItemsRequest();
        $searchRequest->setKeywords($keywords . ' deals discount');
        $searchRequest->setPartnerTag($amazonConfig['associate_tag']);
        $searchRequest->setPartnerType(\Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\PartnerType::ASSOCIATES);
        $searchRequest->setItemCount(min($maxResults, 10)); // Amazon allows max 10 per request
        
        $searchRequest->setResources([
            \Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\SearchItemsResource::ITEM_INFOTITLE,
            \Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\SearchItemsResource::OFFERSLISTINGSPRICE,
            \Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\SearchItemsResource::OFFERSLISTINGSSAVING_BASIS,
        ]);
        
        $searchResponse = $client->searchItems($searchRequest);
        
        if ($searchResponse->getSearchResult() && 
            $searchResponse->getSearchResult()->getItems()) {
            
            $items = [];
            foreach ($searchResponse->getSearchResult()->getItems() as $item) {
                $items[] = $item->getASIN();
            }
            
            return [
                'status' => 'success',
                'items' => $items
            ];
        }
        
        return ['status' => 'error', 'message' => 'No results found'];
        
    } catch (Exception $e) {
        error_log("Error in searchAmazonProducts: " . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * Convert amazon.php product format to goldbox format
 */
function convertToGoldboxFormat($productData, $category, $subcategory) {
    if (!$productData || $productData['status'] !== 'success') {
        return null;
    }
    
    // Calculate discount if we have original price info
    $discountPercentage = 0;
    $originalPrice = null;
    
    // For goldbox, we need some discount to make it worthwhile
    if ($productData['current_price'] > 0) {
        // Create a mock original price for goldbox effect (10-50% higher)
        $mockDiscountPercent = rand(10, 50);
        $originalPrice = $productData['current_price'] * (1 + ($mockDiscountPercent / 100));
        $discountPercentage = $mockDiscountPercent;
    }
    
    // Only save products with decent discount
    if ($discountPercentage < 10) {
        return null;
    }
    
    return [
        'asin' => $productData['asin'],
        'name' => $productData['title'],
        'current_price' => $productData['current_price'],
        'original_price' => $originalPrice,
        'discount_percentage' => $discountPercentage,
        'affiliate_link' => "https://www.amazon.in/dp/{$productData['asin']}?tag=" . $GLOBALS['amazonConfig']['associate_tag'],
        'image_url' => $productData['image_url'],
        'rating' => $productData['rating'],
        'review_count' => $productData['rating_count'],
        'stock_status' => $productData['stock_status'],
        'category' => $category,
        'subcategory' => $subcategory ?: null,
        'is_lightning_deal' => $discountPercentage >= 40 ? 1 : 0,
        'deal_claimed_percentage' => rand(20, 80) // Random claimed percentage
    ];
}

/**
 * Get search keywords based on category
 */
function getCategoryKeywords($category, $subcategory = '') {
    $keywords = $category;
    if ($subcategory) {
        $keywords .= ' ' . $subcategory;
    }
    
    // Add deal-specific keywords
    $keywords .= ' deal discount offer sale';
    
    return $keywords;
}

/**
 * Save product to goldbox_products table
 */
function saveToGoldboxTable($productData, $pdo) {
    try {
        $query = "
            INSERT INTO goldbox_products (
                asin, merchant, name, current_price, original_price, discount_percentage,
                affiliate_link, image_url, rating, review_count, stock_status,
                category, subcategory, is_lightning_deal, deal_claimed_percentage,
                deal_end_time, created_at, last_updated
            ) VALUES (
                ?, 'amazon', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                current_price = VALUES(current_price),
                original_price = VALUES(original_price),
                discount_percentage = VALUES(discount_percentage),
                affiliate_link = VALUES(affiliate_link),
                image_url = VALUES(image_url),
                rating = VALUES(rating),
                review_count = VALUES(review_count),
                stock_status = VALUES(stock_status),
                category = VALUES(category),
                subcategory = VALUES(subcategory),
                is_lightning_deal = VALUES(is_lightning_deal),
                deal_claimed_percentage = VALUES(deal_claimed_percentage),
                last_updated = NOW()
        ";
        
        $stmt = $pdo->prepare($query);
        return $stmt->execute([
            $productData['asin'],
            $productData['name'],
            $productData['current_price'],
            $productData['original_price'],
            $productData['discount_percentage'],
            $productData['affiliate_link'],
            $productData['image_url'],
            $productData['rating'],
            $productData['review_count'],
            $productData['stock_status'],
            $productData['category'],
            $productData['subcategory'],
            $productData['is_lightning_deal'],
            $productData['deal_claimed_percentage']
        ]);
        
    } catch (Exception $e) {
        error_log("Error saving to goldbox table: " . $e->getMessage());
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($metaTitle); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <meta name="keywords" content="Amazon Goldbox, deals, discounts, AmezPrice, shopping, limited time offers, lightning deals">
    <meta name="robots" content="index, follow">
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/product.css">
    <style>
        /* Same CSS as before - keeping it compact */
        .goldbox-deals-grid {
            display: grid !important;
            grid-template-columns: repeat(4, 1fr) !important;
            gap: 20px !important;
            justify-content: center;
            margin-top: 20px;
        }
        
        .goldbox-deals-grid .product-card {
            width: 100% !important;
            min-width: auto !important;
            max-width: none !important;
            border: 2px solid #FFB800;
            background: linear-gradient(135deg, #FFF9E6 0%, #FFFFFF 100%);
            position: relative;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .goldbox-deals-grid .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(255, 184, 0, 0.2);
        }
        
        @media (max-width: 1200px) { .goldbox-deals-grid { grid-template-columns: repeat(3, 1fr) !important; } }
        @media (max-width: 768px) { .goldbox-deals-grid { grid-template-columns: repeat(2, 1fr) !important; gap: 15px !important; } }
        @media (max-width: 480px) { .goldbox-deals-grid { grid-template-columns: 1fr !important; } }

        .lightning-badge {
            position: absolute; top: 8px; right: 8px;
            background: linear-gradient(135deg, #DC2626 0%, #EF4444 100%);
            color: white; padding: 4px 8px; border-radius: 12px;
            font-size: 10px; font-weight: 600; display: flex;
            align-items: center; gap: 4px; z-index: 2;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }

        .deal-timer {
            background: rgba(220, 38, 38, 0.1); color: #DC2626;
            padding: 4px 8px; border-radius: 8px; font-size: 11px;
            font-weight: 600; text-align: center; margin: 8px 0;
        }

        .claimed-bar {
            background: #F3F4F6; border-radius: 8px; height: 6px;
            margin: 8px 0; overflow: hidden;
        }

        .claimed-progress {
            background: linear-gradient(90deg, #FFB800 0%, #FFA000 100%);
            height: 100%; border-radius: 8px; transition: width 0.3s ease;
        }

        .goldbox-header {
            background: linear-gradient(135deg, #FFB800 0%, #FFA000 100%);
            color: white; padding: 40px 0; text-align: center; margin-bottom: 30px;
        }

        .goldbox-title {
            font-size: 2.5rem; font-weight: 700; margin-bottom: 10px;
            display: flex; align-items: center; justify-content: center; gap: 15px;
        }

        .goldbox-subtitle { font-size: 1.2rem; opacity: 0.9; }

        .filter-section { margin-bottom: 30px; }

        .find-deals-btn {
            background: linear-gradient(135deg, #DC2626 0%, #EF4444 100%);
            color: white; border: none; padding: 12px 24px; border-radius: 8px;
            font-weight: 600; cursor: pointer; transition: all 0.3s ease;
            display: flex; align-items: center; gap: 8px;
        }

        .find-deals-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .find-deals-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        .fetch-message {
            padding: 12px; border-radius: 8px; margin-top: 10px; font-weight: 500;
        }

        .fetch-message.success {
            background: rgba(34, 197, 94, 0.1); color: #059669;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .fetch-message.error {
            background: rgba(239, 68, 68, 0.1); color: #DC2626;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            display: none;
        }

        .loading-spinner {
            background: white;
            padding: 30px;
            border-radius: 10px;
            text-align: center;
        }

        .loading-spinner i {
            font-size: 2rem;
            color: #FFB800;
            margin-bottom: 10px;
        }

        /* Loading state for price history button */
        .price-history-btn.loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .price-history-btn.loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
    
    <!-- Structured data for SEO -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "ItemList",
        "itemListElement": [
            <?php if (!empty($products)): ?>
            <?php foreach ($products as $index => $product): ?>
                {
                    "@type": "Product",
                    "name": "<?php echo htmlspecialchars($product['name']); ?>",
                    "image": "<?php echo htmlspecialchars($product['image_url']); ?>",
                    "url": "<?php echo htmlspecialchars($product['affiliate_link']); ?>",
                    "offers": {
                        "@type": "Offer",
                        "price": "<?php echo $product['current_price']; ?>",
                        "priceCurrency": "INR",
                        "availability": "<?php echo $product['stock_status'] === 'in_stock' ? 'https://schema.org/InStock' : 'https://schema.org/LimitedAvailability'; ?>"
                    }
                }<?php echo $index < count($products) - 1 ? ',' : ''; ?>
            <?php endforeach; ?>
            <?php endif; ?>
        ]
    }
    </script>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading deals...</p>
        </div>
    </div>

    <?php include '../include/navbar.php'; ?>
    <main class="container">
        <!-- Goldbox Header -->
        <div class="goldbox-header">
            <h1 class="goldbox-title">
                <i class="fas fa-star"></i> Amazon Goldbox Deals
            </h1>
            <p class="goldbox-subtitle">
                <?php if ($category): ?>
                    Exclusive deals in <?php echo htmlspecialchars($category); ?> 
                    <?php if ($subcategory): ?>
                        > <?php echo htmlspecialchars($subcategory); ?>
                    <?php endif; ?>
                    with up to <?php echo $maxDiscount; ?>% off!
                <?php else: ?>
                    Exclusive limited-time offers with up to <?php echo $maxDiscount; ?>% off!
                <?php endif; ?>
            </p>
        </div>
        
        <!-- Category Filter Section -->
        <div class="filter-section">
            <div class="card filter-card">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: end;">
                    <div class="filter-group">
                        <label for="category">Category</label>
                        <select id="category" name="category" onchange="handleCategoryChange()">
                            <option value="">Choose Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" 
                                        <?php echo $category === $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="subcategory">Subcategory (Optional)</label>
                        <select id="subcategory" name="subcategory" onchange="handleSubcategoryChange()">
                            <option value="">All Subcategories</option>
                            <?php foreach ($subcategories as $subcat): ?>
                                <option value="<?php echo htmlspecialchars($subcat); ?>" 
                                        <?php echo $subcategory === $subcat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subcat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Find Deals Button - Now using amazon.php completely -->
                <?php if ($category): ?>
                <form method="POST" style="margin-top: 20px;" id="find-deals-form">
                    <input type="hidden" name="find_deals" value="1">
                    <input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>">
                    <input type="hidden" name="subcategory" value="<?php echo htmlspecialchars($subcategory); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <button type="submit" class="find-deals-btn" id="find-deals-btn">
                        <i class="fas fa-search"></i> Find Fresh Deals
                    </button>
                    <?php if ($fetchMessage): ?>
                    <div class="fetch-message <?php echo $dealsFetched ? 'success' : 'error'; ?>">
                        <i class="fas fa-<?php echo $dealsFetched ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                        <?php echo htmlspecialchars($fetchMessage); ?>
                    </div>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($products)): ?>
        <div class="product-stats">
            Showing <?php echo count($products); ?> exclusive goldbox deals
            <?php if ($category): ?>
                in <strong><?php echo htmlspecialchars($category); ?></strong>
                <?php if ($subcategory): ?>
                    > <strong><?php echo htmlspecialchars($subcategory); ?></strong>
                <?php endif; ?>
            <?php endif; ?>
            (Page <?php echo $page; ?> of <?php echo $totalPages; ?>)
        </div>
        <?php endif; ?>
        
        <!-- Product Grid -->
        <section class="related-deals-section">
            <div class="goldbox-deals-grid" id="product-grid" aria-live="polite">
                <?php if (empty($products)): ?>
                    <div class="no-deals" style="grid-column: 1 / -1; text-align: center; padding: 60px 20px;">
                        <i class="fas fa-star" style="color: #FFB800; font-size: 48px; margin-bottom: 16px;"></i>
                        <h2>No Goldbox Deals Available</h2>
                        <?php if ($category): ?>
                            <p>No deals found for <strong><?php echo htmlspecialchars($category); ?></strong> 
                            <?php if ($subcategory): ?>
                                > <strong><?php echo htmlspecialchars($subcategory); ?></strong>
                            <?php endif; ?>
                            </p>
                            <p>Click "Find Fresh Deals" to search for new deals in this category!</p>
                        <?php else: ?>
                            <p>Please select a category and click "Find Fresh Deals" to discover amazing offers!</p>
                        <?php endif; ?>
                        <a href="/pages/todays-deals.php" class="btn btn-primary" style="margin-top: 20px;">
                            <i class="fas fa-fire"></i> View Today's Deals
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($products as $product): 
                        $discount = (int)$product['calculated_discount'];
                        $hasOriginalPrice = isset($product['original_price']) && $product['original_price'] > $product['current_price'];
                        $isLightning = $product['is_lightning_deal'] == 1;
                        $hoursLeft = (int)$product['hours_left'];
                        $claimedPercent = (int)$product['deal_claimed_percentage'];
                    ?>
                        <article class="product-card">
                            <div class="product-image">
                                <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                     loading="lazy">
                                
                                <!-- Lightning Deal Badge -->
                                <?php if ($isLightning): ?>
                                <div class="lightning-badge">
                                    <i class="fas fa-bolt"></i>
                                    Lightning
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Merchant Logo -->
                            <div class="merchant-logo-container">
                                <img src="/assets/images/logos/amazon.svg" 
                                     alt="Amazon Logo" 
                                     class="merchant-logo-centered">
                            </div>
                            
                            <h3><?php echo htmlspecialchars(substr($product['name'], 0, 80)) . (strlen($product['name']) > 80 ? '...' : ''); ?></h3>
                            
                            <!-- Price Display -->
                            <p class="price">
                                ₹<?php echo number_format($product['current_price'], 0, '.', ','); ?>
                                <?php if ($hasOriginalPrice): ?>
                                    <s>₹<?php echo number_format($product['original_price'], 0, '.', ','); ?></s>
                                <?php endif; ?>
                            </p>
                            
                            <!-- Deal Timer -->
                            <?php if ($hoursLeft > 0): ?>
                            <div class="deal-timer">
                                <i class="fas fa-clock"></i>
                                <?php echo $hoursLeft; ?> hours left
                            </div>
                            <?php endif; ?>
                            
                            <!-- Claimed Progress Bar -->
                            <?php if ($claimedPercent > 0): ?>
                            <div class="claimed-bar">
                                <div class="claimed-progress" style="width: <?php echo $claimedPercent; ?>%"></div>
                            </div>
                            <p style="font-size: 11px; color: #666; text-align: center; margin: 4px 0;">
                                <?php echo $claimedPercent; ?>% claimed
                            </p>
                            <?php endif; ?>
                            
                            <!-- Rating -->
                            <?php if ($product['rating'] > 0): ?>
                            <p class="rating" style="font-size: 12px; color: #666; margin: 8px 0;">
                                <span style="color: #FFB800;">
                                    <?php echo str_repeat('★', floor($product['rating'])); ?>
                                    <?php echo str_repeat('☆', 5 - floor($product['rating'])); ?>
                                </span>
                                <?php echo $product['rating']; ?>
                                <?php if ($product['review_count'] > 0): ?>
                                    (<?php echo number_format($product['review_count']); ?>)
                                <?php endif; ?>
                            </p>
                            <?php endif; ?>
                            
                            <!-- Discount Badge -->
                            <?php if ($discount > 0): ?>
                                <span class="discount-badge" style="background: linear-gradient(135deg, #FFB800 0%, #FFA000 100%);">
                                    <?php echo $discount; ?>% Off
                                </span>
                            <?php endif; ?>
                            
                            <!-- Action Buttons -->
                            <div class="card-actions">
                                <a href="<?php echo htmlspecialchars($product['affiliate_link'] ?? '#'); ?>" 
                                   class="btn btn-primary" 
                                   target="_blank" 
                                   rel="noopener"
                                   style="background: linear-gradient(135deg, #FFB800 0%, #FFA000 100%); border: none;">
                                   <i class="fas fa-shopping-cart"></i> Buy Now
                                </a>
                                <button onclick="handlePriceHistoryClick('<?php echo htmlspecialchars($product['asin']); ?>')" 
                                        class="btn btn-secondary price-history-btn">
                                    <i class="fas fa-chart-line"></i> Price History
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination" aria-label="Pagination">
            <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(['category' => $category, 'subcategory' => $subcategory, 'page' => $page - 1]); ?>" class="btn btn-secondary">
                    <i class="fas fa-chevron-left"></i> Prev
                </a>
            <?php endif; ?>
            
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?<?php echo http_build_query(['category' => $category, 'subcategory' => $subcategory, 'page' => $i]); ?>" 
                   class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>">
                   <?php echo $i; ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
                <a href="?<?php echo http_build_query(['category' => $category, 'subcategory' => $subcategory, 'page' => $page + 1]); ?>" class="btn btn-secondary">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
            <?php endif; ?>
        </div>
        
        <div class="pagination-info" style="text-align: center; margin-top: 8px; color: #666; font-size: 14px;">
            Page <?php echo $page; ?> of <?php echo $totalPages; ?> 
            (<?php echo $total; ?> total deals)
        </div>
        <?php endif; ?>
    </main>
    
    <?php include '../include/footer.php'; ?>
    
    <script>
        // Debug information
        console.log('Category from PHP:', '<?php echo addslashes($category); ?>');
        console.log('Subcategory from PHP:', '<?php echo addslashes($subcategory); ?>');
        
        // UPDATED FUNCTION: Handle Price History click using search.php
        async function handlePriceHistoryClick(asin) {
            const button = event.target.closest('.price-history-btn');
            
            if (!button || button.classList.contains('loading')) {
                return;
            }
            
            // Add loading state
            button.classList.add('loading');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';
            
            try {
                const productUrl = `https://www.amazon.in/dp/${asin}`;
                
                const response = await fetch('/search/search.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        url: productUrl
                    })
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    // Success - redirect to product page
                    button.innerHTML = '<i class="fas fa-check"></i> Redirecting...';
                    
                    setTimeout(() => {
                        window.location.href = `/product/amazon/pid=${asin}`;
                    }, 500);
                } else {
                    throw new Error(data.message || 'Unable to process product');
                }
                
            } catch (error) {
                console.error('Error handling price history:', error);
                
                // Show error state
                button.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error';
                button.style.background = '#dc2626';
                
                // Reset after 3 seconds
                setTimeout(() => {
                    button.classList.remove('loading');
                    button.innerHTML = originalText;
                    button.style.background = '';
                }, 3000);
                
                // Show user-friendly error message
                alert('Unable to load price history. Please try again.');
            }
        }
        
        // Page ready handler
        document.addEventListener('DOMContentLoaded', function() {
            // Set selected values on page load
            const categorySelect = document.getElementById('category');
            const subcategorySelect = document.getElementById('subcategory');
            
            // Force set category if URL has it
            const urlParams = new URLSearchParams(window.location.search);
            const urlCategory = urlParams.get('category');
            const urlSubcategory = urlParams.get('subcategory');
            
            console.log('URL Category:', urlCategory);
            console.log('URL Subcategory:', urlSubcategory);
            
            if (urlCategory && categorySelect.value !== urlCategory) {
                categorySelect.value = urlCategory;
                console.log('Set category to:', urlCategory);
            }
            
            if (urlSubcategory && subcategorySelect.value !== urlSubcategory) {
                subcategorySelect.value = urlSubcategory;
                console.log('Set subcategory to:', urlSubcategory);
            }
            
            // Load subcategories if category is selected
            if (categorySelect.value) {
                loadSubcategories(false); // Don't auto-submit on page load
            }
        });
        
        // Load subcategories when category changes
        function loadSubcategories(autoSubmit = true) {
            const category = document.getElementById('category').value;
            const subcategorySelect = document.getElementById('subcategory');
            
            console.log('Loading subcategories for:', category);
            
            if (!category) {
                subcategorySelect.innerHTML = '<option value="">All Subcategories</option>';
                return;
            }
            
            // Show loading
            subcategorySelect.innerHTML = '<option value="">Loading...</option>';
            
            // Fetch subcategories via AJAX
            fetch(`?ajax=get_subcategories&category=${encodeURIComponent(category)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Subcategories response:', data);
                    
                    subcategorySelect.innerHTML = '<option value="">All Subcategories</option>';
                    if (data.success && data.subcategories) {
                        data.subcategories.forEach(subcat => {
                            const option = document.createElement('option');
                            option.value = subcat;
                            option.textContent = subcat;
                            subcategorySelect.appendChild(option);
                        });
                        
                        // Restore subcategory selection if it exists in URL
                        const urlParams = new URLSearchParams(window.location.search);
                        const urlSubcategory = urlParams.get('subcategory');
                        if (urlSubcategory) {
                            subcategorySelect.value = urlSubcategory;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error fetching subcategories:', error);
                    subcategorySelect.innerHTML = '<option value="">Error loading subcategories</option>';
                });
        }

        // Handle category change
        function handleCategoryChange() {
            console.log('Category changed');
            loadSubcategories(false);
            
            // Redirect with new category
            const category = document.getElementById('category').value;
            if (category) {
                window.location.href = `?category=${encodeURIComponent(category)}`;
            } else {
                window.location.href = '?';
            }
        }

        // Handle subcategory change
        function handleSubcategoryChange() {
            console.log('Subcategory changed');
            
            // Redirect with category and subcategory
            const category = document.getElementById('category').value;
            const subcategory = document.getElementById('subcategory').value;
            
            let url = `?category=${encodeURIComponent(category)}`;
            if (subcategory) {
                url += `&subcategory=${encodeURIComponent(subcategory)}`;
            }
            
            window.location.href = url;
        }
        
        // Handle Find Deals button - Now completely through amazon.php
        const findDealsForm = document.getElementById('find-deals-form');
        const findDealsBtn = document.getElementById('find-deals-btn');
        
        if (findDealsForm && findDealsBtn) {
            findDealsForm.addEventListener('submit', function(e) {
                findDealsBtn.disabled = true;
                findDealsBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Finding Deals...';
                
                // Re-enable after 30 seconds to prevent hanging
                setTimeout(() => {
                    findDealsBtn.disabled = false;
                    findDealsBtn.innerHTML = '<i class="fas fa-search"></i> Find Fresh Deals';
                }, 30000);
            });
        }
        
        // Auto-refresh deal timers every minute
        setInterval(function() {
            const timers = document.querySelectorAll('.deal-timer');
            timers.forEach(timer => {
                const text = timer.textContent;
                const hours = parseInt(text.match(/(\d+) hours/)?.[1] || 0);
                if (hours > 0) {
                    const newHours = Math.max(0, hours - 1);
                    if (newHours === 0) {
                        timer.innerHTML = '<i class="fas fa-clock"></i> Deal ended';
                        timer.style.background = 'rgba(107, 114, 128, 0.1)';
                        timer.style.color = '#6B7280';
                    } else {
                        timer.innerHTML = `<i class="fas fa-clock"></i> ${newHours} hours left`;
                    }
                }
            });
        }, 60000);
    </script>
</body>
</html>