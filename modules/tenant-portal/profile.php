<?php
/**
 * FIS Real Estate — Tenant Portal Profile Management (Part 16.4)
 * Update phone, email, emergency contact, change password.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

// Check portal enabled + tier
if (!getSetting('tenant_portal_enabled', '0') || !hasTier('professional')) {
    header('Location: ' . BASE_URL . 'modules/tenant-portal/login.php');
    exit;
}

$pageTitle = __('tp_my_profile', 'My Profile');
$tenantId = (int) ($_SESSION['tenant_portal_id'] ?? 0);

// Fetch current profile
$stmt = $conn->prepare("
    SELECT id, first_name, last_name, phone, email,
           emergency_contact_name, emergency_contact_phone, emergency_contact_relation,
           status, move_in_date, portal_last_login
    FROM tenants WHERE id = ?
");
$stmt->execute([$tenantId]);
$profile = $stmt->fetch();

if (!$profile) {
    setAlert(__('error'), 'danger');
    header('Location: ' . BASE_URL . 'modules/tenant-portal/');
    exit;
}

$errors = [];
$success = '';
$passwordErrors = [];
$passwordSuccess = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    checkCSRF();

    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $emergencyName = trim($_POST['emergency_contact_name'] ?? '');
    $emergencyPhone = trim($_POST['emergency_contact_phone'] ?? '');
    $emergencyRelation = trim($_POST['emergency_contact_relation'] ?? '');

    if (empty($phone)) $errors[] = __('tp_phone_required', 'Phone number is required.');
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = __('invalid_email', 'Invalid email address.');

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                UPDATE tenants SET
                    phone = ?, email = ?,
                    emergency_contact_name = ?, emergency_contact_phone = ?, emergency_contact_relation = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $phone, $email ?: null,
                $emergencyName ?: null, $emergencyPhone ?: null, $emergencyRelation ?: null,
                $tenantId
            ]);

            $_SESSION['tenant_portal_phone'] = $phone;
            $_SESSION['tenant_portal_email'] = $email;

            // Refresh profile
            $profile['phone'] = $phone;
            $profile['email'] = $email;
            $profile['emergency_contact_name'] = $emergencyName;
            $profile['emergency_contact_phone'] = $emergencyPhone;
            $profile['emergency_contact_relation'] = $emergencyRelation;

            logActivity('updated', 'tenant_portal_profile', $tenantId, ['fields' => 'phone,email,emergency']);
            $success = __('tp_profile_updated', 'Profile updated successfully.');
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    checkCSRF();

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword)) $passwordErrors[] = __('tp_current_required', 'Current password is required.');
    if (empty($newPassword)) $passwordErrors[] = __('tp_new_required', 'New password is required.');
    if (strlen($newPassword) < 6) $passwordErrors[] = __('tp_password_min', 'Password must be at least 6 characters.');
    if ($newPassword !== $confirmPassword) $passwordErrors[] = __('passwords_dont_match');

    if (empty($passwordErrors)) {
        $stmt = $conn->prepare("SELECT portal_password_hash FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $current = $stmt->fetch();

        if (!$current || !password_verify($currentPassword, $current['portal_password_hash'])) {
            $passwordErrors[] = __('current_password_wrong');
        } else {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE tenants SET portal_password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $tenantId]);

            logActivity('updated', 'tenant_portal_password', $tenantId);
            $passwordSuccess = __('tp_password_changed', 'Password changed successfully.');
        }
    }
}

require_once __DIR__ . '/../../includes/tenant-header.php';
?>

<!-- Page Header -->
<div class="mb-4">
    <h4 class="mb-1"><i class="bi bi-person me-2" style="color:var(--re-primary);"></i><?php _e('tp_my_profile', 'My Profile'); ?></h4>
    <p class="text-muted mb-0"><?php echo htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']); ?></p>
</div>

<div class="row g-4">
    <!-- Profile Info Card -->
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom"><i class="bi bi-person-lines-fill me-1"></i> <?php _e('tp_contact_info', 'Contact Information'); ?></div>
            <div class="card-body">

                <?php if ($errors): ?>
                <div class="alert alert-danger py-2">
                    <ul class="mb-0" style="padding-left:1rem;">
                        <?php foreach ($errors as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i><?php echo $success; ?></div>
                <?php endif; ?>

                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="update_profile" value="1">

                    <!-- Read-only name -->
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('first_name', 'First Name'); ?></label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($profile['first_name']); ?>" disabled>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('last_name', 'Last Name'); ?></label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($profile['last_name']); ?>" disabled>
                        </div>
                    </div>

                    <!-- Editable fields -->
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('phone'); ?> <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control" name="phone" value="<?php echo htmlspecialchars($profile['phone']); ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('email'); ?></label>
                            <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($profile['email'] ?? ''); ?>">
                        </div>
                    </div>

                    <hr>
                    <h6 class="fw-semibold mb-3"><i class="bi bi-exclamation-triangle me-1"></i> <?php _e('emergency_contact', 'Emergency Contact'); ?></h6>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold"><?php _e('contact_name', 'Name'); ?></label>
                            <input type="text" class="form-control" name="emergency_contact_name" value="<?php echo htmlspecialchars($profile['emergency_contact_name'] ?? ''); ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold"><?php _e('contact_phone', 'Phone'); ?></label>
                            <input type="tel" class="form-control" name="emergency_contact_phone" value="<?php echo htmlspecialchars($profile['emergency_contact_phone'] ?? ''); ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold"><?php _e('relationship', 'Relationship'); ?></label>
                            <input type="text" class="form-control" name="emergency_contact_relation" value="<?php echo htmlspecialchars($profile['emergency_contact_relation'] ?? ''); ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i> <?php _e('save_changes', 'Save Changes'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column -->
    <div class="col-12 col-lg-5">
        <!-- Account Info -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom"><i class="bi bi-info-circle me-1"></i> <?php _e('tp_account_info', 'Account Information'); ?></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td class="text-muted"><?php _e('status'); ?></td><td><span class="badge bg-<?php echo $profile['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($profile['status']); ?></span></td></tr>
                        <?php if ($profile['move_in_date']): ?>
                        <tr><td class="text-muted"><?php _e('move_in_date', 'Move-in Date'); ?></td><td><?php echo formatDate($profile['move_in_date']); ?></td></tr>
                        <?php endif; ?>
                        <?php if ($profile['portal_last_login']): ?>
                        <tr><td class="text-muted"><?php _e('tp_last_login', 'Last Login'); ?></td><td><?php echo formatDateTime($profile['portal_last_login']); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom"><i class="bi bi-key me-1"></i> <?php _e('change_password'); ?></div>
            <div class="card-body">

                <?php if ($passwordErrors): ?>
                <div class="alert alert-danger py-2">
                    <ul class="mb-0" style="padding-left:1rem;">
                        <?php foreach ($passwordErrors as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if ($passwordSuccess): ?>
                <div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i><?php echo $passwordSuccess; ?></div>
                <?php endif; ?>

                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="change_password" value="1">

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('current_password'); ?></label>
                        <input type="password" class="form-control" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('new_password'); ?></label>
                        <input type="password" class="form-control" name="new_password" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('confirm_password'); ?></label>
                        <input type="password" class="form-control" name="confirm_password" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="bi bi-lock me-1"></i> <?php _e('update_password'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/tenant-footer.php'; ?>
