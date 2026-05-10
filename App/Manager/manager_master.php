<?php
/* ============================================================
   ECORAIN — MANAGER MASTER FILE
   ============================================================
   Single-file router for all manager features.
   Routing: ?page=<slug>

   Slugs:
     (none / 'dashboard') → Dashboard
     'oversight'          → Manager Oversight
     'usage'              → Usage Statistics
     'weather'            → Weather Monitor
     'map'                → Tank Map
     'settings'           → Settings
     'user'               → User Management
   ============================================================ */

require_once '../../connections/config.php';
require_once '../../connections/functions.php';

requireAnyRole(['admin', 'manager']);

/* ── Determine current page ─────────────────────────────────────────────── */
$page       = $_GET['page'] ?? 'dashboard';
$activePage = $page;

/* ── Session guard ──────────────────────────────────────────────────────── */
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$userId   = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'user';

/* ── Log page visit ─────────────────────────────────────────────────────── */
$pageLabels = [
    'dashboard' => 'Manager Dashboard',
    'oversight' => 'Manager Oversight',
    'usage'     => 'Manager Usage',
    'weather'   => 'Manager Weather Map',
    'map'       => 'Manager Map',
    'settings'  => 'Manager Settings',
    'user'      => 'Manager User Log',
];
logPageVisit($pageLabels[$page] ?? 'Manager', ucfirst($page));


/* ============================================================
   SECTION: DATA LOADING SWITCH
   Each case loads only its own data — no cross-loading.
   ============================================================ */

switch ($page) {

    /* ── DASHBOARD ───────────────────────────────────────────────────────── */
    case 'dashboard':
    default:
        $allTanks           = $pdo->query("SELECT * FROM tank")->fetchAll(PDO::FETCH_ASSOC);
        $totalCurrentLiters = array_sum(array_column($allTanks, 'current_liters'));
        $totalMaxCapacity   = array_sum(array_column($allTanks, 'max_capacity'));
        $tankCount          = count($allTanks);
        $onlineCount        = count(array_filter($allTanks, fn($t) => strtolower($t['status_tank']) === 'active'));
        $percent            = ($totalMaxCapacity > 0)
                              ? round(($totalCurrentLiters / $totalMaxCapacity) * 100, 1) : 0;

        $quality = $pdo->query(
            "SELECT * FROM water_quality ORDER BY recorded_at DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        $todayRow       = $pdo->query("SELECT COALESCE(SUM(usage_liters),0) AS t FROM water_usage WHERE DATE(recorded_at)=CURDATE()")->fetch(PDO::FETCH_ASSOC);
        $todayCollected = (float)$todayRow['t'];

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

        $sensors = $pdo->query("
            SELECT sr.anomaly, sr.recorded_at, s.sensor_type, s.model
            FROM sensor_readings sr JOIN sensors s ON sr.sensor_id=s.sensor_id
            ORDER BY sr.recorded_at DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        $activities = $pdo->query("
            SELECT ual.action, ual.created_at, ual.user_id
            FROM user_activity_logs ual
            ORDER BY ual.created_at DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        /* Dashboard helpers */
        function phLabel($v)   { return $v == 0 ? 'None' : ($v < 6.5 ? 'Low'  : ($v > 8.5 ? 'High' : 'Optimal')); }
        function phColor($v)   { return ($v == 0 || ($v >= 6.5 && $v <= 8.5)) ? '#16a34a' : '#ef4444'; }
        function turbLabel($v) { return $v == 0 ? 'None' : ($v > 4 ? 'Poor' : ($v > 1 ? 'Moderate' : 'Excellent')); }
        function turbColor($v) { return ($v == 0 || $v <= 1) ? '#16a34a' : ($v <= 4 ? '#d97706' : '#ef4444'); }

        $tankBg = $percent < 20
            ? 'linear-gradient(135deg,#fee2e2,#fca5a5)'
            : ($percent < 50
                ? 'linear-gradient(135deg,#fef9c3,#fde68a)'
                : 'linear-gradient(135deg,#dbeafe,#93c5fd)');
        $tankAccent = $percent < 20 ? '#dc2626' : ($percent < 50 ? '#d97706' : '#2563eb');

        $updatedAgo = 'N/A';
        if ($quality) {
            $diff = abs(time() - strtotime($quality['recorded_at']));
            if      ($diff < 60)    $updatedAgo = $diff . 's ago';
            elseif  ($diff < 3600)  $updatedAgo = floor($diff / 60) . 'm ago';
            elseif  ($diff < 86400) $updatedAgo = floor($diff / 3600) . 'h ago';
            else    $updatedAgo = floor($diff / 86400) . 'd ago';
        }
        break;

    /* ── OVERSIGHT ───────────────────────────────────────────────────────── */
    case 'oversight':
        $tankCount   = (int)$pdo->query("SELECT COUNT(*) FROM tank")->fetchColumn();
        $onlineTanks = (int)$pdo->query("SELECT COUNT(*) FROM tank WHERE LOWER(status_tank)='active'")->fetchColumn();
        $todayUsage  = (float)$pdo->query("SELECT COALESCE(SUM(usage_liters),0) FROM water_usage WHERE DATE(recorded_at)=CURDATE()")->fetchColumn();
        $anomalies   = (int)$pdo->query("SELECT COUNT(*) FROM sensor_readings WHERE anomaly != 'None' AND DATE(recorded_at)=CURDATE()")->fetchColumn();

        $activeUsers = $pdo->query("
            SELECT u.username, u.email, u.role,
                   COUNT(ual.activity_id) AS action_count,
                   MAX(ual.created_at) AS last_action
            FROM user_activity_logs ual
            LEFT JOIN users u ON ual.user_id = u.id
            WHERE DATE(ual.created_at) = CURDATE()
              AND (u.role IS NULL OR u.role IN ('user', 'manager'))
            GROUP BY ual.user_id
            ORDER BY last_action DESC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        $filterRole   = $_GET['role']   ?? '';
        $filterAction = $_GET['action'] ?? '';
        $filterDate   = $_GET['date']   ?? date('Y-m-d');
        $curPage      = max(1, (int)($_GET['p'] ?? 1));
        $perPage      = 15;
        $offset       = ($curPage - 1) * $perPage;

        $where  = ["ual.role IN ('user','manager')"];
        $params = [];
        if ($filterRole && in_array($filterRole, ['user','manager'])) {
            $where[] = 'ual.role = :role'; $params[':role'] = $filterRole;
        }
        if ($filterAction) { $where[] = 'ual.action LIKE :action'; $params[':action'] = '%'.$filterAction.'%'; }
        if ($filterDate)   { $where[] = 'DATE(ual.created_at) = :date'; $params[':date'] = $filterDate; }
        $whereStr = implode(' AND ', $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM user_activity_logs ual WHERE $whereStr");
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

        $tankActivity = $pdo->query("
            SELECT t.tankname, t.status_tank, t.current_liters, t.max_capacity,
                   (SELECT COUNT(*) FROM water_usage wu WHERE wu.tank_id=t.tank_id AND DATE(wu.recorded_at)=CURDATE()) AS usage_events,
                   (SELECT COALESCE(SUM(wu2.usage_liters),0) FROM water_usage wu2 WHERE wu2.tank_id=t.tank_id AND DATE(wu2.recorded_at)=CURDATE()) AS today_liters,
                   (SELECT COUNT(*) FROM sensor_readings sr JOIN sensors s ON sr.sensor_id=s.sensor_id WHERE s.tank_id=t.tank_id AND sr.anomaly != 'None' AND DATE(sr.recorded_at)=CURDATE()) AS anomaly_count
            FROM tank t ORDER BY t.tankname
        ")->fetchAll(PDO::FETCH_ASSOC);

        $sensorAnomalies = $pdo->query("
            SELECT sr.anomaly, sr.recorded_at, s.sensor_type, s.model, t.tankname
            FROM sensor_readings sr
            JOIN sensors s ON sr.sensor_id = s.sensor_id
            JOIN tank t    ON s.tank_id    = t.tank_id
            WHERE sr.anomaly != 'None'
            ORDER BY sr.recorded_at DESC LIMIT 15
        ")->fetchAll(PDO::FETCH_ASSOC);

        $qualityRecent = $pdo->query("
            SELECT wq.*, t.tankname
            FROM water_quality wq
            JOIN tank t ON wq.tank_id = t.tank_id
            ORDER BY wq.recorded_at DESC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        $pageVisits = $pdo->query("
            SELECT pv.page_label, pv.page, pv.role, pv.ip_address, u.username, pv.visited_at
            FROM page_visits pv
            LEFT JOIN users u ON pv.user_id = u.id
            WHERE pv.role IN ('user','manager')
            ORDER BY pv.visited_at DESC LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);

        $usageRowsO = $pdo->query("
            SELECT DATE(recorded_at) AS d, SUM(usage_liters) AS total
            FROM water_usage WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(recorded_at) ORDER BY d
        ")->fetchAll(PDO::FETCH_ASSOC);
        $usageMapO = array_column($usageRowsO, 'total', 'd');
        $oversightChartLabels = $oversightChartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $oversightChartLabels[] = date('D', strtotime($d));
            $oversightChartData[]   = (float)($usageMapO[$d] ?? 0);
        }

        /* Oversight helpers */
        function roleBadgeMgr(string $role): string {
            $s = match($role) {
                'admin'   => 'background:#eff6ff;color:#2563eb;border-color:#bfdbfe',
                'manager' => 'background:#f5f3ff;color:#7c3aed;border-color:#ddd6fe',
                default   => 'background:#ecfdf5;color:#059669;border-color:#a7f3d0',
            };
            return "<span class='badge' style='$s'>" . ucfirst($role) . "</span>";
        }
        function timeAgoMgr(string $ts): string {
            $d = time() - strtotime($ts);
            if ($d < 60)    return $d . 's ago';
            if ($d < 3600)  return floor($d / 60) . 'm ago';
            if ($d < 86400) return floor($d / 3600) . 'h ago';
            return date('M j', strtotime($ts));
        }
        function actionIconMgr(string $a): string {
            if (str_contains($a, 'login'))     return '🔑';
            if (str_contains($a, 'logout'))    return '🚪';
            if (str_contains($a, 'delete'))    return '🗑️';
            if (str_contains($a, 'page_view')) return '👁️';
            if (str_contains($a, 'edit') || str_contains($a, 'update')) return '✏️';
            return '📋';
        }
        break;

    /* ── USAGE ───────────────────────────────────────────────────────────── */
    case 'usage':
        $totalCollected = (float)$pdo->query("SELECT COALESCE(SUM(usage_liters),0) FROM water_usage WHERE usage_type != 'Tap'")->fetchColumn();
        $totalTap       = (float)$pdo->query("SELECT COALESCE(SUM(usage_liters),0) FROM water_usage WHERE usage_type = 'Tap'")->fetchColumn();
        $netSavings     = max(0, $totalCollected - $totalTap);
        $avgDaily       = (float)$pdo->query("SELECT COALESCE(AVG(daily_sum),0) FROM (SELECT DATE(recorded_at) d, SUM(usage_liters) daily_sum FROM water_usage WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND usage_type != 'Tap' GROUP BY DATE(recorded_at)) t")->fetchColumn();
        $thisMonth      = (float)$pdo->query("SELECT COALESCE(SUM(usage_liters),0) FROM water_usage WHERE MONTH(recorded_at)=MONTH(CURDATE()) AND YEAR(recorded_at)=YEAR(CURDATE()) AND usage_type != 'Tap'")->fetchColumn();
        $lastMonth      = (float)$pdo->query("SELECT COALESCE(SUM(usage_liters),0) FROM water_usage WHERE MONTH(recorded_at)=MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(recorded_at)=YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND usage_type != 'Tap'")->fetchColumn();
        $pctChange      = $lastMonth > 0 ? round(($thisMonth - $lastMonth) / $lastMonth * 100, 1) : 0;

        $trend30 = $pdo->query("SELECT DATE(recorded_at) AS d, SUM(usage_liters) AS total FROM water_usage WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND usage_type != 'Tap' GROUP BY DATE(recorded_at) ORDER BY d ASC")->fetchAll(PDO::FETCH_ASSOC);
        $trend30Map = [];
        foreach ($trend30 as $r) $trend30Map[$r['d']] = (float)$r['total'];
        $trendLabels = $trendData = [];
        for ($i = 29; $i >= 0; $i--) {
            $day           = date('Y-m-d', strtotime("-$i days"));
            $trendLabels[] = date('M j', strtotime($day));
            $trendData[]   = $trend30Map[$day] ?? 0;
        }

        $monthly = $pdo->query("
            SELECT DATE_FORMAT(recorded_at,'%b') AS mon,
                   DATE_FORMAT(recorded_at,'%Y-%m') AS ym,
                   SUM(CASE WHEN usage_type != 'Tap' THEN usage_liters ELSE 0 END) AS rainwater,
                   SUM(CASE WHEN usage_type = 'Tap'  THEN usage_liters ELSE 0 END) AS tap
            FROM water_usage
            WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY ym, mon ORDER BY ym ASC LIMIT 6
        ")->fetchAll(PDO::FETCH_ASSOC);
        $barLabels    = array_column($monthly, 'mon');
        $barRainwater = array_column($monthly, 'rainwater');
        $barTap       = array_column($monthly, 'tap');

        $breakdown   = $pdo->query("SELECT usage_type, SUM(usage_liters) AS total FROM water_usage GROUP BY usage_type ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);
        $breakLabels = array_column($breakdown, 'usage_type');
        $breakData   = array_map('floatval', array_column($breakdown, 'total'));

        $recentUsage = $pdo->query("
            SELECT wu.usage_type, wu.usage_liters, wu.recorded_at, t.tankname, u.email
            FROM water_usage wu
            LEFT JOIN tank t  ON wu.tank_id  = t.tank_id
            LEFT JOIN users u ON wu.user_id   = u.id
            ORDER BY wu.recorded_at DESC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
        break;

    /* ── WEATHER ─────────────────────────────────────────────────────────── */
    case 'weather':
        $WX_API_KEY = 'a5712e740541248ce7883f0af8581be4';
        $WX_LAT     = 8.360015;
        $WX_LON     = 124.868419;
        $WX_CITY    = 'Manolo Fortich, Bukidnon';

        function fetchJson($url) {
            $ctx = stream_context_create(['http' => ['timeout' => 5]]);
            $raw = @file_get_contents($url, false, $ctx);
            return $raw ? json_decode($raw, true) : null;
        }
        $currentWeather = fetchJson("https://api.openweathermap.org/data/2.5/weather?lat={$WX_LAT}&lon={$WX_LON}&appid={$WX_API_KEY}&units=metric");
        $forecastData   = fetchJson("https://api.openweathermap.org/data/2.5/forecast?lat={$WX_LAT}&lon={$WX_LON}&appid={$WX_API_KEY}&units=metric&cnt=40");

        $wxTemp        = $currentWeather ? round($currentWeather['main']['temp'])        : '--';
        $wxFeelsLike   = $currentWeather ? round($currentWeather['main']['feels_like'])  : '--';
        $wxHumidity    = $currentWeather ? $currentWeather['main']['humidity']           : '--';
        $wxWindSpeed   = $currentWeather ? round($currentWeather['wind']['speed'] * 3.6) : '--';
        $wxVisibility  = $currentWeather ? round(($currentWeather['visibility'] ?? 10000) / 1000) : '--';
        $wxPressure    = $currentWeather ? $currentWeather['main']['pressure']           : '--';
        $wxDescription = $currentWeather ? ucfirst($currentWeather['weather'][0]['description']) : 'N/A';
        $wxWeatherId   = $currentWeather ? $currentWeather['weather'][0]['id']           : 800;
        $wxCloudiness  = $currentWeather ? $currentWeather['clouds']['all']              : 0;

        function wxEmojiPHP(int $id): string {
            if ($id >= 200 && $id < 300) return '⛈️';
            if ($id >= 300 && $id < 400) return '🌦️';
            if ($id >= 500 && $id < 600) return '🌧️';
            if ($id >= 600 && $id < 700) return '❄️';
            if ($id >= 700 && $id < 800) return '🌫️';
            if ($id === 800)              return '☀️';
            if ($id === 801 || $id === 802) return '⛅';
            return '☁️';
        }
        $wxIcon  = wxEmojiPHP($wxWeatherId);
        $wxDaily = [];
        if ($forecastData && isset($forecastData['list'])) {
            $seen = [];
            foreach ($forecastData['list'] as $item) {
                $date = date('Y-m-d', $item['dt']);
                $hour = (int)date('H', $item['dt']);
                if (!isset($seen[$date]) || abs($hour - 12) < abs((int)date('H', $seen[$date]['dt']) - 12))
                    $seen[$date] = $item;
            }
            $wxDaily = array_values(array_slice($seen, 0, 7));
        }

        $rainfall14 = $pdo->query("
            SELECT DATE(recorded_at) AS d, SUM(usage_liters) AS mm
            FROM water_usage
            WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND usage_type != 'Tap'
            GROUP BY DATE(recorded_at) ORDER BY d ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $rfMap = [];
        foreach ($rainfall14 as $r) $rfMap[$r['d']] = round((float)$r['mm'] / 10, 1);
        $rfLabels = $rfData = [];
        for ($i = 13; $i >= 0; $i--) {
            $day        = date('Y-m-d', strtotime("-$i days"));
            $rfLabels[] = date('M j', strtotime($day));
            $rfData[]   = $rfMap[$day] ?? 0;
        }

        $totalReadings = (int)$pdo->query("SELECT COUNT(*) FROM sensor_readings")->fetchColumn();
        $rainReadings  = (int)$pdo->query("SELECT COUNT(*) FROM sensor_readings WHERE anomaly != 'None'")->fetchColumn();
        $alertDays     = (int)$pdo->query("SELECT COUNT(DISTINCT DATE(recorded_at)) FROM sensor_readings WHERE anomaly = 'High'")->fetchColumn();
        $wxNormalPct   = $totalReadings > 0 ? round(($totalReadings - $rainReadings) / $totalReadings * 100) : 84;
        $wxRainPct     = $totalReadings > 0 ? round(($rainReadings - $alertDays)     / $totalReadings * 100) : 11;
        $wxAlertPct    = max(0, 100 - $wxNormalPct - $wxRainPct);

        $rainAlert = false; $alertMsg = '';
        if ($forecastData && isset($forecastData['list'])) {
            foreach (array_slice($forecastData['list'], 0, 8) as $item) {
                $pop = ($item['pop'] ?? 0) * 100;
                if ($pop >= 70) { $rainAlert = true; $alertMsg = 'Heavy rain expected in the next 24 hours (' . round($pop) . '% chance). Check tank overflow settings and ensure drainage is clear.'; break; }
            }
        }
        if (!$rainAlert && $wxTemp !== '--' && $wxTemp > 32) {
            $rainAlert = true;
            $alertMsg  = "Temperature is {$wxTemp}°C. Heat risk — stay hydrated and limit outdoor exposure between 11 AM – 3 PM.";
        }
        break;

    /* ── MAP ─────────────────────────────────────────────────────────────── */
    case 'map':
        $stmtUser = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
        $stmtUser->execute([$userId]);
        $mapCurrentUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

        $mapTanks = $pdo->query("
            SELECT t.tank_id, t.tankname, t.location_add, t.current_liters, t.max_capacity, t.status_tank,
                   ROUND((t.current_liters / t.max_capacity) * 100) AS fill_pct,
                   (SELECT sr.recorded_at FROM sensor_readings sr
                    INNER JOIN sensors s ON s.sensor_id = sr.sensor_id
                    WHERE s.tank_id = t.tank_id ORDER BY sr.recorded_at DESC LIMIT 1) AS last_reading
            FROM tank t ORDER BY t.tank_id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $mapTotalStored   = array_sum(array_column($mapTanks, 'current_liters'));
        $mapTotalCapacity = array_sum(array_column($mapTanks, 'max_capacity'));
        $mapOverallPct    = $mapTotalCapacity > 0 ? round(($mapTotalStored / $mapTotalCapacity) * 100) : 0;
        $mapOnlineCount   = count(array_filter($mapTanks, fn($t) => strtolower($t['status_tank']) === 'active'));
        $mapSystemStatus  = $mapOnlineCount === count($mapTanks) ? 'All Online' : ($mapOnlineCount > 0 ? 'Partial' : 'Offline');

        function timeAgoMap(?string $datetime): string {
            if (!$datetime) return 'No data';
            $diff = time() - strtotime($datetime);
            if ($diff < 60)    return 'just now';
            if ($diff < 3600)  return round($diff / 60) . ' min ago';
            if ($diff < 86400) return round($diff / 3600) . ' hr ago';
            return round($diff / 86400) . ' days ago';
        }
        function statusBadgeMap(string $status): string {
            $s = strtolower($status);
            if ($s === 'active')      return '<span class="badge badge-online"  style="padding:3px 9px;font-size:11px;font-weight:600;">📶 Online</span>';
            if ($s === 'maintenance') return '<span class="badge badge-maintenance" style="padding:3px 9px;font-size:11px;font-weight:600;">🔧 Maintenance</span>';
            return '<span class="badge badge-offline" style="padding:3px 9px;font-size:11px;font-weight:600;">⚠ Offline</span>';
        }
        function barColorMap(int $pct): string {
            if ($pct >= 75) return '#3b82f6';
            if ($pct >= 40) return '#f59e0b';
            return '#ef4444';
        }
        break;

    /* ── SETTINGS ────────────────────────────────────────────────────────── */
    case 'settings':
        $settingsSuccess = '';
        $settingsError   = '';

        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            setting_key   VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $settingsTank     = $pdo->query("SELECT * FROM tank LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $settingsAllTanks = $pdo->query("SELECT tank_id, tankname, location_add, status_tank FROM tank ORDER BY tank_id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $settingsRows     = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

        function cfg(array $rows, string $key, $default) { return $rows[$key] ?? $default; }

        /* Handle Delete Tank */
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tank'])) {
            try {
                $dTankId = (int)($_POST['tank_id'] ?? 0);
                if ($dTankId <= 0) throw new Exception('Invalid tank ID.');
                $stmt = $pdo->prepare("DELETE FROM tank WHERE tank_id = ?");
                $stmt->execute([$dTankId]);
                if ($stmt->rowCount() === 0) throw new Exception('Tank not found or already deleted.');
                $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, role, action, status, ip_address) VALUES (?,?,?,'delete_tank','success',?)")
                    ->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '', $_SESSION['role'] ?? 'manager', $_SERVER['REMOTE_ADDR'] ?? '']);
                $settingsSuccess  = 'Tank deleted successfully.';
                $settingsAllTanks = $pdo->query("SELECT tank_id, tankname, location_add, status_tank FROM tank ORDER BY tank_id ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $settingsError = $e->getMessage(); }
        }

        /* Handle Add Tank */
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tank'])) {
            try {
                $aTankName    = trim($_POST['tankname']        ?? '');
                $aLocationAdd = trim($_POST['location_add']    ?? '');
                $aCurLiters   = (int)($_POST['current_liters'] ?? 0);
                $aMaxCap      = (int)($_POST['max_capacity']   ?? 0);
                $aStatus      = trim($_POST['status_tank']     ?? 'Active');
                if ($aTankName    === '') throw new Exception('Tank name is required.');
                if ($aLocationAdd === '') throw new Exception('Location is required.');
                if ($aMaxCap       <= 0) throw new Exception('Max capacity must be greater than 0.');
                $pdo->prepare("INSERT INTO tank (tankname, location_add, current_liters, max_capacity, status_tank) VALUES (?,?,?,?,?)")
                    ->execute([$aTankName, $aLocationAdd, $aCurLiters, $aMaxCap, $aStatus]);
                $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, role, action, status, ip_address) VALUES (?,?,?,'add_tank','success',?)")
                    ->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '', $_SESSION['role'] ?? 'manager', $_SERVER['REMOTE_ADDR'] ?? '']);
                $settingsSuccess  = 'Tank added successfully.';
                $settingsTank     = $pdo->query("SELECT * FROM tank LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $settingsAllTanks = $pdo->query("SELECT tank_id, tankname, location_add, status_tank FROM tank ORDER BY tank_id ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $settingsError = $e->getMessage(); }
        }

        /* Handle Settings Save */
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['add_tank']) && !isset($_POST['delete_tank'])) {
            try {
                $sCap   = (int)($_POST['tank_capacity'] ?? 5000);
                $sThres = (int)($_POST['threshold']     ?? 1000);
                if ($settingsTank)
                    $pdo->prepare("UPDATE tank SET max_capacity = ? WHERE tank_id = ?")->execute([$sCap, $settingsTank['tank_id']]);
                $sData = [
                    'tank_capacity'       => $sCap,
                    'threshold'           => $sThres,
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
                $sStmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
                foreach ($sData as $k => $v) $sStmt->execute([$k, $v]);
                $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, role, action, status, ip_address) VALUES (?,?,?,'update_settings','success',?)")
                    ->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '', $_SESSION['role'] ?? 'manager', $_SERVER['REMOTE_ADDR'] ?? '']);
                $settingsRows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
                $settingsTank = $pdo->query("SELECT * FROM tank LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $settingsSuccess = 'Settings saved successfully.';
            } catch (PDOException $e) { $settingsError = 'Database error: ' . $e->getMessage(); }
        }

        $sMe      = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $sMe->execute([$_SESSION['user_id']]);
        $sMe      = $sMe->fetch(PDO::FETCH_ASSOC);
        $sMaxCap  = (int)cfg($settingsRows, 'tank_capacity', $settingsTank['max_capacity'] ?? 5000);
        $sThreshV = (int)cfg($settingsRows, 'threshold', 1000);
        $sPct     = $sMaxCap > 0 ? round($sThreshV / $sMaxCap * 100) : 20;
        break;

    /* ── USER MANAGEMENT ─────────────────────────────────────────────────── */
    case 'user':
        $uAction  = $_POST['action'] ?? '';
        $uSuccess = '';
        $uError   = '';

        if ($uAction === 'add') {
            $uEmail = trim($_POST['email'] ?? ''); $uPass = trim($_POST['password'] ?? ''); $uRole = $_POST['role'] ?? 'user';
            if (!$uEmail || !$uPass) { $uError = 'Email and password are required.'; }
            elseif (!filter_var($uEmail, FILTER_VALIDATE_EMAIL)) { $uError = 'Invalid email address.'; }
            else {
                try {
                    $hash = password_hash($uPass, PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO users (email, password, role) VALUES (?,?,?)")->execute([$uEmail, $hash, $uRole]);
                    $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, action, status, ip_address) VALUES (?,?,'add_user','success',?)")->execute([$pdo->lastInsertId(), $uEmail, $_SERVER['REMOTE_ADDR'] ?? '']);
                    $uSuccess = "User <strong>$uEmail</strong> added successfully.";
                } catch (PDOException $e) {
                    $uError = strpos($e->getMessage(), 'Duplicate') !== false ? 'That email is already registered.' : 'Database error: ' . $e->getMessage();
                }
            }
        }
        if ($uAction === 'edit') {
            $eId = (int)($_POST['id'] ?? 0); $eEmail = trim($_POST['email'] ?? ''); $eRole = $_POST['role'] ?? 'user';
            if (!$eId || !$eEmail) { $uError = 'Invalid data.'; }
            else {
                try {
                    if (!empty($_POST['password'])) {
                        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE users SET email=?,password=?,role=? WHERE id=?")->execute([$eEmail,$hash,$eRole,$eId]);
                    } else {
                        $pdo->prepare("UPDATE users SET email=?,role=? WHERE id=?")->execute([$eEmail,$eRole,$eId]);
                    }
                    $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, action, status, ip_address) VALUES (?,?,'edit_user','success',?)")->execute([$eId,$eEmail,$_SERVER['REMOTE_ADDR']??'']);
                    $uSuccess = "User updated successfully.";
                } catch (PDOException $e) { $uError = 'Database error: ' . $e->getMessage(); }
            }
        }
        if ($uAction === 'delete') {
            $dIds = [];
            if (!empty($_POST['id']))  $dIds = [(int)$_POST['id']];
            elseif (!empty($_POST['ids'])) $dIds = array_map('intval', explode(',', $_POST['ids']));
            if ($dIds) {
                $ph = implode(',', array_fill(0, count($dIds), '?'));
                try {
                    $pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($dIds);
                    $pdo->prepare("INSERT INTO user_activity_logs (action,status,ip_address) VALUES ('delete_user','success',?)")->execute([$_SERVER['REMOTE_ADDR']??'']);
                    $uSuccess = count($dIds) . ' user(s) deleted successfully.';
                } catch (PDOException $e) { $uError = 'Database error: ' . $e->getMessage(); }
            }
        }

        $uSearch  = trim($_GET['q'] ?? '');
        $uPage    = max(1, (int)($_GET['upage'] ?? 1));
        $uPerPage = 10;
        $uOffset  = ($uPage - 1) * $uPerPage;
        if ($uSearch) {
            $like   = "%$uSearch%";
            $uTotal = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email LIKE ? OR role LIKE ?");
            $uTotal->execute([$like, $like]);
            $uUsers = $pdo->prepare("SELECT * FROM users WHERE email LIKE ? OR role LIKE ? ORDER BY created_at DESC LIMIT $uPerPage OFFSET $uOffset");
            $uUsers->execute([$like, $like]);
        } else {
            $uTotal = $pdo->query("SELECT COUNT(*) FROM users");
            $uUsers = $pdo->query("SELECT * FROM users ORDER BY created_at DESC LIMIT $uPerPage OFFSET $uOffset");
        }
        $uTotalUsers = (int)$uTotal->fetchColumn();
        $uUserRows   = $uUsers->fetchAll();
        $uTotalPages = max(1, ceil($uTotalUsers / $uPerPage));
        $uLogs       = $pdo->query("SELECT ual.*, u.email AS user_email FROM user_activity_logs ual LEFT JOIN users u ON ual.user_id = u.id ORDER BY ual.created_at DESC LIMIT 10")->fetchAll();
        break;

} // end data-loading switch

/* ── Shared avatar initials ─────────────────────────────────────────────── */
$avatarInitialsShared = function_exists('avatarInitials')
    ? htmlspecialchars(avatarInitials())
    : strtoupper(substr($_SESSION['email'] ?? $_SESSION['username'] ?? 'M', 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>EcoRain — <?= ucfirst($page === 'dashboard' ? 'Dashboard' : $page) ?></title>

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=Barlow+Condensed:wght@400;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

  <!-- Chart.js — used by Dashboard, Oversight, Usage, Weather -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <?php if ($page === 'map'): ?>
  <!-- Leaflet — map page only -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
  <?php endif; ?>

  <?php if ($page === 'user'): ?>
  <!-- Bootstrap + jQuery + Material Icons — user page only -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>
  <?php endif; ?>

  <!-- Master stylesheet -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/app/manager/manager_style.css">
  <!-- Legacy shared CSS -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/others/all.css">
</head>
<body>

<!-- ============================================================
     SHARED: SIDEBAR OVERLAY
     ============================================================ -->
<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<?php if ($page === 'user'): ?>
<!-- ============================================================
     PAGE: USER MANAGEMENT  (standalone top-nav layout)
     ============================================================ -->
<nav class="top-nav">
  <a href="<?= BASE_URL ?>/app/manager/manager_master.php" class="top-nav-brand">
    <div class="logo-drop">💧</div>
    <span class="logo-name">EcoRain</span>
  </a>
  <a href="<?= BASE_URL ?>/app/manager/manager_master.php" class="top-nav-back">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
    Back to Dashboard
  </a>
</nav>

<div style="max-width:1200px;margin:0 auto;padding:1.5rem 1.25rem 3rem;">

  <!-- Flash alerts -->
  <?php if ($uSuccess): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?= $uSuccess ?><button type="button" class="close" data-dismiss="alert">&times;</button></div><?php endif; ?>
  <?php if ($uError):   ?><div class="alert alert-danger  alert-dismissible fade show" role="alert"><?= htmlspecialchars($uError) ?><button type="button" class="close" data-dismiss="alert">&times;</button></div><?php endif; ?>

  <!-- USERS TABLE -->
  <div class="table-wrapper">
    <div class="table-title-bar">
      <h2>User Management</h2>
      <div class="spacer"></div>
      <form method="GET" class="search-bar" style="display:inline;">
        <input type="hidden" name="page" value="user">
        <input type="text" name="q" placeholder="Search..." value="<?= htmlspecialchars($uSearch) ?>">
      </form>
      <button class="btn-sm-action btn-green" data-toggle="modal" data-target="#addUserModal"><i class="material-icons" style="font-size:16px;">add</i> Add User</button>
      <button class="btn-sm-action btn-red" id="bulkDeleteBtn" data-toggle="modal" data-target="#bulkDeleteModal"><i class="material-icons" style="font-size:16px;">delete</i> Delete</button>
    </div>
    <div class="tbl-scroll">
      <table class="data-table">
        <thead><tr>
          <th><span class="custom-checkbox"><input type="checkbox" id="selectAll"><label for="selectAll"></label></span></th>
          <th>#</th><th>Email</th><th>Role</th><th>Verified</th><th>Created</th><th>Actions</th>
        </tr></thead>
        <tbody>
          <?php if ($uUserRows): foreach ($uUserRows as $u): ?>
          <tr>
            <td><span class="custom-checkbox"><input type="checkbox" class="row-checkbox" value="<?= $u['id'] ?>" id="chk<?= $u['id'] ?>"><label for="chk<?= $u['id'] ?>"></label></span></td>
            <td><?= $u['id'] ?></td>
            <td style="word-break:break-all"><?= htmlspecialchars($u['email']) ?></td>
            <td><span class="role-badge role-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
            <td><?php if ($u['is_verified']): ?><i class="material-icons verified-icon">check_circle</i><?php else: ?><i class="material-icons unverified-icon">cancel</i><?php endif; ?></td>
            <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
            <td>
              <a href="#editUserModal" class="action-link edit" data-toggle="modal" data-id="<?= $u['id'] ?>" data-email="<?= htmlspecialchars($u['email']) ?>" data-role="<?= $u['role'] ?>"><i class="material-icons">edit</i></a>
              <a href="#deleteUserModal" class="action-link delete" data-toggle="modal" data-id="<?= $u['id'] ?>" data-email="<?= htmlspecialchars($u['email']) ?>"><i class="material-icons">delete</i></a>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:2rem;">No users found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="tbl-footer">
      <div class="hint-text">Showing <b><?= count($uUserRows) ?></b> of <b><?= $uTotalUsers ?></b> users<?= $uSearch ? ' — <em>' . htmlspecialchars($uSearch) . '</em>' : '' ?></div>
      <?php if ($uTotalPages > 1): ?>
      <div class="pager pager-user" style="display:flex;gap:.3rem;">
        <?php if ($uPage > 1): ?><a href="?page=user&upage=<?= $uPage-1 ?>&q=<?= urlencode($uSearch) ?>">‹</a><?php endif; ?>
        <?php for ($p = 1; $p <= $uTotalPages; $p++): ?>
          <?php if ($p === $uPage): ?><span class="active"><?= $p ?></span><?php else: ?><a href="?page=user&upage=<?= $p ?>&q=<?= urlencode($uSearch) ?>"><?= $p ?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if ($uPage < $uTotalPages): ?><a href="?page=user&upage=<?= $uPage+1 ?>&q=<?= urlencode($uSearch) ?>">›</a><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div><!-- /table-wrapper -->

  <!-- ACTIVITY LOG -->
  <div class="log-wrapper">
    <div class="log-title-bar"><h3>📋 Recent Activity Log</h3></div>
    <div class="tbl-scroll">
      <table class="data-table">
        <thead><tr><th>#</th><th>User</th><th>Email</th><th>Action</th><th>Status</th><th>IP</th><th>Time</th></tr></thead>
        <tbody>
          <?php if ($uLogs): foreach ($uLogs as $log): ?>
          <tr>
            <td><?= $log['activity_id'] ?></td>
            <td><?= $log['user_id'] ? '#' . $log['user_id'] : '<em style="color:#9ca3af">—</em>' ?></td>
            <td style="word-break:break-all"><?= htmlspecialchars($log['email'] ?? $log['user_email'] ?? '—') ?></td>
            <td><?= htmlspecialchars(str_replace('_', ' ', $log['action'])) ?></td>
            <td><span class="status-<?= $log['status'] === 'success' ? 'ok' : 'bad' ?>"><?= ucfirst($log['status']) ?></span></td>
            <td><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
            <td><?= date('M j, H:i', strtotime($log['created_at'])) ?></td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:2rem;">No activity logged yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div><!-- /content -->

<!-- ADD USER MODAL -->
<div id="addUserModal" class="modal fade"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="POST"><input type="hidden" name="action" value="add"><input type="hidden" name="page" value="user">
    <div class="modal-header"><h5 class="modal-title">Add New User</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" placeholder="user@example.com" required></div>
      <div class="form-group"><label>Password</label><input type="password" name="password" class="form-control" required></div>
      <div class="form-group"><label>Role</label><select name="role" class="form-control"><option value="user">User</option><option value="admin">Admin</option></select></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success btn-sm">Add User</button></div>
  </form>
</div></div></div>

<!-- EDIT USER MODAL -->
<div id="editUserModal" class="modal fade"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="page" value="user"><input type="hidden" name="id" id="editUserId">
    <div class="modal-header"><h5 class="modal-title">Edit User</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Email</label><input type="email" name="email" id="editEmail" class="form-control" required></div>
      <div class="form-group"><label>New Password <small class="text-muted">(leave blank to keep)</small></label><input type="password" name="password" class="form-control"></div>
      <div class="form-group"><label>Role</label><select name="role" id="editRole" class="form-control"><option value="user">User</option><option value="admin">Admin</option></select></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Save Changes</button></div>
  </form>
</div></div></div>

<!-- DELETE SINGLE MODAL -->
<div id="deleteUserModal" class="modal fade"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="page" value="user"><input type="hidden" name="id" id="deleteUserId">
    <div class="modal-header"><h5 class="modal-title">Delete User</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <div class="modal-body"><p>Delete <strong id="deleteUserEmail"></strong>? This action cannot be undone.</p></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger btn-sm">Delete</button></div>
  </form>
</div></div></div>

<!-- BULK DELETE MODAL -->
<div id="bulkDeleteModal" class="modal fade"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="page" value="user"><input type="hidden" name="ids" id="bulkDeleteIds">
    <div class="modal-header"><h5 class="modal-title">Bulk Delete</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <div class="modal-body"><p>Delete <strong id="bulkDeleteCount">0</strong> selected user(s)? This cannot be undone.</p></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger btn-sm">Delete All</button></div>
  </form>
</div></div></div>

<script>
$(function() {
  var $cb = $('input.row-checkbox');
  $('#selectAll').click(function() { $cb.prop('checked', this.checked); });
  $cb.click(function() { if (!this.checked) $('#selectAll').prop('checked', false); });
  $(document).on('click','a.action-link.edit',   function() { $('#editUserId').val($(this).data('id')); $('#editEmail').val($(this).data('email')); $('#editRole').val($(this).data('role')); });
  $(document).on('click','a.action-link.delete', function() { $('#deleteUserId').val($(this).data('id')); $('#deleteUserEmail').text($(this).data('email')); });
  $('#bulkDeleteBtn').click(function(e) {
    var ids = $cb.filter(':checked').map(function() { return this.value; }).get();
    if (!ids.length) { e.preventDefault(); e.stopPropagation(); alert('Select at least one user.'); return false; }
    $('#bulkDeleteIds').val(ids.join(',')); $('#bulkDeleteCount').text(ids.length);
  });
  setTimeout(function() { $('.alert').fadeOut('slow'); }, 4000);
});
</script>

<?php else: /* ── All other pages: sidebar layout ── */?>

     
<aside class="sidebar" id="sidebar">
  <div class="logo">
    <span class="logo-icon">💧</span>
    <span class="logo-text">EcoRain</span>
  </div>

  <a href="<?= BASE_URL ?>/app/manager/manager_master.php" class="nav-item <?= $activePage==='dashboard'?'active':'' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
    <span>Dashboard</span>
  </a>
  <a href="<?= BASE_URL ?>/app/manager/manager_master.php?page=oversight" class="nav-item <?= $activePage==='oversight'?'active':'' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
    <span>Oversight</span>
  </a>
  <a href="<?= BASE_URL ?>/app/manager/manager_master.php?page=usage" class="nav-item <?= $activePage==='usage'?'active':'' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    <span>Usage Stats</span>
  </a>
  <a href="<?= BASE_URL ?>/app/manager/manager_master.php?page=weather" class="nav-item <?= $activePage==='weather'?'active':'' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
    <span>Weather</span>
  </a>
  <a href="<?= BASE_URL ?>/app/manager/manager_master.php?page=map" class="nav-item <?= $activePage==='map'?'active':'' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
    <span>Tank Map</span>
  </a>
  <a href="<?= BASE_URL ?>/app/manager/manager_master.php?page=settings" class="nav-item <?= $activePage==='settings'?'active':'' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
    <span>Settings</span>
  </a>

  <div class="sidebar-spacer"></div>
  <div class="sidebar-bottom">
    <a href="<?= BASE_URL ?>/connections/signout.php" class="nav-item logout">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      <span>Log Out</span>
    </a>
  </div>
</aside>

<!-- ============================================================
     MAP uses shell→content structure; others use app-body
     ============================================================ -->
<?php if ($page === 'map'): ?>
<div class="shell" style="margin-left:var(--sidebar-w);">
  <div class="main">
<?php else: ?>
<div class="app-body">
<?php endif; ?>

  <!-- ============================================================
       SHARED: TOPBAR
       ============================================================ -->
  <header class="topbar">
    <div class="topbar-left">
      <button class="hamburger" onclick="toggleSidebar()" aria-label="Menu">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div>
        <?php
        $titleMap = [
            'dashboard' => '<span class="live-dot"></span>Dashboard',
            'oversight' => '<span class="live-dot"></span>Manager Oversight',
            'usage'     => 'Usage Statistics',
            'weather'   => 'Weather Monitor',
            'map'       => 'Tank Locations',
            'settings'  => 'Settings',
        ];
        $subMap = [
            'dashboard' => 'Welcome to EcoRain',
            'oversight' => 'Tank status, user activity &amp; sensor anomalies',
            'usage'     => 'Track your water conservation impact',
            'weather'   => 'Live conditions — Manolo Fortich, Bukidnon',
            'map'       => 'Monitor your tank network',
            'settings'  => 'Configure your EcoRain System',
        ];
        ?>
        <div class="page-title"><?= $titleMap[$page] ?? 'EcoRain' ?></div>
        <div class="page-sub"><?= $subMap[$page]   ?? '' ?></div>
      </div>
    </div>
    <div class="topbar-right">
      <?php if (($page === 'oversight') && ($anomalies ?? 0) > 0): ?>
      <div class="t-btn" title="<?= $anomalies ?> sensor anomalies today" style="border-color:#d97706;color:#d97706">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      </div>
      <?php endif; ?>
      <?php if ($page === 'map'): ?>
      <div class="search-box" id="desktopSearchBox">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" placeholder="Search tanks..." id="searchInput">
      </div>
      <?php else: ?>
      <div class="t-search">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" placeholder="Search..."/>
      </div>
      <?php endif; ?>
      <div class="t-btn">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        <span class="notif-dot"></span>
      </div>
      <a href="<?= BASE_URL ?>/app/manager/manager_master.php?page=user" class="t-avatar"><?= $avatarInitialsShared ?></a>
    </div>
  </header>

  <!-- ============================================================
       PAGE CONTENT — open the correct wrapper then switch
       ============================================================ -->
  <?php if ($page === 'map'): ?>
  <div class="content" style="flex:1;display:flex;overflow:hidden;min-height:0;">
  <?php else: ?>
  <main class="main-content">
  <?php endif; ?>
 

    <!-- ============================================================
       SECTION: DASHBOARD START
       ============================================================ -->
    <?php if ($page === 'dashboard' || $page === 'default'): ?>

    <!-- TOP ROW: Tank Card + Water Quality + Chart -->
    <div style="display:grid;grid-template-columns:320px 1fr 1fr;gap:1.25rem;align-items:start;margin-bottom:1.25rem;" class="top-row">

      <!-- Aggregate Tank Card -->
      <div class="tank-card" style="background:<?= $tankBg ?>">
        <div class="tank-header">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M12 8v4l3 3"/></svg>
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
          <span class="tank-stat-chip"><span class="chip-dot" style="background:<?= $tColor ?>"></span><?= htmlspecialchars($t['tankname']) ?> <?= $tPct ?>%</span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="tank-footer">
          <div class="tank-meta"><span><?= number_format($totalCurrentLiters) ?>L stored</span><span><?= number_format($totalMaxCapacity) ?>L max</span></div>
          <div class="tank-bar-bg"><div class="tank-bar-fill" style="width:<?= $percent ?>%;background:<?= $tankAccent ?>"></div></div>
          <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
            <div class="tank-collected">💧 <?= number_format($todayCollected, 0) ?>L collected today</div>
            <a href="<?= BASE_URL ?>/app/manager/manager_master.php?page=map" class="tank-view-map">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
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
          <div class="wq-metric-hd"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>pH Level</div>
          <div class="wq-val"><?= $quality ? $quality['ph_level'] : '0.0' ?></div>
          <div class="wq-lbl" style="color:<?= $quality ? phColor($quality['ph_level']) : '#16a34a' ?>"><?= $quality ? phLabel($quality['ph_level']) : 'None' ?></div>
        </div>
        <div class="wq-metric">
          <div class="wq-metric-hd"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8.56 2.75c4.37 6.03 6.02 9.42 8.03 17.72m2.54-15.38c-3.72 4.35-8.94 5.66-16.88 5.85m19.5 1.9c-3.5-.93-6.63-.82-8.94 0-2.58.92-5.01 2.86-7.44 6.32"/></svg>Turbidity</div>
          <div class="wq-val"><?= $quality ? $quality['turbidity'] : '0.0' ?></div>
          <div class="wq-lbl" style="color:<?= $quality ? turbColor($quality['turbidity']) : '#16a34a' ?>"><?= $quality ? turbLabel($quality['turbidity']) : 'None' ?></div>
        </div>
      </div>

      <!-- 7-Day Usage Chart -->
      <div class="card">
        <div style="font-size:.875rem;font-weight:600;margin-bottom:.85rem;">Water Usage — Last 7 Days</div>
        <div style="height:200px;position:relative;"><canvas id="bar-chart"></canvas></div>
      </div>
    </div><!-- /top-row -->

    <!-- MID ROW: Forecast + Sensor Readings -->
    <div class="two-col" style="margin-bottom:1.25rem;">
      <div class="card">
        <div class="forecast-title" id="wx-location">Rainfall Forecast</div>
        <div id="wx-error"   style="display:none;color:#ef4444;font-size:.8rem;margin-bottom:.5rem"></div>
        <div id="wx-loading" style="color:var(--muted);font-size:.82rem">Loading forecast...</div>
        <div id="forecastSection" style="display:none">
          <div class="forecast-inner" id="rainfallForecast"></div>
        </div>
      </div>
      <div class="card">
        <div class="card-label">Sensor Readings</div>
        <?php if ($sensors): ?>
        <table class="mini-table">
          <thead><tr><th>Sensor</th><th>Model</th><th>Anomaly</th><th>Time</th></tr></thead>
          <tbody>
            <?php foreach ($sensors as $s): ?>
            <tr>
              <td><?= htmlspecialchars($s['sensor_type']) ?></td>
              <td style="color:var(--muted)"><?= htmlspecialchars($s['model']) ?></td>
              <td><span class="badge"><?= htmlspecialchars($s['anomaly']) ?></span></td>
              <td><?= date('H:i', strtotime($s['recorded_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?><p style="color:var(--subtle);font-size:.82rem;margin-top:.35rem">No readings yet.</p><?php endif; ?>
      </div>
    </div>

    <!-- BOTTOM ROW: Activity Log + Fleet Summary -->
    <div class="bottom-row">
      <div class="card">
        <div class="card-label">Activity Log</div>
        <?php if ($activities): ?>
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
        <?php else: ?><p style="color:var(--subtle);font-size:.82rem;margin-top:.35rem">No activity yet.</p><?php endif; ?>
      </div>
      <div class="card">
        <div class="card-label">Fleet Summary</div>
        <?php if (!empty($allTanks)): ?>
        <table class="mini-table" style="table-layout:fixed;width:100%">
          <colgroup><col style="width:22%"><col style="width:48%"><col style="width:30%"></colgroup>
          <thead><tr><th>Tank</th><th>Fill</th><th style="text-align:right">Status</th></tr></thead>
          <tbody>
            <?php foreach ($allTanks as $t):
              $tPct   = $t['max_capacity'] > 0 ? round(($t['current_liters'] / $t['max_capacity']) * 100) : 0;
              $tColor = $tPct >= 75 ? '#3b82f6' : ($tPct >= 40 ? '#f59e0b' : '#ef4444');
              $tSt    = strtolower($t['status_tank']);
            ?>
            <tr>
              <td style="font-weight:600"><?= htmlspecialchars($t['tankname']) ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:.5rem">
                  <div style="flex:1;height:5px;background:var(--border);border-radius:99px;overflow:hidden"><div style="width:<?= $tPct ?>%;height:100%;background:<?= $tColor ?>;border-radius:99px"></div></div>
                  <span style="font-size:.73rem;font-weight:600;color:var(--muted);min-width:2.4rem;text-align:right"><?= $tPct ?>%</span>
                </div>
              </td>
              <td style="text-align:right"><span class="badge" style="<?= $tSt==='active' ? '' : 'background:#fef2f2;color:#b91c1c;border-color:#fecaca' ?>"><?= htmlspecialchars($t['status_tank']) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:1rem;padding-top:.75rem;border-top:1px solid var(--border)">
          <span style="font-size:.78rem;color:var(--muted)"><?= $onlineCount ?>/<?= $tankCount ?> tanks online</span>
          <span style="font-size:.78rem;font-weight:600"><?= number_format($totalCurrentLiters) ?>L / <?= number_format($totalMaxCapacity) ?>L</span>
        </div>
        <?php else: ?><p style="color:var(--subtle);font-size:.82rem;margin-top:.35rem">No tank data.</p><?php endif; ?>
      </div>
    </div>

    <!-- Chart data injection -->
    <script>
    window.dashChartLabels = <?= json_encode($chartLabels) ?>;
    window.dashChartData   = <?= json_encode($chartData) ?>;
    </script>
    <!-- SECTION: DASHBOARD END -->

    

    <!--============================================================
       SECTION: MANAGER OVERSIGHT START
       ============================================================ -->
     <?php elseif ($page === 'oversight'): ?>

    <!-- STAT CARDS -->
    <div class="stats-grid">
      <div class="stat-card" style="display:flex;align-items:center;gap:1rem;">
        <div class="stat-icon" style="background:#eff6ff">🪣</div>
        <div><div class="stat-num"><?= $onlineTanks ?>/<?= $tankCount ?></div><div class="stat-lbl">Tanks Online</div></div>
      </div>
      <div class="stat-card" style="display:flex;align-items:center;gap:1rem;">
        <div class="stat-icon" style="background:#ecfdf5">💧</div>
        <div><div class="stat-num"><?= number_format($todayUsage, 0) ?>L</div><div class="stat-lbl">Used Today</div></div>
      </div>
      <div class="stat-card" style="display:flex;align-items:center;gap:1rem;">
        <div class="stat-icon" style="background:#f5f3ff">👥</div>
        <div><div class="stat-num"><?= count($activeUsers) ?></div><div class="stat-lbl">Active Users</div></div>
      </div>
      <div class="stat-card <?= $anomalies > 0 ? 'stat-warn' : '' ?>" style="display:flex;align-items:center;gap:1rem;">
        <div class="stat-icon" style="background:#fffbeb">⚠️</div>
        <div><div class="stat-num"><?= $anomalies ?></div><div class="stat-lbl">Anomalies Today</div></div>
      </div>
    </div>

    <!-- TANK FLEET + 7-DAY CHART -->
    <div class="three-col">
      <div class="card">
        <div class="card-hd">
          <span class="card-title">Tank Fleet Status</span>
          <a href="<?= BASE_URL ?>/app/manager/manager_master.php?page=map" style="font-size:.78rem;font-weight:600;color:var(--accent);text-decoration:none">View Map →</a>
        </div>
        <div class="tank-grid">
          <?php foreach ($tankActivity as $t):
            $pct   = $t['max_capacity'] > 0 ? round(($t['current_liters']/$t['max_capacity'])*100) : 0;
            $color = $pct >= 75 ? '#3b82f6' : ($pct >= 40 ? '#f59e0b' : '#ef4444');
            $tS    = strtolower($t['status_tank']);
          ?>
          <div class="tank-item" style="border-color:<?= $t['anomaly_count']>0 ? '#fde68a' : 'var(--border)' ?>">
            <div class="tank-item-name"><?= htmlspecialchars($t['tankname']) ?></div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.25rem">
              <span style="font-size:.7rem;font-weight:600;color:<?= $color ?>"><?= $pct ?>%</span>
              <span class="badge" style="<?= $tS==='active' ? '' : 'background:#fef2f2;color:#b91c1c;border-color:#fecaca' ?>"><?= ucfirst($t['status_tank']) ?></span>
            </div>
            <div class="tank-fill-bar"><div class="tank-fill-inner" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
            <div class="tank-item-meta">
              <span><?= number_format($t['current_liters']) ?>L / <?= number_format($t['max_capacity']) ?>L</span>
              <span style="color:<?= $t['anomaly_count']>0 ? '#d97706' : 'var(--subtle)' ?>"><?= $t['anomaly_count'] ?> anomaly<?= $t['anomaly_count'] != 1 ? 's' : '' ?></span>
            </div>
            <?php if ($t['today_liters'] > 0): ?><div style="font-size:.7rem;color:var(--accent);margin-top:.3rem">+<?= number_format($t['today_liters'],0) ?>L collected today</div><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-hd"><span class="card-title">Usage — 7 Days</span></div>
        <div style="height:180px;position:relative;"><canvas id="usageChart"></canvas></div>
        <div style="margin-top:1.25rem;border-top:1px solid var(--border);padding-top:1rem">
          <div class="card-label" style="margin-bottom:.7rem">Active Today</div>
          <?php if (empty($activeUsers)): ?>
            <p style="color:var(--subtle);font-size:.8rem">No user activity today.</p>
          <?php else: ?>
            <?php foreach ($activeUsers as $au):
              $initials = strtoupper(substr($au['username'] ?? 'U', 0, 2));
              $bg = $au['role'] === 'manager' ? '#7c3aed' : '#10b981';
            ?>
            <div class="active-user-row">
              <div class="mini-av" style="background:<?= $bg ?>"><?= htmlspecialchars($initials) ?></div>
              <div style="flex:1;min-width:0">
                <div style="font-size:.82rem;font-weight:600"><?= htmlspecialchars($au['username'] ?? 'Unknown') ?></div>
                <div style="font-size:.7rem;color:var(--subtle)"><?= htmlspecialchars($au['email'] ?? '') ?></div>
              </div>
              <div style="text-align:right">
                <div style="font-size:.78rem;font-weight:600"><?= $au['action_count'] ?> acts</div>
                <div style="font-size:.68rem;color:var(--subtle)"><?= timeAgoMgr($au['last_action']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div><!-- /three-col -->

    <!-- TABS -->
    <div class="card">
      <div class="tabs">
        <div class="tab active" onclick="switchTab('log',this)">User Activity Log</div>
        <div class="tab" onclick="switchTab('sensors',this)">Sensor Anomalies<?php if ($anomalies > 0): ?> <span style="background:#f59e0b;color:#fff;border-radius:99px;font-size:.65rem;padding:.1rem .4rem;margin-left:.3rem"><?= $anomalies ?></span><?php endif; ?></div>
        <div class="tab" onclick="switchTab('quality',this)">Water Quality Log</div>
        <div class="tab" onclick="switchTab('pages',this)">Page Visits</div>
      </div>

      <!-- TAB: User Activity Log -->
      <div id="tab-log">
        <form method="get" class="filter-bar">
          <input type="hidden" name="page" value="oversight">
          <select name="role"><option value="">All roles</option><option value="manager" <?= $filterRole==='manager'?'selected':'' ?>>Manager</option><option value="user" <?= $filterRole==='user'?'selected':'' ?>>User</option></select>
          <input type="text"  name="action" placeholder="Action…" value="<?= htmlspecialchars($filterAction) ?>">
          <input type="date"  name="date"   value="<?= htmlspecialchars($filterDate) ?>">
          <button type="submit" class="btn-sm btn-primary">Filter</button>
          <a href="?page=oversight" class="btn-sm btn-ghost" style="display:inline-flex;align-items:center;text-decoration:none">Reset</a>
        </form>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Description</th><th>IP</th><th>When</th></tr></thead>
            <tbody>
              <?php if (empty($logs)): ?>
              <tr><td colspan="7" style="text-align:center;color:var(--subtle);padding:1.5rem 0">No activity found.</td></tr>
              <?php else: foreach ($logs as $log): ?>
              <tr>
                <td><div style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($log['username'] ?? '—') ?></div><div style="font-size:.7rem;color:var(--subtle)"><?= htmlspecialchars($log['user_email'] ?? $log['email'] ?? '') ?></div></td>
                <td><?= roleBadgeMgr($log['role'] ?? 'user') ?></td>
                <td style="font-size:.8rem"><?= actionIconMgr($log['action']) ?> <?= htmlspecialchars($log['action']) ?></td>
                <td style="font-size:.75rem;color:var(--muted)"><?= htmlspecialchars($log['module'] ?? '—') ?></td>
                <td style="font-size:.75rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($log['description'] ?? '') ?>"><?= htmlspecialchars($log['description'] ?? '—') ?></td>
                <td><span class="ip-chip"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></span></td>
                <td style="font-size:.75rem;color:var(--muted);white-space:nowrap"><?= timeAgoMgr($log['created_at']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($totalPages > 1):
          $qs = http_build_query(array_filter(['page'=>'oversight','role'=>$filterRole,'action'=>$filterAction,'date'=>$filterDate]));
        ?>
        <div class="pager">
          <?php for ($i=1;$i<=$totalPages;$i++): ?>
            <?php if ($i===$curPage): ?><span class="cur"><?=$i?></span><?php else: ?><a href="?<?=$qs?>&p=<?=$i?>"><?=$i?></a><?php endif; ?>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
      </div><!-- /tab-log -->

      <!-- TAB: Sensor Anomalies -->
      <div id="tab-sensors" style="display:none">
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr><th>Tank</th><th>Sensor</th><th>Model</th><th>Anomaly</th><th>When</th></tr></thead>
            <tbody>
              <?php if (empty($sensorAnomalies)): ?>
              <tr><td colspan="5" style="text-align:center;color:var(--subtle);padding:1.5rem 0">✅ No anomalies recorded.</td></tr>
              <?php else: foreach ($sensorAnomalies as $sa): ?>
              <tr>
                <td style="font-weight:600"><?= htmlspecialchars($sa['tankname']) ?></td>
                <td><?= htmlspecialchars($sa['sensor_type']) ?></td>
                <td style="color:var(--muted);font-size:.75rem"><?= htmlspecialchars($sa['model']) ?></td>
                <td><span class="badge" style="background:#fffbeb;color:#d97706;border-color:#fde68a"><?= htmlspecialchars($sa['anomaly']) ?></span></td>
                <td style="font-size:.75rem;color:var(--muted)"><?= timeAgoMgr($sa['recorded_at']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div><!-- /tab-sensors -->

      <!-- TAB: Water Quality Log -->
      <div id="tab-quality" style="display:none">
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr><th>Tank</th><th>pH</th><th>Turbidity</th><th>Status</th><th>When</th></tr></thead>
            <tbody>
              <?php if (empty($qualityRecent)): ?>
              <tr><td colspan="5" style="text-align:center;color:var(--subtle);padding:1.5rem 0">No quality data.</td></tr>
              <?php else: foreach ($qualityRecent as $q): ?>
              <tr>
                <td style="font-weight:600"><?= htmlspecialchars($q['tankname']) ?></td>
                <td style="font-weight:600"><?= $q['ph_level'] ?></td>
                <td><?= $q['turbidity'] ?></td>
                <td><span class="badge" style="<?= $q['quality_status']==='Good' ? '' : 'background:#fef2f2;color:#dc2626;border-color:#fecaca' ?>"><?= htmlspecialchars($q['quality_status']) ?></span></td>
                <td style="font-size:.75rem;color:var(--muted)"><?= timeAgoMgr($q['recorded_at']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div><!-- /tab-quality -->

      <!-- TAB: Page Visits -->
      <div id="tab-pages" style="display:none">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.85rem;flex-wrap:wrap;gap:.5rem">
          <p style="font-size:.78rem;color:var(--muted)">Showing last <strong><?= count($pageVisits) ?></strong> page visits by managers &amp; users.</p>
          <div style="display:flex;gap:.4rem"><span class="badge" style="background:#f5f3ff;color:#7c3aed;border-color:#ddd6fe">Manager</span><span class="badge" style="background:#ecfdf5;color:#059669;border-color:#a7f3d0">User</span></div>
        </div>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr><th>User</th><th>Role</th><th>Page</th><th>IP Address</th><th>When</th></tr></thead>
            <tbody>
              <?php if (empty($pageVisits)): ?>
              <tr><td colspan="5" style="text-align:center;color:var(--subtle);padding:1.5rem 0">No page visits logged yet.</td></tr>
              <?php else: foreach ($pageVisits as $pv): ?>
              <tr>
                <td style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($pv['username'] ?? '—') ?></td>
                <td><?= roleBadgeMgr($pv['role'] ?? 'user') ?></td>
                <td><div style="font-size:.8rem;font-weight:500"><?= htmlspecialchars($pv['page_label'] ?? '—') ?></div><div style="font-size:.7rem;color:var(--subtle)"><?= htmlspecialchars($pv['page']) ?></div></td>
                <td><span class="ip-chip"><?= htmlspecialchars($pv['ip_address'] ?? '—') ?></span></td>
                <td style="font-size:.75rem;color:var(--muted);white-space:nowrap" title="<?= $pv['visited_at'] ?>"><?= timeAgoMgr($pv['visited_at']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div><!-- /tab-pages -->

    </div><!-- /card tabs -->

    <!-- Chart data injection -->
    <script>
    window.oversightChartLabels = <?= json_encode($oversightChartLabels) ?>;
    window.oversightChartData   = <?= json_encode($oversightChartData) ?>;
    </script>
    <!-- SECTION: MANAGER OVERSIGHT END -->

   

     <!-- ============================================================
       SECTION: USAGE STATS START
       ============================================================ -->
     <?php elseif ($page === 'usage'): ?>

    <!-- STAT GRID -->
    <div class="stats-grid" style="margin-bottom:1.25rem;">
      <div class="stat-card">
        <div class="stat-top"><div class="stat-label">Total Collected</div><div class="stat-icon" style="background:#eff6ff;width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#3b82f6" stroke-width="2"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg></div></div>
        <div class="stat-value"><?= number_format($totalCollected) ?><span class="unit">L</span></div>
        <div class="stat-foot"><?php if ($pctChange >= 0): ?><span class="up">↑ <?= abs($pctChange) ?>%</span><?php else: ?><span class="down">↓ <?= abs($pctChange) ?>%</span><?php endif; ?><span>vs last month</span></div>
        <div class="stat-glow" style="background:#3b82f6"></div>
      </div>
      <div class="stat-card">
        <div class="stat-top"><div class="stat-label">Total Tap Used</div><div class="stat-icon" style="background:#fef2f2;width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#ef4444" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg></div></div>
        <div class="stat-value"><?= number_format($totalTap) ?><span class="unit">L</span></div>
        <div class="stat-foot"><span>Total tap water consumption</span></div>
        <div class="stat-glow" style="background:#ef4444"></div>
      </div>
      <div class="stat-card">
        <div class="stat-top"><div class="stat-label">Net Savings</div><div class="stat-icon" style="background:#ecfdf5;width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#10b981" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div></div>
        <div class="stat-value"><?= number_format($netSavings) ?><span class="unit">L</span></div>
        <div class="stat-foot"><span>Rainwater used instead of tap</span></div>
        <div class="stat-glow" style="background:#10b981"></div>
      </div>
      <div class="stat-card">
        <div class="stat-top"><div class="stat-label">Avg Daily (30d)</div><div class="stat-icon" style="background:#faf5ff;width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#8b5cf6" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div></div>
        <div class="stat-value"><?= number_format($avgDaily, 0) ?><span class="unit">L</span></div>
        <div class="stat-foot"><span>Average per active day</span></div>
        <div class="stat-glow" style="background:#8b5cf6"></div>
      </div>
    </div>

    <!-- 30-DAY TREND -->
    <div style="margin-bottom:1.25rem;">
      <div class="chart-card"><div class="chart-header"><div><div class="chart-title">Daily Collection Trend</div><div class="chart-sub">Last 30 Days</div></div><span class="chart-pill">Last 30 Days</span></div><div style="padding:1.1rem 1.35rem"><canvas id="trendChart" height="75"></canvas></div></div>
    </div>

    <!-- MONTHLY BAR + DOUGHNUT -->
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:1.25rem;margin-bottom:1.25rem;" class="chart-row-3">
      <div class="chart-card">
        <div class="chart-header"><div><div class="chart-title">Monthly Comparison</div><div class="chart-sub">Rainwater vs Tap — last 6 months</div></div></div>
        <div style="padding:1.1rem 1.35rem">
          <div class="legend"><div class="legend-item"><div class="leg-dot" style="background:#3b82f6"></div>Rainwater</div><div class="legend-item"><div class="leg-dot" style="background:#d1d5db"></div>Tap Water</div></div>
          <canvas id="barChart" height="150"></canvas>
        </div>
      </div>
      <div class="chart-card">
        <div class="chart-header"><div><div class="chart-title">Usage Breakdown</div><div class="chart-sub">By type — all time</div></div></div>
        <div style="padding:1.1rem 1.35rem;display:flex;justify-content:center;align-items:center;min-height:220px;">
          <?php if (count($breakData) > 0 && array_sum($breakData) > 0): ?><canvas id="doughnutChart" height="200"></canvas><?php else: ?><p style="color:#9ca3af;font-size:.85rem;text-align:center">No usage data yet.</p><?php endif; ?>
        </div>
      </div>
    </div>

    <!-- RECENT TABLE -->
    <div class="table-card" style="overflow:hidden">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.35rem;border-bottom:1px solid var(--border)"><div class="chart-title">Recent Usage Records</div><span class="chart-pill">Last 10</span></div>
      <div class="tbl-wrap">
        <table class="tbl" style="min-width:480px">
          <thead><tr><th style="padding:.65rem 1.35rem">Type</th><th style="padding:.65rem 1.35rem">Volume</th><th style="padding:.65rem 1.35rem">Tank</th><th style="padding:.65rem 1.35rem">User</th><th style="padding:.65rem 1.35rem">Date &amp; Time</th></tr></thead>
          <tbody>
            <?php if ($recentUsage): foreach ($recentUsage as $row):
              $tKey  = strtolower(str_replace(' ','',$row['usage_type']));
              $tCls  = match($tKey) { 'cleaning'=>'type-cleaning','irrigation'=>'type-irrigation','drinking'=>'type-drinking','tap'=>'type-tap',default=>'type-other' };
            ?>
            <tr>
              <td style="padding:.75rem 1.35rem"><span class="type-badge <?= $tCls ?>"><?= htmlspecialchars($row['usage_type']) ?></span></td>
              <td style="padding:.75rem 1.35rem"><?= number_format((float)$row['usage_liters'], 2) ?> L</td>
              <td style="padding:.75rem 1.35rem"><?= htmlspecialchars($row['tankname'] ?? '—') ?></td>
              <td style="padding:.75rem 1.35rem"><?= htmlspecialchars($row['email'] ? explode('@',$row['email'])[0] : '—') ?></td>
              <td style="padding:.75rem 1.35rem"><?= date('M j, Y  H:i', strtotime($row['recorded_at'])) ?></td>
            </tr>
            <?php endforeach; else: ?><tr class="empty-row"><td colspan="5">No usage records found.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Chart data injection -->
    <script>
    window.usageTrendLabels  = <?= json_encode($trendLabels) ?>;
    window.usageTrendData    = <?= json_encode($trendData) ?>;
    window.usageBarLabels    = <?= json_encode($barLabels   ?: ['No data']) ?>;
    window.usageBarRainwater = <?= json_encode(array_map('floatval', $barRainwater) ?: [0]) ?>;
    window.usageBarTap       = <?= json_encode(array_map('floatval', $barTap)       ?: [0]) ?>;
    window.usageBreakLabels  = <?= json_encode($breakLabels ?: ['No data']) ?>;
    window.usageBreakData    = <?= json_encode($breakData   ?: [0]) ?>;
    </script>
    <!-- SECTION: USAGE STATS END -->

   

   <!--  ============================================================
       SECTION: WEATHER MONITOR START
       ============================================================ -->
     <?php elseif ($page === 'weather'): ?>

    <!-- HERO CARD -->
    <div class="hero-card">
      <div class="hero-top"><div><div class="hero-date"><?= date('l, F j · g:i A') ?></div><div class="hero-location">📍 <?= htmlspecialchars($WX_CITY) ?></div></div></div>
      <div class="hero-temp-row">
        <div>
          <div class="big-temp"><?= $wxTemp ?><sup>°</sup></div>
          <div class="weather-desc-wrap">
            <div class="weather-desc"><?= $wxIcon ?> <?= htmlspecialchars($wxDescription) ?></div>
            <div class="feels-like">Feels like <?= $wxFeelsLike ?>°C · <?= $wxCloudiness ?>% cloud cover</div>
          </div>
        </div>
      </div>
      <div class="hero-cloud"><?= $wxIcon ?></div>
      <div class="hero-pills">
        <div class="pill"><span>💧</span><span class="pval"><?= $wxHumidity ?>%</span><span>Humidity</span></div>
        <div class="pill"><span>🌬️</span><span class="pval"><?= $wxWindSpeed ?> km/h</span><span>Wind</span></div>
        <div class="pill"><span>👁️</span><span class="pval"><?= $wxVisibility ?> km</span><span>Visibility</span></div>
        <div class="pill"><span>🌡️</span><span class="pval"><?= $wxPressure ?></span><span>hPa</span></div>
      </div>
    </div>

    <!-- ALERT BANNER -->
    <?php if ($rainAlert): ?>
    <div class="alert-banner"><div class="alert-icon">⚠️</div><div><div class="alert-title">Weather Advisory</div><div class="alert-desc"><?= htmlspecialchars($alertMsg) ?></div></div></div>
    <?php elseif (!$currentWeather): ?>
    <div class="alert-banner" style="border-color:#fca5a5;background:#fef2f2"><div class="alert-icon">📡</div><div><div class="alert-title" style="color:#991b1b">Weather API Unavailable</div><div class="alert-desc" style="color:#7f1d1d">Could not reach OpenWeatherMap.</div></div></div>
    <?php else: ?>
    <div class="alert-banner" style="border-color:#bbf7d0;background:#f0fdf4"><div class="alert-icon">✅</div><div><div class="alert-title" style="color:#166534">All Clear</div><div class="alert-desc" style="color:#14532d">No weather alerts for <?= htmlspecialchars($WX_CITY) ?>. Conditions are normal.</div></div></div>
    <?php endif; ?>

    <!-- 7-DAY FORECAST STRIP -->
    <div>
      <div class="section-label">7-Day Forecast</div>
      <div class="forecast-row-w">
        <?php if ($wxDaily): foreach ($wxDaily as $i => $day):
          $dId=$day['weather'][0]['id']??800; $dEmoj=wxEmojiPHP($dId);
          $dTemp=round($day['main']['temp']); $dMax=round($day['main']['temp_max']); $dMin=round($day['main']['temp_min']);
          $dPop=round(($day['pop']??0)*100); $dDay=$i===0?'Today':date('D',$day['dt']);
        ?>
        <div class="fc-item <?= $i===0?'today':'' ?>">
          <div class="fc-day"><?= $dDay ?></div><div class="fc-emoji"><?= $dEmoj ?></div>
          <div class="fc-temp"><?= $dTemp ?>°</div>
          <div class="fc-hilo"><span><?= $dMax ?>°</span><span class="fc-lo"><?= $dMin ?>°</span></div>
          <?php if ($dPop > 0): ?><div class="fc-rain <?= $i!==0?'has-rain':'' ?>">💧 <?= $dPop ?>%</div><?php endif; ?>
        </div>
        <?php endforeach;
        else: $fb=[['Today','⛅',28,31,24,60],['Fri','🌧️',25,27,22,80],['Sat','🌦️',26,29,23,50],['Sun','☀️',30,33,25,10],['Mon','⛅',29,32,24,30],['Tue','🌩️',24,26,21,90],['Wed','🌤️',27,30,23,20]];
        foreach ($fb as [$dDay,$dEmoj,$dTemp,$dMax,$dMin,$dPop]): ?>
        <div class="fc-item <?= $dDay==='Today'?'today':'' ?>">
          <div class="fc-day"><?= $dDay ?></div><div class="fc-emoji"><?= $dEmoj ?></div>
          <div class="fc-temp"><?= $dTemp ?>°</div>
          <div class="fc-hilo"><span><?= $dMax ?>°</span><span class="fc-lo"><?= $dMin ?>°</span></div>
          <div class="fc-rain <?= $dDay!=='Today'?'has-rain':'' ?>">💧 <?= $dPop ?>%</div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- CHARTS ROW -->
    <div class="bottom-grid">
      <div class="w-card"><div class="w-card-title">Rainfall Collection — Last 14 Days<span class="w-card-badge">mm equiv.</span></div><div style="position:relative;height:140px"><canvas id="rainfallChart"></canvas></div></div>
      <div class="w-card">
        <div class="w-card-title">Sensor Inference Summary</div>
        <div class="donut-wrap">
          <div class="donut-canvas-wrap"><canvas id="donutChart"></canvas><div class="donut-center"><div class="donut-pct"><?= $wxNormalPct ?>%</div><div class="donut-sub">Normal</div></div></div>
          <div class="donut-legend">
            <div class="dleg-item"><div class="dleg-dot" style="background:#2563eb"></div><div><div class="dleg-val"><?= $wxNormalPct ?>%</div><div>Normal readings</div></div></div>
            <div class="dleg-item"><div class="dleg-dot" style="background:#93c5fd"></div><div><div class="dleg-val"><?= $wxRainPct ?>%</div><div>Rain anomaly</div></div></div>
            <div class="dleg-item"><div class="dleg-dot" style="background:#f59e0b"></div><div><div class="dleg-val"><?= $wxAlertPct ?>%</div><div>Alert readings</div></div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Chart data injection -->
    <script>
    window.wxRfLabels  = <?= json_encode($rfLabels) ?>;
    window.wxRfData    = <?= json_encode($rfData) ?>;
    window.wxNormalPct = <?= $wxNormalPct ?>;
    window.wxRainPct   = <?= $wxRainPct ?>;
    window.wxAlertPct  = <?= $wxAlertPct ?>;
    </script>
    <!-- SECTION: WEATHER MONITOR END -->

   

    <!-- ============================================================
       SECTION: TANK MAP START
       ============================================================ -->
    <?php elseif ($page === 'map'): ?>


    <!-- Map panel -->
    <div class="map-panel">
      <div class="live-badge">Live Network View</div>
      <div id="map"></div>
      <button class="fab-panel" id="fabPanel" onclick="openPanel()" style="display:none;align-items:center;">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
        <?= count($mapTanks) ?> Tanks
      </button>
    </div>

    <!-- Right panel -->
    <div class="right-panel" id="rightPanel">
      <div class="panel-header">
        <h3>Tank Locations</h3>
        <div style="display:flex;align-items:center;gap:8px">
          <span class="tank-count"><?= count($mapTanks) ?> Tanks</span>
          <button class="panel-close" onclick="closePanel()"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
      </div>
      <div style="padding:10px 12px 0;display:none" id="panelSearch">
        <div class="search-box" style="min-width:unset;width:100%">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" placeholder="Search tanks..." id="searchInputMobile">
        </div>
      </div>
      <div class="tank-list" id="tankList">
        <?php foreach ($mapTanks as $i => $tank):
          $pct=$tank['fill_pct']; $color=barColorMap((int)$pct);
          $s=strtolower($tank['status_tank']); $ago=timeAgoMap($tank['last_reading']);
          $dotClass=$s==='active'?'':($s==='maintenance'?'warn':'offline');
        ?>
        <div class="tank-card-map <?= $i===0?'selected':'' ?>" id="card-<?= $tank['tank_id'] ?>" onclick="focusTank(<?= $tank['tank_id'] ?>)">
          <div class="card-head">
            <h4><span class="dot <?= $dotClass ?>"></span><?= htmlspecialchars($tank['tankname']) ?></h4>
            <?= statusBadgeMap($tank['status_tank']) ?>
          </div>
          <div class="card-location"><?= htmlspecialchars($tank['location_add']) ?></div>
          <div>
            <div class="fill-row"><span class="fill-pct"><?= $pct ?>%</span><span class="fill-liters"><?= number_format($tank['current_liters']) ?>L / <?= number_format($tank['max_capacity']) ?>L</span></div>
            <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
          </div>
          <div class="card-updated">🕐 Updated <?= $ago ?></div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($mapTanks)): ?><div style="text-align:center;padding:40px;color:var(--text-3)">No tanks found in the database.</div><?php endif; ?>
      </div>
      <div class="fleet-summary">
        <h5>Fleet Summary</h5>
        <div class="fleet-grid">
          <div class="fleet-stat"><label>Total Stored</label><div class="value"><?= number_format($mapTotalStored) ?>L</div></div>
          <div class="fleet-stat"><label>System Status</label><div class="value"><span class="sys-dot"></span><?= $mapSystemStatus ?></div></div>
        </div>
        <div class="overall-row"><span>Overall Capacity</span><span><?= $mapOverallPct ?>%</span></div>
        <div class="progress-bar" style="height:8px"><div class="progress-fill" style="width:<?= $mapOverallPct ?>%;background:var(--accent)"></div></div>
      </div>
    </div><!-- /right-panel -->

    <!-- Tank data injection -->
    <script>
    window.mapTanks = <?= json_encode(array_map(function($t){
        return ['id'=>$t['tank_id'],'name'=>$t['tankname'],'location'=>$t['location_add'],
                'liters'=>$t['current_liters'],'capacity'=>$t['max_capacity'],
                'pct'=>$t['fill_pct'],'status'=>strtolower($t['status_tank'])];
    }, $mapTanks), JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <!-- SECTION: TANK MAP END -->


<?php elseif ($page === 'settings'): ?>

    <?php if ($settingsSuccess): ?><div class="flash flash-ok"><svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg><?= htmlspecialchars($settingsSuccess) ?></div><?php endif; ?>
    <?php if ($settingsError):   ?><div class="flash flash-err"><svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?= htmlspecialchars($settingsError) ?></div><?php endif; ?>

    <form method="POST" action="?page=settings">

      <!-- Tank Configuration -->
      <div class="s-card">
        <div class="s-card-head"><div class="s-card-icon icon-blue">🪣</div><div><div class="s-card-title">Tank Configuration</div><div class="s-card-sub">Manage capacity and overflow thresholds</div></div></div>
        <div class="s-card-body">
          <div class="fg2 mb">
            <div class="field"><label>Tank Capacity (Litres)</label><input class="f-input" type="number" name="tank_capacity" id="tankCapacity" value="<?= $sMaxCap ?>" min="100" step="100"/></div>
            <div class="field"><label>Low-Level Alert Threshold</label><div class="slider-wrap"><input type="range" name="threshold" id="threshold" min="0" max="<?= $sMaxCap ?>" value="<?= $sThreshV ?>" style="--val:<?= $sPct ?>%" oninput="updateSlider(this,'thresholdVal')"/><span class="slider-lbl" id="thresholdVal"><?= number_format($sThreshV) ?>L</span></div></div>
          </div>
          <hr class="row-divider"/>
          <div class="tog-row"><div class="tog-info"><strong>Overflow Prevention</strong><span>Automatically divert water when tank reaches capacity</span></div><label class="tog"><input type="checkbox" name="overflow_prevention" <?= cfg($settingsRows,'overflow_prevention','1')==='1'?'checked':'' ?>/><div class="tog-track"></div><div class="tog-thumb"></div></label></div>
          <hr class="row-divider"/>
          <!-- Registered Tanks -->
          <?php if (!empty($settingsAllTanks)): ?>
          <div style="margin-bottom:1.25rem">
            <div style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6b7280;margin-bottom:.6rem">Registered Tanks</div>
            <table class="tank-list-table">
              <thead><tr><th>#</th><th>Tank Name</th><th>Location</th><th>Status</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($settingsAllTanks as $t): ?>
                <tr>
                  <td style="color:#9ca3af;font-size:.78rem"><?= $t['tank_id'] ?></td>
                  <td style="font-weight:600"><?= htmlspecialchars($t['tankname']) ?></td>
                  <td style="color:#6b7280">📍 <?= htmlspecialchars($t['location_add']) ?></td>
                  <td><span class="tank-badge <?= strtolower($t['status_tank']) ?>"><?= htmlspecialchars($t['status_tank']) ?></span></td>
                  <td><button type="button" class="btn-delete-tank" onclick="confirmDelete(<?= $t['tank_id'] ?>,'<?= htmlspecialchars(addslashes($t['tankname'])) ?>')"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>Delete</button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
          <button type="button" class="btn-add-tank" onclick="showAddTankForm()">Add Tank</button>
          <div id="addTankForm">
            <form method="POST" action="?page=settings" class="fgen-form">
              <input type="hidden" name="add_tank" value="1"/>
              <div class="fgen-field"><label class="fgen-label">Tank Name</label><input type="text" name="tankname" class="fgen-input" placeholder="e.g. Main Rooftop Tank" required/></div>
              <div class="fgen-field"><label class="fgen-label">Location Address</label><input type="text" name="location_add" class="fgen-input" placeholder="e.g. Brgy. Poblacion, Manolo Fortich, Bukidnon" required/></div>
              <div class="fgen-field"><label class="fgen-label">Current Liters</label><input type="number" name="current_liters" class="fgen-input" placeholder="0" min="0"/></div>
              <div class="fgen-field"><label class="fgen-label">Max Capacity (L)</label><input type="number" name="max_capacity" class="fgen-input" placeholder="5000" min="1" required/></div>
              <div class="fgen-field"><label class="fgen-label">Status</label><select name="status_tank" class="fgen-select"><option value="">Select status</option><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
              <div class="fgen-form-actions"><button type="submit" class="fgen-submit">Submit</button><button type="button" class="fgen-cancel" onclick="hideAddTankForm()">Cancel</button></div>
            </form>
          </div>
        </div>
      </div><!-- /tank config -->

      <!-- Pump Settings -->
      <div class="s-card">
        <div class="s-card-head"><div class="s-card-icon icon-green">⚙️</div><div><div class="s-card-title">Pump Settings</div><div class="s-card-sub">Control automation and scheduling</div></div></div>
        <div class="s-card-body">
          <div class="tog-row"><div class="tog-info"><strong>Auto Mode</strong><span>Pump operates based on demand and weather conditions</span></div><label class="tog"><input type="checkbox" name="pump_auto" <?= cfg($settingsRows,'pump_auto','1')==='1'?'checked':'' ?>/><div class="tog-track"></div><div class="tog-thumb"></div></label></div>
          <hr class="row-divider"/>
          <div class="fg2">
            <div class="field"><label>Schedule Mode</label><select class="f-select" name="pump_schedule"><?php $scheds=['smart'=>'Smart (Weather-based)','fixed'=>'Fixed Schedule','manual'=>'Manual Only','sensor'=>'Sensor-Driven']; $curS=cfg($settingsRows,'pump_schedule','smart'); foreach($scheds as $v=>$l) echo "<option value=\"$v\"".($curS===$v?' selected':'').">$l</option>"; ?></select></div>
            <div class="field"><label>Max Wattage Limit (W)</label><input class="f-input" type="number" name="pump_wattage" value="<?= (int)cfg($settingsRows,'pump_wattage',100) ?>" min="0"/></div>
          </div>
        </div>
      </div>

      <!-- Notifications -->
      <div class="s-card">
        <div class="s-card-head"><div class="s-card-icon icon-yellow">🔔</div><div><div class="s-card-title">Notifications</div><div class="s-card-sub">Choose which alerts and reports to receive</div></div></div>
        <div class="s-card-body">
          <?php $notifs=['notif_low_water'=>['Low Water Alert','1','Alert when tank drops below threshold'],'notif_heavy_rain'=>['Heavy Rain Alert','1','Alert when heavy rain is forecast'],'notif_pump_failure'=>['Pump Failure Alert','1','Alert when pump encounters an error'],'notif_weekly'=>['Weekly Usage Report','0','Receive weekly water usage summary'],'notif_monthly'=>['Monthly Summary','1','Monthly system performance report']];
          foreach($notifs as $name=>[$lbl,$def,$desc]): $chk=cfg($settingsRows,$name,$def)==='1'; ?>
          <div class="tog-row"><div class="tog-info"><strong><?= $lbl ?></strong><span><?= $desc ?></span></div><label class="tog"><input type="checkbox" name="<?= $name ?>" <?= $chk?'checked':'' ?>/><div class="tog-track"></div><div class="tog-thumb"></div></label></div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Water Quality Alerts -->
      <div class="s-card">
        <div class="s-card-head"><div class="s-card-icon icon-purple">💧</div><div><div class="s-card-title">Water Quality Alerts</div><div class="s-card-sub">pH, TDS thresholds and testing schedule</div></div></div>
        <div class="s-card-body">
          <div class="fg2 mb">
            <div class="field"><label>pH Range (Min – Max)</label><div class="ph-wrap"><input class="f-input" type="number" name="ph_min" step="0.1" min="0" max="14" value="<?= cfg($settingsRows,'ph_min','6.5') ?>"/><span class="ph-dash">—</span><input class="f-input" type="number" name="ph_max" step="0.1" min="0" max="14" value="<?= cfg($settingsRows,'ph_max','8.5') ?>"/></div></div>
            <div class="field"><label>TDS Threshold (ppm)</label><input class="f-input" type="number" name="tds_threshold" value="<?= (int)cfg($settingsRows,'tds_threshold',100) ?>" min="0"/></div>
          </div>
          <hr class="row-divider"/>
          <div class="fg1">
            <div class="field"><label>Test Frequency</label><select class="f-select" name="test_frequency"><?php $freqs=['every_3h'=>'Every 3 hours','every_6h'=>'Every 6 hours','every_12h'=>'Every 12 hours','daily'=>'Once daily','continuous'=>'Continuous']; $curF=cfg($settingsRows,'test_frequency','every_6h'); foreach($freqs as $v=>$l) echo "<option value=\"$v\"".($curF===$v?' selected':'').">$l</option>"; ?></select></div>
          </div>
        </div>
      </div>

      <!-- Account -->
      <div class="s-card">
        <div class="s-card-head"><div class="s-card-icon icon-slate">👤</div><div><div class="s-card-title">Account</div><div class="s-card-sub">Email, timezone and role preferences</div></div></div>
        <div class="s-card-body">
          <div class="fg2 mb">
            <div class="field"><label>Email Address</label><input class="f-input" type="email" name="account_email" value="<?= htmlspecialchars(cfg($settingsRows,'account_email',$sMe['email']??'')) ?>"/></div>
            <div class="field"><label>Role</label><input class="f-input" type="text" value="<?= ucfirst($sMe['role']??'manager') ?>" readonly/></div>
          </div>
          <hr class="row-divider"/>
          <div class="fg1">
            <div class="field"><label>Timezone</label><select class="f-select" name="account_timezone"><?php $tzones=['Asia/Manila'=>'Asia/Manila (PHT +8)','UTC'=>'UTC','America/Los_Angeles'=>'Pacific Time (PT)','America/New_York'=>'Eastern Time (ET)']; $curTz=cfg($settingsRows,'account_timezone','Asia/Manila'); foreach($tzones as $v=>$l) echo "<option value=\"$v\"".($curTz===$v?' selected':'').">$l</option>"; ?></select></div>
          </div>
        </div>
      </div>

      <!-- Save Bar -->
      <div class="save-bar">
        <button type="button" class="btn-discard" onclick="window.location.reload()">Discard</button>
        <button type="submit" class="btn-save"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>Save Changes</button>
      </div>
    </form>

    <!-- DELETE CONFIRM MODAL -->
    <div class="modal-backdrop" id="deleteModal">
      <div class="modal-box">
        <div class="modal-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg></div>
        <h4>Delete Tank?</h4>
        <p>You're about to permanently delete <strong id="modalTankName"></strong>.<br>This action cannot be undone.</p>
        <div class="modal-actions"><button class="btn-modal-cancel" onclick="closeDeleteModal()">Cancel</button><button class="btn-modal-confirm" onclick="submitDelete()">Yes, Delete</button></div>
      </div>
    </div>

    <!-- Hidden delete form -->
    <form method="POST" action="?page=settings" id="deleteTankForm" style="display:none">
      <input type="hidden" name="delete_tank" value="1"/>
      <input type="hidden" name="tank_id" id="deleteTankId"/>
    </form>

    <!-- Toast -->
    <div class="toast" id="toast">✅&nbsp; Settings saved successfully</div>

    <!-- Settings JS init -->
    <script>
    initSettings();
    initSettingsToast(<?= $settingsSuccess ? 'true' : 'false' ?>);
    </script>

<?php endif; ?>

  <?php if ($page !== 'map'): ?>
  </main>
</div>
  <?php endif; ?>

<script src="<?= BASE_URL ?>/app/manager/manager_script.js"></script>
<?php if ($page === 'map'): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>
<script>initPage('<?= htmlspecialchars($page) ?>');</script>
</body>
</html>
<?php endif; /* closes if ($page === 'user') ... else */ ?>