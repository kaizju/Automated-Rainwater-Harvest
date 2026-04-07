<?php
// ── Guards ────────────────────────────────────────────────────────────────────

function redirect($path) {
    header("Location: " . BASE_URL . $path);
    exit;
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function hasRole(string $role): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function hasAnyRole(array $roles): bool {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], $roles, true);
}

function isAdmin(): bool       { return hasRole('admin'); }
function isManager(): bool     { return hasAnyRole(['admin', 'manager']); }
function isRegularUser(): bool { return hasRole('user'); }

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect('/index.php');
    }
}

function requireRole(string $role): void {
    requireLogin();
    if (!hasRole($role)) {
        logActivity('unauthorized_access', 'warning', 'auth', 'Attempted access to ' . $role . '-only page');
        redirect('/App/Dashboard/dashboard.php');
    }
}

function requireAnyRole(array $roles): void {
    requireLogin();
    if (!hasAnyRole($roles)) {
        logActivity('unauthorized_access', 'warning', 'auth', 'Attempted access to restricted page');
        redirect('/app/Dashboard/dashboard.php');
    }
}

// ── Session helpers ───────────────────────────────────────────────────────────

function currentRole(): string     { return $_SESSION['role']     ?? 'user'; }
function currentEmail(): string    { return $_SESSION['email']    ?? ''; }
function currentUserId(): int      { return (int)($_SESSION['user_id'] ?? 0); }
function currentUsername(): string { return $_SESSION['username'] ?? ''; }

function avatarInitials(): string {
    $email = currentEmail();
    if (!$email) return 'ME';
    $local = explode('@', $email)[0];
    $parts = preg_split('/[._\-]+/', $local);
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }
    return strtoupper(substr($local, 0, 2));
}

// ── Activity Logging ──────────────────────────────────────────────────────────

/**
 * Log any user action to user_activity_logs.
 *
 * @param string      $action      Short action key, e.g. 'login', 'tank_edit', 'page_view'
 * @param string      $status      'success' | 'failed' | 'warning' | 'critical'
 * @param string      $module      Domain area: 'auth', 'tank', 'users', 'settings', 'sensor', etc.
 * @param string|null $description Human-readable detail
 * @param array|null  $oldValue    State before change (will be JSON-encoded)
 * @param array|null  $newValue    State after change (will be JSON-encoded)
 */
function logActivity(
    string  $action,
    string  $status      = 'success',
    string  $module      = 'general',
    ?string $description = null,
    ?array  $oldValue    = null,
    ?array  $newValue    = null
): void {
    global $pdo;
    if (!isset($pdo)) return;

    // Map status → severity
    $severity = match($status) {
        'critical', 'failed' => match($action) {
            'login_failed', 'unauthorized_access' => 'warning',
            default => 'info'
        },
        'warning' => 'warning',
        default   => 'info',
    };
    if (str_contains($action, 'delete') || str_contains($action, 'critical')) {
        $severity = 'critical';
    }

    $dbStatus = in_array($status, ['success', 'failed']) ? $status : 'success';

    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_activity_logs
                (user_id, role, email, action, status, module, description,
                 old_value, new_value, severity, ip_address, user_agent, created_at)
            VALUES
                (:uid, :role, :email, :action, :status, :module, :desc,
                 :old, :new, :severity, :ip, :ua, NOW())
        ");
        $stmt->execute([
            ':uid'      => currentUserId() ?: null,
            ':role'     => currentRole(),
            ':email'    => currentEmail() ?: null,
            ':action'   => $action,
            ':status'   => $dbStatus,
            ':module'   => $module,
            ':desc'     => $description,
            ':old'      => $oldValue  ? json_encode($oldValue,  JSON_UNESCAPED_UNICODE) : null,
            ':new'      => $newValue  ? json_encode($newValue,  JSON_UNESCAPED_UNICODE) : null,
            ':severity' => $severity,
            ':ip'       => $_SERVER['REMOTE_ADDR']     ?? null,
            ':ua'       => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('logActivity error: ' . $e->getMessage());
    }
}

/**
 * Log a page navigation event to page_visits.
 * Call this at the top of every page after requireLogin().
 *
 * @param string $pageLabel  Human-friendly label, e.g. "Admin Dashboard"
 * @param string $pageKey    Short key for the module, e.g. "dashboard"
 */
function logPageVisit(string $pageLabel, string $pageKey = ''): void {
    global $pdo;
    if (!isset($pdo) || !isLoggedIn()) return;

    $page = $_SERVER['REQUEST_URI'] ?? 'unknown';

    try {
        $stmt = $pdo->prepare("
            INSERT INTO page_visits
                (user_id, role, email, page, page_label, ip_address, user_agent, visited_at)
            VALUES
                (:uid, :role, :email, :page, :label, :ip, :ua, NOW())
        ");
        $stmt->execute([
            ':uid'   => currentUserId() ?: null,
            ':role'  => currentRole(),
            ':email' => currentEmail() ?: null,
            ':page'  => $page,
            ':label' => $pageLabel,
            ':ip'    => $_SERVER['REMOTE_ADDR']     ?? null,
            ':ua'    => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('logPageVisit error: ' . $e->getMessage());
    }

    // Also write a condensed entry in activity log so oversight pages see it
    logActivity('page_view', 'success', $pageKey ?: 'navigation', "Visited: $pageLabel");
}

/**
 * Raise a system alert (low water, bad pH, sensor anomaly, etc.)
 */
function raiseAlert(
    string $alertType,
    string $message,
    string $severity = 'warning',
    ?int   $tankId   = null
): void {
    global $pdo;
    if (!isset($pdo)) return;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_alerts
                (tank_id, user_id, alert_type, message, severity, is_resolved, created_at)
            VALUES (:tid, :uid, :type, :msg, :sev, 0, NOW())
        ");
        $stmt->execute([
            ':tid'  => $tankId,
            ':uid'  => currentUserId() ?: null,
            ':type' => $alertType,
            ':msg'  => $message,
            ':sev'  => $severity,
        ]);
    } catch (Throwable $e) {
        error_log('raiseAlert error: ' . $e->getMessage());
    }
}

// ── Deprecated render helpers (kept for back-compat) ─────────────────────────

function renderHeader(string $title): void {
    $isLoggedIn = isLoggedIn();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?></title>
        <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="<?= BASE_URL ?>/Others/all.css">
    </head>
    <body>
        <?php if ($isLoggedIn): ?>
            <div class="main-content"><div class="container">
        <?php else: ?>
            <div class="container" style="margin:0 auto;max-width:500px;padding-top:100px;">
        <?php endif;
}

function renderFooter(): void {
    $isLoggedIn = isLoggedIn();
    ?>
        <?php if ($isLoggedIn): ?>
                </div></div>
        <?php else: ?>
            </div>
        <?php endif; ?>
    </body>
    </html>
    <?php
}