<?php
/**
 * FIS Real Estate — Tenant Portal Login (Part 16.1)
 * Separate auth for tenants — phone/email + password.
 * Uses $_SESSION['tenant_portal_*'] namespace.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

// Handle language switch
if (isset($_GET['lang'])) {
    setLang($_GET['lang']);
}

// Handle logout
if (isset($_GET['logout'])) {
    unset($_SESSION['tenant_portal_id'], $_SESSION['tenant_portal_name'], $_SESSION['tenant_portal_tenant_id'], $_SESSION['tenant_portal_email'], $_SESSION['tenant_portal_phone']);
    setAlert(__('tp_logged_out', 'You have been logged out.'), 'info');
    header('Location: ' . BASE_URL . 'modules/tenant-portal/login.php');
    exit;
}

// Check if tenant portal is enabled (system-level)
$portalEnabled = getSetting('tenant_portal_enabled', '0');

// Check tier — must be Professional+
$tierOk = false;
try {
    $tierOk = hasTier('professional');
} catch (Exception $e) {
    // If subscription check fails in dev, allow access
    $tierOk = true;
}

// Redirect if already logged in
if (isset($_SESSION['tenant_portal_id']) && !empty($_SESSION['tenant_portal_id'])) {
    header('Location: ' . BASE_URL . 'modules/tenant-portal/');
    exit;
}

$error = '';
$success = '';

// Handle password reset request
if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $success = __('tp_password_updated', 'Your password has been updated. You can now log in.');
}

// Handle password reset form
$resetMode = isset($_GET['action']) && $_GET['action'] === 'reset';
$resetToken = trim($_GET['token'] ?? '');

if ($resetMode && $resetToken) {
    // Validate token
    $stmt = $conn->prepare("SELECT id, first_name, last_name FROM tenants WHERE portal_reset_token = ? AND portal_reset_expires > NOW() AND portal_enabled = 1");
    $stmt->execute([$resetToken]);
    $resetTenant = $stmt->fetch();

    if (!$resetTenant) {
        $error = __('tp_reset_invalid', 'This reset link is invalid or has expired.');
        $resetMode = false;
    }

    if ($resetTenant && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (strlen($newPass) < 6) {
            $error = __('tp_password_min', 'Password must be at least 6 characters.');
        } elseif ($newPass !== $confirmPass) {
            $error = __('passwords_dont_match');
        } else {
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE tenants SET portal_password_hash = ?, portal_reset_token = NULL, portal_reset_expires = NULL WHERE id = ?");
            $stmt->execute([$hash, $resetTenant['id']]);

            header('Location: ' . BASE_URL . 'modules/tenant-portal/login.php?reset=success');
            exit;
        }
    }
}

// Handle password reset request (step 1 — send email)
$requestReset = isset($_GET['action']) && $_GET['action'] === 'forgot';

if ($requestReset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');

    if (empty($identifier)) {
        $error = __('tp_enter_email_phone', 'Please enter your email or phone number.');
    } else {
        $stmt = $conn->prepare("SELECT id, first_name, last_name, email, phone FROM tenants WHERE (email = ? OR phone = ?) AND portal_enabled = 1");
        $stmt->execute([$identifier, $identifier]);
        $resetReqTenant = $stmt->fetch();

        if ($resetReqTenant) {
            $token = generateToken();
            $expires = date('Y-m-d H:i:s', strtotime('+2 hours'));

            $stmt = $conn->prepare("UPDATE tenants SET portal_reset_token = ?, portal_reset_expires = ? WHERE id = ?");
            $stmt->execute([$token, $expires, $resetReqTenant['id']]);

            // Send reset email if email exists
            if ($resetReqTenant['email']) {
                $resetUrl = BASE_URL . 'modules/tenant-portal/login.php?action=reset&token=' . $token;
                $subject = __('tp_reset_subject', 'Password Reset') . ' - ' . getSetting('company_name', APP_NAME);
                $body = "<p>" . __('tp_reset_greeting', 'Hello') . " " . htmlspecialchars($resetReqTenant['first_name']) . ",</p>";
                $body .= "<p>" . __('tp_reset_instruction', 'Click the link below to reset your tenant portal password:') . "</p>";
                $body .= "<p><a href='{$resetUrl}'>{$resetUrl}</a></p>";
                $body .= "<p>" . __('tp_reset_expiry', 'This link expires in 2 hours.') . "</p>";

                $headers = "From: " . MAIL_FROM . "\r\nContent-Type: text/html; charset=UTF-8\r\n";
                @mail($resetReqTenant['email'], $subject, $body, $headers);
            }
        }

        // Always show success message (don't reveal if account exists)
        $success = __('tp_reset_sent', 'If an account exists with that email/phone, a reset link has been sent.');
        $requestReset = false;
    }
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$resetMode && !$requestReset && !isset($_POST['new_password']) && !isset($_POST['identifier'])) {
    $identifier = trim($_POST['login_identifier'] ?? '');
    $password = $_POST['login_password'] ?? '';

    if (!$portalEnabled || !$tierOk) {
        $error = __('tp_disabled', 'The tenant portal is not available.');
    } elseif (empty($identifier) || empty($password)) {
        $error = __('tp_login_required', 'Please enter your email/phone and password.');
    } else {
        $stmt = $conn->prepare("
            SELECT t.id, t.first_name, t.last_name, t.phone, t.email,
                   t.portal_enabled, t.portal_password_hash, t.status
            FROM tenants t
            WHERE (t.email = :ident1 OR t.phone = :ident2)
        ");
        $stmt->execute(['ident1' => $identifier, 'ident2' => $identifier]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            $error = __('tp_login_failed', 'Invalid email/phone or password.');
        } elseif (!$tenant['portal_enabled']) {
            $error = __('tp_not_enabled', 'Portal access has not been enabled for your account. Please contact your landlord.');
        } elseif ($tenant['status'] !== 'active') {
            $error = __('tp_inactive', 'Your tenant account is not active.');
        } elseif (empty($tenant['portal_password_hash'])) {
            $error = __('tp_no_password', 'No portal password set. Please contact your landlord to set up your portal access.');
        } elseif (password_verify($password, $tenant['portal_password_hash'])) {
            // Success — set session
            $_SESSION['tenant_portal_id'] = $tenant['id'];
            $_SESSION['tenant_portal_tenant_id'] = $tenant['id'];
            $_SESSION['tenant_portal_name'] = $tenant['first_name'] . ' ' . $tenant['last_name'];
            $_SESSION['tenant_portal_email'] = $tenant['email'];
            $_SESSION['tenant_portal_phone'] = $tenant['phone'];

            // Update last login
            $conn->prepare("UPDATE tenants SET portal_last_login = NOW() WHERE id = ?")
                 ->execute([$tenant['id']]);

            // Log activity
            logActivity('login', 'tenant_portal', $tenant['id'], ['method' => 'portal_login']);

            header('Location: ' . BASE_URL . 'modules/tenant-portal/');
            exit;
        } else {
            $error = __('tp_login_failed', 'Invalid email/phone or password.');
        }
    }
}

$currentLang = getCurrentLang();
$dir = isRTL() ? 'rtl' : 'ltr';
$companyName = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>" dir="<?php echo $dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php _e('tp_login', 'Tenant Login'); ?> - <?php echo htmlspecialchars($companyName); ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

    <style>
        :root {
            --re-primary: #1a6b4a;
            --re-primary-dark: #134f37;
            --re-accent: #e8a838;
        }

        * { box-sizing: border-box; }

        body {
            background: linear-gradient(135deg, #0f4c35 0%, #1a6b4a 50%, #2d8f64 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            margin: 0;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
            pointer-events: none;
            z-index: 0;
        }

        .login-container { width: 100%; max-width: 420px; padding: 1rem; position: relative; z-index: 1; }

        .login-card {
            background: white;
            border-radius: 1.25rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 2.5rem 2rem 2rem;
        }

        .login-header { text-align: center; margin-bottom: 2rem; }

        .login-logo {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, var(--re-accent), #f0c060);
            border-radius: 16px;
            display: inline-flex; align-items: center; justify-content: center;
            margin-bottom: 1rem;
            box-shadow: 0 4px 15px rgba(232, 168, 56, 0.3);
        }

        .login-logo i { font-size: 2rem; color: white; }

        .login-header h1 { font-size: 1.4rem; font-weight: 700; color: #1a1a2e; margin-bottom: 0.25rem; }
        .login-header p { color: #6c757d; margin-bottom: 0; font-size: 0.9rem; }

        .form-label { font-weight: 500; color: #344054; font-size: 0.875rem; }

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
            background: #f9fafb; border: 1.5px solid #d0d5dd;
            border-right: none; border-radius: 0.625rem 0 0 0.625rem;
            color: #667085;
        }

        .input-group .form-control {
            border-left: none; border-radius: 0 0.625rem 0.625rem 0;
        }

        .btn-login {
            padding: 0.7rem; font-size: 1rem; font-weight: 600;
            border-radius: 0.625rem; background: var(--re-accent);
            border: none; width: 100%; color: #333; transition: all 0.2s;
        }

        .btn-login:hover {
            background: #d4962e; transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(232, 168, 56, 0.35); color: #333;
        }

        .forgot-link { color: var(--re-primary); text-decoration: none; font-size: 0.85rem; }
        .forgot-link:hover { text-decoration: underline; color: var(--re-primary-dark); }

        .lang-switcher { position: fixed; top: 1rem; right: 1rem; z-index: 10; }
        .lang-switcher .btn {
            padding: 0.2rem 0.6rem; font-size: 0.8rem; border-radius: 50px;
            color: rgba(255,255,255,0.7); border-color: rgba(255,255,255,0.3);
        }
        .lang-switcher .btn.active { background: rgba(255,255,255,0.2); color: white; border-color: rgba(255,255,255,0.5); }

        .copyright { text-align: center; margin-top: 1.5rem; color: rgba(255,255,255,0.6); font-size: 0.8rem; }

        @media (max-width: 576px) { .login-card { padding: 2rem 1.5rem 1.5rem; } }
    </style>
</head>
<body>
    <!-- Language Switcher -->
    <div class="lang-switcher">
        <a href="?lang=en<?php echo $resetMode ? '&action=reset&token=' . urlencode($resetToken) : ''; ?><?php echo $requestReset ? '&action=forgot' : ''; ?>" class="btn btn-outline-light btn-sm <?php echo $currentLang === 'en' ? 'active' : ''; ?>">EN</a>
        <a href="?lang=sw<?php echo $resetMode ? '&action=reset&token=' . urlencode($resetToken) : ''; ?><?php echo $requestReset ? '&action=forgot' : ''; ?>" class="btn btn-outline-light btn-sm <?php echo $currentLang === 'sw' ? 'active' : ''; ?>">SW</a>
        <a href="?lang=ar<?php echo $resetMode ? '&action=reset&token=' . urlencode($resetToken) : ''; ?><?php echo $requestReset ? '&action=forgot' : ''; ?>" class="btn btn-outline-light btn-sm <?php echo $currentLang === 'ar' ? 'active' : ''; ?>">AR</a>
        <a href="?lang=hi<?php echo $resetMode ? '&action=reset&token=' . urlencode($resetToken) : ''; ?><?php echo $requestReset ? '&action=forgot' : ''; ?>" class="btn btn-outline-light btn-sm <?php echo $currentLang === 'hi' ? 'active' : ''; ?>">HI</a>
        <a href="?lang=ur<?php echo $resetMode ? '&action=reset&token=' . urlencode($resetToken) : ''; ?><?php echo $requestReset ? '&action=forgot' : ''; ?>" class="btn btn-outline-light btn-sm <?php echo $currentLang === 'ur' ? 'active' : ''; ?>">UR</a>
        <a href="?lang=gu<?php echo $resetMode ? '&action=reset&token=' . urlencode($resetToken) : ''; ?><?php echo $requestReset ? '&action=forgot' : ''; ?>" class="btn btn-outline-light btn-sm <?php echo $currentLang === 'gu' ? 'active' : ''; ?>">GU</a>
    </div>

    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">
                    <i class="bi bi-house-door"></i>
                </div>
                <?php if ($resetMode && isset($resetTenant)): ?>
                    <h1><?php _e('tp_reset_title', 'Reset Password'); ?></h1>
                    <p><?php echo htmlspecialchars($resetTenant['first_name'] . ' ' . $resetTenant['last_name']); ?></p>
                <?php elseif ($requestReset): ?>
                    <h1><?php _e('tp_forgot_title', 'Forgot Password'); ?></h1>
                    <p><?php _e('tp_forgot_desc', 'Enter your email or phone to receive a reset link.'); ?></p>
                <?php else: ?>
                    <h1><?php _e('tp_portal', 'Tenant Portal'); ?></h1>
                    <p><?php echo htmlspecialchars($companyName); ?></p>
                <?php endif; ?>
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

            <?php if (!$portalEnabled || !$tierOk): ?>
                <div class="text-center text-muted py-3">
                    <i class="bi bi-lock" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('tp_disabled', 'The tenant portal is not available.'); ?></p>
                    <small class="text-muted"><?php _e('tp_contact_landlord', 'Please contact your landlord for assistance.'); ?></small>
                </div>

            <?php elseif ($resetMode && isset($resetTenant)): ?>
                <!-- Password Reset Form -->
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="new_password" class="form-label"><?php _e('new_password'); ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="new_password" name="new_password" placeholder="<?php _e('new_password'); ?>" required minlength="6">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="confirm_password" class="form-label"><?php _e('confirm_password'); ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="<?php _e('confirm_password'); ?>" required minlength="6">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-login">
                        <i class="bi bi-check-lg me-1"></i> <?php _e('update_password'); ?>
                    </button>
                </form>
                <div class="text-center mt-3">
                    <a href="<?php echo BASE_URL; ?>modules/tenant-portal/login.php" class="forgot-link"><?php _e('back_to_login'); ?></a>
                </div>

            <?php elseif ($requestReset): ?>
                <!-- Forgot Password Form -->
                <form method="POST" action="">
                    <div class="mb-4">
                        <label for="identifier" class="form-label"><?php _e('tp_email_or_phone', 'Email or Phone'); ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="text" class="form-control" id="identifier" name="identifier" placeholder="<?php _e('tp_email_or_phone', 'Email or Phone'); ?>" required autofocus>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-login">
                        <i class="bi bi-send me-1"></i> <?php _e('send_reset_link'); ?>
                    </button>
                </form>
                <div class="text-center mt-3">
                    <a href="<?php echo BASE_URL; ?>modules/tenant-portal/login.php" class="forgot-link"><?php _e('back_to_login'); ?></a>
                </div>

            <?php else: ?>
                <!-- Login Form -->
                <form method="POST" action="" autocomplete="off">
                    <div class="mb-3">
                        <label for="login_identifier" class="form-label"><?php _e('tp_email_or_phone', 'Email or Phone'); ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control" id="login_identifier" name="login_identifier"
                                   placeholder="<?php _e('tp_email_or_phone', 'Email or Phone'); ?>"
                                   value="<?php echo isset($_POST['login_identifier']) ? clean($_POST['login_identifier']) : ''; ?>"
                                   required autofocus>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="login_password" class="form-label"><?php _e('password'); ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="login_password" name="login_password"
                                   placeholder="<?php _e('password'); ?>" required>
                        </div>
                    </div>

                    <div class="text-end mb-4">
                        <a href="<?php echo BASE_URL; ?>modules/tenant-portal/login.php?action=forgot" class="forgot-link">
                            <?php _e('forgot_password'); ?>
                        </a>
                    </div>

                    <button type="submit" class="btn btn-login">
                        <i class="bi bi-box-arrow-in-right me-1"></i> <?php _e('sign_in'); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div class="copyright">
            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($companyName); ?> &mdash; <?php _e('tp_powered_by', 'Powered by FIS Real Estate'); ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(el) {
                el.classList.remove('show');
                setTimeout(() => el.remove(), 150);
            });
        }, 5000);
    </script>
</body>
</html>
