<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /');
    exit();
}

// Include database connection
require_once 'services/database.php';

$db = Database::getInstance();
$user = $_SESSION['twitch_user'];

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
    } elseif (isset($_POST['update_custom_domain'])) {
        // Only allow custom domain updates for testing user
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
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="has-background-dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - YourLinks.click</title>
    <!-- Bulma CSS with Dark Mode -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma-dark-mode@1.0.4/dist/css/bulma-dark-mode.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Toastify CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/css/site.css">
</head>
<body class="has-background-dark has-text-light">
    <!-- Navigation -->
    <nav class="navbar is-dark" role="navigation" aria-label="main navigation">
        <div class="navbar-brand">
            <a class="navbar-item has-text-primary" href="/">
                <i class="fas fa-link"></i>
                <strong>YourLinks.click</strong>
            </a>
        </div>
        <div class="navbar-menu">
            <div class="navbar-end">
                <div class="navbar-item">
                    <div class="buttons">
                        <a href="/services/twitch.php?logout=true" class="button is-light">
                            <span class="icon">
                                <i class="fas fa-sign-out-alt"></i>
                            </span>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    <!-- Main Content -->
    <section class="section">
        <div class="container">
            <!-- User Info Header -->
            <div class="card has-background-dark-ter has-text-light">
                <div class="card-content">
                    <div class="media">
                        <div class="media-left">
                            <figure class="image is-64x64">
                                <img src="<?php echo htmlspecialchars($user['profile_image_url']); ?>" alt="Profile" class="is-rounded">
                            </figure>
                        </div>
                        <div class="media-content">
                            <p class="title is-4 has-text-primary">
                                <i class="fas fa-user has-text-primary"></i> Welcome back, <?php echo htmlspecialchars($user['display_name']); ?>!
                            </p>
                            <p class="subtitle is-6 has-text-grey-light">
                                <i class="fab fa-twitch has-text-primary"></i> @<?php echo htmlspecialchars($user['login']); ?>
                            </p>
                            <p class="content has-text-grey">Manage your links and view analytics from your dashboard.</p>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Custom Domain Section -->
            <?php if ($user['login'] === 'gfaundead'): ?>
<details class="box has-background-dark-ter has-text-light mt-5" open>
    <summary>
        <h2 class="title is-4 has-text-primary">
            <i class="fas fa-globe has-text-primary"></i> Custom Domain
        </h2>
    </summary>
    <p class="subtitle is-6 has-text-grey-light mb-4">
        Use your own domain instead of the subdomain format
    </p>
                
                <?php
                // Get user's custom domain info
                $userDomainInfo = $db->select("SELECT custom_domain, domain_verified, domain_verification_token FROM users WHERE id = ?", [$_SESSION['user_id']]);
                $customDomain = $userDomainInfo[0]['custom_domain'] ?? '';
                $domainVerified = $userDomainInfo[0]['domain_verified'] ?? false;
                $verificationToken = $userDomainInfo[0]['domain_verification_token'] ?? '';
                ?>
                
                <form method="POST" action="">
                    <div class="field">
                        <label class="label" for="custom_domain">
                            <i class="fas fa-domain"></i> Your Custom Domain
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="text" id="custom_domain" name="custom_domain" 
                                   value="<?php echo htmlspecialchars($customDomain); ?>"
                                   placeholder="e.g., mydomain.com">
                            <span class="icon is-small is-left">
                                <i class="fas fa-globe"></i>
                            </span>
                        </div>
                        <p class="help">
                            Enter your domain without 'www' or 'http'. Example: mydomain.com
                        </p>
                        <?php if ($customDomain && $domainVerified): ?>
                            <p class="help has-text-success">
                                <i class="fas fa-check-circle"></i> Domain verified! Your links will work at <?php echo htmlspecialchars($customDomain); ?>/linkname
                            </p>
                        <?php elseif ($customDomain && !$domainVerified): ?>
                            <p class="help has-text-warning">
                                <i class="fas fa-exclamation-triangle"></i> Domain not verified yet. Please add the DNS record below.
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="field">
                        <div class="control">
                            <button type="submit" name="update_custom_domain" class="button is-primary">
                                <span class="icon">
                                    <i class="fas fa-save"></i>
                                </span>
                                <span><?php echo $customDomain ? 'Update Domain' : 'Add Custom Domain'; ?></span>
                            </button>
                            <?php if ($customDomain && !$domainVerified): ?>
                                <button type="button" class="button is-info ml-2" id="verify-domain-btn" data-domain="<?php echo htmlspecialchars($customDomain); ?>" data-token="<?php echo htmlspecialchars($verificationToken); ?>">
                                    <span class="icon">
                                        <i class="fas fa-shield-alt"></i>
                                    </span>
                                    <span>Verify Domain</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
                
                <?php if ($customDomain && !$domainVerified && $verificationToken): ?>
                <div class="notification is-info is-dark mt-4">
                    <h4 class="title is-5 has-text-info">
                        <i class="fas fa-dns"></i> DNS Verification Required
                    </h4>
                    <p class="mb-3">To verify ownership of <strong><?php echo htmlspecialchars($customDomain); ?></strong>, add this TXT record to your DNS settings:</p>
                    
                    <div class="field">
                        <label class="label">Record Type:</label>
                        <div class="control">
                            <input class="input" type="text" value="TXT" readonly>
                        </div>
                    </div>
                    
                    <div class="field">
                        <label class="label">Name/Host:</label>
                        <div class="control">
                            <input class="input" type="text" value="_yourlinks_verification.<?php echo htmlspecialchars($customDomain); ?>" readonly>
                        </div>
                    </div>
                    
                    <div class="field">
                        <label class="label">Value:</label>
                        <div class="control">
                            <input class="input" type="text" value="<?php echo htmlspecialchars($verificationToken); ?>" readonly>
                        </div>
                    </div>
                    
                    <div class="field">
                        <label class="label">TTL:</label>
                        <div class="control">
                            <input class="input" type="text" value="300" readonly>
                        </div>
                    </div>
                    
                    <p class="mt-3">
                        <strong>Domain Setup Instructions:</strong><br>
                        <strong>Option 1 - Addon Domain (Easiest):</strong><br>
                        1. Login to your domain's cPanel<br>
                        2. Go to <code>Domains → Addon Domains</code><br>
                        3. Add <strong><?php echo htmlspecialchars($customDomain); ?></strong> as addon domain<br>
                        4. Point DNS A record to: <code>110.232.143.81</code><br>
                        5. Return here and click "Verify Domain"<br><br>
                        <strong>Option 2 - DNS Pointing:</strong><br>
                        1. Go to your domain registrar's DNS settings<br>
                        2. Add A record: <code>@</code> → <code>110.232.143.81</code> (server IP)<br>
                        3. Add the TXT record below<br>
                        4. Wait 5-30 minutes for DNS propagation<br>
                        5. Return here and click "Verify Domain"<br><br>
                        <strong>💡 Note:</strong> SSL certificates are automatically managed for verified custom domains.
                    </p>
                    
                    <div class="content mt-3">
                        <h5 class="title is-6">Example Links:</h5>
                        <ul>
                            <li><code><?php echo htmlspecialchars($customDomain); ?>/youtube</code> → Your YouTube channel</li>
                            <li><code><?php echo htmlspecialchars($customDomain); ?>/twitter</code> → Your Twitter profile</li>
                            <li><code><?php echo htmlspecialchars($customDomain); ?>/discord</code> → Your Discord server</li>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($customDomain && $domainVerified): ?>
                <div class="notification is-success is-dark mt-4">
                    <h4 class="title is-5 has-text-success">
                        <i class="fas fa-check-circle"></i> Custom Domain Active!
                    </h4>
                    <p>Your links are now available at:</p>
                    <p class="has-text-weight-bold"><?php echo htmlspecialchars($customDomain); ?>/linkname</p>
                    <p class="mt-2">You can still use the subdomain format if needed.</p>
                </div>
                <?php endif; ?>
            </details>
            <?php else: ?>
            <!-- Feature Coming Soon Section -->
            <details class="box has-background-dark-ter has-text-light mt-5" open>
                <summary>
                    <h2 class="title is-4 has-text-primary">
                        <i class="fas fa-globe has-text-primary"></i> Custom Domain
                    </h2>
                </summary>
                <div class="notification is-info is-dark">
                    <h4 class="title is-5 has-text-info">
                        <i class="fas fa-clock"></i> Feature Coming Soon
                    </h4>
                    <p>Custom domains are currently in development and testing. This feature will allow you to use your own domain (like yourdomain.com/link) instead of the subdomain format.</p>
                    <p class="mt-3">
                        <strong>Expected features:</strong>
                    </p>
                    <ul class="mt-2">
                        <li>• Use your own domain for links</li>
                        <li>• Automatic SSL certificate management</li>
                        <li>• DNS verification for security</li>
                        <li>• Multiple domains per account</li>
                    </ul>
                    <p class="mt-3 has-text-grey">
                        <em>This feature is being tested internally and will be available to all users soon!</em>
                    </p>
                </div>
            </details>
            <?php endif; ?>
            <!-- Category Management Section -->
            <details class="box has-background-dark-ter has-text-light mt-5" open>
                <summary>
                    <h2 class="title is-4 has-text-primary">
                        <i class="fas fa-tags has-text-primary"></i> Link Categories
                    </h2>
                </summary>
                <p class="subtitle is-6 has-text-grey-light mb-4">
                    Organize your links into categories for better management
                </p>

                <!-- Create New Category -->
                <div class="box has-background-dark-ter">
                    <h3 class="title is-5 has-text-light">
                        <i class="fas fa-plus-circle"></i> Create New Category
                    </h3>
                    <form method="POST" action="">
                        <div class="columns">
                            <div class="column is-4">
                                <div class="field">
                                    <label class="label">Category Name</label>
                                    <div class="control has-icons-left">
                                        <input class="input" type="text" name="category_name" required
                                               placeholder="e.g., Social Media">
                                        <span class="icon is-small is-left">
                                            <i class="fas fa-tag"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="column is-4">
                                <div class="field">
                                    <label class="label">Description (Optional)</label>
                                    <div class="control">
                                        <input class="input" type="text" name="category_description"
                                               placeholder="Brief description">
                                    </div>
                                </div>
                            </div>
                            <div class="column is-2">
                                <div class="field">
                                    <label class="label">Color</label>
                                    <div class="control">
                                        <input class="input" type="color" name="category_color" value="#3273dc">
                                    </div>
                                </div>
                            </div>
                            <div class="column is-2">
                                <div class="field">
                                    <label class="label">&nbsp;</label>
                                    <div class="control">
                                        <button type="submit" name="create_category" class="button is-primary is-fullwidth">
                                            <span class="icon">
                                                <i class="fas fa-plus"></i>
                                            </span>
                                            <span>Create</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Existing Categories -->
                <?php if (!empty($userCategories)): ?>
                <div class="box has-background-dark-ter">
                    <h3 class="title is-5 has-text-light">
                        <i class="fas fa-list"></i> Your Categories
                    </h3>
                    <div class="columns is-multiline">
                        <?php foreach ($userCategories as $category): ?>
                        <div class="column is-6-tablet is-4-desktop">
                            <div class="card has-background-dark-ter has-text-light" style="border-left: 4px solid <?php echo htmlspecialchars($category['color']); ?>">
                                <div class="card-content">
                                    <div class="media">
                                        <div class="media-left">
                                            <span class="icon has-text-primary">
                                                <i class="<?php echo htmlspecialchars($category['icon']); ?>"></i>
                                            </span>
                                        </div>
                                        <div class="media-content">
                                            <p class="title is-6 has-text-light">
                                                <?php echo htmlspecialchars($category['name']); ?>
                                            </p>
                                            <?php if (!empty($category['description'])): ?>
                                            <p class="subtitle is-7 has-text-grey-light">
                                                <?php echo htmlspecialchars($category['description']); ?>
                                            </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="content">
                                        <?php
                                        $linkCount = $db->select("SELECT COUNT(*) as count FROM links WHERE user_id = ? AND category_id = ?", [$_SESSION['user_id'], $category['id']]);
                                        ?>
                                        <span class="tag is-info is-light">
                                            <?php echo $linkCount[0]['count']; ?> links
                                        </span>
                                        <div class="buttons are-small mt-2">
                                            <button type="button" class="button is-danger is-small delete-category-btn"
                                                    data-category-id="<?php echo $category['id']; ?>"
                                                    data-category-name="<?php echo htmlspecialchars($category['name']); ?>">
                                                <span class="icon">
                                                    <i class="fas fa-trash"></i>
                                                </span>
                                            </button>
                                        </div>
                                        <!-- Hidden delete form -->
                                        <form method="POST" action="" class="is-hidden" id="delete-category-form-<?php echo $category['id']; ?>">
                                            <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                            <input type="hidden" name="delete_category" value="1">
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </details>
            <!-- Success/Error Messages -->
            <?php if (isset($success)): ?>
                <div class="notification is-success is-dark">
                    <button class="delete"></button>
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="notification is-danger is-dark">
                    <button class="delete"></button>
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <!-- Stats Cards -->
            <div class="columns is-multiline mt-5">
                <div class="column is-4">
                    <div class="card has-background-primary-dark has-text-white">
                        <div class="card-content has-text-centered">
                            <div class="content">
                                <p class="title is-2">
                                    <?php
                                    $linksCount = $db->select("SELECT COUNT(*) as count FROM links WHERE user_id = ?", [$_SESSION['user_id']]);
                                    echo $linksCount[0]['count'] ?? 0;
                                    ?>
                                </p>
                                <p class="subtitle is-5">
                                    <i class="fas fa-link"></i> Total Links
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-4">
                    <div class="card has-background-info-dark has-text-white">
                        <div class="card-content has-text-centered">
                            <div class="content">
                                <p class="title is-2">
                                    <?php
                                    $clicksCount = $db->select("SELECT SUM(clicks) as total FROM links WHERE user_id = ?", [$_SESSION['user_id']]);
                                    echo $clicksCount[0]['total'] ?? 0;
                                    ?>
                                </p>
                                <p class="subtitle is-5">
                                    <i class="fas fa-mouse-pointer"></i> Total Clicks
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-4">
                    <div class="card has-background-success-dark has-text-white">
                        <div class="card-content has-text-centered">
                            <div class="content">
                                <p class="title is-2">
                                    <?php
                                    $activeCount = $db->select("SELECT COUNT(*) as count FROM links WHERE user_id = ? AND is_active = TRUE", [$_SESSION['user_id']]);
                                    echo $activeCount[0]['count'] ?? 0;
                                    ?>
                                </p>
                                <p class="subtitle is-5">
                                    <i class="fas fa-check-circle"></i> Active Links
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Link Management Section -->
            <div class="box has-background-dark-ter has-text-light">
                <h2 class="title is-4 has-text-primary">
                    <i class="fas fa-plus-circle has-text-primary"></i> Create New Link
                </h2>
                <form method="POST" action="">
                    <div class="field">
                        <label class="label" for="link_name">
                            <i class="fas fa-tag"></i> Link Name
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="text" id="link_name" name="link_name" required
                                   placeholder="e.g., youtube, twitter, discord">
                            <span class="icon is-small is-left">
                                <i class="fas fa-link"></i>
                            </span>
                        </div>
                        <p class="help">
                            <?php if ($user['login'] === 'gfaundead' && $customDomain && $domainVerified): ?>
                                Your link will be available at:<br>
                                <strong><?php echo htmlspecialchars($user['login']); ?>.yourlinks.click/<span id="preview-name">linkname</span></strong><br>
                                <strong><?php echo htmlspecialchars($customDomain); ?>/<span id="preview-name-custom">linkname</span></strong>
                            <?php else: ?>
                                Your link will be: <strong><?php echo htmlspecialchars($user['login']); ?>.yourlinks.click/<span id="preview-name">linkname</span></strong>
                                <?php if ($user['login'] === 'gfaundead' && $customDomain && !$domainVerified): ?>
                                    <br><em class="has-text-warning">Custom domain available after verification</em>
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="field">
                        <label class="label" for="original_url">
                            <i class="fas fa-external-link-alt"></i> Destination URL
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="url" id="original_url" name="original_url" required
                                   placeholder="https://example.com/your-profile">
                            <span class="icon is-small is-left">
                                <i class="fas fa-globe"></i>
                            </span>
                        </div>
                        <p class="help">The URL where visitors will be redirected</p>
                    </div>
                    <div class="field">
                        <label class="label" for="title">
                            <i class="fas fa-heading"></i> Title (Optional)
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="text" id="title" name="title"
                                   placeholder="Display name for this link">
                            <span class="icon is-small is-left">
                                <i class="fas fa-i-cursor"></i>
                            </span>
                        </div>
                        <p class="help">A friendly name to help you remember this link</p>
                    </div>
                    <div class="field">
                        <label class="label" for="category_id">
                            <i class="fas fa-tag"></i> Category
                        </label>
                        <div class="control has-icons-left">
                            <div class="select is-fullwidth">
                                <select id="category_id" name="category_id">
                                    <option value="">No Category</option>
                                    <?php foreach ($userCategories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" 
                                                data-color="<?php echo htmlspecialchars($category['color']); ?>"
                                                data-icon="<?php echo htmlspecialchars($category['icon']); ?>">
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <span class="icon is-small is-left">
                                <i class="fas fa-tag"></i>
                            </span>
                        </div>
                        <p class="help">Organize your links into categories for better management</p>
                    </div>
                    <div class="field">
                        <label class="label" for="expires_at">
                            <i class="fas fa-clock"></i> Expiration Date (Optional)
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="datetime-local" id="expires_at" name="expires_at"
                                   min="<?php echo date('Y-m-d\TH:i'); ?>">
                            <span class="icon is-small is-left">
                                <i class="fas fa-calendar-alt"></i>
                            </span>
                        </div>
                        <p class="help">Set when this link should expire. Leave empty for no expiration.</p>
                    </div>
                    <div class="field">
                        <label class="label" for="expiration_behavior">
                            <i class="fas fa-exclamation-triangle"></i> When Link Expires
                        </label>
                        <div class="control has-icons-left">
                            <div class="select is-fullwidth">
                                <select id="expiration_behavior" name="expiration_behavior">
                                    <option value="inactive">Make link inactive (404 error)</option>
                                    <option value="redirect">Redirect to custom URL</option>
                                    <option value="custom_page">Show custom expired page</option>
                                </select>
                            </div>
                            <span class="icon is-small is-left">
                                <i class="fas fa-toggle-off"></i>
                            </span>
                        </div>
                        <p class="help">Choose what happens when the link expires</p>
                    </div>
                    <div class="field" id="expired_redirect_field" style="display: none;">
                        <label class="label" for="expired_redirect_url">
                            <i class="fas fa-external-link-alt"></i> Expired Redirect URL
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="url" id="expired_redirect_url" name="expired_redirect_url"
                                   placeholder="https://example.com/expired">
                            <span class="icon is-small is-left">
                                <i class="fas fa-globe"></i>
                            </span>
                        </div>
                        <p class="help">URL to redirect visitors when this link expires</p>
                    </div>
                    <div class="field" id="expired_custom_page_field" style="display: none;">
                        <label class="label" for="expired_page_title">
                            <i class="fas fa-heading"></i> Custom Page Title
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="text" id="expired_page_title" name="expired_page_title"
                                   placeholder="Link Expired" value="Link Expired">
                            <span class="icon is-small is-left">
                                <i class="fas fa-i-cursor"></i>
                            </span>
                        </div>
                        <p class="help">Title shown on the custom expired page</p>
                    </div>
                    <div class="field" id="expired_custom_message_field" style="display: none;">
                        <label class="label" for="expired_page_message">
                            <i class="fas fa-comment"></i> Custom Message
                        </label>
                        <div class="control">
                            <textarea class="textarea" id="expired_page_message" name="expired_page_message"
                                      placeholder="This link has expired..." rows="3">This link has expired and is no longer available.</textarea>
                        </div>
                        <p class="help">Message shown on the custom expired page</p>
                    </div>
                    <div class="field">
                        <div class="control">
                            <button type="submit" name="create_link" class="button is-primary is-medium">
                                <span class="icon">
                                    <i class="fas fa-plus"></i>
                                </span>
                                <span>Create Link</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <!-- Links Table -->
            <div class="box has-background-dark-ter has-text-light">
                <div class="level">
                    <div class="level-left">
                        <h2 class="title is-4 has-text-primary">
                            <i class="fas fa-list has-text-primary"></i> Your Links
                        </h2>
                    </div>
                    <div class="level-right">
                        <?php if (count($userLinks) > 10): ?>
                        <div class="field">
                            <div class="control has-icons-left">
                                <input class="input" type="text" id="link-search" placeholder="Search links...">
                                <span class="icon is-small is-left">
                                    <i class="fas fa-search"></i>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                if (empty($userLinks)) {
                    echo '<div class="notification is-info is-dark">';
                    echo '<i class="fas fa-info-circle"></i> You haven\'t created any links yet. Create your first link above!';
                    echo '</div>';
                } else {
                    echo '<div class="table-container">';
                    echo '<table class="table is-fullwidth is-hoverable is-striped" id="links-table">';
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
                        $statusClass = $link['is_active'] ? 'has-text-success' : 'has-text-danger';
                        echo '<tr class="link-row" data-link-name="' . htmlspecialchars(strtolower($link['link_name'])) . '" data-title="' . htmlspecialchars(strtolower($link['title'] ?? '')) . '" data-url="' . htmlspecialchars(strtolower($link['original_url'])) . '" data-category="' . htmlspecialchars(strtolower($link['category_name'] ?? '')) . '">';
                        echo '<td>';
                        echo '<a href="' . htmlspecialchars($fullUrl) . '" target="_blank" class="has-text-link link-copy" data-url="' . htmlspecialchars($fullUrl) . '">';
                        echo '<i class="fas fa-external-link-alt"></i> ' . htmlspecialchars($link['link_name']);
                        echo '</a>';
                        echo '</td>';
                        echo '<td>';
                        echo '<a href="' . htmlspecialchars($link['original_url']) . '" target="_blank" class="has-text-grey">';
                        echo htmlspecialchars($link['original_url']);
                        echo '</a>';
                        echo '</td>';
                        echo '<td>' . htmlspecialchars($link['title'] ?? '') . '</td>';
                        echo '<td>';
                        if (!empty($link['category_name'])) {
                            echo '<span class="tag" style="background-color: ' . htmlspecialchars($link['category_color']) . '; color: white;">';
                            echo '<span class="icon is-small mr-1">';
                            echo '<i class="' . htmlspecialchars($link['category_icon']) . '"></i>';
                            echo '</span>';
                            echo htmlspecialchars($link['category_name']);
                            echo '</span>';
                        } else {
                            echo '<span class="tag is-dark has-text-grey-light">';
                            echo '<i class="fas fa-question-circle mr-1"></i>';
                            echo 'No Category';
                            echo '</span>';
                        }
                        echo '</td>';
                        echo '<td>';
                        if (!empty($link['expires_at'])) {
                            $expiresAt = strtotime($link['expires_at']);
                            $now = time();
                            $daysUntilExpiry = floor(($expiresAt - $now) / (60 * 60 * 24));
                            
                            if ($link['expiration_status'] === 'expired') {
                                echo '<span class="tag is-danger">';
                                echo '<i class="fas fa-exclamation-triangle mr-1"></i>';
                                echo 'Expired';
                            } elseif ($link['expiration_status'] === 'expiring_soon') {
                                echo '<span class="tag is-warning">';
                                echo '<i class="fas fa-clock mr-1"></i>';
                                echo 'Expires in ' . $daysUntilExpiry . ' day' . ($daysUntilExpiry !== 1 ? 's' : '');
                            } else {
                                echo '<span class="tag is-info is-light">';
                                echo '<i class="fas fa-calendar-alt mr-1"></i>';
                                echo date('M j, Y g:i A', $expiresAt);
                            }
                            echo '</span>';
                        } else {
                            echo '<span class="tag is-dark has-text-grey-light">';
                            echo '<i class="fas fa-infinity mr-1"></i>';
                            echo 'Never';
                            echo '</span>';
                        }
                        echo '</td>';
                        echo '<td><span class="tag is-info is-light">' . $link['clicks'] . '</span></td>';
                        echo '<td><span class="tag ' . ($link['is_active'] ? 'is-success' : 'is-danger') . '">' . $status . '</span></td>';
                        echo '<td>';
                        echo '<div class="buttons are-small">';
                        echo '<button type="button" class="button is-info edit-btn" data-link-id="' . $link['id'] . '" data-link-name="' . htmlspecialchars($link['link_name']) . '" data-original-url="' . htmlspecialchars($link['original_url']) . '" data-title="' . htmlspecialchars($link['title'] ?? '') . '" data-category-id="' . ($link['category_id'] ?? '') . '" data-expires-at="' . htmlspecialchars($link['expires_at'] ?? '') . '" data-expiration-behavior="' . htmlspecialchars($link['expiration_behavior'] ?? 'inactive') . '" data-expired-redirect-url="' . htmlspecialchars($link['expired_redirect_url'] ?? '') . '" data-expired-page-title="' . htmlspecialchars($link['expired_page_title'] ?? 'Link Expired') . '" data-expired-page-message="' . htmlspecialchars($link['expired_page_message'] ?? 'This link has expired and is no longer available.') . '">';
                        echo '<span class="icon"><i class="fas fa-edit"></i></span>';
                        echo '<span>Edit</span>';
                        echo '</button>';
                        if ($link['is_active']) {
                            echo '<button type="button" class="button is-warning deactivate-btn" data-link-id="' . $link['id'] . '">';
                            echo '<span class="icon"><i class="fas fa-pause"></i></span>';
                            echo '<span>Deactivate</span>';
                            echo '</button>';
                        } else {
                            echo '<button type="button" class="button is-success activate-btn" data-link-id="' . $link['id'] . '">';
                            echo '<span class="icon"><i class="fas fa-play"></i></span>';
                            echo '<span>Activate</span>';
                            echo '</button>';
                        }
                        echo '<button type="button" class="button is-danger delete-btn" data-link-id="' . $link['id'] . '">';
                        echo '<span class="icon"><i class="fas fa-trash"></i></span>';
                        echo '<span>Delete</span>';
                        echo '</button>';
                        echo '</div>';
                        // Hidden forms for POST actions
                        echo '<form method="POST" action="" class="is-hidden" id="activate-form-' . $link['id'] . '">';
                        echo '<input type="hidden" name="link_id" value="' . $link['id'] . '">';
                        echo '<input type="hidden" name="activate_link" value="1">';
                        echo '</form>';
                        echo '<form method="POST" action="" class="is-hidden" id="deactivate-form-' . $link['id'] . '">';
                        echo '<input type="hidden" name="link_id" value="' . $link['id'] . '">';
                        echo '<input type="hidden" name="deactivate_link" value="1">';
                        echo '</form>';
                        echo '<form method="POST" action="" class="is-hidden" id="delete-form-' . $link['id'] . '">';
                        echo '<input type="hidden" name="link_id" value="' . $link['id'] . '">';
                        echo '<input type="hidden" name="delete_link" value="1">';
                        echo '</form>';
                        echo '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody>';
                    echo '</table>';
                    echo '</div>';
                }
                ?>
            </div>
        </div>
    </section>

    <!-- Deactivate Link Modal -->
    <div class="modal" id="deactivate-link-modal">
        <div class="modal-background"></div>
        <div class="modal-card">
            <header class="modal-card-head has-background-dark">
                <p class="modal-card-title has-text-light">
                    <i class="fas fa-pause has-text-warning"></i> Deactivate Link
                </p>
                <button class="delete" aria-label="close" id="deactivate-modal-close"></button>
            </header>
            <section class="modal-card-body has-background-dark-ter">
                <div class="notification is-warning is-dark">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>What should happen when visitors try to access this deactivated link?</strong>
                </div>

                <form method="POST" action="" id="deactivate-link-form">
                    <input type="hidden" name="deactivate_link_id" id="deactivate_link_id">

                    <div class="field">
                        <label class="label has-text-light" for="deactivate_behavior">
                            <i class="fas fa-exclamation-triangle"></i> When Link is Deactivated
                        </label>
                        <div class="control has-icons-left">
                            <div class="select is-fullwidth">
                                <select id="deactivate_behavior" name="deactivate_behavior" required>
                                    <option value="inactive">Show 404 error page</option>
                                    <option value="redirect">Redirect to custom URL</option>
                                    <option value="custom_page">Show custom deactivated page</option>
                                </select>
                            </div>
                            <span class="icon is-small is-left">
                                <i class="fas fa-toggle-off"></i>
                            </span>
                        </div>
                        <p class="help has-text-grey-light">Choose what happens when visitors access this deactivated link</p>
                    </div>

                    <div class="field" id="deactivate_redirect_field" style="display: none;">
                        <label class="label has-text-light" for="deactivate_redirect_url">
                            <i class="fas fa-external-link-alt"></i> Deactivated Redirect URL
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="url" id="deactivate_redirect_url" name="deactivate_redirect_url"
                                   placeholder="https://example.com/deactivated">
                            <span class="icon is-small is-left">
                                <i class="fas fa-globe"></i>
                            </span>
                        </div>
                        <p class="help has-text-grey-light">URL to redirect visitors when this link is deactivated</p>
                    </div>
                    <div class="field" id="deactivate_custom_page_field" style="display: none;">
                        <label class="label has-text-light" for="deactivated_page_title">
                            <i class="fas fa-heading"></i> Custom Page Title
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="text" id="deactivated_page_title" name="deactivated_page_title"
                                   placeholder="Link Deactivated" value="Link Deactivated">
                            <span class="icon is-small is-left">
                                <i class="fas fa-i-cursor"></i>
                            </span>
                        </div>
                        <p class="help has-text-grey-light">Title shown on the custom deactivated page</p>
                    </div>
                    <div class="field" id="deactivate_custom_message_field" style="display: none;">
                        <label class="label has-text-light" for="deactivated_page_message">
                            <i class="fas fa-comment"></i> Custom Message
                        </label>
                        <div class="control">
                            <textarea class="textarea" id="deactivated_page_message" name="deactivated_page_message"
                                      placeholder="This link has been deactivated..." rows="3">This link has been deactivated and is no longer available.</textarea>
                        </div>
                        <p class="help has-text-grey-light">Message shown on the custom deactivated page</p>
                    </div>
                </form>
            </section>
            <footer class="modal-card-foot has-background-dark">
                <button class="button is-warning" type="submit" form="deactivate-link-form" name="deactivate_link">
                    <span class="icon">
                        <i class="fas fa-pause"></i>
                    </span>
                    <span>Deactivate Link</span>
                </button>
                <button class="button is-dark" id="deactivate-modal-cancel">
                    <span class="icon">
                        <i class="fas fa-times"></i>
                    </span>
                    <span>Cancel</span>
                </button>
            </footer>
        </div>
    </div>

    <!-- Edit Link Modal -->
    <div class="modal" id="edit-link-modal">
        <div class="modal-background"></div>
        <div class="modal-card">
            <header class="modal-card-head has-background-dark">
                <p class="modal-card-title has-text-light">
                    <i class="fas fa-edit has-text-primary"></i> Edit Link
                </p>
                <button class="delete" aria-label="close" id="edit-modal-close"></button>
            </header>
            <section class="modal-card-body has-background-dark-ter">
                <form method="POST" action="" id="edit-link-form">
                    <input type="hidden" name="edit_link_id" id="edit_link_id">
                    
                    <div class="field">
                        <label class="label has-text-light" for="edit_link_name">
                            <i class="fas fa-tag"></i> Link Name
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="text" id="edit_link_name" name="edit_link_name" required
                                   placeholder="e.g., youtube, twitter, discord">
                            <span class="icon is-small is-left">
                                <i class="fas fa-link"></i>
                            </span>
                        </div>
                        <p class="help has-text-grey-light">
                            <?php if ($user['login'] === 'gfaundead' && $customDomain && $domainVerified): ?>
                                Your link will be available at:<br>
                                <strong><?php echo htmlspecialchars($user['login']); ?>.yourlinks.click/<span id="edit-preview-name">linkname</span></strong><br>
                                <strong><?php echo htmlspecialchars($customDomain); ?>/<span id="edit-preview-name-custom">linkname</span></strong>
                            <?php else: ?>
                                Your link will be: <strong><?php echo htmlspecialchars($user['login']); ?>.yourlinks.click/<span id="edit-preview-name">linkname</span></strong>
                                <?php if ($user['login'] === 'gfaundead' && $customDomain && !$domainVerified): ?>
                                    <br><em class="has-text-warning">Custom domain available after verification</em>
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <div class="field">
                        <label class="label has-text-light" for="edit_original_url">
                            <i class="fas fa-external-link-alt"></i> Destination URL
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="url" id="edit_original_url" name="edit_original_url" required
                                   placeholder="https://example.com/your-profile">
                            <span class="icon is-small is-left">
                                <i class="fas fa-globe"></i>
                            </span>
                        </div>
                        <p class="help has-text-grey-light">The URL where visitors will be redirected</p>
                    </div>
                    
                    <div class="field">
                        <label class="label has-text-light" for="edit_title">
                            <i class="fas fa-heading"></i> Title (Optional)
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="text" id="edit_title" name="edit_title"
                                   placeholder="Display name for this link">
                            <span class="icon is-small is-left">
                                <i class="fas fa-i-cursor"></i>
                            </span>
                        </div>
                        <p class="help has-text-grey-light">A friendly name to help you remember this link</p>
                    </div>
                    
                    <div class="field">
                        <label class="label has-text-light" for="edit_category_id">
                            <i class="fas fa-tag"></i> Category
                        </label>
                        <div class="control has-icons-left">
                            <div class="select is-fullwidth">
                                <select id="edit_category_id" name="edit_category_id">
                                    <option value="">No Category</option>
                                    <?php foreach ($userCategories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" 
                                                data-color="<?php echo htmlspecialchars($category['color']); ?>"
                                                data-icon="<?php echo htmlspecialchars($category['icon']); ?>">
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <span class="icon is-small is-left">
                                <i class="fas fa-tag"></i>
                            </span>
                        </div>
                        <p class="help has-text-grey-light">Organize your links into categories for better management</p>
                    </div>
                    
                    <div class="field">
                        <label class="label has-text-light" for="edit_expires_at">
                            <i class="fas fa-clock"></i> Expiration Date (Optional)
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="datetime-local" id="edit_expires_at" name="edit_expires_at">
                            <span class="icon is-small is-left">
                                <i class="fas fa-calendar-alt"></i>
                            </span>
                        </div>
                        <p class="help has-text-grey-light">Set when this link should expire. Leave empty for no expiration.</p>
                    </div>
                    
                    <div class="field">
                        <label class="label has-text-light" for="edit_expiration_behavior">
                            <i class="fas fa-exclamation-triangle"></i> When Link Expires
                        </label>
                        <div class="control has-icons-left">
                            <div class="select is-fullwidth">
                                <select id="edit_expiration_behavior" name="edit_expiration_behavior">
                                    <option value="inactive">Make link inactive (404 error)</option>
                                    <option value="redirect">Redirect to custom URL</option>
                                    <option value="custom_page">Show custom expired page</option>
                                </select>
                            </div>
                            <span class="icon is-small is-left">
                                <i class="fas fa-toggle-off"></i>
                            </span>
                        </div>
                        <p class="help has-text-grey-light">Choose what happens when the link expires</p>
                    </div>
                    
                    <div class="field" id="edit_expired_redirect_field" style="display: none;">
                        <label class="label has-text-light" for="edit_expired_redirect_url">
                            <i class="fas fa-external-link-alt"></i> Expired Redirect URL
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="url" id="edit_expired_redirect_url" name="edit_expired_redirect_url"
                                   placeholder="https://example.com/expired">
                            <span class="icon is-small is-left">
                                <i class="fas fa-globe"></i>
                            </span>
                        </div>
                        <p class="help has-text-grey-light">URL to redirect visitors when this link expires</p>
                    </div>
                    <div class="field" id="edit_expired_custom_page_field" style="display: none;">
                        <label class="label has-text-light" for="edit_expired_page_title">
                            <i class="fas fa-heading"></i> Custom Page Title
                        </label>
                        <div class="control has-icons-left">
                            <input class="input" type="text" id="edit_expired_page_title" name="edit_expired_page_title"
                                   placeholder="Link Expired">
                            <span class="icon is-small is-left">
                                <i class="fas fa-i-cursor"></i>
                            </span>
                        </div>
                        <p class="help has-text-grey-light">Title shown on the custom expired page</p>
                    </div>
                    <div class="field" id="edit_expired_custom_message_field" style="display: none;">
                        <label class="label has-text-light" for="edit_expired_page_message">
                            <i class="fas fa-comment"></i> Custom Message
                        </label>
                        <div class="control">
                            <textarea class="textarea" id="edit_expired_page_message" name="edit_expired_page_message"
                                      placeholder="This link has expired..." rows="3"></textarea>
                        </div>
                        <p class="help has-text-grey-light">Message shown on the custom expired page</p>
                    </div>
                </form>
            </section>
            <footer class="modal-card-foot has-background-dark">
                <button class="button is-primary" type="submit" form="edit-link-form" name="edit_link">
                    <span class="icon">
                        <i class="fas fa-save"></i>
                    </span>
                    <span>Save Changes</span>
                </button>
                <button class="button is-dark" id="edit-modal-cancel">
                    <span class="icon">
                        <i class="fas fa-times"></i>
                    </span>
                    <span>Cancel</span>
                </button>
            </footer>
        </div>
    </div>

    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Toastify JS -->
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script>
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
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                showSuccessToast('Link copied to clipboard!');
            }
        }
        // Show success toast on page load if success message exists
        <?php if (isset($success)): ?>
            showSuccessToast("<?php echo addslashes($success); ?>");
        <?php endif; ?>
        // Show error toast on page load if error message exists
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
                // Populate the edit modal
                document.getElementById('edit_link_id').value = linkId;
                document.getElementById('edit_link_name').value = linkName;
                document.getElementById('edit_original_url').value = originalUrl;
                document.getElementById('edit_title').value = title;
                document.getElementById('edit_category_id').value = categoryId;
                document.getElementById('edit_expires_at').value = expiresAt ? new Date(expiresAt).toISOString().slice(0, 16) : '';
                document.getElementById('edit_expiration_behavior').value = expirationBehavior;
                document.getElementById('edit_expired_redirect_url').value = expiredRedirectUrl;
                
                // Populate custom page fields
                const editExpiredPageTitle = this.getAttribute('data-expired-page-title') || 'Link Expired';
                const editExpiredPageMessage = this.getAttribute('data-expired-page-message') || 'This link has expired and is no longer available.';
                document.getElementById('edit_expired_page_title').value = editExpiredPageTitle;
                document.getElementById('edit_expired_page_message').value = editExpiredPageMessage;
                
                // Handle expiration behavior visibility
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
                // Update preview
                updateEditPreview(linkName);
                // Show modal
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
            const linkName = this.value.trim();
            updateEditPreview(linkName);
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
                const tableContainer = document.querySelector('.table-container');
                let visibleCount = 0;
                tableRows.forEach(row => {
                    const linkName = row.getAttribute('data-link-name') || '';
                    const title = row.getAttribute('data-title') || '';
                    const url = row.getAttribute('data-url') || '';
                    const category = row.getAttribute('data-category') || '';
                    const matches = linkName.includes(searchTerm) ||
                                  title.includes(searchTerm) ||
                                  url.includes(searchTerm) ||
                                  category.includes(searchTerm);
                    if (matches || searchTerm === '') {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });
                // Show/hide "no results" message
                let noResultsMsg = document.getElementById('no-search-results');
                if (searchTerm !== '' && visibleCount === 0) {
                    if (!noResultsMsg) {
                        noResultsMsg = document.createElement('div');
                        noResultsMsg.id = 'no-search-results';
                        noResultsMsg.className = 'notification is-warning is-dark';
                        noResultsMsg.innerHTML = '<i class="fas fa-search"></i> No links found matching your search.';
                        tableContainer.appendChild(noResultsMsg);
                    }
                    noResultsMsg.style.display = '';
                } else if (noResultsMsg) {
                    noResultsMsg.style.display = 'none';
                }
            });
            // Add search input styling and focus effects
            searchInput.addEventListener('focus', function() {
                this.parentElement.classList.add('is-focused');
            });
            searchInput.addEventListener('blur', function() {
                this.parentElement.classList.remove('is-focused');
            });
            // Clear search on escape key
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
                    background: '#1a1a1a',
                    color: '#ffffff'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Show loading toast
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

                // Populate the deactivate modal
                document.getElementById('deactivate_link_id').value = linkId;

                // Reset form to default values
                document.getElementById('deactivate_behavior').value = 'inactive';
                document.getElementById('deactivate_redirect_url').value = '';
                document.getElementById('deactivate_redirect_field').style.display = 'none';
                document.getElementById('deactivate_custom_page_field').style.display = 'none';
                document.getElementById('deactivate_custom_message_field').style.display = 'none';

                // Show modal
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
                    background: '#1a1a1a',
                    color: '#ffffff'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Show loading toast
                        showInfoToast("Deleting link...");
                        document.getElementById('delete-form-' + linkId).submit();
                    }
                });
            });
        });
        // Copy link functionality
        document.querySelectorAll('.link-copy').forEach(link => {
            link.addEventListener('click', function(e) {
                // Only copy if Ctrl/Cmd is held (to not interfere with normal clicking)
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
                    background: '#1a1a1a',
                    color: '#ffffff'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Show loading toast
                        showInfoToast("Deleting category...");
                        document.getElementById('delete-category-form-' + categoryId).submit();
                    }
                });
            });
        });
        // Close notifications
        document.querySelectorAll('.notification .delete').forEach(deleteBtn => {
            deleteBtn.addEventListener('click', function() {
                this.parentNode.style.display = 'none';
            });
        });
        // Domain verification
        const verifyBtn = document.getElementById('verify-domain-btn');
        if (verifyBtn) {
            verifyBtn.addEventListener('click', function() {
                const domain = this.getAttribute('data-domain');
                const token = this.getAttribute('data-token');
                // Show loading state
                this.classList.add('is-loading');
                this.disabled = true;
                // Make verification request
                fetch('/services/verify_domain.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `domain=${encodeURIComponent(domain)}&token=${encodeURIComponent(token)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccessToast('Domain verified successfully!');
                        setTimeout(() => {
                            location.reload(); // Reload to show verified status
                        }, 1500);
                    } else {
                        showErrorToast(data.message || 'Verification failed');
                    }
                })
                .catch(error => {
                    showErrorToast('Failed to verify domain. Please try again.');
                })
                .finally(() => {
                    // Reset button state
                    this.classList.remove('is-loading');
                    this.disabled = false;
                });
            });
        }
    </script>
</body>
</html>