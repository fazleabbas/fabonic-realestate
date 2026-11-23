<?php
/**
 * FIS Real Estate — Central Admin Login
 */
require_once __DIR__ . '/includes/auth.php';

// Already logged in?
if (isAdminLoggedIn()) {
    header('Location: trials.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        if (adminLogin($username, $password)) {
            header('Location: trials.php');
            exit;
        } else {
            $error = 'Invalid username or password';
        }
    } else {
        $error = 'Please enter both username and password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — FIS Real Estate</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #0f3d2d 0%, #1a6b4a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .login-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 8px 40px rgba(0,0,0,0.2);
            padding: 2.5rem;
            width: 100%;
            max-width: 400px;
        }
        .login-brand {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .login-brand-icon {
            width: 56px; height: 56px;
            background: linear-gradient(135deg, #1a6b4a, #e8a838);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.75rem;
        }
        .login-brand-icon i { color: white; font-size: 1.5rem; }
        .login-brand h4 { font-weight: 700; color: #134f37; margin-bottom: 0.1rem; }
        .login-brand p { font-size: 0.82rem; color: #888; margin: 0; }
        .form-control { border-radius: 0.5rem; padding: 0.6rem 0.75rem; }
        .form-control:focus { border-color: #1a6b4a; box-shadow: 0 0 0 0.2rem rgba(26,107,74,0.15); }
        .btn-login {
            background: #1a6b4a; color: white; border: none;
            padding: 0.6rem; border-radius: 0.5rem; font-weight: 600;
            width: 100%; transition: background 0.2s;
        }
        .btn-login:hover { background: #134f37; color: white; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-brand">
            <div class="login-brand-icon"><i class="bi bi-building"></i></div>
            <h4>FIS RE Admin</h4>
            <p>Central Administration Panel</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 px-3" style="font-size:0.85rem;">
            <i class="bi bi-exclamation-circle me-1"></i> <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="mb-3">
                <label class="form-label fw-bold" style="font-size:0.85rem;">Username</label>
                <input type="text" class="form-control" name="username" required autofocus value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold" style="font-size:0.85rem;">Password</label>
                <input type="password" class="form-control" name="password" required>
            </div>
            <button type="submit" class="btn btn-login">
                <i class="bi bi-box-arrow-in-right me-1"></i> Login
            </button>
        </form>

        <div class="text-center mt-3">
            <a href="../public/" style="font-size:0.82rem;color:#888;text-decoration:none;">&larr; Back to website</a>
        </div>
    </div>
</body>
</html>
