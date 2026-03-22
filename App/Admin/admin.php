<?php
require_once '../../Connections/config.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../../index.php');
    exit;
}

/* ── STAT CARDS ── */
$totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeToday = (int) $pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_activity_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$actionsToday = (int) $pdo->query("SELECT COUNT(*) FROM user_activity_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$failedLogins = (int) $pdo->query("SELECT COUNT(*) FROM user_activity_logs WHERE action = 'login' AND status = 'failed' AND DATE(created_at) = CURDATE()")->fetchColumn();

$newUsersThisMonth = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();

$actionsYesterday = (int) $pdo->query("SELECT COUNT(*) FROM user_activity_logs WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetchColumn();
$actionsPctChange = $actionsYesterday > 0 ? round(($actionsToday - $actionsYesterday) / $actionsYesterday * 100, 1) : 0;

/* ── ROLE BREAKDOWN ── */
$roleRows = $pdo->query("SELECT role, COUNT(*) AS cnt FROM users GROUP BY role")->fetchAll(PDO::FETCH_ASSOC);
$roleCounts = ['admin' => 0, 'manager' => 0, 'user' => 0];
foreach ($roleRows as $r) {
    $roleCounts[$r['role']] = (int) $r['cnt'];
}

/* ── 7-DAY ACTIVITY BY ROLE ── */
$activity7 = $pdo->query("
    SELECT DATE(ual.created_at) AS day_date,
           COALESCE(u.role,'user') AS role,
           COUNT(*) AS cnt
    FROM user_activity_logs ual
    LEFT JOIN users u ON ual.user_id = u.id
    WHERE ual.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(ual.created_at), COALESCE(u.role,'user')
    ORDER BY day_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

$chartLabels = [];
$chartAdmin = [];
$chartMgr = [];
$chartUser = [];
$mapAdmin = $mapMgr = $mapUser = [];
foreach ($activity7 as $row) {
    if ($row['role'] === 'admin')
        $mapAdmin[$row['day_date']] = (int) $row['cnt'];
    if ($row['role'] === 'manager')
        $mapMgr[$row['day_date']] = (int) $row['cnt'];
    if ($row['role'] === 'user')
        $mapUser[$row['day_date']] = (int) $row['cnt'];
}
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('D', strtotime($d));
    $chartAdmin[] = $mapAdmin[$d] ?? 0;
    $chartMgr[] = $mapMgr[$d] ?? 0;
    $chartUser[] = $mapUser[$d] ?? 0;
}

/* ── RECENT USERS (last 8) ── */
$recentUsers = $pdo->query("
    SELECT u.id, u.email, u.role, u.created_at,
           ual.action  AS last_action,
           ual.created_at AS last_time
    FROM users u
    LEFT JOIN user_activity_logs ual ON ual.activity_id = (
        SELECT activity_id FROM user_activity_logs
        WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1
    )
    ORDER BY COALESCE(ual.created_at, u.created_at) DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

/* ── LIVE ACTIVITY FEED (last 10 entries) ── */
$feedRows = $pdo->query("
    SELECT ual.action, ual.status, ual.created_at, ual.ip_address,
           COALESCE(u.email, ual.email) AS user_email,
           COALESCE(u.role,'—') AS role
    FROM user_activity_logs ual
    LEFT JOIN users u ON ual.user_id = u.id
    ORDER BY ual.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

/* ── FULL AUDIT LOG (last 15) ── */
$auditLog = $pdo->query("
    SELECT ual.activity_id, ual.user_id, ual.action, ual.status,
           ual.ip_address, ual.created_at,
           COALESCE(u.email, ual.email, '—') AS user_email,
           COALESCE(u.role,'—') AS role
    FROM user_activity_logs ual
    LEFT JOIN users u ON ual.user_id = u.id
    ORDER BY ual.created_at DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

/* ── TOP ACTIONS TODAY ── */
$topActions = $pdo->query("
    SELECT action, COUNT(*) AS cnt
    FROM user_activity_logs
    WHERE DATE(created_at) = CURDATE()
    GROUP BY action ORDER BY cnt DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
$maxAction = $topActions ? max(array_column($topActions, 'cnt')) : 1;

/* ── FLAGGED ACCOUNTS (5+ failed logins or unusual IPs) ── */
$flagged = $pdo->query("
    SELECT u.email, u.id,
           COUNT(*) AS fail_count,
           MAX(ual.created_at) AS last_seen,
           MAX(ual.ip_address) AS ip
    FROM user_activity_logs ual
    JOIN users u ON ual.user_id = u.id
    WHERE ual.status = 'failed'
      AND ual.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY u.id, u.email
    HAVING fail_count >= 3
    ORDER BY fail_count DESC
    LIMIT 3
")->fetchAll(PDO::FETCH_ASSOC);

/* ── SENSOR + SYSTEM STATUS ── */
$lastSensor = $pdo->query("SELECT MAX(recorded_at) AS t FROM sensor_readings")->fetchColumn();
$sensorDelay = $lastSensor ? round((time() - strtotime($lastSensor)) / 60) : null;

/* ── HELPERS ── */
function timeAgo($ts)
{
    if (!$ts)
        return 'N/A';
    $diff = time() - strtotime($ts);
    if ($diff < 60)
        return $diff . 's ago';
    if ($diff < 3600)
        return floor($diff / 60) . 'm ago';
    if ($diff < 86400)
        return floor($diff / 3600) . 'h ago';
    return date('M j', strtotime($ts));
}
function initials($email)
{
    $parts = explode('@', $email);
    $name = explode('.', $parts[0]);
    $ini = strtoupper(substr($name[0], 0, 1));
    if (isset($name[1]))
        $ini .= strtoupper(substr($name[1], 0, 1));
    else
        $ini .= strtoupper(substr($parts[0], 1, 1));
    return $ini;
}
$avatarColors = ['#3b82f6', '#7c3aed', '#10b981', '#ef4444', '#f59e0b', '#06b6d4', '#ec4899', '#8b5cf6'];
function avatarColor($id)
{
    global $avatarColors;
    return $avatarColors[$id % count($avatarColors)];
}

$me = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$me->execute([$_SESSION['user_id']]);
$me = $me->fetch(PDO::FETCH_ASSOC);
$initials = strtoupper(substr($me['email'] ?? 'AD', 0, 2));

// JSON for charts
$chartLabelsJson = json_encode($chartLabels);
$chartAdminJson = json_encode($chartAdmin);
$chartMgrJson = json_encode($chartMgr);
$chartUserJson = json_encode($chartUser);
$roleChartJson = json_encode(array_values($roleCounts));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EcoRain — Admin Oversight</title>
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&display=swap"
        rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/Others/all.css">
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

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="logo">
            <span class="logo-icon">💧</span>
            <span class="logo-text">EcoRain</span>
        </div>

        <div class="nav-section-label">Overview</div>
        <a href="<?= BASE_URL ?>/App/Admin/admin_dashboard.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7" rx="1" />
                <rect x="14" y="3" width="7" height="7" rx="1" />
                <rect x="3" y="14" width="7" height="7" rx="1" />
                <rect x="14" y="14" width="7" height="7" rx="1" />
            </svg>
            Dashboard
        </a>
        <a href="<?= BASE_URL ?>/App/Admin/admin.php" class="nav-item active">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                <circle cx="12" cy="12" r="3" />
            </svg>
            Admin Oversight
        </a>
        <a href="<?= BASE_URL ?>/App/Admin/admin_usage.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
            </svg>
            Usage Stats
        </a>
        <a href="<?= BASE_URL ?>/App/Admin/admin_weather.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
            </svg>
            Weather
        </a>

         <div class="nav-section-label">Management</div>
        
        <a href="<?= BASE_URL ?>/App/Admin/admin_userlogs.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 00-3-3.87" />
                <path d="M16 3.13a4 4 0 010 7.75" />
            </svg>
            Users &amp; Roles
        </a>
        <a href="<?= BASE_URL ?>/App/Admin/admin_settings.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="3" />
                <path
                    d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" />
            </svg>
            Settings
        </a>

        <div class="sidebar-spacer"></div>
        <div class="sidebar-bottom">
            <a href="<?= BASE_URL ?>/Connections/signout.php" class="nav-item logout">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9" />
                </svg>
                Log Out
            </a>
        </div>
    </aside>

    <!-- APP BODY -->
    <div class="app-body">

        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" onclick="toggleSidebar()" aria-label="Menu">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <line x1="3" y1="6" x2="21" y2="6" />
                        <line x1="3" y1="12" x2="21" y2="12" />
                        <line x1="3" y1="18" x2="21" y2="18" />
                    </svg>
                </button>
                <div>
                    <div class="page-title">Admin Oversight</div>
                    <div class="page-sub">All user &amp; manager activity — EcoRain</div>
                </div>
            </div>
            <div class="topbar-right">
                <div class="t-search">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>
                    <input type="text" placeholder="Search users, actions…" />
                </div>
                <div class="t-btn">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9" />
                        <path d="M13.73 21a2 2 0 01-3.46 0" />
                    </svg>
                    <?php if ($failedLogins > 0): ?><span class="notif-dot"></span><?php endif; ?>
                </div>
                <a href="<?= BASE_URL ?>/App/Users/user.php" class="t-avatar"><?= htmlspecialchars($initials) ?></a>
            </div>
        </header>

        <main class="main">

            <!-- ── STAT CARDS ── -->
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-lbl">Total Users</div>
                        <div class="stat-icon" style="background:#eff6ff">
                            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#3b82f6"
                                stroke-width="2">
                                <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
                                <circle cx="9" cy="7" r="4" />
                                <path d="M23 21v-2a4 4 0 00-3-3.87" />
                                <path d="M16 3.13a4 4 0 010 7.75" />
                            </svg>
                        </div>
                    </div>
                    <div class="stat-val"><?= number_format($totalUsers) ?></div>
                    <div class="stat-foot">
                        <span class="up">↑ <?= $newUsersThisMonth ?></span>
                        <span>this month</span>
                    </div>
                    <div class="stat-glow" style="background:#3b82f6"></div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-lbl">Active Today</div>
                        <div class="stat-icon" style="background:#ecfdf5">
                            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#10b981"
                                stroke-width="2">
                                <circle cx="12" cy="12" r="10" />
                                <polyline points="12 6 12 12 16 14" />
                            </svg>
                        </div>
                    </div>
                    <div class="stat-val"><?= number_format($activeToday) ?></div>
                    <div class="stat-foot"><span>unique users active</span></div>
                    <div class="stat-glow" style="background:#10b981"></div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-lbl">Actions Today</div>
                        <div class="stat-icon" style="background:#faf5ff">
                            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#7c3aed"
                                stroke-width="2">
                                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
                            </svg>
                        </div>
                    </div>
                    <div class="stat-val"><?= number_format($actionsToday) ?></div>
                    <div class="stat-foot">
                        <?php if ($actionsPctChange >= 0): ?>
                            <span class="up">↑ <?= abs($actionsPctChange) ?>%</span>
                        <?php else: ?>
                            <span class="down">↓ <?= abs($actionsPctChange) ?>%</span>
                        <?php endif; ?>
                        <span>vs yesterday</span>
                    </div>
                    <div class="stat-glow" style="background:#7c3aed"></div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-lbl">Failed Logins</div>
                        <div class="stat-icon" style="background:#fef2f2">
                            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#ef4444"
                                stroke-width="2">
                                <circle cx="12" cy="12" r="10" />
                                <line x1="12" y1="8" x2="12" y2="12" />
                                <line x1="12" y1="16" x2="12.01" y2="16" />
                            </svg>
                        </div>
                    </div>
                    <div class="stat-val"><?= number_format($failedLogins) ?></div>
                    <div class="stat-foot">
                        <?php if ($failedLogins > 5): ?>
                            <span class="down">⚠ needs review</span>
                        <?php else: ?>
                            <span>today</span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-glow" style="background:#ef4444"></div>
                </div>
            </div>

            <!-- ── MID ROW: Chart + Role Breakdown ── -->
            <div class="mid-row">

                <!-- Stacked bar chart -->
                <div class="card">
                    <div class="chart-title">
                        Activity — Last 7 Days
                        <span class="chart-pill">Actions / Day</span>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="activityChart"></canvas>
                    </div>
                </div>

                <!-- Role breakdown -->
                <div class="card">
                    <div class="card-label">Role Breakdown</div>
                    <div style="height:140px;position:relative;margin-bottom:1rem">
                        <canvas id="roleChart"></canvas>
                    </div>
                    <?php
                    $totalForPct = max(1, $totalUsers);
                    $roles = [
                        ['label' => 'Admins', 'count' => $roleCounts['admin'], 'color' => '#3b82f6'],
                        ['label' => 'Managers', 'count' => $roleCounts['manager'], 'color' => '#7c3aed'],
                        ['label' => 'Users', 'count' => $roleCounts['user'], 'color' => '#10b981'],
                    ];
                    foreach ($roles as $ro):
                        $pct = round($ro['count'] / $totalForPct * 100);
                        ?>
                        <div class="prog-row">
                            <span class="prog-label"><?= $ro['label'] ?></span>
                            <div class="prog-bar-bg">
                                <div class="prog-bar-fill" style="width:<?= $pct ?>%;background:<?= $ro['color'] ?>"></div>
                            </div>
                            <span class="prog-val"><?= number_format($ro['count']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>

            <!-- ── BOTTOM ROW: Recent Users + Live Feed ── -->
            <div class="bottom-row">

                <!-- Recent Users -->
                <div class="card">
                    <div class="card-label-row">
                        <span class="card-label" style="margin-bottom:0">Recent Users</span>
                        <span class="badge badge-blue" style="text-transform:none;font-size:.68rem">Last 8</span>
                    </div>
                    <div style="overflow-x:auto">
                        <table class="mini-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Last Action</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentUsers as $u):
                                    $ini = initials($u['email']);
                                    $color = avatarColor((int) $u['id']);
                                    $roleClass = 'role-' . ($u['role'] === 'admin' ? 'admin' : ($u['role'] === 'manager' ? 'manager' : 'user'));
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:.55rem">
                                                <div class="user-avatar" style="background:<?= $color ?>">
                                                    <?= htmlspecialchars($ini) ?></div>
                                                <span
                                                    style="font-size:.8rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:130px"><?= htmlspecialchars($u['email']) ?></span>
                                            </div>
                                        </td>
                                        <td><span
                                                class="role-badge <?= $roleClass ?>"><?= htmlspecialchars($u['role']) ?></span>
                                        </td>
                                        <td style="font-size:.78rem;color:var(--muted)">
                                            <?= htmlspecialchars(str_replace('_', ' ', $u['last_action'] ?? '—')) ?></td>
                                        <td style="font-size:.73rem;color:var(--subtle)">
                                            <?= timeAgo($u['last_time'] ?? $u['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recentUsers)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align:center;color:var(--subtle);padding:1.5rem">No
                                            users found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Live Activity Feed -->
                <div class="card">
                    <div class="card-label-row">
                        <span class="card-label" style="margin-bottom:0">Live Activity Feed</span>
                        <span
                            style="display:flex;align-items:center;gap:.35rem;font-size:.7rem;font-weight:600;color:#10b981">
                            <span class="live-dot"></span> Live
                        </span>
                    </div>
                    <?php
                    $feedColors = ['login' => '#10b981', 'logout' => '#94a3b8', 'update_settings' => '#3b82f6', 'edit_user' => '#7c3aed', 'add_user' => '#3b82f6', 'delete_user' => '#ef4444', 'profile_update' => '#f59e0b', 'login_attempt' => '#ef4444'];
                    foreach ($feedRows as $f):
                        $dot = $feedColors[$f['action']] ?? ($f['status'] === 'failed' ? '#ef4444' : '#64748b');
                        $emailDisplay = $f['user_email'] ? explode('@', $f['user_email'])[0] : 'system';
                        ?>
                        <div class="feed-item">
                            <div class="feed-dot" style="background:<?= $dot ?>"></div>
                            <div class="feed-meta">
                                <div class="feed-action">
                                    <span class="feed-user"><?= htmlspecialchars($emailDisplay) ?></span>
                                    <?= htmlspecialchars(str_replace('_', ' ', $f['action'])) ?>
                                    <?php if ($f['status'] === 'failed'): ?>
                                        <span class="badge badge-red" style="font-size:.65rem;margin-left:.25rem">failed</span>
                                    <?php endif; ?>
                                </div>
                                <div class="feed-time"><?= timeAgo($f['created_at']) ?> ·
                                    <?= htmlspecialchars($f['ip_address'] ?? '') ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($feedRows)): ?>
                        <p style="color:var(--subtle);font-size:.82rem;margin-top:.35rem">No activity yet.</p>
                    <?php endif; ?>
                </div>

            </div>

            <!-- ── FULL AUDIT LOG ── -->
            <div class="card">
                <div class="card-label-row">
                    <span class="card-label" style="margin-bottom:0">Full Audit Log</span>
                    <div style="display:flex;gap:.5rem">
                        <span class="badge badge-green">Success</span>
                        <span class="badge badge-red">Failed</span>
                    </div>
                </div>
                <div style="overflow-x:auto">
                    <table class="mini-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>User</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th>Status</th>
                                <th>IP Address</th>
                                <th>Date &amp; Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auditLog as $log):
                                $sc = $log['status'] === 'success' ? 'badge-green' : 'badge-red';
                                $roleC = $log['role'] === 'admin' ? 'role-admin' : ($log['role'] === 'manager' ? 'role-manager' : 'role-user');
                                ?>
                                <tr>
                                    <td style="color:var(--subtle);font-size:.75rem">#<?= $log['activity_id'] ?></td>
                                    <td style="font-weight:600;font-size:.8rem;color:var(--accent)">
                                        <?= $log['user_id'] ? '#' . $log['user_id'] : '—' ?></td>
                                    <td
                                        style="font-size:.78rem;color:var(--muted);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                        <?= htmlspecialchars($log['user_email']) ?></td>
                                    <td><?php if ($log['role'] !== '—'): ?><span
                                                class="role-badge <?= $roleC ?>"><?= htmlspecialchars($log['role']) ?></span><?php else:
                                        echo '—';
                                    endif; ?></td>
                                    <td style="font-size:.78rem">
                                        <?= htmlspecialchars(str_replace('_', ' ', $log['action'])) ?></td>
                                    <td><span class="badge <?= $sc ?>"><?= htmlspecialchars($log['status']) ?></span></td>
                                    <td style="font-size:.75rem;color:var(--muted)">
                                        <?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                                    <td style="font-size:.73rem;color:var(--subtle)">
                                        <?= date('M j, H:i', strtotime($log['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($auditLog)): ?>
                                <tr>
                                    <td colspan="8" style="text-align:center;color:var(--subtle);padding:2rem">No audit logs
                                        found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── SYSTEM HEALTH + TOP ACTIONS + FLAGGED ── -->
            <div class="health-grid">

                <!-- System Health -->
                <div class="card">
                    <div class="card-label">System Health</div>
                    <div style="display:flex;flex-direction:column;gap:.65rem">
                        <div style="display:flex;justify-content:space-between;align-items:center;font-size:.83rem">
                            <span style="color:var(--muted)">Database</span>
                            <span class="badge badge-green">Online</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;font-size:.83rem">
                            <span style="color:var(--muted)">Weather API</span>
                            <span class="badge badge-green">Online</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;font-size:.83rem">
                            <span style="color:var(--muted)">Sensor Feed</span>
                            <?php if ($sensorDelay === null): ?>
                                <span class="badge badge-red">No Data</span>
                            <?php elseif ($sensorDelay > 30): ?>
                                <span class="badge badge-yellow">Delayed <?= $sensorDelay ?>m</span>
                            <?php else: ?>
                                <span class="badge badge-green">Live</span>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;font-size:.83rem">
                            <span style="color:var(--muted)">Total Log Entries</span>
                            <span
                                style="font-weight:700;color:var(--text)"><?= number_format((int) $pdo->query("SELECT COUNT(*) FROM user_activity_logs")->fetchColumn()) ?></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;font-size:.83rem">
                            <span style="color:var(--muted)">Registered Users</span>
                            <span style="font-weight:700;color:var(--text)"><?= number_format($totalUsers) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Top Actions Today -->
                <div class="card">
                    <div class="card-label">Top Actions Today</div>
                    <?php if ($topActions): ?>
                        <?php
                        $actionColors = ['#3b82f6', '#7c3aed', '#10b981', '#f59e0b', '#ef4444'];
                        foreach ($topActions as $idx => $a):
                            $pctBar = $maxAction > 0 ? round($a['cnt'] / $maxAction * 100) : 0;
                            ?>
                            <div class="prog-row">
                                <span class="prog-label"><?= htmlspecialchars(str_replace('_', ' ', $a['action'])) ?></span>
                                <div class="prog-bar-bg">
                                    <div class="prog-bar-fill"
                                        style="width:<?= $pctBar ?>%;background:<?= $actionColors[$idx % 5] ?>"></div>
                                </div>
                                <span class="prog-val"><?= number_format($a['cnt']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color:var(--subtle);font-size:.82rem">No actions recorded today.</p>
                    <?php endif; ?>
                </div>

                <!-- Flagged Accounts -->
                <div class="card">
                    <div class="card-label">Flagged Accounts</div>
                    <?php if ($flagged): ?>
                        <?php foreach ($flagged as $fl):
                            $flIni = initials($fl['email']);
                            $flColor = '#ef4444';
                            ?>
                            <div style="border:1px solid var(--border);border-radius:10px;padding:.75rem;margin-bottom:.65rem">
                                <div style="display:flex;align-items:center;gap:.6rem">
                                    <div class="user-avatar" style="background:<?= $flColor ?>"><?= htmlspecialchars($flIni) ?>
                                    </div>
                                    <div style="flex:1;min-width:0">
                                        <div
                                            style="font-size:.82rem;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                            <?= htmlspecialchars($fl['email']) ?></div>
                                        <div style="font-size:.7rem;color:var(--subtle)"><?= $fl['fail_count'] ?> failed logins
                                            · <?= timeAgo($fl['last_seen']) ?></div>
                                    </div>
                                    <span class="badge badge-red">Locked</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="display:flex;align-items:center;gap:.5rem;padding:.5rem 0">
                            <span style="color:#10b981;font-size:1rem">✓</span>
                            <span style="font-size:.83rem;color:var(--muted)">No flagged accounts in the last 24h.</span>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('overlay').classList.toggle('show');
        }
        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('overlay').classList.remove('show');
        }

        Chart.defaults.font.family = "'DM Sans', sans-serif";
        Chart.defaults.color = '#94a3b8';
        Chart.defaults.font.size = 11;

        // Stacked Bar Chart — 7-day activity by role
        new Chart(document.getElementById('activityChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= $chartLabelsJson ?>,
                datasets: [
                    {
                        label: 'Admin',
                        data: <?= $chartAdminJson ?>,
                        backgroundColor: 'rgba(59,130,246,0.85)',
                        borderRadius: 5, borderSkipped: false, stack: 's'
                    },
                    {
                        label: 'Manager',
                        data: <?= $chartMgrJson ?>,
                        backgroundColor: 'rgba(124,58,237,0.75)',
                        borderRadius: 0, borderSkipped: false, stack: 's'
                    },
                    {
                        label: 'User',
                        data: <?= $chartUserJson ?>,
                        backgroundColor: 'rgba(16,185,129,0.6)',
                        borderRadius: 0, borderSkipped: false, stack: 's'
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true, position: 'top', align: 'end',
                        labels: { font: { size: 10, family: 'DM Sans' }, color: '#94a3b8', boxWidth: 14, boxHeight: 6, borderRadius: 3, useBorderRadius: true }
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleFont: { family: 'Sora', size: 11 },
                        bodyFont: { family: 'DM Sans', size: 11 },
                        padding: 10, cornerRadius: 8
                    }
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { color: '#94a3b8' } },
                    y: { stacked: true, grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8' } }
                }
            }
        });

        // Doughnut Chart — Role breakdown
        new Chart(document.getElementById('roleChart').getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Admin', 'Manager', 'User'],
                datasets: [{
                    data: <?= $roleChartJson ?>,
                    backgroundColor: ['#3b82f6', '#7c3aed', '#10b981'],
                    borderWidth: 0, hoverOffset: 4
                }]
            },
            options: {
                cutout: '70%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw}` }
                    }
                }
            }
        });
    </script>

    <link rel="stylesheet" href="/Others/all.css">
</body>

</html>