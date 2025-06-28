<?php
require_once '../../config/database.php';
require_once '../../config/globals.php';
require_once '../../config/security.php';
require_once '../../middleware/csrf.php';

// Set JSON response header
header('Content-Type: application/json');

startApplicationSession();

// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Verify CSRF token
if (!verifyCsrfToken()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'CSRF token verification failed']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['filename']) || empty($input['filename'])) {
    echo json_encode(['status' => 'error', 'message' => 'Filename is required']);
    exit;
}

$filename = $input['filename'];
$logDir = '../../logs/';
$filePath = $logDir . $filename;

// Security checks
if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid filename']);
    exit;
}

// Check if file exists and is readable
if (!file_exists($filePath)) {
    echo json_encode(['status' => 'error', 'message' => 'Log file not found']);
    exit;
}

if (!is_readable($filePath)) {
    echo json_encode(['status' => 'error', 'message' => 'Log file is not readable']);
    exit;
}

// Check file extension
$allowedExtensions = ['log', 'txt'];
$fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if (!in_array($fileExtension, $allowedExtensions)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid file type']);
    exit;
}

try {
    // Get file stats
    $fileSize = filesize($filePath);
    $maxSize = 5 * 1024 * 1024; // 5MB limit for viewing
    
    if ($fileSize > $maxSize) {
        // For large files, read only the last part
        $handle = fopen($filePath, 'r');
        fseek($handle, max(0, $fileSize - $maxSize));
        $content = fread($handle, $maxSize);
        fclose($handle);
        
        // Remove incomplete first line
        $content = substr($content, strpos($content, "\n") + 1);
        $content = "... (showing last " . formatBytes($maxSize) . " of file)\n\n" . $content;
    } else {
        $content = file_get_contents($filePath);
    }
    
    // Count lines
    $lineCount = substr_count($content, "\n") + 1;
    
    // Escape HTML special characters
    $content = htmlspecialchars($content);
    
    // Add line numbers for better readability
    $lines = explode("\n", $content);
    $numberedLines = [];
    $startLine = $fileSize > $maxSize ? max(1, $lineCount - count($lines)) : 1;
    
    foreach ($lines as $index => $line) {
        $lineNumber = $startLine + $index;
        $numberedLines[] = sprintf('%4d | %s', $lineNumber, $line);
    }
    
    $numberedContent = implode("\n", $numberedLines);
    
    echo json_encode([
        'status' => 'success',
        'content' => $numberedContent,
        'size' => formatBytes($fileSize),
        'lines' => $lineCount,
        'filename' => $filename,
        'modified' => date('Y-m-d H:i:s', filemtime($filePath))
    ]);
    
} catch (Exception $e) {
    error_log("Error reading log file: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error reading log file']);
}

function formatBytes($size, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
}
?>