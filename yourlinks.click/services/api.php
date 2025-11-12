<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Database configuration
require_once '/var/www/config/database.php';

// Define constants for both databases
define('DB_HOST', $db_servername);
define('DB_USER', $db_username);
define('DB_PASS', $db_password);
define('DB_YOURLINKS', 'yourlinks');
define('DB_WEBSITE', 'website'); // The website database that stores API keys

class ApiResponse {
    public static function success($data = [], $message = 'Success') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
        exit();
    }
    public static function error($message = 'An error occurred', $code = 400) {
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit();
    }
}

class ApiDatabase {
    private static $instances = [];
    private $connection;
    private function __construct($database) {
        $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASS, $database);
        if ($this->connection->connect_error) {
            ApiResponse::error("Database connection failed: " . $this->connection->connect_error, 500);
        }
        $this->connection->set_charset("utf8mb4");
    }
    public static function getInstance($database = DB_YOURLINKS) {
        if (!isset(self::$instances[$database])) {
            self::$instances[$database] = new ApiDatabase($database);
        }
        return self::$instances[$database];
    }
    public function getConnection() {
        return $this->connection;
    }
    public function query($sql, $params = []) {
        if (empty($params)) {
            $result = $this->connection->query($sql);
            return $result;
        } else {
            $stmt = $this->connection->prepare($sql);
            if ($stmt === false) {
                ApiResponse::error("Database prepare failed: " . $this->connection->error, 500);
            }
            if (!empty($params)) {
                $types = '';
                foreach ($params as $param) {
                    if (is_int($param)) {
                        $types .= 'i';
                    } elseif (is_float($param)) {
                        $types .= 'd';
                    } else {
                        $types .= 's';
                    }
                }
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            return $stmt;
        }
    }
    public function select($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        if ($stmt instanceof mysqli_stmt) {
            $result = $stmt->get_result();
            return $result->fetch_all(MYSQLI_ASSOC);
        } else {
            return $stmt->fetch_all(MYSQLI_ASSOC);
        }
    }
    public function insert($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $this->connection->insert_id;
    }
    public function execute($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        if ($stmt instanceof mysqli_stmt) {
            return $stmt->affected_rows;
        } else {
            return $this->connection->affected_rows;
        }
    }
}

class ApiAuth {
    private $apiKey;
    private $twitchUserId;
    private $userId;
    public function __construct($apiKey) {
        if (empty($apiKey)) {
            ApiResponse::error('API key is required', 400);
        }
        $this->apiKey = $apiKey;
        $this->validateApiKey();
    }
    private function validateApiKey() {
        // Query the website database to find the twitch_user_id associated with this API key
        $websiteDb = ApiDatabase::getInstance(DB_WEBSITE);
        // Adjust this query based on your actual "website" database schema
        // This assumes you have a table that stores API keys with associated twitch_user_id
        $sql = "SELECT twitch_user_id FROM users WHERE api_key = ? LIMIT 1";
        $result = $websiteDb->select($sql, [$this->apiKey]);
        if (empty($result)) {
            ApiResponse::error('Invalid API key', 401);
        }
        $this->twitchUserId = $result[0]['twitch_user_id'];
        $this->getUserIdFromTwitchId();
    }
    private function getUserIdFromTwitchId() {
        // Query the yourlinks database to find the user_id matching this twitch_user_id
        $yourlinksDb = ApiDatabase::getInstance(DB_YOURLINKS);
        $sql = "SELECT id FROM users WHERE twitch_id = ? LIMIT 1";
        $result = $yourlinksDb->select($sql, [$this->twitchUserId]);
        if (empty($result)) {
            ApiResponse::error('User not found. Twitch ID may not be registered in YourLinks.', 404);
        }
        $this->userId = $result[0]['id'];
    }
    public function getUserId() {
        return $this->userId;
    }
    public function getTwitchUserId() {
        return $this->twitchUserId;
    }
}

class LinkManager {
    private $userId;
    private $db;
    public function __construct($userId) {
        $this->userId = $userId;
        $this->db = ApiDatabase::getInstance(DB_YOURLINKS);
    }
    public function addLink($params) {
        // Validate required parameters
        if (empty($params['link_name']) || empty($params['original_url'])) {
            ApiResponse::error('Missing required parameters: link_name and original_url', 400);
        }
        $linkName = trim($params['link_name']);
        $originalUrl = trim($params['original_url']);
        $title = isset($params['title']) ? trim($params['title']) : $linkName;
        $categoryId = isset($params['category_id']) ? intval($params['category_id']) : null;
        $expiresAt = isset($params['expires_at']) ? $params['expires_at'] : null;
        $expiredRedirectUrl = isset($params['expired_redirect_url']) ? trim($params['expired_redirect_url']) : null;
        $isActive = isset($params['is_active']) ? (bool)$params['is_active'] : true;
        // Validate link_name format (alphanumeric, hyphens, underscores only)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $linkName)) {
            ApiResponse::error('Link name can only contain alphanumeric characters, hyphens, and underscores', 400);
        }
        // Validate URL format
        if (!filter_var($originalUrl, FILTER_VALIDATE_URL)) {
            ApiResponse::error('Invalid URL format for original_url', 400);
        }
        // Check if link_name is unique for this user
        $checkSql = "SELECT id FROM links WHERE user_id = ? AND link_name = ? LIMIT 1";
        $checkResult = $this->db->select($checkSql, [$this->userId, $linkName]);
        if (!empty($checkResult)) {
            ApiResponse::error('Link name already exists for this user', 409);
        }
        // If category_id is provided, verify it belongs to this user
        if ($categoryId !== null) {
            $categorySql = "SELECT id FROM categories WHERE id = ? AND user_id = ? LIMIT 1";
            $categoryResult = $this->db->select($categorySql, [$categoryId, $this->userId]);
            if (empty($categoryResult)) {
                ApiResponse::error('Category not found or does not belong to this user', 404);
            }
        }
        // Insert the new link
        $insertSql = "INSERT INTO links (
            user_id, 
            link_name, 
            original_url, 
            title, 
            category_id, 
            expires_at, 
            expired_redirect_url, 
            is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $linkId = $this->db->insert($insertSql, [
            $this->userId,
            $linkName,
            $originalUrl,
            $title,
            $categoryId,
            $expiresAt,
            $expiredRedirectUrl,
            $isActive ? 1 : 0
        ]);
        return [
            'link_id' => $linkId,
            'link_name' => $linkName,
            'original_url' => $originalUrl,
            'title' => $title,
            'is_active' => $isActive,
            'created_at' => date('Y-m-d H:i:s')
        ];
    }
    public function getLinks() {
        $sql = "SELECT id, link_name, original_url, title, category_id, is_active, clicks, created_at 
                FROM links 
                WHERE user_id = ? 
                ORDER BY created_at DESC";
        return $this->db->select($sql, [$this->userId]);
    }
    public function getLink($linkId) {
        $sql = "SELECT id, link_name, original_url, title, category_id, is_active, clicks, created_at 
                FROM links 
                WHERE user_id = ? AND id = ? 
                LIMIT 1";
        $result = $this->db->select($sql, [$this->userId, $linkId]);
        if (empty($result)) {
            ApiResponse::error('Link not found', 404);
        }
        return $result[0];
    }
}

function handleApiRequest() {
    // Get the action parameter
    $action = isset($_GET['action']) ? $_GET['action'] : 'add_link';
    switch ($action) {
        case 'add_link':
            handleAddLink();
            break;
        case 'list_links':
            handleListLinks();
            break;
        case 'get_link':
            handleGetLink();
            break;
        default:
            ApiResponse::error('Unknown action: ' . htmlspecialchars($action), 400);
    }
}

function handleAddLink() {
    // Validate API key
    $apiKey = isset($_GET['api_key']) ? $_GET['api_key'] : null;
    $auth = new ApiAuth($apiKey);
    // Create link manager
    $linkManager = new LinkManager($auth->getUserId());
    // Add the link
    $linkData = $linkManager->addLink($_GET);
    ApiResponse::success($linkData, 'Link created successfully');
}

function handleListLinks() {
    // Validate API key
    $apiKey = isset($_GET['api_key']) ? $_GET['api_key'] : null;
    $auth = new ApiAuth($apiKey);
    // Get links
    $linkManager = new LinkManager($auth->getUserId());
    $links = $linkManager->getLinks();
    ApiResponse::success(['links' => $links], 'Links retrieved successfully');
}

function handleGetLink() {
    // Validate API key
    $apiKey = isset($_GET['api_key']) ? $_GET['api_key'] : null;
    $auth = new ApiAuth($apiKey);
    // Get link ID
    $linkId = isset($_GET['link_id']) ? intval($_GET['link_id']) : null;
    if (empty($linkId)) {
        ApiResponse::error('Missing required parameter: link_id', 400);
    }
    // Get link
    $linkManager = new LinkManager($auth->getUserId());
    $link = $linkManager->getLink($linkId);
    ApiResponse::success(['link' => $link], 'Link retrieved successfully');
}

// Handle the API request
handleApiRequest();
?>