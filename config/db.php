<?php
// 1.0 DATABASE CONFIGURATION SETTINGS

// 1.1 ENVIRONMENT VARIABLES WITH FALLBACK DEFAULTS
$host = $_ENV['DB_HOST'] ?? 'localhost';
$db_name = $_ENV['DB_NAME'] ?? 'facturas';
$username = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASS'] ?? 'ManelMaior1920!';
$charset = 'utf8mb4'; // Using utf8mb4 for full Unicode support including emojis

// 1.2 APPLICATION SETTINGS
$base_url = ''; // Base URL for the application
$system_name = $_ENV['SYSTEM_NAME'] ?? 'Budget Control';

// 2.0 DATABASE CONNECTION HANDLING
try {
    // 2.1 CREATE PDO CONNECTION
    $dsn = "mysql:host=$host;dbname=$db_name;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Turn on errors in the form of exceptions
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Make the default fetch mode associative array
        PDO::ATTR_EMULATE_PREPARES   => false, // Turn off emulated prepared statements
        PDO::ATTR_PERSISTENT         => false, // Don't use persistent connections
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES $charset" // Set character encoding
    ];
    
    // 2.2 ESTABLISH CONNECTION
    $pdo = new PDO($dsn, $username, $password, $options);
    
    // 2.3 ADDITIONAL CONFIGURATIONS
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false); // Preserve data types
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5); // Set connection timeout to 5 seconds
    
} catch (PDOException $e) {
    // 3.0 ERROR HANDLING
    
    // 3.1 LOG ERROR DETAILS
    error_log('Database connection failed: ' . $e->getMessage());
    
    // 3.2 USER-FRIENDLY ERROR MESSAGE
    $error_message = "System temporarily unavailable. Please try again later.";
    
    // 3.3 SECURE ERROR DISPLAY
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE === true) {
        $error_message .= " [Technical details: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "]";
    }
    
    // 3.4 TERMINATE SCRIPT
    die($error_message);
}

// 4.0 GLOBAL DATABASE FUNCTIONS

/**
 * 4.1 GET DATABASE CONNECTION
 * @return PDO Returns the database connection object
 */
function getDatabaseConnection() {
    global $pdo;
    return $pdo;
}

// 5.0 SECURITY SETTINGS

// 5.1 DISABLE MAGIC QUOTES IF ENABLED
if (function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc()) {
    function stripslashes_deep($value) {
        return is_array($value) ? array_map('stripslashes_deep', $value) : stripslashes($value);
    }
    $_POST = array_map('stripslashes_deep', $_POST);
    $_GET = array_map('stripslashes_deep', $_GET);
    $_COOKIE = array_map('stripslashes_deep', $_COOKIE);
    $_REQUEST = array_map('stripslashes_deep', $_REQUEST);
}

// 5.2 SET DEFAULT TIMEZONE
date_default_timezone_set('Europe/Lisbon');
