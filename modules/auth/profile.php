<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/header.php';
$pageTitle = __('profile') . ' - ' . APP_NAME;

// Get current user info
$stmt = $conn->prepare("
    SELECT u.*, r.name as role_name
    FROM users u
    INNER JOIN roles r ON u.role_id = r.id
    WHERE u.id = ?
");
$stmt->execute([getUserId()]);
$user = $stmt->fetch();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $fullName = clean($_POST['full_name'] ?? '');
    $email = clean($_POST['email'] ?? '');
    $phone = clean($_POST['phone'] ?? '');
    $language = $_POST['language'] ?? 'en';

    if (empty($fullName)) {
        $error = __('name') . ' is required';
    } else {
        $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, language = ? WHERE id = ?")
             ->execute([$fullName, $email, $phone, $language, getUserId()]);

        // Update session data
        $_SESSION['user_data']['name'] = $fullName;
        $_SESSION['user_data']['email'] = $email;
        $_SESSION['user_data']['language'] = $language;

        setLang($language);

        logActivity('updated', 'user', getUserId());
        $success = __('success');

        // Refresh user data
        $stmt->execute([getUserId()]);
        $user = $stmt->fetch();
    }
}
?>

<div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-person me-2"></i><?php _e('profile'); ?></h5>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                <div class="alert alert-success py-2"><?php echo $success; ?></div>
                <?php endif; ?>

                <form method="POST">
                    <?php echo csrfField(); ?>

                    <div class="mb-3">
                        <label class="form-label"><?php _e('username'); ?></label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?php _e('roles'); ?></label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['role_name']); ?>" disabled>
                    </div>

                    <div class="mb-3">
                        <label for="full_name" class="form-label"><?php _e('name'); ?> *</label>
                        <input type="text" class="form-control" id="full_name" name="full_name"
                               value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label"><?php _e('email'); ?></label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="phone" class="form-label"><?php _e('phone'); ?></label>
                        <input type="text" class="form-control" id="phone" name="phone"
                               value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                    </div>

                    <div class="mb-4">
                        <label for="language" class="form-label">Language</label>
                        <select class="form-select" id="language" name="language">
                            <option value="en" <?php echo $user['language'] === 'en' ? 'selected' : ''; ?>>English</option>
                            <option value="sw" <?php echo $user['language'] === 'sw' ? 'selected' : ''; ?>>Kiswahili</option>
                            <option value="ar" <?php echo $user['language'] === 'ar' ? 'selected' : ''; ?>>العربية</option>
                            <option value="hi" <?php echo $user['language'] === 'hi' ? 'selected' : ''; ?>>हिन्दी</option>
                            <option value="ur" <?php echo $user['language'] === 'ur' ? 'selected' : ''; ?>>اردو</option>
                            <option value="gu" <?php echo $user['language'] === 'gu' ? 'selected' : ''; ?>>ગુજરાતી</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i> <?php _e('save'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
