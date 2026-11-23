<?php
/**
 * FIS Real Estate — Payments List (9.1 + 9.4)
 * List view with filters (property, status, method, date range), search, pagination.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('payments');

$subStatus = enforceSubscription();

if (!hasPermission('payments', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Get filter values
$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterProperty = (int)($_GET['property_id'] ?? 0);
$filterMethod = trim($_GET['payment_method'] ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = RECORDS_PER_PAGE;

// Build WHERE clause
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(CONCAT(t.first_name, ' ', t.last_name) LIKE :search OR u.unit_number LIKE :search2 OR p.name LIKE :search3 OR py.reference_number LIKE :search4)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
    $params['search4'] = "%{$search}%";
}
if ($filterStatus !== '') {
    $where[] = "py.status = :status";
    $params['status'] = $filterStatus;
}
if ($filterProperty > 0) {
    $where[] = "u.property_id = :property_id";
    $params['property_id'] = $filterProperty;
}
if ($filterMethod !== '') {
    $where[] = "py.payment_method = :method";
    $params['method'] = $filterMethod;
}
if ($filterDateFrom !== '') {
    $where[] = "py.payment_date >= :date_from";
    $params['date_from'] = date('Y-m-d', strtotime($filterDateFrom));
}
if ($filterDateTo !== '') {
    $where[] = "py.payment_date <= :date_to";
    $params['date_to'] = date('Y-m-d', strtotime($filterDateTo));
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
    FROM payments py
    INNER JOIN units u ON py.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON py.tenant_id = t.id
    {$whereSql}
");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$pagination = paginate($totalRecords, $page, $perPage);

// Sum for filtered results
$sumStmt = $conn->prepare("
    SELECT COALESCE(SUM(py.amount),0)
    FROM payments py
    INNER JOIN units u ON py.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON py.tenant_id = t.id
    {$whereSql}
");
$sumStmt->execute($params);
$filteredTotal = (float) $sumStmt->fetchColumn();

// Fetch payments
$sql = "SELECT py.*, u.unit_number, p.name AS property_name,
        CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
        r.receipt_number
        FROM payments py
        INNER JOIN units u ON py.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        INNER JOIN tenants t ON py.tenant_id = t.id
        LEFT JOIN receipts r ON r.payment_id = py.id
        {$whereSql}
        ORDER BY py.payment_date DESC, py.created_at DESC
        LIMIT :offset, :limit";

$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->execute();
$payments = $stmt->fetchAll();

// Properties list for filter dropdown
$propStmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name");
$properties = $propStmt->fetchAll();

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
        <h4><i class="bi bi-cash-coin me-2"></i><?php _e('payments'); ?></h4>
        <p class="text-muted mb-0"><?php _e('manage_payments', 'Manage rent payments'); ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if (hasPermission('payments', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/payments/bulk.php" class="btn btn-outline-primary">
            <i class="bi bi-list-check me-1"></i> <?php _e('bulk_payment', 'Bulk Payment'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/payments/create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> <?php _e('record_payment', 'Record Payment'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('payments.index'); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <!-- Search -->
            <div class="col-12 col-md-3">
                <input type="text" class="form-control form-control-sm" name="search" placeholder="<?php _e('search_payment_placeholder', 'Search tenant, unit, ref...'); ?>" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <!-- Property -->
            <div class="col-6 col-md-2">
                <select name="property_id" class="form-select form-select-sm">
                    <option value=""><?php _e('all_properties', 'All Properties'); ?></option>
                    <?php foreach ($properties as $prop): ?>
                    <option value="<?php echo $prop['id']; ?>" <?php echo $filterProperty == $prop['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prop['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Method -->
            <div class="col-6 col-md-2">
                <select name="payment_method" class="form-select form-select-sm">
                    <option value=""><?php _e('all_methods', 'All Methods'); ?></option>
                    <?php foreach ($methodLabels as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo $filterMethod === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Date From -->
            <div class="col-6 col-md-2">
                <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>" placeholder="<?php _e('from'); ?>">
            </div>
            <!-- Date To -->
            <div class="col-6 col-md-1">
                <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($filterDateTo); ?>">
            </div>
            <!-- Buttons -->
            <div class="col-12 col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/payments/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear', 'Clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Payments List -->
<?php if (empty($payments)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-cash-coin" style="font-size:3rem;color:#ccc;"></i>
        <h5 class="mt-3 text-muted"><?php _e('no_payments', 'No payment records found'); ?></h5>
        <?php if (hasPermission('payments', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/payments/create.php" class="btn btn-primary mt-2">
            <i class="bi bi-plus-circle me-1"></i> <?php _e('record_payment', 'Record Payment'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<!-- Results info -->
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <small class="text-muted">
        <?php echo __('showing', 'Showing'); ?> <?php echo $pagination['offset'] + 1; ?>-<?php echo min($pagination['offset'] + $perPage, $totalRecords); ?>
        <?php echo __('of', 'of'); ?> <?php echo $totalRecords; ?> <?php echo __('payments'); ?>
    </small>
    <small class="fw-semibold text-primary">
        <?php _e('filtered_total', 'Total'); ?>: <?php echo formatCurrency($filteredTotal); ?>
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
                    <th class="d-none d-lg-table-cell"><?php _e('property'); ?></th>
                    <th class="text-end"><?php _e('amount'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('method', 'Method'); ?></th>
                    <th class="d-none d-lg-table-cell"><?php _e('receipt', 'Receipt'); ?></th>
                    <th><?php _e('status'); ?></th>
                    <th style="width:90px;"><?php _e('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $py): ?>
                <tr class="<?php echo $py['status'] === 'reversed' ? 'table-danger' : ''; ?>">
                    <td><small><?php echo formatDate($py['payment_date']); ?></small></td>
                    <td>
                        <a href="<?php echo BASE_URL; ?>modules/payments/view.php?id=<?php echo $py['id']; ?>" class="fw-semibold text-decoration-none">
                            <?php echo htmlspecialchars($py['tenant_name']); ?>
                        </a>
                        <br><small class="text-muted"><?php echo htmlspecialchars($py['tenant_phone']); ?></small>
                    </td>
                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($py['unit_number']); ?></td>
                    <td class="d-none d-lg-table-cell"><small><?php echo htmlspecialchars($py['property_name']); ?></small></td>
                    <td class="text-end fw-semibold <?php echo $py['status'] === 'reversed' ? 'text-decoration-line-through' : ''; ?>">
                        <?php echo formatCurrency($py['amount'], $py['currency']); ?>
                    </td>
                    <td class="d-none d-md-table-cell"><small><?php echo $methodLabels[$py['payment_method']] ?? ucfirst(str_replace('_', ' ', $py['payment_method'])); ?></small></td>
                    <td class="d-none d-lg-table-cell">
                        <?php if ($py['receipt_number']): ?>
                        <a href="<?php echo BASE_URL; ?>modules/payments/receipt.php?payment_id=<?php echo $py['id']; ?>" target="_blank" class="text-decoration-none">
                            <small><i class="bi bi-receipt me-1"></i><?php echo htmlspecialchars($py['receipt_number']); ?></small>
                        </a>
                        <?php else: ?>
                        <small class="text-muted">—</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?php echo $statusColors[$py['status']] ?? 'secondary'; ?>">
                            <?php echo $statusLabels[$py['status']] ?? ucfirst($py['status']); ?>
                        </span>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="<?php echo BASE_URL; ?>modules/payments/view.php?id=<?php echo $py['id']; ?>" class="btn btn-outline-primary" title="<?php _e('view'); ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (hasPermission('payments', 'delete') && $py['status'] !== 'reversed'): ?>
                            <button class="btn btn-outline-danger" onclick="deletePayment(<?php echo $py['id']; ?>, '<?php echo htmlspecialchars(addslashes($py['tenant_name'])); ?>')" title="<?php _e('delete'); ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
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

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><?php _e('confirm_delete', 'Confirm Delete'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('delete_payment_confirm', 'Delete this payment for'); ?> <strong id="deleteName"></strong>?</p>
                <p class="text-muted small"><?php _e('delete_warning', 'This action cannot be undone.'); ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-sm btn-danger" id="confirmDeleteBtn"><?php _e('delete'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo generateCSRF(); ?>';
const apiUrl = BASE_URL + 'api/payments.php';

let deleteId = null;
function deletePayment(id, name) {
    deleteId = id;
    document.getElementById('deleteName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (!deleteId) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('csrf_token', csrfToken);
    formData.append('id', deleteId);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
                bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
            }
        })
        .catch(() => alert('Request failed'));
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
