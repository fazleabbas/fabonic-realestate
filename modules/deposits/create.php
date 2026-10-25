<?php
/**
 * FIS Real Estate — Record Security Deposit (10.1)
 * Deposit collection form with lease selector, auto-fill deposit amount.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/charges.php';
require_once __DIR__ . '/../../includes/receipt_account_picker.php';
$pageTitle = __('record_deposit', 'Record Deposit');

$subStatus = enforceSubscription();

if (!hasPermission('deposits', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Get active leases without deposits for dropdown
$leasesStmt = $conn->query("
    SELECT l.id, l.rent_amount, l.deposit_amount, l.currency, l.deposit_currency,
           l.start_date, l.end_date,
           u.unit_number, u.id AS unit_id, p.name AS property_name, p.id AS property_id,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
           t.id AS tenant_id
    FROM leases l
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON l.tenant_id = t.id
    LEFT JOIN deposits d ON d.lease_id = l.id
    WHERE l.status = 'active' AND d.id IS NULL" . propertyScopeSql('u.property_id') . "
    ORDER BY t.first_name, t.last_name, u.unit_number
");
$activeLeases = $leasesStmt->fetchAll();

// Handle form submission
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $old = [
        'lease_id' => (int)($_POST['lease_id'] ?? 0),
        'amount' => trim($_POST['amount'] ?? ''),
        'currency' => trim($_POST['currency'] ?? DEFAULT_CURRENCY),
        'paid_date' => trim($_POST['paid_date'] ?? ''),
        'payment_method' => trim($_POST['payment_method'] ?? 'cash'),
        'reference_number' => trim($_POST['reference_number'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
        // Blank means the pre-part-38 behaviour: decide from the method.
        'ledger_account_id' => (int)($_POST['ledger_account_id'] ?? 0),
    ];

    $validMethods = ['cash', 'bank_transfer', 'mobile_money', 'cheque'];

    // Validate
    if (!$old['lease_id']) $errors[] = __('lease_required', 'Lease is required');
    if ((float)$old['amount'] <= 0) $errors[] = __('amount_required', 'Amount must be greater than 0');
    if ($old['paid_date'] === '') $errors[] = __('paid_date_required', 'Paid date is required');
    if (!in_array($old['payment_method'], $validMethods)) $errors[] = __('invalid_method', 'Invalid payment method');

    // Validate lease
    $tenantId = 0;
    $unitId = 0;
    if ($old['lease_id']) {
        $leaseCheck = $conn->prepare("SELECT l.*, u.property_id FROM leases l INNER JOIN units u ON l.unit_id = u.id WHERE l.id = :id");
        $leaseCheck->execute(['id' => $old['lease_id']]);
        $leaseData = $leaseCheck->fetch();
        if (!$leaseData) {
            $errors[] = __('lease_not_found', 'Lease not found');
        } else {
            $tenantId = (int)$leaseData['tenant_id'];
            $unitId = (int)$leaseData['unit_id'];
            if (!canAccessUnit($unitId)) {
                $errors[] = __('no_access_record', "You don't have access to this record");
            }
            if (empty($old['currency'])) $old['currency'] = $leaseData['deposit_currency'] ?: $leaseData['currency'];

            // Check if deposit already exists
            $existCheck = $conn->prepare("SELECT id FROM deposits WHERE lease_id = :lid");
            $existCheck->execute(['lid' => $old['lease_id']]);
            if ($existCheck->fetch()) {
                $errors[] = __('deposit_already_exists', 'A deposit already exists for this lease');
            }
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO deposits (lease_id, tenant_id, unit_id, amount, currency, paid_date,
                    payment_method, reference_number, notes, ledger_account_id, status)
                VALUES (:lease_id, :tenant_id, :unit_id, :amount, :currency, :paid_date,
                    :payment_method, :reference_number, :notes, :ledger_account_id, 'held')
            ");
            $stmt->execute([
                'lease_id' => $old['lease_id'],
                'tenant_id' => $tenantId,
                'unit_id' => $unitId,
                'amount' => (float)$old['amount'],
                'currency' => $old['currency'],
                'paid_date' => date('Y-m-d', strtotime($old['paid_date'])),
                'payment_method' => $old['payment_method'],
                'reference_number' => $old['reference_number'] ?: null,
                'notes' => $old['notes'] ?: null,
                'ledger_account_id' => $old['ledger_account_id'] ?: null,
            ]);

            $depositId = (int) $conn->lastInsertId();
            // Ledger (part 31): Dr Float/Bank  Cr Tenant Deposits Held.
            postDeposit($depositId);


            logActivity('created', 'deposit', $depositId, [
                'amount' => $old['amount'],
                'currency' => $old['currency'],
                'lease_id' => $old['lease_id'],
            ]);

            setAlert(__('deposit_recorded', 'Deposit recorded successfully!'), 'success');
            redirect('modules/deposits/view.php?id=' . $depositId);
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Build leases JSON for JS auto-fill
$leasesJson = json_encode($activeLeases);

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-plus-circle me-2"></i><?php _e('record_deposit', 'Record Deposit'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/deposits/"><?php _e('deposits'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('record', 'Record'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('deposits.create'); ?>

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
        <form method="POST" id="depositForm">
            <?php echo csrfField(); ?>

            <!-- Lease Selection -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-link-45deg me-1"></i> <?php _e('select_lease', 'Select Lease'); ?></h6>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('lease', 'Lease'); ?> <span class="text-danger">*</span></label>
                    <select name="lease_id" id="leaseSelect" class="form-select" required>
                        <option value=""><?php _e('select_lease_deposit', 'Select a lease (only leases without deposits shown)...'); ?></option>
                        <?php foreach ($activeLeases as $l): ?>
                        <option value="<?php echo $l['id']; ?>"
                                data-deposit="<?php echo $l['deposit_amount']; ?>"
                                data-currency="<?php echo htmlspecialchars($l['deposit_currency'] ?: $l['currency']); ?>"
                                data-rent="<?php echo $l['rent_amount']; ?>"
                                data-tenant="<?php echo htmlspecialchars($l['tenant_name']); ?>"
                                data-unit="<?php echo htmlspecialchars($l['unit_number']); ?>"
                                data-property="<?php echo htmlspecialchars($l['property_name']); ?>"
                                <?php echo ($old['lease_id'] ?? 0) == $l['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($l['tenant_name'] . ' — ' . $l['unit_number'] . ' (' . $l['property_name'] . ') — Deposit: ' . formatCurrency($l['deposit_amount'], $l['deposit_currency'] ?: $l['currency'])); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($activeLeases)): ?>
                    <small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i><?php _e('no_leases_for_deposit', 'No active leases without deposits. All leases already have deposits recorded.'); ?></small>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Lease Info Display -->
            <div id="leaseInfoBar" class="alert alert-info mt-3 d-none">
                <div class="d-flex flex-wrap gap-3">
                    <span><i class="bi bi-person me-1"></i><strong id="infoTenant"></strong></span>
                    <span><i class="bi bi-door-open me-1"></i><span id="infoUnit"></span></span>
                    <span><i class="bi bi-buildings me-1"></i><span id="infoProperty"></span></span>
                    <span><i class="bi bi-cash-stack me-1"></i><?php _e('expected_deposit', 'Expected'); ?>: <strong id="infoDeposit"></strong></span>
                </div>
            </div>

            <hr>

            <!-- Deposit Details -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-safe me-1"></i> <?php _e('deposit_details', 'Deposit Details'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('amount'); ?> <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" name="amount" id="depositAmount" step="0.01" min="0" value="<?php echo htmlspecialchars($old['amount'] ?? ''); ?>" required>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('currency'); ?></label>
                    <select name="currency" id="currencySelect" class="form-select">
                        <option value="TZS" <?php echo ($old['currency'] ?? DEFAULT_CURRENCY) === 'TZS' ? 'selected' : ''; ?>>TZS</option>
                        <option value="USD" <?php echo ($old['currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD</option>
                        <option value="KES" <?php echo ($old['currency'] ?? '') === 'KES' ? 'selected' : ''; ?>>KES</option>
                        <option value="UGX" <?php echo ($old['currency'] ?? '') === 'UGX' ? 'selected' : ''; ?>>UGX</option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('paid_date', 'Date Paid'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="paid_date" value="<?php echo htmlspecialchars($old['paid_date'] ?? date('Y-m-d')); ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('payment_method'); ?> <span class="text-danger">*</span></label>
                    <select name="payment_method" id="paymentMethod" class="form-select" required>
                        <option value="cash" <?php echo ($old['payment_method'] ?? 'cash') === 'cash' ? 'selected' : ''; ?>><?php _e('cash', 'Cash'); ?></option>
                        <option value="bank_transfer" <?php echo ($old['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : ''; ?>><?php _e('bank_transfer', 'Bank Transfer'); ?></option>
                        <option value="mobile_money" <?php echo ($old['payment_method'] ?? '') === 'mobile_money' ? 'selected' : ''; ?>><?php _e('mobile_money', 'Mobile Money'); ?></option>
                        <option value="cheque" <?php echo ($old['payment_method'] ?? '') === 'cheque' ? 'selected' : ''; ?>><?php _e('cheque', 'Cheque'); ?></option>
                    </select>
                </div>
                <?php receiptAccountPicker($old['ledger_account_id'] ?? null); ?>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('reference_number', 'Reference Number'); ?></label>
                    <input type="text" class="form-control" name="reference_number" placeholder="<?php _e('reference_placeholder', 'Transaction reference...'); ?>" value="<?php echo htmlspecialchars($old['reference_number'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-8">
                    <label class="form-label fw-semibold"><?php _e('notes', 'Notes'); ?></label>
                    <textarea class="form-control" name="notes" rows="2" placeholder="<?php _e('deposit_notes_placeholder', 'Optional notes about this deposit...'); ?>"><?php echo htmlspecialchars($old['notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/deposits/" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('record_deposit', 'Record Deposit'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#leaseSelect').select2({ width: '100%', placeholder: '<?php echo __("select_lease_deposit", "Select a lease..."); ?>' });

    // Auto-fill when lease is selected
    $('#leaseSelect').on('change', function() {
        const opt = $(this).find(':selected');
        const leaseId = $(this).val();

        if (!leaseId) {
            $('#leaseInfoBar').addClass('d-none');
            return;
        }

        // Show info bar
        $('#infoTenant').text(opt.data('tenant'));
        $('#infoUnit').text(opt.data('unit'));
        $('#infoProperty').text(opt.data('property'));
        $('#infoDeposit').text(opt.data('currency') + ' ' + Number(opt.data('deposit')).toLocaleString());
        $('#leaseInfoBar').removeClass('d-none');

        // Auto-fill amount & currency
        if (!$('#depositAmount').val()) {
            $('#depositAmount').val(opt.data('deposit'));
        }
        $('#currencySelect').val(opt.data('currency'));
    });

    // Trigger if lease was pre-selected (on error reload)
    if ($('#leaseSelect').val()) {
        $('#leaseSelect').trigger('change');
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
