<?php
/**
 * FIS Real Estate — Tenants List (7.1 + 7.5)
 * List view with filters (status, ID type), search (name, phone, email, id_number), pagination, bulk actions.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('tenants');

$subStatus = enforceSubscription();

if (!hasPermission('tenants', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Get filter values
$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterIdType = trim($_GET['id_type'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = RECORDS_PER_PAGE;

// Build WHERE clause
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(t.first_name LIKE :search OR t.last_name LIKE :search2 OR t.phone LIKE :search3 OR t.email LIKE :search4 OR t.id_number LIKE :search5 OR CONCAT(t.first_name, ' ', t.last_name) LIKE :search6)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
    $params['search4'] = "%{$search}%";
    $params['search5'] = "%{$search}%";
    $params['search6'] = "%{$search}%";
}
if ($filterStatus !== '') {
    $where[] = "t.status = :status";
    $params['status'] = $filterStatus;
}
if ($filterIdType !== '') {
    $where[] = "t.id_type = :id_type";
    $params['id_type'] = $filterIdType;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Property-based access scoping (tenant is in scope only via a lease in an allowed property)
$scopeSql = tenantScopeSql('t.id');
if ($scopeSql !== '') {
    $whereSql = ($whereSql === '' ? 'WHERE 1=1' : $whereSql) . $scopeSql;
}

// Count total
$countStmt = $conn->prepare("SELECT COUNT(*) FROM tenants t {$whereSql}");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$pagination = paginate($totalRecords, $page, $perPage);

// Fetch tenants with current unit info
$sql = "SELECT t.*,
        (SELECT CONCAT(u.unit_number, ' - ', p.name)
         FROM leases l
         INNER JOIN units u ON l.unit_id = u.id
         INNER JOIN properties p ON u.property_id = p.id
         WHERE l.tenant_id = t.id AND l.status = 'active'
         ORDER BY l.created_at DESC LIMIT 1
        ) AS current_unit
        FROM tenants t
        {$whereSql}
        ORDER BY t.created_at DESC
        LIMIT :offset, :limit";

$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->execute();
$tenants = $stmt->fetchAll();

$statusColors = ['active' => 'success', 'notice_given' => 'warning', 'moved_out' => 'secondary'];
$statusLabels = [
    'active' => __('active', 'Active'),
    'notice_given' => __('notice_given', 'Notice Given'),
    'moved_out' => __('moved_out', 'Moved Out'),
];

$idTypeLabels = [
    'national_id' => __('national_id', 'National ID'),
    'passport' => __('passport', 'Passport'),
    'driving_license' => __('driving_license', 'Driving License'),
    'voter_id' => __('voter_id', 'Voter ID'),
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-people me-2"></i><?php _e('tenants'); ?></h4>
        <p class="text-muted mb-0"><?php _e('manage_tenants', 'Manage your tenants'); ?></p>
    </div>
    <?php if (hasPermission('tenants', 'create')): ?>
    <div class="d-flex gap-2">
        <?php echo importButton('rent_roll'); ?>
        <a href="<?php echo BASE_URL; ?>modules/tenants/create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> <?php _e('add_tenant', 'Add Tenant'); ?>
        </a>
    </div>
    <?php endif; ?>
</div>

<?php pageIntro('tenants.index'); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <!-- Search -->
            <div class="col-12 col-md-4">
                <input type="text" class="form-control form-control-sm" name="search" placeholder="<?php _e('search_tenant_placeholder', 'Search by name, phone, email, ID...'); ?>" value="<?php echo htmlspecialchars($search); ?>">
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
            <!-- ID Type -->
            <div class="col-6 col-md-2">
                <select name="id_type" class="form-select form-select-sm">
                    <option value=""><?php _e('all_id_types', 'All ID Types'); ?></option>
                    <?php foreach ($idTypeLabels as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo $filterIdType === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Buttons -->
            <div class="col-12 col-md-4 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/tenants/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear', 'Clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Actions Bar -->
<?php if (hasPermission('tenants', 'edit')): ?>
<div id="bulkBar" class="alert alert-info d-flex align-items-center gap-2 flex-wrap d-none mb-3">
    <span><strong id="selectedCount">0</strong> <?php _e('selected', 'selected'); ?></span>
    <button class="btn btn-sm btn-success" onclick="bulkStatus('active')"><i class="bi bi-check-circle me-1"></i><?php _e('set_active', 'Set Active'); ?></button>
    <button class="btn btn-sm btn-warning" onclick="bulkStatus('notice_given')"><i class="bi bi-exclamation-triangle me-1"></i><?php _e('set_notice_given', 'Set Notice Given'); ?></button>
    <button class="btn btn-sm btn-secondary" onclick="bulkStatus('moved_out')"><i class="bi bi-box-arrow-right me-1"></i><?php _e('set_moved_out', 'Set Moved Out'); ?></button>
    <?php if (hasPermission('tenants', 'delete')): ?>
    <button class="btn btn-sm btn-danger" onclick="bulkDelete()"><i class="bi bi-trash me-1"></i><?php _e('delete'); ?></button>
    <?php endif; ?>
    <button class="btn btn-sm btn-outline-secondary ms-auto" onclick="clearSelection()"><i class="bi bi-x me-1"></i><?php _e('cancel'); ?></button>
</div>
<?php endif; ?>

<!-- Tenants List -->
<?php if (empty($tenants)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-people" style="font-size:3rem;color:#ccc;"></i>
        <h5 class="mt-3 text-muted"><?php _e('no_tenants', 'No tenants found'); ?></h5>
        <?php if (hasPermission('tenants', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/tenants/create.php" class="btn btn-primary mt-2">
            <i class="bi bi-plus-circle me-1"></i> <?php _e('add_tenant', 'Add Tenant'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<!-- Results info -->
<div class="d-flex justify-content-between align-items-center mb-2">
    <small class="text-muted">
        <?php echo __('showing', 'Showing'); ?> <?php echo $pagination['offset'] + 1; ?>-<?php echo min($pagination['offset'] + $perPage, $totalRecords); ?>
        <?php echo __('of', 'of'); ?> <?php echo $totalRecords; ?> <?php echo __('tenants'); ?>
    </small>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <?php if (hasPermission('tenants', 'edit')): ?>
                    <th style="width:40px;">
                        <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll(this)">
                    </th>
                    <?php endif; ?>
                    <th><?php _e('name'); ?></th>
                    <th><?php _e('phone'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('email'); ?></th>
                    <th class="d-none d-lg-table-cell"><?php _e('current_unit', 'Current Unit'); ?></th>
                    <th><?php _e('status'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('id_type_short', 'ID'); ?></th>
                    <th style="width:120px;"><?php _e('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tenants as $t): ?>
                <tr>
                    <?php if (hasPermission('tenants', 'edit')): ?>
                    <td>
                        <input type="checkbox" class="form-check-input tenant-check" value="<?php echo $t['id']; ?>" onchange="updateBulkBar()">
                    </td>
                    <?php endif; ?>
                    <td>
                        <a href="<?php echo BASE_URL; ?>modules/tenants/view.php?id=<?php echo $t['id']; ?>" class="fw-semibold text-decoration-none">
                            <?php echo htmlspecialchars($t['first_name'] . ' ' . $t['last_name']); ?>
                        </a>
                    </td>
                    <td><?php echo htmlspecialchars($t['phone']); ?></td>
                    <td class="d-none d-md-table-cell">
                        <small class="text-muted"><?php echo htmlspecialchars($t['email'] ?? '-'); ?></small>
                    </td>
                    <td class="d-none d-lg-table-cell">
                        <?php if ($t['current_unit']): ?>
                        <small><?php echo htmlspecialchars($t['current_unit']); ?></small>
                        <?php else: ?>
                        <small class="text-muted">-</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?php echo $statusColors[$t['status']] ?? 'secondary'; ?>">
                            <?php echo $statusLabels[$t['status']] ?? ucfirst($t['status']); ?>
                        </span>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <?php if ($t['id_type']): ?>
                        <small><?php echo $idTypeLabels[$t['id_type']] ?? ucfirst(str_replace('_', ' ', $t['id_type'])); ?></small>
                        <?php else: ?>
                        <small class="text-muted">-</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="<?php echo BASE_URL; ?>modules/tenants/view.php?id=<?php echo $t['id']; ?>" class="btn btn-outline-primary" title="<?php _e('view'); ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (hasPermission('tenants', 'edit')): ?>
                            <a href="<?php echo BASE_URL; ?>modules/tenants/edit.php?id=<?php echo $t['id']; ?>" class="btn btn-outline-secondary" title="<?php _e('edit'); ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (hasPermission('tenants', 'delete')): ?>
                            <button class="btn btn-outline-danger" onclick="deleteTenant(<?php echo $t['id']; ?>, '<?php echo htmlspecialchars(addslashes($t['first_name'] . ' ' . $t['last_name'])); ?>')" title="<?php _e('delete'); ?>">
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
                <p><?php _e('delete_tenant_confirm', 'Are you sure you want to delete tenant'); ?> <strong id="deleteName"></strong>?</p>
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
const apiUrl = BASE_URL + 'api/tenants.php';

// Bulk selection
function toggleSelectAll(el) {
    document.querySelectorAll('.tenant-check').forEach(cb => cb.checked = el.checked);
    updateBulkBar();
}

function updateBulkBar() {
    const checked = document.querySelectorAll('.tenant-check:checked');
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
    document.querySelectorAll('.tenant-check').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateBulkBar();
}

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.tenant-check:checked')).map(cb => cb.value);
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
    if (!confirm('<?php echo __("bulk_delete_tenants_confirm", "Delete selected tenants? This cannot be undone."); ?>')) return;

    // Delete one by one (no bulk_delete endpoint for tenants — active lease check needed per tenant)
    let deleted = 0, errors = [];
    const promises = ids.map(id => {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('csrf_token', csrfToken);
        formData.append('id', id);
        return fetch(apiUrl, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) deleted++;
                else errors.push(data.message);
            });
    });

    Promise.all(promises).then(() => {
        if (errors.length) {
            alert(deleted + ' deleted. Errors: ' + errors.join('; '));
        }
        location.reload();
    });
}

// Delete single
let deleteId = null;
function deleteTenant(id, name) {
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
