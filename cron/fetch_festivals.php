<?php
require_once '../config/database.php';
require_once '../config/globals.php';
require_once '../ai_engine/scripts/fetch_festivals.php';

try {
    // fetch_festivals.php contains inline logic that executes when included
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Festival fetch cron executed successfully\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents('../logs/cron.log', "[" . date('Y-m-d H:i:s') . "] Festival fetch cron failed: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>