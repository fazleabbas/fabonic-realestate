<?php
/**
 * FIS Real Estate — Inspection Deposit Deductions (11.5)
 * Link damaged/deteriorated inspection items to deposit deductions.
 * Auto-suggests deductions for items that worsened between move-in and move-out.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('insp_deductions', 'Inspection Deductions');

$subStatus = enforceSubscription();

if (!hasPermission('inspections', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$inspectionId = (int)($_GET['id'] ?? 0);
if (!$inspectionId) {
    setAlert(__('insp_id_required', 'Inspection ID required'), 'danger');
    redirect('modules/inspections/');
}

// Fetch inspection
$stmt = $conn->prepare("
    SELECT i.*, u.unit_number, u.unit_label,
           p.name AS property_name,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.id AS tenant_id,
           l.id AS lease_id
    FROM inspections i
    INNER JOIN units u ON i.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    LEFT JOIN tenants t ON i.tenant_id = t.id
    LEFT JOIN leases l ON i.lease_id = l.id
    WHERE i.id = :id
");
$stmt->execute(['id' => $inspectionId]);
$inspection = $stmt->fetch();

if (!$inspection) {
    setAlert(__('insp_not_found', 'Inspection not found'), 'danger');
    redirect('modules/inspections/');
}

if (!canAccessUnit($inspection['unit_id'])) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/inspections/');
}

// Get deposit for this lease
$deposit = null;
if ($inspection['lease_id']) {
    $depStmt = $conn->prepare("
        SELECT d.*,
            (SELECT COALESCE(SUM(dd.amount), 0) FROM deposit_deductions dd WHERE dd.deposit_id = d.id) AS total_deductions
        FROM deposits d
        WHERE d.lease_id = :lid AND d.status IN ('held', 'partially_refunded')
        LIMIT 1
    ");
    $depStmt->execute(['lid' => $inspection['lease_id']]);
    $deposit = $depStmt->fetch();
}

// Get items with poor/damaged/missing conditions
$damagedStmt = $conn->prepare("
    SELECT ii.* FROM inspection_items ii
    WHERE ii.inspection_id = :iid AND ii.`condition` IN ('poor', 'damaged', 'missing')
    ORDER BY ii.room, ii.item_name
");
$damagedStmt->execute(['iid' => $inspectionId]);
$damagedItems = $damagedStmt->fetchAll();

// Compare with move-in if this is a move-out inspection
$deteriorated = [];
$moveIn = null;
if ($inspection['inspection_type'] === 'move_out' && $inspection['lease_id']) {
    $miStmt = $conn->prepare("
        SELECT i.* FROM inspections i
        WHERE i.lease_id = :lid AND i.inspection_type = 'move_in' AND i.status = 'completed'
        ORDER BY i.inspection_date DESC LIMIT 1
    ");
    $miStmt->execute(['lid' => $inspection['lease_id']]);
    $moveIn = $miStmt->fetch();

    if ($moveIn) {
        $miItemStmt = $conn->prepare("SELECT * FROM inspection_items WHERE inspection_id = :iid");
        $miItemStmt->execute(['iid' => $moveIn['id']]);
        $miItems = $miItemStmt->fetchAll();

        $miIndex = [];
        foreach ($miItems as $mi) {
            $miIndex[$mi['room'] . '|' . $mi['item_name']] = $mi;
        }

        $condOrder = ['excellent' => 1, 'good' => 2, 'fair' => 3, 'poor' => 4, 'damaged' => 5, 'missing' => 6];

        // Get ALL move-out items and find deteriorated ones
        $moItemStmt = $conn->prepare("SELECT * FROM inspection_items WHERE inspection_id = :iid ORDER BY room, item_name");
        $moItemStmt->execute(['iid' => $inspectionId]);
        $moItems = $moItemStmt->fetchAll();

        foreach ($moItems as $mo) {
            $key = $mo['room'] . '|' . $mo['item_name'];
            if (isset($miIndex[$key])) {
                $miCond = $condOrder[$miIndex[$key]['condition']] ?? 2;
                $moCond = $condOrder[$mo['condition']] ?? 2;
                if ($moCond > $miCond) {
                    $deteriorated[] = [
                        'item_id' => $mo['id'],
                        'room' => $mo['room'],
                        'item_name' => $mo['item_name'],
                        'move_in_condition' => $miIndex[$key]['condition'],
                        'move_out_condition' => $mo['condition'],
                        'notes' => $mo['notes'],
                    ];
                }
            }
        }
    }
}

// Get existing deductions linked to this inspection's items
$existingDedStmt = $conn->prepare("
    SELECT dd.*, ii.room, ii.item_name, ii.`condition`
    FROM deposit_deductions dd
    INNER JOIN inspection_items ii ON dd.inspection_item_id = ii.id
    WHERE ii.inspection_id = :iid
    ORDER BY dd.created_at DESC
");
$existingDedStmt->execute(['iid' => $inspectionId]);
$existingDeductions = $existingDedStmt->fetchAll();

$condLabels = [
    'excellent' => __('cond_excellent', 'Excellent'),
    'good' => __('cond_good', 'Good'),
    'fair' => __('cond_fair', 'Fair'),
    'poor' => __('cond_poor', 'Poor'),
    'damaged' => __('cond_damaged', 'Damaged'),
    'missing' => __('cond_missing', 'Missing'),
];
$condColors = [
    'excellent' => 'success', 'good' => 'primary', 'fair' => 'info',
    'poor' => 'warning', 'damaged' => 'danger', 'missing' => 'dark'
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-cash-coin me-2"></i><?php _e('insp_deductions', 'Inspection Deductions'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/inspections/"><?php _e('inspections'); ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/inspections/view.php?id=<?php echo $inspectionId; ?>">#<?php echo $inspectionId; ?></a></li>
                <li class="breadcrumb-item active"><?php _e('deductions'); ?></li>
            </ol>
        </nav>
    </div>
    <a href="<?php echo BASE_URL; ?>modules/inspections/view.php?id=<?php echo $inspectionId; ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> <?php _e('back_to_inspection', 'Back to Inspection'); ?>
    </a>
</div>

<?php pageIntro('inspections.deductions'); ?>

<!-- Inspection Info -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span><i class="bi bi-person me-1"></i><strong><?php echo htmlspecialchars($inspection['tenant_name'] ?: '—'); ?></strong></span>
            <span><i class="bi bi-door-open me-1"></i><?php echo htmlspecialchars($inspection['unit_number']); ?></span>
            <span><i class="bi bi-buildings me-1"></i><?php echo htmlspecialchars($inspection['property_name']); ?></span>
            <span><i class="bi bi-calendar me-1"></i><?php echo formatDate($inspection['inspection_date']); ?></span>
            <span class="badge bg-<?php echo $inspection['inspection_type'] === 'move_out' ? 'danger' : ($inspection['inspection_type'] === 'move_in' ? 'success' : 'info'); ?>">
                <?php echo ucfirst(str_replace('_', '-', $inspection['inspection_type'])); ?>
            </span>
        </div>
    </div>
</div>

<!-- Deposit Info -->
<?php if ($deposit): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-2">
                <small class="text-muted"><?php _e('deposit_amount', 'Deposit Amount'); ?></small>
                <h5 class="mb-0 text-primary"><?php echo $deposit['currency']; ?> <?php echo number_format($deposit['amount'], 0); ?></h5>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-2">
                <small class="text-muted"><?php _e('total_deductions', 'Total Deductions'); ?></small>
                <h5 class="mb-0 text-danger"><?php echo $deposit['currency']; ?> <?php echo number_format($deposit['total_deductions'], 0); ?></h5>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-2">
                <small class="text-muted"><?php _e('insp_available', 'Available'); ?></small>
                <?php $available = (float)$deposit['amount'] - (float)$deposit['total_deductions']; ?>
                <h5 class="mb-0 text-success"><?php echo $deposit['currency']; ?> <?php echo number_format($available, 0); ?></h5>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-2">
                <small class="text-muted"><?php _e('status'); ?></small>
                <h5 class="mb-0">
                    <span class="badge bg-<?php echo $deposit['status'] === 'held' ? 'primary' : 'warning'; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $deposit['status'])); ?>
                    </span>
                </h5>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <?php _e('insp_no_deposit', 'No active deposit found for this lease. Deductions cannot be created without a deposit.'); ?>
    <?php if ($inspection['lease_id']): ?>
    <a href="<?php echo BASE_URL; ?>modules/deposits/create.php?lease_id=<?php echo $inspection['lease_id']; ?>" class="alert-link ms-2">
        <?php _e('insp_create_deposit', 'Create Deposit'); ?> &rarr;
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Existing Deductions from This Inspection -->
<?php if (!empty($existingDeductions)): ?>
<div class="card mb-3">
    <div class="card-header bg-light">
        <i class="bi bi-list-check me-1"></i> <?php _e('insp_existing_deductions', 'Existing Deductions from This Inspection'); ?>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th><?php _e('insp_room', 'Room'); ?></th>
                    <th><?php _e('insp_item', 'Item'); ?></th>
                    <th><?php _e('condition', 'Condition'); ?></th>
                    <th><?php _e('description'); ?></th>
                    <th class="text-end"><?php _e('amount'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $existTotal = 0; foreach ($existingDeductions as $ed): $existTotal += (float)$ed['amount']; ?>
                <tr>
                    <td><?php echo htmlspecialchars($ed['room']); ?></td>
                    <td><?php echo htmlspecialchars($ed['item_name']); ?></td>
                    <td><span class="badge bg-<?php echo $condColors[$ed['condition']] ?? 'secondary'; ?>"><?php echo $condLabels[$ed['condition']] ?? ucfirst($ed['condition']); ?></span></td>
                    <td><small><?php echo htmlspecialchars($ed['description']); ?></small></td>
                    <td class="text-end fw-semibold"><?php echo number_format($ed['amount'], 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-light">
                    <td colspan="4" class="text-end fw-bold"><?php _e('total'); ?>:</td>
                    <td class="text-end fw-bold"><?php echo number_format($existTotal, 0); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Deteriorated Items (Move-out vs Move-in Comparison) -->
<?php if (!empty($deteriorated)): ?>
<div class="card mb-3">
    <div class="card-header bg-danger text-white">
        <i class="bi bi-arrow-down-circle me-1"></i> <?php _e('insp_deteriorated_items', 'Deteriorated Items (Condition Worsened)'); ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0" id="deterioratedTable">
                <thead>
                    <tr>
                        <th style="width:30px;">
                            <?php if ($deposit): ?>
                            <input type="checkbox" id="checkAllDeteriorated" title="<?php _e('select_all'); ?>">
                            <?php endif; ?>
                        </th>
                        <th><?php _e('insp_room', 'Room'); ?></th>
                        <th><?php _e('insp_item', 'Item'); ?></th>
                        <th><?php _e('insp_movein_condition', 'Move-in'); ?></th>
                        <th>&rarr;</th>
                        <th><?php _e('insp_moveout_condition', 'Move-out'); ?></th>
                        <th><?php _e('notes'); ?></th>
                        <?php if ($deposit): ?>
                        <th style="width:120px;"><?php _e('insp_deduction_amount', 'Amount'); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deteriorated as $det): ?>
                    <tr>
                        <td>
                            <?php if ($deposit): ?>
                            <input type="checkbox" class="deduction-check" data-item-id="<?php echo $det['item_id']; ?>"
                                   data-room="<?php echo htmlspecialchars($det['room']); ?>"
                                   data-item="<?php echo htmlspecialchars($det['item_name']); ?>"
                                   data-condition="<?php echo $det['move_out_condition']; ?>">
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($det['room']); ?></td>
                        <td><?php echo htmlspecialchars($det['item_name']); ?></td>
                        <td><span class="badge bg-<?php echo $condColors[$det['move_in_condition']] ?? 'secondary'; ?>"><?php echo $condLabels[$det['move_in_condition']] ?? ucfirst($det['move_in_condition']); ?></span></td>
                        <td class="text-danger fw-bold">&darr;</td>
                        <td><span class="badge bg-<?php echo $condColors[$det['move_out_condition']] ?? 'secondary'; ?>"><?php echo $condLabels[$det['move_out_condition']] ?? ucfirst($det['move_out_condition']); ?></span></td>
                        <td><small class="text-muted"><?php echo htmlspecialchars($det['notes'] ?? ''); ?></small></td>
                        <?php if ($deposit): ?>
                        <td>
                            <input type="number" class="form-control form-control-sm deduction-amount" min="0" step="100" value="0"
                                   data-item-id="<?php echo $det['item_id']; ?>" placeholder="0">
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- All Damaged/Missing Items -->
<?php if (!empty($damagedItems)): ?>
<div class="card mb-3">
    <div class="card-header bg-warning text-dark">
        <i class="bi bi-exclamation-triangle me-1"></i> <?php _e('insp_damaged_items', 'Damaged / Missing Items'); ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0" id="damagedTable">
                <thead>
                    <tr>
                        <th style="width:30px;">
                            <?php if ($deposit): ?>
                            <input type="checkbox" id="checkAllDamaged" title="<?php _e('select_all'); ?>">
                            <?php endif; ?>
                        </th>
                        <th><?php _e('insp_room', 'Room'); ?></th>
                        <th><?php _e('insp_item', 'Item'); ?></th>
                        <th><?php _e('condition', 'Condition'); ?></th>
                        <th><?php _e('notes'); ?></th>
                        <?php if ($deposit): ?>
                        <th style="width:120px;"><?php _e('insp_deduction_amount', 'Amount'); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($damagedItems as $di): ?>
                    <tr>
                        <td>
                            <?php if ($deposit): ?>
                            <input type="checkbox" class="deduction-check-dmg" data-item-id="<?php echo $di['id']; ?>"
                                   data-room="<?php echo htmlspecialchars($di['room']); ?>"
                                   data-item="<?php echo htmlspecialchars($di['item_name']); ?>"
                                   data-condition="<?php echo $di['condition']; ?>">
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($di['room']); ?></td>
                        <td><?php echo htmlspecialchars($di['item_name']); ?></td>
                        <td><span class="badge bg-<?php echo $condColors[$di['condition']] ?? 'secondary'; ?>"><?php echo $condLabels[$di['condition']] ?? ucfirst($di['condition']); ?></span></td>
                        <td><small class="text-muted"><?php echo htmlspecialchars($di['notes'] ?? ''); ?></small></td>
                        <?php if ($deposit): ?>
                        <td>
                            <input type="number" class="form-control form-control-sm deduction-amount-dmg" min="0" step="100" value="0"
                                   data-item-id="<?php echo $di['id']; ?>" placeholder="0">
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- No Issues -->
<?php if (empty($deteriorated) && empty($damagedItems)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-check-circle" style="font-size:3rem;color:#198754;"></i>
        <h5 class="mt-3 text-muted"><?php _e('insp_no_issues', 'No damaged or deteriorated items found'); ?></h5>
        <p class="text-muted"><?php _e('insp_no_issues_desc', 'All items are in acceptable condition. No deductions needed.'); ?></p>
    </div>
</div>
<?php endif; ?>

<!-- Submit Deductions Bar -->
<?php if ($deposit && (!empty($deteriorated) || !empty($damagedItems))): ?>
<div class="card mt-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <span class="fw-semibold"><?php _e('insp_selected_deductions', 'Selected Deductions'); ?>:</span>
                <span id="selectedCount" class="badge bg-secondary ms-1">0</span>
                <span class="text-muted ms-2"><?php _e('total'); ?>:</span>
                <strong id="selectedTotal" class="text-danger ms-1"><?php echo $deposit['currency']; ?> 0</strong>
                <small class="text-muted ms-2">(<?php _e('insp_available', 'Available'); ?>: <?php echo $deposit['currency']; ?> <?php echo number_format($available, 0); ?>)</small>
            </div>
            <button type="button" class="btn btn-danger" id="submitDeductionsBtn" disabled>
                <i class="bi bi-cash-coin me-1"></i> <?php _e('insp_create_deductions', 'Create Deductions'); ?>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($deposit && (!empty($deteriorated) || !empty($damagedItems))): ?>
<script>
const csrfToken = '<?php echo generateCSRF(); ?>';
const apiUrl = '<?php echo BASE_URL; ?>api/inspections.php';
const depositId = <?php echo $deposit['id']; ?>;
const availableBalance = <?php echo $available; ?>;
const currency = '<?php echo $deposit['currency']; ?>';

// Check all deteriorated
const checkAllDet = document.getElementById('checkAllDeteriorated');
if (checkAllDet) {
    checkAllDet.addEventListener('change', function() {
        document.querySelectorAll('.deduction-check').forEach(cb => {
            cb.checked = this.checked;
        });
        updateSummary();
    });
}

// Check all damaged
const checkAllDmg = document.getElementById('checkAllDamaged');
if (checkAllDmg) {
    checkAllDmg.addEventListener('change', function() {
        document.querySelectorAll('.deduction-check-dmg').forEach(cb => {
            cb.checked = this.checked;
        });
        updateSummary();
    });
}

// Listen to checkbox and amount changes
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('deduction-check') ||
        e.target.classList.contains('deduction-check-dmg') ||
        e.target.classList.contains('deduction-amount') ||
        e.target.classList.contains('deduction-amount-dmg')) {
        updateSummary();
    }
});
document.addEventListener('input', function(e) {
    if (e.target.classList.contains('deduction-amount') ||
        e.target.classList.contains('deduction-amount-dmg')) {
        updateSummary();
    }
});

function updateSummary() {
    let count = 0;
    let total = 0;

    // Deteriorated items
    document.querySelectorAll('.deduction-check:checked').forEach(cb => {
        const itemId = cb.dataset.itemId;
        const amountInput = document.querySelector('.deduction-amount[data-item-id="' + itemId + '"]');
        if (amountInput) {
            const amt = parseFloat(amountInput.value) || 0;
            if (amt > 0) {
                count++;
                total += amt;
            }
        }
    });

    // Damaged items
    document.querySelectorAll('.deduction-check-dmg:checked').forEach(cb => {
        const itemId = cb.dataset.itemId;
        const amountInput = document.querySelector('.deduction-amount-dmg[data-item-id="' + itemId + '"]');
        if (amountInput) {
            const amt = parseFloat(amountInput.value) || 0;
            if (amt > 0) {
                count++;
                total += amt;
            }
        }
    });

    document.getElementById('selectedCount').textContent = count;
    document.getElementById('selectedTotal').textContent = currency + ' ' + total.toLocaleString();
    document.getElementById('submitDeductionsBtn').disabled = count === 0;

    // Warn if exceeds
    const totalEl = document.getElementById('selectedTotal');
    if (total > availableBalance) {
        totalEl.classList.add('text-danger');
        totalEl.classList.remove('text-success');
    } else {
        totalEl.classList.remove('text-danger');
        totalEl.classList.add('text-success');
    }
}

// Submit deductions
document.getElementById('submitDeductionsBtn').addEventListener('click', function() {
    const deductions = [];

    // Deteriorated
    document.querySelectorAll('.deduction-check:checked').forEach(cb => {
        const itemId = cb.dataset.itemId;
        const room = cb.dataset.room;
        const item = cb.dataset.item;
        const condition = cb.dataset.condition;
        const amountInput = document.querySelector('.deduction-amount[data-item-id="' + itemId + '"]');
        const amt = parseFloat(amountInput ? amountInput.value : 0) || 0;
        if (amt > 0) {
            deductions.push({
                inspection_item_id: itemId,
                description: room + ' - ' + item + ' (' + condition + ')',
                amount: amt
            });
        }
    });

    // Damaged
    document.querySelectorAll('.deduction-check-dmg:checked').forEach(cb => {
        const itemId = cb.dataset.itemId;
        const room = cb.dataset.room;
        const item = cb.dataset.item;
        const condition = cb.dataset.condition;
        const amountInput = document.querySelector('.deduction-amount-dmg[data-item-id="' + itemId + '"]');
        const amt = parseFloat(amountInput ? amountInput.value : 0) || 0;
        if (amt > 0) {
            // Check not already in deteriorated list
            const exists = deductions.find(d => d.inspection_item_id == itemId);
            if (!exists) {
                deductions.push({
                    inspection_item_id: itemId,
                    description: room + ' - ' + item + ' (' + condition + ')',
                    amount: amt
                });
            }
        }
    });

    if (deductions.length === 0) {
        alert('<?php echo __('insp_no_deductions_selected', 'No deductions selected or amounts are zero.'); ?>');
        return;
    }

    const totalAmt = deductions.reduce((sum, d) => sum + d.amount, 0);
    if (totalAmt > availableBalance) {
        alert('<?php echo __('insp_deductions_exceed', 'Total deductions exceed available deposit balance.'); ?>');
        return;
    }

    if (!confirm('<?php echo __('insp_confirm_deductions', 'Create these deductions? This will be deducted from the tenant deposit.'); ?>')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'create_deductions');
    formData.append('csrf_token', csrfToken);
    formData.append('deposit_id', depositId);
    deductions.forEach((d, i) => {
        formData.append('deductions[' + i + '][inspection_item_id]', d.inspection_item_id);
        formData.append('deductions[' + i + '][description]', d.description);
        formData.append('deductions[' + i + '][amount]', d.amount);
    });

    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> <?php echo __('processing'); ?>';

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Error');
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-cash-coin me-1"></i> <?php echo __('insp_create_deductions', 'Create Deductions'); ?>';
            }
        })
        .catch(() => {
            alert('Request failed');
            this.disabled = false;
            this.innerHTML = '<i class="bi bi-cash-coin me-1"></i> <?php echo __('insp_create_deductions', 'Create Deductions'); ?>';
        });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
