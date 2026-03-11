<?php
require_once __DIR__ . '/../Connections/config.php';
require_once __DIR__ . '/../Connections/functions.php';
require_once __DIR__ . '/../Others/activity-logger.php';

if (isLoggedIn()) {
    switch ($_SESSION['role']) {
        case 'admin':
            redirect('/App/Dashboard/dashboard.php');
            break;
       
        case 'user':
            redirect('/app/user/dashboard.php');
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
                redirect('/app/user/dashboard.php');
                break;
        }
    } else {
        $error = 'Invalid credentials or email not verified.';
        logActivity($pdo, null, $email, 'login', 'failed');
    }
}

renderHeader('EcoRain — Sign In');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EcoRain — Sign In</title>
    <style>
        /* Basic Login Form - Clean & Simple */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            line-height: 1.6;
            color: #334155;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
        }

        .login-card {
            background: white;
            border-radius: 12px;
            padding: 32px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .login-header h2 {
            font-size: 1.875rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .login-header p {
            color: #64748b;
            font-size: 0.875rem;
        }

        /* Server-side error banner */
        .server-error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 20px;
            animation: fadeUp 0.25s ease;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .input-wrapper input {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 18px 16px 8px 16px;
            color: #1e293b;
            font-size: 16px;
            transition: all 0.2s ease;
            width: 100%;
            outline: none;
        }

        .input-wrapper input::placeholder {
            color: transparent;
        }

        .input-wrapper label {
            position: absolute;
            left: 16px;
            top: 14px;
            color: #64748b;
            font-size: 16px;
            transition: all 0.2s ease;
            pointer-events: none;
            transform-origin: left top;
        }

        .input-wrapper input:focus,
        .input-wrapper input:not(:placeholder-shown) {
            border-color: #6366f1;
        }

        .input-wrapper input:focus+label,
        .input-wrapper input:not(:placeholder-shown)+label {
            transform: translateY(-8px) scale(0.75);
            color: #6366f1;
            font-weight: 500;
        }

        /* Error state overrides */
        .form-group.error .input-wrapper input {
            border-color: #ef4444;
        }

        .form-group.error .input-wrapper input+label {
            color: #ef4444;
        }

        /* Password toggle */
        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 48px;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            color: #64748b;
            transition: color 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle:hover {
            color: #1e293b;
        }

        .eye-icon {
            display: block;
            width: 20px;
            height: 20px;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b' stroke-width='1.5'%3e%3cpath stroke-linecap='round' stroke-linejoin='round' d='M15 12a3 3 0 11-6 0 3 3 0 016 0z'/%3e%3cpath stroke-linecap='round' stroke-linejoin='round' d='M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z'/%3e%3c/svg%3e");
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            transition: background-image 0.2s ease;
        }

        .eye-icon.show-password {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b' stroke-width='1.5'%3e%3cpath stroke-linecap='round' stroke-linejoin='round' d='M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 11-4.243-4.243m4.242 4.242L9.88 9.88'/%3e%3c/svg%3e");
        }

        /* Inline error messages */
        .error-message {
            display: block;
            color: #ef4444;
            font-size: 0.75rem;
            font-weight: 500;
            margin-top: 4px;
            margin-left: 4px;
            opacity: 0;
            transform: translateY(-4px);
            transition: all 0.2s ease;
            min-height: 16px;
        }

        .error-message.show {
            opacity: 1;
            transform: translateY(0);
        }

        /* Form options */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .remember-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .remember-wrapper input[type="checkbox"] {
            display: none;
        }

        .checkbox-label {
            color: #64748b;
            font-size: 0.875rem;
            cursor: pointer;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkmark {
            width: 16px;
            height: 16px;
            border: 2px solid #d1d5db;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            flex-shrink: 0;
            background: white;
        }

        .remember-wrapper input[type="checkbox"]:checked~.checkbox-label .checkmark {
            background: #6366f1;
            border-color: #6366f1;
        }

        .remember-wrapper input[type="checkbox"]:checked~.checkbox-label .checkmark::after {
            content: '✓';
            color: white;
            font-size: 10px;
            font-weight: bold;
        }

        .forgot-password {
            color: #6366f1;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .forgot-password:hover {
            color: #4f46e5;
        }

        /* Button */
        .login-btn {
            width: 100%;
            background: #6366f1;
            border: none;
            border-radius: 8px;
            padding: 14px 24px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            margin-bottom: 24px;
        }

        .login-btn:hover:not(:disabled) {
            background: #4f46e5;
        }

        .login-btn:active:not(:disabled) {
            transform: translateY(1px);
        }

        .login-btn:disabled {
            pointer-events: none;
            background: #a5a6f6;
        }

        .btn-text {
            transition: opacity 0.2s ease;
        }

        .btn-loader {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 18px;
            height: 18px;
            border: 2px solid transparent;
            border-top: 2px solid white;
            border-radius: 50%;
            opacity: 0;
            animation: spin 0.8s linear infinite;
            transition: opacity 0.2s ease;
        }

        .login-btn.loading .btn-text {
            opacity: 0;
        }

        .login-btn.loading .btn-loader {
            opacity: 1;
        }

        .login-btn.loading {
            pointer-events: none;
            background: #a5a6f6;
        }

        /* Signup link */
        .signup-link {
            text-align: center;
        }

        .signup-link p {
            color: #64748b;
            font-size: 0.875rem;
        }

        .signup-link a {
            color: #6366f1;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .signup-link a:hover {
            color: #4f46e5;
        }

        /* Success */
        .success-message {
            display: none;
            text-align: center;
            padding: 32px 20px;
        }

        .success-message.show {
            display: block;
            animation: fadeUp 0.35s ease forwards;
        }

        .success-icon {
            width: 52px;
            height: 52px;
            background: #22c55e;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
            margin: 0 auto 16px;
            animation: successPulse 0.5s ease;
        }

        .success-message h3 {
            color: #1e293b;
            font-size: 1.25rem;
            margin-bottom: 8px;
        }

        .success-message p {
            color: #64748b;
            font-size: 0.875rem;
        }

        @keyframes spin {
            to {
                transform: translate(-50%, -50%) rotate(360deg);
            }
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(16px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes successPulse {
            0% {
                transform: scale(0);
            }

            55% {
                transform: scale(1.15);
            }

            100% {
                transform: scale(1);
            }
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 24px;
            }

            .login-header h2 {
                font-size: 1.5rem;
            }

            .form-options {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
        }
    </style>
</head>

<body>

    <div class="login-container">
        <div class="login-card">

            <div class="login-header">
                <h2>Sign In</h2>
                <p>Enter your credentials to access your account</p>
            </div>

            <?php if ($error): ?>
                <div class="server-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="login-form" id="loginForm" novalidate>

                <div class="form-group" id="emailGroup">
                    <div class="input-wrapper">
                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder=" "
                            required
                            autocomplete="email"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        <label for="email">Email Address</label>
                    </div>
                    <span class="error-message" id="emailError"></span>
                </div>

                <div class="form-group" id="passwordGroup">
                    <div class="input-wrapper password-wrapper">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder=" "
                            required
                            autocomplete="current-password">
                        <label for="password">Password</label>
                        <button type="button" class="password-toggle" id="passwordToggle" aria-label="Toggle password visibility">
                            <span class="eye-icon" id="eyeIcon"></span>
                        </button>
                    </div>
                    <span class="error-message" id="passwordError"></span>
                </div>

                <div class="form-options">
                    <label class="remember-wrapper">
                        <input type="checkbox" id="remember" name="remember">
                        <span class="checkbox-label">
                            <span class="checkmark"></span>
                            Remember me
                        </span>
                    </label>
                    <a href="#" class="forgot-password">Forgot password?</a>
                </div>

                <button type="submit" class="login-btn" id="loginBtn">
                    <span class="btn-text">Sign In</span>
                    <span class="btn-loader"></span>
                </button>

            </form>

            <div class="signup-link" id="signupLink">
                <p>Don't have an account? <a href="#">Create one</a></p>
            </div>

            <div class="success-message" id="successMessage">
                <div class="success-icon">✓</div>
                <h3>Login Successful!</h3>
                <p>Redirecting to your dashboard...</p>
            </div>

        </div>
    </div>

    <script>
        // ── Password toggle ────────────────────────────────────────────────────────
        document.getElementById('passwordToggle').addEventListener('click', function() {
            const input = document.getElementById('password');
            const icon = document.getElementById('eyeIcon');
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            icon.classList.toggle('show-password', visible);
        });

        // ── Client-side validation helpers ────────────────────────────────────────
        function showError(groupId, errorId, msg) {
            document.getElementById(groupId).classList.add('error');
            const el = document.getElementById(errorId);
            el.textContent = msg;
            el.classList.add('show');
        }

        function clearError(groupId, errorId) {
            document.getElementById(groupId).classList.remove('error');
            const el = document.getElementById(errorId);
            el.textContent = '';
            el.classList.remove('show');
        }

        function isValidEmail(v) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
        }

        // Live validation
        document.getElementById('email').addEventListener('input', function() {
            if (this.value && !isValidEmail(this.value)) {
                showError('emailGroup', 'emailError', 'Please enter a valid email address.');
            } else {
                clearError('emailGroup', 'emailError');
            }
        });

        document.getElementById('password').addEventListener('input', function() {
            if (this.value.length > 0 && this.value.length < 6) {
                showError('passwordGroup', 'passwordError', 'Password must be at least 6 characters.');
            } else {
                clearError('passwordGroup', 'passwordError');
            }
        });

        // ── Form submit (client-side gate before PHP takes over) ──────────────────
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            let valid = true;

            clearError('emailGroup', 'emailError');
            clearError('passwordGroup', 'passwordError');

            if (!email) {
                showError('emailGroup', 'emailError', 'Email address is required.');
                valid = false;
            } else if (!isValidEmail(email)) {
                showError('emailGroup', 'emailError', 'Please enter a valid email address.');
                valid = false;
            }

            if (!password) {
                showError('passwordGroup', 'passwordError', 'Password is required.');
                valid = false;
            } else if (password.length < 6) {
                showError('passwordGroup', 'passwordError', 'Password must be at least 6 characters.');
                valid = false;
            }

            if (!valid) {
                e.preventDefault();
                return;
            }

            
            const btn = document.getElementById('loginBtn');
            btn.classList.add('loading');
            btn.disabled = true;
        });

        // ── Show success state if PHP already redirected (won't fire on redirect) ─
        // Kept here in case you need a client-only success flow in future
    </script>

</body>

</html>
<?php renderFooter(); ?>