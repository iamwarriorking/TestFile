<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

class AmazonRatingExtractor {
    
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';
    private $debug = false;
    
    /**
     * Get rating and review count for Amazon product
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
        
        // Try different approaches
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
     * Advanced product page extraction
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
        
        // Modern regex patterns
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
                $this->log("✅ Found rating via regex: $rating");
                break;
            }
        }
        
        // Try review patterns
        foreach ($reviewPatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $reviews = (int)str_replace(',', '', $matches[1]);
                $this->log("✅ Found reviews via regex: $reviews");
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
     * Advanced HTML parsing
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
                        
                        if (preg_match('/([0-9]+[,\.]?[0-9]*)\s*(?:out\s*of|von|sur|de)/i', $text, $matches)) {
                            $rating = (float) str_replace(',', '.', $matches[1]);
                            if ($rating > 5) $rating = 5;
                            $this->log("✅ Extracted rating: $rating");
                            break;
                        }
                    }
                }
                
                // Updated review selectors
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
                        
                        if (preg_match('/([0-9,]+)/', $text, $matches)) {
                            $reviews = (int) str_replace(',', '', $matches[1]);
                            $this->log("✅ Extracted reviews: $reviews");
                            break;
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
     * Enhanced page fetching
     */
    private function fetchPage($url) {
        $this->log("🌐 Fetching: $url");
        
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
                $this->log("✅ Successfully fetched via cURL");
                return $content;
            } else {
                $this->log("❌ cURL failed: HTTP $httpCode, Error: $error");
            }
        }
        
        return false;
    }
    
    /**
     * Debug logging
     */
    private function log($message) {
        if ($this->debug) {
            error_log("[AmazonRating] $message");
        }
    }
}

// 🔥 FIXED: Main API Logic - Removed product existence check for direct calls
try {
    $input = json_decode(file_get_contents('php://input'), true);
    $asin = $input['asin'] ?? '';
    $domain = $input['domain'] ?? 'in';
    $force = $input['force'] ?? false;
    $directCall = $input['direct_call'] ?? false; // 🔥 NEW: For calls from amazon.php
    
    if (empty($asin)) {
        echo json_encode(['status' => 'error', 'message' => 'ASIN is required']);
        exit;
    }
    
    // 🔥 FIXED: Skip database check for direct calls from amazon.php
    if (!$directCall) {
        // Check if product exists (only for regular API calls)
        $stmt = $pdo->prepare("SELECT * FROM products WHERE asin = ? AND merchant = 'amazon'");
        $stmt->execute([$asin]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            echo json_encode(['status' => 'error', 'message' => 'Product not found']);
            exit;
        }
        
        // Check if rating already exists (unless forced)
        if (!$force && ($product['rating'] > 0 || $product['rating_count'] > 0)) {
            echo json_encode([
                'status' => 'exists',
                'message' => 'Rating already exists',
                'rating' => (float)$product['rating'],
                'rating_count' => (int)$product['rating_count']
            ]);
            exit;
        }
    }
    
    // Extract rating from Amazon
    $extractor = new AmazonRatingExtractor();
    $result = $extractor->getRatingAndReviews($asin, $domain);
    
    if ($result['success']) {
        // 🔥 FIXED: Only update database if not a direct call
        if (!$directCall) {
            $stmt = $pdo->prepare("
                UPDATE products 
                SET rating = ?, rating_count = ?, rating_updated_at = NOW() 
                WHERE asin = ? AND merchant = 'amazon'
            ");
            $stmt->execute([$result['rating'], $result['reviews'], $asin]);
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Rating extracted successfully',
            'rating' => $result['rating'],
            'rating_count' => $result['reviews'],
            'asin' => $asin
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to extract rating: ' . $result['error'],
            'rating' => 0,
            'rating_count' => 0
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
}
?>