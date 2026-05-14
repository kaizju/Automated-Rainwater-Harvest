<?php
require_once '../../connections/config.php';
require_once '../../connections/functions.php';

requireAnyRole(['admin', 'manager']);
logPageVisit('Manager Map', 'Map');

$activePage = 'Map';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$stmtUser = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$currentUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

// Fetch tanks with latest sensor reading
$stmtTanks = $pdo->query("
    SELECT
        t.tank_id, t.tankname, t.location_add, t.current_liters, t.max_capacity, t.status_tank,
        ROUND((t.current_liters / NULLIF(t.max_capacity,0)) * 100) AS fill_pct,
        (SELECT wlr.pct FROM water_level_readings wlr
         WHERE wlr.tank_id = t.tank_id
         ORDER BY wlr.recorded_at DESC LIMIT 1) AS sensor_pct,
        (SELECT wlr.volume_l FROM water_level_readings wlr
         WHERE wlr.tank_id = t.tank_id
         ORDER BY wlr.recorded_at DESC LIMIT 1) AS sensor_liters,
        (SELECT wlr.recorded_at FROM water_level_readings wlr
         WHERE wlr.tank_id = t.tank_id
         ORDER BY wlr.recorded_at DESC LIMIT 1) AS last_sensor_reading
    FROM tank t ORDER BY t.tank_id ASC
");
$tanks = $stmtTanks->fetchAll(PDO::FETCH_ASSOC);

// Use sensor_pct for display if available
foreach ($tanks as &$t) {
    if ($t['sensor_pct'] !== null) {
        $t['fill_pct']      = round($t['sensor_pct']);
        $t['current_liters'] = round($t['sensor_liters'] ?? ($t['sensor_pct'] / 100 * $t['max_capacity']));
    }
}
unset($t);

$totalStored   = array_sum(array_column($tanks, 'current_liters'));
$totalCapacity = array_sum(array_column($tanks, 'max_capacity'));
$overallPct    = $totalCapacity > 0 ? round(($totalStored / $totalCapacity) * 100) : 0;
$onlineCount   = count(array_filter($tanks, fn($t) => strtolower($t['status_tank']) === 'active'));
$systemStatus  = $onlineCount === count($tanks) ? 'All Online' : ($onlineCount > 0 ? 'Partial' : 'Offline');

function timeAgo(?string $datetime): string {
    if (!$datetime) return 'No data';
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return round($diff / 60) . ' min ago';
    if ($diff < 86400) return round($diff / 3600) . ' hr ago';
    return round($diff / 86400) . ' days ago';
}
function statusBadge(string $status): string {
    $s = strtolower($status);
    if ($s === 'active')      return '<span class="badge badge-online">&#x1F4F6; Online</span>';
    if ($s === 'maintenance') return '<span class="badge badge-maintenance">&#x1F527; Maintenance</span>';
    return '<span class="badge badge-offline">&#x26A0; Offline</span>';
}
function barColor(int $pct): string {
    if ($pct >= 75) return '#3b82f6';
    if ($pct >= 40) return '#f59e0b';
    return '#ef4444';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EcoRain — Tank Map</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --sidebar-bg: #0f172a; --sidebar-w: 240px; --accent: #3b82f6;
            --surface: #fff; --bg: #f1f5f9; --text-1: #0f172a; --text-2: #64748b;
            --text-3: #94a3b8; --border: #e2e8f0; --radius: 12px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.06);
            --online: #22c55e; --warn: #f59e0b; --danger: #ef4444;
            font-family: 'DM Sans', sans-serif;
        }
        html, body { height: 100%; overflow: hidden; }
        .shell { display: flex; height: 100vh; position: relative; }

        /* SIDEBAR */
        .sidebar { width: var(--sidebar-w); flex-shrink: 0; background: var(--sidebar-bg); display: flex; flex-direction: column; padding: 1.5rem 1rem; position: relative; z-index: 200; overflow-y: auto; transition: transform .25s ease; }
        .logo { display: flex; align-items: center; gap: .6rem; padding: .25rem .5rem .25rem .25rem; margin-bottom: 2rem; }
        .logo-icon { width: 34px; height: 34px; background: linear-gradient(145deg,#60a5fa,#1d4ed8); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; flex-shrink: 0; }
        .logo-text { font-family: 'Sora',sans-serif; font-size: 1.1rem; font-weight: 700; color: #fff; letter-spacing: -.02em; }
        .nav-item { display: flex; align-items: center; gap: .7rem; padding: .6rem .75rem; border-radius: 9px; font-size: .875rem; font-weight: 500; color: #94a3b8; text-decoration: none; margin-bottom: .1rem; transition: background .15s, color .15s; }
        .nav-item svg { width: 17px; height: 17px; flex-shrink: 0; }
        .nav-item:hover  { background: rgba(255,255,255,.06); color: #e2e8f0; }
        .nav-item.active { background: rgba(96,165,250,.15); color: #93c5fd; font-weight: 600; }
        .nav-item.logout:hover { background: rgba(239,68,68,.1); color: #fca5a5; }
        .sidebar-spacer { flex: 1; }
        .sidebar-bottom { border-top: 1px solid #1e293b; padding-top: 1rem; margin-top: .5rem; }

        /* OVERLAY */
        .overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 150; }
        .overlay.show { display: block; }

        /* MAIN */
        .main { flex: 1; display: flex; flex-direction: column; min-width: 0; background: var(--bg); overflow: hidden; }

        /* TOPBAR */
        .topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0 20px; height: 64px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-shrink: 0; }
        .topbar-left { display: flex; align-items: center; gap: .65rem; }
        .topbar-left h1 { font-family: 'Space Grotesk',sans-serif; font-size: 18px; font-weight: 700; color: var(--text-1); }
        .topbar-left p  { font-size: 11px; color: var(--text-2); margin-top: 1px; }
        .topbar-right { display: flex; align-items: center; gap: 10px; }
        .hamburger { display: none; background: none; border: none; cursor: pointer; padding: .35rem; color: var(--text-1); border-radius: 8px; flex-shrink: 0; }
        .hamburger svg { width: 22px; height: 22px; }
        .search-box { display: flex; align-items: center; gap: 8px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 7px 13px; min-width: 180px; }
        .search-box input { border: none; background: none; outline: none; font-size: 13px; color: var(--text-1); width: 100%; }
        .search-box svg { width: 14px; height: 14px; color: var(--text-3); flex-shrink: 0; }
        .btn-icon { width: 36px; height: 36px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface); display: grid; place-items: center; cursor: pointer; position: relative; color: var(--text-2); flex-shrink: 0; }
        .btn-icon svg { width: 16px; height: 16px; }
        .notif-dot { position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; border-radius: 50%; background: var(--danger); border: 1.5px solid var(--surface); }
        .avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg,#3b82f6,#7c3aed); display: grid; place-items: center; color: #fff; font-weight: 700; font-size: 14px; text-decoration: none; flex-shrink: 0; }

        /* CONTENT */
        .content { flex: 1; display: flex; overflow: hidden; min-height: 0; }

        /* MAP */
        .map-panel { flex: 1; position: relative; min-width: 0; }
        .live-badge { position: absolute; top: 16px; left: 16px; z-index: 500; background: rgba(255,255,255,.95); backdrop-filter: blur(8px); border: 1px solid var(--border); border-radius: 8px; padding: 8px 14px; display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 600; color: var(--text-1); box-shadow: var(--shadow-sm); }
        .live-badge::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: var(--online); animation: pulse 1.6s infinite; }
        @keyframes pulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.5;transform:scale(1.3);} }
        #map { width: 100%; height: 100%; }

        /* RIGHT PANEL */
        .right-panel { width: 360px; flex-shrink: 0; background: var(--surface); border-left: 1px solid var(--border); display: flex; flex-direction: column; overflow: hidden; transition: transform .3s ease; }
        .panel-header { padding: 16px 20px 12px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .panel-header h3 { font-family: 'Space Grotesk',sans-serif; font-size: 15px; font-weight: 700; color: var(--text-1); }
        .tank-count { background: var(--bg); border: 1px solid var(--border); border-radius: 20px; padding: 3px 10px; font-size: 12px; font-weight: 600; color: var(--text-2); }
        .panel-close { display: none; background: none; border: none; cursor: pointer; color: var(--text-2); padding: 4px; border-radius: 6px; }
        .panel-close svg { width: 18px; height: 18px; }

        /* TANK LIST */
        .tank-list { flex: 1; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 10px; }
        .tank-list::-webkit-scrollbar { width: 5px; }
        .tank-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }

        /* TANK CARD */
        .tank-card { background: var(--surface); border: 1.5px solid var(--border); border-radius: var(--radius); padding: 14px; transition: border-color .2s, box-shadow .2s; cursor: pointer; }
        .tank-card:hover    { border-color: var(--accent); box-shadow: var(--shadow-sm); }
        .tank-card.selected { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
        .card-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; gap: 8px; }
        .card-head h4 { font-family: 'Space Grotesk',sans-serif; font-size: 14px; font-weight: 700; color: var(--text-1); display: flex; align-items: center; gap: 6px; }
        .card-head h4 .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--online); display: inline-block; flex-shrink: 0; }
        .card-head h4 .dot.warn    { background: var(--warn); }
        .card-head h4 .dot.offline { background: var(--danger); }
        .card-location { font-size: 12px; color: var(--text-2); margin-bottom: 12px; }
        .card-location::before { content: '📍 '; }
        .badge { border-radius: 20px; padding: 3px 9px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .badge-online      { background: #dcfce7; color: #15803d; }
        .badge-maintenance { background: #fef3c7; color: #92400e; }
        .badge-offline     { background: #fee2e2; color: #b91c1c; }
        .fill-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px; }
        .fill-pct    { font-family: 'Space Grotesk',sans-serif; font-size: 20px; font-weight: 700; }
        .fill-liters { font-size: 12px; color: var(--text-2); font-weight: 600; }
        .progress-bar  { height: 6px; background: var(--bg); border-radius: 99px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 99px; transition: width .6s ease; }
        .card-updated  { margin-top: 8px; font-size: 11px; color: var(--text-3); }

        /* FLEET SUMMARY */
        .fleet-summary { border-top: 1px solid var(--border); padding: 14px 20px; flex-shrink: 0; }
        .fleet-summary h5 { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--text-3); margin-bottom: 12px; }
        .fleet-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
        .fleet-stat label { font-size: 11px; color: var(--text-3); text-transform: uppercase; letter-spacing: .06em; display: block; margin-bottom: 4px; }
        .fleet-stat .value { font-family: 'Space Grotesk',sans-serif; font-size: 15px; font-weight: 700; color: var(--text-1); }
        .fleet-stat .sys-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--online); margin-right: 5px; }
        .overall-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; }
        .overall-row span { font-size: 11px; color: var(--text-2); font-weight: 600; }

        /* FAB */
        .fab-panel { display: none; position: absolute; bottom: 20px; right: 20px; z-index: 600; background: var(--accent); color: #fff; border: none; border-radius: 14px; padding: 11px 18px; font-size: 13px; font-weight: 700; font-family: 'DM Sans',sans-serif; cursor: pointer; box-shadow: 0 4px 16px rgba(59,130,246,.45); align-items: center; gap: 7px; }
        .fab-panel svg { width: 16px; height: 16px; }

        /* RESPONSIVE */
        @media (max-width: 1024px) {
            :root { --sidebar-w: 64px; }
            .logo-text,.nav-item span { display: none; }
            .logo { justify-content: center; padding: .25rem; }
            .nav-item { justify-content: center; padding: .65rem; }
            .nav-item svg { width: 20px; height: 20px; }
            .search-box { min-width: 150px; }
        }
        @media (max-width: 768px) {
            .sidebar { position: fixed; top:0; left:0; bottom:0; width:260px !important; transform:translateX(-100%); z-index:300; }
            .sidebar.open { transform: translateX(0); }
            .logo-text,.nav-item span { display: block !important; }
            .logo { justify-content: flex-start !important; padding: .25rem .5rem .25rem .25rem !important; }
            .nav-item { justify-content: flex-start !important; padding: .6rem .75rem !important; }
            .hamburger { display: flex; }
            .search-box { display: none; }
            .topbar { padding: 0 14px; }
            .topbar-left h1 { font-size: 16px; }
            .topbar-left p  { display: none; }
            .right-panel { position: fixed; bottom:0; left:0; right:0; width:100% !important; height:55vh; border-left:none; border-top:1px solid var(--border); border-radius:20px 20px 0 0; z-index:250; transform:translateY(100%); box-shadow:0 -8px 32px rgba(0,0,0,.12); }
            .right-panel.open { transform: translateY(0); }
            .panel-close { display: flex; }
            .fab-panel { display: flex; }
            .content { position: relative; }
        }
        @media (max-width: 400px) {
            .btn-icon,.avatar { width:32px; height:32px; }
            .fab-panel { bottom:14px; right:14px; padding:9px 14px; font-size:12px; }
        }
    </style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<div class="shell">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="logo">
            <span class="logo-icon">💧</span>
            <span class="logo-text">EcoRain</span>
        </div>
        <a href="<?= BASE_URL ?>/app/manager/manager.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            <span>Dashboard</span>
        </a>
        <a href="<?= BASE_URL ?>/app/manager/manager_oversight.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <span>Oversight</span>
        </a>
        <a href="<?= BASE_URL ?>/app/manager/usage.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <span>Usage Stats</span>
        </a>
        <a href="<?= BASE_URL ?>/app/manager/weather.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
            <span>Weather</span>
        </a>
        <a href="<?= BASE_URL ?>/app/manager/map.php" class="nav-item active">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <span>Tank Map</span>
        </a>
        <a href="<?= BASE_URL ?>/app/manager/settings.php" class="nav-item">
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

    <!-- MAIN -->
    <div class="main">

        <!-- TOPBAR -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" onclick="toggleSidebar()">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div>
                    <h1>Tank Locations</h1>
                    <p>Live sensor monitoring</p>
                </div>
            </div>
            <div class="topbar-right">
                <div class="search-box">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" placeholder="Search tanks..." id="searchInput">
                </div>
                <div class="btn-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                    <span class="notif-dot"></span>
                </div>
                <a href="<?= BASE_URL ?>/app/manager/user.php" class="avatar">
                    <?= strtoupper(substr($currentUser['username'] ?? 'U', 0, 1)) ?>
                </a>
            </div>
        </header>

        <!-- CONTENT -->
        <div class="content">

            <!-- MAP -->
            <div class="map-panel">
                <div class="live-badge">Live Network View</div>
                <div id="map"></div>
                <button class="fab-panel" id="fabPanel" onclick="openPanel()">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?= count($tanks) ?> Tanks
                </button>
            </div>

            <!-- RIGHT PANEL -->
            <div class="right-panel" id="rightPanel">
                <div class="panel-header">
                    <h3>Tank Locations</h3>
                    <div style="display:flex;align-items:center;gap:8px">
                        <span class="tank-count"><?= count($tanks) ?> Tanks</span>
                        <button class="panel-close" onclick="closePanel()">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Mobile search -->
                <div style="padding:10px 12px 0;display:none" id="panelSearch">
                    <div class="search-box" style="min-width:unset;width:100%">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" placeholder="Search tanks..." id="searchInputMobile">
                    </div>
                </div>

                <!-- TANK CARDS -->
                <div class="tank-list" id="tankList">
                    <?php foreach ($tanks as $i => $tank):
                        $pct      = (int)$tank['fill_pct'];
                        $color    = barColor($pct);
                        $s        = strtolower($tank['status_tank']);
                        $dotClass = $s === 'active' ? '' : ($s === 'maintenance' ? 'warn' : 'offline');
                        $ago      = timeAgo($tank['last_sensor_reading'] ?? $tank['last_reading'] ?? null);
                        $hasLive  = $tank['sensor_pct'] !== null;
                    ?>
                    <div class="tank-card <?= $i === 0 ? 'selected' : '' ?>"
                         id="card-<?= $tank['tank_id'] ?>"
                         onclick="focusTank(<?= $tank['tank_id'] ?>)">
                        <div class="card-head">
                            <h4><span class="dot <?= $dotClass ?>"></span><?= htmlspecialchars($tank['tankname']) ?></h4>
                            <?= statusBadge($tank['status_tank']) ?>
                        </div>
                        <div class="card-location"><?= htmlspecialchars($tank['location_add']) ?></div>
                        <div>
                            <div class="fill-row">
                                <span class="fill-pct" id="pct-<?= $tank['tank_id'] ?>" style="color:<?= $color ?>"><?= $pct ?>%</span>
                                <span class="fill-liters" id="liters-<?= $tank['tank_id'] ?>"><?= number_format($tank['current_liters']) ?>L / <?= number_format($tank['max_capacity']) ?>L</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" id="bar-<?= $tank['tank_id'] ?>" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                            </div>
                        </div>
                        <div class="card-updated">🕐 Updated <?= $ago ?></div>
                        <div id="sensor-<?= $tank['tank_id'] ?>" style="font-size:.7rem;margin-top:.3rem;color:<?= $hasLive ? '#16a34a' : '#f59e0b' ?>">
                            <?= $hasLive ? '📡 Live · ' . $ago : '⚠ No sensor data yet' ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($tanks)): ?>
                        <div style="text-align:center;padding:40px;color:var(--text-3)">No tanks found.</div>
                    <?php endif; ?>
                </div>

                <!-- FLEET SUMMARY -->
                <div class="fleet-summary">
                    <h5>Fleet Summary</h5>
                    <div class="fleet-grid">
                        <div class="fleet-stat">
                            <label>Total Stored</label>
                            <div class="value" id="fleet-total"><?= number_format($totalStored) ?>L</div>
                        </div>
                        <div class="fleet-stat">
                            <label>System Status</label>
                            <div class="value"><span class="sys-dot"></span><?= $systemStatus ?></div>
                        </div>
                    </div>
                    <div class="overall-row">
                        <span>Overall Capacity</span>
                        <span id="fleet-pct"><?= $overallPct ?>%</span>
                    </div>
                    <div class="progress-bar" style="height:8px">
                        <div class="progress-fill" id="fleet-bar" style="width:<?= $overallPct ?>%;background:var(--accent)"></div>
                    </div>
                </div>
            </div>

        </div><!-- end .content -->
    </div><!-- end .main -->
</div><!-- end .shell -->

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ── Tank data from PHP ─────────────────────────────────────────────────
const TANKS_INIT = <?= json_encode(array_map(function($t) {
    return [
        'id'       => $t['tank_id'],
        'tank_id'  => $t['tank_id'],
        'name'     => $t['tankname'],
        'location' => $t['location_add'],
        'liters'   => $t['current_liters'],
        'capacity' => $t['max_capacity'],
        'pct'      => $t['fill_pct'],
        'status'   => strtolower($t['status_tank']),
    ];
}, $tanks), JSON_UNESCAPED_UNICODE) ?>;

const BASE_URL    = '<?= BASE_URL ?>';
const DEFAULT_LAT = 8.360015;
const DEFAULT_LNG = 124.868419;

// ── Map setup ──────────────────────────────────────────────────────────
const map = L.map('map').setView([DEFAULT_LAT, DEFAULT_LNG], 13);
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 19
}).addTo(map);

function pctColor(p) {
    return p >= 75 ? '#3b82f6' : p >= 40 ? '#f59e0b' : '#ef4444';
}

function makeIcon(color) {
    return L.divIcon({
        className: '',
        html: `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="40" viewBox="0 0 32 40">
          <path fill="${color}" stroke="#fff" stroke-width="2" d="M16 2C9.37 2 4 7.37 4 14c0 9 12 24 12 24s12-15 12-24C28 7.37 22.63 2 16 2z"/>
          <circle cx="16" cy="14" r="6" fill="rgba(255,255,255,0.85)"/>
        </svg>`,
        iconSize: [32,40], iconAnchor: [16,40], popupAnchor: [0,-42]
    });
}

function buildPopup(t, color, pct, liters, ago) {
    return `<div style="font-family:'DM Sans',sans-serif;min-width:190px;">
      <strong style="font-size:14px">${t.name}</strong><br>
      <span style="font-size:12px;color:#64748b">📍 ${t.location}</span>
      <div style="margin-top:8px;font-size:13px">
        <b style="color:${color}">${Math.round(pct)}%</b>
        <span style="color:#64748b"> — ${Number(liters).toLocaleString()}L / ${Number(t.capacity).toLocaleString()}L</span>
      </div>
      <div style="margin-top:6px;height:5px;background:#e2e8f0;border-radius:99px">
        <div style="width:${pct}%;height:100%;background:${color};border-radius:99px"></div>
      </div>
      <div style="margin-top:6px;font-size:11px;color:#94a3b8;text-transform:capitalize">Status: ${t.status}</div>
      ${ago ? `<div style="margin-top:4px;font-size:11px;color:#16a34a">📡 Live · ${ago}</div>` : ''}
    </div>`;
}

window.markerMap = {};

async function geocode(loc) {
    try {
        const r = await fetch(`https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(loc)}&format=json&limit=1`, { headers: {'Accept-Language':'en'} });
        const d = await r.json();
        if (d.length) return { lat: parseFloat(d[0].lat), lng: parseFloat(d[0].lon) };
    } catch(e) { console.warn('Geocode failed:', loc, e); }
    return null;
}

async function loadAllTanks() {
    const bounds = [];
    for (const t of TANKS_INIT) {
        let coords = null;
        if (t.location?.trim()) coords = await geocode(t.location);
        if (!coords) coords = { lat: DEFAULT_LAT + (Math.random()*.01-.005), lng: DEFAULT_LNG + (Math.random()*.01-.005) };
        const color  = t.status === 'active' ? pctColor(t.pct) : (t.status === 'maintenance' ? '#f59e0b' : '#6b7280');
        const marker = L.marker([coords.lat, coords.lng], { icon: makeIcon(color) })
            .addTo(map)
            .bindPopup(buildPopup(t, color, t.pct, t.liters, null));
        window.markerMap[t.id] = { marker, lat: coords.lat, lng: coords.lng, tank: t };
        bounds.push([coords.lat, coords.lng]);
    }
    if (bounds.length) map.fitBounds(bounds, { padding: [40,40] });
    if (TANKS_INIT.length) setTimeout(() => focusTank(TANKS_INIT[0].id), 600);
}

loadAllTanks();

function focusTank(tankId) {
    document.querySelectorAll('.tank-card').forEach(c => c.classList.remove('selected'));
    const card = document.getElementById('card-' + tankId);
    if (card) { card.classList.add('selected'); card.scrollIntoView({ behavior:'smooth', block:'nearest' }); }
    const m = window.markerMap[tankId];
    if (m) { map.flyTo([m.lat, m.lng], 17, { duration:1 }); setTimeout(() => m.marker.openPopup(), 900); }
    if (window.innerWidth <= 768) setTimeout(() => closePanel(), 300);
}

// ── Live IoT refresh ───────────────────────────────────────────────────
function timeAgoJs(dateStr) {
    if (!dateStr) return null;
    const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
    if (diff < 10)   return 'just now';
    if (diff < 60)   return diff + 's ago';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    return Math.floor(diff/3600) + 'h ago';
}

async function refreshLive() {
    try {
        const res  = await fetch(`${BASE_URL}/others/data.php?action=tank_levels&_=${Date.now()}`);
        const data = await res.json();
        if (!data.tanks) return;

        let totalLiters = 0, totalCap = 0;

        data.tanks.forEach(t => {
            const tid    = t.tank_id;
            const pct    = parseFloat(t.sensor_pct ?? t.pct ?? 0);
            const liters = parseFloat(t.sensor_liters ?? t.current_liters ?? 0);
            const color  = pctColor(pct);
            const ago    = timeAgoJs(t.last_reading);

            totalLiters += liters;
            totalCap    += parseFloat(t.max_capacity ?? 0);

            // Update right panel card
            const pctEl = document.getElementById('pct-' + tid);
            if (pctEl) { pctEl.textContent = Math.round(pct) + '%'; pctEl.style.color = color; }

            const litEl = document.getElementById('liters-' + tid);
            if (litEl) litEl.textContent = Number(liters).toLocaleString() + 'L / ' + Number(t.max_capacity).toLocaleString() + 'L';

            const barEl = document.getElementById('bar-' + tid);
            if (barEl) { barEl.style.width = pct + '%'; barEl.style.background = color; }

            const senEl = document.getElementById('sensor-' + tid);
            if (senEl) {
                senEl.textContent = ago ? '📡 Live · ' + ago : '⚠ No sensor data yet';
                senEl.style.color = ago ? '#16a34a' : '#f59e0b';
            }

            // Update map marker + popup
            const m = window.markerMap[tid];
            if (m) {
                m.marker.setIcon(makeIcon(color));
                m.marker.setPopupContent(buildPopup(m.tank, color, pct, liters, ago));
            }
        });

        // Update fleet summary
        const overallPct = totalCap > 0 ? Math.round((totalLiters / totalCap) * 100) : 0;
        const fleetTotal = document.getElementById('fleet-total');
        const fleetPct   = document.getElementById('fleet-pct');
        const fleetBar   = document.getElementById('fleet-bar');
        if (fleetTotal) fleetTotal.textContent = Math.round(totalLiters).toLocaleString() + 'L';
        if (fleetPct)   fleetPct.textContent   = overallPct + '%';
        if (fleetBar)   fleetBar.style.width   = overallPct + '%';

    } catch(e) { console.warn('Live refresh error:', e); }
}

refreshLive();
setInterval(refreshLive, 8000);

// ── Search ─────────────────────────────────────────────────────────────
function filterTanks(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.tank-card').forEach(card => {
        const name = card.querySelector('h4').textContent.toLowerCase();
        const loc  = card.querySelector('.card-location').textContent.toLowerCase();
        card.style.display = (name.includes(q) || loc.includes(q)) ? '' : 'none';
    });
}
document.getElementById('searchInput').addEventListener('input', e => filterTanks(e.target.value));
document.getElementById('searchInputMobile').addEventListener('input', e => filterTanks(e.target.value));

// ── Sidebar + panel ────────────────────────────────────────────────────
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); document.getElementById('overlay').classList.toggle('show'); }
function closeSidebar()  { document.getElementById('sidebar').classList.remove('open');  document.getElementById('overlay').classList.remove('show'); }
function openPanel()     { document.getElementById('rightPanel').classList.add('open');  document.getElementById('panelSearch').style.display = 'block'; }
function closePanel()    { document.getElementById('rightPanel').classList.remove('open'); }
function handleResize() {
    const mob = window.innerWidth <= 768;
    document.getElementById('panelSearch').style.display = mob ? 'block' : 'none';
    if (!mob) { closePanel(); closeSidebar(); }
}
window.addEventListener('resize', handleResize);
</script>
</body>
</html>