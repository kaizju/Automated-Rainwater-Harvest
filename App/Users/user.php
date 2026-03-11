<?php
require_once '../../Connections/config.php';

/* ── HANDLE POST ACTIONS ── */
$action  = $_POST['action'] ?? '';
$success = '';
$error   = '';

// ADD USER
if ($action === 'add') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role     = $_POST['role'] ?? 'user';

    if (!$email || !$password) {
        $error = 'Email and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, ?)");
            $stmt->execute([$email, $hash, $role]);

            // Log activity
            $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, action, status, ip_address)
                           VALUES (?, ?, 'add_user', 'success', ?)")
                ->execute([$pdo->lastInsertId(), $email, $_SERVER['REMOTE_ADDR'] ?? '']);

            $success = "User <strong>$email</strong> added successfully.";
        } catch (PDOException $e) {
            $error = strpos($e->getMessage(), 'Duplicate') !== false
                ? 'That email is already registered.'
                : 'Database error: ' . $e->getMessage();
        }
    }
}

// EDIT USER
if ($action === 'edit') {
    $id    = (int)($_POST['id'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    $role  = $_POST['role'] ?? 'user';

    if (!$id || !$email) {
        $error = 'Invalid data.';
    } else {
        try {
            if (!empty($_POST['password'])) {
                $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET email=?, password=?, role=? WHERE id=?");
                $stmt->execute([$email, $hash, $role, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET email=?, role=? WHERE id=?");
                $stmt->execute([$email, $role, $id]);
            }

            $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, action, status, ip_address)
                           VALUES (?, ?, 'edit_user', 'success', ?)")
                ->execute([$id, $email, $_SERVER['REMOTE_ADDR'] ?? '']);

            $success = "User updated successfully.";
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// DELETE (single or bulk)
if ($action === 'delete') {
    $ids = [];
    if (!empty($_POST['id'])) {
        $ids = [(int)$_POST['id']];
    } elseif (!empty($_POST['ids'])) {
        $ids = array_map('intval', explode(',', $_POST['ids']));
    }

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)")->execute($ids);
            $pdo->prepare("INSERT INTO user_activity_logs (action, status, ip_address)
                           VALUES ('delete_user', 'success', ?)")
                ->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
            $success = count($ids) . ' user(s) deleted successfully.';
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

/* ── FETCH USERS ── */
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

/* ── FETCH ACTIVITY LOG ── */
$logs = $pdo->query("
    SELECT ual.*, u.email AS user_email
    FROM user_activity_logs ual
    LEFT JOIN users u ON ual.user_id = u.id
    ORDER BY ual.created_at DESC LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>User Management — EcoRain</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto|Varela+Round">
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>
<style>
body {
    color: #566787;
    background: #f5f5f5;
    font-family: 'Varela Round', sans-serif;
    font-size: 13px;
}
.table-responsive { margin: 30px 0; }
.table-wrapper {
    background: #fff;
    padding: 20px 25px;
    border-radius: 3px;
    min-width: 1000px;
    box-shadow: 0 1px 1px rgba(0,0,0,.05);
}
.table-title {
    padding-bottom: 15px;
    background: #435d7d;
    color: #fff;
    padding: 16px 30px;
    min-width: 100%;
    margin: -20px -25px 10px;
    border-radius: 3px 3px 0 0;
}
.table-title h2 { margin: 5px 0 0; font-size: 24px; }
.table-title .btn-group { float: right; }
.table-title .btn {
    color: #fff; float: right; font-size: 13px; border: none;
    min-width: 50px; border-radius: 2px; outline: none !important; margin-left: 10px;
}
.table-title .btn i { float: left; font-size: 21px; margin-right: 5px; }
.table-title .btn span { float: left; margin-top: 2px; }

/* Search bar */
.search-bar { float: left; margin-top: 4px; }
.search-bar input {
    border-radius: 2px; border: 1px solid rgba(255,255,255,.3);
    background: rgba(255,255,255,.15); color: #fff;
    padding: 4px 10px; font-size: 13px; outline: none;
}
.search-bar input::placeholder { color: rgba(255,255,255,.7); }
.search-bar input:focus { background: rgba(255,255,255,.25); }

table.table tr th, table.table tr td {
    border-color: #e9e9e9; padding: 12px 15px; vertical-align: middle;
}
table.table tr th:first-child { width: 60px; }
table.table tr th:last-child  { width: 120px; }
table.table-striped tbody tr:nth-of-type(odd) { background-color: #fcfcfc; }
table.table-striped.table-hover tbody tr:hover { background: #f5f5f5; }
table.table th i { font-size: 13px; margin: 0 5px; cursor: pointer; }
table.table td:last-child i { opacity: .9; font-size: 22px; margin: 0 5px; }
table.table td a { font-weight: bold; color: #566787; display: inline-block; text-decoration: none; outline: none !important; }
table.table td a:hover { color: #2196F3; }
table.table td a.edit   { color: #FFC107; }
table.table td a.delete { color: #F44336; }
table.table td i { font-size: 19px; }

/* Role badge */
.role-badge {
    display: inline-block; padding: 3px 10px; border-radius: 10px;
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
}
.role-admin   { background: #fff3cd; color: #856404; }
.role-user    { background: #d4edda; color: #155724; }

/* Verified badge */
.verified   { color: #28a745; font-size: 16px !important; }
.unverified { color: #dc3545; font-size: 16px !important; }

/* Pagination */
.pagination { float: right; margin: 0 0 5px; }
.pagination li a {
    border: none; font-size: 13px; min-width: 30px; min-height: 30px;
    color: #999; margin: 0 2px; line-height: 30px; border-radius: 2px !important;
    text-align: center; padding: 0 6px;
}
.pagination li a:hover { color: #666; }
.pagination li.active a, .pagination li.active a.page-link { background: #03A9F4; color: #fff; }
.pagination li.active a:hover { background: #0397d6; }
.pagination li.disabled i { color: #ccc; }
.pagination li i { font-size: 16px; padding-top: 6px; }
.hint-text { float: left; margin-top: 10px; font-size: 13px; }

/* Checkbox */
.custom-checkbox { position: relative; }
.custom-checkbox input[type="checkbox"] { opacity: 0; position: absolute; margin: 5px 0 0 3px; z-index: 9; }
.custom-checkbox label:before {
    width: 18px; height: 18px; content: ''; margin-right: 10px;
    display: inline-block; vertical-align: text-top; background: white;
    border: 1px solid #bbb; border-radius: 2px; box-sizing: border-box; z-index: 2;
}
.custom-checkbox input[type="checkbox"]:checked + label:after {
    content: ''; position: absolute; left: 6px; top: 3px; width: 6px; height: 11px;
    border: solid #000; border-width: 0 3px 3px 0; z-index: 3; transform: rotateZ(45deg);
}
.custom-checkbox input[type="checkbox"]:checked + label:before { border-color: #03A9F4; background: #03A9F4; }
.custom-checkbox input[type="checkbox"]:checked + label:after  { border-color: #fff; }

/* Modal */
.modal .modal-dialog { max-width: 440px; }
.modal .modal-header, .modal .modal-body, .modal .modal-footer { padding: 20px 30px; }
.modal .modal-content { border-radius: 3px; font-size: 14px; }
.modal .modal-footer  { background: #ecf0f1; border-radius: 0 0 3px 3px; }
.modal .form-control  { border-radius: 2px; box-shadow: none; border-color: #ddd; }
.modal textarea.form-control { resize: vertical; }
.modal .btn { border-radius: 2px; min-width: 100px; }
.modal form label { font-weight: normal; }
.modal .modal-title { display: inline-block; }

/* Alert */
.alert { margin: 10px 0 0; border-radius: 2px; font-size: 13px; }

/* Log table */
.log-wrapper {
    background: #fff; padding: 20px 25px; border-radius: 3px;
    min-width: 1000px; box-shadow: 0 1px 1px rgba(0,0,0,.05); margin-top: 20px;
}
.log-title {
    background: #566787; color: #fff; padding: 14px 30px;
    margin: -20px -25px 15px; border-radius: 3px 3px 0 0; font-size: 16px; font-weight: 600;
}
.status-success { color: #28a745; font-weight: 600; }
.status-failed  { color: #dc3545; font-weight: 600; }
</style>
</head>
<body>
<div class="container-xl">

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
            <?= $success ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
            <?= htmlspecialchars($error) ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <div class="table-wrapper">
            <div class="table-title">
                <div class="row align-items-center">
                    <div class="col-sm-4">
                        <h2>User <b>Management</b></h2>
                    </div>
                    <div class="col-sm-4">
                        <form method="GET" class="search-bar">
                            <input type="text" name="q" placeholder="Search by email or role…"
                                   value="<?= htmlspecialchars($search) ?>">
                        </form>
                    </div>
                    <div class="col-sm-4 text-right">
                        <a href="#addUserModal" class="btn btn-success" data-toggle="modal">
                            <i class="material-icons">&#xE147;</i> <span>Add New User</span>
                        </a>
                        <a href="#bulkDeleteModal" class="btn btn-danger" data-toggle="modal" id="bulkDeleteBtn">
                            <i class="material-icons">&#xE15C;</i> <span>Delete</span>
                        </a>
                    </div>
                </div>
            </div>

            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>
                            <span class="custom-checkbox">
                                <input type="checkbox" id="selectAll">
                                <label for="selectAll"></label>
                            </span>
                        </th>
                        <th>#</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Verified</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($userRows): ?>
                    <?php foreach ($userRows as $u): ?>
                    <tr>
                        <td>
                            <span class="custom-checkbox">
                                <input type="checkbox" class="row-checkbox" name="options[]"
                                       value="<?= $u['id'] ?>" id="chk<?= $u['id'] ?>">
                                <label for="chk<?= $u['id'] ?>"></label>
                            </span>
                        </td>
                        <td><?= $u['id'] ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td>
                            <span class="role-badge role-<?= $u['role'] ?>">
                                <?= ucfirst($u['role']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($u['is_verified']): ?>
                                <i class="material-icons verified" title="Verified">check_circle</i>
                            <?php else: ?>
                                <i class="material-icons unverified" title="Not verified">cancel</i>
                            <?php endif; ?>
                        </td>
                        <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                        <td>
                            <a href="#editUserModal" class="edit" data-toggle="modal"
                               data-id="<?= $u['id'] ?>"
                               data-email="<?= htmlspecialchars($u['email']) ?>"
                               data-role="<?= $u['role'] ?>">
                                <i class="material-icons" title="Edit">&#xE254;</i>
                            </a>
                            <a href="#deleteUserModal" class="delete" data-toggle="modal"
                               data-id="<?= $u['id'] ?>"
                               data-email="<?= htmlspecialchars($u['email']) ?>">
                                <i class="material-icons" title="Delete">&#xE872;</i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <div class="clearfix">
                <div class="hint-text">
                    Showing <b><?= count($userRows) ?></b> of <b><?= $totalUsers ?></b> users
                    <?= $search ? '— search: <em>' . htmlspecialchars($search) . '</em>' : '' ?>
                </div>
                <?php if ($totalPages > 1): ?>
                <ul class="pagination">
                    <li class="<?= $page <= 1 ? 'disabled' : '' ?>">
                        <a href="?page=<?= $page-1 ?>&q=<?= urlencode($search) ?>">
                            <i class="fa fa-angle-left"></i>
                        </a>
                    </li>
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <li class="<?= $p === $page ? 'active' : '' ?>">
                        <a href="?page=<?= $p ?>&q=<?= urlencode($search) ?>"><?= $p ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="<?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a href="?page=<?= $page+1 ?>&q=<?= urlencode($search) ?>">
                            <i class="fa fa-angle-right"></i>
                        </a>
                    </li>
                </ul>
                <?php endif; ?>
            </div>
        </div><!-- /table-wrapper -->
    </div>

    <!-- ── ACTIVITY LOG TABLE ── -->
    <div class="table-responsive">
        <div class="log-wrapper">
            <div class="log-title">📋 Recent Activity Log</div>
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Email (logged)</th>
                        <th>Action</th>
                        <th>Status</th>
                        <th>IP Address</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($logs): ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= $log['activity_id'] ?></td>
                        <td><?= $log['user_id'] ? '#' . $log['user_id'] : '<em class="text-muted">—</em>' ?></td>
                        <td><?= htmlspecialchars($log['email'] ?? $log['user_email'] ?? '—') ?></td>
                        <td><?= htmlspecialchars(str_replace('_', ' ', $log['action'])) ?></td>
                        <td>
                            <span class="status-<?= $log['status'] ?>">
                                <?= ucfirst($log['status']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                        <td><?= date('M j, Y H:i', strtotime($log['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No activity logged yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /container -->

<!-- ── ADD USER MODAL ── -->
<div id="addUserModal" class="modal fade">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h4 class="modal-title">Add New User</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="user@example.com" required>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Min 8 characters" required>
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" class="form-control">
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="button" class="btn btn-default" data-dismiss="modal" value="Cancel">
                    <input type="submit" class="btn btn-success" value="Add User">
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── EDIT USER MODAL ── -->
<div id="editUserModal" class="modal fade">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editUserId">
                <div class="modal-header">
                    <h4 class="modal-title">Edit User</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" id="editEmail" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>New Password <small class="text-muted">(leave blank to keep current)</small></label>
                        <input type="password" name="password" class="form-control" placeholder="Leave blank to keep">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" id="editRole" class="form-control">
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="button" class="btn btn-default" data-dismiss="modal" value="Cancel">
                    <input type="submit" class="btn btn-info" value="Save Changes">
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── DELETE SINGLE MODAL ── -->
<div id="deleteUserModal" class="modal fade">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteUserId">
                <div class="modal-header">
                    <h4 class="modal-title">Delete User</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete <strong id="deleteUserEmail"></strong>?</p>
                    <p class="text-warning"><small>This action cannot be undone.</small></p>
                </div>
                <div class="modal-footer">
                    <input type="button" class="btn btn-default" data-dismiss="modal" value="Cancel">
                    <input type="submit" class="btn btn-danger" value="Delete">
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── BULK DELETE MODAL ── -->
<div id="bulkDeleteModal" class="modal fade">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="ids" id="bulkDeleteIds">
                <div class="modal-header">
                    <h4 class="modal-title">Delete Selected Users</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete <strong id="bulkDeleteCount">0</strong> selected user(s)?</p>
                    <p class="text-warning"><small>This action cannot be undone.</small></p>
                </div>
                <div class="modal-footer">
                    <input type="button" class="btn btn-default" data-dismiss="modal" value="Cancel">
                    <input type="submit" class="btn btn-danger" value="Delete All">
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function () {

    // ── Tooltips ──
    $('[data-toggle="tooltip"]').tooltip();

    // ── Select All checkboxes ──
    var $checkboxes = $('table tbody input.row-checkbox');
    $('#selectAll').click(function () {
        $checkboxes.prop('checked', this.checked);
    });
    $checkboxes.click(function () {
        if (!this.checked) $('#selectAll').prop('checked', false);
    });

    // ── Populate EDIT modal ──
    $(document).on('click', 'a.edit', function () {
        $('#editUserId').val($(this).data('id'));
        $('#editEmail').val($(this).data('email'));
        $('#editRole').val($(this).data('role'));
    });

    // ── Populate DELETE single modal ──
    $(document).on('click', 'a.delete', function () {
        $('#deleteUserId').val($(this).data('id'));
        $('#deleteUserEmail').text($(this).data('email'));
    });

    // ── Populate BULK DELETE modal ──
    $('#bulkDeleteBtn').click(function (e) {
        var ids = $checkboxes.filter(':checked').map(function () {
            return this.value;
        }).get();

        if (ids.length === 0) {
            e.preventDefault();
            alert('Please select at least one user to delete.');
            return false;
        }

        $('#bulkDeleteIds').val(ids.join(','));
        $('#bulkDeleteCount').text(ids.length);
    });

    // ── Auto-dismiss alerts ──
    setTimeout(function () {
        $('.alert').fadeOut('slow');
    }, 4000);
});
</script>
</body>
</html>