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
3. Set OAuth Redirect URLs to: `http://yourlinks.click/services/twitch.php`
4. Copy your Client ID and Client Secret

### 4. Configuration

Update the following files with your credentials:

**services/database.php**:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'yourlinks_db');
define('DB_USER', 'yourlinks_user');
define('DB_PASS', 'your_password_here');
```

**services/twitch.php**:
```php
define('TWITCH_CLIENT_ID', 'your_twitch_client_id_here');
define('TWITCH_CLIENT_SECRET', 'your_twitch_client_secret_here');
define('TWITCH_REDIRECT_URI', 'http://yourlinks.click/services/twitch.php');
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
├── css/
│   └── site.css          # Main stylesheet
├── services/
│   ├── database.php      # MySQL database connection class
│   └── twitch.php        # Twitch OAuth authentication
├── database_schema.sql   # Database setup script
└── README.md             # This file
```

## Security Notes

- Change default database passwords
- Use HTTPS in production
- Regularly update PHP and dependencies
- Implement rate limiting for link creation
- Add CSRF protection for forms (basic implementation included)

## Development

The application uses:
- PHP with PDO for database interactions
- Vanilla JavaScript for frontend interactions
- CSS for styling
- MySQL for data storage

## License

See LICENSE file for details.