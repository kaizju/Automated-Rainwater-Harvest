<?php
require_once '../../connections/config.php';
require_once '../../connections/functions.php';

requireRole('admin');
logPageVisit('Admin Oversight', 'oversight');

$activePage = 'oversight';

// ── Stats bar ────────────────────────────────────────────────────────────────
$totalUsers   = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$onlineToday  = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_activity_logs WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$totalActions = (int)$pdo->query("SELECT COUNT(*) FROM user_activity_logs WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$criticalCount = (int)$pdo->query("SELECT COUNT(*) FROM system_alerts WHERE is_resolved=0 AND severity='critical'")->fetchColumn();

// ── Role breakdown ───────────────────────────────────────────────────────────
$roleStats = $pdo->query("SELECT role, COUNT(*) AS cnt FROM users GROUP BY role")->fetchAll(PDO::FETCH_ASSOC);
$roleCounts = array_column($roleStats, 'cnt', 'role');

// ── Filters ──────────────────────────────────────────────────────────────────
$filterRole   = $_GET['role']   ?? '';
$filterAction = $_GET['action'] ?? '';
$filterDate   = $_GET['date']   ?? '';
$filterUser   = $_GET['user']   ?? '';
$page         = max(1, (int)($_GET['p'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($filterRole) {
  $where[] = 'ual.role = :role';
  $params[':role']   = $filterRole;
}
if ($filterAction) {
  $where[] = 'ual.action LIKE :action';
  $params[':action'] = '%' . $filterAction . '%';
}
if ($filterDate) {
  $where[] = 'DATE(ual.created_at) = :date';
  $params[':date']   = $filterDate;
}
if ($filterUser) {
  $where[] = '(u.email LIKE :user OR u.username LIKE :user2)';
  $params[':user'] = '%' . $filterUser . '%';
  $params[':user2'] = '%' . $filterUser . '%';
}

$whereStr = implode(' AND ', $where);

$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM user_activity_logs ual
    LEFT JOIN users u ON ual.user_id = u.id
    WHERE $whereStr
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
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

// ── Page visits (recent 50, ALL roles, with IP) ───────────────────────────────
$visits = $pdo->query("
    SELECT pv.*, u.username, u.email AS user_email
    FROM page_visits pv
    LEFT JOIN users u ON pv.user_id = u.id
    ORDER BY pv.visited_at DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// ── Unresolved system alerts ──────────────────────────────────────────────────
$alerts = $pdo->query("
    SELECT sa.*, t.tankname, u.username
    FROM system_alerts sa
    LEFT JOIN tank t  ON sa.tank_id = t.tank_id
    LEFT JOIN users u ON sa.user_id = u.id
    WHERE sa.is_resolved = 0
    ORDER BY sa.severity DESC, sa.created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// ── All users list ────────────────────────────────────────────────────────────
$users = $pdo->query("
    SELECT u.id, u.username, u.email, u.role, u.is_verified, u.created_at,
           (SELECT COUNT(*) FROM user_activity_logs WHERE user_id=u.id AND DATE(created_at)=CURDATE()) AS today_actions,
           (SELECT MAX(created_at)  FROM user_activity_logs WHERE user_id=u.id) AS last_seen
    FROM users u ORDER BY u.role, u.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Action frequency chart data ───────────────────────────────────────────────
$actionFreq = $pdo->query("
    SELECT action, COUNT(*) AS cnt
    FROM user_activity_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY action ORDER BY cnt DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$chartActionLabels = array_column($actionFreq, 'action');
$chartActionData   = array_column($actionFreq, 'cnt');

// ── Activity by role (last 7 days) ────────────────────────────────────────────
$actByRole = $pdo->query("
    SELECT DATE(created_at) AS d,
           SUM(role='admin')   AS admins,
           SUM(role='manager') AS managers,
           SUM(role='user')    AS users_cnt
    FROM user_activity_logs
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at) ORDER BY d ASC
")->fetchAll(PDO::FETCH_ASSOC);

$roleChartLabels   = [];
$roleChartAdmin    = [];
$roleChartManager  = [];
$roleChartUser     = [];
$dateMap = array_column($actByRole, null, 'd');
for ($i = 6; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime("-$i days"));
  $roleChartLabels[]  = date('D', strtotime($d));
  $roleChartAdmin[]   = (int)($dateMap[$d]['admins']    ?? 0);
  $roleChartManager[] = (int)($dateMap[$d]['managers']  ?? 0);
  $roleChartUser[]    = (int)($dateMap[$d]['users_cnt'] ?? 0);
}

// ── CSV Export ────────────────────────────────────────────────────────────────
if (isset($_GET['export_report'])) {
  $filename = 'ecorain_oversight_report_' . date('Y-m-d_His') . '.csv';
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Pragma: no-cache');
  header('Expires: 0');

  $out = fopen('php://output', 'w');

  // ── Section 1: Activity Logs ──────────────────────────────────────────
  fputcsv($out, ['=== ACTIVITY LOGS ===']);
  fputcsv($out, ['#', 'Username', 'Email', 'Role', 'Action', 'Module', 'Description', 'Severity', 'IP Address', 'Date & Time']);

  $exportLogs = $pdo->query("
        SELECT ual.activity_id, u.username, u.email, ual.role, ual.action,
               ual.module, ual.description, ual.severity, ual.ip_address, ual.created_at
        FROM user_activity_logs ual
        LEFT JOIN users u ON ual.user_id = u.id
        ORDER BY ual.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

  foreach ($exportLogs as $i => $row) {
    fputcsv($out, [
      $i + 1,
      $row['username']    ?? 'System',
      $row['email']       ?? '',
      $row['role']        ?? '',
      $row['action']      ?? '',
      $row['module']      ?? '',
      $row['description'] ?? '',
      $row['severity']    ?? '',
      $row['ip_address']  ?? '',
      $row['created_at']  ?? '',
    ]);
  }

  // ── Section 2: Page Visits ────────────────────────────────────────────
  fputcsv($out, []);
  fputcsv($out, ['=== PAGE VISITS ===']);
  fputcsv($out, ['#', 'Username', 'Email', 'Role', 'Page Label', 'Page URL', 'IP Address', 'Visited At']);

  $exportVisits = $pdo->query("
        SELECT pv.*, u.username, u.email AS user_email
        FROM page_visits pv
        LEFT JOIN users u ON pv.user_id = u.id
        ORDER BY pv.visited_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

  foreach ($exportVisits as $i => $row) {
    fputcsv($out, [
      $i + 1,
      $row['username']   ?? 'Guest',
      $row['user_email'] ?? '',
      $row['role']       ?? '',
      $row['page_label'] ?? '',
      $row['page']       ?? '',
      $row['ip_address'] ?? '',
      $row['visited_at'] ?? '',
    ]);
  }

  // ── Section 3: System Alerts ──────────────────────────────────────────
  fputcsv($out, []);
  fputcsv($out, ['=== SYSTEM ALERTS ===']);
  fputcsv($out, ['#', 'Message', 'Severity', 'Tank', 'Triggered By', 'Status', 'Created At', 'Resolved At']);

  $exportAlerts = $pdo->query("
        SELECT sa.*, t.tankname, u.username
        FROM system_alerts sa
        LEFT JOIN tank t  ON sa.tank_id = t.tank_id
        LEFT JOIN users u ON sa.user_id = u.id
        ORDER BY sa.severity DESC, sa.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

  foreach ($exportAlerts as $i => $row) {
    fputcsv($out, [
      $i + 1,
      $row['message']     ?? '',
      $row['severity']    ?? '',
      $row['tankname']    ?? '—',
      $row['username']    ?? 'System',
      $row['is_resolved'] ? 'Resolved' : 'Open',
      $row['created_at']  ?? '',
      $row['resolved_at'] ?? '',
    ]);
  }

  // ── Summary footer ────────────────────────────────────────────────────
  fputcsv($out, []);
  fputcsv($out, ['=== SUMMARY ===']);
  fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
  fputcsv($out, ['Total Activity Logs', count($exportLogs)]);
  fputcsv($out, ['Total Page Visits',   count($exportVisits)]);
  fputcsv($out, ['Total System Alerts', count($exportAlerts)]);
  fputcsv($out, ['Exported By', $_SESSION['username'] ?? 'Admin']);

  fclose($out);
  exit;
}
// ── Helpers ───────────────────────────────────────────────────────────────────
function roleColor(string $role): string
{
  return match ($role) {
    'admin'   => '#3b82f6',
    'manager' => '#8b5cf6',
    default   => '#10b981',
  };
}
function roleBadge(string $role): string
{
  $colors = match ($role) {
    'admin'   => 'background:#eff6ff;color:#2563eb;border-color:#bfdbfe',
    'manager' => 'background:#f5f3ff;color:#7c3aed;border-color:#ddd6fe',
    default   => 'background:#ecfdf5;color:#059669;border-color:#a7f3d0',
  };
  return "<span class='badge' style='$colors'>" . ucfirst($role) . "</span>";
}
function severityBadge(string $sev): string
{
  $colors = match ($sev) {
    'critical' => 'background:#fef2f2;color:#dc2626;border-color:#fecaca',
    'warning'  => 'background:#fffbeb;color:#d97706;border-color:#fde68a',
    default    => 'background:#f0fdf4;color:#16a34a;border-color:#bbf7d0',
  };
  return "<span class='badge' style='$colors'>" . ucfirst($sev) . "</span>";
}
function timeAgo(string $ts): string
{
  $diff = time() - strtotime($ts);
  if ($diff < 60)    return $diff . 's ago';
  if ($diff < 3600)  return floor($diff / 60) . 'm ago';
  if ($diff < 86400) return floor($diff / 3600) . 'h ago';
  return date('M j', strtotime($ts));
}
function actionIcon(string $action): string
{
  if (str_contains($action, 'login'))    return '🔑';
  if (str_contains($action, 'logout'))   return '🚪';
  if (str_contains($action, 'delete'))   return '🗑️';
  if (str_contains($action, 'create') || str_contains($action, 'add')) return '➕';
  if (str_contains($action, 'edit') || str_contains($action, 'update')) return '✏️';
  if (str_contains($action, 'page_view') || str_contains($action, 'visit')) return '👁️';
  if (str_contains($action, 'export'))   return '📤';
  if (str_contains($action, 'alert'))    return '🚨';
  return '📋';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>EcoRain — Admin Oversight</title>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="<?= BASE_URL ?>/Others/all.css">
  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
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
      height: 100%;
    }

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
      top: 0;
      left: 0;
      height: 100vh;
      overflow-y: auto;
      z-index: 100;
      transition: transform .25s ease;
    }

    .sidebar.open {
      transform: translateX(0) !important;
    }

    .logo {
      display: flex;
      align-items: center;
      gap: .6rem;
      padding: .25rem .5rem .25rem .25rem;
      margin-bottom: 1.5rem;
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
      flex-shrink: 0;
    }

    .logo-text {
      font-family: 'Sora', sans-serif;
      font-size: 1.1rem;
      font-weight: 700;
      color: #fff;
      letter-spacing: -.02em;
    }

    .nav-section-label {
      font-size: .6rem;
      font-weight: 700;
      letter-spacing: .1em;
      text-transform: uppercase;
      color: #475569;
      padding: .65rem .75rem .25rem;
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
      transition: background .15s, color .15s;
    }

    .nav-item svg {
      width: 17px;
      height: 17px;
      flex-shrink: 0;
    }

    .nav-item:hover {
      background: rgba(255, 255, 255, .06);
      color: #e2e8f0;
    }

    .nav-item.active {
      background: rgba(96, 165, 250, .15);
      color: #93c5fd;
      font-weight: 600;
    }

    .nav-item.logout:hover {
      background: rgba(239, 68, 68, .1);
      color: #fca5a5;
    }

    .sidebar-spacer {
      flex: 1;
    }

    .sidebar-bottom {
      border-top: 1px solid #1e293b;
      padding-top: 1rem;
      margin-top: .5rem;
    }

    /* OVERLAY */
    .overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .45);
      z-index: 99;
    }

    .overlay.show {
      display: block;
    }

    /* APP BODY */
    .app-body {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
      margin-left: var(--sidebar-w);
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
      flex-shrink: 0;
    }

    .topbar-left {
      display: flex;
      align-items: center;
      gap: .75rem;
    }

    .hamburger {
      display: none;
      background: none;
      border: none;
      cursor: pointer;
      padding: .35rem;
      color: var(--text);
      border-radius: 8px;
    }

    .hamburger svg {
      width: 22px;
      height: 22px;
    }

    .page-title {
      font-family: 'Sora', sans-serif;
      font-size: 1.05rem;
      font-weight: 700;
    }

    .page-sub {
      font-size: .72rem;
      color: var(--muted);
    }

    .topbar-right {
      display: flex;
      align-items: center;
      gap: .65rem;
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
      transition: border-color .15s;
    }

    .t-btn:hover {
      border-color: var(--accent);
      color: var(--accent);
    }

    .t-btn svg {
      width: 15px;
      height: 15px;
    }

    .notif-dot {
      position: absolute;
      top: 5px;
      right: 5px;
      width: 6px;
      height: 6px;
      background: #ef4444;
      border-radius: 50%;
      border: 1.5px solid var(--card-bg);
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
      text-decoration: none;
    }

    /* MAIN */
    .main {
      flex: 1;
      padding: 1.5rem;
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
      overflow-y: auto;
    }

    /* STAT CARDS */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 1rem;
    }

    .stat-card {
      background: var(--card-bg);
      border-radius: var(--radius);
      border: 1px solid var(--border);
      padding: 1.1rem 1.25rem;
      box-shadow: var(--shadow);
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .stat-icon {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      font-size: 1.2rem;
    }

    .stat-num {
      font-family: 'Sora', sans-serif;
      font-size: 1.65rem;
      font-weight: 800;
      color: var(--text);
      line-height: 1;
    }

    .stat-lbl {
      font-size: .73rem;
      color: var(--muted);
      margin-top: .15rem;
    }

    .stat-card.critical .stat-num {
      color: #dc2626;
    }

    /* CARDS */
    .card {
      background: var(--card-bg);
      border-radius: var(--radius);
      border: 1px solid var(--border);
      padding: 1.25rem 1.35rem;
      box-shadow: var(--shadow);
    }

    .card-hd {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1rem;
    }

    .card-title {
      font-size: .875rem;
      font-weight: 700;
      color: var(--text);
    }

    .card-label {
      font-size: .68rem;
      font-weight: 700;
      letter-spacing: .1em;
      text-transform: uppercase;
      color: var(--subtle);
    }

    /* CHARTS ROW */
    .charts-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.25rem;
    }

    .chart-wrap {
      height: 200px;
      position: relative;
    }

    /* SECTION TABS */
    .tabs {
      display: flex;
      gap: .4rem;
      border-bottom: 1px solid var(--border);
      margin-bottom: 1rem;
    }

    .tab {
      padding: .5rem .9rem;
      font-size: .8rem;
      font-weight: 600;
      color: var(--muted);
      cursor: pointer;
      border-bottom: 2px solid transparent;
      margin-bottom: -1px;
      transition: color .15s, border-color .15s;
    }

    .tab.active {
      color: var(--accent);
      border-bottom-color: var(--accent);
    }

    /* FILTER BAR */
    .filter-bar {
      display: flex;
      gap: .6rem;
      flex-wrap: wrap;
      align-items: center;
      margin-bottom: 1rem;
    }

    .filter-bar input,
    .filter-bar select {
      height: 34px;
      border: 1px solid var(--border);
      border-radius: 9px;
      background: var(--bg);
      color: var(--text);
      font-size: .8rem;
      font-family: 'DM Sans', sans-serif;
      padding: 0 .75rem;
      outline: none;
      transition: border-color .15s;
    }

    .filter-bar input:focus,
    .filter-bar select:focus {
      border-color: var(--accent);
    }

    .filter-bar input[type="text"] {
      width: 180px;
    }

    .btn-sm {
      height: 34px;
      padding: 0 .9rem;
      border-radius: 9px;
      font-size: .8rem;
      font-weight: 600;
      font-family: 'DM Sans', sans-serif;
      cursor: pointer;
      border: none;
      transition: background .15s;
    }

    .btn-primary {
      background: var(--accent);
      color: #fff;
    }

    .btn-primary:hover {
      background: #1d4ed8;
    }

    .btn-ghost {
      background: var(--card-bg);
      border: 1px solid var(--border);
      color: var(--muted);
    }

    .btn-ghost:hover {
      border-color: var(--accent);
      color: var(--accent);
    }

    /* TABLES */
    .tbl-wrap {
      overflow-x: auto;
    }

    .tbl {
      width: 100%;
      border-collapse: collapse;
      font-size: .82rem;
    }

    .tbl th {
      font-size: .65rem;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: var(--subtle);
      padding: 0 .6rem .55rem 0;
      text-align: left;
      border-bottom: 1px solid var(--border);
      white-space: nowrap;
    }

    .tbl td {
      padding: .55rem .6rem .55rem 0;
      border-bottom: 1px solid #f8fafc;
      vertical-align: middle;
      color: #374151;
    }

    .tbl tr:last-child td {
      border-bottom: none;
    }

    .tbl tr:hover td {
      background: #fafbfc;
    }

    .badge {
      display: inline-block;
      padding: .18rem .48rem;
      border-radius: 6px;
      font-size: .7rem;
      font-weight: 600;
      background: #f0fdf4;
      color: #16a34a;
      border: 1px solid #bbf7d0;
      white-space: nowrap;
    }

    .action-chip {
      display: inline-flex;
      align-items: center;
      gap: .3rem;
      font-size: .75rem;
      font-weight: 500;
      color: var(--text);
    }

    /* USERS GRID */
    .users-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: .85rem;
    }

    .user-card {
      border: 1px solid var(--border);
      border-radius: 11px;
      padding: .9rem 1rem;
      display: flex;
      gap: .75rem;
      align-items: flex-start;
      transition: border-color .15s;
    }

    .user-card:hover {
      border-color: var(--accent);
    }

    .user-avatar {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: .78rem;
      font-weight: 700;
      flex-shrink: 0;
    }

    .user-name {
      font-size: .875rem;
      font-weight: 600;
      color: var(--text);
    }

    .user-email {
      font-size: .73rem;
      color: var(--muted);
      margin: .1rem 0 .35rem;
    }

    .user-meta {
      display: flex;
      gap: .4rem;
      align-items: center;
      flex-wrap: wrap;
    }

    .user-stat {
      font-size: .68rem;
      color: var(--subtle);
    }

    /* ALERT ITEMS */
    .alert-item {
      display: flex;
      gap: .75rem;
      align-items: flex-start;
      padding: .75rem 0;
      border-bottom: 1px solid #f8fafc;
    }

    .alert-item:last-child {
      border-bottom: none;
    }

    .alert-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      flex-shrink: 0;
      margin-top: .35rem;
    }

    /* PAGINATION */
    .pager {
      display: flex;
      gap: .35rem;
      align-items: center;
      margin-top: 1rem;
      flex-wrap: wrap;
    }

    .pager a,
    .pager span {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 32px;
      height: 32px;
      border-radius: 8px;
      font-size: .78rem;
      font-weight: 600;
      text-decoration: none;
      border: 1px solid var(--border);
      color: var(--muted);
      transition: background .12s, border-color .12s;
    }

    .pager a:hover {
      border-color: var(--accent);
      color: var(--accent);
    }

    .pager .cur {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }

    /* LIVE PULSE */
    .live-dot {
      display: inline-block;
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #22c55e;
      margin-right: .35rem;
      animation: pulse 2s infinite;
    }

    @keyframes pulse {

      0%,
      100% {
        opacity: 1;
        transform: scale(1)
      }

      50% {
        opacity: .5;
        transform: scale(1.3)
      }
    }

    /* IP chip */
    .ip-chip {
      font-family: 'Courier New', monospace;
      font-size: .72rem;
      color: var(--subtle);
      background: #f8fafc;
      border: 1px solid var(--border);
      border-radius: 5px;
      padding: .1rem .4rem;
      white-space: nowrap;
    }

    /* RESPONSIVE */
    @media (max-width: 1024px) {
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .charts-row {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 768px) {
      .sidebar {
        transform: translateX(-100%);
      }

      .app-body {
        margin-left: 0;
      }

      .hamburger {
        display: flex;
      }

      .main {
        padding: 1rem;
      }

      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .users-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 480px) {
      .stats-grid {
        grid-template-columns: 1fr;
      }

      .filter-bar {
        flex-direction: column;
        align-items: stretch;
      }

      .filter-bar input,
      .filter-bar select {
        width: 100%;
      }
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
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <rect x="3" y="3" width="7" height="7" rx="1" />
        <rect x="14" y="3" width="7" height="7" rx="1" />
        <rect x="3" y="14" width="7" height="7" rx="1" />
        <rect x="14" y="14" width="7" height="7" rx="1" />
      </svg>
      <span>Dashboard</span>
    </a>
    <a href="<?= BASE_URL ?>/app/admin/admin_oversight.php" class="nav-item">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
        <circle cx="12" cy="12" r="3" />
      </svg>
      <span>Admin Oversight</span>
    </a>
    <a href="<?= BASE_URL ?>/app/admin/admin_usage.php" class="nav-item">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
      </svg>
      <span>Usage Stats</span>
    </a>
    <a href="<?= BASE_URL ?>/app/admin/admin_weather.php" class="nav-item">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
      </svg>
      <span>Weather</span>
    </a>
    <a href="<?= BASE_URL ?>/app/admin/admin_map.php" class="nav-item">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z" />
        <circle cx="12" cy="10" r="3" />
      </svg>
      <span>Tank Map</span>
    </a>

    <div class="nav-section-label">Management</div>
    <a href="<?= BASE_URL ?>/app/admin/admin_userlogs.php" class="nav-item">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
        <circle cx="9" cy="7" r="4" />
        <path d="M23 21v-2a4 4 0 00-3-3.87" />
        <path d="M16 3.13a4 4 0 010 7.75" />
      </svg>
      <span>Users &amp; Roles</span>
    </a>
    <a href="<?= BASE_URL ?>/app/admin/admin_settings.php" class="nav-item">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="3" />
        <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" />
      </svg>
      <span>Settings</span>
    </a>

    <div class="sidebar-spacer"></div>
    <div class="sidebar-bottom">
      <a href="<?= BASE_URL ?>/connections/signout.php" class="nav-item logout">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9" />
        </svg>
        <span>Log Out</span>
      </a>
    </div>
  </aside>

  <!-- APP BODY -->
  <div class="app-body">
    <header class="topbar">
      <div class="topbar-left">
        <button class="hamburger" onclick="toggleSidebar()">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <line x1="3" y1="6" x2="21" y2="6" />
            <line x1="3" y1="12" x2="21" y2="12" />
            <line x1="3" y1="18" x2="21" y2="18" />
          </svg>
        </button>
        <div>
          <div class="page-title"><span class="live-dot"></span>Admin Oversight</div>
          <div class="page-sub">Full system visibility &amp; audit trail</div>
        </div>
      </div>
      <div class="topbar-right">
        <div class="t-btn" title="Unresolved alerts: <?= $criticalCount ?>">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9" />
            <path d="M13.73 21a2 2 0 01-3.46 0" />
          </svg>
          <?php if ($criticalCount > 0): ?><span class="notif-dot"></span><?php endif; ?>
        </div>
        <a href="<?= BASE_URL ?>/App/Admin/admin_userlogs.php" class="t-avatar"><?= htmlspecialchars(avatarInitials()) ?></a>
      </div>
    </header>

    <main class="main">

      <!-- STAT CARDS -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background:#eff6ff">👥</div>
          <div>
            <div class="stat-num"><?= $totalUsers ?></div>
            <div class="stat-lbl">Total Users</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#ecfdf5">🟢</div>
          <div>
            <div class="stat-num"><?= $onlineToday ?></div>
            <div class="stat-lbl">Active Today</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#f5f3ff">📋</div>
          <div>
            <div class="stat-num"><?= $totalActions ?></div>
            <div class="stat-lbl">Actions Today</div>
          </div>
        </div>
        <div class="stat-card <?= $criticalCount > 0 ? 'critical' : '' ?>">
          <div class="stat-icon" style="background:#fef2f2">🚨</div>
          <div>
            <div class="stat-num"><?= $criticalCount ?></div>
            <div class="stat-lbl">Open Alerts</div>
          </div>
        </div>
      </div>

      <!-- CHARTS -->
      <div class="charts-row">
        <div class="card">
          <div class="card-hd">
            <span class="card-title">Activity by Role — Last 7 Days</span>
            <span class="card-label">actions</span>
          </div>
          <div class="chart-wrap"><canvas id="roleChart"></canvas></div>
        </div>
        <div class="card">
          <div class="card-hd">
            <span class="card-title">Top Actions — Last 7 Days</span>
            <span class="card-label">frequency</span>
          </div>
          <div class="chart-wrap"><canvas id="actionChart"></canvas></div>
        </div>
      </div>

      <!-- USERS PANEL -->
      <div class="card">
        <div class="card-hd">
          <span class="card-title">All Users
            <span style="font-size:.72rem;font-weight:400;color:var(--muted);margin-left:.4rem">
              <?= ($roleCounts['admin'] ?? 0) ?> admin · <?= ($roleCounts['manager'] ?? 0) ?> manager · <?= ($roleCounts['user'] ?? 0) ?> user
            </span>
          </span>
          <a href="<?= BASE_URL ?>/App/Admin/admin_userlogs.php" style="font-size:.78rem;font-weight:600;color:var(--accent);text-decoration:none">Manage →</a>
        </div>
        <div class="users-grid">
          <?php foreach ($users as $u):
            $initials = strtoupper(substr($u['username'], 0, 2));
            $lastSeen = $u['last_seen'] ? timeAgo($u['last_seen']) : 'never';
          ?>
            <div class="user-card">
              <div class="user-avatar" style="background:<?= roleColor($u['role']) ?>">
                <?= htmlspecialchars($initials) ?>
              </div>
              <div style="flex:1;min-width:0">
                <div class="user-name"><?= htmlspecialchars($u['username']) ?></div>
                <div class="user-email"><?= htmlspecialchars($u['email']) ?></div>
                <div class="user-meta">
                  <?= roleBadge($u['role']) ?>
                  <span class="user-stat">last: <?= $lastSeen ?></span>
                  <span class="user-stat">today: <?= $u['today_actions'] ?> acts</span>
                  <?php if (!$u['is_verified']): ?>
                    <span class="badge" style="background:#fff7ed;color:#c2410c;border-color:#fed7aa">unverified</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ACTIVITY LOG + PAGE VISITS + ALERTS TABS -->
      <div class="card">
        <div class="tabs">
         
          <div class="tab active" onclick="switchTab('log',this)">Activity Log</div>
          <div class="tab" onclick="switchTab('visits',this)">Page Visits</div>
          <div class="tab" onclick="switchTab('alerts',this)">
            System Alerts
            <?php if ($criticalCount > 0): ?>
              <span style="background:#ef4444;color:#fff;border-radius:99px;font-size:.65rem;padding:.1rem .4rem;margin-left:.3rem"><?= $criticalCount ?></span>
            <?php endif; ?>
          </div>
           <!-- in topbar-right, before the avatar -->
          <a href="?export_report=1" class="t-btn" title="Export Full Report (CSV)"
            style="width:auto;padding:0 .65rem;gap:.35rem;font-size:.75rem;font-weight:600;color:var(--accent);border-color:var(--accent);text-decoration:none">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:14px;height:14px">
              <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
              <polyline points="7 10 12 15 17 10" />
              <line x1="12" y1="15" x2="12" y2="3" />
            </svg>
            Export Report
          </a>
        </div>

        <!-- ── ACTIVITY LOG ─────────────────────────────────────────────────── -->
        <div id="tab-log">
          <form method="get" class="filter-bar">
            <input type="text" name="user" placeholder="Search user/email…" value="<?= htmlspecialchars($filterUser) ?>">
            <select name="role">
              <option value="">All roles</option>
              <option value="admin" <?= $filterRole === 'admin'   ? 'selected' : '' ?>>Admin</option>
              <option value="manager" <?= $filterRole === 'manager' ? 'selected' : '' ?>>Manager</option>
              <option value="user" <?= $filterRole === 'user'    ? 'selected' : '' ?>>User</option>
            </select>
            <input type="text" name="action" placeholder="Action keyword…" value="<?= htmlspecialchars($filterAction) ?>">
            <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>">
            <button type="submit" class="btn-sm btn-primary">Filter</button>
            <a href="?" class="btn-sm btn-ghost" style="display:inline-flex;align-items:center;text-decoration:none">Reset</a>
          </form>

          <div class="tbl-wrap">
            <table class="tbl">
              <thead>
                <tr>
                  <th>#</th>
                  <th>User</th>
                  <th>Role</th>
                  <th>Action</th>
                  <th>Module</th>
                  <th>Description</th>
                  <th>Severity</th>
                  <th>IP</th>
                  <th>When</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($logs)): ?>
                  <tr>
                    <td colspan="9" style="text-align:center;color:var(--subtle);padding:1.5rem 0">No activity records found.</td>
                  </tr>
                  <?php else: foreach ($logs as $log): ?>
                    <tr>
                      <td style="color:var(--subtle);font-size:.72rem"><?= $log['activity_id'] ?></td>
                      <td>
                        <div style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($log['username'] ?? 'System') ?></div>
                        <div style="font-size:.7rem;color:var(--subtle)"><?= htmlspecialchars($log['user_email'] ?? $log['email'] ?? '') ?></div>
                      </td>
                      <td><?= roleBadge($log['role'] ?? 'user') ?></td>
                      <td>
                        <span class="action-chip">
                          <?= actionIcon($log['action']) ?>
                          <?= htmlspecialchars($log['action']) ?>
                        </span>
                      </td>
                      <td style="color:var(--muted);font-size:.75rem"><?= htmlspecialchars($log['module'] ?? '—') ?></td>
                      <td style="font-size:.75rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($log['description'] ?? '') ?>">
                        <?= htmlspecialchars($log['description'] ?? '—') ?>
                      </td>
                      <td><?= severityBadge($log['severity'] ?? 'info') ?></td>
                      <td><span class="ip-chip"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></span></td>
                      <td style="white-space:nowrap;font-size:.75rem;color:var(--muted)" title="<?= $log['created_at'] ?>">
                        <?= timeAgo($log['created_at']) ?>
                      </td>
                    </tr>
                <?php endforeach;
                endif; ?>
              </tbody>
            </table>
          </div>

          <!-- PAGINATION -->
          <?php if ($totalPages > 1): ?>
            <div class="pager">
              <?php
              $qs = http_build_query(array_filter(['role' => $filterRole, 'action' => $filterAction, 'date' => $filterDate, 'user' => $filterUser]));
              for ($i = 1; $i <= $totalPages; $i++):
              ?>
                <?php if ($i === $page): ?>
                  <span class="cur"><?= $i ?></span>
                <?php else: ?>
                  <a href="?p=<?= $i ?>&<?= $qs ?>"><?= $i ?></a>
                <?php endif; ?>
              <?php endfor; ?>
              <span style="font-size:.75rem;color:var(--subtle);margin-left:.25rem"><?= $totalRows ?> records</span>
            </div>
          <?php endif; ?>
        </div>

        <!-- ── PAGE VISITS (all roles + IP) ───────────────────────────────── -->
        <div id="tab-visits" style="display:none">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.85rem;flex-wrap:wrap;gap:.5rem">
            <p style="font-size:.78rem;color:var(--muted)">
              Showing last <strong><?= count($visits) ?></strong> page visits across all roles.
            </p>
            <div style="display:flex;gap:.4rem">
              <span class="badge" style="background:#eff6ff;color:#2563eb;border-color:#bfdbfe">Admin</span>
              <span class="badge" style="background:#f5f3ff;color:#7c3aed;border-color:#ddd6fe">Manager</span>
              <span class="badge" style="background:#ecfdf5;color:#059669;border-color:#a7f3d0">User</span>
            </div>
          </div>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Role</th>
                  <th>Page</th>
                  <th>IP Address</th>
                  <th>When</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($visits)): ?>
                  <tr>
                    <td colspan="5" style="text-align:center;color:var(--subtle);padding:1.5rem 0">No visits recorded yet.</td>
                  </tr>
                  <?php else: foreach ($visits as $v): ?>
                    <tr>
                      <td>
                        <div style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($v['username'] ?? 'Guest') ?></div>
                        <div style="font-size:.7rem;color:var(--subtle)"><?= htmlspecialchars($v['user_email'] ?? '') ?></div>
                      </td>
                      <td><?= roleBadge($v['role'] ?? 'user') ?></td>
                      <td>
                        <div style="font-size:.8rem;font-weight:600"><?= htmlspecialchars($v['page_label'] ?? '—') ?></div>
                        <div style="font-size:.7rem;color:var(--subtle)"><?= htmlspecialchars($v['page']) ?></div>
                      </td>
                      <td><span class="ip-chip"><?= htmlspecialchars($v['ip_address'] ?? '—') ?></span></td>
                      <td style="font-size:.75rem;color:var(--muted);white-space:nowrap" title="<?= $v['visited_at'] ?>"><?= timeAgo($v['visited_at']) ?></td>
                    </tr>
                <?php endforeach;
                endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- ── SYSTEM ALERTS ───────────────────────────────────────────────── -->
        <div id="tab-alerts" style="display:none">
          <?php if (empty($alerts)): ?>
            <p style="color:var(--subtle);font-size:.82rem;text-align:center;padding:1.5rem 0">✅ No unresolved alerts.</p>
            <?php else: foreach ($alerts as $al):
              $dotColor = match ($al['severity']) {
                'critical' => '#dc2626',
                'warning' => '#d97706',
                default => '#16a34a'
              };
            ?>
              <div class="alert-item">
                <span class="alert-dot" style="background:<?= $dotColor ?>"></span>
                <div style="flex:1">
                  <div style="font-size:.82rem;font-weight:600"><?= htmlspecialchars($al['message']) ?></div>
                  <div style="font-size:.72rem;color:var(--muted);margin-top:.15rem">
                    <?= severityBadge($al['severity']) ?>
                    <?php if ($al['tankname']): ?>
                      <span style="margin-left:.35rem">Tank: <?= htmlspecialchars($al['tankname']) ?></span>
                    <?php endif; ?>
                    <span style="margin-left:.35rem"><?= timeAgo($al['created_at']) ?></span>
                  </div>
                </div>
                <a href="?resolve=<?= $al['alert_id'] ?>" class="btn-sm btn-ghost" style="font-size:.72rem;display:inline-flex;align-items:center;text-decoration:none">Resolve</a>
              </div>
          <?php endforeach;
          endif; ?>
        </div>

      </div><!-- /card -->

    </main>
  </div>

  <?php
  // Handle alert resolve
  if (isset($_GET['resolve']) && isAdmin()) {
    $aid = (int)$_GET['resolve'];
    $pdo->prepare("UPDATE system_alerts SET is_resolved=1, resolved_at=NOW() WHERE alert_id=:id")->execute([':id' => $aid]);
    logActivity('alert_resolved', 'success', 'alerts', "Resolved alert #$aid");
    header("Location: " . BASE_URL . "/App/Admin/admin_oversight.php");
    exit;
  }
  ?>

  <script>
    function toggleSidebar() {
      document.getElementById('sidebar').classList.toggle('open');
      document.getElementById('overlay').classList.toggle('show');
    }

    function closeSidebar() {
      document.getElementById('sidebar').classList.remove('open');
      document.getElementById('overlay').classList.remove('show');
    }

    // Tabs
    function switchTab(id, el) {
      document.querySelectorAll('[id^="tab-"]').forEach(t => t.style.display = 'none');
      document.getElementById('tab-' + id).style.display = '';
      document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
      el.classList.add('active');
    }

    // Role activity chart
    new Chart(document.getElementById('roleChart').getContext('2d'), {
      type: 'line',
      data: {
        labels: <?= json_encode($roleChartLabels) ?>,
        datasets: [{
            label: 'Admin',
            data: <?= json_encode($roleChartAdmin) ?>,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59,130,246,.08)',
            tension: .4,
            fill: true,
            borderWidth: 2,
            pointRadius: 3
          },
          {
            label: 'Manager',
            data: <?= json_encode($roleChartManager) ?>,
            borderColor: '#8b5cf6',
            backgroundColor: 'rgba(139,92,246,.06)',
            tension: .4,
            fill: true,
            borderWidth: 2,
            pointRadius: 3
          },
          {
            label: 'User',
            data: <?= json_encode($roleChartUser) ?>,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16,185,129,.06)',
            tension: .4,
            fill: true,
            borderWidth: 2,
            pointRadius: 3
          },
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            labels: {
              font: {
                family: 'DM Sans',
                size: 11
              },
              color: '#64748b',
              boxWidth: 12,
              boxHeight: 4,
              borderRadius: 2,
              useBorderRadius: true
            }
          }
        },
        scales: {
          x: {
            grid: {
              display: false
            },
            ticks: {
              color: '#94a3b8',
              font: {
                family: 'DM Sans',
                size: 11
              }
            }
          },
          y: {
            beginAtZero: true,
            grid: {
              color: '#f1f5f9'
            },
            ticks: {
              color: '#94a3b8',
              font: {
                family: 'DM Sans',
                size: 11
              }
            }
          }
        }
      }
    });

    // Action frequency chart
    new Chart(document.getElementById('actionChart').getContext('2d'), {
      type: 'bar',
      data: {
        labels: <?= json_encode($chartActionLabels) ?>,
        datasets: [{
          label: 'Count',
          data: <?= json_encode($chartActionData) ?>,
          backgroundColor: ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#ec4899', '#6366f1'],
          borderWidth: 0,
          borderRadius: 6,
          borderSkipped: false,
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false
          }
        },
        scales: {
          x: {
            grid: {
              color: '#f1f5f9'
            },
            ticks: {
              color: '#94a3b8',
              font: {
                family: 'DM Sans',
                size: 10
              }
            }
          },
          y: {
            grid: {
              display: false
            },
            ticks: {
              color: '#374151',
              font: {
                family: 'DM Sans',
                size: 11
              }
            }
          }
        }
      }
    });
  </script>
</body>

</html>