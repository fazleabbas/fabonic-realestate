<?php
/**
 * Fabonic Real Estate — Tenant Portal: Maintenance Requests
 * ------------------------------------------------------------
 * Lets a tenant raise a maintenance request against the unit on their active
 * lease, and follow its progress. Staff see it immediately in
 * modules/maintenance/ with reported_by_type = 'tenant'.
 *
 * Availability requires BOTH modules to be on and in-tier:
 *   tenant_portal_enabled + Professional   (the portal itself)
 *   maintenance_enabled   + Enterprise     (the maintenance module)
 *
 * NOTE: api/maintenance.php is NOT included here — it is a staff-authenticated
 * endpoint that 401s on a tenant session. The one helper we need (a staff
 * notification row) is written inline instead.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

// Portal gate — same as the other portal pages.
if (!getSetting('tenant_portal_enabled', '0') || !hasTier('professional')) {
    header('Location: ' . BASE_URL . 'modules/tenant-portal/login.php');
    exit;
}

// Maintenance gate — matches includes/header.php's staff-side check.
$maintenanceAvailable = getSetting('maintenance_enabled', '0') === '1' && hasTier('enterprise');
if (!$maintenanceAvailable) {
    header('Location: ' . BASE_URL . 'modules/tenant-portal/');
    exit;
}

$pageTitle = __('tp_maintenance', 'Maintenance');
$tenantId  = (int) ($_SESSION['tenant_portal_id'] ?? 0);

$errors  = [];
$success = '';

// ------------------------------------------------------------------
// The unit this tenant may raise requests against = their active lease.
// ------------------------------------------------------------------
$activeLease = null;
try {
    $stmt = $conn->prepare("
        SELECT l.id AS lease_id, u.id AS unit_id, u.unit_number, u.unit_type,
               p.name AS property_name
        FROM leases l
        INNER JOIN units u ON l.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        WHERE l.tenant_id = ? AND l.status = 'active'
        ORDER BY l.start_date DESC LIMIT 1
    ");
    $stmt->execute([$tenantId]);
    $activeLease = $stmt->fetch();
} catch (PDOException $e) {
    error_log('Tenant portal maintenance (lease lookup): ' . $e->getMessage());
}

// Categories for the dropdown
$categories = [];
try {
    $categories = $conn->query("SELECT id, name FROM maintenance_categories ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    error_log('Tenant portal maintenance (categories): ' . $e->getMessage());
}

// ------------------------------------------------------------------
// Submit a new request
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'create_request') {
    checkCSRF();

    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $categoryId  = (int) ($_POST['category_id'] ?? 0);
    $priority    = $_POST['priority'] ?? 'medium';

    if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
        $priority = 'medium';
    }
    if (!$activeLease) {
        $errors[] = __('tp_maint_no_lease', 'You do not have an active lease, so a request cannot be raised. Please contact your landlord.');
    }
    if ($title === '') {
        $errors[] = __('tp_maint_title_required', 'Please give the problem a short title.');
    }
    if ($description === '') {
        $errors[] = __('tp_maint_desc_required', 'Please describe the problem.');
    }

    if (!$errors) {
        try {
            $conn->beginTransaction();

            $ins = $conn->prepare("
                INSERT INTO maintenance_requests
                    (unit_id, tenant_id, category_id, priority, title, description,
                     status, reported_by_type, reported_by_id)
                VALUES (:unit_id, :tenant_id, :category_id, :priority, :title, :description,
                     'reported', 'tenant', :reported_by)
            ");
            $ins->execute([
                'unit_id'     => $activeLease['unit_id'],
                'tenant_id'   => $tenantId,
                'category_id' => $categoryId ?: null,
                'priority'    => $priority,
                'title'       => $title,
                'description' => $description,
                'reported_by' => $tenantId,
            ]);
            $requestId = (int) $conn->lastInsertId();

            // Optional photo of the problem — stored as a 'before' photo.
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $up = uploadFile($_FILES['photo'], 'maintenance/' . $requestId, ALLOWED_IMAGE_TYPES);
                if ($up['success']) {
                    $conn->prepare("
                        INSERT INTO maintenance_photos (request_id, file_path, caption, photo_type)
                        VALUES (?, ?, ?, 'before')
                    ")->execute([$requestId, $up['filepath'], $title]);
                } else {
                    // A bad photo must not lose the request itself.
                    error_log('Tenant portal maintenance photo: ' . ($up['message'] ?? 'unknown'));
                }
            }

            // Tell the staff admins. Mirrors createMaintenanceNotification() in
            // api/maintenance.php, which we cannot include from a tenant session.
            if (getSetting('maintenance_notify_on_status', '1') === '1') {
                $tenantName = $_SESSION['tenant_portal_name'] ?? '';
                $msg = sprintf(
                    __('tp_maint_notify', 'New maintenance request from %1$s (Unit %2$s): %3$s'),
                    $tenantName,
                    $activeLease['unit_number'],
                    $title
                );
                $admins = $conn->query("
                    SELECT u.id, u.full_name FROM users u
                    INNER JOIN roles r ON u.role_id = r.id
                    WHERE r.name = 'Admin' AND u.status = 'active'
                ")->fetchAll();
                $noti = $conn->prepare("
                    INSERT INTO maintenance_notifications
                        (request_id, recipient_type, recipient_id, recipient_name, channel, event_type, message, status)
                    VALUES (?, 'staff', ?, ?, 'system', 'tenant_reported', ?, 'sent')
                ");
                $sysNoti = $conn->prepare("
                    INSERT INTO notifications (user_id, user_type, title, message, link, is_read)
                    VALUES (?, 'staff', ?, ?, ?, 0)
                ");
                foreach ($admins as $admin) {
                    $noti->execute([$requestId, $admin['id'], $admin['full_name'], $msg]);
                    $sysNoti->execute([
                        $admin['id'],
                        __('maint_notification', 'Maintenance Update'),
                        $msg,
                        'modules/maintenance/view.php?id=' . $requestId,
                    ]);
                }
            }

            $conn->commit();
            $_SESSION['tp_maint_success'] = sprintf(
                __('tp_maint_created', 'Your request has been sent to your landlord. Reference #%d.'),
                $requestId
            );
            header('Location: ' . BASE_URL . 'modules/tenant-portal/maintenance.php');
            exit;
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                if ($conn->inTransaction()) $conn->rollBack();
            }
            error_log('Tenant portal maintenance (create): ' . $e->getMessage());
            $errors[] = __('tp_maint_failed', 'Sorry, the request could not be submitted. Please try again.');
        }
    }
}

if (!empty($_SESSION['tp_maint_success'])) {
    $success = $_SESSION['tp_maint_success'];
    unset($_SESSION['tp_maint_success']);
}

// ------------------------------------------------------------------
// This tenant's own requests (never anyone else's)
// ------------------------------------------------------------------
$requests = [];
try {
    $stmt = $conn->prepare("
        SELECT mr.*, mc.name AS category_name, u.unit_number,
               (SELECT COUNT(*) FROM maintenance_photos mp WHERE mp.request_id = mr.id) AS photo_count
        FROM maintenance_requests mr
        LEFT JOIN maintenance_categories mc ON mr.category_id = mc.id
        INNER JOIN units u ON mr.unit_id = u.id
        WHERE mr.tenant_id = ?
        ORDER BY mr.created_at DESC
    ");
    $stmt->execute([$tenantId]);
    $requests = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Tenant portal maintenance (list): ' . $e->getMessage());
}

$statusMeta = [
    'reported'     => ['bg-secondary',    __('maint_reported', 'Reported')],
    'acknowledged' => ['bg-info text-dark', __('maint_acknowledged', 'Acknowledged')],
    'assigned'     => ['bg-primary',      __('maint_assigned', 'Assigned')],
    'in_progress'  => ['bg-warning text-dark', __('maint_in_progress', 'In Progress')],
    'completed'    => ['bg-success',      __('maint_completed', 'Completed')],
    'closed'       => ['bg-dark',         __('maint_closed', 'Closed')],
    'cancelled'    => ['bg-danger',       __('maint_cancelled', 'Cancelled')],
];
$priorityMeta = [
    'low'    => ['bg-light text-dark border', __('maint_priority_low', 'Low')],
    'medium' => ['bg-info text-dark',         __('maint_priority_medium', 'Medium')],
    'high'   => ['bg-warning text-dark',      __('maint_priority_high', 'High')],
    'urgent' => ['bg-danger',                 __('maint_priority_urgent', 'Urgent')],
];

require_once __DIR__ . '/../../includes/tenant-header.php';
?>

<div class="row g-3">
    <!-- ============================ New request ============================ -->
    <div class="col-12 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-tools me-2"></i><?php _e('tp_maint_new', 'Report a Problem'); ?></h6>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger py-2">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                        <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if (!$activeLease): ?>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <?php _e('tp_maint_no_lease', 'You do not have an active lease, so a request cannot be raised. Please contact your landlord.'); ?>
                </div>
                <?php else: ?>
                <p class="text-muted small">
                    <i class="bi bi-house-door me-1"></i>
                    <?php echo htmlspecialchars($activeLease['property_name']); ?> &mdash;
                    <?php _e('unit', 'Unit'); ?> <?php echo htmlspecialchars($activeLease['unit_number']); ?>
                </p>

                <form method="POST" enctype="multipart/form-data">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="form_action" value="create_request">

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('tp_maint_title', 'What is the problem?'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="title" maxlength="200" required
                               placeholder="<?php _e('tp_maint_title_ph', 'e.g. Kitchen tap is leaking'); ?>"
                               value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label fw-semibold"><?php _e('category', 'Category'); ?></label>
                            <select name="category_id" class="form-select">
                                <option value=""><?php _e('tp_maint_pick_category', 'Select…'); ?></option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo (int)$cat['id']; ?>"
                                    <?php echo (int)($_POST['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-5">
                            <label class="form-label fw-semibold"><?php _e('priority', 'Priority'); ?></label>
                            <select name="priority" class="form-select">
                                <?php foreach (['low','medium','high','urgent'] as $pr): ?>
                                <option value="<?php echo $pr; ?>"
                                    <?php echo ($_POST['priority'] ?? 'medium') === $pr ? 'selected' : ''; ?>>
                                    <?php echo $priorityMeta[$pr][1]; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('description', 'Description'); ?> <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="description" rows="4" required
                                  placeholder="<?php _e('tp_maint_desc_ph', 'Tell us what happened, and since when.'); ?>"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('tp_maint_photo', 'Photo'); ?>
                            <small class="text-muted">(<?php _e('optional', 'optional'); ?>)</small>
                        </label>
                        <input type="file" class="form-control" name="photo" accept="image/*">
                        <small class="text-muted"><?php _e('tp_maint_photo_help', 'A picture helps your landlord fix it faster.'); ?></small>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-send me-1"></i> <?php _e('tp_maint_submit', 'Send Request'); ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============================ My requests ============================ -->
    <div class="col-12 col-lg-7">
        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-1"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-list-check me-2"></i><?php _e('tp_maint_my_requests', 'My Requests'); ?></h6>
                <span class="badge bg-secondary"><?php echo count($requests); ?></span>
            </div>

            <?php if (empty($requests)): ?>
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-clipboard-check" style="font-size:2.2rem;"></i>
                <p class="mt-2 mb-0"><?php _e('tp_maint_none', 'You have not reported anything yet.'); ?></p>
            </div>
            <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($requests as $req):
                    $st = $statusMeta[$req['status']] ?? ['bg-secondary', $req['status']];
                    $pr = $priorityMeta[$req['priority']] ?? ['bg-secondary', $req['priority']];
                ?>
                <div class="list-group-item py-3">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?php echo htmlspecialchars($req['title']); ?></div>
                            <div class="small text-muted mt-1">
                                #<?php echo (int)$req['id']; ?>
                                &middot; <?php echo formatDate($req['created_at']); ?>
                                <?php if ($req['category_name']): ?>
                                &middot; <?php echo htmlspecialchars($req['category_name']); ?>
                                <?php endif; ?>
                                <?php if ($req['photo_count']): ?>
                                &middot; <i class="bi bi-image"></i> <?php echo (int)$req['photo_count']; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge <?php echo $st[0]; ?>"><?php echo $st[1]; ?></span><br>
                            <span class="badge <?php echo $pr[0]; ?> mt-1" style="font-size:.7rem;"><?php echo $pr[1]; ?></span>
                        </div>
                    </div>

                    <?php if (!empty($req['description'])): ?>
                    <p class="small text-muted mb-0 mt-2"><?php echo nl2br(htmlspecialchars($req['description'])); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($req['completion_notes'])): ?>
                    <div class="alert alert-success py-2 px-3 mt-2 mb-0" style="font-size:.85rem;">
                        <i class="bi bi-check-circle me-1"></i>
                        <strong><?php _e('tp_maint_resolution', 'Resolution'); ?>:</strong>
                        <?php echo nl2br(htmlspecialchars($req['completion_notes'])); ?>
                        <?php if (!empty($req['completed_date'])): ?>
                        <div class="text-muted mt-1"><?php echo formatDate($req['completed_date']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/tenant-footer.php'; ?>
