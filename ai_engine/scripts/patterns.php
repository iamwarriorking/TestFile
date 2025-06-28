<?php
require_once __DIR__ . '/../../config/database.php';

function stats_standard_deviation($arr) {
    $num_of_elements = count($arr);
    if ($num_of_elements == 0) return 0;
    $mean = array_sum($arr) / $num_of_elements;
    $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $arr)) / $num_of_elements;
    return sqrt($variance);
}

// Use new price_history table instead of JSON
$stmt = $pdo->prepare("
    SELECT DISTINCT p.asin 
    FROM products p 
    INNER JOIN price_history ph ON p.asin = ph.product_asin
    GROUP BY p.asin
    HAVING COUNT(ph.id) >= 10
");
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($products as $product) {
    // Get price history from new table
    $historyStmt = $pdo->prepare("
        SELECT date_recorded, price 
        FROM price_history 
        WHERE product_asin = ? 
        ORDER BY date_recorded ASC
    ");
    $historyStmt->execute([$product['asin']]);
    $historyData = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($historyData) < 10) continue;
    
    $dates = array_column($historyData, 'date_recorded');
    $prices = array_column($historyData, 'price');
    $prices = array_map('floatval', $prices);

    $patterns = [];
    for ($i = 1; $i < count($prices); $i++) {
        if ($prices[$i] < $prices[$i - 1]) {
            $drop = ($prices[$i - 1] - $prices[$i]) / $prices[$i - 1] * 100;
            if ($drop >= 8) {
                $day = date('l', strtotime($dates[$i]));
                $patterns[$day][] = $drop;
            }
        }
    }

    foreach ($patterns as $day => $drops) {
        if (count($drops) >= 3 && stats_standard_deviation($drops) < 2) {
            $avgDrop = array_sum($drops) / count($drops);
            $description = "This product drops " . round($avgDrop) . "% every $day at 6PM!";
            $stmt = $pdo->prepare("
                INSERT INTO patterns (asin, pattern_description, confidence)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE pattern_description = ?, confidence = ?
            ");
            $stmt->execute([
                $product['asin'],
                $description,
                0.9,
                $description,
                0.9
            ]);
        }
    }
}

file_put_contents('../logs/patterns.log', 
    "[" . date('Y-m-d H:i:s') . "] Pattern analysis completed using price_history table\n", 
    FILE_APPEND
);
?>