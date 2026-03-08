<?php
session_start();
define('BASE_URL', 'http://localhost/Automated-RainWater-Harvest');

define('DB_HOST', 'localhost');
define('DB_NAME', 'automated_rainwater');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>