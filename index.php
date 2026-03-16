<?php
require_once __DIR__ . '/Connections/config.php';
require_once __DIR__ . '/Connections/functions.php';
require_once __DIR__ . '/Others/activity_logger.php';

if (isLoggedIn()) {
    switch ($_SESSION['role']) {
        case 'admin':
            redirect('/App/Dashboard/dashboard.php');
            break;
        case 'user':
            redirect('/App/User/dashboard.php');
            break;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_verified = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email']   = $user['email'];
        $_SESSION['role']    = $user['role'];
        logActivity($pdo, $user['id'], $user['email'], 'login', 'success');
        switch ($user['role']) {
            case 'admin':
                redirect('/App/Dashboard/dashboard.php');
                break;
            case 'user':
                redirect('/App/User/dashboard.php');
                break;
        }
    } else {
        $error = 'Invalid credentials or email not verified.';
        logActivity($pdo, null, $email, 'login', 'failed');
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EcoRain — Sign In</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
           
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
        }

        .login-outer {
            width: 100%;
            max-width: 420px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
        }

        /* LOGO */
        .brand {
            display: flex;
            align-items: center;
            gap: .75rem;
        }

        .brand-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(145deg, #60a5fa, #1d4ed8);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }

        .brand-name {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: #000000;
            letter-spacing: -.02em;
        }

        /* CARD */
        .login-card {
            width: 100%;
            background: #fff;
            border-radius: 16px;
            padding: 2rem 2.25rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .35);
        }

        .login-header {
            text-align: center;
            margin-bottom: 1.75rem;
        }

        .login-header h2 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: .35rem;
        }

        .login-header p {
            color: #64748b;
            font-size: .875rem;
        }

        /* SERVER ERROR */
        .server-error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: .75rem 1rem;
            font-size: .875rem;
            font-weight: 500;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        /* FORM */
        .form-group {
            margin-bottom: 1.1rem;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper input {
            width: 100%;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 9px;
            padding: 1.1rem 1rem .5rem 1rem;
            color: #1e293b;
            font-size: .9rem;
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }

        .input-wrapper input::placeholder {
            color: transparent;
        }

        .input-wrapper label {
            position: absolute;
            left: 1rem;
            top: .9rem;
            color: #94a3b8;
            font-size: .875rem;
            transition: all .2s ease;
            pointer-events: none;
            transform-origin: left top;
        }

        .input-wrapper input:focus,
        .input-wrapper input:not(:placeholder-shown) {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, .12);
            background: #fff;
        }

        .input-wrapper input:focus+label,
        .input-wrapper input:not(:placeholder-shown)+label {
            transform: translateY(-8px) scale(.76);
            color: #3b82f6;
            font-weight: 600;
        }

        .form-group.has-error .input-wrapper input {
            border-color: #ef4444;
        }

        .form-group.has-error .input-wrapper input:focus+label,
        .form-group.has-error .input-wrapper input:not(:placeholder-shown)+label {
            color: #ef4444;
        }

        .password-wrapper input {
            padding-right: 3rem;
        }

        .eye-toggle {
            position: absolute;
            right: .85rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: .3rem;
            color: #94a3b8;
            transition: color .2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .eye-toggle:hover {
            color: #1e293b;
        }

        .eye-toggle svg {
            width: 18px;
            height: 18px;
        }

        .field-error {
            font-size: .73rem;
            color: #ef4444;
            font-weight: 500;
            margin-top: .3rem;
            min-height: 16px;
            display: block;
            opacity: 0;
            transform: translateY(-4px);
            transition: all .2s ease;
        }

        .field-error.show {
            opacity: 1;
            transform: translateY(0);
        }

        /* SUBMIT */
        .login-btn {
            width: 100%;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 9px;
            padding: .9rem 1.5rem;
            font-size: .95rem;
            font-weight: 700;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: background .2s, transform .1s;
            position: relative;
            margin-bottom: .5rem;
        }

        .login-btn:hover:not(:disabled) {
            background: #1d4ed8;
        }

        .login-btn:active:not(:disabled) {
            transform: translateY(1px);
        }

        .login-btn:disabled {
            background: #93c5fd;
            pointer-events: none;
        }

        .btn-text {
            transition: opacity .2s;
        }

        .btn-loader {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 18px;
            height: 18px;
            border: 2px solid transparent;
            border-top-color: #fff;
            border-radius: 50%;
            opacity: 0;
            animation: spin .8s linear infinite;
            transition: opacity .2s;
        }

        .login-btn.loading .btn-text {
            opacity: 0;
        }

        .login-btn.loading .btn-loader {
            opacity: 1;
        }

        @keyframes spin {
            to {
                transform: translate(-50%, -50%) rotate(360deg);
            }
        }

        /* FOOTER NOTE */
        .card-footer-note {
            text-align: center;
            font-size: .78rem;
            color: #94a3b8;
            margin-top: .5rem;
        }

        /* RESPONSIVE */
        @media (max-width: 480px) {
            .login-card {
                padding: 1.5rem 1.25rem;
            }

            .login-header h2 {
                font-size: 1.3rem;
            }
        }

        @media (max-width: 360px) {
            body {
                padding: .75rem;
            }
        }
    </style>
</head>

<body>

    <div class="login-outer">

        <div class="brand">
            <div class="brand-icon">💧</div>
            <span class="brand-name">EcoRain</span>
        </div>

        <div class="login-card">

            <div class="login-header">
                <h2>Welcome back</h2>
                <p>Sign in to your EcoRain account</p>
            </div>

            <?php if ($error): ?>
                <div class="server-error">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" y1="8" x2="12" y2="12" />
                        <line x1="12" y1="16" x2="12.01" y2="16" />
                    </svg>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="loginForm" novalidate>

                <div class="form-group" id="emailGroup">
                    <div class="input-wrapper">
                        <input type="email" id="email" name="email" placeholder=" " required autocomplete="email"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        <label for="email">Email Address</label>
                    </div>
                    <span class="field-error" id="emailError"></span>
                </div>

                <div class="form-group" id="passwordGroup">
                    <div class="input-wrapper password-wrapper">
                        <input type="password" id="password" name="password" placeholder=" " required autocomplete="current-password">
                        <label for="password">Password</label>
                        <button type="button" class="eye-toggle" id="eyeBtn" aria-label="Toggle password">
                            <svg id="eyeIcon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                        </button>
                    </div>
                    <span class="field-error" id="passwordError"></span>
                </div>

                <button type="submit" class="login-btn" id="loginBtn">
                    <span class="btn-text">Sign In</span>
                    <span class="btn-loader"></span>
                </button>

                <div class="card-footer-note">Rainwater harvesting management system</div>

            </form>
        </div>

    </div>

    <script>
        const eyeBtn = document.getElementById('eyeBtn');
        const pwdInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');

        eyeBtn.addEventListener('click', () => {
            const show = pwdInput.type === 'password';
            pwdInput.type = show ? 'text' : 'password';
            eyeIcon.innerHTML = show ?
                '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>' :
                '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        });

        function showErr(groupId, errId, msg) {
            document.getElementById(groupId).classList.add('has-error');
            const el = document.getElementById(errId);
            el.textContent = msg;
            el.classList.add('show');
        }

        function clearErr(groupId, errId) {
            document.getElementById(groupId).classList.remove('has-error');
            const el = document.getElementById(errId);
            el.textContent = '';
            el.classList.remove('show');
        }

        function isEmail(v) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
        }

        document.getElementById('email').addEventListener('input', function() {
            if (this.value && !isEmail(this.value)) showErr('emailGroup', 'emailError', 'Please enter a valid email.');
            else clearErr('emailGroup', 'emailError');
        });
        document.getElementById('password').addEventListener('input', function() {
            if (this.value && this.value.length < 6) showErr('passwordGroup', 'passwordError', 'Password must be at least 6 characters.');
            else clearErr('passwordGroup', 'passwordError');
        });

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const pwd = document.getElementById('password').value;
            let ok = true;
            clearErr('emailGroup', 'emailError');
            clearErr('passwordGroup', 'passwordError');

            if (!email) {
                showErr('emailGroup', 'emailError', 'Email is required.');
                ok = false;
            } else if (!isEmail(email)) {
                showErr('emailGroup', 'emailError', 'Enter a valid email.');
                ok = false;
            }
            if (!pwd) {
                showErr('passwordGroup', 'passwordError', 'Password is required.');
                ok = false;
            } else if (pwd.length < 6) {
                showErr('passwordGroup', 'passwordError', 'At least 6 characters.');
                ok = false;
            }

            if (!ok) {
                e.preventDefault();
                return;
            }
            const btn = document.getElementById('loginBtn');
            btn.classList.add('loading');
            btn.disabled = true;
        });
    </script>
</body>

</html>