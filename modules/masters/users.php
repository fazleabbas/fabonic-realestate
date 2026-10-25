<?php
/**
 * FIS Real Estate — User Management (Part 14.3)
 * List, create, edit, delete users, assign roles, reset passwords.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('users', 'Users');

$subStatus = enforceSubscription();

if (getUserData('role_name') !== 'Admin') {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Filters
$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterRole = (int)($_GET['role_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));

// Build query
$where = "1=1";
$params = [];

if ($search) {
    $where .= " AND (u.full_name LIKE :s OR u.username LIKE :s2 OR u.email LIKE :s3 OR u.phone LIKE :s4)";
    $params['s'] = "%{$search}%";
    $params['s2'] = "%{$search}%";
    $params['s3'] = "%{$search}%";
    $params['s4'] = "%{$search}%";
}
if ($filterStatus) {
    $where .= " AND u.status = :status";
    $params['status'] = $filterStatus;
}
if ($filterRole > 0) {
    $where .= " AND u.role_id = :rid";
    $params['rid'] = $filterRole;
}

// Count
$countStmt = $conn->prepare("SELECT COUNT(*) FROM users u WHERE {$where}");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$pagination = paginate($totalRecords, $page);

// Fetch users
$sql = "SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role_id, u.language,
               u.status, u.last_login, u.created_at,
               r.name AS role_name
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE {$where}
        ORDER BY u.id ASC
        LIMIT :offset, :lim";
$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':lim', $pagination['records_per_page'], PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

// Roles for dropdown
$roles = $conn->query("SELECT id, name FROM roles ORDER BY id")->fetchAll();

// Properties for user↔property mapping (Part 24)
$propsList = $conn->query("SELECT id, name FROM properties ORDER BY name")->fetchAll();
$scopingOn = getSetting('property_scoping_enabled', '0') === '1';

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-person-badge me-2"></i><?php _e('users', 'Users'); ?></h4>
        <p class="text-muted mb-0"><?php _e('usr_desc', 'Manage system users, assign roles, and reset passwords'); ?></p>
    </div>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#userModal" onclick="resetUserForm()">
        <i class="bi bi-plus-circle me-1"></i> <?php _e('usr_add', 'Add User'); ?>
    </button>
</div>

<?php pageIntro('masters.users'); ?>

<!-- Property Scoping Master Switch (Part 24) -->
<div class="card mb-3 border-<?php echo $scopingOn ? 'success' : 'warning'; ?>">
    <div class="card-body py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <span class="fw-semibold"><i class="bi bi-shield-lock me-1"></i><?php _e('usr_scoping_title', 'Property Access Scoping'); ?></span>
            <span class="badge bg-<?php echo $scopingOn ? 'success' : 'secondary'; ?> ms-1"><?php echo $scopingOn ? __('on', 'ON') : __('off', 'OFF'); ?></span>
            <div><small class="text-muted">
                <?php if ($scopingOn): ?>
                <?php _e('usr_scoping_on_desc', 'Non-admin users can only access their assigned properties. Users with no assignment see nothing.'); ?>
                <?php else: ?>
                <?php _e('usr_scoping_off_desc', 'All users see all data (default). Map users to properties below, then turn this ON to enforce.'); ?>
                <?php endif; ?>
            </small></div>
        </div>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="scopingToggle" style="width:3em;height:1.5em;" <?php echo $scopingOn ? 'checked' : ''; ?>>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="<?php _e('usr_search_ph', 'Search name, username, email...'); ?>" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all_statuses', 'All Statuses'); ?></option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>><?php _e('active'); ?></option>
                    <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>><?php _e('inactive'); ?></option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="role_id" class="form-select form-select-sm">
                    <option value=""><?php _e('all_roles', 'All Roles'); ?></option>
                    <?php foreach ($roles as $role): ?>
                    <option value="<?php echo $role['id']; ?>" <?php echo $filterRole == $role['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($role['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-5 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i><?php _e('search'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/masters/users.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people me-1"></i> <?php _e('users'); ?> (<?php echo $totalRecords; ?>)</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($users)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-person-x" style="font-size:2.5rem;"></i>
            <p class="mt-2 mb-0"><?php _e('usr_none', 'No users found.'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php _e('usr_full_name', 'Full Name'); ?></th>
                        <th><?php _e('username'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('email'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('phone'); ?></th>
                        <th><?php _e('role'); ?></th>
                        <th class="text-center"><?php _e('status'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('usr_last_login', 'Last Login'); ?></th>
                        <th class="text-center"><?php _e('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td class="text-muted"><?php echo $user['id']; ?></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($user['full_name']); ?></td>
                        <td><code><?php echo htmlspecialchars($user['username']); ?></code></td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($user['email'] ?? '—'); ?></small></td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($user['phone'] ?? '—'); ?></small></td>
                        <td><span class="badge bg-<?php echo $user['role_name'] === 'Admin' ? 'danger' : 'primary'; ?>"><?php echo htmlspecialchars($user['role_name'] ?? 'N/A'); ?></span></td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo $user['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                <?php echo ucfirst($user['status']); ?>
                            </span>
                        </td>
                        <td class="d-none d-md-table-cell"><small><?php echo $user['last_login'] ? formatDateTime($user['last_login']) : '—'; ?></small></td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary" onclick="editUser(<?php echo $user['id']; ?>)" title="<?php _e('edit'); ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-outline-warning" onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>')" title="<?php _e('usr_reset_pwd', 'Reset Password'); ?>">
                                    <i class="bi bi-key"></i>
                                </button>
                                <?php if ($user['id'] != getUserId()): ?>
                                <button type="button" class="btn btn-outline-danger" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>')" title="<?php _e('delete'); ?>">
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
        <?php endif; ?>
    </div>
</div>

<!-- Pagination -->
<?php if ($pagination['total_pages'] > 1): ?>
<nav>
    <ul class="pagination pagination-sm justify-content-center">
        <li class="page-item <?php echo !$pagination['has_prev'] ? 'disabled' : ''; ?>">
            <a class="page-link" href="?page=<?php echo $pagination['prev_page']; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filterStatus); ?>&role_id=<?php echo $filterRole; ?>">&laquo;</a>
        </li>
        <?php for ($p = 1; $p <= $pagination['total_pages']; $p++): ?>
        <li class="page-item <?php echo $p == $pagination['current_page'] ? 'active' : ''; ?>">
            <a class="page-link" href="?page=<?php echo $p; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filterStatus); ?>&role_id=<?php echo $filterRole; ?>"><?php echo $p; ?></a>
        </li>
        <?php endfor; ?>
        <li class="page-item <?php echo !$pagination['has_next'] ? 'disabled' : ''; ?>">
            <a class="page-link" href="?page=<?php echo $pagination['next_page']; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filterStatus); ?>&role_id=<?php echo $filterRole; ?>">&raquo;</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<!-- User Create/Edit Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle"><?php _e('usr_add', 'Add User'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="userForm">
                    <input type="hidden" name="id" id="userId">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('username'); ?> <span class="text-danger">*</span></label>
                            <input type="text" name="username" id="userUsername" class="form-control" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('usr_full_name', 'Full Name'); ?> <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" id="userFullName" class="form-control" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('email'); ?></label>
                            <input type="email" name="email" id="userEmail" class="form-control">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('phone'); ?></label>
                            <input type="tel" name="phone" id="userPhone" class="form-control">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('role'); ?></label>
                            <select name="role_id" id="userRole" class="form-select">
                                <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('status'); ?></label>
                            <select name="status" id="userStatus" class="form-select">
                                <option value="active"><?php _e('active'); ?></option>
                                <option value="inactive"><?php _e('inactive'); ?></option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('set_language', 'Language'); ?></label>
                            <select name="language" id="userLang" class="form-select">
                                <option value="en">English</option>
                                <option value="sw">Kiswahili</option>
                                <option value="ar">العربية</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_approve" value="1" id="userCanApprove">
                                <label class="form-check-label fw-semibold" for="userCanApprove">
                                    <i class="bi bi-check2-square me-1"></i><?php _e('usr_can_approve', 'Can approve payments and deposits'); ?>
                                </label>
                                <div><small class="text-muted"><?php _e('usr_can_approve_hint', 'Lets this user approve ledger entries. They still cannot approve anything they created themselves.'); ?></small></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6" id="passwordGroup">
                            <label class="form-label fw-semibold"><?php _e('password'); ?> <span class="text-danger">*</span></label>
                            <input type="password" name="password" id="userPassword" class="form-control" minlength="6">
                            <small class="text-muted"><?php _e('usr_pwd_hint', 'Minimum 6 characters'); ?></small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-buildings me-1"></i><?php _e('usr_assigned_properties', 'Assigned Properties'); ?>
                            </label>
                            <select name="property_ids[]" id="userProperties" class="form-select" multiple size="5">
                                <?php foreach ($propsList as $prop): ?>
                                <option value="<?php echo $prop['id']; ?>"><?php echo htmlspecialchars($prop['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">
                                <?php if ($scopingOn): ?>
                                <?php _e('usr_props_hint_on', 'Non-admin users can only access data for the properties selected here. Admins always see everything.'); ?>
                                <?php else: ?>
                                <i class="bi bi-info-circle me-1"></i><?php _e('usr_props_hint_off', 'Property scoping is currently OFF — these assignments are saved but not yet enforced.'); ?>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary" onclick="saveUser()">
                    <i class="bi bi-check-circle me-1"></i> <span id="userSaveBtn"><?php _e('save'); ?></span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPwdModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key me-1"></i> <?php _e('usr_reset_pwd', 'Reset Password'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><?php _e('usr_reset_for', 'Reset password for'); ?>: <strong id="resetPwdName"></strong></p>
                <input type="hidden" id="resetPwdId">
                <label class="form-label fw-semibold"><?php _e('usr_new_password', 'New Password'); ?></label>
                <input type="password" id="resetPwdInput" class="form-control" minlength="6">
                <small class="text-muted"><?php _e('usr_pwd_hint', 'Minimum 6 characters'); ?></small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-warning" onclick="submitResetPassword()">
                    <i class="bi bi-key me-1"></i> <?php _e('usr_reset', 'Reset'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteUserModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i> <?php _e('confirm_delete'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php _e('usr_delete_confirm', 'Are you sure you want to delete user'); ?> <strong id="deleteUserName"></strong>?</p>
            </div>
            <div class="modal-footer">
                <input type="hidden" id="deleteUserId">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-danger" onclick="submitDeleteUser()">
                    <i class="bi bi-trash me-1"></i> <?php _e('delete'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let editMode = false;

// Property scoping master switch
const scopingToggle = document.getElementById('scopingToggle');
if (scopingToggle) {
    scopingToggle.addEventListener('change', function () {
        const on = this.checked;
        if (on && !confirm('<?php echo __("usr_scoping_confirm", "Turn ON property scoping? Non-admin users with no assigned properties will immediately lose access to all data until you map them."); ?>')) {
            this.checked = false;
            return;
        }
        const fd = new FormData();
        fd.append('action', 'set_scoping');
        fd.append('enabled', on ? '1' : '0');
        fetch(BASE_URL + 'api/settings.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) { location.reload(); }
                else { alert(res.message || 'Error'); this.checked = !on; }
            })
            .catch(() => { alert('Request failed'); this.checked = !on; });
    });
}

function resetUserForm() {
    editMode = false;
    document.getElementById('userModalTitle').textContent = '<?php echo __("usr_add", "Add User"); ?>';
    document.getElementById('userSaveBtn').textContent = '<?php echo __("save"); ?>';
    document.getElementById('userId').value = '';
    document.getElementById('userUsername').value = '';
    document.getElementById('userUsername').disabled = false;
    document.getElementById('userFullName').value = '';
    document.getElementById('userEmail').value = '';
    document.getElementById('userPhone').value = '';
    document.getElementById('userCanApprove').checked = false;
    document.getElementById('userRole').value = '1';
    document.getElementById('userStatus').value = 'active';
    document.getElementById('userLang').value = 'en';
    document.getElementById('userPassword').value = '';
    document.getElementById('passwordGroup').style.display = '';
    document.getElementById('userPassword').required = true;
    // Clear property selections
    Array.from(document.getElementById('userProperties').options).forEach(o => o.selected = false);
}

function editUser(id) {
    editMode = true;
    document.getElementById('userModalTitle').textContent = '<?php echo __("usr_edit", "Edit User"); ?>';
    document.getElementById('userSaveBtn').textContent = '<?php echo __("save_changes"); ?>';
    document.getElementById('passwordGroup').style.display = 'none';
    document.getElementById('userPassword').required = false;

    fetch(BASE_URL + 'api/settings.php?action=get_user&id=' + id)
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                const u = res.data;
                document.getElementById('userId').value = u.id;
                document.getElementById('userUsername').value = u.username;
                document.getElementById('userUsername').disabled = true;
                document.getElementById('userFullName').value = u.full_name;
                document.getElementById('userEmail').value = u.email || '';
                document.getElementById('userPhone').value = u.phone || '';
                document.getElementById('userRole').value = u.role_id;
                document.getElementById('userStatus').value = u.status;
                document.getElementById('userLang').value = u.language || 'en';
                document.getElementById('userCanApprove').checked = Number(u.can_approve) === 1;
                // Select this user's assigned properties
                const assigned = (u.property_ids || []).map(String);
                Array.from(document.getElementById('userProperties').options).forEach(o => {
                    o.selected = assigned.includes(o.value);
                });
                new bootstrap.Modal(document.getElementById('userModal')).show();
            } else {
                alert(res.message || 'Error loading user');
            }
        });
}

function saveUser() {
    const form = document.getElementById('userForm');
    const fd = new FormData(form);
    fd.append('action', editMode ? 'update_user' : 'create_user');

    // Remove disabled field for create
    if (!editMode && document.getElementById('userUsername').disabled) {
        document.getElementById('userUsername').disabled = false;
    }

    fetch(BASE_URL + 'api/settings.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                location.reload();
            } else {
                alert(res.message || 'Error saving user');
            }
        })
        .catch(() => alert('Error saving user'));
}

function resetPassword(id, name) {
    document.getElementById('resetPwdId').value = id;
    document.getElementById('resetPwdName').textContent = name;
    document.getElementById('resetPwdInput').value = '';
    new bootstrap.Modal(document.getElementById('resetPwdModal')).show();
}

function submitResetPassword() {
    const id = document.getElementById('resetPwdId').value;
    const pwd = document.getElementById('resetPwdInput').value;
    if (!pwd || pwd.length < 6) { alert('<?php echo __("usr_pwd_hint", "Minimum 6 characters"); ?>'); return; }

    const fd = new FormData();
    fd.append('action', 'reset_password');
    fd.append('id', id);
    fd.append('new_password', pwd);

    fetch(BASE_URL + 'api/settings.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('resetPwdModal')).hide();
                alert('<?php echo __("usr_pwd_reset_ok", "Password reset successfully"); ?>');
            } else {
                alert(res.message || 'Error');
            }
        });
}

function deleteUser(id, name) {
    document.getElementById('deleteUserId').value = id;
    document.getElementById('deleteUserName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
}

function submitDeleteUser() {
    const id = document.getElementById('deleteUserId').value;
    const fd = new FormData();
    fd.append('action', 'delete_user');
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
