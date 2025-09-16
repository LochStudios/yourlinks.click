# YourLinks.click - Config File Templates

## Database Configuration Template
# Copy this to /var/www/config/database.php

```php
<?php
// Database Configuration for YourLinks.click
// Location: /var/www/config/database.php

$db_servername = 'localhost';           // Database server hostname
$db_username = 'yourlinks_user';      // Database username
$db_password = 'your_secure_password'; // Database password

// Note: This file should be outside the web root for security
// Set permissions to 600 (readable/writable by owner only)
// chown www-data:www-data /var/www/config/database.php
// chmod 600 /var/www/config/database.php
?>
```

## Twitch OAuth Configuration Template
# Copy this to /var/www/config/twitch.php

```php
<?php
// Twitch OAuth Configuration for YourLinks.click
// Location: /var/www/config/twitch.php

$twitch_client_id = 'your_twitch_client_id_here';
$twitch_client_secret = 'your_twitch_client_secret_here';
$twitch_redirect_uri = 'https://yourlinks.click/services/twitch.php';

// Get these values from: https://dev.twitch.tv/console/apps
// Make sure the redirect URI matches exactly

// Note: This file should be outside the web root for security
// Set permissions to 600 (readable/writable by owner only)
// chown www-data:www-data /var/www/config/twitch.php
// chmod 600 /var/www/config/twitch.php
?>
```

## Server Setup Commands

```bash
# Create config directory
sudo mkdir -p /var/www/config

# Set proper ownership (adjust for your web server user)
sudo chown www-data:www-data /var/www/config

# Set directory permissions
sudo chmod 755 /var/www/config

# Create config files
sudo touch /var/www/config/database.php
sudo touch /var/www/config/twitch.php

# Set file permissions (600 = owner read/write only)
sudo chmod 600 /var/www/config/*.php

# Edit config files with your credentials
sudo nano /var/www/config/database.php
sudo nano /var/www/config/twitch.php
```