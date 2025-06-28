<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../email/send.php';

$stmt = $pdo->query("
    SELECT p.asin, p.name, p.current_price, p.highest_price, p.affiliate_link, p.rating, p.merchant, p.image_path
    FROM products p
    WHERE p.current_price <= p.highest_price * 0.7 AND p.rating >= 3.5
    ORDER BY (p.highest_price - p.current_price) / p.highest_price DESC
    LIMIT 1
");
$deal = $stmt->fetch(PDO::FETCH_ASSOC);

if ($deal) {
    $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
    $trackerCountStmt->execute([$deal['asin']]);
    $trackerCount = $trackerCountStmt->fetchColumn();

    $subject = "🎉 Hot Deal for You!";
    $template = file_get_contents('../../email/templates/deal_email.php');
    $message = str_replace(
        ['{{name}}', '{{highest_price}}', '{{current_price}}', '{{discount}}', '{{tracker_count}}', '{{image_path}}', '{{affiliate_link}}', '{{history_url}}'],
        [
            htmlspecialchars($deal['name']),
            number_format($deal['highest_price'], 0, '.', ','),
            number_format($deal['current_price'], 0, '.', ','),
            round(($deal['highest_price'] - $deal['current_price']) / $deal['highest_price'] * 100),
            $trackerCount,
            htmlspecialchars($deal['image_path']),
            htmlspecialchars($deal['affiliate_link']),
            "https://amezprice.com/product/{$deal['merchant']}/pid={$deal['asin']}"
        ],
        $template
    );

    $stmt = $pdo->query("SELECT email FROM email_subscriptions WHERE subscribed = 'yes'");
    $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($emails as $email) {
        sendEmail($email, $subject, $message, 'deals');
    }
}
?>