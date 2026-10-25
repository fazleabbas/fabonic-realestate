<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Handle language switch
if (isset($_GET['lang'])) {
    setLang($_GET['lang']);
}

// Check remember me cookie
if (!isLoggedIn() && isset($_COOKIE[REMEMBER_ME_COOKIE])) {
    $token = $_COOKIE[REMEMBER_ME_COOKIE];
    $stmt = $conn->prepare("
        SELECT u.*, r.name as role_name, r.permissions as role_permissions
        FROM users u
        INNER JOIN roles r ON u.role_id = r.id
        WHERE u.remember_token = ? AND u.status = 'active'
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_data'] = [
            'name' => $user['full_name'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role_id' => $user['role_id'],
            'role_name' => $user['role_name'],
            'language' => $user['language']
        ];
        $_SESSION['user_permissions'] = json_decode($user['role_permissions'], true) ?: [];

        if ($user['language']) {
            setLang($user['language']);
        }

        $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
        logActivity('login', 'user', $user['id'], ['method' => 'remember_me']);
        redirect('');
    } else {
        // Invalid token — clear cookie
        setcookie(REMEMBER_ME_COOKIE, '', time() - 3600, '/', '', false, true);
    }
}

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('');
}

$error = '';
$success = '';

// Check for session timeout redirect
if (isset($_GET['timeout']) && $_GET['timeout'] === '1') {
    $error = __('session_expired_msg', 'Your session has expired due to inactivity. Please log in again.');
}

// Check for password reset success message
if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $success = __('password_updated');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = clean($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember_me']);

    if (empty($username) || empty($password)) {
        $error = __('username_required') . ' / ' . __('password_required');
    } else {
        try {
            $stmt = $conn->prepare("
                SELECT u.*, r.name as role_name, r.permissions as role_permissions
                FROM users u
                INNER JOIN roles r ON u.role_id = r.id
                WHERE u.username = ?
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = __('login_failed');
                logActivity('failed_login', 'auth', null, ['username' => $username]);
            } elseif ($user['status'] !== 'active') {
                $error = __('account_inactive');
            } elseif (password_verify($password, $user['password_hash'])) {
                // Set session data
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_data'] = [
                    'name' => $user['full_name'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role_id' => $user['role_id'],
                    'role_name' => $user['role_name'],
                    'language' => $user['language']
                ];
                $_SESSION['user_permissions'] = json_decode($user['role_permissions'], true) ?: [];

                // Set user's preferred language
                if ($user['language']) {
                    setLang($user['language']);
                }

                // Remember me
                if ($rememberMe) {
                    $token = generateToken();
                    $conn->prepare("UPDATE users SET remember_token = ? WHERE id = ?")
                         ->execute([$token, $user['id']]);
                    setcookie(
                        REMEMBER_ME_COOKIE,
                        $token,
                        time() + (86400 * REMEMBER_ME_DAYS),
                        '/',
                        '',
                        false,
                        true
                    );
                }

                // Update last login
                $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
                     ->execute([$user['id']]);

                logActivity('login', 'user', $user['id']);
                redirect('');
            } else {
                $error = __('login_failed');
                logActivity('failed_login', 'auth', null, ['username' => $username]);
            }
        } catch (PDOException $e) {
            $error = __('error');
            error_log("Login error: " . $e->getMessage());
        }
    }
}

$currentLang = getCurrentLang();
$dir = isRTL() ? 'rtl' : 'ltr';
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>" dir="<?php echo $dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php _e('login'); ?> - <?php echo APP_NAME; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

    <style>
        :root {
            --re-primary: #1a6b4a;
            --re-primary-dark: #134f37;
            --re-accent: #e8a838;
            --re-bg-start: #0f4c35;
            --re-bg-end: #1a6b4a;
        }

        * { box-sizing: border-box; }

        body {
            background: linear-gradient(135deg, var(--re-bg-start) 0%, var(--re-bg-end) 50%, #2d8f64 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            margin: 0;
            position: relative;
            overflow: hidden;
        }

        /* Subtle pattern overlay */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
            pointer-events: none;
            z-index: 0;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 1rem;
            position: relative;
            z-index: 1;
        }

        .login-card {
            background: white;
            border-radius: 1.25rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 2.5rem 2rem 2rem;
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-logo {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--re-primary), var(--re-accent));
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            box-shadow: 0 4px 15px rgba(26, 107, 74, 0.3);
        }

        .login-logo i {
            font-size: 2rem;
            color: white;
        }

        .login-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 0.25rem;
        }

        .login-header p {
            color: #6c757d;
            margin-bottom: 0;
            font-size: 0.9rem;
        }

        .form-label {
            font-weight: 500;
            color: #344054;
            font-size: 0.875rem;
        }

        .form-control {
            padding: 0.7rem 1rem;
            border-radius: 0.625rem;
            border: 1.5px solid #d0d5dd;
            font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-control:focus {
            border-color: var(--re-primary);
            box-shadow: 0 0 0 3px rgba(26, 107, 74, 0.15);
        }

        .input-group-text {
            background: #f9fafb;
            border: 1.5px solid #d0d5dd;
            border-right: none;
            border-radius: 0.625rem 0 0 0.625rem;
            color: #667085;
        }

        .input-group .form-control {
            border-left: none;
            border-radius: 0 0.625rem 0.625rem 0;
        }

        .btn-login {
            padding: 0.7rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 0.625rem;
            background: var(--re-primary);
            border: none;
            width: 100%;
            color: white;
            transition: all 0.2s;
        }

        .btn-login:hover {
            background: var(--re-primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(26, 107, 74, 0.35);
            color: white;
        }

        .form-check-input:checked {
            background-color: var(--re-primary);
            border-color: var(--re-primary);
        }

        .forgot-link {
            color: var(--re-primary);
            text-decoration: none;
            font-size: 0.85rem;
        }

        .forgot-link:hover {
            text-decoration: underline;
            color: var(--re-primary-dark);
        }

        .lang-switcher {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 10;
        }

        .lang-switcher .btn {
            padding: 0.2rem 0.6rem;
            font-size: 0.8rem;
            border-radius: 50px;
            color: rgba(255,255,255,0.7);
            border-color: rgba(255,255,255,0.3);
        }

        .lang-switcher .btn.active {
            background: rgba(255,255,255,0.2);
            color: white;
            border-color: rgba(255,255,255,0.5);
        }

        .copyright {
            text-align: center;
            margin-top: 1.5rem;
            color: rgba(255,255,255,0.6);
            font-size: 0.8rem;
        }

        @media (max-width: 576px) {
            .login-card { padding: 2rem 1.5rem 1.5rem; }
        }
    </style>
</head>
<body>
    <!-- Language Switcher -->
    <div class="lang-switcher">
        <a href="?lang=en" class="btn btn-outline-light btn-sm <?php echo $currentLang === 'en' ? 'active' : ''; ?>">EN</a>
        <a href="?lang=sw" class="btn btn-outline-light btn-sm <?php echo $currentLang === 'sw' ? 'active' : ''; ?>">SW</a>
        <a href="?lang=ar" class="btn btn-outline-light btn-sm <?php echo $currentLang === 'ar' ? 'active' : ''; ?>">AR</a>
        <a href="?lang=hi" class="btn btn-outline-light btn-sm <?php echo $currentLang === 'hi' ? 'active' : ''; ?>">HI</a>
        <a href="?lang=ur" class="btn btn-outline-light btn-sm <?php echo $currentLang === 'ur' ? 'active' : ''; ?>">UR</a>
        <a href="?lang=gu" class="btn btn-outline-light btn-sm <?php echo $currentLang === 'gu' ? 'active' : ''; ?>">GU</a>
    </div>

    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">
                    <i class="bi bi-building"></i>
                </div>
                <h1><?php echo APP_NAME; ?></h1>
                <p><?php _e('login_subtitle'); ?></p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                <i class="bi bi-exclamation-triangle me-1"></i> <?php echo $error; ?>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                <i class="bi bi-check-circle me-1"></i> <?php echo $success; ?>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="off">
                <div class="mb-3">
                    <label for="username" class="form-label"><?php _e('username'); ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="username" name="username"
                               placeholder="<?php _e('username'); ?>"
                               value="<?php echo isset($_POST['username']) ? clean($_POST['username']) : ''; ?>"
                               required autofocus>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label"><?php _e('password'); ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="<?php _e('password'); ?>" required>
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword"
                                tabindex="-1" aria-label="<?php _e('show_password', 'Show password'); ?>">
                            <i class="bi bi-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me">
                        <label class="form-check-label" for="remember_me" style="font-size:0.85rem;">
                            <?php _e('remember_me'); ?>
                        </label>
                    </div>
                    <a href="<?php echo BASE_URL; ?>modules/auth/forgot_password.php" class="forgot-link">
                        <?php _e('forgot_password'); ?>
                    </a>
                </div>

                <button type="submit" class="btn btn-login">
                    <i class="bi bi-box-arrow-in-right me-1"></i> <?php _e('sign_in'); ?>
                </button>
            </form>

            <?php if (defined('DEMO_MODE') && DEMO_MODE): ?>
            <div class="text-center mt-3">
                <small class="text-muted">
                    <i class="bi bi-info-circle"></i> <?php _e('username'); ?>: demo | <?php _e('password'); ?>: demo1234
                </small>
            </div>
            <?php endif; ?>
        </div>

        <div class="copyright">
            &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?> &mdash; <?php _e('app_tagline'); ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-dismiss alerts
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(el) {
                el.classList.remove('show');
                setTimeout(() => el.remove(), 150);
            });
        }, 5000);

        // Show/hide password
        (function() {
            var btn = document.getElementById('togglePassword');
            var input = document.getElementById('password');
            var icon = document.getElementById('togglePasswordIcon');
            if (btn && input) {
                btn.addEventListener('click', function() {
                    var show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
                    btn.setAttribute('aria-label', show ? '<?php _e('hide_password', 'Hide password'); ?>' : '<?php _e('show_password', 'Show password'); ?>');
                });
            }
        })();
    </script>
</body>
</html>
