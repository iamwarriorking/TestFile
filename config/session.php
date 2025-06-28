<?php
// config/session.php

function validateSessionState() {
    if (!isset($_SESSION['initialized']) || 
        !isset($_SESSION['created_at']) ||
        !isset($_SESSION['last_activity'])) {
        return false;
    }
    
    // Check session age (3 hours to match cookie lifetime)
    $max_lifetime = 10800; // 3 hours
    if (time() - $_SESSION['created_at'] > $max_lifetime) {
        return false;
    }
    
    // Check inactivity
    $inactivity_limit = 900; // 15 minutes
    if (time() - $_SESSION['last_activity'] > $inactivity_limit) {
        return false;
    }
    
    return true;
}

function cleanupSession() {
    $_SESSION = array();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time()-3600, '/');
    }
    session_destroy();
}

function startApplicationSession() {
    // Check if session is already active
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Session is already running, just log it and return
        file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Session already active: " . session_id() . "\n", FILE_APPEND);
        return;
    }
    
    // Only start new session if none exists
    if (session_status() === PHP_SESSION_NONE) {
        $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                   (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        
        // Get the domain without www for cookie consistency
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $domain = preg_replace('/^www\./', '', $host);
        
        $sessionOptions = [
            'name' => 'AMEZPRICE_SESSID',
            'cookie_lifetime' => 10800, // 3 hour lifetime
            'cookie_path' => '/',
            'cookie_domain' => '.' . $domain, // Set domain to work with both www and non-www
            'cookie_secure' => $isSecure, // Set based on connection security
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax', // Changed back to Lax for better compatibility
            'use_strict_mode' => true,
            'gc_maxlifetime' => 10800 // Match cookie lifetime
        ];
        
        session_start($sessionOptions);
        
        // Log session initialization
        file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] New session started: " . session_id() . ", Secure: " . ($isSecure ? 'yes' : 'no') . ", Domain: ." . $domain . "\n", FILE_APPEND);
        
        // Only regenerate session ID for completely new/unauthenticated sessions
        // Initialize or update session timestamps
        if (!isset($_SESSION['initialized'])) {
            session_regenerate_id(true);
            $_SESSION['initialized'] = true;
            $_SESSION['created_at'] = time();
            $_SESSION['last_rotation'] = time();
            $_SESSION['last_activity'] = time();
            file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] New session initialized: " . session_id() . "\n", FILE_APPEND);
        } else {
            // Check and rotate session ID if needed
            if (isset($_SESSION['last_rotation'])) {
                $rotation_interval = 900; // 15 minutes
                if (time() - $_SESSION['last_rotation'] >= $rotation_interval) {
                    session_regenerate_id(true);
                    $_SESSION['last_rotation'] = time();
                    file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Session ID rotated: " . session_id() . "\n", FILE_APPEND);
                }
            }
            
            // Update last activity
            $_SESSION['last_activity'] = time();
        }
        
        // Validate session state
        if (!validateSessionState()) {
            cleanupSession();
            session_start($sessionOptions);
            file_put_contents(__DIR__ . '/../logs/auth.log', "[" . date('Y-m-d H:i:s') . "] Invalid session state, started new session: " . session_id() . "\n", FILE_APPEND);
        }
    }
}
?>