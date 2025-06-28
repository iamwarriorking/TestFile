<?php
/**
 * Improved Amazon Rating & Review Count Extractor - 2025 Updated
 * AmezPrice Integration Test - Works with latest Amazon HTML structure
 * 
 * Updated for current Amazon page structure (June 2025)
 */

// Error reporting for testing
ini_set('display_errors', 1);
error_reporting(E_ALL);

class ImprovedAmazonRatingExtractor {
    
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';
    private $debug = true;
    
    /**
     * Get rating and review count for Amazon product
     * Updated for 2025 Amazon structure
     */
    public function getRatingAndReviews($asin, $domain = 'in') {
        
        if (empty($asin)) {
            return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'ASIN is required'];
        }
        
        // Clean ASIN
        $asin = trim(strtoupper($asin));
        
        $result = [
            'rating' => 0,
            'reviews' => 0,  
            'success' => false,
            'error' => '',
            'debug_info' => []
        ];
        
        // Try different approaches with updated methods
        $methods = [
            'product_page_advanced',
            'reviews_page_advanced',
            'json_extraction',
            'regex_fallback'
        ];
        
        foreach ($methods as $method) {
            $this->log("🔄 Trying method: $method");
            
            try {
                switch ($method) {
                    case 'product_page_advanced':
                        $data = $this->extractFromProductPageAdvanced($asin, $domain);
                        break;
                    case 'reviews_page_advanced':
                        $data = $this->extractFromReviewsPageAdvanced($asin, $domain);
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
        }
        
        return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'Unable to extract rating data from any method'];
    }
    
    /**
     * Advanced product page extraction with 2025 selectors
     */
    private function extractFromProductPageAdvanced($asin, $domain) {
        $url = "https://www.amazon.$domain/dp/$asin";
        $html = $this->fetchPage($url);
        
        if (!$html) {
            return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'Failed to fetch product page'];
        }
        
        $this->log("📄 HTML content length: " . strlen($html));
        
        // Try multiple parsing approaches
        $approaches = [
            'dom_parsing_2025',
            'json_ld_extraction',
            'meta_tag_extraction'
        ];
        
        foreach ($approaches as $approach) {
            $result = $this->parseHTMLAdvanced($html, $approach);
            if ($result['success']) {
                return $result;
            }
        }
        
        return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'No data found in product page'];
    }
    
    /**
     * Advanced reviews page extraction 
     */
    private function extractFromReviewsPageAdvanced($asin, $domain) {
        $url = "https://www.amazon.$domain/product-reviews/$asin";
        $html = $this->fetchPage($url);
        
        if (!$html) {
            return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'Failed to fetch reviews page'];
        }
        
        return $this->parseReviewsPageAdvanced($html);
    }
    
    /**
     * Extract from JSON-LD structured data
     */
    private function extractFromJSONData($asin, $domain) {
        $url = "https://www.amazon.$domain/dp/$asin";
        $html = $this->fetchPage($url);
        
        if (!$html) {
            return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'Failed to fetch for JSON extraction'];
        }
        
        // Look for JSON-LD structured data
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
    
    /**
     * Regex-based fallback extraction
     */
    private function extractUsingRegex($asin, $domain) {
        $url = "https://www.amazon.$domain/dp/$asin";
        $html = $this->fetchPage($url);
        
        if (!$html) {
            return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'Failed to fetch for regex extraction'];
        }
        
        $rating = 0;
        $reviews = 0;
        
        // Modern regex patterns for 2025 Amazon structure
        $ratingPatterns = [
            '/([0-9]\.[0-9])\s*out\s*of\s*5\s*stars/i',
            '/rating["\']:\s*["\']?([0-9]\.[0-9])["\']?/i',
            '/ratingValue["\']:\s*["\']?([0-9]\.[0-9])["\']?/i',
            '/average[^>]*rating[^>]*["\']([0-9]\.[0-9])["\']?/i',
            '/"ratingValue":"([0-9]\.[0-9])"/i'
        ];
        
        $reviewPatterns = [
            '/([0-9,]+)\s*(?:customer\s*)?(?:global\s*)?ratings?/i',
            '/([0-9,]+)\s*(?:customer\s*)?reviews?/i',
            '/reviewCount["\']:\s*["\']?([0-9,]+)["\']?/i',
            '/"reviewCount":"([0-9,]+)"/i',
            '/([0-9,]+)\s*total\s*ratings?/i'
        ];
        
        // Try rating patterns
        foreach ($ratingPatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $rating = (float)$matches[1];
                $this->log("✅ Found rating via regex: $rating (pattern: $pattern)");
                break;
            }
        }
        
        // Try review patterns
        foreach ($reviewPatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $reviews = (int)str_replace(',', '', $matches[1]);
                $this->log("✅ Found reviews via regex: $reviews (pattern: $pattern)");
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
    
    /**
     * Advanced HTML parsing with 2025 selectors
     */
    private function parseHTMLAdvanced($html, $approach) {
        if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            return ['rating' => 0, 'reviews' => 0, 'success' => false, 'error' => 'DOM classes not available'];
        }
        
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);
        
        $rating = 0;
        $reviews = 0;
        
        switch ($approach) {
            case 'dom_parsing_2025':
                // Updated selectors for 2025 Amazon structure
                $ratingSelectors = [
                    "//span[@class='a-icon-alt' and contains(text(), 'out of')]",
                    "//span[@data-hook='rating-out-of-text']",
                    "//span[contains(@class, 'a-offscreen') and contains(text(), 'out of')]",
                    "//div[@data-hook='average-star-rating']//span[@class='a-offscreen']",
                    "//span[@id='acrPopover']//span[@class='a-offscreen']",
                    "//i[contains(@class, 'a-icon-star')]//span[@class='a-offscreen']",
                    "//div[@data-hook='average-star-rating']//i//span",
                    "//span[contains(@aria-label, 'out of 5')]",
                    "//div[@id='averageCustomerReviews']//span[@class='a-offscreen']"
                ];
                
                foreach ($ratingSelectors as $selector) {
                    $nodes = $xpath->query($selector);
                    if ($nodes->length > 0) {
                        $text = trim($nodes->item(0)->textContent);
                        $this->log("🔍 Found rating text: '$text'");
                        
                        // Extract rating with improved regex
                        if (preg_match('/([0-9]+[,\.]?[0-9]*)\s*(?:out\s*of|von|sur|de)/i', $text, $matches)) {
                            $rating = (float) str_replace(',', '.', $matches[1]);
                            if ($rating > 5) $rating = 5;
                            $this->log("✅ Extracted rating: $rating");
                            break;
                        }
                    }
                }
                
                // Updated review selectors for 2025
                $reviewSelectors = [
                    "//span[@data-hook='total-review-count']",
                    "//a[@data-hook='see-all-reviews-link-foot']//span",
                    "//div[@data-hook='total-review-count']",
                    "//span[@id='acrCustomerReviewText']",
                    "//a[contains(@href, '#customerReviews')]//span",
                    "//div[@id='averageCustomerReviews']//a//span",
                    "//span[contains(text(), 'ratings') or contains(text(), 'reviews') or contains(text(), 'global ratings')]",
                    "//a[@data-hook='see-all-reviews-link-foot']"
                ];
                
                foreach ($reviewSelectors as $selector) {
                    $nodes = $xpath->query($selector);
                    if ($nodes->length > 0) {
                        $text = trim($nodes->item(0)->textContent);
                        $this->log("🔍 Found review text: '$text'");
                        
                        // Extract review count with improved regex
                        if (preg_match('/([0-9,]+)/', $text, $matches)) {
                            $reviews = (int) str_replace(',', '', $matches[1]);
                            $this->log("✅ Extracted reviews: $reviews");
                            break;
                        }
                    }
                }
                break;
                
            case 'json_ld_extraction':
                // Already handled in extractFromJSONData
                break;
                
            case 'meta_tag_extraction':
                // Look for meta tags with rating info
                $metaSelectors = [
                    "//meta[@property='product:rating:value']/@content",
                    "//meta[@property='product:review:count']/@content",
                    "//meta[@name='rating']/@content",
                    "//meta[@name='review_count']/@content"
                ];
                
                foreach ($metaSelectors as $selector) {
                    $nodes = $xpath->query($selector);
                    if ($nodes->length > 0) {
                        $value = trim($nodes->item(0)->nodeValue);
                        if (strpos($selector, 'rating') !== false && !$rating) {
                            $rating = (float)$value;
                        } elseif (strpos($selector, 'review') !== false && !$reviews) {
                            $reviews = (int)$value;
                        }
                    }
                }
                break;
        }
        
        $success = ($rating > 0 || $reviews > 0);
        
        return [
            'rating' => $rating,
            'reviews' => $reviews,
            'success' => $success,
            'error' => $success ? '' : "No data found via $approach"
        ];
    }
    
    /**
     * Advanced reviews page parsing
     */
    private function parseReviewsPageAdvanced($html) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);
        
        $rating = 0;
        $reviews = 0;
        
        // Reviews page specific selectors
        $ratingSelectors = [
            "//div[@data-hook='rating-out-of-text']",
            "//span[@data-hook='rating-out-of-text']",
            "//div[contains(@class, 'averageStarRating')]//span",
            "//i[contains(@class, 'a-icon-star')]//span[@class='a-offscreen']"
        ];
        
        foreach ($ratingSelectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                $text = trim($nodes->item(0)->textContent);
                $this->log("🔍 Reviews page rating text: '$text'");
                
                if (preg_match('/([0-9]+[,\.]?[0-9]*)\s*(?:out\s*of|von|sur|de)/i', $text, $matches)) {
                    $rating = (float) str_replace(',', '.', $matches[1]);
                    if ($rating > 5) $rating = 5;
                    break;
                }
            }
        }
        
        $reviewSelectors = [
            "//div[@data-hook='cr-filter-info-review-rating-count']",
            "//div[contains(@class, 'totalReviewCount')]",
            "//span[contains(text(), 'total ratings')]"
        ];
        
        foreach ($reviewSelectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                $text = trim($nodes->item(0)->textContent);
                $this->log("🔍 Reviews page count text: '$text'");
                
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
            'error' => $success ? '' : 'No data found in reviews page'
        ];
    }
    
    /**
     * Enhanced page fetching with better headers and error handling
     */
    private function fetchPage($url) {
        $this->log("🌐 Fetching: $url");
        
        // Method 1: cURL with enhanced headers
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
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9,hi;q=0.8',
                    'Accept-Encoding: gzip, deflate, br',
                    'Cache-Control: no-cache',
                    'Connection: keep-alive',
                    'Upgrade-Insecure-Requests: 1',
                    'Sec-Fetch-Dest: document',
                    'Sec-Fetch-Mode: navigate',
                    'Sec-Fetch-Site: none',
                    'DNT: 1'
                ]
            ]);
            
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($content && $httpCode == 200) {
                $this->log("✅ Successfully fetched via cURL (Size: " . strlen($content) . " bytes)");
                return $content;
            } else {
                $this->log("❌ cURL failed: HTTP $httpCode, Error: $error");
            }
        }
        
        // Method 2: file_get_contents with enhanced context
        if (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => 
                        "User-Agent: {$this->userAgent}\r\n" .
                        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n" .
                        "Accept-Language: en-US,en;q=0.9\r\n" .
                        "Accept-Encoding: gzip, deflate\r\n" .
                        "Connection: close\r\n",
                    'timeout' => 30,
                    'ignore_errors' => true
                ]
            ]);
            
            $content = @file_get_contents($url, false, $context);
            if ($content) {
                $this->log("✅ Successfully fetched via file_get_contents (Size: " . strlen($content) . " bytes)");
                return $content;
            } else {
                $this->log("❌ file_get_contents failed");
            }
        }
        
        return false;
    }
    
    /**
     * Enhanced debug logging
     */
    private function log($message) {
        if ($this->debug) {
            echo "[" . date('Y-m-d H:i:s') . "] $message\n";
            flush();
        }
    }
}

// Test Interface - Enhanced UI
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🚀 Improved Amazon Rating Extractor - AmezPrice 2025</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        h1 {
            color: #232F3E;
            text-align: center;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 16px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        input[type="text"], select {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        input[type="text"]:focus, select:focus {
            border-color: #FF9900;
            outline: none;
        }
        button {
            background: linear-gradient(45deg, #FF9900, #FF6B00);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s;
        }
        button:hover {
            transform: translateY(-2px);
        }
        .result {
            margin-top: 30px;
            padding: 25px;
            border-radius: 10px;
        }
        .success {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .error {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
            border: none;
            box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
        }
        .rating-display {
            font-size: 24px;
            text-align: center;
            margin: 20px 0;
            padding: 20px;
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
        }
        .stars {
            color: #FFD700;
            font-size: 35px;
            margin-bottom: 10px;
        }
        .debug-log {
            background-color: #1e1e1e;
            color: #00ff00;
            border: 1px solid #333;
            padding: 20px;
            margin-top: 20px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 400px;
            overflow-y: auto;
        }
        .examples {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
        }
        .examples h3 {
            margin-top: 0;
            color: #333;
        }
        .example-asin {
            background-color: rgba(255,255,255,0.8);
            padding: 8px 12px;
            border-radius: 5px;
            font-family: monospace;
            margin: 5px;
            display: inline-block;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        .example-asin:hover {
            background-color: #FF9900;
            color: white;
            transform: scale(1.05);
        }
        .integration-code {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        .integration-code textarea {
            width: 100%;
            height: 150px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            border: none;
            background: transparent;
            resize: vertical;
        }
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .status-success { background-color: #28a745; }
        .status-error { background-color: #dc3545; }
        .status-warning { background-color: #ffc107; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Improved Amazon Rating Extractor</h1>
        <p class="subtitle">AmezPrice Integration Test - Updated for 2025 Amazon Structure</p>
        
        <div class="examples">
            <h3>📝 Test ASINs (Click to use):</h3>
            <span class="example-asin" onclick="document.getElementById('asin').value='B0D965C25Y'">B0D965C25Y</span>
            <span class="example-asin" onclick="document.getElementById('asin').value='B08N5WRWNW'">B08N5WRWNW</span>
            <span class="example-asin" onclick="document.getElementById('asin').value='B07HGJKJL2'">B07HGJKJL2</span>
            <span class="example-asin" onclick="document.getElementById('asin').value='B08CFSZLQ4'">B08CFSZLQ4</span>
            <span class="example-asin" onclick="document.getElementById('asin').value='B0863TXGM3'">B0863TXGM3</span>
        </div>

        <form method="POST">
            <div class="form-group">
                <label for="asin">🏷️ Amazon ASIN:</label>
                <input type="text" id="asin" name="asin" placeholder="Enter Amazon ASIN (e.g., B0D965C25Y)" 
                       value="<?php echo isset($_POST['asin']) ? htmlspecialchars($_POST['asin']) : ''; ?>" required>
            </div>
            
            <div class="form-group">
                <label for="domain">🌍 Amazon Domain:</label>
                <select id="domain" name="domain">
                    <option value="in" <?php echo (isset($_POST['domain']) && $_POST['domain'] == 'in') ? 'selected' : ''; ?>>🇮🇳 India (.in)</option>
                    <option value="com" <?php echo (isset($_POST['domain']) && $_POST['domain'] == 'com') ? 'selected' : ''; ?>>🇺🇸 USA (.com)</option>
                    <option value="co.uk" <?php echo (isset($_POST['domain']) && $_POST['domain'] == 'co.uk') ? 'selected' : ''; ?>>🇬🇧 UK (.co.uk)</option>
                    <option value="de" <?php echo (isset($_POST['domain']) && $_POST['domain'] == 'de') ? 'selected' : ''; ?>>🇩🇪 Germany (.de)</option>
                </select>
            </div>
            
            <button type="submit">🚀 Extract Rating & Reviews</button>
        </form>

        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['asin'])) {
            $asin = trim($_POST['asin']);
            $domain = $_POST['domain'] ?? 'in';
            
            echo "<div class='result'>";
            echo "<h3>🔄 Processing ASIN: $asin</h3>";
            
            // Start output buffering to capture debug logs
            ob_start();
            
            $extractor = new ImprovedAmazonRatingExtractor();
            $result = $extractor->getRatingAndReviews($asin, $domain);
            
            // Get debug logs
            $debugLog = ob_get_contents();
            ob_end_clean();
            
            if ($result['success']) {
                echo "<div class='success'>";
                echo "<div class='rating-display'>";
                echo "<div class='stars'>";
                
                // Display stars
                $fullStars = floor($result['rating']);
                $hasHalfStar = ($result['rating'] - $fullStars) >= 0.5;
                
                for ($i = 0; $i < $fullStars; $i++) {
                    echo "★";
                }
                if ($hasHalfStar) {
                    echo "⭐";
                }
                for ($i = $fullStars + ($hasHalfStar ? 1 : 0); $i < 5; $i++) {
                    echo "☆";
                }
                
                echo "</div>";
                echo "<strong>⭐ Rating: {$result['rating']}/5</strong><br>";
                echo "<strong>💬 Reviews: " . number_format($result['reviews']) . "</strong>";
                echo "</div>";
                
                // Display integration code
                echo "<div class='integration-code'>";
                echo "<h4>📋 AmezPrice Integration Code:</h4>";
                echo "<textarea readonly>";
                echo "// Database Schema Update:\n";
                echo "ALTER TABLE products ADD COLUMN rating DECIMAL(2,1) DEFAULT 0;\n";
                echo "ALTER TABLE products ADD COLUMN review_count INT DEFAULT 0;\n";
                echo "ALTER TABLE products ADD COLUMN rating_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;\n\n";
                echo "// PHP Integration:\n";
                echo "\$extractor = new ImprovedAmazonRatingExtractor();\n";
                echo "\$data = \$extractor->getRatingAndReviews('{$asin}', '{$domain}');\n";
                echo "if (\$data['success']) {\n";
                echo "    \$rating = \$data['rating']; // {$result['rating']}\n";
                echo "    \$reviews = \$data['reviews']; // {$result['reviews']}\n";
                echo "    \n";
                echo "    // Update your database\n";
                echo "    \$stmt = \$pdo->prepare(\"UPDATE products SET rating = ?, review_count = ?, rating_updated_at = NOW() WHERE asin = ?\");\n";
                echo "    \$stmt->execute([\$rating, \$reviews, '{$asin}']);\n";
                echo "}";
                echo "</textarea>";
                echo "</div>";
                
                echo "</div>";
            } else {
                echo "<div class='error'>";
                echo "<div class='status-indicator status-error'></div>";
                echo "<h4>❌ Failed to extract rating data</h4>";
                echo "<p><strong>Error:</strong> {$result['error']}</p>";
                echo "<p><strong>Suggestions:</strong></p>";
                echo "<ul>";
                echo "<li>Try a different ASIN - some products may not have ratings yet</li>";
                echo "<li>Check if the ASIN exists on the selected Amazon domain</li>";
                echo "<li>Amazon might be blocking requests - try again after a few minutes</li>";
                echo "<li>The product page structure might have changed</li>";
                echo "</ul>";
                echo "</div>";
            }
            
            // Show debug log
            if (!empty($debugLog)) {
                echo "<div class='debug-log'>";
                echo "<h4>🐛 Debug Log:</h4>";
                echo "<pre>" . htmlspecialchars($debugLog) . "</pre>";
                echo "</div>";
            }
            
            echo "</div>";
        }
        ?>
        
        <div style="margin-top: 40px; text-align: center; color: #666; font-size: 12px;">
            <p>🔧 Built for AmezPrice | Advanced Amazon Data Extraction</p>
            <p>💡 Uses multiple extraction methods for maximum reliability</p>
            <p>🚀 Updated for 2025 Amazon structure with JSON-LD, DOM parsing, and regex fallbacks</p>
        </div>
    </div>
</body>
</html>