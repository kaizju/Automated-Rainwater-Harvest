<?php
require_once __DIR__ . '../../../Connections/config.php';
require_once __DIR__ . '../../../Connections/functions.php';
require_once __DIR__ . '../../../Others/activity_logger.php';

requireLogin();

$user_id = $_SESSION['user_id'];
$success = '';
$error   = '';

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) { redirect('/login.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_email    = trim($_POST['email']    ?? '');
    $new_password = trim($_POST['password'] ?? '');
    $confirm_pass = trim($_POST['confirm_password'] ?? '');

    if (empty($new_email) || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!empty($new_password) && $new_password !== $confirm_pass) {
        $error = 'Passwords do not match.';
    } elseif (!empty($new_password) && strlen($new_password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->execute([$new_email, $user_id]);
        if ($check->fetch()) {
            $error = 'That email is already in use by another account.';
        } else {
            if (!empty($new_password)) {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE users SET email = ?, password = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$new_email, $hashed, $user_id]);
            } else {
                $upd = $pdo->prepare("UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$new_email, $user_id]);
            }
            $_SESSION['email'] = $new_email;
            logActivity($pdo, $user_id, $new_email, 'profile_update', 'success');
            $success = 'Profile updated successfully.';
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
}

$logs_stmt = $pdo->prepare("SELECT action, status, ip_address, created_at FROM user_activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$logs_stmt->execute([$user_id]);
$logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM user_activity_logs WHERE user_id = ?");
$total_stmt->execute([$user_id]);
$total_logs = $total_stmt->fetchColumn();

$success_stmt = $pdo->prepare("SELECT COUNT(*) FROM user_activity_logs WHERE user_id = ? AND status = 'success'");
$success_stmt->execute([$user_id]);
$success_logs = $success_stmt->fetchColumn();

$failed_stmt = $pdo->prepare("SELECT COUNT(*) FROM user_activity_logs WHERE user_id = ? AND status = 'failed'");
$failed_stmt->execute([$user_id]);
$failed_logs = $failed_stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EcoRain — My Profile</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            min-height: 100vh;
            padding: 0;
        }

        /* TOP NAV BAR */
        .top-nav {
            background: #0f172a;
            padding: 0 1.5rem;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .top-nav-logo { display: flex; align-items: center; gap: .6rem; text-decoration: none; }
        .logo-drop { width: 32px; height: 32px; background: linear-gradient(160deg,#60a5fa,#2563eb); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .logo-name { font-family: 'Space Grotesk', sans-serif; font-size: 1rem; font-weight: 700; color: #fff; }
        .top-nav-links { display: flex; align-items: center; gap: .5rem; }
        .top-nav-link { display: flex; align-items: center; gap: .45rem; padding: .45rem .75rem; border-radius: 7px; font-size: .82rem; font-weight: 500; color: #94a3b8; text-decoration: none; transition: background .15s, color .15s; white-space: nowrap; }
        .top-nav-link:hover { background: rgba(255,255,255,.07); color: #e2e8f0; }
        .top-nav-link svg { width: 15px; height: 15px; flex-shrink: 0; }
        .logout-link:hover { background: rgba(239,68,68,.12); color: #fca5a5; }
        .top-nav-link.hide-mobile { display: flex; }

        /* CONTENT */
        .page-wrap {
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 1.25rem 3rem;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        /* PAGE HEADER */
        .page-header { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
        .back-btn { display: flex; align-items: center; gap: .5rem; color: #64748b; text-decoration: none; font-size: .85rem; font-weight: 500; transition: color .2s; flex-shrink: 0; }
        .back-btn:hover { color: #1e293b; }
        .page-header h1 { font-family: 'Space Grotesk', sans-serif; font-size: 1.5rem; font-weight: 700; color: #1e293b; }
        .role-badge { margin-left: auto; background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 20px; padding: 4px 14px; font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }

        /* ALERTS */
        .alert { display: flex; align-items: center; gap: .6rem; padding: .85rem 1rem; border-radius: 10px; font-size: .875rem; font-weight: 500; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #16a34a; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }

        /* CARDS */
        .card { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; padding: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
        .card-title { font-size: .72rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: #94a3b8; margin-bottom: 1.25rem; display: flex; align-items: center; gap: .6rem; }
        .card-title::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }

        /* STAT ROW */
        .stat-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: .85rem; margin-bottom: 1.25rem; }
        .stat-chip { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1rem 1.1rem; text-align: center; }
        .stat-chip .num { font-family: 'Space Grotesk', sans-serif; font-size: 1.4rem; font-weight: 700; color: #1e293b; }
        .stat-chip .lbl { font-size: .68rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; margin-top: 3px; }
        .stat-chip.blue  .num { color: #2563eb; }
        .stat-chip.green .num { color: #16a34a; }
        .stat-chip.amber .num { color: #d97706; }

        /* INFO GRID */
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .info-item label { display: block; font-size: .72rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #64748b; margin-bottom: 5px; }
        .info-item .value { font-size: .9rem; font-weight: 500; color: #1e293b; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: .65rem .9rem; word-break: break-all; }
        .info-item .value.verified   { color: #16a34a; }
        .info-item .value.unverified { color: #ef4444; }

        /* FORM */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-group { display: flex; flex-direction: column; gap: .4rem; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-size: .72rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #64748b; }
        .form-group input { background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: .65rem .9rem; color: #1e293b; font-size: .9rem; font-family: 'Inter', sans-serif; outline: none; width: 100%; transition: border-color .2s, box-shadow .2s; }
        .form-group input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
        .form-group input::placeholder { color: #b0bac9; }
        .form-hint { font-size: .72rem; color: #94a3b8; }

        /* SAVE BTN */
        .save-btn { background: #2563eb; color: #fff; border: none; border-radius: 8px; padding: .7rem 2rem; font-size: .9rem; font-weight: 600; font-family: 'Inter', sans-serif; cursor: pointer; transition: background .2s, transform .1s; margin-top: .5rem; display: inline-flex; align-items: center; gap: .5rem; }
        .save-btn:hover  { background: #1d4ed8; }
        .save-btn:active { transform: translateY(1px); }

        /* LOG TABLE */
        .log-scroll { overflow-x: auto; }
        .log-table { width: 100%; border-collapse: collapse; font-size: .85rem; min-width: 440px; }
        .log-table th { font-size: .68rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #94a3b8; text-align: left; padding: 0 1rem .65rem; border-bottom: 1px solid #e2e8f0; }
        .log-table td { padding: .75rem 1rem; color: #374151; border-bottom: 1px solid #f1f5f9; font-size: .83rem; }
        .log-table tr:last-child td { border-bottom: none; }
        .log-table tr:hover td { background: #f8fafc; }

        .status-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: .72rem; font-weight: 600; }
        .status-pill::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .status-pill.success { background: rgba(22,163,74,.1); color: #16a34a; }
        .status-pill.failed  { background: rgba(239,68,68,.1);  color: #ef4444; }

        .action-tag { background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px; padding: 2px 8px; font-size: .78rem; color: #2563eb; }

        /* SESSION CARD */
        .danger-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        .danger-row p { font-size: .875rem; color: #64748b; }
        .danger-row strong { color: #1e293b; display: block; margin-bottom: .2rem; }
        .logout-btn { display: inline-flex; align-items: center; gap: 8px; background: rgba(239,68,68,.08); color: #ef4444; border: 1px solid rgba(239,68,68,.25); border-radius: 8px; padding: .6rem 1.35rem; font-size: .875rem; font-weight: 600; font-family: 'Inter', sans-serif; text-decoration: none; cursor: pointer; transition: background .2s; }
        .logout-btn:hover { background: rgba(239,68,68,.16); }

        /* RESPONSIVE */
        @media (max-width: 640px) {
            .top-nav-link.hide-mobile { display: none; }
            .form-grid  { grid-template-columns: 1fr; }
            .info-grid  { grid-template-columns: 1fr; }
            .stat-row   { grid-template-columns: 1fr 1fr; }
            .form-group.full { grid-column: 1; }
            .page-header h1 { font-size: 1.2rem; }
            .card { padding: 1.1rem; }
        }
        @media (max-width: 400px) {
            .stat-row { grid-template-columns: 1fr; }
            .top-nav { padding: 0 1rem; }
        }
    </style>
</head>
<body>

<!-- TOP NAV -->
<nav class="top-nav">
    <a href="<?php echo BASE_URL; ?>/App/User/dashboard.php" class="top-nav-logo">
        <div class="logo-drop">💧</div>
        <span class="logo-name">EcoRain</span>
    </a>
    <div class="top-nav-links">
        <a href="<?php echo BASE_URL; ?>/App/User/dashboard.php" class="top-nav-link hide-mobile">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        <a href="<?php echo BASE_URL; ?>/App/User/usage.php" class="top-nav-link hide-mobile">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Usage
        </a>
        <a href="<?php echo BASE_URL; ?>/App/User/weather.php" class="top-nav-link hide-mobile">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
            Weather
        </a>
        <a href="<?php echo BASE_URL; ?>/Connections/signout.php" class="top-nav-link logout-link">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            Log Out
        </a>
    </div>
</nav>

<div class="page-wrap">

    <!-- HEADER -->
    <div class="page-header">
        <a href="<?php echo BASE_URL; ?>/App/User/dashboard.php" class="back-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
            Back
        </a>
        <h1>My Profile</h1>
        <span class="role-badge"><?php echo htmlspecialchars($user['role']); ?></span>
    </div>

    <!-- ALERTS -->
    <?php if ($success): ?>
        <div class="alert alert-success">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- ACCOUNT INFO -->
    <div class="card">
        <div class="card-title">Account Information</div>

        <div class="stat-row">
            <div class="stat-chip blue">
                <div class="num"><?php echo $total_logs; ?></div>
                <div class="lbl">Total Actions</div>
            </div>
            <div class="stat-chip green">
                <div class="num"><?php echo $success_logs; ?></div>
                <div class="lbl">Successful</div>
            </div>
            <div class="stat-chip amber">
                <div class="num"><?php echo $failed_logs; ?></div>
                <div class="lbl">Failed</div>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-item">
                <label>User ID</label>
                <div class="value">#<?php echo htmlspecialchars($user['id']); ?></div>
            </div>
            <div class="info-item">
                <label>Email</label>
                <div class="value"><?php echo htmlspecialchars($user['email']); ?></div>
            </div>
            <div class="info-item">
                <label>Role</label>
                <div class="value"><?php echo ucfirst(htmlspecialchars($user['role'])); ?></div>
            </div>
            <div class="info-item">
                <label>Verified</label>
                <div class="value <?php echo $user['is_verified'] ? 'verified' : 'unverified'; ?>">
                    <?php echo $user['is_verified'] ? '✓ Verified' : '✗ Not Verified'; ?>
                </div>
            </div>
            <div class="info-item">
                <label>Member Since</label>
                <div class="value"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></div>
            </div>
            <div class="info-item">
                <label>Last Updated</label>
                <div class="value"><?php echo date('M d, Y', strtotime($user['updated_at'])); ?></div>
            </div>
        </div>
    </div>

    <!-- EDIT PROFILE -->
    <div class="card">
        <div class="card-title">Edit Profile</div>
        <form method="POST">
            <div class="form-grid">
                <div class="form-group full">
                    <label>Email Address</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required autocomplete="email">
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="password" placeholder="Leave blank to keep current" autocomplete="new-password">
                    <span class="form-hint">Minimum 6 characters</span>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" placeholder="Repeat new password" autocomplete="new-password">
                </div>
            </div>
            <button type="submit" name="update_profile" class="save-btn">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Save Changes
            </button>
        </form>
    </div>

    <!-- RECENT ACTIVITY -->
    <div class="card">
        <div class="card-title">Recent Activity</div>
        <?php if (empty($logs)): ?>
            <p style="color:#94a3b8;font-size:.875rem;">No activity recorded yet.</p>
        <?php else: ?>
            <div class="log-scroll">
            <table class="log-table">
                <thead>
                    <tr><th>Action</th><th>Status</th><th>IP Address</th><th>Time</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><span class="action-tag"><?php echo htmlspecialchars($log['action']); ?></span></td>
                            <td><span class="status-pill <?php echo $log['status']; ?>"><?php echo ucfirst($log['status']); ?></span></td>
                            <td><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
                            <td><?php echo date('M d, H:i', strtotime($log['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- SESSION -->
    <div class="card">
        <div class="card-title">Session</div>
        <div class="danger-row">
            <div>
                <strong>Sign out of EcoRain</strong>
                <p>You will be redirected to the login page.</p>
            </div>
            <a href="<?php echo BASE_URL; ?>/Connections/signout.php" class="logout-btn">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                Log Out
            </a>
        </div>
    </div>

</div>
</body>
<link rel="stylesheet" href="/Others/all.css">
</html>