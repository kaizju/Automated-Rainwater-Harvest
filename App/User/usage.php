<?php
require_once '../../Connections/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

$totalCollected = (float)$pdo->query("SELECT COALESCE(SUM(usage_liters),0) FROM water_usage WHERE usage_type != 'Tap'")->fetchColumn();
$totalTap = (float)$pdo->query("SELECT COALESCE(SUM(usage_liters),0) FROM water_usage WHERE usage_type = 'Tap'")->fetchColumn();
$netSavings = max(0, $totalCollected - $totalTap);
$avgDaily = (float)$pdo->query("SELECT COALESCE(AVG(daily_sum),0) FROM (SELECT DATE(recorded_at) d, SUM(usage_liters) daily_sum FROM water_usage WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND usage_type != 'Tap' GROUP BY DATE(recorded_at)) t")->fetchColumn();
$thisMonth = (float)$pdo->query("SELECT COALESCE(SUM(usage_liters),0) FROM water_usage WHERE MONTH(recorded_at)=MONTH(CURDATE()) AND YEAR(recorded_at)=YEAR(CURDATE()) AND usage_type != 'Tap'")->fetchColumn();
$lastMonth = (float)$pdo->query("SELECT COALESCE(SUM(usage_liters),0) FROM water_usage WHERE MONTH(recorded_at)=MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(recorded_at)=YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND usage_type != 'Tap'")->fetchColumn();
$pctChange = $lastMonth > 0 ? round(($thisMonth - $lastMonth) / $lastMonth * 100, 1) : 0;

$trend30 = $pdo->query("SELECT DATE(recorded_at) AS d, SUM(usage_liters) AS total FROM water_usage WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND usage_type != 'Tap' GROUP BY DATE(recorded_at) ORDER BY d ASC")->fetchAll(PDO::FETCH_ASSOC);
$trend30Map = []; foreach ($trend30 as $r) $trend30Map[$r['d']] = (float)$r['total'];
$trendLabels = []; $trendData = [];
for ($i = 29; $i >= 0; $i--) { $day = date('Y-m-d', strtotime("-$i days")); $trendLabels[] = date('M j', strtotime($day)); $trendData[] = $trend30Map[$day] ?? 0; }

$monthly = $pdo->query("SELECT DATE_FORMAT(recorded_at,'%b') AS mon, DATE_FORMAT(recorded_at,'%Y-%m') AS ym, SUM(CASE WHEN usage_type != 'Tap' THEN usage_liters ELSE 0 END) AS rainwater, SUM(CASE WHEN usage_type = 'Tap' THEN usage_liters ELSE 0 END) AS tap FROM water_usage WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym, mon ORDER BY ym ASC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$barLabels = array_column($monthly,'mon');
$barRainwater = array_column($monthly,'rainwater');
$barTap = array_column($monthly,'tap');

$breakdown = $pdo->query("SELECT usage_type, SUM(usage_liters) AS total FROM water_usage GROUP BY usage_type ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);
$breakLabels = array_column($breakdown,'usage_type');
$breakData   = array_map('floatval', array_column($breakdown,'total'));

$recentUsage = $pdo->query("SELECT wu.usage_type, wu.usage_liters, wu.recorded_at, t.tankname, u.email FROM water_usage wu LEFT JOIN tank t ON wu.tank_id = t.tank_id LEFT JOIN users u ON wu.user_id = u.id ORDER BY wu.recorded_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$initials = strtoupper(substr($_SESSION['email'] ?? 'U', 0, 2));

$trendLabelsJson  = json_encode($trendLabels);
$trendDataJson    = json_encode($trendData);
$barLabelsJson    = json_encode($barLabels ?: ['No data']);
$barRainwaterJson = json_encode(array_map('floatval', $barRainwater) ?: [0]);
$barTapJson       = json_encode(array_map('floatval', $barTap) ?: [0]);
$breakLabelsJson  = json_encode($breakLabels ?: ['No data']);
$breakDataJson    = json_encode($breakData   ?: [0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>EcoRain — Usage Statistics</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Inter', sans-serif; background: #e5e7eb; color: #111827; font-size: 14px; display: flex; min-height: 100vh; overflow-x: hidden; }

  /* SIDEBAR */
  .sidebar { width: 260px; background: #0f172a; min-height: 100vh; height: 100vh; position: fixed; top: 0; left: 0; display: flex; flex-direction: column; padding: 1.5rem 1rem; flex-shrink: 0; z-index: 100; transition: transform .25s ease; overflow-y: auto; }
  .sidebar.open { transform: translateX(0) !important; }
  .sidebar-logo { display: flex; align-items: center; gap: .6rem; padding: .25rem .5rem .25rem .25rem; margin-bottom: 2rem; }
  .logo-drop { width: 34px; height: 34px; background: linear-gradient(160deg,#60a5fa 20%,#2563eb 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
  .logo-name { font-size: 1.1rem; font-weight: 700; color: #fff; letter-spacing: -.01em; }
  .nav-section { flex: 1; display: flex; flex-direction: column; gap: .15rem; }
  .nav-link { display: flex; align-items: center; gap: .75rem; padding: .6rem .85rem; border-radius: 8px; font-size: .875rem; font-weight: 500; color: #94a3b8; text-decoration: none; transition: background .15s, color .15s; }
  .nav-link svg { width: 17px; height: 17px; flex-shrink: 0; stroke-width: 1.8; }
  .nav-link:hover  { background: rgba(255,255,255,.07); color: #e2e8f0; }
  .nav-link.active { background: rgba(255,255,255,.12); color: #fff; font-weight: 600; }
  .sidebar-footer { margin-top: auto; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,.08); }
  .nav-link.logout:hover { background: rgba(239,68,68,.13); color: #fca5a5; }

  .overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 99; }
  .overlay.show { display: block; }

  .main-wrap { flex: 1; display: flex; flex-direction: column; overflow: hidden; margin-left: 260px; transition: margin-left .25s; }

  .topbar { height: 64px; background: #fff; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; padding: 0 2rem; flex-shrink: 0; position: sticky; top: 0; z-index: 50; }
  .topbar-left { display: flex; align-items: center; gap: .75rem; }
  .hamburger { display: none; background: none; border: none; cursor: pointer; padding: .35rem; color: #111827; border-radius: 8px; }
  .hamburger svg { width: 22px; height: 22px; }
  .topbar-left .page-title { font-size: 1.25rem; font-weight: 700; color: #111827; }
  .topbar-left .page-sub   { font-size: .78rem; color: #6b7280; margin-top: .1rem; }
  .topbar-right { display: flex; align-items: center; gap: .85rem; }
  .t-search { display: flex; align-items: center; gap: .5rem; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: .45rem .85rem; width: 200px; }
  .t-search svg { width: 14px; height: 14px; color: #9ca3af; flex-shrink: 0; }
  .t-search input { background: none; border: none; outline: none; font-size: .83rem; font-family: 'Inter',sans-serif; color: #111827; width: 100%; }
  .t-search input::placeholder { color: #9ca3af; }
  .t-icon { width: 36px; height: 36px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #6b7280; position: relative; }
  .t-icon svg { width: 16px; height: 16px; }
  .notif-dot { position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; background: #ef4444; border-radius: 50%; border: 1.5px solid #fff; }
  .t-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg,#3b82f6,#8b5cf6); display: flex; align-items: center; justify-content: center; color: #fff; font-size: .8rem; font-weight: 600; cursor: pointer; text-decoration: none; }

  .page-content { flex: 1; overflow-y: auto; padding: 1.75rem 2rem 3rem; }

  /* STAT CARDS */
  .stat-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 1rem; margin-bottom: 1.25rem; }
  .stat-card { background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; padding: 1.2rem 1.35rem; position: relative; overflow: hidden; transition: transform .2s, box-shadow .2s; }
  .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.07); }
  .stat-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: .55rem; }
  .stat-label { font-size: .68rem; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .07em; }
  .stat-icon  { width: 32px; height: 32px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .stat-value { font-size: 1.8rem; font-weight: 700; color: #111827; letter-spacing: -.04em; line-height: 1; margin-bottom: .4rem; }
  .stat-value .unit { font-size: .9rem; font-weight: 500; color: #9ca3af; }
  .stat-foot  { font-size: .73rem; color: #9ca3af; display: flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
  .up   { color: #10b981; font-weight: 700; }
  .down { color: #ef4444; font-weight: 700; }
  .stat-glow { position: absolute; width: 70px; height: 70px; border-radius: 50%; filter: blur(36px); opacity: .12; bottom: -15px; right: -10px; pointer-events: none; }

  /* CHART CARDS */
  .chart-row { display: grid; gap: 1.25rem; margin-bottom: 1.25rem; }
  .chart-row-1 { grid-template-columns: 1fr; }
  .chart-row-3 { grid-template-columns: 2fr 1fr; }

  .chart-card { background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; overflow: hidden; }
  .chart-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.35rem; border-bottom: 1px solid #f3f4f6; flex-wrap: wrap; gap: .5rem; }
  .chart-title { font-size: .9rem; font-weight: 600; color: #111827; }
  .chart-sub   { font-size: .72rem; color: #9ca3af; margin-top: .15rem; }
  .chart-pill  { font-size: .68rem; font-weight: 600; padding: .28rem .75rem; border-radius: 20px; background: #eff6ff; color: #3b82f6; border: 1px solid #dbeafe; white-space: nowrap; }
  .chart-body  { padding: 1.1rem 1.35rem; }

  .legend { display: flex; gap: 1rem; margin-bottom: .85rem; flex-wrap: wrap; }
  .legend-item { display: flex; align-items: center; gap: .35rem; font-size: .73rem; color: #6b7280; font-weight: 500; }
  .leg-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }

  /* TABLE */
  .table-card { background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; overflow: hidden; }
  .table-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.35rem; border-bottom: 1px solid #f3f4f6; flex-wrap: wrap; gap: .5rem; }
  .table-title { font-size: .9rem; font-weight: 600; color: #111827; }
  .tbl { width: 100%; border-collapse: collapse; min-width: 480px; }
  .tbl th { padding: .65rem 1.35rem; text-align: left; font-size: .68rem; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .07em; border-bottom: 1px solid #f3f4f6; white-space: nowrap; }
  .tbl td { padding: .75rem 1.35rem; font-size: .84rem; color: #374151; border-bottom: 1px solid #f9fafb; }
  .tbl tr:last-child td { border-bottom: none; }
  .tbl tr:hover td { background: #f9fafb; }
  .tbl-scroll { overflow-x: auto; }
  .type-badge { display: inline-block; padding: .2rem .65rem; border-radius: 20px; font-size: .72rem; font-weight: 600; white-space: nowrap; }
  .type-cleaning   { background: #eff6ff; color: #3b82f6; }
  .type-irrigation { background: #ecfdf5; color: #10b981; }
  .type-drinking   { background: #faf5ff; color: #8b5cf6; }
  .type-tap        { background: #fef2f2; color: #ef4444; }
  .type-other      { background: #f3f4f6; color: #6b7280; }
  .empty-row td { text-align: center; color: #9ca3af; padding: 2rem; font-size: .85rem; }

  /* RESPONSIVE */
  @media (max-width: 1100px) {
    .stat-grid { grid-template-columns: repeat(2,1fr); }
    .chart-row-3 { grid-template-columns: 1fr; }
  }
  @media (max-width: 900px) {
    .sidebar { transform: translateX(-100%); }
    .main-wrap { margin-left: 0; }
    .hamburger { display: flex; }
    .t-search { display: none; }
    .topbar { padding: 0 1rem; }
    .page-content { padding: 1.25rem; }
  }
  @media (max-width: 600px) {
    .stat-grid { grid-template-columns: repeat(2,1fr); }
    .stat-value { font-size: 1.5rem; }
    .page-content { padding: 1rem .85rem; }
  }
  @media (max-width: 400px) {
    .stat-grid { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-drop">💧</div>
    <span class="logo-name">EcoRain</span>
  </div>
  <nav class="nav-section">
    <a href="<?php echo BASE_URL;?>/App/User/dashboard.php" class="nav-link">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Dashboard
    </a>
    <a href="<?php echo BASE_URL;?>/App/User/usage.php" class="nav-link active">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      Usage Stats
    </a>
    <a href="<?php echo BASE_URL;?>/App/User/weather.php" class="nav-link">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
      Weather
    </a>
  </nav>
  <div class="sidebar-footer">
    <a href="<?php echo BASE_URL; ?>/Connections/signout.php" class="nav-link logout">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      Log Out
    </a>
  </div>
</aside>

<div class="main-wrap">
  <header class="topbar">
    <div class="topbar-left">
      <button class="hamburger" onclick="toggleSidebar()"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
      <div>
        <div class="page-title">Usage Statistics</div>
        <div class="page-sub">Track your water conservation impact</div>
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
      <a href="<?php echo BASE_URL;?>/App/User/profileinfo.php" class="t-avatar"><?= htmlspecialchars($initials) ?></a>
    </div>
  </header>

  <div class="page-content">

    <!-- STAT CARDS -->
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-label">Total Collected</div>
          <div class="stat-icon" style="background:#eff6ff;"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#3b82f6" stroke-width="2"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg></div>
        </div>
        <div class="stat-value"><?= number_format($totalCollected) ?><span class="unit">L</span></div>
        <div class="stat-foot">
          <?php if ($pctChange >= 0): ?><span class="up">↑ <?= abs($pctChange) ?>%</span><span>vs last month</span>
          <?php else: ?><span class="down">↓ <?= abs($pctChange) ?>%</span><span>vs last month</span><?php endif; ?>
        </div>
        <div class="stat-glow" style="background:#3b82f6;"></div>
      </div>
      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-label">Total Tap Used</div>
          <div class="stat-icon" style="background:#fef2f2;"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#ef4444" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg></div>
        </div>
        <div class="stat-value"><?= number_format($totalTap) ?><span class="unit">L</span></div>
        <div class="stat-foot"><span>Total tap water consumption</span></div>
        <div class="stat-glow" style="background:#ef4444;"></div>
      </div>
      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-label">Net Savings</div>
          <div class="stat-icon" style="background:#ecfdf5;"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#10b981" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
        </div>
        <div class="stat-value"><?= number_format($netSavings) ?><span class="unit">L</span></div>
        <div class="stat-foot"><span>Rainwater used instead of tap</span></div>
        <div class="stat-glow" style="background:#10b981;"></div>
      </div>
      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-label">Avg Daily (30d)</div>
          <div class="stat-icon" style="background:#faf5ff;"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#8b5cf6" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
        </div>
        <div class="stat-value"><?= number_format($avgDaily, 0) ?><span class="unit">L</span></div>
        <div class="stat-foot"><span>Average per active day</span></div>
        <div class="stat-glow" style="background:#8b5cf6;"></div>
      </div>
    </div>

    <!-- 30-DAY TREND -->
    <div class="chart-row chart-row-1">
      <div class="chart-card">
        <div class="chart-header">
          <div><div class="chart-title">Daily Collection Trend</div><div class="chart-sub">Last 30 Days</div></div>
          <span class="chart-pill">Last 30 Days</span>
        </div>
        <div class="chart-body"><canvas id="trendChart" height="75"></canvas></div>
      </div>
    </div>

    <!-- MONTHLY BAR + DOUGHNUT -->
    <div class="chart-row chart-row-3">
      <div class="chart-card">
        <div class="chart-header">
          <div><div class="chart-title">Monthly Comparison</div><div class="chart-sub">Rainwater vs Tap — last 6 months</div></div>
        </div>
        <div class="chart-body">
          <div class="legend">
            <div class="legend-item"><div class="leg-dot" style="background:#3b82f6;"></div>Rainwater</div>
            <div class="legend-item"><div class="leg-dot" style="background:#d1d5db;"></div>Tap Water</div>
          </div>
          <canvas id="barChart" height="150"></canvas>
        </div>
      </div>
      <div class="chart-card">
        <div class="chart-header"><div><div class="chart-title">Usage Breakdown</div><div class="chart-sub">By type — all time</div></div></div>
        <div class="chart-body" style="display:flex;justify-content:center;align-items:center;min-height:220px;">
          <?php if (count($breakData) > 0 && array_sum($breakData) > 0): ?>
            <canvas id="doughnutChart" height="200"></canvas>
          <?php else: ?>
            <p style="color:#9ca3af;font-size:.85rem;text-align:center;">No usage data yet.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- RECENT USAGE TABLE -->
    <div class="table-card">
      <div class="table-header">
        <div class="chart-title">Recent Usage Records</div>
        <span class="chart-pill">Last 10</span>
      </div>
      <div class="tbl-scroll">
      <table class="tbl">
        <thead><tr><th>Type</th><th>Volume</th><th>Tank</th><th>User</th><th>Date &amp; Time</th></tr></thead>
        <tbody>
          <?php if ($recentUsage): ?>
            <?php foreach ($recentUsage as $row):
              $typeKey = strtolower(str_replace(' ','',$row['usage_type']));
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

  </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); document.getElementById('overlay').classList.toggle('show'); }
function closeSidebar()  { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.remove('show'); }

Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#9ca3af';
Chart.defaults.font.size = 11;

const tCtx = document.getElementById('trendChart').getContext('2d');
const grad = tCtx.createLinearGradient(0, 0, 0, 220);
grad.addColorStop(0, 'rgba(59,130,246,0.22)');
grad.addColorStop(1, 'rgba(59,130,246,0)');
new Chart(tCtx, { type: 'line', data: { labels: <?= $trendLabelsJson ?>, datasets: [{ data: <?= $trendDataJson ?>, borderColor: '#3b82f6', borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#3b82f6', tension: 0.45, fill: true, backgroundColor: grad }] }, options: { responsive: true, plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false, backgroundColor: '#fff', borderColor: '#e5e7eb', borderWidth: 1, titleColor: '#111827', bodyColor: '#6b7280', callbacks: { label: ctx => ` ${ctx.raw}L` } } }, scales: { x: { grid: { color: '#f3f4f6' }, ticks: { maxTicksLimit: 10 } }, y: { grid: { color: '#f3f4f6' }, ticks: { callback: v => v + 'L' }, suggestedMin: 0 } } } });

new Chart(document.getElementById('barChart').getContext('2d'), { type: 'bar', data: { labels: <?= $barLabelsJson ?>, datasets: [ { label:'Rainwater', data: <?= $barRainwaterJson ?>, backgroundColor:'rgba(59,130,246,0.82)', borderRadius:6, borderSkipped:false }, { label:'Tap Water', data: <?= $barTapJson ?>, backgroundColor:'rgba(209,213,219,0.85)', borderRadius:6, borderSkipped:false } ] }, options: { responsive: true, plugins: { legend: { display: false }, tooltip: { backgroundColor:'#fff', borderColor:'#e5e7eb', borderWidth:1, titleColor:'#111827', bodyColor:'#6b7280', callbacks:{ label: ctx => ` ${ctx.dataset.label}: ${ctx.raw}L` } } }, scales: { x: { grid: { display:false } }, y: { grid: { color:'#f3f4f6' }, ticks: { callback: v => v+'L' } } } } });

<?php if (count($breakData) > 0 && array_sum($breakData) > 0): ?>
const dColors = ['#3b82f6','#10b981','#8b5cf6','#ef4444','#f59e0b','#6b7280'];
new Chart(document.getElementById('doughnutChart').getContext('2d'), { type: 'doughnut', data: { labels: <?= $breakLabelsJson ?>, datasets: [{ data: <?= $breakDataJson ?>, backgroundColor: dColors.slice(0, <?= count($breakData) ?>), borderWidth: 0, hoverOffset: 6 }] }, options: { cutout: '68%', plugins: { legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true, pointStyleWidth: 8 } }, tooltip: { backgroundColor:'#fff', borderColor:'#e5e7eb', borderWidth:1, titleColor:'#111827', bodyColor:'#6b7280', callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw}L` } } } } });
<?php endif; ?>
</script>
</body>
<link rel="stylesheet" href="/Others/all.css">
</html>