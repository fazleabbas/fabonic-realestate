<?php
/**
 * FIS Real Estate — View Unit (6.1 + 6.3 Status Management)
 * Unit detail page with status badge, status change with reason, photo preview, lease info.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

$subStatus = enforceSubscription();

if (!hasPermission('units', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Unit not found', 'danger');
    redirect('modules/units/');
}

// Fetch unit with property info
$stmt = $conn->prepare("
    SELECT u.*, p.name AS property_name, p.property_type AS property_type_val
    FROM units u
    INNER JOIN properties p ON u.property_id = p.id
    WHERE u.id = :id
");
$stmt->execute(['id' => $id]);
$unit = $stmt->fetch();

if (!$unit) {
    setAlert('Unit not found', 'danger');
    redirect('modules/units/');
}

if (!canAccessUnit($id)) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/units/');
}

$pageTitle = $unit['unit_number'] . ' - ' . $unit['property_name'];

// Photos (first 6 for gallery preview)
$photoStmt = $conn->prepare("SELECT * FROM unit_photos WHERE unit_id = :id ORDER BY sort_order ASC, id ASC LIMIT 6");
$photoStmt->execute(['id' => $id]);
$photos = $photoStmt->fetchAll();

$totalPhotos = (int) $conn->query("SELECT COUNT(*) FROM unit_photos WHERE unit_id = {$id}")->fetchColumn();

// Active lease for this unit
$leaseStmt = $conn->prepare("
    SELECT l.*, CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone, t.id AS tenant_id_val
    FROM leases l
    INNER JOIN tenants t ON l.tenant_id = t.id
    WHERE l.unit_id = :uid AND l.status = 'active'
    ORDER BY l.created_at DESC LIMIT 1
");
$leaseStmt->execute(['uid' => $id]);
$activeLease = $leaseStmt->fetch();

// Status history from activity_log
$historyStmt = $conn->prepare("
    SELECT al.*, u.full_name AS user_name
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.entity_type = 'unit'
      AND al.entity_id = :uid
      AND al.action = 'updated'
    ORDER BY al.created_at DESC
    LIMIT 10
");
$historyStmt->execute(['uid' => $id]);
$statusHistory = $historyStmt->fetchAll();

// Property settings (for deposit months context)
$propSettings = $conn->prepare("SELECT * FROM property_settings WHERE property_id = :pid");
$propSettings->execute(['pid' => $unit['property_id']]);
$propSettings = $propSettings->fetch();

$statusColors = ['vacant' => 'danger', 'occupied' => 'success', 'maintenance' => 'warning', 'reserved' => 'info'];
$statusLabels = [
    'vacant' => __('vacant', 'Vacant'),
    'occupied' => __('occupied', 'Occupied'),
    'maintenance' => __('maintenance', 'Maintenance'),
    'reserved' => __('reserved', 'Reserved'),
];

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

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-door-open me-2"></i><?php echo htmlspecialchars($unit['unit_number']); ?>
            <?php if ($unit['unit_label']): ?>
            <small class="text-muted">(<?php echo htmlspecialchars($unit['unit_label']); ?>)</small>
            <?php endif; ?>
        </h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/units/"><?php _e('units'); ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $unit['property_id']; ?>"><?php echo htmlspecialchars($unit['property_name']); ?></a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($unit['unit_number']); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <?php if (hasPermission('units', 'edit')): ?>
        <a href="<?php echo BASE_URL; ?>modules/units/edit.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-pencil me-1"></i> <?php _e('edit'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/units/photos.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-images me-1"></i> <?php _e('photos', 'Photos'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('units.view'); ?>

<!-- Unit Info Bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span class="badge bg-<?php echo $statusColors[$unit['status']] ?? 'secondary'; ?>" style="font-size:0.85rem;">
                <?php echo $statusLabels[$unit['status']] ?? ucfirst($unit['status']); ?>
            </span>
            <span><i class="bi bi-building me-1"></i>
                <a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $unit['property_id']; ?>" class="text-decoration-none">
                    <?php echo htmlspecialchars($unit['property_name']); ?>
                </a>
            </span>
            <span><i class="bi bi-tag me-1"></i> <?php echo $typeLabels[$unit['unit_type']] ?? ucfirst($unit['unit_type']); ?></span>
            <?php if ($unit['floor']): ?>
            <span><i class="bi bi-layers me-1"></i> <?php _e('floor', 'Floor'); ?> <?php echo htmlspecialchars($unit['floor']); ?></span>
            <?php endif; ?>
            <?php if ($unit['size_sqm']): ?>
            <span><i class="bi bi-rulers me-1"></i> <?php echo formatNumber($unit['size_sqm'], 0); ?> m&sup2;</span>
            <?php endif; ?>
            <?php if ($unit['bedrooms'] !== null): ?>
            <span><i class="bi bi-hospital me-1"></i> <?php echo $unit['bedrooms']; ?> <?php _e('bedrooms', 'Bedrooms'); ?></span>
            <?php endif; ?>
            <?php if ($unit['bathrooms'] !== null): ?>
            <span><i class="bi bi-droplet me-1"></i> <?php echo $unit['bathrooms']; ?> <?php _e('bathrooms', 'Bathrooms'); ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Info Cards Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;">
                <i class="bi bi-cash-stack"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($unit['rent_amount'], $unit['currency']); ?></div>
                <div class="stat-card-label"><?php _e('rent_amount', 'Rent Amount'); ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;">
                <i class="bi bi-safe"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($unit['deposit_amount'], $unit['currency']); ?></div>
                <div class="stat-card-label"><?php _e('deposit_amount', 'Deposit'); ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:<?php echo $activeLease ? '#dcfce7' : '#f0f0f0'; ?>;color:<?php echo $activeLease ? '#166534' : '#666'; ?>;">
                <i class="bi bi-file-earmark-text"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo $activeLease ? __('active') : __('none', 'None'); ?></div>
                <div class="stat-card-label"><?php _e('active_lease', 'Active Lease'); ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;">
                <i class="bi bi-images"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo $totalPhotos; ?></div>
                <div class="stat-card-label"><?php _e('photos', 'Photos'); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Status Change (6.3) -->
    <?php if (hasPermission('units', 'edit')): ?>
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-arrow-repeat me-1"></i> <?php _e('change_status', 'Change Status'); ?>
            </div>
            <div class="card-body">
                <form id="statusChangeForm">
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRF(); ?>">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('new_status', 'New Status'); ?></label>
                        <select name="new_status" class="form-select" id="newStatus">
                            <?php foreach ($statusLabels as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $unit['status'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('reason_note', 'Reason / Note'); ?></label>
                        <textarea name="reason" class="form-control" rows="2" placeholder="<?php _e('reason_placeholder', 'Optional reason for status change'); ?>"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm w-100" id="statusBtn">
                        <i class="bi bi-arrow-repeat me-1"></i> <?php _e('update_status', 'Update Status'); ?>
                    </button>
                </form>

                <div id="statusResult" class="mt-2"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Active Lease Info -->
    <div class="col-12 col-lg-<?php echo hasPermission('units', 'edit') ? '4' : '6'; ?>">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-file-earmark-text me-1"></i> <?php _e('lease_info', 'Lease Information'); ?>
            </div>
            <div class="card-body">
                <?php if ($activeLease): ?>
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted"><?php _e('tenant', 'Tenant'); ?></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($activeLease['tenant_name']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('phone'); ?></td>
                        <td><?php echo htmlspecialchars($activeLease['tenant_phone']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('lease_period', 'Period'); ?></td>
                        <td><?php echo formatDate($activeLease['start_date']); ?> — <?php echo formatDate($activeLease['end_date']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('rent', 'Rent'); ?></td>
                        <td class="fw-semibold"><?php echo formatCurrency($activeLease['rent_amount'], $activeLease['currency']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('frequency', 'Frequency'); ?></td>
                        <td><?php echo paymentFrequencyLabel($activeLease['payment_frequency']); ?></td>
                    </tr>
                </table>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-file-earmark-x" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('no_active_lease', 'No active lease'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Status History -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-clock-history me-1"></i> <?php _e('activity_history', 'Activity History'); ?>
            </div>
            <div class="card-body" style="max-height:300px;overflow-y:auto;">
                <?php if (empty($statusHistory)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-clock" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('no_history', 'No activity history'); ?></p>
                </div>
                <?php else: ?>
                <div class="timeline-simple">
                    <?php foreach ($statusHistory as $entry):
                        $details = json_decode($entry['details'] ?? '{}', true) ?: [];
                    ?>
                    <div class="d-flex gap-2 mb-3 pb-2 border-bottom">
                        <div>
                            <i class="bi bi-circle-fill text-primary" style="font-size:0.5rem;margin-top:6px;"></i>
                        </div>
                        <div class="flex-grow-1">
                            <small class="text-muted"><?php echo formatDateTime($entry['created_at']); ?></small>
                            <?php if (!empty($details['status_change'])): ?>
                            <br><small class="fw-semibold"><?php echo htmlspecialchars($details['status_change']); ?></small>
                            <?php if (!empty($details['reason'])): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($details['reason']); ?></small>
                            <?php endif; ?>
                            <?php else: ?>
                            <br><small><?php _e('updated', 'Updated'); ?></small>
                            <?php endif; ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($entry['user_name'] ?? 'System'); ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Photo Gallery Preview -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-images me-1"></i> <?php _e('photos', 'Photos'); ?> (<?php echo $totalPhotos; ?>)</span>
        <?php if (hasPermission('units', 'edit')): ?>
        <a href="<?php echo BASE_URL; ?>modules/units/photos.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-pencil me-1"></i><?php _e('manage', 'Manage'); ?>
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($photos)): ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-camera" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0"><?php _e('no_photos', 'No photos uploaded yet'); ?></p>
        </div>
        <?php else: ?>
        <div class="row g-2">
            <?php foreach ($photos as $photo): ?>
            <div class="col-4 col-md-3 col-lg-2">
                <div style="aspect-ratio:1;border-radius:8px;overflow:hidden;background:#f0f0f0;">
                    <img src="<?php echo BASE_URL; ?>uploads/<?php echo htmlspecialchars($photo['file_path']); ?>"
                         alt="<?php echo htmlspecialchars($photo['caption'] ?? ''); ?>"
                         style="width:100%;height:100%;object-fit:cover;cursor:pointer;"
                         onclick="window.open(this.src, '_blank')"
                         loading="lazy">
                </div>
            </div>
            <?php endforeach; ?>
            <?php if ($totalPhotos > 6): ?>
            <div class="col-4 col-md-3 col-lg-2">
                <a href="<?php echo BASE_URL; ?>modules/units/photos.php?id=<?php echo $id; ?>" class="d-flex align-items-center justify-content-center" style="aspect-ratio:1;border-radius:8px;background:#f0f0f0;text-decoration:none;">
                    <span class="text-muted">+<?php echo $totalPhotos - 6; ?> <?php _e('more', 'more'); ?></span>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Description -->
<?php if ($unit['description']): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-text-paragraph me-1"></i> <?php _e('description'); ?></div>
    <div class="card-body">
        <p class="mb-0"><?php echo nl2br(htmlspecialchars($unit['description'])); ?></p>
    </div>
</div>
<?php endif; ?>

<script>
// Status change form
const statusForm = document.getElementById('statusChangeForm');
if (statusForm) {
    statusForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const btn = document.getElementById('statusBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> <?php echo __("updating", "Updating..."); ?>';

        const formData = new FormData(statusForm);

        fetch(BASE_URL + 'api/units.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                const result = document.getElementById('statusResult');
                if (data.success) {
                    result.innerHTML = '<div class="alert alert-success alert-sm py-1 px-2 mb-0"><small>' + data.message + '</small></div>';
                    setTimeout(() => location.reload(), 800);
                } else {
                    result.innerHTML = '<div class="alert alert-danger alert-sm py-1 px-2 mb-0"><small>' + data.message + '</small></div>';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> <?php echo __("update_status", "Update Status"); ?>';
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> <?php echo __("update_status", "Update Status"); ?>';
                alert('Request failed');
            });
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
