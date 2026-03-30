<?php
// Public profile page — shown at username.yourlinks.click/
// $subdomain is set by redirect.php before requiring this file.
// If accessed directly (shouldn't happen), derive it from the host.

if (!isset($subdomain)) {
    $parts = explode('.', $_SERVER['HTTP_HOST'] ?? '');
    $subdomain = $parts[0] ?? '';
    if ($subdomain === 'yourlinks' || $subdomain === 'www' || empty($subdomain)) {
        header('Location: /');
        exit();
    }
    require_once __DIR__ . '/services/database.php';
}

$db = Database::getInstance();

// Ensure profile tables exist (idempotent — safe to call every request)
$db->query("CREATE TABLE IF NOT EXISTS profile_settings (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL UNIQUE,
    bio          VARCHAR(500)  DEFAULT NULL,
    page_title   VARCHAR(100)  DEFAULT NULL,
    accent_color VARCHAR(7)    NOT NULL DEFAULT '#7c5cbf',
    show_profile_pic TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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

// Fetch user
$userRow = $db->select("SELECT * FROM users WHERE username = ?", [$subdomain]);
if (empty($userRow)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Not Found</title></head><body style="text-align:center;padding:4rem;font-family:sans-serif;background:#0d0d0f;color:#e8e8f0;"><h2>User not found</h2><a href="https://yourlinks.click" style="color:#9070d8;">Back to YourLinks.click</a></body></html>';
    exit();
}
$userRow = $userRow[0];

// Fetch profile settings
$settings = $db->select("SELECT * FROM profile_settings WHERE user_id = ?", [$userRow['id']]);
$settings = !empty($settings) ? $settings[0] : [];

$pageTitle   = !empty($settings['page_title'])   ? $settings['page_title']   : ($userRow['display_name'] ?: $userRow['username']);
$accentColor = !empty($settings['accent_color']) ? $settings['accent_color'] : '#7c5cbf';
$bio         = $settings['bio'] ?? '';
$showPic     = isset($settings['show_profile_pic']) ? (bool)$settings['show_profile_pic'] : true;
$profilePic  = $userRow['profile_image_url'] ?? '';

// Fetch active profile links
$profileLinks = $db->select(
    "SELECT * FROM profile_links WHERE user_id = ? AND is_active = 1 ORDER BY display_order ASC, id ASC",
    [$userRow['id']]
);

// Platform metadata
$platforms = [
    'twitch'    => ['icon' => 'fab fa-twitch',    'color' => '#6441a5'],
    'youtube'   => ['icon' => 'fab fa-youtube',   'color' => '#ff0000'],
    'twitter'   => ['icon' => 'fab fa-x-twitter', 'color' => '#e7e7e7'],
    'instagram' => ['icon' => 'fab fa-instagram', 'color' => '#e1306c'],
    'discord'   => ['icon' => 'fab fa-discord',   'color' => '#5865f2'],
    'tiktok'    => ['icon' => 'fab fa-tiktok',    'color' => '#e7e7e7'],
    'facebook'  => ['icon' => 'fab fa-facebook',  'color' => '#1877f2'],
    'linkedin'  => ['icon' => 'fab fa-linkedin',  'color' => '#0077b5'],
    'spotify'   => ['icon' => 'fab fa-spotify',   'color' => '#1db954'],
    'github'    => ['icon' => 'fab fa-github',    'color' => '#e7e7e7'],
    'custom'    => ['icon' => 'fas fa-link',      'color' => $accentColor],
];

$accentSafe = htmlspecialchars($accentColor);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> — YourLinks.click</title>
    <link rel="icon" type="image/png" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
    <link rel="icon" type="image/png" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <meta name="description" content="<?php echo htmlspecialchars($bio ?: $pageTitle . '\'s links'); ?>">
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <style>
        :root { --accent: <?php echo $accentSafe; ?>; }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            background: #0d0d0f;
            color: #e8e8f0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .profile-wrap {
            width: 100%;
            max-width: 520px;
            padding: 3rem 1.25rem 4rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }
        .profile-avatar {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent);
            box-shadow: 0 0 0 5px <?php echo $accentSafe; ?>33;
        }
        .profile-name {
            font-size: 1.5rem;
            font-weight: 700;
            text-align: center;
        }
        .profile-handle {
            font-size: 0.875rem;
            color: #a8a8bc;
            margin-top: -0.35rem;
        }
        .profile-bio {
            font-size: 0.95rem;
            color: #a8a8bc;
            text-align: center;
            line-height: 1.65;
            max-width: 420px;
        }
        .profile-links {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 0.5rem;
        }
        .profile-btn {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            width: 100%;
            padding: 0.8rem 1.1rem;
            border-radius: 12px;
            background: #1a1a20;
            border: 1px solid rgba(255,255,255,0.07);
            color: #e8e8f0;
            text-decoration: none;
            font-size: 0.975rem;
            font-weight: 600;
            transition: background 0.15s, border-color 0.15s, transform 0.1s;
        }
        .profile-btn:hover {
            background: #1f1f28;
            border-color: var(--accent);
            color: #fff;
            transform: translateY(-1px);
        }
        .profile-btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .profile-btn-label { flex: 1; text-align: center; }
        .profile-btn-arrow { font-size: 0.7rem; color: #6c6c84; flex-shrink: 0; }
        .profile-empty {
            text-align: center;
            color: #6c6c84;
            font-size: 0.9rem;
            padding: 2rem 0;
        }
        .profile-footer {
            margin-top: 2rem;
            font-size: 0.8rem;
            color: #6c6c84;
        }
        .profile-footer a { color: var(--accent); text-decoration: none; }
        .profile-footer a:hover { color: #e8e8f0; }
    </style>
</head>
<body>
    <div class="profile-wrap">
        <?php if ($showPic && $profilePic): ?>
        <img class="profile-avatar"
             src="<?php echo htmlspecialchars($profilePic); ?>"
             alt="<?php echo htmlspecialchars($pageTitle); ?>">
        <?php endif; ?>

        <h1 class="profile-name"><?php echo htmlspecialchars($pageTitle); ?></h1>
        <p class="profile-handle">@<?php echo htmlspecialchars($userRow['username']); ?></p>

        <?php if ($bio): ?>
        <p class="profile-bio"><?php echo nl2br(htmlspecialchars($bio)); ?></p>
        <?php endif; ?>

        <div class="profile-links">
            <?php if (empty($profileLinks)): ?>
            <p class="profile-empty">
                <i class="fas fa-link" style="margin-right:0.4rem;"></i>No links added yet.
            </p>
            <?php else: ?>
            <?php foreach ($profileLinks as $pl):
                $pInfo     = $platforms[$pl['platform']] ?? $platforms['custom'];
                $iconColor = $pl['platform'] === 'custom' ? $accentColor : $pInfo['color'];
            ?>
            <a href="<?php echo htmlspecialchars($pl['url']); ?>"
               class="profile-btn"
               target="_blank" rel="noopener noreferrer">
                <span class="profile-btn-icon"
                      style="background:<?php echo htmlspecialchars($iconColor); ?>22; color:<?php echo htmlspecialchars($iconColor); ?>;">
                    <i class="<?php echo htmlspecialchars($pInfo['icon']); ?>"></i>
                </span>
                <span class="profile-btn-label"><?php echo htmlspecialchars($pl['title']); ?></span>
                <span class="profile-btn-arrow"><i class="fas fa-chevron-right"></i></span>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <p class="profile-footer">
            Powered by <a href="https://yourlinks.click" target="_blank">YourLinks.click</a>
        </p>
    </div>
</body>
</html>
