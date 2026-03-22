<?php
require_once '../../Connections/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../index.php'); exit; }

$API_KEY = 'a5712e740541248ce7883f0af8581be4';
$LAT     = 8.360015;
$LON     = 124.868419;
$CITY    = 'Manolo Fortich, Bukidnon';

$currentUrl  = "https://api.openweathermap.org/data/2.5/weather?lat={$LAT}&lon={$LON}&appid={$API_KEY}&units=metric";
$forecastUrl = "https://api.openweathermap.org/data/2.5/forecast?lat={$LAT}&lon={$LON}&appid={$API_KEY}&units=metric&cnt=40";

function fetchJson($url) {
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? json_decode($raw, true) : null;
}

$currentWeather = fetchJson($currentUrl);
$forecastData   = fetchJson($forecastUrl);

$temp        = $currentWeather ? round($currentWeather['main']['temp'])        : '--';
$feelsLike   = $currentWeather ? round($currentWeather['main']['feels_like'])  : '--';
$humidity    = $currentWeather ? $currentWeather['main']['humidity']           : '--';
$windSpeed   = $currentWeather ? round($currentWeather['wind']['speed'] * 3.6) : '--';
$visibility  = $currentWeather ? round(($currentWeather['visibility'] ?? 10000) / 1000) : '--';
$pressure    = $currentWeather ? $currentWeather['main']['pressure']           : '--';
$description = $currentWeather ? ucfirst($currentWeather['weather'][0]['description']) : 'N/A';
$weatherId   = $currentWeather ? $currentWeather['weather'][0]['id']           : 800;
$cloudiness  = $currentWeather ? $currentWeather['clouds']['all']              : 0;

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
$weatherIcon = weatherEmoji($weatherId);

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

$rainfall14 = $pdo->query("SELECT DATE(recorded_at) AS d, SUM(usage_liters) AS mm FROM water_usage WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND usage_type != 'Tap' GROUP BY DATE(recorded_at) ORDER BY d ASC")->fetchAll(PDO::FETCH_ASSOC);
$rfMap = [];
foreach ($rainfall14 as $r) $rfMap[$r['d']] = round((float)$r['mm'] / 10, 1);
$rfLabels = []; $rfData = [];
for ($i = 13; $i >= 0; $i--) { $day = date('Y-m-d', strtotime("-$i days")); $rfLabels[] = date('M j', strtotime($day)); $rfData[] = $rfMap[$day] ?? 0; }

$totalReadings = (int)$pdo->query("SELECT COUNT(*) FROM sensor_readings")->fetchColumn();
$rainReadings  = (int)$pdo->query("SELECT COUNT(*) FROM sensor_readings WHERE anomaly != 'None'")->fetchColumn();
$alertDays     = (int)$pdo->query("SELECT COUNT(DISTINCT DATE(recorded_at)) FROM sensor_readings WHERE anomaly = 'High'")->fetchColumn();
$normalPct = $totalReadings > 0 ? round(($totalReadings - $rainReadings) / $totalReadings * 100) : 84;
$rainPct   = $totalReadings > 0 ? round(($rainReadings - $alertDays) / $totalReadings * 100)     : 11;
$alertPct  = max(0, 100 - $normalPct - $rainPct);

$rainAlert = false; $alertMsg = '';
if ($forecastData && isset($forecastData['list'])) {
    foreach (array_slice($forecastData['list'], 0, 8) as $item) {
        $pop = ($item['pop'] ?? 0) * 100;
        if ($pop >= 70) { $rainAlert = true; $alertMsg = 'Heavy rain expected in the next 24 hours (' . round($pop) . '% chance). Check tank overflow settings and ensure drainage is clear.'; break; }
    }
}
if (!$rainAlert && $temp !== '--' && $temp > 32) { $rainAlert = true; $alertMsg = "Temperature is {$temp}°C. Heat risk — stay hydrated and limit outdoor exposure between 11 AM – 3 PM."; }

$initials = strtoupper(substr($_SESSION['email'] ?? 'U', 0, 2));
$rfLabelsJson = json_encode($rfLabels);
$rfDataJson   = json_encode($rfData);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>EcoRain — Weather Monitor</title>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/Others/all.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
  *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        :root {
            --sidebar-w: 240px;
            --topbar-h: 60px;
            --bg: #f1f5f9;
            --card-bg: #ffffff;
            --border: #e2e8f0;
            --text: #0f172a;
            --muted: #64748b;
            --subtle: #94a3b8;
            --accent: #2563eb;
            --accent-light: #eff6ff;
            --radius: 14px;
            --shadow: 0 1px 3px rgba(0, 0, 0, .06), 0 4px 16px rgba(0, 0, 0, .05);
        }

        html,
        body {
            height: 100%
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            display: flex;
            min-height: 100vh;
            overflow-x: hidden
        }

        /* SIDEBAR */
        .sidebar {
            width: var(--sidebar-w);
            flex-shrink: 0;
            background: #0f172a;
            display: flex;
            flex-direction: column;
            padding: 1.5rem 1rem;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
            transition: transform .25s ease
        }

        .sidebar.open {
            transform: translateX(0) !important
        }

        .logo {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .25rem .5rem .25rem .25rem;
            margin-bottom: 2rem
        }

        .logo-icon {
            width: 34px;
            height: 34px;
            background: linear-gradient(145deg, #60a5fa, #1d4ed8);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0
        }

        .logo-text {
            font-family: 'Sora', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: -.02em
        }

        .nav-section-label {
            font-size: .6rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: #475569;
            padding: .5rem .75rem .25rem;
            margin-top: .5rem
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: .7rem;
            padding: .6rem .75rem;
            border-radius: 9px;
            font-size: .875rem;
            font-weight: 500;
            color: #94a3b8;
            text-decoration: none;
            margin-bottom: .1rem;
            transition: background .15s, color .15s
        }

        .nav-item svg {
            width: 17px;
            height: 17px;
            flex-shrink: 0
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, .06);
            color: #e2e8f0
        }

        .nav-item.active {
            background: rgba(96, 165, 250, .15);
            color: #93c5fd;
            font-weight: 600
        }

        .nav-item.logout:hover {
            background: rgba(239, 68, 68, .1);
            color: #fca5a5
        }

        .sidebar-spacer {
            flex: 1
        }

        .sidebar-bottom {
            border-top: 1px solid #1e293b;
            padding-top: 1rem;
            margin-top: .5rem
        }

        /* OVERLAY */
        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            z-index: 99
        }

        .overlay.show {
            display: block
        }

        /* APP BODY */
        .app-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            margin-left: var(--sidebar-w);
            transition: margin-left .25s ease
        }

        /* TOPBAR */
        .topbar {
            height: var(--topbar-h);
            background: var(--card-bg);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 50;
            flex-shrink: 0
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: .75rem
        }

        .hamburger {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: .35rem;
            color: var(--text);
            border-radius: 8px
        }

        .hamburger svg {
            width: 22px;
            height: 22px
        }

        .page-title {
            font-family: 'Sora', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text)
        }

        .page-sub {
            font-size: .72rem;
            color: var(--muted)
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: .65rem
        }

        .t-search {
            display: flex;
            align-items: center;
            gap: .45rem;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: .38rem .8rem;
            width: 180px
        }

        .t-search svg {
            width: 13px;
            height: 13px;
            color: var(--subtle);
            flex-shrink: 0
        }

        .t-search input {
            background: none;
            border: none;
            outline: none;
            font-size: .8rem;
            font-family: 'DM Sans', sans-serif;
            color: var(--text);
            width: 100%
        }

        .t-search input::placeholder {
            color: var(--subtle)
        }

        .t-btn {
            width: 34px;
            height: 34px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--card-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--muted);
            position: relative;
            transition: border-color .15s, background .15s
        }

        .t-btn:hover {
            border-color: var(--accent);
            background: var(--accent-light);
            color: var(--accent)
        }

        .t-btn svg {
            width: 15px;
            height: 15px
        }

        .notif-dot {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 6px;
            height: 6px;
            background: #ef4444;
            border-radius: 50%;
            border: 1.5px solid var(--card-bg)
        }

        .t-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #7c3aed);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: .78rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none
        }

        /* MAIN */
        .main {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1.25rem
        }

        /* CARDS */
        .card {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 1.25rem 1.35rem;
            box-shadow: var(--shadow)
        }

        .card-label {
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--subtle);
            margin-bottom: .85rem
        }

        .card-label-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: .85rem
        }

        /* STAT GRID */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 1.2rem 1.35rem;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: transform .2s, box-shadow .2s
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, .08)
        }

        .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: .55rem
        }

        .stat-lbl {
            font-size: .68rem;
            font-weight: 700;
            color: var(--subtle);
            text-transform: uppercase;
            letter-spacing: .07em
        }

        .stat-icon {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0
        }

        .stat-val {
            font-family: 'Sora', sans-serif;
            font-size: 1.9rem;
            font-weight: 800;
            color: var(--text);
            letter-spacing: -.04em;
            line-height: 1;
            margin-bottom: .4rem
        }

        .stat-foot {
            font-size: .73rem;
            color: var(--subtle);
            display: flex;
            align-items: center;
            gap: .35rem;
            flex-wrap: wrap
        }

        .up {
            color: #10b981;
            font-weight: 700
        }

        .down {
            color: #ef4444;
            font-weight: 700
        }

        .stat-glow {
            position: absolute;
            width: 70px;
            height: 70px;
            border-radius: 50%;
            filter: blur(36px);
            opacity: .1;
            bottom: -15px;
            right: -10px;
            pointer-events: none
        }

        /* MID ROW */
        .mid-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.25rem;
            align-items: start
        }

        /* CHART */
        .chart-title {
            font-size: .875rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: .85rem;
            display: flex;
            align-items: center;
            justify-content: space-between
        }

        .chart-pill {
            font-size: .68rem;
            font-weight: 600;
            padding: .22rem .65rem;
            border-radius: 20px;
            background: var(--accent-light);
            color: var(--accent);
            border: 1px solid #dbeafe
        }

        .chart-wrap {
            height: 190px;
            position: relative
        }

        /* TABLES */
        .mini-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .82rem
        }

        .mini-table th {
            font-size: .65rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--subtle);
            padding: .4rem .6rem .55rem 0;
            text-align: left;
            border-bottom: 1px solid var(--border)
        }

        .mini-table td {
            padding: .6rem .6rem .6rem 0;
            border-bottom: 1px solid #f8fafc;
            vertical-align: middle;
            color: #374151
        }

        .mini-table tr:last-child td {
            border-bottom: none
        }

        .mini-table tr:hover td {
            background: #f9fafb
        }

        /* BADGES */
        .badge {
            display: inline-block;
            padding: .18rem .48rem;
            border-radius: 6px;
            font-size: .7rem;
            font-weight: 600
        }

        .badge-green {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0
        }

        .badge-yellow {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a
        }

        .badge-red {
            background: #fef2f2;
            color: #ef4444;
            border: 1px solid #fecaca
        }

        .badge-blue {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe
        }

        .badge-purple {
            background: #f5f3ff;
            color: #7c3aed;
            border: 1px solid #ddd6fe
        }

        /* ROLE BADGES */
        .role-badge {
            display: inline-block;
            padding: .15rem .45rem;
            border-radius: 5px;
            font-size: .68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em
        }

        .role-admin {
            background: #fef3c7;
            color: #92400e
        }

        .role-user {
            background: #dcfce7;
            color: #166534
        }

        .role-manager {
            background: #ede9fe;
            color: #6d28d9
        }

        /* BOTTOM ROW */
        .bottom-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem
        }

        /* LIVE FEED */
        .feed-item {
            display: flex;
            align-items: flex-start;
            gap: .75rem;
            padding: .75rem 0;
            border-bottom: 1px solid #f8fafc
        }

        .feed-item:last-child {
            border-bottom: none
        }

        .feed-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 5px
        }

        .feed-meta {
            flex: 1;
            min-width: 0
        }

        .feed-action {
            font-size: .83rem;
            font-weight: 500;
            color: var(--text);
            line-height: 1.4
        }

        .feed-time {
            font-size: .7rem;
            color: var(--subtle);
            margin-top: .18rem
        }

        .feed-user {
            font-size: .73rem;
            color: var(--accent);
            font-weight: 600
        }

        /* USER AVATAR */
        .user-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .65rem;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0
        }

        /* PROGRESS BARS */
        .prog-row {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin-bottom: .7rem
        }

        .prog-row:last-child {
            margin-bottom: 0
        }

        .prog-label {
            font-size: .78rem;
            color: var(--text);
            font-weight: 500;
            width: 90px;
            flex-shrink: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap
        }

        .prog-bar-bg {
            flex: 1;
            height: 6px;
            border-radius: 99px;
            background: #e2e8f0;
            overflow: hidden
        }

        .prog-bar-fill {
            height: 100%;
            border-radius: 99px
        }

        .prog-val {
            font-size: .75rem;
            font-weight: 600;
            color: var(--muted);
            min-width: 36px;
            text-align: right
        }

        /* SYSTEM HEALTH GRID */
        .health-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.25rem
        }

        /* LIVE INDICATOR */
        .live-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #10b981;
            display: inline-block;
            animation: pulse 1.5s infinite
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: .35
            }
        }

        /* RESPONSIVE */
        @media(max-width:1200px) {
            .stat-grid {
                grid-template-columns: repeat(2, 1fr)
            }

            .health-grid {
                grid-template-columns: 1fr 1fr
            }
        }

        @media(max-width:1024px) {
            .mid-row {
                grid-template-columns: 1fr
            }

            .bottom-row {
                grid-template-columns: 1fr 1fr
            }
        }

        @media(max-width:768px) {
            .sidebar {
                transform: translateX(-100%)
            }

            .app-body {
                margin-left: 0
            }

            .hamburger {
                display: flex
            }

            .t-search {
                display: none
            }

            .main {
                padding: 1rem
            }

            .stat-grid {
                grid-template-columns: 1fr 1fr
            }

            .bottom-row {
                grid-template-columns: 1fr
            }

            .health-grid {
                grid-template-columns: 1fr
            }
        }

        @media(max-width:480px) {
            .topbar {
                padding: 0 1rem
            }

            .main {
                padding: .75rem;
                gap: .85rem
            }

            .stat-grid {
                grid-template-columns: 1fr 1fr
            }
        }
</style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">
  <div class="logo">
    <span class="logo-icon">💧</span>
    <span class="logo-text">EcoRain</span>
  </div>
 
  <div class="nav-section-label">Overview</div>
 
  <a href="<?= BASE_URL ?>/App/Admin/admin_dashboard.php"
     class="nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <rect x="3" y="3" width="7" height="7" rx="1"/>
      <rect x="14" y="3" width="7" height="7" rx="1"/>
      <rect x="3" y="14" width="7" height="7" rx="1"/>
      <rect x="14" y="14" width="7" height="7" rx="1"/>
    </svg>
    Dashboard
  </a>
 
  <a href="<?= BASE_URL ?>/App/Admin/admin.php"
     class="nav-item <?= $activePage === 'oversight' ? 'active' : '' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
      <circle cx="12" cy="12" r="3"/>
    </svg>
    Admin Oversight
  </a>
 
  <a href="<?= BASE_URL ?>/App/Admin/admin_usage.php"
     class="nav-item <?= $activePage === 'usage' ? 'active' : '' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
    </svg>
    Usage Stats
  </a>
 
  <a href="<?= BASE_URL ?>/App/Admin/admin_weather.php"
     class="nav-item <?= $activePage === 'weather' ? 'active' : '' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
    </svg>
    Weather
  </a>
 
  <a href="<?php echo BASE_URL; ?>/App/Admin/admin_map.php" class="nav-item">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/>
        <circle cx="12" cy="10" r="3"/>
      </svg>
      Tank Map
    </a>
  <div class="nav-section-label">Management</div>
 
  <a href="<?= BASE_URL ?>/App/Admin/admin_userlogs.php"
     class="nav-item <?= $activePage === 'users' ? 'active' : '' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
      <circle cx="9" cy="7" r="4"/>
      <path d="M23 21v-2a4 4 0 00-3-3.87"/>
      <path d="M16 3.13a4 4 0 010 7.75"/>
    </svg>
    Users &amp; Roles
  </a>
 
  <a href="<?= BASE_URL ?>/App/Admin/admin_settings.php"
     class="nav-item <?= $activePage === 'settings' ? 'active' : '' ?>">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <circle cx="12" cy="12" r="3"/>
      <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
    </svg>
    Settings
  </a>
 
  <div class="sidebar-spacer"></div>
  <div class="sidebar-bottom">
    <a href="<?= BASE_URL ?>/Connections/signout.php" class="nav-item logout">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>
      </svg>
      Log Out
    </a>
  </div>
</aside>

<div class="main-wrap">
  <header class="topbar">
    <div class="topbar-left">
      <button class="hamburger" onclick="toggleSidebar()"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
      <div>
        <div class="page-title">Weather Monitor</div>
        <div class="page-sub">Live conditions — <?= htmlspecialchars($CITY) ?></div>
      </div>
    </div>
    <div class="topbar-right">
      <div class="t-search">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" placeholder="Search..."/>
      </div>
      <div class="t-icon">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        <span class="notif-dot"></span>
      </div>
       <a href="<?php echo BASE_URL;?>/App/Admin/admin_userlogs.php" class="t-avatar"><?= htmlspecialchars($initials) ?></a>
    </div>
  </header>

  <div class="page-content">

    <!-- HERO -->
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

    <!-- ALERT -->
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

    <!-- 7-DAY FORECAST -->
    <div>
      <div class="section-label">7-Day Forecast</div>
      <div class="forecast-row">
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
          <?php $fallback = [['Today','⛅',28,31,24,60],['Fri','🌧️',25,27,22,80],['Sat','🌦️',26,29,23,50],['Sun','☀️',30,33,25,10],['Mon','⛅',29,32,24,30],['Tue','🌩️',24,26,21,90],['Wed','🌤️',27,30,23,20]];
          foreach ($fallback as [$dDay,$dEmoj,$dTemp,$dMax,$dMin,$dPop]): ?>
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

    <!-- BOTTOM GRID -->
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
            <div class="dleg-item"><div class="dleg-dot" style="background:#2563eb;"></div><div><div class="dleg-val"><?= $normalPct ?>%</div><div>Normal readings</div></div></div>
            <div class="dleg-item"><div class="dleg-dot" style="background:#93c5fd;"></div><div><div class="dleg-val"><?= $rainPct ?>%</div><div>Rain anomaly</div></div></div>
            <div class="dleg-item"><div class="dleg-dot" style="background:#f59e0b;"></div><div><div class="dleg-val"><?= $alertPct ?>%</div><div>Alert readings</div></div></div>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); document.getElementById('overlay').classList.toggle('show'); }
function closeSidebar()  { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.remove('show'); }

Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#9ca3af';
Chart.defaults.font.size = 11;

const rfCtx = document.getElementById('rainfallChart').getContext('2d');
const grad  = rfCtx.createLinearGradient(0, 0, 0, 140);
grad.addColorStop(0, 'rgba(37,99,235,0.28)');
grad.addColorStop(1, 'rgba(37,99,235,0)');
new Chart(rfCtx, { type: 'line', data: { labels: <?= $rfLabelsJson ?>, datasets: [{ data: <?= $rfDataJson ?>, borderColor: '#2563eb', borderWidth: 2.5, backgroundColor: grad, fill: true, tension: 0.42, pointRadius: 3, pointBackgroundColor: '#2563eb', pointHoverRadius: 5 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f172a', callbacks: { label: ctx => ` ${ctx.raw} mm` } } }, scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 7, color: '#94a3b8' } }, y: { grid: { color: '#f3f4f6' }, beginAtZero: true, ticks: { color: '#94a3b8', callback: v => v + 'mm' } } } } });

new Chart(document.getElementById('donutChart').getContext('2d'), { type: 'doughnut', data: { datasets: [{ data: [<?= $normalPct ?>, <?= $rainPct ?>, <?= $alertPct ?>], backgroundColor: ['#2563eb', '#93c5fd', '#f59e0b'], borderWidth: 0, hoverOffset: 4 }] }, options: { cutout: '72%', responsive: true, plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f172a', callbacks: { label: ctx => ` ${ctx.parsed}%` } } } } });
</script>
</body>
</html>