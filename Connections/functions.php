<?php
// ── Guards ────────────────────────────────────────────────────────────────────

function redirect($path) {
    header("Location: " . BASE_URL . $path);
    exit;
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Exact role match — use for single-role gates (e.g. requireRole('admin')).
 */
function hasRole(string $role): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

/**
 * Multi-role check — use when multiple roles share access.
 * Example: hasAnyRole(['admin', 'manager'])
 */
function hasAnyRole(array $roles): bool {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], $roles, true);
}

// Convenience helpers — keep conditions readable in page files
function isAdmin(): bool    { return hasRole('admin'); }
function isManager(): bool  { return hasAnyRole(['admin', 'manager']); }
function isRegularUser(): bool { return hasRole('user'); }

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect('/index.php');
    }
}

/**
 * Require an exact role. Redirects non-matching logged-in users to dashboard
 * instead of dying — friendlier and avoids leaking role names in error text.
 */
function requireRole(string $role): void {
    requireLogin();
    if (!hasRole($role)) {
        redirect('/App/Dashboard/dashboard.php');
    }
}

/**
 * Require any one of the given roles.
 * Example: requireAnyRole(['admin', 'manager'])
 */
function requireAnyRole(array $roles): void {
    requireLogin();
    if (!hasAnyRole($roles)) {
        redirect('/App/Dashboard/dashboard.php');
    }
}

// ── Session helpers ───────────────────────────────────────────────────────────

/** Current user's role, defaulting to 'user' if unset. */
function currentRole(): string {
    return $_SESSION['role'] ?? 'user';
}

/** Current user's email. */
function currentEmail(): string {
    return $_SESSION['email'] ?? '';
}

/** Current user's ID. */
function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

/**
 * Two-letter initials from email for the avatar widget.
 * "jane.doe@example.com" → "JA"
 */
function avatarInitials(): string {
    $email = currentEmail();
    if (!$email) return 'ME';
    $local = explode('@', $email)[0];          // part before @
    $parts = preg_split('/[._\-]+/', $local);   // split on . _ -
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }
    return strtoupper(substr($local, 0, 2));
}

// ── Unused renderHeader / renderFooter kept below but marked deprecated ───────
// These output raw HTML from inside a function which makes layout brittle —
// prefer standalone template files instead. Left here so existing callers
// don't break, but do not use in new pages.

function renderHeader(string $title): void {
    $isLoggedIn = isLoggedIn();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?></title>
        <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="<?= BASE_URL ?>/Others/all.css">
    </head>
    <body>
        <?php if ($isLoggedIn): ?>
            <div class="main-content"><div class="container">
        <?php else: ?>
            <div class="container" style="margin:0 auto;max-width:500px;padding-top:100px;">
        <?php endif;
}

function renderFooter(): void {
    $isLoggedIn = isLoggedIn();
    ?>
        <?php if ($isLoggedIn): ?>
                </div></div>
        <?php else: ?>
            </div>
        <?php endif; ?>
    </body>
    </html>
    <?php
}