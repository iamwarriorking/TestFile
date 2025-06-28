<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/amazon.php';

$amazonConfig = require __DIR__ . '/../../config/amazon.php';

// Check if Amazon API is active
if ($amazonConfig['api_status'] !== 'active') {
    echo "Amazon API is disabled in config\n";
    exit;
}

use Amazon\ProductAdvertisingAPI\v1\ApiException;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\api\DefaultApi;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\GetItemsRequest;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\GetItemsResource;
use Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\PartnerType;

try {
    // Get total count of products that need updating
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE merchant = 'amazon' AND (last_updated < NOW() - INTERVAL 24 HOUR OR last_updated IS NULL)");
    $countStmt->execute();
    $totalProducts = $countStmt->fetchColumn();
    
    if ($totalProducts == 0) {
        echo "No Amazon products to update.\n";
        exit;
    }
    
    echo "Total Amazon products to update: $totalProducts\n";
    
    $batchSize = 10; // Amazon API limit
    $totalBatches = ceil($totalProducts / $batchSize);
    $processedCount = 0;
    
    // Initialize Amazon client once
    $client = initializeAmazonClient();
    if (!$client) {
        throw new Exception('Failed to initialize Amazon client');
    }
    
    // Process all products in batches
    for ($batch = 0; $batch < $totalBatches; $batch++) {
        $offset = $batch * $batchSize;
        
        echo "Processing batch " . ($batch + 1) . " of $totalBatches (offset: $offset)\n";
        
        // Get ASINs for current batch
        $stmt = $pdo->prepare("SELECT asin FROM products WHERE merchant = 'amazon' AND (last_updated < NOW() - INTERVAL 24 HOUR OR last_updated IS NULL) LIMIT $batchSize OFFSET $offset");
        $stmt->execute();
        $asins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($asins)) {
            echo "No more ASINs found in this batch.\n";
            break;
        }
        
        // Configure request
        $getItemsRequest = new GetItemsRequest();
        $getItemsRequest->setItemIds($asins);
        $getItemsRequest->setPartnerTag($amazonConfig['associate_tag']);
        $getItemsRequest->setPartnerType(PartnerType::ASSOCIATES);
        $getItemsRequest->setResources([
            GetItemsResource::ITEM_INFOTITLE,
            GetItemsResource::OFFERSLISTINGSPRICE,
            GetItemsResource::OFFERSLISTINGSAVAILABILITYMESSAGE,
            GetItemsResource::IMAGESPRIMARYLARGE,
            GetItemsResource::IMAGESPRIMARYMEDIUM,
            GetItemsResource::IMAGESPRIMARYSMALL,
            GetItemsResource::CUSTOMER_REVIEWSSTAR_RATING,
            GetItemsResource::CUSTOMER_REVIEWSCOUNT
        ]);

        $getItemsResponse = $client->getItems($getItemsRequest);

        // Process batch response
        if ($getItemsResponse->getItemsResult() !== null && 
            $getItemsResponse->getItemsResult()->getItems() !== null) {
            
            $items = $getItemsResponse->getItemsResult()->getItems();
            
            foreach ($items as $item) {
                $asin = $item->getASIN();
                
                try {
                    // Extract product data
                    $title = '';
                    if ($item->getItemInfo() !== null &&
                        $item->getItemInfo()->getTitle() !== null &&
                        $item->getItemInfo()->getTitle()->getDisplayValue() !== null) {
                        $title = $item->getItemInfo()->getTitle()->getDisplayValue();
                    }
                    
                    $price = 0;
                    $availability = '';
                    if ($item->getOffers() !== null &&
                        $item->getOffers()->getListings() !== null &&
                        count($item->getOffers()->getListings()) > 0) {
                        $listing = $item->getOffers()->getListings()[0];
                        
                        if ($listing->getPrice() !== null &&
                            $listing->getPrice()->getDisplayAmount() !== null) {
                            $priceStr = $listing->getPrice()->getDisplayAmount();
                            $price = (float)preg_replace('/[^\d.]/', '', $priceStr);
                        }
                        
                        if ($listing->getAvailability() !== null &&
                            $listing->getAvailability()->getMessage() !== null) {
                            $availability = $listing->getAvailability()->getMessage();
                        }
                    }
                    
                    $imageUrl = '';
                    if ($item->getImages() !== null && $item->getImages()->getPrimary() !== null) {
                        $primary = $item->getImages()->getPrimary();
                        if ($primary->getLarge() !== null) {
                            $imageUrl = $primary->getLarge()->getURL();
                        } elseif ($primary->getMedium() !== null) {
                            $imageUrl = $primary->getMedium()->getURL();
                        } elseif ($primary->getSmall() !== null) {
                            $imageUrl = $primary->getSmall()->getURL();
                        }
                    }

                    $rating = null;
                    $reviewCount = null;
                    if ($item->getCustomerReviews() !== null) {
                        $rating = $item->getCustomerReviews()->getStarRating();
                        $reviewCount = $item->getCustomerReviews()->getCount();
                    }

                    $detailPageUrl = $item->getDetailPageURL() ?? '';

                    // Update database
                    $stmt = $pdo->prepare("
                        UPDATE products SET 
                            name = ?, 
                            current_price = ?, 
                            affiliate_link = ?, 
                            image_path = ?, 
                            stock_status = ?,
                            rating = ?,
                            rating_count = ?,
                            last_updated = NOW() 
                        WHERE asin = ?
                    ");

                    $stmt->execute([
                        $title,
                        $price,
                        $detailPageUrl,
                        $imageUrl,
                        ($availability && strpos(strtolower($availability), 'stock') !== false) ? 'in_stock' : 'out_of_stock',
                        $rating,
                        $reviewCount,
                        $asin
                    ]);

                    $processedCount++;
                    echo "Updated Amazon ASIN: $asin - $title (Progress: $processedCount/$totalProducts)\n";

                } catch (Exception $e) {
                    echo "Error processing Amazon ASIN $asin: " . $e->getMessage() . "\n";
                    continue;
                }
            }
        }

        // Handle errors
        if ($getItemsResponse->getErrors() !== null) {
            foreach ($getItemsResponse->getErrors() as $error) {
                echo "Amazon API Error: " . $error->getCode() . " - " . $error->getMessage() . "\n";
            }
        }

        echo "Batch " . ($batch + 1) . " completed. Processed " . count($asins) . " ASINs in this batch.\n";
        
        // Rate limiting between batches
        if ($batch < $totalBatches - 1) {
            echo "Waiting 2 seconds before next batch...\n";
            sleep(2);
        }
    }

    echo "Amazon update completed successfully. Total processed: $processedCount products.\n";

} catch (ApiException $exception) {
    echo "Amazon API Exception: " . $exception->getCode() . " - " . $exception->getMessage() . "\n";
} catch (Exception $e) {
    echo "Amazon processing error: " . $e->getMessage() . "\n";
}
?>
