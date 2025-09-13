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
    } elseif (isset($_POST['delete_link']) && isset($_POST['link_id'])) {
        $db->execute("DELETE FROM links WHERE id = ? AND user_id = ?", [$_POST['link_id'], $_SESSION['user_id']]);
        $success = "Link deleted successfully!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - YourLinks.click</title>
    <link rel="stylesheet" href="/css/site.css">
</head>
<body class="dashboard-page">
    <div class="header">
        <h1>YourLinks.click Dashboard</h1>
        <div class="user-info">
            <img src="<?php echo htmlspecialchars($user['profile_image_url']); ?>" alt="Profile" class="user-avatar">
            <div>
                <strong><?php echo htmlspecialchars($user['display_name']); ?></strong>
                <br>
                <small><?php echo htmlspecialchars($user['login']); ?></small>
            </div>
        </div>
        <a href="/services/twitch.php?logout=true" class="logout-btn">Logout</a>
    </div>

    <div class="dashboard-content">
        <div class="welcome">
            <h2>Welcome back, <?php echo htmlspecialchars($user['display_name']); ?>!</h2>
            <p>Manage your links and view analytics from your dashboard.</p>
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
        </div>

        <div class="stats">
            <div class="stat-card">
                <div class="stat-number">
                    <?php
                    // Get total links count
                    $linksCount = $db->select("SELECT COUNT(*) as count FROM links WHERE user_id = ?", [$_SESSION['user_id']]);
                    echo $linksCount[0]['count'] ?? 0;
                    ?>
                </div>
                <div>Total Links</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">
                    <?php
                    // Get total clicks
                    $clicksCount = $db->select("SELECT SUM(clicks) as total FROM links WHERE user_id = ?", [$_SESSION['user_id']]);
                    echo $clicksCount[0]['total'] ?? 0;
                    ?>
                </div>
                <div>Total Clicks</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">
                    <?php
                    // Get active links
                    $activeCount = $db->select("SELECT COUNT(*) as count FROM links WHERE user_id = ? AND is_active = TRUE", [$_SESSION['user_id']]);
                    echo $activeCount[0]['count'] ?? 0;
                    ?>
                </div>
                <div>Active Links</div>
            </div>
        </div>

        <!-- Link Management Section -->
        <div class="link-management">
            <h2>Create New Link</h2>
            <form method="POST" action="" class="create-link-form">
                <div class="form-group">
                    <label for="link_name">Link Name:</label>
                    <input type="text" id="link_name" name="link_name" required
                           placeholder="e.g., youtube, twitter, discord">
                    <small>Your link will be: <?php echo htmlspecialchars($user['login']); ?>.yourlinks.click/<span id="preview-name">linkname</span></small>
                </div>

                <div class="form-group">
                    <label for="original_url">Destination URL:</label>
                    <input type="url" id="original_url" name="original_url" required
                           placeholder="https://example.com/your-profile">
                </div>

                <div class="form-group">
                    <label for="title">Title (optional):</label>
                    <input type="text" id="title" name="title"
                           placeholder="Display name for this link">
                </div>

                <button type="submit" name="create_link" class="btn-primary">Create Link</button>
            </form>

            <h2>Your Links</h2>
            <div class="links-table">
                <?php
                $userLinks = $db->select(
                    "SELECT * FROM links WHERE user_id = ? ORDER BY created_at DESC",
                    [$_SESSION['user_id']]
                );

                if (empty($userLinks)) {
                    echo '<p>You haven\'t created any links yet. Create your first link above!</p>';
                } else {
                    echo '<table>';
                    echo '<thead>';
                    echo '<tr>';
                    echo '<th>Link Name</th>';
                    echo '<th>Destination</th>';
                    echo '<th>Title</th>';
                    echo '<th>Clicks</th>';
                    echo '<th>Status</th>';
                    echo '<th>Actions</th>';
                    echo '</tr>';
                    echo '</thead>';
                    echo '<tbody>';

                    foreach ($userLinks as $link) {
                        $fullUrl = 'https://' . $user['login'] . '.yourlinks.click/' . $link['link_name'];
                        $status = $link['is_active'] ? 'Active' : 'Inactive';
                        $statusClass = $link['is_active'] ? 'status-active' : 'status-inactive';

                        echo '<tr>';
                        echo '<td><a href="' . htmlspecialchars($fullUrl) . '" target="_blank">' . htmlspecialchars($link['link_name']) . '</a></td>';
                        echo '<td><a href="' . htmlspecialchars($link['original_url']) . '" target="_blank">' . htmlspecialchars($link['original_url']) . '</a></td>';
                        echo '<td>' . htmlspecialchars($link['title'] ?? '') . '</td>';
                        echo '<td>' . $link['clicks'] . '</td>';
                        echo '<td><span class="' . $statusClass . '">' . $status . '</span></td>';
                        echo '<td>';
                        echo '<form method="POST" action="" style="display: inline;">';
                        echo '<input type="hidden" name="link_id" value="' . $link['id'] . '">';
                        if ($link['is_active']) {
                            echo '<button type="submit" name="deactivate_link" class="btn-secondary">Deactivate</button>';
                        } else {
                            echo '<button type="submit" name="activate_link" class="btn-primary">Activate</button>';
                        }
                        echo '<button type="submit" name="delete_link" class="btn-danger" onclick="return confirm(\'Are you sure you want to delete this link?\')">Delete</button>';
                        echo '</form>';
                        echo '</td>';
                        echo '</tr>';
                    }

                    echo '</tbody>';
                    echo '</table>';
                }
                ?>
            </div>
        </div>
    </div>

    <script>
        // Live preview of link URL
        document.getElementById('link_name').addEventListener('input', function() {
            const linkName = this.value.trim();
            const previewElement = document.getElementById('preview-name');
            if (linkName) {
                previewElement.textContent = linkName.toLowerCase().replace(/[^a-zA-Z0-9_-]/g, '');
            } else {
                previewElement.textContent = 'linkname';
            }
        });
    </script>
</body>
</html>