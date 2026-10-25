<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if (isLoggedIn()) {
    redirect('');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = clean($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = __('email') . ' is required and must be valid';
    } else {
        $stmt = $conn->prepare("SELECT id, full_name, email FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate reset token
            $token = generateToken();
            $expires = date('Y-m-d H:i:s', strtotime('+' . PASSWORD_RESET_EXPIRY . ' hours'));

            $conn->prepare("UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?")
                 ->execute([$token, $expires, $user['id']]);

            // Build reset link
            $resetLink = BASE_URL . 'modules/auth/reset_password.php?token=' . $token;

            // Send email
            $subject = APP_NAME . ' - ' . __('reset_password');
            $htmlBody = '
            <div style="font-family:Segoe UI,sans-serif;max-width:500px;margin:0 auto;padding:20px;">
                <h2 style="color:#1a6b4a;">' . APP_NAME . '</h2>
                <p>Hi ' . htmlspecialchars($user['full_name']) . ',</p>
                <p>You requested a password reset. Click the button below to set a new password:</p>
                <p style="text-align:center;margin:30px 0;">
                    <a href="' . $resetLink . '" style="background:#1a6b4a;color:white;padding:12px 30px;border-radius:8px;text-decoration:none;font-weight:600;">
                        Reset Password
                    </a>
                </p>
                <p style="color:#666;font-size:0.85em;">This link expires in ' . PASSWORD_RESET_EXPIRY . ' hours. If you didn\'t request this, ignore this email.</p>
                <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">
                <p style="color:#999;font-size:0.8em;">&copy; ' . date('Y') . ' ' . APP_NAME . '</p>
            </div>';

            $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
            $headers .= "Reply-To: " . MAIL_FROM . "\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

            @mail($user['email'], $subject, $htmlBody, $headers);

            logActivity('password_reset_request', 'user', $user['id']);
        }

        // Always show success to prevent email enumeration
        $success = __('reset_link_sent');
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
    <title><?php _e('forgot_password'); ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --re-primary: #1a6b4a;
            --re-primary-dark: #134f37;
        }
        body {
            background: linear-gradient(135deg, #0f4c35 0%, #1a6b4a 50%, #2d8f64 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        .card-container { width: 100%; max-width: 420px; padding: 1rem; }
        .card-box {
            background: white;
            border-radius: 1.25rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 2.5rem 2rem 2rem;
        }
        .form-control { padding: 0.7rem 1rem; border-radius: 0.625rem; border: 1.5px solid #d0d5dd; }
        .form-control:focus { border-color: var(--re-primary); box-shadow: 0 0 0 3px rgba(26,107,74,0.15); }
        .btn-primary-re {
            background: var(--re-primary); border: none; border-radius: 0.625rem;
            padding: 0.7rem; font-weight: 600; width: 100%; color: white;
        }
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
                    <i class="bi bi-envelope-at" style="font-size:1.5rem;color:white;"></i>
                </div>
                <h4 class="fw-bold mb-1"><?php _e('forgot_password'); ?></h4>
                <p class="text-muted" style="font-size:0.9rem;"><?php _e('reset_password_subtitle'); ?></p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i> <?php echo $error; ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i> <?php echo $success; ?></div>
            <?php else: ?>
            <form method="POST">
                <div class="mb-4">
                    <label for="email" class="form-label"><?php _e('email'); ?></label>
                    <input type="email" class="form-control" id="email" name="email"
                           placeholder="you@example.com" required autofocus
                           value="<?php echo isset($_POST['email']) ? clean($_POST['email']) : ''; ?>">
                </div>
                <button type="submit" class="btn btn-primary-re">
                    <i class="bi bi-send me-1"></i> <?php _e('send_reset_link'); ?>
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
