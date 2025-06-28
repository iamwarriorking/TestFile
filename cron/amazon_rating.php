<?php
/**
 * Amazon Rating Update Cronjob for AmezPrice
 * Runs periodically to update ratings for popular products
 * 
 * Conditions:
 * - Only updates products with 100+ tracking users
 * - Updates only once every 3 months
 * - Processes products in batches to avoid overwhelming Amazon
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0); // No time limit for cronjob

require_once __DIR__ . '/../config/database.php';

// Logging function
function logRatingUpdate($message, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/rating_update.log';
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
        echo "[{$timestamp}] {$message}\n";
    } catch (Exception $e) {
        error_log("Rating update logging error: " . $e->getMessage());
    }
}

class AmazonRatingCronExtractor {
    
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';
    private $debug = true;
    private $delayBetweenRequests = 3; // 3 seconds delay between requests
    
    public function getRatingAndReviews($asin, $domain = 'in') {
        
        if (empty($asin)) {
            return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'ASIN is required'];
        }
        
        $asin = trim(strtoupper($asin));
        
        $result = [
            'rating' => 0,
            'reviews' => 0,  
            'success' => false,
            'error' => ''
        ];
        
        // Try different methods
        $methods = [
            'product_page_advanced',
            'json_extraction',
            'regex_fallback'
        ];
        
        foreach ($methods as $method) {
            $this->log("🔄 Trying method: $method for ASIN: $asin");
            
            try {
                switch ($method) {
                    case 'product_page_advanced':
                        $data = $this->extractFromProductPageAdvanced($asin, $domain);
                        break;
                    case 'json_extraction':
                        $data = $this->extractFromJSONData($asin, $domain);
                        break;
                    case 'regex_fallback':
                        $data = $this->extractUsingRegex($asin, $domain);
                        break;
                }
                
                if ($data['success']) {
                    $this->log("✅ Success with method: $method");
                    return $data;
                }
                
            } catch (Exception $e) {
                $this->log("❌ Error in $method: " . $e->getMessage());
            }
            
            // Add delay between different methods
            sleep(1);
        }
        
        return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'Unable to extract rating data'];
    }
    
    private function extractFromProductPageAdvanced($asin, $domain) {
        $url = "https://www.amazon.$domain/dp/$asin";
        $html = $this->fetchPage($url);
        
        if (!$html) {
            return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'Failed to fetch product page'];
        }
        
        return $this->parseHTMLAdvanced($html);
    }
    
    private function extractFromJSONData($asin, $domain) {
        $url = "https://www.amazon.$domain/dp/$asin";
        $html = $this->fetchPage($url);
        
        if (!$html) {
            return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'Failed to fetch for JSON extraction'];
        }
        
        $pattern = '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si';
        if (preg_match_all($pattern, $html, $matches)) {
            foreach ($matches[1] as $jsonString) {
                $data = json_decode(trim($jsonString), true);
                if ($data && isset($data['aggregateRating'])) {
                    $rating = (float)($data['aggregateRating']['ratingValue'] ?? 0);
                    $reviews = (int)($data['aggregateRating']['reviewCount'] ?? 0);
                    
                    if ($rating > 0) {
                        $this->log("✅ Found JSON-LD data: Rating=$rating, Reviews=$reviews");
                        return ['rating' => $rating, 'reviews' => $reviews, 'success' => true, 'error' => ''];
                    }
                }
            }
        }
        
        return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'No JSON-LD data found'];
    }
    
    private function extractUsingRegex($asin, $domain) {
        $url = "https://www.amazon.$domain/dp/$asin";
        $html = $this->fetchPage($url);
        
        if (!$html) {
            return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'Failed to fetch for regex extraction'];
        }
        
        $rating = 0;
        $reviews = 0;
        
        $ratingPatterns = [
            '/([0-9]\.[0-9])\s*out\s*of\s*5\s*stars/i',
            '/rating["\']:\s*["\']?([0-9]\.[0-9])["\']?/i',
            '/ratingValue["\']:\s*["\']?([0-9]\.[0-9])["\']?/i',
            '/"ratingValue":"([0-9]\.[0-9])"/i'
        ];
        
        $reviewPatterns = [
            '/([0-9,]+)\s*(?:customer\s*)?(?:global\s*)?ratings?/i',
            '/([0-9,]+)\s*(?:customer\s*)?reviews?/i',
            '/reviewCount["\']:\s*["\']?([0-9,]+)["\']?/i',
            '/"reviewCount":"([0-9,]+)"/i'
        ];
        
        foreach ($ratingPatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $rating = (float)$matches[1];
                break;
            }
        }
        
        foreach ($reviewPatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $reviews = (int)str_replace(',', '', $matches[1]);
                break;
            }
        }
        
        $success = ($rating > 0 || $reviews > 0);
        return [
            'rating' => $rating,
            'reviews' => $reviews,
            'success' => $success,
            'error' => $success ? '' : 'No data found via regex'
        ];
    }
    
    private function parseHTMLAdvanced($html) {
        if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'DOM classes not available'];
        }
        
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);
        
        $rating = 0;
        $reviews = 0;
        
        $ratingSelectors = [
            "//span[@class='a-icon-alt' and contains(text(), 'out of')]",
            "//span[@data-hook='rating-out-of-text']",
            "//span[contains(@class, 'a-offscreen') and contains(text(), 'out of')]",
            "//div[@data-hook='average-star-rating']//span[@class='a-offscreen']",
            "//i[contains(@class, 'a-icon-star')]//span[@class='a-offscreen']"
        ];
        
        foreach ($ratingSelectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                $text = trim($nodes->item(0)->textContent);
                
                if (preg_match('/([0-9]+[,\.]?[0-9]*)\s*(?:out\s*of|von|sur|de)/i', $text, $matches)) {
                    $rating = (float) str_replace(',', '.', $matches[1]);
                    if ($rating > 5) $rating = 5;
                    break;
                }
            }
        }
        
        $reviewSelectors = [
            "//span[@data-hook='total-review-count']",
            "//a[@data-hook='see-all-reviews-link-foot']//span",
            "//div[@data-hook='total-review-count']",
            "//span[@id='acrCustomerReviewText']"
        ];
        
        foreach ($reviewSelectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                $text = trim($nodes->item(0)->textContent);
                
                if (preg_match('/([0-9,]+)/', $text, $matches)) {
                    $reviews = (int) str_replace(',', '', $matches[1]);
                    break;
                }
            }
        }
        
        $success = ($rating > 0 || $reviews > 0);
        
        return [
            'rating' => $rating,
            'reviews' => $reviews,
            'success' => $success,
            'error' => $success ? '' : 'No data found via DOM parsing'
        ];
    }
    
    private function fetchPage($url) {
        $this->log("🌐 Fetching: $url");
        
        // Add delay before request
        sleep($this->delayBetweenRequests);
        
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_ENCODING => 'gzip, deflate',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9,hi;q=0.8',
                    'Accept-Encoding: gzip, deflate, br',
                    'Cache-Control: no-cache',
                    'Connection: keep-alive',
                    'Upgrade-Insecure-Requests: 1'
                ]
            ]);
            
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($content && $httpCode == 200) {
                return $content;
            }
        }
        
        return false;
    }
    
    private function log($message) {
        if ($this->debug) {
            logRatingUpdate($message);
        }
    }
}

// Main Cronjob Logic
try {
    logRatingUpdate("Starting Amazon Rating Update Cronjob");
    
    // Get products that need rating update
    // Conditions:
    // 1. tracking_count >= 100 (100+ users tracking)
    // 2. rating_updated_at is NULL OR older than 3 months
    // 3. merchant = 'amazon'
    
    $stmt = $pdo->prepare("
        SELECT asin, name, tracking_count, rating, rating_count, rating_updated_at
        FROM products 
        WHERE merchant = 'amazon' 
        AND tracking_count >= 100 
        AND (
            rating_updated_at IS NULL 
            OR rating_updated_at < DATE_SUB(NOW(), INTERVAL 3 MONTH)
        )
        ORDER BY tracking_count DESC 
        LIMIT 50
    ");
    
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalProducts = count($products);
    logRatingUpdate("Found {$totalProducts} products that need rating update");
    
    if ($totalProducts === 0) {
        logRatingUpdate("No products found for rating update. Exiting.");
        exit;
    }
    
    $extractor = new AmazonRatingCronExtractor();
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($products as $index => $product) {
        $asin = $product['asin'];
        $productName = substr($product['name'], 0, 50) . '...';
        $currentIndex = $index + 1;
        
        logRatingUpdate("Processing ({$currentIndex}/{$totalProducts}): {$productName}", [
            'asin' => $asin,
            'tracking_count' => $product['tracking_count'],
            'current_rating' => $product['rating'],
            'current_rating_count' => $product['rating_count']
        ]);
        
        try {
            $result = $extractor->getRatingAndReviews($asin, 'in');
            
            if ($result['success']) {
                // Update database
                $updateStmt = $pdo->prepare("
                    UPDATE products 
                    SET rating = ?, rating_count = ?, rating_updated_at = NOW() 
                    WHERE asin = ? AND merchant = 'amazon'
                ");
                $updateStmt->execute([$result['rating'], $result['reviews'], $asin]);
                
                $successCount++;
                logRatingUpdate("✅ Rating updated successfully", [
                    'asin' => $asin,
                    'old_rating' => $product['rating'],
                    'new_rating' => $result['rating'],
                    'old_review_count' => $product['rating_count'],
                    'new_review_count' => $result['reviews']
                ]);
                
            } else {
                $errorCount++;
                logRatingUpdate("❌ Failed to extract rating", [
                    'asin' => $asin,
                    'error' => $result['error']
                ]);
            }
            
        } catch (Exception $e) {
            $errorCount++;
            logRatingUpdate("❌ Exception occurred while processing product", [
                'asin' => $asin,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
        
        // Add delay between products to be respectful to Amazon
        if ($currentIndex < $totalProducts) {
            logRatingUpdate("⏳ Waiting 5 seconds before next product...");
            sleep(5);
        }
    }
    
    // Final summary
    logRatingUpdate("Amazon Rating Update Cronjob Completed", [
        'total_products' => $totalProducts,
        'successful_updates' => $successCount,
        'failed_updates' => $errorCount,
        'success_rate' => round(($successCount / $totalProducts) * 100, 2) . '%'
    ]);
    
} catch (Exception $e) {
    logRatingUpdate("❌ Fatal error in rating update cronjob", [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}

logRatingUpdate("Cronjob execution finished");
?>