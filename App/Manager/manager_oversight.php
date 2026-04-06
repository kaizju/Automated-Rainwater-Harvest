<?php
require_once '../../Connections/config.php';
require_once '../../Connections/functions.php';

requireAnyRole(['admin', 'manager']);
logPageVisit('Manager Oversight', 'oversight');

$activePage = 'oversight';

// ── Summary stats ─────────────────────────────────────────────────────────────
$tankCount    = (int)$pdo->query("SELECT COUNT(*) FROM tank")->fetchColumn();
$onlineTanks  = (int)$pdo->query("SELECT COUNT(*) FROM tank WHERE LOWER(status_tank)='active'")->fetchColumn();
$todayUsage   = (float)$pdo->query("SELECT COALESCE(SUM(usage_liters),0) FROM water_usage WHERE DATE(recorded_at)=CURDATE()")->fetchColumn();
$anomalies    = (int)$pdo->query("SELECT COUNT(*) FROM sensor_readings WHERE anomaly != 'None' AND DATE(recorded_at)=CURDATE()")->fetchColumn();

// ── Active users today (non-admin) ────────────────────────────────────────────
$activeUsers = $pdo->query("
    SELECT u.username, u.email, u.role,
           COUNT(ual.activity_id) AS action_count,
           MAX(ual.created_at) AS last_action
    FROM user_activity_logs ual
    LEFT JOIN users u ON ual.user_id = u.id
    WHERE DATE(ual.created_at) = CURDATE()
      AND (u.role IS NULL OR u.role IN ('user', 'manager'))
    GROUP BY ual.user_id
    ORDER BY last_action DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ── Recent activity (all roles, last 50 — managers see everything except admin internals) ──
$filterRole   = $_GET['role']   ?? '';
$filterAction = $_GET['action'] ?? '';
$filterDate   = $_GET['date']   ?? date('Y-m-d');
$page         = max(1, (int)($_GET['p'] ?? 1));
$perPage      = 15;
$offset       = ($page - 1) * $perPage;

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

// ── Tank-specific activity ────────────────────────────────────────────────────
$tankActivity = $pdo->query("
    SELECT t.tankname, t.status_tank, t.current_liters, t.max_capacity,
           (SELECT COUNT(*) FROM water_usage wu WHERE wu.tank_id=t.tank_id AND DATE(wu.recorded_at)=CURDATE()) AS usage_events,
           (SELECT COALESCE(SUM(wu2.usage_liters),0) FROM water_usage wu2 WHERE wu2.tank_id=t.tank_id AND DATE(wu2.recorded_at)=CURDATE()) AS today_liters,
           (SELECT COUNT(*) FROM sensor_readings sr JOIN sensors s ON sr.sensor_id=s.sensor_id WHERE s.tank_id=t.tank_id AND sr.anomaly != 'None' AND DATE(sr.recorded_at)=CURDATE()) AS anomaly_count
    FROM tank t ORDER BY t.tankname
")->fetchAll(PDO::FETCH_ASSOC);

// ── Recent sensor anomalies ───────────────────────────────────────────────────
$sensorAnomalies = $pdo->query("
    SELECT sr.anomaly, sr.recorded_at, s.sensor_type, s.model, t.tankname
    FROM sensor_readings sr
    JOIN sensors s  ON sr.sensor_id = s.sensor_id
    JOIN tank t     ON s.tank_id    = t.tank_id
    WHERE sr.anomaly != 'None'
    ORDER BY sr.recorded_at DESC LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// ── Water quality recent ──────────────────────────────────────────────────────
$qualityRecent = $pdo->query("
    SELECT wq.*, t.tankname
    FROM water_quality wq
    JOIN tank t ON wq.tank_id = t.tank_id
    ORDER BY wq.recorded_at DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ── Page visits by users (not admins) ────────────────────────────────────────
$pageVisits = $pdo->query("
    SELECT pv.page_label, pv.page, pv.role, u.username, pv.visited_at
    FROM page_visits pv
    LEFT JOIN users u ON pv.user_id = u.id
    WHERE pv.role IN ('user','manager')
    ORDER BY pv.visited_at DESC LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// ── Usage trend (7 days) ──────────────────────────────────────────────────────
$usageRows = $pdo->query("
    SELECT DATE(recorded_at) AS d, SUM(usage_liters) AS total
    FROM water_usage WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(recorded_at) ORDER BY d
")->fetchAll(PDO::FETCH_ASSOC);
$usageMap = array_column($usageRows, 'total', 'd');
$chartLabels = $chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('D', strtotime($d));
    $chartData[]   = (float)($usageMap[$d] ?? 0);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
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
    if ($d < 3600)  return floor($d/60) . 'm ago';
    if ($d < 86400) return floor($d/3600) . 'h ago';
    return date('M j', strtotime($ts));
}
function actionIconMgr(string $a): string {
    if (str_contains($a,'login'))   return '🔑';
    if (str_contains($a,'logout'))  return '🚪';
    if (str_contains($a,'delete'))  return '🗑️';
    if (str_contains($a,'page_view')) return '👁️';
    if (str_contains($a,'edit') || str_contains($a,'update')) return '✏️';
    return '📋';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>EcoRain — Manager Oversight</title>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="<?= BASE_URL ?>/Others/all.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --sidebar-w: 240px; --topbar-h: 60px;
      --bg: #f1f5f9; --card-bg: #fff; --border: #e2e8f0;
      --text: #0f172a; --muted: #64748b; --subtle: #94a3b8;
      --accent: #2563eb; --accent-light: #f5f3ff;
      --radius: 14px; --shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.05);
    }
    html, body { height: 100%; }
    body { background: var(--bg); color: var(--text); font-family: 'DM Sans', sans-serif; display: flex; min-height: 100vh; overflow-x: hidden; }

    /* SIDEBAR */
    .sidebar { width: var(--sidebar-w); flex-shrink: 0; background: #0f172a; display: flex; flex-direction: column; padding: 1.5rem 1rem; position: fixed; top: 0; left: 0; height: 100vh; overflow-y: auto; z-index: 100; transition: transform .25s ease; }
    .sidebar.open { transform: translateX(0) !important; }
    .logo { display: flex; align-items: center; gap: .6rem; padding: .25rem .5rem .25rem .25rem; margin-bottom: 2rem; }
    .logo-icon { width: 34px; height: 34px; background: linear-gradient(145deg,#a78bfa,#7c3aed); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; flex-shrink: 0; }
    .logo-text { font-family: 'Sora', sans-serif; font-size: 1.1rem; font-weight: 700; color: #fff; letter-spacing: -.02em; }
    .nav-item { display: flex; align-items: center; gap: .7rem; padding: .6rem .75rem; border-radius: 9px; font-size: .875rem; font-weight: 500; color: #94a3b8; text-decoration: none; margin-bottom: .1rem; transition: background .15s, color .15s; }
    .nav-item svg { width: 17px; height: 17px; flex-shrink: 0; }
    .nav-item:hover { background: rgba(255,255,255,.06); color: #e2e8f0; }
    .nav-item.active { background: rgba(167,139,250,.15); color: #c4b5fd; font-weight: 600; }
    .nav-item.logout:hover { background: rgba(239,68,68,.1); color: #fca5a5; }
    .sidebar-spacer { flex: 1; }
    .sidebar-bottom { border-top: 1px solid #1e293b; padding-top: 1rem; margin-top: .5rem; }

    /* OVERLAY */
    .overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 99; }
    .overlay.show { display: block; }

    /* APP BODY */
    .app-body { flex: 1; display: flex; flex-direction: column; min-width: 0; margin-left: var(--sidebar-w); }

    /* TOPBAR */
    .topbar { height: var(--topbar-h); background: var(--card-bg); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 1.5rem; position: sticky; top: 0; z-index: 50; flex-shrink: 0; }
    .topbar-left { display: flex; align-items: center; gap: .75rem; }
    .hamburger { display: none; background: none; border: none; cursor: pointer; padding: .35rem; color: var(--text); border-radius: 8px; }
    .hamburger svg { width: 22px; height: 22px; }
    .page-title { font-family: 'Sora', sans-serif; font-size: 1.05rem; font-weight: 700; }
    .page-sub { font-size: .72rem; color: var(--muted); }
    .topbar-right { display: flex; align-items: center; gap: .65rem; }
    .t-btn { width: 34px; height: 34px; border: 1px solid var(--border); border-radius: 9px; background: var(--card-bg); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--muted); transition: border-color .15s; }
    .t-btn:hover { border-color: var(--accent); color: var(--accent); }
    .t-btn svg { width: 15px; height: 15px; }
    .t-avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg,#7c3aed,#4f46e5); display: flex; align-items: center; justify-content: center; color: #fff; font-size: .78rem; font-weight: 700; cursor: pointer; text-decoration: none; }

    /* MAIN */
    .main { flex: 1; padding: 1.5rem; display: flex; flex-direction: column; gap: 1.25rem; overflow-y: auto; }

    /* STAT CARDS */
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
    .stat-card { background: var(--card-bg); border-radius: var(--radius); border: 1px solid var(--border); padding: 1.1rem 1.25rem; box-shadow: var(--shadow); display: flex; align-items: center; gap: 1rem; }
    .stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
    .stat-num { font-family: 'Sora', sans-serif; font-size: 1.65rem; font-weight: 800; line-height: 1; }
    .stat-lbl { font-size: .73rem; color: var(--muted); margin-top: .15rem; }
    .stat-warn .stat-num { color: #d97706; }

    /* CARDS */
    .card { background: var(--card-bg); border-radius: var(--radius); border: 1px solid var(--border); padding: 1.25rem 1.35rem; box-shadow: var(--shadow); }
    .card-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
    .card-title { font-size: .875rem; font-weight: 700; }
    .card-label { font-size: .68rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--subtle); }

    /* GRID ROWS */
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
    .three-col { display: grid; grid-template-columns: 2fr 1fr; gap: 1.25rem; }
    .chart-wrap { height: 180px; position: relative; }

    /* TABLES */
    .tbl-wrap { overflow-x: auto; }
    .tbl { width: 100%; border-collapse: collapse; font-size: .82rem; }
    .tbl th { font-size: .65rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--subtle); padding: 0 .5rem .55rem 0; text-align: left; border-bottom: 1px solid var(--border); white-space: nowrap; }
    .tbl td { padding: .5rem .5rem .5rem 0; border-bottom: 1px solid #f8fafc; vertical-align: middle; color: #374151; }
    .tbl tr:last-child td { border-bottom: none; }
    .tbl tr:hover td { background: #fafbfc; }
    .badge { display: inline-block; padding: .18rem .48rem; border-radius: 6px; font-size: .7rem; font-weight: 600; background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; white-space: nowrap; }

    /* TANK CARDS */
    .tank-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: .85rem; }
    .tank-item { border: 1px solid var(--border); border-radius: 11px; padding: 1rem; }
    .tank-item-name { font-size: .875rem; font-weight: 700; margin-bottom: .5rem; }
    .tank-fill-bar { height: 6px; background: var(--border); border-radius: 99px; overflow: hidden; margin: .4rem 0 .3rem; }
    .tank-fill-inner { height: 100%; border-radius: 99px; }
    .tank-item-meta { font-size: .73rem; color: var(--muted); display: flex; justify-content: space-between; }

    /* USER ACTIVITY CHIPS */
    .active-user-row { display: flex; align-items: center; gap: .65rem; padding: .6rem 0; border-bottom: 1px solid #f8fafc; }
    .active-user-row:last-child { border-bottom: none; }
    .mini-av { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .7rem; font-weight: 700; color: #fff; flex-shrink: 0; }

    /* TABS */
    .tabs { display: flex; gap: .4rem; border-bottom: 1px solid var(--border); margin-bottom: 1rem; }
    .tab { padding: .5rem .9rem; font-size: .8rem; font-weight: 600; color: var(--muted); cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: color .15s, border-color .15s; }
    .tab.active { color: var(--accent); border-bottom-color: var(--accent); }

    /* FILTER */
    .filter-bar { display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
    .filter-bar input, .filter-bar select { height: 34px; border: 1px solid var(--border); border-radius: 9px; background: var(--bg); color: var(--text); font-size: .8rem; font-family: 'DM Sans', sans-serif; padding: 0 .75rem; outline: none; }
    .filter-bar input:focus, .filter-bar select:focus { border-color: var(--accent); }
    .btn-sm { height: 34px; padding: 0 .9rem; border-radius: 9px; font-size: .8rem; font-weight: 600; font-family: 'DM Sans', sans-serif; cursor: pointer; border: none; }
    .btn-primary { background: var(--accent); color: #fff; }
    .btn-ghost { background: var(--card-bg); border: 1px solid var(--border); color: var(--muted); }

    /* PAGINATION */
    .pager { display: flex; gap: .35rem; margin-top: .85rem; flex-wrap: wrap; }
    .pager a, .pager span { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 7px; font-size: .78rem; font-weight: 600; text-decoration: none; border: 1px solid var(--border); color: var(--muted); }
    .pager a:hover { border-color: var(--accent); color: var(--accent); }
    .pager .cur { background: var(--accent); color: #fff; border-color: var(--accent); }

    /* LIVE PULSE */
    .live-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: #a78bfa; margin-right: .35rem; animation: pulse 2s infinite; }
    @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.3)} }

    /* RESPONSIVE */
    @media (max-width: 1024px) { .stats-grid { grid-template-columns: repeat(2,1fr); } .two-col, .three-col { grid-template-columns: 1fr; } }
    @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .app-body { margin-left: 0; } .hamburger { display: flex; } .main { padding: 1rem; } .stats-grid { grid-template-columns: repeat(2,1fr); } }
    @media (max-width: 480px) { .stats-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="logo">
    <span class="logo-icon">💧</span>
    <span class="logo-text">EcoRain</span>
  </div>

  <a href="<?= BASE_URL ?>/App/Manager/manager.php" class="nav-item <?= $activePage==='dashboard'?'active':'' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
    Dashboard
  </a>
   
  <a href="<?= BASE_URL ?>/App/Manager/manager_oversight.php" class="nav-item <?= $activePage==='oversight'?'active':'' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
    Oversight
  </a>
  <a href="<?= BASE_URL ?>/App/Manager/usage.php" class="nav-item <?= $activePage==='usage'?'active':'' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    Usage Stats
  </a>
  <a href="<?= BASE_URL ?>/App/Manager/weather.php" class="nav-item">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
    Weather
  </a>
  <a href="<?= BASE_URL ?>/App/Manager/map.php" class="nav-item">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
    Tank Map
  </a>
  <a href="<?= BASE_URL ?>/App/Manager/settings.php" class="nav-item">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
    Settings
  </a>

  <div class="sidebar-spacer"></div>
  <div class="sidebar-bottom">
    <a href="<?= BASE_URL ?>/Connections/signout.php" class="nav-item logout">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      Log Out
    </a>
  </div>
</aside>

<div class="app-body">
  <header class="topbar">
    <div class="topbar-left">
      <button class="hamburger" onclick="toggleSidebar()">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div>
        <div class="page-title"><span class="live-dot"></span>Manager Oversight</div>
        <div class="page-sub">Tank status, user activity &amp; sensor anomalies</div>
      </div>
    </div>
    <div class="topbar-right">
      <?php if ($anomalies > 0): ?>
      <div class="t-btn" title="<?= $anomalies ?> sensor anomalies today" style="border-color:#d97706;color:#d97706">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      </div>
      <?php endif; ?>
      <a href="<?php echo BASE_URL;?>/App/Manager/user.php" class="t-avatar"><?= htmlspecialchars(avatarInitials()) ?></a>
    </div>
  </header>

  <main class="main">

    <!-- STATS -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff">🪣</div>
        <div><div class="stat-num"><?= $onlineTanks ?>/<?= $tankCount ?></div><div class="stat-lbl">Tanks Online</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#ecfdf5">💧</div>
        <div><div class="stat-num"><?= number_format($todayUsage, 0) ?>L</div><div class="stat-lbl">Used Today</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#f5f3ff">👥</div>
        <div><div class="stat-num"><?= count($activeUsers) ?></div><div class="stat-lbl">Active Users</div></div>
      </div>
      <div class="stat-card <?= $anomalies > 0 ? 'stat-warn' : '' ?>">
        <div class="stat-icon" style="background:#fffbeb">⚠️</div>
        <div><div class="stat-num"><?= $anomalies ?></div><div class="stat-lbl">Anomalies Today</div></div>
      </div>
    </div>

    <!-- TANKS + CHART -->
    <div class="three-col">
      <div class="card">
        <div class="card-hd">
          <span class="card-title">Tank Fleet Status</span>
          <a href="<?= BASE_URL ?>/App/Manager/map.php" style="font-size:.78rem;font-weight:600;color:var(--accent);text-decoration:none">View Map →</a>
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
            <div class="tank-fill-bar">
              <div class="tank-fill-inner" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
            </div>
            <div class="tank-item-meta">
              <span><?= number_format($t['current_liters']) ?>L / <?= number_format($t['max_capacity']) ?>L</span>
              <span style="color:<?= $t['anomaly_count']>0 ? '#d97706' : 'var(--subtle)' ?>">
                <?= $t['anomaly_count'] ?> anomaly<?= $t['anomaly_count'] != 1 ? 's' : '' ?>
              </span>
            </div>
            <?php if ($t['today_liters'] > 0): ?>
              <div style="font-size:.7rem;color:var(--accent);margin-top:.3rem">+<?= number_format($t['today_liters'],0) ?>L collected today</div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-hd"><span class="card-title">Usage — 7 Days</span></div>
        <div class="chart-wrap"><canvas id="usageChart"></canvas></div>

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
    </div>

    <!-- ACTIVITY + SENSORS TABS -->
    <div class="card">
      <div class="tabs">
        <div class="tab active" onclick="switchTab('log',this)">User Activity Log</div>
        <div class="tab" onclick="switchTab('sensors',this)">
          Sensor Anomalies
          <?php if ($anomalies > 0): ?>
            <span style="background:#f59e0b;color:#fff;border-radius:99px;font-size:.65rem;padding:.1rem .4rem;margin-left:.3rem"><?= $anomalies ?></span>
          <?php endif; ?>
        </div>
        <div class="tab" onclick="switchTab('quality',this)">Water Quality Log</div>
        <div class="tab" onclick="switchTab('pages',this)">Page Visits</div>
      </div>

      <!-- USER ACTIVITY -->
      <div id="tab-log">
        <form method="get" class="filter-bar">
          <select name="role">
            <option value="">All roles</option>
            <option value="manager" <?= $filterRole==='manager'?'selected':'' ?>>Manager</option>
            <option value="user"    <?= $filterRole==='user'?'selected':'' ?>>User</option>
          </select>
          <input type="text" name="action" placeholder="Action…" value="<?= htmlspecialchars($filterAction) ?>">
          <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>">
          <button type="submit" class="btn-sm btn-primary">Filter</button>
          <a href="?" class="btn-sm btn-ghost" style="display:inline-flex;align-items:center;text-decoration:none">Reset</a>
        </form>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead>
              <tr><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Description</th><th>IP</th><th>When</th></tr>
            </thead>
            <tbody>
              <?php if (empty($logs)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--subtle);padding:1.5rem 0">No activity found.</td></tr>
              <?php else: foreach ($logs as $log): ?>
              <tr>
                <td>
                  <div style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($log['username'] ?? '—') ?></div>
                  <div style="font-size:.7rem;color:var(--subtle)"><?= htmlspecialchars($log['user_email'] ?? $log['email'] ?? '') ?></div>
                </td>
                <td><?= roleBadgeMgr($log['role'] ?? 'user') ?></td>
                <td style="font-size:.8rem"><?= actionIconMgr($log['action']) ?> <?= htmlspecialchars($log['action']) ?></td>
                <td style="font-size:.75rem;color:var(--muted)"><?= htmlspecialchars($log['module'] ?? '—') ?></td>
                <td style="font-size:.75rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                    title="<?= htmlspecialchars($log['description'] ?? '') ?>"><?= htmlspecialchars($log['description'] ?? '—') ?></td>
                <td style="font-size:.72rem;color:var(--subtle)"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                <td style="font-size:.75rem;color:var(--muted);white-space:nowrap"><?= timeAgoMgr($log['created_at']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="pager">
          <?php
          $qs = http_build_query(array_filter(['role'=>$filterRole,'action'=>$filterAction,'date'=>$filterDate]));
          for ($i=1;$i<=$totalPages;$i++): ?>
            <?php if ($i===$page): ?><span class="cur"><?=$i?></span>
            <?php else: ?><a href="?p=<?=$i?>&<?=$qs?>"><?=$i?></a><?php endif; ?>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- SENSOR ANOMALIES -->
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
      </div>

      <!-- WATER QUALITY LOG -->
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
      </div>

      <!-- PAGE VISITS -->
      <div id="tab-pages" style="display:none">
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr><th>User</th><th>Role</th><th>Page</th><th>When</th></tr></thead>
            <tbody>
              <?php if (empty($pageVisits)): ?>
                <tr><td colspan="4" style="text-align:center;color:var(--subtle);padding:1.5rem 0">No page visits logged yet.</td></tr>
              <?php else: foreach ($pageVisits as $pv): ?>
              <tr>
                <td style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($pv['username'] ?? '—') ?></td>
                <td><?= roleBadgeMgr($pv['role'] ?? 'user') ?></td>
                <td>
                  <div style="font-size:.8rem;font-weight:500"><?= htmlspecialchars($pv['page_label'] ?? '') ?></div>
                  <div style="font-size:.7rem;color:var(--subtle)"><?= htmlspecialchars($pv['page']) ?></div>
                </td>
                <td style="font-size:.75rem;color:var(--muted);white-space:nowrap"><?= timeAgoMgr($pv['visited_at']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div><!-- /card -->

  </main>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); document.getElementById('overlay').classList.toggle('show'); }
function closeSidebar()  { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.remove('show'); }
function switchTab(id, el) {
  document.querySelectorAll('[id^="tab-"]').forEach(t => t.style.display = 'none');
  document.getElementById('tab-' + id).style.display = '';
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
}
new Chart(document.getElementById('usageChart').getContext('2d'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [{ label: 'Liters', data: <?= json_encode($chartData) ?>, backgroundColor: '#a78bfa', hoverBackgroundColor: '#7c3aed', borderWidth: 0, borderRadius: 5, borderSkipped: false }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { family: 'DM Sans', size: 10 } } },
      y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8', font: { family: 'DM Sans', size: 10 } } }
    }
  }
});
</script>
</body>
</html>
