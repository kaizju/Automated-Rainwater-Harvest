<?php
require_once __DIR__ . '/../Connections/config.php';
require_once __DIR__ . '/../Connections/functions.php';
require_once __DIR__ . '/../Others/activity_logger.php';

// Log the logout activity before destroying the session
if (isset($_SESSION['user_id']) && isset($_SESSION['email'])) {
    logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'logout', 'success');
}

// Clear all session data
$_SESSION = [];

// Destroy the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}


session_destroy();


die(header('Location: /sag-ob/Automated-RainWater-Harvest/index.php'));
exit;
?>