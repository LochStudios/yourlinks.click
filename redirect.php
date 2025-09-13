<?php
// Redirect handler for username.yourlinks.click/linkname URLs

// Include database connection
require_once 'services/database.php';

$db = Database::getInstance();

// Function to show custom expired/deactivated page
function showCustomPage($title, $message, $type) {
    $icon = $type === 'expired' ? 'fas fa-clock' : 'fas fa-ban';
    $color = $type === 'expired' ? '#ff6b6b' : '#4ecdc4';
    $bgColor = $type === 'expired' ? '#ffeaa7' : '#a8e6cf';
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . '</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, ' . $bgColor . ' 0%, ' . $color . ' 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .custom-page {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            padding: 3rem;
            text-align: center;
            max-width: 500px;
            margin: 2rem;
        }
        .icon-large {
            font-size: 4rem;
            color: ' . $color . ';
            margin-bottom: 1.5rem;
        }
        .title {
            color: #363636;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        .message {
            color: #7a7a7a;
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        .back-link {
            display: inline-block;
            color: ' . $color . ';
            text-decoration: none;
            font-weight: 600;
            border: 2px solid ' . $color . ';
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            transition: all 0.3s ease;
        }
        .back-link:hover {
            background: ' . $color . ';
            color: white;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="custom-page">
        <div class="icon-large">
            <i class="' . $icon . '"></i>
        </div>
        <h1 class="title is-2">' . htmlspecialchars($title) . '</h1>
        <p class="message">' . nl2br(htmlspecialchars($message)) . '</p>
        <a href="/" class="back-link">
            <i class="fas fa-home mr-2"></i>
            Go to YourLinks.click
        </a>
    </div>
</body>
</html>';
}

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

// Find the link in database (including expired and deactivated links to handle their behaviors)
$link = $db->select(
    "SELECT l.*, u.username FROM links l
        JOIN users u ON l.user_id = u.id
        WHERE u.username = ? AND l.link_name = ?",
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

// Check if link is deactivated
$isDeactivated = !$linkData['is_active'];

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
            // Show custom expired page
            showCustomPage(
                $linkData['expired_page_title'] ?? 'Link Expired',
                $linkData['expired_page_message'] ?? 'This link has expired and is no longer available.',
                'expired'
            );
            exit();
            
        case 'inactive':
        default:
            // Link is inactive/expired
            header('HTTP/1.0 404 Not Found');
            echo 'This link has expired';
            exit();
    }
} elseif ($isDeactivated) {
    // Handle deactivated link based on deactivation behavior
    switch ($linkData['deactivation_behavior']) {
        case 'redirect':
            if (!empty($linkData['deactivated_redirect_url'])) {
                // Track the deactivated click
                $db->execute(
                    "UPDATE links SET clicks = clicks + 1 WHERE id = ?",
                    [$linkData['id']]
                );
                
                // Log deactivated click
                $db->insert(
                    "INSERT INTO link_clicks (link_id, ip_address, user_agent, referrer, is_deactivated) VALUES (?, ?, ?, ?, TRUE)",
                    [
                        $linkData['id'],
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $_SERVER['HTTP_USER_AGENT'] ?? '',
                        $_SERVER['HTTP_REFERER'] ?? ''
                    ]
                );
                
                // Redirect to deactivated URL
                header('Location: ' . $linkData['deactivated_redirect_url']);
                exit();
            }
            // If no redirect URL, fall through to inactive behavior
            break;
            
        case 'custom_page':
            // Show custom deactivated page
            showCustomPage(
                $linkData['deactivated_page_title'] ?? 'Link Deactivated',
                $linkData['deactivated_page_message'] ?? 'This link has been deactivated and is no longer available.',
                'deactivated'
            );
            exit();
            
        case 'inactive':
        default:
            // Link is deactivated
            header('HTTP/1.0 404 Not Found');
            echo 'This link has been deactivated';
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