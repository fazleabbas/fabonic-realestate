<?php
/**
 * FIS Real Estate — Create Tenant (7.1 + 7.2 + 7.4)
 * Tenant profile creation with screening fields, references, and emergency contact.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('add_tenant', 'Add Tenant');

$subStatus = enforceSubscription();

if (!hasPermission('tenants', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Handle form submission
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $old = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'id_type' => trim($_POST['id_type'] ?? ''),
        'id_number' => trim($_POST['id_number'] ?? ''),
        'occupation' => trim($_POST['occupation'] ?? ''),
        'employer' => trim($_POST['employer'] ?? ''),
        'employer_phone' => trim($_POST['employer_phone'] ?? ''),
        'emergency_contact_name' => trim($_POST['emergency_contact_name'] ?? ''),
        'emergency_contact_phone' => trim($_POST['emergency_contact_phone'] ?? ''),
        'emergency_contact_relation' => trim($_POST['emergency_contact_relation'] ?? ''),
        'tin' => trim($_POST['tin'] ?? ''),
        'next_of_kin_name' => trim($_POST['next_of_kin_name'] ?? ''),
        'next_of_kin_relationship' => trim($_POST['next_of_kin_relationship'] ?? ''),
        'next_of_kin_phone' => trim($_POST['next_of_kin_phone'] ?? ''),
        'next_of_kin_address' => trim($_POST['next_of_kin_address'] ?? ''),
        'reference1_name' => trim($_POST['reference1_name'] ?? ''),
        'reference1_phone' => trim($_POST['reference1_phone'] ?? ''),
        'reference2_name' => trim($_POST['reference2_name'] ?? ''),
        'reference2_phone' => trim($_POST['reference2_phone'] ?? ''),
        'screening_notes' => trim($_POST['screening_notes'] ?? ''),
        'status' => trim($_POST['status'] ?? 'active'),
        'move_in_date' => trim($_POST['move_in_date'] ?? ''),
    ];

    $validStatuses = ['active', 'notice_given', 'moved_out'];
    $validIdTypes = ['national_id', 'passport', 'driving_license', 'voter_id', ''];

    // Validate
    if ($old['first_name'] === '') $errors[] = __('first_name_required', 'First name is required');
    if ($old['last_name'] === '') $errors[] = __('last_name_required', 'Last name is required');
    if ($old['phone'] === '') $errors[] = __('phone_required', 'Phone number is required');
    if (!in_array($old['status'], $validStatuses)) $errors[] = __('invalid_status', 'Invalid status');
    if (!in_array($old['id_type'], $validIdTypes)) $errors[] = __('invalid_id_type', 'Invalid ID type');
    if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = __('invalid_email', 'Invalid email address');

    // TIN is soft-validated: reformat when it is a 9-digit number, otherwise keep
    // what was typed and warn afterwards so the save is never blocked.
    $tinCheck = normalizeTin($old['tin']);
    $old['tin'] = $tinCheck['value'] ?? '';
    $tinWarning = !$tinCheck['valid'];

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO tenants (first_name, last_name, phone, email, id_type, id_number,
                    occupation, employer, employer_phone,
                    emergency_contact_name, emergency_contact_phone, emergency_contact_relation,
                    tin, next_of_kin_name, next_of_kin_relationship, next_of_kin_phone, next_of_kin_address,
                    reference1_name, reference1_phone, reference2_name, reference2_phone,
                    screening_notes, status, move_in_date)
                VALUES (:first_name, :last_name, :phone, :email, :id_type, :id_number,
                    :occupation, :employer, :employer_phone,
                    :emergency_contact_name, :emergency_contact_phone, :emergency_contact_relation,
                    :tin, :next_of_kin_name, :next_of_kin_relationship, :next_of_kin_phone, :next_of_kin_address,
                    :reference1_name, :reference1_phone, :reference2_name, :reference2_phone,
                    :screening_notes, :status, :move_in_date)
            ");
            $stmt->execute([
                'first_name' => $old['first_name'],
                'last_name' => $old['last_name'],
                'phone' => $old['phone'],
                'email' => $old['email'] ?: null,
                'id_type' => $old['id_type'] ?: null,
                'id_number' => $old['id_number'] ?: null,
                'occupation' => $old['occupation'] ?: null,
                'employer' => $old['employer'] ?: null,
                'employer_phone' => $old['employer_phone'] ?: null,
                'emergency_contact_name' => $old['emergency_contact_name'] ?: null,
                'emergency_contact_phone' => $old['emergency_contact_phone'] ?: null,
                'emergency_contact_relation' => $old['emergency_contact_relation'] ?: null,
                'tin' => $old['tin'] ?: null,
                'next_of_kin_name' => $old['next_of_kin_name'] ?: null,
                'next_of_kin_relationship' => $old['next_of_kin_relationship'] ?: null,
                'next_of_kin_phone' => $old['next_of_kin_phone'] ?: null,
                'next_of_kin_address' => $old['next_of_kin_address'] ?: null,
                'reference1_name' => $old['reference1_name'] ?: null,
                'reference1_phone' => $old['reference1_phone'] ?: null,
                'reference2_name' => $old['reference2_name'] ?: null,
                'reference2_phone' => $old['reference2_phone'] ?: null,
                'screening_notes' => $old['screening_notes'] ?: null,
                'status' => $old['status'],
                'move_in_date' => $old['move_in_date'] ? date('Y-m-d', strtotime($old['move_in_date'])) : null,
            ]);

            $tenantId = (int) $conn->lastInsertId();

            logActivity('created', 'tenant', $tenantId, ['name' => $old['first_name'] . ' ' . $old['last_name'], 'phone' => $old['phone']]);

            $successMsg = __('tenant_created', 'Tenant created successfully!');
            if ($tinWarning) {
                $successMsg .= ' ' . __('tin_format_warning', 'Note: the TIN does not look like a 9-digit number and was saved as typed.');
            }
            setAlert($successMsg, $tinWarning ? 'warning' : 'success');
            redirect('modules/tenants/view.php?id=' . $tenantId);
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
        <h4><i class="bi bi-plus-circle me-2"></i><?php _e('add_tenant', 'Add Tenant'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/tenants/"><?php _e('tenants'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('create'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('tenants.create'); ?>

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

<!-- Create Form -->
<div class="card">
    <div class="card-body">
        <form method="POST" id="tenantForm">
            <?php echo csrfField(); ?>

            <!-- Personal Information -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-person me-1"></i> <?php _e('personal_info', 'Personal Information'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('first_name', 'First Name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($old['first_name'] ?? ''); ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('last_name', 'Last Name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($old['last_name'] ?? ''); ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('phone'); ?> <span class="text-danger">*</span></label>
                    <input type="tel" class="form-control" name="phone" value="<?php echo htmlspecialchars($old['phone'] ?? ''); ?>" required placeholder="e.g. 0712345678">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('email'); ?></label>
                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($old['email'] ?? ''); ?>" placeholder="tenant@example.com">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('status'); ?></label>
                    <select name="status" class="form-select">
                        <option value="active" <?php echo ($old['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>><?php _e('active', 'Active'); ?></option>
                        <option value="notice_given" <?php echo ($old['status'] ?? '') === 'notice_given' ? 'selected' : ''; ?>><?php _e('notice_given', 'Notice Given'); ?></option>
                        <option value="moved_out" <?php echo ($old['status'] ?? '') === 'moved_out' ? 'selected' : ''; ?>><?php _e('moved_out', 'Moved Out'); ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('move_in_date', 'Move-in Date'); ?></label>
                    <input type="date" class="form-control" name="move_in_date" value="<?php echo htmlspecialchars($old['move_in_date'] ?? ''); ?>">
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
                        <option value="national_id" <?php echo ($old['id_type'] ?? '') === 'national_id' ? 'selected' : ''; ?>><?php _e('national_id', 'National ID'); ?></option>
                        <option value="passport" <?php echo ($old['id_type'] ?? '') === 'passport' ? 'selected' : ''; ?>><?php _e('passport', 'Passport'); ?></option>
                        <option value="driving_license" <?php echo ($old['id_type'] ?? '') === 'driving_license' ? 'selected' : ''; ?>><?php _e('driving_license', 'Driving License'); ?></option>
                        <option value="voter_id" <?php echo ($old['id_type'] ?? '') === 'voter_id' ? 'selected' : ''; ?>><?php _e('voter_id', 'Voter ID'); ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('id_number', 'ID Number'); ?></label>
                    <input type="text" class="form-control" name="id_number" value="<?php echo htmlspecialchars($old['id_number'] ?? ''); ?>" placeholder="<?php _e('id_number_placeholder', 'Enter ID number'); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('tin', 'TIN'); ?></label>
                    <input type="text" class="form-control" name="tin" value="<?php echo htmlspecialchars($old['tin'] ?? ''); ?>" placeholder="<?php _e('tin_placeholder', 'e.g. 123-456-789'); ?>" inputmode="numeric">
                    <small class="text-muted"><?php _e('tin_help', '9 digits. Saved as typed if it does not match.'); ?></small>
                </div>
                <div class="col-12">
                    <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i><?php _e('id_copy_upload_note', 'ID copy can be uploaded after creating the tenant.'); ?></p>
                </div>
            </div>

            <hr>

            <!-- Employment -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-briefcase me-1"></i> <?php _e('employment', 'Employment'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('occupation', 'Occupation'); ?></label>
                    <input type="text" class="form-control" name="occupation" value="<?php echo htmlspecialchars($old['occupation'] ?? ''); ?>" placeholder="e.g. Teacher, Engineer">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('employer', 'Employer'); ?></label>
                    <input type="text" class="form-control" name="employer" value="<?php echo htmlspecialchars($old['employer'] ?? ''); ?>" placeholder="<?php _e('employer_placeholder', 'Company / organization name'); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('employer_phone', 'Employer Phone'); ?></label>
                    <input type="tel" class="form-control" name="employer_phone" value="<?php echo htmlspecialchars($old['employer_phone'] ?? ''); ?>">
                </div>
            </div>

            <hr>

            <!-- Emergency Contact -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-exclamation-triangle me-1"></i> <?php _e('emergency_contact', 'Emergency Contact'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('contact_name', 'Contact Name'); ?></label>
                    <input type="text" class="form-control" name="emergency_contact_name" value="<?php echo htmlspecialchars($old['emergency_contact_name'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('contact_phone', 'Contact Phone'); ?></label>
                    <input type="tel" class="form-control" name="emergency_contact_phone" value="<?php echo htmlspecialchars($old['emergency_contact_phone'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('relationship', 'Relationship'); ?></label>
                    <input type="text" class="form-control" name="emergency_contact_relation" value="<?php echo htmlspecialchars($old['emergency_contact_relation'] ?? ''); ?>" placeholder="e.g. Spouse, Parent, Sibling">
                </div>
            </div>

            <hr>

            <!-- Next of Kin -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-people me-1"></i> <?php _e('next_of_kin', 'Next of Kin'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('nok_name', 'Full Name'); ?></label>
                    <input type="text" class="form-control" name="next_of_kin_name" value="<?php echo htmlspecialchars($old['next_of_kin_name'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('nok_relationship', 'Relationship'); ?></label>
                    <input type="text" class="form-control" name="next_of_kin_relationship" value="<?php echo htmlspecialchars($old['next_of_kin_relationship'] ?? ''); ?>" placeholder="<?php _e('nok_relationship_placeholder', 'e.g. Spouse, Parent, Sibling'); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('nok_phone', 'Phone'); ?></label>
                    <input type="tel" class="form-control" name="next_of_kin_phone" value="<?php echo htmlspecialchars($old['next_of_kin_phone'] ?? ''); ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('nok_address', 'Address'); ?></label>
                    <input type="text" class="form-control" name="next_of_kin_address" value="<?php echo htmlspecialchars($old['next_of_kin_address'] ?? ''); ?>">
                </div>
            </div>

            <hr>

            <!-- References -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-person-check me-1"></i> <?php _e('references', 'References'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('reference1_name', 'Reference 1 Name'); ?></label>
                    <input type="text" class="form-control" name="reference1_name" value="<?php echo htmlspecialchars($old['reference1_name'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('reference1_phone', 'Reference 1 Phone'); ?></label>
                    <input type="tel" class="form-control" name="reference1_phone" value="<?php echo htmlspecialchars($old['reference1_phone'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('reference2_name', 'Reference 2 Name'); ?></label>
                    <input type="text" class="form-control" name="reference2_name" value="<?php echo htmlspecialchars($old['reference2_name'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('reference2_phone', 'Reference 2 Phone'); ?></label>
                    <input type="tel" class="form-control" name="reference2_phone" value="<?php echo htmlspecialchars($old['reference2_phone'] ?? ''); ?>">
                </div>
            </div>

            <hr>

            <!-- Screening Notes -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-clipboard-check me-1"></i> <?php _e('screening', 'Screening'); ?></h6>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('screening_notes', 'Screening Notes'); ?></label>
                    <textarea class="form-control" name="screening_notes" rows="3" placeholder="<?php _e('screening_notes_placeholder', 'Background notes, verification details, interview observations...'); ?>"><?php echo htmlspecialchars($old['screening_notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/tenants/" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('save_tenant', 'Save Tenant'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
