<?php
// Database configuration
// Include sensitive configuration from external file
require_once '/var/www/config/database.php';

// Define constants from config variables for backward compatibility
define('DB_HOST', $db_servername);
define('DB_NAME', 'yourlinks');
define('DB_USER', $db_username);
define('DB_PASS', $db_password);

class Database {
    private static $instance = null;
    private $connection;
    private function __construct() {
        $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($this->connection->connect_error) {
            die("Database connection failed: " . $this->connection->connect_error);
        }
        // Set charset to utf8mb4 for proper Unicode support
        $this->connection->set_charset("utf8mb4");
    }
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    public function getConnection() {
        return $this->connection;
    }
    // Helper method to execute queries
    public function query($sql, $params = []) {
        if (empty($params)) {
            $result = $this->connection->query($sql);
            return $result;
        } else {
            $stmt = $this->connection->prepare($sql);
            if ($stmt === false) {
                die("Prepare failed: " . $this->connection->error);
            }
            // Bind parameters
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
    // Helper method for SELECT queries
    public function select($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        if ($stmt instanceof mysqli_stmt) {
            $result = $stmt->get_result();
            return $result->fetch_all(MYSQLI_ASSOC);
        } else {
            return $stmt->fetch_all(MYSQLI_ASSOC);
        }
    }
    // Helper method for INSERT queries
    public function insert($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $this->connection->insert_id;
    }
    // Helper method for UPDATE/DELETE queries
    public function execute($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        if ($stmt instanceof mysqli_stmt) {
            return $stmt->affected_rows;
        } else {
            return $this->connection->affected_rows;
        }
    }
}
?>