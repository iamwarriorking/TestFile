<?php
// Set content type to XML
header("Content-Type: application/xml; charset=utf-8");

// Database connection
require_once 'config/database.php';

// Start XML output
echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9
        http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . PHP_EOL;

// Base URL of your site
$baseUrl = 'https://amezprice.com';

// Add static pages
$static_pages = [
    '' => ['priority' => '1.0', 'changefreq' => 'daily', 'lastmod' => date('Y-m-d')],
    'search' => ['priority' => '0.9', 'changefreq' => 'daily', 'lastmod' => date('Y-m-d')],
    'pages/todays-deals.php' => ['priority' => '0.9', 'changefreq' => 'daily', 'lastmod' => date('Y-m-d')],
    'user/login.php' => ['priority' => '0.7', 'changefreq' => 'monthly', 'lastmod' => date('Y-m-d')],
    'user/register.php' => ['priority' => '0.7', 'changefreq' => 'monthly', 'lastmod' => date('Y-m-d')],
    'user/dashboard.php' => ['priority' => '0.8', 'changefreq' => 'weekly', 'lastmod' => date('Y-m-d')],
    'pages/about.php' => ['priority' => '0.6', 'changefreq' => 'monthly', 'lastmod' => date('Y-m-d')],
    'pages/contact.php' => ['priority' => '0.6', 'changefreq' => 'monthly', 'lastmod' => date('Y-m-d')],
    'pages/privacy-policy.php' => ['priority' => '0.5', 'changefreq' => 'yearly', 'lastmod' => date('Y-m-d')],
    'pages/terms.php' => ['priority' => '0.5', 'changefreq' => 'yearly', 'lastmod' => date('Y-m-d')]
];

// Add static pages to sitemap
foreach ($static_pages as $page => $meta) {
    $url = $baseUrl . ($page ? '/' . $page : '');
    echo "\t<url>" . PHP_EOL;
    echo "\t\t<loc>" . htmlspecialchars($url) . "</loc>" . PHP_EOL;
    echo "\t\t<lastmod>" . $meta['lastmod'] . "</lastmod>" . PHP_EOL;
    echo "\t\t<changefreq>" . $meta['changefreq'] . "</changefreq>" . PHP_EOL;
    echo "\t\t<priority>" . $meta['priority'] . "</priority>" . PHP_EOL;
    echo "\t</url>" . PHP_EOL;
}

try {
    // Add categories
    $categories = include 'config/category.php';
    foreach ($categories as $entry) {
        if (!empty($entry['category']) && !empty($entry['heading'])) {
            $categoryUrl = $baseUrl . '/pages/todays-deals.php?category=' . urlencode($entry['category']);
            echo "\t<url>" . PHP_EOL;
            echo "\t\t<loc>" . htmlspecialchars($categoryUrl) . "</loc>" . PHP_EOL;
            echo "\t\t<lastmod>" . date('Y-m-d') . "</lastmod>" . PHP_EOL;
            echo "\t\t<changefreq>weekly</changefreq>" . PHP_EOL;
            echo "\t\t<priority>0.8</priority>" . PHP_EOL;
            echo "\t</url>" . PHP_EOL;
        }
    }
    
    // Add product pages - get the top 1000 most popular products
    $stmt = $pdo->prepare("
        SELECT p.asin, p.merchant, p.name, p.updated_at
        FROM products p 
        LEFT JOIN user_products up ON p.asin = up.product_asin 
        GROUP BY p.asin
        ORDER BY COUNT(up.id) DESC, p.updated_at DESC
        LIMIT 1000
    ");
    $stmt->execute();
    
    while ($product = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $lastmod = !empty($product['updated_at']) ? date('Y-m-d', strtotime($product['updated_at'])) : date('Y-m-d');
        $productUrl = $baseUrl . '/product/' . htmlspecialchars($product['merchant']) . '/pid=' . htmlspecialchars($product['asin']);
        
        echo "\t<url>" . PHP_EOL;
        echo "\t\t<loc>" . htmlspecialchars($productUrl) . "</loc>" . PHP_EOL;
        echo "\t\t<lastmod>" . $lastmod . "</lastmod>" . PHP_EOL;
        echo "\t\t<changefreq>daily</changefreq>" . PHP_EOL;
        echo "\t\t<priority>0.8</priority>" . PHP_EOL;
        echo "\t</url>" . PHP_EOL;
    }
    
} catch (Exception $e) {
    // If database error occurs, at least output the static pages
    error_log("Sitemap generation error: " . $e->getMessage());
}

// Close XML
echo '</urlset>';
?>