<?php
/**
 * FIS Real Estate — Units List (6.1 + 6.5)
 * List view with filters (property, type, status, rent range), search, pagination, bulk actions.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('units');

$subStatus = enforceSubscription();

if (!hasPermission('units', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Subscription unit quota usage
$unitUsage = getUnitUsage();

// Get filter values
$search = trim($_GET['search'] ?? '');
$filterProperty = (int)($_GET['property_id'] ?? 0);
$filterType = trim($_GET['unit_type'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterRentMin = trim($_GET['rent_min'] ?? '');
$filterRentMax = trim($_GET['rent_max'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = RECORDS_PER_PAGE;

// Build WHERE clause
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(u.unit_number LIKE :search OR u.unit_label LIKE :search2 OR u.description LIKE :search3)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
}
if ($filterProperty > 0) {
    $where[] = "u.property_id = :property_id";
    $params['property_id'] = $filterProperty;
}
if ($filterType !== '') {
    $where[] = "u.unit_type = :unit_type";
    $params['unit_type'] = $filterType;
}
if ($filterStatus !== '') {
    $where[] = "u.status = :status";
    $params['status'] = $filterStatus;
}
if ($filterRentMin !== '' && is_numeric($filterRentMin)) {
    $where[] = "u.rent_amount >= :rent_min";
    $params['rent_min'] = (float) $filterRentMin;
}
if ($filterRentMax !== '' && is_numeric($filterRentMax)) {
    $where[] = "u.rent_amount <= :rent_max";
    $params['rent_max'] = (float) $filterRentMax;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Apply property-based access scoping (both queries carry the `u` alias)
$scope = propertyScopeSql('u.property_id');
$whereSql = ($whereSql === '' && $scope !== '') ? 'WHERE 1=1' . $scope : $whereSql . $scope;

// Count total
$countStmt = $conn->prepare("SELECT COUNT(*) FROM units u {$whereSql}");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$pagination = paginate($totalRecords, $page, $perPage);

// Fetch units with property name
$sql = "SELECT u.*, p.name AS property_name
        FROM units u
        INNER JOIN properties p ON u.property_id = p.id
        {$whereSql}
        ORDER BY u.created_at DESC
        LIMIT :offset, :limit";

$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->execute();
$units = $stmt->fetchAll();

// Get properties for filter dropdown
$properties = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name ASC")->fetchAll();

// Unit type labels
$typeLabels = [
    'apartment' => __('apartment', 'Apartment'),
    'room' => __('room', 'Room'),
    'shop' => __('shop', 'Shop'),
    'office' => __('office', 'Office'),
    'warehouse' => __('warehouse', 'Warehouse'),
    'plot' => __('plot', 'Plot'),
    'house' => __('house', 'House'),
    'flat' => __('flat', 'Flat'),
    'hostel' => __('hostel', 'Hostel'),
    'godown' => __('godown', 'Godown'),
];

$typeIcons = [
    'apartment' => 'bi-building',
    'room' => 'bi-door-closed',
    'shop' => 'bi-shop',
    'office' => 'bi-briefcase',
    'warehouse' => 'bi-box-seam',
    'plot' => 'bi-map',
    'house' => 'bi-house-door',
    'flat' => 'bi-building',
    'hostel' => 'bi-house',
    'godown' => 'bi-box-seam',
];

$statusColors = ['vacant' => 'danger', 'occupied' => 'success', 'maintenance' => 'warning', 'reserved' => 'info'];
$statusLabels = [
    'vacant' => __('vacant', 'Vacant'),
    'occupied' => __('occupied', 'Occupied'),
    'maintenance' => __('maintenance', 'Maintenance'),
    'reserved' => __('reserved', 'Reserved'),
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-door-open me-2"></i><?php _e('units'); ?></h4>
        <p class="text-muted mb-0"><?php _e('manage_units', 'Manage your units'); ?></p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if (!$unitUsage['unlimited']): ?>
        <span class="badge rounded-pill <?php echo $unitUsage['at_limit'] ? 'bg-danger' : 'bg-secondary'; ?> py-2 px-3"
              title="<?php echo htmlspecialchars(__('units_used_help', 'Units used against your subscription quota')); ?>">
            <i class="bi bi-diagram-3 me-1"></i>
            <?php echo sprintf(__('units_used', '%1$d / %2$d units'), $unitUsage['used'], $unitUsage['limit']); ?>
        </span>
        <?php endif; ?>
        <?php if (hasPermission('units', 'create')): ?>
        <?php echo importButton('rent_roll'); ?>
            <?php if ($unitUsage['at_limit']): ?>
            <a href="<?php echo BASE_URL; ?>modules/subscription/subscribe.php" class="btn btn-warning"
               title="<?php echo htmlspecialchars(sprintf(__('unit_limit_reached', 'You have reached your subscription unit limit (%1$d of %2$d units used). Upgrade your subscription to add more units.'), $unitUsage['used'], $unitUsage['limit'])); ?>">
                <i class="bi bi-arrow-up-circle me-1"></i> <?php _e('unit_limit_upgrade', 'Upgrade Plan'); ?>
            </a>
            <?php else: ?>
            <a href="<?php echo BASE_URL; ?>modules/units/create.php<?php echo $filterProperty ? '?property_id=' . $filterProperty : ''; ?>" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> <?php _e('add_unit', 'Add Unit'); ?>
            </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('units.index'); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <!-- Search -->
            <div class="col-12 col-md-2">
                <input type="text" class="form-control form-control-sm" name="search" placeholder="<?php _e('search'); ?>..." value="<?php echo htmlspecialchars($search); ?>">
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
            <!-- Type -->
            <div class="col-6 col-md-2">
                <select name="unit_type" class="form-select form-select-sm">
                    <option value=""><?php _e('all_types', 'All Types'); ?></option>
                    <?php foreach ($typeLabels as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo $filterType === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Status -->
            <div class="col-6 col-md-1">
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all_statuses', 'All Statuses'); ?></option>
                    <?php foreach ($statusLabels as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo $filterStatus === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Rent Range -->
            <div class="col-3 col-md-1">
                <input type="number" class="form-control form-control-sm" name="rent_min" placeholder="<?php _e('min_rent', 'Min'); ?>" value="<?php echo htmlspecialchars($filterRentMin); ?>" min="0" step="0.01">
            </div>
            <div class="col-3 col-md-1">
                <input type="number" class="form-control form-control-sm" name="rent_max" placeholder="<?php _e('max_rent', 'Max'); ?>" value="<?php echo htmlspecialchars($filterRentMax); ?>" min="0" step="0.01">
            </div>
            <!-- Buttons -->
            <div class="col-6 col-md-3 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/units/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear', 'Clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Actions Bar -->
<?php if (hasPermission('units', 'edit')): ?>
<div id="bulkBar" class="alert alert-info d-flex align-items-center gap-2 flex-wrap d-none mb-3">
    <span><strong id="selectedCount">0</strong> <?php _e('selected', 'selected'); ?></span>
    <button class="btn btn-sm btn-success" onclick="bulkStatus('vacant')"><i class="bi bi-door-open me-1"></i><?php _e('set_vacant', 'Set Vacant'); ?></button>
    <button class="btn btn-sm btn-warning" onclick="bulkStatus('maintenance')"><i class="bi bi-tools me-1"></i><?php _e('set_maintenance', 'Set Maintenance'); ?></button>
    <button class="btn btn-sm btn-info" onclick="bulkStatus('reserved')"><i class="bi bi-bookmark me-1"></i><?php _e('set_reserved', 'Set Reserved'); ?></button>
    <?php if (hasPermission('units', 'delete')): ?>
    <button class="btn btn-sm btn-danger" onclick="bulkDelete()"><i class="bi bi-trash me-1"></i><?php _e('delete'); ?></button>
    <?php endif; ?>
    <button class="btn btn-sm btn-outline-secondary ms-auto" onclick="clearSelection()"><i class="bi bi-x me-1"></i><?php _e('cancel'); ?></button>
</div>
<?php endif; ?>

<!-- Units List -->
<?php if (empty($units)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-door-open" style="font-size:3rem;color:#ccc;"></i>
        <h5 class="mt-3 text-muted"><?php _e('no_units', 'No units found'); ?></h5>
        <?php if (hasPermission('units', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/units/create.php" class="btn btn-primary mt-2">
            <i class="bi bi-plus-circle me-1"></i> <?php _e('add_unit', 'Add Unit'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<!-- Results info -->
<div class="d-flex justify-content-between align-items-center mb-2">
    <small class="text-muted">
        <?php echo __('showing', 'Showing'); ?> <?php echo $pagination['offset'] + 1; ?>-<?php echo min($pagination['offset'] + $perPage, $totalRecords); ?>
        <?php echo __('of', 'of'); ?> <?php echo $totalRecords; ?> <?php echo __('units'); ?>
    </small>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <?php if (hasPermission('units', 'edit')): ?>
                    <th style="width:40px;">
                        <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll(this)">
                    </th>
                    <?php endif; ?>
                    <th><?php _e('unit_number', 'Unit #'); ?></th>
                    <th><?php _e('property', 'Property'); ?></th>
                    <th><?php _e('type'); ?></th>
                    <th class="text-end"><?php _e('rent', 'Rent'); ?></th>
                    <th><?php _e('status'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('details', 'Details'); ?></th>
                    <th style="width:120px;"><?php _e('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($units as $unit): ?>
                <tr>
                    <?php if (hasPermission('units', 'edit')): ?>
                    <td>
                        <input type="checkbox" class="form-check-input unit-check" value="<?php echo $unit['id']; ?>" onchange="updateBulkBar()">
                    </td>
                    <?php endif; ?>
                    <td>
                        <a href="<?php echo BASE_URL; ?>modules/units/view.php?id=<?php echo $unit['id']; ?>" class="fw-semibold text-decoration-none">
                            <?php echo htmlspecialchars($unit['unit_number']); ?>
                        </a>
                        <?php if ($unit['unit_label']): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($unit['unit_label']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $unit['property_id']; ?>" class="text-decoration-none">
                            <?php echo htmlspecialchars($unit['property_name']); ?>
                        </a>
                    </td>
                    <td>
                        <span class="badge bg-light text-dark">
                            <i class="<?php echo $typeIcons[$unit['unit_type']] ?? 'bi-door-open'; ?> me-1"></i>
                            <?php echo $typeLabels[$unit['unit_type']] ?? ucfirst($unit['unit_type']); ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <span class="fw-semibold"><?php echo formatCurrency($unit['rent_amount'], $unit['currency']); ?></span>
                        <?php if ($unit['deposit_amount'] > 0): ?>
                        <br><small class="text-muted"><?php _e('deposit_short', 'Dep'); ?>: <?php echo formatCurrency($unit['deposit_amount'], $unit['currency']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?php echo $statusColors[$unit['status']] ?? 'secondary'; ?>">
                            <?php echo $statusLabels[$unit['status']] ?? ucfirst($unit['status']); ?>
                        </span>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <small class="text-muted">
                            <?php if ($unit['bedrooms'] !== null): ?><?php echo $unit['bedrooms']; ?> <?php _e('bd', 'bd'); ?><?php endif; ?>
                            <?php if ($unit['bathrooms'] !== null): ?> / <?php echo $unit['bathrooms']; ?> <?php _e('ba', 'ba'); ?><?php endif; ?>
                            <?php if ($unit['size_sqm']): ?> &middot; <?php echo formatNumber($unit['size_sqm'], 0); ?> m&sup2;<?php endif; ?>
                            <?php if ($unit['floor']): ?> &middot; <?php _e('floor_short', 'Fl'); ?> <?php echo htmlspecialchars($unit['floor']); ?><?php endif; ?>
                        </small>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="<?php echo BASE_URL; ?>modules/units/view.php?id=<?php echo $unit['id']; ?>" class="btn btn-outline-primary" title="<?php _e('view'); ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (hasPermission('units', 'edit')): ?>
                            <a href="<?php echo BASE_URL; ?>modules/units/edit.php?id=<?php echo $unit['id']; ?>" class="btn btn-outline-secondary" title="<?php _e('edit'); ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (hasPermission('units', 'delete')): ?>
                            <button class="btn btn-outline-danger" onclick="deleteUnit(<?php echo $unit['id']; ?>, '<?php echo htmlspecialchars(addslashes($unit['unit_number'])); ?>')" title="<?php _e('delete'); ?>">
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
                <p><?php _e('delete_unit_confirm', 'Are you sure you want to delete unit'); ?> <strong id="deleteName"></strong>?</p>
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
const apiUrl = BASE_URL + 'api/units.php';

// Bulk selection
function toggleSelectAll(el) {
    document.querySelectorAll('.unit-check').forEach(cb => cb.checked = el.checked);
    updateBulkBar();
}

function updateBulkBar() {
    const checked = document.querySelectorAll('.unit-check:checked');
    const bar = document.getElementById('bulkBar');
    if (bar) {
        if (checked.length > 0) {
            bar.classList.remove('d-none');
            document.getElementById('selectedCount').textContent = checked.length;
        } else {
            bar.classList.add('d-none');
        }
    }
}

function clearSelection() {
    document.querySelectorAll('.unit-check').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateBulkBar();
}

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.unit-check:checked')).map(cb => cb.value);
}

function bulkStatus(newStatus) {
    const ids = getSelectedIds();
    if (!ids.length) return;

    const formData = new FormData();
    formData.append('action', 'bulk_status');
    formData.append('csrf_token', csrfToken);
    formData.append('new_status', newStatus);
    ids.forEach(id => formData.append('ids[]', id));

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

function bulkDelete() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    if (!confirm('<?php echo __("bulk_delete_confirm", "Delete selected units? This cannot be undone."); ?>')) return;

    const formData = new FormData();
    formData.append('action', 'bulk_delete');
    formData.append('csrf_token', csrfToken);
    ids.forEach(id => formData.append('ids[]', id));

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

// Delete single
let deleteId = null;
function deleteUnit(id, name) {
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
