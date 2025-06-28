<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/globals.php';

// Function to generate an unsubscribe token for an email
function generateUnsubscribeToken($email) {

    return hash('sha256', $email . UNSUBSCRIBE_SECRET_KEY);
}

// Function to verify an unsubscribe token
function verifyUnsubscribeToken($email, $token) {
    $expected_token = generateUnsubscribeToken($email);
    return hash_equals($expected_token, $token);
}
?>