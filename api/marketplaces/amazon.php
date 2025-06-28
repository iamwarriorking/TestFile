<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

// Load Amazon configuration properly
$amazonConfig = require __DIR__ . '/../../config/amazon.php';
// Load category filter
$categoryFilter = require __DIR__ . '/../../config/category_filter.php';

// Correct namespace imports
use Amazon\ProductAdvertisingAPI\v1\ApiException;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\api\DefaultApi;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\GetItemsRequest;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\GetItemsResource;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\SearchItemsRequest;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\SearchItemsResource;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\PartnerType;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\ProductAdvertisingAPIClientException;
use Amazon\ProductAdvertisingAPI\v1\Configuration;
use GuzzleHttp\Client;

function logError($message, Exception $e) {
    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/amazon_errors.log';
    $logEntry = sprintf(
        "[%s] %s: %s\nStack trace:\n%s\n\n",
        date('Y-m-d H:i:s'),
        $message,
        $e->getMessage(),
        $e->getTraceAsString()
    );
    
    if (!file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX)) {
        error_log("Failed to write to amazon_errors.log: " . $message . " - " . $e->getMessage());
    }
}

function logAmazonAPI($message, $data = []) {
    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/amazon_api.log';
    $logData = array_merge([
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => $message
    ], $data);
    
    file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);
}

function initializeAmazonClient() {
    global $amazonConfig;

    if (!$amazonConfig || !is_array($amazonConfig)) {
        error_log("Amazon configuration is null or invalid");
        return null;
    }

    $requiredFields = ['access_key', 'secret_key', 'associate_tag', 'marketplace', 'region'];
    foreach ($requiredFields as $field) {
        if (!isset($amazonConfig[$field]) || empty($amazonConfig[$field])) {
            error_log("Missing required Amazon configuration field: $field");
            return null;
        }
    }

    try {
        $config = new Configuration();
        $config->setAccessKey($amazonConfig['access_key']);
        $config->setSecretKey($amazonConfig['secret_key']);
        $config->setHost($amazonConfig['marketplace']);
        $config->setRegion($amazonConfig['region']);

        return new DefaultApi(new Client(), $config);
        
    } catch (Exception $e) {
        logError('Amazon Client Initialization Error', $e);
        return null;
    }
}

function validateASIN($asin) {
    if (!$asin || strlen($asin) !== 10) {
        return false;
    }
    
    if (!preg_match('/^[A-Z0-9]{10}$/', $asin)) {
        return false;
    }
    
    return true;
}

function parseStockQuantityFromMessage($availabilityMessage) {
    if (!$availabilityMessage) {
        return 0;
    }
    
    $message = strtolower(trim($availabilityMessage));
    
    $patterns = [
        '/only (\d+) left in stock/i' => function($matches) { return (int)$matches[1]; },
        '/(\d+) in stock/i' => function($matches) { return (int)$matches[1]; },
        '/(\d+) available/i' => function($matches) { return (int)$matches[1]; },
        '/(\d+) left/i' => function($matches) { return (int)$matches[1]; },
        '/(\d+) remaining/i' => function($matches) { return (int)$matches[1]; },
        '/limited stock.*(\d+)/i' => function($matches) { return (int)$matches[1]; },
        '/temporarily out of stock/i' => function() { return 0; },
        '/currently unavailable/i' => function() { return 0; },
        '/out of stock/i' => function() { return 0; },
        '/discontinued/i' => function() { return 0; },
        '/usually ships within/i' => function() { return 10; },
        '/in stock soon/i' => function() { return 0; },
        '/in stock/i' => function() { return 10; },
        '/available/i' => function() { return 10; }
    ];
    
    foreach ($patterns as $pattern => $callback) {
        if (preg_match($pattern, $message, $matches)) {
            return $callback($matches);
        }
    }
    
    if (strpos($message, 'stock') !== false || strpos($message, 'available') !== false) {
        return 10;
    }
    
    return 0;
}

// FILTER 1: Check if category/subcategory contains numbers
function hasNumbers($text) {
    return preg_match('/\d/', $text);
}

// NEW FILTER: Check if category is in allowed words (even if it has numbers)
function isAllowedCategory($text) {
    global $categoryFilter;
    
    if (!isset($categoryFilter['allowed_words'])) {
        return false;
    }
    
    $textLower = strtolower(trim($text));
    
    foreach ($categoryFilter['allowed_words'] as $allowedWord) {
        if (strtolower(trim($allowedWord)) === $textLower) {
            logAmazonAPI('Category allowed due to allowed_words list', [
                'category' => $text,
                'matched_allowed_word' => $allowedWord
            ]);
            return true;
        }
    }
    
    return false;
}

// FILTER 2: Check if brand name appears in category
function isBrandInCategory($brandName, $categoryName, $subcategoryName) {
    if (!$brandName || $brandName === 'Generic' || $brandName === 'Unknown') {
        return false;
    }
    
    $brandLower = strtolower(trim($brandName));
    $categoryLower = strtolower(trim($categoryName));
    $subcategoryLower = strtolower(trim($subcategoryName));
    
    // Check if brand name appears in category or subcategory
    return (strpos($categoryLower, $brandLower) !== false || 
            strpos($subcategoryLower, $brandLower) !== false);
}

// Updated function with all filters including allowed_words check
function extractCategoryFromBrowseNodes($browseNodeInfo, $brandName = null) {
    global $categoryFilter;
    
    if (!$browseNodeInfo || !$browseNodeInfo->getBrowseNodes()) {
        return ['category' => 'General', 'subcategory' => 'General'];
    }
    
    $browseNodes = $browseNodeInfo->getBrowseNodes();
    $category = 'General';
    $subcategory = 'General';
    
    foreach ($browseNodes as $node) {
        if ($node->getDisplayName()) {
            $nodeName = trim($node->getDisplayName());
            
            // FILTER 1: Skip existing category filter (multiple words)
            if (shouldFilterCategory($nodeName, $categoryFilter['keywords'])) {
                logAmazonAPI('Filtering out browse node (keywords)', ['filtered_node' => $nodeName]);
                continue;
            }
            
            // FILTER 2: Skip if category/subcategory contains numbers (UNLESS it's in allowed_words)
            if (hasNumbers($nodeName) && !isAllowedCategory($nodeName)) {
                logAmazonAPI('Filtering out browse node (contains numbers)', ['filtered_node' => $nodeName]);
                continue;
            }
            
            // If we reach here, the category is allowed
            if (hasNumbers($nodeName) && isAllowedCategory($nodeName)) {
                logAmazonAPI('Allowing browse node with numbers (in allowed_words)', ['allowed_node' => $nodeName]);
            }
            
            $subcategory = $nodeName;
            
            $ancestor = $node->getAncestor();
            while ($ancestor !== null) {
                if ($ancestor->getDisplayName()) {
                    $ancestorName = trim($ancestor->getDisplayName());
                    
                    // Apply same filters to ancestors
                    if (shouldFilterCategory($ancestorName, $categoryFilter['keywords'])) {
                        logAmazonAPI('Filtering out ancestor node (keywords)', ['filtered_ancestor' => $ancestorName]);
                        $nextAncestor = $ancestor->getAncestor();
                        if ($nextAncestor === null) {
                            break;
                        }
                        $ancestor = $nextAncestor;
                        continue;
                    }
                    
                    // FIXED: Apply allowed_words check to ancestors too
                    if (hasNumbers($ancestorName) && !isAllowedCategory($ancestorName)) {
                        logAmazonAPI('Filtering out ancestor node (contains numbers)', ['filtered_ancestor' => $ancestorName]);
                        $nextAncestor = $ancestor->getAncestor();
                        if ($nextAncestor === null) {
                            break;
                        }
                        $ancestor = $nextAncestor;
                        continue;
                    }
                    
                    // If ancestor has numbers but is allowed
                    if (hasNumbers($ancestorName) && isAllowedCategory($ancestorName)) {
                        logAmazonAPI('Allowing ancestor node with numbers (in allowed_words)', ['allowed_ancestor' => $ancestorName]);
                    }
                    
                    $category = $ancestorName;
                    $nextAncestor = $ancestor->getAncestor();
                    if ($nextAncestor === null) {
                        break;
                    }
                    $ancestor = $nextAncestor;
                } else {
                    break;
                }
            }
            
            if ($subcategory !== 'General') {
                break;
            }
        }
    }
    
    $nonCategoryTerms = ['all categories', 'amazon', 'store', 'shop', 'categories', 'department'];
    $category = array_filter([strtolower($category)], function($cat) use ($nonCategoryTerms) {
        return !in_array($cat, $nonCategoryTerms);
    })[0] ?? 'General';
    $subcategory = array_filter([strtolower($subcategory)], function($cat) use ($nonCategoryTerms) {
        return !in_array($cat, $nonCategoryTerms);
    })[0] ?? 'General';
    
    $category = ucwords($category);
    $subcategory = ucwords($subcategory);
    
    // FILTER 3: Final check - if brand name appears in category, filter it out
    if ($brandName && isBrandInCategory($brandName, $category, $subcategory)) {
        logAmazonAPI('Filtering out category (brand name detected)', [
            'brand' => $brandName,
            'category' => $category,
            'subcategory' => $subcategory
        ]);
        return ['category' => 'General', 'subcategory' => 'General'];
    }
    
    logAmazonAPI('Final category extraction result', [
        'category' => $category,
        'subcategory' => $subcategory,
        'brand' => $brandName
    ]);
    
    return [
        'category' => $category,
        'subcategory' => $subcategory
    ];
}

// Helper function to check if category should be filtered
function shouldFilterCategory($categoryName, $filterKeywords) {
    $categoryLower = strtolower($categoryName);
    
    foreach ($filterKeywords as $keyword) {
        if (strpos($categoryLower, strtolower($keyword)) !== false) {
            return true; // Filter this category
        }
    }
    
    return false; // Don't filter this category
}

function parseAmazonCategoryBreadcrumb($breadcrumb) {
    if (!$breadcrumb) {
        return ['category' => 'General', 'subcategory' => 'General'];
    }
    
    $categories = preg_split('/[›>]/', $breadcrumb);
    $categories = array_map('trim', $categories);
    $categories = array_filter($categories, function($cat) {
        return !empty($cat) && strlen($cat) > 1;
    });
    
    if (empty($categories)) {
        return ['category' => 'General', 'subcategory' => 'General'];
    }
    
    if (count($categories) == 1) {
        $category = $categories[0];
        $subcategory = $categories[0];
    } else {
        $category = $categories[0];
        $subcategory = end($categories);
    }
    
    $category = ucwords(strtolower($category));
    $subcategory = ucwords(strtolower($subcategory));
    
    return [
        'category' => $category,
        'subcategory' => $subcategory
    ];
}

function extractBrandFromItemInfo($itemInfo) {
    if (!$itemInfo) {
        return null;
    }
    
    if ($itemInfo->getByLineInfo() && 
        $itemInfo->getByLineInfo()->getBrand() && 
        $itemInfo->getByLineInfo()->getBrand()->getDisplayValue()) {
        return trim($itemInfo->getByLineInfo()->getBrand()->getDisplayValue());
    }
    
    if ($itemInfo->getByLineInfo() && 
        $itemInfo->getByLineInfo()->getManufacturer() && 
        $itemInfo->getByLineInfo()->getManufacturer()->getDisplayValue()) {
        return trim($itemInfo->getByLineInfo()->getManufacturer()->getDisplayValue());
    }
    
    return null;
}

function extractBrandFromTitle($title) {
    if (!$title) {
        return null;
    }
    
    $brandPatterns = [
        '/^([A-Za-z0-9&\s]+?)\s+[A-Za-z0-9]/i',
        '/by\s+([A-Za-z0-9&\s]+)/i',
        '/from\s+([A-Za-z0-9&\s]+)/i'
    ];
    
    foreach ($brandPatterns as $pattern) {
        if (preg_match($pattern, $title, $matches)) {
            $brand = trim($matches[1]);
            if (strlen($brand) > 2 && strlen($brand) < 50) {
                return $brand;
            }
        }
    }
    
    $words = explode(' ', $title);
    if (count($words) > 0 && strlen($words[0]) > 2) {
        return $words[0];
    }
    
    return null;
}

// Function to format professional error messages
function formatApiErrorMessage($errorCode, $errorMessage) {
    // Check for specific Amazon API error codes and provide professional messages
    if (strpos($errorCode, 'ItemNotAccessible') !== false) {
        return 'Amazon does not provide any data for this product. As such, we are unable to track it.';
    }
    
    // Handle other common Amazon API errors professionally
    $professionalMessages = [
        'InvalidParameterValue' => 'The product information could not be retrieved due to invalid parameters.',
        'RequestThrottled' => 'Too many requests have been made. Please try again in a few moments.',
        'AccessDenied' => 'Access to this product information is currently restricted.',
        'ItemsNotFound' => 'The requested product could not be found in Amazon\'s database.',
        'TooManyRequests' => 'Service is temporarily busy. Please try again later.',
        'InternalError' => 'A temporary service issue occurred. Please try again later.',
    ];
    
    // Check if we have a professional message for this error code
    foreach ($professionalMessages as $code => $message) {
        if (strpos($errorCode, $code) !== false) {
            return $message;
        }
    }
    
    // Default professional message for unknown errors
    return 'The product information could not be retrieved at this time. Please try again later.';
}


function getRatingFromScraping($asin, $domain = 'in') {
    logAmazonAPI('Attempting to get rating via scraping', ['asin' => $asin]);
    
    try {
        // 🔥 FIXED: Add direct_call parameter
        $postData = json_encode([
            'asin' => $asin,
            'domain' => $domain,
            'force' => false,
            'direct_call' => true  // 🔥 NEW: Skip database check
        ]);
        
        // Get base URL dynamically
        $baseUrl = 'https://amezprice.com'; // Your domain
        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
        }
        
        // Make HTTP request to amazon_rating.php
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . '/api/amazon_rating.php',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($postData)
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response && $httpCode == 200) {
            $data = json_decode($response, true);
            
            if ($data && $data['status'] === 'success') {
                logAmazonAPI('Rating scraping successful', [
                    'asin' => $asin,
                    'rating' => $data['rating'],
                    'rating_count' => $data['rating_count']
                ]);
                
                return [
                    'success' => true,
                    'rating' => (float)$data['rating'],
                    'rating_count' => (int)$data['rating_count']
                ];
            } else {
                logAmazonAPI('Rating scraping failed', [
                    'asin' => $asin,
                    'response' => $data
                ]);
            }
        } else {
            logAmazonAPI('Rating scraping HTTP error', [
                'asin' => $asin,
                'http_code' => $httpCode,
                'error' => $error
            ]);
        }
        
    } catch (Exception $e) {
        logAmazonAPI('Rating scraping exception', [
            'asin' => $asin,
            'message' => $e->getMessage()
        ]);
    }
    
    return [
        'success' => false,
        'rating' => 0.0,
        'rating_count' => 0
    ];
}

function fetchAmazonProduct($asin) {
    global $amazonConfig;

    logAmazonAPI('fetchAmazonProduct called', ['input_asin' => $asin]);

    if (!$amazonConfig || !is_array($amazonConfig)) {
        error_log("Amazon configuration is null or invalid in fetchAmazonProduct");
        return ['status' => 'error', 'message' => 'Configuration error'];
    }

    $asin = strtoupper(trim($asin));
    
    if (!validateASIN($asin)) {
        logAmazonAPI('Invalid ASIN format', [
            'asin' => $asin,
            'length' => strlen($asin),
            'pattern_match' => preg_match('/^[A-Z0-9]{10}$/', $asin)
        ]);
        return ['status' => 'error', 'message' => 'Invalid ASIN format. Must be 10 alphanumeric characters.'];
    }

    $client = initializeAmazonClient();
    if (!$client) {
        return ['status' => 'error', 'message' => 'Failed to initialize Amazon client'];
    }

    try {
        $getItemsRequest = new GetItemsRequest();
        $getItemsRequest->setItemIds([$asin]);
        $getItemsRequest->setPartnerTag($amazonConfig['associate_tag']);
        $getItemsRequest->setPartnerType(PartnerType::ASSOCIATES);
        
        $getItemsRequest->setResources([
            GetItemsResource::ITEM_INFOTITLE,
            GetItemsResource::ITEM_INFOBY_LINE_INFO,
            GetItemsResource::BROWSE_NODE_INFOBROWSE_NODES,
            GetItemsResource::BROWSE_NODE_INFOBROWSE_NODESANCESTOR,
            GetItemsResource::OFFERSLISTINGSPRICE,
            GetItemsResource::OFFERSLISTINGSSAVING_BASIS,  // 🔥 FIXED: Added MRP field
            GetItemsResource::OFFERSLISTINGSAVAILABILITYMESSAGE,
            GetItemsResource::IMAGESPRIMARYLARGE,
            GetItemsResource::IMAGESPRIMARYMEDIUM,
            GetItemsResource::IMAGESPRIMARYSMALL,
            GetItemsResource::CUSTOMER_REVIEWSSTAR_RATING,
            GetItemsResource::CUSTOMER_REVIEWSCOUNT
        ]);

        $invalidPropertyList = $getItemsRequest->listInvalidProperties();
        if (count($invalidPropertyList) > 0) {
            logAmazonAPI('Invalid GetItems request parameters', [
                'invalid_properties' => $invalidPropertyList,
                'asin' => $asin
            ]);
            return ['status' => 'error', 'message' => 'Invalid request parameters: ' . implode(', ', $invalidPropertyList)];
        }

        logAmazonAPI('Sending request to Amazon API', ['asin' => $asin]);
        $getItemsResponse = $client->getItems($getItemsRequest);
        
        logAmazonAPI('Received response from Amazon API', [
            'has_items_result' => $getItemsResponse->getItemsResult() !== null,
            'items_count' => $getItemsResponse->getItemsResult() ? count($getItemsResponse->getItemsResult()->getItems() ?? []) : 0,
            'has_errors' => $getItemsResponse->getErrors() !== null && count($getItemsResponse->getErrors()) > 0
        ]);
        
        if ($getItemsResponse->getErrors() !== null && count($getItemsResponse->getErrors()) > 0) {
            $errors = [];
            $professionalMessage = '';
            
            foreach ($getItemsResponse->getErrors() as $error) {
                $errorCode = $error->getCode();
                $errorMessage = $error->getMessage();
                $errors[] = $errorCode . ': ' . $errorMessage;
                
                // Get professional message for the first error (usually the most relevant)
                if (empty($professionalMessage)) {
                    $professionalMessage = formatApiErrorMessage($errorCode, $errorMessage);
                }
            }
            
            logAmazonAPI('Amazon API returned errors', ['errors' => $errors]);
            
            // Return professional message instead of raw API error
            return [
                'status' => 'note', // Changed from 'error' to 'note'
                'message' => $professionalMessage,
                'raw_errors' => $errors // Keep raw errors for logging purposes
            ];
        }
        
        if ($getItemsResponse->getItemsResult() !== null && 
            $getItemsResponse->getItemsResult()->getItems() !== null &&
            count($getItemsResponse->getItemsResult()->getItems()) > 0) {
            
            $item = $getItemsResponse->getItemsResult()->getItems()[0];
            
            $productData = [
                'status' => 'success',
                'asin' => $item->getASIN(),
                'title' => '',
                'current_price' => 0,
                'original_price' => 0,  // 🔥 FIXED: Added original_price field
                'image_url' => '',
                'stock_status' => 'out_of_stock',
                'stock_quantity' => 0,
                'rating' => 0.0,
                'rating_count' => 0,
                'category' => 'General',
                'subcategory' => 'General',
                'brand' => 'Generic'
            ];

            if ($item->getItemInfo() !== null &&
                $item->getItemInfo()->getTitle() !== null &&
                $item->getItemInfo()->getTitle()->getDisplayValue() !== null) {
                $productData['title'] = $item->getItemInfo()->getTitle()->getDisplayValue();
            }

            // Extract brand first (we need it for category filtering)
            $productData['brand'] = extractBrandFromItemInfo($item->getItemInfo());
            if (!$productData['brand'] || $productData['brand'] === 'Unknown') {
                $extractedBrand = extractBrandFromTitle($productData['title']);
                if ($extractedBrand) {
                    $productData['brand'] = $extractedBrand;
                }
            }

            // Extract category with brand-based filtering
            if ($item->getBrowseNodeInfo() !== null) {
                $categoryData = extractCategoryFromBrowseNodes($item->getBrowseNodeInfo(), $productData['brand']);
                $productData['category'] = $categoryData['category'];
                $productData['subcategory'] = $categoryData['subcategory'];
            }

            if ($item->getOffers() !== null &&
                $item->getOffers()->getListings() !== null &&
                count($item->getOffers()->getListings()) > 0) {
                
                $listing = $item->getOffers()->getListings()[0];
                
                // 🔥 FIXED: Extract current price
                if ($listing->getPrice() !== null &&
                    $listing->getPrice()->getDisplayAmount() !== null) {
                    $priceStr = $listing->getPrice()->getDisplayAmount();
                    $productData['current_price'] = (float)preg_replace('/[^\d.]/', '', $priceStr);
                }
                
                // 🔥 FIXED: Extract original price (MRP) from SavingBasis
                if ($listing->getSavingBasis() !== null &&
                    $listing->getSavingBasis()->getDisplayAmount() !== null) {
                    $originalPriceStr = $listing->getSavingBasis()->getDisplayAmount();
                    $productData['original_price'] = (float)preg_replace('/[^\d.]/', '', $originalPriceStr);
                    
                    logAmazonAPI('Original price (MRP) extracted', [
                        'asin' => $asin,
                        'original_price' => $productData['original_price'],
                        'current_price' => $productData['current_price']
                    ]);
                } else {
                    // 🔥 FIXED: If no SavingBasis, set original_price = current_price
                    $productData['original_price'] = $productData['current_price'];
                    
                    logAmazonAPI('No MRP found, using current price as original', [
                        'asin' => $asin,
                        'price' => $productData['current_price']
                    ]);
                }
                
                if ($listing->getAvailability() !== null) {
                    $availability = $listing->getAvailability();
                    
                    $availabilityMessage = '';
                    if ($availability->getMessage() !== null) {
                        $availabilityMessage = $availability->getMessage();
                    }
                    
                    if ($availabilityMessage) {
                        $stockQty = parseStockQuantityFromMessage($availabilityMessage);
                        $productData['stock_quantity'] = $stockQty;
                        $productData['stock_status'] = $stockQty > 0 ? 'in_stock' : 'out_of_stock';
                        
                        logAmazonAPI('Stock parsed from availability message', [
                            'message' => $availabilityMessage,
                            'parsed_quantity' => $stockQty,
                            'status' => $productData['stock_status']
                        ]);
                    } else {
                        $productData['stock_status'] = 'in_stock';
                        $productData['stock_quantity'] = 10;
                    }
                } else {
                    if ($productData['current_price'] > 0) {
                        $productData['stock_status'] = 'in_stock';
                        $productData['stock_quantity'] = 5;
                    }
                }
            }

            if ($item->getImages() !== null && $item->getImages()->getPrimary() !== null) {
                $primary = $item->getImages()->getPrimary();
                
                if ($primary->getLarge() !== null) {
                    $productData['image_url'] = $primary->getLarge()->getURL();
                } elseif ($primary->getMedium() !== null) {
                    $productData['image_url'] = $primary->getMedium()->getURL();
                } elseif ($primary->getSmall() !== null) {
                    $productData['image_url'] = $primary->getSmall()->getURL();
                }
            }

            // 🔥 FIXED: RATING LOGIC - Try API first, then fallback to scraping
            $ratingFromAPI = false;

            // Try to get rating from Amazon API first
            if ($item->getCustomerReviews() !== null) {
                if ($item->getCustomerReviews()->getStarRating() !== null) {
                    $productData['rating'] = (float)$item->getCustomerReviews()->getStarRating();
                    $ratingFromAPI = true;
                }
                if ($item->getCustomerReviews()->getCount() !== null) {
                    $productData['rating_count'] = (int)$item->getCustomerReviews()->getCount();
                    $ratingFromAPI = true;
                }
                
                logAmazonAPI('Ratings extracted from API', [
                    'asin' => $asin,
                    'rating' => $productData['rating'],
                    'rating_count' => $productData['rating_count'],
                    'rating_from_api' => $ratingFromAPI
                ]);
            }

            // 🔥 FIXED: If rating not available from API, try scraping (MOVED BEFORE RETURN)
            if (!$ratingFromAPI || ($productData['rating'] == 0 && $productData['rating_count'] == 0)) {
                logAmazonAPI('API rating not available, trying scraping fallback', [
                    'asin' => $asin,
                    'api_rating' => $productData['rating'],
                    'api_count' => $productData['rating_count']
                ]);
                
                $scrapingResult = getRatingFromScraping($asin);
                
                if ($scrapingResult['success']) {
                    $productData['rating'] = $scrapingResult['rating'];
                    $productData['rating_count'] = $scrapingResult['rating_count'];
                    
                    logAmazonAPI('Ratings extracted from scraping', [
                        'asin' => $asin,
                        'rating' => $productData['rating'],
                        'rating_count' => $productData['rating_count'],
                        'source' => 'scraping'
                    ]);
                } else {
                    logAmazonAPI('Scraping fallback also failed', [
                        'asin' => $asin,
                        'final_rating' => $productData['rating'],
                        'final_count' => $productData['rating_count']
                    ]);
                }
            }

            logAmazonAPI('Product data extracted successfully', [
                'title' => substr($productData['title'], 0, 50) . '...',
                'current_price' => $productData['current_price'],
                'original_price' => $productData['original_price'],  // 🔥 FIXED: Added logging
                'has_image' => !empty($productData['image_url']),
                'stock_status' => $productData['stock_status'],
                'stock_quantity' => $productData['stock_quantity'],
                'category' => $productData['category'],
                'subcategory' => $productData['subcategory'],
                'brand' => $productData['brand'],
                'rating' => $productData['rating'],
                'rating_count' => $productData['rating_count'],
                'rating_source' => $ratingFromAPI ? 'api' : 'scraping'
            ]);

            return $productData;
        }

        logAmazonAPI('No product data found in response', ['asin' => $asin]);
        return ['status' => 'note', 'message' => 'Product information is not available for tracking at this time.'];

    } catch (ApiException $exception) {
        logAmazonAPI('Amazon API Exception', [
            'code' => $exception->getCode(),
            'message' => $exception->getMessage(),
            'response_body' => $exception->getResponseBody()
        ]);
        
        // Format professional message for API exceptions
        $professionalMessage = formatApiErrorMessage('ApiException', $exception->getMessage());
        return ['status' => 'note', 'message' => $professionalMessage];
        
    } catch (Exception $exception) {
        logAmazonAPI('General Exception', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ]);
        return ['status' => 'note', 'message' => 'Service is temporarily unavailable. Please try again later.'];
    }
}
?>