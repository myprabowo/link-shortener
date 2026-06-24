<?php
// config.example.php
// Rename this file to config.php and fill in your actual credentials

// Database Configuration
define('DB_FILE', __DIR__ . '/database.sqlite');

// Base URL for the shortened links (must include trailing slash)
define('BASE_URL', 'https://s.domain.com/');

// Authentication Credentials
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'your_password'); // Replace with a stronger password
define('API_KEY', 'your_secret_api_key'); // Replace with a hard-to-guess API Key

// Establish Database Connection
try {
    $pdo = new PDO("sqlite:" . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Automatically create the table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        short_code TEXT NOT NULL UNIQUE,
        original_url TEXT NOT NULL,
        clicks INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Function to generate a random string for the short code
function generateShortCode($length = 6)
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    return $randomString;
}
