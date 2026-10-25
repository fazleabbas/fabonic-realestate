<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/header.php';
$pageTitle = __('change_password') . ' - ' . APP_NAME;

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Verify current password
    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([getUserId()]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        $error = __('current_password_wrong');
    } elseif (strlen($newPassword) < 8) {
        $error = __('password_min_length');
    } elseif ($newPassword !== $confirmPassword) {
        $error = __('passwords_dont_match');
    } else {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
             ->execute([$hash, getUserId()]);

        logActivity('password_changed', 'user', getUserId());
        $success = __('password_updated');
    }
}
?>

<div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-key me-2"></i><?php _e('change_password'); ?></h5>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i> <?php echo $error; ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i> <?php echo $success; ?></div>
                <?php endif; ?>

                <form method="POST">
                    <?php echo csrfField(); ?>

                    <div class="mb-3">
                        <label for="current_password" class="form-label"><?php _e('current_password'); ?></label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label"><?php _e('new_password'); ?></label>
                        <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" required>
                        <div class="form-text"><?php _e('password_min_length'); ?></div>
                    </div>

                    <div class="mb-4">
                        <label for="confirm_password" class="form-label"><?php _e('confirm_password'); ?></label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" required>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i> <?php _e('update_password'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
