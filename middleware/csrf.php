<?php
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/globals.php';
require_once __DIR__ . '/../config/session.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    startApplicationSession();
}

function generateCsrfToken() {
    global $securityConfig;
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes($securityConfig['csrf']['token_length']));
        $_SESSION['csrf_token_expiry'] = time() + $securityConfig['csrf']['expiry_time'];
        file_put_contents(__DIR__ . '/../logs/auth.log', 
            "[" . date('Y-m-d H:i:s') . "] CSRF token generated for session " . session_id() . ": " . $_SESSION['csrf_token'] . "\n", 
            FILE_APPEND);
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    if (empty($_SESSION['csrf_token'])) {
        file_put_contents(__DIR__ . '/../logs/auth.log', 
            "[" . date('Y-m-d H:i:s') . "] CSRF token validation failed - no session token\n", 
            FILE_APPEND);
        return false;
    }
    
    // Check if token has expired
    if (isset($_SESSION['csrf_token_expiry']) && time() > $_SESSION['csrf_token_expiry']) {
        file_put_contents(__DIR__ . '/../logs/auth.log', 
            "[" . date('Y-m-d H:i:s') . "] CSRF token expired\n", 
            FILE_APPEND);
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_expiry']);
        return false;
    }
    
    $isValid = hash_equals($_SESSION['csrf_token'], $token);
    
    if (!$isValid) {
        $logMessage = "[" . date('Y-m-d H:i:s') . "] CSRF token validation failed\n";
        $logMessage .= "Session ID: " . session_id() . "\n";
        $logMessage .= "Expected: " . $_SESSION['csrf_token'] . "\n";
        $logMessage .= "Received: " . ($token ?: 'empty') . "\n";
        $logMessage .= "All Headers: " . print_r(getallheaders(), true) . "\n";
        file_put_contents(__DIR__ . '/../logs/auth.log', $logMessage, FILE_APPEND);
    } else {
        file_put_contents(__DIR__ . '/../logs/auth.log', 
            "[" . date('Y-m-d H:i:s') . "] CSRF token validation successful for session " . session_id() . "\n", 
            FILE_APPEND);
    }
    
    return $isValid;
}

// Auto-handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
    (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false || 
     strpos($_SERVER['HTTP_CONTENT_TYPE'] ?? '', 'application/json') !== false ||
     strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false)) {
    
    // Try multiple header formats
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 
             $_SERVER['HTTP_X-CSRF-TOKEN'] ?? 
             $_POST['csrf_token'] ?? '';
    
    file_put_contents(__DIR__ . '/../logs/auth.log', 
        "[" . date('Y-m-d H:i:s') . "] AJAX CSRF check - token received: " . ($token ?: 'empty') . "\n", 
        FILE_APPEND);
    
    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'CSRF token not found. Please refresh the page and try again.'
        ]);
        exit;
    }
    return;
}

// For regular form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        die('Invalid CSRF token. Please go back and try again.');
    }
}
?>