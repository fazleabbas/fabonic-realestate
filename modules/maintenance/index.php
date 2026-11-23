<?php
/**
 * FIS Real Estate — Maintenance Requests List (12.x)
 * List view with filters (status, priority, category, property), search, pagination.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('maintenance');

$subStatus = enforceSubscription();

if (!hasTier('enterprise')) {
    setAlert(__('maint_tier_required', 'Enterprise tier or higher required for maintenance management.'), 'warning');
    redirect('index.php');
}
if (getSetting('maintenance_enabled', '0') !== '1') {
    setAlert(__('maint_disabled', 'Maintenance module is disabled. Enable it in Settings > Modules.'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('maintenance', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Get filter values
$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterPriority = trim($_GET['priority'] ?? '');
$filterCategory = (int)($_GET['category_id'] ?? 0);
$filterProperty = (int)($_GET['property_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = RECORDS_PER_PAGE;

// Build WHERE clause
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(mr.title LIKE :search OR u.unit_number LIKE :search2 OR p.name LIKE :search3 OR CONCAT(t.first_name, ' ', t.last_name) LIKE :search4)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
    $params['search4'] = "%{$search}%";
}
if ($filterStatus === 'open') {
    // Pseudo-status: every request still needing attention. Mirrors the
    // dashboard's "Open Maintenance" figure so the drill-down count matches.
    $where[] = "mr.status NOT IN ('completed', 'closed', 'cancelled')";
} elseif ($filterStatus !== '') {
    $where[] = "mr.status = :status";
    $params['status'] = $filterStatus;
}
if ($filterPriority !== '') {
    $where[] = "mr.priority = :priority";
    $params['priority'] = $filterPriority;
}
if ($filterCategory > 0) {
    $where[] = "mr.category_id = :category_id";
    $params['category_id'] = $filterCategory;
}
if ($filterProperty > 0) {
    $where[] = "u.property_id = :property_id";
    $params['property_id'] = $filterProperty;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Property-based access scoping (units joined as alias u)
$scopeSql = propertyScopeSql('u.property_id');
if ($scopeSql !== '') {
    $whereSql = $whereSql === '' ? 'WHERE 1=1' . $scopeSql : $whereSql . $scopeSql;
}

// Count total
$countStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    LEFT JOIN tenants t ON mr.tenant_id = t.id
    {$whereSql}
");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$pagination = paginate($totalRecords, $page, $perPage);

// Fetch requests
$sql = "SELECT mr.*, u.unit_number, p.name AS property_name,
        mc.name AS category_name,
        CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
        (SELECT COUNT(*) FROM maintenance_assignments ma WHERE ma.request_id = mr.id) AS assignment_count
        FROM maintenance_requests mr
        INNER JOIN units u ON mr.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        LEFT JOIN tenants t ON mr.tenant_id = t.id
        LEFT JOIN maintenance_categories mc ON mr.category_id = mc.id
        {$whereSql}
        ORDER BY
            FIELD(mr.priority, 'urgent', 'high', 'medium', 'low'),
            mr.created_at DESC
        LIMIT :offset, :limit";

$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->execute();
$requests = $stmt->fetchAll();

// Dropdowns
$propStmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name");
$properties = $propStmt->fetchAll();

$catStmt = $conn->query("SELECT id, name FROM maintenance_categories ORDER BY name");
$categories = $catStmt->fetchAll();

$priorityColors = ['low' => 'info', 'medium' => 'primary', 'high' => 'warning', 'urgent' => 'danger'];
$priorityLabels = [
    'low' => __('maint_priority_low', 'Low'),
    'medium' => __('maint_priority_medium', 'Medium'),
    'high' => __('maint_priority_high', 'High'),
    'urgent' => __('maint_priority_urgent', 'Urgent'),
];

$statusColors = [
    'reported' => 'secondary', 'acknowledged' => 'info', 'assigned' => 'primary',
    'in_progress' => 'warning', 'completed' => 'success', 'closed' => 'dark', 'cancelled' => 'danger',
];
$statusLabels = [
    'reported' => __('maint_reported', 'Reported'),
    'acknowledged' => __('maint_acknowledged', 'Acknowledged'),
    'assigned' => __('maint_assigned', 'Assigned'),
    'in_progress' => __('maint_in_progress', 'In Progress'),
    'completed' => __('maint_completed', 'Completed'),
    'closed' => __('maint_closed', 'Closed'),
    'cancelled' => __('maint_cancelled', 'Cancelled'),
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-tools me-2"></i><?php _e('maintenance'); ?></h4>
        <p class="text-muted mb-0"><?php _e('maint_manage', 'Manage maintenance requests & work orders'); ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?php echo BASE_URL; ?>modules/maintenance/contractors.php" class="btn btn-outline-primary">
            <i class="bi bi-people me-1"></i> <?php _e('maint_contractors', 'Contractors'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/maintenance/reports.php" class="btn btn-outline-secondary">
            <i class="bi bi-bar-chart-line me-1"></i> <?php _e('reports'); ?>
        </a>
        <?php if (hasPermission('maintenance', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/maintenance/create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> <?php _e('maint_new_request', 'New Request'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('maintenance.index'); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <!-- Search -->
            <div class="col-12 col-md-3">
                <input type="text" class="form-control form-control-sm" name="search" placeholder="<?php _e('maint_search_placeholder', 'Search title, unit, tenant...'); ?>" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <!-- Property -->
            <div class="col-6 col-md-2">
                <select name="property_id" class="form-select form-select-sm">
                    <option value=""><?php _e('all_properties'); ?></option>
                    <?php foreach ($properties as $prop): ?>
                    <option value="<?php echo $prop['id']; ?>" <?php echo $filterProperty == $prop['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prop['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Status -->
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all_statuses'); ?></option>
                    <option value="open" <?php echo $filterStatus === 'open' ? 'selected' : ''; ?>><?php _e('maint_open', 'Open (not finished)'); ?></option>
                    <?php foreach ($statusLabels as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo $filterStatus === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Priority -->
            <div class="col-6 col-md-2">
                <select name="priority" class="form-select form-select-sm">
                    <option value=""><?php _e('maint_all_priorities', 'All Priorities'); ?></option>
                    <?php foreach ($priorityLabels as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo $filterPriority === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Buttons -->
            <div class="col-12 col-md-3 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/maintenance/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Requests List -->
<?php if (empty($requests)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-tools" style="font-size:3rem;color:#ccc;"></i>
        <h5 class="mt-3 text-muted"><?php _e('maint_no_requests', 'No maintenance requests'); ?></h5>
        <?php if (hasPermission('maintenance', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/maintenance/create.php" class="btn btn-primary mt-2">
            <i class="bi bi-plus-circle me-1"></i> <?php _e('maint_new_request', 'New Request'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<!-- Results info -->
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <small class="text-muted">
        <?php echo __('showing'); ?> <?php echo $pagination['offset'] + 1; ?>-<?php echo min($pagination['offset'] + $perPage, $totalRecords); ?>
        <?php echo __('of'); ?> <?php echo $totalRecords; ?> <?php echo __('maint_requests', 'requests'); ?>
    </small>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th><?php _e('maint_title', 'Title'); ?></th>
                    <th><?php _e('priority'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('category'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('unit_number', 'Unit'); ?></th>
                    <th class="d-none d-lg-table-cell"><?php _e('property'); ?></th>
                    <th class="d-none d-lg-table-cell"><?php _e('tenant'); ?></th>
                    <th><?php _e('status'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('date'); ?></th>
                    <th style="width:90px;"><?php _e('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $req): ?>
                <tr>
                    <td>
                        <a href="<?php echo BASE_URL; ?>modules/maintenance/view.php?id=<?php echo $req['id']; ?>" class="fw-semibold text-decoration-none">
                            <?php echo htmlspecialchars($req['title']); ?>
                        </a>
                        <?php if ($req['assignment_count'] > 0): ?>
                        <small class="text-muted ms-1"><i class="bi bi-person-check"></i></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?php echo $priorityColors[$req['priority']] ?? 'secondary'; ?>">
                            <?php echo $priorityLabels[$req['priority']] ?? ucfirst($req['priority']); ?>
                        </span>
                    </td>
                    <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($req['category_name'] ?? '—'); ?></small></td>
                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($req['unit_number']); ?></td>
                    <td class="d-none d-lg-table-cell"><small><?php echo htmlspecialchars($req['property_name']); ?></small></td>
                    <td class="d-none d-lg-table-cell"><small><?php echo htmlspecialchars($req['tenant_name'] ?: '—'); ?></small></td>
                    <td>
                        <span class="badge bg-<?php echo $statusColors[$req['status']] ?? 'secondary'; ?>">
                            <?php echo $statusLabels[$req['status']] ?? ucfirst($req['status']); ?>
                        </span>
                    </td>
                    <td class="d-none d-md-table-cell"><small><?php echo formatDate($req['created_at']); ?></small></td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="<?php echo BASE_URL; ?>modules/maintenance/view.php?id=<?php echo $req['id']; ?>" class="btn btn-outline-primary" title="<?php _e('view'); ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (hasPermission('maintenance', 'delete')): ?>
                            <button class="btn btn-outline-danger" onclick="deleteRequest(<?php echo $req['id']; ?>, '<?php echo htmlspecialchars(addslashes($req['title'])); ?>')" title="<?php _e('delete'); ?>">
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

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><?php _e('confirm_delete'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('maint_delete_confirm', 'Delete this maintenance request:'); ?> <strong id="deleteName"></strong>?</p>
                <p class="text-muted small"><?php _e('delete_warning'); ?></p>
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
const apiUrl = BASE_URL + 'api/maintenance.php';

let deleteId = null;
function deleteRequest(id, name) {
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
