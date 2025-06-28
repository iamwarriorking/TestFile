<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../middleware/csrf.php';
require_once __DIR__ . '/../config/globals.php';
startApplicationSession();

$csrfToken = generateCsrfToken();
$vapidPublicKey = getVapidPublicKey();
$fontAwesomeKitUrl = getFontAwesomeKitUrl();

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
<meta name="vapid-public-key" content="<?php echo htmlspecialchars($vapidPublicKey ?? ''); ?>">
<link rel="icon" type="image/x-icon" href="/assets/images/logos/favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/images/logos/apple-touch-icon.png">
<script src="<?php echo htmlspecialchars($fontAwesomeKitUrl); ?>" crossorigin="anonymous"></script>