<?php
// Redirect handler for username.yourlinks.click/linkname URLs

// Include database connection
require_once 'services/database.php';

$db = Database::getInstance();

// Get the subdomain (username) and link name from the URL
$host = $_SERVER['HTTP_HOST'];
$requestUri = $_SERVER['REQUEST_URI'];

// Check if this is a custom domain request
$customDomainUser = null;
if ($host !== 'yourlinks.click' && $host !== 'www.yourlinks.click' && strpos($host, '.yourlinks.click') === false) {
    // This might be a custom domain - check if it belongs to a user
    $customDomainUser = $db->select("SELECT id, username FROM users WHERE custom_domain = ? AND domain_verified = TRUE", [$host]);
    if ($customDomainUser) {
        $subdomain = $customDomainUser[0]['username'];
    } else {
        // Not a recognized custom domain
        header('HTTP/1.0 404 Not Found');
        echo 'Domain not found';
        exit();
    }
} else {
    // Extract subdomain from host for yourlinks.click domains
    $parts = explode('.', $host);
    $subdomain = $parts[0];
}

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

// Find the link in database (including expired links to handle expiration behavior)
$link = $db->select(
    "SELECT l.*, u.username FROM links l
        JOIN users u ON l.user_id = u.id
        WHERE u.username = ? AND l.link_name = ? AND l.is_active = TRUE",
    [$subdomain, $linkName]
);

if (!$link) {
    header('HTTP/1.0 404 Not Found');
    echo 'Link not found';
    exit();
}

$linkData = $link[0];

// Check if link is expired
$isExpired = !empty($linkData['expires_at']) && strtotime($linkData['expires_at']) <= time();

if ($isExpired) {
    // Handle expired link based on expiration behavior
    switch ($linkData['expiration_behavior']) {
        case 'redirect':
            if (!empty($linkData['expired_redirect_url'])) {
                // Track the expired click
                $db->execute(
                    "UPDATE links SET clicks = clicks + 1 WHERE id = ?",
                    [$linkData['id']]
                );
                
                // Log expired click
                $db->insert(
                    "INSERT INTO link_clicks (link_id, ip_address, user_agent, referrer, is_expired) VALUES (?, ?, ?, ?, TRUE)",
                    [
                        $linkData['id'],
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $_SERVER['HTTP_USER_AGENT'] ?? '',
                        $_SERVER['HTTP_REFERER'] ?? ''
                    ]
                );
                
                // Redirect to expired URL
                header('Location: ' . $linkData['expired_redirect_url']);
                exit();
            }
            // If no redirect URL, fall through to inactive behavior
            break;
            
        case 'custom_page':
            // Future feature: show custom expired page
            // For now, fall through to inactive behavior
            break;
            
        case 'inactive':
        default:
            // Link is inactive/expired
            header('HTTP/1.0 404 Not Found');
            echo 'This link has expired';
            exit();
    }
}

// Link is active and not expired - proceed with normal redirect
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