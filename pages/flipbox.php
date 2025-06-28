<?php
require_once '../config/database.php';
require_once '../config/marketplaces.php';
require_once '../config/fontawesome.php';
require_once '../middleware/csrf.php';

startApplicationSession();

if ($marketplaces['flipkart'] !== 'active') {
    header('Location: /');
    exit;
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 32;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT * FROM flipbox_products ORDER BY discount_percentage DESC LIMIT ? OFFSET ?");
$stmt->execute([$perPage, $offset]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalStmt = $pdo->query("SELECT COUNT(*) FROM flipbox_products");
$total = $totalStmt->fetchColumn();
$totalPages = ceil($total / $perPage);

$maxDiscount = !empty($products) ? max(array_column($products, 'discount_percentage')) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Discover the best Flipkart Flipbox deals with up to <?php echo $maxDiscount; ?>% off. Page <?php echo $page; ?> of <?php echo $totalPages; ?> curated deals.">
    <meta name="keywords" content="Flipkart Flipbox, deals, discounts, AmezPrice, shopping, best offers">
    <title>Flipkart Flipbox Deals - Page <?php echo $page; ?> - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/main.css">
    <script src="https://kit.fontawesome.com/<?php echo htmlspecialchars($fontawesomeConfig['kit_id']); ?>.js" crossorigin="anonymous"></script>
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <main class="container">
        <div class="deals-header">
            <h1 class="deals-title">
                <i class="fas fa-gift flipbox-icon"></i> Flipkart Flipbox Deals
            </h1>
            <p class="deals-subtitle">Best Flipkart deals curated just for you - Up to <?php echo $maxDiscount; ?>% off!</p>
        </div>
        
        <?php if (empty($products)): ?>
            <div class="no-deals flipbox-no-deals">
                <i class="fas fa-gift" aria-hidden="true"></i>
                <h2>No Flipbox Deals Available</h2>
                <p>Check back later for amazing Flipkart Flipbox deals!</p>
                <a href="/" class="btn btn-primary flipbox-btn">
                    <i class="fas fa-home"></i> Back to Home
                </a>
            </div>
        <?php else: ?>
            <div class="product-grid enhanced-grid flipbox-grid">
                <?php foreach ($products as $product): ?>
                    <div class="product-card flipbox-card" aria-label="Product: <?php echo htmlspecialchars($product['name']); ?>">
                        <div class="product-image-container">
                            <img src="<?php echo htmlspecialchars($product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy">
                            <div class="flipbox-badge">
                                <i class="fas fa-gift"></i>
                                Flipbox
                            </div>
                            <?php if ($product['discount_percentage'] > 0): ?>
                                <span class="discount-badge flipkart-discount"><?php echo round($product['discount_percentage']); ?>% Off</span>
                            <?php endif; ?>
                            <div class="trending-badge">
                                <i class="fas fa-trending-up"></i>
                                Trending
                            </div>
                        </div>
                        <div class="product-content">
                            <h3><?php echo htmlspecialchars(substr($product['name'], 0, 70)) . (strlen($product['name']) > 70 ? '...' : ''); ?></h3>
                            <div class="product-price">
                                <span class="current-price">₹<?php echo number_format($product['current_price'], 0, '.', ','); ?></span>
                                <?php if (isset($product['original_price']) && $product['original_price'] > $product['current_price']): ?>
                                    <span class="original-price">₹<?php echo number_format($product['original_price'], 0, '.', ','); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="flipbox-features">
                                <span class="feature-badge flipkart-feature">
                                    <i class="fas fa-shipping-fast"></i> Free Delivery
                                </span>
                                <span class="feature-badge flipkart-feature">
                                    <i class="fas fa-medal"></i> Flipkart Assured
                                </span>
                            </div>
                        </div>
                        <div class="product-actions">
                            <a href="<?php echo htmlspecialchars($product['affiliate_link']); ?>" class="btn btn-primary flipbox-btn" target="_blank" aria-label="Buy <?php echo htmlspecialchars($product['name']); ?>">
                                <i class="fas fa-shopping-cart"></i> Buy on Flipkart
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="loading-spinner" style="display: none;">
                <i class="fas fa-spinner fa-spin" aria-hidden="true"></i>
                <p>Loading more deals...</p>
            </div>
            
            <?php if ($totalPages > 1): ?>
            <div class="pagination flipbox-pagination" role="navigation" aria-label="Pagination">
                <?php if ($page > 1): ?>
                    <a href="/pages/flipbox.php?page=<?php echo $page - 1; ?>" class="btn btn-secondary" data-page="<?php echo $page - 1; ?>" aria-label="Previous page">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                
                if ($start > 1): ?>
                    <a href="/pages/flipbox.php?page=1" class="btn btn-secondary" data-page="1">1</a>
                    <?php if ($start > 2): ?>
                        <span class="pagination-ellipsis">...</span>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <a href="/pages/flipbox.php?page=<?php echo $i; ?>" class="btn <?php echo $i === $page ? 'btn-primary flipbox-active' : 'btn-secondary'; ?>" data-page="<?php echo $i; ?>" aria-label="Page <?php echo $i; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?>
                        <span class="pagination-ellipsis">...</span>
                    <?php endif; ?>
                    <a href="/pages/flipbox.php?page=<?php echo $totalPages; ?>" class="btn btn-secondary" data-page="<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a>
                <?php endif; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="/pages/flipbox.php?page=<?php echo $page + 1; ?>" class="btn btn-secondary" data-page="<?php echo $page + 1; ?>" aria-label="Next page">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    <?php include '../include/footer.php'; ?>
    <script src="/assets/js/main.js"></script>
    <script>
        // Enhanced pagination with loading states
        document.addEventListener('DOMContentLoaded', () => {
            const paginationLinks = document.querySelectorAll('.pagination a[data-page]');
            const spinner = document.querySelector('.loading-spinner');
            const grid = document.querySelector('.product-grid');
            
            paginationLinks.forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    
                    // Show loading state
                    if (spinner) spinner.style.display = 'block';
                    if (grid) grid.style.opacity = '0.6';
                    
                    // Simulate loading delay for better UX
                    setTimeout(() => {
                        window.location.href = link.href;
                    }, 300);
                });
            });
            
            // Add hover effects to cards
            const cards = document.querySelectorAll('.flipbox-card');
            cards.forEach(card => {
                card.addEventListener('mouseenter', () => {
                    card.style.transform = 'translateY(-8px) scale(1.02)';
                });
                
                card.addEventListener('mouseleave', () => {
                    card.style.transform = 'translateY(0) scale(1)';
                });
            });
        });
    </script>
</body>
</html>