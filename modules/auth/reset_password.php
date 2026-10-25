<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if (isLoggedIn()) {
    redirect('');
}

$error = '';
$token = $_GET['token'] ?? '';
$validToken = false;
$user = null;

// Validate token
if (!empty($token)) {
    $stmt = $conn->prepare("
        SELECT id, full_name FROM users
        WHERE password_reset_token = ? AND password_reset_expires > NOW() AND status = 'active'
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    $validToken = (bool) $user;
}

if (!$validToken) {
    $error = __('reset_link_invalid');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = __('password_min_length');
    } elseif ($password !== $confirmPassword) {
        $error = __('passwords_dont_match');
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $conn->prepare("
            UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?
        ")->execute([$hash, $user['id']]);

        logActivity('password_reset', 'user', $user['id']);

        // Redirect to login with success
        header("Location: " . BASE_URL . "modules/auth/login.php?reset=success");
        exit();
    }
}

$currentLang = getCurrentLang();
$dir = isRTL() ? 'rtl' : 'ltr';
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>" dir="<?php echo $dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('reset_password'); ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root { --re-primary: #1a6b4a; --re-primary-dark: #134f37; }
        body {
            background: linear-gradient(135deg, #0f4c35 0%, #1a6b4a 50%, #2d8f64 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        .card-container { width: 100%; max-width: 420px; padding: 1rem; }
        .card-box { background: white; border-radius: 1.25rem; box-shadow: 0 20px 60px rgba(0,0,0,0.3); padding: 2.5rem 2rem 2rem; }
        .form-control { padding: 0.7rem 1rem; border-radius: 0.625rem; border: 1.5px solid #d0d5dd; }
        .form-control:focus { border-color: var(--re-primary); box-shadow: 0 0 0 3px rgba(26,107,74,0.15); }
        .btn-primary-re { background: var(--re-primary); border: none; border-radius: 0.625rem; padding: 0.7rem; font-weight: 600; width: 100%; color: white; }
        .btn-primary-re:hover { background: var(--re-primary-dark); color: white; }
        a.back-link { color: var(--re-primary); text-decoration: none; font-size: 0.9rem; }
        a.back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card-container">
        <div class="card-box">
            <div class="text-center mb-4">
                <div style="width:56px;height:56px;background:linear-gradient(135deg,#1a6b4a,#e8a838);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                    <i class="bi bi-key" style="font-size:1.5rem;color:white;"></i>
                </div>
                <h4 class="fw-bold mb-1"><?php _e('reset_password'); ?></h4>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i> <?php echo $error; ?></div>
            <?php endif; ?>

            <?php if ($validToken): ?>
            <p class="text-muted mb-3" style="font-size:0.9rem;">
                <?php echo htmlspecialchars($user['full_name']); ?>, enter your new password below.
            </p>
            <form method="POST">
                <div class="mb-3">
                    <label for="password" class="form-label"><?php _e('new_password'); ?></label>
                    <input type="password" class="form-control" id="password" name="password"
                           minlength="8" required autofocus placeholder="Min. 8 characters">
                </div>
                <div class="mb-4">
                    <label for="confirm_password" class="form-label"><?php _e('confirm_password'); ?></label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                           minlength="8" required placeholder="Confirm password">
                </div>
                <button type="submit" class="btn btn-primary-re">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('update_password'); ?>
                </button>
            </form>
            <?php endif; ?>

            <div class="text-center mt-3">
                <a href="<?php echo BASE_URL; ?>modules/auth/login.php" class="back-link">
                    <i class="bi bi-arrow-left"></i> <?php _e('back_to_login'); ?>
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
