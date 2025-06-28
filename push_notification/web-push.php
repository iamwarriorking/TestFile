<?php
require_once '../config/database.php';
require_once '../config/globals.php';
require_once '../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class WebPushService {
    private $webPush;
    private $lastError = '';

    public function __construct($options) {
        $this->webPush = new WebPush($options, [], 20, [
            \GuzzleHttp\RequestOptions::ALLOW_REDIRECTS => false
        ]);
        $this->webPush->setAutomaticPadding(true);
    }

    public function getLastError() {
        return $this->lastError;
    }

    private function logError($message, $endpoint = '') {
        $logMessage = "[" . date('Y-m-d H:i:s') . "] $message";
        if ($endpoint) {
            $logMessage .= " for $endpoint";
        }
        
        $logFile = '../logs/push_errors.log';
        if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
            rename($logFile, $logFile . '.' . date('Ymd'));
        }
        
        file_put_contents($logFile, $logMessage . "\n", FILE_APPEND);
        $this->lastError = $message;
    }

    public function sendNotification($subscription, $payload, $options = []) {
        try {
            if (empty($subscription)) {
                $this->logError("Empty subscription received");
                return false;
            }
            
            $subData = json_decode($subscription, true);
            if (!$subData || !isset($subData['endpoint']) || !isset($subData['keys'])) {
                $this->logError("Invalid subscription format");
                return false;
            }
            
            $sub = Subscription::create($subData);
            $defaultOptions = [
                'TTL' => 2419200,
                'urgency' => 'normal',
                'topic' => 'price-update'
            ];
            $mergedOptions = array_merge($defaultOptions, $options);

            $report = $this->webPush->sendOneNotification($sub, json_encode($payload), $mergedOptions);

            if ($report->isSuccess()) {
                return true;
            } else {
                $this->logError("Push failed: {$report->getReason()}", $report->getEndpoint());
                return false;
            }
        } catch (Exception $e) {
            $this->logError("Push error: " . $e->getMessage());
            return false;
        }
    }

    public function sendBatchNotifications($subscriptions, $payload, $options = []) {
        try {
            if (empty($subscriptions)) {
                $this->logError("No subscriptions provided for batch");
                return false;
            }
            
            $validSubscriptions = 0;
            
            foreach ($subscriptions as $subscription) {
                try {
                    $subData = json_decode($subscription, true);
                    if (!$subData || !isset($subData['endpoint']) || !isset($subData['keys'])) {
                        continue;
                    }
                    
                    $sub = Subscription::create($subData);
                    $defaultOptions = [
                        'TTL' => 2419200,
                        'urgency' => 'normal',
                        'topic' => 'price-update'
                    ];
                    $mergedOptions = array_merge($defaultOptions, $options);

                    $this->webPush->queueNotification($sub, json_encode($payload), $mergedOptions);
                    $validSubscriptions++;
                } catch (Exception $e) {
                    $this->logError("Skipped invalid subscription: " . $e->getMessage());
                    continue;
                }
            }
            
            if ($validSubscriptions == 0) {
                $this->logError("No valid subscriptions found in batch");
                return false;
            }

            $reports = $this->webPush->flush();
            $success = true;
            $failCount = 0;

            foreach ($reports as $report) {
                if (!$report->isSuccess()) {
                    $this->logError("Batch push failed: {$report->getReason()}", $report->getEndpoint());
                    $failCount++;
                    $success = false;
                }
            }
            
            if ($failCount > 0) {
                $this->logError("$failCount notifications failed out of " . count($reports));
            }

            return $success;
        } catch (Exception $e) {
            $this->logError("Batch push error: " . $e->getMessage());
            return false;
        }
    }

    public static function generateVapidKeys() {
        global $pdo;
        
        try {
            $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
            $stmt = $pdo->prepare("INSERT INTO vapid_keys (public_key, private_key) VALUES (?, ?)");
            $stmt->execute([$keys['publicKey'], $keys['privateKey']]);
            
            return $keys;
        } catch (Exception $e) {
            file_put_contents('../logs/push_errors.log', "[" . date('Y-m-d H:i:s') . "] VAPID key generation failed: " . $e->getMessage() . "\n", FILE_APPEND);
            throw $e;
        }
    }
    
    public static function cleanupStaleSubscriptions($failureThreshold = 3) {
        global $pdo;
        
        try {
            $deletedCount = 0;
            
            $stmt = $pdo->prepare("
                SELECT ps.id, ps.user_id, ps.product_asin, COUNT(*) as failure_count
                FROM push_subscriptions ps
                JOIN push_errors pe ON ps.id = pe.subscription_id
                WHERE pe.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY ps.id
                HAVING failure_count >= ?
            ");
            $stmt->execute([$failureThreshold]);
            $staleSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($staleSubscriptions)) {
                $pdo->beginTransaction();
                
                foreach ($staleSubscriptions as $sub) {
                    $deleteStmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?");
                    $deleteStmt->execute([$sub['id']]);
                    $deletedCount++;
                }
                
                $pdo->commit();
            }
            
            return ['status' => 'success', 'deleted_count' => $deletedCount];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            file_put_contents('../logs/push_errors.log', "[" . date('Y-m-d H:i:s') . "] Subscription cleanup failed: " . $e->getMessage() . "\n", FILE_APPEND);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
?>