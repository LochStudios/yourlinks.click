<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// Login URL via StreamersConnect
define('SC_LOGIN_URL', 'https://streamersconnect.com/?service=twitch&login=yourlinks.click&scopes=user:read:email&return_url=https://yourlinks.click/callback.php');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . SC_LOGIN_URL);
    exit();
}

// Include Twitch service for token validation
require_once 'services/twitch.php';

// Validate Twitch access token
if (!isset($_SESSION['access_token'])) {
    // No token stored, redirect to login
    session_destroy();
    header('Location: ' . SC_LOGIN_URL);
    exit();
}

$twitch = new TwitchAuth();
$tokenValidation = $twitch->validateToken($_SESSION['access_token']);

if (!$tokenValidation || $tokenValidation['user_id'] !== $_SESSION['twitch_user']['id']) {
    // Token is invalid or doesn't match the user, destroy session and redirect to login
    session_destroy();
    header('Location: ' . SC_LOGIN_URL);
    exit();
}

// Include database connection
require_once 'services/database.php';
require_once 'services/brandfetch.php';

$db = Database::getInstance();
brandfetch_ensure_table($db);
$user = $_SESSION['twitch_user'];

// Ensure profile tables exist
$db->query("CREATE TABLE IF NOT EXISTS profile_settings (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL UNIQUE,
    bio          VARCHAR(500)  DEFAULT NULL,
    page_title   VARCHAR(100)  DEFAULT NULL,
    accent_color VARCHAR(7)    NOT NULL DEFAULT '#7c5cbf',
    show_profile_pic TINYINT(1) NOT NULL DEFAULT 1,
    home_mode    ENUM('linktree','twitch_redirect') NOT NULL DEFAULT 'linktree',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$_col_check = $db->getConnection()->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profile_settings' AND COLUMN_NAME = 'home_mode'");
if ($_col_check && $_col_check->num_rows === 0) {
    $db->getConnection()->query("ALTER TABLE profile_settings ADD COLUMN home_mode ENUM('linktree','twitch_redirect') NOT NULL DEFAULT 'linktree'");
}
unset($_col_check);
$db->query("CREATE TABLE IF NOT EXISTS profile_links (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    platform      VARCHAR(50)   NOT NULL DEFAULT 'custom',
    title         VARCHAR(100)  NOT NULL,
    url           VARCHAR(2048) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Platform definitions (shared with profile.php)
$platformDefs = [
    'twitch'    => ['icon' => 'fab fa-twitch',    'label' => 'Twitch',      'color' => '#6441a5'],
    'youtube'   => ['icon' => 'fab fa-youtube',   'label' => 'YouTube',     'color' => '#ff0000'],
    'twitter'   => ['icon' => 'fab fa-x-twitter', 'label' => 'X / Twitter', 'color' => '#e7e7e7'],
    'instagram' => ['icon' => 'fab fa-instagram', 'label' => 'Instagram',   'color' => '#e1306c'],
    'discord'   => ['icon' => 'fab fa-discord',   'label' => 'Discord',     'color' => '#5865f2'],
    'tiktok'    => ['icon' => 'fab fa-tiktok',    'label' => 'TikTok',      'color' => '#e7e7e7'],
    'facebook'  => ['icon' => 'fab fa-facebook',  'label' => 'Facebook',    'color' => '#1877f2'],
    'linkedin'  => ['icon' => 'fab fa-linkedin',  'label' => 'LinkedIn',    'color' => '#0077b5'],
    'spotify'   => ['icon' => 'fab fa-spotify',   'label' => 'Spotify',     'color' => '#1db954'],
    'github'    => ['icon' => 'fab fa-github',    'label' => 'GitHub',      'color' => '#e7e7e7'],
    'custom'    => ['icon' => 'fas fa-link',      'label' => 'Custom Link', 'color' => '#7c5cbf'],
];

// Load profile data
$profileSettings = $db->select("SELECT * FROM profile_settings WHERE user_id = ?", [$_SESSION['user_id']]);
$profileSettings = !empty($profileSettings) ? $profileSettings[0] : [];
$profileLinksList = $db->select(
    "SELECT * FROM profile_links WHERE user_id = ? ORDER BY display_order ASC, id ASC",
    [$_SESSION['user_id']]
);

// Get user's links for search functionality
$userLinks = $db->select(
    "SELECT l.*, c.name as category_name, c.color as category_color, c.icon as category_icon,
     CASE 
         WHEN l.expires_at IS NOT NULL AND l.expires_at <= NOW() THEN 'expired'
         WHEN l.expires_at IS NOT NULL AND l.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 'expiring_soon'
         ELSE 'active'
     END as expiration_status
     FROM links l
     LEFT JOIN categories c ON l.category_id = c.id
     WHERE l.user_id = ? ORDER BY l.created_at DESC",
    [$_SESSION['user_id']]
);

// Get user's categories
$userCategories = $db->select(
    "SELECT * FROM categories WHERE user_id = ? ORDER BY name ASC",
    [$_SESSION['user_id']]
);

// Create default categories if user has none
if (empty($userCategories)) {
    $defaultCategories = [
        ['name' => 'Social Media', 'description' => 'Links to social media profiles', 'color' => '#1da1f2', 'icon' => 'fab fa-twitter'],
        ['name' => 'Gaming', 'description' => 'Gaming related links', 'color' => '#6441a5', 'icon' => 'fas fa-gamepad'],
        ['name' => 'Music', 'description' => 'Music and audio links', 'color' => '#e91e63', 'icon' => 'fas fa-music'],
        ['name' => 'Videos', 'description' => 'Video content links', 'color' => '#ff0000', 'icon' => 'fab fa-youtube'],
        ['name' => 'Other', 'description' => 'Miscellaneous links', 'color' => '#607d8b', 'icon' => 'fas fa-link']
    ];
    foreach ($defaultCategories as $category) {
        $db->insert(
            "INSERT INTO categories (user_id, name, description, color, icon) VALUES (?, ?, ?, ?, ?)",
            [$_SESSION['user_id'], $category['name'], $category['description'], $category['color'], $category['icon']]
        );
    }
    // Refresh categories
    $userCategories = $db->select(
        "SELECT * FROM categories WHERE user_id = ? ORDER BY name ASC",
        [$_SESSION['user_id']]
    );
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_link'])) {
        // Create new link
        $linkName = trim($_POST['link_name']);
        $originalUrl = trim($_POST['original_url']);
        $title = trim($_POST['title'] ?? '');
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $expirationBehavior = $_POST['expiration_behavior'] ?? 'inactive';
        $expiredRedirectUrl = trim($_POST['expired_redirect_url'] ?? '');
        $expiredPageTitle = trim($_POST['expired_page_title'] ?? 'Link Expired');
        $expiredPageMessage = trim($_POST['expired_page_message'] ?? 'This link has expired and is no longer available.');
        // Validate inputs
        if (empty($linkName) || empty($originalUrl)) {
            $error = "Link name and destination URL are required.";
        } elseif (!filter_var($originalUrl, FILTER_VALIDATE_URL)) {
            $error = "Please enter a valid URL.";
        } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $linkName)) {
            $error = "Link name can only contain letters, numbers, hyphens, and underscores.";
        } elseif ($expiresAt && strtotime($expiresAt) <= time()) {
            $error = "Expiration date must be in the future.";
        } elseif ($expirationBehavior === 'redirect' && empty($expiredRedirectUrl)) {
            $error = "Redirect URL is required when expiration behavior is set to redirect.";
        } elseif ($expirationBehavior === 'redirect' && !filter_var($expiredRedirectUrl, FILTER_VALIDATE_URL)) {
            $error = "Please enter a valid redirect URL.";
        } else {
            // Check if link name already exists for this user
            $existing = $db->select("SELECT id FROM links WHERE user_id = ? AND link_name = ?", [$_SESSION['user_id'], $linkName]);
            if ($existing) {
                $error = "You already have a link with this name.";
            } else {
                // Create the link
                $db->insert(
                    "INSERT INTO links (user_id, link_name, original_url, title, category_id, expires_at, expired_redirect_url, expired_page_title, expired_page_message, expiration_behavior) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$_SESSION['user_id'], $linkName, $originalUrl, $title, $categoryId, $expiresAt, $expiredRedirectUrl, $expiredPageTitle, $expiredPageMessage, $expirationBehavior]
                );
                $success = "Link created successfully!";
                // Refresh links data
                $userLinks = $db->select(
                    "SELECT l.*, c.name as category_name, c.color as category_color, c.icon as category_icon,
                     CASE 
                         WHEN l.expires_at IS NOT NULL AND l.expires_at <= NOW() THEN 'expired'
                         WHEN l.expires_at IS NOT NULL AND l.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 'expiring_soon'
                         ELSE 'active'
                     END as expiration_status
                     FROM links l
                     LEFT JOIN categories c ON l.category_id = c.id
                     WHERE l.user_id = ? ORDER BY l.created_at DESC",
                    [$_SESSION['user_id']]
                );
            }
        }
    } elseif (isset($_POST['edit_link'])) {
        // Edit existing link
        $linkId = (int)$_POST['edit_link_id'];
        $linkName = trim($_POST['edit_link_name']);
        $originalUrl = trim($_POST['edit_original_url']);
        $title = trim($_POST['edit_title'] ?? '');
        $categoryId = !empty($_POST['edit_category_id']) ? (int)$_POST['edit_category_id'] : null;
        $expiresAt = !empty($_POST['edit_expires_at']) ? $_POST['edit_expires_at'] : null;
        $expirationBehavior = $_POST['edit_expiration_behavior'] ?? 'inactive';
        $expiredRedirectUrl = trim($_POST['edit_expired_redirect_url'] ?? '');
        $expiredPageTitle = trim($_POST['edit_expired_page_title'] ?? 'Link Expired');
        $expiredPageMessage = trim($_POST['edit_expired_page_message'] ?? 'This link has expired and is no longer available.');
        // Validate inputs
        if (empty($linkName) || empty($originalUrl)) {
            $error = "Link name and destination URL are required.";
        } elseif (!filter_var($originalUrl, FILTER_VALIDATE_URL)) {
            $error = "Please enter a valid URL.";
        } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $linkName)) {
            $error = "Link name can only contain letters, numbers, hyphens, and underscores.";
        } elseif ($expiresAt && strtotime($expiresAt) <= time()) {
            $error = "Expiration date must be in the future.";
        } elseif ($expirationBehavior === 'redirect' && empty($expiredRedirectUrl)) {
            $error = "Redirect URL is required when expiration behavior is set to redirect.";
        } elseif ($expirationBehavior === 'redirect' && !filter_var($expiredRedirectUrl, FILTER_VALIDATE_URL)) {
            $error = "Please enter a valid redirect URL.";
        } else {
            // Check if link name already exists for this user (excluding current link)
            $existing = $db->select("SELECT id FROM links WHERE user_id = ? AND link_name = ? AND id != ?", [$_SESSION['user_id'], $linkName, $linkId]);
            if ($existing) {
                $error = "You already have a link with this name.";
            } else {
                // Update the link
                $db->execute(
                    "UPDATE links SET link_name = ?, original_url = ?, title = ?, category_id = ?, expires_at = ?, expired_redirect_url = ?, expired_page_title = ?, expired_page_message = ?, expiration_behavior = ? WHERE id = ? AND user_id = ?",
                    [$linkName, $originalUrl, $title, $categoryId, $expiresAt, $expiredRedirectUrl, $expiredPageTitle, $expiredPageMessage, $expirationBehavior, $linkId, $_SESSION['user_id']]
                );
                $success = "Link updated successfully!";
                // Refresh links data
                $userLinks = $db->select(
                    "SELECT l.*, c.name as category_name, c.color as category_color, c.icon as category_icon,
                     CASE 
                         WHEN l.expires_at IS NOT NULL AND l.expires_at <= NOW() THEN 'expired'
                         WHEN l.expires_at IS NOT NULL AND l.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 'expiring_soon'
                         ELSE 'active'
                     END as expiration_status
                     FROM links l
                     LEFT JOIN categories c ON l.category_id = c.id
                     WHERE l.user_id = ? ORDER BY l.created_at DESC",
                    [$_SESSION['user_id']]
                );
            }
        }
    } elseif (isset($_POST['activate_link']) && isset($_POST['link_id'])) {
        // Activate existing link
        $linkId = (int)$_POST['link_id'];
        // Update the link to be active
        $db->execute(
            "UPDATE links SET is_active = TRUE WHERE id = ? AND user_id = ?",
            [$linkId, $_SESSION['user_id']]
        );
        $success = "Link activated successfully!";
        // Refresh links data
        $userLinks = $db->select(
            "SELECT l.*, c.name as category_name, c.color as category_color, c.icon as category_icon,
             CASE 
                 WHEN l.expires_at IS NOT NULL AND l.expires_at <= NOW() THEN 'expired'
                 WHEN l.expires_at IS NOT NULL AND l.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 'expiring_soon'
                 ELSE 'active'
             END as expiration_status
             FROM links l
             LEFT JOIN categories c ON l.category_id = c.id
             WHERE l.user_id = ? ORDER BY l.created_at DESC",
            [$_SESSION['user_id']]
        );
    } elseif (isset($_POST['deactivate_link']) && isset($_POST['deactivate_link_id'])) {
        // Deactivate existing link with custom behavior
        $linkId = (int)$_POST['deactivate_link_id'];
        $deactivateBehavior = $_POST['deactivate_behavior'] ?? 'inactive';
        $deactivateRedirectUrl = trim($_POST['deactivate_redirect_url'] ?? '');
        $deactivateRedirectUrl = trim($_POST['deactivate_redirect_url'] ?? '');
        $deactivatedPageTitle = trim($_POST['deactivated_page_title'] ?? 'Link Deactivated');
        $deactivatedPageMessage = trim($_POST['deactivated_page_message'] ?? 'This link has been deactivated and is no longer available.');
        // Validate inputs
        if ($deactivateBehavior === 'redirect' && empty($deactivateRedirectUrl)) {
            $error = "Redirect URL is required when deactivation behavior is set to redirect.";
        } elseif ($deactivateBehavior === 'redirect' && !filter_var($deactivateRedirectUrl, FILTER_VALIDATE_URL)) {
            $error = "Please enter a valid redirect URL.";
        } else {
            // Update the link to be inactive with deactivation behavior
            $db->execute(
                "UPDATE links SET is_active = FALSE, deactivation_behavior = ?, deactivated_redirect_url = ?, deactivated_page_title = ?, deactivated_page_message = ? WHERE id = ? AND user_id = ?",
                [$deactivateBehavior, $deactivateRedirectUrl, $deactivatedPageTitle, $deactivatedPageMessage, $linkId, $_SESSION['user_id']]
            );
            $success = "Link deactivated successfully!";
            // Refresh links data
            $userLinks = $db->select(
                "SELECT l.*, c.name as category_name, c.color as category_color, c.icon as category_icon,
                 CASE 
                     WHEN l.expires_at IS NOT NULL AND l.expires_at <= NOW() THEN 'expired'
                     WHEN l.expires_at IS NOT NULL AND l.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 'expiring_soon'
                     ELSE 'active'
                 END as expiration_status
                 FROM links l
                 LEFT JOIN categories c ON l.category_id = c.id
                 WHERE l.user_id = ? ORDER BY l.created_at DESC",
                [$_SESSION['user_id']]
            );
        }
    } elseif (isset($_POST['delete_link']) && isset($_POST['link_id'])) {
        // Delete existing link
        $linkId = (int)$_POST['link_id'];
        // Verify the link belongs to the user before deleting
        $link = $db->select("SELECT id FROM links WHERE id = ? AND user_id = ?", [$linkId, $_SESSION['user_id']]);
        if ($link) {
            $db->execute("DELETE FROM links WHERE id = ? AND user_id = ?", [$linkId, $_SESSION['user_id']]);
            $success = "Link deleted successfully!";
            // Refresh links data
            $userLinks = $db->select(
                "SELECT l.*, c.name as category_name, c.color as category_color, c.icon as category_icon,
                 CASE 
                     WHEN l.expires_at IS NOT NULL AND l.expires_at <= NOW() THEN 'expired'
                     WHEN l.expires_at IS NOT NULL AND l.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 'expiring_soon'
                     ELSE 'active'
                 END as expiration_status
                 FROM links l
                 LEFT JOIN categories c ON l.category_id = c.id
                 WHERE l.user_id = ? ORDER BY l.created_at DESC",
                [$_SESSION['user_id']]
            );
        } else {
            $error = "Link not found or you don't have permission to delete it.";
        }
    } elseif (isset($_POST['save_profile_settings'])) {
        $bio         = substr(trim($_POST['profile_bio'] ?? ''), 0, 500);
        $pageTitle   = substr(trim($_POST['profile_page_title'] ?? ''), 0, 100);
        $accentColor = trim($_POST['profile_accent_color'] ?? '#7c5cbf');
        $showPic     = isset($_POST['profile_show_pic']) ? 1 : 0;
        $homeMode    = ($_POST['home_mode'] ?? 'linktree') === 'twitch_redirect' ? 'twitch_redirect' : 'linktree';
        // Validate accent colour
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accentColor)) {
            $accentColor = '#7c5cbf';
        }
        $existing = $db->select("SELECT id FROM profile_settings WHERE user_id = ?", [$_SESSION['user_id']]);
        if (empty($existing)) {
            $db->insert(
                "INSERT INTO profile_settings (user_id, bio, page_title, accent_color, show_profile_pic, home_mode) VALUES (?, ?, ?, ?, ?, ?)",
                [$_SESSION['user_id'], $bio ?: null, $pageTitle ?: null, $accentColor, $showPic, $homeMode]
            );
        } else {
            $db->execute(
                "UPDATE profile_settings SET bio = ?, page_title = ?, accent_color = ?, show_profile_pic = ?, home_mode = ? WHERE user_id = ?",
                [$bio ?: null, $pageTitle ?: null, $accentColor, $showPic, $homeMode, $_SESSION['user_id']]
            );
        }
        $success = "Profile settings saved!";
        $profileSettings = $db->select("SELECT * FROM profile_settings WHERE user_id = ?", [$_SESSION['user_id']]);
        $profileSettings = !empty($profileSettings) ? $profileSettings[0] : [];
    } elseif (isset($_POST['add_profile_link'])) {
        $platform = $_POST['pl_platform'] ?? 'custom';
        $title    = substr(trim($_POST['pl_title'] ?? ''), 0, 100);
        $url      = trim($_POST['pl_url'] ?? '');
        $isActive = isset($_POST['pl_active']) ? 1 : 0;
        $allowed  = array_keys($platformDefs);
        if (!in_array($platform, $allowed, true)) $platform = 'custom';
        if (empty($title) || empty($url)) {
            $error = "Title and URL are required.";
        } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
            $error = "Please enter a valid URL.";
        } else {
            $maxOrder = $db->select("SELECT MAX(display_order) as mo FROM profile_links WHERE user_id = ?", [$_SESSION['user_id']]);
            $nextOrder = (int)($maxOrder[0]['mo'] ?? -1) + 1;
            $db->insert(
                "INSERT INTO profile_links (user_id, platform, title, url, display_order, is_active) VALUES (?, ?, ?, ?, ?, ?)",
                [$_SESSION['user_id'], $platform, $title, $url, $nextOrder, $isActive]
            );
            $success = "Profile link added!";
        }
        $profileLinksList = $db->select(
            "SELECT * FROM profile_links WHERE user_id = ? ORDER BY display_order ASC, id ASC",
            [$_SESSION['user_id']]
        );
    } elseif (isset($_POST['edit_profile_link']) && isset($_POST['plink_id'])) {
        $plinkId  = (int)$_POST['plink_id'];
        $platform = $_POST['pl_platform'] ?? 'custom';
        $title    = substr(trim($_POST['pl_title'] ?? ''), 0, 100);
        $url      = trim($_POST['pl_url'] ?? '');
        $isActive = isset($_POST['pl_active']) ? 1 : 0;
        $allowed  = array_keys($platformDefs);
        if (!in_array($platform, $allowed, true)) $platform = 'custom';
        if (empty($title) || empty($url)) {
            $error = "Title and URL are required.";
        } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
            $error = "Please enter a valid URL.";
        } else {
            $db->execute(
                "UPDATE profile_links SET platform = ?, title = ?, url = ?, is_active = ? WHERE id = ? AND user_id = ?",
                [$platform, $title, $url, $isActive, $plinkId, $_SESSION['user_id']]
            );
            $success = "Profile link updated!";
        }
        $profileLinksList = $db->select(
            "SELECT * FROM profile_links WHERE user_id = ? ORDER BY display_order ASC, id ASC",
            [$_SESSION['user_id']]
        );
    } elseif (isset($_POST['delete_profile_link']) && isset($_POST['plink_id'])) {
        $plinkId = (int)$_POST['plink_id'];
        $db->execute("DELETE FROM profile_links WHERE id = ? AND user_id = ?", [$plinkId, $_SESSION['user_id']]);
        $success = "Profile link removed.";
        $profileLinksList = $db->select(
            "SELECT * FROM profile_links WHERE user_id = ? ORDER BY display_order ASC, id ASC",
            [$_SESSION['user_id']]
        );
    } elseif (isset($_POST['move_profile_link']) && isset($_POST['plink_id']) && isset($_POST['direction'])) {
        $plinkId   = (int)$_POST['plink_id'];
        $direction = $_POST['direction'] === 'up' ? 'up' : 'down';
        $current   = $db->select("SELECT id, display_order FROM profile_links WHERE id = ? AND user_id = ?", [$plinkId, $_SESSION['user_id']]);
        if (!empty($current)) {
            $curOrder = (int)$current[0]['display_order'];
            if ($direction === 'up') {
                $swap = $db->select("SELECT id, display_order FROM profile_links WHERE user_id = ? AND display_order < ? ORDER BY display_order DESC LIMIT 1", [$_SESSION['user_id'], $curOrder]);
            } else {
                $swap = $db->select("SELECT id, display_order FROM profile_links WHERE user_id = ? AND display_order > ? ORDER BY display_order ASC LIMIT 1", [$_SESSION['user_id'], $curOrder]);
            }
            if (!empty($swap)) {
                $db->execute("UPDATE profile_links SET display_order = ? WHERE id = ?", [(int)$swap[0]['display_order'], $plinkId]);
                $db->execute("UPDATE profile_links SET display_order = ? WHERE id = ?", [$curOrder, (int)$swap[0]['id']]);
            }
        }
        $profileLinksList = $db->select(
            "SELECT * FROM profile_links WHERE user_id = ? ORDER BY display_order ASC, id ASC",
            [$_SESSION['user_id']]
        );
    } elseif (isset($_POST['update_custom_domain'])) {
        if ($user['login'] === 'gfaundead') {
            $customDomain = trim($_POST['custom_domain']);
            $domainError = null;
            if (!empty($customDomain)) {
                // Validate domain format
                if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $customDomain)) {
                    $domainError = "Please enter a valid domain name.";
                } elseif (substr($customDomain, 0, 4) === 'www.') {
                    $domainError = "Please enter the domain without 'www.' (e.g., example.com).";
                } else {
                    // Check if domain is already used by another user
                    $existingDomain = $db->select("SELECT id FROM users WHERE custom_domain = ? AND id != ?", [$customDomain, $_SESSION['user_id']]);
                    if ($existingDomain) {
                        $domainError = "This domain is already in use by another user.";
                    }
                }
            }
            if (!$domainError) {
                $db->execute(
                    "UPDATE users SET custom_domain = ?, domain_verified = FALSE WHERE id = ?",
                    [$customDomain ?: null, $_SESSION['user_id']]
                );
                $success = empty($customDomain) ? "Custom domain removed successfully!" : "Custom domain updated! Please verify ownership.";
            } else {
                $error = $domainError;
            }
        } else {
            $error = "Custom domains are currently in development.";
        }
    } elseif (isset($_POST['create_category'])) {
        $categoryName = trim($_POST['category_name']);
        $categoryDescription = trim($_POST['category_description'] ?? '');
        $categoryColor = trim($_POST['category_color'] ?? '#3273dc');
        $categoryIcon = trim($_POST['category_icon'] ?? 'fas fa-tag');
        if (empty($categoryName)) {
            $error = "Category name is required.";
        } else {
            // Check if category name already exists for this user
            $existing = $db->select("SELECT id FROM categories WHERE user_id = ? AND name = ?", [$_SESSION['user_id'], $categoryName]);
            if ($existing) {
                $error = "You already have a category with this name.";
            } else {
                $db->insert(
                    "INSERT INTO categories (user_id, name, description, color, icon) VALUES (?, ?, ?, ?, ?)",
                    [$_SESSION['user_id'], $categoryName, $categoryDescription, $categoryColor, $categoryIcon]
                );
                $success = "Category created successfully!";
                // Refresh categories
                $userCategories = $db->select(
                    "SELECT * FROM categories WHERE user_id = ? ORDER BY name ASC",
                    [$_SESSION['user_id']]
                );
            }
        }
    } elseif (isset($_POST['delete_category']) && isset($_POST['category_id'])) {
        $categoryId = (int)$_POST['category_id'];
        // Check if category has links
        $linksInCategory = $db->select("SELECT COUNT(*) as count FROM links WHERE user_id = ? AND category_id = ?", [$_SESSION['user_id'], $categoryId]);
        if ($linksInCategory[0]['count'] > 0) {
            $error = "Cannot delete category that contains links. Please move or delete the links first.";
        } else {
            $db->execute("DELETE FROM categories WHERE id = ? AND user_id = ?", [$categoryId, $_SESSION['user_id']]);
            $success = "Category deleted successfully!";
            // Refresh categories
            $userCategories = $db->select(
                "SELECT * FROM categories WHERE user_id = ? ORDER BY name ASC",
                [$_SESSION['user_id']]
            );
        }
        // Only allow domain verification for testing user
        if ($user['login'] === 'gfaundead') {
            // Generate verification token
            $verificationToken = bin2hex(random_bytes(16));
            $db->execute(
                "UPDATE users SET domain_verification_token = ? WHERE id = ?",
                [$verificationToken, $_SESSION['user_id']]
            );
            // Get user's custom domain
            $userData = $db->select("SELECT custom_domain FROM users WHERE id = ?", [$_SESSION['user_id']]);
            if ($userData && $userData[0]['custom_domain']) {
                $success = "Verification instructions sent! Add this TXT record to your DNS: " . $verificationToken;
            }
        } else {
            $error = "Custom domains are currently in development.";
        }
    } elseif (isset($_POST['admin_update_brandfetch']) && $user['login'] === 'gfaundead') {
        $bfDomain  = trim($_POST['bf_domain'] ?? '');
        $bfIconUrl = trim($_POST['bf_icon_url'] ?? '');
        if (!empty($bfDomain)) {
            $bfIconUrl = $bfIconUrl === '' ? null : $bfIconUrl;
            $existing  = $db->select("SELECT id FROM brandfetch_cache WHERE domain = ?", [$bfDomain]);
            if ($existing) {
                $db->execute(
                    "UPDATE brandfetch_cache SET icon_url = ?, fetched_at = NOW() WHERE domain = ?",
                    [$bfIconUrl, $bfDomain]
                );
            } else {
                $db->execute(
                    "INSERT INTO brandfetch_cache (domain, icon_url) VALUES (?, ?)",
                    [$bfDomain, $bfIconUrl]
                );
            }
            $success = "Brandfetch cache updated for: " . htmlspecialchars($bfDomain);
        }
    } elseif (isset($_POST['admin_delete_brandfetch']) && $user['login'] === 'gfaundead') {
        $bfDomain = trim($_POST['bf_domain'] ?? '');
        if (!empty($bfDomain)) {
            $db->execute("DELETE FROM brandfetch_cache WHERE domain = ?", [$bfDomain]);
            $success = "Brandfetch cache entry deleted for: " . htmlspecialchars($bfDomain);
        }
    }
}
// Load brandfetch cache entries for admin panel
$brandfetchEntries = [];
if ($user['login'] === 'gfaundead') {
    $brandfetchEntries = $db->select(
        "SELECT domain, icon_url, fetched_at FROM brandfetch_cache ORDER BY fetched_at DESC"
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - YourLinks.click</title>
    <link rel="icon" type="image/png" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
    <link rel="icon" type="image/png" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <!-- Toastify CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.css">
    <!-- Site CSS -->
    <link rel="stylesheet" href="/css/site.css?v=<?php echo filemtime(__DIR__ . '/css/site.css'); ?>">
</head>
<body>
    <!-- Cookie Notice -->
    <div id="cookie-notice" class="yl-cookie-bar" style="display:none;">
        <div class="yl-cookie-bar-text">
            <i class="fas fa-cookie-bite"></i>
            <strong>Cookie Notice:</strong> We use cookies to remember your menu preferences. Your collapse/expand settings for sections will be saved automatically.
        </div>
        <button id="cookie-notice-close" class="yl-cookie-bar-close" aria-label="Close notice">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <!-- Top Navigation -->
    <nav class="yl-topbar">
        <a href="/" class="yl-topbar-brand">
            <i class="fas fa-link"></i>
            YourLinks.click
        </a>
        <div class="yl-topbar-user">
            <img src="<?php echo htmlspecialchars($user['profile_image_url']); ?>" alt="Avatar" class="yl-topbar-avatar">
            <span class="yl-topbar-username"><?php echo htmlspecialchars($user['display_name']); ?></span>
        </div>
        <div class="yl-topbar-actions">
            <a href="/services/twitch.php?logout=true" class="sp-btn sp-btn-secondary sp-btn-sm">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="yl-main">

        <!-- User Profile Card -->
        <div class="sp-card" style="margin-bottom: 1.5rem;">
            <div class="sp-card-body yl-user-card">
                <img src="<?php echo htmlspecialchars($user['profile_image_url']); ?>" alt="Profile" class="yl-user-avatar-lg">
                <div class="yl-user-info">
                    <p class="yl-user-display">
                        <i class="fas fa-user" style="color: var(--accent-hover);"></i>
                        Welcome back, <?php echo htmlspecialchars($user['display_name']); ?>!
                    </p>
                    <p class="yl-user-login">
                        <i class="fab fa-twitch" style="color: var(--accent-hover);"></i>
                        @<?php echo htmlspecialchars($user['login']); ?>
                    </p>
                    <p class="yl-user-desc">Manage your links and view analytics from your dashboard.</p>
                </div>
            </div>
        </div>

        <!-- Public Profile Page Section -->
        <details class="sp-card yl-detail-section" open data-section="profile-page">
            <summary class="sp-card-header yl-section-toggle">
                <span class="sp-card-title">
                    <i class="fas fa-id-card" style="color: var(--accent-hover);"></i> Public Profile Page
                </span>
                <i class="fas fa-chevron-down yl-section-toggle-icon"></i>
            </summary>
            <div class="sp-card-body">
                <p class="sp-help" style="margin-bottom:1.25rem;">
                    This is your public Linktree-style page — people visiting
                    <strong><?php echo htmlspecialchars($user['login']); ?>.yourlinks.click</strong>
                    will see these links.
                    <a href="https://<?php echo htmlspecialchars($user['login']); ?>.yourlinks.click" target="_blank" class="sp-btn sp-btn-secondary sp-btn-sm" style="margin-left:0.5rem;">
                        <i class="fas fa-external-link-alt"></i> Preview
                    </a>
                </p>

                <!-- Profile appearance settings -->
                <div class="sp-card" style="margin-bottom:1.5rem;">
                    <div class="sp-card-header">
                        <span class="sp-card-title"><i class="fas fa-palette"></i> Appearance</span>
                    </div>
                    <div class="sp-card-body">
                        <form method="POST" action="">
                            <div class="yl-form-row">
                                <div class="sp-form-group">
                                    <label class="sp-label" for="profile_page_title">Display Name / Page Title</label>
                                    <input class="sp-input" type="text" id="profile_page_title" name="profile_page_title"
                                           value="<?php echo htmlspecialchars($profileSettings['page_title'] ?? ''); ?>"
                                           placeholder="<?php echo htmlspecialchars($user['display_name']); ?> (default)">
                                    <span class="sp-help">Leave blank to use your Twitch display name</span>
                                </div>
                                <div class="sp-form-group">
                                    <label class="sp-label" for="profile_accent_color">Accent Colour</label>
                                    <input class="sp-input" type="color" id="profile_accent_color" name="profile_accent_color"
                                           value="<?php echo htmlspecialchars($profileSettings['accent_color'] ?? '#7c5cbf'); ?>"
                                           style="height:2.5rem;padding:0.3rem;cursor:pointer;">
                                </div>
                            </div>
                            <div class="sp-form-group">
                                <label class="sp-label" for="profile_bio">Bio</label>
                                <textarea class="sp-input" id="profile_bio" name="profile_bio"
                                          rows="3" maxlength="500"
                                          placeholder="A short bio shown on your profile page (max 500 chars)"
                                          style="resize:vertical;"><?php echo htmlspecialchars($profileSettings['bio'] ?? ''); ?></textarea>
                            </div>
                            <div class="sp-form-group">
                                <label class="sp-label" style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                                    <input type="checkbox" name="profile_show_pic" id="profile_show_pic"
                                           <?php echo (!isset($profileSettings['show_profile_pic']) || $profileSettings['show_profile_pic']) ? 'checked' : ''; ?>>
                                    Show profile picture
                                </label>
                            </div>
                            <div class="sp-form-group">
                                <label class="sp-label">Default page behaviour</label>
                                <div style="display:flex;flex-direction:column;gap:0.5rem;margin-top:0.25rem;">
                                    <label style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;font-weight:400;">
                                        <input type="radio" name="home_mode" value="linktree"
                                               <?php echo (($profileSettings['home_mode'] ?? 'linktree') === 'linktree') ? 'checked' : ''; ?>>
                                        Show my Linktree profile page
                                    </label>
                                    <label style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;font-weight:400;">
                                        <input type="radio" name="home_mode" value="twitch_redirect"
                                               <?php echo (($profileSettings['home_mode'] ?? '') === 'twitch_redirect') ? 'checked' : ''; ?>>
                                        Redirect visitors straight to my Twitch channel
                                    </label>
                                </div>
                                <span class="sp-help">Controls what happens when someone visits <strong><?php echo htmlspecialchars($user['login']); ?>.yourlinks.click</strong> with no link path.</span>
                            </div>
                            <button type="submit" name="save_profile_settings" class="sp-btn sp-btn-primary">
                                <i class="fas fa-save"></i> Save Appearance
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Add a new profile link -->
                <div class="sp-card" style="margin-bottom:1.5rem;">
                    <div class="sp-card-header">
                        <span class="sp-card-title"><i class="fas fa-plus-circle"></i> Add Profile Link</span>
                    </div>
                    <div class="sp-card-body">
                        <form method="POST" action="">
                            <div class="yl-form-row">
                                <div class="sp-form-group">
                                    <label class="sp-label" for="pl_platform">Platform</label>
                                    <select class="sp-input" id="pl_platform" name="pl_platform">
                                        <?php foreach ($platformDefs as $key => $pdef): ?>
                                        <option value="<?php echo htmlspecialchars($key); ?>">
                                            <?php echo htmlspecialchars($pdef['label']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="sp-form-group">
                                    <label class="sp-label" for="pl_title">Button Label</label>
                                    <input class="sp-input" type="text" id="pl_title" name="pl_title"
                                           required placeholder="e.g., My YouTube Channel">
                                </div>
                            </div>
                            <div class="sp-form-group">
                                <label class="sp-label" for="pl_url">URL</label>
                                <div class="sp-input-wrap">
                                    <span class="sp-input-icon"><i class="fas fa-globe"></i></span>
                                    <input class="sp-input" type="url" id="pl_url" name="pl_url"
                                           required placeholder="https://...">
                                </div>
                            </div>
                            <div class="sp-form-group">
                                <label class="sp-label" style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                                    <input type="checkbox" name="pl_active" id="pl_active" checked>
                                    Show on profile page
                                </label>
                            </div>
                            <button type="submit" name="add_profile_link" class="sp-btn sp-btn-primary">
                                <i class="fas fa-plus"></i> Add Link
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Existing profile links -->
                <?php if (!empty($profileLinksList)): ?>
                <div class="sp-card">
                    <div class="sp-card-header">
                        <span class="sp-card-title"><i class="fas fa-list"></i> Your Profile Links</span>
                    </div>
                    <div class="sp-card-body" style="padding:0;">
                        <div class="yl-profile-links-list">
                            <?php foreach ($profileLinksList as $idx => $pl):
                                $pInfo    = $platformDefs[$pl['platform']] ?? $platformDefs['custom'];
                                $isFirst  = $idx === 0;
                                $isLast   = $idx === count($profileLinksList) - 1;
                                $brandImg = brandfetch_get_icon($pl['platform'], $pl['url'], $db);
                            ?>
                            <div class="yl-profile-link-row <?php echo $pl['is_active'] ? '' : 'yl-profile-link-inactive'; ?>">
                                <span class="yl-profile-link-icon" style="color:<?php echo htmlspecialchars($pInfo['color']); ?>;">
                                    <?php if ($brandImg): ?>
                                    <img src="<?php echo htmlspecialchars($brandImg); ?>" alt="" class="yl-brand-icon">
                                    <?php else: ?>
                                    <i class="<?php echo htmlspecialchars($pInfo['icon']); ?>"></i>
                                    <?php endif; ?>
                                </span>
                                <div class="yl-profile-link-info">
                                    <span class="yl-profile-link-title"><?php echo htmlspecialchars($pl['title']); ?></span>
                                    <span class="yl-profile-link-url"><?php echo htmlspecialchars($pl['url']); ?></span>
                                </div>
                                <?php if (!$pl['is_active']): ?>
                                <span class="sp-badge sp-badge-grey">Hidden</span>
                                <?php endif; ?>
                                <!-- Reorder buttons -->
                                <div class="yl-profile-link-actions">
                                    <?php if (!$isFirst): ?>
                                    <form method="POST" action="" style="display:inline;">
                                        <input type="hidden" name="plink_id" value="<?php echo $pl['id']; ?>">
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" name="move_profile_link" class="sp-btn sp-btn-secondary sp-btn-sm" title="Move up">
                                            <i class="fas fa-arrow-up"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (!$isLast): ?>
                                    <form method="POST" action="" style="display:inline;">
                                        <input type="hidden" name="plink_id" value="<?php echo $pl['id']; ?>">
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" name="move_profile_link" class="sp-btn sp-btn-secondary sp-btn-sm" title="Move down">
                                            <i class="fas fa-arrow-down"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <!-- Edit button triggers inline form -->
                                    <button type="button" class="sp-btn sp-btn-info sp-btn-sm yl-plink-edit-btn"
                                            data-id="<?php echo $pl['id']; ?>"
                                            data-platform="<?php echo htmlspecialchars($pl['platform']); ?>"
                                            data-title="<?php echo htmlspecialchars($pl['title']); ?>"
                                            data-url="<?php echo htmlspecialchars($pl['url']); ?>"
                                            data-active="<?php echo $pl['is_active'] ? '1' : '0'; ?>">
                                        <i class="fas fa-pencil-alt"></i>
                                    </button>
                                    <!-- Delete -->
                                    <form method="POST" action="" style="display:inline;" class="yl-plink-delete-form">
                                        <input type="hidden" name="plink_id" value="<?php echo $pl['id']; ?>">
                                        <button type="submit" name="delete_profile_link" class="sp-btn sp-btn-danger sp-btn-sm"
                                                data-name="<?php echo htmlspecialchars($pl['title']); ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="sp-alert sp-alert-info">
                    <i class="fas fa-info-circle"></i> No profile links yet — add your first link above.
                </div>
                <?php endif; ?>
            </div>
        </details>

        <!-- Edit Profile Link Modal (hidden, shown via JS) -->
        <div id="plink-edit-modal" class="yl-modal" style="display:none;">
            <div class="yl-modal-box">
                <div class="yl-modal-header">
                    <span><i class="fas fa-pencil-alt"></i> Edit Profile Link</span>
                    <button type="button" id="plink-edit-modal-close" class="yl-modal-close">&times;</button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="plink_id" id="edit_plink_id">
                    <div class="sp-form-group">
                        <label class="sp-label">Platform</label>
                        <select class="sp-input" name="pl_platform" id="edit_pl_platform">
                            <?php foreach ($platformDefs as $key => $pdef): ?>
                            <option value="<?php echo htmlspecialchars($key); ?>">
                                <?php echo htmlspecialchars($pdef['label']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">Button Label</label>
                        <input class="sp-input" type="text" name="pl_title" id="edit_pl_title" required>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">URL</label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-globe"></i></span>
                            <input class="sp-input" type="url" name="pl_url" id="edit_pl_url" required>
                        </div>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                            <input type="checkbox" name="pl_active" id="edit_pl_active"> Show on profile page
                        </label>
                    </div>
                    <div class="sp-btn-group">
                        <button type="submit" name="edit_profile_link" class="sp-btn sp-btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <button type="button" id="plink-edit-modal-cancel" class="sp-btn sp-btn-secondary">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Custom Domain Section -->
        <?php if ($user['login'] === 'gfaundead'): ?>
        <details class="sp-card yl-detail-section" open data-section="custom-domain">
            <summary class="sp-card-header yl-section-toggle">
                <span class="sp-card-title">
                    <i class="fas fa-globe" style="color: var(--accent-hover);"></i> Custom Domain
                </span>
                <i class="fas fa-chevron-down yl-section-toggle-icon"></i>
            </summary>
            <div class="sp-card-body">
                <p class="sp-help" style="margin-bottom: 1.25rem;">Use your own domain instead of the subdomain format</p>
                <?php
                $userDomainInfo = $db->select("SELECT custom_domain, domain_verified, domain_verification_token FROM users WHERE id = ?", [$_SESSION['user_id']]);
                $customDomain = $userDomainInfo[0]['custom_domain'] ?? '';
                $domainVerified = $userDomainInfo[0]['domain_verified'] ?? false;
                $verificationToken = $userDomainInfo[0]['domain_verification_token'] ?? '';
                ?>
                <form method="POST" action="">
                    <div class="sp-form-group">
                        <label class="sp-label" for="custom_domain">
                            <i class="fas fa-globe"></i> Your Custom Domain
                        </label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-globe"></i></span>
                            <input class="sp-input" type="text" id="custom_domain" name="custom_domain"
                                   value="<?php echo htmlspecialchars($customDomain); ?>"
                                   placeholder="e.g., mydomain.com">
                        </div>
                        <?php if ($customDomain && $domainVerified): ?>
                            <span class="sp-help" style="color: var(--green);"><i class="fas fa-check-circle"></i> Domain verified! Your links will work at <?php echo htmlspecialchars($customDomain); ?>/linkname</span>
                        <?php elseif ($customDomain && !$domainVerified): ?>
                            <span class="sp-help" style="color: var(--amber);"><i class="fas fa-exclamation-triangle"></i> Domain not verified yet. Please add the DNS record below.</span>
                        <?php else: ?>
                            <span class="sp-help">Enter your domain without 'www' or 'http'. Example: mydomain.com</span>
                        <?php endif; ?>
                    </div>
                    <div class="sp-btn-group">
                        <button type="submit" name="update_custom_domain" class="sp-btn sp-btn-primary">
                            <i class="fas fa-save"></i>
                            <?php echo $customDomain ? 'Update Domain' : 'Add Custom Domain'; ?>
                        </button>
                        <?php if ($customDomain && !$domainVerified): ?>
                        <button type="button" class="sp-btn sp-btn-info" id="verify-domain-btn"
                                data-domain="<?php echo htmlspecialchars($customDomain); ?>"
                                data-token="<?php echo htmlspecialchars($verificationToken); ?>">
                            <i class="fas fa-shield-alt"></i> Verify Domain
                        </button>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($customDomain && !$domainVerified && $verificationToken): ?>
                <div class="sp-alert sp-alert-info" style="margin-top: 1.25rem;">
                    <p style="font-weight: 700; margin-bottom: 0.75rem;"><i class="fas fa-dns"></i> DNS Verification Required</p>
                    <p style="margin-bottom: 0.75rem;">To verify ownership of <strong><?php echo htmlspecialchars($customDomain); ?></strong>, add this TXT record to your DNS settings:</p>
                    <div class="sp-form-group">
                        <label class="sp-label">Record Type</label>
                        <input class="sp-input" type="text" value="TXT" readonly>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">Name / Host</label>
                        <input class="sp-input" type="text" value="_yourlinks_verification.<?php echo htmlspecialchars($customDomain); ?>" readonly>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">Value</label>
                        <input class="sp-input" type="text" value="<?php echo htmlspecialchars($verificationToken); ?>" readonly>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label">TTL</label>
                        <input class="sp-input" type="text" value="300" readonly>
                    </div>
                    <p style="font-weight: 700; margin-bottom: 0.5rem;">Domain Setup Instructions:</p>
                    <p style="margin-bottom: 0.5rem;"><strong>Option 1 â€” Addon Domain (Easiest):</strong><br>
                    1. Login to your domain's cPanel<br>
                    2. Go to <code>Domains â†’ Addon Domains</code><br>
                    3. Add <strong><?php echo htmlspecialchars($customDomain); ?></strong> as addon domain<br>
                    4. Point DNS A record to: <code>110.232.143.81</code><br>
                    5. Return here and click "Verify Domain"</p>
                    <p style="margin-bottom: 0.5rem;"><strong>Option 2 â€” DNS Pointing:</strong><br>
                    1. Go to your domain registrar's DNS settings<br>
                    2. Add A record: <code>@</code> â†’ <code>110.232.143.81</code><br>
                    3. Add the TXT record above<br>
                    4. Wait 5â€“30 minutes for DNS propagation<br>
                    5. Return here and click "Verify Domain"</p>
                    <p><strong>Note:</strong> SSL certificates are automatically managed for verified custom domains.</p>
                    <p style="margin-top: 0.75rem; font-weight: 700;">Example Links:</p>
                    <ul style="margin: 0.4rem 0 0 1.25rem; list-style: disc; color: var(--text-secondary);">
                        <li><code><?php echo htmlspecialchars($customDomain); ?>/youtube</code> â†’ Your YouTube channel</li>
                        <li><code><?php echo htmlspecialchars($customDomain); ?>/twitter</code> â†’ Your Twitter profile</li>
                        <li><code><?php echo htmlspecialchars($customDomain); ?>/discord</code> â†’ Your Discord server</li>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if ($customDomain && $domainVerified): ?>
                <div class="sp-alert sp-alert-success" style="margin-top: 1.25rem;">
                    <p style="font-weight: 700;"><i class="fas fa-check-circle"></i> Custom Domain Active!</p>
                    <p>Your links are now available at: <strong><?php echo htmlspecialchars($customDomain); ?>/linkname</strong></p>
                    <p style="margin-top: 0.5rem;">You can still use the subdomain format if needed.</p>
                </div>
                <?php endif; ?>
            </div>
        </details>

        <?php else: ?>
        <!-- Custom Domain: Coming Soon -->
        <details class="sp-card yl-detail-section" open data-section="custom-domain-coming-soon">
            <summary class="sp-card-header yl-section-toggle">
                <span class="sp-card-title">
                    <i class="fas fa-globe" style="color: var(--accent-hover);"></i> Custom Domain
                </span>
                <i class="fas fa-chevron-down yl-section-toggle-icon"></i>
            </summary>
            <div class="sp-card-body">
                <div class="sp-alert sp-alert-info">
                    <p style="font-weight: 700; margin-bottom: 0.5rem;"><i class="fas fa-clock"></i> Feature Coming Soon</p>
                    <p>Custom domains are currently in development and testing. This feature will allow you to use your own domain (like yourdomain.com/link) instead of the subdomain format.</p>
                    <p style="font-weight: 700; margin: 0.75rem 0 0.35rem;"><strong>Expected features:</strong></p>
                    <ul style="margin-left: 1.25rem; list-style: disc; color: var(--text-secondary);">
                        <li>Use your own domain for links</li>
                        <li>Automatic SSL certificate management</li>
                        <li>DNS verification for security</li>
                        <li>Multiple domains per account</li>
                    </ul>
                    <p style="margin-top: 0.75rem; color: var(--text-muted);"><em>This feature is being tested internally and will be available to all users soon!</em></p>
                </div>
            </div>
        </details>
        <?php endif; ?>

        <!-- Category Management Section -->
        <details class="sp-card yl-detail-section" open data-section="category-management">
            <summary class="sp-card-header yl-section-toggle">
                <span class="sp-card-title">
                    <i class="fas fa-tags" style="color: var(--accent-hover);"></i> Link Categories
                </span>
                <i class="fas fa-chevron-down yl-section-toggle-icon"></i>
            </summary>
            <div class="sp-card-body">
                <p class="sp-help" style="margin-bottom: 1.25rem;">Organize your links into categories for better management</p>

                <!-- Create New Category -->
                <div class="sp-card" style="margin-bottom: 1.5rem;">
                    <div class="sp-card-header">
                        <span class="sp-card-title"><i class="fas fa-plus-circle"></i> Create New Category</span>
                    </div>
                    <div class="sp-card-body">
                        <form method="POST" action="">
                            <div class="yl-form-row">
                                <div class="sp-form-group" style="margin-bottom: 0;">
                                    <label class="sp-label" for="cat_name">Category Name</label>
                                    <div class="sp-input-wrap">
                                        <span class="sp-input-icon"><i class="fas fa-tag"></i></span>
                                        <input class="sp-input" type="text" id="cat_name" name="category_name" required placeholder="e.g., Social Media">
                                    </div>
                                </div>
                                <div class="sp-form-group" style="margin-bottom: 0;">
                                    <label class="sp-label" for="cat_desc">Description (Optional)</label>
                                    <input class="sp-input" type="text" id="cat_desc" name="category_description" placeholder="Brief description">
                                </div>
                                <div class="sp-form-group" style="margin-bottom: 0;">
                                    <label class="sp-label" for="cat_color">Color</label>
                                    <input class="sp-input" type="color" id="cat_color" name="category_color" value="#7c5cbf" style="padding: 0.35rem; height: 2.5rem; cursor: pointer;">
                                </div>
                                <div class="sp-form-group" style="margin-bottom: 0;">
                                    <label class="sp-label">&nbsp;</label>
                                    <button type="submit" name="create_category" class="sp-btn sp-btn-primary" style="width: 100%;">
                                        <i class="fas fa-plus"></i> Create
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Existing Categories -->
                <?php if (!empty($userCategories)): ?>
                <div class="sp-card">
                    <div class="sp-card-header">
                        <span class="sp-card-title"><i class="fas fa-list"></i> Your Categories</span>
                    </div>
                    <div class="sp-card-body">
                        <div class="yl-category-grid">
                            <?php foreach ($userCategories as $category): ?>
                            <?php $linkCount = $db->select("SELECT COUNT(*) as count FROM links WHERE user_id = ? AND category_id = ?", [$_SESSION['user_id'], $category['id']]); ?>
                            <div class="yl-category-card" style="border-left-color: <?php echo htmlspecialchars($category['color']); ?>;">
                                <span class="yl-category-icon" style="color: <?php echo htmlspecialchars($category['color']); ?>;">
                                    <i class="<?php echo htmlspecialchars($category['icon']); ?>"></i>
                                </span>
                                <div class="yl-category-info">
                                    <p class="yl-category-name"><?php echo htmlspecialchars($category['name']); ?></p>
                                    <p class="yl-category-count"><?php echo $linkCount[0]['count']; ?> link<?php echo $linkCount[0]['count'] !== 1 ? 's' : ''; ?></p>
                                </div>
                                <button type="button" class="sp-btn sp-btn-danger sp-btn-sm delete-category-btn"
                                        data-category-id="<?php echo $category['id']; ?>"
                                        data-category-name="<?php echo htmlspecialchars($category['name']); ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <form method="POST" action="" style="display:none;" id="delete-category-form-<?php echo $category['id']; ?>">
                                    <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                    <input type="hidden" name="delete_category" value="1">
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </details>

        <!-- Stats -->
        <div class="sp-stat-row" style="margin-bottom: 1.5rem;">
            <div class="sp-stat">
                <span class="sp-stat-label"><i class="fas fa-link"></i> Total Links</span>
                <span class="sp-stat-value">
                    <?php
                    $linksCount = $db->select("SELECT COUNT(*) as count FROM links WHERE user_id = ?", [$_SESSION['user_id']]);
                    echo $linksCount[0]['count'] ?? 0;
                    ?>
                </span>
            </div>
            <div class="sp-stat">
                <span class="sp-stat-label"><i class="fas fa-mouse-pointer"></i> Total Clicks</span>
                <span class="sp-stat-value">
                    <?php
                    $clicksCount = $db->select("SELECT SUM(clicks) as total FROM links WHERE user_id = ?", [$_SESSION['user_id']]);
                    echo $clicksCount[0]['total'] ?? 0;
                    ?>
                </span>
            </div>
            <div class="sp-stat online">
                <span class="sp-stat-label"><i class="fas fa-check-circle"></i> Active Links</span>
                <span class="sp-stat-value">
                    <?php
                    $activeCount = $db->select("SELECT COUNT(*) as count FROM links WHERE user_id = ? AND is_active = TRUE", [$_SESSION['user_id']]);
                    echo $activeCount[0]['count'] ?? 0;
                    ?>
                </span>
            </div>
        </div>

        <!-- Create New Link -->
        <div class="sp-card" style="margin-bottom: 1.5rem;">
            <div class="sp-card-header">
                <span class="sp-card-title">
                    <i class="fas fa-plus-circle" style="color: var(--accent-hover);"></i> Create New Link
                </span>
            </div>
            <div class="sp-card-body">
                <form method="POST" action="">
                    <div class="sp-form-group">
                        <label class="sp-label" for="link_name"><i class="fas fa-tag"></i> Link Name</label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-link"></i></span>
                            <input class="sp-input" type="text" id="link_name" name="link_name" required placeholder="e.g., youtube, twitter, discord">
                        </div>
                        <span class="sp-help">
                            <?php if ($user['login'] === 'gfaundead' && $customDomain && $domainVerified): ?>
                                Your link will be available at:<br>
                                <strong><?php echo htmlspecialchars($user['login']); ?>.yourlinks.click/<span id="preview-name">linkname</span></strong><br>
                                <strong><?php echo htmlspecialchars($customDomain); ?>/<span id="preview-name-custom">linkname</span></strong>
                            <?php else: ?>
                                Your link will be: <strong><?php echo htmlspecialchars($user['login']); ?>.yourlinks.click/<span id="preview-name">linkname</span></strong>
                                <?php if ($user['login'] === 'gfaundead' && $customDomain && !$domainVerified): ?>
                                    <br><em style="color: var(--amber);">Custom domain available after verification</em>
                                <?php endif; ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="original_url"><i class="fas fa-external-link-alt"></i> Destination URL</label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-globe"></i></span>
                            <input class="sp-input" type="url" id="original_url" name="original_url" required placeholder="https://example.com/your-profile">
                        </div>
                        <span class="sp-help">The URL where visitors will be redirected</span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="title"><i class="fas fa-heading"></i> Title (Optional)</label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-i-cursor"></i></span>
                            <input class="sp-input" type="text" id="title" name="title" placeholder="Display name for this link">
                        </div>
                        <span class="sp-help">A friendly name to help you remember this link</span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="category_id"><i class="fas fa-tag"></i> Category</label>
                        <select class="sp-select" id="category_id" name="category_id">
                            <option value="">No Category</option>
                            <?php foreach ($userCategories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"
                                        data-color="<?php echo htmlspecialchars($category['color']); ?>"
                                        data-icon="<?php echo htmlspecialchars($category['icon']); ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="sp-help">Organize your links into categories for better management</span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="expires_at"><i class="fas fa-clock"></i> Expiration Date (Optional)</label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-calendar-alt"></i></span>
                            <input class="sp-input" type="datetime-local" id="expires_at" name="expires_at" min="<?php echo date('Y-m-d\TH:i'); ?>">
                        </div>
                        <span class="sp-help">Set when this link should expire. Leave empty for no expiration.</span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="expiration_behavior"><i class="fas fa-exclamation-triangle"></i> When Link Expires</label>
                        <select class="sp-select" id="expiration_behavior" name="expiration_behavior">
                            <option value="inactive">Make link inactive (404 error)</option>
                            <option value="redirect">Redirect to custom URL</option>
                            <option value="custom_page">Show custom expired page</option>
                        </select>
                        <span class="sp-help">Choose what happens when the link expires</span>
                    </div>
                    <div class="sp-form-group" id="expired_redirect_field" style="display:none;">
                        <label class="sp-label" for="expired_redirect_url"><i class="fas fa-external-link-alt"></i> Expired Redirect URL</label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-globe"></i></span>
                            <input class="sp-input" type="url" id="expired_redirect_url" name="expired_redirect_url" placeholder="https://example.com/expired">
                        </div>
                        <span class="sp-help">URL to redirect visitors when this link expires</span>
                    </div>
                    <div class="sp-form-group" id="expired_custom_page_field" style="display:none;">
                        <label class="sp-label" for="expired_page_title"><i class="fas fa-heading"></i> Custom Page Title</label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-i-cursor"></i></span>
                            <input class="sp-input" type="text" id="expired_page_title" name="expired_page_title" placeholder="Link Expired" value="Link Expired">
                        </div>
                        <span class="sp-help">Title shown on the custom expired page</span>
                    </div>
                    <div class="sp-form-group" id="expired_custom_message_field" style="display:none;">
                        <label class="sp-label" for="expired_page_message"><i class="fas fa-comment"></i> Custom Message</label>
                        <textarea class="sp-textarea" id="expired_page_message" name="expired_page_message" placeholder="This link has expired..." rows="3">This link has expired and is no longer available.</textarea>
                        <span class="sp-help">Message shown on the custom expired page</span>
                    </div>
                    <button type="submit" name="create_link" class="sp-btn sp-btn-primary">
                        <i class="fas fa-plus"></i> Create Link
                    </button>
                </form>
            </div>
        </div>

        <!-- Links Table -->
        <div class="sp-card">
            <div class="sp-card-header">
                <span class="sp-card-title">
                    <i class="fas fa-list" style="color: var(--accent-hover);"></i> Your Links
                </span>
                <?php if (count($userLinks) > 10): ?>
                <div class="sp-input-wrap" style="width: 220px;">
                    <span class="sp-input-icon"><i class="fas fa-search"></i></span>
                    <input class="sp-input" type="text" id="link-search" placeholder="Search links...">
                </div>
                <?php endif; ?>
            </div>
            <?php
            if (empty($userLinks)) {
                echo '<div class="sp-card-body"><div class="sp-alert sp-alert-info"><i class="fas fa-info-circle"></i> You haven\'t created any links yet. Create your first link above!</div></div>';
            } else {
                echo '<div class="sp-table-wrap" id="links-table-container">';
                echo '<table class="sp-table" id="links-table">';
                echo '<thead>';
                echo '<tr>';
                echo '<th><i class="fas fa-tag"></i> Link Name</th>';
                echo '<th><i class="fas fa-external-link-alt"></i> Destination</th>';
                echo '<th><i class="fas fa-heading"></i> Title</th>';
                echo '<th><i class="fas fa-folder"></i> Category</th>';
                echo '<th><i class="fas fa-clock"></i> Expires</th>';
                echo '<th><i class="fas fa-mouse-pointer"></i> Clicks</th>';
                echo '<th><i class="fas fa-toggle-on"></i> Status</th>';
                echo '<th><i class="fas fa-cogs"></i> Actions</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';
                foreach ($userLinks as $link) {
                    $fullUrl = 'https://' . $user['login'] . '.yourlinks.click/' . $link['link_name'];
                    $status = $link['is_active'] ? 'Active' : 'Inactive';
                    echo '<tr class="link-row" data-link-name="' . htmlspecialchars(strtolower($link['link_name'])) . '" data-title="' . htmlspecialchars(strtolower($link['title'] ?? '')) . '" data-url="' . htmlspecialchars(strtolower($link['original_url'])) . '" data-category="' . htmlspecialchars(strtolower($link['category_name'] ?? '')) . '">';
                    echo '<td><a href="' . htmlspecialchars($fullUrl) . '" target="_blank" class="link-copy" data-url="' . htmlspecialchars($fullUrl) . '" style="color: var(--accent-hover);"><i class="fas fa-external-link-alt"></i> ' . htmlspecialchars($link['link_name']) . '</a></td>';
                    echo '<td><a href="' . htmlspecialchars($link['original_url']) . '" target="_blank" style="color: var(--text-muted); font-size: 0.8rem;">' . htmlspecialchars($link['original_url']) . '</a></td>';
                    echo '<td>' . htmlspecialchars($link['title'] ?? '') . '</td>';
                    echo '<td>';
                    if (!empty($link['category_name'])) {
                        echo '<span class="sp-badge" style="background-color: ' . htmlspecialchars($link['category_color']) . '22; color: ' . htmlspecialchars($link['category_color']) . '; border: 1px solid ' . htmlspecialchars($link['category_color']) . '66;">';
                        echo '<i class="' . htmlspecialchars($link['category_icon']) . '"></i> ';
                        echo htmlspecialchars($link['category_name']);
                        echo '</span>';
                    } else {
                        echo '<span class="sp-badge sp-badge-grey"><i class="fas fa-question-circle"></i> None</span>';
                    }
                    echo '</td>';
                    echo '<td>';
                    if (!empty($link['expires_at'])) {
                        $expiresAt = strtotime($link['expires_at']);
                        $now = time();
                        $daysUntilExpiry = floor(($expiresAt - $now) / (60 * 60 * 24));
                        if ($link['expiration_status'] === 'expired') {
                            echo '<span class="sp-badge sp-badge-red"><i class="fas fa-exclamation-triangle"></i> Expired</span>';
                        } elseif ($link['expiration_status'] === 'expiring_soon') {
                            echo '<span class="sp-badge sp-badge-amber"><i class="fas fa-clock"></i> ' . $daysUntilExpiry . 'd left</span>';
                        } else {
                            echo '<span class="sp-badge sp-badge-blue"><i class="fas fa-calendar-alt"></i> ' . date('M j, Y', $expiresAt) . '</span>';
                        }
                    } else {
                        echo '<span class="sp-badge sp-badge-grey"><i class="fas fa-infinity"></i> Never</span>';
                    }
                    echo '</td>';
                    echo '<td><span class="sp-badge sp-badge-blue">' . $link['clicks'] . '</span></td>';
                    echo '<td><span class="sp-badge ' . ($link['is_active'] ? 'sp-badge-green' : 'sp-badge-red') . '">' . $status . '</span></td>';
                    echo '<td>';
                    echo '<div style="display:flex; gap: 0.4rem; flex-wrap: wrap;">';
                    echo '<button type="button" class="sp-btn sp-btn-info sp-btn-sm edit-btn" data-link-id="' . $link['id'] . '" data-link-name="' . htmlspecialchars($link['link_name']) . '" data-original-url="' . htmlspecialchars($link['original_url']) . '" data-title="' . htmlspecialchars($link['title'] ?? '') . '" data-category-id="' . ($link['category_id'] ?? '') . '" data-expires-at="' . htmlspecialchars($link['expires_at'] ?? '') . '" data-expiration-behavior="' . htmlspecialchars($link['expiration_behavior'] ?? 'inactive') . '" data-expired-redirect-url="' . htmlspecialchars($link['expired_redirect_url'] ?? '') . '" data-expired-page-title="' . htmlspecialchars($link['expired_page_title'] ?? 'Link Expired') . '" data-expired-page-message="' . htmlspecialchars($link['expired_page_message'] ?? 'This link has expired and is no longer available.') . '"><i class="fas fa-edit"></i> Edit</button>';
                    if ($link['is_active']) {
                        echo '<button type="button" class="sp-btn sp-btn-warning sp-btn-sm deactivate-btn" data-link-id="' . $link['id'] . '"><i class="fas fa-pause"></i> Deactivate</button>';
                    } else {
                        echo '<button type="button" class="sp-btn sp-btn-success sp-btn-sm activate-btn" data-link-id="' . $link['id'] . '"><i class="fas fa-play"></i> Activate</button>';
                    }
                    echo '<button type="button" class="sp-btn sp-btn-danger sp-btn-sm delete-btn" data-link-id="' . $link['id'] . '"><i class="fas fa-trash"></i> Delete</button>';
                    echo '</div>';
                    echo '<form method="POST" action="" style="display:none;" id="activate-form-' . $link['id'] . '"><input type="hidden" name="link_id" value="' . $link['id'] . '"><input type="hidden" name="activate_link" value="1"></form>';
                    echo '<form method="POST" action="" style="display:none;" id="deactivate-form-' . $link['id'] . '"><input type="hidden" name="link_id" value="' . $link['id'] . '"><input type="hidden" name="deactivate_link" value="1"></form>';
                    echo '<form method="POST" action="" style="display:none;" id="delete-form-' . $link['id'] . '"><input type="hidden" name="link_id" value="' . $link['id'] . '"><input type="hidden" name="delete_link" value="1"></form>';
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</tbody>';
                echo '</table>';
                echo '</div>';
            }
            ?>
        </div>

    </div><!-- /.yl-main -->

    <?php if ($user['login'] === 'gfaundead'): ?>
    <!-- Admin: Brandfetch Cache Manager -->
    <div class="yl-main" style="margin-top: 1.5rem;">
        <details class="sp-card yl-detail-section" data-section="admin-brandfetch">
            <summary class="sp-card-header yl-section-toggle">
                <span class="sp-card-title">
                    <i class="fas fa-image" style="color: var(--accent-hover);"></i> Admin: Brandfetch Icon Cache
                </span>
                <i class="fas fa-chevron-down yl-section-toggle-icon"></i>
            </summary>
            <div class="sp-card-body">
                <p class="sp-help" style="margin-bottom: 1.25rem;">
                    Domains with a <strong>NULL</strong> icon have no Brandfetch entry — they will show the
                    <i class="fas fa-link"></i> icon. You can manually set an icon URL or clear an entry so the API retries after 30 days.
                </p>
                <!-- Add / Update entry form -->
                <div class="sp-card" style="margin-bottom: 1.5rem;">
                    <div class="sp-card-header">
                        <span class="sp-card-title"><i class="fas fa-pencil-alt"></i> Add / Update Entry</span>
                    </div>
                    <div class="sp-card-body">
                        <form method="POST" action="">
                            <div class="yl-form-row">
                                <div class="sp-form-group" style="flex:1;">
                                    <label class="sp-label">Domain</label>
                                    <div class="sp-input-wrap">
                                        <span class="sp-input-icon"><i class="fas fa-globe"></i></span>
                                        <input class="sp-input" type="text" name="bf_domain" required placeholder="e.g. fourthwall.com">
                                    </div>
                                </div>
                                <div class="sp-form-group" style="flex:2;">
                                    <label class="sp-label">Icon URL <em style="font-weight:400;">(leave blank to store NULL)</em></label>
                                    <div class="sp-input-wrap">
                                        <span class="sp-input-icon"><i class="fas fa-image"></i></span>
                                        <input class="sp-input" type="url" name="bf_icon_url" placeholder="https://...">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="admin_update_brandfetch" class="sp-btn sp-btn-primary">
                                <i class="fas fa-save"></i> Save
                            </button>
                        </form>
                    </div>
                </div>
                <!-- Cache entries table -->
                <?php if (!empty($brandfetchEntries)): ?>
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th>Icon Preview</th>
                                <th>Icon URL</th>
                                <th>Fetched At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($brandfetchEntries as $bfe): $bfId = 'bfe-' . md5($bfe['domain']); ?>
                            <tr id="row-<?php echo $bfId; ?>">
                                <td><strong><?php echo htmlspecialchars($bfe['domain']); ?></strong></td>
                                <td style="text-align:center;">
                                    <?php if ($bfe['icon_url']): ?>
                                        <img src="<?php echo htmlspecialchars($bfe['icon_url']); ?>" alt="" style="height:28px;width:28px;object-fit:contain;">
                                    <?php else: ?>
                                        <i class="fas fa-link" style="color:var(--text-muted);"></i>
                                    <?php endif; ?>
                                </td>
                                <td style="word-break:break-all;max-width:320px;font-size:0.8rem;">
                                    <?php echo $bfe['icon_url'] ? htmlspecialchars($bfe['icon_url']) : '<em style="color:var(--text-muted);">NULL</em>'; ?>
                                </td>
                                <td style="white-space:nowrap;"><?php echo htmlspecialchars($bfe['fetched_at']); ?></td>
                                <td style="white-space:nowrap;">
                                    <button type="button" class="sp-btn sp-btn-info sp-btn-sm" title="Edit icon URL"
                                            onclick="bfToggleEdit('<?php echo $bfId; ?>')">
                                        <i class="fas fa-pencil-alt"></i>
                                    </button>
                                    <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Delete cache entry for <?php echo htmlspecialchars(addslashes($bfe['domain'])); ?>?');">
                                        <input type="hidden" name="bf_domain" value="<?php echo htmlspecialchars($bfe['domain']); ?>">
                                        <button type="submit" name="admin_delete_brandfetch" class="sp-btn sp-btn-danger sp-btn-sm" title="Delete entry (API will retry after 30 days)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <tr id="edit-<?php echo $bfId; ?>" style="display:none;">
                                <td colspan="5" style="background:var(--bg-secondary,#1e1e2e);padding:0.75rem 1rem;">
                                    <form method="POST" action="" style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">
                                        <input type="hidden" name="bf_domain" value="<?php echo htmlspecialchars($bfe['domain']); ?>">
                                        <div class="sp-form-group" style="flex:1;min-width:260px;margin-bottom:0;">
                                            <label class="sp-label" style="font-size:0.8rem;">Icon URL for <strong><?php echo htmlspecialchars($bfe['domain']); ?></strong> <em style="font-weight:400;">(leave blank for NULL)</em></label>
                                            <div class="sp-input-wrap">
                                                <span class="sp-input-icon"><i class="fas fa-image"></i></span>
                                                <input class="sp-input" type="url" name="bf_icon_url"
                                                       value="<?php echo htmlspecialchars($bfe['icon_url'] ?? ''); ?>"
                                                       placeholder="https://...">
                                            </div>
                                        </div>
                                        <button type="submit" name="admin_update_brandfetch" class="sp-btn sp-btn-primary sp-btn-sm">
                                            <i class="fas fa-save"></i> Save
                                        </button>
                                        <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" onclick="bfToggleEdit('<?php echo $bfId; ?>')">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <script>
                function bfToggleEdit(id) {
                    var row = document.getElementById('edit-' + id);
                    row.style.display = row.style.display === 'none' ? '' : 'none';
                }
                </script>
                <?php else: ?>
                <div class="sp-alert sp-alert-info"><i class="fas fa-info-circle"></i> No brandfetch cache entries yet.</div>
                <?php endif; ?>
            </div>
        </details>
    </div>
    <?php endif; ?>
    <!-- Deactivate Link Modal -->
    <div class="modal" id="deactivate-link-modal">
        <div class="modal-background"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">
                    <i class="fas fa-pause" style="color: var(--amber);"></i> Deactivate Link
                </p>
                <button class="delete" aria-label="close" id="deactivate-modal-close"></button>
            </header>
            <section class="modal-card-body">
                <div class="sp-alert sp-alert-warning" style="margin-bottom: 1.25rem;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>What should happen when visitors try to access this deactivated link?</strong>
                </div>
                <form method="POST" action="" id="deactivate-link-form">
                    <input type="hidden" name="deactivate_link_id" id="deactivate_link_id">
                    <div class="sp-form-group">
                        <label class="sp-label" for="deactivate_behavior">
                            <i class="fas fa-exclamation-triangle"></i> When Link is Deactivated
                        </label>
                        <select class="sp-select" id="deactivate_behavior" name="deactivate_behavior" required>
                            <option value="inactive">Show 404 error page</option>
                            <option value="redirect">Redirect to custom URL</option>
                            <option value="custom_page">Show custom deactivated page</option>
                        </select>
                        <span class="sp-help">Choose what happens when visitors access this deactivated link</span>
                    </div>
                    <div class="sp-form-group" id="deactivate_redirect_field" style="display:none;">
                        <label class="sp-label" for="deactivate_redirect_url">
                            <i class="fas fa-external-link-alt"></i> Deactivated Redirect URL
                        </label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-globe"></i></span>
                            <input class="sp-input" type="url" id="deactivate_redirect_url" name="deactivate_redirect_url" placeholder="https://example.com/deactivated">
                        </div>
                        <span class="sp-help">URL to redirect visitors when this link is deactivated</span>
                    </div>
                    <div class="sp-form-group" id="deactivate_custom_page_field" style="display:none;">
                        <label class="sp-label" for="deactivated_page_title">
                            <i class="fas fa-heading"></i> Custom Page Title
                        </label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-i-cursor"></i></span>
                            <input class="sp-input" type="text" id="deactivated_page_title" name="deactivated_page_title" placeholder="Link Deactivated" value="Link Deactivated">
                        </div>
                        <span class="sp-help">Title shown on the custom deactivated page</span>
                    </div>
                    <div class="sp-form-group" id="deactivate_custom_message_field" style="display:none;">
                        <label class="sp-label" for="deactivated_page_message">
                            <i class="fas fa-comment"></i> Custom Message
                        </label>
                        <textarea class="sp-textarea" id="deactivated_page_message" name="deactivated_page_message" placeholder="This link has been deactivated..." rows="3">This link has been deactivated and is no longer available.</textarea>
                        <span class="sp-help">Message shown on the custom deactivated page</span>
                    </div>
                </form>
            </section>
            <footer class="modal-card-foot">
                <button class="sp-btn sp-btn-warning" type="submit" form="deactivate-link-form" name="deactivate_link">
                    <i class="fas fa-pause"></i> Deactivate Link
                </button>
                <button class="sp-btn sp-btn-secondary" id="deactivate-modal-cancel">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </footer>
        </div>
    </div>

    <!-- Edit Link Modal -->
    <div class="modal" id="edit-link-modal">
        <div class="modal-background"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title">
                    <i class="fas fa-edit" style="color: var(--accent-hover);"></i> Edit Link
                </p>
                <button class="delete" aria-label="close" id="edit-modal-close"></button>
            </header>
            <section class="modal-card-body">
                <form method="POST" action="" id="edit-link-form">
                    <input type="hidden" name="edit_link_id" id="edit_link_id">
                    <div class="sp-form-group">
                        <label class="sp-label" for="edit_link_name"><i class="fas fa-tag"></i> Link Name</label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-link"></i></span>
                            <input class="sp-input" type="text" id="edit_link_name" name="edit_link_name" required placeholder="e.g., youtube, twitter, discord">
                        </div>
                        <span class="sp-help">
                            <?php if ($user['login'] === 'gfaundead' && $customDomain && $domainVerified): ?>
                                Your link will be available at:<br>
                                <strong><?php echo htmlspecialchars($user['login']); ?>.yourlinks.click/<span id="edit-preview-name">linkname</span></strong><br>
                                <strong><?php echo htmlspecialchars($customDomain); ?>/<span id="edit-preview-name-custom">linkname</span></strong>
                            <?php else: ?>
                                Your link will be: <strong><?php echo htmlspecialchars($user['login']); ?>.yourlinks.click/<span id="edit-preview-name">linkname</span></strong>
                                <?php if ($user['login'] === 'gfaundead' && $customDomain && !$domainVerified): ?>
                                    <br><em style="color: var(--amber);">Custom domain available after verification</em>
                                <?php endif; ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="edit_original_url"><i class="fas fa-external-link-alt"></i> Destination URL</label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-globe"></i></span>
                            <input class="sp-input" type="url" id="edit_original_url" name="edit_original_url" required placeholder="https://example.com/your-profile">
                        </div>
                        <span class="sp-help">The URL where visitors will be redirected</span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="edit_title"><i class="fas fa-heading"></i> Title (Optional)</label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-i-cursor"></i></span>
                            <input class="sp-input" type="text" id="edit_title" name="edit_title" placeholder="Display name for this link">
                        </div>
                        <span class="sp-help">A friendly name to help you remember this link</span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="edit_category_id"><i class="fas fa-tag"></i> Category</label>
                        <select class="sp-select" id="edit_category_id" name="edit_category_id">
                            <option value="">No Category</option>
                            <?php foreach ($userCategories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"
                                        data-color="<?php echo htmlspecialchars($category['color']); ?>"
                                        data-icon="<?php echo htmlspecialchars($category['icon']); ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="sp-help">Organize your links into categories for better management</span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="edit_expires_at"><i class="fas fa-clock"></i> Expiration Date (Optional)</label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-calendar-alt"></i></span>
                            <input class="sp-input" type="datetime-local" id="edit_expires_at" name="edit_expires_at">
                        </div>
                        <span class="sp-help">Set when this link should expire. Leave empty for no expiration.</span>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="edit_expiration_behavior"><i class="fas fa-exclamation-triangle"></i> When Link Expires</label>
                        <select class="sp-select" id="edit_expiration_behavior" name="edit_expiration_behavior">
                            <option value="inactive">Make link inactive (404 error)</option>
                            <option value="redirect">Redirect to custom URL</option>
                            <option value="custom_page">Show custom expired page</option>
                        </select>
                        <span class="sp-help">Choose what happens when the link expires</span>
                    </div>
                    <div class="sp-form-group" id="edit_expired_redirect_field" style="display:none;">
                        <label class="sp-label" for="edit_expired_redirect_url"><i class="fas fa-external-link-alt"></i> Expired Redirect URL</label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-globe"></i></span>
                            <input class="sp-input" type="url" id="edit_expired_redirect_url" name="edit_expired_redirect_url" placeholder="https://example.com/expired">
                        </div>
                        <span class="sp-help">URL to redirect visitors when this link expires</span>
                    </div>
                    <div class="sp-form-group" id="edit_expired_custom_page_field" style="display:none;">
                        <label class="sp-label" for="edit_expired_page_title"><i class="fas fa-heading"></i> Custom Page Title</label>
                        <div class="sp-input-wrap">
                            <span class="sp-input-icon"><i class="fas fa-i-cursor"></i></span>
                            <input class="sp-input" type="text" id="edit_expired_page_title" name="edit_expired_page_title" placeholder="Link Expired">
                        </div>
                        <span class="sp-help">Title shown on the custom expired page</span>
                    </div>
                    <div class="sp-form-group" id="edit_expired_custom_message_field" style="display:none;">
                        <label class="sp-label" for="edit_expired_page_message"><i class="fas fa-comment"></i> Custom Message</label>
                        <textarea class="sp-textarea" id="edit_expired_page_message" name="edit_expired_page_message" placeholder="This link has expired..." rows="3"></textarea>
                        <span class="sp-help">Message shown on the custom expired page</span>
                    </div>
                </form>
            </section>
            <footer class="modal-card-foot">
                <button class="sp-btn sp-btn-primary" type="submit" form="edit-link-form" name="edit_link">
                    <i class="fas fa-save"></i> Save Changes
                </button>
                <button class="sp-btn sp-btn-secondary" id="edit-modal-cancel">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </footer>
        </div>
    </div>

    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Toastify JS -->
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script>
        // Cookie utility functions
        function setCookie(name, value, days = 30) {
            const expires = new Date();
            expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
            document.cookie = name + '=' + value + ';expires=' + expires.toUTCString() + ';path=/';
        }
        
        function getCookie(name) {
            const nameEQ = name + '=';
            const ca = document.cookie.split(';');
            for(let i = 0; i < ca.length; i++) {
                let c = ca[i];
                while (c.charAt(0) === ' ') c = c.substring(1, c.length);
                if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
            }
            return null;
        }
        
        function deleteCookie(name) {
            setCookie(name, '', -1);
        }

        // Show cookie notice on first visit
        function showCookieNotice() {
            const cookieNoticeShown = getCookie('cookie-notice-shown');
            const cookieNoticeEl = document.getElementById('cookie-notice');
            
            if (!cookieNoticeShown && cookieNoticeEl) {
                cookieNoticeEl.style.display = 'flex';
                setCookie('cookie-notice-shown', 'true', 365);
            }
        }

        // Initialize collapse states from cookies
        function initializeCollapseStates() {
            document.querySelectorAll('details[data-section]').forEach(details => {
                const sectionName = details.getAttribute('data-section');
                const collapsed = getCookie(sectionName + '-collapsed');
                if (collapsed === 'true') {
                    details.removeAttribute('open');
                } else if (collapsed === 'false') {
                    details.setAttribute('open', '');
                } else {
                    if (details.hasAttribute('open')) {
                        details.setAttribute('open', '');
                    } else {
                        details.removeAttribute('open');
                    }
                }
            });
        }
        
        // Save collapse state to cookie
        function saveCollapseState(sectionName, isCollapsed) {
            setCookie(sectionName + '-collapsed', isCollapsed ? 'true' : 'false', 30);
            showCollapseNotification(sectionName, isCollapsed);
        }

        // Show brief notification when section is collapsed/expanded
        function showCollapseNotification(sectionName, isCollapsed) {
            const statusText = isCollapsed ? 'Collapsed' : 'Expanded';
            const sectionLabel = sectionName.replace(/-/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            Toastify({
                text: `${sectionLabel} ${statusText}. (Saved)`,
                duration: 2000,
                gravity: "bottom",
                position: "right",
                backgroundColor: "linear-gradient(to right, #7c5cbf, #9070d8)",
                stopOnFocus: true,
                className: "collapse-toast"
            }).showToast();
        }
        
        // Toastify notification functions
        function showSuccessToast(message) {
            Toastify({
                text: message,
                duration: 3000,
                gravity: "top",
                position: "right",
                backgroundColor: "linear-gradient(to right, #48c774, #23d160)",
                stopOnFocus: true,
                className: "success-toast"
            }).showToast();
        }
        function showErrorToast(message) {
            Toastify({
                text: message,
                duration: 5000,
                gravity: "top",
                position: "right",
                backgroundColor: "linear-gradient(to right, #f14668, #ff3860)",
                stopOnFocus: true,
                className: "error-toast"
            }).showToast();
        }
        function showInfoToast(message) {
            Toastify({
                text: message,
                duration: 4000,
                gravity: "top",
                position: "right",
                backgroundColor: "linear-gradient(to right, #209cee, #3273dc)",
                stopOnFocus: true,
                className: "info-toast"
            }).showToast();
        }

        // Copy to clipboard function
        async function copyToClipboard(text) {
            try {
                await navigator.clipboard.writeText(text);
                showSuccessToast('Link copied to clipboard!');
            } catch (err) {
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                showSuccessToast('Link copied to clipboard!');
            }
        }

        // Show toasts on page load if messages exist
        <?php if (isset($success)): ?>
            showSuccessToast("<?php echo addslashes($success); ?>");
        <?php endif; ?>
        <?php if (isset($error)): ?>
            showErrorToast("<?php echo addslashes($error); ?>");
        <?php endif; ?>

        // Live preview of link URL
        document.getElementById('link_name').addEventListener('input', function() {
            const linkName = this.value.trim();
            const previewElement = document.getElementById('preview-name');
            const previewElementCustom = document.getElementById('preview-name-custom');
            if (linkName) {
                const cleanName = linkName.toLowerCase().replace(/[^a-zA-Z0-9_-]/g, '');
                if (previewElement) previewElement.textContent = cleanName;
                if (previewElementCustom) previewElementCustom.textContent = cleanName;
            } else {
                if (previewElement) previewElement.textContent = 'linkname';
                if (previewElementCustom) previewElementCustom.textContent = 'linkname';
            }
        });

        // Expiration behavior dropdown handler
        document.getElementById('expiration_behavior').addEventListener('change', function() {
            const expiredRedirectField = document.getElementById('expired_redirect_field');
            const expiredCustomPageField = document.getElementById('expired_custom_page_field');
            const expiredCustomMessageField = document.getElementById('expired_custom_message_field');
            const expiredRedirectInput = document.getElementById('expired_redirect_url');
            if (this.value === 'redirect') {
                expiredRedirectField.style.display = 'block';
                expiredCustomPageField.style.display = 'none';
                expiredCustomMessageField.style.display = 'none';
                expiredRedirectInput.required = true;
            } else if (this.value === 'custom_page') {
                expiredRedirectField.style.display = 'none';
                expiredCustomPageField.style.display = 'block';
                expiredCustomMessageField.style.display = 'block';
                expiredRedirectInput.required = false;
                expiredRedirectInput.value = '';
            } else {
                expiredRedirectField.style.display = 'none';
                expiredCustomPageField.style.display = 'none';
                expiredCustomMessageField.style.display = 'none';
                expiredRedirectInput.required = false;
                expiredRedirectInput.value = '';
            }
        });

        // Edit link functionality
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const linkId = this.getAttribute('data-link-id');
                const linkName = this.getAttribute('data-link-name');
                const originalUrl = this.getAttribute('data-original-url');
                const title = this.getAttribute('data-title');
                const categoryId = this.getAttribute('data-category-id');
                const expiresAt = this.getAttribute('data-expires-at');
                const expirationBehavior = this.getAttribute('data-expiration-behavior');
                const expiredRedirectUrl = this.getAttribute('data-expired-redirect-url');
                document.getElementById('edit_link_id').value = linkId;
                document.getElementById('edit_link_name').value = linkName;
                document.getElementById('edit_original_url').value = originalUrl;
                document.getElementById('edit_title').value = title;
                document.getElementById('edit_category_id').value = categoryId;
                document.getElementById('edit_expires_at').value = expiresAt ? new Date(expiresAt).toISOString().slice(0, 16) : '';
                document.getElementById('edit_expiration_behavior').value = expirationBehavior;
                document.getElementById('edit_expired_redirect_url').value = expiredRedirectUrl;
                const editExpiredPageTitle = this.getAttribute('data-expired-page-title') || 'Link Expired';
                const editExpiredPageMessage = this.getAttribute('data-expired-page-message') || 'This link has expired and is no longer available.';
                document.getElementById('edit_expired_page_title').value = editExpiredPageTitle;
                document.getElementById('edit_expired_page_message').value = editExpiredPageMessage;
                const editExpiredRedirectField = document.getElementById('edit_expired_redirect_field');
                const editExpiredCustomPageField = document.getElementById('edit_expired_custom_page_field');
                const editExpiredCustomMessageField = document.getElementById('edit_expired_custom_message_field');
                const editExpiredRedirectInput = document.getElementById('edit_expired_redirect_url');
                if (expirationBehavior === 'redirect') {
                    editExpiredRedirectField.style.display = 'block';
                    editExpiredCustomPageField.style.display = 'none';
                    editExpiredCustomMessageField.style.display = 'none';
                    editExpiredRedirectInput.required = true;
                } else if (expirationBehavior === 'custom_page') {
                    editExpiredRedirectField.style.display = 'none';
                    editExpiredCustomPageField.style.display = 'block';
                    editExpiredCustomMessageField.style.display = 'block';
                    editExpiredRedirectInput.required = false;
                    editExpiredRedirectInput.value = '';
                } else {
                    editExpiredRedirectField.style.display = 'none';
                    editExpiredCustomPageField.style.display = 'none';
                    editExpiredCustomMessageField.style.display = 'none';
                    editExpiredRedirectInput.required = false;
                    editExpiredRedirectInput.value = '';
                }
                updateEditPreview(linkName);
                document.getElementById('edit-link-modal').classList.add('is-active');
            });
        });

        // Edit modal close handlers
        document.getElementById('edit-modal-close').addEventListener('click', function() {
            document.getElementById('edit-link-modal').classList.remove('is-active');
        });
        document.getElementById('edit-modal-cancel').addEventListener('click', function() {
            document.getElementById('edit-link-modal').classList.remove('is-active');
        });
        document.getElementById('edit-link-modal').querySelector('.modal-background').addEventListener('click', function() {
            document.getElementById('edit-link-modal').classList.remove('is-active');
        });

        // Edit form live preview
        document.getElementById('edit_link_name').addEventListener('input', function() {
            updateEditPreview(this.value.trim());
        });

        function updateEditPreview(linkName) {
            const previewElement = document.getElementById('edit-preview-name');
            const previewElementCustom = document.getElementById('edit-preview-name-custom');
            if (linkName) {
                const cleanName = linkName.toLowerCase().replace(/[^a-zA-Z0-9_-]/g, '');
                if (previewElement) previewElement.textContent = cleanName;
                if (previewElementCustom) previewElementCustom.textContent = cleanName;
            } else {
                if (previewElement) previewElement.textContent = 'linkname';
                if (previewElementCustom) previewElementCustom.textContent = 'linkname';
            }
        }

        // Edit expiration behavior dropdown handler
        document.getElementById('edit_expiration_behavior').addEventListener('change', function() {
            const editExpiredRedirectField = document.getElementById('edit_expired_redirect_field');
            const editExpiredCustomPageField = document.getElementById('edit_expired_custom_page_field');
            const editExpiredCustomMessageField = document.getElementById('edit_expired_custom_message_field');
            const editExpiredRedirectInput = document.getElementById('edit_expired_redirect_url');
            if (this.value === 'redirect') {
                editExpiredRedirectField.style.display = 'block';
                editExpiredCustomPageField.style.display = 'none';
                editExpiredCustomMessageField.style.display = 'none';
                editExpiredRedirectInput.required = true;
            } else if (this.value === 'custom_page') {
                editExpiredRedirectField.style.display = 'none';
                editExpiredCustomPageField.style.display = 'block';
                editExpiredCustomMessageField.style.display = 'block';
                editExpiredRedirectInput.required = false;
                editExpiredRedirectInput.value = '';
            } else {
                editExpiredRedirectField.style.display = 'none';
                editExpiredCustomPageField.style.display = 'none';
                editExpiredCustomMessageField.style.display = 'none';
                editExpiredRedirectInput.required = false;
                editExpiredRedirectInput.value = '';
            }
        });

        // Set minimum datetime for edit expiration
        document.getElementById('edit_expires_at').addEventListener('focus', function() {
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            this.min = now.toISOString().slice(0, 16);
        });

        // Search functionality for links table
        const searchInput = document.getElementById('link-search');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();
                const tableRows = document.querySelectorAll('#links-table tbody .link-row');
                const tableContainer = document.getElementById('links-table-container');
                let visibleCount = 0;
                tableRows.forEach(row => {
                    const linkName = row.getAttribute('data-link-name') || '';
                    const title = row.getAttribute('data-title') || '';
                    const url = row.getAttribute('data-url') || '';
                    const category = row.getAttribute('data-category') || '';
                    const matches = linkName.includes(searchTerm) || title.includes(searchTerm) || url.includes(searchTerm) || category.includes(searchTerm);
                    if (matches || searchTerm === '') {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });
                let noResultsMsg = document.getElementById('no-search-results');
                if (searchTerm !== '' && visibleCount === 0) {
                    if (!noResultsMsg) {
                        noResultsMsg = document.createElement('div');
                        noResultsMsg.id = 'no-search-results';
                        noResultsMsg.className = 'sp-alert sp-alert-warning';
                        noResultsMsg.style.margin = '1rem';
                        noResultsMsg.innerHTML = '<i class="fas fa-search"></i> No links found matching your search.';
                        tableContainer.appendChild(noResultsMsg);
                    }
                    noResultsMsg.style.display = '';
                } else if (noResultsMsg) {
                    noResultsMsg.style.display = 'none';
                }
            });
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    this.value = '';
                    this.dispatchEvent(new Event('input'));
                    this.blur();
                }
            });
        }

        // SweetAlert2 for actions
        document.querySelectorAll('.activate-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const linkId = this.getAttribute('data-link-id');
                Swal.fire({
                    title: 'Activate Link?',
                    text: 'This link will become active and redirect visitors.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#48c774',
                    cancelButtonColor: '#f14668',
                    confirmButtonText: 'Yes, activate it!',
                    background: '#1a1a20',
                    color: '#e8e8f0'
                }).then((result) => {
                    if (result.isConfirmed) {
                        showInfoToast("Activating link...");
                        document.getElementById('activate-form-' + linkId).submit();
                    }
                });
            });
        });

        // Deactivate link functionality
        document.querySelectorAll('.deactivate-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const linkId = this.getAttribute('data-link-id');
                document.getElementById('deactivate_link_id').value = linkId;
                document.getElementById('deactivate_behavior').value = 'inactive';
                document.getElementById('deactivate_redirect_url').value = '';
                document.getElementById('deactivate_redirect_field').style.display = 'none';
                document.getElementById('deactivate_custom_page_field').style.display = 'none';
                document.getElementById('deactivate_custom_message_field').style.display = 'none';
                document.getElementById('deactivate-link-modal').classList.add('is-active');
            });
        });

        // Deactivate modal close handlers
        document.getElementById('deactivate-modal-close').addEventListener('click', function() {
            document.getElementById('deactivate-link-modal').classList.remove('is-active');
        });
        document.getElementById('deactivate-modal-cancel').addEventListener('click', function() {
            document.getElementById('deactivate-link-modal').classList.remove('is-active');
        });
        document.getElementById('deactivate-link-modal').querySelector('.modal-background').addEventListener('click', function() {
            document.getElementById('deactivate-link-modal').classList.remove('is-active');
        });

        // Deactivate behavior dropdown handler
        document.getElementById('deactivate_behavior').addEventListener('change', function() {
            const deactivateRedirectField = document.getElementById('deactivate_redirect_field');
            const deactivateCustomPageField = document.getElementById('deactivate_custom_page_field');
            const deactivateCustomMessageField = document.getElementById('deactivate_custom_message_field');
            const deactivateRedirectInput = document.getElementById('deactivate_redirect_url');
            if (this.value === 'redirect') {
                deactivateRedirectField.style.display = 'block';
                deactivateCustomPageField.style.display = 'none';
                deactivateCustomMessageField.style.display = 'none';
                deactivateRedirectInput.required = true;
            } else if (this.value === 'custom_page') {
                deactivateRedirectField.style.display = 'none';
                deactivateCustomPageField.style.display = 'block';
                deactivateCustomMessageField.style.display = 'block';
                deactivateRedirectInput.required = false;
                deactivateRedirectInput.value = '';
            } else {
                deactivateRedirectField.style.display = 'none';
                deactivateCustomPageField.style.display = 'none';
                deactivateCustomMessageField.style.display = 'none';
                deactivateRedirectInput.required = false;
                deactivateRedirectInput.value = '';
            }
        });

        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const linkId = this.getAttribute('data-link-id');
                Swal.fire({
                    title: 'Delete Link?',
                    text: 'This action cannot be undone. The link will be permanently deleted.',
                    icon: 'error',
                    showCancelButton: true,
                    confirmButtonColor: '#f14668',
                    cancelButtonColor: '#363636',
                    confirmButtonText: 'Yes, delete it!',
                    background: '#1a1a20',
                    color: '#e8e8f0'
                }).then((result) => {
                    if (result.isConfirmed) {
                        showInfoToast("Deleting link...");
                        document.getElementById('delete-form-' + linkId).submit();
                    }
                });
            });
        });

        // Copy link functionality
        document.querySelectorAll('.link-copy').forEach(link => {
            link.addEventListener('click', function(e) {
                if (e.ctrlKey || e.metaKey) {
                    e.preventDefault();
                    const url = this.getAttribute('data-url');
                    copyToClipboard(url);
                }
            });
        });

        // Category delete functionality
        document.querySelectorAll('.delete-category-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const categoryId = this.getAttribute('data-category-id');
                const categoryName = this.getAttribute('data-category-name');
                Swal.fire({
                    title: 'Delete Category?',
                    text: `Are you sure you want to delete "${categoryName}"? This action cannot be undone.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#f14668',
                    cancelButtonColor: '#363636',
                    confirmButtonText: 'Yes, delete it!',
                    background: '#1a1a20',
                    color: '#e8e8f0'
                }).then((result) => {
                    if (result.isConfirmed) {
                        showInfoToast("Deleting category...");
                        document.getElementById('delete-category-form-' + categoryId).submit();
                    }
                });
            });
        });

        // Profile link — edit modal
        const plinkEditModal   = document.getElementById('plink-edit-modal');
        const plinkEditClose   = document.getElementById('plink-edit-modal-close');
        const plinkEditCancel  = document.getElementById('plink-edit-modal-cancel');

        document.querySelectorAll('.yl-plink-edit-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('edit_plink_id').value     = this.dataset.id;
                document.getElementById('edit_pl_title').value     = this.dataset.title;
                document.getElementById('edit_pl_url').value       = this.dataset.url;
                document.getElementById('edit_pl_active').checked  = this.dataset.active === '1';
                const sel = document.getElementById('edit_pl_platform');
                for (let opt of sel.options) {
                    if (opt.value === this.dataset.platform) { opt.selected = true; break; }
                }
                plinkEditModal.style.display = 'flex';
            });
        });
        if (plinkEditClose)  plinkEditClose.addEventListener('click',  () => { plinkEditModal.style.display = 'none'; });
        if (plinkEditCancel) plinkEditCancel.addEventListener('click', () => { plinkEditModal.style.display = 'none'; });
        window.addEventListener('click', e => { if (e.target === plinkEditModal) plinkEditModal.style.display = 'none'; });

        // Profile link — delete confirmation
        document.querySelectorAll('.yl-plink-delete-form button[name="delete_profile_link"]').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const name = this.dataset.name;
                const form = this.closest('form');
                Swal.fire({
                    title: 'Remove Link?',
                    text: `Remove "${name}" from your profile page?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#f14668',
                    cancelButtonColor: '#363636',
                    confirmButtonText: 'Yes, remove it!',
                    background: '#1a1a20',
                    color: '#e8e8f0'
                }).then(result => { if (result.isConfirmed) form.submit(); });
            });
        });

        // Domain verification
        const verifyBtn = document.getElementById('verify-domain-btn');
        if (verifyBtn) {
            verifyBtn.addEventListener('click', function() {
                const domain = this.getAttribute('data-domain');
                const token = this.getAttribute('data-token');
                this.classList.add('sp-btn-loading');
                this.disabled = true;
                fetch('/services/verify_domain.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `domain=${encodeURIComponent(domain)}&token=${encodeURIComponent(token)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccessToast('Domain verified successfully!');
                        setTimeout(() => { location.reload(); }, 1500);
                    } else {
                        showErrorToast(data.message || 'Verification failed');
                    }
                })
                .catch(() => { showErrorToast('Failed to verify domain. Please try again.'); })
                .finally(() => {
                    this.classList.remove('sp-btn-loading');
                    this.disabled = false;
                });
            });
        }

        // Token validation - check every 5 minutes
        function validateToken() {
            fetch('/services/validate_token.php', {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include'
            })
            .then(response => response.json())
            .then(data => {
                if (!data.valid) {
                    showErrorToast('Your session has expired. Redirecting to login...');
                    setTimeout(() => {
                        window.location.href = 'https://streamersconnect.com/?service=twitch&login=yourlinks.click&scopes=user:read:email&return_url=https://yourlinks.click/callback.php';
                    }, 2000);
                }
            })
            .catch(error => { console.log('Token validation failed:', error); });
        }
        setInterval(validateToken, 300000);
        setTimeout(validateToken, 10000);

        // Cookie notice close button handler
        document.addEventListener('DOMContentLoaded', function() {
            const cookieNoticeClose = document.getElementById('cookie-notice-close');
            if (cookieNoticeClose) {
                cookieNoticeClose.addEventListener('click', function() {
                    document.getElementById('cookie-notice').style.display = 'none';
                });
            }
            initializeCollapseStates();
            showCookieNotice();
            document.querySelectorAll('details[data-section]').forEach(details => {
                details.addEventListener('toggle', function() {
                    const sectionName = this.getAttribute('data-section');
                    const isNowOpen = this.hasAttribute('open');
                    saveCollapseState(sectionName, !isNowOpen);
                });
            });
        });
    </script>
</body>
</html>
