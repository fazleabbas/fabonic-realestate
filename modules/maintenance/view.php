<?php
/**
 * FIS Real Estate — View Maintenance Request (12.2 / 12.3)
 * Request detail: stat cards, status management, assignments (work orders),
 * time logs, photo gallery (before/after), activity log.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

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

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Request not found', 'danger');
    redirect('modules/maintenance/');
}

// Fetch request with related data
$stmt = $conn->prepare("
    SELECT mr.*, u.unit_number, u.unit_label, u.id AS unit_id_val,
           p.name AS property_name, p.id AS property_id_val,
           mc.name AS category_name,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
           t.phone AS tenant_phone, t.email AS tenant_email, t.id AS tenant_id_val,
           rep.full_name AS reported_by_name
    FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    LEFT JOIN tenants t ON mr.tenant_id = t.id
    LEFT JOIN maintenance_categories mc ON mr.category_id = mc.id
    LEFT JOIN users rep ON mr.reported_by_type = 'staff' AND mr.reported_by_id = rep.id
    WHERE mr.id = :id
");
$stmt->execute(['id' => $id]);
$request = $stmt->fetch();

if (!$request) {
    setAlert('Request not found', 'danger');
    redirect('modules/maintenance/');
}

if (!canAccessUnit($request['unit_id'])) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/maintenance/');
}

$pageTitle = __('maint_request', 'Request') . ' #' . $id;

// Get photos
$photoStmt = $conn->prepare("SELECT * FROM maintenance_photos WHERE request_id = :rid ORDER BY photo_type, created_at");
$photoStmt->execute(['rid' => $id]);
$photos = $photoStmt->fetchAll();

$beforePhotos = array_filter($photos, function($p) { return $p['photo_type'] === 'before'; });
$afterPhotos = array_filter($photos, function($p) { return $p['photo_type'] === 'after'; });

// Get assignments with contractor info
$assignStmt = $conn->prepare("
    SELECT ma.*, c.name AS contractor_name, c.phone AS contractor_phone,
           c.specialty AS contractor_specialty, c.company_name AS contractor_company,
           u.full_name AS assigned_by_name,
           (SELECT COALESCE(SUM(tl.hours_worked), 0) FROM maintenance_time_logs tl WHERE tl.assignment_id = ma.id) AS total_hours
    FROM maintenance_assignments ma
    INNER JOIN contractors c ON ma.contractor_id = c.id
    LEFT JOIN users u ON ma.assigned_by = u.id
    WHERE ma.request_id = :rid
    ORDER BY ma.assigned_date DESC
");
$assignStmt->execute(['rid' => $id]);
$assignments = $assignStmt->fetchAll();

// Get time logs (across all assignments for this request)
$timeStmt = $conn->prepare("
    SELECT tl.*, u.full_name AS logged_by_name, c.name AS contractor_name, ma.id AS assignment_id
    FROM maintenance_time_logs tl
    INNER JOIN maintenance_assignments ma ON tl.assignment_id = ma.id
    INNER JOIN contractors c ON ma.contractor_id = c.id
    LEFT JOIN users u ON tl.logged_by = u.id
    WHERE ma.request_id = :rid
    ORDER BY tl.log_date DESC
");
$timeStmt->execute(['rid' => $id]);
$timeLogs = $timeStmt->fetchAll();

// Total hours and cost
$totalHours = 0;
foreach ($timeLogs as $tl) {
    $totalHours += (float)$tl['hours_worked'];
}
$totalCost = 0;
foreach ($assignments as $a) {
    $totalCost += (float)($a['actual_cost'] ?: $a['estimated_cost'] ?: 0);
}

// Active contractors for assignment modal
$contractorStmt = $conn->query("SELECT id, name, specialty, phone FROM contractors WHERE status = 'active' ORDER BY name");
$contractors = $contractorStmt->fetchAll();

// Activity log
$historyStmt = $conn->prepare("
    SELECT al.*, u.full_name AS user_name
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE (al.entity_type = 'maintenance_request' AND al.entity_id = :id)
       OR (al.entity_type = 'maintenance_assignment' AND al.entity_id IN (
            SELECT ma2.id FROM maintenance_assignments ma2 WHERE ma2.request_id = :id2
       ))
    ORDER BY al.created_at DESC
    LIMIT 20
");
$historyStmt->execute(['id' => $id, 'id2' => $id]);
$activityHistory = $historyStmt->fetchAll();

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
$assignStatusLabels = [
    'assigned' => __('maint_assigned', 'Assigned'),
    'accepted' => __('maint_accepted', 'Accepted'),
    'in_progress' => __('maint_in_progress', 'In Progress'),
    'completed' => __('maint_completed', 'Completed'),
];

$isClosed = in_array($request['status'], ['completed', 'closed', 'cancelled']);

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-tools me-2"></i><?php echo $pageTitle; ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/maintenance/"><?php _e('maintenance'); ?></a></li>
                <li class="breadcrumb-item active">#<?php echo $id; ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (hasPermission('maintenance', 'edit') && !$isClosed): ?>
        <a href="<?php echo BASE_URL; ?>modules/maintenance/edit.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-pencil me-1"></i> <?php _e('edit'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('maintenance.view'); ?>

<!-- Info Bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span class="badge bg-<?php echo $priorityColors[$request['priority']] ?? 'secondary'; ?>" style="font-size:0.85rem;">
                <?php echo $priorityLabels[$request['priority']] ?? ucfirst($request['priority']); ?>
            </span>
            <span class="badge bg-<?php echo $statusColors[$request['status']] ?? 'secondary'; ?>" style="font-size:0.85rem;">
                <?php echo $statusLabels[$request['status']] ?? ucfirst($request['status']); ?>
            </span>
            <?php if ($request['category_name']): ?>
            <span><i class="bi bi-tag me-1"></i><?php echo htmlspecialchars($request['category_name']); ?></span>
            <?php endif; ?>
            <span><i class="bi bi-door-open me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/units/view.php?id=<?php echo $request['unit_id_val']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($request['unit_number']); ?></a>
            </span>
            <span><i class="bi bi-buildings me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $request['property_id_val']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($request['property_name']); ?></a>
            </span>
            <?php if ($request['tenant_name']): ?>
            <span><i class="bi bi-person me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/tenants/view.php?id=<?php echo $request['tenant_id_val']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($request['tenant_name']); ?></a>
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
                <div class="stat-card-value"><?php echo formatDate($request['created_at']); ?></div>
                <div class="stat-card-label"><?php _e('maint_reported_date', 'Reported'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-people"></i></div>
            <div>
                <div class="stat-card-value"><?php echo count($assignments); ?></div>
                <div class="stat-card-label"><?php _e('maint_assignments', 'Assignments'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-clock"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatNumber($totalHours, 1); ?>h</div>
                <div class="stat-card-label"><?php _e('maint_total_hours', 'Hours Logged'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#f3e8ff;color:#6b21a8;"><i class="bi bi-cash"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($totalCost); ?></div>
                <div class="stat-card-label"><?php _e('maint_total_cost', 'Total Cost'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Description -->
<?php if ($request['description']): ?>
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-card-text me-1"></i> <?php _e('description'); ?></div>
    <div class="card-body">
        <p class="mb-0"><?php echo nl2br(htmlspecialchars($request['description'])); ?></p>
    </div>
</div>
<?php endif; ?>

<!-- Status Change & Completion Notes -->
<?php if (hasPermission('maintenance', 'edit')): ?>
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-arrow-repeat me-1"></i> <?php _e('maint_update_status', 'Update Status'); ?></div>
    <div class="card-body">
        <form id="statusForm" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold"><?php _e('status'); ?></label>
                <select name="status" class="form-select form-select-sm">
                    <?php foreach ($statusLabels as $sVal => $sLabel): ?>
                    <option value="<?php echo $sVal; ?>" <?php echo $request['status'] === $sVal ? 'selected' : ''; ?>><?php echo $sLabel; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold"><?php _e('maint_completion_notes', 'Notes'); ?></label>
                <input type="text" name="notes" class="form-control form-control-sm" value="<?php echo htmlspecialchars($request['completion_notes'] ?? ''); ?>" placeholder="<?php _e('maint_status_notes_placeholder', 'Status update notes...'); ?>">
            </div>
            <div class="col-12 col-md-3">
                <button type="button" class="btn btn-sm btn-primary w-100" onclick="updateStatus()">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('maint_update', 'Update'); ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Photos (Before / After) -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-images me-1"></i> <?php _e('maint_photos', 'Photos'); ?> (<?php echo count($photos); ?>)</span>
        <?php if (hasPermission('maintenance', 'edit') && !$isClosed): ?>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadPhotoModal">
            <i class="bi bi-camera me-1"></i> <?php _e('maint_add_photo', 'Add Photo'); ?>
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($photos)): ?>
        <div class="text-center text-muted py-3">
            <i class="bi bi-camera" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0"><?php _e('maint_no_photos', 'No photos uploaded'); ?></p>
        </div>
        <?php else: ?>
        <?php if (!empty($beforePhotos)): ?>
        <h6 class="fw-semibold mb-2"><?php _e('maint_before_photos', 'Before'); ?></h6>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($beforePhotos as $photo): ?>
            <div class="position-relative" style="width:120px;">
                <a href="<?php echo BASE_URL . 'uploads/' . htmlspecialchars($photo['file_path']); ?>" target="_blank">
                    <img src="<?php echo BASE_URL . 'uploads/' . htmlspecialchars($photo['file_path']); ?>" class="rounded border" style="width:120px;height:90px;object-fit:cover;" alt="">
                </a>
                <?php if (hasPermission('maintenance', 'edit') && !$isClosed): ?>
                <button class="btn btn-sm btn-danger position-absolute top-0 end-0" style="padding:1px 5px;font-size:0.7rem;" onclick="deletePhoto(<?php echo $photo['id']; ?>)">
                    <i class="bi bi-x"></i>
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($afterPhotos)): ?>
        <h6 class="fw-semibold mb-2"><?php _e('maint_after_photos', 'After'); ?></h6>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($afterPhotos as $photo): ?>
            <div class="position-relative" style="width:120px;">
                <a href="<?php echo BASE_URL . 'uploads/' . htmlspecialchars($photo['file_path']); ?>" target="_blank">
                    <img src="<?php echo BASE_URL . 'uploads/' . htmlspecialchars($photo['file_path']); ?>" class="rounded border" style="width:120px;height:90px;object-fit:cover;" alt="">
                </a>
                <?php if (hasPermission('maintenance', 'edit') && !$isClosed): ?>
                <button class="btn btn-sm btn-danger position-absolute top-0 end-0" style="padding:1px 5px;font-size:0.7rem;" onclick="deletePhoto(<?php echo $photo['id']; ?>)">
                    <i class="bi bi-x"></i>
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Assignments / Work Orders (12.2) -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-check me-1"></i> <?php _e('maint_work_orders', 'Work Orders / Assignments'); ?> (<?php echo count($assignments); ?>)</span>
        <?php if (hasPermission('maintenance', 'edit') && !$isClosed): ?>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#assignModal">
            <i class="bi bi-plus me-1"></i> <?php _e('maint_assign_contractor', 'Assign Contractor'); ?>
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($assignments)): ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-person-x" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0"><?php _e('maint_no_assignments', 'No contractors assigned yet'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th><?php _e('maint_contractor', 'Contractor'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('maint_specialty', 'Specialty'); ?></th>
                        <th><?php _e('status'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('maint_est_cost', 'Est. Cost'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('maint_act_cost', 'Act. Cost'); ?></th>
                        <th class="d-none d-lg-table-cell"><?php _e('maint_est_completion', 'Due Date'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('maint_hours', 'Hours'); ?></th>
                        <?php if (hasPermission('maintenance', 'edit')): ?>
                        <th style="width:100px;"><?php _e('actions'); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignments as $a): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($a['contractor_name']); ?></strong>
                            <?php if ($a['contractor_phone']): ?>
                            <br><small class="text-muted"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($a['contractor_phone']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($a['contractor_specialty'] ?? '—'); ?></small></td>
                        <td>
                            <span class="badge bg-<?php echo $statusColors[$a['status']] ?? 'secondary'; ?>">
                                <?php echo $assignStatusLabels[$a['status']] ?? ucfirst($a['status']); ?>
                            </span>
                        </td>
                        <td class="d-none d-md-table-cell"><?php echo $a['estimated_cost'] ? formatCurrency($a['estimated_cost'], $a['currency']) : '—'; ?></td>
                        <td class="d-none d-md-table-cell"><?php echo $a['actual_cost'] ? formatCurrency($a['actual_cost'], $a['currency']) : '—'; ?></td>
                        <td class="d-none d-lg-table-cell"><small><?php echo $a['estimated_completion'] ? formatDate($a['estimated_completion']) : '—'; ?></small></td>
                        <td class="d-none d-md-table-cell"><?php echo formatNumber($a['total_hours'], 1); ?>h</td>
                        <?php if (hasPermission('maintenance', 'edit')): ?>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" onclick="editAssignment(<?php echo htmlspecialchars(json_encode($a)); ?>)" title="<?php _e('edit'); ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if (hasPermission('maintenance', 'delete')): ?>
                                <button class="btn btn-outline-danger" onclick="deleteAssignment(<?php echo $a['id']; ?>)" title="<?php _e('delete'); ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Time Logs (12.3) -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-1"></i> <?php _e('maint_time_log', 'Time Log'); ?> (<?php echo formatNumber($totalHours, 1); ?>h)</span>
        <?php if (hasPermission('maintenance', 'edit') && !empty($assignments) && !$isClosed): ?>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#timeLogModal">
            <i class="bi bi-plus me-1"></i> <?php _e('maint_add_time', 'Log Time'); ?>
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($timeLogs)): ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-clock" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0"><?php _e('maint_no_time_logs', 'No time logged yet'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th><?php _e('date'); ?></th>
                        <th><?php _e('maint_contractor', 'Contractor'); ?></th>
                        <th><?php _e('maint_hours', 'Hours'); ?></th>
                        <th><?php _e('description'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('maint_logged_by', 'Logged By'); ?></th>
                        <?php if (hasPermission('maintenance', 'edit')): ?>
                        <th style="width:40px;"></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($timeLogs as $tl): ?>
                    <tr>
                        <td><small><?php echo formatDate($tl['log_date']); ?></small></td>
                        <td><small><?php echo htmlspecialchars($tl['contractor_name']); ?></small></td>
                        <td><strong><?php echo formatNumber($tl['hours_worked'], 1); ?>h</strong></td>
                        <td><small><?php echo htmlspecialchars($tl['description'] ?? '—'); ?></small></td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($tl['logged_by_name'] ?? '—'); ?></small></td>
                        <?php if (hasPermission('maintenance', 'edit')): ?>
                        <td>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteTimeLog(<?php echo $tl['id']; ?>)" title="<?php _e('delete'); ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Completion Notes -->
<?php if ($request['completion_notes']): ?>
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-check-circle me-1"></i> <?php _e('maint_completion_notes', 'Completion Notes'); ?></div>
    <div class="card-body">
        <p class="mb-0"><?php echo nl2br(htmlspecialchars($request['completion_notes'])); ?></p>
        <?php if ($request['completed_date']): ?>
        <small class="text-muted mt-2 d-block"><?php _e('maint_completed_on', 'Completed on'); ?>: <?php echo formatDate($request['completed_date']); ?></small>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Reported By Info -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-info-circle me-1"></i> <?php _e('maint_details', 'Details'); ?></div>
    <div class="card-body">
        <div class="row">
            <div class="col-6 col-md-3">
                <small class="text-muted d-block"><?php _e('maint_reported_by', 'Reported By'); ?></small>
                <strong><?php echo htmlspecialchars($request['reported_by_name'] ?? ucfirst($request['reported_by_type'])); ?></strong>
            </div>
            <div class="col-6 col-md-3">
                <small class="text-muted d-block"><?php _e('maint_reported_date', 'Reported'); ?></small>
                <strong><?php echo formatDateTime($request['created_at']); ?></strong>
            </div>
            <div class="col-6 col-md-3">
                <small class="text-muted d-block"><?php _e('maint_last_updated', 'Last Updated'); ?></small>
                <strong><?php echo formatDateTime($request['updated_at']); ?></strong>
            </div>
            <div class="col-6 col-md-3">
                <small class="text-muted d-block"><?php _e('maint_photo_count', 'Photos'); ?></small>
                <strong><?php echo count($photos); ?></strong>
            </div>
        </div>
    </div>
</div>

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
                    <br><small><?php echo ucfirst($entry['action']); ?> <?php echo $entry['entity_type']; ?>
                    <?php
                    if ($entry['details']) {
                        $details = json_decode($entry['details'], true);
                        if (isset($details['status_change'])) {
                            echo ' — ' . htmlspecialchars($details['status_change']);
                        }
                    }
                    ?>
                    </small>
                    <br><small class="text-muted"><?php echo htmlspecialchars($entry['user_name'] ?? 'System'); ?></small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================
     MODALS
     ============================ -->

<!-- Assign Contractor Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-person-plus me-1"></i> <?php _e('maint_assign_contractor', 'Assign Contractor'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold"><?php _e('maint_contractor', 'Contractor'); ?> <span class="text-danger">*</span></label>
                        <select id="assignContractor" class="form-select">
                            <option value=""><?php _e('maint_select_contractor', 'Select contractor...'); ?></option>
                            <?php foreach ($contractors as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name'] . ($c['specialty'] ? ' — ' . $c['specialty'] : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold"><?php _e('maint_assigned_date', 'Assigned Date'); ?></label>
                        <input type="date" id="assignDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold"><?php _e('maint_est_completion', 'Est. Completion'); ?></label>
                        <input type="date" id="assignEstCompletion" class="form-control">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold"><?php _e('maint_est_cost', 'Estimated Cost'); ?></label>
                        <input type="number" id="assignEstCost" class="form-control" step="0.01" min="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold"><?php _e('currency'); ?></label>
                        <input type="text" id="assignCurrency" class="form-control" value="<?php echo DEFAULT_CURRENCY; ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold"><?php _e('notes'); ?></label>
                        <textarea id="assignNotes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-sm btn-primary" onclick="submitAssignment()">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('maint_assign', 'Assign'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Assignment Modal -->
<div class="modal fade" id="editAssignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-pencil me-1"></i> <?php _e('maint_edit_assignment', 'Edit Assignment'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editAssignId">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold"><?php _e('status'); ?></label>
                        <select id="editAssignStatus" class="form-select">
                            <?php foreach ($assignStatusLabels as $sVal => $sLabel): ?>
                            <option value="<?php echo $sVal; ?>"><?php echo $sLabel; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold"><?php _e('maint_act_cost', 'Actual Cost'); ?></label>
                        <input type="number" id="editAssignActualCost" class="form-control" step="0.01" min="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold"><?php _e('maint_act_completion', 'Actual Completion'); ?></label>
                        <input type="date" id="editAssignActualCompletion" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold"><?php _e('notes'); ?></label>
                        <textarea id="editAssignNotes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-sm btn-primary" onclick="submitEditAssignment()">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('save'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Upload Photo Modal -->
<div class="modal fade" id="uploadPhotoModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-camera me-1"></i> <?php _e('maint_add_photo', 'Add Photo'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?php _e('type'); ?></label>
                    <select id="uploadPhotoType" class="form-select">
                        <option value="before"><?php _e('maint_before_photos', 'Before'); ?></option>
                        <option value="after"><?php _e('maint_after_photos', 'After'); ?></option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?php _e('maint_photo_file', 'Photo'); ?></label>
                    <input type="file" id="uploadPhotoFile" class="form-control" accept="image/*">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-sm btn-primary" onclick="submitUploadPhoto()">
                    <i class="bi bi-upload me-1"></i> <?php _e('upload'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Time Log Modal -->
<div class="modal fade" id="timeLogModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-clock me-1"></i> <?php _e('maint_add_time', 'Log Time'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?php _e('maint_assignment', 'Assignment'); ?> <span class="text-danger">*</span></label>
                    <select id="timeLogAssignment" class="form-select">
                        <?php foreach ($assignments as $a): ?>
                        <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['contractor_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?php _e('date'); ?></label>
                    <input type="date" id="timeLogDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?php _e('maint_hours', 'Hours'); ?> <span class="text-danger">*</span></label>
                    <input type="number" id="timeLogHours" class="form-control" step="0.25" min="0.25" placeholder="e.g. 2.5">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?php _e('description'); ?></label>
                    <input type="text" id="timeLogDesc" class="form-control" placeholder="<?php _e('maint_time_desc_placeholder', 'What was done...'); ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-sm btn-primary" onclick="submitTimeLog()">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('save'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo generateCSRF(); ?>';
const apiUrl = BASE_URL + 'api/maintenance.php';
const requestId = <?php echo $id; ?>;

// Update Status
function updateStatus() {
    const form = document.getElementById('statusForm');
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('csrf_token', csrfToken);
    formData.append('id', requestId);
    formData.append('status', form.querySelector('[name=status]').value);
    formData.append('notes', form.querySelector('[name=notes]').value);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.message);
        })
        .catch(() => alert('Request failed'));
}

// Submit Assignment
function submitAssignment() {
    const contractorId = document.getElementById('assignContractor').value;
    if (!contractorId) { alert('<?php echo __("maint_select_contractor", "Select a contractor"); ?>'); return; }

    const formData = new FormData();
    formData.append('action', 'assign');
    formData.append('csrf_token', csrfToken);
    formData.append('request_id', requestId);
    formData.append('contractor_id', contractorId);
    formData.append('assigned_date', document.getElementById('assignDate').value);
    formData.append('estimated_completion', document.getElementById('assignEstCompletion').value);
    formData.append('estimated_cost', document.getElementById('assignEstCost').value);
    formData.append('currency', document.getElementById('assignCurrency').value);
    formData.append('notes', document.getElementById('assignNotes').value);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.message);
        })
        .catch(() => alert('Request failed'));
}

// Edit Assignment
function editAssignment(data) {
    document.getElementById('editAssignId').value = data.id;
    document.getElementById('editAssignStatus').value = data.status;
    document.getElementById('editAssignActualCost').value = data.actual_cost || '';
    document.getElementById('editAssignActualCompletion').value = data.actual_completion || '';
    document.getElementById('editAssignNotes').value = data.notes || '';
    new bootstrap.Modal(document.getElementById('editAssignModal')).show();
}

function submitEditAssignment() {
    const formData = new FormData();
    formData.append('action', 'update_assignment');
    formData.append('csrf_token', csrfToken);
    formData.append('assignment_id', document.getElementById('editAssignId').value);
    formData.append('status', document.getElementById('editAssignStatus').value);
    formData.append('actual_cost', document.getElementById('editAssignActualCost').value);
    formData.append('actual_completion', document.getElementById('editAssignActualCompletion').value);
    formData.append('notes', document.getElementById('editAssignNotes').value);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.message);
        })
        .catch(() => alert('Request failed'));
}

// Delete Assignment
function deleteAssignment(id) {
    if (!confirm('<?php echo __("maint_delete_assignment_confirm", "Remove this assignment?"); ?>')) return;

    const formData = new FormData();
    formData.append('action', 'delete_assignment');
    formData.append('csrf_token', csrfToken);
    formData.append('assignment_id', id);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.message);
        })
        .catch(() => alert('Request failed'));
}

// Upload Photo
function submitUploadPhoto() {
    const fileInput = document.getElementById('uploadPhotoFile');
    if (!fileInput.files || !fileInput.files[0]) { alert('<?php echo __("maint_select_photo", "Select a photo"); ?>'); return; }

    const formData = new FormData();
    formData.append('action', 'upload_photo');
    formData.append('request_id', requestId);
    formData.append('photo_type', document.getElementById('uploadPhotoType').value);
    formData.append('photo', fileInput.files[0]);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.message);
        })
        .catch(() => alert('Upload failed'));
}

// Delete Photo
function deletePhoto(id) {
    if (!confirm('<?php echo __("delete_photo_confirm", "Delete this photo?"); ?>')) return;

    const formData = new FormData();
    formData.append('action', 'delete_photo');
    formData.append('csrf_token', csrfToken);
    formData.append('photo_id', id);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.message);
        })
        .catch(() => alert('Request failed'));
}

// Time Log
function submitTimeLog() {
    const hours = document.getElementById('timeLogHours').value;
    if (!hours || parseFloat(hours) <= 0) { alert('<?php echo __("maint_hours_required", "Hours are required"); ?>'); return; }

    const formData = new FormData();
    formData.append('action', 'add_time_log');
    formData.append('csrf_token', csrfToken);
    formData.append('assignment_id', document.getElementById('timeLogAssignment').value);
    formData.append('log_date', document.getElementById('timeLogDate').value);
    formData.append('hours_worked', hours);
    formData.append('description', document.getElementById('timeLogDesc').value);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.message);
        })
        .catch(() => alert('Request failed'));
}

// Delete Time Log
function deleteTimeLog(id) {
    if (!confirm('<?php echo __("maint_delete_time_confirm", "Delete this time entry?"); ?>')) return;

    const formData = new FormData();
    formData.append('action', 'delete_time_log');
    formData.append('csrf_token', csrfToken);
    formData.append('log_id', id);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.message);
        })
        .catch(() => alert('Request failed'));
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
