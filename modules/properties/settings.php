<?php
/**
 * FIS Real Estate — Property Settings (5.4)
 * Per-property: default rent cycle, late fee rules, deposit rules, payment instructions.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

$subStatus = enforceSubscription();

if (!hasPermission('properties', 'edit')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Property not found', 'danger');
    redirect('modules/properties/');
}

$stmt = $conn->prepare("SELECT * FROM properties WHERE id = :id");
$stmt->execute(['id' => $id]);
$property = $stmt->fetch();

if (!$property) {
    setAlert('Property not found', 'danger');
    redirect('modules/properties/');
}

if (!canAccessProperty($id)) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/properties/');
}

$pageTitle = __('settings') . ' - ' . $property['name'];

// Get existing settings
$settingsStmt = $conn->prepare("SELECT * FROM property_settings WHERE property_id = :id");
$settingsStmt->execute(['id' => $id]);
$settings = $settingsStmt->fetch();

// Defaults if no settings row exists
if (!$settings) {
    $settings = [
        'property_id' => $id,
        'rent_cycle' => 'monthly',
        'late_fee_type' => 'none',
        'late_fee_value' => 0,
        'grace_period_days' => 5,
        'max_late_fee' => 0,
        'deposit_months' => 1,
        'payment_instructions' => ''
    ];
}

$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $settings['rent_cycle'] = trim($_POST['rent_cycle'] ?? 'monthly');
    $settings['late_fee_type'] = trim($_POST['late_fee_type'] ?? 'none');
    $settings['late_fee_value'] = max(0, (float)($_POST['late_fee_value'] ?? 0));
    $settings['grace_period_days'] = max(0, (int)($_POST['grace_period_days'] ?? 5));
    $settings['max_late_fee'] = max(0, (float)($_POST['max_late_fee'] ?? 0));
    $settings['deposit_months'] = max(0, (float)($_POST['deposit_months'] ?? 1));
    $settings['payment_instructions'] = trim($_POST['payment_instructions'] ?? '');

    // Validate
    if (!in_array($settings['rent_cycle'], ['monthly', 'quarterly', 'yearly'])) {
        $errors[] = 'Invalid rent cycle';
    }
    if (!in_array($settings['late_fee_type'], ['percentage', 'fixed', 'none'])) {
        $errors[] = 'Invalid late fee type';
    }

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO property_settings (property_id, rent_cycle, late_fee_type, late_fee_value, grace_period_days, max_late_fee, deposit_months, payment_instructions)
                VALUES (:pid, :rc, :lft, :lfv, :gpd, :mlf, :dm, :pi)
                ON DUPLICATE KEY UPDATE
                    rent_cycle = VALUES(rent_cycle),
                    late_fee_type = VALUES(late_fee_type),
                    late_fee_value = VALUES(late_fee_value),
                    grace_period_days = VALUES(grace_period_days),
                    max_late_fee = VALUES(max_late_fee),
                    deposit_months = VALUES(deposit_months),
                    payment_instructions = VALUES(payment_instructions)
            ");
            $stmt->execute([
                'pid' => $id,
                'rc' => $settings['rent_cycle'],
                'lft' => $settings['late_fee_type'],
                'lfv' => $settings['late_fee_value'],
                'gpd' => $settings['grace_period_days'],
                'mlf' => $settings['max_late_fee'],
                'dm' => $settings['deposit_months'],
                'pi' => $settings['payment_instructions']
            ]);

            logActivity('updated', 'property_settings', $id);
            $success = true;
            setAlert(__('settings_saved', 'Settings saved successfully!'), 'success');
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
        <h4><i class="bi bi-gear me-2"></i><?php _e('property_settings', 'Property Settings'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/properties/"><?php _e('properties'); ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $id; ?>"><?php echo htmlspecialchars($property['name']); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('settings'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('properties.settings'); ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?php echo htmlspecialchars($err); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST">
    <?php echo csrfField(); ?>

    <div class="row g-3">
        <!-- Rent Cycle -->
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-calendar-month me-1"></i> <?php _e('rent_cycle_settings', 'Rent Cycle'); ?>
                </div>
                <div class="card-body">
                    <label class="form-label fw-semibold"><?php _e('default_rent_cycle', 'Default Rent Cycle'); ?></label>
                    <select name="rent_cycle" class="form-select">
                        <option value="monthly" <?php echo $settings['rent_cycle'] === 'monthly' ? 'selected' : ''; ?>><?php _e('monthly', 'Monthly'); ?></option>
                        <option value="quarterly" <?php echo $settings['rent_cycle'] === 'quarterly' ? 'selected' : ''; ?>><?php _e('quarterly', 'Quarterly'); ?></option>
                        <option value="yearly" <?php echo $settings['rent_cycle'] === 'yearly' ? 'selected' : ''; ?>><?php _e('yearly', 'Yearly'); ?></option>
                    </select>
                    <small class="text-muted"><?php _e('rent_cycle_help', 'Default billing frequency for new leases in this property'); ?></small>
                </div>
            </div>
        </div>

        <!-- Deposit Rules -->
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-safe me-1"></i> <?php _e('deposit_rules', 'Deposit Rules'); ?>
                </div>
                <div class="card-body">
                    <label class="form-label fw-semibold"><?php _e('deposit_months', 'Deposit (Number of Months)'); ?></label>
                    <input type="number" class="form-control" name="deposit_months" value="<?php echo $settings['deposit_months']; ?>" min="0" max="12" step="0.5">
                    <small class="text-muted"><?php _e('deposit_help', 'Default security deposit as number of months rent'); ?></small>
                </div>
            </div>
        </div>

        <!-- Late Fee Rules -->
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-clock-history me-1"></i> <?php _e('late_fee_rules', 'Late Fee Rules'); ?>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold"><?php _e('late_fee_type', 'Late Fee Type'); ?></label>
                            <select name="late_fee_type" class="form-select" id="lateFeeType" onchange="toggleLateFeeFields()">
                                <option value="none" <?php echo $settings['late_fee_type'] === 'none' ? 'selected' : ''; ?>><?php _e('none', 'None'); ?></option>
                                <option value="percentage" <?php echo $settings['late_fee_type'] === 'percentage' ? 'selected' : ''; ?>><?php _e('percentage', 'Percentage'); ?></option>
                                <option value="fixed" <?php echo $settings['late_fee_type'] === 'fixed' ? 'selected' : ''; ?>><?php _e('fixed_amount', 'Fixed Amount'); ?></option>
                            </select>
                        </div>

                        <div class="col-12 col-md-3 late-fee-fields" style="<?php echo $settings['late_fee_type'] === 'none' ? 'display:none;' : ''; ?>">
                            <label class="form-label fw-semibold"><?php _e('late_fee_value', 'Late Fee Value'); ?></label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="late_fee_value" value="<?php echo $settings['late_fee_value']; ?>" min="0" step="0.01">
                                <span class="input-group-text" id="lateFeeUnit"><?php echo $settings['late_fee_type'] === 'percentage' ? '%' : APP_CURRENCY_SYMBOL; ?></span>
                            </div>
                        </div>

                        <div class="col-12 col-md-3 late-fee-fields" style="<?php echo $settings['late_fee_type'] === 'none' ? 'display:none;' : ''; ?>">
                            <label class="form-label fw-semibold"><?php _e('grace_period', 'Grace Period (Days)'); ?></label>
                            <input type="number" class="form-control" name="grace_period_days" value="<?php echo $settings['grace_period_days']; ?>" min="0" max="60">
                            <small class="text-muted"><?php _e('grace_help', 'Days after due date before late fee applies'); ?></small>
                        </div>

                        <div class="col-12 col-md-3 late-fee-fields" style="<?php echo $settings['late_fee_type'] === 'none' ? 'display:none;' : ''; ?>">
                            <label class="form-label fw-semibold"><?php _e('max_late_fee', 'Max Late Fee'); ?></label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="max_late_fee" value="<?php echo $settings['max_late_fee']; ?>" min="0" step="0.01">
                                <span class="input-group-text"><?php echo APP_CURRENCY_SYMBOL; ?></span>
                            </div>
                            <small class="text-muted"><?php _e('max_fee_help', '0 = unlimited'); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Instructions -->
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-credit-card me-1"></i> <?php _e('payment_instructions_label', 'Payment Instructions'); ?>
                </div>
                <div class="card-body">
                    <label class="form-label fw-semibold"><?php _e('payment_instructions_desc', 'Instructions shown to tenants for making payments'); ?></label>
                    <textarea class="form-control" name="payment_instructions" rows="4" placeholder="<?php _e('payment_instructions_placeholder', 'e.g. Bank: CRDB, Account: 01J1234567, Name: ABC Properties Ltd'); ?>"><?php echo htmlspecialchars($settings['payment_instructions']); ?></textarea>
                    <small class="text-muted"><?php _e('payment_instructions_help', 'Include bank details, mobile money numbers, or any other payment info specific to this property'); ?></small>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between mt-3">
        <a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i> <?php _e('save_settings', 'Save Settings'); ?>
        </button>
    </div>
</form>

<script>
function toggleLateFeeFields() {
    const type = document.getElementById('lateFeeType').value;
    const fields = document.querySelectorAll('.late-fee-fields');
    const unit = document.getElementById('lateFeeUnit');

    if (type === 'none') {
        fields.forEach(f => f.style.display = 'none');
    } else {
        fields.forEach(f => f.style.display = '');
        unit.textContent = type === 'percentage' ? '%' : '<?php echo APP_CURRENCY_SYMBOL; ?>';
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
