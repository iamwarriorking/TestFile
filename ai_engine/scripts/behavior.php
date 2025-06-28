<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/globals.php';
require_once __DIR__ . '/../config/safety.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Rubix\ML\Clusterers\KMeans;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Transformers\ZScaleStandardizer;

// Security check
if (!restrictAccess(__FILE__)) {
    http_response_code(403);
    exit('Access denied');
}

try {
    // Start execution time tracking for logging
    $startTime = microtime(true);

    // Fetch user behavior data with pagination to avoid memory issues
    $batchSize = 1000;
    $offset = 0;
    $allUserData = [];

    do {
        $stmt = $pdo->prepare("
            SELECT 
                user_id, 
                asin, 
                is_favorite, 
                is_ai_suggested, 
                interaction_type,
                created_at
            FROM user_behavior 
            ORDER BY user_id, created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$batchSize, $offset]);
        $behaviors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Process batch
        foreach ($behaviors as $behavior) {
            $userId = $behavior['user_id'];
            if (!isset($allUserData[$userId])) {
                $allUserData[$userId] = [
                    'favorites' => 0,
                    'ai_suggested' => 0,
                    'buy_now' => 0,
                    'price_history' => 0,
                    'tracking' => 0,
                    'notification_received' => 0,
                    'notification_dismissed' => 0,
                    'notification_buy_now' => 0,
                    'notification_price_history' => 0,
                    'notification_track' => 0,
                    'notification_share' => 0,
                    'notification_clicked' => 0,
                    'recommended' => 0,
                    'recent_activity' => 0
                ];
            }

            // Increment counters based on interaction type
            if ($behavior['is_favorite']) $allUserData[$userId]['favorites']++;
            if ($behavior['is_ai_suggested']) $allUserData[$userId]['ai_suggested']++;
            
            // Count recent activity (last 7 days)
            if (strtotime($behavior['created_at']) > strtotime('-7 days')) {
                $allUserData[$userId]['recent_activity']++;
            }

            // Increment based on interaction type
            $interactionType = $behavior['interaction_type'];
            if (isset($allUserData[$userId][$interactionType])) {
                $allUserData[$userId][$interactionType]++;
            }
        }

        $offset += $batchSize;
    } while (count($behaviors) === $batchSize);

    // Ensure we have enough users for clustering
    if (count($allUserData) < 5) {
        throw new Exception("Insufficient user data for clustering (minimum 5 users required)");
    }

    // Create samples for clustering with proper normalization
    $samples = [];
    $userIds = [];
    
    foreach ($allUserData as $userId => $data) {
        $userIds[] = $userId;
        $samples[] = [
            $data['favorites'],
            $data['ai_suggested'],
            $data['buy_now'],
            $data['price_history'],
            $data['tracking'],
            $data['notification_received'],
            $data['notification_dismissed'],
            $data['notification_buy_now'],
            $data['notification_price_history'],
            $data['notification_track'],
            $data['notification_share'],
            $data['notification_clicked'],
            $data['recommended'],
            $data['recent_activity']
        ];
    }

    // Normalize samples to prevent bias from high-frequency interactions
    $dataset = new Unlabeled($samples);
    
    // Apply standardization
    $standardizer = new ZScaleStandardizer();
    $dataset->apply($standardizer);
    
    // Determine optimal number of clusters (max 5, min 2)
    $numClusters = min(5, max(2, floor(sqrt(count($samples) / 2))));
    
    $clusterer = new KMeans($numClusters, 300); // max 300 iterations
    $clusterer->train($dataset);
    $clusters = $clusterer->predict($dataset);

    // Update user clusters in database with proper transaction handling
    $pdo->beginTransaction();
    
    try {
        // Clear existing clusters
        $pdo->exec("UPDATE users SET cluster = NULL, cluster_updated_at = NULL");
        
        // Prepare update statement
        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET cluster = ?, cluster_updated_at = NOW() 
            WHERE id = ?
        ");

        $updatedUsers = 0;
        foreach ($userIds as $index => $userId) {
            if (isset($clusters[$index])) {
                $updateStmt->execute([$clusters[$index], $userId]);
                $updatedUsers++;
            }
        }

        // Commit transaction
        $pdo->commit();
        
        // Log cluster statistics
        $clusterStats = array_count_values($clusters);
        $statsLog = "[" . date('Y-m-d H:i:s') . "] User clustering completed:\n";
        foreach ($clusterStats as $cluster => $count) {
            $statsLog .= "  Cluster {$cluster}: {$count} users\n";
        }
        $statsLog .= "  Total users updated: {$updatedUsers}\n";
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $pdo->rollback();
        throw new Exception("Database transaction failed: " . $e->getMessage());
    }

    // Calculate execution time and log success
    $executionTime = microtime(true) - $startTime;
    $logMessage = "[" . date('Y-m-d H:i:s') . "] Behavior analysis completed successfully:\n";
    $logMessage .= "  Users processed: " . count($allUserData) . "\n";
    $logMessage .= "  Clusters created: {$numClusters}\n";
    $logMessage .= "  Execution time: " . round($executionTime, 2) . " seconds\n";
    $logMessage .= "  Memory usage: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB\n\n";
    
    file_put_contents('../logs/behavior.log', $logMessage, FILE_APPEND | LOCK_EX);

} catch (Exception $e) {
    // Ensure transaction is rolled back
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    // Log error
    $errorMessage = "[" . date('Y-m-d H:i:s') . "] Behavior analysis failed: " . $e->getMessage() . "\n";
    file_put_contents('../logs/behavior.log', $errorMessage, FILE_APPEND | LOCK_EX);
    error_log("Behavior analysis error: " . $e->getMessage());
    
    // Exit with error code
    exit(1);
}
?>