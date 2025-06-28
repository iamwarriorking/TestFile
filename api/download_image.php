<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

/**
 * Log function for debugging
 */
function logImageDownload($message, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/image_download.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logData = array_merge([
            'timestamp' => $timestamp,
            'message' => $message
        ], $data);
        
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log("Image download logging error: " . $e->getMessage());
    }
}

/**
 * Check if local image file exists and is valid
 */
function checkLocalImage($asin, $merchant) {
    $filename = $merchant . '-' . $asin . '.webp';
    $fullPath = __DIR__ . '/../assets/images/products/' . $filename;
    $relativePath = 'assets/images/products/' . $filename;
    
    // Check if file exists and is not empty
    if (file_exists($fullPath) && filesize($fullPath) > 0) {
        // Additional check: verify it's a valid image
        $imageInfo = @getimagesize($fullPath);
        if ($imageInfo !== false) {
            return [
                'exists' => true,
                'path' => $relativePath,
                'full_path' => $fullPath,
                'size' => filesize($fullPath),
                'filename' => $filename
            ];
        }
    }
    
    return [
        'exists' => false,
        'path' => $relativePath,
        'full_path' => $fullPath,
        'filename' => $filename
    ];
}

/**
 * Get current database image path for product
 */
function getDatabaseImagePath($asin, $merchant) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT local_image_path FROM products WHERE asin = ? AND merchant = ?");
        $stmt->execute([$asin, $merchant]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        logImageDownload('Database query error', [
            'error' => $e->getMessage(),
            'asin' => $asin,
            'merchant' => $merchant
        ]);
        return false;
    }
}

/**
 * Check if re-download is needed
 */
function needsRedownload($asin, $merchant, $forceDownload = false) {
    // If force download is requested
    if ($forceDownload) {
        return [
            'needs_download' => true,
            'reason' => 'Force download requested'
        ];
    }
    
    // Check local file existence
    $localCheck = checkLocalImage($asin, $merchant);
    
    if (!$localCheck['exists']) {
        return [
            'needs_download' => true,
            'reason' => 'Local file does not exist or is corrupted',
            'expected_path' => $localCheck['path']
        ];
    }
    
    // Check if database path matches local file
    $dbPath = getDatabaseImagePath($asin, $merchant);
    
    // 🔥 FIX: Always update database if path is empty or doesn't match
    if (empty($dbPath) || !str_contains($dbPath, $localCheck['filename'])) {
        return [
            'needs_download' => false, // File exists locally
            'needs_db_update' => true, // But database needs update
            'reason' => 'Local file exists but database path is empty or incorrect',
            'db_path' => $dbPath,
            'expected_path' => $localCheck['path'],
            'local_info' => $localCheck
        ];
    }
    
    return [
        'needs_download' => false,
        'needs_db_update' => false,
        'reason' => 'Local file exists and database is in sync',
        'local_info' => $localCheck
    ];
}

/**
 * Get large quality image URL from Amazon
 */
function getAmazonLargeImageUrl($originalUrl) {
    $baseUrl = preg_replace('/\._[A-Z0-9_,]+_\./', '.', $originalUrl);
    $baseUrl = preg_replace('/\.(jpg|jpeg|png|webp).*$/i', '', $baseUrl);
    
    $largePatterns = [
        '._SL1500_.',
        '._SL1200_.',
        '._SL1000_.',
        '._AC_SL1500_.',
        '._AC_UL1500_.',
        '',
    ];
    
    foreach ($largePatterns as $pattern) {
        $testUrl = $baseUrl . $pattern . 'jpg';
        if (isImageAccessible($testUrl)) {
            return $testUrl;
        }
    }
    
    return $originalUrl;
}

/**
 * Get large quality image URL from Flipkart
 */
function getFlipkartLargeImageUrl($originalUrl) {
    $largeUrl = preg_replace('/\/\d+\/\d+\//', '/1500/1500/', $originalUrl);
    $largeUrl = preg_replace('/\?q=\d+/', '?q=90', $largeUrl);
    
    if (isImageAccessible($largeUrl)) {
        return $largeUrl;
    }
    
    return $originalUrl;
}

/**
 * Check if image URL is accessible
 */
function isImageAccessible($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode >= 200 && $httpCode < 300;
}

/**
 * Download image from URL
 */
function downloadImage($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
        'Accept-Encoding: gzip, deflate, br',
    ));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    
    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    // Log detailed information about the request
    logImageDownload('Image download attempt', [
        'url' => $url,
        'http_code' => $httpCode,
        'curl_error' => $error,
        'content_type' => $info['content_type'] ?? 'unknown',
        'size' => $info['download_content_length'] ?? 'unknown'
    ]);
    
    if ($httpCode !== 200 || $error || !$imageData) {
        throw new Exception("Failed to download image. HTTP Code: $httpCode, Error: $error");
    }
    
    return $imageData;
}

/**
 * Convert image to WebP format
 */
function convertToWebP($imageData, $quality = 85) {
    try {
        // Try to determine image type before processing
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($imageData);
        
        logImageDownload('Image conversion attempt', [
            'mime_type' => $mimeType,
            'data_length' => strlen($imageData)
        ]);
        
        $image = @imagecreatefromstring($imageData);
        
        if (!$image) {
            logImageDownload('Image creation failed', [
                'error' => error_get_last()['message'] ?? 'Unknown error'
            ]);
            throw new Exception("Failed to create image resource from data: " . error_get_last()['message']);
        }
        
        ob_start();
        
        if (!imagewebp($image, null, $quality)) {
            $error = error_get_last()['message'] ?? 'Unknown error';
            imagedestroy($image);
            ob_end_clean();
            throw new Exception("Failed to convert image to WebP format: $error");
        }
        
        $webpData = ob_get_contents();
        ob_end_clean();
        
        imagedestroy($image);
        
        return $webpData;
    } catch (Exception $e) {
        logImageDownload('Image conversion exception', [
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

/**
 * Save image to local directory
 */
function saveImageLocally($imageData, $filename) {
    $uploadDir = __DIR__ . '/../assets/images/products/';
    
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception("Failed to create upload directory");
        }
    }
    
    $filePath = $uploadDir . $filename;
    
    if (file_put_contents($filePath, $imageData) === false) {
        throw new Exception("Failed to save image file");
    }
    
    return 'assets/images/products/' . $filename;
}

/**
 * Process image download and conversion
 */
function processImageDownload($merchant, $asin, $imageUrl, $forceDownload = false) {
    try {
        // Check if re-download is needed
        $downloadCheck = needsRedownload($asin, $merchant, $forceDownload);
        
        // 🔥 FIX: Handle case where file exists but database needs update
        if (!$downloadCheck['needs_download'] && !$downloadCheck['needs_db_update']) {
            return [
                'status' => 'success',
                'action' => 'skipped',
                'reason' => $downloadCheck['reason'],
                'existing_file' => $downloadCheck['local_info'],
                'filename' => $downloadCheck['local_info']['filename'],
                'path' => $downloadCheck['local_info']['path']
            ];
        }
        
        // If file exists but database needs update
        if (!$downloadCheck['needs_download'] && $downloadCheck['needs_db_update']) {
            return [
                'status' => 'success',
                'action' => 'db_update_only',
                'reason' => $downloadCheck['reason'],
                'existing_file' => $downloadCheck['local_info'],
                'filename' => $downloadCheck['local_info']['filename'],
                'path' => $downloadCheck['local_info']['path']
            ];
        }
        
        logImageDownload('Starting image download process', [
            'merchant' => $merchant,
            'asin' => $asin,
            'original_url' => $imageUrl,
            'reason' => $downloadCheck['reason'],
            'force_download' => $forceDownload
        ]);
        
        // Get large quality image URL based on merchant
        if ($merchant === 'amazon') {
            $largeImageUrl = getAmazonLargeImageUrl($imageUrl);
        } elseif ($merchant === 'flipkart') {
            $largeImageUrl = getFlipkartLargeImageUrl($imageUrl);
        } else {
            throw new Exception("Unsupported merchant: $merchant");
        }
        
        // Download the image
        $imageData = downloadImage($largeImageUrl);
        
        // Convert to WebP
        $webpData = convertToWebP($imageData);
        
        // Generate filename: merchant-asin.webp
        $filename = $merchant . '-' . $asin . '.webp';
        
        // Save to local directory
        $savedPath = saveImageLocally($webpData, $filename);
        
        logImageDownload('Image processed successfully', [
            'filename' => $filename,
            'path' => $savedPath,
            'original_size' => strlen($imageData),
            'webp_size' => strlen($webpData),
            'compression_ratio' => round((1 - strlen($webpData) / strlen($imageData)) * 100, 2) . '%'
        ]);
        
        return [
            'status' => 'success',
            'action' => 'downloaded',
            'filename' => $filename,
            'path' => $savedPath,
            'original_url' => $imageUrl,
            'large_url' => $largeImageUrl,
            'original_size' => strlen($imageData),
            'webp_size' => strlen($webpData),
            'compression_ratio' => round((1 - strlen($webpData) / strlen($imageData)) * 100, 2) . '%'
        ];
        
    } catch (Exception $e) {
        logImageDownload('Error in image processing', [
            'error' => $e->getMessage(),
            'merchant' => $merchant,
            'asin' => $asin
        ]);
        
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Update database with local image path
 */
function updateProductImagePath($asin, $merchant, $localPath) {
    global $pdo;
    
    try {
        // First check if the product exists
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE asin = ? AND merchant = ?");
        $checkStmt->execute([$asin, $merchant]);
        $exists = (int)$checkStmt->fetchColumn();
        
        logImageDownload('Database check before update', [
            'asin' => $asin,
            'merchant' => $merchant,
            'product_exists' => $exists ? 'yes' : 'no'
        ]);
        
        if (!$exists) {
            logImageDownload('Cannot update image path - product does not exist', [
                'asin' => $asin,
                'merchant' => $merchant
            ]);
            return false;
        }
        
        $stmt = $pdo->prepare("
            UPDATE products 
            SET local_image_path = ?, last_updated = NOW() 
            WHERE asin = ? AND merchant = ?
        ");
        
        $success = $stmt->execute([$localPath, $asin, $merchant]);
        $rowsAffected = $stmt->rowCount();
        
        logImageDownload('Database update result', [
            'success' => $success ? 'yes' : 'no',
            'rows_affected' => $rowsAffected,
            'asin' => $asin,
            'merchant' => $merchant,
            'local_path' => $localPath,
            'sql_error' => $stmt->errorInfo()
        ]);
        
        return $success && $rowsAffected > 0;
    } catch (Exception $e) {
        logImageDownload('Database update error', [
            'error' => $e->getMessage(),
            'asin' => $asin,
            'merchant' => $merchant,
            'local_path' => $localPath
        ]);
        return false;
    }
}

/**
 * Get original image URL from database if not provided
 */
function getOriginalImageUrl($asin, $merchant) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT local_image_path FROM products WHERE asin = ? AND merchant = ?");
        $stmt->execute([$asin, $merchant]);
        $dbPath = $stmt->fetchColumn();
        
        // If database has a URL (not local path), return it
        if ($dbPath && (str_starts_with($dbPath, 'http://') || str_starts_with($dbPath, 'https://'))) {
            return $dbPath;
        }
        
        return null;
    } catch (Exception $e) {
        return null;
    }
}

// Main execution
try {
    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $merchant = $input['merchant'] ?? '';
    $asin = $input['asin'] ?? '';
    $imageUrl = $input['image_url'] ?? '';
    $updateDb = $input['update_db'] ?? true;
    $forceDownload = $input['force_download'] ?? false;
    $checkOnly = $input['check_only'] ?? false;
    
    // Validate inputs
    if (empty($merchant) || empty($asin)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Missing required parameters: merchant, asin'
        ]);
        exit;
    }
    
    if (!in_array($merchant, ['amazon', 'flipkart'])) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Merchant must be either "amazon" or "flipkart"'
        ]);
        exit;
    }
    
    // If only checking file status
    if ($checkOnly) {
        $downloadCheck = needsRedownload($asin, $merchant, false);
        $localCheck = checkLocalImage($asin, $merchant);
        $dbPath = getDatabaseImagePath($asin, $merchant);
        
        echo json_encode([
            'status' => 'success',
            'action' => 'check_only',
            'file_exists' => $localCheck['exists'],
            'local_info' => $localCheck,
            'database_path' => $dbPath,
            'needs_download' => $downloadCheck['needs_download'],
            'needs_db_update' => $downloadCheck['needs_db_update'] ?? false,
            'download_reason' => $downloadCheck['reason']
        ]);
        exit;
    }
    
    // Try to get image URL from database if not provided
    if (empty($imageUrl)) {
        $imageUrl = getOriginalImageUrl($asin, $merchant);
        if (!$imageUrl) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Image URL is required. Either provide image_url parameter or ensure database has original URL.'
            ]);
            exit;
        }
    }
    
    if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid image URL provided'
        ]);
        exit;
    }
    
    // Check if GD extension is available
    if (!extension_loaded('gd')) {
        echo json_encode([
            'status' => 'error',
            'message' => 'GD extension is required for image processing'
        ]);
        exit;
    }
    
    // Process the image download
    $result = processImageDownload($merchant, $asin, $imageUrl, $forceDownload);
    
    // 🔥 FIX: Always try to update database if successful and updateDb is true
    if ($result['status'] === 'success' && $updateDb) {
        // Update database with local image path
        $dbUpdateSuccess = updateProductImagePath($asin, $merchant, $result['path']);
        $result['db_updated'] = $dbUpdateSuccess;
        
        if (!$dbUpdateSuccess) {
            $result['db_update_error'] = 'Failed to update database with local image path';
            // Don't fail the entire operation if download was successful
            logImageDownload('Warning: Image downloaded but database update failed', [
                'asin' => $asin,
                'merchant' => $merchant,
                'path' => $result['path']
            ]);
        }
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    logImageDownload('Fatal error in main execution', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
}
?>