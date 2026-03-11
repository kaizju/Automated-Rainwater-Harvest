<?php
require_once '../../Connections/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../../index.php');
    exit;
}

$success = '';
$error   = '';

$pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB");

$tank = $pdo->query("SELECT * FROM tank LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

function cfg(array $rows, string $key, $default) {
    return $rows[$key] ?? $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $capacity  = (int)($_POST['tank_capacity'] ?? 5000);
        $threshold = (int)($_POST['threshold']     ?? 1000);

        if ($tank) {
            $pdo->prepare("UPDATE tank SET max_capacity = ? WHERE tank_id = ?")
                ->execute([$capacity, $tank['tank_id']]);
        }

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

        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value)
                               VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($settings as $k => $v) $stmt->execute([$k, $v]);

        $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, action, status, ip_address)
                       VALUES (?, ?, 'update_settings', 'success', ?)")
            ->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '']);

        $rows    = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $tank    = $pdo->query("SELECT * FROM tank LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $success = 'Settings saved successfully.';

    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

$me = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$me->execute([$_SESSION['user_id']]);
$me       = $me->fetch(PDO::FETCH_ASSOC);
$initials = strtoupper(substr($me['email'] ?? 'A', 0, 2));

$maxCap  = (int)cfg($rows, 'tank_capacity', $tank['max_capacity'] ?? 5000);
$threshV = (int)cfg($rows, 'threshold', 1000);
$pct     = $maxCap > 0 ? round($threshV / $maxCap * 100) : 20;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>EcoRain — Settings</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet"/>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Inter', sans-serif;
  background: #e5e7eb;
  color: #111827;
  font-size: 14px;
  display: flex;
  min-height: 100vh;
}

/* ══════════════════════════════
   SIDEBAR — matches dashboard exactly
══════════════════════════════ */
.sidebar {
  width: 260px;
  min-width: 260px;
  background: #0f172a;
  min-height: 100vh;
  height: 100vh;
  position: sticky;
  top: 0;
  display: flex;
  flex-direction: column;
  padding: 1.5rem 1rem;
  flex-shrink: 0;
}
.sidebar-logo {
  display: flex;
  align-items: center;
  gap: .65rem;
  padding: .25rem .5rem .25rem .25rem;
  margin-bottom: 2rem;
}
.logo-drop {
  width: 34px; height: 34px;
  background: linear-gradient(160deg, #60a5fa 10%, #2563eb 100%);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.05rem;
  flex-shrink: 0;
}
.logo-name {
  font-family: 'Space Grotesk', sans-serif;
  font-size: 1.1rem;
  font-weight: 700;
  color: #fff;
  letter-spacing: -.01em;
}
.nav-section {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: .1rem;
}
.nav-link {
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: .62rem .9rem;
  border-radius: 8px;
  font-size: .875rem;
  font-weight: 500;
  color: #94a3b8;
  text-decoration: none;
  transition: background .15s, color .15s;
}
.nav-link svg { width: 17px; height: 17px; flex-shrink: 0; stroke-width: 1.8; }
.nav-link:hover  { background: rgba(255,255,255,.07); color: #e2e8f0; }
.nav-link.active { background: rgba(255,255,255,.12); color: #fff; font-weight: 600; }
.sidebar-footer {
  margin-top: auto;
  padding-top: 1rem;
  border-top: 1px solid rgba(255,255,255,.08);
}
.nav-link.logout:hover { background: rgba(239,68,68,.13); color: #fca5a5; }

/* ══════════════════════════════
   MAIN WRAPPER
══════════════════════════════ */
.main-wrap {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
  overflow: hidden;
}

/* TOPBAR */
.topbar {
  height: 64px;
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 2rem;
  flex-shrink: 0;
  position: sticky;
  top: 0;
  z-index: 20;
}
.topbar-left .page-title {
  font-family: 'Space Grotesk', sans-serif;
  font-size: 1.25rem;
  font-weight: 700;
  color: #111827;
}
.topbar-left .page-sub { font-size: .78rem; color: #6b7280; margin-top: .1rem; }
.topbar-right { display: flex; align-items: center; gap: .85rem; }
.t-search {
  display: flex; align-items: center; gap: .5rem;
  background: #f9fafb; border: 1px solid #e5e7eb;
  border-radius: 8px; padding: .45rem .85rem; width: 200px;
}
.t-search svg { width: 14px; height: 14px; color: #9ca3af; flex-shrink: 0; }
.t-search input {
  background: none; border: none; outline: none;
  font-size: .83rem; font-family: 'Inter', sans-serif;
  color: #111827; width: 100%;
}
.t-search input::placeholder { color: #9ca3af; }
.t-icon {
  width: 36px; height: 36px;
  border: 1px solid #e5e7eb; border-radius: 8px;
  background: #fff; display: flex; align-items: center; justify-content: center;
  cursor: pointer; color: #6b7280; position: relative;
}
.t-icon svg { width: 16px; height: 16px; }
.notif-dot {
  position: absolute; top: 6px; right: 6px;
  width: 7px; height: 7px; background: #ef4444;
  border-radius: 50%; border: 1.5px solid #fff;
}
.t-avatar {
  width: 36px; height: 36px; border-radius: 50%;
  background: linear-gradient(135deg, #3b82f6, #8b5cf6);
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: .8rem; font-weight: 600; cursor: pointer;
}

/* ══════════════════════════════
   PAGE CONTENT
══════════════════════════════ */
.page-content {
  flex: 1;
  overflow-y: auto;
  padding: 1.75rem 2rem 3rem;
}

/* flash alerts */
.flash {
  display: flex; align-items: center; gap: .6rem;
  padding: .8rem 1rem; border-radius: 10px;
  font-size: .84rem; font-weight: 500; margin-bottom: 1.25rem;
}
.flash-ok  { background: #f0fdf4; border: 1px solid #bbf7d0; color: #16a34a; }
.flash-err { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }

/* ══════════════════════════════
   SETTINGS CARDS
══════════════════════════════ */
.s-card {
  background: #fff;
  border-radius: 16px;
  border: 1px solid #e5e7eb;
  margin-bottom: 1.1rem;
  overflow: hidden;
}
.s-card-head {
  display: flex; align-items: center; gap: .75rem;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid #f3f4f6;
}
.s-card-icon {
  width: 34px; height: 34px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: .95rem; flex-shrink: 0;
}
.icon-blue   { background: #eff6ff; }
.icon-green  { background: #f0fdf4; }
.icon-yellow { background: #fffbeb; }
.icon-purple { background: #f5f3ff; }
.icon-slate  { background: #f8fafc; }

.s-card-title { font-size: .9rem; font-weight: 700; color: #111827; }
.s-card-sub   { font-size: .73rem; color: #9ca3af; margin-top: .08rem; }
.s-card-body  { padding: 1.25rem 1.5rem 1.5rem; }

/* grids */
.fg2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.1rem; }
.fg1 { display: grid; grid-template-columns: 1fr; gap: 1.1rem; }
.mb  { margin-bottom: 1.1rem; }

/* field */
.field { display: flex; flex-direction: column; gap: .38rem; }
.field > label {
  font-size: .72rem; font-weight: 600;
  letter-spacing: .06em; text-transform: uppercase; color: #6b7280;
}

/* inputs */
.f-input, .f-select {
  background: #f9fafb; border: 1px solid #e5e7eb;
  border-radius: 8px; padding: .6rem .85rem;
  font-size: .875rem; font-family: 'Inter', sans-serif;
  color: #111827; outline: none; width: 100%;
  transition: border-color .15s, box-shadow .15s;
}
.f-input:focus, .f-select:focus {
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59,130,246,.1);
}
.f-input[readonly] { background: #f3f4f6; color: #9ca3af; cursor: not-allowed; }
.f-select {
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 12px center;
  padding-right: 2.2rem; cursor: pointer;
}

/* slider */
.slider-wrap { display: flex; align-items: center; gap: .75rem; }
.slider-wrap input[type=range] {
  flex: 1; -webkit-appearance: none;
  height: 5px; border-radius: 3px; outline: none; cursor: pointer;
  background: linear-gradient(to right,
    #3b82f6 0%, #3b82f6 var(--val, 20%),
    #e5e7eb var(--val, 20%), #e5e7eb 100%);
}
.slider-wrap input[type=range]::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 17px; height: 17px; border-radius: 50%;
  background: #3b82f6; border: 2.5px solid #fff;
  box-shadow: 0 1px 5px rgba(59,130,246,.4); cursor: pointer;
}
.slider-lbl {
  font-size: .78rem; font-weight: 600; color: #3b82f6;
  min-width: 58px; text-align: right;
  background: #eff6ff; border-radius: 6px; padding: .25rem .55rem;
}

/* divider */
.row-divider { border: none; border-top: 1px solid #f3f4f6; margin: 1rem 0; }

/* toggle row */
.tog-row {
  display: flex; align-items: center;
  justify-content: space-between;
  padding: .88rem 0;
  border-bottom: 1px solid #f3f4f6;
}
.tog-row:last-child { border-bottom: none; padding-bottom: 0; }
.tog-row:first-child { padding-top: 0; }
.tog-info strong { font-size: .875rem; font-weight: 500; color: #111827; display: block; }
.tog-info span   { font-size: .75rem; color: #9ca3af; margin-top: .12rem; display: block; line-height: 1.4; }

/* toggle switch */
.tog { position: relative; width: 42px; height: 24px; flex-shrink: 0; cursor: pointer; }
.tog input { opacity: 0; width: 0; height: 0; position: absolute; }
.tog-track {
  position: absolute; inset: 0;
  background: #d1d5db; border-radius: 12px; transition: background .2s; cursor: pointer;
}
.tog-thumb {
  position: absolute; top: 2px; left: 2px;
  width: 20px; height: 20px; background: #fff; border-radius: 50%;
  box-shadow: 0 1px 4px rgba(0,0,0,.2); transition: transform .2s; pointer-events: none;
}
.tog input:checked ~ .tog-track { background: #22c55e; }
.tog input:checked ~ .tog-thumb { transform: translateX(18px); }

/* pH range pair */
.ph-wrap { display: flex; align-items: center; gap: .6rem; }
.ph-wrap .f-input { text-align: center; }
.ph-dash { color: #9ca3af; font-size: 1rem; font-weight: 500; flex-shrink: 0; }

/* save bar */
.save-bar {
  display: flex; align-items: center; justify-content: flex-end;
  gap: .85rem; margin-top: .5rem;
}
.btn-discard {
  background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
  padding: .65rem 1.5rem; font-size: .875rem; font-weight: 500;
  color: #6b7280; cursor: pointer; font-family: 'Inter', sans-serif;
  transition: border-color .15s, color .15s;
}
.btn-discard:hover { border-color: #94a3b8; color: #374151; }
.btn-save {
  background: #3b82f6; color: #fff; border: none; border-radius: 8px;
  padding: .65rem 2rem; font-size: .875rem; font-weight: 600;
  font-family: 'Inter', sans-serif; cursor: pointer;
  display: flex; align-items: center; gap: .5rem;
  transition: background .15s, transform .1s, box-shadow .15s;
}
.btn-save:hover  { background: #2563eb; box-shadow: 0 4px 14px rgba(59,130,246,.35); transform: translateY(-1px); }
.btn-save:active { transform: translateY(0); box-shadow: none; }
.btn-save svg { width: 15px; height: 15px; }

/* toast */
.toast {
  position: fixed; bottom: 24px; right: 24px;
  background: #0f172a; color: #fff;
  padding: .9rem 1.25rem; border-radius: 12px;
  font-size: .84rem; font-weight: 500;
  display: flex; align-items: center; gap: .7rem;
  box-shadow: 0 8px 24px rgba(0,0,0,.18);
  transform: translateY(70px) scale(.95); opacity: 0;
  transition: transform .32s cubic-bezier(.34,1.56,.64,1), opacity .32s;
  z-index: 999; pointer-events: none;
}
.toast.show { transform: translateY(0) scale(1); opacity: 1; }

@media (max-width: 900px) {
  .sidebar       { display: none; }
  .fg2           { grid-template-columns: 1fr; }
  .page-content  { padding: 1.25rem; }
  .topbar        { padding: 0 1rem; }
  .t-search      { display: none; }
}
</style>
</head>
<body>

<!-- ══════════════ SIDEBAR ══════════════ -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-drop">💧</div>
    <span class="logo-name">EcoRain</span>
  </div>

  <nav class="nav-section">
    <a href="<?php echo BASE_URL;?>/App/Dashboard/dashboard.php" class="nav-link">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <rect x="3" y="3" width="7" height="7" rx="1"/>
        <rect x="14" y="3" width="7" height="7" rx="1"/>
        <rect x="3" y="14" width="7" height="7" rx="1"/>
        <rect x="14" y="14" width="7" height="7" rx="1"/>
      </svg>
      Dashboard
    </a>
    <a href="<?php echo BASE_URL;?>/App/usage/usage.php" class="nav-link">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
      </svg>
      Usage Stats
    </a>
    <a href="<?php echo BASE_URL;?>/App/weather/weather.php" class="nav-link">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
      </svg>
      Weather
    </a>
    <a href="<?php echo BASE_URL;?>/App/settings/settings.php" class="nav-link ">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <circle cx="12" cy="12" r="3"/>
        <path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M4.93 19.07l1.41-1.41M19.07 19.07l-1.41-1.41M12 2v2M12 20v2M2 12h2M20 12h2"/>
      </svg>
      Settings
    </a>
  </nav>

  <div class="sidebar-footer">
    <a href="<?php echo BASE_URL; ?>/Connections/signout.php" class="nav-link logout">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>
      </svg>
      Log Out
    </a>
  </div>
</aside>

<!-- ══════════════ MAIN ══════════════ -->
<div class="main-wrap">

  <header class="topbar">
    <div class="topbar-left">
      <div class="page-title">Settings</div>
      <div class="page-sub">Configure your EcoRain System</div>
    </div>
    <div class="topbar-right">
      <div class="t-search">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="text" placeholder="Search..."/>
      </div>
      <div class="t-icon">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
          <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 01-3.46 0"/>
        </svg>
        <span class="notif-dot"></span>
      </div>
      <div class="t-avatar"><?= htmlspecialchars($initials) ?></div>
    </div>
  </header>

  <div class="page-content">

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

      <!-- TANK CONFIGURATION -->
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
                     value="<?= (int)cfg($rows,'tank_capacity',$tank['max_capacity'] ?? 5000) ?>"
                     min="100" step="100"/>
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
              <input type="checkbox" name="overflow_prevention"
                     <?= cfg($rows,'overflow_prevention','1')==='1' ? 'checked' : '' ?>/>
              <div class="tog-track"></div>
              <div class="tog-thumb"></div>
            </label>
          </div>
        </div>
      </div>

      <!-- PUMP SETTINGS -->
      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-icon icon-green">⚙️</div>
          <div>
            <div class="s-card-title">Pump Settings</div>
            <div class="s-card-sub">Control automation and scheduling</div>
          </div>
        </div>
        <div class="s-card-body">
          <div class="tog-row">
            <div class="tog-info">
              <strong>Auto Mode</strong>
              <span>Pump operates based on demand and weather conditions</span>
            </div>
            <label class="tog">
              <input type="checkbox" name="pump_auto"
                     <?= cfg($rows,'pump_auto','1')==='1' ? 'checked' : '' ?>/>
              <div class="tog-track"></div>
              <div class="tog-thumb"></div>
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
              <input class="f-input" type="number" name="pump_wattage"
                     value="<?= (int)cfg($rows,'pump_wattage',100) ?>" min="0"/>
            </div>
          </div>
        </div>
      </div>

      <!-- NOTIFICATIONS -->
      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-icon icon-yellow">🔔</div>
          <div>
            <div class="s-card-title">Notifications</div>
            <div class="s-card-sub">Choose which alerts and reports to receive</div>
          </div>
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
            <div class="tog-info">
              <strong><?= $lbl ?></strong>
              <span><?= $desc ?></span>
            </div>
            <label class="tog">
              <input type="checkbox" name="<?= $name ?>" <?= $chk ? 'checked' : '' ?>/>
              <div class="tog-track"></div>
              <div class="tog-thumb"></div>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- WATER QUALITY ALERTS -->
      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-icon icon-purple">💧</div>
          <div>
            <div class="s-card-title">Water Quality Alerts</div>
            <div class="s-card-sub">pH, TDS thresholds and testing schedule</div>
          </div>
        </div>
        <div class="s-card-body">
          <div class="fg2 mb">
            <div class="field">
              <label>pH Range (Min – Max)</label>
              <div class="ph-wrap">
                <input class="f-input" type="number" name="ph_min" step="0.1" min="0" max="14"
                       value="<?= cfg($rows,'ph_min','6.5') ?>"/>
                <span class="ph-dash">—</span>
                <input class="f-input" type="number" name="ph_max" step="0.1" min="0" max="14"
                       value="<?= cfg($rows,'ph_max','8.5') ?>"/>
              </div>
            </div>
            <div class="field">
              <label>TDS Threshold (ppm)</label>
              <input class="f-input" type="number" name="tds_threshold"
                     value="<?= (int)cfg($rows,'tds_threshold',100) ?>" min="0"/>
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

      <!-- ACCOUNT -->
      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-icon icon-slate">👤</div>
          <div>
            <div class="s-card-title">Account</div>
            <div class="s-card-sub">Email, timezone and role preferences</div>
          </div>
        </div>
        <div class="s-card-body">
          <div class="fg2 mb">
            <div class="field">
              <label>Email Address</label>
              <input class="f-input" type="email" name="account_email"
                     value="<?= htmlspecialchars(cfg($rows,'account_email',$me['email'] ?? '')) ?>"/>
            </div>
            <div class="field">
              <label>Role</label>
              <input class="f-input" type="text"
                     value="<?= ucfirst($me['role'] ?? 'admin') ?>" readonly/>
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

      <!-- Save Bar -->
      <div class="save-bar">
        <button type="button" class="btn-discard" onclick="window.location.reload()">Discard</button>
        <button type="submit" class="btn-save">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          Save Changes
        </button>
      </div>

    </form>
  </div><!-- /page-content -->
</div><!-- /main-wrap -->

<!-- Toast -->
<div class="toast" id="toast">✅&nbsp; Settings saved successfully</div>

<script>
function updateSlider(el, valId) {
  const max = parseInt(el.max) || 5000;
  const val = parseInt(el.value);
  el.style.setProperty('--val', Math.round(val / max * 100) + '%');
  document.getElementById(valId).textContent = val.toLocaleString() + 'L';
}

document.getElementById('tankCapacity').addEventListener('input', function () {
  const s = document.getElementById('threshold');
  const newMax = parseInt(this.value) || 5000;
  if (parseInt(s.value) > newMax) s.value = newMax;
  s.max = newMax;
  updateSlider(s, 'thresholdVal');
});

<?php if ($success): ?>
(function () {
  const t = document.getElementById('toast');
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3200);
})();
<?php endif; ?>

setTimeout(() => {
  document.querySelectorAll('.flash').forEach(a => {
    a.style.transition = 'opacity .5s';
    a.style.opacity = '0';
    setTimeout(() => a.remove(), 500);
  });
}, 4000);
</script>
</body>
</html>