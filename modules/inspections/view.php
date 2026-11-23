<?php
/**
 * FIS Real Estate — View/Edit Inspection (11.2)
 * Inspection detail page with room-by-room checklist, condition rating,
 * photo upload per item, status management, activity log.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

$subStatus = enforceSubscription();

if (!hasPermission('inspections', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Inspection not found', 'danger');
    redirect('modules/inspections/');
}

// Fetch inspection with related data
$stmt = $conn->prepare("
    SELECT i.*, u.unit_number, u.unit_label, u.id AS unit_id_val,
           p.name AS property_name, p.id AS property_id_val,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
           t.phone AS tenant_phone, t.email AS tenant_email, t.id AS tenant_id_val,
           l.start_date AS lease_start, l.end_date AS lease_end,
           l.rent_amount AS lease_rent, l.currency AS lease_currency, l.status AS lease_status
    FROM inspections i
    INNER JOIN units u ON i.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    LEFT JOIN tenants t ON i.tenant_id = t.id
    LEFT JOIN leases l ON i.lease_id = l.id
    WHERE i.id = :id
");
$stmt->execute(['id' => $id]);
$inspection = $stmt->fetch();

if (!$inspection) {
    setAlert('Inspection not found', 'danger');
    redirect('modules/inspections/');
}

if (!canAccessUnit($inspection['unit_id'])) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/inspections/');
}

$pageTitle = __('inspection', 'Inspection') . ' #' . $id;

// Get items grouped by room
$itemStmt = $conn->prepare("SELECT * FROM inspection_items WHERE inspection_id = :iid ORDER BY room, item_name");
$itemStmt->execute(['iid' => $id]);
$allItems = $itemStmt->fetchAll();

$itemsByRoom = [];
foreach ($allItems as $item) {
    $itemsByRoom[$item['room']][] = $item;
}

// Stats
$totalItems = count($allItems);
$completedItems = 0;
$damagedItems = 0;
foreach ($allItems as $item) {
    if ($item['condition'] !== 'good') $completedItems++;
    if (in_array($item['condition'], ['poor', 'damaged', 'missing'])) $damagedItems++;
}

// Activity log
$historyStmt = $conn->prepare("
    SELECT al.*, u.full_name AS user_name
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.entity_type = 'inspection' AND al.entity_id = :id
    ORDER BY al.created_at DESC
    LIMIT 15
");
$historyStmt->execute(['id' => $id]);
$activityHistory = $historyStmt->fetchAll();

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
$condColors = [
    'excellent' => 'success', 'good' => 'primary', 'fair' => 'info',
    'poor' => 'warning', 'damaged' => 'danger', 'missing' => 'dark'
];
$condLabels = [
    'excellent' => __('cond_excellent', 'Excellent'),
    'good' => __('cond_good', 'Good'),
    'fair' => __('cond_fair', 'Fair'),
    'poor' => __('cond_poor', 'Poor'),
    'damaged' => __('cond_damaged', 'Damaged'),
    'missing' => __('cond_missing', 'Missing'),
];

$isEditable = $inspection['status'] !== 'completed';

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-clipboard-check me-2"></i><?php echo $pageTitle; ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/inspections/"><?php _e('inspections'); ?></a></li>
                <li class="breadcrumb-item active">#<?php echo $id; ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?php echo BASE_URL; ?>modules/inspections/report.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
            <i class="bi bi-printer me-1"></i> <?php _e('insp_print_report', 'Print Report'); ?>
        </a>
        <?php if ($inspection['inspection_type'] === 'move_out' && $inspection['status'] === 'completed' && $inspection['lease_id']): ?>
        <a href="<?php echo BASE_URL; ?>modules/inspections/deductions.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-cash-coin me-1"></i> <?php _e('insp_link_deductions', 'Link to Deposit'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('inspections.view'); ?>

<!-- Info Bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span class="badge bg-<?php echo $typeColors[$inspection['inspection_type']] ?? 'secondary'; ?>" style="font-size:0.85rem;">
                <?php echo $typeLabels[$inspection['inspection_type']] ?? ucfirst(str_replace('_', ' ', $inspection['inspection_type'])); ?>
            </span>
            <span class="badge bg-<?php echo $statusColors[$inspection['status']] ?? 'secondary'; ?>" style="font-size:0.85rem;">
                <?php echo $statusLabels[$inspection['status']] ?? ucfirst($inspection['status']); ?>
            </span>
            <?php if ($inspection['tenant_name']): ?>
            <span><i class="bi bi-person me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/tenants/view.php?id=<?php echo $inspection['tenant_id_val']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($inspection['tenant_name']); ?></a>
            </span>
            <?php endif; ?>
            <span><i class="bi bi-door-open me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/units/view.php?id=<?php echo $inspection['unit_id_val']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($inspection['unit_number']); ?></a>
            </span>
            <span><i class="bi bi-buildings me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $inspection['property_id_val']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($inspection['property_name']); ?></a>
            </span>
            <?php if ($inspection['lease_id']): ?>
            <span><i class="bi bi-file-earmark-text me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/leases/view.php?id=<?php echo $inspection['lease_id']; ?>" class="text-decoration-none"><?php _e('lease'); ?> #<?php echo $inspection['lease_id']; ?></a>
            </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-calendar-check"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatDate($inspection['inspection_date']); ?></div>
                <div class="stat-card-label"><?php _e('insp_date', 'Date'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-list-check"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $totalItems; ?></div>
                <div class="stat-card-label"><?php _e('insp_total_items', 'Total Items'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-exclamation-triangle"></i></div>
            <div>
                <div class="stat-card-value text-danger"><?php echo $damagedItems; ?></div>
                <div class="stat-card-label"><?php _e('insp_issues_found', 'Issues Found'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#f3e8ff;color:#6b21a8;"><i class="bi bi-person-badge"></i></div>
            <div>
                <div class="stat-card-value" style="font-size:0.9rem;"><?php echo htmlspecialchars($inspection['inspector_name'] ?: '—'); ?></div>
                <div class="stat-card-label"><?php _e('inspector'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Room-by-Room Checklist -->
<form id="inspectionForm">
    <input type="hidden" name="action" value="save_items">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRF(); ?>">
    <input type="hidden" name="inspection_id" value="<?php echo $id; ?>">

    <?php if (empty($itemsByRoom)): ?>
    <div class="card mb-4">
        <div class="card-body text-center py-5">
            <i class="bi bi-clipboard" style="font-size:3rem;color:#ccc;"></i>
            <h5 class="mt-3 text-muted"><?php _e('insp_no_items', 'No inspection items yet'); ?></h5>
            <p class="text-muted"><?php _e('insp_no_items_help', 'Add items using the button below, or go back and select a template.'); ?></p>
        </div>
    </div>
    <?php else: ?>
    <?php foreach ($itemsByRoom as $roomName => $items): ?>
    <div class="card mb-3">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-semibold"><i class="bi bi-house-door me-1"></i> <?php echo htmlspecialchars($roomName); ?></h6>
            <span class="badge bg-secondary"><?php echo count($items); ?> <?php _e('insp_items', 'items'); ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="min-width:140px;"><?php _e('insp_item', 'Item'); ?></th>
                            <th style="min-width:140px;"><?php _e('insp_condition', 'Condition'); ?></th>
                            <th class="d-none d-md-table-cell" style="min-width:180px;"><?php _e('notes'); ?></th>
                            <th style="width:80px;"><?php _e('insp_photo', 'Photo'); ?></th>
                            <?php if ($isEditable && hasPermission('inspections', 'edit')): ?>
                            <th style="width:40px;"></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr id="item-row-<?php echo $item['id']; ?>">
                            <td class="fw-semibold"><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td>
                                <?php if ($isEditable && hasPermission('inspections', 'edit')): ?>
                                <select name="items[<?php echo $item['id']; ?>][condition]" class="form-select form-select-sm">
                                    <?php foreach ($condLabels as $cVal => $cLabel): ?>
                                    <option value="<?php echo $cVal; ?>" <?php echo $item['condition'] === $cVal ? 'selected' : ''; ?>><?php echo $cLabel; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php else: ?>
                                <span class="badge bg-<?php echo $condColors[$item['condition']] ?? 'secondary'; ?>">
                                    <?php echo $condLabels[$item['condition']] ?? ucfirst($item['condition']); ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <?php if ($isEditable && hasPermission('inspections', 'edit')): ?>
                                <input type="text" name="items[<?php echo $item['id']; ?>][notes]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($item['notes'] ?? ''); ?>" placeholder="<?php _e('insp_item_notes', 'Notes...'); ?>">
                                <?php else: ?>
                                <small><?php echo htmlspecialchars($item['notes'] ?? ''); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($item['photo_path']): ?>
                                <a href="<?php echo BASE_URL . 'uploads/' . htmlspecialchars($item['photo_path']); ?>" target="_blank" class="btn btn-sm btn-outline-success" title="<?php _e('view'); ?>">
                                    <i class="bi bi-image"></i>
                                </a>
                                <?php if ($isEditable && hasPermission('inspections', 'edit')): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deletePhoto(<?php echo $item['id']; ?>)" title="<?php _e('delete'); ?>">
                                    <i class="bi bi-x"></i>
                                </button>
                                <?php endif; ?>
                                <?php elseif ($isEditable && hasPermission('inspections', 'edit')): ?>
                                <label class="btn btn-sm btn-outline-secondary" title="<?php _e('insp_upload_photo', 'Upload Photo'); ?>">
                                    <i class="bi bi-camera"></i>
                                    <input type="file" class="d-none" accept="image/*" onchange="uploadPhoto(<?php echo $item['id']; ?>, this)">
                                </label>
                                <?php else: ?>
                                <small class="text-muted">—</small>
                                <?php endif; ?>
                            </td>
                            <?php if ($isEditable && hasPermission('inspections', 'edit')): ?>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteItem(<?php echo $item['id']; ?>)" title="<?php _e('delete'); ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Save + Status Buttons -->
    <?php if ($isEditable && hasPermission('inspections', 'edit')): ?>
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('insp_overall_notes', 'Overall Notes'); ?></label>
                    <textarea name="overall_notes" class="form-control" rows="2"><?php echo htmlspecialchars($inspection['overall_notes'] ?? ''); ?></textarea>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('status'); ?></label>
                    <select name="status" class="form-select">
                        <option value="in_progress" <?php echo $inspection['status'] === 'in_progress' ? 'selected' : ''; ?>><?php _e('insp_in_progress', 'In Progress'); ?></option>
                        <option value="completed" <?php echo $inspection['status'] === 'completed' ? 'selected' : ''; ?>><?php _e('insp_completed', 'Completed'); ?></option>
                    </select>
                </div>
                <div class="col-6 col-md-3 d-flex gap-2">
                    <button type="button" class="btn btn-primary flex-grow-1" onclick="saveInspection()">
                        <i class="bi bi-check-lg me-1"></i> <?php _e('save'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</form>

<!-- Add Item Section -->
<?php if ($isEditable && hasPermission('inspections', 'edit')): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-plus-circle me-1"></i> <?php _e('insp_add_item', 'Add Item'); ?></div>
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label"><?php _e('insp_room', 'Room'); ?></label>
                <input type="text" id="newItemRoom" class="form-control form-control-sm" list="roomList" placeholder="<?php _e('insp_room_placeholder', 'Select or type room name...'); ?>">
                <datalist id="roomList">
                    <?php foreach (array_keys($itemsByRoom) as $room): ?>
                    <option value="<?php echo htmlspecialchars($room); ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label"><?php _e('insp_item', 'Item'); ?></label>
                <input type="text" id="newItemName" class="form-control form-control-sm" placeholder="<?php _e('insp_item_placeholder', 'e.g. Walls, Floor, Ceiling...'); ?>">
            </div>
            <div class="col-12 col-md-4">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addItem()">
                    <i class="bi bi-plus me-1"></i> <?php _e('insp_add_item', 'Add Item'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Notes (if completed) -->
<?php if ($inspection['overall_notes'] && !$isEditable): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-card-text me-1"></i> <?php _e('insp_overall_notes'); ?></div>
    <div class="card-body">
        <p class="mb-0"><?php echo nl2br(htmlspecialchars($inspection['overall_notes'])); ?></p>
    </div>
</div>
<?php endif; ?>

<!-- Activity Log -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-clock-history me-1"></i> <?php _e('activity'); ?></div>
    <div class="card-body">
        <?php if (empty($activityHistory)): ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-clock" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0"><?php _e('no_history'); ?></p>
        </div>
        <?php else: ?>
        <div class="timeline-simple">
            <?php foreach ($activityHistory as $entry): ?>
            <div class="d-flex gap-2 mb-3 pb-2 border-bottom">
                <div><i class="bi bi-circle-fill text-primary" style="font-size:0.5rem;margin-top:6px;"></i></div>
                <div class="flex-grow-1">
                    <small class="text-muted"><?php echo formatDateTime($entry['created_at']); ?></small>
                    <br><small><?php echo ucfirst($entry['action']); ?> <?php echo $entry['entity_type']; ?></small>
                    <br><small class="text-muted"><?php echo htmlspecialchars($entry['user_name'] ?? 'System'); ?></small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const csrfToken = '<?php echo generateCSRF(); ?>';
const apiUrl = BASE_URL + 'api/inspections.php';

// Save entire inspection form
function saveInspection() {
    const form = document.getElementById('inspectionForm');
    const formData = new FormData(form);

    const btn = form.querySelector('button[onclick="saveInspection()"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> <?php echo __("processing"); ?>';

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> <?php echo __("save"); ?>';
            }
        })
        .catch(() => {
            alert('Request failed');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> <?php echo __("save"); ?>';
        });
}

// Upload photo for item
function uploadPhoto(itemId, input) {
    if (!input.files || !input.files[0]) return;

    const formData = new FormData();
    formData.append('action', 'upload_photo');
    formData.append('item_id', itemId);
    formData.append('photo', input.files[0]);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(() => alert('Upload failed'));
}

// Delete photo
function deletePhoto(itemId) {
    if (!confirm('<?php echo __("delete_photo_confirm", "Delete this photo?"); ?>')) return;

    const formData = new FormData();
    formData.append('action', 'delete_photo');
    formData.append('csrf_token', csrfToken);
    formData.append('item_id', itemId);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.message);
        })
        .catch(() => alert('Request failed'));
}

// Delete item
function deleteItem(itemId) {
    if (!confirm('<?php echo __("insp_delete_item_confirm", "Remove this item?"); ?>')) return;

    const formData = new FormData();
    formData.append('action', 'delete_item');
    formData.append('csrf_token', csrfToken);
    formData.append('item_id', itemId);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('item-row-' + itemId)?.remove();
            } else {
                alert(data.message);
            }
        })
        .catch(() => alert('Request failed'));
}

// Add new item
function addItem() {
    const room = document.getElementById('newItemRoom').value.trim();
    const itemName = document.getElementById('newItemName').value.trim();
    if (!room || !itemName) {
        alert('<?php echo __("insp_room_item_required", "Room and item name are required"); ?>');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'add_item');
    formData.append('csrf_token', csrfToken);
    formData.append('inspection_id', <?php echo $id; ?>);
    formData.append('room', room);
    formData.append('item_name', itemName);

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
