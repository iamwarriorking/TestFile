<?php
require_once '../config/database.php';
require_once '../config/globals.php';
require_once '../middleware/csrf.php';

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
$message = $input['message'] ?? null;
$timestamp = $input['timestamp'] ?? null;

if (!$message || !$timestamp) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

file_put_contents('../logs/service_worker_errors.log', "[" . date('Y-m-d H:i:s', $timestamp / 1000) . "] $message\n", FILE_APPEND);
echo json_encode(['status' => 'success', 'message' => 'Error logged']);
?>