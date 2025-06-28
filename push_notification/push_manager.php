<?php
require_once '../config/database.php';
require_once 'web-push.php';

function sendPushNotification($subscription, $data) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT public_key, private_key FROM vapid_keys ORDER BY created_at DESC LIMIT 1");
    $stmt->execute();
    $vapid = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vapid) {
        file_put_contents('../logs/push_errors.log', "[" . date('Y-m-d H:i:s') . "] VAPID keys not found\n", FILE_APPEND);
        return ['status' => 'error', 'message' => 'VAPID keys not found'];
    }

    $webPush = new WebPushService([
        'VAPID' => [
            'subject' => 'mailto:support@amezprice.com',
            'publicKey' => $vapid['public_key'],
            'privateKey' => $vapid['private_key']
        ]
    ]);

    $payload = [
        'title' => $data['title'],
        'message' => $data['message'],
        'previous_price' => $data['previous_price'],
        'current_price' => $data['current_price'],
        'tracker_count' => $data['tracker_count'],
        'image_path' => $data['image_path'],
        'affiliate_link' => $data['affiliate_link'],
        'history_url' => $data['history_url'],
        'category' => $data['category'],
        'product_asin' => $data['product_asin'],
        'urgency' => $data['urgency'] ?? 'normal',
        'timestamp' => time() * 1000
    ];

    $options = [
        'TTL' => $data['ttl'] ?? 2419200,
        'urgency' => $data['urgency'] ?? 'normal'
    ];

    try {
        if ($webPush->sendNotification($subscription, $payload, $options)) {
            return ['status' => 'success', 'message' => 'Notification sent'];
        } else {
            $error = $webPush->getLastError() ?? 'Unknown error';
            file_put_contents('../logs/push_errors.log', "[" . date('Y-m-d H:i:s') . "] Failed to send notification: $error\n", FILE_APPEND);
            return ['status' => 'error', 'message' => 'Failed to send notification'];
        }
    } catch (Exception $e) {
        file_put_contents('../logs/push_errors.log', "[" . date('Y-m-d H:i:s') . "] Push notification exception: " . $e->getMessage() . "\n", FILE_APPEND);
        return ['status' => 'error', 'message' => 'Exception during notification send'];
    }
}

function sendPriceDropNotification($userId, $product) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT subscription FROM push_subscriptions WHERE user_id = ? AND product_asin = ?");
    $stmt->execute([$userId, $product['asin']]);
    $subscription = $stmt->fetchColumn();

    if ($subscription) {
        $trackerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM user_products WHERE product_asin = ?");
        $trackerCountStmt->execute([$product['asin']]);
        $trackerCount = $trackerCountStmt->fetchColumn();

        $data = [
            'title' => $product['name'],
            'message' => "Price dropped by ₹" . number_format($product['previous_price'] - $product['current_price'], 0, '.', ','),
            'previous_price' => $product['previous_price'],
            'current_price' => $product['current_price'],
            'tracker_count' => $trackerCount,
            'image_path' => $product['image_path'],
            'affiliate_link' => $product['affiliate_link'],
            'history_url' => "https://amezprice.com/product/{$product['merchant']}/pid={$product['asin']}",
            'category' => $product['category'],
            'product_asin' => $product['asin'],
            'urgency' => $product['is_flash_deal'] ? 'high' : 'normal',
            'ttl' => $product['is_flash_deal'] ? 300 : 2419200
        ];

        return sendPushNotification($subscription, $data);
    }

    file_put_contents('../logs/push_notification.log', "[" . date('Y-m-d H:i:s') . "] No subscription found for user $userId and product {$product['asin']}\n", FILE_APPEND);
    return ['status' => 'info', 'message' => 'No subscription found for this user and product'];
}

function sendBatchPriceDropNotifications($userId, $products) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT public_key, private_key FROM vapid_keys ORDER BY created_at DESC LIMIT 1");
    $stmt->execute();
    $vapid = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vapid) {
        file_put_contents('../logs/push_errors.log', "[" . date('Y-m-d H:i:s') . "] VAPID keys not found for batch notification\n", FILE_APPEND);
        return ['status' => 'error', 'message' => 'VAPID keys not found'];
    }

    $stmt = $pdo->prepare("SELECT subscription FROM push_subscriptions WHERE user_id = ?");
    $stmt->execute([$userId]);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($subscriptions)) {
        file_put_contents('../logs/push_notification.log', "[" . date('Y-m-d H:i:s') . "] No subscriptions found for user $userId\n", FILE_APPEND);
        return ['status' => 'info', 'message' => 'No subscriptions found for this user'];
    }
    
    $productAsins = array_map(function($product) {
        return $product['asin'];
    }, $products);
    
    $placeholders = str_repeat('?,', count($productAsins) - 1) . '?';
    $trackerCountStmt = $pdo->prepare("SELECT product_asin, COUNT(*) as count 
        FROM user_products 
        WHERE product_asin IN ($placeholders) 
        GROUP BY product_asin");
    $trackerCountStmt->execute($productAsins);
    
    $trackerCounts = [];
    while ($row = $trackerCountStmt->fetch(PDO::FETCH_ASSOC)) {
        $trackerCounts[$row['product_asin']] = $row['count'];
    }

    $webPush = new WebPushService([
        'VAPID' => [
            'subject' => 'mailto:support@amezprice.com',
            'publicKey' => $vapid['public_key'],
            'privateKey' => $vapid['private_key']
        ]
    ]);

    $payload = [
        'title' => 'Multiple Deals Alert!',
        'message' => 'New price drops for your tracked products!',
        'deals' => array_map(function($product) use ($trackerCounts) {
            return [
                'name' => $product['name'],
                'previous_price' => $product['previous_price'],
                'current_price' => $product['current_price'],
                'tracker_count' => $trackerCounts[$product['asin']] ?? 0,
                'image_path' => $product['image_path'],
                'affiliate_link' => $product['affiliate_link'],
                'history_url' => "https://amezprice.com/product/{$product['merchant']}/pid={$product['asin']}",
                'category' => $product['category'],
                'product_asin' => $product['asin'],
                'urgency' => $product['is_flash_deal'] ? 'high' : 'normal',
                'timestamp' => time() * 1000
            ];
        }, $products),
        'urgency' => 'normal',
        'timestamp' => time() * 1000
    ];

    $options = [
        'TTL' => 2419200,
        'urgency' => 'normal',
        'topic' => 'price-update-batch'
    ];

    try {
        if ($webPush->sendBatchNotifications($subscriptions, $payload, $options)) {
            return ['status' => 'success', 'message' => 'Batch notifications sent'];
        } else {
            $error = $webPush->getLastError() ?? 'Unknown error';
            file_put_contents('../logs/push_errors.log', "[" . date('Y-m-d H:i:s') . "] Failed to send batch notifications: $error\n", FILE_APPEND);
            return ['status' => 'error', 'message' => 'Failed to send batch notifications'];
        }
    } catch (Exception $e) {
        file_put_contents('../logs/push_errors.log', "[" . date('Y-m-d H:i:s') . "] Batch notification exception: " . $e->getMessage() . "\n", FILE_APPEND);
        return ['status' => 'error', 'message' => 'Exception during batch notification send'];
    }
}
?>