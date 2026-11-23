<?php
/**
 * FIS Real Estate — Record Payment (9.1 + 9.2)
 * Payment recording form with lease selector, auto-fill, period calculation.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/charges.php';
require_once __DIR__ . '/../../includes/receipt_account_picker.php';
$pageTitle = __('record_payment', 'Record Payment');

$subStatus = enforceSubscription();

if (!hasPermission('payments', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Get active leases for dropdown
$leasesStmt = $conn->query("
    SELECT l.id, l.rent_amount, l.currency, l.payment_frequency, l.payment_due_day,
           l.start_date, l.end_date,
           u.unit_number, u.id AS unit_id, p.name AS property_name, p.id AS property_id,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
           t.id AS tenant_id
    FROM leases l
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON l.tenant_id = t.id
    WHERE l.status = 'active'" . propertyScopeSql('u.property_id') . "
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
        'payment_date' => trim($_POST['payment_date'] ?? ''),
        'payment_method' => trim($_POST['payment_method'] ?? 'cash'),
        'reference_number' => trim($_POST['reference_number'] ?? ''),
        'period_from' => trim($_POST['period_from'] ?? ''),
        'period_to' => trim($_POST['period_to'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
        'generate_receipt' => (int)($_POST['generate_receipt'] ?? 1),
        // Where the money went. Blank means "decide it for me", which is the
        // behaviour this form had before part 38.
        'ledger_account_id' => (int)($_POST['ledger_account_id'] ?? 0),
    ];

    $validMethods = ['cash', 'bank_transfer', 'mobile_money', 'cheque', 'gateway'];

    // Validate
    if (!$old['lease_id']) $errors[] = __('lease_required', 'Lease is required');
    if ((float)$old['amount'] <= 0) $errors[] = __('amount_required', 'Amount must be greater than 0');
    if ($old['payment_date'] === '') $errors[] = __('payment_date_required', 'Payment date is required');
    if (!in_array($old['payment_method'], $validMethods)) $errors[] = __('invalid_method', 'Invalid payment method');

    // Validate lease
    $tenantId = 0;
    $unitId = 0;
    if ($old['lease_id']) {
        $leaseCheck = $conn->prepare("SELECT l.*, u.property_id FROM leases l INNER JOIN units u ON l.unit_id = u.id WHERE l.id = :id AND l.status = 'active'");
        $leaseCheck->execute(['id' => $old['lease_id']]);
        $leaseData = $leaseCheck->fetch();
        if (!$leaseData) {
            $errors[] = __('lease_not_found', 'Lease not found or not active');
        } else {
            $tenantId = (int)$leaseData['tenant_id'];
            $unitId = (int)$leaseData['unit_id'];
            if (!canAccessUnit($unitId)) {
                $errors[] = __('no_access_record', "You don't have access to this record");
            }
            if (empty($old['currency'])) $old['currency'] = $leaseData['currency'];
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO payments (lease_id, tenant_id, unit_id, amount, currency, payment_date,
                    payment_method, reference_number, period_from, period_to, notes, recorded_by,
                    ledger_account_id, status)
                VALUES (:lease_id, :tenant_id, :unit_id, :amount, :currency, :payment_date,
                    :payment_method, :reference_number, :period_from, :period_to, :notes, :recorded_by,
                    :ledger_account_id, 'recorded')
            ");
            $stmt->execute([
                'lease_id' => $old['lease_id'],
                'tenant_id' => $tenantId,
                'unit_id' => $unitId,
                'amount' => (float)$old['amount'],
                'currency' => $old['currency'],
                'payment_date' => date('Y-m-d', strtotime($old['payment_date'])),
                'payment_method' => $old['payment_method'],
                'reference_number' => $old['reference_number'] ?: null,
                'period_from' => $old['period_from'] ? date('Y-m-d', strtotime($old['period_from'])) : null,
                'period_to' => $old['period_to'] ? date('Y-m-d', strtotime($old['period_to'])) : null,
                'notes' => $old['notes'] ?: null,
                'recorded_by' => getUserId(),
                'ledger_account_id' => $old['ledger_account_id'] ?: null,
            ]);

            $paymentId = (int) $conn->lastInsertId();
            // Ledger (part 31): Dr Float/Bank  Cr Receivable, then allocate
            // against outstanding charges. Never allowed to break the payment.
            postPayment($paymentId);


            // Auto-generate receipt
            $receiptNumber = null;
            if ($old['generate_receipt']) {
                $prefix = getSetting('receipt_prefix', 'RCT-');
                $nextNum = (int)getSetting('receipt_next_number', 1);
                $receiptNumber = $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
                updateSetting('receipt_next_number', $nextNum + 1);

                $conn->prepare("INSERT INTO receipts (payment_id, receipt_number) VALUES (:pid, :rnum)")
                    ->execute(['pid' => $paymentId, 'rnum' => $receiptNumber]);
            }

            $conn->commit();

            logActivity('created', 'payment', $paymentId, [
                'amount' => $old['amount'],
                'currency' => $old['currency'],
                'method' => $old['payment_method'],
                'lease_id' => $old['lease_id'],
                'receipt_number' => $receiptNumber,
            ]);

            setAlert(__('payment_recorded', 'Payment recorded successfully!'), 'success');
            redirect('modules/payments/view.php?id=' . $paymentId);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
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
        <h4><i class="bi bi-plus-circle me-2"></i><?php _e('record_payment', 'Record Payment'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/payments/"><?php _e('payments'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('record', 'Record'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('payments.create'); ?>

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
        <form method="POST" id="paymentForm">
            <?php echo csrfField(); ?>

            <!-- Lease Selection -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-link-45deg me-1"></i> <?php _e('select_lease', 'Select Lease'); ?></h6>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('lease', 'Lease'); ?> <span class="text-danger">*</span></label>
                    <select name="lease_id" id="leaseSelect" class="form-select" required>
                        <option value=""><?php _e('select_lease_placeholder', 'Select a lease (tenant — unit — property)...'); ?></option>
                        <?php foreach ($activeLeases as $l): ?>
                        <option value="<?php echo $l['id']; ?>"
                                data-rent="<?php echo $l['rent_amount']; ?>"
                                data-currency="<?php echo htmlspecialchars($l['currency']); ?>"
                                data-frequency="<?php echo htmlspecialchars($l['payment_frequency']); ?>"
                                data-due-day="<?php echo $l['payment_due_day']; ?>"
                                data-tenant="<?php echo htmlspecialchars($l['tenant_name']); ?>"
                                data-unit="<?php echo htmlspecialchars($l['unit_number']); ?>"
                                data-property="<?php echo htmlspecialchars($l['property_name']); ?>"
                                <?php echo ($old['lease_id'] ?? 0) == $l['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($l['tenant_name'] . ' — ' . $l['unit_number'] . ' (' . $l['property_name'] . ') — ' . formatCurrency($l['rent_amount'], $l['currency']) . '/' . paymentFrequencyLabel($l['payment_frequency'])); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($activeLeases)): ?>
                    <small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i><?php _e('no_active_leases', 'No active leases found. Create a lease first.'); ?></small>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Lease Info Display -->
            <div id="leaseInfoBar" class="alert alert-info mt-3 d-none">
                <div class="d-flex flex-wrap gap-3">
                    <span><i class="bi bi-person me-1"></i><strong id="infoTenant"></strong></span>
                    <span><i class="bi bi-door-open me-1"></i><span id="infoUnit"></span></span>
                    <span><i class="bi bi-buildings me-1"></i><span id="infoProperty"></span></span>
                    <span><i class="bi bi-cash-stack me-1"></i><?php _e('rent'); ?>: <strong id="infoRent"></strong></span>
                    <span><i class="bi bi-arrow-repeat me-1"></i><span id="infoFrequency"></span></span>
                </div>
            </div>

            <hr>

            <!-- Payment Details -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-cash-coin me-1"></i> <?php _e('payment_details', 'Payment Details'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('amount'); ?> <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" name="amount" id="paymentAmount" step="0.01" min="0" value="<?php echo htmlspecialchars($old['amount'] ?? ''); ?>" required>
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
                    <label class="form-label fw-semibold"><?php _e('payment_date', 'Payment Date'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="payment_date" value="<?php echo htmlspecialchars($old['payment_date'] ?? date('Y-m-d')); ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('payment_method'); ?> <span class="text-danger">*</span></label>
                    <select name="payment_method" id="paymentMethod" class="form-select" required>
                        <option value="cash" <?php echo ($old['payment_method'] ?? 'cash') === 'cash' ? 'selected' : ''; ?>><?php _e('cash', 'Cash'); ?></option>
                        <option value="bank_transfer" <?php echo ($old['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : ''; ?>><?php _e('bank_transfer', 'Bank Transfer'); ?></option>
                        <option value="mobile_money" <?php echo ($old['payment_method'] ?? '') === 'mobile_money' ? 'selected' : ''; ?>><?php _e('mobile_money', 'Mobile Money'); ?></option>
                        <option value="cheque" <?php echo ($old['payment_method'] ?? '') === 'cheque' ? 'selected' : ''; ?>><?php _e('cheque', 'Cheque'); ?></option>
                        <option value="gateway" <?php echo ($old['payment_method'] ?? '') === 'gateway' ? 'selected' : ''; ?>><?php _e('gateway', 'Payment Gateway'); ?></option>
                    </select>
                </div>
                <?php receiptAccountPicker($old['ledger_account_id'] ?? null); ?>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('reference_number', 'Reference Number'); ?></label>
                    <input type="text" class="form-control" name="reference_number" placeholder="<?php _e('reference_placeholder', 'Transaction reference...'); ?>" value="<?php echo htmlspecialchars($old['reference_number'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('period_from', 'Period From'); ?></label>
                    <input type="date" class="form-control" name="period_from" id="periodFrom" value="<?php echo htmlspecialchars($old['period_from'] ?? ''); ?>">
                    <small class="text-muted"><?php _e('period_help', 'Which rent period does this payment cover?'); ?></small>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('period_to', 'Period To'); ?></label>
                    <input type="date" class="form-control" name="period_to" id="periodTo" value="<?php echo htmlspecialchars($old['period_to'] ?? ''); ?>">
                </div>
            </div>

            <!-- Period Suggestion -->
            <div id="periodSuggestion" class="mt-2 d-none">
                <div class="alert alert-light border py-2">
                    <small><i class="bi bi-lightbulb me-1"></i><span id="periodSuggestionText"></span></small>
                    <button type="button" class="btn btn-sm btn-outline-primary ms-2" id="applyPeriodBtn"><?php _e('apply', 'Apply'); ?></button>
                </div>
            </div>

            <hr>

            <!-- Notes & Options -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-gear me-1"></i> <?php _e('options', 'Options'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-8">
                    <label class="form-label fw-semibold"><?php _e('notes', 'Notes'); ?></label>
                    <textarea class="form-control" name="notes" rows="2" placeholder="<?php _e('payment_notes_placeholder', 'Optional notes about this payment...'); ?>"><?php echo htmlspecialchars($old['notes'] ?? ''); ?></textarea>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('receipt_options', 'Receipt'); ?></label>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="generate_receipt" value="1" id="generateReceipt" <?php echo ($old['generate_receipt'] ?? 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="generateReceipt"><?php _e('auto_generate_receipt', 'Auto-generate receipt'); ?></label>
                    </div>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/payments/" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('record_payment', 'Record Payment'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const leasesData = <?php echo $leasesJson; ?>;
let currentLease = null;

$(document).ready(function() {
    $('#leaseSelect').select2({ width: '100%', placeholder: '<?php echo __("select_lease_placeholder", "Select a lease..."); ?>' });

    // Auto-fill when lease is selected
    $('#leaseSelect').on('change', function() {
        const opt = $(this).find(':selected');
        const leaseId = $(this).val();

        if (!leaseId) {
            $('#leaseInfoBar').addClass('d-none');
            currentLease = null;
            return;
        }

        // Find lease data
        currentLease = leasesData.find(l => l.id == leaseId);
        if (!currentLease) return;

        // Show info bar
        $('#infoTenant').text(currentLease.tenant_name);
        $('#infoUnit').text(currentLease.unit_number);
        $('#infoProperty').text(currentLease.property_name);
        $('#infoRent').text(currentLease.currency + ' ' + Number(currentLease.rent_amount).toLocaleString());
        $('#infoFrequency').text(currentLease.payment_frequency.charAt(0).toUpperCase() + currentLease.payment_frequency.slice(1));
        $('#leaseInfoBar').removeClass('d-none');

        // Auto-fill amount & currency
        if (!$('#paymentAmount').val()) {
            $('#paymentAmount').val(currentLease.rent_amount);
        }
        $('#currencySelect').val(currentLease.currency);

        // Calculate period suggestion
        calculatePeriod();
    });

    // Recalculate period when amount changes
    $('#paymentAmount').on('change', function() {
        if (currentLease) calculatePeriod();
    });

    // Apply suggested period
    $('#applyPeriodBtn').on('click', function() {
        const suggestion = $(this).data('suggestion');
        if (suggestion) {
            $('#periodFrom').val(suggestion.from);
            $('#periodTo').val(suggestion.to);
        }
    });

    // Trigger if lease was pre-selected (on error reload)
    if ($('#leaseSelect').val()) {
        $('#leaseSelect').trigger('change');
    }
});

function calculatePeriod() {
    if (!currentLease) return;

    const amount = parseFloat($('#paymentAmount').val()) || 0;
    const leaseId = currentLease.id;

    if (amount <= 0) {
        $('#periodSuggestion').addClass('d-none');
        return;
    }

    fetch(BASE_URL + 'api/payments.php?action=calculate_period&lease_id=' + leaseId + '&amount=' + amount)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.suggested_periods && data.suggested_periods.length > 0) {
                const periods = data.suggested_periods;
                const first = periods[0];
                const last = periods[periods.length - 1];
                const text = '<?php echo __("covers_periods", "Covers"); ?> ' + data.periods_covered + ' <?php echo __("period_s", "period(s)"); ?>: ' + first.from + ' — ' + last.to
                    + (data.remainder > 0 ? ' (<?php echo __("remainder", "remainder"); ?>: ' + currentLease.currency + ' ' + data.remainder.toLocaleString() + ')' : '');

                $('#periodSuggestionText').text(text);
                $('#applyPeriodBtn').data('suggestion', {from: first.from, to: last.to});
                $('#periodSuggestion').removeClass('d-none');
            } else {
                $('#periodSuggestion').addClass('d-none');
            }
        })
        .catch(() => $('#periodSuggestion').addClass('d-none'));
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
