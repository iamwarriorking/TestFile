<?php
require_once '../../config/database.php';
require_once '../../config/globals.php';
require_once '../../config/security.php';
require_once '../../middleware/csrf.php';

startApplicationSession();

// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

// Get filename from query parameter
if (!isset($_GET['file']) || empty($_GET['file'])) {
    http_response_code(400);
    die('Filename is required');
}

$filename = $_GET['file'];
$logDir = '../../logs/';
$filePath = $logDir . $filename;

// Security checks
if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
    http_response_code(400);
    die('Invalid filename');
}

// Check if file exists and is readable
if (!file_exists($filePath)) {
    http_response_code(404);
    die('Log file not found');
}

if (!is_readable($filePath)) {
    http_response_code(403);
    die('Log file is not readable');
}

// Check file extension
$allowedExtensions = ['log', 'txt'];
$fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if (!in_array($fileExtension, $allowedExtensions)) {
    http_response_code(400);
    die('Invalid file type');
}

try {
    // Log the download
    $adminId = $_SESSION['admin_id'];
    $adminEmail = $_SESSION['admin_email'] ?? 'unknown';
    error_log("Admin log download: File '$filename' downloaded by admin ID $adminId ($adminEmail)");
    
    // Set headers for file download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Clear any output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Read and output the file
    readfile($filePath);
    
} catch (Exception $e) {
    error_log("Admin log download error: " . $e->getMessage());
    http_response_code(500);
    die('Error downloading log file');
}
?>