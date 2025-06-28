<?php

define('UNSUBSCRIBE_SECRET_KEY', '95a69d29f4d10c49fc8c4b33966ab4ab62b42fe011a09f8471488fce1df8324c');

// FontAwesome configuration
function getFontAwesomeConfig() {
    static $config = null;
    if ($config === null) {
        $config = include __DIR__ . '/fontawesome.php';
    }
    return $config;
}

function getFontAwesomeKitUrl() {
    $config = getFontAwesomeConfig();
    return "https://kit.fontawesome.com/{$config['kit_id']}.js";
}

// VAPID key retrieval function
function getVapidPublicKey() {
    global $pdo;
    static $vapidPublicKey = null;
    
    // Return cached value if already fetched
    if ($vapidPublicKey !== null) {
        return $vapidPublicKey;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT public_key FROM vapid_keys ORDER BY created_at DESC LIMIT 1");
        $stmt->execute();
        $vapidPublicKey = $stmt->fetchColumn();
        return $vapidPublicKey;
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/../logs/push_errors.log', "[" . date('Y-m-d H:i:s') . "] Failed to fetch VAPID public key: " . $e->getMessage() . "\n", FILE_APPEND);
        return '';
    }
}

?>