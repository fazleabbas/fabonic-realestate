<?php
/**
 * FIS Real Estate — Leases List (8.1)
 * List view with filters (status, property, frequency, expiring), search, pagination.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('leases');

$subStatus = enforceSubscription();

if (!hasPermission('leases', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Get filter values
$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterProperty = (int)($_GET['property_id'] ?? 0);
$filterFrequency = trim($_GET['frequency'] ?? '');
$filterExpiring = trim($_GET['expiring'] ?? '');
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
    $where[] = "l.status = :status";
    $params['status'] = $filterStatus;
}
if ($filterProperty > 0) {
    $where[] = "u.property_id = :property_id";
    $params['property_id'] = $filterProperty;
}
if ($filterFrequency !== '') {
    $where[] = "l.payment_frequency = :frequency";
    $params['frequency'] = $filterFrequency;
}
if ($filterExpiring === 'soon') {
    $where[] = "l.status = 'active' AND l.end_date <= DATE_ADD(CURDATE(), INTERVAL l.renewal_alert_days DAY)";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Property-based access scoping (units joined as u)
$scopeSql = propertyScopeSql('u.property_id');
if ($scopeSql !== '') {
    $whereSql = ($whereSql === '' ? 'WHERE 1=1' : $whereSql) . $scopeSql;
}

// Count total
$countStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM leases l
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON l.tenant_id = t.id
    {$whereSql}
");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$pagination = paginate($totalRecords, $page, $perPage);

// Fetch leases
$sql = "SELECT l.*, u.unit_number, p.name AS property_name,
        CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone
        FROM leases l
        INNER JOIN units u ON l.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        INNER JOIN tenants t ON l.tenant_id = t.id
        {$whereSql}
        ORDER BY l.created_at DESC
        LIMIT :offset, :limit";

$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->execute();
$leases = $stmt->fetchAll();

// Properties list for filter dropdown (scoped)
$propStmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name");
$properties = $propStmt->fetchAll();

$statusColors = ['active' => 'success', 'expired' => 'secondary', 'terminated' => 'danger', 'renewed' => 'info'];
$statusLabels = [
    'active' => __('active', 'Active'),
    'expired' => __('expired', 'Expired'),
    'terminated' => __('terminated', 'Terminated'),
    'renewed' => __('renewed', 'Renewed'),
];

$frequencyLabels = [];
foreach (paymentFrequencies() as $freqKey) {
    $frequencyLabels[$freqKey] = paymentFrequencyLabel($freqKey);
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-file-earmark-text me-2"></i><?php _e('leases'); ?></h4>
        <p class="text-muted mb-0"><?php _e('manage_leases', 'Manage your leases'); ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if (hasPermission('leases', 'create')): ?>
        <?php echo importButton('rent_roll'); ?>
        <a href="<?php echo BASE_URL; ?>modules/leases/templates.php" class="btn btn-outline-primary">
            <i class="bi bi-file-earmark-richtext me-1"></i> <?php _e('lease_templates', 'Templates'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/leases/create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> <?php _e('add_lease', 'New Lease'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('leases.index'); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <!-- Search -->
            <div class="col-12 col-md-3">
                <input type="text" class="form-control form-control-sm" name="search" placeholder="<?php _e('search_lease_placeholder', 'Search tenant, unit, property...'); ?>" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <!-- Status -->
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all_statuses', 'All Statuses'); ?></option>
                    <?php foreach ($statusLabels as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo $filterStatus === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
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
            <!-- Frequency -->
            <div class="col-6 col-md-2">
                <select name="frequency" class="form-select form-select-sm">
                    <option value=""><?php _e('all_frequencies', 'All Frequencies'); ?></option>
                    <?php foreach ($frequencyLabels as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo $filterFrequency === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Expiring Filter -->
            <div class="col-6 col-md-1">
                <div class="form-check form-check-sm mt-1">
                    <input class="form-check-input" type="checkbox" name="expiring" value="soon" id="expiringCheck" <?php echo $filterExpiring === 'soon' ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="expiringCheck"><?php _e('expiring_soon', 'Expiring'); ?></label>
                </div>
            </div>
            <!-- Buttons -->
            <div class="col-12 col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/leases/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear', 'Clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Leases List -->
<?php if (empty($leases)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-file-earmark-text" style="font-size:3rem;color:#ccc;"></i>
        <h5 class="mt-3 text-muted"><?php _e('no_leases', 'No lease records found'); ?></h5>
        <?php if (hasPermission('leases', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/leases/create.php" class="btn btn-primary mt-2">
            <i class="bi bi-plus-circle me-1"></i> <?php _e('add_lease', 'New Lease'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<!-- Results info -->
<div class="d-flex justify-content-between align-items-center mb-2">
    <small class="text-muted">
        <?php echo __('showing', 'Showing'); ?> <?php echo $pagination['offset'] + 1; ?>-<?php echo min($pagination['offset'] + $perPage, $totalRecords); ?>
        <?php echo __('of', 'of'); ?> <?php echo $totalRecords; ?> <?php echo __('leases'); ?>
    </small>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th><?php _e('tenant'); ?></th>
                    <th><?php _e('unit_number', 'Unit'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('property'); ?></th>
                    <th><?php _e('period'); ?></th>
                    <th class="text-end"><?php _e('rent'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('frequency'); ?></th>
                    <th><?php _e('status'); ?></th>
                    <th style="width:100px;"><?php _e('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leases as $lease):
                    $isExpiring = $lease['status'] === 'active' && strtotime($lease['end_date']) <= strtotime('+' . $lease['renewal_alert_days'] . ' days');
                ?>
                <tr>
                    <td>
                        <a href="<?php echo BASE_URL; ?>modules/leases/view.php?id=<?php echo $lease['id']; ?>" class="fw-semibold text-decoration-none">
                            <?php echo htmlspecialchars($lease['tenant_name']); ?>
                        </a>
                        <br><small class="text-muted"><?php echo htmlspecialchars($lease['tenant_phone']); ?></small>
                    </td>
                    <td><?php echo htmlspecialchars($lease['unit_number']); ?></td>
                    <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($lease['property_name']); ?></small></td>
                    <td>
                        <small>
                            <?php echo formatDate($lease['start_date']); ?><br>
                            <?php echo formatDate($lease['end_date']); ?>
                        </small>
                    </td>
                    <td class="text-end fw-semibold"><?php echo formatCurrency($lease['rent_amount'], $lease['currency']); ?></td>
                    <td class="d-none d-md-table-cell"><small><?php echo $frequencyLabels[$lease['payment_frequency']] ?? paymentFrequencyLabel($lease['payment_frequency']); ?></small></td>
                    <td>
                        <span class="badge bg-<?php echo $statusColors[$lease['status']] ?? 'secondary'; ?>">
                            <?php echo $statusLabels[$lease['status']] ?? ucfirst($lease['status']); ?>
                        </span>
                        <?php if ($isExpiring): ?>
                        <br><span class="badge bg-warning text-dark" style="font-size:0.65rem;"><i class="bi bi-exclamation-triangle me-1"></i><?php _e('expiring_soon', 'Expiring'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="<?php echo BASE_URL; ?>modules/leases/view.php?id=<?php echo $lease['id']; ?>" class="btn btn-outline-primary" title="<?php _e('view'); ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (hasPermission('leases', 'edit') && isLeaseEditable($lease)): ?>
                            <a href="<?php echo BASE_URL; ?>modules/leases/edit.php?id=<?php echo $lease['id']; ?>" class="btn btn-outline-secondary" title="<?php _e('edit'); ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (hasPermission('leases', 'delete')): ?>
                            <button class="btn btn-outline-danger" onclick="deleteLease(<?php echo $lease['id']; ?>, '<?php echo htmlspecialchars(addslashes($lease['tenant_name'])); ?>')" title="<?php _e('delete'); ?>">
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
                <p><?php _e('delete_lease_confirm', 'Are you sure you want to delete this lease for'); ?> <strong id="deleteName"></strong>?</p>
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
const apiUrl = BASE_URL + 'api/leases.php';

// Delete single
let deleteId = null;
function deleteLease(id, name) {
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
