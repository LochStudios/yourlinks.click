<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Domain verification endpoint for YourLinks.click
// This script verifies domain ownership by checking DNS TXT records

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Get domain from POST data
$domain = trim($_POST['domain'] ?? '');
$expectedToken = trim($_POST['token'] ?? '');

if (empty($domain) || empty($expectedToken)) {
    http_response_code(400);
    echo json_encode(['error' => 'Domain and token are required']);
    exit();
}

// Validate domain format
if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domain)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid domain format']);
    exit();
}

// Check DNS TXT record
$txtRecords = dns_get_record("_yourlinks_verification.{$domain}", DNS_TXT);

$found = false;
if ($txtRecords) {
    foreach ($txtRecords as $record) {
        if (isset($record['txt']) && $record['txt'] === $expectedToken) {
            $found = true;
            break;
        }
    }
}

if ($found) {
    echo json_encode([
        'success' => true,
        'message' => 'Domain verification successful!',
        'domain' => $domain
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'TXT record not found or incorrect. Please check your DNS settings.',
        'domain' => $domain,
        'expected' => $expectedToken
    ]);
}
?>