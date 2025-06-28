<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../user/unsub_handler.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

$mailConfig = include __DIR__ . '/../config/mail.php';

// Define logs directory with correct path
$logsDir = __DIR__ . '/../logs';

// Ensure logs directory exists
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// Add logging function
function logEmail($message, $data = []) {
    global $logsDir;
    try {
        $logFile = $logsDir . '/email.log';
        $timestamp = date('Y-m-d H:i:s');
        $logData = array_merge([
            'timestamp' => $timestamp,
            'message' => $message
        ], $data);
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log("Email logging error: " . $e->getMessage());
    }
}

function sendEmail($to, $subject, $message, $type = 'otp') {
    global $mailConfig, $logsDir;
    
    // Start logging
    logEmail("Starting email send process", [
        'to' => $to,
        'subject' => $subject,
        'type' => $type
    ]);
    
    if ($type === 'deals' || $type === 'offers') {
        $unsubscribeUrl = getUnsubscribeUrl($to);
        $message = str_replace('{{unsubscribe_url}}', $unsubscribeUrl, $message);
    }

    // Check if mailConfig is loaded
    if (!$mailConfig) {
        $error = "Mail configuration not loaded";
        logEmail($error);
        error_log($error);
        return false;
    }
    
    // For alert and stock emails, use the same config as otp (or add specific configs)
    $configType = in_array($type, ['alert', 'stock']) ? 'otp' : $type;
    
    // Check if type exists in config
    if (!isset($mailConfig[$configType])) {
        $error = "Mail configuration for type '$configType' not found";
        logEmail($error);
        error_log($error);
        return false;
    }
    
    // Debug information
    $debug_info = "[" . date('Y-m-d H:i:s') . "] Attempting to send email\n";
    $debug_info .= "To: $to\n";
    $debug_info .= "Subject: $subject\n";
    $debug_info .= "Type: $type\n";
    
    $mailer = new PHPMailer(true);
    $attempts = $mailConfig['retry_attempts'] ?? 3;
    $delay = $mailConfig['retry_delay'] ?? 5;

    // Log mail configuration
    $debug_info .= "Mail Configuration:\n";
    $debug_info .= "Server: " . $mailConfig[$configType]['server'] . "\n";
    $debug_info .= "Port: " . $mailConfig[$configType]['port'] . "\n";
    $debug_info .= "Username: " . $mailConfig[$configType]['username'] . "\n";
    
    file_put_contents($logsDir . '/email.log', $debug_info, FILE_APPEND | LOCK_EX);

    for ($i = 0; $i < $attempts; $i++) {
        try {
            // Clear any previous settings
            $mailer->clearAddresses();
            $mailer->clearAttachments();
            $mailer->clearReplyTos();
            
            // Server settings
            $mailer->SMTPDebug = SMTP::DEBUG_OFF; // Turn off for production
            $mailer->Debugoutput = function($str, $level) use ($logsDir) {
                $clean_str = trim(preg_replace('/\r\n|\r|\n/', ' ', $str));
                file_put_contents(
                    $logsDir . '/email.log',
                    "[" . date('Y-m-d H:i:s') . "] [SMTP Level $level] $clean_str\n",
                    FILE_APPEND | LOCK_EX
                );
            };

            $mailer->isSMTP();
            $mailer->Host = $mailConfig[$configType]['server'];
            $mailer->SMTPAuth = true;
            $mailer->Username = $mailConfig[$configType]['username'];
            $mailer->Password = $mailConfig[$configType]['password'];
            
            // SSL Configuration
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mailer->Port = $mailConfig[$configType]['port'];
            
            // Additional settings for Hostinger
            $mailer->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            // Set timeout
            $mailer->Timeout = 60;
            $mailer->SMTPKeepAlive = false;
            
            // Recipients
            $mailer->setFrom($mailConfig[$configType]['email'], 'AmezPrice');
            $mailer->addAddress($to);
            
            // Content
            $mailer->isHTML(true);
            $mailer->CharSet = 'UTF-8';
            $mailer->Subject = $subject;
            $mailer->Body = $message;
            $mailer->AltBody = strip_tags($message);

            // Send email
            $result = $mailer->send();
            
            // Log success
            $success_msg = "[" . date('Y-m-d H:i:s') . "] Email sent successfully to $to";
            file_put_contents($logsDir . '/email.log', $success_msg . "\n", FILE_APPEND | LOCK_EX);
            
            logEmail("Email sent successfully", [
                'to' => $to,
                'attempt' => $i + 1,
                'type' => $type
            ]);
            
            return true;

        } catch (Exception $e) {
            $error_log = sprintf(
                "[%s] Attempt %d failed\nError: %s\nMailer Error: %s\nStack Trace: %s\n",
                date('Y-m-d H:i:s'),
                $i + 1,
                $e->getMessage(),
                $mailer->ErrorInfo,
                $e->getTraceAsString()
            );
            
            file_put_contents($logsDir . '/email.log', $error_log, FILE_APPEND | LOCK_EX);
            
            logEmail("Email send attempt failed", [
                'to' => $to,
                'attempt' => $i + 1,
                'error' => $e->getMessage(),
                'mailer_error' => $mailer->ErrorInfo,
                'type' => $type
            ]);
            
            // Log to PHP error log as well
            error_log("Email send failed: " . $e->getMessage() . " | Mailer: " . $mailer->ErrorInfo);
            
            // Clear recipients before retry
            $mailer->clearAddresses();
            $mailer->clearAttachments();
            
            if ($i < $attempts - 1) {
                $retry_msg = "[" . date('Y-m-d H:i:s') . "] Waiting $delay seconds before retry...";
                file_put_contents($logsDir . '/email.log', $retry_msg . "\n", FILE_APPEND | LOCK_EX);
                sleep($delay);
            }
        }
    }
    
    // Log final failure
    $final_error = "[" . date('Y-m-d H:i:s') . "] All $attempts attempts failed for sending email to $to";
    file_put_contents($logsDir . '/email.log', $final_error . "\n\n", FILE_APPEND | LOCK_EX);
    
    logEmail("All email send attempts failed", [
        'to' => $to,
        'total_attempts' => $attempts,
        'type' => $type
    ]);
    
    error_log("Email sending completely failed for: $to after $attempts attempts");
    
    return false;
}

function getUnsubscribeUrl($email) {
    require_once __DIR__ . '/../user/unsub_handler.php';
    $token = generateUnsubscribeToken($email);
    $encodedEmail = urlencode($email);
    $encodedToken = urlencode($token);
    
    return "https://amezprice.com/user/unsubscribe.php?email={$encodedEmail}&token={$encodedToken}";
}

// Test function for debugging
function testEmailConfig() {
    global $mailConfig, $logsDir;
    
    logEmail("Testing email configuration");
    
    if (!$mailConfig) {
        logEmail("ERROR: Mail configuration not loaded");
        return false;
    }
    
    if (!isset($mailConfig['otp'])) {
        logEmail("ERROR: OTP configuration not found");
        return false;
    }
    
    logEmail("Email configuration loaded successfully", [
        'otp_server' => $mailConfig['otp']['server'],
        'otp_port' => $mailConfig['otp']['port'],
        'otp_username' => $mailConfig['otp']['username']
    ]);
    
    return true;
}
?>