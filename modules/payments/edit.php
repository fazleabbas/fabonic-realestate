<?php
/**
 * FIS Real Estate — Edit Payment (9.1)
 * Edit an existing payment record. Lease/tenant/unit are read-only.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('edit_payment', 'Edit Payment');

$subStatus = enforceSubscription();

if (!hasPermission('payments', 'edit')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Payment not found', 'danger');
    redirect('modules/payments/');
}

// Fetch payment
$stmt = $conn->prepare("
    SELECT py.*, u.unit_number, p.name AS property_name,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
           l.rent_amount AS lease_rent, l.currency AS lease_currency
    FROM payments py
    INNER JOIN units u ON py.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON py.tenant_id = t.id
    LEFT JOIN leases l ON py.lease_id = l.id
    WHERE py.id = :id
");
$stmt->execute(['id' => $id]);
$payment = $stmt->fetch();

if (!$payment) {
    setAlert('Payment not found', 'danger');
    redirect('modules/payments/');
}

if (!canAccessUnit($payment['unit_id'])) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/payments/');
}

if ($payment['status'] === 'reversed') {
    setAlert(__('reversed_not_editable', 'Reversed payments cannot be edited.'), 'danger');
    redirect('modules/payments/view.php?id=' . $id);
}

// Handle form submission
$errors = [];
$old = [
    'amount' => $payment['amount'],
    'payment_date' => $payment['payment_date'],
    'payment_method' => $payment['payment_method'],
    'reference_number' => $payment['reference_number'],
    'period_from' => $payment['period_from'],
    'period_to' => $payment['period_to'],
    'notes' => $payment['notes'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $old = [
        'amount' => trim($_POST['amount'] ?? ''),
        'payment_date' => trim($_POST['payment_date'] ?? ''),
        'payment_method' => trim($_POST['payment_method'] ?? 'cash'),
        'reference_number' => trim($_POST['reference_number'] ?? ''),
        'period_from' => trim($_POST['period_from'] ?? ''),
        'period_to' => trim($_POST['period_to'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
    ];

    $validMethods = ['cash', 'bank_transfer', 'mobile_money', 'cheque', 'gateway'];

    if ((float)$old['amount'] <= 0) $errors[] = __('amount_required', 'Amount must be greater than 0');
    if ($old['payment_date'] === '') $errors[] = __('payment_date_required', 'Payment date is required');
    if (!in_array($old['payment_method'], $validMethods)) $errors[] = __('invalid_method', 'Invalid payment method');

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                UPDATE payments SET
                    amount = :amount, payment_date = :payment_date,
                    payment_method = :payment_method, reference_number = :reference_number,
                    period_from = :period_from, period_to = :period_to, notes = :notes
                WHERE id = :id
            ");
            $stmt->execute([
                'amount' => (float)$old['amount'],
                'payment_date' => date('Y-m-d', strtotime($old['payment_date'])),
                'payment_method' => $old['payment_method'],
                'reference_number' => $old['reference_number'] ?: null,
                'period_from' => $old['period_from'] ? date('Y-m-d', strtotime($old['period_from'])) : null,
                'period_to' => $old['period_to'] ? date('Y-m-d', strtotime($old['period_to'])) : null,
                'notes' => $old['notes'] ?: null,
                'id' => $id,
            ]);

            logActivity('updated', 'payment', $id, [
                'amount' => $payment['amount'] . ' -> ' . $old['amount']
            ]);

            setAlert(__('payment_updated', 'Payment updated successfully!'), 'success');
            redirect('modules/payments/view.php?id=' . $id);
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
        <h4><i class="bi bi-pencil me-2"></i><?php _e('edit_payment', 'Edit Payment'); ?> #<?php echo $id; ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/payments/"><?php _e('payments'); ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/payments/view.php?id=<?php echo $id; ?>">#<?php echo $id; ?></a></li>
                <li class="breadcrumb-item active"><?php _e('edit'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('payments.edit'); ?>

<!-- Lease Info (read-only) -->
<div class="alert alert-info">
    <div class="d-flex flex-wrap gap-3">
        <span><i class="bi bi-person me-1"></i><strong><?php echo htmlspecialchars($payment['tenant_name']); ?></strong></span>
        <span><i class="bi bi-door-open me-1"></i><?php echo htmlspecialchars($payment['unit_number']); ?></span>
        <span><i class="bi bi-buildings me-1"></i><?php echo htmlspecialchars($payment['property_name']); ?></span>
        <?php if ($payment['lease_id']): ?>
        <span><i class="bi bi-file-earmark-text me-1"></i><?php _e('lease', 'Lease'); ?> #<?php echo $payment['lease_id']; ?></span>
        <?php endif; ?>
    </div>
    <small class="text-muted"><?php _e('lease_tenant_readonly_payment', 'Lease, tenant and unit cannot be changed.'); ?></small>
</div>

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
        <form method="POST">
            <?php echo csrfField(); ?>

            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('amount'); ?> <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" name="amount" step="0.01" min="0" value="<?php echo htmlspecialchars($old['amount']); ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('payment_date'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="payment_date" value="<?php echo htmlspecialchars($old['payment_date']); ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('payment_method'); ?> <span class="text-danger">*</span></label>
                    <select name="payment_method" class="form-select" required>
                        <option value="cash" <?php echo $old['payment_method'] === 'cash' ? 'selected' : ''; ?>><?php _e('cash', 'Cash'); ?></option>
                        <option value="bank_transfer" <?php echo $old['payment_method'] === 'bank_transfer' ? 'selected' : ''; ?>><?php _e('bank_transfer', 'Bank Transfer'); ?></option>
                        <option value="mobile_money" <?php echo $old['payment_method'] === 'mobile_money' ? 'selected' : ''; ?>><?php _e('mobile_money', 'Mobile Money'); ?></option>
                        <option value="cheque" <?php echo $old['payment_method'] === 'cheque' ? 'selected' : ''; ?>><?php _e('cheque', 'Cheque'); ?></option>
                        <option value="gateway" <?php echo $old['payment_method'] === 'gateway' ? 'selected' : ''; ?>><?php _e('gateway', 'Gateway'); ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('reference_number'); ?></label>
                    <input type="text" class="form-control" name="reference_number" value="<?php echo htmlspecialchars($old['reference_number'] ?? ''); ?>">
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('period_from', 'Period From'); ?></label>
                    <input type="date" class="form-control" name="period_from" value="<?php echo htmlspecialchars($old['period_from'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('period_to', 'Period To'); ?></label>
                    <input type="date" class="form-control" name="period_to" value="<?php echo htmlspecialchars($old['period_to'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('notes'); ?></label>
                    <textarea class="form-control" name="notes" rows="2"><?php echo htmlspecialchars($old['notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/payments/view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('save_changes', 'Save Changes'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
