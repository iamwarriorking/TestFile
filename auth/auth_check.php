<?php
if (!function_exists('checkAuthAndRedirect')) {
    function checkAuthAndRedirect() {
        if (isset($_SESSION['user_id']) || isset($_SESSION['admin_id'])) {
            $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            // Redirect from auth pages if already logged in
            if (strpos($currentPath, '/auth/') === 0) {
                header("Location: " . (isset($_SESSION['admin_id']) ? '/admin/dashboard.php' : '/user/dashboard.php'));
                exit;
            }
        }
    }
}

// Call the function
checkAuthAndRedirect();
?>