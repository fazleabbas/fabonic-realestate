<?php
/**
 * FIS Real Estate — Contractor Management (12.4)
 * Two-column layout: list + create/edit form.
 * Contact info, specialties, rating, hourly rate.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('maint_contractors', 'Contractors');

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

// Get contractors with job counts
$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(c.name LIKE :search OR c.company_name LIKE :search2 OR c.specialty LIKE :search3 OR c.phone LIKE :search4)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
    $params['search4'] = "%{$search}%";
}
if ($filterStatus !== '') {
    $where[] = "c.status = :status";
    $params['status'] = $filterStatus;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $conn->prepare("
    SELECT c.*,
        (SELECT COUNT(*) FROM maintenance_assignments ma WHERE ma.contractor_id = c.id) AS total_jobs,
        (SELECT COUNT(*) FROM maintenance_assignments ma WHERE ma.contractor_id = c.id AND ma.status = 'completed') AS completed_jobs,
        (SELECT COALESCE(SUM(ma.actual_cost), 0) FROM maintenance_assignments ma WHERE ma.contractor_id = c.id) AS total_revenue
    FROM contractors c
    {$whereSql}
    ORDER BY c.name
");
$stmt->execute($params);
$contractors = $stmt->fetchAll();

// Editing?
$editId = (int)($_GET['edit'] ?? 0);
$editData = null;
if ($editId) {
    $editStmt = $conn->prepare("SELECT * FROM contractors WHERE id = :id");
    $editStmt->execute(['id' => $editId]);
    $editData = $editStmt->fetch();
}

// Categories for reference
$catStmt = $conn->query("SELECT id, name FROM maintenance_categories ORDER BY name");
$categories = $catStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-people me-2"></i><?php _e('maint_contractors', 'Contractors'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/maintenance/"><?php _e('maintenance'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('maint_contractors', 'Contractors'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('maintenance.contractors'); ?>

<!-- Search & Filter -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <input type="text" class="form-control form-control-sm" name="search" placeholder="<?php _e('maint_search_contractor', 'Search name, company, specialty, phone...'); ?>" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-6 col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all_statuses'); ?></option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>><?php _e('active'); ?></option>
                    <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>><?php _e('inactive'); ?></option>
                </select>
            </div>
            <div class="col-6 col-md-5 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/maintenance/contractors.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3">
    <!-- Contractor List -->
    <div class="col-12 col-lg-7">
        <?php if (empty($contractors)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-people" style="font-size:3rem;color:#ccc;"></i>
                <h5 class="mt-3 text-muted"><?php _e('maint_no_contractors', 'No contractors found'); ?></h5>
                <p class="text-muted"><?php _e('maint_add_contractor_help', 'Add contractors using the form on the right.'); ?></p>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th><?php _e('name'); ?></th>
                            <th class="d-none d-md-table-cell"><?php _e('maint_specialty', 'Specialty'); ?></th>
                            <th class="d-none d-md-table-cell"><?php _e('phone'); ?></th>
                            <th class="d-none d-lg-table-cell"><?php _e('maint_rating', 'Rating'); ?></th>
                            <th class="d-none d-md-table-cell text-center"><?php _e('maint_jobs', 'Jobs'); ?></th>
                            <th><?php _e('status'); ?></th>
                            <th style="width:90px;"><?php _e('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contractors as $c): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($c['name']); ?></strong>
                                <?php if ($c['company_name']): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($c['company_name']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($c['specialty'] ?? '—'); ?></small></td>
                            <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($c['phone'] ?? '—'); ?></small></td>
                            <td class="d-none d-lg-table-cell">
                                <?php if ($c['rating']): ?>
                                <span class="text-warning">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="bi bi-star<?php echo $i <= round($c['rating']) ? '-fill' : ''; ?>" style="font-size:0.75rem;"></i>
                                    <?php endfor; ?>
                                </span>
                                <small class="text-muted ms-1"><?php echo number_format($c['rating'], 1); ?></small>
                                <?php else: ?>
                                <small class="text-muted">—</small>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-md-table-cell text-center">
                                <span class="badge bg-secondary"><?php echo (int)$c['completed_jobs']; ?>/<?php echo (int)$c['total_jobs']; ?></span>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $c['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                    <?php echo $c['status'] === 'active' ? __('active') : __('inactive'); ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="?edit=<?php echo $c['id']; ?>" class="btn btn-outline-primary" title="<?php _e('edit'); ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if (hasPermission('maintenance', 'delete')): ?>
                                    <button class="btn btn-outline-danger" onclick="deleteContractor(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>')" title="<?php _e('delete'); ?>">
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
        <?php endif; ?>
    </div>

    <!-- Create/Edit Form -->
    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-<?php echo $editData ? 'pencil' : 'plus-circle'; ?> me-1"></i>
                <?php echo $editData ? __('maint_edit_contractor', 'Edit Contractor') : __('maint_add_contractor', 'Add Contractor'); ?>
            </div>
            <div class="card-body">
                <form id="contractorForm">
                    <input type="hidden" name="id" value="<?php echo $editData['id'] ?? 0; ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?php _e('name'); ?> <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control form-control-sm" value="<?php echo htmlspecialchars($editData['name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?php _e('maint_company', 'Company'); ?></label>
                            <input type="text" name="company_name" class="form-control form-control-sm" value="<?php echo htmlspecialchars($editData['company_name'] ?? ''); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?php _e('phone'); ?></label>
                            <input type="text" name="phone" class="form-control form-control-sm" value="<?php echo htmlspecialchars($editData['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?php _e('email'); ?></label>
                            <input type="email" name="email" class="form-control form-control-sm" value="<?php echo htmlspecialchars($editData['email'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?php _e('maint_specialty', 'Specialty'); ?></label>
                            <input type="text" name="specialty" class="form-control form-control-sm" list="specialtyList" value="<?php echo htmlspecialchars($editData['specialty'] ?? ''); ?>" placeholder="<?php _e('maint_specialty_placeholder', 'e.g. Plumbing, Electrical, General...'); ?>">
                            <datalist id="specialtyList">
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat['name']); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold"><?php _e('maint_hourly_rate', 'Hourly Rate'); ?></label>
                            <input type="number" name="hourly_rate" class="form-control form-control-sm" step="0.01" min="0" value="<?php echo $editData['hourly_rate'] ?? ''; ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold"><?php _e('currency'); ?></label>
                            <input type="text" name="currency" class="form-control form-control-sm" value="<?php echo htmlspecialchars($editData['currency'] ?? DEFAULT_CURRENCY); ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold"><?php _e('maint_rating', 'Rating'); ?></label>
                            <input type="number" name="rating" class="form-control form-control-sm" step="0.1" min="0" max="5" value="<?php echo $editData['rating'] ?? ''; ?>" placeholder="0-5">
                        </div>
                        <div class="col-8">
                            <label class="form-label fw-semibold"><?php _e('notes'); ?></label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($editData['notes'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold"><?php _e('status'); ?></label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="active" <?php echo ($editData['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>><?php _e('active'); ?></option>
                                <option value="inactive" <?php echo ($editData['status'] ?? 'active') === 'inactive' ? 'selected' : ''; ?>><?php _e('inactive'); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between mt-3">
                        <?php if ($editData): ?>
                        <a href="<?php echo BASE_URL; ?>modules/maintenance/contractors.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-x me-1"></i> <?php _e('cancel'); ?>
                        </a>
                        <?php else: ?>
                        <div></div>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-primary" onclick="saveContractor()">
                            <i class="bi bi-check-lg me-1"></i> <?php echo $editData ? __('save_changes') : __('maint_add_contractor', 'Add Contractor'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo generateCSRF(); ?>';
const apiUrl = BASE_URL + 'api/maintenance.php';

function saveContractor() {
    const form = document.getElementById('contractorForm');
    const formData = new FormData(form);
    formData.append('action', 'save_contractor');
    formData.append('csrf_token', csrfToken);

    if (!formData.get('name').trim()) {
        alert('<?php echo __("maint_name_required", "Name is required"); ?>');
        return;
    }

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = '<?php echo BASE_URL; ?>modules/maintenance/contractors.php';
            } else {
                alert(data.message);
            }
        })
        .catch(() => alert('Request failed'));
}

function deleteContractor(id, name) {
    if (!confirm('<?php echo __("maint_delete_contractor_confirm", "Delete contractor"); ?> "' + name + '"?')) return;

    const formData = new FormData();
    formData.append('action', 'delete_contractor');
    formData.append('csrf_token', csrfToken);
    formData.append('id', id);

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
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
