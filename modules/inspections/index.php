<?php
/**
 * FIS Real Estate — Inspections List (11.x)
 * List view with filters (type, status, property), search, pagination.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('inspections');

$subStatus = enforceSubscription();

if (!hasPermission('inspections', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Get filter values
$search = trim($_GET['search'] ?? '');
$filterType = trim($_GET['type'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterProperty = (int)($_GET['property_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = RECORDS_PER_PAGE;

// Build WHERE clause
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(CONCAT(t.first_name, ' ', t.last_name) LIKE :search OR u.unit_number LIKE :search2 OR p.name LIKE :search3 OR i.inspector_name LIKE :search4)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
    $params['search4'] = "%{$search}%";
}
if ($filterType !== '') {
    $where[] = "i.inspection_type = :type";
    $params['type'] = $filterType;
}
if ($filterStatus !== '') {
    $where[] = "i.status = :status";
    $params['status'] = $filterStatus;
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
    FROM inspections i
    INNER JOIN units u ON i.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    LEFT JOIN tenants t ON i.tenant_id = t.id
    {$whereSql}
");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$pagination = paginate($totalRecords, $page, $perPage);

// Fetch inspections
$sql = "SELECT i.*, u.unit_number, p.name AS property_name,
        CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
        (SELECT COUNT(*) FROM inspection_items ii WHERE ii.inspection_id = i.id) AS item_count
        FROM inspections i
        INNER JOIN units u ON i.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        LEFT JOIN tenants t ON i.tenant_id = t.id
        {$whereSql}
        ORDER BY i.inspection_date DESC, i.created_at DESC
        LIMIT :offset, :limit";

$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->execute();
$inspections = $stmt->fetchAll();

// Properties list for filter dropdown
$propStmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name");
$properties = $propStmt->fetchAll();

$typeColors = ['move_in' => 'success', 'move_out' => 'danger', 'routine' => 'info'];
$typeLabels = [
    'move_in' => __('insp_move_in', 'Move-in'),
    'move_out' => __('insp_move_out', 'Move-out'),
    'routine' => __('insp_routine', 'Routine'),
];

$statusColors = ['scheduled' => 'secondary', 'in_progress' => 'warning', 'completed' => 'success'];
$statusLabels = [
    'scheduled' => __('insp_scheduled', 'Scheduled'),
    'in_progress' => __('insp_in_progress', 'In Progress'),
    'completed' => __('insp_completed', 'Completed'),
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-clipboard-check me-2"></i><?php _e('inspections'); ?></h4>
        <p class="text-muted mb-0"><?php _e('manage_inspections', 'Manage property inspections'); ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?php echo BASE_URL; ?>modules/inspections/templates.php" class="btn btn-outline-primary">
            <i class="bi bi-clipboard-data me-1"></i> <?php _e('templates'); ?>
        </a>
        <?php if (hasPermission('inspections', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/inspections/create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> <?php _e('new_inspection', 'New Inspection'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('inspections.index'); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <!-- Search -->
            <div class="col-12 col-md-3">
                <input type="text" class="form-control form-control-sm" name="search" placeholder="<?php _e('search_inspection_placeholder', 'Search tenant, unit, inspector...'); ?>" value="<?php echo htmlspecialchars($search); ?>">
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
            <!-- Type -->
            <div class="col-6 col-md-2">
                <select name="type" class="form-select form-select-sm">
                    <option value=""><?php _e('all_types'); ?></option>
                    <?php foreach ($typeLabels as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo $filterType === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Status -->
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all_statuses'); ?></option>
                    <?php foreach ($statusLabels as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo $filterStatus === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Buttons -->
            <div class="col-12 col-md-3 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/inspections/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Inspections List -->
<?php if (empty($inspections)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-clipboard-check" style="font-size:3rem;color:#ccc;"></i>
        <h5 class="mt-3 text-muted"><?php _e('no_inspections'); ?></h5>
        <?php if (hasPermission('inspections', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/inspections/create.php" class="btn btn-primary mt-2">
            <i class="bi bi-plus-circle me-1"></i> <?php _e('new_inspection', 'New Inspection'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<!-- Results info -->
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <small class="text-muted">
        <?php echo __('showing'); ?> <?php echo $pagination['offset'] + 1; ?>-<?php echo min($pagination['offset'] + $perPage, $totalRecords); ?>
        <?php echo __('of'); ?> <?php echo $totalRecords; ?> <?php echo __('inspections'); ?>
    </small>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th><?php _e('date'); ?></th>
                    <th><?php _e('type'); ?></th>
                    <th><?php _e('tenant'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('unit_number', 'Unit'); ?></th>
                    <th class="d-none d-lg-table-cell"><?php _e('property'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('inspector'); ?></th>
                    <th class="d-none d-md-table-cell text-center"><?php _e('insp_items', 'Items'); ?></th>
                    <th><?php _e('status'); ?></th>
                    <th style="width:90px;"><?php _e('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inspections as $insp): ?>
                <tr>
                    <td><small><?php echo formatDate($insp['inspection_date']); ?></small></td>
                    <td>
                        <span class="badge bg-<?php echo $typeColors[$insp['inspection_type']] ?? 'secondary'; ?>">
                            <?php echo $typeLabels[$insp['inspection_type']] ?? ucfirst(str_replace('_', ' ', $insp['inspection_type'])); ?>
                        </span>
                    </td>
                    <td>
                        <a href="<?php echo BASE_URL; ?>modules/inspections/view.php?id=<?php echo $insp['id']; ?>" class="fw-semibold text-decoration-none">
                            <?php echo htmlspecialchars($insp['tenant_name'] ?: '—'); ?>
                        </a>
                    </td>
                    <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($insp['unit_number']); ?></td>
                    <td class="d-none d-lg-table-cell"><small><?php echo htmlspecialchars($insp['property_name']); ?></small></td>
                    <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($insp['inspector_name'] ?: '—'); ?></small></td>
                    <td class="d-none d-md-table-cell text-center"><?php echo (int)$insp['item_count']; ?></td>
                    <td>
                        <span class="badge bg-<?php echo $statusColors[$insp['status']] ?? 'secondary'; ?>">
                            <?php echo $statusLabels[$insp['status']] ?? ucfirst($insp['status']); ?>
                        </span>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="<?php echo BASE_URL; ?>modules/inspections/view.php?id=<?php echo $insp['id']; ?>" class="btn btn-outline-primary" title="<?php _e('view'); ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (hasPermission('inspections', 'delete')): ?>
                            <button class="btn btn-outline-danger" onclick="deleteInspection(<?php echo $insp['id']; ?>, '<?php echo htmlspecialchars(addslashes($insp['tenant_name'] ?: $insp['unit_number'])); ?>')" title="<?php _e('delete'); ?>">
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
                <p><?php _e('delete_inspection_confirm', 'Delete this inspection for'); ?> <strong id="deleteName"></strong>?</p>
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
const apiUrl = BASE_URL + 'api/inspections.php';

let deleteId = null;
function deleteInspection(id, name) {
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
