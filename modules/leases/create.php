<?php
/**
 * FIS Real Estate — Create Lease (8.1 + 8.2)
 * Lease creation form with unit/tenant selectors, dates, rent, deposit, frequency, template selection.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('add_lease', 'New Lease');

$subStatus = enforceSubscription();

if (!hasPermission('leases', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Get available units (vacant/reserved) — scoped to accessible properties
$unitsStmt = $conn->query("
    SELECT u.id, u.unit_number, u.unit_label, u.rent_amount, u.currency, u.deposit_amount,
           u.status, p.name AS property_name, p.id AS property_id
    FROM units u
    INNER JOIN properties p ON u.property_id = p.id
    WHERE u.status IN ('vacant', 'reserved')" . propertyScopeSql('u.property_id') . "
    ORDER BY p.name, u.unit_number
");
$availableUnits = $unitsStmt->fetchAll();

// Get active tenants — scoped (tenant in scope only via a lease in an allowed property)
$tenantsStmt = $conn->query("
    SELECT id, first_name, last_name, phone, email
    FROM tenants
    WHERE status = 'active'" . tenantScopeSql('id') . "
    ORDER BY first_name, last_name
");
$activeTenants = $tenantsStmt->fetchAll();

// Get lease templates
$tplStmt = $conn->query("SELECT id, name, is_default FROM lease_templates ORDER BY is_default DESC, name");
$templates = $tplStmt->fetchAll();

// Handle form submission
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $old = [
        'unit_id' => (int)($_POST['unit_id'] ?? 0),
        'tenant_id' => (int)($_POST['tenant_id'] ?? 0),
        'start_date' => trim($_POST['start_date'] ?? ''),
        'end_date' => trim($_POST['end_date'] ?? ''),
        'rent_amount' => trim($_POST['rent_amount'] ?? ''),
        'currency' => trim($_POST['currency'] ?? DEFAULT_CURRENCY),
        'deposit_amount' => trim($_POST['deposit_amount'] ?? ''),
        'deposit_currency' => trim($_POST['deposit_currency'] ?? DEFAULT_CURRENCY),
        'payment_frequency' => trim($_POST['payment_frequency'] ?? 'monthly'),
        'payment_due_day' => (int)($_POST['payment_due_day'] ?? 1),
        'bill_from' => trim($_POST['bill_from'] ?? ''),
        'terms' => trim($_POST['terms'] ?? ''),
        'renewal_alert_days' => (int)($_POST['renewal_alert_days'] ?? 30),
    ];

    $validFrequencies = paymentFrequencies();

    // Validate
    if (!$old['unit_id']) $errors[] = __('unit_required', 'Unit is required');
    if (!$old['tenant_id']) $errors[] = __('tenant_required', 'Tenant is required');
    if ($old['start_date'] === '') $errors[] = __('start_date_required', 'Start date is required');
    if ($old['end_date'] === '') $errors[] = __('end_date_required', 'End date is required');
    if ((float)$old['rent_amount'] <= 0) $errors[] = __('rent_amount_required', 'Rent amount must be greater than 0');
    if (!in_array($old['payment_frequency'], $validFrequencies)) $errors[] = __('invalid_frequency', 'Invalid payment frequency');
    if ($old['payment_due_day'] < 1 || $old['payment_due_day'] > 28) $errors[] = __('invalid_due_day', 'Payment due day must be between 1 and 28');
    if ($old['start_date'] && $old['end_date'] && strtotime($old['end_date']) <= strtotime($old['start_date'])) {
        $errors[] = __('end_date_after_start', 'End date must be after start date');
    }
    $billedThrough = leaseBilledThroughFromInput($old['bill_from'], $old['start_date'], $old['end_date'], $errors);

    // Check unit availability
    if ($old['unit_id']) {
        $unitCheck = $conn->prepare("SELECT id, status FROM units WHERE id = :id");
        $unitCheck->execute(['id' => $old['unit_id']]);
        $unitData = $unitCheck->fetch();
        if (!$unitData) {
            $errors[] = __('unit_not_found', 'Unit not found');
        } elseif (!in_array($unitData['status'], ['vacant', 'reserved'])) {
            $errors[] = __('unit_not_available', 'Unit is not available');
        }
    }

    // Check tenant active
    if ($old['tenant_id']) {
        $tenantCheck = $conn->prepare("SELECT id, status FROM tenants WHERE id = :id");
        $tenantCheck->execute(['id' => $old['tenant_id']]);
        $tenantData = $tenantCheck->fetch();
        if (!$tenantData) {
            $errors[] = __('tenant_not_found', 'Tenant not found');
        } elseif ($tenantData['status'] !== 'active') {
            $errors[] = __('tenant_not_active', 'Tenant is not active');
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO leases (unit_id, tenant_id, start_date, end_date, rent_amount, currency,
                    deposit_amount, deposit_currency, payment_frequency, payment_due_day,
                    billed_through, terms, renewal_alert_days, status)
                VALUES (:unit_id, :tenant_id, :start_date, :end_date, :rent_amount, :currency,
                    :deposit_amount, :deposit_currency, :payment_frequency, :payment_due_day,
                    :billed_through, :terms, :renewal_alert_days, 'active')
            ");
            $stmt->execute([
                'unit_id' => $old['unit_id'],
                'tenant_id' => $old['tenant_id'],
                'start_date' => date('Y-m-d', strtotime($old['start_date'])),
                'end_date' => date('Y-m-d', strtotime($old['end_date'])),
                'rent_amount' => (float)$old['rent_amount'],
                'currency' => $old['currency'],
                'deposit_amount' => (float)$old['deposit_amount'],
                'deposit_currency' => $old['deposit_currency'],
                'payment_frequency' => $old['payment_frequency'],
                'payment_due_day' => $old['payment_due_day'],
                'billed_through' => $billedThrough,
                'terms' => $old['terms'] ?: null,
                'renewal_alert_days' => $old['renewal_alert_days'],
            ]);

            $leaseId = (int) $conn->lastInsertId();

            // Auto-set unit status to 'occupied'
            $conn->prepare("UPDATE units SET status = 'occupied' WHERE id = :id")
                ->execute(['id' => $old['unit_id']]);

            $conn->commit();

            logActivity('created', 'lease', $leaseId, [
                'unit_id' => $old['unit_id'],
                'tenant_id' => $old['tenant_id'],
                'rent_amount' => $old['rent_amount'],
                'unit_set_occupied' => true
            ]);

            setAlert(__('lease_created', 'Lease created successfully!'), 'success');
            redirect('modules/leases/view.php?id=' . $leaseId);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Build units JSON for JS auto-fill
$unitsJson = json_encode($availableUnits);

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-plus-circle me-2"></i><?php _e('add_lease', 'New Lease'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/leases/"><?php _e('leases'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('create'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('leases.create'); ?>

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
        <form method="POST" id="leaseForm">
            <?php echo csrfField(); ?>

            <!-- Unit & Tenant Selection -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-link-45deg me-1"></i> <?php _e('lease_parties', 'Unit & Tenant'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('unit', 'Unit'); ?> <span class="text-danger">*</span></label>
                    <select name="unit_id" id="unitSelect" class="form-select" required>
                        <option value=""><?php _e('select_unit', 'Select a unit...'); ?></option>
                        <?php foreach ($availableUnits as $u): ?>
                        <option value="<?php echo $u['id']; ?>"
                                data-rent="<?php echo $u['rent_amount']; ?>"
                                data-currency="<?php echo htmlspecialchars($u['currency']); ?>"
                                data-deposit="<?php echo $u['deposit_amount']; ?>"
                                <?php echo ($old['unit_id'] ?? 0) == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['property_name'] . ' — ' . $u['unit_number'] . ($u['unit_label'] ? ' (' . $u['unit_label'] . ')' : '') . ' [' . ucfirst($u['status']) . ']'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($availableUnits)): ?>
                    <small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i><?php _e('no_available_units', 'No vacant or reserved units available'); ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('tenant'); ?> <span class="text-danger">*</span></label>
                    <select name="tenant_id" id="tenantSelect" class="form-select" required>
                        <option value=""><?php _e('select_tenant', 'Select a tenant...'); ?></option>
                        <?php foreach ($activeTenants as $t): ?>
                        <option value="<?php echo $t['id']; ?>" <?php echo ($old['tenant_id'] ?? 0) == $t['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($t['first_name'] . ' ' . $t['last_name'] . ' — ' . $t['phone']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($activeTenants)): ?>
                    <small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i><?php _e('no_active_tenants', 'No active tenants. Create a tenant first.'); ?></small>
                    <?php endif; ?>
                </div>
            </div>

            <hr>

            <!-- Lease Period -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-calendar-range me-1"></i> <?php _e('lease_period', 'Lease Period'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('start_date', 'Start Date'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="start_date" id="startDate" value="<?php echo htmlspecialchars($old['start_date'] ?? ''); ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('end_date', 'End Date'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="end_date" id="endDate" value="<?php echo htmlspecialchars($old['end_date'] ?? ''); ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('payment_frequency', 'Payment Frequency'); ?> <span class="text-danger">*</span></label>
                    <select name="payment_frequency" class="form-select">
                        <option value="monthly" <?php echo ($old['payment_frequency'] ?? 'monthly') === 'monthly' ? 'selected' : ''; ?>><?php _e('monthly', 'Monthly'); ?></option>
                        <option value="quarterly" <?php echo ($old['payment_frequency'] ?? '') === 'quarterly' ? 'selected' : ''; ?>><?php _e('quarterly', 'Quarterly'); ?></option>
                        <option value="half_yearly" <?php echo ($old['payment_frequency'] ?? '') === 'half_yearly' ? 'selected' : ''; ?>><?php _e('half_yearly', 'Half Yearly'); ?></option>
                        <option value="yearly" <?php echo ($old['payment_frequency'] ?? '') === 'yearly' ? 'selected' : ''; ?>><?php _e('yearly', 'Yearly'); ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('payment_due_day', 'Due Day (1-28)'); ?> <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" name="payment_due_day" min="1" max="28" value="<?php echo $old['payment_due_day'] ?? 1; ?>" required>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('lease_bill_from', 'Bill automatically from'); ?></label>
                    <input type="date" class="form-control" name="bill_from" value="<?php echo htmlspecialchars($old['bill_from'] ?? ''); ?>">
                    <div class="form-text"><?php _e('lease_bill_from_help', 'Leave blank to bill rent from the start date. Set it when this lease started before you used this system: rent before this date will NOT be billed, so enter anything still unpaid as opening arrears.'); ?></div>
                </div>
            </div>

            <hr>

            <!-- Rent & Deposit -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-cash-stack me-1"></i> <?php _e('rent_deposit', 'Rent & Deposit'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('rent_amount', 'Rent Amount'); ?> <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" name="rent_amount" id="rentAmount" step="0.01" min="0" value="<?php echo htmlspecialchars($old['rent_amount'] ?? ''); ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('currency'); ?></label>
                    <select name="currency" id="currencySelect" class="form-select">
                        <option value="TZS" <?php echo ($old['currency'] ?? DEFAULT_CURRENCY) === 'TZS' ? 'selected' : ''; ?>>TZS</option>
                        <option value="USD" <?php echo ($old['currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD</option>
                        <option value="KES" <?php echo ($old['currency'] ?? '') === 'KES' ? 'selected' : ''; ?>>KES</option>
                        <option value="UGX" <?php echo ($old['currency'] ?? '') === 'UGX' ? 'selected' : ''; ?>>UGX</option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('deposit_amount', 'Deposit Amount'); ?></label>
                    <input type="number" class="form-control" name="deposit_amount" id="depositAmount" step="0.01" min="0" value="<?php echo htmlspecialchars($old['deposit_amount'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('deposit_currency', 'Deposit Currency'); ?></label>
                    <select name="deposit_currency" class="form-select">
                        <option value="TZS" <?php echo ($old['deposit_currency'] ?? DEFAULT_CURRENCY) === 'TZS' ? 'selected' : ''; ?>>TZS</option>
                        <option value="USD" <?php echo ($old['deposit_currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD</option>
                        <option value="KES" <?php echo ($old['deposit_currency'] ?? '') === 'KES' ? 'selected' : ''; ?>>KES</option>
                        <option value="UGX" <?php echo ($old['deposit_currency'] ?? '') === 'UGX' ? 'selected' : ''; ?>>UGX</option>
                    </select>
                </div>
            </div>

            <hr>

            <!-- Terms & Settings -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-gear me-1"></i> <?php _e('terms_settings', 'Terms & Settings'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('renewal_alert_days', 'Renewal Alert (days)'); ?></label>
                    <input type="number" class="form-control" name="renewal_alert_days" min="0" max="365" value="<?php echo $old['renewal_alert_days'] ?? 30; ?>">
                    <small class="text-muted"><?php _e('renewal_alert_help', 'Days before expiry to show alert'); ?></small>
                </div>
                <div class="col-12 col-md-9">
                    <label class="form-label fw-semibold"><?php _e('additional_terms', 'Additional Terms'); ?></label>
                    <textarea class="form-control" name="terms" rows="3" placeholder="<?php _e('terms_placeholder', 'Any additional lease terms or conditions...'); ?>"><?php echo htmlspecialchars($old['terms'] ?? ''); ?></textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/leases/" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('create_lease', 'Create Lease'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Initialize Select2 on unit and tenant dropdowns
$(document).ready(function() {
    $('#unitSelect').select2({ width: '100%', placeholder: '<?php echo __("select_unit", "Select a unit..."); ?>' });
    $('#tenantSelect').select2({ width: '100%', placeholder: '<?php echo __("select_tenant", "Select a tenant..."); ?>' });

    // Auto-fill rent/deposit when unit is selected
    $('#unitSelect').on('change', function() {
        const opt = $(this).find(':selected');
        const rent = opt.data('rent');
        const currency = opt.data('currency');
        const deposit = opt.data('deposit');

        if (rent !== undefined && rent > 0) {
            $('#rentAmount').val(rent);
        }
        if (currency) {
            $('#currencySelect').val(currency);
        }
        if (deposit !== undefined && deposit > 0) {
            $('#depositAmount').val(deposit);
        }
    });

    // Auto-calculate end date when start date and frequency change
    function calcEndDate() {
        const start = document.getElementById('startDate').value;
        const freq = document.querySelector('[name="payment_frequency"]').value;
        const endField = document.getElementById('endDate');

        if (start && !endField.value) {
            const d = new Date(start);
            if (freq === 'monthly') d.setFullYear(d.getFullYear() + 1);
            else if (freq === 'quarterly') d.setFullYear(d.getFullYear() + 1);
            else if (freq === 'yearly') d.setFullYear(d.getFullYear() + 1);
            // Subtract one day to get the last day of the period
            d.setDate(d.getDate() - 1);
            endField.value = d.toISOString().split('T')[0];
        }
    }

    document.getElementById('startDate').addEventListener('change', calcEndDate);
    document.querySelector('[name="payment_frequency"]').addEventListener('change', calcEndDate);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
