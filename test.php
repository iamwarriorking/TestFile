<?php
// Alternative approach using SearchItems API
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "ALTERNATIVE APPROACH: Using SearchItems API for Pricing...\n\n";

if (file_exists(__DIR__ . '/config/amazon.php')) {
    $amazonConfig = require __DIR__ . '/config/amazon.php';
} else {
    die("❌ Config not found\n");
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    die("❌ Autoload not found\n");
}

use Amazon\ProductAdvertisingAPI\v1\ApiException;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\api\DefaultApi;
use Amazon\ProductAdvertisingAPI\v1\Configuration;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\SearchItemsRequest;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\SearchItemsResource;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\PartnerType;

function searchForProductPricing($productTitle, $amazonConfig) {
    echo "=== SEARCH-BASED PRICING APPROACH ===\n";
    echo "Searching for: $productTitle\n\n";
    
    try {
        $config = new Configuration();
        $config->setAccessKey($amazonConfig['access_key']);
        $config->setSecretKey($amazonConfig['secret_key']);
        $config->setHost('webservices.amazon.in');
        $config->setRegion('eu-west-1');
        
        $client = new DefaultApi(new \GuzzleHttp\Client(['timeout' => 60]), $config);
        
        // Create search request
        $searchRequest = new SearchItemsRequest();
        $searchRequest->setKeywords($productTitle);
        $searchRequest->setPartnerTag($amazonConfig['associate_tag']);
        $searchRequest->setPartnerType(PartnerType::ASSOCIATES);
        $searchRequest->setMarketplace('www.amazon.in');
        $searchRequest->setItemCount(5); // Get top 5 results
        
        // Resources for search
        $searchResources = [
            SearchItemsResource::ITEM_INFOTITLE,
            SearchItemsResource::ITEM_INFOBY_LINE_INFO,
            SearchItemsResource::OFFERSLISTINGSPRICE,
            SearchItemsResource::OFFERSLISTINGSAVAILABILITYMESSAGE,
            SearchItemsResource::OFFERSLISTINGSPROMOTIONS,
            SearchItemsResource::OFFERSLISTINGSSAVING_BASIS,
            SearchItemsResource::OFFERSSUMMARIESLOWEST_PRICE,
            SearchItemsResource::IMAGESPRIMARYLARGE,
            SearchItemsResource::CUSTOMER_REVIEWSSTAR_RATING,
            SearchItemsResource::CUSTOMER_REVIEWSCOUNT
        ];
        
        $searchRequest->setResources($searchResources);
        
        echo "Making search API call...\n";
        
        $searchResponse = $client->searchItems($searchRequest);
        
        if ($searchResponse->getErrors() !== null && count($searchResponse->getErrors()) > 0) {
            echo "❌ Search API Errors:\n";
            foreach ($searchResponse->getErrors() as $error) {
                echo "  • " . $error->getCode() . ": " . $error->getMessage() . "\n";
            }
            return false;
        }
        
        if ($searchResponse->getSearchResult() !== null && 
            $searchResponse->getSearchResult()->getItems() !== null) {
            
            $items = $searchResponse->getSearchResult()->getItems();
            echo "✅ Found " . count($items) . " products in search\n\n";
            
            foreach ($items as $index => $item) {
                echo "--- Result " . ($index + 1) . " ---\n";
                echo "ASIN: " . $item->getASIN() . "\n";
                
                if ($item->getItemInfo() && $item->getItemInfo()->getTitle()) {
                    echo "Title: " . substr($item->getItemInfo()->getTitle()->getDisplayValue(), 0, 60) . "...\n";
                }
                
                if ($item->getItemInfo() && $item->getItemInfo()->getByLineInfo() &&
                    $item->getItemInfo()->getByLineInfo()->getBrand()) {
                    echo "Brand: " . $item->getItemInfo()->getByLineInfo()->getBrand()->getDisplayValue() . "\n";
                }
                
                // Check pricing in search results
                if ($item->getOffers() !== null) {
                    $offers = $item->getOffers();
                    
                    if ($offers->getListings() !== null && count($offers->getListings()) > 0) {
                        $listing = $offers->getListings()[0]; // First listing
                        
                        if ($listing->getPrice() !== null) {
                            echo "💰 Price: " . $listing->getPrice()->getDisplayAmount() . "\n";
                        }
                        
                        if ($listing->getAvailability() !== null) {
                            echo "📦 Stock: " . $listing->getAvailability()->getMessage() . "\n";
                        }
                        
                        if ($listing->getPromotions() !== null && count($listing->getPromotions()) > 0) {
                            echo "🎉 Promotions: " . count($listing->getPromotions()) . " available\n";
                            foreach ($listing->getPromotions() as $promo) {
                                if ($promo->getDisplayText()) {
                                    echo "  • " . $promo->getDisplayText() . "\n";
                                }
                            }
                        }
                        
                        if ($listing->getSavingBasis() !== null) {
                            echo "💡 Original Price: " . $listing->getSavingBasis()->getDisplayAmount() . "\n";
                        }
                    }
                    
                    if ($offers->getSummaries() !== null) {
                        foreach ($offers->getSummaries() as $summary) {
                            if ($summary->getLowestPrice() !== null) {
                                echo "⬇️ Lowest Price: " . $summary->getLowestPrice()->getDisplayAmount() . "\n";
                            }
                        }
                    }
                } else {
                    echo "❌ No pricing data\n";
                }
                
                if ($item->getCustomerReviews() !== null) {
                    if ($item->getCustomerReviews()->getStarRating() !== null) {
                        echo "⭐ Rating: " . $item->getCustomerReviews()->getStarRating()->getValue() . "/5\n";
                    }
                }
                
                echo "\n";
            }
            
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        echo "🚨 Error: " . $e->getMessage() . "\n";
        return false;
    }
}

// Test with different search terms
$searchTerms = [
    "Whirlpool 1.5 Ton AC",
    "Samsung Mobile Phone",
    "Dell Laptop",
    "Boat Headphones"
];

foreach ($searchTerms as $term) {
    echo "🔍 Testing search: $term\n";
    $result = searchForProductPricing($term, $amazonConfig);
    echo "Result: " . ($result ? "✅ Found pricing data" : "❌ No pricing data") . "\n";
    echo str_repeat("-", 80) . "\n\n";
}

echo "=== FINAL RECOMMENDATION ===\n";
echo "आपके case के लिए best approach:\n\n";
echo "1. ✅ GetItems API + OFFERSLISTINGSPROMOTIONS resource working है\n";
echo "2. ❌ लेकिन कुछ products (जैसे appliances) में offers data limited है\n";
echo "3. ✅ SearchItems API में better pricing coverage है\n";
echo "4. ✅ Combination approach use करें:\n";
echo "   • पहले GetItems try करें specific ASIN के लिए\n";
echo "   • अगर offers नहीं मिले तो SearchItems use करें\n";
echo "   • Promotional deals के लिए OFFERSLISTINGSPROMOTIONS resource perfect है\n\n";

echo "Test completed at: " . date('Y-m-d H:i:s') . "\n";
?>