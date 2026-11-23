<?php
/**
 * FIS Real Estate — Properties List (5.1 + 5.5)
 * List view with filters (type, status, city), search, pagination, bulk status toggle.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('properties');

$subStatus = enforceSubscription();

if (!hasPermission('properties', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Get filter values
$search = trim($_GET['search'] ?? '');
$filterType = trim($_GET['type'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterCity = trim($_GET['city'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = RECORDS_PER_PAGE;

// Build WHERE clause
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(name LIKE :search OR address LIKE :search2 OR city LIKE :search3)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
}
if ($filterType !== '') {
    $where[] = "property_type = :type";
    $params['type'] = $filterType;
}
if ($filterStatus !== '') {
    $where[] = "status = :status";
    $params['status'] = $filterStatus;
}
if ($filterCity !== '') {
    $where[] = "city = :city";
    $params['city'] = $filterCity;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Apply property-based access scoping (count query uses raw `properties`, fetch uses alias `p`)
$countScope = propertyScopeSql('id');
$fetchScope = propertyScopeSql('p.id');
$countWhereSql = ($whereSql === '' && $countScope !== '') ? 'WHERE 1=1' . $countScope : $whereSql . $countScope;
$fetchWhereSql = ($whereSql === '' && $fetchScope !== '') ? 'WHERE 1=1' . $fetchScope : $whereSql . $fetchScope;

// Count total
$countStmt = $conn->prepare("SELECT COUNT(*) FROM properties {$countWhereSql}");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$pagination = paginate($totalRecords, $page, $perPage);

// Fetch properties with unit counts
$sql = "SELECT p.*,
            (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id) AS unit_count,
            (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id AND u.status = 'occupied') AS occupied_count,
            (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id AND u.status = 'vacant') AS vacant_count
        FROM properties p
        {$fetchWhereSql}
        ORDER BY p.created_at DESC
        LIMIT :offset, :limit";

$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->execute();
$properties = $stmt->fetchAll();

// Get distinct cities for filter
$cities = $conn->query("SELECT DISTINCT city FROM properties WHERE city IS NOT NULL AND city != '' ORDER BY city ASC")->fetchAll(PDO::FETCH_COLUMN);

// Property type labels
$typeLabels = [
    'residential' => __('residential', 'Residential'),
    'commercial' => __('commercial', 'Commercial'),
    'land' => __('land', 'Land'),
    'mixed' => __('mixed', 'Mixed Use')
];

$typeIcons = [
    'residential' => 'bi-house-door',
    'commercial' => 'bi-shop',
    'land' => 'bi-map',
    'mixed' => 'bi-buildings'
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-buildings me-2"></i><?php _e('properties'); ?></h4>
        <p class="text-muted mb-0"><?php _e('manage_properties', 'Manage your properties'); ?></p>
    </div>
    <?php if (hasPermission('properties', 'create')): ?>
    <div class="d-flex gap-2">
        <?php echo importButton('rent_roll'); ?>
        <a href="<?php echo BASE_URL; ?>modules/properties/create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> <?php _e('add_property', 'Add Property'); ?>
        </a>
    </div>
    <?php endif; ?>
</div>

<?php pageIntro('properties.index'); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <!-- Search -->
            <div class="col-12 col-md-3">
                <input type="text" class="form-control form-control-sm" name="search" placeholder="<?php _e('search'); ?>..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <!-- Type -->
            <div class="col-6 col-md-2">
                <select name="type" class="form-select form-select-sm">
                    <option value=""><?php _e('all_types', 'All Types'); ?></option>
                    <?php foreach ($typeLabels as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo $filterType === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Status -->
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all_statuses', 'All Statuses'); ?></option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>><?php _e('active'); ?></option>
                    <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>><?php _e('inactive'); ?></option>
                </select>
            </div>
            <!-- City -->
            <div class="col-6 col-md-2">
                <select name="city" class="form-select form-select-sm">
                    <option value=""><?php _e('all_cities', 'All Cities'); ?></option>
                    <?php foreach ($cities as $c): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $filterCity === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Buttons -->
            <div class="col-6 col-md-3 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/properties/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear', 'Clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Actions (shown when items selected) -->
<?php if (hasPermission('properties', 'edit')): ?>
<div id="bulkBar" class="alert alert-info d-flex align-items-center gap-3 d-none mb-3">
    <span><strong id="selectedCount">0</strong> <?php _e('selected', 'selected'); ?></span>
    <button class="btn btn-sm btn-success" onclick="bulkToggle('active')"><i class="bi bi-check-circle me-1"></i><?php _e('set_active', 'Set Active'); ?></button>
    <button class="btn btn-sm btn-warning" onclick="bulkToggle('inactive')"><i class="bi bi-pause-circle me-1"></i><?php _e('set_inactive', 'Set Inactive'); ?></button>
    <button class="btn btn-sm btn-outline-secondary ms-auto" onclick="clearSelection()"><i class="bi bi-x me-1"></i><?php _e('cancel'); ?></button>
</div>
<?php endif; ?>

<!-- Properties List -->
<?php if (empty($properties)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-buildings" style="font-size:3rem;color:#ccc;"></i>
        <h5 class="mt-3 text-muted"><?php _e('no_properties', 'No properties found'); ?></h5>
        <?php if (hasPermission('properties', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/properties/create.php" class="btn btn-primary mt-2">
            <i class="bi bi-plus-circle me-1"></i> <?php _e('add_property', 'Add Property'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<!-- Results info -->
<div class="d-flex justify-content-between align-items-center mb-2">
    <small class="text-muted">
        <?php echo __('showing', 'Showing'); ?> <?php echo $pagination['offset'] + 1; ?>-<?php echo min($pagination['offset'] + $perPage, $totalRecords); ?>
        <?php echo __('of', 'of'); ?> <?php echo $totalRecords; ?> <?php echo __('properties'); ?>
    </small>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <?php if (hasPermission('properties', 'edit')): ?>
                    <th style="width:40px;">
                        <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll(this)">
                    </th>
                    <?php endif; ?>
                    <th><?php _e('name'); ?></th>
                    <th><?php _e('type'); ?></th>
                    <th><?php _e('city'); ?></th>
                    <th class="text-center"><?php _e('units'); ?></th>
                    <th class="text-center"><?php _e('occupancy', 'Occupancy'); ?></th>
                    <th><?php _e('status'); ?></th>
                    <th style="width:120px;"><?php _e('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($properties as $prop): ?>
                <?php
                    $occupancy = $prop['unit_count'] > 0 ? round(($prop['occupied_count'] / $prop['unit_count']) * 100) : 0;
                    $occupancyClass = $occupancy >= 80 ? 'success' : ($occupancy >= 50 ? 'warning' : 'danger');
                ?>
                <tr>
                    <?php if (hasPermission('properties', 'edit')): ?>
                    <td>
                        <input type="checkbox" class="form-check-input prop-check" value="<?php echo $prop['id']; ?>" onchange="updateBulkBar()">
                    </td>
                    <?php endif; ?>
                    <td>
                        <a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $prop['id']; ?>" class="fw-semibold text-decoration-none">
                            <?php echo htmlspecialchars($prop['name']); ?>
                        </a>
                        <?php if ($prop['address']): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars(mb_strimwidth($prop['address'], 0, 50, '...')); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-light text-dark">
                            <i class="<?php echo $typeIcons[$prop['property_type']] ?? 'bi-building'; ?> me-1"></i>
                            <?php echo $typeLabels[$prop['property_type']] ?? ucfirst($prop['property_type']); ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($prop['city'] ?: '-'); ?></td>
                    <td class="text-center">
                        <span class="fw-semibold"><?php echo $prop['unit_count']; ?></span>
                        <?php if ($prop['unit_count'] > 0): ?>
                        <br><small class="text-success"><?php echo $prop['occupied_count']; ?> occ.</small>
                        <small class="text-danger"><?php echo $prop['vacant_count']; ?> vac.</small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($prop['unit_count'] > 0): ?>
                        <div class="progress" style="height:6px;width:60px;display:inline-block;" title="<?php echo $occupancy; ?>%">
                            <div class="progress-bar bg-<?php echo $occupancyClass; ?>" style="width:<?php echo $occupancy; ?>%"></div>
                        </div>
                        <br><small><?php echo $occupancy; ?>%</small>
                        <?php else: ?>
                        <small class="text-muted">-</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?php echo $prop['status'] === 'active' ? 'success' : 'secondary'; ?>">
                            <?php echo ucfirst($prop['status']); ?>
                        </span>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $prop['id']; ?>" class="btn btn-outline-primary" title="<?php _e('view'); ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (hasPermission('properties', 'edit')): ?>
                            <a href="<?php echo BASE_URL; ?>modules/properties/edit.php?id=<?php echo $prop['id']; ?>" class="btn btn-outline-secondary" title="<?php _e('edit'); ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (hasPermission('properties', 'delete')): ?>
                            <button class="btn btn-outline-danger" onclick="deleteProperty(<?php echo $prop['id']; ?>, '<?php echo htmlspecialchars(addslashes($prop['name'])); ?>')" title="<?php _e('delete'); ?>">
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
                <p><?php _e('delete_property_confirm', 'Are you sure you want to delete'); ?> <strong id="deleteName"></strong>?</p>
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
const apiUrl = BASE_URL + 'api/properties.php';

// Bulk selection
function toggleSelectAll(el) {
    document.querySelectorAll('.prop-check').forEach(cb => cb.checked = el.checked);
    updateBulkBar();
}

function updateBulkBar() {
    const checked = document.querySelectorAll('.prop-check:checked');
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
    document.querySelectorAll('.prop-check').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateBulkBar();
}

function bulkToggle(newStatus) {
    const ids = Array.from(document.querySelectorAll('.prop-check:checked')).map(cb => cb.value);
    if (!ids.length) return;

    const formData = new FormData();
    formData.append('action', 'toggle_status');
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

// Delete
let deleteId = null;
function deleteProperty(id, name) {
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
