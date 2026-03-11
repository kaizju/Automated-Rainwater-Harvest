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

// Helpers
function phLabel($v)    { return $v == 0 ? 'None' : ($v < 6.5 ? 'Low' : ($v > 8.5 ? 'High' : 'Optimal')); }
function phColor($v)    { return ($v == 0 || ($v >= 6.5 && $v <= 8.5)) ? '#16a34a' : '#ef4444'; }
function turbLabel($v)  { return $v == 0 ? 'None' : ($v > 4 ? 'Poor' : ($v > 1 ? 'Moderate' : 'Excellent')); }
function turbColor($v)  { return ($v == 0 || $v <= 1) ? '#16a34a' : ($v <= 4 ? '#d97706' : '#ef4444'); }
function qualityLabel($q) { return $q ? $q['quality_status'] : 'N/A'; }

// Tank card color based on level
$tankBg = $percent < 20 ? '#fca5a5' : ($percent < 50 ? '#fde68a' : '#93c5fd');
$tankBgDark = $percent < 20 ? '#fecaca' : ($percent < 50 ? '#fef3c7' : '#bfdbfe');

// Updated how long ago
$updatedAgo = 'N/A';
if ($quality) {
    $diff = time() - strtotime($quality['recorded_at']);
    if ($diff < 60) $updatedAgo = $diff . 's ago';
    elseif ($diff < 3600) $updatedAgo = floor($diff/60) . 'm ago';
    else $updatedAgo = floor($diff/3600) . 'h ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EcoRain — Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: #e5e7eb;
    color: #111827;
    font-family: 'Inter', sans-serif;
    min-height: 100vh;
    display: flex;
  }

  /* ── SIDEBAR ── */
  .sidebar {
    width: 260px;
    min-height: 100vh;
    background: #0f172a;
    display: flex;
    flex-direction: column;
    padding: 1.75rem 1.25rem;
    position: sticky;
    top: 0;
    height: 100vh;
    flex-shrink: 0;
    z-index: 10;
  }

  .logo {
    display: flex;
    align-items: center;
    gap: .65rem;
    margin-bottom: 2.25rem;
    padding-left: .25rem;
  }
  .logo-icon { font-size: 1.5rem; }
  .logo-text {
    font-size: 1.15rem;
    font-weight: 700;
    color: #fff;
    letter-spacing: -.01em;
  }

  .nav-item {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .65rem .85rem;
    border-radius: 8px;
    font-size: .9rem;
    font-weight: 500;
    color: #94a3b8;
    text-decoration: none;
    margin-bottom: .2rem;
    transition: all .15s;
  }
  .nav-item svg { width: 18px; height: 18px; flex-shrink: 0; }
  .nav-item:hover { background: rgba(255,255,255,.06); color: #e2e8f0; }
  .nav-item.active { background: rgba(255,255,255,.1); color: #fff; font-weight: 600; }

  .sidebar-bottom {
    margin-top: auto;
    padding-top: 1.25rem;
    border-top: 1px solid #1e293b;
  }
  .nav-item.logout:hover { background: rgba(239,68,68,.12); color: #fca5a5; }

  /* ── MAIN ── */
  .main {
    flex: 1;
    overflow-y: auto;
    padding: 2rem 2rem 3rem;
  }

  /* Page header */
  .page-header { margin-bottom: 1.75rem; }
  .page-header h1 {
    font-size: 2rem;
    font-weight: 700;
    color: #111827;
    letter-spacing: -.02em;
  }
  .page-header p { color: #6b7280; font-size: .9rem; margin-top: .2rem; }

  /* ── TOP ROW: 3 columns ── */
  .top-row {
    display: grid;
    grid-template-columns: 380px 1fr 1fr;
    gap: 1.25rem;
    margin-bottom: 1.25rem;
    align-items: start;
  }

  /* ── TANK CARD ── */
  .tank-card {
    border-radius: 16px;
    padding: 1.75rem;
    min-height: 380px;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
  }
  .tank-header {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-size: .95rem;
    font-weight: 600;
    color: #1e3a5f;
    margin-bottom: 1.5rem;
  }
  .tank-header svg { width: 20px; height: 20px; }

  .tank-percent-big {
    font-size: 4rem;
    font-weight: 800;
    color: #111827;
    line-height: 1;
    margin-bottom: auto;
  }
  .tank-liters-row {
    font-size: 1.1rem;
    font-weight: 500;
    color: #374151;
    margin-bottom: .75rem;
    margin-top: 2rem;
  }
  .tank-bar-bg {
    background: rgba(255,255,255,.55);
    border-radius: 99px;
    height: 8px;
    overflow: hidden;
    margin-bottom: .75rem;
  }
  .tank-bar-fill {
    height: 100%;
    border-radius: 99px;
    background: #fff;
    transition: width .8s ease;
  }
  .tank-collected {
    font-size: .85rem;
    color: #374151;
    display: flex;
    align-items: center;
    gap: .35rem;
  }

  /* ── WHITE CARD ── */
  .card {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #e5e7eb;
    padding: 1.5rem;
  }
  .card-section-label {
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: #6b7280;
    margin-bottom: .75rem;
  }

  /* ── WATER QUALITY ── */
  .wq-top {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin-bottom: 1.1rem;
    flex-wrap: wrap;
  }
  .wq-badge {
    background: #e5e7eb;
    color: #374151;
    font-size: .78rem;
    font-weight: 600;
    padding: .25rem .65rem;
    border-radius: 6px;
  }
  .wq-updated { font-size: .78rem; color: #9ca3af; }

  .wq-metric {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.1rem 1.25rem;
    margin-bottom: .75rem;
  }
  .wq-metric:last-child { margin-bottom: 0; }
  .wq-metric-header {
    display: flex;
    align-items: center;
    gap: .4rem;
    font-size: .82rem;
    color: #6b7280;
    margin-bottom: .5rem;
  }
  .wq-metric-header svg { width: 16px; height: 16px; }
  .wq-metric-value {
    font-size: 2rem;
    font-weight: 700;
    color: #111827;
    line-height: 1;
    margin-bottom: .35rem;
  }
  .wq-metric-status {
    font-size: .82rem;
    font-weight: 600;
  }

  /* ── CHART CARD ── */
  .chart-title {
    font-size: .9rem;
    font-weight: 600;
    color: #111827;
    margin-bottom: 1rem;
  }
  .chart-wrap { height: 220px; position: relative; }

  /* ── FORECAST CARD ── */
  .forecast-card {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #e5e7eb;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.25rem;
  }
  .forecast-title {
    font-size: .9rem;
    font-weight: 600;
    color: #111827;
    margin-bottom: 1rem;
    padding-bottom: .75rem;
    border-bottom: 1px solid #f3f4f6;
  }
  .forecast-inner {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
  }
  .forecast-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #f3f4f6;
  }
  .forecast-row:last-child { border-bottom: none; }
  .forecast-icon { font-size: 1.8rem; width: 44px; text-align: center; flex-shrink: 0; }
  .forecast-day  { font-size: .95rem; font-weight: 600; color: #111827; }
  .forecast-pct  { font-size: .82rem; color: #9ca3af; margin-top: .1rem; }
  .forecast-right { margin-left: auto; text-align: right; }
  .forecast-predicted { font-size: .95rem; font-weight: 700; color: #111827; }
  .forecast-lbl { font-size: .75rem; color: #9ca3af; }

  /* ── BOTTOM ROW ── */
  .bottom-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
  }

  /* ── SENSOR / ACTIVITY TABLES ── */
  .mini-table { width: 100%; border-collapse: collapse; font-size: .84rem; margin-top: .5rem; }
  .mini-table th {
    font-size: .7rem; font-weight: 600; letter-spacing: .07em;
    text-transform: uppercase; color: #9ca3af;
    padding: 0 0 .6rem; text-align: left;
    border-bottom: 1px solid #f3f4f6;
  }
  .mini-table td { padding: .6rem 0; border-bottom: 1px solid #f3f4f6; vertical-align: middle; color: #374151; }
  .mini-table tr:last-child td { border-bottom: none; }
  .mini-table td:last-child { text-align: right; color: #9ca3af; font-size: .76rem; }
  .s-badge {
    display: inline-block;
    padding: .2rem .55rem;
    border-radius: 6px;
    font-size: .74rem;
    font-weight: 600;
    background: #f0fdf4;
    color: #16a34a;
    border: 1px solid #bbf7d0;
  }
  .u-name { color: #2563eb; font-weight: 600; }

  /* ── RESPONSIVE ── */
  @media (max-width: 1280px) {
    .top-row { grid-template-columns: 340px 1fr 1fr; }
  }
  @media (max-width: 1100px) {
    .top-row { grid-template-columns: 1fr 1fr; }
    .bottom-row { grid-template-columns: 1fr; }
  }
  @media (max-width: 768px) {
    .sidebar { display: none; }
    .main { padding: 1.25rem; }
    .top-row { grid-template-columns: 1fr; }
    .bottom-row { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar">
  <div class="logo">
    <span class="logo-icon">💧</span>
    <span class="logo-text">EcoRain</span>
  </div>

  <a href="<?= BASE_URL ?>/Dashboard/index.php" class="nav-item active">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
    Dashboard
  </a>
  <a href="#" class="nav-item">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3z" opacity="0"/><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    Usage Stats
  </a>
  <a href="#" class="nav-item">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
    Weather
  </a>
  <a href="#" class="nav-item">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
    Settings
  </a>

  <div class="sidebar-bottom">
    <a href="#" class="nav-item logout">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      Log Out
    </a>
  </div>
</aside>

<!-- ── MAIN ── -->
<div class="main">

  <div class="page-header">
    <h1>DashBoard</h1>
    <p>Welcome to EcoRain</p>
  </div>

  <!-- TOP ROW -->
  <div class="top-row">

    <!-- Tank Card -->
    <div class="tank-card" style="background:<?= $tankBg ?>">
      <div class="tank-header">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>
        Main Tank Level
      </div>

      <div class="tank-percent-big"><?= $percent ?>%</div>

      <div>
        <div class="tank-liters-row">
          <?= $tank ? number_format($tank['max_capacity']) : '5,000' ?>L
        </div>
        <div class="tank-bar-bg">
          <div class="tank-bar-fill" style="width:<?= $percent ?>%"></div>
        </div>
        <div class="tank-collected">
          <?= number_format($todayCollected, 0) ?>L collected today
        </div>
      </div>
    </div>

    <!-- Water Quality -->
    <div class="card">
      <div class="card-section-label">Water Quality</div>
      <div class="wq-top">
        <span class="wq-badge"><?= $quality ? htmlspecialchars($quality['quality_status']) : 'N/A' ?></span>
        <span class="wq-updated">updated <?= $updatedAgo ?></span>
      </div>

      <!-- pH -->
      <div class="wq-metric">
        <div class="wq-metric-header">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          pH Level
        </div>
        <div class="wq-metric-value"><?= $quality ? $quality['ph_level'] : '0' ?></div>
        <div class="wq-metric-status" style="color:<?= $quality ? phColor($quality['ph_level']) : '#16a34a' ?>">
          <?= $quality ? phLabel($quality['ph_level']) : 'None' ?>
        </div>
      </div>

      <!-- Turbidity -->
      <div class="wq-metric">
        <div class="wq-metric-header">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8.56 2.75c4.37 6.03 6.02 9.42 8.03 17.72m2.54-15.38c-3.72 4.35-8.94 5.66-16.88 5.85m19.5 1.9c-3.5-.93-6.63-.82-8.94 0-2.58.92-5.01 2.86-7.44 6.32"/></svg>
          Turbidity
        </div>
        <div class="wq-metric-value"><?= $quality ? $quality['turbidity'] : '0' ?></div>
        <div class="wq-metric-status" style="color:<?= $quality ? turbColor($quality['turbidity']) : '#16a34a' ?>">
          <?= $quality ? turbLabel($quality['turbidity']) : 'None' ?>
        </div>
      </div>
    </div>

    <!-- Water Usage Chart -->
    <div class="card">
      <div class="chart-title">Water Usage - Last 7 Days</div>
      <div class="chart-wrap">
        <canvas id="bar-chart"></canvas>
      </div>
    </div>

  </div><!-- /top-row -->

  <!-- RAINFALL FORECAST (full width left + middle) -->
  <div style="display:grid; grid-template-columns: 380px 1fr 1fr; gap:1.25rem; margin-bottom:1.25rem; align-items:start;">

    <!-- Forecast spans left column -->
    <div style="grid-column: 1 / 3;">
      <div class="forecast-card">
        <div class="forecast-title" id="wx-location">Rainfall Forecast</div>
        <div id="wx-error" style="display:none;color:#ef4444;font-size:.83rem;margin-bottom:.5rem"></div>
        <div id="forecastSection" style="display:none">
          <div class="forecast-inner" id="rainfallForecast"></div>
        </div>
        <div id="wx-loading" style="color:#9ca3af;font-size:.84rem">Loading forecast…</div>
      </div>
    </div>

    <!-- Sensor Readings -->
    <div class="card">
      <div class="card-section-label">Sensor Readings</div>
      <?php if ($sensors): ?>
      <table class="mini-table">
        <thead><tr><th>Sensor</th><th>Model</th><th>Anomaly</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($sensors as $s): ?>
          <tr>
            <td><?= htmlspecialchars($s['sensor_type']) ?></td>
            <td style="color:#9ca3af"><?= htmlspecialchars($s['model']) ?></td>
            <td><span class="s-badge"><?= htmlspecialchars($s['anomaly']) ?></span></td>
            <td><?= date('H:i', strtotime($s['recorded_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <p style="color:#9ca3af;font-size:.85rem;margin-top:.5rem">No readings yet.</p>
      <?php endif; ?>
    </div>

  </div>

  <!-- BOTTOM ROW -->
  <div class="bottom-row">

    <!-- Activity Log -->
    <div class="card">
      <div class="card-section-label">Activity Log</div>
      <?php if ($activities): ?>
      <table class="mini-table">
        <thead><tr><th>User</th><th>Action</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($activities as $a): ?>
          <tr>
            <td class="u-name">User #<?= htmlspecialchars($a['user_id']) ?></td>
            <td><?= htmlspecialchars($a['action']) ?></td>
            <td><?= date('M j, H:i', strtotime($a['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <p style="color:#9ca3af;font-size:.85rem;margin-top:.5rem">No activity yet.</p>
      <?php endif; ?>
    </div>

    <!-- Tank Info Summary -->
    <div class="card">
      <div class="card-section-label">Tank Summary</div>
      <?php if ($tank): ?>
      <table class="mini-table">
        <tbody>
          <tr>
            <td style="color:#6b7280">Tank Name</td>
            <td style="text-align:right;font-weight:600"><?= htmlspecialchars($tank['tankname']) ?></td>
          </tr>
          <tr>
            <td style="color:#6b7280">Location</td>
            <td style="text-align:right;font-weight:600"><?= htmlspecialchars($tank['location_add']) ?></td>
          </tr>
          <tr>
            <td style="color:#6b7280">Current Volume</td>
            <td style="text-align:right;font-weight:600"><?= number_format($tank['current_liters']) ?>L</td>
          </tr>
          <tr>
            <td style="color:#6b7280">Max Capacity</td>
            <td style="text-align:right;font-weight:600"><?= number_format($tank['max_capacity']) ?>L</td>
          </tr>
          <tr>
            <td style="color:#6b7280">Fill Level</td>
            <td style="text-align:right;font-weight:600"><?= $percent ?>%</td>
          </tr>
          <tr>
            <td style="color:#6b7280">Status</td>
            <td style="text-align:right">
              <span class="s-badge"><?= htmlspecialchars($tank['status_tank']) ?></span>
            </td>
          </tr>
        </tbody>
      </table>
      <?php else: ?>
        <p style="color:#9ca3af;font-size:.85rem;margin-top:.5rem">No tank data.</p>
      <?php endif; ?>
    </div>

  </div><!-- /bottom-row -->

</div><!-- /main -->

<script>
// ── Bar Chart ──
new Chart(document.getElementById('bar-chart').getContext('2d'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [{
      label: 'RainWater Collection',
      data: <?= json_encode($chartData) ?>,
      backgroundColor: '#3b82f6',
      borderWidth: 0,
      borderRadius: 4,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        display: true,
        position: 'top',
        align: 'end',
        labels: {
          font: { size: 11, family: 'Inter' },
          color: '#6b7280',
          boxWidth: 24,
          boxHeight: 10,
          borderRadius: 3,
          useBorderRadius: true,
        }
      }
    },
    scales: {
      x: {
        grid: { color: '#f3f4f6' },
        ticks: { color: '#9ca3af', font: { family: 'Inter', size: 11 } }
      },
      y: {
        beginAtZero: true,
        grid: { color: '#f3f4f6' },
        ticks: { color: '#9ca3af', font: { family: 'Inter', size: 11 } }
      }
    }
  }
});

// ── Rainfall Forecast ──
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
  if (hr)         return Math.min(Math.round(h * 0.7 + c * 0.3), 95);
  if (h>80&&c>70) return Math.round((h+c)/2*0.5);
  if (h>70)       return Math.round(h*0.3);
  return Math.round(c*0.2);
}

async function loadForecast() {
  try {
    const res = await fetch(`https://api.openweathermap.org/data/2.5/forecast?lat=${WX.lat}&lon=${WX.lon}&appid=${WX.key}&units=metric`);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();

    document.getElementById('wx-location').textContent = `Rainfall Forecast - ${data.city.name}, ${data.city.country}`;
    document.getElementById('wx-loading').style.display = 'none';

    const daily = {};
    data.list.forEach(item => {
      const key = new Date(item.dt*1000).toLocaleDateString('en-US',{weekday:'long',month:'short',day:'numeric'});
      if (!daily[key]) daily[key] = {
        name: new Date(item.dt*1000).toLocaleDateString('en-US',{weekday:'long'}),
        rain:[], chance:[], desc: item.weather[0].description
      };
      daily[key].rain.push(item.rain ? (item.rain['3h']||0) : 0);
      daily[key].chance.push(rainChance(item));
    });

    const html = Object.keys(daily).slice(0,3).map((k,i) => {
      const total  = daily[k].rain.reduce((a,b)=>a+b,0);
      const avg    = Math.round(daily[k].chance.reduce((a,b)=>a+b,0) / daily[k].chance.length);
      const label  = i===0 ? 'Today' : i===1 ? 'Tomorrow' : daily[k].name.slice(0,3);
      const predicted = `+${Math.round(total * 10)}L`;
      return `
        <div class="forecast-row">
          <div class="forecast-icon">${wxIcon(daily[k].desc, total)}</div>
          <div>
            <div class="forecast-day">${label}</div>
            <div class="forecast-pct">${avg}% chance</div>
          </div>
          <div class="forecast-right">
            <div class="forecast-predicted">${predicted}</div>
            <div class="forecast-lbl">predicted</div>
          </div>
        </div>`;
    }).join('');

    document.getElementById('rainfallForecast').innerHTML = html;
    document.getElementById('forecastSection').style.display = 'block';
  } catch(e) {
    document.getElementById('wx-loading').style.display = 'none';
    document.getElementById('wx-error').style.display = 'block';
    document.getElementById('wx-error').textContent = 'Weather unavailable: ' + e.message;
    document.getElementById('wx-location').textContent = 'Rainfall Forecast';
  }
}
loadForecast();
</script>
</body>
</html>