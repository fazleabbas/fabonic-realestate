<?php
/**
 * FIS Real Estate — Role & Permission Management (Part 14.4)
 * Create roles, granular permission editor, default roles, user counts.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('roles', 'Roles');

$subStatus = enforceSubscription();

if (getUserData('role_name') !== 'Admin') {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Load roles with user counts
$roles = $conn->query("
    SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count
    FROM roles r ORDER BY r.id
")->fetchAll();

// Edit mode
$editId = (int)($_GET['edit'] ?? 0);
$editRole = null;
$editPermissions = [];
if ($editId > 0) {
    foreach ($roles as $r) {
        if ($r['id'] == $editId) {
            $editRole = $r;
            $editPermissions = json_decode($r['permissions'] ?? '{}', true) ?: [];
            break;
        }
    }
}

// Define available modules and their actions
$permissionModules = [
    'properties' => ['label' => __('properties', 'Properties'), 'icon' => 'bi-buildings', 'actions' => ['view', 'create', 'edit', 'delete']],
    'units' => ['label' => __('units', 'Units'), 'icon' => 'bi-door-open', 'actions' => ['view', 'create', 'edit', 'delete']],
    'tenants' => ['label' => __('tenants', 'Tenants'), 'icon' => 'bi-people', 'actions' => ['view', 'create', 'edit', 'delete']],
    'leases' => ['label' => __('leases', 'Leases'), 'icon' => 'bi-file-earmark-text', 'actions' => ['view', 'create', 'edit', 'delete']],
    'payments' => ['label' => __('payments', 'Payments'), 'icon' => 'bi-cash-coin', 'actions' => ['view', 'create', 'edit', 'delete']],
    'deposits' => ['label' => __('deposits', 'Deposits'), 'icon' => 'bi-safe', 'actions' => ['view', 'create', 'edit', 'delete']],
    'inspections' => ['label' => __('inspections', 'Inspections'), 'icon' => 'bi-clipboard-check', 'actions' => ['view', 'create', 'edit', 'delete']],
    'maintenance' => ['label' => __('maintenance', 'Maintenance'), 'icon' => 'bi-tools', 'actions' => ['view', 'create', 'edit', 'delete']],
    'documents' => ['label' => __('doc_documents', 'Documents'), 'icon' => 'bi-folder2-open', 'actions' => ['view', 'create', 'edit', 'delete']],
    'reports' => ['label' => __('reports', 'Reports'), 'icon' => 'bi-bar-chart-line', 'actions' => ['view']],
    'settings' => ['label' => __('settings', 'Settings'), 'icon' => 'bi-gear', 'actions' => ['view', 'edit']],
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-shield-lock me-2"></i><?php _e('roles', 'Roles'); ?></h4>
        <p class="text-muted mb-0"><?php _e('role_desc', 'Manage roles and set granular module-level permissions'); ?></p>
    </div>
</div>

<?php pageIntro('masters.roles'); ?>

<div class="row g-4">
    <!-- Roles List -->
    <div class="col-12 col-lg-5">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list me-1"></i> <?php _e('role_all', 'All Roles'); ?></span>
                <button type="button" class="btn btn-sm btn-primary" onclick="newRole()">
                    <i class="bi bi-plus me-1"></i> <?php _e('role_add', 'Add Role'); ?>
                </button>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($roles as $role):
                        $perms = json_decode($role['permissions'] ?? '{}', true) ?: [];
                        $isAll = !empty($perms['all']);
                        $isActive = $editId == $role['id'];
                    ?>
                    <div class="list-group-item <?php echo $isActive ? 'bg-light' : ''; ?> d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-semibold">
                                <?php echo htmlspecialchars($role['name']); ?>
                                <?php if ($role['is_system']): ?>
                                <span class="badge bg-secondary ms-1" style="font-size:0.65rem;"><?php _e('role_system', 'System'); ?></span>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted">
                                <?php echo htmlspecialchars($role['description'] ?? ''); ?>
                                · <?php echo $role['user_count']; ?> <?php _e('users'); ?>
                            </small>
                            <div class="mt-1">
                                <?php if ($isAll): ?>
                                <span class="badge bg-danger" style="font-size:0.65rem;"><?php _e('role_full_access', 'Full Access'); ?></span>
                                <?php else:
                                    $moduleCount = count(array_filter(array_keys($perms), function($k) { return $k !== 'all'; }));
                                ?>
                                <span class="badge bg-info" style="font-size:0.65rem;"><?php echo $moduleCount; ?> <?php _e('role_modules', 'modules'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex gap-1">
                            <a href="?edit=<?php echo $role['id']; ?>" class="btn btn-sm btn-outline-primary" title="<?php _e('edit'); ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if (!$role['is_system']): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteRole(<?php echo $role['id']; ?>, '<?php echo htmlspecialchars($role['name']); ?>', <?php echo $role['user_count']; ?>)" title="<?php _e('delete'); ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Role Editor -->
    <div class="col-12 col-lg-7">
        <div class="card" id="roleEditor">
            <div class="card-header">
                <i class="bi bi-pencil-square me-1"></i>
                <span id="roleEditorTitle"><?php echo $editRole ? __('role_edit', 'Edit Role') . ': ' . htmlspecialchars($editRole['name']) : __('role_add', 'Add Role'); ?></span>
            </div>
            <div class="card-body">
                <form id="roleForm" onsubmit="saveRole(event)">
                    <input type="hidden" name="id" id="roleId" value="<?php echo $editRole['id'] ?? ''; ?>">

                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('role_name', 'Role Name'); ?> <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="roleName" class="form-control" required
                                value="<?php echo htmlspecialchars($editRole['name'] ?? ''); ?>"
                                <?php echo ($editRole && $editRole['is_system'] && $editRole['name'] === 'Admin') ? 'readonly' : ''; ?>>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('description'); ?></label>
                            <input type="text" name="description" id="roleDesc" class="form-control"
                                value="<?php echo htmlspecialchars($editRole['description'] ?? ''); ?>">
                        </div>
                    </div>

                    <?php if (!$editRole || !($editRole['is_system'] && $editRole['name'] === 'Admin')): ?>
                    <!-- Permission Grid -->
                    <h6 class="mb-3"><i class="bi bi-shield-check me-1"></i> <?php _e('role_permissions', 'Permissions'); ?></h6>
                    <div class="mb-3">
                        <button type="button" class="btn btn-sm btn-outline-success me-1" onclick="selectAllPerms()"><?php _e('role_select_all', 'Select All'); ?></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAllPerms()"><?php _e('role_clear_all', 'Clear All'); ?></button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th style="width:200px;"><?php _e('module'); ?></th>
                                    <th class="text-center" style="width:80px;"><?php _e('role_view', 'View'); ?></th>
                                    <th class="text-center" style="width:80px;"><?php _e('role_create', 'Create'); ?></th>
                                    <th class="text-center" style="width:80px;"><?php _e('role_edit_perm', 'Edit'); ?></th>
                                    <th class="text-center" style="width:80px;"><?php _e('role_delete_perm', 'Delete'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($permissionModules as $modKey => $mod):
                                    $currentPerms = $editPermissions[$modKey] ?? [];
                                    if (!is_array($currentPerms)) $currentPerms = [];
                                ?>
                                <tr>
                                    <td>
                                        <i class="bi <?php echo $mod['icon']; ?> me-1 text-muted"></i>
                                        <span class="fw-semibold"><?php echo $mod['label']; ?></span>
                                    </td>
                                    <?php foreach (['view', 'create', 'edit', 'delete'] as $act): ?>
                                    <td class="text-center">
                                        <?php if (in_array($act, $mod['actions'])): ?>
                                        <input type="checkbox" class="form-check-input perm-check"
                                            data-module="<?php echo $modKey; ?>" data-action="<?php echo $act; ?>"
                                            <?php echo in_array($act, $currentPerms) ? 'checked' : ''; ?>>
                                        <?php else: ?>
                                        <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-1"></i> <?php _e('role_admin_note', 'The Admin role has full access to all modules. Permissions cannot be modified.'); ?>
                    </div>
                    <?php endif; ?>

                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i> <?php echo $editRole ? __('save_changes') : __('role_create_btn', 'Create Role'); ?>
                        </button>
                        <?php if ($editRole): ?>
                        <a href="<?php echo BASE_URL; ?>modules/masters/roles.php" class="btn btn-outline-secondary"><?php _e('cancel'); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Role Modal -->
<div class="modal fade" id="deleteRoleModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i> <?php _e('confirm_delete'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('role_delete_confirm', 'Delete role'); ?> <strong id="deleteRoleName"></strong>?</p>
                <p id="deleteRoleWarning" class="text-danger" style="display:none;"></p>
            </div>
            <div class="modal-footer">
                <input type="hidden" id="deleteRoleId">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="deleteRoleBtn" onclick="submitDeleteRole()">
                    <i class="bi bi-trash me-1"></i> <?php _e('delete'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function newRole() {
    window.location.href = '<?php echo BASE_URL; ?>modules/masters/roles.php';
}

function saveRole(e) {
    e.preventDefault();

    const id = document.getElementById('roleId').value;
    const name = document.getElementById('roleName').value.trim();
    const desc = document.getElementById('roleDesc').value.trim();

    if (!name) { alert('<?php echo __("role_name_required", "Role name is required"); ?>'); return; }

    // Build permissions JSON from checkboxes
    const permissions = {};
    document.querySelectorAll('.perm-check:checked').forEach(cb => {
        const mod = cb.dataset.module;
        const act = cb.dataset.action;
        if (!permissions[mod]) permissions[mod] = [];
        permissions[mod].push(act);
    });

    const fd = new FormData();
    fd.append('action', id ? 'update_role' : 'create_role');
    if (id) fd.append('id', id);
    fd.append('name', name);
    fd.append('description', desc);
    fd.append('permissions', JSON.stringify(permissions));

    fetch(BASE_URL + 'api/settings.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) { location.href = '<?php echo BASE_URL; ?>modules/masters/roles.php'; }
            else { alert(res.message || 'Error'); }
        })
        .catch(() => alert('Error saving role'));
}

function selectAllPerms() {
    document.querySelectorAll('.perm-check').forEach(cb => cb.checked = true);
}

function clearAllPerms() {
    document.querySelectorAll('.perm-check').forEach(cb => cb.checked = false);
}

function deleteRole(id, name, userCount) {
    document.getElementById('deleteRoleId').value = id;
    document.getElementById('deleteRoleName').textContent = name;

    const warning = document.getElementById('deleteRoleWarning');
    const btn = document.getElementById('deleteRoleBtn');
    if (userCount > 0) {
        warning.textContent = '<?php echo __("role_has_users", "This role has"); ?> ' + userCount + ' <?php echo __("role_users_assigned", "users assigned. Reassign them first."); ?>';
        warning.style.display = '';
        btn.disabled = true;
    } else {
        warning.style.display = 'none';
        btn.disabled = false;
    }

    new bootstrap.Modal(document.getElementById('deleteRoleModal')).show();
}

function submitDeleteRole() {
    const id = document.getElementById('deleteRoleId').value;
    const fd = new FormData();
    fd.append('action', 'delete_role');
    fd.append('id', id);

    fetch(BASE_URL + 'api/settings.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) { location.reload(); }
            else { alert(res.message || 'Error'); }
        });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
