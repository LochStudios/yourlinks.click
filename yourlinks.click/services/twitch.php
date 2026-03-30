<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class TwitchAuth {
    // Validate a Twitch access token against Twitch's validation endpoint
    public function validateToken($accessToken) {
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
        if (isset($response['client_id']) && isset($response['user_id'])) {
            return $response;
        }
        return false;
    }
}

// Handle logout when accessed directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    if (isset($_GET['logout'])) {
        $_SESSION = array();
        session_destroy();
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        header('Location: /');
        exit();
    }
    header('HTTP/1.1 404 Not Found');
    exit();
}
?>