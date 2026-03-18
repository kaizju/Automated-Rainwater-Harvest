<?php
// Start session only once — safe to call even if already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('BASE_URL', 'http://localhost/Automated-RainWater-Harvest');

define('DB_HOST', 'localhost');
define('DB_NAME', 'automated_rainwater');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Don't leak DB credentials in production — log instead of die()
    error_log("DB connection failed: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}