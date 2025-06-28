<?php
$allowedPaths = [
    realpath(__DIR__ . '/../../ai_engine'),
    realpath(__DIR__ . '/../../database'),
    realpath(__DIR__ . '/../../hotdeals_bot'),
    realpath(__DIR__ . '/../../config/google.php'),
    realpath(__DIR__ . '/../../config/globals.php')
];

function restrictAccess($path) {
    global $allowedPaths;
    $realPath = realpath($path);
    if ($realPath === false) {
        return false;
    }
    $normalizedPath = str_replace('\\', '/', $realPath);
    foreach ($allowedPaths as $allowed) {
        $normalizedAllowed = str_replace('\\', '/', realpath($allowed));
        if ($normalizedAllowed && strpos($normalizedPath, $normalizedAllowed) === 0) {
            return true;
        }
    }
    file_put_contents(__DIR__ . '/../logs/restrictions.log', "[" . date('Y-m-d H:i:s') . "] Denied access to " . htmlspecialchars($path) . "\n", FILE_APPEND);
    return false;
}
?>