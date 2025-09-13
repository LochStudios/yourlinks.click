<?php
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
    "SELECT * FROM links WHERE user_id = ? ORDER BY created_at DESC",
    [$_SESSION['user_id']]
);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_link'])) {
        // Create new link
        $linkName = trim($_POST['link_name']);
        $originalUrl = trim($_POST['original_url']);
        $title = trim($_POST['title'] ?? '');
        // Validate inputs
        if (empty($linkName) || empty($originalUrl)) {
            $error = "Link name and destination URL are required.";
        } elseif (!filter_var($originalUrl, FILTER_VALIDATE_URL)) {
            $error = "Please enter a valid URL.";
        } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $linkName)) {
            $error = "Link name can only contain letters, numbers, hyphens, and underscores.";
        } else {
            // Check if link name already exists for this user
            $existing = $db->select("SELECT id FROM links WHERE user_id = ? AND link_name = ?", [$_SESSION['user_id'], $linkName]);
            if ($existing) {
                $error = "You already have a link with this name.";
            } else {
                // Create the link
                $db->insert(
                    "INSERT INTO links (user_id, link_name, original_url, title) VALUES (?, ?, ?, ?)",
                    [$_SESSION['user_id'], $linkName, $originalUrl, $title]
                );
                $success = "Link created successfully!";
            }
        }
    } elseif (isset($_POST['activate_link']) && isset($_POST['link_id'])) {
        $db->execute("UPDATE links SET is_active = TRUE WHERE id = ? AND user_id = ?", [$_POST['link_id'], $_SESSION['user_id']]);
        $success = "Link activated successfully!";
    } elseif (isset($_POST['deactivate_link']) && isset($_POST['link_id'])) {
        $db->execute("UPDATE links SET is_active = FALSE WHERE id = ? AND user_id = ?", [$_POST['link_id'], $_SESSION['user_id']]);
        $success = "Link deactivated successfully!";
    } elseif (isset($_POST['update_custom_domain'])) {
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
    } elseif (isset($_POST['verify_domain'])) {
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
            <div class="box has-background-dark-ter has-text-light mt-5">
                <h2 class="title is-4 has-text-primary">
                    <i class="fas fa-globe has-text-primary"></i> Custom Domain
                </h2>
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
            </div>
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
                            <?php if ($customDomain && $domainVerified): ?>
                                Your link will be available at:<br>
                                <strong><?php echo htmlspecialchars($user['login']); ?>.yourlinks.click/<span id="preview-name">linkname</span></strong><br>
                                <strong><?php echo htmlspecialchars($customDomain); ?>/<span id="preview-name-custom">linkname</span></strong>
                            <?php else: ?>
                                Your link will be: <strong><?php echo htmlspecialchars($user['login']); ?>.yourlinks.click/<span id="preview-name">linkname</span></strong>
                                <?php if ($customDomain && !$domainVerified): ?>
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
                        echo '<tr class="link-row" data-link-name="' . htmlspecialchars(strtolower($link['link_name'])) . '" data-title="' . htmlspecialchars(strtolower($link['title'] ?? '')) . '" data-url="' . htmlspecialchars(strtolower($link['original_url'])) . '">';
                        echo '<td>';
                        echo '<a href="' . htmlspecialchars($fullUrl) . '" target="_blank" class="has-text-link">';
                        echo '<i class="fas fa-external-link-alt"></i> ' . htmlspecialchars($link['link_name']);
                        echo '</a>';
                        echo '</td>';
                        echo '<td>';
                        echo '<a href="' . htmlspecialchars($link['original_url']) . '" target="_blank" class="has-text-grey">';
                        echo htmlspecialchars($link['original_url']);
                        echo '</a>';
                        echo '</td>';
                        echo '<td>' . htmlspecialchars($link['title'] ?? '') . '</td>';
                        echo '<td><span class="tag is-info is-light">' . $link['clicks'] . '</span></td>';
                        echo '<td><span class="tag ' . ($link['is_active'] ? 'is-success' : 'is-danger') . '">' . $status . '</span></td>';
                        echo '<td>';
                        echo '<div class="buttons are-small">';
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
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
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
                    const matches = linkName.includes(searchTerm) ||
                                  title.includes(searchTerm) ||
                                  url.includes(searchTerm);
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
                        document.getElementById('activate-form-' + linkId).submit();
                    }
                });
            });
        });
        document.querySelectorAll('.deactivate-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const linkId = this.getAttribute('data-link-id');
                Swal.fire({
                    title: 'Deactivate Link?',
                    text: 'This link will no longer redirect visitors.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#f39c12',
                    cancelButtonColor: '#f14668',
                    confirmButtonText: 'Yes, deactivate it!',
                    background: '#1a1a1a',
                    color: '#ffffff'
                }).then((result) => {
                    if (result.isConfirmed) {
                        document.getElementById('deactivate-form-' + linkId).submit();
                    }
                });
            });
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
                        document.getElementById('delete-form-' + linkId).submit();
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
                        Swal.fire({
                            title: 'Domain Verified!',
                            text: 'Your custom domain has been successfully verified and activated.',
                            icon: 'success',
                            background: '#1a1a1a',
                            color: '#ffffff',
                            confirmButtonColor: '#48c774'
                        }).then(() => {
                            location.reload(); // Reload to show verified status
                        });
                    } else {
                        Swal.fire({
                            title: 'Verification Failed',
                            text: data.message,
                            icon: 'error',
                            background: '#1a1a1a',
                            color: '#ffffff',
                            confirmButtonColor: '#f14668'
                        });
                    }
                })
                .catch(error => {
                    Swal.fire({
                        title: 'Error',
                        text: 'Failed to verify domain. Please try again.',
                        icon: 'error',
                        background: '#1a1a1a',
                        color: '#ffffff',
                        confirmButtonColor: '#f14668'
                    });
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