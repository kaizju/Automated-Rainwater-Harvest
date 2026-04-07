<?php
require_once __DIR__ . '/../connections/config.php';
require_once __DIR__ . '/../connections/functions.php';

// Log logout BEFORE destroying the session (session data still available here)
if (isLoggedIn()) {
    logActivity('logout', 'success', 'auth', 'User logged out');
}

// Clear session data
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

header('Location: ' . BASE_URL . '/index.php');
exit;