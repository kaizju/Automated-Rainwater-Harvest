<?php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'automated_rainwater');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS
    );
    echo "✅ Connected successfully!";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}