<?php
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: " . LOGIN_REDIRECT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $festivals = json_decode(file_get_contents('php://input'), true);
    foreach ($festivals as $festival) {
        $stmt = $pdo->prepare("
            INSERT INTO festivals (event_name, event_date, event_type, offers_likely)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE event_name = ?, event_type = ?, offers_likely = ?
        ");
        $stmt->execute([
            $festival['event_name'],
            $festival['event_date'],
            $festival['event_type'],
            $festival['offers_likely'],
            $festival['event_name'],
            $festival['event_type'],
            $festival['offers_likely']
        ]);
    }
    file_put_contents('../data/festivals.json', json_encode($festivals));
    echo json_encode(['status' => 'success']);
} else {
    $festivals = json_decode(file_get_contents('../data/festivals.json'), true);
    echo json_encode($festivals);
}
?>