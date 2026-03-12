<?php

function logActivity($pdo, $user_id, $email, $action, $status = 'success') {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);

        $stmt = $pdo->prepare("
            INSERT INTO user_activity_logs (user_id, email, action, status, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        return $stmt->execute([$user_id, $email, $action, $status, $ip, $user_agent]);

    } catch (PDOException $e) {
        error_log("Activity Log Error: " . $e->getMessage());
        return false;
    }
}
?>