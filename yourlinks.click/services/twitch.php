<?php
session_start();

// Twitch OAuth Configuration
// Include sensitive configuration from external file
require_once '/var/www/config/yourlinksclick.php';

// Define constants from config variables for backward compatibility
define('TWITCH_CLIENT_ID', $twitch_client_id);
define('TWITCH_CLIENT_SECRET', $twitch_client_secret);
define('TWITCH_REDIRECT_URI', $twitch_redirect_uri);

// Include database connection
require_once 'database.php';

class TwitchAuth {
    private $clientId;
    private $clientSecret;
    private $redirectUri;

    public function __construct() {
        $this->clientId = TWITCH_CLIENT_ID;
        $this->clientSecret = TWITCH_CLIENT_SECRET;
        $this->redirectUri = TWITCH_REDIRECT_URI;
    }

    // Generate Twitch OAuth URL
    public function getAuthUrl() {
        $state = bin2hex(random_bytes(16)); // CSRF protection
        $_SESSION['oauth_state'] = $state;

        $params = array(
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'user:read:email',
            'state' => $state
        );

        return 'https://id.twitch.tv/oauth2/authorize?' . http_build_query($params);
    }

    // Exchange authorization code for access token
    public function getAccessToken($code) {
        $url = 'https://id.twitch.tv/oauth2/token';
        $data = array(
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri
        );

        $options = array(
            'http' => array(
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            )
        );

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === FALSE) {
            return false;
        }

        return json_decode($result, true);
    }

    // Get user information from Twitch
    public function getUserInfo($accessToken) {
        $url = 'https://api.twitch.tv/helix/users';
        $options = array(
            'http' => array(
                'header' => "Authorization: Bearer " . $accessToken . "\r\n" .
                           "Client-Id: " . $this->clientId . "\r\n",
                'method' => 'GET'
            )
        );

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === FALSE) {
            return false;
        }

        return json_decode($result, true);
    }

    // Save or update user in database
    public function saveUser($userData) {
        $db = Database::getInstance();

        // Check if user exists
        $existingUser = $db->select("SELECT id FROM users WHERE twitch_id = ?", [$userData['id']]);

        if (empty($existingUser)) {
            // Insert new user
            $userId = $db->insert(
                "INSERT INTO users (twitch_id, username, email, display_name, profile_image_url, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
                [
                    $userData['id'],
                    $userData['login'],
                    $userData['email'],
                    $userData['display_name'],
                    $userData['profile_image_url']
                ]
            );
        } else {
            // Update existing user
            $db->execute(
                "UPDATE users SET username = ?, email = ?, display_name = ?, profile_image_url = ?, updated_at = NOW() WHERE twitch_id = ?",
                [
                    $userData['login'],
                    $userData['email'],
                    $userData['display_name'],
                    $userData['profile_image_url'],
                    $userData['id']
                ]
            );
            $userId = $existingUser[0]['id'];
        }

        return $userId;
    }
}

// Handle the OAuth flow
$twitch = new TwitchAuth();

if (isset($_GET['login'])) {
    // Redirect to Twitch OAuth
    header('Location: ' . $twitch->getAuthUrl());
    exit();
} elseif (isset($_GET['code'])) {
    // Handle OAuth callback
    $code = $_GET['code'];
    $state = $_GET['state'];

    // Verify state for CSRF protection
    if (!isset($_SESSION['oauth_state']) || $state !== $_SESSION['oauth_state']) {
        die('Invalid state parameter');
    }

    // Get access token
    $tokenData = $twitch->getAccessToken($code);
    if (!$tokenData || isset($tokenData['error'])) {
        die('Failed to get access token: ' . ($tokenData['error_description'] ?? 'Unknown error'));
    }

    // Get user info
    $userData = $twitch->getUserInfo($tokenData['access_token']);
    if (!$userData || !isset($userData['data'][0])) {
        die('Failed to get user information');
    }

    $user = $userData['data'][0];

    // Save user to database
    $userId = $twitch->saveUser($user);

    // Store user session
    $_SESSION['user_id'] = $userId;
    $_SESSION['twitch_user'] = $user;

    // Redirect to dashboard or home
    header('Location: /dashboard.php'); // You'll need to create this
    exit();
} elseif (isset($_GET['logout'])) {
    // Handle logout
    session_destroy();
    header('Location: /');
    exit();
} else {
    // Invalid request
    header('HTTP/1.1 400 Bad Request');
    echo 'Invalid request';
}
?>