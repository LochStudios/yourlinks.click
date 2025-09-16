<?php
// Setup script for YourLinks.click
// This script creates the database and tables if they don't exist

// Include config to get credentials
if (file_exists('/var/www/config/database.php')) {
    require_once '/var/www/config/database.php';
} else {
    die("Database config file not found. Please create /var/www/config/database.php with your database credentials.\n");
}

// First, connect to MySQL server without specifying a database
$servername = $db_servername;
$username = $db_username;
$password = $db_password;

$conn = new mysqli($servername, $username, $password);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . "\n");
}

// Create database if it doesn't exist
$sql = "CREATE DATABASE IF NOT EXISTS yourlinks CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if ($conn->query($sql) === TRUE) {
    echo "Database 'yourlinks' created or already exists.\n";
} else {
    die("Error creating database: " . $conn->error . "\n");
}

// Close connection and reconnect to the specific database
$conn->close();

// Now include the normal database connection
require_once 'services/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

// Read the database schema from GitHub
$schemaUrl = 'https://raw.githubusercontent.com/LochStudios/yourlinks.click/refs/heads/main/database_schema.sql';
$schema = file_get_contents($schemaUrl);

if ($schema === false) {
    die("Failed to fetch database schema from GitHub\n");
}

// Split into individual statements
$statements = array_filter(array_map('trim', explode(';', $schema)));

$createdTables = 0;
$errors = [];

foreach ($statements as $statement) {
    // Skip comments and empty statements
    if (empty($statement) || preg_match('/^--/', $statement)) {
        continue;
    }

    // Execute the statement
    if ($conn->query($statement) === TRUE) {
        if (preg_match('/CREATE TABLE/i', $statement) || preg_match('/CREATE DATABASE/i', $statement)) {
            $createdTables++;
        }
    } else {
        $errors[] = "Error executing: " . $statement . "\nError: " . $conn->error;
    }
}

echo "Database setup completed!\n";
echo "Database: " . DB_NAME . "\n";
echo "Tables created/verified: $createdTables\n";

if (!empty($errors)) {
    echo "\nErrors encountered:\n";
    foreach ($errors as $error) {
        echo $error . "\n";
    }
} else {
    echo "No errors.\n";
}

echo "\nSetup complete. You can now use YourLinks.click!\n";
?>