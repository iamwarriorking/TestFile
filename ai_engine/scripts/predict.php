<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../config/safety.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Rubix\ML\Learners\GradientBoost;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Transformers\ZScaleStandardizer;
use Rubix\ML\CrossValidation\Metrics\MeanAbsoluteError;

// Security check
if (!restrictAccess(__FILE__)) {
    http_response_code(403);
    exit('Access denied');
}

// Simple cache implementation
class AICache {
    private static $cacheDir = '../cache/';
    
    public static function get($key) {
        $file = self::$cacheDir . md5($key) . '.cache';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && $data['expires'] > time()) {
                return $data['value'];
            }
            unlink($file);
        }
        return null;
    }
    
    public static function set($key, $value, $ttl = 3600) {
        if (!is_dir(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0755, true);
        }
        
        $file = self::$cacheDir . md5($key) . '.cache';
        $data = [
            'value' => $value,
            'expires' => time() + $ttl
        ];
        file_put_contents($file, json_encode($data), LOCK_EX);
    }
}

// Feature engineering (reuse from retrain.php)
class FeatureEngineer {
    
    public static function extractAdvancedFeatures($history, $category, $festivals, $config) {
        $prices = array_values($history);
        $dates = array_keys($history);
        
        $features = [
            'current_price' => end($prices),
            'time_progression' => 1.0,
            'category_electronics' => in_array($category, ['smartphone', 'television', 'laptop', 'tablet']) ? 1 : 0,
            'festival_proximity' => self::getFestivalProximity($festivals)
        ];
        
        if ($config['feature_engineering']['enable_volatility_features']) {
            $features['price_volatility'] = self::calculateVolatility($prices);
        }
        
        if ($config['feature_engineering']['enable_seasonal_features']) {
            $features['seasonal_effect'] = self::getSeasonalFactor($dates);
        }
        
        if ($config['feature_engineering']['enable_momentum_features']) {
            $features['price_momentum'] = self::calculateMomentum($prices);
        }
        
        $features['price_range_ratio'] = self::getPriceRangeRatio($prices);
        $features['day_of_week'] = self::getDayOfWeek(end($dates));
        $features['data_maturity'] = min(count($prices) / 30, 1.0);
        
        return $features;
    }
    
    private static function calculateVolatility($prices) {
        if (count($prices) < 2) return 0;
        
        $returns = [];
        for ($i = 1; $i < count($prices); $i++) {
            if ($prices[$i-1] > 0) {
                $returns[] = ($prices[$i] - $prices[$i-1]) / $prices[$i-1];
            }
        }
        
        if (empty($returns)) return 0;
        
        $mean = array_sum($returns) / count($returns);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $returns)) / count($returns);
        return sqrt($variance);
    }
    
    private static function calculateMomentum($prices) {
        if (count($prices) < 3) return 0;
        
        $recent = array_slice($prices, -3);
        $older = array_slice($prices, 0, 3);
        
        $recentAvg = array_sum($recent) / count($recent);
        $olderAvg = array_sum($older) / count($older);
        
        return $olderAvg > 0 ? ($recentAvg - $olderAvg) / $olderAvg : 0;
    }
    
    private static function getSeasonalFactor($dates) {
        $lastDate = end($dates);
        $month = (int)date('m', strtotime($lastDate));
        
        $festivalMonths = [10, 11, 12, 1, 3, 4];
        return in_array($month, $festivalMonths) ? 1 : 0;
    }
    
    private static function getFestivalProximity($festivals) {
        $today = time();
        $proximityScore = 0;
        
        foreach ($festivals as $festival) {
            if ($festival['offers_likely']) {
                $festivalTime = strtotime($festival['event_date']);
                $daysDiff = abs($festivalTime - $today) / 86400;
                
                if ($daysDiff <= 7) {
                    $proximityScore += 1 - ($daysDiff / 7);
                }
            }
        }
        
        return min($proximityScore, 1.0);
    }
    
    private static function getPriceRangeRatio($prices) {
        if (count($prices) < 2) return 0;
        
        $min = min($prices);
        $max = max($prices);
        
        return $max > 0 ? ($max - $min) / $max : 0;
    }
    
    private static function getDayOfWeek($date) {
        return (int)date('w', strtotime($date)) / 6;
    }
}

try {
    $startTime = microtime(true);
    $config = include '../config/ai_config.php';
    
    // Set limits
    ini_set('memory_limit', $config['memory_limit']);
    set_time_limit($config['max_execution_time']);
    
    // Check if cached predictions exist
    $cacheKey = 'predictions_' . date('Y-m-d');
    if ($config['cache_enabled']) {
        $cachedPredictions = AICache::get($cacheKey);
        if ($cachedPredictions) {
            file_put_contents('../logs/predict.log', "[" . date('Y-m-d H:i:s') . "] Using cached predictions\n", FILE_APPEND);
            exit(0);
        }
    }
    
    // Enhanced query with proper validation
    $stmt = $pdo->prepare("
        SELECT p.asin, p.category, p.name, p.current_price,
            COUNT(ph.id) as history_count
        FROM products p 
        INNER JOIN price_history ph ON p.asin = ph.product_asin
        WHERE p.current_price > 0
        AND p.category IS NOT NULL
        GROUP BY p.asin
        HAVING history_count >= ?
        LIMIT ?
    ");
    $stmt->execute([$config['prediction_settings']['min_price_history_points'], $config['batch_size']]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get festivals
    $festivalStmt = $pdo->prepare("
        SELECT event_name, event_date, offers_likely 
        FROM festivals 
        WHERE event_date >= CURDATE() 
        AND event_date <= DATE_ADD(CURDATE(), INTERVAL ? MONTH)
    ");
    $festivalStmt->execute([$config['prediction_settings']['months_ahead'] * 2]);
    $festivals = $festivalStmt->fetchAll(PDO::FETCH_ASSOC);

    // Load model and standardizer
    $modelPath = "../models/price_model_{$config['model_version']}.ser";
    $standardizerPath = "../models/standardizer_{$config['model_version']}.ser";

    if (!file_exists($modelPath) || !file_exists($standardizerPath)) {
        throw new Exception("Model files not found");
    }

    $model = GradientBoost::load($modelPath);
    $standardizer = ZScaleStandardizer::load($standardizerPath);

    $predictedCount = 0;
    $errors = [];

    $pdo->beginTransaction();

    try {
        $pdo->exec("DELETE FROM predictions WHERE prediction_date < CURDATE()");
        
        $insertStmt = $pdo->prepare("
            INSERT INTO predictions (asin, predicted_price, prediction_date, period, confidence, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
            predicted_price = VALUES(predicted_price), 
            confidence = VALUES(confidence),
            updated_at = NOW()
        ");

        foreach ($products as $product) {
            try {
                // Get price history from new table
                $historyStmt = $pdo->prepare("
                    SELECT date_recorded, price 
                    FROM price_history 
                    WHERE product_asin = ? 
                    ORDER BY date_recorded ASC
                ");
                $historyStmt->execute([$product['asin']]);
                $historyData = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($historyData) < $config['prediction_settings']['min_price_history_points']) {
                    continue;
                }

                // Convert to associative array
                $history = [];
                foreach ($historyData as $record) {
                    $history[$record['date_recorded']] = (float)$record['price'];
                }

                // Extract features and predict
                $features = FeatureEngineer::extractAdvancedFeatures(
                    $history, 
                    $product['category'], 
                    $festivals, 
                    $config
                );

                // Prepare prediction samples for multiple months
                $predictions = [];
                for ($i = 1; $i <= $config['prediction_settings']['months_ahead']; $i++) {
                    $futureFeatures = $features;
                    $futureFeatures['time_progression'] = 1.0 + ($i * 0.1); // Adjust for future time
                    
                    // Predict
                    $sample = [array_values($futureFeatures)];
                    $dataset = new Labeled($sample, [0]); // Dummy label
                    $dataset->apply($standardizer);
                    
                    $predictedPrice = $model->predict($dataset)[0];
                    
                    // Validate prediction
                    $currentPrice = $product['current_price'];
                    $priceChangePercent = abs(($predictedPrice - $currentPrice) / $currentPrice * 100);
                    
                    if ($priceChangePercent > $config['prediction_settings']['max_price_change_percent']) {
                        $predictedPrice = $currentPrice * (1 + (($priceChangePercent > 0 ? 1 : -1) * $config['prediction_settings']['max_price_change_percent'] / 100));
                    }
                    
                    // Calculate confidence based on historical volatility
                    $volatility = $features['price_volatility'] ?? 0;
                    $confidence = max(0.3, min(0.9, 1 - $volatility));
                    
                    if ($confidence >= $config['prediction_settings']['min_confidence']) {
                        $predictionDate = date('Y-m-01', strtotime("+{$i} month"));
                        $period = date('m-Y', strtotime($predictionDate));
                        
                        $insertStmt->execute([
                            $product['asin'],
                            round($predictedPrice, 2),
                            $predictionDate,
                            $period,
                            round($confidence, 2)
                        ]);
                        
                        $predictions[] = [
                            'month' => $i,
                            'price' => $predictedPrice,
                            'confidence' => $confidence
                        ];
                    }
                }

                if (!empty($predictions)) {
                    $predictedCount++;
                }

            } catch (Exception $e) {
                $errors[] = "Error predicting for {$product['asin']}: " . $e->getMessage();
            }
        }

        // Commit transaction
        $pdo->commit();
        
        // Cache the results
        if ($config['cache_enabled']) {
            AICache::set($cacheKey, true, $config['cache_ttl']);
        }

    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }

    // Log execution results
    $executionTime = microtime(true) - $startTime;
    $logMessage = "[" . date('Y-m-d H:i:s') . "] Price predictions completed:\n";
    $logMessage .= "  Products processed: " . count($products) . "\n";
    $logMessage .= "  Successful predictions: {$predictedCount}\n";
    $logMessage .= "  Errors: " . count($errors) . "\n";
    $logMessage .= "  Execution time: " . round($executionTime, 2) . " seconds\n";
    $logMessage .= "  Memory usage: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB\n\n";
    
    if (!empty($errors)) {
        $logMessage .= "  Error details:\n";
        foreach (array_slice($errors, 0, 10) as $error) { // Log first 10 errors
            $logMessage .= "    - {$error}\n";
        }
    }
    
    file_put_contents('../logs/predict.log', $logMessage, FILE_APPEND | LOCK_EX);

} catch (Exception $e) {
    // Ensure transaction is rolled back
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    // Log error
    $errorMessage = "[" . date('Y-m-d H:i:s') . "] Prediction failed: " . $e->getMessage() . "\n";
    file_put_contents('../logs/predict.log', $errorMessage, FILE_APPEND | LOCK_EX);
    error_log("Prediction error: " . $e->getMessage());
    exit(1);
}
?>