# YourLinks.click - Config File Templates

## Database Configuration Template
# Copy this to /home/yourlink/webconfig/database.php

```php
<?php
// Database Configuration for YourLinks.click
// Location: /home/yourlink/webconfig/database.php

$DB_HOST = 'localhost';           // Database server hostname
$DB_NAME = 'yourlinks_db';        // Database name
$DB_USER = 'yourlinks_user';      // Database username
$DB_PASS = 'your_secure_password'; // Database password

// Note: This file should be outside the web root for security
// Set permissions to 600 (readable/writable by owner only)
// chown www-data:www-data /home/yourlink/webconfig/database.php
// chmod 600 /home/yourlink/webconfig/database.php
?>
```

## Twitch OAuth Configuration Template
# Copy this to /home/yourlink/webconfig/twitch.php

```php
<?php
// Twitch OAuth Configuration for YourLinks.click
// Location: /home/yourlink/webconfig/twitch.php

$CLIENT_ID = 'your_twitch_client_id_here';
$CLIENT_SECRET = 'your_twitch_client_secret_here';
$REDIRECT_URI = 'https://yourlinks.click/services/twitch.php';

// Get these values from: https://dev.twitch.tv/console/apps
// Make sure the redirect URI matches exactly

// Note: This file should be outside the web root for security
// Set permissions to 600 (readable/writable by owner only)
// chown www-data:www-data /home/yourlink/webconfig/twitch.php
// chmod 600 /home/yourlink/webconfig/twitch.php
?>
```

## Server Setup Commands

```bash
# Create config directory
sudo mkdir -p /home/yourlink/webconfig

# Set proper ownership (adjust for your web server user)
sudo chown www-data:www-data /home/yourlink/webconfig

# Set directory permissions
sudo chmod 755 /home/yourlink/webconfig

# Create config files
sudo touch /home/yourlink/webconfig/database.php
sudo touch /home/yourlink/webconfig/twitch.php

# Set file permissions (600 = owner read/write only)
sudo chmod 600 /home/yourlink/webconfig/*.php

# Edit config files with your credentials
sudo nano /home/yourlink/webconfig/database.php
sudo nano /home/yourlink/webconfig/twitch.php
```