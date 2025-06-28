<?php
require_once '../config/database.php';
require_once '../config/globals.php';
require_once '../api/marketplaces/flipkart.php';

try {
    // Placeholder: fetchFlipkartDeals() is not defined in flipkart.php
    // TODO: Implement deal-fetching logic using Flipkart API
    $deals = []; // Replace with actual deal-fetching logic
    if (empty($deals)) {
        throw new Exception("No deals fetched from Flipkart; fetchFlipkartDeals() not implemented");
    }

    $pdo->beginTransaction();
    $stmt = $pdo->query("TRUNCATE TABLE flipbox_products");

    foreach ($deals as $deal) {
        if (!isset($deal['id'], $deal['name'], $deal['current_price'], $deal['discount_percentage'], $deal['affiliate_link'], $deal['image_url'])) {
            continue;
        }

        $stmt = $pdo->prepare("
            INSERT INTO flipbox_products (asin, name, current_price, discount_percentage, affiliate_link, image_url, last_updated)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $deal['id'],
            $deal['name'],
            $deal['current_price'],
            $deal['discount_percentage'],
            $deal['affiliate_link'],
            $deal['image_url']
        ]);
    }

    $pdo->commit();
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Flipbox update cron executed successfully\n", FILE_APPEND);
} catch (Exception $e) {
    $pdo->rollBack();
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Flipbox update cron failed: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>