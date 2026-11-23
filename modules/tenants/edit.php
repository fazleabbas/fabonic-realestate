<?php
/**
 * FIS Real Estate — Edit Tenant (7.1 + 7.2 + 7.4)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

$subStatus = enforceSubscription();

if (!hasPermission('tenants', 'edit')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Tenant not found', 'danger');
    redirect('modules/tenants/');
}

// Fetch tenant
$stmt = $conn->prepare("SELECT * FROM tenants WHERE id = :id");
$stmt->execute(['id' => $id]);
$tenant = $stmt->fetch();

if (!$tenant) {
    setAlert('Tenant not found', 'danger');
    redirect('modules/tenants/');
}

// Property-based access scoping guard
if (!canAccessTenant($id)) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/tenants/');
}

$pageTitle = __('edit') . ' - ' . $tenant['first_name'] . ' ' . $tenant['last_name'];

// Handle form submission
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $tenant['first_name'] = trim($_POST['first_name'] ?? '');
    $tenant['last_name'] = trim($_POST['last_name'] ?? '');
    $tenant['phone'] = trim($_POST['phone'] ?? '');
    $tenant['email'] = trim($_POST['email'] ?? '');
    $tenant['id_type'] = trim($_POST['id_type'] ?? '');
    $tenant['id_number'] = trim($_POST['id_number'] ?? '');
    $tenant['occupation'] = trim($_POST['occupation'] ?? '');
    $tenant['employer'] = trim($_POST['employer'] ?? '');
    $tenant['employer_phone'] = trim($_POST['employer_phone'] ?? '');
    $tenant['emergency_contact_name'] = trim($_POST['emergency_contact_name'] ?? '');
    $tenant['emergency_contact_phone'] = trim($_POST['emergency_contact_phone'] ?? '');
    $tenant['emergency_contact_relation'] = trim($_POST['emergency_contact_relation'] ?? '');
    $tenant['tin'] = trim($_POST['tin'] ?? '');
    $tenant['next_of_kin_name'] = trim($_POST['next_of_kin_name'] ?? '');
    $tenant['next_of_kin_relationship'] = trim($_POST['next_of_kin_relationship'] ?? '');
    $tenant['next_of_kin_phone'] = trim($_POST['next_of_kin_phone'] ?? '');
    $tenant['next_of_kin_address'] = trim($_POST['next_of_kin_address'] ?? '');
    $tenant['reference1_name'] = trim($_POST['reference1_name'] ?? '');
    $tenant['reference1_phone'] = trim($_POST['reference1_phone'] ?? '');
    $tenant['reference2_name'] = trim($_POST['reference2_name'] ?? '');
    $tenant['reference2_phone'] = trim($_POST['reference2_phone'] ?? '');
    $tenant['screening_notes'] = trim($_POST['screening_notes'] ?? '');
    $tenant['status'] = trim($_POST['status'] ?? 'active');
    $tenant['move_in_date'] = trim($_POST['move_in_date'] ?? '');
    $tenant['move_out_date'] = trim($_POST['move_out_date'] ?? '');

    $validStatuses = ['active', 'notice_given', 'moved_out'];
    $validIdTypes = ['national_id', 'passport', 'driving_license', 'voter_id', ''];

    if ($tenant['first_name'] === '') $errors[] = __('first_name_required', 'First name is required');
    if ($tenant['last_name'] === '') $errors[] = __('last_name_required', 'Last name is required');
    if ($tenant['phone'] === '') $errors[] = __('phone_required', 'Phone number is required');
    if (!in_array($tenant['status'], $validStatuses)) $errors[] = __('invalid_status', 'Invalid status');
    if (!in_array($tenant['id_type'], $validIdTypes)) $errors[] = __('invalid_id_type', 'Invalid ID type');
    if ($tenant['email'] !== '' && !filter_var($tenant['email'], FILTER_VALIDATE_EMAIL)) $errors[] = __('invalid_email', 'Invalid email address');

    // Soft TIN check — reformats a 9-digit number, never blocks the save.
    $tinCheck = normalizeTin($tenant['tin']);
    $tenant['tin'] = $tinCheck['value'] ?? '';
    $tinWarning = !$tinCheck['valid'];

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                UPDATE tenants SET
                    first_name = :first_name, last_name = :last_name, phone = :phone, email = :email,
                    id_type = :id_type, id_number = :id_number,
                    occupation = :occupation, employer = :employer, employer_phone = :employer_phone,
                    emergency_contact_name = :emergency_contact_name,
                    emergency_contact_phone = :emergency_contact_phone,
                    emergency_contact_relation = :emergency_contact_relation,
                    tin = :tin,
                    next_of_kin_name = :next_of_kin_name,
                    next_of_kin_relationship = :next_of_kin_relationship,
                    next_of_kin_phone = :next_of_kin_phone,
                    next_of_kin_address = :next_of_kin_address,
                    reference1_name = :reference1_name, reference1_phone = :reference1_phone,
                    reference2_name = :reference2_name, reference2_phone = :reference2_phone,
                    screening_notes = :screening_notes, status = :status,
                    move_in_date = :move_in_date, move_out_date = :move_out_date,
                    portal_enabled = :portal_enabled
                WHERE id = :id
            ");

            $portalEnabled = isset($_POST['portal_enabled']) ? 1 : 0;

            $stmt->execute([
                'first_name' => $tenant['first_name'],
                'last_name' => $tenant['last_name'],
                'phone' => $tenant['phone'],
                'email' => $tenant['email'] ?: null,
                'id_type' => $tenant['id_type'] ?: null,
                'id_number' => $tenant['id_number'] ?: null,
                'occupation' => $tenant['occupation'] ?: null,
                'employer' => $tenant['employer'] ?: null,
                'employer_phone' => $tenant['employer_phone'] ?: null,
                'emergency_contact_name' => $tenant['emergency_contact_name'] ?: null,
                'emergency_contact_phone' => $tenant['emergency_contact_phone'] ?: null,
                'emergency_contact_relation' => $tenant['emergency_contact_relation'] ?: null,
                'tin' => $tenant['tin'] ?: null,
                'next_of_kin_name' => $tenant['next_of_kin_name'] ?: null,
                'next_of_kin_relationship' => $tenant['next_of_kin_relationship'] ?: null,
                'next_of_kin_phone' => $tenant['next_of_kin_phone'] ?: null,
                'next_of_kin_address' => $tenant['next_of_kin_address'] ?: null,
                'reference1_name' => $tenant['reference1_name'] ?: null,
                'reference1_phone' => $tenant['reference1_phone'] ?: null,
                'reference2_name' => $tenant['reference2_name'] ?: null,
                'reference2_phone' => $tenant['reference2_phone'] ?: null,
                'screening_notes' => $tenant['screening_notes'] ?: null,
                'status' => $tenant['status'],
                'move_in_date' => $tenant['move_in_date'] ? date('Y-m-d', strtotime($tenant['move_in_date'])) : null,
                'move_out_date' => $tenant['move_out_date'] ? date('Y-m-d', strtotime($tenant['move_out_date'])) : null,
                'portal_enabled' => $portalEnabled,
                'id' => $id
            ]);

            // Set portal password if provided
            $portalPassword = trim($_POST['portal_password'] ?? '');
            if ($portalEnabled && !empty($portalPassword)) {
                if (strlen($portalPassword) >= 6) {
                    $hash = password_hash($portalPassword, PASSWORD_BCRYPT);
                    $pwStmt = $conn->prepare("UPDATE tenants SET portal_password_hash = ? WHERE id = ?");
                    $pwStmt->execute([$hash, $id]);
                }
            }

            logActivity('updated', 'tenant', $id, ['name' => $tenant['first_name'] . ' ' . $tenant['last_name']]);

            $successMsg = __('tenant_updated', 'Tenant updated successfully!');
            if ($tinWarning) {
                $successMsg .= ' ' . __('tin_format_warning', 'Note: the TIN does not look like a 9-digit number and was saved as typed.');
            }
            setAlert($successMsg, $tinWarning ? 'warning' : 'success');
            redirect('modules/tenants/view.php?id=' . $id);
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-pencil me-2"></i><?php _e('edit_tenant', 'Edit Tenant'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/tenants/"><?php _e('tenants'); ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/tenants/view.php?id=<?php echo $id; ?>"><?php echo htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('edit'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('tenants.edit'); ?>

<!-- Errors -->
<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?php echo htmlspecialchars($err); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Edit Form -->
<div class="card">
    <div class="card-body">
        <form method="POST" id="tenantForm">
            <?php echo csrfField(); ?>

            <!-- Personal Information -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-person me-1"></i> <?php _e('personal_info', 'Personal Information'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('first_name', 'First Name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($tenant['first_name']); ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('last_name', 'Last Name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($tenant['last_name']); ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('phone'); ?> <span class="text-danger">*</span></label>
                    <input type="tel" class="form-control" name="phone" value="<?php echo htmlspecialchars($tenant['phone']); ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('email'); ?></label>
                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($tenant['email'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('status'); ?></label>
                    <select name="status" class="form-select">
                        <option value="active" <?php echo $tenant['status'] === 'active' ? 'selected' : ''; ?>><?php _e('active', 'Active'); ?></option>
                        <option value="notice_given" <?php echo $tenant['status'] === 'notice_given' ? 'selected' : ''; ?>><?php _e('notice_given', 'Notice Given'); ?></option>
                        <option value="moved_out" <?php echo $tenant['status'] === 'moved_out' ? 'selected' : ''; ?>><?php _e('moved_out', 'Moved Out'); ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('move_in_date', 'Move-in Date'); ?></label>
                    <input type="date" class="form-control" name="move_in_date" value="<?php echo $tenant['move_in_date'] ? date('Y-m-d', strtotime($tenant['move_in_date'])) : ''; ?>">
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('move_out_date', 'Move-out Date'); ?></label>
                    <input type="date" class="form-control" name="move_out_date" value="<?php echo $tenant['move_out_date'] ? date('Y-m-d', strtotime($tenant['move_out_date'])) : ''; ?>">
                </div>
            </div>

            <hr>

            <!-- Identification -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-card-text me-1"></i> <?php _e('identification', 'Identification'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('id_type', 'ID Type'); ?></label>
                    <select name="id_type" class="form-select">
                        <option value=""><?php _e('select_id_type', 'Select ID Type'); ?></option>
                        <option value="national_id" <?php echo ($tenant['id_type'] ?? '') === 'national_id' ? 'selected' : ''; ?>><?php _e('national_id', 'National ID'); ?></option>
                        <option value="passport" <?php echo ($tenant['id_type'] ?? '') === 'passport' ? 'selected' : ''; ?>><?php _e('passport', 'Passport'); ?></option>
                        <option value="driving_license" <?php echo ($tenant['id_type'] ?? '') === 'driving_license' ? 'selected' : ''; ?>><?php _e('driving_license', 'Driving License'); ?></option>
                        <option value="voter_id" <?php echo ($tenant['id_type'] ?? '') === 'voter_id' ? 'selected' : ''; ?>><?php _e('voter_id', 'Voter ID'); ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('id_number', 'ID Number'); ?></label>
                    <input type="text" class="form-control" name="id_number" value="<?php echo htmlspecialchars($tenant['id_number'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('tin', 'TIN'); ?></label>
                    <input type="text" class="form-control" name="tin" value="<?php echo htmlspecialchars($tenant['tin'] ?? ''); ?>" placeholder="<?php _e('tin_placeholder', 'e.g. 123-456-789'); ?>" inputmode="numeric">
                    <small class="text-muted"><?php _e('tin_help', '9 digits. Saved as typed if it does not match.'); ?></small>
                </div>
                <div class="col-12">
                    <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i><?php _e('id_copy_manage_note', 'ID copy can be managed from the tenant view page.'); ?></p>
                </div>
            </div>

            <hr>

            <!-- Employment -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-briefcase me-1"></i> <?php _e('employment', 'Employment'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('occupation', 'Occupation'); ?></label>
                    <input type="text" class="form-control" name="occupation" value="<?php echo htmlspecialchars($tenant['occupation'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('employer', 'Employer'); ?></label>
                    <input type="text" class="form-control" name="employer" value="<?php echo htmlspecialchars($tenant['employer'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('employer_phone', 'Employer Phone'); ?></label>
                    <input type="tel" class="form-control" name="employer_phone" value="<?php echo htmlspecialchars($tenant['employer_phone'] ?? ''); ?>">
                </div>
            </div>

            <hr>

            <!-- Emergency Contact -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-exclamation-triangle me-1"></i> <?php _e('emergency_contact', 'Emergency Contact'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('contact_name', 'Contact Name'); ?></label>
                    <input type="text" class="form-control" name="emergency_contact_name" value="<?php echo htmlspecialchars($tenant['emergency_contact_name'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('contact_phone', 'Contact Phone'); ?></label>
                    <input type="tel" class="form-control" name="emergency_contact_phone" value="<?php echo htmlspecialchars($tenant['emergency_contact_phone'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('relationship', 'Relationship'); ?></label>
                    <input type="text" class="form-control" name="emergency_contact_relation" value="<?php echo htmlspecialchars($tenant['emergency_contact_relation'] ?? ''); ?>">
                </div>
            </div>

            <hr>

            <!-- Next of Kin -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-people me-1"></i> <?php _e('next_of_kin', 'Next of Kin'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('nok_name', 'Full Name'); ?></label>
                    <input type="text" class="form-control" name="next_of_kin_name" value="<?php echo htmlspecialchars($tenant['next_of_kin_name'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('nok_relationship', 'Relationship'); ?></label>
                    <input type="text" class="form-control" name="next_of_kin_relationship" value="<?php echo htmlspecialchars($tenant['next_of_kin_relationship'] ?? ''); ?>" placeholder="<?php _e('nok_relationship_placeholder', 'e.g. Spouse, Parent, Sibling'); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('nok_phone', 'Phone'); ?></label>
                    <input type="tel" class="form-control" name="next_of_kin_phone" value="<?php echo htmlspecialchars($tenant['next_of_kin_phone'] ?? ''); ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('nok_address', 'Address'); ?></label>
                    <input type="text" class="form-control" name="next_of_kin_address" value="<?php echo htmlspecialchars($tenant['next_of_kin_address'] ?? ''); ?>">
                </div>
            </div>

            <hr>

            <!-- References -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-person-check me-1"></i> <?php _e('references', 'References'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('reference1_name', 'Reference 1 Name'); ?></label>
                    <input type="text" class="form-control" name="reference1_name" value="<?php echo htmlspecialchars($tenant['reference1_name'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('reference1_phone', 'Reference 1 Phone'); ?></label>
                    <input type="tel" class="form-control" name="reference1_phone" value="<?php echo htmlspecialchars($tenant['reference1_phone'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('reference2_name', 'Reference 2 Name'); ?></label>
                    <input type="text" class="form-control" name="reference2_name" value="<?php echo htmlspecialchars($tenant['reference2_name'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('reference2_phone', 'Reference 2 Phone'); ?></label>
                    <input type="tel" class="form-control" name="reference2_phone" value="<?php echo htmlspecialchars($tenant['reference2_phone'] ?? ''); ?>">
                </div>
            </div>

            <hr>

            <!-- Screening Notes -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-clipboard-check me-1"></i> <?php _e('screening', 'Screening'); ?></h6>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('screening_notes', 'Screening Notes'); ?></label>
                    <textarea class="form-control" name="screening_notes" rows="3"><?php echo htmlspecialchars($tenant['screening_notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <hr>

            <!-- Tenant Portal Access (16.5) — only visible if Professional+ tier -->
            <?php
            $portalTierOk = false;
            try { $portalTierOk = hasTier('professional'); } catch (Exception $e) { $portalTierOk = true; }
            ?>
            <?php if ($portalTierOk && getUserData('role_name') === 'Admin'): ?>
            <h6 class="fw-semibold mb-3"><i class="bi bi-house-door me-1"></i> <?php _e('tp_portal_access', 'Tenant Portal Access'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="portal_enabled" name="portal_enabled" value="1"
                               <?php echo !empty($tenant['portal_enabled']) ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-semibold" for="portal_enabled">
                            <?php _e('tp_enable_portal', 'Enable Portal Access'); ?>
                        </label>
                    </div>
                    <small class="text-muted"><?php _e('tp_enable_desc', 'Allow this tenant to log in to the self-service portal.'); ?></small>
                </div>
                <div class="col-12 col-md-4" id="portalPasswordDiv" style="<?php echo !empty($tenant['portal_enabled']) ? '' : 'display:none;'; ?>">
                    <label class="form-label fw-semibold"><?php _e('tp_set_password', 'Set Portal Password'); ?></label>
                    <input type="password" class="form-control" name="portal_password" placeholder="<?php _e('tp_leave_blank', 'Leave blank to keep current'); ?>" minlength="6" autocomplete="new-password">
                    <small class="text-muted"><?php _e('tp_password_hint', 'Min 6 characters. Tenant uses email/phone to log in.'); ?></small>
                </div>
                <div class="col-12 col-md-4" id="portalStatusDiv" style="<?php echo !empty($tenant['portal_enabled']) ? '' : 'display:none;'; ?>">
                    <?php if (!empty($tenant['portal_password_hash'])): ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i><?php _e('tp_password_set', 'Password Set'); ?></span>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i><?php _e('tp_no_password_yet', 'No password set yet'); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($tenant['portal_last_login'])): ?>
                    <br><small class="text-muted mt-1"><?php _e('tp_last_login', 'Last Login'); ?>: <?php echo formatDateTime($tenant['portal_last_login']); ?></small>
                    <?php endif; ?>
                </div>
            </div>
            <script>
            document.getElementById('portal_enabled').addEventListener('change', function() {
                document.getElementById('portalPasswordDiv').style.display = this.checked ? '' : 'none';
                document.getElementById('portalStatusDiv').style.display = this.checked ? '' : 'none';
            });
            </script>

            <hr>
            <?php endif; ?>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/tenants/view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('cancel'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('save_changes', 'Save Changes'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
