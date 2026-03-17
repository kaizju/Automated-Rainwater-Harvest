<?php
require_once '../../Connections/config.php';

$action  = $_POST['action'] ?? '';
$success = '';
$error   = '';

if ($action === 'add') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role     = $_POST['role'] ?? 'user';
    if (!$email || !$password) { $error = 'Email and password are required.'; }
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Invalid email address.'; }
    else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, ?)");
            $stmt->execute([$email, $hash, $role]);
            $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, action, status, ip_address) VALUES (?, ?, 'add_user', 'success', ?)")
                ->execute([$pdo->lastInsertId(), $email, $_SERVER['REMOTE_ADDR'] ?? '']);
            $success = "User <strong>$email</strong> added successfully.";
        } catch (PDOException $e) {
            $error = strpos($e->getMessage(), 'Duplicate') !== false ? 'That email is already registered.' : 'Database error: ' . $e->getMessage();
        }
    }
}

if ($action === 'edit') {
    $id    = (int)($_POST['id'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    $role  = $_POST['role'] ?? 'user';
    if (!$id || !$email) { $error = 'Invalid data.'; }
    else {
        try {
            if (!empty($_POST['password'])) {
                $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET email=?, password=?, role=? WHERE id=?")->execute([$email, $hash, $role, $id]);
            } else {
                $pdo->prepare("UPDATE users SET email=?, role=? WHERE id=?")->execute([$email, $role, $id]);
            }
            $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, action, status, ip_address) VALUES (?, ?, 'edit_user', 'success', ?)")->execute([$id, $email, $_SERVER['REMOTE_ADDR'] ?? '']);
            $success = "User updated successfully.";
        } catch (PDOException $e) { $error = 'Database error: ' . $e->getMessage(); }
    }
}

if ($action === 'delete') {
    $ids = [];
    if (!empty($_POST['id'])) $ids = [(int)$_POST['id']];
    elseif (!empty($_POST['ids'])) $ids = array_map('intval', explode(',', $_POST['ids']));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)")->execute($ids);
            $pdo->prepare("INSERT INTO user_activity_logs (action, status, ip_address) VALUES ('delete_user', 'success', ?)")->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
            $success = count($ids) . ' user(s) deleted successfully.';
        } catch (PDOException $e) { $error = 'Database error: ' . $e->getMessage(); }
    }
}

$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

if ($search) {
    $like  = "%$search%";
    $total = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email LIKE ? OR role LIKE ?");
    $total->execute([$like, $like]);
    $users = $pdo->prepare("SELECT * FROM users WHERE email LIKE ? OR role LIKE ? ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
    $users->execute([$like, $like]);
} else {
    $total = $pdo->query("SELECT COUNT(*) FROM users");
    $users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
}

$totalUsers = (int)$total->fetchColumn();
$userRows   = $users->fetchAll();
$totalPages = max(1, ceil($totalUsers / $perPage));

$logs = $pdo->query("SELECT ual.*, u.email AS user_email FROM user_activity_logs ual LEFT JOIN users u ON ual.user_id = u.id ORDER BY ual.created_at DESC LIMIT 10")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — EcoRain</title>
    <link rel="stylesheet" href="/Others/all.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #374151;
            font-size: 13px;
            min-height: 100vh;
            padding: 0;
        }

        /* TOP NAV */
        .top-nav {
            background: #0f172a;
            padding: 0 1.5rem;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .top-nav-brand { display: flex; align-items: center; gap: .6rem; text-decoration: none; }
        .logo-drop { width: 32px; height: 32px; background: linear-gradient(160deg,#60a5fa,#2563eb); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .logo-name { font-family: 'Space Grotesk', sans-serif; font-size: 1rem; font-weight: 700; color: #fff; }
        .top-nav-back { display: flex; align-items: center; gap: .5rem; color: #94a3b8; text-decoration: none; font-size: .82rem; font-weight: 500; transition: color .15s; }
        .top-nav-back:hover { color: #e2e8f0; }
        .top-nav-back svg { width: 15px; height: 15px; }

        /* MAIN CONTENT */
        .content { max-width: 1200px; margin: 0 auto; padding: 1.5rem 1.25rem 3rem; }

        .alerts-wrap { margin-bottom: 1rem; }

        /* TABLE WRAPPER */
        .table-wrapper {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,.05);
            margin-bottom: 1.5rem;
        }
        .table-title-bar {
            background: #1e3a5f;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: .75rem;
        }
        .table-title-bar h2 { font-family: 'Space Grotesk', sans-serif; font-size: 1.15rem; font-weight: 700; color: #fff; margin: 0; }
        .table-title-bar .spacer { flex: 1; min-width: 1rem; }

        .search-bar input {
            border-radius: 7px;
            border: 1px solid rgba(255,255,255,.25);
            background: rgba(255,255,255,.12);
            color: #fff;
            padding: .42rem .85rem;
            font-size: .83rem;
            outline: none;
            font-family: 'Inter', sans-serif;
            width: 200px;
            max-width: 100%;
        }
        .search-bar input::placeholder { color: rgba(255,255,255,.6); }

        .tbl-scroll { overflow-x: auto; }
        table.data-table { width: 100%; border-collapse: collapse; min-width: 540px; }
        table.data-table th { font-size: .68rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #9ca3af; padding: .65rem 1.1rem; text-align: left; border-bottom: 1px solid #f3f4f6; white-space: nowrap; }
        table.data-table td { padding: .75rem 1.1rem; border-bottom: 1px solid #f9fafb; vertical-align: middle; color: #374151; font-size: .84rem; }
        table.data-table tr:last-child td { border-bottom: none; }
        table.data-table tr:hover td { background: #f9fafb; }

        /* ROLE BADGE */
        .role-badge { display: inline-block; padding: .2rem .65rem; border-radius: 20px; font-size: .71rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
        .role-admin { background: #fef3c7; color: #92400e; }
        .role-user  { background: #dcfce7; color: #166534; }

        .verified-icon   { color: #16a34a; font-size: 18px !important; }
        .unverified-icon { color: #ef4444; font-size: 18px !important; }

        /* ACTION LINKS */
        .action-link { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 7px; text-decoration: none; transition: background .15s; }
        .action-link.edit   { color: #d97706; } .action-link.edit:hover   { background: #fef3c7; }
        .action-link.delete { color: #ef4444; } .action-link.delete:hover { background: #fef2f2; }
        .action-link .material-icons { font-size: 18px; }

        /* PAGINATION */
        .tbl-footer { display: flex; align-items: center; justify-content: space-between; padding: .85rem 1.1rem; border-top: 1px solid #f3f4f6; flex-wrap: wrap; gap: .65rem; }
        .hint-text { font-size: .78rem; color: #9ca3af; }
        .pager { display: flex; gap: .3rem; flex-wrap: wrap; }
        .pager a, .pager span { display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 28px; border-radius: 7px; font-size: .78rem; font-weight: 500; text-decoration: none; color: #6b7280; padding: 0 6px; transition: background .15s; }
        .pager a:hover { background: #f1f5f9; color: #1e293b; }
        .pager span.active { background: #2563eb; color: #fff; }

        /* CHECKBOX */
        .custom-checkbox { position: relative; }
        .custom-checkbox input[type="checkbox"] { opacity: 0; position: absolute; z-index: 9; width: 16px; height: 16px; cursor: pointer; }
        .custom-checkbox label::before { content: ''; display: inline-block; width: 16px; height: 16px; border: 1.5px solid #d1d5db; border-radius: 4px; background: white; vertical-align: middle; margin-right: 6px; }
        .custom-checkbox input:checked + label::before { background: #2563eb; border-color: #2563eb; }
        .custom-checkbox input:checked + label::after { content: ''; position: absolute; left: 5px; top: 3px; width: 5px; height: 9px; border: solid #fff; border-width: 0 2px 2px 0; transform: rotateZ(45deg); }

        /* LOG TABLE */
        .log-wrapper { background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
        .log-title-bar { background: #475569; padding: .85rem 1.5rem; }
        .log-title-bar h3 { font-family: 'Space Grotesk', sans-serif; font-size: 1rem; font-weight: 700; color: #fff; margin: 0; }
        .status-ok  { color: #16a34a; font-weight: 600; }
        .status-bad { color: #ef4444; font-weight: 600; }

        /* BTN */
        .btn-sm-action { display: inline-flex; align-items: center; gap: .35rem; padding: .42rem .9rem; border-radius: 8px; font-size: .8rem; font-weight: 600; cursor: pointer; border: none; font-family: 'Inter', sans-serif; transition: opacity .15s; }
        .btn-green { background: #16a34a; color: #fff; }
        .btn-red   { background: #ef4444; color: #fff; }
        .btn-sm-action:hover { opacity: .85; }

        /* MODALS */
        .modal .modal-content { border-radius: 12px; border: 1px solid #e5e7eb; }
        .modal .modal-header { background: #1e3a5f; border-radius: 12px 12px 0 0; }
        .modal .modal-title { color: #fff; font-family: 'Space Grotesk', sans-serif; font-size: 1rem; font-weight: 700; }
        .modal .close { color: #fff; opacity: .8; }
        .modal .form-control { border-radius: 8px; font-size: .875rem; }

        @media (max-width: 768px) {
            .search-bar input { width: 140px; }
            .table-title-bar h2 { font-size: 1rem; }
            .content { padding: 1rem .85rem 2rem; }
        }
        @media (max-width: 480px) {
            .top-nav { padding: 0 1rem; }
            .search-bar input { width: 120px; }
        }
    </style>
</head>
<body>

<nav class="top-nav">
    <a href="<?php echo BASE_URL; ?>/App/Dashboard/dashboard.php" class="top-nav-brand">
        <div class="logo-drop">💧</div>
        <span class="logo-name">EcoRain</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/App/Dashboard/dashboard.php" class="top-nav-back">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        Back to Dashboard
    </a>
</nav>

<div class="content">

    <div class="alerts-wrap">
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= $success ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        <?php endif; ?>
    </div>

    <!-- USERS TABLE -->
    <div class="table-wrapper">
        <div class="table-title-bar">
            <h2>User Management</h2>
            <div class="spacer"></div>
            <form method="GET" class="search-bar" style="display:inline;">
                <input type="text" name="q" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
            </form>
            <button class="btn-sm-action btn-green" data-toggle="modal" data-target="#addUserModal">
                <i class="material-icons" style="font-size:16px;">add</i> Add User
            </button>
            <button class="btn-sm-action btn-red" id="bulkDeleteBtn" data-toggle="modal" data-target="#bulkDeleteModal">
                <i class="material-icons" style="font-size:16px;">delete</i> Delete
            </button>
        </div>

        <div class="tbl-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th><span class="custom-checkbox"><input type="checkbox" id="selectAll"><label for="selectAll"></label></span></th>
                    <th>#</th><th>Email</th><th>Role</th><th>Verified</th><th>Created</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($userRows): ?>
                    <?php foreach ($userRows as $u): ?>
                        <tr>
                            <td><span class="custom-checkbox"><input type="checkbox" class="row-checkbox" value="<?= $u['id'] ?>" id="chk<?= $u['id'] ?>"><label for="chk<?= $u['id'] ?>"></label></span></td>
                            <td><?= $u['id'] ?></td>
                            <td style="word-break:break-all"><?= htmlspecialchars($u['email']) ?></td>
                            <td><span class="role-badge role-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
                            <td>
                                <?php if ($u['is_verified']): ?>
                                    <i class="material-icons verified-icon" title="Verified">check_circle</i>
                                <?php else: ?>
                                    <i class="material-icons unverified-icon" title="Not verified">cancel</i>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                            <td>
                                <a href="#editUserModal" class="action-link edit" data-toggle="modal" data-id="<?= $u['id'] ?>" data-email="<?= htmlspecialchars($u['email']) ?>" data-role="<?= $u['role'] ?>">
                                    <i class="material-icons">edit</i>
                                </a>
                                <a href="#deleteUserModal" class="action-link delete" data-toggle="modal" data-id="<?= $u['id'] ?>" data-email="<?= htmlspecialchars($u['email']) ?>">
                                    <i class="material-icons">delete</i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:2rem;">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>

        <div class="tbl-footer">
            <div class="hint-text">Showing <b><?= count($userRows) ?></b> of <b><?= $totalUsers ?></b> users<?= $search ? ' — <em>' . htmlspecialchars($search) . '</em>' : '' ?></div>
            <?php if ($totalPages > 1): ?>
                <div class="pager">
                    <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>&q=<?= urlencode($search) ?>">‹</a><?php endif; ?>
                    <?php for ($p=1; $p<=$totalPages; $p++): ?>
                        <?php if ($p===$page): ?><span class="active"><?= $p ?></span><?php else: ?><a href="?page=<?= $p ?>&q=<?= urlencode($search) ?>"><?= $p ?></a><?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a href="?page=<?= $page+1 ?>&q=<?= urlencode($search) ?>">›</a><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div><!-- /table-wrapper -->

    <!-- ACTIVITY LOG -->
    <div class="log-wrapper">
        <div class="log-title-bar"><h3>📋 Recent Activity Log</h3></div>
        <div class="tbl-scroll">
        <table class="data-table">
            <thead><tr><th>#</th><th>User</th><th>Email</th><th>Action</th><th>Status</th><th>IP</th><th>Time</th></tr></thead>
            <tbody>
                <?php if ($logs): ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= $log['activity_id'] ?></td>
                            <td><?= $log['user_id'] ? '#' . $log['user_id'] : '<em style="color:#9ca3af">—</em>' ?></td>
                            <td style="word-break:break-all"><?= htmlspecialchars($log['email'] ?? $log['user_email'] ?? '—') ?></td>
                            <td><?= htmlspecialchars(str_replace('_', ' ', $log['action'])) ?></td>
                            <td><span class="status-<?= $log['status'] === 'success' ? 'ok' : 'bad' ?>"><?= ucfirst($log['status']) ?></span></td>
                            <td><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                            <td><?= date('M j, H:i', strtotime($log['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:2rem;">No activity logged yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

</div><!-- /content -->

<!-- ADD USER MODAL -->
<div id="addUserModal" class="modal fade">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header"><h5 class="modal-title">Add New User</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                <div class="modal-body">
                    <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" placeholder="user@example.com" required></div>
                    <div class="form-group"><label>Password</label><input type="password" name="password" class="form-control" required></div>
                    <div class="form-group"><label>Role</label><select name="role" class="form-control"><option value="user">User</option><option value="admin">Admin</option></select></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success btn-sm">Add User</button></div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT USER MODAL -->
<div id="editUserModal" class="modal fade">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editUserId">
                <div class="modal-header"><h5 class="modal-title">Edit User</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                <div class="modal-body">
                    <div class="form-group"><label>Email</label><input type="email" name="email" id="editEmail" class="form-control" required></div>
                    <div class="form-group"><label>New Password <small class="text-muted">(leave blank to keep)</small></label><input type="password" name="password" class="form-control"></div>
                    <div class="form-group"><label>Role</label><select name="role" id="editRole" class="form-control"><option value="user">User</option><option value="admin">Admin</option></select></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Save Changes</button></div>
            </form>
        </div>
    </div>
</div>

<!-- DELETE SINGLE MODAL -->
<div id="deleteUserModal" class="modal fade">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteUserId">
                <div class="modal-header"><h5 class="modal-title">Delete User</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                <div class="modal-body"><p>Delete <strong id="deleteUserEmail"></strong>? This action cannot be undone.</p></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger btn-sm">Delete</button></div>
            </form>
        </div>
    </div>
</div>

<!-- BULK DELETE MODAL -->
<div id="bulkDeleteModal" class="modal fade">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="ids" id="bulkDeleteIds">
                <div class="modal-header"><h5 class="modal-title">Bulk Delete</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                <div class="modal-body"><p>Delete <strong id="bulkDeleteCount">0</strong> selected user(s)? This cannot be undone.</p></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger btn-sm">Delete All</button></div>
            </form>
        </div>
    </div>
</div>

<script>
$(function() {
    var $checkboxes = $('input.row-checkbox');
    $('#selectAll').click(function() { $checkboxes.prop('checked', this.checked); });
    $checkboxes.click(function() { if (!this.checked) $('#selectAll').prop('checked', false); });

    $(document).on('click', 'a.action-link.edit', function() {
        $('#editUserId').val($(this).data('id'));
        $('#editEmail').val($(this).data('email'));
        $('#editRole').val($(this).data('role'));
    });
    $(document).on('click', 'a.action-link.delete', function() {
        $('#deleteUserId').val($(this).data('id'));
        $('#deleteUserEmail').text($(this).data('email'));
    });
    $('#bulkDeleteBtn').click(function(e) {
        var ids = $checkboxes.filter(':checked').map(function() { return this.value; }).get();
        if (!ids.length) { e.preventDefault(); e.stopPropagation(); alert('Select at least one user.'); return false; }
        $('#bulkDeleteIds').val(ids.join(','));
        $('#bulkDeleteCount').text(ids.length);
    });
    setTimeout(function() { $('.alert').fadeOut('slow'); }, 4000);
});
</script>
</body>
</html>