<?php
require_once __DIR__ . '/connections/config.php';
require_once __DIR__ . '/connections/functions.php';

// Redirect already-logged-in users
if (isLoggedIn()) {
    switch ($_SESSION['role']) {
        case 'admin':   redirect('/app/admin/admin_oversight.php'); break;
        case 'manager': redirect('/app/manager/manager.php');       break;
        case 'user':    redirect('/app/user/dashboard.php');        break;
    }
}

$error   = '';
$success = '';
$fields  = ['username' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim($_POST['username']  ?? '');
    $email     = trim($_POST['email']     ?? '');
    $password  = trim($_POST['password']  ?? '');
    $repassword = trim($_POST['repassword'] ?? '');

    $fields['username'] = $username;
    $fields['email']    = $email;

    // ── Validation ──────────────────────────────────────────────
    if (!$username || !$email || !$password || !$repassword) {
        $error = 'All fields are required.';
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $error = 'Username must be between 3 and 50 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $repassword) {
        $error = 'Passwords do not match.';
    } else {
        // ── Duplicate checks ─────────────────────────────────────
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'That username is already taken.';
            } else {
                // ── Create user ──────────────────────────────────────
                $hashed = password_hash($password, PASSWORD_BCRYPT);

                $stmt = $pdo->prepare("
                    INSERT INTO users (username, email, password, role, is_verified, created_at)
                    VALUES (?, ?, ?, 'user', 1, NOW())
                ");
                $stmt->execute([$username, $email, $hashed]);

                logActivity('register', 'success', 'auth', 'New account created: ' . $email);

                $success = 'Account created successfully! You can now sign in.';
                $fields  = ['username' => '', 'email' => ''];
            }
        }
    }

    if ($error) {
        logActivity('register', 'failed', 'auth', 'Failed registration for: ' . $email);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EcoRain — Create Account</title>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap"
        rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
        }

        .register-outer {
            width: 100%;
            max-width: 420px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
        }

        /* ── Brand ── */
        .brand { display: flex; align-items: center; gap: .75rem; }
        .brand-icon {
            width: 44px; height: 44px;
            background: linear-gradient(145deg, #60a5fa, #1d4ed8);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem;
        }
        .brand-name {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.5rem; font-weight: 700;
            color: #000; letter-spacing: -.02em;
        }

        /* ── Card ── */
        .register-card {
            width: 100%;
            background: #fff;
            border-radius: 16px;
            padding: 2rem 2.25rem;
            box-shadow: 0 20px 60px rgba(0,0,0,.35);
        }

        .register-header { text-align: center; margin-bottom: 1.75rem; }
        .register-header h2 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.5rem; font-weight: 700;
            color: #1e293b; margin-bottom: .35rem;
        }
        .register-header p { color: #64748b; font-size: .875rem; }

        /* ── Alerts ── */
        .server-error,
        .server-success {
            border-radius: 8px;
            padding: .75rem 1rem; font-size: .875rem; font-weight: 500;
            margin-bottom: 1.25rem;
            display: flex; align-items: center; gap: .5rem;
        }
        .server-error   { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .server-success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }

        /* ── Form groups ── */
        .form-group { margin-bottom: 1.1rem; }

        .input-wrapper { position: relative; }
        .input-wrapper input {
            width: 100%;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 9px;
            padding: 1.1rem 1rem .5rem 1rem;
            color: #1e293b; font-size: .9rem;
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        .input-wrapper input::placeholder { color: transparent; }
        .input-wrapper label {
            position: absolute; left: 1rem; top: .9rem;
            color: #94a3b8; font-size: .875rem;
            transition: all .2s ease;
            pointer-events: none; transform-origin: left top;
        }
        .input-wrapper input:focus,
        .input-wrapper input:not(:placeholder-shown) {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,.12);
            background: #fff;
        }
        .input-wrapper input:focus + label,
        .input-wrapper input:not(:placeholder-shown) + label {
            transform: translateY(-8px) scale(.76);
            color: #3b82f6; font-weight: 600;
        }
        .form-group.has-error .input-wrapper input { border-color: #ef4444; }
        .form-group.has-error .input-wrapper input:focus + label,
        .form-group.has-error .input-wrapper input:not(:placeholder-shown) + label { color: #ef4444; }

        /* ── Password toggle ── */
        .password-wrapper input { padding-right: 3rem; }
        .eye-toggle {
            position: absolute; right: .85rem; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; padding: .3rem;
            color: #94a3b8; transition: color .2s;
            display: flex; align-items: center; justify-content: center;
        }
        .eye-toggle:hover { color: #1e293b; }
        .eye-toggle svg { width: 18px; height: 18px; }

        /* ── Strength bar ── */
        .strength-bar-wrap {
            margin-top: .45rem;
            height: 4px; border-radius: 2px;
            background: #e2e8f0; overflow: hidden;
            opacity: 0; transition: opacity .2s;
        }
        .strength-bar-wrap.visible { opacity: 1; }
        .strength-bar {
            height: 100%; border-radius: 2px;
            width: 0; transition: width .3s ease, background .3s ease;
        }
        .strength-label {
            font-size: .7rem; font-weight: 600; margin-top: .25rem;
            min-height: 14px; color: #94a3b8;
            opacity: 0; transition: opacity .2s;
        }
        .strength-label.visible { opacity: 1; }

        /* ── Field error ── */
        .field-error {
            font-size: .73rem; color: #ef4444; font-weight: 500;
            margin-top: .3rem; min-height: 16px; display: block;
            opacity: 0; transform: translateY(-4px);
            transition: all .2s ease;
        }
        .field-error.show { opacity: 1; transform: translateY(0); }

        /* ── Submit ── */
        .register-btn {
            width: 100%; background: #2563eb; color: #fff;
            border: none; border-radius: 9px;
            padding: .9rem 1.5rem; font-size: .95rem; font-weight: 700;
            font-family: 'Inter', sans-serif; cursor: pointer;
            transition: background .2s, transform .1s;
            position: relative; margin-bottom: .5rem;
        }
        .register-btn:hover:not(:disabled) { background: #1d4ed8; }
        .register-btn:active:not(:disabled) { transform: translateY(1px); }
        .register-btn:disabled { background: #93c5fd; pointer-events: none; }

        .btn-text { transition: opacity .2s; }
        .btn-loader {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 18px; height: 18px;
            border: 2px solid transparent; border-top-color: #fff;
            border-radius: 50%; opacity: 0;
            animation: spin .8s linear infinite; transition: opacity .2s;
        }
        .register-btn.loading .btn-text { opacity: 0; }
        .register-btn.loading .btn-loader { opacity: 1; }

        @keyframes spin { to { transform: translate(-50%, -50%) rotate(360deg); } }

        /* ── Footer note ── */
        .card-footer-note {
            text-align: center; font-size: .78rem; color: #94a3b8;
            margin-top: .5rem;
        }
        .card-footer-note a { color: #3b82f6; text-decoration: none; font-weight: 600; }
        .card-footer-note a:hover { text-decoration: underline; }

        @media (max-width: 480px) {
            .register-card { padding: 1.5rem 1.25rem; }
            .register-header h2 { font-size: 1.3rem; }
        }
        @media (max-width: 360px) { body { padding: .75rem; } }
    </style>
</head>

<body>
    <div class="register-outer">

        <div class="brand">
            <div class="brand-icon">💧</div>
            <span class="brand-name">EcoRain</span>
        </div>

        <div class="register-card">

            <div class="register-header">
                <h2>Create an account</h2>
                <p>Join EcoRain to manage your rainwater system</p>
            </div>

            <?php if ($error): ?>
                <div class="server-error">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="server-success">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <?= htmlspecialchars($success) ?>
                    <a href="/index.php" style="margin-left:.25rem; color:#16a34a; font-weight:700;">Sign in →</a>
                </div>
            <?php endif; ?>

            <form method="POST" id="registerForm" novalidate>

                <!-- Username -->
                <div class="form-group" id="usernameGroup">
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" placeholder=" "
                               required autocomplete="username" maxlength="50"
                               value="<?= htmlspecialchars($fields['username']) ?>">
                        <label for="username">Username</label>
                    </div>
                    <span class="field-error" id="usernameError"></span>
                </div>

                <!-- Email -->
                <div class="form-group" id="emailGroup">
                    <div class="input-wrapper">
                        <input type="email" id="email" name="email" placeholder=" "
                               required autocomplete="email"
                               value="<?= htmlspecialchars($fields['email']) ?>">
                        <label for="email">Email Address</label>
                    </div>
                    <span class="field-error" id="emailError"></span>
                </div>

                <!-- Password -->
                <div class="form-group" id="passwordGroup">
                    <div class="input-wrapper password-wrapper">
                        <input type="password" id="password" name="password" placeholder=" "
                               required autocomplete="new-password">
                        <label for="password">Password</label>
                        <button type="button" class="eye-toggle" id="eyeBtn1" aria-label="Toggle password">
                            <svg id="eyeIcon1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <!-- Strength bar -->
                    <div class="strength-bar-wrap" id="strengthWrap">
                        <div class="strength-bar" id="strengthBar"></div>
                    </div>
                    <div class="strength-label" id="strengthLabel"></div>
                    <span class="field-error" id="passwordError"></span>
                </div>

                <!-- Confirm Password -->
                <div class="form-group" id="repasswordGroup">
                    <div class="input-wrapper password-wrapper">
                        <input type="password" id="repassword" name="repassword" placeholder=" "
                               required autocomplete="new-password">
                        <label for="repassword">Confirm Password</label>
                        <button type="button" class="eye-toggle" id="eyeBtn2" aria-label="Toggle confirm password">
                            <svg id="eyeIcon2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <span class="field-error" id="repasswordError"></span>
                </div>

                <button type="submit" class="register-btn" id="registerBtn">
                    <span class="btn-text">Create Account</span>
                    <span class="btn-loader"></span>
                </button>

                <div class="card-footer-note">
                    Already have an account? <a href="/index.php">Sign in</a>
                </div>

            </form>
        </div>

    </div>

    <script>
        // ── Eye toggles ─────────────────────────────────────────────────────────
        function makeEyeToggle(btnId, inputId, iconId) {
            const btn   = document.getElementById(btnId);
            const input = document.getElementById(inputId);
            const icon  = document.getElementById(iconId);
            const EYE_OPEN   = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
            const EYE_CLOSED = '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
            btn.addEventListener('click', () => {
                const show = input.type === 'password';
                input.type  = show ? 'text' : 'password';
                icon.innerHTML = show ? EYE_CLOSED : EYE_OPEN;
            });
        }
        makeEyeToggle('eyeBtn1', 'password',   'eyeIcon1');
        makeEyeToggle('eyeBtn2', 'repassword', 'eyeIcon2');

        // ── Error helpers ────────────────────────────────────────────────────────
        function showErr(groupId, errId, msg) {
            document.getElementById(groupId).classList.add('has-error');
            const el = document.getElementById(errId);
            el.textContent = msg; el.classList.add('show');
        }
        function clearErr(groupId, errId) {
            document.getElementById(groupId).classList.remove('has-error');
            const el = document.getElementById(errId);
            el.textContent = ''; el.classList.remove('show');
        }

        function isEmail(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }

        // ── Password strength ────────────────────────────────────────────────────
        const strengthWrap  = document.getElementById('strengthWrap');
        const strengthBar   = document.getElementById('strengthBar');
        const strengthLabel = document.getElementById('strengthLabel');

        const STRENGTH = [
            { label: 'Too short',  color: '#ef4444', pct: '20%' },
            { label: 'Weak',       color: '#f97316', pct: '40%' },
            { label: 'Fair',       color: '#eab308', pct: '60%' },
            { label: 'Good',       color: '#22c55e', pct: '80%' },
            { label: 'Strong',     color: '#16a34a', pct: '100%' },
        ];

        function getStrength(pwd) {
            if (pwd.length < 6)  return 0;
            let score = 1;
            if (pwd.length >= 10)           score++;
            if (/[A-Z]/.test(pwd))          score++;
            if (/[0-9]/.test(pwd))          score++;
            if (/[^A-Za-z0-9]/.test(pwd))   score++;
            return Math.min(score, 4);
        }

        document.getElementById('password').addEventListener('input', function () {
            const val = this.value;
            // Field error
            if (val && val.length < 6) showErr('passwordGroup', 'passwordError', 'Password must be at least 6 characters.');
            else clearErr('passwordGroup', 'passwordError');

            // Strength bar
            if (val.length > 0) {
                const s = getStrength(val);
                const info = STRENGTH[s];
                strengthWrap.classList.add('visible');
                strengthLabel.classList.add('visible');
                strengthBar.style.width      = info.pct;
                strengthBar.style.background = info.color;
                strengthLabel.style.color    = info.color;
                strengthLabel.textContent    = info.label;
            } else {
                strengthWrap.classList.remove('visible');
                strengthLabel.classList.remove('visible');
            }

            // Re-validate confirm field if filled
            const repwd = document.getElementById('repassword').value;
            if (repwd) {
                if (val !== repwd) showErr('repasswordGroup', 'repasswordError', 'Passwords do not match.');
                else clearErr('repasswordGroup', 'repasswordError');
            }
        });

        // ── Inline validators ────────────────────────────────────────────────────
        document.getElementById('username').addEventListener('input', function () {
            if (this.value && this.value.length < 3) showErr('usernameGroup', 'usernameError', 'At least 3 characters required.');
            else clearErr('usernameGroup', 'usernameError');
        });

        document.getElementById('email').addEventListener('input', function () {
            if (this.value && !isEmail(this.value)) showErr('emailGroup', 'emailError', 'Please enter a valid email.');
            else clearErr('emailGroup', 'emailError');
        });

        document.getElementById('repassword').addEventListener('input', function () {
            const pwd = document.getElementById('password').value;
            if (this.value && this.value !== pwd) showErr('repasswordGroup', 'repasswordError', 'Passwords do not match.');
            else clearErr('repasswordGroup', 'repasswordError');
        });

        // ── Submit validation ────────────────────────────────────────────────────
        document.getElementById('registerForm').addEventListener('submit', function (e) {
            const username   = document.getElementById('username').value.trim();
            const email      = document.getElementById('email').value.trim();
            const password   = document.getElementById('password').value;
            const repassword = document.getElementById('repassword').value;
            let ok = true;

            ['usernameGroup','emailGroup','passwordGroup','repasswordGroup'].forEach((g, i) => {
                clearErr(g, ['usernameError','emailError','passwordError','repasswordError'][i]);
            });

            if (!username)              { showErr('usernameGroup',   'usernameError',   'Username is required.');               ok = false; }
            else if (username.length<3) { showErr('usernameGroup',   'usernameError',   'At least 3 characters required.');     ok = false; }

            if (!email)                 { showErr('emailGroup',      'emailError',      'Email is required.');                  ok = false; }
            else if (!isEmail(email))   { showErr('emailGroup',      'emailError',      'Enter a valid email.');                ok = false; }

            if (!password)              { showErr('passwordGroup',   'passwordError',   'Password is required.');               ok = false; }
            else if (password.length<6) { showErr('passwordGroup',   'passwordError',   'At least 6 characters.');             ok = false; }

            if (!repassword)            { showErr('repasswordGroup', 'repasswordError', 'Please confirm your password.');       ok = false; }
            else if (password !== repassword) { showErr('repasswordGroup', 'repasswordError', 'Passwords do not match.'); ok = false; }

            if (!ok) { e.preventDefault(); return; }

            const btn = document.getElementById('registerBtn');
            btn.classList.add('loading');
            btn.disabled = true;
        });
    </script>
</body>
</html>