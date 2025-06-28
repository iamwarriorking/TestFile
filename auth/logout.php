<?php
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/session.php';

// Start session properly
startApplicationSession();

// Log the logout with more details
$userId = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 'unknown';
$userType = $_SESSION['user_type'] ?? 'unknown';
file_put_contents(__DIR__ . '/../logs/auth.log', 
    "[" . date('Y-m-d H:i:s') . "] Logout initiated for $userType ID: $userId\n", 
    FILE_APPEND
);

// Clear all session variables first
$_SESSION = array();

// Delete the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// Clear JWT from session
unset($_SESSION['jwt']);

// Destroy the session completely
session_destroy();

// Clear any session related cookies
foreach ($_COOKIE as $name => $value) {
    if (strpos($name, 'PHPSESSID') !== false) {
        setcookie($name, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
}

// Log successful logout
file_put_contents(__DIR__ . '/../logs/auth.log', 
    "[" . date('Y-m-d H:i:s') . "] Logout successful for $userType ID: $userId\n", 
    FILE_APPEND
);

// Properly redirect to login page with a message
header('Location: ' . LOGIN_REDIRECT);
exit;