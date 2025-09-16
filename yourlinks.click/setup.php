<?php
// Setup script for YourLinks.click
// This script creates the database and tables if they don't exist

// Include database connection (this will create the database if needed)
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