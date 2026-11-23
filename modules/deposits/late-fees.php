<?php
/**
 * FIS Real Estate — Late Fees List (10.5)
 * List view for late fees with filters, waive option, manual apply trigger.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('late_fees', 'Late Fees');

$subStatus = enforceSubscription();

if (!hasPermission('deposits', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Get filter values
$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterProperty = (int)($_GET['property_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = RECORDS_PER_PAGE;

// Build WHERE clause
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(CONCAT(t.first_name, ' ', t.last_name) LIKE :search OR u.unit_number LIKE :search2 OR p.name LIKE :search3)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
}
if ($filterStatus !== '') {
    $where[] = "lf.status = :status";
    $params['status'] = $filterStatus;
}
if ($filterProperty > 0) {
    $where[] = "u.property_id = :property_id";
    $params['property_id'] = $filterProperty;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Property-based access scoping (query JOINs units u)
$scopeSql = propertyScopeSql('u.property_id');
if ($scopeSql !== '') {
    $whereSql = ($whereSql !== '' ? $whereSql : 'WHERE 1=1') . $scopeSql;
}

// Count total
$countStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM late_fees lf
    INNER JOIN leases l ON lf.lease_id = l.id
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON lf.tenant_id = t.id
    {$whereSql}
");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$pagination = paginate($totalRecords, $page, $perPage);

// Sum pending
$pendingSql = "SELECT COALESCE(SUM(lf.amount),0)
    FROM late_fees lf
    INNER JOIN leases l ON lf.lease_id = l.id
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON lf.tenant_id = t.id
    {$whereSql}";
if ($whereSql) {
    $pendingSql .= " AND lf.status = 'pending'";
} else {
    $pendingSql .= " WHERE lf.status = 'pending'";
}
$pendingStmt = $conn->prepare($pendingSql);
$pendingStmt->execute($params);
$pendingTotal = (float) $pendingStmt->fetchColumn();

// Fetch late fees
$sql = "SELECT lf.*, u.unit_number, p.name AS property_name,
        CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
        l.rent_amount AS lease_rent, l.currency AS lease_currency,
        wu.full_name AS waived_by_name
        FROM late_fees lf
        INNER JOIN leases l ON lf.lease_id = l.id
        INNER JOIN units u ON l.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        INNER JOIN tenants t ON lf.tenant_id = t.id
        LEFT JOIN users wu ON lf.waived_by = wu.id
        {$whereSql}
        ORDER BY lf.fee_date DESC, lf.created_at DESC
        LIMIT :offset, :limit";

$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->execute();
$lateFees = $stmt->fetchAll();

// Properties list for filter dropdown
$propStmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name");
$properties = $propStmt->fetchAll();

// Late fee settings
$lateEnabled = getSetting('late_fee_enabled', '0') === '1';

$feeStatusColors = ['pending' => 'warning', 'paid' => 'success', 'waived' => 'secondary'];
$feeStatusLabels = [
    'pending' => __('fee_pending', 'Pending'),
    'paid' => __('fee_paid', 'Paid'),
    'waived' => __('fee_waived', 'Waived'),
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-clock-history me-2"></i><?php _e('late_fees', 'Late Fees'); ?></h4>
        <p class="text-muted mb-0"><?php _e('manage_late_fees', 'Manage late fee charges'); ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?php echo BASE_URL; ?>modules/deposits/late-fee-settings.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-gear me-1"></i> <?php _e('late_fee_settings', 'Settings'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/deposits/" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-safe me-1"></i> <?php _e('deposits'); ?>
        </a>
        <?php if (hasPermission('deposits', 'edit') && $lateEnabled): ?>
        <button class="btn btn-warning btn-sm" id="applyFeesBtn" onclick="applyLateFees()">
            <i class="bi bi-lightning me-1"></i> <?php _e('apply_late_fees', 'Apply Late Fees Now'); ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('deposits.late-fees'); ?>

<!-- Late Fee Status Banner -->
<?php if (!$lateEnabled): ?>
<div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <div>
        <strong><?php _e('late_fees_disabled', 'Late fees are currently disabled.'); ?></strong>
        <?php if (getUserData('role_name') === 'Admin'): ?>
        <a href="<?php echo BASE_URL; ?>modules/deposits/late-fee-settings.php" class="ms-2"><?php _e('enable_in_settings', 'Enable in settings'); ?></a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <input type="text" class="form-control form-control-sm" name="search" placeholder="<?php _e('search_late_fee_placeholder', 'Search tenant, unit, property...'); ?>" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="property_id" class="form-select form-select-sm">
                    <option value=""><?php _e('all_properties', 'All Properties'); ?></option>
                    <?php foreach ($properties as $prop): ?>
                    <option value="<?php echo $prop['id']; ?>" <?php echo $filterProperty == $prop['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prop['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all_statuses', 'All Statuses'); ?></option>
                    <?php foreach ($feeStatusLabels as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo $filterStatus === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-5 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/deposits/late-fees.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear', 'Clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Late Fees List -->
<?php if (empty($lateFees)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-clock-history" style="font-size:3rem;color:#ccc;"></i>
        <h5 class="mt-3 text-muted"><?php _e('no_late_fees', 'No late fee records found'); ?></h5>
    </div>
</div>
<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <small class="text-muted">
        <?php echo __('showing', 'Showing'); ?> <?php echo $pagination['offset'] + 1; ?>-<?php echo min($pagination['offset'] + $perPage, $totalRecords); ?>
        <?php echo __('of', 'of'); ?> <?php echo $totalRecords; ?> <?php _e('late_fees', 'late fees'); ?>
    </small>
    <small class="fw-semibold text-warning">
        <?php _e('pending_fees', 'Pending Fees'); ?>: <?php echo formatCurrency($pendingTotal); ?>
    </small>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th><?php _e('date'); ?></th>
                    <th><?php _e('tenant'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('unit_number', 'Unit'); ?></th>
                    <th class="d-none d-lg-table-cell"><?php _e('period', 'Period'); ?></th>
                    <th class="text-end"><?php _e('amount'); ?></th>
                    <th><?php _e('status'); ?></th>
                    <th style="width:90px;"><?php _e('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lateFees as $lf): ?>
                <tr>
                    <td><small><?php echo formatDate($lf['fee_date']); ?></small></td>
                    <td>
                        <span class="fw-semibold"><?php echo htmlspecialchars($lf['tenant_name']); ?></span>
                        <br><small class="text-muted"><?php echo htmlspecialchars($lf['tenant_phone']); ?></small>
                    </td>
                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($lf['unit_number']); ?></td>
                    <td class="d-none d-lg-table-cell"><small><?php echo htmlspecialchars($lf['period_reference'] ?? '—'); ?></small></td>
                    <td class="text-end fw-semibold <?php echo $lf['status'] === 'waived' ? 'text-decoration-line-through text-muted' : ''; ?>">
                        <?php echo formatCurrency($lf['amount'], $lf['currency']); ?>
                    </td>
                    <td>
                        <span class="badge bg-<?php echo $feeStatusColors[$lf['status']] ?? 'secondary'; ?>">
                            <?php echo $feeStatusLabels[$lf['status']] ?? ucfirst($lf['status']); ?>
                        </span>
                        <?php if ($lf['status'] === 'waived' && $lf['waived_by_name']): ?>
                        <br><small class="text-muted"><?php _e('by', 'by'); ?> <?php echo htmlspecialchars($lf['waived_by_name']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($lf['status'] === 'pending' && hasPermission('deposits', 'edit')): ?>
                        <button class="btn btn-outline-secondary btn-sm" onclick="waiveLateFee(<?php echo $lf['id']; ?>, '<?php echo htmlspecialchars(addslashes($lf['tenant_name'])); ?>', '<?php echo formatCurrency($lf['amount'], $lf['currency']); ?>')" title="<?php _e('waive', 'Waive'); ?>">
                            <i class="bi bi-x-lg"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($pagination['total_pages'] > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <li class="page-item <?php echo !$pagination['has_prev'] ? 'disabled' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagination['prev_page']])); ?>">&laquo;</a>
        </li>
        <?php
        $startPage = max(1, $pagination['current_page'] - 2);
        $endPage = min($pagination['total_pages'], $pagination['current_page'] + 2);
        for ($p = $startPage; $p <= $endPage; $p++):
        ?>
        <li class="page-item <?php echo $p === $pagination['current_page'] ? 'active' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"><?php echo $p; ?></a>
        </li>
        <?php endfor; ?>
        <li class="page-item <?php echo !$pagination['has_next'] ? 'disabled' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagination['next_page']])); ?>">&raquo;</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

<!-- Waive Modal -->
<div class="modal fade" id="waiveModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-x-lg me-1"></i> <?php _e('waive_late_fee', 'Waive Late Fee'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('waive_confirm', 'Waive this late fee?'); ?></p>
                <p><strong id="waiveInfo"></strong></p>
                <form id="waiveForm">
                    <input type="hidden" name="action" value="waive_late_fee">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRF(); ?>">
                    <input type="hidden" name="id" id="waiveFeeId" value="">
                    <div class="mb-3">
                        <label class="form-label"><?php _e('waive_reason', 'Reason'); ?></label>
                        <textarea name="reason" class="form-control" rows="2" placeholder="<?php _e('waive_reason_placeholder', 'Reason for waiving...'); ?>"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-sm btn-warning" id="confirmWaiveBtn"><?php _e('waive', 'Waive'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo generateCSRF(); ?>';
const apiUrl = BASE_URL + 'api/deposits.php';

// Waive late fee
function waiveLateFee(id, name, amount) {
    document.getElementById('waiveFeeId').value = id;
    document.getElementById('waiveInfo').textContent = name + ' — ' + amount;
    new bootstrap.Modal(document.getElementById('waiveModal')).show();
}

document.getElementById('confirmWaiveBtn').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';

    const formData = new FormData(document.getElementById('waiveForm'));

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
                btn.disabled = false;
                btn.textContent = '<?php echo __("waive", "Waive"); ?>';
            }
        })
        .catch(() => {
            alert('Request failed');
            btn.disabled = false;
            btn.textContent = '<?php echo __("waive", "Waive"); ?>';
        });
});

// Apply late fees manually
function applyLateFees() {
    const btn = document.getElementById('applyFeesBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> <?php echo __("processing", "Processing..."); ?>';

    const formData = new FormData();
    formData.append('action', 'apply_late_fees');
    formData.append('csrf_token', csrfToken);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert(data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-lightning me-1"></i> <?php echo __("apply_late_fees", "Apply Late Fees Now"); ?>';
            }
        })
        .catch(() => {
            alert('Request failed');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-lightning me-1"></i> <?php echo __("apply_late_fees", "Apply Late Fees Now"); ?>';
        });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
