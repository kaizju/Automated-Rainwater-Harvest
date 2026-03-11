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
<link rel="stylesheet" href="/Others/all.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Inter', sans-serif; background: #e5e7eb; color: #111827; font-size: 14px; display: flex; min-height: 100vh; overflow-x: hidden; }

  .sidebar { width: 260px; background: #0f172a; min-height: 100vh; height: 100vh; position: fixed; top: 0; left: 0; display: flex; flex-direction: column; padding: 1.5rem 1rem; flex-shrink: 0; z-index: 100; transition: transform .25s ease; overflow-y: auto; }
  .sidebar.open { transform: translateX(0) !important; }
  .sidebar-logo { display: flex; align-items: center; gap: .6rem; padding: .25rem .5rem .25rem .25rem; margin-bottom: 2rem; }
  .logo-drop { width: 34px; height: 34px; background: linear-gradient(160deg,#60a5fa 20%,#2563eb 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
  .logo-name { font-size: 1.1rem; font-weight: 700; color: #fff; }
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
  .topbar-left .page-sub { font-size: .78rem; color: #6b7280; margin-top: .1rem; }
  .topbar-right { display: flex; align-items: center; gap: .85rem; }
  .t-search { display: flex; align-items: center; gap: .5rem; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: .45rem .85rem; width: 200px; }
  .t-search svg { width: 14px; height: 14px; color: #9ca3af; flex-shrink: 0; }
  .t-search input { background: none; border: none; outline: none; font-size: .83rem; color: #111827; width: 100%; }
  .t-search input::placeholder { color: #9ca3af; }
  .t-icon { width: 36px; height: 36px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #6b7280; position: relative; }
  .t-icon svg { width: 16px; height: 16px; }
  .notif-dot { position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; background: #ef4444; border-radius: 50%; border: 1.5px solid #fff; }
  .t-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg,#3b82f6,#8b5cf6); display: flex; align-items: center; justify-content: center; color: #fff; font-size: .8rem; font-weight: 600; cursor: pointer; text-decoration: none; }

  .page-content { flex: 1; overflow-y: auto; padding: 1.75rem 2rem 3rem; display: flex; flex-direction: column; gap: 1.25rem; }

  /* HERO */
  .hero-card { background: linear-gradient(135deg,#1d4ed8 0%,#2563eb 50%,#3b82f6 100%); border-radius: 16px; padding: 1.75rem 2rem; color: #fff; position: relative; overflow: hidden; }
  .hero-card::before { content: ''; position: absolute; top: -50px; right: -50px; width: 200px; height: 200px; border-radius: 50%; background: rgba(255,255,255,.06); }
  .hero-card::after  { content: ''; position: absolute; bottom: -70px; right: 80px; width: 160px; height: 160px; border-radius: 50%; background: rgba(255,255,255,.04); }
  .hero-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.25rem; }
  .hero-date { font-size: .78rem; opacity: .75; margin-bottom: .3rem; }
  .hero-location { font-size: .95rem; font-weight: 600; }
  .hero-temp-row { display: flex; align-items: flex-end; gap: 1.5rem; margin-bottom: 1.25rem; position: relative; z-index: 1; flex-wrap: wrap; }
  .big-temp { font-family: 'Space Grotesk', sans-serif; font-size: clamp(3rem,6vw,5rem); font-weight: 700; line-height: 1; }
  .big-temp sup { font-size: 1.4rem; vertical-align: super; }
  .weather-desc-wrap { padding-bottom: .4rem; }
  .weather-desc { font-size: 1rem; opacity: .85; margin-top: .3rem; }
  .feels-like { font-size: .78rem; opacity: .65; margin-top: .2rem; }
  .hero-cloud { font-size: 5rem; opacity: .88; position: absolute; right: 2rem; top: 1.75rem; pointer-events: none; }
  .hero-pills { display: flex; gap: .6rem; flex-wrap: wrap; position: relative; z-index: 1; }
  .pill { background: rgba(255,255,255,.18); border-radius: 20px; padding: .38rem .85rem; font-size: .78rem; font-weight: 600; display: flex; align-items: center; gap: .35rem; backdrop-filter: blur(4px); }
  .pill .pval { font-size: .88rem; font-weight: 700; }

  /* ALERT */
  .alert-banner { background: #fffbeb; border: 1.5px solid #fde68a; border-radius: 12px; padding: 1rem 1.25rem; display: flex; align-items: flex-start; gap: .85rem; }
  .alert-icon { font-size: 1.25rem; flex-shrink: 0; margin-top: .05rem; }
  .alert-title { font-size: .84rem; font-weight: 700; color: #92400e; margin-bottom: .25rem; }
  .alert-desc  { font-size: .78rem; color: #78350f; line-height: 1.55; }

  /* SECTION LABEL */
  .section-label { font-size: .72rem; font-weight: 700; color: #6b7280; letter-spacing: .08em; text-transform: uppercase; margin-bottom: .65rem; }

  /* FORECAST ROW */
  .forecast-row { display: flex; gap: .65rem; overflow-x: auto; padding-bottom: .3rem; }
  .forecast-row::-webkit-scrollbar { height: 3px; }
  .forecast-row::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
  .fc-item { background: #fff; border-radius: 12px; padding: .85rem .75rem; display: flex; flex-direction: column; align-items: center; gap: .45rem; min-width: 72px; flex: 1; font-size: .75rem; color: #6b7280; font-weight: 500; border: 1px solid #e5e7eb; transition: transform .2s, box-shadow .2s; }
  .fc-item:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(37,99,235,.1); }
  .fc-item.today { background: linear-gradient(135deg,#2563eb,#3b82f6); color: #fff; border-color: transparent; }
  .fc-day  { font-size: .68rem; opacity: .8; }
  .fc-emoji { font-size: 1.4rem; }
  .fc-temp { font-size: .9rem; font-weight: 700; }
  .fc-hilo { display: flex; gap: .25rem; font-size: .68rem; }
  .fc-lo   { opacity: .55; }
  .fc-item.today .fc-lo { opacity: .65; }
  .fc-rain { font-size: .65rem; font-weight: 600; }
  .fc-item.today .fc-rain { color: rgba(255,255,255,.8); }
  .fc-rain.has-rain { color: #3b82f6; }

  /* BOTTOM GRID */
  .bottom-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
  .w-card { background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; padding: 1.2rem 1.35rem; }
  .w-card-title { font-size: .82rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
  .w-card-badge { background: #eff6ff; color: #3b82f6; border: 1px solid #dbeafe; border-radius: 20px; padding: .18rem .6rem; font-size: .68rem; font-weight: 700; text-transform: none; letter-spacing: 0; }
  .chart-wrap { position: relative; height: 140px; }
  .donut-wrap { display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap; }
  .donut-canvas-wrap { position: relative; width: 110px; height: 110px; flex-shrink: 0; }
  .donut-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); text-align: center; }
  .donut-pct { font-family: 'Space Grotesk',sans-serif; font-size: 1.4rem; font-weight: 700; color: #2563eb; }
  .donut-sub { font-size: .65rem; color: #9ca3af; }
  .donut-legend { display: flex; flex-direction: column; gap: .65rem; flex: 1; min-width: 120px; }
  .dleg-item { display: flex; align-items: center; gap: .6rem; font-size: .78rem; color: #6b7280; }
  .dleg-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
  .dleg-val { font-weight: 700; color: #111827; }

  /* RESPONSIVE */
  @media (max-width: 1000px) { .bottom-grid { grid-template-columns: 1fr; } }
  @media (max-width: 900px) {
    .sidebar { transform: translateX(-100%); }
    .main-wrap { margin-left: 0; }
    .hamburger { display: flex; }
    .t-search { display: none; }
    .topbar { padding: 0 1rem; }
    .page-content { padding: 1.25rem; gap: 1rem; }
    .hero-cloud { display: none; }
  }
  @media (max-width: 600px) {
    .page-content { padding: 1rem .85rem; }
    .hero-card { padding: 1.25rem; }
    .hero-pills { gap: .4rem; }
    .pill { padding: .3rem .65rem; font-size: .73rem; }
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
    <a href="<?php echo BASE_URL;?>/App/Dashboard/dashboard.php" class="nav-link">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Dashboard
    </a>
    <a href="<?php echo BASE_URL;?>/App/usage/usage.php" class="nav-link">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      Usage Stats
    </a>
    <a href="<?php echo BASE_URL;?>/App/weather/weather.php" class="nav-link active">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
      Weather
    </a>
    <a href="<?php echo BASE_URL;?>/App/settings/settings.php" class="nav-link">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M4.93 19.07l1.41-1.41M19.07 19.07l-1.41-1.41M12 2v2M12 20v2M2 12h2M20 12h2"/></svg>
      Settings
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
       <a href="<?php echo BASE_URL;?>/App/Users/user.php" class="t-avatar"><?= htmlspecialchars($initials) ?></a>
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