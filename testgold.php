<?php
/**
 * Independent Amazon ASIN Test File
 * Description: ASIN number से stock quantity, availability, current price और original price check करने के लिए
 * Author: RajpurohitHitesh
 * Date: 2025-06-21
 * For Hostinger Testing
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 60);

// Amazon API Configuration
$amazonConfig = [
    'access_key' => 'AKPAGA3ECW1749145874',
    'secret_key' => 'Chd6xX4qrF9Y6ugSPBKH0OaTD7hE8dpxM5MPqJ9Q',
    'associate_tag' => 'amezprice-21',
    'marketplace' => 'webservices.amazon.in',
    'region' => 'eu-west-1',
    'api_status' => 'active'
];

// Check if vendor autoload exists (for Composer dependencies)
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die('❌ Error: vendor/autoload.php not found. Please run "composer install" first.');
}

require_once __DIR__ . '/vendor/autoload.php';

use Amazon\ProductAdvertisingAPI\v1\ApiException;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\api\DefaultApi;
use Amazon\ProductAdvertisingAPI\v1\Configuration;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\GetItemsRequest;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\GetItemsResource;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\PartnerType;
use GuzzleHttp\Client;

/**
 * ASIN validation function
 */
function validateASIN($asin) {
    if (!$asin || strlen($asin) !== 10) {
        return false;
    }
    
    if (!preg_match('/^[A-Z0-9]{10}$/', $asin)) {
        return false;
    }
    
    return true;
}

/**
 * Parse stock quantity from availability message
 */
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
        '/available/i' => function() { return 5; }
    ];
    
    foreach ($patterns as $pattern => $callback) {
        if (preg_match($pattern, $message, $matches)) {
            return $callback($matches);
        }
    }
    
    return 0;
}

/**
 * Initialize Amazon API Client
 */
function initializeAmazonClient($amazonConfig) {
    if (!$amazonConfig || !is_array($amazonConfig)) {
        throw new Exception("Amazon configuration is invalid");
    }

    $requiredFields = ['access_key', 'secret_key', 'associate_tag', 'marketplace', 'region'];
    foreach ($requiredFields as $field) {
        if (!isset($amazonConfig[$field]) || empty($amazonConfig[$field])) {
            throw new Exception("Missing required Amazon configuration field: $field");
        }
    }

    try {
        $config = new Configuration();
        $config->setAccessKey($amazonConfig['access_key']);
        $config->setSecretKey($amazonConfig['secret_key']);
        $config->setHost($amazonConfig['marketplace']);
        $config->setRegion($amazonConfig['region']);

        return new DefaultApi(new Client(['timeout' => 30]), $config);
        
    } catch (Exception $e) {
        throw new Exception('Amazon Client Initialization Error: ' . $e->getMessage());
    }
}

/**
 * Fetch product data from Amazon using ASIN
 */
function fetchAmazonProductByASIN($asin, $amazonConfig) {
    $asin = strtoupper(trim($asin));
    
    // Validate ASIN format
    if (!validateASIN($asin)) {
        return [
            'status' => 'error', 
            'message' => 'Invalid ASIN format. Must be 10 alphanumeric characters.',
            'asin' => $asin
        ];
    }

    // Initialize Amazon API client
    try {
        $client = initializeAmazonClient($amazonConfig);
    } catch (Exception $e) {
        return [
            'status' => 'error', 
            'message' => 'Failed to initialize Amazon client: ' . $e->getMessage()
        ];
    }

    try {
        // Create GetItems request
        $getItemsRequest = new GetItemsRequest();
        $getItemsRequest->setItemIds([$asin]);
        $getItemsRequest->setPartnerTag($amazonConfig['associate_tag']);
        $getItemsRequest->setPartnerType(PartnerType::ASSOCIATES);
        $getItemsRequest->setMarketplace('www.amazon.in');
        
        // Set required resources
        $getItemsRequest->setResources([
            GetItemsResource::ITEM_INFOTITLE,
            GetItemsResource::ITEM_INFOBY_LINE_INFO,
            GetItemsResource::OFFERSLISTINGSPRICE,
            GetItemsResource::OFFERSLISTINGSSAVING_BASIS,  // For original price (MRP)
            GetItemsResource::OFFERSLISTINGSAVAILABILITYMESSAGE,
            GetItemsResource::IMAGESPRIMARYLARGE,
            GetItemsResource::IMAGESPRIMARYMEDIUM,
            GetItemsResource::IMAGESPRIMARYSMALL,
            GetItemsResource::CUSTOMER_REVIEWSSTAR_RATING,
            GetItemsResource::CUSTOMER_REVIEWSCOUNT
        ]);

        // Validate request parameters
        $invalidPropertyList = $getItemsRequest->listInvalidProperties();
        if (count($invalidPropertyList) > 0) {
            return [
                'status' => 'error', 
                'message' => 'Invalid request parameters: ' . implode(', ', $invalidPropertyList)
            ];
        }

        // Make API call
        $getItemsResponse = $client->getItems($getItemsRequest);
        
        // Check for API errors
        if ($getItemsResponse->getErrors() !== null && count($getItemsResponse->getErrors()) > 0) {
            $errors = [];
            foreach ($getItemsResponse->getErrors() as $error) {
                $errors[] = $error->getCode() . ': ' . $error->getMessage();
            }
            return [
                'status' => 'error', 
                'message' => 'Amazon API Error: ' . implode(', ', $errors)
            ];
        }

        // Process response
        if ($getItemsResponse->getItemsResult() !== null &&
            $getItemsResponse->getItemsResult()->getItems() !== null &&
            count($getItemsResponse->getItemsResult()->getItems()) > 0) {
            
            $item = $getItemsResponse->getItemsResult()->getItems()[0];
            
            // Initialize product data
            $productData = [
                'status' => 'success',
                'asin' => $item->getASIN(),
                'title' => '',
                'current_price' => 0,
                'original_price' => 0,
                'currency' => 'INR',
                'image_url' => '',
                'stock_status' => 'out_of_stock',
                'stock_quantity' => 0,
                'availability_message' => '',
                'rating' => 0.0,
                'rating_count' => 0,
                'affiliate_link' => $item->getDetailPageURL() ?? ''
            ];

            // Extract title
            if ($item->getItemInfo() !== null &&
                $item->getItemInfo()->getTitle() !== null &&
                $item->getItemInfo()->getTitle()->getDisplayValue() !== null) {
                $productData['title'] = $item->getItemInfo()->getTitle()->getDisplayValue();
            }

            // Extract pricing and availability information
            if ($item->getOffers() !== null &&
                $item->getOffers()->getListings() !== null &&
                count($item->getOffers()->getListings()) > 0) {
                
                $listing = $item->getOffers()->getListings()[0];
                
                // Extract current price
                if ($listing->getPrice() !== null &&
                    $listing->getPrice()->getDisplayAmount() !== null) {
                    $priceStr = $listing->getPrice()->getDisplayAmount();
                    $productData['current_price'] = (float)preg_replace('/[^\d.]/', '', $priceStr);
                }
                
                // Extract original price (MRP) from SavingBasis
                if ($listing->getSavingBasis() !== null &&
                    $listing->getSavingBasis()->getDisplayAmount() !== null) {
                    $originalPriceStr = $listing->getSavingBasis()->getDisplayAmount();
                    $productData['original_price'] = (float)preg_replace('/[^\d.]/', '', $originalPriceStr);
                } else {
                    // If no original price found, use current price as original
                    $productData['original_price'] = $productData['current_price'];
                }
                
                // Extract availability information
                if ($listing->getAvailability() !== null) {
                    $availability = $listing->getAvailability();
                    
                    if ($availability->getMessage() !== null) {
                        $availabilityMessage = $availability->getMessage();
                        $productData['availability_message'] = $availabilityMessage;
                        
                        // Parse stock quantity from message
                        $stockQty = parseStockQuantityFromMessage($availabilityMessage);
                        $productData['stock_quantity'] = $stockQty;
                        $productData['stock_status'] = $stockQty > 0 ? 'in_stock' : 'out_of_stock';
                    } else {
                        $productData['stock_status'] = 'in_stock';
                        $productData['stock_quantity'] = 10; // Default assumption
                    }
                } else {
                    if ($productData['current_price'] > 0) {
                        $productData['stock_status'] = 'in_stock';
                        $productData['stock_quantity'] = 5; // Default assumption
                    }
                }
            }

            // Extract image URL
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

            // Extract rating information
            if ($item->getCustomerReviews() !== null) {
                if ($item->getCustomerReviews()->getStarRating() !== null) {
                    $productData['rating'] = (float)$item->getCustomerReviews()->getStarRating()->getValue();
                }
                if ($item->getCustomerReviews()->getCount() !== null) {
                    $productData['rating_count'] = (int)$item->getCustomerReviews()->getCount();
                }
            }

            return $productData;
        }

        return [
            'status' => 'error', 
            'message' => 'Product not found for ASIN: ' . $asin
        ];

    } catch (ApiException $e) {
        return [
            'status' => 'error', 
            'message' => 'Amazon API Exception: ' . $e->getMessage()
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error', 
            'message' => 'General Exception: ' . $e->getMessage()
        ];
    }
}

/**
 * Format and display product information
 */
function displayProductInfo($productData) {
    echo "<div style='font-family: Arial, sans-serif; max-width: 800px; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>";
    
    if ($productData['status'] === 'error') {
        echo "<h2 style='color: #d32f2f;'>❌ Error</h2>";
        echo "<p style='color: #d32f2f; font-weight: bold;'>" . htmlspecialchars($productData['message']) . "</p>";
        if (isset($productData['asin'])) {
            echo "<p><strong>ASIN:</strong> " . htmlspecialchars($productData['asin']) . "</p>";
        }
    } else {
        echo "<h2 style='color: #2e7d32;'>✅ Product Information</h2>";
        
        // Product Image
        if (!empty($productData['image_url'])) {
            echo "<div style='text-align: center; margin: 20px 0;'>";
            echo "<img src='" . htmlspecialchars($productData['image_url']) . "' alt='Product Image' style='max-width: 300px; max-height: 300px; border: 1px solid #ddd; border-radius: 4px;'>";
            echo "</div>";
        }
        
        // Basic Information
        echo "<div style='background-color: #f5f5f5; padding: 15px; border-radius: 4px; margin: 15px 0;'>";
        echo "<h3 style='margin-top: 0; color: #1976d2;'>📦 Basic Information</h3>";
        echo "<p><strong>ASIN:</strong> " . htmlspecialchars($productData['asin']) . "</p>";
        echo "<p><strong>Title:</strong> " . htmlspecialchars($productData['title']) . "</p>";
        echo "</div>";
        
        // Pricing Information
        echo "<div style='background-color: #e8f5e8; padding: 15px; border-radius: 4px; margin: 15px 0;'>";
        echo "<h3 style='margin-top: 0; color: #2e7d32;'>💰 Pricing Information</h3>";
        echo "<p><strong>Current Price:</strong> ₹" . number_format($productData['current_price'], 2) . "</p>";
        echo "<p><strong>Original Price (MRP):</strong> ₹" . number_format($productData['original_price'], 2) . "</p>";
        
        if ($productData['original_price'] > $productData['current_price'] && $productData['original_price'] > 0) {
            $discount = $productData['original_price'] - $productData['current_price'];
            $discountPercentage = ($discount / $productData['original_price']) * 100;
            echo "<p style='color: #d32f2f;'><strong>Discount:</strong> ₹" . number_format($discount, 2) . " (" . number_format($discountPercentage, 1) . "% OFF)</p>";
        }
        echo "</div>";
        
        // Stock Information
        $stockColor = $productData['stock_status'] === 'in_stock' ? '#2e7d32' : '#d32f2f';
        echo "<div style='background-color: #e3f2fd; padding: 15px; border-radius: 4px; margin: 15px 0;'>";
        echo "<h3 style='margin-top: 0; color: #1976d2;'>📊 Stock Information</h3>";
        echo "<p><strong>Stock Status:</strong> <span style='color: $stockColor; font-weight: bold;'>" . 
             ucfirst(str_replace('_', ' ', $productData['stock_status'])) . "</span></p>";
        echo "<p><strong>Stock Quantity:</strong> " . $productData['stock_quantity'] . " units</p>";
        
        if (!empty($productData['availability_message'])) {
            echo "<p><strong>Availability Message:</strong> " . htmlspecialchars($productData['availability_message']) . "</p>";
        }
        echo "</div>";
        
        // Rating Information
        if ($productData['rating'] > 0) {
            echo "<div style='background-color: #fff3e0; padding: 15px; border-radius: 4px; margin: 15px 0;'>";
            echo "<h3 style='margin-top: 0; color: #f57c00;'>⭐ Rating Information</h3>";
            echo "<p><strong>Rating:</strong> " . $productData['rating'] . "/5.0 stars</p>";
            echo "<p><strong>Total Reviews:</strong> " . number_format($productData['rating_count']) . "</p>";
            echo "</div>";
        }
        
        // Additional Information
        if (!empty($productData['affiliate_link'])) {
            echo "<div style='text-align: center; margin: 20px 0;'>";
            echo "<a href='" . htmlspecialchars($productData['affiliate_link']) . "' target='_blank' style='background-color: #1976d2; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block;'>View on Amazon</a>";
            echo "</div>";
        }
    }
    
    echo "</div>";
}

// HTML Header
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Amazon ASIN Product Checker - AmezPrice</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            color: #1976d2;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            box-sizing: border-box;
        }
        input[type="text"]:focus {
            outline: none;
            border-color: #1976d2;
        }
        button {
            background-color: #1976d2;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
        }
        button:hover {
            background-color: #155a9e;
        }
        .example {
            background-color: #f0f7ff;
            padding: 15px;
            border-radius: 4px;
            margin-top: 10px;
        }
        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Amazon ASIN Product Checker</h1>
            <p>Enter an ASIN number to get detailed product information including price, stock, and availability</p>
        </div>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="asin">Enter Amazon ASIN:</label>
                <input type="text" id="asin" name="asin" placeholder="e.g., B08N5WRWNW" value="<?php echo isset($_POST['asin']) ? htmlspecialchars($_POST['asin']) : ''; ?>" required>
                <div class="example">
                    <strong>Example ASINs to test:</strong><br>
                    • B08N5WRWNW (Echo Dot)<br>
                    • B07HGJJ586 (Fire TV Stick)<br>
                    • B0756CYWWD (Amazon Echo)
                </div>
            </div>
            
            <button type="submit">Check Product Information</button>
        </form>
        
        <?php
        // Process form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['asin'])) {
            $asin = trim($_POST['asin']);
            
            echo "<div class='loading'>";
            echo "<h3>🔄 Fetching product information...</h3>";
            echo "<p>Please wait while we retrieve data from Amazon API...</p>";
            echo "</div>";
            
            // Fetch product data
            $productData = fetchAmazonProductByASIN($asin, $amazonConfig);
            
            // Display results
            displayProductInfo($productData);
            
            // Debug information (for development)
            if (isset($_GET['debug']) && $_GET['debug'] === '1') {
                echo "<div style='margin-top: 30px; padding: 20px; background-color: #f9f9f9; border-radius: 4px;'>";
                echo "<h3>🐛 Debug Information</h3>";
                echo "<pre style='background-color: #fff; padding: 15px; border-radius: 4px; overflow: auto;'>";
                echo htmlspecialchars(json_encode($productData, JSON_PRETTY_PRINT));
                echo "</pre>";
                echo "</div>";
            }
        }
        ?>
        
        <div style="margin-top: 40px; text-align: center; color: #666; border-top: 1px solid #ddd; padding-top: 20px;">
            <p><strong>AmezPrice ASIN Checker</strong> | Built for Hostinger Testing | Date: <?php echo date('Y-m-d H:i:s'); ?></p>
            <p><small>Add ?debug=1 to URL for debug information</small></p>
        </div>
    </div>
</body>
</html>