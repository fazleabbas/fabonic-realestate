<?php
/**
 * FIS Real Estate — View Payment (9.1 + 9.3)
 * Payment detail page with receipt generation, reverse action, activity log.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

$subStatus = enforceSubscription();

if (!hasPermission('payments', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Payment not found', 'danger');
    redirect('modules/payments/');
}

// Fetch payment with related data
$stmt = $conn->prepare("
    SELECT py.*, u.unit_number, u.unit_label, u.id AS unit_id_val,
           p.name AS property_name, p.id AS property_id_val,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
           t.phone AS tenant_phone, t.email AS tenant_email, t.id AS tenant_id_val,
           l.start_date AS lease_start, l.end_date AS lease_end, l.rent_amount AS lease_rent,
           l.currency AS lease_currency, l.payment_frequency AS lease_frequency, l.status AS lease_status,
           rec.full_name AS recorded_by_name
    FROM payments py
    INNER JOIN units u ON py.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON py.tenant_id = t.id
    LEFT JOIN leases l ON py.lease_id = l.id
    LEFT JOIN users rec ON py.recorded_by = rec.id
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

$pageTitle = __('payment', 'Payment') . ' #' . $id;

// Get receipt
$rcptStmt = $conn->prepare("SELECT * FROM receipts WHERE payment_id = :pid");
$rcptStmt->execute(['pid' => $id]);
$receipt = $rcptStmt->fetch();

// Activity log
$historyStmt = $conn->prepare("
    SELECT al.*, u.full_name AS user_name
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.entity_type = 'payment'
      AND al.entity_id = :pid
    ORDER BY al.created_at DESC
    LIMIT 15
");
$historyStmt->execute(['pid' => $id]);
$activityHistory = $historyStmt->fetchAll();

// Other payments for same lease
$otherPayments = [];
if ($payment['lease_id']) {
    $otherStmt = $conn->prepare("
        SELECT py.id, py.amount, py.currency, py.payment_date, py.payment_method, py.status,
               r.receipt_number
        FROM payments py
        LEFT JOIN receipts r ON r.payment_id = py.id
        WHERE py.lease_id = :lid AND py.id != :pid
        ORDER BY py.payment_date DESC
        LIMIT 10
    ");
    $otherStmt->execute(['lid' => $payment['lease_id'], 'pid' => $id]);
    $otherPayments = $otherStmt->fetchAll();
}

// Documents attached to this payment (only when the Documents module is available)
$docsModuleActive = hasTier('professional') && getSetting('documents_enabled', '0') === '1' && hasPermission('documents', 'view');
$paymentDocs = [];
if ($docsModuleActive) {
    try {
        $pdStmt = $conn->prepare("
            SELECT d.id, d.title, d.file_name, d.file_size, d.created_at, d.is_confidential,
                   dc.name AS category_name, dc.color AS category_color
            FROM documents d
            LEFT JOIN document_categories dc ON d.category_id = dc.id
            WHERE d.entity_type = 'payment' AND d.entity_id = :pid AND d.status = 'active'
            ORDER BY d.created_at DESC
            LIMIT 100
        ");
        $pdStmt->execute(['pid' => $id]);
        $paymentDocs = $pdStmt->fetchAll();
    } catch (PDOException $e) {
        // Documents module enabled but its tables aren't installed yet — never break the payment page.
        $docsModuleActive = false;
    }
}

$statusColors = ['recorded' => 'success', 'reversed' => 'danger'];
$statusLabels = [
    'recorded' => __('recorded', 'Recorded'),
    'reversed' => __('reversed', 'Reversed'),
];

$methodLabels = [
    'cash' => __('cash', 'Cash'),
    'bank_transfer' => __('bank_transfer', 'Bank Transfer'),
    'mobile_money' => __('mobile_money', 'Mobile Money'),
    'cheque' => __('cheque', 'Cheque'),
    'gateway' => __('gateway', 'Gateway'),
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-cash-coin me-2"></i><?php _e('payment', 'Payment'); ?> #<?php echo $id; ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/payments/"><?php _e('payments'); ?></a></li>
                <li class="breadcrumb-item active">#<?php echo $id; ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <!-- Receipt / Print -->
        <?php if ($receipt): ?>
        <a href="<?php echo BASE_URL; ?>modules/payments/receipt.php?payment_id=<?php echo $id; ?>" target="_blank" class="btn btn-sm btn-outline-success">
            <i class="bi bi-receipt me-1"></i> <?php _e('print_receipt', 'Print Receipt'); ?> (<?php echo htmlspecialchars($receipt['receipt_number']); ?>)
        </a>
        <?php elseif ($payment['status'] === 'recorded' && hasPermission('payments', 'edit')): ?>
        <button class="btn btn-sm btn-outline-success" id="generateReceiptBtn">
            <i class="bi bi-receipt me-1"></i> <?php _e('generate_receipt', 'Generate Receipt'); ?>
        </button>
        <?php endif; ?>
        <!-- Edit -->
        <?php if (hasPermission('payments', 'edit') && $payment['status'] === 'recorded'): ?>
        <a href="<?php echo BASE_URL; ?>modules/payments/edit.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-pencil me-1"></i> <?php _e('edit'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('payments.view'); ?>

<!-- Reversed Alert -->
<?php if ($payment['status'] === 'reversed'): ?>
<div class="alert alert-danger d-flex align-items-start gap-2">
    <i class="bi bi-x-octagon-fill" style="font-size:1.2rem;margin-top:2px;"></i>
    <div>
        <strong><?php _e('payment_reversed', 'Payment Reversed'); ?></strong><br>
        <small><?php _e('payment_reversed_note', 'This payment has been reversed and no longer counts toward the balance.'); ?></small>
    </div>
</div>
<?php endif; ?>

<!-- Payment Info Bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span class="badge bg-<?php echo $statusColors[$payment['status']] ?? 'secondary'; ?>" style="font-size:0.85rem;">
                <?php echo $statusLabels[$payment['status']] ?? ucfirst($payment['status']); ?>
            </span>
            <span><i class="bi bi-person me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/tenants/view.php?id=<?php echo $payment['tenant_id_val']; ?>" class="text-decoration-none">
                    <?php echo htmlspecialchars($payment['tenant_name']); ?>
                </a>
            </span>
            <span><i class="bi bi-door-open me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/units/view.php?id=<?php echo $payment['unit_id_val']; ?>" class="text-decoration-none">
                    <?php echo htmlspecialchars($payment['unit_number']); ?>
                </a>
            </span>
            <span><i class="bi bi-buildings me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $payment['property_id_val']; ?>" class="text-decoration-none">
                    <?php echo htmlspecialchars($payment['property_name']); ?>
                </a>
            </span>
            <?php if ($payment['lease_id']): ?>
            <span><i class="bi bi-file-earmark-text me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/leases/view.php?id=<?php echo $payment['lease_id']; ?>" class="text-decoration-none">
                    <?php _e('lease', 'Lease'); ?> #<?php echo $payment['lease_id']; ?>
                </a>
            </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;">
                <i class="bi bi-cash-stack"></i>
            </div>
            <div>
                <div class="stat-card-value <?php echo $payment['status'] === 'reversed' ? 'text-decoration-line-through text-muted' : ''; ?>">
                    <?php echo formatCurrency($payment['amount'], $payment['currency']); ?>
                </div>
                <div class="stat-card-label"><?php _e('amount_paid', 'Amount Paid'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;">
                <i class="bi bi-calendar-check"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo formatDate($payment['payment_date']); ?></div>
                <div class="stat-card-label"><?php _e('payment_date', 'Payment Date'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dcfce7;color:#166534;">
                <i class="bi bi-credit-card me-1"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo $methodLabels[$payment['payment_method']] ?? ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></div>
                <div class="stat-card-label"><?php _e('payment_method'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#f3e8ff;color:#6b21a8;">
                <i class="bi bi-receipt"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo $receipt ? htmlspecialchars($receipt['receipt_number']) : '—'; ?></div>
                <div class="stat-card-label"><?php _e('receipt_number', 'Receipt #'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Row -->
<div class="row g-3 mb-4">
    <!-- Payment Details -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> <?php _e('payment_details', 'Payment Details'); ?></div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted" style="width:140px;"><?php _e('amount'); ?></td>
                        <td class="fw-semibold"><?php echo formatCurrency($payment['amount'], $payment['currency']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('payment_date'); ?></td>
                        <td><?php echo formatDate($payment['payment_date']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('payment_method'); ?></td>
                        <td><?php echo $methodLabels[$payment['payment_method']] ?? ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                    </tr>
                    <?php if ($payment['reference_number']): ?>
                    <tr>
                        <td class="text-muted"><?php _e('reference_number'); ?></td>
                        <td><code><?php echo htmlspecialchars($payment['reference_number']); ?></code></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($payment['period_from']): ?>
                    <tr>
                        <td class="text-muted"><?php _e('period_covered', 'Period Covered'); ?></td>
                        <td><?php echo formatDate($payment['period_from']); ?> — <?php echo formatDate($payment['period_to']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted"><?php _e('recorded_by', 'Recorded By'); ?></td>
                        <td><?php echo htmlspecialchars($payment['recorded_by_name'] ?? 'System'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('recorded_on', 'Recorded On'); ?></td>
                        <td><?php echo formatDateTime($payment['created_at']); ?></td>
                    </tr>
                    <?php if ($payment['lease_id'] && $payment['lease_rent']): ?>
                    <tr>
                        <td class="text-muted"><?php _e('lease_rent', 'Lease Rent'); ?></td>
                        <td><?php echo formatCurrency($payment['lease_rent'], $payment['lease_currency']); ?>/<?php echo $payment['lease_frequency']; ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Right Panel -->
    <div class="col-12 col-lg-6">
        <!-- Notes -->
        <?php if ($payment['notes']): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-card-text me-1"></i> <?php _e('notes'); ?></div>
            <div class="card-body">
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($payment['notes'])); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <?php if ($payment['status'] === 'recorded' && hasPermission('payments', 'edit')): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-lightning me-1"></i> <?php _e('payment_actions', 'Payment Actions'); ?></div>
            <div class="card-body">
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-danger btn-sm" onclick="showReverseModal()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> <?php _e('reverse_payment', 'Reverse Payment'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Other Payments for Same Lease -->
        <?php if (!empty($otherPayments)): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-list-ul me-1"></i> <?php _e('other_payments_lease', 'Other Payments (Same Lease)'); ?></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th><?php _e('date'); ?></th>
                                <th class="text-end"><?php _e('amount'); ?></th>
                                <th><?php _e('method', 'Method'); ?></th>
                                <th><?php _e('status'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($otherPayments as $op): ?>
                            <tr>
                                <td><a href="?id=<?php echo $op['id']; ?>" class="text-decoration-none"><small><?php echo formatDate($op['payment_date']); ?></small></a></td>
                                <td class="text-end"><small class="fw-semibold"><?php echo formatCurrency($op['amount'], $op['currency']); ?></small></td>
                                <td><small><?php echo $methodLabels[$op['payment_method']] ?? ucfirst(str_replace('_', ' ', $op['payment_method'])); ?></small></td>
                                <td><span class="badge bg-<?php echo $statusColors[$op['status']] ?? 'secondary'; ?>" style="font-size:0.65rem;"><?php echo ucfirst($op['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Documents / Vouchers -->
<?php if ($docsModuleActive): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-paperclip me-1"></i> <?php _e('doc_documents', 'Documents'); ?> (<?php echo count($paymentDocs); ?>)</span>
        <?php if (hasPermission('documents', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/documents/upload.php?entity_type=payment&entity_id=<?php echo $id; ?>"
           class="btn btn-sm btn-primary">
            <i class="bi bi-cloud-upload me-1"></i> <?php _e('doc_upload_voucher', 'Upload Voucher'); ?>
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body <?php echo empty($paymentDocs) ? '' : 'p-0'; ?>">
        <?php if (empty($paymentDocs)): ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-paperclip" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0"><?php _e('doc_no_payment_docs', 'No documents attached to this payment'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th><?php _e('doc_title', 'Title'); ?></th>
                        <th><?php _e('doc_category', 'Category'); ?></th>
                        <th class="text-end"><?php _e('doc_size', 'Size'); ?></th>
                        <th><?php _e('date'); ?></th>
                        <th class="text-end"><?php _e('actions', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paymentDocs as $doc):
                        $bytes = (int)$doc['file_size'];
                        if ($bytes >= 1048576) { $sizeFmt = round($bytes / 1048576, 1) . ' MB'; }
                        elseif ($bytes >= 1024) { $sizeFmt = round($bytes / 1024, 1) . ' KB'; }
                        else { $sizeFmt = $bytes . ' B'; }
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo BASE_URL; ?>modules/documents/view.php?id=<?php echo $doc['id']; ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars($doc['title']); ?>
                            </a>
                            <?php if ((int)$doc['is_confidential'] === 1): ?>
                            <i class="bi bi-lock-fill text-warning ms-1" title="<?php _e('doc_confidential', 'Confidential'); ?>"></i>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($doc['category_name']): ?>
                            <span class="badge" style="background:<?php echo htmlspecialchars($doc['category_color'] ?: '#6c757d'); ?>;">
                                <?php echo htmlspecialchars($doc['category_name']); ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><small><?php echo $sizeFmt; ?></small></td>
                        <td><small><?php echo formatDate($doc['created_at']); ?></small></td>
                        <td class="text-end">
                            <a href="<?php echo BASE_URL; ?>api/documents.php?action=download&id=<?php echo $doc['id']; ?>"
                               class="btn btn-sm btn-outline-secondary py-0 px-1" title="<?php _e('download', 'Download'); ?>">
                                <i class="bi bi-download"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Activity Log -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-clock-history me-1"></i> <?php _e('activity', 'Activity'); ?></div>
    <div class="card-body">
        <?php if (empty($activityHistory)): ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-clock" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0"><?php _e('no_history', 'No activity history'); ?></p>
        </div>
        <?php else: ?>
        <div class="timeline-simple">
            <?php foreach ($activityHistory as $entry):
                $details = json_decode($entry['details'] ?? '{}', true) ?: [];
            ?>
            <div class="d-flex gap-2 mb-3 pb-2 border-bottom">
                <div>
                    <i class="bi bi-circle-fill text-primary" style="font-size:0.5rem;margin-top:6px;"></i>
                </div>
                <div class="flex-grow-1">
                    <small class="text-muted"><?php echo formatDateTime($entry['created_at']); ?></small>
                    <?php if (!empty($details['status_change'])): ?>
                    <br><small class="fw-semibold"><?php echo htmlspecialchars($details['status_change']); ?></small>
                    <?php if (!empty($details['reason'])): ?>
                    <br><small class="text-muted"><?php echo htmlspecialchars($details['reason']); ?></small>
                    <?php endif; ?>
                    <?php else: ?>
                    <br><small><?php echo ucfirst($entry['action']); ?></small>
                    <?php if (!empty($details['amount'])): ?>
                    <br><small class="text-muted"><?php echo htmlspecialchars($details['amount']); ?></small>
                    <?php endif; ?>
                    <?php endif; ?>
                    <br><small class="text-muted"><?php echo htmlspecialchars($entry['user_name'] ?? 'System'); ?></small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Reverse Payment Modal -->
<div class="modal fade" id="reverseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title"><i class="bi bi-arrow-counterclockwise me-1"></i> <?php _e('reverse_payment', 'Reverse Payment'); ?></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('reverse_confirm', 'Are you sure you want to reverse this payment? This will mark the payment as void.'); ?></p>
                <div class="alert alert-light border">
                    <strong><?php echo formatCurrency($payment['amount'], $payment['currency']); ?></strong> — <?php echo htmlspecialchars($payment['tenant_name']); ?> — <?php echo formatDate($payment['payment_date']); ?>
                </div>
                <form id="reverseForm">
                    <input type="hidden" name="action" value="reverse">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRF(); ?>">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('reversal_reason', 'Reason for Reversal'); ?></label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="<?php _e('reversal_reason_placeholder', 'Explain why this payment is being reversed...'); ?>"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-sm btn-danger" id="confirmReverseBtn">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> <?php _e('confirm_reverse', 'Reverse Payment'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo generateCSRF(); ?>';
const apiUrl = BASE_URL + 'api/payments.php';

// Reverse modal
function showReverseModal() {
    new bootstrap.Modal(document.getElementById('reverseModal')).show();
}

document.getElementById('confirmReverseBtn').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> <?php echo __("processing", "Processing..."); ?>';

    const formData = new FormData(document.getElementById('reverseForm'));

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1"></i> <?php echo __("confirm_reverse", "Reverse Payment"); ?>';
            }
        })
        .catch(() => {
            alert('Request failed');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1"></i> <?php echo __("confirm_reverse", "Reverse Payment"); ?>';
        });
});

// Generate receipt
<?php if (!$receipt && $payment['status'] === 'recorded'): ?>
document.getElementById('generateReceiptBtn')?.addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> <?php echo __("processing", "Processing..."); ?>';

    const formData = new FormData();
    formData.append('action', 'generate_receipt');
    formData.append('csrf_token', csrfToken);
    formData.append('payment_id', <?php echo $id; ?>);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-receipt me-1"></i> <?php echo __("generate_receipt", "Generate Receipt"); ?>';
            }
        })
        .catch(() => {
            alert('Request failed');
            btn.disabled = false;
        });
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
