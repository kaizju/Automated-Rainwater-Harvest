<?php
require_once '../../Connections/config.php';

// Tank
$tank    = $pdo->query("SELECT * FROM tank LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$percent = 0;
if ($tank && $tank['max_capacity'] > 0) {
    $percent = round(($tank['current_liters'] / $tank['max_capacity']) * 100, 1);
}

// Water Quality
$quality = $pdo->query(
    "SELECT * FROM water_quality ORDER BY recorded_at DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

// Today collected
$todayRow       = $pdo->query("SELECT COALESCE(SUM(usage_liters),0) AS t FROM water_usage WHERE DATE(recorded_at)=CURDATE()")->fetch(PDO::FETCH_ASSOC);
$todayCollected = (float)$todayRow['t'];

// Usage last 7 days
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

// Sensor readings
$sensors = $pdo->query("
    SELECT sr.anomaly, sr.recorded_at, s.sensor_type, s.model
    FROM sensor_readings sr JOIN sensors s ON sr.sensor_id=s.sensor_id
    ORDER BY sr.recorded_at DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Activity log
$activities = $pdo->query("
    SELECT ual.action, ual.created_at, ual.user_id
    FROM user_activity_logs ual
    ORDER BY ual.created_at DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

function phLabel($v)   { return $v == 0 ? 'None' : ($v < 6.5 ? 'Low' : ($v > 8.5 ? 'High' : 'Optimal')); }
function phColor($v)   { return ($v == 0 || ($v >= 6.5 && $v <= 8.5)) ? '#16a34a' : '#ef4444'; }
function turbLabel($v) { return $v == 0 ? 'None' : ($v > 4 ? 'Poor' : ($v > 1 ? 'Moderate' : 'Excellent')); }
function turbColor($v) { return ($v == 0 || $v <= 1) ? '#16a34a' : ($v <= 4 ? '#d97706' : '#ef4444'); }

$tankBg     = $percent < 20 ? 'linear-gradient(135deg,#fee2e2,#fca5a5)' : ($percent < 50 ? 'linear-gradient(135deg,#fef9c3,#fde68a)' : 'linear-gradient(135deg,#dbeafe,#93c5fd)');
$tankAccent = $percent < 20 ? '#dc2626' : ($percent < 50 ? '#d97706' : '#2563eb');

$updatedAgo = 'N/A';
if ($quality) {
    $diff = time() - strtotime($quality['recorded_at']);
    if ($diff < 60) $updatedAgo = $diff . 's ago';
    elseif ($diff < 3600) $updatedAgo = floor($diff/60) . 'm ago';
    else $updatedAgo = floor($diff/3600) . 'h ago';
}

$initials = 'AD';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EcoRain — Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

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
    --shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.05);
  }

  html, body { height: 100%; }
  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    display: flex;
    min-height: 100vh;
    overflow-x: hidden;
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
    top: 0; left: 0;
    height: 100vh;
    overflow-y: auto;
    z-index: 100;
    transition: transform .25s ease;
  }
  .sidebar.open { transform: translateX(0) !important; }

  .logo {
    display: flex; align-items: center; gap: .6rem;
    padding: .25rem .5rem .25rem .25rem;
    margin-bottom: 2rem;
  }
  .logo-icon {
    width: 34px; height: 34px;
    background: linear-gradient(145deg, #60a5fa, #1d4ed8);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.05rem; flex-shrink: 0;
  }
  .logo-text { font-family: 'Sora', sans-serif; font-size: 1.1rem; font-weight: 700; color: #fff; letter-spacing: -.02em; }

  .nav-item {
    display: flex; align-items: center; gap: .7rem;
    padding: .6rem .75rem; border-radius: 9px;
    font-size: .875rem; font-weight: 500;
    color: #94a3b8; text-decoration: none;
    margin-bottom: .1rem;
    transition: background .15s, color .15s;
  }
  .nav-item svg { width: 17px; height: 17px; flex-shrink: 0; }
  .nav-item:hover { background: rgba(255,255,255,.06); color: #e2e8f0; }
  .nav-item.active { background: rgba(96,165,250,.15); color: #93c5fd; font-weight: 600; }

  .sidebar-spacer { flex: 1; }
  .sidebar-bottom { border-top: 1px solid #1e293b; padding-top: 1rem; margin-top: .5rem; }
  .nav-item.logout:hover { background: rgba(239,68,68,.1); color: #fca5a5; }

  /* OVERLAY */
  .overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 99;
  }
  .overlay.show { display: block; }

  /* APP BODY */
  .app-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    margin-left: var(--sidebar-w);
    transition: margin-left .25s ease;
  }

  /* TOPBAR */
  .topbar {
    height: var(--topbar-h);
    background: var(--card-bg);
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 1.5rem;
    position: sticky; top: 0; z-index: 50;
    flex-shrink: 0;
  }
  .topbar-left { display: flex; align-items: center; gap: .75rem; }
  .hamburger {
    display: none;
    background: none; border: none; cursor: pointer;
    padding: .35rem; color: var(--text);
    border-radius: 8px;
  }
  .hamburger svg { width: 22px; height: 22px; }
  .page-title { font-family: 'Sora', sans-serif; font-size: 1.05rem; font-weight: 700; color: var(--text); }
  .page-sub { font-size: .72rem; color: var(--muted); }

  .topbar-right { display: flex; align-items: center; gap: .65rem; }
  .t-search {
    display: flex; align-items: center; gap: .45rem;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 9px; padding: .38rem .8rem; width: 180px;
  }
  .t-search svg { width: 13px; height: 13px; color: var(--subtle); flex-shrink: 0; }
  .t-search input { background: none; border: none; outline: none; font-size: .8rem; font-family: 'DM Sans', sans-serif; color: var(--text); width: 100%; }
  .t-search input::placeholder { color: var(--subtle); }

  .t-btn {
    width: 34px; height: 34px; border: 1px solid var(--border);
    border-radius: 9px; background: var(--card-bg);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: var(--muted); position: relative;
    transition: border-color .15s, background .15s;
  }
  .t-btn:hover { border-color: var(--accent); background: var(--accent-light); color: var(--accent); }
  .t-btn svg { width: 15px; height: 15px; }
  .notif-dot { position: absolute; top: 5px; right: 5px; width: 6px; height: 6px; background: #ef4444; border-radius: 50%; border: 1.5px solid var(--card-bg); }

  .t-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #7c3aed);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: .78rem; font-weight: 700; cursor: pointer;
    text-decoration: none;
  }

  /* MAIN */
  .main {
    flex: 1; padding: 1.5rem;
    overflow-y: auto;
    display: flex; flex-direction: column; gap: 1.25rem;
  }

  /* CARDS */
  .card {
    background: var(--card-bg); border-radius: var(--radius);
    border: 1px solid var(--border); padding: 1.25rem 1.35rem;
    box-shadow: var(--shadow);
  }
  .card-label {
    font-size: .68rem; font-weight: 700; letter-spacing: .1em;
    text-transform: uppercase; color: var(--subtle); margin-bottom: .85rem;
  }

  /* TOP ROW */
  .top-row {
    display: grid;
    grid-template-columns: 320px 1fr 1fr;
    gap: 1.25rem; align-items: start;
  }

  /* TANK CARD */
  .tank-card {
    border-radius: var(--radius); padding: 1.4rem;
    min-height: 300px; display: flex; flex-direction: column;
    position: relative; overflow: hidden; box-shadow: var(--shadow);
  }
  .tank-header {
    display: flex; align-items: center; gap: .45rem;
    font-size: .8rem; font-weight: 600; color: #1e3a5f;
    margin-bottom: 1rem; opacity: .75;
  }
  .tank-header svg { width: 15px; height: 15px; }
  .tank-percent-big {
    font-family: 'Sora', sans-serif; font-size: 4rem; font-weight: 800;
    color: #0f172a; line-height: 1; letter-spacing: -.04em;
  }
  .tank-liters-sub { font-size: .82rem; font-weight: 500; color: #374151; margin-top: .3rem; margin-bottom: auto; }
  .tank-footer { margin-top: 1.5rem; }
  .tank-meta {
    font-size: .78rem; font-weight: 500; color: #374151;
    margin-bottom: .55rem; display: flex; justify-content: space-between;
  }
  .tank-bar-bg {
    background: rgba(255,255,255,.5); border-radius: 99px;
    height: 7px; overflow: hidden; margin-bottom: .65rem;
  }
  .tank-bar-fill { height: 100%; border-radius: 99px; transition: width .9s; }
  .tank-collected {
    display: inline-flex; align-items: center; gap: .3rem;
    background: rgba(255,255,255,.45); border: 1px solid rgba(255,255,255,.6);
    border-radius: 7px; padding: .28rem .6rem;
    font-size: .76rem; font-weight: 600; color: #1e3a5f;
  }

  /* WATER QUALITY */
  .wq-top { display: flex; align-items: center; gap: .6rem; margin-bottom: 1rem; flex-wrap: wrap; }
  .wq-status-badge {
    font-size: .72rem; font-weight: 700; padding: .22rem .6rem;
    border-radius: 6px; background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0;
  }
  .wq-updated { font-size: .72rem; color: var(--subtle); }
  .wq-metric {
    border: 1px solid var(--border); border-radius: 11px;
    padding: .9rem 1rem; margin-bottom: .6rem;
  }
  .wq-metric:last-child { margin-bottom: 0; }
  .wq-metric-hd { display: flex; align-items: center; gap: .35rem; font-size: .73rem; color: var(--muted); margin-bottom: .35rem; }
  .wq-metric-hd svg { width: 13px; height: 13px; }
  .wq-val { font-family: 'Sora', sans-serif; font-size: 1.75rem; font-weight: 700; color: var(--text); line-height: 1; margin-bottom: .2rem; }
  .wq-lbl { font-size: .76rem; font-weight: 600; }

  /* CHART */
  .chart-title { font-size: .875rem; font-weight: 600; color: var(--text); margin-bottom: .85rem; }
  .chart-wrap { height: 200px; position: relative; }

  /* MID ROW */
  .mid-row { display: grid; grid-template-columns: 2fr 1fr; gap: 1.25rem; align-items: start; }

  /* FORECAST */
  .forecast-title { font-size: .875rem; font-weight: 600; color: var(--text); margin-bottom: 1rem; padding-bottom: .75rem; border-bottom: 1px solid var(--border); }
  .forecast-inner { border: 1px solid var(--border); border-radius: 11px; overflow: hidden; }
  .forecast-row {
    display: flex; align-items: center; gap: .85rem; padding: .8rem 1rem;
    border-bottom: 1px solid #f1f5f9; transition: background .12s;
  }
  .forecast-row:last-child { border-bottom: none; }
  .forecast-row:hover { background: var(--bg); }
  .forecast-icon { font-size: 1.5rem; width: 36px; text-align: center; flex-shrink: 0; }
  .forecast-day { font-size: .875rem; font-weight: 600; color: var(--text); }
  .forecast-pct { font-size: .73rem; color: var(--muted); margin-top: .08rem; }
  .forecast-right { margin-left: auto; text-align: right; }
  .forecast-predicted { font-size: .875rem; font-weight: 700; color: var(--text); }
  .forecast-lbl { font-size: .68rem; color: var(--subtle); }

  /* BOTTOM ROW */
  .bottom-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }

  /* TABLES */
  .mini-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
  .mini-table th {
    font-size: .65rem; font-weight: 700; letter-spacing: .08em;
    text-transform: uppercase; color: var(--subtle);
    padding: 0 0 .55rem; text-align: left; border-bottom: 1px solid var(--border);
  }
  .mini-table td {
    padding: .55rem 0; border-bottom: 1px solid #f8fafc;
    vertical-align: middle; color: #374151;
  }
  .mini-table tr:last-child td { border-bottom: none; }
  .mini-table td:last-child { text-align: right; color: var(--subtle); font-size: .73rem; }
  .badge {
    display: inline-block; padding: .18rem .48rem; border-radius: 6px;
    font-size: .7rem; font-weight: 600;
    background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0;
  }
  .u-link { color: var(--accent); font-weight: 600; }

  /* RESPONSIVE — tablet */
  @media (max-width: 1200px) {
    .top-row { grid-template-columns: 280px 1fr 1fr; }
  }
  @media (max-width: 1024px) {
    .top-row { grid-template-columns: 1fr 1fr; }
    .mid-row { grid-template-columns: 1fr; }
    .bottom-row { grid-template-columns: 1fr 1fr; }
  }
  @media (max-width: 768px) {
    :root { --sidebar-w: 240px; }
    .sidebar { transform: translateX(-100%); }
    .app-body { margin-left: 0; }
    .hamburger { display: flex; }
    .t-search { display: none; }
    .main { padding: 1rem; }
    .top-row { grid-template-columns: 1fr; }
    .mid-row { grid-template-columns: 1fr; }
    .bottom-row { grid-template-columns: 1fr; }
    .tank-card { min-height: auto; }
    .chart-wrap { height: 180px; }
  }
  @media (max-width: 480px) {
    .topbar { padding: 0 1rem; }
    .main { padding: .75rem; gap: .85rem; }
    .tank-percent-big { font-size: 3rem; }
  }
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

  <a href="<?php echo BASE_URL;?>/App/User/dashboard.php" class="nav-item active">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
    Dashboard
  </a>
  <a href="<?php echo BASE_URL;?>/App/User/usage.php" class="nav-item">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    Usage Stats
  </a>
  <a href="<?php echo BASE_URL;?>/App/User/weather.php" class="nav-item">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
    Weather
  </a>
  

  <div class="sidebar-spacer"></div>
  <div class="sidebar-bottom">
    <a href="<?php echo BASE_URL; ?>/Connections/signout.php" class="nav-item logout">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      Log Out
    </a>
  </div>
</aside>

<!-- APP BODY -->
<div class="app-body">

  <header class="topbar">
    <div class="topbar-left">
      <button class="hamburger" onclick="toggleSidebar()" aria-label="Menu">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div>
        <div class="page-title">Dashboard</div>
        <div class="page-sub">Welcome to EcoRain</div>
      </div>
    </div>
    <div class="topbar-right">
      <div class="t-search">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" placeholder="Search..."/>
      </div>
      <div class="t-btn">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        <span class="notif-dot"></span>
      </div>
      <a href="<?php echo BASE_URL;?>/App/User/profileinfo.php" class="t-avatar"><?= htmlspecialchars($initials) ?></a>
    </div>
  </header>

  <main class="main">

    <!-- TOP ROW -->
    <div class="top-row">

      <!-- Tank Card -->
      <div class="tank-card" style="background:<?= $tankBg ?>">
        <div class="tank-header">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M12 8v4l3 3"/></svg>
          Main Tank Level
        </div>
        <div class="tank-percent-big"><?= $percent ?>%</div>
        <div class="tank-liters-sub">of <?= $tank ? number_format($tank['max_capacity']) : '5,000' ?>L capacity</div>
        <div class="tank-footer">
          <div class="tank-meta">
            <span><?= $tank ? number_format($tank['current_liters']) : '0' ?>L current</span>
            <span><?= $tank ? number_format($tank['max_capacity']) : '5,000' ?>L max</span>
          </div>
          <div class="tank-bar-bg">
            <div class="tank-bar-fill" style="width:<?= $percent ?>%;background:<?= $tankAccent ?>"></div>
          </div>
          <div class="tank-collected">💧 <?= number_format($todayCollected, 0) ?>L collected today</div>
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
          <div class="wq-lbl" style="color:<?= $quality ? phColor($quality['ph_level']) : '#16a34a' ?>"><?= $quality ? phLabel($quality['ph_level']) : 'None' ?></div>
        </div>
        <div class="wq-metric">
          <div class="wq-metric-hd">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8.56 2.75c4.37 6.03 6.02 9.42 8.03 17.72m2.54-15.38c-3.72 4.35-8.94 5.66-16.88 5.85m19.5 1.9c-3.5-.93-6.63-.82-8.94 0-2.58.92-5.01 2.86-7.44 6.32"/></svg>
            Turbidity
          </div>
          <div class="wq-val"><?= $quality ? $quality['turbidity'] : '0.0' ?></div>
          <div class="wq-lbl" style="color:<?= $quality ? turbColor($quality['turbidity']) : '#16a34a' ?>"><?= $quality ? turbLabel($quality['turbidity']) : 'None' ?></div>
        </div>
      </div>

      <!-- Chart -->
      <div class="card">
        <div class="chart-title">Water Usage — Last 7 Days</div>
        <div class="chart-wrap">
          <canvas id="bar-chart"></canvas>
        </div>
      </div>

    </div><!-- /top-row -->

    <!-- MID ROW -->
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

    <!-- BOTTOM ROW -->
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

      <div class="card">
        <div class="card-label">Tank Summary</div>
        <?php if ($tank): ?>
        <table class="mini-table">
          <tbody>
            <tr><td style="color:var(--muted)">Tank Name</td><td style="text-align:right;font-weight:600"><?= htmlspecialchars($tank['tankname']) ?></td></tr>
            <tr><td style="color:var(--muted)">Location</td><td style="text-align:right;font-weight:600;word-break:break-all"><?= htmlspecialchars($tank['location_add']) ?></td></tr>
            <tr><td style="color:var(--muted)">Current Volume</td><td style="text-align:right;font-weight:600"><?= number_format($tank['current_liters']) ?>L</td></tr>
            <tr><td style="color:var(--muted)">Max Capacity</td><td style="text-align:right;font-weight:600"><?= number_format($tank['max_capacity']) ?>L</td></tr>
            <tr><td style="color:var(--muted)">Fill Level</td><td style="text-align:right;font-weight:600"><?= $percent ?>%</td></tr>
            <tr><td style="color:var(--muted)">Status</td><td style="text-align:right"><span class="badge"><?= htmlspecialchars($tank['status_tank']) ?></span></td></tr>
          </tbody>
        </table>
        <?php else: ?>
          <p style="color:var(--subtle);font-size:.82rem;margin-top:.35rem">No tank data.</p>
        <?php endif; ?>
      </div>

    </div><!-- /bottom-row -->

  </main>
</div>

<script>
// Sidebar toggle
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('show');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('overlay').classList.remove('show');
}

// Bar Chart
new Chart(document.getElementById('bar-chart').getContext('2d'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [{
      label: 'Rainwater Collection (L)',
      data: <?= json_encode($chartData) ?>,
      backgroundColor: '#3b82f6',
      hoverBackgroundColor: '#2563eb',
      borderWidth: 0, borderRadius: 5, borderSkipped: false,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { display: true, position: 'top', align: 'end',
        labels: { font: { size: 10, family: 'DM Sans' }, color: '#94a3b8', boxWidth: 18, boxHeight: 7, borderRadius: 3, useBorderRadius: true }
      },
      tooltip: { backgroundColor: '#0f172a', titleFont: { family: 'Sora', size: 11 }, bodyFont: { family: 'DM Sans', size: 11 }, padding: 10, cornerRadius: 8 }
    },
    scales: {
      x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { family: 'DM Sans', size: 11 } } },
      y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8', font: { family: 'DM Sans', size: 11 } } }
    }
  }
});

// Rainfall Forecast
const WX = { key: 'a5712e740541248ce7883f0af8581be4', lat: 8.360015, lon: 124.868419 };

function wxIcon(desc, rain) {
  if (rain > 5) return '🌧️';
  if (rain > 0) return '🌦️';
  if (desc.includes('cloud')) return '☁️';
  if (desc.includes('clear') || desc.includes('sun')) return '☀️';
  return '🌤️';
}
function rainChance(item) {
  const hr = item.rain && item.rain['3h'] > 0;
  const h = item.main.humidity, c = item.clouds.all;
  if (hr) return Math.min(Math.round(h * 0.7 + c * 0.3), 95);
  if (h>80&&c>70) return Math.round((h+c)/2*0.5);
  if (h>70) return Math.round(h*0.3);
  return Math.round(c*0.2);
}

async function loadForecast() {
  try {
    const res = await fetch(`https://api.openweathermap.org/data/2.5/forecast?lat=${WX.lat}&lon=${WX.lon}&appid=${WX.key}&units=metric`);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    document.getElementById('wx-location').textContent = `Rainfall Forecast — ${data.city.name}, ${data.city.country}`;
    document.getElementById('wx-loading').style.display = 'none';
    const daily = {};
    data.list.forEach(item => {
      const key = new Date(item.dt*1000).toLocaleDateString('en-US',{weekday:'long',month:'short',day:'numeric'});
      if (!daily[key]) daily[key] = { name: new Date(item.dt*1000).toLocaleDateString('en-US',{weekday:'long'}), rain:[], chance:[], desc: item.weather[0].description };
      daily[key].rain.push(item.rain ? (item.rain['3h']||0) : 0);
      daily[key].chance.push(rainChance(item));
    });
    const html = Object.keys(daily).slice(0,3).map((k,i) => {
      const total = daily[k].rain.reduce((a,b)=>a+b,0);
      const avg = Math.round(daily[k].chance.reduce((a,b)=>a+b,0)/daily[k].chance.length);
      const label = i===0?'Today':i===1?'Tomorrow':daily[k].name.slice(0,3);
      return `<div class="forecast-row">
        <div class="forecast-icon">${wxIcon(daily[k].desc,total)}</div>
        <div><div class="forecast-day">${label}</div><div class="forecast-pct">${avg}% chance of rain</div></div>
        <div class="forecast-right"><div class="forecast-predicted">+${Math.round(total*10)}L</div><div class="forecast-lbl">predicted</div></div>
      </div>`;
    }).join('');
    document.getElementById('rainfallForecast').innerHTML = html;
    document.getElementById('forecastSection').style.display = 'block';
  } catch(e) {
    document.getElementById('wx-loading').style.display = 'none';
    document.getElementById('wx-error').style.display = 'block';
    document.getElementById('wx-error').textContent = 'Weather unavailable: ' + e.message;
  }
}
loadForecast();
</script>
</body>
<link rel="stylesheet" href="/Others/all.css">
</html>