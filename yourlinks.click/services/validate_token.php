<?php
// Token validation endpoint for AJAX calls
session_start();

// Include Twitch service for token validation
require_once 'services/twitch.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['access_token'])) {
    header('Content-Type: application/json');
    echo json_encode(['valid' => false, 'message' => 'Not logged in']);
    exit();
}

$twitch = new TwitchAuth();
$tokenValidation = $twitch->validateToken($_SESSION['access_token']);

if (!$tokenValidation || $tokenValidation['user_id'] !== $_SESSION['twitch_user']['id']) {
    // Token is invalid, destroy session
    session_destroy();
    header('Content-Type: application/json');
    echo json_encode(['valid' => false, 'message' => 'Token invalid']);
    exit();
}

// Token is valid
header('Content-Type: application/json');
echo json_encode(['valid' => true, 'message' => 'Token valid']);
exit();
?>