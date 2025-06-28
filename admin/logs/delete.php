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

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
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

// Check if file exists
if (!file_exists($filePath)) {
    echo json_encode(['status' => 'error', 'message' => 'Log file not found']);
    exit;
}

// Check file extension
$allowedExtensions = ['log', 'txt'];
$fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if (!in_array($fileExtension, $allowedExtensions)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid file type']);
    exit;
}

// Additional security: prevent deletion of critical system files
$protectedFiles = ['error.log', 'access.log', 'system.log'];
if (in_array(strtolower($filename), $protectedFiles)) {
    echo json_encode(['status' => 'error', 'message' => 'Cannot delete protected system log files']);
    exit;
}

try {
    // Log the deletion attempt
    $adminId = $_SESSION['admin_id'];
    $adminEmail = $_SESSION['admin_email'] ?? 'unknown';
    
    error_log("Admin log deletion: File '$filename' requested for deletion by admin ID $adminId ($adminEmail)");
    
    // Attempt to delete the file
    if (unlink($filePath)) {
        // Log successful deletion
        error_log("Admin log deletion: File '$filename' successfully deleted by admin ID $adminId");
        
        // Log to database if you have an admin_logs table
        try {
            $stmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, target, details, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([
                $adminId,
                'delete_log',
                $filename,
                "Deleted log file: $filename"
            ]);
        } catch (Exception $e) {
            // Database logging failed, but file deletion succeeded
            error_log("Failed to log admin action to database: " . $e->getMessage());
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Log file deleted successfully',
            'filename' => $filename
        ]);
    } else {
        error_log("Admin log deletion: Failed to delete file '$filename' - permission denied or file locked");
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete log file. Check file permissions.']);
    }
    
} catch (Exception $e) {
    error_log("Admin log deletion error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An error occurred while deleting the log file']);
}
?>