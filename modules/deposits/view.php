<?php
/**
 * FIS Real Estate — View Security Deposit (10.1 + 10.2 + 10.3)
 * Deposit detail page with deductions management, refund workflow, activity log.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/payables.php';
require_once __DIR__ . '/../../includes/entity_documents.php';
require_once __DIR__ . '/../../includes/subscription.php';

$subStatus = enforceSubscription();

if (!hasPermission('deposits', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Deposit not found', 'danger');
    redirect('modules/deposits/');
}

// Fetch deposit with related data
$stmt = $conn->prepare("
    SELECT d.*, u.unit_number, u.unit_label, u.id AS unit_id_val,
           p.name AS property_name, p.id AS property_id_val,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
           t.phone AS tenant_phone, t.email AS tenant_email, t.id AS tenant_id_val,
           l.start_date AS lease_start, l.end_date AS lease_end, l.rent_amount AS lease_rent,
           l.currency AS lease_currency, l.deposit_amount AS lease_deposit, l.status AS lease_status
    FROM deposits d
    INNER JOIN units u ON d.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON d.tenant_id = t.id
    INNER JOIN leases l ON d.lease_id = l.id
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

$pageTitle = __('deposit', 'Deposit') . ' #' . $id;

// Get deductions
$dedStmt = $conn->prepare("SELECT * FROM deposit_deductions WHERE deposit_id = :did ORDER BY created_at");
$dedStmt->execute(['did' => $id]);
$deductions = $dedStmt->fetchAll();
$totalDeductions = 0;
foreach ($deductions as $ded) {
    $totalDeductions += (float)$ded['amount'];
}
$refundableAmount = max(0, (float)$deposit['amount'] - $totalDeductions);

// Activity log
$historyStmt = $conn->prepare("
    SELECT al.*, u.full_name AS user_name
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE (al.entity_type = 'deposit' AND al.entity_id = :did)
       OR (al.entity_type = 'deposit_deduction' AND JSON_EXTRACT(al.details, '$.deposit_id') = :did2)
    ORDER BY al.created_at DESC
    LIMIT 15
");
$historyStmt->execute(['did' => $id, 'did2' => $id]);
$activityHistory = $historyStmt->fetchAll();

$statusColors = ['held' => 'primary', 'partially_refunded' => 'warning', 'refunded' => 'success', 'forfeited' => 'danger'];
$statusLabels = [
    'held' => __('deposit_held', 'Held'),
    'partially_refunded' => __('deposit_partially_refunded', 'Partial Refund'),
    'refunded' => __('deposit_refunded', 'Refunded'),
    'forfeited' => __('deposit_forfeited', 'Forfeited'),
];

$methodLabels = [
    'cash' => __('cash', 'Cash'),
    'bank_transfer' => __('bank_transfer', 'Bank Transfer'),
    'mobile_money' => __('mobile_money', 'Mobile Money'),
    'cheque' => __('cheque', 'Cheque'),
];

$isEditable = in_array($deposit['status'], ['held', 'partially_refunded']);

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-safe me-2"></i><?php _e('deposit', 'Deposit'); ?> #<?php echo $id; ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/deposits/"><?php _e('deposits'); ?></a></li>
                <li class="breadcrumb-item active">#<?php echo $id; ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (hasPermission('deposits', 'edit') && $deposit['status'] === 'held'): ?>
        <a href="<?php echo BASE_URL; ?>modules/deposits/edit.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-pencil me-1"></i> <?php _e('edit'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('deposits.view'); ?>

<!-- Status Alert -->
<?php if (($deposit['payout_status'] ?? 'none') === 'pending'): ?>
<div class="alert alert-warning d-flex align-items-center">
    <i class="bi bi-hourglass-split fs-4 me-3"></i>
    <div>
        <strong><?php echo $deposit['payout_type'] === 'forfeit'
            ? __('dep_forfeit_pending', 'Forfeit awaiting approval')
            : __('dep_refund_pending', 'Refund awaiting approval'); ?></strong><br>
        <small><?php _e('dep_pending_note', 'Nothing has moved yet. An approver must sign this off in Ledger &rsaquo; Payables.'); ?></small>
    </div>
</div>
<?php elseif (($deposit['payout_status'] ?? '') === 'rejected' && !empty($deposit['payout_rejection_reason'])): ?>
<div class="alert alert-secondary py-2">
    <strong><?php _e('led_rejected', 'Rejected'); ?>:</strong> <?php echo htmlspecialchars($deposit['payout_rejection_reason']); ?>
</div>
<?php endif; ?>

<?php if ($deposit['status'] === 'forfeited'): ?>
<div class="alert alert-danger d-flex align-items-start gap-2">
    <i class="bi bi-x-octagon-fill" style="font-size:1.2rem;margin-top:2px;"></i>
    <div>
        <strong><?php _e('deposit_forfeited', 'Deposit Forfeited'); ?></strong><br>
        <small><?php _e('deposit_forfeited_note', 'This deposit has been forfeited and will not be returned.'); ?></small>
    </div>
</div>
<?php elseif ($deposit['status'] === 'refunded'): ?>
<div class="alert alert-success d-flex align-items-start gap-2">
    <i class="bi bi-check-circle-fill" style="font-size:1.2rem;margin-top:2px;"></i>
    <div>
        <strong><?php _e('deposit_refunded', 'Deposit Refunded'); ?></strong><br>
        <small><?php _e('deposit_refunded_note', 'This deposit has been fully refunded.'); ?> <?php echo formatCurrency($deposit['refund_amount'], $deposit['currency']); ?> <?php _e('on', 'on'); ?> <?php echo formatDate($deposit['refund_date']); ?></small>
    </div>
</div>
<?php endif; ?>

<!-- Info Bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span class="badge bg-<?php echo $statusColors[$deposit['status']] ?? 'secondary'; ?>" style="font-size:0.85rem;">
                <?php echo $statusLabels[$deposit['status']] ?? ucfirst($deposit['status']); ?>
            </span>
            <span><i class="bi bi-person me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/tenants/view.php?id=<?php echo $deposit['tenant_id_val']; ?>" class="text-decoration-none">
                    <?php echo htmlspecialchars($deposit['tenant_name']); ?>
                </a>
            </span>
            <span><i class="bi bi-door-open me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/units/view.php?id=<?php echo $deposit['unit_id_val']; ?>" class="text-decoration-none">
                    <?php echo htmlspecialchars($deposit['unit_number']); ?>
                </a>
            </span>
            <span><i class="bi bi-buildings me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $deposit['property_id_val']; ?>" class="text-decoration-none">
                    <?php echo htmlspecialchars($deposit['property_name']); ?>
                </a>
            </span>
            <span><i class="bi bi-file-earmark-text me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/leases/view.php?id=<?php echo $deposit['lease_id']; ?>" class="text-decoration-none">
                    <?php _e('lease', 'Lease'); ?> #<?php echo $deposit['lease_id']; ?>
                </a>
            </span>
        </div>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;">
                <i class="bi bi-safe"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($deposit['amount'], $deposit['currency']); ?></div>
                <div class="stat-card-label"><?php _e('deposit_amount', 'Deposit Amount'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;">
                <i class="bi bi-dash-circle"></i>
            </div>
            <div>
                <div class="stat-card-value text-danger"><?php echo formatCurrency($totalDeductions, $deposit['currency']); ?></div>
                <div class="stat-card-label"><?php _e('total_deductions', 'Total Deductions'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;">
                <i class="bi bi-arrow-return-left"></i>
            </div>
            <div>
                <div class="stat-card-value text-success"><?php echo formatCurrency($refundableAmount, $deposit['currency']); ?></div>
                <div class="stat-card-label"><?php _e('refundable_amount', 'Refundable'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#f3e8ff;color:#6b21a8;">
                <i class="bi bi-calendar-check"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo $deposit['paid_date'] ? formatDate($deposit['paid_date']) : '—'; ?></div>
                <div class="stat-card-label"><?php _e('paid_date', 'Paid Date'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Row -->
<div class="row g-3 mb-4">
    <!-- Deposit Details -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> <?php _e('deposit_details', 'Deposit Details'); ?></div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted" style="width:140px;"><?php _e('amount'); ?></td>
                        <td class="fw-semibold"><?php echo formatCurrency($deposit['amount'], $deposit['currency']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('paid_date', 'Paid Date'); ?></td>
                        <td><?php echo $deposit['paid_date'] ? formatDate($deposit['paid_date']) : '—'; ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('payment_method'); ?></td>
                        <td><?php echo $methodLabels[$deposit['payment_method']] ?? ucfirst(str_replace('_', ' ', $deposit['payment_method'] ?? '')); ?></td>
                    </tr>
                    <?php if ($deposit['reference_number']): ?>
                    <tr>
                        <td class="text-muted"><?php _e('reference_number'); ?></td>
                        <td><code><?php echo htmlspecialchars($deposit['reference_number']); ?></code></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted"><?php _e('lease_deposit', 'Lease Deposit'); ?></td>
                        <td><?php echo formatCurrency($deposit['lease_deposit'], $deposit['lease_currency']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('recorded_on', 'Recorded On'); ?></td>
                        <td><?php echo formatDateTime($deposit['created_at']); ?></td>
                    </tr>
                    <?php if ($deposit['refund_date']): ?>
                    <tr>
                        <td class="text-muted"><?php _e('refund_date', 'Refund Date'); ?></td>
                        <td><?php echo formatDate($deposit['refund_date']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('refund_amount', 'Refund Amount'); ?></td>
                        <td class="fw-semibold text-success"><?php echo formatCurrency($deposit['refund_amount'], $deposit['currency']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('refund_method', 'Refund Method'); ?></td>
                        <td><?php echo htmlspecialchars($deposit['refund_method'] ?? ''); ?></td>
                    </tr>
                    <?php if ($deposit['refund_reference']): ?>
                    <tr>
                        <td class="text-muted"><?php _e('refund_reference', 'Refund Ref'); ?></td>
                        <td><code><?php echo htmlspecialchars($deposit['refund_reference']); ?></code></td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Right Panel: Notes + Actions -->
    <div class="col-12 col-lg-6">
        <?php if ($deposit['notes']): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-card-text me-1"></i> <?php _e('notes'); ?></div>
            <div class="card-body">
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($deposit['notes'])); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <?php if ($isEditable && hasPermission('deposits', 'edit')): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-lightning me-1"></i> <?php _e('deposit_actions', 'Deposit Actions'); ?></div>
            <div class="card-body">
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-success btn-sm" onclick="showRefundModal()">
                        <i class="bi bi-arrow-return-left me-1"></i> <?php _e('process_refund', 'Process Refund'); ?>
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="showForfeitModal()">
                        <i class="bi bi-x-octagon me-1"></i> <?php _e('forfeit_deposit', 'Forfeit Deposit'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Deductions Section (10.2) -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-check me-1"></i> <?php _e('deductions', 'Deductions'); ?> (<?php echo count($deductions); ?>)</span>
        <?php if ($isEditable && hasPermission('deposits', 'edit')): ?>
        <button class="btn btn-sm btn-outline-primary" onclick="showAddDeductionModal()">
            <i class="bi bi-plus me-1"></i><?php _e('add_deduction', 'Add Deduction'); ?>
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($deductions)): ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-check-circle" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0"><?php _e('no_deductions', 'No deductions recorded'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th><?php _e('description'); ?></th>
                        <th class="text-end"><?php _e('amount'); ?></th>
                        <th><?php _e('date'); ?></th>
                        <?php if ($isEditable && hasPermission('deposits', 'edit')): ?>
                        <th style="width:50px;"></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deductions as $ded): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($ded['description']); ?></td>
                        <td class="text-end fw-semibold text-danger"><?php echo formatCurrency($ded['amount'], $deposit['currency']); ?></td>
                        <td><small><?php echo formatDate($ded['created_at']); ?></small></td>
                        <?php if ($isEditable && hasPermission('deposits', 'edit')): ?>
                        <td>
                            <button class="btn btn-outline-danger btn-sm" onclick="deleteDeduction(<?php echo $ded['id']; ?>)" title="<?php _e('delete'); ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light">
                        <td class="fw-semibold"><?php _e('total_deductions', 'Total Deductions'); ?></td>
                        <td class="text-end fw-bold text-danger"><?php echo formatCurrency($totalDeductions, $deposit['currency']); ?></td>
                        <td colspan="2"></td>
                    </tr>
                    <tr class="table-light">
                        <td class="fw-semibold"><?php _e('refundable_balance', 'Refundable Balance'); ?></td>
                        <td class="text-end fw-bold text-success"><?php echo formatCurrency($refundableAmount, $deposit['currency']); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

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
                    <br><small><?php echo ucfirst($entry['action']); ?> <?php echo $entry['entity_type']; ?></small>
                    <?php if (!empty($details['amount'])): ?>
                    <br><small class="text-muted"><?php echo htmlspecialchars($details['amount']); ?>
                    <?php if (!empty($details['description'])): ?> — <?php echo htmlspecialchars($details['description']); ?><?php endif; ?></small>
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

<!-- Add Deduction Modal -->
<div class="modal fade" id="addDeductionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-dash-circle me-1"></i> <?php _e('add_deduction', 'Add Deduction'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="deductionForm">
                    <input type="hidden" name="action" value="add_deduction">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRF(); ?>">
                    <input type="hidden" name="deposit_id" value="<?php echo $id; ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('description'); ?> <span class="text-danger">*</span></label>
                        <input type="text" name="description" class="form-control" placeholder="<?php _e('deduction_desc_placeholder', 'e.g. Broken window repair, Wall repainting...'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('amount'); ?> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><?php echo htmlspecialchars($deposit['currency']); ?></span>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" max="<?php echo $refundableAmount; ?>" required>
                        </div>
                        <small class="text-muted"><?php _e('max_deduction', 'Max'); ?>: <?php echo formatCurrency($refundableAmount, $deposit['currency']); ?></small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-sm btn-primary" id="confirmDeductionBtn">
                    <i class="bi bi-plus me-1"></i> <?php _e('add_deduction', 'Add Deduction'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Process Refund Modal (10.3) -->
<div class="modal fade" id="refundModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h6 class="modal-title"><i class="bi bi-arrow-return-left me-1"></i> <?php _e('process_refund', 'Process Refund'); ?></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span><?php _e('deposit_amount', 'Deposit'); ?></span>
                        <strong><?php echo formatCurrency($deposit['amount'], $deposit['currency']); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-1 text-danger">
                        <span><?php _e('total_deductions', 'Deductions'); ?></span>
                        <strong>-<?php echo formatCurrency($totalDeductions, $deposit['currency']); ?></strong>
                    </div>
                    <hr class="my-1">
                    <div class="d-flex justify-content-between text-success">
                        <span class="fw-bold"><?php _e('refundable_amount', 'Refundable'); ?></span>
                        <strong><?php echo formatCurrency($refundableAmount, $deposit['currency']); ?></strong>
                    </div>
                </div>
                <form id="refundForm">
                    <input type="hidden" name="action" value="process_refund">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRF(); ?>">
                    <input type="hidden" name="deposit_id" value="<?php echo $id; ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('refund_amount', 'Refund Amount'); ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><?php echo htmlspecialchars($deposit['currency']); ?></span>
                            <input type="number" name="refund_amount" class="form-control" step="0.01" min="0.01" max="<?php echo $refundableAmount; ?>" value="<?php echo $refundableAmount; ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('pay_source_full', 'Paid from'); ?> <span class="text-danger">*</span></label>
                        <select name="from_account_id" class="form-select" required>
                            <?php
                            // Only asset accounts in the deposit's own currency: the
                            // ledger refuses cross-currency entries.
                            $refundSources = array_merge(
                                ledgerAccounts(['type' => 'asset', 'subtype' => 'bank', 'currency' => $deposit['currency']]),
                                ledgerAccounts(['type' => 'asset', 'subtype' => 'cash', 'currency' => $deposit['currency']]),
                                ledgerAccounts(['type' => 'asset', 'subtype' => 'float', 'currency' => $deposit['currency']])
                            );
                            foreach ($refundSources as $ra): ?>
                            <option value="<?php echo (int)$ra['id']; ?>"><?php echo htmlspecialchars($ra['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($refundSources)): ?>
                        <small class="text-danger"><?php _e('pay_no_source_account', 'Create a bank or cash account first, under Chart of Accounts.'); ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('refund_date', 'Refund Date'); ?> <span class="text-danger">*</span></label>
                        <input type="date" name="refund_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('refund_method', 'Refund Method'); ?> <span class="text-danger">*</span></label>
                        <select name="refund_method" class="form-select" required>
                            <option value="cash"><?php _e('cash', 'Cash'); ?></option>
                            <option value="bank_transfer"><?php _e('bank_transfer', 'Bank Transfer'); ?></option>
                            <option value="mobile_money"><?php _e('mobile_money', 'Mobile Money'); ?></option>
                            <option value="cheque"><?php _e('cheque', 'Cheque'); ?></option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('refund_reference', 'Reference'); ?></label>
                        <input type="text" name="refund_reference" class="form-control" placeholder="<?php _e('reference_placeholder', 'Transaction reference...'); ?>">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-sm btn-success" id="confirmRefundBtn">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('confirm_refund', 'Confirm Refund'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Forfeit Modal -->
<div class="modal fade" id="forfeitModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title"><i class="bi bi-x-octagon me-1"></i> <?php _e('forfeit_deposit', 'Forfeit Deposit'); ?></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('forfeit_confirm', 'Are you sure you want to forfeit this deposit? The tenant will not receive any refund.'); ?></p>
                <div class="alert alert-light border">
                    <strong><?php echo formatCurrency($deposit['amount'], $deposit['currency']); ?></strong> — <?php echo htmlspecialchars($deposit['tenant_name']); ?>
                </div>
                <form id="forfeitForm">
                    <input type="hidden" name="action" value="forfeit">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRF(); ?>">
                    <input type="hidden" name="deposit_id" value="<?php echo $id; ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('forfeit_reason', 'Reason for Forfeiture'); ?></label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="<?php _e('forfeit_reason_placeholder', 'Explain why the deposit is being forfeited...'); ?>"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-sm btn-danger" id="confirmForfeitBtn">
                    <i class="bi bi-x-octagon me-1"></i> <?php _e('confirm_forfeit', 'Forfeit Deposit'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo generateCSRF(); ?>';
const apiUrl = BASE_URL + 'api/deposits.php';

// Add Deduction
function showAddDeductionModal() {
    new bootstrap.Modal(document.getElementById('addDeductionModal')).show();
}

document.getElementById('confirmDeductionBtn').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> <?php echo __("processing", "Processing..."); ?>';

    const formData = new FormData(document.getElementById('deductionForm'));

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-plus me-1"></i> <?php echo __("add_deduction", "Add Deduction"); ?>';
            }
        })
        .catch(() => {
            alert('Request failed');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-plus me-1"></i> <?php echo __("add_deduction", "Add Deduction"); ?>';
        });
});

// Delete Deduction
function deleteDeduction(id) {
    if (!confirm('<?php echo __("delete_deduction_confirm", "Remove this deduction?"); ?>')) return;

    const formData = new FormData();
    formData.append('action', 'delete_deduction');
    formData.append('csrf_token', csrfToken);
    formData.append('id', id);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(() => alert('Request failed'));
}

// Process Refund
function showRefundModal() {
    new bootstrap.Modal(document.getElementById('refundModal')).show();
}

document.getElementById('confirmRefundBtn').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> <?php echo __("processing", "Processing..."); ?>';

    const formData = new FormData(document.getElementById('refundForm'));

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> <?php echo __("confirm_refund", "Confirm Refund"); ?>';
            }
        })
        .catch(() => {
            alert('Request failed');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> <?php echo __("confirm_refund", "Confirm Refund"); ?>';
        });
});

// Forfeit
function showForfeitModal() {
    new bootstrap.Modal(document.getElementById('forfeitModal')).show();
}

document.getElementById('confirmForfeitBtn').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> <?php echo __("processing", "Processing..."); ?>';

    const formData = new FormData(document.getElementById('forfeitForm'));

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-x-octagon me-1"></i> <?php echo __("confirm_forfeit", "Forfeit Deposit"); ?>';
            }
        })
        .catch(() => {
            alert('Request failed');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-x-octagon me-1"></i> <?php echo __("confirm_forfeit", "Forfeit Deposit"); ?>';
        });
});
</script>

<?php
// Deposit slips, bank advices, signed refund acknowledgements.
renderEntityDocuments('deposit', $id, [
    'title'      => __('doc_deposit_docs', 'Deposit documents'),
    'empty_text' => __('doc_deposit_none', 'No slips or acknowledgements attached to this deposit'),
]);
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
