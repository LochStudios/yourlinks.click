<?php
$v = filemtime(__DIR__ . '/css/site.css');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YourLinks.click - Link Management Service</title>
    <link rel="icon" type="image/png" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
    <link rel="icon" type="image/png" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <!-- Site CSS -->
    <link rel="stylesheet" href="/css/site.css?v=<?php echo $v; ?>">
</head>
<body>
    <!-- Top Nav -->
    <nav class="db-topnav">
        <a href="/" class="db-topnav-brand">
            <i class="fas fa-link" style="color: var(--accent-hover);"></i>
            YourLinks.click
        </a>
    </nav>
    <!-- Hero -->
    <div class="db-hero">
        <h1><i class="fas fa-link" style="color: var(--accent-hover);"></i> Welcome to YourLinks.click</h1>
        <p class="db-hero-sub">A powerful link management service for streamers and creators.</p>
        <p class="db-hero-desc">Organize, track, and share your links — all from one personalized dashboard.</p>
    </div>
    <!-- Features -->
    <div class="db-landing-section">
        <div class="db-landing-section-header">
            <h2><i class="fas fa-star" style="color: var(--amber);"></i> Features</h2>
            <p>Everything you need to manage your online presence.</p>
        </div>
        <div class="db-features-grid">
            <div class="db-feature-card">
                <div class="db-feature-card-icon" style="color: var(--blue);">
                    <i class="fas fa-magic"></i>
                </div>
                <h4>Short, Memorable Links</h4>
                <p>Create clean, branded links at <em>yourname.yourlinks.click/linkname</em></p>
            </div>
            <div class="db-feature-card">
                <div class="db-feature-card-icon" style="color: var(--green);">
                    <i class="fas fa-chart-line"></i>
                </div>
                <h4>Click Analytics</h4>
                <p>Track clicks and monitor the performance of every link you create.</p>
            </div>
            <div class="db-feature-card">
                <div class="db-feature-card-icon" style="color: var(--amber);">
                    <i class="fas fa-folder"></i>
                </div>
                <h4>Link Categories</h4>
                <p>Organize your links into color-coded categories for easy management.</p>
            </div>
            <div class="db-feature-card">
                <div class="db-feature-card-icon" style="color: var(--red);">
                    <i class="fas fa-clock"></i>
                </div>
                <h4>Expiration Dates</h4>
                <p>Set links to expire automatically — perfect for limited-time promotions.</p>
            </div>
        </div>
        <!-- Auth notice -->
        <div class="sp-alert sp-alert-info" style="max-width: 640px; margin: 1.5rem auto 2rem;">
            <i class="fas fa-shield-alt"></i>
            <strong>Secure authentication via Twitch.</strong>
            Log in with your existing Twitch account — no new password to remember.
        </div>
        <!-- Login card -->
        <div class="db-login-card">
            <h3>Get Started</h3>
            <p>Sign in with your Twitch account to access your personalized link dashboard.</p>
            <a href="https://streamersconnect.com/?service=twitch&login=yourlinks.click&scopes=user:read:email&return_url=https://yourlinks.click/callback.php" class="db-twitch-btn">
                <i class="fab fa-twitch"></i>
                Login with Twitch
            </a>
            <p class="db-login-note">By logging in you agree to our use of cookies for session management.</p>
        </div>
    </div>
    <!-- Footer -->
    <footer class="db-landing-footer">
        &copy; <?php echo date('Y'); ?> YourLinks.click &mdash; A BotOfTheSpecter service
    </footer>
</body>
</html>