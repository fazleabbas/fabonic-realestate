<?php
/**
 * FIS Real Estate — View Lease (8.3 + 8.4 + 8.5)
 * Lease detail page with:
 * - Info bar + stat cards
 * - Renewal alert banner (8.4)
 * - Renewal workflow (8.4)
 * - Termination modal (8.5)
 * - Generate PDF / print button (8.3)
 * - Renewal chain history (8.4)
 * - Activity log
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

$subStatus = enforceSubscription();

if (!hasPermission('leases', 'view')) {
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
    SELECT l.*, u.unit_number, u.unit_label, u.unit_type, u.id AS unit_id_val,
           p.name AS property_name, p.id AS property_id_val,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
           t.phone AS tenant_phone, t.email AS tenant_email, t.id AS tenant_id_val
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

$pageTitle = __('lease', 'Lease') . ' #' . $id;

// Check if expiring soon
$isExpiring = false;
$daysUntilExpiry = null;
if ($lease['status'] === 'active') {
    $daysUntilExpiry = (int)((strtotime($lease['end_date']) - time()) / 86400);
    $isExpiring = $daysUntilExpiry <= (int)$lease['renewal_alert_days'];
}

// Renewal chain — walk backward
$chain = [];
$currentId = $id;
$visited = [];
while ($currentId && !in_array($currentId, $visited)) {
    $visited[] = $currentId;
    $chainStmt = $conn->prepare("SELECT id, previous_lease_id, start_date, end_date, rent_amount, currency, status FROM leases WHERE id = :id");
    $chainStmt->execute(['id' => $currentId]);
    $row = $chainStmt->fetch();
    if (!$row) break;
    array_unshift($chain, $row);
    $currentId = $row['previous_lease_id'];
}
// Walk forward
$currentId = $id;
$fwdVisited = [$id];
while (true) {
    $fwdStmt = $conn->prepare("SELECT id, previous_lease_id, start_date, end_date, rent_amount, currency, status FROM leases WHERE previous_lease_id = :id");
    $fwdStmt->execute(['id' => $currentId]);
    $row = $fwdStmt->fetch();
    if (!$row || in_array($row['id'], $fwdVisited)) break;
    $fwdVisited[] = $row['id'];
    $chain[] = $row;
    $currentId = $row['id'];
}
// De-duplicate
$seen = [];
$uniqueChain = [];
foreach ($chain as $item) {
    if (!in_array($item['id'], $seen)) {
        $seen[] = $item['id'];
        $uniqueChain[] = $item;
    }
}

// Payments for this lease
$payStmt = $conn->prepare("
    SELECT py.*, u.unit_number
    FROM payments py
    INNER JOIN units u ON py.unit_id = u.id
    WHERE py.lease_id = :lid
    ORDER BY py.payment_date DESC
    LIMIT 20
");
$payStmt->execute(['lid' => $id]);
$payments = $payStmt->fetchAll();

$totalPaid = 0;
foreach ($payments as $py) {
    if ($py['status'] === 'recorded') $totalPaid += (float)$py['amount'];
}

// Lease templates (for print dropdown)
$tplStmt = $conn->query("SELECT id, name, is_default FROM lease_templates ORDER BY is_default DESC, name");
$templates = $tplStmt->fetchAll();

// Activity log
$historyStmt = $conn->prepare("
    SELECT al.*, u.full_name AS user_name
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.entity_type = 'lease'
      AND al.entity_id = :lid
    ORDER BY al.created_at DESC
    LIMIT 15
");
$historyStmt->execute(['lid' => $id]);
$activityHistory = $historyStmt->fetchAll();

$statusColors = ['active' => 'success', 'expired' => 'secondary', 'terminated' => 'danger', 'renewed' => 'info'];
$statusLabels = [
    'active' => __('active', 'Active'),
    'expired' => __('expired', 'Expired'),
    'terminated' => __('terminated', 'Terminated'),
    'renewed' => __('renewed', 'Renewed'),
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-file-earmark-text me-2"></i><?php _e('lease', 'Lease'); ?> #<?php echo $id; ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/leases/"><?php _e('leases'); ?></a></li>
                <li class="breadcrumb-item active">#<?php echo $id; ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (!empty($templates)): ?>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-printer me-1"></i> <?php _e('generate_document', 'Generate Document'); ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php foreach ($templates as $tpl): ?>
                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/leases/print.php?lease_id=<?php echo $id; ?>&template_id=<?php echo $tpl['id']; ?>" target="_blank">
                    <?php echo htmlspecialchars($tpl['name']); ?><?php echo $tpl['is_default'] ? ' *' : ''; ?>
                </a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php else: ?>
        <a href="<?php echo BASE_URL; ?>modules/leases/print.php?lease_id=<?php echo $id; ?>" target="_blank" class="btn btn-sm btn-outline-success">
            <i class="bi bi-printer me-1"></i> <?php _e('print_lease', 'Print Lease'); ?>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('leases', 'edit') && isLeaseEditable($lease)): ?>
        <a href="<?php echo BASE_URL; ?>modules/leases/edit.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-pencil me-1"></i> <?php _e('edit'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('leases.view'); ?>

<!-- Expiring Soon Alert (8.4) -->
<?php if ($isExpiring && $lease['status'] === 'active'): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 flex-wrap">
    <i class="bi bi-exclamation-triangle-fill" style="font-size:1.2rem;"></i>
    <div class="flex-grow-1">
        <strong><?php _e('lease_expiring_soon', 'Lease Expiring Soon!'); ?></strong>
        <?php if ($daysUntilExpiry > 0): ?>
        <?php echo sprintf(__('expires_in_days', 'This lease expires in %d days (on %s).'), $daysUntilExpiry, formatDate($lease['end_date'])); ?>
        <?php elseif ($daysUntilExpiry === 0): ?>
        <?php _e('expires_today', 'This lease expires today!'); ?>
        <?php else: ?>
        <?php echo sprintf(__('expired_days_ago', 'This lease expired %d days ago (on %s).'), abs($daysUntilExpiry), formatDate($lease['end_date'])); ?>
        <?php endif; ?>
    </div>
    <?php if (hasPermission('leases', 'create')): ?>
    <button class="btn btn-warning btn-sm" onclick="showRenewModal()">
        <i class="bi bi-arrow-repeat me-1"></i> <?php _e('renew_lease', 'Renew Lease'); ?>
    </button>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Termination Details (8.5) -->
<?php if ($lease['status'] === 'terminated'): ?>
<div class="alert alert-danger d-flex align-items-start gap-2">
    <i class="bi bi-x-octagon-fill" style="font-size:1.2rem;margin-top:2px;"></i>
    <div>
        <strong><?php _e('lease_terminated', 'Lease Terminated'); ?></strong><br>
        <small><?php _e('terminated_on', 'Terminated on'); ?>: <?php echo formatDate($lease['terminated_date']); ?></small>
        <?php if ($lease['terminated_reason']): ?>
        <br><small><?php _e('reason', 'Reason'); ?>: <?php echo htmlspecialchars($lease['terminated_reason']); ?></small>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Lease Info Bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span class="badge bg-<?php echo $statusColors[$lease['status']] ?? 'secondary'; ?>" style="font-size:0.85rem;">
                <?php echo $statusLabels[$lease['status']] ?? ucfirst($lease['status']); ?>
            </span>
            <span><i class="bi bi-person me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/tenants/view.php?id=<?php echo $lease['tenant_id_val']; ?>" class="text-decoration-none">
                    <?php echo htmlspecialchars($lease['tenant_name']); ?>
                </a>
            </span>
            <span><i class="bi bi-door-open me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/units/view.php?id=<?php echo $lease['unit_id_val']; ?>" class="text-decoration-none">
                    <?php echo htmlspecialchars($lease['unit_number']); ?>
                </a>
            </span>
            <span><i class="bi bi-buildings me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $lease['property_id_val']; ?>" class="text-decoration-none">
                    <?php echo htmlspecialchars($lease['property_name']); ?>
                </a>
            </span>
            <span><i class="bi bi-calendar-range me-1"></i> <?php echo formatDate($lease['start_date']); ?> — <?php echo formatDate($lease['end_date']); ?></span>
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
                <div class="stat-card-value"><?php echo formatCurrency($lease['rent_amount'], $lease['currency']); ?></div>
                <div class="stat-card-label"><?php echo paymentFrequencyLabel($lease['payment_frequency']); ?> <?php _e('rent', 'Rent'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;">
                <i class="bi bi-safe"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($lease['deposit_amount'], $lease['deposit_currency']); ?></div>
                <div class="stat-card-label"><?php _e('deposit', 'Deposit'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dcfce7;color:#166534;">
                <i class="bi bi-cash-coin"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($totalPaid, $lease['currency']); ?></div>
                <div class="stat-card-label"><?php _e('total_paid', 'Total Paid'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:<?php echo $daysUntilExpiry !== null && $daysUntilExpiry <= 30 ? '#fef3c7' : '#f3e8ff'; ?>;color:<?php echo $daysUntilExpiry !== null && $daysUntilExpiry <= 30 ? '#92400e' : '#6b21a8'; ?>;">
                <i class="bi bi-calendar-check"></i>
            </div>
            <div>
                <div class="stat-card-value">
                    <?php if ($lease['status'] === 'active' && $daysUntilExpiry !== null): ?>
                    <?php echo $daysUntilExpiry; ?> <?php _e('days', 'days'); ?>
                    <?php else: ?>
                    —
                    <?php endif; ?>
                </div>
                <div class="stat-card-label"><?php _e('until_expiry', 'Until Expiry'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Row -->
<div class="row g-3 mb-4">
    <!-- Lease Details -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> <?php _e('lease_details', 'Lease Details'); ?></div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted" style="width:140px;"><?php _e('unit', 'Unit'); ?></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($lease['unit_number']); ?><?php echo $lease['unit_label'] ? ' (' . htmlspecialchars($lease['unit_label']) . ')' : ''; ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('property'); ?></td>
                        <td><?php echo htmlspecialchars($lease['property_name']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('tenant'); ?></td>
                        <td><?php echo htmlspecialchars($lease['tenant_name']); ?> (<?php echo htmlspecialchars($lease['tenant_phone']); ?>)</td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('start_date', 'Start'); ?></td>
                        <td><?php echo formatDate($lease['start_date']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('end_date', 'End'); ?></td>
                        <td><?php echo formatDate($lease['end_date']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('rent_amount'); ?></td>
                        <td class="fw-semibold"><?php echo formatCurrency($lease['rent_amount'], $lease['currency']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('deposit_amount'); ?></td>
                        <td><?php echo formatCurrency($lease['deposit_amount'], $lease['deposit_currency']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('frequency'); ?></td>
                        <td><?php echo paymentFrequencyLabel($lease['payment_frequency']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('due_day', 'Due Day'); ?></td>
                        <td><?php echo $lease['payment_due_day']; ?><?php echo __('of_each_period', ' of each period'); ?></td>
                    </tr>
                    <?php if (!empty($lease['billed_through'])): ?>
                    <tr>
                        <td class="text-muted"><?php _e('lease_bill_from', 'Bill automatically from'); ?></td>
                        <td><?php echo formatDate(leaseBillFromValue($lease['billed_through'])); ?>
                            <div class="small text-muted"><?php _e('lease_bill_from_view_help', 'Earlier rent was billed before this system, as opening balances.'); ?></div></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted"><?php _e('renewal_alert', 'Renewal Alert'); ?></td>
                        <td><?php echo $lease['renewal_alert_days']; ?> <?php _e('days_before_expiry', 'days before expiry'); ?></td>
                    </tr>
                    <?php if ($lease['previous_lease_id']): ?>
                    <tr>
                        <td class="text-muted"><?php _e('previous_lease', 'Previous Lease'); ?></td>
                        <td><a href="?id=<?php echo $lease['previous_lease_id']; ?>" class="text-decoration-none">#<?php echo $lease['previous_lease_id']; ?></a></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Actions Panel -->
    <div class="col-12 col-lg-6">
        <!-- Terms -->
        <?php if ($lease['terms']): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-card-text me-1"></i> <?php _e('additional_terms', 'Additional Terms'); ?></div>
            <div class="card-body">
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($lease['terms'])); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <?php if ($lease['status'] === 'active' && hasPermission('leases', 'edit')): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-lightning me-1"></i> <?php _e('lease_actions', 'Lease Actions'); ?></div>
            <div class="card-body">
                <div class="d-flex gap-2 flex-wrap">
                    <?php if (hasPermission('leases', 'create')): ?>
                    <button class="btn btn-info btn-sm" onclick="showRenewModal()">
                        <i class="bi bi-arrow-repeat me-1"></i> <?php _e('renew_lease', 'Renew Lease'); ?>
                    </button>
                    <?php endif; ?>
                    <button class="btn btn-danger btn-sm" onclick="showTerminateModal()">
                        <i class="bi bi-x-octagon me-1"></i> <?php _e('terminate_lease', 'Terminate Lease'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Renewal Chain (8.4) -->
        <?php if (count($uniqueChain) > 1): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-diagram-3 me-1"></i> <?php _e('renewal_history', 'Renewal History'); ?></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><?php _e('period'); ?></th>
                                <th class="text-end"><?php _e('rent'); ?></th>
                                <th><?php _e('status'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($uniqueChain as $ch): ?>
                            <tr class="<?php echo $ch['id'] == $id ? 'table-active' : ''; ?>">
                                <td>
                                    <?php if ($ch['id'] == $id): ?>
                                    <strong>#<?php echo $ch['id']; ?></strong>
                                    <?php else: ?>
                                    <a href="?id=<?php echo $ch['id']; ?>" class="text-decoration-none">#<?php echo $ch['id']; ?></a>
                                    <?php endif; ?>
                                </td>
                                <td><small><?php echo formatDate($ch['start_date']); ?> — <?php echo formatDate($ch['end_date']); ?></small></td>
                                <td class="text-end"><?php echo formatCurrency($ch['rent_amount'], $ch['currency']); ?></td>
                                <td><span class="badge bg-<?php echo $statusColors[$ch['status']] ?? 'secondary'; ?>" style="font-size:0.7rem;"><?php echo ucfirst($ch['status']); ?></span></td>
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

<!-- Tabs: Payments + Activity -->
<div class="card mb-4">
    <div class="card-header p-0">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabPayments" type="button">
                    <i class="bi bi-cash-coin me-1"></i> <?php _e('payments'); ?> (<?php echo count($payments); ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabActivity" type="button">
                    <i class="bi bi-clock-history me-1"></i> <?php _e('activity', 'Activity'); ?>
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content">
            <!-- Payments Tab -->
            <div class="tab-pane fade show active" id="tabPayments">
                <?php if (empty($payments)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-cash-coin" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('no_payments_found', 'No payment records found'); ?></p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th><?php _e('date'); ?></th>
                                <th class="text-end"><?php _e('amount'); ?></th>
                                <th><?php _e('method', 'Method'); ?></th>
                                <th><?php _e('period'); ?></th>
                                <th><?php _e('reference', 'Ref'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $py): ?>
                            <tr>
                                <td><?php echo formatDate($py['payment_date']); ?></td>
                                <td class="text-end fw-semibold"><?php echo formatCurrency($py['amount'], $py['currency']); ?></td>
                                <td><small><?php echo ucfirst(str_replace('_', ' ', $py['payment_method'])); ?></small></td>
                                <td><small><?php echo $py['period_from'] ? formatDate($py['period_from']) . ' - ' . formatDate($py['period_to']) : '-'; ?></small></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($py['reference_number'] ?? '-'); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Activity Tab -->
            <div class="tab-pane fade" id="tabActivity">
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
                            <?php if (!empty($details['terminated_reason'])): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($details['terminated_reason']); ?></small>
                            <?php endif; ?>
                            <?php elseif (!empty($details['renewal_of'])): ?>
                            <br><small><?php echo __('renewed_from', 'Renewed from lease'); ?> #<?php echo $details['renewal_of']; ?></small>
                            <?php else: ?>
                            <br><small><?php echo ucfirst($entry['action']); ?></small>
                            <?php endif; ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($entry['user_name'] ?? 'System'); ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Terminate Modal (8.5) -->
<div class="modal fade" id="terminateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title"><i class="bi bi-x-octagon me-1"></i> <?php _e('terminate_lease', 'Terminate Lease'); ?></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('terminate_confirm', 'Are you sure you want to terminate this lease? The unit will be set back to vacant.'); ?></p>
                <form id="terminateForm">
                    <input type="hidden" name="action" value="terminate">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRF(); ?>">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('termination_date', 'Termination Date'); ?> <span class="text-danger">*</span></label>
                        <input type="date" name="terminated_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('termination_reason', 'Reason for Termination'); ?></label>
                        <textarea name="terminated_reason" class="form-control" rows="3" placeholder="<?php _e('termination_reason_placeholder', 'Optional: explain why this lease is being terminated...'); ?>"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-sm btn-danger" id="confirmTerminateBtn">
                    <i class="bi bi-x-octagon me-1"></i> <?php _e('confirm_terminate', 'Terminate Lease'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Renew Modal (8.4) -->
<div class="modal fade" id="renewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h6 class="modal-title"><i class="bi bi-arrow-repeat me-1"></i> <?php _e('renew_lease', 'Renew Lease'); ?></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small"><?php _e('renew_info', 'This creates a new lease linked to the current one. The current lease status will change to "renewed".'); ?></p>
                <form id="renewForm">
                    <input type="hidden" name="action" value="renew">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRF(); ?>">
                    <input type="hidden" name="old_lease_id" value="<?php echo $id; ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php _e('start_date', 'Start Date'); ?> <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control" value="<?php echo date('Y-m-d', strtotime($lease['end_date'] . ' +1 day')); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php _e('end_date', 'End Date'); ?> <span class="text-danger">*</span></label>
                            <?php
                            $newEnd = date('Y-m-d', strtotime($lease['end_date'] . ' +1 year'));
                            ?>
                            <input type="date" name="end_date" class="form-control" value="<?php echo $newEnd; ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php _e('rent_amount'); ?> <span class="text-danger">*</span></label>
                            <input type="number" name="rent_amount" class="form-control" step="0.01" min="0" value="<?php echo $lease['rent_amount']; ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold"><?php _e('currency'); ?></label>
                            <select name="currency" class="form-select">
                                <option value="TZS" <?php echo $lease['currency'] === 'TZS' ? 'selected' : ''; ?>>TZS</option>
                                <option value="USD" <?php echo $lease['currency'] === 'USD' ? 'selected' : ''; ?>>USD</option>
                                <option value="KES" <?php echo $lease['currency'] === 'KES' ? 'selected' : ''; ?>>KES</option>
                                <option value="UGX" <?php echo $lease['currency'] === 'UGX' ? 'selected' : ''; ?>>UGX</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php _e('deposit_amount'); ?></label>
                            <input type="number" name="deposit_amount" class="form-control" step="0.01" min="0" value="<?php echo $lease['deposit_amount']; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold"><?php _e('deposit_currency', 'Dep. Currency'); ?></label>
                            <select name="deposit_currency" class="form-select">
                                <option value="TZS" <?php echo $lease['deposit_currency'] === 'TZS' ? 'selected' : ''; ?>>TZS</option>
                                <option value="USD" <?php echo $lease['deposit_currency'] === 'USD' ? 'selected' : ''; ?>>USD</option>
                                <option value="KES" <?php echo $lease['deposit_currency'] === 'KES' ? 'selected' : ''; ?>>KES</option>
                                <option value="UGX" <?php echo $lease['deposit_currency'] === 'UGX' ? 'selected' : ''; ?>>UGX</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php _e('payment_frequency', 'Frequency'); ?></label>
                            <select name="payment_frequency" class="form-select">
                                <option value="monthly" <?php echo $lease['payment_frequency'] === 'monthly' ? 'selected' : ''; ?>><?php _e('monthly'); ?></option>
                                <option value="quarterly" <?php echo $lease['payment_frequency'] === 'quarterly' ? 'selected' : ''; ?>><?php _e('quarterly'); ?></option>
                                <option value="half_yearly" <?php echo $lease['payment_frequency'] === 'half_yearly' ? 'selected' : ''; ?>><?php _e('half_yearly', 'Half Yearly'); ?></option>
                                <option value="yearly" <?php echo $lease['payment_frequency'] === 'yearly' ? 'selected' : ''; ?>><?php _e('yearly'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php _e('payment_due_day', 'Due Day'); ?></label>
                            <input type="number" name="payment_due_day" class="form-control" min="1" max="28" value="<?php echo $lease['payment_due_day']; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php _e('renewal_alert_days', 'Alert Days'); ?></label>
                            <input type="number" name="renewal_alert_days" class="form-control" min="0" max="365" value="<?php echo $lease['renewal_alert_days']; ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?php _e('additional_terms', 'Terms'); ?></label>
                            <textarea name="terms" class="form-control" rows="2"><?php echo htmlspecialchars($lease['terms'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-sm btn-info text-white" id="confirmRenewBtn">
                    <i class="bi bi-arrow-repeat me-1"></i> <?php _e('confirm_renew', 'Renew Lease'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo generateCSRF(); ?>';
const apiUrl = BASE_URL + 'api/leases.php';

// Show modals
function showTerminateModal() {
    new bootstrap.Modal(document.getElementById('terminateModal')).show();
}
function showRenewModal() {
    new bootstrap.Modal(document.getElementById('renewModal')).show();
}

// Terminate lease (8.5)
document.getElementById('confirmTerminateBtn').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> <?php echo __("processing", "Processing..."); ?>';

    const formData = new FormData(document.getElementById('terminateForm'));

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-x-octagon me-1"></i> <?php echo __("confirm_terminate", "Terminate Lease"); ?>';
            }
        })
        .catch(() => {
            alert('Request failed');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-x-octagon me-1"></i> <?php echo __("confirm_terminate", "Terminate Lease"); ?>';
        });
});

// Renew lease (8.4)
document.getElementById('confirmRenewBtn').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> <?php echo __("processing", "Processing..."); ?>';

    const formData = new FormData(document.getElementById('renewForm'));

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = BASE_URL + 'modules/leases/view.php?id=' + data.id;
            } else {
                alert(data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> <?php echo __("confirm_renew", "Renew Lease"); ?>';
            }
        })
        .catch(() => {
            alert('Request failed');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> <?php echo __("confirm_renew", "Renew Lease"); ?>';
        });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
