# YourLinks.click - Link Management Service

A PHP-based link shortening and management service with Twitch OAuth authentication.

## Features

- **Twitch OAuth Login**: Secure authentication using Twitch accounts
- **Custom Subdomains**: Each user gets their own subdomain (username.yourlinks.click)
- **Custom Domains**: Users can use their own domains (mydomain.com/link)
- **Link Management**: Create and manage personalized links through dashboard
- **Link Categories**: Organize links into custom categories with colors and icons
- **Link Expiration**: Set expiration dates for links with custom behaviors
- **Analytics**: Track clicks and performance metrics
- **User Dashboard**: Manage your links in a clean interface
- **MySQL Database**: Robust data storage with proper relationships
- **Dark Mode**: Modern dark theme enabled by default for better user experience

## How It Works

1. **User Registration**: Users log in with Twitch OAuth
2. **Subdomain Creation**: Each user gets username.yourlinks.click
3. **Link Creation**: Users create links through their dashboard
4. **Link Access**: Visitors access links via username.yourlinks.click/linkname
5. **Redirection**: System redirects to the destination URL and tracks clicks

### Example Usage

- User "exampleuser" creates a link named "youtube" pointing to "https://youtube.com/"
- The link becomes: `https://exampleuser.yourlinks.click/youtube`
- With custom domain: `https://mydomain.com/youtube`
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

5. **Custom Domain Setup** (Optional):
   - Users can add their own domains in the dashboard
   - Domain ownership is verified via DNS TXT records
   - SSL certificates are automatically managed for custom domains
   - **Domain Configuration**: Users must point their domain to your server

### Hosting Requirements

This service can be deployed on shared hosting (cPanel) or VPS. The application handles both wildcard subdomains and custom domains through DNS configuration.

#### **Shared Hosting (cPanel)**
- ✅ **Wildcard subdomains**: `*.yourlinks.click` routing
- ✅ **Custom domains**: Via DNS pointing (no addon domains required)
- ✅ **SSL certificates**: Provided by hosting provider
- ✅ **Database**: MySQL included with hosting

#### **VPS Hosting**
- ✅ **Full server control**: Custom configurations
- ✅ **Higher performance**: For increased traffic
- ✅ **Custom software**: Advanced server setups

### Custom Domain Setup

Users can configure custom domains by updating DNS records at their registrar:

#### **Required DNS Records**
```
Type: A
Name: @ (or yourdomain.com)
Value: [server-ip-address]
TTL: 300

Type: TXT
Name: _yourlinks_verification.yourdomain.com
Value: [verification-token-from-dashboard]
TTL: 300
```

#### **Setup Process**
1. **Access domain registrar** (GoDaddy, Namecheap, etc.)
2. **Navigate to DNS settings** for the domain
3. **Add A record**: Point domain to server IP address
4. **Add TXT record**: For domain ownership verification
5. **Wait for DNS propagation** (5-30 minutes)
6. **Verify domain** through the dashboard
7. **Use custom domain** for links

#### **Domain Verification**
- **Automated verification**: System checks DNS TXT records
- **SSL provisioning**: Verified domains receive SSL certificates
- **Security**: Prevents unauthorized domain usage

### Server Configuration

Configure your hosting environment for optimal performance:

#### **Wildcard Subdomain Setup**
- **Create wildcard subdomain**: `*.yourlinks.click`
- **Document root**: Point to `public_html` directory
- **Purpose**: Enables `user.yourlinks.click` URLs

#### **SSL Certificate**
- **Install wildcard SSL**: For `*.yourlinks.click`
- **Provider**: Usually provided by hosting company
- **Coverage**: Includes custom domains

#### **PHP Configuration**
- **Version**: PHP 8.1 or higher recommended
- **Extensions**: Enable PDO, MySQLi, cURL
- **Settings**: Ensure `allow_url_fopen` is enabled

#### **Database Setup**
- **Create database**: MySQL database for the service
- **Import schema**: Use provided `database.sql` file
- **User permissions**: Grant necessary privileges

### API Endpoints

#### Domain Verification
```
GET /services/verify_domain.php?domain=example.com&token=verification_token
```
- **Purpose**: Verifies domain ownership via DNS TXT records
- **Parameters**: 
  - `domain`: The domain to verify
  - `token`: Verification token from user's dashboard
- **Response**: JSON with verification status

### Server Requirements

The server must be configured to handle custom domains:

1. **Wildcard SSL**: SSL certificate covering `*.yourlinks.click`
2. **Apache Configuration**: `.htaccess` handles domain routing
3. **PHP Settings**: `allow_url_fopen` enabled for DNS verification
4. **File Permissions**: Proper permissions for web server access

### 7. Access the Application

Open your browser and navigate to `http://yourlinks.click`

### 8. Dark Mode Testing

The application uses dark mode by default. To test the dark mode implementation:

1. **Open the test page**: Navigate to `http://yourlinks.click/darkmode-test.html`
2. **Test components**: Verify that all UI elements display correctly with dark backgrounds
3. **Check contrast**: Ensure text is readable against dark backgrounds
4. **Test interactions**: Verify buttons, forms, and alerts work properly in dark mode

The dark mode implementation includes:
- Dark hero backgrounds with gradients
- Dark card and box backgrounds
- Light text on dark backgrounds
- Dark-themed form inputs and controls
- Dark table headers with light content
- Dark-themed SweetAlert2 popups

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
│   ├── twitch.php        # Twitch OAuth authentication
│   └── verify_domain.php # Domain verification service
├── config/               # Configuration files (not in repo)
│   ├── database.php      # Database credentials
│   └── twitch.php        # Twitch OAuth credentials
└── README.md             # This file
```

## Development

The application uses:
- **Backend**: PHP with PDO for database interactions
- **Frontend**:
  - [Bulma CSS 1.0.4](https://bulma.io/) - Modern CSS framework with dark mode support
  - [Font Awesome 6.4.0](https://fontawesome.com/) - Icon library
  - [SweetAlert2](https://sweetalert2.github.io/) - Beautiful alert dialogs with dark theme
- **Database**: MySQL for data storage
- **Architecture**: MVC pattern with secure configuration management
- **Theme**: Dark mode enabled by default for modern user experience

## Security Notes

- **Sensitive Configuration**: Database and OAuth credentials stored outside web root
- **File Permissions**: Restrict config file permissions (600)
- **HTTPS Only**: Use HTTPS in production to protect OAuth tokens and user data
- **Database Security**: Use strong passwords and limit database user privileges
- **Regular Updates**: Keep PHP, MySQL, and dependencies updated
- **Rate Limiting**: Implement rate limiting for link creation and API calls
- **CSRF Protection**: Basic CSRF protection for forms
- **Input Validation**: All user inputs are validated and sanitized

## Deployment Checklist

- [ ] Create configuration directory outside web root
- [ ] Set up database credentials in config files
- [ ] Configure Twitch OAuth credentials
- [ ] Set proper file permissions (755 for directories, 644 for files, 600 for config)
- [ ] Configure wildcard subdomains
- [ ] Set up SSL certificates
- [ ] Test the application functionality
- [ ] Verify .gitignore excludes sensitive files

## Database Migration

If you're adding custom domain support to an existing installation, run the migration:

```sql
-- Run this SQL to add custom domain columns
source custom_domain_migration.sql;
```

This adds the necessary columns for custom domain functionality.

### Categories Feature Migration

To add the link categories feature to an existing installation:

```sql
-- Run this SQL to add categories support
source database_categories_migration.sql;
```

This creates the categories table and adds the category_id column to the links table.

### Expiration Feature Migration

To add the link expiration feature to an existing installation:

```sql
-- Run this SQL to add expiration support
source database_categories_migration.sql;
```

This adds expiration columns to the links table:
- `expires_at`: DATETIME for expiration timestamp
- `expired_redirect_url`: TEXT for custom redirect URL
- `expiration_behavior`: ENUM for behavior type

## Link Categories Feature

The application includes a comprehensive link categorization system:

### **Category Management**
- **Create Categories**: Users can create custom categories with names, descriptions, colors, and icons
- **Default Categories**: System automatically creates starter categories:
  - Social Media (Twitter icon, blue)
  - Gaming (Gamepad icon, purple)
  - Music (Music icon, pink)
  - Videos (YouTube icon, red)
  - Other (Link icon, grey)
- **Visual Design**: Each category has a custom color and FontAwesome icon
- **Category Cards**: Categories are displayed as cards showing link count and management options

### **Link Organization**
- **Category Assignment**: When creating links, users can select from their categories
- **Visual Tags**: Links display category tags with custom colors and icons in the table
- **Search Integration**: Search functionality includes category names
- **No Category Option**: Links can exist without a category assignment

### **Category Operations**
- **Create**: Add new categories with custom styling
- **Read**: View all categories with link counts
- **Update**: Modify category properties (planned feature)
- **Delete**: Remove categories (with protection for categories containing links)

### **Database Schema**
```sql
-- Categories table
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    color VARCHAR(7) DEFAULT '#3273dc',
    icon VARCHAR(50) DEFAULT 'fas fa-tag',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Links table extension
ALTER TABLE links ADD COLUMN category_id INT NULL;
ALTER TABLE links ADD CONSTRAINT fk_links_category_id
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL;
```

## Link Expiration Feature

The application includes a comprehensive link expiration system that allows users to set time-based expiration for their links:

### **Expiration Options**
- **No Expiration**: Links remain active indefinitely (default)
- **Custom Date/Time**: Set specific expiration date and time
- **Expiration Behaviors**:
  - **Inactive**: Link becomes inactive (404 error)
  - **Redirect**: Redirect to a custom URL when expired
  - **Custom Page**: Show a custom expired page (planned feature)

### **Expiration Management**
- **Date Picker**: User-friendly datetime picker for setting expiration
- **Validation**: Prevents setting expiration dates in the past
- **Visual Indicators**: 
  - Green tags for active links with future expiration
  - Yellow warning tags for links expiring within 7 days
  - Red danger tags for expired links
- **Dashboard Display**: Expiration status shown in links table

### **Expiration Behaviors**
- **Inactive Links**: Expired links return 404 errors
- **Redirect Links**: Expired links redirect to user-specified URLs
- **Custom Pages**: Future feature for branded expired link pages

### **Database Schema**
```sql
-- Links table expiration columns
ALTER TABLE links ADD COLUMN expires_at DATETIME NULL;
ALTER TABLE links ADD COLUMN expired_redirect_url TEXT NULL;
ALTER TABLE links ADD COLUMN expiration_behavior ENUM('inactive', 'redirect', 'custom_page') DEFAULT 'inactive';

-- Index for performance
CREATE INDEX idx_links_expires_at ON links(expires_at);
```

### **Usage Examples**
- **Temporary Promotions**: Set expiration for limited-time offers
- **Event Links**: Links that expire after an event ends
- **Seasonal Content**: Links that automatically deactivate after a season
- **Time-Sensitive Information**: Links that should not be accessible after a certain date

### **Testing the Feature**
A test script is included to verify expiration functionality:

```bash
# Access the test page
https://yourlinks.click/test_expiration.php
```

The test page allows you to:
- Create test links with different expiration behaviors
- Monitor expiration status in real-time
- Test expired link handling
- Clean up test data