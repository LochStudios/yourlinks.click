<?php
$v = filemtime(__DIR__ . '/css/site.css');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
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
    <!-- Theme bootstrap: apply saved/OS theme before first paint -->
    <script>
        (function () {
            try {
                var t = localStorage.getItem('sp-theme');
                if (!t) t = (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
                if (t !== 'light') t = 'dark';
                document.documentElement.setAttribute('data-theme', t);
                document.documentElement.className = (t === 'light' ? 'light-theme' : 'dark-theme');
            } catch (e) {}
        })();
    </script>
</head>
<body>
    <!-- Top Nav -->
    <nav class="db-topnav">
        <a href="/" class="db-topnav-brand">
            <i class="fas fa-link" style="color: var(--accent-hover);"></i>
            YourLinks.click
        </a>
        <button class="sp-theme-toggle" id="spThemeToggle" type="button" aria-label="Toggle light or dark theme" title="Toggle theme">
            <i class="fa-solid fa-moon" id="spThemeIcon"></i>
        </button>
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
    <script>
        // Light/dark theme toggle
        (function () {
            var btn = document.getElementById('spThemeToggle');
            if (!btn) return;
            function current() { return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark'; }
            function syncIcon(theme) {
                var icon = document.getElementById('spThemeIcon');
                if (icon) icon.className = (theme === 'light' ? 'fa-solid fa-sun' : 'fa-solid fa-moon');
            }
            function apply(theme, persist) {
                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.className = (theme === 'light' ? 'light-theme' : 'dark-theme');
                syncIcon(theme);
                if (persist) { try { localStorage.setItem('sp-theme', theme); } catch (e) {} }
            }
            syncIcon(current());
            btn.addEventListener('click', function () { apply(current() === 'light' ? 'dark' : 'light', true); });
            window.addEventListener('storage', function (e) {
                if (e.key === 'sp-theme' && (e.newValue === 'light' || e.newValue === 'dark')) { apply(e.newValue, false); }
            });
        })();
    </script>
</body>
</html>