<?php
/**
 * FIS Real Estate — Edit Lease (8.5)
 * Edit form for active and expired leases. Unit/tenant cannot be changed.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

$subStatus = enforceSubscription();

if (!hasPermission('leases', 'edit')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Lease not found', 'danger');
    redirect('modules/leases/');
}

// Fetch lease with unit/property/tenant details
$stmt = $conn->prepare("
    SELECT l.*, u.unit_number, p.name AS property_name,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone
    FROM leases l
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON l.tenant_id = t.id
    WHERE l.id = :id
");
$stmt->execute(['id' => $id]);
$lease = $stmt->fetch();

if (!$lease) {
    setAlert('Lease not found', 'danger');
    redirect('modules/leases/');
}

// Property-based access scoping guard
if (!canAccessLease($id)) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/leases/');
}

if (!isLeaseEditable($lease)) {
    setAlert(__('only_active_expired_editable', 'Only active or expired leases can be edited.'), 'warning');
    redirect('modules/leases/view.php?id=' . $id);
}

$pageTitle = __('edit_lease', 'Edit Lease') . ' #' . $id;

// Handle form submission
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $lease['start_date'] = trim($_POST['start_date'] ?? '');
    $lease['end_date'] = trim($_POST['end_date'] ?? '');
    $lease['rent_amount'] = trim($_POST['rent_amount'] ?? '');
    $lease['currency'] = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
    $lease['deposit_amount'] = trim($_POST['deposit_amount'] ?? '');
    $lease['deposit_currency'] = trim($_POST['deposit_currency'] ?? DEFAULT_CURRENCY);
    $lease['payment_frequency'] = trim($_POST['payment_frequency'] ?? 'monthly');
    $lease['payment_due_day'] = (int)($_POST['payment_due_day'] ?? 1);
    $billFrom = trim($_POST['bill_from'] ?? '');
    $lease['terms'] = trim($_POST['terms'] ?? '');
    $lease['renewal_alert_days'] = (int)($_POST['renewal_alert_days'] ?? 30);

    $validFrequencies = paymentFrequencies();

    if ($lease['start_date'] === '') $errors[] = __('start_date_required', 'Start date is required');
    if ($lease['end_date'] === '') $errors[] = __('end_date_required', 'End date is required');
    if ((float)$lease['rent_amount'] <= 0) $errors[] = __('rent_amount_required', 'Rent amount must be greater than 0');
    if (!in_array($lease['payment_frequency'], $validFrequencies)) $errors[] = __('invalid_frequency', 'Invalid payment frequency');
    if ($lease['payment_due_day'] < 1 || $lease['payment_due_day'] > 28) $errors[] = __('invalid_due_day', 'Payment due day must be between 1 and 28');
    if ($lease['start_date'] && $lease['end_date'] && strtotime($lease['end_date']) <= strtotime($lease['start_date'])) {
        $errors[] = __('end_date_after_start', 'End date must be after start date');
    }
    $lease['billed_through'] = leaseBilledThroughFromInput($billFrom, $lease['start_date'], $lease['end_date'], $errors);

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                UPDATE leases SET
                    start_date = :start_date, end_date = :end_date,
                    rent_amount = :rent_amount, currency = :currency,
                    deposit_amount = :deposit_amount, deposit_currency = :deposit_currency,
                    payment_frequency = :payment_frequency, payment_due_day = :payment_due_day,
                    billed_through = :billed_through, terms = :terms, renewal_alert_days = :renewal_alert_days
                WHERE id = :id
            ");
            $stmt->execute([
                'start_date' => date('Y-m-d', strtotime($lease['start_date'])),
                'end_date' => date('Y-m-d', strtotime($lease['end_date'])),
                'rent_amount' => (float)$lease['rent_amount'],
                'currency' => $lease['currency'],
                'deposit_amount' => (float)$lease['deposit_amount'],
                'deposit_currency' => $lease['deposit_currency'],
                'payment_frequency' => $lease['payment_frequency'],
                'payment_due_day' => $lease['payment_due_day'],
                'billed_through' => $lease['billed_through'],
                'terms' => $lease['terms'] ?: null,
                'renewal_alert_days' => $lease['renewal_alert_days'],
                'id' => $id
            ]);

            logActivity('updated', 'lease', $id, ['rent_amount' => $lease['rent_amount']]);

            setAlert(__('lease_updated', 'Lease updated successfully!'), 'success');
            redirect('modules/leases/view.php?id=' . $id);
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
        <h4><i class="bi bi-pencil me-2"></i><?php _e('edit_lease', 'Edit Lease'); ?> #<?php echo $id; ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/leases/"><?php _e('leases'); ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/leases/view.php?id=<?php echo $id; ?>">#<?php echo $id; ?></a></li>
                <li class="breadcrumb-item active"><?php _e('edit'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('leases.edit'); ?>

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

<!-- Editing a lapsed lease (status kept as-is) -->
<?php if ($lease['status'] !== 'active'): ?>
<div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <div><?php _e('editing_expired_lease_note', 'You are editing an expired lease. Saving will not reactivate it — renew the lease to start a new term.'); ?></div>
</div>
<?php endif; ?>

<!-- Read-only: Unit & Tenant -->
<div class="alert alert-light border mb-3">
    <div class="d-flex flex-wrap gap-3">
        <span><strong><?php _e('unit', 'Unit'); ?>:</strong> <?php echo htmlspecialchars($lease['unit_number']); ?> — <?php echo htmlspecialchars($lease['property_name']); ?></span>
        <span><strong><?php _e('tenant'); ?>:</strong> <?php echo htmlspecialchars($lease['tenant_name']); ?> (<?php echo htmlspecialchars($lease['tenant_phone']); ?>)</span>
    </div>
    <small class="text-muted"><?php _e('unit_tenant_readonly', 'Unit and tenant cannot be changed. Create a new lease if needed.'); ?></small>
</div>

<!-- Edit Form -->
<div class="card">
    <div class="card-body">
        <form method="POST" id="leaseEditForm">
            <?php echo csrfField(); ?>

            <!-- Lease Period -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-calendar-range me-1"></i> <?php _e('lease_period', 'Lease Period'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('start_date', 'Start Date'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="start_date" value="<?php echo date('Y-m-d', strtotime($lease['start_date'])); ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('end_date', 'End Date'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="end_date" value="<?php echo date('Y-m-d', strtotime($lease['end_date'])); ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('payment_frequency', 'Payment Frequency'); ?></label>
                    <select name="payment_frequency" class="form-select">
                        <option value="monthly" <?php echo $lease['payment_frequency'] === 'monthly' ? 'selected' : ''; ?>><?php _e('monthly'); ?></option>
                        <option value="quarterly" <?php echo $lease['payment_frequency'] === 'quarterly' ? 'selected' : ''; ?>><?php _e('quarterly'); ?></option>
                        <option value="half_yearly" <?php echo $lease['payment_frequency'] === 'half_yearly' ? 'selected' : ''; ?>><?php _e('half_yearly', 'Half Yearly'); ?></option>
                        <option value="yearly" <?php echo $lease['payment_frequency'] === 'yearly' ? 'selected' : ''; ?>><?php _e('yearly'); ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('payment_due_day', 'Due Day (1-28)'); ?></label>
                    <input type="number" class="form-control" name="payment_due_day" min="1" max="28" value="<?php echo $lease['payment_due_day']; ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('lease_bill_from', 'Bill automatically from'); ?></label>
                    <input type="date" class="form-control" name="bill_from" value="<?php echo htmlspecialchars(leaseBillFromValue($lease['billed_through'] ?? null)); ?>">
                    <div class="form-text"><?php _e('lease_bill_from_edit_help', 'Rent before this date is not billed automatically. Moving it EARLIER makes the system bill the earlier periods on its next run, so only do that if they were never billed or entered as arrears.'); ?></div>
                </div>
            </div>

            <hr>

            <!-- Rent & Deposit -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-cash-stack me-1"></i> <?php _e('rent_deposit', 'Rent & Deposit'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('rent_amount'); ?> <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" name="rent_amount" step="0.01" min="0" value="<?php echo $lease['rent_amount']; ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('currency'); ?></label>
                    <select name="currency" class="form-select">
                        <option value="TZS" <?php echo $lease['currency'] === 'TZS' ? 'selected' : ''; ?>>TZS</option>
                        <option value="USD" <?php echo $lease['currency'] === 'USD' ? 'selected' : ''; ?>>USD</option>
                        <option value="KES" <?php echo $lease['currency'] === 'KES' ? 'selected' : ''; ?>>KES</option>
                        <option value="UGX" <?php echo $lease['currency'] === 'UGX' ? 'selected' : ''; ?>>UGX</option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('deposit_amount'); ?></label>
                    <input type="number" class="form-control" name="deposit_amount" step="0.01" min="0" value="<?php echo $lease['deposit_amount']; ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('deposit_currency', 'Deposit Currency'); ?></label>
                    <select name="deposit_currency" class="form-select">
                        <option value="TZS" <?php echo $lease['deposit_currency'] === 'TZS' ? 'selected' : ''; ?>>TZS</option>
                        <option value="USD" <?php echo $lease['deposit_currency'] === 'USD' ? 'selected' : ''; ?>>USD</option>
                        <option value="KES" <?php echo $lease['deposit_currency'] === 'KES' ? 'selected' : ''; ?>>KES</option>
                        <option value="UGX" <?php echo $lease['deposit_currency'] === 'UGX' ? 'selected' : ''; ?>>UGX</option>
                    </select>
                </div>
            </div>

            <hr>

            <!-- Terms & Settings -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-gear me-1"></i> <?php _e('terms_settings', 'Terms & Settings'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('renewal_alert_days', 'Renewal Alert (days)'); ?></label>
                    <input type="number" class="form-control" name="renewal_alert_days" min="0" max="365" value="<?php echo $lease['renewal_alert_days']; ?>">
                </div>
                <div class="col-12 col-md-9">
                    <label class="form-label fw-semibold"><?php _e('additional_terms', 'Additional Terms'); ?></label>
                    <textarea class="form-control" name="terms" rows="3"><?php echo htmlspecialchars($lease['terms'] ?? ''); ?></textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/leases/view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">
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
