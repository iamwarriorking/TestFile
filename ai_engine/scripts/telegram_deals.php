<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/telegram.php';

$stmt = $pdo->query("
    SELECT p.asin, p.name, p.current_price, p.highest_price, p.lowest_price, p.affiliate_link, p.rating, p.merchant
    FROM products p
    WHERE p.current_price <= p.lowest_price * 1.1 AND p.rating >= 3.5
    ORDER BY p.current_price ASC
    LIMIT 10
");
$hotDeals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$botToken = $telegramConfig['hotdealsbot_token'];
$baseUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";

foreach ($hotDeals as $product) {
    $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
    $trackerCountStmt->execute([$product['asin']]);
    $trackerCount = $trackerCountStmt->fetchColumn();

    $message = "🎉 **Hot deal for you!** 🎉\n\n"
             . "[{$product['name']}]({$product['affiliate_link']})\n\n"
             . "Highest Price: ₹" . number_format($product['highest_price'], 0, '.', ',') . "\n\n"
             . "**Current Price: ₹" . number_format($product['current_price'], 0, '.', ',') . "**\n\n"
             . round(($product['highest_price'] - $product['current_price']) / $product['highest_price'] * 100) . "% off\n\n"
             . "🔥 {$trackerCount} users are tracking this!\n\n"
             . "🔔 Updated at " . date('d M Y, h:i A');

    $payload = [
        'chat_id' => '@AmezPriceHotDeals',
        'text' => $message,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Buy Now', 'url' => $product['affiliate_link']],
                    ['text' => 'Price History', 'url' => "https://amezprice.com/product/{$product['merchant']}/pid={$product['asin']}"],
                    ['text' => 'Set price alert for this!', 'url' => 'https://t.me/AmezPriceBot?start=alert_' . $product['asin']]
                ]
            ]
        ])
    ];

    $ch = curl_init($baseUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

$stmt = $pdo->query("
    SELECT p.asin, p.name, p.current_price, p.highest_price, p.affiliate_link, p.rating, p.merchant
    FROM products p
    WHERE p.current_price <= p.highest_price * 0.7 AND p.rating >= 3.5
    ORDER BY (p.highest_price - p.current_price) / p.highest_price DESC
    LIMIT 5
");
$amezDeals = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($amezDeals as $product) {
    $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
    $trackerCountStmt->execute([$product['asin']]);
    $trackerCount = $trackerCountStmt->fetchColumn();

    $message = "🎉 **Hot deal for you!** 🎉\n\n"
             . "[{$product['name']}]({$product['affiliate_link']})\n\n"
             . "Highest Price: ₹" . number_format($product['highest_price'], 0, '.', ',') . "\n\n"
             . "**Current Price: ₹" . number_format($product['current_price'], 0, '.', ',') . "**\n\n"
             . round(($product['highest_price'] - $product['current_price']) / $product['highest_price'] * 100) . "% off\n\n"
             . "🔥 {$trackerCount} users are tracking this!\n\n"
             . "🔔 Updated at " . date('d M Y, h:i A');

    $payload = [
        'chat_id' => '@AmezPrice',
        'text' => $message,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Buy Now', 'url' => $product['affiliate_link']],
                    ['text' => 'Price History', 'url' => "https://amezprice.com/product/{$product['merchant']}/pid={$product['asin']}"],
                    ['text' => 'Set price alert for this!', 'url' => 'https://t.me/AmezPriceBot?start=alert_' . $product['asin']]
                ]
            ]
        ])
    ];

    $ch = curl_init($baseUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// Best Offer of the Day
$stmt = $pdo->query("
    SELECT p.asin, p.name, p.current_price, p.highest_price, p.lowest_price, p.affiliate_link, p.rating, p.merchant
    FROM products p
    WHERE p.current_price <= p.lowest_price AND p.rating >= 3.5
    ORDER BY p.current_price ASC
    LIMIT 1
");
$bestOffer = $stmt->fetch(PDO::FETCH_ASSOC);

if ($bestOffer) {
    $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
    $trackerCountStmt->execute([$bestOffer['asin']]);
    $trackerCount = $trackerCountStmt->fetchColumn();

    $message = "🏆 **Best Offer of the Day!** 🏆\n\n"
             . "[{$bestOffer['name']}]({$bestOffer['affiliate_link']})\n\n"
             . "Highest Price: ₹" . number_format($bestOffer['highest_price'], 0, '.', ',') . "\n\n"
             . "**Current Price: ₹" . number_format($bestOffer['current_price'], 0, '.', ',') . "**\n\n"
             . round(($bestOffer['highest_price'] - $bestOffer['current_price']) / $bestOffer['highest_price'] * 100) . "% off\n\n"
             . "🔥 {$trackerCount} users are tracking this!\n\n"
             . "🔔 Updated at " . date('d M Y, h:i A');

    $payload = [
        'chat_id' => '@AmezPrice',
        'text' => $message,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Buy Now', 'url' => $bestOffer['affiliate_link']],
                    ['text' => 'Price History', 'url' => "https://amezprice.com/product/{$bestOffer['merchant']}/pid={$bestOffer['asin']}"],
                    ['text' => 'Set price alert for this!', 'url' => 'https://t.me/AmezPriceBot?start=alert_' . $bestOffer['asin']]
                ]
            ]
        ])
    ];

    $ch = curl_init($baseUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}
?>