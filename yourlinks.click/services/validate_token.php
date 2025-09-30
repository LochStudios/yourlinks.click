<?php
// Token validation endpoint for AJAX calls
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once 'database.php';

// Twitch token validation function
function validateToken($accessToken) {
    $url = 'https://id.twitch.tv/oauth2/validate';
    $options = array(
        'http' => array(
            'header' => "Authorization: OAuth " . $accessToken . "\r\n",
            'method' => 'GET'
        )
    );

    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);

    if ($result === FALSE) {
        return false;
    }

    $response = json_decode($result, true);

    // Check if the response indicates a valid token
    if (isset($response['client_id']) && isset($response['user_id'])) {
        return $response;
    }

    return false;
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['access_token'])) {
    header('Content-Type: application/json');
    echo json_encode(['valid' => false, 'message' => 'Not logged in']);
    exit();
}

$tokenValidation = validateToken($_SESSION['access_token']);

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