<?php
// StreamersConnect OAuth callback handler
// Receives auth_data from StreamersConnect after Twitch OAuth

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'services/database.php';

// Determine the login URL used throughout the site
define('SC_LOGIN_URL', 'https://streamersconnect.com/?service=twitch&login=yourlinks.click&scopes=user:read:email&return_url=https://yourlinks.click/callback.php');

if (!isset($_GET['auth_data'])) {
    // No auth data — redirect back to homepage with error
    header('Location: /?error=no_auth_data');
    exit();
}

// Decode the base64-encoded JSON payload from StreamersConnect
$authData = json_decode(base64_decode($_GET['auth_data']), true);

if (!$authData || empty($authData['success']) || empty($authData['user'])) {
    header('Location: /?error=auth_failed');
    exit();
}

$userData = $authData['user'];
$accessToken = $authData['access_token'] ?? '';

// Ensure we have the minimum required fields
if (empty($userData['id']) || empty($accessToken)) {
    header('Location: /?error=auth_failed');
    exit();
}

// Normalise field names — Twitch uses 'login', Discord uses 'username'
$login       = $userData['login']        ?? $userData['username']    ?? '';
$displayName = $userData['display_name'] ?? $userData['global_name'] ?? $login;
$email       = $userData['email']        ?? '';
$profileImg  = $userData['profile_image_url'] ?? '';

$db = Database::getInstance();

// Save or update user record
$existingUser = $db->select("SELECT id FROM users WHERE twitch_id = ?", [$userData['id']]);

if (empty($existingUser)) {
    $userId = $db->insert(
        "INSERT INTO users (twitch_id, username, email, display_name, profile_image_url, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
        [$userData['id'], $login, $email, $displayName, $profileImg]
    );
} else {
    $db->execute(
        "UPDATE users SET username = ?, email = ?, display_name = ?, profile_image_url = ?, updated_at = NOW() WHERE twitch_id = ?",
        [$login, $email, $displayName, $profileImg, $userData['id']]
    );
    $userId = $existingUser[0]['id'];
}

// Populate session — same shape the rest of the app expects
$_SESSION['user_id']     = $userId;
$_SESSION['access_token'] = $accessToken;
$_SESSION['twitch_user'] = [
    'id'                => $userData['id'],
    'login'             => $login,
    'display_name'      => $displayName,
    'email'             => $email,
    'profile_image_url' => $profileImg,
];

header('Location: /dashboard.php');
exit();
