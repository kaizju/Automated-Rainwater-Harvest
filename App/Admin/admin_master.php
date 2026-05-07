<?php
/* ============================================================
   ECORAIN — ADMIN MASTER FILE
   admin_master.php

   Replaces all individual admin_*.php files:
     - admin_dashboard.php  → ?page=dashboard  (default)
     - admin_map.php        → ?page=map
     - admin_oversight.php  → ?page=oversight
     - admin_settings.php   → ?page=settings
     - admin_usage.php      → ?page=usage
     - admin_userlogs.php   → ?page=userlogs
     - admin_weather.php    → ?page=weather

   Usage:  admin_master.php?page=dashboard
   ============================================================ */

require_once '../../connections/config.php';
require_once '../../connections/functions.php';

requireRole('admin');

/* ── Determine active page from GET parameter ──────────────── */
$validPages = ['dashboard','map','oversight','settings','usage','userlogs','weather'];
$page       = isset($_GET['page']) && in_array($_GET['page'], $validPages, true)
              ? $_GET['page']
              : 'dashboard';

/* ── Log the page visit with the resolved page name ───────── */
$pageLabels = [
  'dashboard' => 'Admin Dashboard',
  'map'       => 'Admin Map',
  'oversight' => 'Admin Oversight',
  'settings'  => 'Admin Settings',
  'usage'     => 'Admin Usage',
  'userlogs'  => 'Admin User Logs',
  'weather'   => 'Admin Weather',
];
logPageVisit($pageLabels[$page], ucfirst($page));

$activePage = ucfirst($page);

/* ============================================================
   SECTION: SHARED HELPER FUNCTIONS
   Used across multiple pages
   ============================================================ */

/** Returns initials from session username or email */


/** Relative time string from a datetime */
function timeAgo(?string $ts): string {
    if (!$ts) return 'never';
    $diff = abs(time() - strtotime($ts));
    if ($diff < 60)    return $diff . 's ago';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

/** Role-colored badge HTML */
function roleBadge(string $role): string {
    $colors = match ($role) {
        'admin'   => 'background:#eff6ff;color:#2563eb;border-color:#bfdbfe',
        'manager' => 'background:#f5f3ff;color:#7c3aed;border-color:#ddd6fe',
        default   => 'background:#ecfdf5;color:#059669;border-color:#a7f3d0',
    };
    return "<span class='badge' style='$colors'>" . ucfirst(htmlspecialchars($role)) . "</span>";
}

/** Severity badge HTML */
function severityBadge(string $sev): string {
    $colors = match ($sev) {
        'critical' => 'background:#fef2f2;color:#dc2626;border-color:#fecaca',
        'warning'  => 'background:#fffbeb;color:#d97706;border-color:#fde68a',
        default    => 'background:#f0fdf4;color:#16a34a;border-color:#bbf7d0',
    };
    return "<span class='badge' style='$colors'>" . ucfirst(htmlspecialchars($sev)) . "</span>";
}

/** Role hex color for avatars */
function roleColor(string $role): string {
    return match ($role) {
        'admin'   => '#3b82f6',
        'manager' => '#8b5cf6',
        default   => '#10b981',
    };
}

/** Action icon emoji */
function actionIcon(string $action): string {
    if (str_contains($action, 'login'))    return '🔑';
    if (str_contains($action, 'logout'))   return '🚪';
    if (str_contains($action, 'delete'))   return '🗑️';
    if (str_contains($action, 'create') || str_contains($action, 'add')) return '➕';
    if (str_contains($action, 'edit')   || str_contains($action, 'update')) return '✏️';
    if (str_contains($action, 'page_view') || str_contains($action, 'visit')) return '👁️';
    if (str_contains($action, 'export'))   return '📤';
    if (str_contains($action, 'alert'))    return '🚨';
    return '📋';
}

/** Weather emoji from OWM ID */
function weatherEmoji(int $id): string {
    if ($id >= 200 && $id < 300) return '⛈️';
    if ($id >= 300 && $id < 400) return '🌦️';
    if ($id >= 500 && $id < 600) return '🌧️';
    if ($id >= 600 && $id < 700) return '❄️';
    if ($id >= 700 && $id < 800) return '🌫️';
    if ($id === 800)              return '☀️';
    if ($id === 801 || $id === 802) return '⛅';
    return '☁️';
}

/** Map: tank bar color by fill percent */
function barColor(int $pct): string {
    if ($pct >= 75) return '#3b82f6';
    if ($pct >= 40) return '#f59e0b';
    return '#ef4444';
}

/** Map: status badge HTML */
function statusBadge(string $status): string {
    $s = strtolower($status);
    if ($s === 'active')      return '<span class="badge badge-online">&#x1F4F6; Online</span>';
    if ($s === 'maintenance') return '<span class="badge badge-maintenance">&#x1F527; Maintenance</span>';
    return '<span class="badge badge-offline">&#x26A0; Offline</span>';
}

/** Fetch JSON from a URL (weather API) */
function fetchJson(string $url): ?array {
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? json_decode($raw, true) : null;
}

/* ============================================================
   SECTION: PAGE-SPECIFIC DATA QUERIES
   Each page block queries only what it needs
   ============================================================ */

/* ── Common: session guard ─────────────────────────────────── */
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$userId   = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'user';
$initials = avatarInitials();


/* ============================================================
   SECTION: DASHBOARD DATA
   ============================================================ */
if ($page === 'dashboard') {

    /* All tanks aggregate */
    $allTanks = $pdo->query("SELECT * FROM tank")->fetchAll(PDO::FETCH_ASSOC);
    $totalCurrentLiters = array_sum(array_column($allTanks, 'current_liters'));
    $totalMaxCapacity   = array_sum(array_column($allTanks, 'max_capacity'));
    $tankCount          = count($allTanks);
    $onlineCount        = count(array_filter($allTanks, fn($t) => strtolower($t['status_tank']) === 'active'));
    $percent = ($totalMaxCapacity > 0) ? round(($totalCurrentLiters / $totalMaxCapacity) * 100, 1) : 0;

    /* Water quality */
    $quality = $pdo->query("SELECT * FROM water_quality ORDER BY recorded_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    /* Today collected */
    $todayRow       = $pdo->query("SELECT COALESCE(SUM(usage_liters),0) AS t FROM water_usage WHERE DATE(recorded_at)=CURDATE()")->fetch(PDO::FETCH_ASSOC);
    $todayCollected = (float)$todayRow['t'];

    /* Usage last 7 days */
    $usageRows = $pdo->query("
        SELECT DATE(recorded_at) AS day_date, SUM(usage_liters) AS total
        FROM water_usage
        WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(recorded_at) ORDER BY day_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $usageMap = [];
    foreach ($usageRows as $r) $usageMap[$r['day_date']] = $r['total'];
    $chartLabels = $chartData = [];
    for ($i = 6; $i >= 0; $i--) {
        $date          = date('Y-m-d', strtotime("-$i days"));
        $chartLabels[] = date('D', strtotime($date));
        $chartData[]   = isset($usageMap[$date]) ? (float)$usageMap[$date] : 0;
    }

    /* Sensor readings */
    $sensors = $pdo->query("
        SELECT sr.anomaly, sr.recorded_at, s.sensor_type, s.model
        FROM sensor_readings sr JOIN sensors s ON sr.sensor_id=s.sensor_id
        ORDER BY sr.recorded_at DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    /* Activity log */
    $activities = $pdo->query("
        SELECT ual.action, ual.created_at, ual.user_id
        FROM user_activity_logs ual
        ORDER BY ual.created_at DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    /* Dashboard helpers */
    function phLabel($v)   { return $v == 0 ? 'None' : ($v < 6.5 ? 'Low' : ($v > 8.5 ? 'High' : 'Optimal')); }
    function phColor($v)   { return ($v == 0 || ($v >= 6.5 && $v <= 8.5)) ? '#16a34a' : '#ef4444'; }
    function turbLabel($v) { return $v == 0 ? 'None' : ($v > 4 ? 'Poor' : ($v > 1 ? 'Moderate' : 'Excellent')); }
    function turbColor($v) { return ($v == 0 || $v <= 1) ? '#16a34a' : ($v <= 4 ? '#d97706' : '#ef4444'); }

    $tankBg = $percent < 20
        ? 'linear-gradient(135deg,#fee2e2,#fca5a5)'
        : ($percent < 50 ? 'linear-gradient(135deg,#fef9c3,#fde68a)' : 'linear-gradient(135deg,#dbeafe,#93c5fd)');
    $tankAccent = $percent < 20 ? '#dc2626' : ($percent < 50 ? '#d97706' : '#2563eb');

    $updatedAgo = 'N/A';
    if ($quality) {
        $diff = abs(time() - strtotime($quality['recorded_at']));
        if ($diff < 60)    $updatedAgo = $diff . 's ago';
        elseif ($diff < 3600)  $updatedAgo = floor($diff / 60) . 'm ago';
        elseif ($diff < 86400) $updatedAgo = floor($diff / 3600) . 'h ago';
        else                   $updatedAgo = floor($diff / 86400) . 'd ago';
    }
}


/* ============================================================
   SECTION: MAP DATA
   ============================================================ */
if ($page === 'map') {

    $stmtUser = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $currentUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

    $stmtTanks = $pdo->query("
        SELECT t.tank_id, t.tankname, t.location_add, t.current_liters, t.max_capacity, t.status_tank,
               ROUND((t.current_liters / t.max_capacity) * 100) AS fill_pct,
               (SELECT sr.recorded_at FROM sensor_readings sr
                INNER JOIN sensors s ON s.sensor_id = sr.sensor_id
                WHERE s.tank_id = t.tank_id ORDER BY sr.recorded_at DESC LIMIT 1) AS last_reading
        FROM tank t ORDER BY t.tank_id ASC
    ");
    $tanks = $stmtTanks->fetchAll(PDO::FETCH_ASSOC);

    $totalStored   = array_sum(array_column($tanks, 'current_liters'));
    $totalCapacity = array_sum(array_column($tanks, 'max_capacity'));
    $overallPct    = $totalCapacity > 0 ? round(($totalStored / $totalCapacity) * 100) : 0;
    $onlineCount   = count(array_filter($tanks, fn($t) => strtolower($t['status_tank']) === 'active'));
    $systemStatus  = $onlineCount === count($tanks) ? 'All Online' : ($onlineCount > 0 ? 'Partial' : 'Offline');
}


/* ============================================================
   SECTION: OVERSIGHT DATA
   ============================================================ */
if ($page === 'oversight') {

    /* Stats bar */
    $totalUsers    = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $onlineToday   = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_activity_logs WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $totalActions  = (int)$pdo->query("SELECT COUNT(*) FROM user_activity_logs WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $criticalCount = (int)$pdo->query("SELECT COUNT(*) FROM system_alerts WHERE is_resolved=0 AND severity='critical'")->fetchColumn();

    /* Role breakdown */
    $roleStats  = $pdo->query("SELECT role, COUNT(*) AS cnt FROM users GROUP BY role")->fetchAll(PDO::FETCH_ASSOC);
    $roleCounts = array_column($roleStats, 'cnt', 'role');

    /* Filters */
    $filterRole   = $_GET['role']   ?? '';
    $filterAction = $_GET['action'] ?? '';
    $filterDate   = $_GET['date']   ?? '';
    $filterUser   = $_GET['user']   ?? '';
    $pgNum        = max(1, (int)($_GET['p'] ?? 1));
    $perPage      = 20;
    $offset       = ($pgNum - 1) * $perPage;

    $where  = ['1=1'];
    $params = [];
    if ($filterRole)   { $where[] = 'ual.role = :role';                          $params[':role']   = $filterRole; }
    if ($filterAction) { $where[] = 'ual.action LIKE :action';                   $params[':action'] = '%' . $filterAction . '%'; }
    if ($filterDate)   { $where[] = 'DATE(ual.created_at) = :date';              $params[':date']   = $filterDate; }
    if ($filterUser)   { $where[] = '(u.email LIKE :user OR u.username LIKE :user2)'; $params[':user'] = '%' . $filterUser . '%'; $params[':user2'] = '%' . $filterUser . '%'; }

    $whereStr  = implode(' AND ', $where);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM user_activity_logs ual LEFT JOIN users u ON ual.user_id = u.id WHERE $whereStr");
    $countStmt->execute($params);
    $totalRows  = (int)$countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $perPage));

    $logStmt = $pdo->prepare("
        SELECT ual.*, u.username, u.email AS user_email
        FROM user_activity_logs ual
        LEFT JOIN users u ON ual.user_id = u.id
        WHERE $whereStr
        ORDER BY ual.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) $logStmt->bindValue($k, $v);
    $logStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $logStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $logStmt->execute();
    $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

    /* Page visits */
    $visits = $pdo->query("
        SELECT pv.*, u.username, u.email AS user_email
        FROM page_visits pv
        LEFT JOIN users u ON pv.user_id = u.id
        ORDER BY pv.visited_at DESC LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);

    /* Unresolved system alerts */
    $alerts = $pdo->query("
        SELECT sa.*, t.tankname, u.username
        FROM system_alerts sa
        LEFT JOIN tank t  ON sa.tank_id = t.tank_id
        LEFT JOIN users u ON sa.user_id = u.id
        WHERE sa.is_resolved = 0
        ORDER BY sa.severity DESC, sa.created_at DESC LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    /* All users */
    $users = $pdo->query("
        SELECT u.id, u.username, u.email, u.role, u.is_verified, u.created_at,
               (SELECT COUNT(*) FROM user_activity_logs WHERE user_id=u.id AND DATE(created_at)=CURDATE()) AS today_actions,
               (SELECT MAX(created_at) FROM user_activity_logs WHERE user_id=u.id) AS last_seen
        FROM users u ORDER BY u.role, u.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    /* Action frequency chart */
    $actionFreq = $pdo->query("
        SELECT action, COUNT(*) AS cnt FROM user_activity_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY action ORDER BY cnt DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
    $chartActionLabels = array_column($actionFreq, 'action');
    $chartActionData   = array_column($actionFreq, 'cnt');

    /* Activity by role (last 7 days) */
    $actByRole = $pdo->query("
        SELECT DATE(created_at) AS d,
               SUM(role='admin')   AS admins,
               SUM(role='manager') AS managers,
               SUM(role='user')    AS users_cnt
        FROM user_activity_logs
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(created_at) ORDER BY d ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $roleChartLabels = $roleChartAdmin = $roleChartManager = $roleChartUser = [];
    $dateMap = array_column($actByRole, null, 'd');
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $roleChartLabels[]  = date('D', strtotime($d));
        $roleChartAdmin[]   = (int)($dateMap[$d]['admins']    ?? 0);
        $roleChartManager[] = (int)($dateMap[$d]['managers']  ?? 0);
        $roleChartUser[]    = (int)($dateMap[$d]['users_cnt'] ?? 0);
    }

    /* CSV export */
    if (isset($_GET['export_report'])) {
        $filename = 'ecorain_oversight_report_' . date('Y-m-d_His') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache'); header('Expires: 0');
        $out = fopen('php://output', 'w');

        fputcsv($out, ['=== ACTIVITY LOGS ===']);
        fputcsv($out, ['#','Username','Email','Role','Action','Module','Description','Severity','IP Address','Date & Time']);
        $exportLogs = $pdo->query("SELECT ual.activity_id, u.username, u.email, ual.role, ual.action, ual.module, ual.description, ual.severity, ual.ip_address, ual.created_at FROM user_activity_logs ual LEFT JOIN users u ON ual.user_id = u.id ORDER BY ual.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($exportLogs as $i => $row) fputcsv($out, [$i+1, $row['username']??'System', $row['email']??'', $row['role']??'', $row['action']??'', $row['module']??'', $row['description']??'', $row['severity']??'', $row['ip_address']??'', $row['created_at']??'']);

        fputcsv($out, []); fputcsv($out, ['=== PAGE VISITS ===']);
        fputcsv($out, ['#','Username','Email','Role','Page Label','Page URL','IP Address','Visited At']);
        $exportVisits = $pdo->query("SELECT pv.*, u.username, u.email AS user_email FROM page_visits pv LEFT JOIN users u ON pv.user_id = u.id ORDER BY pv.visited_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($exportVisits as $i => $row) fputcsv($out, [$i+1, $row['username']??'Guest', $row['user_email']??'', $row['role']??'', $row['page_label']??'', $row['page']??'', $row['ip_address']??'', $row['visited_at']??'']);

        fputcsv($out, []); fputcsv($out, ['=== SYSTEM ALERTS ===']);
        fputcsv($out, ['#','Message','Severity','Tank','Triggered By','Status','Created At','Resolved At']);
        $exportAlerts = $pdo->query("SELECT sa.*, t.tankname, u.username FROM system_alerts sa LEFT JOIN tank t ON sa.tank_id = t.tank_id LEFT JOIN users u ON sa.user_id = u.id ORDER BY sa.severity DESC, sa.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($exportAlerts as $i => $row) fputcsv($out, [$i+1, $row['message']??'', $row['severity']??'', $row['tankname']??'—', $row['username']??'System', $row['is_resolved'] ? 'Resolved' : 'Open', $row['created_at']??'', $row['resolved_at']??'']);

        fputcsv($out, []); fputcsv($out, ['=== SUMMARY ===']);
        fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
        fputcsv($out, ['Total Activity Logs', count($exportLogs)]);
        fputcsv($out, ['Total Page Visits',   count($exportVisits)]);
        fputcsv($out, ['Total System Alerts', count($exportAlerts)]);
        fputcsv($out, ['Exported By', $_SESSION['username'] ?? 'Admin']);
        fclose($out); exit;
    }

    /* Handle alert resolve */
    if (isset($_GET['resolve'])) {
        $aid = (int)$_GET['resolve'];
        $pdo->prepare("UPDATE system_alerts SET is_resolved=1, resolved_at=NOW() WHERE alert_id=:id")->execute([':id' => $aid]);
        logActivity('alert_resolved', 'success', 'alerts', "Resolved alert #$aid");
        header("Location: " . BASE_URL . "/app/admin/admin_master.php?page=oversight");
        exit;
    }
}


/* ============================================================
   SECTION: SETTINGS DATA & POST HANDLING
   ============================================================ */
if ($page === 'settings') {

    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key   VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $tank     = $pdo->query("SELECT * FROM tank LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $allTanks = $pdo->query("SELECT tank_id, tankname, location_add, status_tank FROM tank ORDER BY tank_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $rows     = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    function cfg(array $rows, string $key, $default) { return $rows[$key] ?? $default; }

    $success = ''; $error = '';

    /* Delete tank */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tank'])) {
        try {
            $tankId = (int)($_POST['tank_id'] ?? 0);
            if ($tankId <= 0) throw new Exception('Invalid tank ID.');
            $stmt = $pdo->prepare("DELETE FROM tank WHERE tank_id = ?");
            $stmt->execute([$tankId]);
            if ($stmt->rowCount() === 0) throw new Exception('Tank not found or already deleted.');
            $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, role, action, status, ip_address) VALUES (?, ?, ?, 'delete_tank', 'success', ?)")
                ->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '', $_SESSION['role'] ?? 'admin', $_SERVER['REMOTE_ADDR'] ?? '']);
            $success  = 'Tank deleted successfully.';
            $allTanks = $pdo->query("SELECT tank_id, tankname, location_add, status_tank FROM tank ORDER BY tank_id ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $error = $e->getMessage(); }
    }

    /* Add tank */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tank'])) {
        try {
            $tankname      = trim($_POST['tankname']        ?? '');
            $location_add  = trim($_POST['location_add']    ?? '');
            $currentLiters = (int)($_POST['current_liters'] ?? 0);
            $maxCapacity   = (int)($_POST['max_capacity']   ?? 0);
            $statusTank    = trim($_POST['status_tank']     ?? 'Active');
            if ($tankname     === '') throw new Exception('Tank name is required.');
            if ($location_add === '') throw new Exception('Location is required.');
            if ($maxCapacity  <= 0)  throw new Exception('Max capacity must be greater than 0.');
            $pdo->prepare("INSERT INTO tank (tankname, location_add, current_liters, max_capacity, status_tank) VALUES (?, ?, ?, ?, ?)")
                ->execute([$tankname, $location_add, $currentLiters, $maxCapacity, $statusTank]);
            $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, role, action, status, ip_address) VALUES (?, ?, ?, 'add_tank', 'success', ?)")
                ->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '', $_SESSION['role'] ?? 'admin', $_SERVER['REMOTE_ADDR'] ?? '']);
            $success  = 'Tank added successfully.';
            $tank     = $pdo->query("SELECT * FROM tank LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $allTanks = $pdo->query("SELECT tank_id, tankname, location_add, status_tank FROM tank ORDER BY tank_id ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $error = $e->getMessage(); }
    }

    /* Save settings */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['add_tank']) && !isset($_POST['delete_tank'])) {
        try {
            $capacity  = (int)($_POST['tank_capacity'] ?? 5000);
            $threshold = (int)($_POST['threshold']     ?? 1000);
            if ($tank) $pdo->prepare("UPDATE tank SET max_capacity = ? WHERE tank_id = ?")->execute([$capacity, $tank['tank_id']]);
            $settings = [
                'tank_capacity'       => $capacity,
                'threshold'           => $threshold,
                'overflow_prevention' => isset($_POST['overflow_prevention']) ? '1' : '0',
                'pump_auto'           => isset($_POST['pump_auto'])           ? '1' : '0',
                'pump_schedule'       => $_POST['pump_schedule']              ?? 'smart',
                'pump_wattage'        => (int)($_POST['pump_wattage']         ?? 100),
                'notif_low_water'     => isset($_POST['notif_low_water'])     ? '1' : '0',
                'notif_heavy_rain'    => isset($_POST['notif_heavy_rain'])    ? '1' : '0',
                'notif_pump_failure'  => isset($_POST['notif_pump_failure'])  ? '1' : '0',
                'notif_weekly'        => isset($_POST['notif_weekly'])        ? '1' : '0',
                'notif_monthly'       => isset($_POST['notif_monthly'])       ? '1' : '0',
                'ph_min'              => $_POST['ph_min']                     ?? '6.5',
                'ph_max'              => $_POST['ph_max']                     ?? '8.5',
                'tds_threshold'       => (int)($_POST['tds_threshold']        ?? 100),
                'test_frequency'      => $_POST['test_frequency']             ?? 'every_6h',
                'account_email'       => trim($_POST['account_email']         ?? ''),
                'account_timezone'    => $_POST['account_timezone']           ?? 'Asia/Manila',
            ];
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ($settings as $k => $v) $stmt->execute([$k, $v]);
            $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, role, action, status, ip_address) VALUES (?, ?, ?, 'update_settings', 'success', ?)")
                ->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '', $_SESSION['role'] ?? 'admin', $_SERVER['REMOTE_ADDR'] ?? '']);
            $rows    = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
            $tank    = $pdo->query("SELECT * FROM tank LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $success = 'Settings saved successfully.';
        } catch (PDOException $e) { $error = 'Database error: ' . $e->getMessage(); }
    }

    $me = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $me->execute([$_SESSION['user_id']]);
    $me       = $me->fetch(PDO::FETCH_ASSOC);
    $initials = strtoupper(substr($me['username'] ?? $me['email'] ?? 'A', 0, 2));

    $maxCap  = (int)cfg($rows, 'tank_capacity', $tank['max_capacity'] ?? 5000);
    $threshV = (int)cfg($rows, 'threshold', 1000);
    $pct     = $maxCap > 0 ? round($threshV / $maxCap * 100) : 20;
}


/* ============================================================
   SECTION: USAGE DATA
   ============================================================ */
if ($page === 'usage') {

    $totalCollected = (float)$pdo->query("SELECT COALESCE(SUM(usage_liters),0) FROM water_usage WHERE usage_type != 'Tap'")->fetchColumn();
    $totalTap       = (float)$pdo->query("SELECT COALESCE(SUM(usage_liters),0) FROM water_usage WHERE usage_type = 'Tap'")->fetchColumn();
    $netSavings     = max(0, $totalCollected - $totalTap);
    $avgDaily       = (float)$pdo->query("SELECT COALESCE(AVG(daily_sum),0) FROM (SELECT DATE(recorded_at) d, SUM(usage_liters) daily_sum FROM water_usage WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND usage_type != 'Tap' GROUP BY DATE(recorded_at)) t")->fetchColumn();
    $thisMonth      = (float)$pdo->query("SELECT COALESCE(SUM(usage_liters),0) FROM water_usage WHERE MONTH(recorded_at)=MONTH(CURDATE()) AND YEAR(recorded_at)=YEAR(CURDATE()) AND usage_type != 'Tap'")->fetchColumn();
    $lastMonth      = (float)$pdo->query("SELECT COALESCE(SUM(usage_liters),0) FROM water_usage WHERE MONTH(recorded_at)=MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(recorded_at)=YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND usage_type != 'Tap'")->fetchColumn();
    $pctChange      = $lastMonth > 0 ? round(($thisMonth - $lastMonth) / $lastMonth * 100, 1) : 0;

    $trend30 = $pdo->query("SELECT DATE(recorded_at) AS d, SUM(usage_liters) AS total FROM water_usage WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND usage_type != 'Tap' GROUP BY DATE(recorded_at) ORDER BY d ASC")->fetchAll(PDO::FETCH_ASSOC);
    $trend30Map = []; foreach ($trend30 as $r) $trend30Map[$r['d']] = (float)$r['total'];
    $trendLabels = $trendData = [];
    for ($i = 29; $i >= 0; $i--) { $day = date('Y-m-d', strtotime("-$i days")); $trendLabels[] = date('M j', strtotime($day)); $trendData[] = $trend30Map[$day] ?? 0; }

    $monthly      = $pdo->query("SELECT DATE_FORMAT(recorded_at,'%b') AS mon, DATE_FORMAT(recorded_at,'%Y-%m') AS ym, SUM(CASE WHEN usage_type != 'Tap' THEN usage_liters ELSE 0 END) AS rainwater, SUM(CASE WHEN usage_type = 'Tap' THEN usage_liters ELSE 0 END) AS tap FROM water_usage WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym, mon ORDER BY ym ASC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    $barLabels    = array_column($monthly, 'mon');
    $barRainwater = array_column($monthly, 'rainwater');
    $barTap       = array_column($monthly, 'tap');

    $breakdown   = $pdo->query("SELECT usage_type, SUM(usage_liters) AS total FROM water_usage GROUP BY usage_type ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);
    $breakLabels = array_column($breakdown, 'usage_type');
    $breakData   = array_map('floatval', array_column($breakdown, 'total'));

    $recentUsage = $pdo->query("SELECT wu.usage_type, wu.usage_liters, wu.recorded_at, t.tankname, u.email FROM water_usage wu LEFT JOIN tank t ON wu.tank_id = t.tank_id LEFT JOIN users u ON wu.user_id = u.id ORDER BY wu.recorded_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
}


/* ============================================================
   SECTION: USER LOGS DATA & POST HANDLING
   ============================================================ */
if ($page === 'userlogs') {

    $action  = $_POST['action'] ?? '';
    $success = ''; $error = '';

    if ($action === 'add') {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');
        $role     = $_POST['role'] ?? 'user';
        if (!$email || !$password) { $error = 'Email and password are required.'; }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Invalid email address.'; }
        else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, ?)")->execute([$email, $hash, $role]);
                $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, action, status, ip_address) VALUES (?, ?, 'add_user', 'success', ?)")->execute([$pdo->lastInsertId(), $email, $_SERVER['REMOTE_ADDR'] ?? '']);
                $success = "User <strong>$email</strong> added successfully.";
            } catch (PDOException $e) {
                $error = strpos($e->getMessage(), 'Duplicate') !== false ? 'That email is already registered.' : 'Database error: ' . $e->getMessage();
            }
        }
    }
    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0); $email = trim($_POST['email'] ?? ''); $role = $_POST['role'] ?? 'user';
        if (!$id || !$email) { $error = 'Invalid data.'; }
        else {
            try {
                if (!empty($_POST['password'])) {
                    $pdo->prepare("UPDATE users SET email=?, password=?, role=? WHERE id=?")->execute([$email, password_hash($_POST['password'], PASSWORD_DEFAULT), $role, $id]);
                } else {
                    $pdo->prepare("UPDATE users SET email=?, role=? WHERE id=?")->execute([$email, $role, $id]);
                }
                $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, action, status, ip_address) VALUES (?, ?, 'edit_user', 'success', ?)")->execute([$id, $email, $_SERVER['REMOTE_ADDR'] ?? '']);
                $success = "User updated successfully.";
            } catch (PDOException $e) { $error = 'Database error: ' . $e->getMessage(); }
        }
    }
    if ($action === 'delete') {
        $ids = [];
        if (!empty($_POST['id']))  $ids = [(int)$_POST['id']];
        elseif (!empty($_POST['ids'])) $ids = array_map('intval', explode(',', $_POST['ids']));
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            try {
                $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)")->execute($ids);
                $pdo->prepare("INSERT INTO user_activity_logs (action, status, ip_address) VALUES ('delete_user', 'success', ?)")->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
                $success = count($ids) . ' user(s) deleted successfully.';
            } catch (PDOException $e) { $error = 'Database error: ' . $e->getMessage(); }
        }
    }

    $search  = trim($_GET['q'] ?? '');
    $pgNum   = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 10;
    $offset  = ($pgNum - 1) * $perPage;

    if ($search) {
        $like  = "%$search%";
        $total = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email LIKE ? OR role LIKE ?");
        $total->execute([$like, $like]);
        $usersQ = $pdo->prepare("SELECT * FROM users WHERE email LIKE ? OR role LIKE ? ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
        $usersQ->execute([$like, $like]);
    } else {
        $total  = $pdo->query("SELECT COUNT(*) FROM users");
        $usersQ = $pdo->query("SELECT * FROM users ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
    }
    $totalUsersCount = (int)$total->fetchColumn();
    $userRows        = $usersQ->fetchAll();
    $totalPagesUL    = max(1, ceil($totalUsersCount / $perPage));

    $logs = $pdo->query("SELECT ual.*, u.email AS user_email FROM user_activity_logs ual LEFT JOIN users u ON ual.user_id = u.id ORDER BY ual.created_at DESC LIMIT 10")->fetchAll();
}


/* ============================================================
   SECTION: WEATHER DATA
   ============================================================ */
if ($page === 'weather') {

    $API_KEY = 'a5712e740541248ce7883f0af8581be4';
    $LAT     = 8.360015;
    $LON     = 124.868419;
    $CITY    = 'Manolo Fortich, Bukidnon';

    $currentWeather = fetchJson("https://api.openweathermap.org/data/2.5/weather?lat={$LAT}&lon={$LON}&appid={$API_KEY}&units=metric");
    $forecastData   = fetchJson("https://api.openweathermap.org/data/2.5/forecast?lat={$LAT}&lon={$LON}&appid={$API_KEY}&units=metric&cnt=40");

    $temp        = $currentWeather ? round($currentWeather['main']['temp'])        : '--';
    $feelsLike   = $currentWeather ? round($currentWeather['main']['feels_like'])  : '--';
    $humidity    = $currentWeather ? $currentWeather['main']['humidity']           : '--';
    $windSpeed   = $currentWeather ? round($currentWeather['wind']['speed'] * 3.6) : '--';
    $visibility  = $currentWeather ? round(($currentWeather['visibility'] ?? 10000) / 1000) : '--';
    $pressure    = $currentWeather ? $currentWeather['main']['pressure']           : '--';
    $description = $currentWeather ? ucfirst($currentWeather['weather'][0]['description']) : 'N/A';
    $weatherId   = $currentWeather ? $currentWeather['weather'][0]['id']           : 800;
    $cloudiness  = $currentWeather ? $currentWeather['clouds']['all']              : 0;
    $weatherIcon = weatherEmoji($weatherId);

    /* 7-day daily forecast */
    $daily = [];
    if ($forecastData && isset($forecastData['list'])) {
        $seen = [];
        foreach ($forecastData['list'] as $item) {
            $date = date('Y-m-d', $item['dt']);
            $hour = (int)date('H', $item['dt']);
            if (!isset($seen[$date]) || abs($hour - 12) < abs((int)date('H', $seen[$date]['dt']) - 12)) {
                $seen[$date] = $item;
            }
        }
        $daily = array_values(array_slice($seen, 0, 7));
    }

    /* Rainfall chart (last 14 days from water_usage) */
    $rainfall14 = $pdo->query("SELECT DATE(recorded_at) AS d, SUM(usage_liters) AS mm FROM water_usage WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND usage_type != 'Tap' GROUP BY DATE(recorded_at) ORDER BY d ASC")->fetchAll(PDO::FETCH_ASSOC);
    $rfMap = []; foreach ($rainfall14 as $r) $rfMap[$r['d']] = round((float)$r['mm'] / 10, 1);
    $rfLabels = $rfData = [];
    for ($i = 13; $i >= 0; $i--) { $day = date('Y-m-d', strtotime("-$i days")); $rfLabels[] = date('M j', strtotime($day)); $rfData[] = $rfMap[$day] ?? 0; }

    /* Sensor summary */
    $totalReadings = (int)$pdo->query("SELECT COUNT(*) FROM sensor_readings")->fetchColumn();
    $rainReadings  = (int)$pdo->query("SELECT COUNT(*) FROM sensor_readings WHERE anomaly != 'None'")->fetchColumn();
    $alertDays     = (int)$pdo->query("SELECT COUNT(DISTINCT DATE(recorded_at)) FROM sensor_readings WHERE anomaly = 'High'")->fetchColumn();
    $normalPct = $totalReadings > 0 ? round(($totalReadings - $rainReadings) / $totalReadings * 100) : 84;
    $rainPct   = $totalReadings > 0 ? round(($rainReadings - $alertDays)    / $totalReadings * 100) : 11;
    $alertPct  = max(0, 100 - $normalPct - $rainPct);

    /* Weather alert logic */
    $rainAlert = false; $alertMsg = '';
    if ($forecastData && isset($forecastData['list'])) {
        foreach (array_slice($forecastData['list'], 0, 8) as $item) {
            $pop = ($item['pop'] ?? 0) * 100;
            if ($pop >= 70) { $rainAlert = true; $alertMsg = 'Heavy rain expected in the next 24 hours (' . round($pop) . '% chance). Check tank overflow settings.'; break; }
        }
    }
    if (!$rainAlert && $temp !== '--' && $temp > 32) {
        $rainAlert = true;
        $alertMsg  = "Temperature is {$temp}°C. Heat risk — stay hydrated and limit outdoor exposure between 11 AM – 3 PM.";
    }
}


/* ============================================================
   SECTION: HTML HEAD
   Shared fonts, Chart.js, Leaflet (map only),
   Bootstrap (userlogs only), then our two asset files
   ============================================================ */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>EcoRain — <?= htmlspecialchars($pageLabels[$page]) ?></title>

  <!-- Shared fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=Barlow+Condensed:wght@400;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

  <?php if ($page === 'map'): /* Leaflet CSS (map only) */ ?>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <?php endif; ?>

  <?php if ($page === 'userlogs'): /* Bootstrap 4 (userlogs only) */ ?>
  <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
  <?php endif; ?>

  <!-- Shared admin stylesheet -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/app/admin/admin_style.css">

  <!-- Shared legacy stylesheet (kept from original) -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/others/all.css">

  <!-- Chart.js (all pages except map & userlogs) -->
  <?php if (!in_array($page, ['map'])): ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <?php endif; ?>

  <!-- PHP → JS data bridge: inject page-specific data before admin_script.js -->
  <script>
    /* ---- Active page identifier used by the JS router ---- */
    var ECORAIN_PAGE = '<?= $page ?>';

    /* ---- Page data bundle ---- */
    var ECORAIN_DATA = <?php
      /* Build the data object based on the current page */
      $jsData = [];

      if ($page === 'dashboard') {
          $jsData['chartLabels'] = $chartLabels;
          $jsData['chartData']   = $chartData;
          $jsData['wx'] = [
              'key' => 'a5712e740541248ce7883f0af8581be4',
              'lat' => 8.360015,
              'lon' => 124.868419,
          ];
      }

      if ($page === 'map') {
          $jsData['tanks'] = array_map(function($t) {
              return [
                  'id'       => $t['tank_id'],
                  'name'     => $t['tankname'],
                  'location' => $t['location_add'],
                  'liters'   => $t['current_liters'],
                  'capacity' => $t['max_capacity'],
                  'pct'      => $t['fill_pct'],
                  'status'   => strtolower($t['status_tank']),
              ];
          }, $tanks);
      }

      if ($page === 'oversight') {
          $jsData['roleChartLabels']  = $roleChartLabels;
          $jsData['roleChartAdmin']   = $roleChartAdmin;
          $jsData['roleChartManager'] = $roleChartManager;
          $jsData['roleChartUser']    = $roleChartUser;
          $jsData['chartActionLabels']= $chartActionLabels;
          $jsData['chartActionData']  = $chartActionData;
      }

      if ($page === 'settings') {
          $jsData['maxCap']        = $maxCap;
          $jsData['settingsSaved'] = !empty($success);
      }

      if ($page === 'usage') {
          $jsData['usage'] = [
              'trendLabels'  => $trendLabels,
              'trendData'    => $trendData,
              'barLabels'    => $barLabels ?: ['No data'],
              'barRainwater' => array_map('floatval', $barRainwater) ?: [0],
              'barTap'       => array_map('floatval', $barTap) ?: [0],
              'breakLabels'  => $breakLabels ?: ['No data'],
              'breakData'    => $breakData   ?: [0],
          ];
      }

      if ($page === 'weather') {
          $jsData['weather'] = [
              'rfLabels'  => $rfLabels,
              'rfData'    => $rfData,
              'normalPct' => $normalPct,
              'rainPct'   => $rainPct,
              'alertPct'  => $alertPct,
          ];
      }

      echo json_encode($jsData, JSON_UNESCAPED_UNICODE);
    ?>;
  </script>
</head>
<body>


<?php /* ============================================================
   SECTION: OVERLAY (mobile sidebar backdrop — all pages)
   ============================================================ */ ?>
<div class="overlay" id="overlay" onclick="closeSidebar()"></div>


<?php /* ============================================================
   SECTION: SIDEBAR — shared by all pages
   All hrefs updated to admin_master.php?page=...
   ============================================================ */ ?>
<aside class="sidebar" id="sidebar">
  <div class="logo">
    <span class="logo-icon">💧</span>
    <span class="logo-text">EcoRain</span>
  </div>

  <div class="nav-section-label">Overview</div>
  <a href="<?= BASE_URL ?>/app/admin/admin_master.php?page=dashboard"
     class="nav-item <?= $page === 'dashboard' ? 'active' : '' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
      <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
    </svg>
    <span>Dashboard</span>
  </a>
  <a href="<?= BASE_URL ?>/app/admin/admin_master.php?page=oversight"
     class="nav-item <?= $page === 'oversight' ? 'active' : '' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
    </svg>
    <span>Admin Oversight</span>
  </a>
  <a href="<?= BASE_URL ?>/app/admin/admin_master.php?page=usage"
     class="nav-item <?= $page === 'usage' ? 'active' : '' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
    </svg>
    <span>Usage Stats</span>
  </a>
  <a href="<?= BASE_URL ?>/app/admin/admin_master.php?page=weather"
     class="nav-item <?= $page === 'weather' ? 'active' : '' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
    </svg>
    <span>Weather</span>
  </a>
  <a href="<?= BASE_URL ?>/app/admin/admin_master.php?page=map"
     class="nav-item <?= $page === 'map' ? 'active' : '' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>
    </svg>
    <span>Tank Map</span>
  </a>

  <div class="nav-section-label">Management</div>
  <a href="<?= BASE_URL ?>/app/admin/admin_master.php?page=userlogs"
     class="nav-item <?= $page === 'userlogs' ? 'active' : '' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>
      <path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>
    </svg>
    <span>Users &amp; Roles</span>
  </a>
  <a href="<?= BASE_URL ?>/app/admin/admin_master.php?page=settings"
     class="nav-item <?= $page === 'settings' ? 'active' : '' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <circle cx="12" cy="12" r="3"/>
      <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
    </svg>
    <span>Settings</span>
  </a>

  <div class="sidebar-spacer"></div>
  <div class="sidebar-bottom">
    <a href="<?= BASE_URL ?>/connections/signout.php" class="nav-item logout">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>
      </svg>
      <span>Log Out</span>
    </a>
  </div>
</aside>


<?php /* ============================================================
   SECTION: APP BODY WRAPPER — wraps topbar + main for all pages
   ============================================================ */ ?>
<div class="app-body">

  <?php /* ============================================================
     SECTION: TOPBAR — shared by all pages
     ============================================================ */ ?>
  <header class="topbar">
    <div class="topbar-left">
      <button class="hamburger" onclick="toggleSidebar()" aria-label="Menu">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <line x1="3" y1="6" x2="21" y2="6"/>
          <line x1="3" y1="12" x2="21" y2="12"/>
          <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
      <div>
        <div class="page-title">
          <?php if ($page === 'oversight'): ?><span class="live-dot"></span><?php endif; ?>
          <?= htmlspecialchars($pageLabels[$page]) ?>
        </div>
        <div class="page-sub">
          <?php
            $subs = [
              'dashboard' => 'Welcome to EcoRain',
              'map'       => 'Monitor your tank network',
              'oversight' => 'Full system visibility &amp; audit trail',
              'settings'  => 'Configure your EcoRain System',
              'usage'     => 'Track your water conservation impact',
              'userlogs'  => 'Manage system users',
              'weather'   => 'Live conditions — Manolo Fortich, Bukidnon',
            ];
            echo $subs[$page];
          ?>
        </div>
      </div>
    </div>
    <div class="topbar-right">
      <div class="t-search">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="text" placeholder="Search..."
               <?php if ($page === 'map'): ?>id="searchInput"<?php endif; ?>>
      </div>
      <div class="t-btn">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
          <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 01-3.46 0"/>
        </svg>
        <span class="notif-dot"></span>
      </div>
      <a href="<?= BASE_URL ?>/app/admin/admin_master.php?page=userlogs" class="t-avatar">
        <?= htmlspecialchars($initials) ?>
      </a>
    </div>
  </header>


  <?php /* ============================================================
     SECTION: MAIN CONTENT SWITCH
     Each case renders the full page body for that feature
     ============================================================ */ ?>
  <main class="main">
  <?php switch ($page):


    /* ============================================================
       SECTION: DASHBOARD START
       ============================================================ */
    case 'dashboard': ?>

      <!-- TOP ROW: Tank card | Water Quality | Chart -->
      <div class="top-row">

        <!-- Tank Card — aggregated across ALL tanks -->
        <div class="tank-card" style="background:<?= $tankBg ?>">
          <div class="tank-header">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/>
              <path d="M12 8v4l3 3"/>
            </svg>
            Total Tank Level — <?= $tankCount ?> Tank<?= $tankCount !== 1 ? 's' : '' ?>
          </div>
          <div class="tank-percent-big"><?= $percent ?>%</div>
          <div class="tank-liters-sub">of <?= number_format($totalMaxCapacity) ?>L combined capacity</div>

          <?php if ($tankCount > 1): ?>
          <div class="tank-stats-row">
            <?php foreach ($allTanks as $t):
              $tPct   = $t['max_capacity'] > 0 ? round(($t['current_liters'] / $t['max_capacity']) * 100) : 0;
              $tColor = $tPct >= 50 ? '#2563eb' : ($tPct >= 20 ? '#d97706' : '#dc2626');
            ?>
            <span class="tank-stat-chip">
              <span class="chip-dot" style="background:<?= $tColor ?>"></span>
              <?= htmlspecialchars($t['tankname']) ?> <?= $tPct ?>%
            </span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <div class="tank-footer">
            <div class="tank-meta">
              <span><?= number_format($totalCurrentLiters) ?>L stored</span>
              <span><?= number_format($totalMaxCapacity) ?>L max</span>
            </div>
            <div class="tank-bar-bg">
              <div class="tank-bar-fill" style="width:<?= $percent ?>%;background:<?= $tankAccent ?>"></div>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
              <div class="tank-collected">💧 <?= number_format($todayCollected, 0) ?>L collected today</div>
              <a href="<?= BASE_URL ?>/app/admin/admin_master.php?page=map" class="tank-view-map">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:13px;height:13px">
                  <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/>
                  <circle cx="12" cy="10" r="3"/>
                </svg>
                View map
              </a>
            </div>
          </div>
        </div>

        <!-- Water Quality -->
        <div class="card">
          <div class="card-label">Water Quality</div>
          <div class="wq-top">
            <span class="wq-status-badge"><?= $quality ? htmlspecialchars($quality['quality_status']) : 'N/A' ?></span>
            <span class="wq-updated">Updated <?= $updatedAgo ?></span>
          </div>
          <div class="wq-metric">
            <div class="wq-metric-hd">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
              pH Level
            </div>
            <div class="wq-val"><?= $quality ? $quality['ph_level'] : '0.0' ?></div>
            <div class="wq-lbl" style="color:<?= $quality ? phColor($quality['ph_level']) : '#16a34a' ?>">
              <?= $quality ? phLabel($quality['ph_level']) : 'None' ?>
            </div>
          </div>
          <div class="wq-metric">
            <div class="wq-metric-hd">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8.56 2.75c4.37 6.03 6.02 9.42 8.03 17.72m2.54-15.38c-3.72 4.35-8.94 5.66-16.88 5.85m19.5 1.9c-3.5-.93-6.63-.82-8.94 0-2.58.92-5.01 2.86-7.44 6.32"/></svg>
              Turbidity
            </div>
            <div class="wq-val"><?= $quality ? $quality['turbidity'] : '0.0' ?></div>
            <div class="wq-lbl" style="color:<?= $quality ? turbColor($quality['turbidity']) : '#16a34a' ?>">
              <?= $quality ? turbLabel($quality['turbidity']) : 'None' ?>
            </div>
          </div>
        </div>

        <!-- Usage Chart -->
        <div class="card">
          <div class="chart-title">Water Usage — Last 7 Days</div>
          <div class="chart-wrap"><canvas id="bar-chart"></canvas></div>
        </div>

      </div><!-- /top-row -->

      <!-- MID ROW: Forecast | Sensor Readings -->
      <div class="mid-row">
        <div class="card">
          <div class="forecast-title" id="wx-location">Rainfall Forecast</div>
          <div id="wx-error" style="display:none;color:#ef4444;font-size:.8rem;margin-bottom:.5rem"></div>
          <div id="wx-loading" style="color:var(--subtle);font-size:.82rem">Loading forecast...</div>
          <div id="forecastSection" style="display:none">
            <div class="forecast-inner" id="rainfallForecast"></div>
          </div>
        </div>

        <div class="card">
          <div class="card-label">Sensor Readings</div>
          <?php if ($sensors): ?>
            <div style="overflow-x:auto">
              <table class="mini-table">
                <thead><tr><th>Sensor</th><th>Model</th><th>Anomaly</th><th>Time</th></tr></thead>
                <tbody>
                  <?php foreach ($sensors as $s): ?>
                  <tr>
                    <td><?= htmlspecialchars($s['sensor_type']) ?></td>
                    <td style="color:var(--subtle)"><?= htmlspecialchars($s['model']) ?></td>
                    <td><span class="badge"><?= htmlspecialchars($s['anomaly']) ?></span></td>
                    <td><?= date('H:i', strtotime($s['recorded_at'])) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p style="color:var(--subtle);font-size:.82rem;margin-top:.35rem">No readings yet.</p>
          <?php endif; ?>
        </div>
      </div><!-- /mid-row -->

      <!-- BOTTOM ROW: Activity Log | Fleet Summary -->
      <div class="bottom-row">
        <div class="card">
          <div class="card-label">Activity Log</div>
          <?php if ($activities): ?>
            <div style="overflow-x:auto">
              <table class="mini-table">
                <thead><tr><th>User</th><th>Action</th><th>Time</th></tr></thead>
                <tbody>
                  <?php foreach ($activities as $a): ?>
                  <tr>
                    <td class="u-link">User #<?= htmlspecialchars($a['user_id']) ?></td>
                    <td><?= htmlspecialchars($a['action']) ?></td>
                    <td><?= date('M j, H:i', strtotime($a['created_at'])) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p style="color:var(--subtle);font-size:.82rem;margin-top:.35rem">No activity yet.</p>
          <?php endif; ?>
        </div>

        <!-- Fleet Summary -->
        <div class="card">
          <div class="card-label">Fleet Summary</div>
          <?php if (!empty($allTanks)): ?>
            <div style="overflow-x:auto">
              <table class="mini-table" style="table-layout:fixed">
                <colgroup><col style="width:22%"><col style="width:48%"><col style="width:30%"></colgroup>
                <thead><tr><th>Tank</th><th>Fill</th><th style="text-align:right">Status</th></tr></thead>
                <tbody>
                  <?php foreach ($allTanks as $t):
                    $tPct   = $t['max_capacity'] > 0 ? round(($t['current_liters'] / $t['max_capacity']) * 100) : 0;
                    $tColor = $tPct >= 75 ? '#3b82f6' : ($tPct >= 40 ? '#f59e0b' : '#ef4444');
                    $tS     = strtolower($t['status_tank']);
                  ?>
                  <tr>
                    <td style="font-weight:600;color:var(--text)"><?= htmlspecialchars($t['tankname']) ?></td>
                    <td>
                      <div style="display:flex;align-items:center;gap:.5rem">
                        <div style="flex:1;height:5px;background:var(--border);border-radius:99px;overflow:hidden">
                          <div style="width:<?= $tPct ?>%;height:100%;background:<?= $tColor ?>;border-radius:99px"></div>
                        </div>
                        <span style="font-size:.73rem;font-weight:600;color:var(--muted);min-width:2.4rem;text-align:right"><?= $tPct ?>%</span>
                      </div>
                    </td>
                    <td style="text-align:right">
                      <span class="badge" style="<?= $tS === 'active' ? '' : 'background:#fef2f2;color:#b91c1c;border-color:#fecaca' ?>">
                        <?= htmlspecialchars($t['status_tank']) ?>
                      </span>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:1rem;padding-top:.75rem;border-top:1px solid var(--border)">
              <span style="font-size:.78rem;color:var(--muted)"><?= $onlineCount ?>/<?= $tankCount ?> tanks online</span>
              <span style="font-size:.78rem;font-weight:600;color:var(--text)"><?= number_format($totalCurrentLiters) ?>L / <?= number_format($totalMaxCapacity) ?>L</span>
            </div>
          <?php else: ?>
            <p style="color:var(--subtle);font-size:.82rem;margin-top:.35rem">No tank data.</p>
          <?php endif; ?>
        </div>
      </div><!-- /bottom-row -->

    <?php break; /* SECTION: DASHBOARD END */


    /* ============================================================
       SECTION: MAP START
       ============================================================ */
    case 'map': ?>

      </main><!-- close .main early; map uses its own full-height layout -->
    </div><!-- close .app-body early -->

    <!-- MAP CONTENT: full-height flex layout -->
    <div style="display:flex;flex:1;overflow:hidden;height:calc(100vh - 60px)">

      <!-- Map panel -->
      <div class="map-panel">
        <div class="live-badge">Live Network View</div>
        <div id="map"></div>
        <button class="fab-panel" id="fabPanel" onclick="openPanel()">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>
          </svg>
          <?= count($tanks) ?> Tanks
        </button>
      </div>

      <!-- Right panel: tank list -->
      <div class="right-panel" id="rightPanel">
        <div class="panel-header">
          <h3>Tank Locations</h3>
          <div style="display:flex;align-items:center;gap:8px">
            <span class="tank-count"><?= count($tanks) ?> Tanks</span>
            <button class="panel-close" onclick="closePanel()" aria-label="Close">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>
        </div>

        <!-- Mobile search inside panel -->
        <div style="padding:10px 12px 0;display:none" id="panelSearch">
          <div class="search-box" style="min-width:unset;width:100%">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" placeholder="Search tanks..." id="searchInputMobile">
          </div>
        </div>

        <div class="tank-list" id="tankList">
          <?php foreach ($tanks as $i => $tank):
            $pct      = (int)$tank['fill_pct'];
            $color    = barColor($pct);
            $s        = strtolower($tank['status_tank']);
            $dotClass = $s === 'active' ? '' : ($s === 'maintenance' ? 'warn' : 'offline');
            $ago      = timeAgo($tank['last_reading']);
          ?>
          <div class="map-tank-card <?= $i === 0 ? 'selected' : '' ?>"
               id="card-<?= $tank['tank_id'] ?>"
               data-tank-id="<?= $tank['tank_id'] ?>"
               onclick="focusTank(<?= $tank['tank_id'] ?>)">
            <div class="card-head">
              <h4><span class="dot <?= $dotClass ?>"></span><?= htmlspecialchars($tank['tankname']) ?></h4>
              <?= statusBadge($tank['status_tank']) ?>
            </div>
            <div class="card-location"><?= htmlspecialchars($tank['location_add']) ?></div>
            <div>
              <div class="fill-row">
                <span class="fill-pct"><?= $pct ?>%</span>
                <span class="fill-liters"><?= number_format($tank['current_liters']) ?>L / <?= number_format($tank['max_capacity']) ?>L</span>
              </div>
              <div class="progress-bar">
                <div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
              </div>
            </div>
            <div class="card-updated">🕐 Updated <?= $ago ?></div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($tanks)): ?>
          <div style="text-align:center;padding:40px;color:var(--text-3)">No tanks found.</div>
          <?php endif; ?>
        </div>

        <!-- Fleet summary footer -->
        <div class="fleet-summary">
          <h5>Fleet Summary</h5>
          <div class="fleet-grid">
            <div class="fleet-stat">
              <label>Total Stored</label>
              <div class="value"><?= number_format($totalStored) ?>L</div>
            </div>
            <div class="fleet-stat">
              <label>System Status</label>
              <div class="value"><span class="sys-dot"></span><?= $systemStatus ?></div>
            </div>
          </div>
          <div class="overall-row">
            <span>Overall Capacity</span><span><?= $overallPct ?>%</span>
          </div>
          <div class="progress-bar" style="height:8px">
            <div class="progress-fill" style="width:<?= $overallPct ?>%;background:var(--accent)"></div>
          </div>
        </div>
      </div><!-- /right-panel -->

    </div><!-- /map content wrapper -->

    <?php break; /* SECTION: MAP END */


    /* ============================================================
       SECTION: OVERSIGHT START
       ============================================================ */
    case 'oversight': ?>

      <!-- Stat cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background:#eff6ff">👥</div>
          <div><div class="stat-num"><?= $totalUsers ?></div><div class="stat-lbl">Total Users</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#ecfdf5">🟢</div>
          <div><div class="stat-num"><?= $onlineToday ?></div><div class="stat-lbl">Active Today</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#f5f3ff">📋</div>
          <div><div class="stat-num"><?= $totalActions ?></div><div class="stat-lbl">Actions Today</div></div>
        </div>
        <div class="stat-card <?= $criticalCount > 0 ? 'critical' : '' ?>">
          <div class="stat-icon" style="background:#fef2f2">🚨</div>
          <div><div class="stat-num"><?= $criticalCount ?></div><div class="stat-lbl">Open Alerts</div></div>
        </div>
      </div>

      <!-- Charts -->
      <div class="charts-row">
        <div class="card">
          <div class="card-hd">
            <span class="card-title">Activity by Role — Last 7 Days</span>
            <span class="card-label">actions</span>
          </div>
          <div class="chart-wrap"><canvas id="roleChart"></canvas></div>
        </div>
        <div class="card">
          <div class="card-hd">
            <span class="card-title">Top Actions — Last 7 Days</span>
            <span class="card-label">frequency</span>
          </div>
          <div class="chart-wrap"><canvas id="actionChart"></canvas></div>
        </div>
      </div>

      <!-- Users panel -->
      <div class="card">
        <div class="card-hd">
          <span class="card-title">All Users
            <span style="font-size:.72rem;font-weight:400;color:var(--muted);margin-left:.4rem">
              <?= ($roleCounts['admin'] ?? 0) ?> admin · <?= ($roleCounts['manager'] ?? 0) ?> manager · <?= ($roleCounts['user'] ?? 0) ?> user
            </span>
          </span>
          <a href="<?= BASE_URL ?>/app/admin/admin_master.php?page=userlogs" style="font-size:.78rem;font-weight:600;color:var(--accent);text-decoration:none">Manage →</a>
        </div>
        <div class="users-grid">
          <?php foreach ($users as $u):
            $ini     = strtoupper(substr($u['username'], 0, 2));
            $lastSeen = $u['last_seen'] ? timeAgo($u['last_seen']) : 'never';
          ?>
          <div class="user-card">
            <div class="user-avatar" style="background:<?= roleColor($u['role']) ?>"><?= htmlspecialchars($ini) ?></div>
            <div style="flex:1;min-width:0">
              <div class="user-name"><?= htmlspecialchars($u['username']) ?></div>
              <div class="user-email"><?= htmlspecialchars($u['email']) ?></div>
              <div class="user-meta">
                <?= roleBadge($u['role']) ?>
                <span class="user-stat">last: <?= $lastSeen ?></span>
                <span class="user-stat">today: <?= $u['today_actions'] ?> acts</span>
                <?php if (!$u['is_verified']): ?>
                  <span class="badge" style="background:#fff7ed;color:#c2410c;border-color:#fed7aa">unverified</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Tabs: Activity Log | Page Visits | System Alerts -->
      <div class="card">
        <div class="tabs">
          <div class="tab active" onclick="switchTab('log',this)">Activity Log</div>
          <div class="tab" onclick="switchTab('visits',this)">Page Visits</div>
          <div class="tab" onclick="switchTab('alerts',this)">
            System Alerts
            <?php if ($criticalCount > 0): ?>
              <span style="background:#ef4444;color:#fff;border-radius:99px;font-size:.65rem;padding:.1rem .4rem;margin-left:.3rem"><?= $criticalCount ?></span>
            <?php endif; ?>
          </div>
          <a href="?page=oversight&export_report=1" class="t-btn" title="Export Full Report (CSV)"
             style="width:auto;padding:0 .65rem;gap:.35rem;font-size:.75rem;font-weight:600;color:var(--accent);border-color:var(--accent);text-decoration:none;display:inline-flex;align-items:center;margin-left:auto">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:14px;height:14px">
              <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
              <polyline points="7 10 12 15 17 10"/>
              <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Export Report
          </a>
        </div>

        <!-- Tab: Activity Log -->
        <div id="tab-log">
          <form method="get" class="filter-bar">
            <input type="hidden" name="page" value="oversight">
            <input type="text" name="user" placeholder="Search user/email…" value="<?= htmlspecialchars($filterUser) ?>">
            <select name="role">
              <option value="">All roles</option>
              <option value="admin"   <?= $filterRole === 'admin'   ? 'selected' : '' ?>>Admin</option>
              <option value="manager" <?= $filterRole === 'manager' ? 'selected' : '' ?>>Manager</option>
              <option value="user"    <?= $filterRole === 'user'    ? 'selected' : '' ?>>User</option>
            </select>
            <input type="text" name="action" placeholder="Action keyword…" value="<?= htmlspecialchars($filterAction) ?>">
            <input type="date"  name="date"  value="<?= htmlspecialchars($filterDate) ?>">
            <button type="submit" class="btn-sm btn-primary">Filter</button>
            <a href="?page=oversight" class="btn-sm btn-ghost" style="display:inline-flex;align-items:center;text-decoration:none">Reset</a>
          </form>

          <div class="tbl-wrap">
            <table class="tbl">
              <thead>
                <tr><th>#</th><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Description</th><th>Severity</th><th>IP</th><th>When</th></tr>
              </thead>
              <tbody>
                <?php if (empty($logs)): ?>
                  <tr><td colspan="9" style="text-align:center;color:var(--subtle);padding:1.5rem 0">No activity records found.</td></tr>
                <?php else: foreach ($logs as $log): ?>
                <tr>
                  <td style="color:var(--subtle);font-size:.72rem"><?= $log['activity_id'] ?></td>
                  <td>
                    <div style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($log['username'] ?? 'System') ?></div>
                    <div style="font-size:.7rem;color:var(--subtle)"><?= htmlspecialchars($log['user_email'] ?? '') ?></div>
                  </td>
                  <td><?= roleBadge($log['role'] ?? 'user') ?></td>
                  <td><span class="action-chip"><?= actionIcon($log['action']) ?> <?= htmlspecialchars($log['action']) ?></span></td>
                  <td style="color:var(--muted);font-size:.75rem"><?= htmlspecialchars($log['module'] ?? '—') ?></td>
                  <td style="font-size:.75rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($log['description'] ?? '') ?>"><?= htmlspecialchars($log['description'] ?? '—') ?></td>
                  <td><?= severityBadge($log['severity'] ?? 'info') ?></td>
                  <td><span class="ip-chip"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></span></td>
                  <td style="white-space:nowrap;font-size:.75rem;color:var(--muted)" title="<?= $log['created_at'] ?>"><?= timeAgo($log['created_at']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

          <?php if ($totalPages > 1): ?>
          <div class="pager">
            <?php
              $qs = http_build_query(array_filter(['page' => 'oversight', 'role' => $filterRole, 'action' => $filterAction, 'date' => $filterDate, 'user' => $filterUser]));
              for ($i = 1; $i <= $totalPages; $i++):
            ?>
              <?php if ($i === $pgNum): ?>
                <span class="cur"><?= $i ?></span>
              <?php else: ?>
                <a href="?<?= $qs ?>&p=<?= $i ?>"><?= $i ?></a>
              <?php endif; ?>
            <?php endfor; ?>
            <span style="font-size:.75rem;color:var(--subtle);margin-left:.25rem"><?= $totalRows ?> records</span>
          </div>
          <?php endif; ?>
        </div>

        <!-- Tab: Page Visits -->
        <div id="tab-visits" style="display:none">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.85rem;flex-wrap:wrap;gap:.5rem">
            <p style="font-size:.78rem;color:var(--muted)">Showing last <strong><?= count($visits) ?></strong> page visits.</p>
          </div>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>User</th><th>Role</th><th>Page</th><th>IP Address</th><th>When</th></tr></thead>
              <tbody>
                <?php if (empty($visits)): ?>
                  <tr><td colspan="5" style="text-align:center;color:var(--subtle);padding:1.5rem 0">No visits recorded yet.</td></tr>
                <?php else: foreach ($visits as $v): ?>
                <tr>
                  <td>
                    <div style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($v['username'] ?? 'Guest') ?></div>
                    <div style="font-size:.7rem;color:var(--subtle)"><?= htmlspecialchars($v['user_email'] ?? '') ?></div>
                  </td>
                  <td><?= roleBadge($v['role'] ?? 'user') ?></td>
                  <td>
                    <div style="font-size:.8rem;font-weight:600"><?= htmlspecialchars($v['page_label'] ?? '—') ?></div>
                    <div style="font-size:.7rem;color:var(--subtle)"><?= htmlspecialchars($v['page']) ?></div>
                  </td>
                  <td><span class="ip-chip"><?= htmlspecialchars($v['ip_address'] ?? '—') ?></span></td>
                  <td style="font-size:.75rem;color:var(--muted);white-space:nowrap"><?= timeAgo($v['visited_at']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Tab: System Alerts -->
        <div id="tab-alerts" style="display:none">
          <?php if (empty($alerts)): ?>
            <p style="color:var(--subtle);font-size:.82rem;text-align:center;padding:1.5rem 0">✅ No unresolved alerts.</p>
          <?php else: foreach ($alerts as $al):
            $dotColor = match ($al['severity']) { 'critical' => '#dc2626', 'warning' => '#d97706', default => '#16a34a' };
          ?>
          <div class="alert-item">
            <span class="alert-dot" style="background:<?= $dotColor ?>"></span>
            <div style="flex:1">
              <div style="font-size:.82rem;font-weight:600"><?= htmlspecialchars($al['message']) ?></div>
              <div style="font-size:.72rem;color:var(--muted);margin-top:.15rem">
                <?= severityBadge($al['severity']) ?>
                <?php if ($al['tankname']): ?><span style="margin-left:.35rem">Tank: <?= htmlspecialchars($al['tankname']) ?></span><?php endif; ?>
                <span style="margin-left:.35rem"><?= timeAgo($al['created_at']) ?></span>
              </div>
            </div>
            <a href="?page=oversight&resolve=<?= $al['alert_id'] ?>" class="btn-sm btn-ghost" style="font-size:.72rem;display:inline-flex;align-items:center;text-decoration:none">Resolve</a>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div><!-- /card tabs -->

    <?php break; /* SECTION: OVERSIGHT END */


    /* ============================================================
       SECTION: SETTINGS START
       ============================================================ */
    case 'settings': ?>

      <?php if ($success): ?>
      <div class="flash flash-ok">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?= htmlspecialchars($success) ?>
      </div>
      <?php endif; ?>
      <?php if ($error): ?>
      <div class="flash flash-err">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST">

        <!-- Tank Configuration -->
        <div class="s-card">
          <div class="s-card-head">
            <div class="s-card-icon icon-blue">🪣</div>
            <div>
              <div class="s-card-title">Tank Configuration</div>
              <div class="s-card-sub">Manage capacity and overflow thresholds</div>
            </div>
          </div>
          <div class="s-card-body">
            <div class="fg2 mb">
              <div class="field">
                <label>Tank Capacity (Litres)</label>
                <input class="f-input" type="number" name="tank_capacity" id="tankCapacity"
                       value="<?= (int)cfg($rows,'tank_capacity',$tank['max_capacity'] ?? 5000) ?>" min="100" step="100"/>
              </div>
              <div class="field">
                <label>Low-Level Alert Threshold</label>
                <div class="slider-wrap">
                  <input type="range" name="threshold" id="threshold"
                         min="0" max="<?= $maxCap ?>" value="<?= $threshV ?>"
                         style="--val:<?= $pct ?>%"
                         oninput="updateSlider(this,'thresholdVal')"/>
                  <span class="slider-lbl" id="thresholdVal"><?= number_format($threshV) ?>L</span>
                </div>
              </div>
            </div>
            <hr class="row-divider"/>
            <div class="tog-row">
              <div class="tog-info">
                <strong>Overflow Prevention</strong>
                <span>Automatically divert water when tank reaches capacity</span>
              </div>
              <label class="tog">
                <input type="checkbox" name="overflow_prevention" <?= cfg($rows,'overflow_prevention','1')==='1'?'checked':'' ?>/>
                <div class="tog-track"></div><div class="tog-thumb"></div>
              </label>
            </div>
            <hr class="row-divider"/>

            <!-- Registered Tanks List -->
            <?php if (!empty($allTanks)): ?>
            <div style="margin-bottom:1.25rem;">
              <div style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6b7280;margin-bottom:.6rem;">Registered Tanks</div>
              <table class="tank-list-table">
                <thead><tr><th>#</th><th>Tank Name</th><th>Location</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                  <?php foreach ($allTanks as $t): ?>
                  <tr>
                    <td style="color:#9ca3af;font-size:.78rem"><?= $t['tank_id'] ?></td>
                    <td style="font-weight:600"><?= htmlspecialchars($t['tankname']) ?></td>
                    <td style="color:#6b7280">📍 <?= htmlspecialchars($t['location_add']) ?></td>
                    <td><span class="tank-badge <?= strtolower($t['status_tank']) ?>"><?= htmlspecialchars($t['status_tank']) ?></span></td>
                    <td>
                      <button type="button" class="btn-delete-tank"
                              onclick="confirmDelete(<?= $t['tank_id'] ?>, '<?= htmlspecialchars(addslashes($t['tankname'])) ?>')">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <polyline points="3 6 5 6 21 6"/>
                          <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
                          <path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
                        </svg>
                        Delete
                      </button>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>

            <button type="button" class="btn-add-tank" onclick="showAddTankForm()">Add Tank</button>

            <!-- Add tank inline form -->
            <div id="addTankForm">
              <form method="POST" class="fgen-form">
                <input type="hidden" name="add_tank" value="1"/>
                <div class="fgen-field">
                  <label class="fgen-label">Tank Name</label>
                  <input type="text" name="tankname" class="fgen-input" placeholder="e.g. Main Rooftop Tank" required/>
                </div>
                <div class="fgen-field">
                  <label class="fgen-label">Location Address</label>
                  <input type="text" name="location_add" class="fgen-input" placeholder="e.g. Brgy. Poblacion, Manolo Fortich" required/>
                </div>
                <div class="fgen-field">
                  <label class="fgen-label">Current Liters</label>
                  <input type="number" name="current_liters" class="fgen-input" placeholder="0" min="0"/>
                </div>
                <div class="fgen-field">
                  <label class="fgen-label">Max Capacity (L)</label>
                  <input type="number" name="max_capacity" class="fgen-input" placeholder="5000" min="1" required/>
                </div>
                <div class="fgen-field">
                  <label class="fgen-label">Status</label>
                  <select name="status_tank" class="fgen-select" id="tankStatusSelect">
                    <option value="">Select status</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                  </select>
                </div>
                <div class="fgen-form-actions">
                  <button type="submit" class="fgen-submit">Submit</button>
                  <button type="button" class="fgen-cancel" onclick="hideAddTankForm()">Cancel</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Pump Settings -->
        <div class="s-card">
          <div class="s-card-head">
            <div class="s-card-icon icon-green">⚙️</div>
            <div><div class="s-card-title">Pump Settings</div><div class="s-card-sub">Control automation and scheduling</div></div>
          </div>
          <div class="s-card-body">
            <div class="tog-row">
              <div class="tog-info"><strong>Auto Mode</strong><span>Pump operates based on demand and weather conditions</span></div>
              <label class="tog">
                <input type="checkbox" name="pump_auto" <?= cfg($rows,'pump_auto','1')==='1'?'checked':'' ?>/>
                <div class="tog-track"></div><div class="tog-thumb"></div>
              </label>
            </div>
            <hr class="row-divider"/>
            <div class="fg2">
              <div class="field">
                <label>Schedule Mode</label>
                <select class="f-select" name="pump_schedule">
                  <?php
                    $schedules = ['smart'=>'Smart (Weather-based)','fixed'=>'Fixed Schedule','manual'=>'Manual Only','sensor'=>'Sensor-Driven'];
                    $curSched  = cfg($rows,'pump_schedule','smart');
                    foreach ($schedules as $v=>$l) echo "<option value=\"$v\"".($curSched===$v?' selected':'').">$l</option>";
                  ?>
                </select>
              </div>
              <div class="field">
                <label>Max Wattage Limit (W)</label>
                <input class="f-input" type="number" name="pump_wattage" value="<?= (int)cfg($rows,'pump_wattage',100) ?>" min="0"/>
              </div>
            </div>
          </div>
        </div>

        <!-- Notifications -->
        <div class="s-card">
          <div class="s-card-head">
            <div class="s-card-icon icon-yellow">🔔</div>
            <div><div class="s-card-title">Notifications</div><div class="s-card-sub">Choose which alerts and reports to receive</div></div>
          </div>
          <div class="s-card-body">
            <?php
              $notifs = [
                'notif_low_water'    => ['Low Water Alert',     '1', 'Alert when tank drops below threshold'],
                'notif_heavy_rain'   => ['Heavy Rain Alert',    '1', 'Alert when heavy rain is forecast'],
                'notif_pump_failure' => ['Pump Failure Alert',  '1', 'Alert when pump encounters an error'],
                'notif_weekly'       => ['Weekly Usage Report', '0', 'Receive weekly water usage summary'],
                'notif_monthly'      => ['Monthly Summary',     '1', 'Monthly system performance report'],
              ];
              foreach ($notifs as $name => [$lbl, $def, $desc]):
                $chk = cfg($rows,$name,$def)==='1';
            ?>
            <div class="tog-row">
              <div class="tog-info"><strong><?= $lbl ?></strong><span><?= $desc ?></span></div>
              <label class="tog">
                <input type="checkbox" name="<?= $name ?>" <?= $chk?'checked':'' ?>/>
                <div class="tog-track"></div><div class="tog-thumb"></div>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Water Quality Alerts -->
        <div class="s-card">
          <div class="s-card-head">
            <div class="s-card-icon icon-purple">💧</div>
            <div><div class="s-card-title">Water Quality Alerts</div><div class="s-card-sub">pH, TDS thresholds and testing schedule</div></div>
          </div>
          <div class="s-card-body">
            <div class="fg2 mb">
              <div class="field">
                <label>pH Range (Min – Max)</label>
                <div class="ph-wrap">
                  <input class="f-input" type="number" name="ph_min" step="0.1" min="0" max="14" value="<?= cfg($rows,'ph_min','6.5') ?>"/>
                  <span class="ph-dash">—</span>
                  <input class="f-input" type="number" name="ph_max" step="0.1" min="0" max="14" value="<?= cfg($rows,'ph_max','8.5') ?>"/>
                </div>
              </div>
              <div class="field">
                <label>TDS Threshold (ppm)</label>
                <input class="f-input" type="number" name="tds_threshold" value="<?= (int)cfg($rows,'tds_threshold',100) ?>" min="0"/>
              </div>
            </div>
            <hr class="row-divider"/>
            <div class="fg1">
              <div class="field">
                <label>Test Frequency</label>
                <select class="f-select" name="test_frequency">
                  <?php
                    $freqs  = ['every_3h'=>'Every 3 hours','every_6h'=>'Every 6 hours','every_12h'=>'Every 12 hours','daily'=>'Once daily','continuous'=>'Continuous'];
                    $curFrq = cfg($rows,'test_frequency','every_6h');
                    foreach ($freqs as $v=>$l) echo "<option value=\"$v\"".($curFrq===$v?' selected':'').">$l</option>";
                  ?>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- Account -->
        <div class="s-card">
          <div class="s-card-head">
            <div class="s-card-icon icon-slate">👤</div>
            <div><div class="s-card-title">Account</div><div class="s-card-sub">Email, timezone and role preferences</div></div>
          </div>
          <div class="s-card-body">
            <div class="fg2 mb">
              <div class="field">
                <label>Email Address</label>
                <input class="f-input" type="email" name="account_email" value="<?= htmlspecialchars(cfg($rows,'account_email',$me['email'] ?? '')) ?>"/>
              </div>
              <div class="field">
                <label>Role</label>
                <input class="f-input" type="text" value="<?= ucfirst($me['role'] ?? 'admin') ?>" readonly/>
              </div>
            </div>
            <hr class="row-divider"/>
            <div class="fg1">
              <div class="field">
                <label>Timezone</label>
                <select class="f-select" name="account_timezone">
                  <?php
                    $tzones = ['Asia/Manila'=>'Asia/Manila (PHT +8)','UTC'=>'UTC','America/Los_Angeles'=>'Pacific Time (PT)','America/New_York'=>'Eastern Time (ET)'];
                    $curTz  = cfg($rows,'account_timezone','Asia/Manila');
                    foreach ($tzones as $v=>$l) echo "<option value=\"$v\"".($curTz===$v?' selected':'').">$l</option>";
                  ?>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- Save bar -->
        <div class="save-bar">
          <button type="button" class="btn-discard" onclick="window.location.reload()">Discard</button>
          <button type="submit" class="btn-save">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            Save Changes
          </button>
        </div>

      </form><!-- /settings form -->

      <!-- Delete confirm modal -->
      <div class="modal-backdrop" id="deleteModal">
        <div class="modal-box">
          <div class="modal-icon">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
              <path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
            </svg>
          </div>
          <h4>Delete Tank?</h4>
          <p>You're about to permanently delete <strong id="modalTankName"></strong>.<br>This action cannot be undone.</p>
          <div class="modal-actions">
            <button class="btn-modal-cancel"  onclick="closeDeleteModal()">Cancel</button>
            <button class="btn-modal-confirm" onclick="submitDelete()">Yes, Delete</button>
          </div>
        </div>
      </div>

      <!-- Hidden delete form -->
      <form method="POST" id="deleteTankForm" style="display:none">
        <input type="hidden" name="delete_tank" value="1"/>
        <input type="hidden" name="tank_id" id="deleteTankId"/>
      </form>

      <div class="toast" id="toast">✅&nbsp; Settings saved successfully</div>

    <?php break; /* SECTION: SETTINGS END */


    /* ============================================================
       SECTION: USAGE START
       ============================================================ */
    case 'usage': ?>

      <!-- Stat cards -->
      <div class="usage-stat-grid">
        <div class="usage-stat-card">
          <div class="stat-top">
            <div class="stat-lbl">Total Collected</div>
            <div class="stat-icon" style="background:#eff6ff">
              <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#3b82f6" stroke-width="2"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg>
            </div>
          </div>
          <div class="stat-value"><?= number_format($totalCollected) ?><span class="unit">L</span></div>
          <div class="stat-foot">
            <?php if ($pctChange >= 0): ?><span class="up">↑ <?= abs($pctChange) ?>%</span><span>vs last month</span>
            <?php else: ?><span class="down">↓ <?= abs($pctChange) ?>%</span><span>vs last month</span><?php endif; ?>
          </div>
          <div class="stat-glow" style="background:#3b82f6"></div>
        </div>
        <div class="usage-stat-card">
          <div class="stat-top">
            <div class="stat-lbl">Total Tap Used</div>
            <div class="stat-icon" style="background:#fef2f2">
              <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#ef4444" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
            </div>
          </div>
          <div class="stat-value"><?= number_format($totalTap) ?><span class="unit">L</span></div>
          <div class="stat-foot"><span>Total tap water consumption</span></div>
          <div class="stat-glow" style="background:#ef4444"></div>
        </div>
        <div class="usage-stat-card">
          <div class="stat-top">
            <div class="stat-lbl">Net Savings</div>
            <div class="stat-icon" style="background:#ecfdf5">
              <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#10b981" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            </div>
          </div>
          <div class="stat-value"><?= number_format($netSavings) ?><span class="unit">L</span></div>
          <div class="stat-foot"><span>Rainwater used instead of tap</span></div>
          <div class="stat-glow" style="background:#10b981"></div>
        </div>
        <div class="usage-stat-card">
          <div class="stat-top">
            <div class="stat-lbl">Avg Daily (30d)</div>
            <div class="stat-icon" style="background:#faf5ff">
              <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#8b5cf6" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
          </div>
          <div class="stat-value"><?= number_format($avgDaily, 0) ?><span class="unit">L</span></div>
          <div class="stat-foot"><span>Average per active day</span></div>
          <div class="stat-glow" style="background:#8b5cf6"></div>
        </div>
      </div>

      <!-- 30-day trend -->
      <div class="chart-row chart-row-1">
        <div class="chart-card">
          <div class="chart-header">
            <div><div class="chart-title-lg">Daily Collection Trend</div><div class="chart-sub">Last 30 Days</div></div>
            <span class="chart-pill">Last 30 Days</span>
          </div>
          <div class="chart-body"><canvas id="trendChart" height="75"></canvas></div>
        </div>
      </div>

      <!-- Monthly bar + Doughnut -->
      <div class="chart-row chart-row-3">
        <div class="chart-card">
          <div class="chart-header">
            <div><div class="chart-title-lg">Monthly Comparison</div><div class="chart-sub">Rainwater vs Tap — last 6 months</div></div>
          </div>
          <div class="chart-body">
            <div class="legend">
              <div class="legend-item"><div class="leg-dot" style="background:#3b82f6"></div>Rainwater</div>
              <div class="legend-item"><div class="leg-dot" style="background:#d1d5db"></div>Tap Water</div>
            </div>
            <canvas id="barChart" height="150"></canvas>
          </div>
        </div>
        <div class="chart-card">
          <div class="chart-header"><div><div class="chart-title-lg">Usage Breakdown</div><div class="chart-sub">By type — all time</div></div></div>
          <div class="chart-body" style="display:flex;justify-content:center;align-items:center;min-height:220px;">
            <?php if (count($breakData) > 0 && array_sum($breakData) > 0): ?>
              <canvas id="doughnutChart" height="200"></canvas>
            <?php else: ?>
              <p style="color:#9ca3af;font-size:.85rem;text-align:center;">No usage data yet.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Recent usage table -->
      <div class="table-card">
        <div class="table-header">
          <div class="chart-title-lg">Recent Usage Records</div>
          <span class="chart-pill">Last 10</span>
        </div>
        <div class="tbl-scroll">
          <table class="tbl-usage">
            <thead><tr><th>Type</th><th>Volume</th><th>Tank</th><th>User</th><th>Date &amp; Time</th></tr></thead>
            <tbody>
              <?php if ($recentUsage): ?>
                <?php foreach ($recentUsage as $row):
                  $typeKey   = strtolower(str_replace(' ','',$row['usage_type']));
                  $typeClass = match($typeKey) { 'cleaning'=>'type-cleaning','irrigation'=>'type-irrigation','drinking'=>'type-drinking','tap'=>'type-tap',default=>'type-other' };
                ?>
                <tr>
                  <td><span class="type-badge <?= $typeClass ?>"><?= htmlspecialchars($row['usage_type']) ?></span></td>
                  <td><?= number_format((float)$row['usage_liters'], 2) ?> L</td>
                  <td><?= htmlspecialchars($row['tankname'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($row['email'] ? explode('@',$row['email'])[0] : '—') ?></td>
                  <td><?= date('M j, Y  H:i', strtotime($row['recorded_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr class="empty-row"><td colspan="5">No usage records found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php break; /* SECTION: USAGE END */


    /* ============================================================
       SECTION: USER LOGS START
       ============================================================ */
    case 'userlogs': ?>

      <!-- Alerts -->
      <div class="alerts-wrap">
        <?php if (!empty($success)): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $success ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
          </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($error) ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
          </div>
        <?php endif; ?>
      </div>

      <!-- Users table -->
      <div class="table-wrapper">
        <div class="table-title-bar">
          <h2>User Management</h2>
          <div class="spacer"></div>
          <form method="GET" style="display:inline;">
            <input type="hidden" name="page" value="userlogs">
            <div class="search-bar">
              <input type="text" name="q" placeholder="Search users..." value="<?= htmlspecialchars($search) ?>">
            </div>
          </form>
          <button class="btn-sm-action btn-green" data-toggle="modal" data-target="#addUserModal">
            <i class="material-icons" style="font-size:16px;">add</i> Add User
          </button>
          <button class="btn-sm-action btn-red" id="bulkDeleteBtn" data-toggle="modal" data-target="#bulkDeleteModal">
            <i class="material-icons" style="font-size:16px;">delete</i> Delete
          </button>
        </div>

        <div class="tbl-scroll">
          <table class="data-table">
            <thead>
              <tr>
                <th><span class="custom-checkbox"><input type="checkbox" id="selectAll"><label for="selectAll"></label></span></th>
                <th>#</th><th>Email</th><th>Role</th><th>Verified</th><th>Created</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($userRows): ?>
                <?php foreach ($userRows as $u): ?>
                <tr>
                  <td>
                    <span class="custom-checkbox">
                      <input type="checkbox" class="row-checkbox" value="<?= $u['id'] ?>" id="chk<?= $u['id'] ?>">
                      <label for="chk<?= $u['id'] ?>"></label>
                    </span>
                  </td>
                  <td><?= $u['id'] ?></td>
                  <td style="word-break:break-all"><?= htmlspecialchars($u['email']) ?></td>
                  <td><span class="role-badge role-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
                  <td>
                    <?php if ($u['is_verified']): ?>
                      <i class="material-icons verified-icon" title="Verified">check_circle</i>
                    <?php else: ?>
                      <i class="material-icons unverified-icon" title="Not verified">cancel</i>
                    <?php endif; ?>
                  </td>
                  <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                  <td>
                    <a href="#editUserModal" class="action-link edit" data-toggle="modal"
                       data-id="<?= $u['id'] ?>" data-email="<?= htmlspecialchars($u['email']) ?>" data-role="<?= $u['role'] ?>">
                      <i class="material-icons">edit</i>
                    </a>
                    <a href="#deleteUserModal" class="action-link delete" data-toggle="modal"
                       data-id="<?= $u['id'] ?>" data-email="<?= htmlspecialchars($u['email']) ?>">
                      <i class="material-icons">delete</i>
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:2rem;">No users found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="tbl-footer">
          <div class="hint-text">
            Showing <b><?= count($userRows) ?></b> of <b><?= $totalUsersCount ?></b> users<?= $search ? ' — <em>' . htmlspecialchars($search) . '</em>' : '' ?>
          </div>
          <?php if ($totalPagesUL > 1): ?>
          <div class="pager-ul">
            <?php if ($pgNum > 1): ?><a href="?page=userlogs&pgNum=<?= $pgNum-1 ?>&q=<?= urlencode($search) ?>">‹</a><?php endif; ?>
            <?php for ($p = 1; $p <= $totalPagesUL; $p++): ?>
              <?php if ($p === $pgNum): ?><span class="active"><?= $p ?></span>
              <?php else: ?><a href="?page=userlogs&pgNum=<?= $p ?>&q=<?= urlencode($search) ?>"><?= $p ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($pgNum < $totalPagesUL): ?><a href="?page=userlogs&pgNum=<?= $pgNum+1 ?>&q=<?= urlencode($search) ?>">›</a><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div><!-- /table-wrapper -->

      <!-- Activity log -->
      <div class="log-wrapper">
        <div class="log-title-bar"><h3>📋 Recent Activity Log</h3></div>
        <div class="tbl-scroll">
          <table class="data-table">
            <thead><tr><th>#</th><th>User</th><th>Email</th><th>Action</th><th>Status</th><th>IP</th><th>Time</th></tr></thead>
            <tbody>
              <?php if ($logs): ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                  <td><?= $log['activity_id'] ?></td>
                  <td><?= $log['user_id'] ? '#' . $log['user_id'] : '<em style="color:#9ca3af">—</em>' ?></td>
                  <td style="word-break:break-all"><?= htmlspecialchars($log['email'] ?? $log['user_email'] ?? '—') ?></td>
                  <td><?= htmlspecialchars(str_replace('_', ' ', $log['action'])) ?></td>
                  <td><span class="status-<?= $log['status'] === 'success' ? 'ok' : 'bad' ?>"><?= ucfirst($log['status']) ?></span></td>
                  <td><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                  <td><?= date('M j, H:i', strtotime($log['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:2rem;">No activity logged yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Add User Modal -->
      <div id="addUserModal" class="modal fade">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
          <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-header"><h5 class="modal-title">Add New User</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">
              <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" placeholder="user@example.com" required></div>
              <div class="form-group"><label>Password</label><input type="password" name="password" class="form-control" required></div>
              <div class="form-group"><label>Role</label><select name="role" class="form-control"><option value="user">User</option><option value="admin">Admin</option></select></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success btn-sm">Add User</button></div>
          </form>
        </div></div>
      </div>

      <!-- Edit User Modal -->
      <div id="editUserModal" class="modal fade">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
          <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editUserId">
            <div class="modal-header"><h5 class="modal-title">Edit User</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <div class="modal-body">
              <div class="form-group"><label>Email</label><input type="email" name="email" id="editEmail" class="form-control" required></div>
              <div class="form-group"><label>New Password <small class="text-muted">(leave blank to keep)</small></label><input type="password" name="password" class="form-control"></div>
              <div class="form-group"><label>Role</label><select name="role" id="editRole" class="form-control"><option value="user">User</option><option value="admin">Admin</option></select></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Save Changes</button></div>
          </form>
        </div></div>
      </div>

      <!-- Delete Single Modal -->
      <div id="deleteUserModal" class="modal fade">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
          <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteUserId">
            <div class="modal-header"><h5 class="modal-title">Delete User</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <div class="modal-body"><p>Delete <strong id="deleteUserEmail"></strong>? This action cannot be undone.</p></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger btn-sm">Delete</button></div>
          </form>
        </div></div>
      </div>

      <!-- Bulk Delete Modal -->
      <div id="bulkDeleteModal" class="modal fade">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
          <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="ids" id="bulkDeleteIds">
            <div class="modal-header"><h5 class="modal-title">Bulk Delete</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <div class="modal-body"><p>Delete <strong id="bulkDeleteCount">0</strong> selected user(s)? This cannot be undone.</p></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger btn-sm">Delete All</button></div>
          </form>
        </div></div>
      </div>

    <?php break; /* SECTION: USER LOGS END */


    /* ============================================================
       SECTION: WEATHER START
       ============================================================ */
    case 'weather': ?>

      <!-- Hero card -->
      <div class="hero-card">
        <div class="hero-top">
          <div>
            <div class="hero-date"><?= date('l, F j · g:i A') ?></div>
            <div class="hero-location">📍 <?= htmlspecialchars($CITY) ?></div>
          </div>
        </div>
        <div class="hero-temp-row">
          <div>
            <div class="big-temp"><?= $temp ?><sup>°</sup></div>
            <div class="weather-desc-wrap">
              <div class="weather-desc"><?= $weatherIcon ?> <?= htmlspecialchars($description) ?></div>
              <div class="feels-like">Feels like <?= $feelsLike ?>°C · <?= $cloudiness ?>% cloud cover</div>
            </div>
          </div>
        </div>
        <div class="hero-cloud"><?= $weatherIcon ?></div>
        <div class="hero-pills">
          <div class="pill"><span>💧</span><span class="pval"><?= $humidity ?>%</span><span>Humidity</span></div>
          <div class="pill"><span>🌬️</span><span class="pval"><?= $windSpeed ?> km/h</span><span>Wind</span></div>
          <div class="pill"><span>👁️</span><span class="pval"><?= $visibility ?> km</span><span>Visibility</span></div>
          <div class="pill"><span>🌡️</span><span class="pval"><?= $pressure ?></span><span>hPa</span></div>
        </div>
      </div>

      <!-- Alert banner -->
      <?php if ($rainAlert): ?>
      <div class="alert-banner">
        <div class="alert-icon">⚠️</div>
        <div><div class="alert-title">Weather Advisory</div><div class="alert-desc"><?= htmlspecialchars($alertMsg) ?></div></div>
      </div>
      <?php elseif (!$currentWeather): ?>
      <div class="alert-banner" style="border-color:#fca5a5;background:#fef2f2;">
        <div class="alert-icon">📡</div>
        <div><div class="alert-title" style="color:#991b1b;">Weather API Unavailable</div><div class="alert-desc" style="color:#7f1d1d;">Could not reach OpenWeatherMap.</div></div>
      </div>
      <?php else: ?>
      <div class="alert-banner" style="border-color:#bbf7d0;background:#f0fdf4;">
        <div class="alert-icon">✅</div>
        <div><div class="alert-title" style="color:#166534;">All Clear</div><div class="alert-desc" style="color:#14532d;">No weather alerts for <?= htmlspecialchars($CITY) ?>. Conditions are normal.</div></div>
      </div>
      <?php endif; ?>

      <!-- 7-day forecast -->
      <div>
        <div class="section-label">7-Day Forecast</div>
        <div class="forecast-row-wx">
          <?php if ($daily): ?>
            <?php foreach ($daily as $i => $day):
              $dId   = $day['weather'][0]['id'] ?? 800;
              $dEmoj = weatherEmoji($dId);
              $dTemp = round($day['main']['temp']);
              $dMax  = round($day['main']['temp_max']);
              $dMin  = round($day['main']['temp_min']);
              $dPop  = round(($day['pop'] ?? 0) * 100);
              $dDay  = $i === 0 ? 'Today' : date('D', $day['dt']);
              $isToday = $i === 0;
            ?>
            <div class="fc-item <?= $isToday ? 'today' : '' ?>">
              <div class="fc-day"><?= $dDay ?></div>
              <div class="fc-emoji"><?= $dEmoj ?></div>
              <div class="fc-temp"><?= $dTemp ?>°</div>
              <div class="fc-hilo"><span><?= $dMax ?>°</span><span class="fc-lo"><?= $dMin ?>°</span></div>
              <?php if ($dPop > 0): ?><div class="fc-rain <?= !$isToday ? 'has-rain' : '' ?>">💧 <?= $dPop ?>%</div><?php endif; ?>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <?php foreach ([['Today','⛅',28,31,24,60],['Fri','🌧️',25,27,22,80],['Sat','🌦️',26,29,23,50],['Sun','☀️',30,33,25,10],['Mon','⛅',29,32,24,30],['Tue','🌩️',24,26,21,90],['Wed','🌤️',27,30,23,20]] as [$dDay,$dEmoj,$dTemp,$dMax,$dMin,$dPop]): ?>
            <div class="fc-item <?= $dDay==='Today'?'today':'' ?>">
              <div class="fc-day"><?= $dDay ?></div>
              <div class="fc-emoji"><?= $dEmoj ?></div>
              <div class="fc-temp"><?= $dTemp ?>°</div>
              <div class="fc-hilo"><span><?= $dMax ?>°</span><span class="fc-lo"><?= $dMin ?>°</span></div>
              <div class="fc-rain <?= $dDay!=='Today'?'has-rain':'' ?>">💧 <?= $dPop ?>%</div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Bottom grid: rainfall chart + sensor donut -->
      <div class="bottom-grid">
        <div class="w-card">
          <div class="w-card-title">Rainfall Collection — Last 14 Days<span class="w-card-badge">mm equiv.</span></div>
          <div class="chart-wrap"><canvas id="rainfallChart"></canvas></div>
        </div>
        <div class="w-card">
          <div class="w-card-title">Sensor Inference Summary</div>
          <div class="donut-wrap">
            <div class="donut-canvas-wrap">
              <canvas id="donutChart"></canvas>
              <div class="donut-center">
                <div class="donut-pct"><?= $normalPct ?>%</div>
                <div class="donut-sub">Normal</div>
              </div>
            </div>
            <div class="donut-legend">
              <div class="dleg-item"><div class="dleg-dot" style="background:#2563eb"></div><div><div class="dleg-val"><?= $normalPct ?>%</div><div>Normal readings</div></div></div>
              <div class="dleg-item"><div class="dleg-dot" style="background:#93c5fd"></div><div><div class="dleg-val"><?= $rainPct ?>%</div><div>Rain anomaly</div></div></div>
              <div class="dleg-item"><div class="dleg-dot" style="background:#f59e0b"></div><div><div class="dleg-val"><?= $alertPct ?>%</div><div>Alert readings</div></div></div>
            </div>
          </div>
        </div>
      </div>

    <?php break; /* SECTION: WEATHER END */


  endswitch; /* END PAGE SWITCH */
  ?>

  <?php if ($page !== 'map'): /* Map closed its own </main> early */ ?>
  </main>
</div><!-- /.app-body -->
  <?php endif; ?>


<?php /* ============================================================
   SECTION: FOOTER SCRIPTS
   Leaflet (map only), Bootstrap (userlogs only), then our JS file
   ============================================================ */ ?>

<?php if ($page === 'map'): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>

<?php if ($page === 'userlogs'): ?>
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>
<?php endif; ?>

<!-- Admin master JS (router + all page logic) -->
<script src="<?= BASE_URL ?>/app/admin/admin_script.js"></script>

</body>
</html>