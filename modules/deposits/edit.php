<?php
/**
 * FIS Real Estate — Edit Security Deposit (10.1)
 * Edit deposit details (amount, date, method, notes). Lease/tenant/unit read-only.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('edit_deposit', 'Edit Deposit');

$subStatus = enforceSubscription();

if (!hasPermission('deposits', 'edit')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Deposit not found', 'danger');
    redirect('modules/deposits/');
}

// Fetch deposit
$stmt = $conn->prepare("
    SELECT d.*, u.unit_number, p.name AS property_name,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name
    FROM deposits d
    INNER JOIN units u ON d.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON d.tenant_id = t.id
    WHERE d.id = :id
");
$stmt->execute(['id' => $id]);
$deposit = $stmt->fetch();

if (!$deposit) {
    setAlert('Deposit not found', 'danger');
    redirect('modules/deposits/');
}

if (!canAccessUnit($deposit['unit_id'])) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/deposits/');
}

if (in_array($deposit['status'], ['refunded', 'forfeited'])) {
    setAlert(__('deposit_not_editable', 'Refunded/forfeited deposits cannot be edited.'), 'warning');
    redirect('modules/deposits/view.php?id=' . $id);
}

$errors = [];
$old = [
    'amount' => $deposit['amount'],
    'paid_date' => $deposit['paid_date'],
    'payment_method' => $deposit['payment_method'],
    'reference_number' => $deposit['reference_number'],
    'notes' => $deposit['notes'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $old = [
        'amount' => trim($_POST['amount'] ?? ''),
        'paid_date' => trim($_POST['paid_date'] ?? ''),
        'payment_method' => trim($_POST['payment_method'] ?? 'cash'),
        'reference_number' => trim($_POST['reference_number'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
    ];

    $validMethods = ['cash', 'bank_transfer', 'mobile_money', 'cheque'];

    if ((float)$old['amount'] <= 0) $errors[] = __('amount_required', 'Amount must be greater than 0');
    if ($old['paid_date'] === '') $errors[] = __('paid_date_required', 'Paid date is required');
    if (!in_array($old['payment_method'], $validMethods)) $errors[] = __('invalid_method', 'Invalid payment method');

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                UPDATE deposits SET
                    amount = :amount, paid_date = :paid_date,
                    payment_method = :payment_method, reference_number = :reference_number,
                    notes = :notes
                WHERE id = :id
            ");
            $stmt->execute([
                'amount' => (float)$old['amount'],
                'paid_date' => date('Y-m-d', strtotime($old['paid_date'])),
                'payment_method' => $old['payment_method'],
                'reference_number' => $old['reference_number'] ?: null,
                'notes' => $old['notes'] ?: null,
                'id' => $id,
            ]);

            logActivity('updated', 'deposit', $id, [
                'amount' => $deposit['amount'] . ' -> ' . $old['amount']
            ]);

            setAlert(__('deposit_updated', 'Deposit updated successfully!'), 'success');
            redirect('modules/deposits/view.php?id=' . $id);
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
        <h4><i class="bi bi-pencil me-2"></i><?php _e('edit_deposit', 'Edit Deposit'); ?> #<?php echo $id; ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/deposits/"><?php _e('deposits'); ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/deposits/view.php?id=<?php echo $id; ?>">#<?php echo $id; ?></a></li>
                <li class="breadcrumb-item active"><?php _e('edit'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('deposits.edit'); ?>

<!-- Read-only info -->
<div class="alert alert-info">
    <small><i class="bi bi-info-circle me-1"></i><?php _e('deposit_lease_readonly', 'Lease, tenant, and unit cannot be changed.'); ?></small>
    <br><strong><?php echo htmlspecialchars($deposit['tenant_name']); ?></strong> — <?php echo htmlspecialchars($deposit['unit_number']); ?> (<?php echo htmlspecialchars($deposit['property_name']); ?>)
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
                    <label class="form-label fw-semibold"><?php _e('paid_date', 'Date Paid'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="paid_date" value="<?php echo htmlspecialchars($old['paid_date']); ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('payment_method'); ?> <span class="text-danger">*</span></label>
                    <select name="payment_method" class="form-select" required>
                        <option value="cash" <?php echo $old['payment_method'] === 'cash' ? 'selected' : ''; ?>><?php _e('cash', 'Cash'); ?></option>
                        <option value="bank_transfer" <?php echo $old['payment_method'] === 'bank_transfer' ? 'selected' : ''; ?>><?php _e('bank_transfer', 'Bank Transfer'); ?></option>
                        <option value="mobile_money" <?php echo $old['payment_method'] === 'mobile_money' ? 'selected' : ''; ?>><?php _e('mobile_money', 'Mobile Money'); ?></option>
                        <option value="cheque" <?php echo $old['payment_method'] === 'cheque' ? 'selected' : ''; ?>><?php _e('cheque', 'Cheque'); ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('reference_number', 'Reference Number'); ?></label>
                    <input type="text" class="form-control" name="reference_number" value="<?php echo htmlspecialchars($old['reference_number'] ?? ''); ?>">
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('notes', 'Notes'); ?></label>
                    <textarea class="form-control" name="notes" rows="2"><?php echo htmlspecialchars($old['notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/deposits/view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('save_changes'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
