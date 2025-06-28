<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/globals.php';
require_once __DIR__ . '/../../config/google.php';

$apiKey = $googleConfig['calendar_api_key'];
$calendarId = 'holiday@group.v.calendar.google.com';
$year = date('Y');
$startDate = "$year-01-01T00:00:00Z";
$endDate = "$year-12-31T23:59:59Z";

try {
    $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendarId) . "/events?key=$apiKey&timeMin=$startDate&timeMax=$endDate";
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    if (!isset($data['items'])) {
        throw new Exception('Invalid response from Google Calendar API');
    }

    foreach ($data['items'] as $event) {
        $eventName = $event['summary'];
        $eventDate = $event['start']['date'];
        $eventType = strpos($eventName, 'Sale') !== false ? 'sale' : 'festival';

        // Check historical discounts using new price_history table
        $offersLikely = false;
        
        $stmt = $pdo->prepare("
            SELECT p.asin, ph.date_recorded, ph.price
            FROM products p
            INNER JOIN price_history ph ON p.asin = ph.product_asin
            WHERE p.category IN ('smartphone', 'television')
            AND ph.date_recorded BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)
            ORDER BY p.asin, ph.date_recorded
        ");
        $stmt->execute([$eventDate, $eventDate]);
        $eventPrices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($eventPrices)) {
            // Group by product and calculate average price drops
            $productDrops = [];
            foreach ($eventPrices as $record) {
                $asin = $record['asin'];
                if (!isset($productDrops[$asin])) {
                    $productDrops[$asin] = [];
                }
                $productDrops[$asin][] = (float)$record['price'];
            }
            
            foreach ($productDrops as $asin => $prices) {
                if (count($prices) >= 2) {
                    $maxPrice = max($prices);
                    $minPrice = min($prices);
                    $dropPercent = (($maxPrice - $minPrice) / $maxPrice) * 100;
                    
                    if ($dropPercent > 10) {
                        $offersLikely = true;
                        break;
                    }
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO festivals (event_name, event_date, event_type, offers_likely)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE event_name = ?, event_type = ?, offers_likely = ?
        ");
        $stmt->execute([
            $eventName,
            $eventDate,
            $eventType,
            $offersLikely,
            $eventName,
            $eventType,
            $offersLikely
        ]);

        file_put_contents('../logs/festivals.log', "[" . date('Y-m-d H:i:s') . "] Fetched $eventName for $eventDate: offers_likely=" . ($offersLikely ? 'true' : 'false') . " (using price_history table)\n", FILE_APPEND);
    }
} catch (Exception $e) {
    file_put_contents('../logs/festivals.log', "[" . date('Y-m-d H:i:s') . "] Error fetching festivals: " . $e->getMessage() . "\n", FILE_APPEND);
}

// Update static fallback
file_put_contents('../data/festivals.json', json_encode($data['items']));
?>