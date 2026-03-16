<?php
session_start();
define('BASE_URL', 'http://localhost/Automated-RainWater-Harvest');

define('DB_HOST', 'localhost');
define('DB_NAME', 'u442411629_sagob');
define('DB_USER', 'u442411629_dev_sagob');
define('DB_PASS', 'Q9-6:{e8=],R');

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