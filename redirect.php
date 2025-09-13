<?php
// Redirect handler for username.yourlinks.click/linkname URLs

// Include database connection
require_once 'services/database.php';

$db = Database::getInstance();

// Get the subdomain (username) and link name from the URL
$host = $_SERVER['HTTP_HOST'];
$requestUri = $_SERVER['REQUEST_URI'];

// Extract subdomain from host
$parts = explode('.', $host);
$subdomain = $parts[0];

// If it's the main domain, redirect to home
if ($subdomain === 'yourlinks' || $subdomain === 'www') {
    header('Location: /');
    exit();
}

// Extract link name from URI (remove leading slash and query parameters)
$linkName = trim(parse_url($requestUri, PHP_URL_PATH), '/');

// If no link name provided, redirect to user's main page (could be their Twitch profile)
if (empty($linkName)) {
    // Try to find user and redirect to their Twitch profile
    $user = $db->select("SELECT * FROM users WHERE username = ?", [$subdomain]);
    if ($user) {
        // Redirect to Twitch profile
        $twitchUrl = "https://twitch.tv/" . $user[0]['username'];
        header('Location: ' . $twitchUrl);
        exit();
    } else {
        header('HTTP/1.0 404 Not Found');
        echo 'User not found';
        exit();
    }
}

// Find the link in database
$link = $db->select(
    "SELECT l.*, u.username FROM links l
        JOIN users u ON l.user_id = u.id
        WHERE u.username = ? AND l.link_name = ? AND l.is_active = TRUE
        AND (l.expires_at IS NULL OR l.expires_at > NOW())",
    [$subdomain, $linkName]
);

if (!$link) {
    header('HTTP/1.0 404 Not Found');
    echo 'Link not found';
    exit();
}

$linkData = $link[0];

// Track the click
$db->execute(
    "UPDATE links SET clicks = clicks + 1 WHERE id = ?",
    [$linkData['id']]
);

// Log click details
$db->insert(
    "INSERT INTO link_clicks (link_id, ip_address, user_agent, referrer) VALUES (?, ?, ?, ?)",
    [
        $linkData['id'],
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $_SERVER['HTTP_REFERER'] ?? ''
    ]
);

// Redirect to the original URL
header('Location: ' . $linkData['original_url']);
exit();
?>