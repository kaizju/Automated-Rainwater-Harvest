<?php
require_once '../../connections/config.php';
require_once '../../connections/functions.php';

requireRole('admin');
logPageVisit('Admin Settings', 'Settings');

$activePage = 'Settings';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../../index.php');
    exit;
}

$activePage = 'settings';
$success    = '';
$error      = '';

$pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB");

$tank = $pdo->query("SELECT * FROM tank LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$allTanks = $pdo->query("SELECT tank_id, tankname, location_add, status_tank FROM tank ORDER BY tank_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

function cfg(array $rows, string $key, $default) {
    return $rows[$key] ?? $default;
}

/* ── Handle Delete Tank POST ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tank'])) {
    try {
        $tankId = (int)($_POST['tank_id'] ?? 0);
        if ($tankId <= 0) throw new Exception('Invalid tank ID.');

        $stmt = $pdo->prepare("DELETE FROM tank WHERE tank_id = ?");
        $stmt->execute([$tankId]);

        if ($stmt->rowCount() === 0) throw new Exception('Tank not found or already deleted.');

        $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, role, action, status, ip_address)
                       VALUES (?, ?, ?, 'delete_tank', 'success', ?)")
            ->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '', $_SESSION['role'] ?? 'admin', $_SERVER['REMOTE_ADDR'] ?? '']);

        $success = 'Tank deleted successfully.';
        $allTanks = $pdo->query("SELECT tank_id, tankname, location_add, status_tank FROM tank ORDER BY tank_id ASC")->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/* ── Handle Add Tank POST ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tank'])) {
    try {
        $tankname      = trim($_POST['tankname']        ?? '');
        $location_add  = trim($_POST['location_add']    ?? '');
        $currentLiters = (int)($_POST['current_liters'] ?? 0);
        $maxCapacity   = (int)($_POST['max_capacity']   ?? 0);
        $statusTank    = trim($_POST['status_tank']     ?? 'Active');

        if ($tankname     === '') throw new Exception('Tank name is required.');
        if ($location_add === '') throw new Exception('Location is required.');
        if ($maxCapacity  <= 0)   throw new Exception('Max capacity must be greater than 0.');

        $pdo->prepare("INSERT INTO tank (tankname, location_add, current_liters, max_capacity, status_tank)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([$tankname, $location_add, $currentLiters, $maxCapacity, $statusTank]);

        $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, role, action, status, ip_address)
                       VALUES (?, ?, ?, 'add_tank', 'success', ?)")
            ->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '', $_SESSION['role'] ?? 'admin', $_SERVER['REMOTE_ADDR'] ?? '']);

        $success  = 'Tank added successfully.';
        $tank     = $pdo->query("SELECT * FROM tank LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $allTanks = $pdo->query("SELECT tank_id, tankname, location_add, status_tank FROM tank ORDER BY tank_id ASC")->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/* ── Handle Settings POST ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['add_tank']) && !isset($_POST['delete_tank'])) {
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

        $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, role, action, status, ip_address)
                       VALUES (?, ?, ?, 'update_settings', 'success', ?)")
            ->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '', $_SESSION['role'] ?? 'admin', $_SERVER['REMOTE_ADDR'] ?? '']);

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
$initials = strtoupper(substr($me['username'] ?? $me['email'] ?? 'A', 0, 2));

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
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/Others/all.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&family=DM+Mono:wght@400;500&family=Barlow+Condensed:wght@400;600;700&display=swap" rel="stylesheet"/>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; background: #e5e7eb; color: #111827; font-size: 14px; display: flex; min-height: 100vh; overflow-x: hidden; }

.sidebar { width: 260px; min-width: 260px; background: #0f172a; min-height: 100vh; height: 100vh; position: fixed; top: 0; left: 0; display: flex; flex-direction: column; padding: 1.5rem 1rem; flex-shrink: 0; z-index: 100; transition: transform .25s ease; overflow-y: auto; }
.sidebar.open { transform: translateX(0) !important; }
.overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 99; }
.overlay.show { display: block; }
.main-wrap { flex: 1; display: flex; flex-direction: column; min-width: 0; overflow: hidden; margin-left: 260px; transition: margin-left .25s; }
.topbar { height: 64px; background: #fff; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; padding: 0 1.75rem; flex-shrink: 0; position: sticky; top: 0; z-index: 50; }
.topbar-left { display: flex; align-items: center; gap: .75rem; }
.hamburger { display: none; background: none; border: none; cursor: pointer; padding: .35rem; color: #111827; border-radius: 8px; }
.hamburger svg { width: 22px; height: 22px; }
.topbar-left .page-title { font-family: 'Space Grotesk', sans-serif; font-size: 1.2rem; font-weight: 700; color: #111827; }
.topbar-left .page-sub { font-size: .78rem; color: #6b7280; margin-top: .1rem; }
.topbar-right { display: flex; align-items: center; gap: .85rem; }
.t-search { display: flex; align-items: center; gap: .5rem; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: .45rem .85rem; width: 200px; }
.t-search svg { width: 14px; height: 14px; color: #9ca3af; flex-shrink: 0; }
.t-search input { background: none; border: none; outline: none; font-size: .83rem; font-family: 'Inter', sans-serif; color: #111827; width: 100%; }
.t-search input::placeholder { color: #9ca3af; }
.t-icon { width: 36px; height: 36px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #6b7280; position: relative; }
.t-icon svg { width: 16px; height: 16px; }
.notif-dot { position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; background: #ef4444; border-radius: 50%; border: 1.5px solid #fff; }
.t-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg,#3b82f6,#8b5cf6); display: flex; align-items: center; justify-content: center; color: #fff; font-size: .8rem; font-weight: 600; cursor: pointer; text-decoration: none; }
.page-content { flex: 1; overflow-y: auto; padding: 1.5rem 1.75rem 3rem; }
.flash { display: flex; align-items: center; gap: .6rem; padding: .8rem 1rem; border-radius: 10px; font-size: .84rem; font-weight: 500; margin-bottom: 1.25rem; }
.flash-ok  { background: #f0fdf4; border: 1px solid #bbf7d0; color: #16a34a; }
.flash-err { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }
.s-card { background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; margin-bottom: 1.1rem; overflow: hidden; }
.s-card-head { display: flex; align-items: center; gap: .75rem; padding: 1rem 1.5rem; border-bottom: 1px solid #f3f4f6; }
.s-card-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: .95rem; flex-shrink: 0; }
.icon-blue   { background: #eff6ff; }
.icon-green  { background: #f0fdf4; }
.icon-yellow { background: #fffbeb; }
.icon-purple { background: #f5f3ff; }
.icon-slate  { background: #f8fafc; }
.s-card-title { font-size: .9rem; font-weight: 700; color: #111827; }
.s-card-sub   { font-size: .73rem; color: #9ca3af; margin-top: .08rem; }
.s-card-body  { padding: 1.25rem 1.5rem 1.5rem; }
.fg2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.1rem; }
.fg1 { display: grid; grid-template-columns: 1fr; gap: 1.1rem; }
.mb  { margin-bottom: 1.1rem; }
.field { display: flex; flex-direction: column; gap: .38rem; }
.field > label { font-size: .72rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #6b7280; }
.f-input, .f-select { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: .6rem .85rem; font-size: .875rem; font-family: 'Inter', sans-serif; color: #111827; outline: none; width: 100%; transition: border-color .15s, box-shadow .15s; }
.f-input:focus, .f-select:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
.f-input[readonly] { background: #f3f4f6; color: #9ca3af; cursor: not-allowed; }
.f-select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 2.2rem; cursor: pointer; }
.slider-wrap { display: flex; align-items: center; gap: .75rem; }
.slider-wrap input[type=range] { flex: 1; -webkit-appearance: none; height: 5px; border-radius: 3px; outline: none; cursor: pointer; background: linear-gradient(to right, #3b82f6 0%, #3b82f6 var(--val, 20%), #e5e7eb var(--val, 20%), #e5e7eb 100%); }
.slider-wrap input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; width: 17px; height: 17px; border-radius: 50%; background: #3b82f6; border: 2.5px solid #fff; box-shadow: 0 1px 5px rgba(59,130,246,.4); cursor: pointer; }
.slider-lbl { font-size: .78rem; font-weight: 600; color: #3b82f6; min-width: 58px; text-align: right; background: #eff6ff; border-radius: 6px; padding: .25rem .55rem; }
.row-divider { border: none; border-top: 1px solid #f3f4f6; margin: 1rem 0; }
.tog-row { display: flex; align-items: center; justify-content: space-between; padding: .88rem 0; border-bottom: 1px solid #f3f4f6; gap: 1rem; }
.tog-row:last-child { border-bottom: none; padding-bottom: 0; }
.tog-row:first-child { padding-top: 0; }
.tog-info { flex: 1; min-width: 0; }
.tog-info strong { font-size: .875rem; font-weight: 500; color: #111827; display: block; }
.tog-info span   { font-size: .75rem; color: #9ca3af; margin-top: .12rem; display: block; line-height: 1.4; }
.tog { position: relative; width: 42px; height: 24px; flex-shrink: 0; cursor: pointer; }
.tog input { opacity: 0; width: 0; height: 0; position: absolute; }
.tog-track { position: absolute; inset: 0; background: #d1d5db; border-radius: 12px; transition: background .2s; cursor: pointer; }
.tog-thumb { position: absolute; top: 2px; left: 2px; width: 20px; height: 20px; background: #fff; border-radius: 50%; box-shadow: 0 1px 4px rgba(0,0,0,.2); transition: transform .2s; pointer-events: none; }
.tog input:checked ~ .tog-track { background: #22c55e; }
.tog input:checked ~ .tog-thumb { transform: translateX(18px); }
.ph-wrap { display: flex; align-items: center; gap: .6rem; }
.ph-wrap .f-input { text-align: center; }
.ph-dash { color: #9ca3af; font-size: 1rem; font-weight: 500; flex-shrink: 0; }
.save-bar { display: flex; align-items: center; justify-content: flex-end; gap: .85rem; margin-top: .5rem; flex-wrap: wrap; }
.btn-discard { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: .65rem 1.5rem; font-size: .875rem; font-weight: 500; color: #6b7280; cursor: pointer; font-family: 'Inter', sans-serif; transition: border-color .15s, color .15s; }
.btn-discard:hover { border-color: #94a3b8; color: #374151; }
.btn-save { background: #3b82f6; color: #fff; border: none; border-radius: 8px; padding: .65rem 2rem; font-size: .875rem; font-weight: 600; font-family: 'Inter', sans-serif; cursor: pointer; display: flex; align-items: center; gap: .5rem; transition: background .15s, transform .1s; }
.btn-save:hover  { background: #2563eb; transform: translateY(-1px); }
.btn-save:active { transform: translateY(0); }
.btn-save svg { width: 15px; height: 15px; }
.toast { position: fixed; bottom: 24px; right: 24px; background: #0f172a; color: #fff; padding: .9rem 1.25rem; border-radius: 12px; font-size: .84rem; font-weight: 500; display: flex; align-items: center; gap: .7rem; box-shadow: 0 8px 24px rgba(0,0,0,.18); transform: translateY(70px) scale(.95); opacity: 0; transition: transform .32s cubic-bezier(.34,1.56,.64,1), opacity .32s; z-index: 999; pointer-events: none; }
.toast.show { transform: translateY(0) scale(1); opacity: 1; }

/* ── Tank list table ────────────────────────────────────────────────────── */
.tank-list-table { width: 100%; border-collapse: collapse; margin-top: .75rem; }
.tank-list-table thead tr { background: #f9fafb; }
.tank-list-table th { font-size: .7rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #6b7280; padding: .6rem .85rem; text-align: left; border-bottom: 1px solid #e5e7eb; }
.tank-list-table td { padding: .7rem .85rem; font-size: .855rem; color: #111827; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.tank-list-table tr:last-child td { border-bottom: none; }
.tank-list-table tr:hover td { background: #f9fafb; }
.tank-badge { display: inline-flex; align-items: center; padding: .2rem .65rem; border-radius: 20px; font-size: .72rem; font-weight: 600; }
.tank-badge.active      { background: #dcfce7; color: #15803d; }
.tank-badge.inactive    { background: #fee2e2; color: #b91c1c; }
.tank-badge.maintenance { background: #fef3c7; color: #92400e; }
.btn-delete-tank { background: transparent; border: 1px solid #fecaca; color: #ef4444; border-radius: 7px; padding: .32rem .75rem; font-size: .78rem; font-weight: 600; font-family: 'Inter', sans-serif; cursor: pointer; display: inline-flex; align-items: center; gap: .3rem; transition: background .15s, border-color .15s; }
.btn-delete-tank:hover { background: #fef2f2; border-color: #ef4444; }
.btn-delete-tank svg { width: 13px; height: 13px; }

/* ── Confirm modal ──────────────────────────────────────────────────────── */
.modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 200; align-items: center; justify-content: center; }
.modal-backdrop.show { display: flex; }
.modal-box { background: #fff; border-radius: 16px; padding: 1.75rem; max-width: 380px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,.18); text-align: center; animation: modal-pop .22s cubic-bezier(.34,1.56,.64,1); }
@keyframes modal-pop { from { opacity:0; transform:scale(.9); } to { opacity:1; transform:scale(1); } }
.modal-icon { width: 48px; height: 48px; background: #fef2f2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; }
.modal-icon svg { width: 22px; height: 22px; color: #ef4444; }
.modal-box h4 { font-family: 'Space Grotesk', sans-serif; font-size: 1rem; font-weight: 700; color: #111827; margin-bottom: .45rem; }
.modal-box p { font-size: .83rem; color: #6b7280; line-height: 1.5; margin-bottom: 1.5rem; }
.modal-box p strong { color: #111827; }
.modal-actions { display: flex; gap: .65rem; }
.btn-modal-cancel  { flex: 1; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: .65rem; font-size: .85rem; font-weight: 500; color: #6b7280; cursor: pointer; font-family: 'Inter', sans-serif; }
.btn-modal-cancel:hover { border-color: #94a3b8; }
.btn-modal-confirm { flex: 1; background: #ef4444; border: none; border-radius: 8px; padding: .65rem; font-size: .85rem; font-weight: 600; color: #fff; cursor: pointer; font-family: 'Inter', sans-serif; }
.btn-modal-confirm:hover { background: #dc2626; }

/* ── Add Tank form ──────────────────────────────────────────────────────── */
:root {
  --fgen-bg:        #0d1117;
  --fgen-surface:   #161b22;
  --fgen-border:    #2a3441;
  --fgen-focus:     #3b82f6;
  --fgen-accent:    #3b82f6;
  --fgen-glow:      rgba(59,130,246,0.18);
  --fgen-text:      #e6edf3;
  --fgen-muted:     #7d8fa3;
  --fgen-label-c:   #8b9ab0;
  --fgen-radius:    6px;
  --fgen-font-ui:   'Barlow Condensed', sans-serif;
  --fgen-font-mono: 'DM Mono', monospace;
}
.btn-add-tank { font-family: var(--fgen-font-ui); font-size: 0.8rem; font-weight: 700; letter-spacing: 0.13em; text-transform: uppercase; color: var(--fgen-accent); background: transparent; border: 1px solid #d1d5db; border-radius: var(--fgen-radius); padding: 0.5rem 1rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.45rem; transition: background 0.18s, border-color 0.18s, box-shadow 0.18s; }
.btn-add-tank::before { content: '+'; font-size: 1.05rem; font-weight: 400; line-height: 1; color: var(--fgen-accent); }
.btn-add-tank:hover { background: rgba(59,130,246,0.07); border-color: var(--fgen-accent); box-shadow: 0 0 0 3px var(--fgen-glow); }
#addTankForm { display: none; animation: fgen-slidein 0.22s ease forwards; margin-top: 1rem; }
#addTankForm.fgen-collapsing { animation: fgen-slideout 0.22s ease forwards; pointer-events: none; }
@keyframes fgen-slidein { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
@keyframes fgen-slideout { from { opacity:1; transform:translateY(0); } to { opacity:0; transform:translateY(-8px); } }
.fgen-form { font-family: var(--fgen-font-ui); background: var(--fgen-surface); border: 1px solid var(--fgen-border); border-radius: var(--fgen-radius); padding: 1.5rem 1.75rem; width: 100%; display: flex; flex-direction: column; gap: 0; position: relative; box-shadow: 0 0 0 1px rgba(59,130,246,0.06), 0 6px 24px rgba(0,0,0,0.35); }
.fgen-form::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg,var(--fgen-accent),#60a5fa); border-radius: var(--fgen-radius) var(--fgen-radius) 0 0; }
.fgen-field { display: flex; flex-direction: column; gap: 0.4rem; padding-bottom: 1.1rem; position: relative; }
.fgen-field:not(:last-of-type)::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 1px; background: var(--fgen-border); opacity: 0.5; }
.fgen-label { font-family: var(--fgen-font-mono); font-size: 0.66rem; font-weight: 500; letter-spacing: 0.1em; text-transform: uppercase; color: var(--fgen-label-c); user-select: none; display: flex; align-items: center; gap: 0.4rem; }
.fgen-label::before { content: '//'; color: var(--fgen-accent); opacity: 0.6; font-size: 0.63rem; }
.fgen-input, .fgen-select { font-family: var(--fgen-font-mono); font-size: 0.9rem; color: var(--fgen-text); background: var(--fgen-bg); border: 1px solid var(--fgen-border); border-radius: var(--fgen-radius); padding: 0.58rem 0.85rem; width: 100%; box-sizing: border-box; outline: none; transition: border-color 0.18s, box-shadow 0.18s, background 0.18s; -webkit-appearance: none; appearance: none; }
.fgen-input::placeholder { color: var(--fgen-muted); opacity: 0.55; }
.fgen-input:focus, .fgen-select:focus { border-color: var(--fgen-focus); background: #0f1620; box-shadow: 0 0 0 3px var(--fgen-glow); }
.fgen-input[type='number']::-webkit-inner-spin-button, .fgen-input[type='number']::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
.fgen-input[type='number'] { -moz-appearance: textfield; }
.fgen-select { padding-right: 2.25rem; cursor: pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%237d8fa3' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 0.85rem center; background-color: var(--fgen-bg); }
.fgen-select option { background: #1c2333; color: var(--fgen-text); }
.fgen-form-actions { display: flex; gap: 0.6rem; margin-top: 1.25rem; }
.fgen-submit { font-family: var(--fgen-font-ui); font-size: 0.82rem; font-weight: 700; letter-spacing: 0.13em; text-transform: uppercase; color: #fff; background: var(--fgen-accent); border: none; border-radius: var(--fgen-radius); padding: 0.65rem 1.4rem; cursor: pointer; flex: 1; position: relative; overflow: hidden; transition: background 0.18s, transform 0.12s, box-shadow 0.18s; box-shadow: 0 2px 8px rgba(59,130,246,0.3); }
.fgen-submit:hover  { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(59,130,246,0.4); }
.fgen-cancel { font-family: var(--fgen-font-ui); font-size: 0.82rem; font-weight: 700; letter-spacing: 0.13em; text-transform: uppercase; color: var(--fgen-muted); background: transparent; border: 1px solid var(--fgen-border); border-radius: var(--fgen-radius); padding: 0.65rem 1.2rem; cursor: pointer; transition: border-color 0.18s, color 0.18s; }
.fgen-cancel:hover { border-color: #3b4a60; color: var(--fgen-text); }

/* Nav section label */
.nav-section-label { font-size: .6rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: #475569; padding: .5rem .75rem .25rem; margin-top: .5rem; }

@media (max-width: 900px) {
  .sidebar { transform: translateX(-100%); }
  .main-wrap { margin-left: 0; }
  .hamburger { display: flex; }
  .t-search { display: none; }
  .fg2 { grid-template-columns: 1fr; }
  .page-content { padding: 1.25rem; }
  .topbar { padding: 0 1rem; }
}
@media (max-width: 480px) {
  .s-card-body { padding: 1rem; }
  .save-bar { justify-content: stretch; }
  .btn-save, .btn-discard { flex: 1; text-align: center; justify-content: center; }
  .ph-wrap { flex-wrap: wrap; }
  .ph-wrap .f-input { min-width: 80px; }
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

        <div class="nav-section-label">Overview</div>
        <a href="<?= BASE_URL ?>/app/admin/admin_dashboard.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            <span>Dashboard</span>
        </a>
        <a href="<?= BASE_URL ?>/app/admin/admin_oversight.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <span>Admin Oversight</span>
        </a>
        <a href="<?= BASE_URL ?>/app/admin/admin_usage.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <span>Usage Stats</span>
        </a>
        <a href="<?= BASE_URL ?>/app/admin/admin_weather.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
            <span>Weather</span>
        </a>
        <a href="<?= BASE_URL ?>/app/admin/admin_map.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <span>Tank Map</span>
        </a>

        <div class="nav-section-label">Management</div>
        <a href="<?= BASE_URL ?>/app/admin/admin_userlogs.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            <span>Users &amp; Roles</span>
        </a>
        <a href="<?= BASE_URL ?>/app/admin/admin_settings.php" class="nav-item">
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
<div class="main-wrap">
  <header class="topbar">
    <div class="topbar-left">
      <button class="hamburger" onclick="toggleSidebar()" aria-label="Menu">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div>
        <div class="page-title">Settings</div>
        <div class="page-sub">Configure your EcoRain System</div>
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
      <a href="<?= BASE_URL ?>/App/Admin/admin_userlogs.php" class="t-avatar"><?= htmlspecialchars($initials) ?></a>
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
              <input type="checkbox" name="overflow_prevention" <?= cfg($rows,'overflow_prevention','1')==='1'?'checked':'' ?>/>
              <div class="tog-track"></div>
              <div class="tog-thumb"></div>
            </label>
          </div>
          <hr class="row-divider"/>

          <!-- REGISTERED TANKS LIST -->
          <?php if (!empty($allTanks)): ?>
          <div style="margin-bottom:1.25rem;">
            <div style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6b7280;margin-bottom:.6rem;">
              Registered Tanks
            </div>
            <table class="tank-list-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Tank Name</th>
                  <th>Location</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($allTanks as $t): ?>
                <tr>
                  <td style="color:#9ca3af;font-size:.78rem"><?= $t['tank_id'] ?></td>
                  <td style="font-weight:600"><?= htmlspecialchars($t['tankname']) ?></td>
                  <td style="color:#6b7280">📍 <?= htmlspecialchars($t['location_add']) ?></td>
                  <td>
                    <span class="tank-badge <?= strtolower($t['status_tank']) ?>">
                      <?= htmlspecialchars($t['status_tank']) ?>
                    </span>
                  </td>
                  <td>
                    <button type="button" class="btn-delete-tank"
                            onclick="confirmDelete(<?= $t['tank_id'] ?>, '<?= htmlspecialchars(addslashes($t['tankname'])) ?>')">
                      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
                        <path d="M10 11v6M14 11v6"/>
                        <path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
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

          <!-- ADD TANK TRIGGER -->
          <button type="button" class="btn-add-tank" onclick="showAddTankForm()">Add Tank</button>

          <!-- ADD TANK INLINE FORM -->
          <div id="addTankForm">
            <form method="POST" class="fgen-form">
              <input type="hidden" name="add_tank" value="1"/>
              <div class="fgen-field">
                <label class="fgen-label">Tank Name</label>
                <input type="text" name="tankname" class="fgen-input" placeholder="e.g. Main Rooftop Tank" required/>
              </div>
              <div class="fgen-field">
                <label class="fgen-label">Location Address</label>
                <input type="text" name="location_add" class="fgen-input" placeholder="e.g. Brgy. Poblacion, Manolo Fortich, Bukidnon" required/>
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
              <input type="checkbox" name="pump_auto" <?= cfg($rows,'pump_auto','1')==='1'?'checked':'' ?>/>
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
              <input class="f-input" type="number" name="pump_wattage" value="<?= (int)cfg($rows,'pump_wattage',100) ?>" min="0"/>
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
              <input type="checkbox" name="<?= $name ?>" <?= $chk?'checked':'' ?>/>
              <div class="tog-track"></div>
              <div class="tog-thumb"></div>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- WATER QUALITY -->
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

      <!-- Save Bar -->
      <div class="save-bar">
        <button type="button" class="btn-discard" onclick="window.location.reload()">Discard</button>
        <button type="submit" class="btn-save">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          Save Changes
        </button>
      </div>

    </form>
  </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="modal-backdrop" id="deleteModal">
  <div class="modal-box">
    <div class="modal-icon">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <polyline points="3 6 5 6 21 6"/>
        <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
        <path d="M10 11v6M14 11v6"/>
        <path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
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

<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('show');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('overlay').classList.remove('show');
}

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

function showAddTankForm() {
  const wrap = document.getElementById('addTankForm');
  wrap.style.display = 'block';
  void wrap.offsetWidth;
  wrap.style.animation = 'none';
  requestAnimationFrame(() => { wrap.style.animation = ''; });
}
function hideAddTankForm() {
  const wrap = document.getElementById('addTankForm');
  wrap.classList.add('fgen-collapsing');
  wrap.addEventListener('animationend', () => {
    wrap.style.display = 'none';
    wrap.classList.remove('fgen-collapsing');
  }, { once: true });
}
document.querySelector('#addTankForm .fgen-form').addEventListener('submit', function (e) {
  e.preventDefault();
  const wrap = document.getElementById('addTankForm');
  wrap.classList.add('fgen-collapsing');
  wrap.addEventListener('animationend', () => { this.submit(); }, { once: true });
});

const tankStatusSel = document.getElementById('tankStatusSelect');
if (tankStatusSel) {
  tankStatusSel.addEventListener('change', e => { e.target.dataset.value = e.target.value; });
}

// Delete modal
function confirmDelete(tankId, tankName) {
  document.getElementById('deleteTankId').value        = tankId;
  document.getElementById('modalTankName').textContent = '"' + tankName + '"';
  document.getElementById('deleteModal').classList.add('show');
}
function closeDeleteModal() {
  document.getElementById('deleteModal').classList.remove('show');
}
function submitDelete() {
  document.getElementById('deleteTankForm').submit();
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
  if (e.target === this) closeDeleteModal();
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