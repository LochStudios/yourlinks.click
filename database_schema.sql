-- YourLinks.click Database Schema
-- MySQL 5.7+ compatible
-- Run this script to create the complete database structure

-- Create database (uncomment if needed)
-- CREATE DATABASE IF NOT EXISTS yourlinks CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE yourlinks;

-- ===========================================
-- USERS TABLE
-- ===========================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    twitch_id VARCHAR(50) NOT NULL UNIQUE,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    profile_image_url TEXT,
    custom_domain VARCHAR(255) NULL UNIQUE,
    domain_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_twitch_id (twitch_id),
    INDEX idx_username (username),
    INDEX idx_custom_domain (custom_domain),
    INDEX idx_domain_verified (domain_verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================
-- CATEGORIES TABLE
-- ===========================================
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    color VARCHAR(7) DEFAULT '#3273dc',
    icon VARCHAR(50) DEFAULT 'fas fa-tag',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_name (name),
    UNIQUE KEY unique_user_category (user_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================
-- LINKS TABLE
-- ===========================================
CREATE TABLE links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    link_name VARCHAR(100) NOT NULL,
    original_url TEXT NOT NULL,
    title VARCHAR(255),
    category_id INT NULL,
    expires_at DATETIME NULL,
    expired_redirect_url TEXT NULL,
    expiration_behavior ENUM('inactive', 'redirect', 'custom_page') DEFAULT 'inactive',
    expired_page_title VARCHAR(255) DEFAULT 'Link Expired',
    expired_page_message TEXT DEFAULT 'This link has expired and is no longer available.',
    deactivation_behavior ENUM('inactive', 'redirect', 'custom_page') DEFAULT 'inactive',
    deactivated_redirect_url TEXT NULL,
    deactivated_page_title VARCHAR(255) DEFAULT 'Link Deactivated',
    deactivated_page_message TEXT DEFAULT 'This link has been deactivated and is no longer available.',
    is_active BOOLEAN DEFAULT TRUE,
    clicks INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_category_id (category_id),
    INDEX idx_link_name (link_name),
    INDEX idx_expires_at (expires_at),
    INDEX idx_is_active (is_active),
    UNIQUE KEY unique_user_link (user_id, link_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================
-- LINK CLICKS TABLE
-- ===========================================
CREATE TABLE link_clicks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    link_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    referrer TEXT,
    is_expired BOOLEAN DEFAULT FALSE,
    is_deactivated BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE,
    INDEX idx_link_id (link_id),
    INDEX idx_created_at (created_at),
    INDEX idx_ip_address (ip_address),
    INDEX idx_is_expired (is_expired),
    INDEX idx_is_deactivated (is_deactivated)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================
-- DEFAULT DATA
-- ===========================================

-- Insert default categories for new users (optional - handled by application)
-- These will be created automatically when users first access the dashboard

-- ===========================================
-- USEFUL QUERIES
-- ===========================================

-- Get user statistics
-- SELECT
--     u.username,
--     COUNT(DISTINCT l.id) as total_links,
--     COUNT(DISTINCT lc.id) as total_clicks,
--     SUM(l.clicks) as total_clicks_sum
-- FROM users u
-- LEFT JOIN links l ON u.id = l.user_id
-- LEFT JOIN link_clicks lc ON l.id = lc.link_id
-- GROUP BY u.id, u.username;

-- Get link performance
-- SELECT
--     l.link_name,
--     l.original_url,
--     l.clicks as direct_clicks,
--     COUNT(lc.id) as detailed_clicks,
--     l.created_at,
--     l.expires_at
-- FROM links l
-- LEFT JOIN link_clicks lc ON l.id = lc.link_id
-- WHERE l.user_id = ?
-- GROUP BY l.id;

-- Clean up expired links (optional maintenance)
-- UPDATE links SET is_active = FALSE WHERE expires_at < NOW() AND expires_at IS NOT NULL;

-- Database optimization (run periodically)
-- ANALYZE TABLE users, categories, links, link_clicks;
-- OPTIMIZE TABLE users, categories, links, link_clicks;