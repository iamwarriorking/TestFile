<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/globals.php';
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/../config/safety.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Rubix\ML\Learners\GradientBoost;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\CrossValidation\KFold;
use Rubix\ML\CrossValidation\Metrics\MeanAbsoluteError;
use Rubix\ML\Transformers\ZScaleStandardizer;

// Security check
if (!restrictAccess(__FILE__)) {
    http_response_code(403);
    exit('Access denied');
}

// Enhanced AI Logger
class AILogger {
    public static function log($level, $message, $context = []) {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'memory_usage' => memory_get_usage(true),
            'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
        ];
        
        file_put_contents(
            '../logs/ai_' . date('Y-m-d') . '.log',
            json_encode($logData) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

// Feature engineering class
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
        $features['data_maturity'] = min(count($prices) / 30, 1.0); // Normalize by 30 data points
        
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
        
        // Festival months in India
        $festivalMonths = [10, 11, 12, 1, 3, 4]; // Oct-Dec, Jan, Mar-Apr
        return in_array($month, $festivalMonths) ? 1 : 0;
    }
    
    private static function getFestivalProximity($festivals) {
        $today = time();
        $proximityScore = 0;
        
        foreach ($festivals as $festival) {
            if ($festival['offers_likely']) {
                $festivalTime = strtotime($festival['event_date']);
                $daysDiff = abs($festivalTime - $today) / 86400; // Convert to days
                
                if ($daysDiff <= 7) {
                    $proximityScore += 1 - ($daysDiff / 7); // Higher score for closer festivals
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
        return (int)date('w', strtotime($date)) / 6; // Normalize to 0-1
    }
}

try {
    $startTime = microtime(true);
    $config = include '../config/ai_config.php';
    
    AILogger::log('INFO', 'Model retraining started', ['config' => $config]);
    
    ini_set('memory_limit', $config['memory_limit']);
    set_time_limit($config['max_execution_time']);
    
    // Get products with sufficient price history from new table
    $stmt = $pdo->prepare("
        SELECT p.asin, p.category, p.name, p.current_price,
               COUNT(ph.id) as history_count
        FROM products p 
        INNER JOIN price_history ph ON p.asin = ph.product_asin
        WHERE p.current_price > 0
        AND p.category IS NOT NULL
        GROUP BY p.asin
        HAVING history_count >= ?
        ORDER BY RAND()
        LIMIT ?
    ");
    $stmt->execute([$config['prediction_settings']['min_price_history_points'], $config['batch_size']]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get festivals
    $festivalStmt = $pdo->prepare("
        SELECT event_name, event_date, offers_likely 
        FROM festivals 
        WHERE event_date >= CURDATE() 
        AND event_date <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
    ");
    $festivalStmt->execute();
    $festivals = $festivalStmt->fetchAll(PDO::FETCH_ASSOC);

    $allSamples = [];
    $allLabels = [];
    $validProducts = 0;

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

            $dates = array_keys($history);
            
            // Create training samples
            for ($i = 0; $i < count($dates) - 1; $i++) {
                $currentHistory = array_slice($history, 0, $i + 1, true);
                $nextPrice = $history[$dates[$i + 1]];
                
                $currentPrice = $history[$dates[$i]];
                $priceChangePercent = abs(($nextPrice - $currentPrice) / $currentPrice * 100);
                
                if ($priceChangePercent > $config['prediction_settings']['max_price_change_percent']) {
                    continue;
                }

                $features = FeatureEngineer::extractAdvancedFeatures(
                    $currentHistory, 
                    $product['category'], 
                    $festivals, 
                    $config
                );

                $allSamples[] = array_values($features);
                $allLabels[] = $nextPrice;
            }

            $validProducts++;

        } catch (Exception $e) {
            AILogger::log('WARNING', 'Error processing product', [
                'asin' => $product['asin'],
                'error' => $e->getMessage()
            ]);
        }
    }

    if (count($allSamples) < $config['validation_settings']['min_samples_per_fold'] * $config['validation_settings']['cross_validation_folds']) {
        throw new Exception("Insufficient training data: " . count($allSamples) . " samples");
    }

    AILogger::log('INFO', 'Training data prepared', [
        'samples' => count($allSamples),
        'products' => $validProducts
    ]);

    // Create dataset and apply standardization
    $dataset = new Labeled($allSamples, $allLabels);
    $standardizer = new ZScaleStandardizer();
    $dataset->apply($standardizer);

    // Model configuration grid search
    $modelConfigs = [
        ['max_depth' => 5, 'learning_rate' => 0.1, 'n_estimators' => 100],
        ['max_depth' => 7, 'learning_rate' => 0.05, 'n_estimators' => 150],
        ['max_depth' => 5, 'learning_rate' => 0.2, 'n_estimators' => 75],
        ['max_depth' => 3, 'learning_rate' => 0.15, 'n_estimators' => 120]
    ];

    $bestModel = null;
    $bestScore = PHP_FLOAT_MAX;
    $bestConfig = null;

    // Test different model configurations
    foreach ($modelConfigs as $configIndex => $modelConfig) {
        try {
            $model = new GradientBoost(
                $modelConfig['n_estimators'],
                $modelConfig['learning_rate'],
                $modelConfig['max_depth'],
                0.8, // min_samples_split
                1e-4 // tolerance
            );

            // Use cross-validation to evaluate model
            $folds = min($config['validation_settings']['cross_validation_folds'], floor(count($allSamples) / 10));
            $validator = new KFold($folds);
            $scores = $validator->test($model, $dataset, new MeanAbsoluteError());
            $avgScore = array_sum($scores) / count($scores);

            AILogger::log('DEBUG', 'Model config tested', [
                'config_index' => $configIndex,
                'config' => $modelConfig,
                'avg_score' => $avgScore
            ]);

            if ($avgScore < $bestScore) {
                $bestScore = $avgScore;
                $bestModel = $model;
                $bestConfig = $modelConfig;
                $bestConfig['config_index'] = $configIndex;
            }

        } catch (Exception $e) {
            AILogger::log('ERROR', 'Error testing model config', [
                'config_index' => $configIndex,
                'error' => $e->getMessage()
            ]);
        }
    }

    if (!$bestModel) {
        throw new Exception("No valid model could be trained");
    }

    // Train the best model on full dataset
    $bestModel->train($dataset);

    // Check if model is significantly better than existing one
    $existingModelPath = "../models/price_model_{$config['model_version']}.ser";
    $shouldUpdateModel = true;

    if (file_exists($existingModelPath)) {
        try {
            $existingModel = GradientBoost::load($existingModelPath);
            $folds = min($config['validation_settings']['cross_validation_folds'], floor(count($allSamples) / 10));
            $validator = new KFold($folds);
            $existingScores = $validator->test($existingModel, $dataset, new MeanAbsoluteError());
            $existingAvgScore = array_sum($existingScores) / count($existingScores);
            
            // Only update if new model is significantly better
            $improvement = ($existingAvgScore - $bestScore) / $existingAvgScore;
            $shouldUpdateModel = $improvement >= $config['accuracy_threshold'];
            
            AILogger::log('INFO', 'Model comparison completed', [
                'existing_score' => $existingAvgScore,
                'new_score' => $bestScore,
                'improvement' => $improvement,
                'should_update' => $shouldUpdateModel
            ]);
            
        } catch (Exception $e) {
            AILogger::log('WARNING', 'Error evaluating existing model', [
                'error' => $e->getMessage()
            ]);
        }
    }

    if ($shouldUpdateModel) {
        // Save new model
        $modelPath = "../models/price_model_{$config['model_version']}.ser";
        $bestModel->save($modelPath);
        
        // Save model parameters
        $paramsPath = "../models/params_{$config['model_version']}.json";
        $params = array_merge($bestConfig, [
            'training_samples' => count($allSamples),
            'training_products' => $validProducts,
            'cross_validation_score' => $bestScore,
            'created_at' => date('Y-m-d H:i:s'),
            'features' => [
                'current_price', 'time_progression', 'category_electronics', 
                'festival_proximity', 'price_volatility', 'day_of_week',
                'price_range_ratio', 'seasonal_effect', 'data_maturity'
            ]
        ]);
        file_put_contents($paramsPath, json_encode($params, JSON_PRETTY_PRINT));
        
        // Save standardizer for prediction use
        $standardizerPath = "../models/standardizer_{$config['model_version']}.ser";
        $standardizer->save($standardizerPath);

        $logMessage = "[" . date('Y-m-d H:i:s') . "] Model updated successfully:\n";
        $logMessage .= "  Model version: {$config['model_version']}\n";
        $logMessage .= "  Training samples: " . count($allSamples) . "\n";
        $logMessage .= "  Valid products: {$validProducts}\n";
        $logMessage .= "  Cross-validation MAE: " . round($bestScore, 4) . "\n";
        $logMessage .= "  Best config: " . json_encode($bestConfig) . "\n";
        
        AILogger::log('SUCCESS', 'Model training completed', [
            'model_path' => $modelPath,
            'score' => $bestScore,
            'config' => $bestConfig
        ]);
    } else {
        $logMessage = "[" . date('Y-m-d H:i:s') . "] Model not updated - insufficient improvement\n";
        $logMessage .= "  Current score: " . round($bestScore, 4) . "\n";
        $logMessage .= "  Required improvement: " . ($config['accuracy_threshold'] * 100) . "%\n";
        
        AILogger::log('INFO', 'Model not updated - insufficient improvement', [
            'score' => $bestScore,
            'required_improvement' => $config['accuracy_threshold']
        ]);
    }

    // Log execution time
    $executionTime = microtime(true) - $startTime;
    $logMessage .= "  Execution time: " . round($executionTime, 2) . " seconds\n";
    $logMessage .= "  Memory usage: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB\n\n";
    
    file_put_contents('../logs/training.log', $logMessage, FILE_APPEND | LOCK_EX);

} catch (Exception $e) {
    $errorMessage = "[" . date('Y-m-d H:i:s') . "] Training failed: " . $e->getMessage() . "\n";
    file_put_contents('../logs/training.log', $errorMessage, FILE_APPEND | LOCK_EX);
    
    AILogger::log('ERROR', 'Model training failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    error_log("Model training error: " . $e->getMessage());
    exit(1);
}
?>