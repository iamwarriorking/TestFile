<?php
require 'globals.php';

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root'); // Replace with Hostinger DB user
define('DB_PASS', ''); // Replace with Hostinger DB password
define('DB_NAME', 'amezprice');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 1 for debugging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Set maximum execution time
set_time_limit(30); // 30 seconds max for connection

// Establish MySQL connection
try {
    $start = microtime(true);

    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5 // 5 second connection timeout
        ]
    );

    // Set MySQL session timezone to IST
    $pdo->exec("SET time_zone = '+05:30'");

        // Ensure logs directory exists
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $connectionTime = microtime(true) - $start;
    if ($connectionTime > 1) {
        file_put_contents($logDir . '/slow_connections.log', "[" . date('Y-m-d H:i:s') . "] Slow connection: " . $connectionTime . " seconds" . PHP_EOL, FILE_APPEND);
    }

} catch (PDOException $e) {
        // Log error with timestamp  
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logDir . '/database_errors.log', "[" . date('Y-m-d H:i:s') . "] Connection failed: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    die("Database connection failed. Please try again later.");
}
?>