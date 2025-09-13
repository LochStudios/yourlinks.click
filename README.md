# YourLinks.click - Link Management Service

A PHP-based link shortening and management service with Twitch OAuth authentication.

## Features

- **Twitch OAuth Login**: Secure authentication using Twitch accounts
- **Custom Subdomains**: Each user gets their own subdomain (username.yourlinks.click)
- **Link Management**: Create and manage personalized links through dashboard
- **Analytics**: Track clicks and performance metrics
- **User Dashboard**: Manage your links in a clean interface
- **MySQL Database**: Robust data storage with proper relationships

## How It Works

1. **User Registration**: Users log in with Twitch OAuth
2. **Subdomain Creation**: Each user gets username.yourlinks.click
3. **Link Creation**: Users create links through their dashboard
4. **Link Access**: Visitors access links via username.yourlinks.click/linkname
5. **Redirection**: System redirects to the destination URL and tracks clicks

### Example Usage

- User "exampleuser" creates a link named "youtube" pointing to "https://youtube.com/"
- The link becomes: `https://exampleuser.yourlinks.click/youtube`
- When clicked, visitors are redirected to the YouTube channel

## Setup Instructions

### 1. Prerequisites

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache/Nginx) with URL rewriting enabled
- Composer (optional, for dependency management)

### 2. Database Setup

1. Create a MySQL database and user:
```sql
CREATE DATABASE yourlinks_db;
CREATE USER 'yourlinks_user'@'localhost' IDENTIFIED BY 'your_password_here';
GRANT ALL PRIVILEGES ON yourlinks_db.* TO 'yourlinks_user'@'localhost';
FLUSH PRIVILEGES;
```

2. Import the database schema:
```bash
mysql -u yourlinks_user -p yourlinks_db < database_schema.sql
```

### 3. Twitch OAuth Setup

1. Go to [Twitch Developer Console](https://dev.twitch.tv/console/apps)
2. Create a new application
3. Set OAuth Redirect URLs to: `https://yourlinks.click/services/twitch.php`
4. Copy your Client ID and Client Secret

### 4. Configuration Setup

Create the configuration directory structure and files:

```bash
# Create config directory (on your server)
mkdir -p /home/yourlink/webconfig

# Create config files
touch /home/yourlink/webconfig/twitch.php
touch /home/yourlink/webconfig/database.php
```

**Update `/home/yourlink/webconfig/database.php`**:
```php
<?php
$DB_HOST = 'localhost';
$DB_NAME = 'yourlinks_db';
$DB_USER = 'yourlinks_user';
$DB_PASS = 'your_actual_password_here';
?>
```

**Update `/home/yourlink/webconfig/twitch.php`**:
```php
<?php
$CLIENT_ID = 'your_twitch_client_id_here';
$CLIENT_SECRET = 'your_twitch_client_secret_here';
$REDIRECT_URI = 'https://yourlinks.click/services/twitch.php';
?>
```

### 5. Web Server Configuration

Make sure your web server serves PHP files and has URL rewriting enabled.

**Apache (.htaccess)**:
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . index.php [L]
```

**Nginx**:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 6. cPanel Wildcard Subdomain Setup

For the subdomain system to work, you need to set up wildcard subdomains in cPanel:

1. **Create Wildcard Subdomain**:
   - Go to cPanel → Subdomains
   - Create a subdomain: `*.yourlinks.click`
   - Point it to the same document root as your main domain

2. **DNS Configuration**:
   - Add a wildcard DNS record: `*.yourlinks.click A [your-server-IP]`
   - Or CNAME record: `*.yourlinks.click CNAME yourlinks.click`

3. **Apache Configuration** (if needed)**:
   - The included `.htaccess` file handles wildcard routing
   - Make sure `mod_rewrite` is enabled

4. **SSL Certificate**:
   - Get a wildcard SSL certificate for `*.yourlinks.click`
   - Or use Let's Encrypt with DNS challenge for wildcard certificates

Ensure the web server can write to necessary directories (if any):
```bash
chmod 755 /path/to/yourlinks.click
```

### 7. Access the Application

Open your browser and navigate to `http://yourlinks.click`

## Project Structure

```
yourlinks.click/
├── index.php              # Home page with service explanation
├── dashboard.php          # User dashboard with link management
├── redirect.php           # Handles subdomain redirects
├── .htaccess             # Apache configuration for wildcards
├── .gitignore            # Git ignore rules for sensitive files
├── css/
│   └── site.css          # Main stylesheet
├── services/
│   ├── database.php      # MySQL database connection class
│   └── twitch.php        # Twitch OAuth authentication
├── home/yourlink/webconfig/  # Sensitive configuration files (not in repo)
│   ├── database.php      # Database credentials
│   └── twitch.php        # Twitch OAuth credentials
└── README.md             # This file
```

## Security Notes

- **Sensitive Configuration**: Database and OAuth credentials are stored in `/home/yourlink/webconfig/` outside the web root for security
- **File Permissions**: Ensure config files have restricted permissions (600) and are owned by the web server user
- **HTTPS Only**: Use HTTPS in production to protect OAuth tokens and user data
- **Database Security**: Use strong passwords and limit database user privileges
- **Regular Updates**: Keep PHP, MySQL, and dependencies updated
- **Rate Limiting**: Implement rate limiting for link creation and API calls
- **CSRF Protection**: The application includes basic CSRF protection for forms
- **Input Validation**: All user inputs are validated and sanitized

## Deployment Checklist

- [ ] Create `/home/yourlink/webconfig/` directory
- [ ] Set up database credentials in config files
- [ ] Configure Twitch OAuth credentials
- [ ] Set proper file permissions (755 for directories, 644 for files, 600 for config)
- [ ] Configure wildcard subdomains in cPanel
- [ ] Set up SSL certificates
- [ ] Test the application functionality
- [ ] Verify .gitignore excludes sensitive files