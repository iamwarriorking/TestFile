<?php
require_once '../config/globals.php';

// Change from temp directory to products directory
$productsDir = '../assets/images/products/';
$files = glob($productsDir . '*.{jpg,png,webp}', GLOB_BRACE);

try {
    $deletedCount = 0;
    foreach ($files as $file) {
        // Check if file is older than 48 hours (48 * 3600 seconds)
        if (filemtime($file) < time() - 48 * 3600) {
            if (unlink($file)) {
                $deletedCount++;
                // Update log message to reflect products images instead of temp images
                file_put_contents('../logs/images.log', "[" . date('Y-m-d H:i:s') . "] Deleted products image: $file\n", FILE_APPEND);
            } else {
                throw new Exception("Failed to delete products image: $file");
            }
        }
    }
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Image cleanup cron executed: Deleted $deletedCount products images\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Image cleanup cron failed: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>