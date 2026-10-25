<?php
/**
 * FIS Real Estate — Create Maintenance Request (12.1)
 * Form to submit a maintenance request: unit, category, priority, description, photos.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('maint_new_request', 'New Maintenance Request');

$subStatus = enforceSubscription();

if (!hasTier('enterprise')) {
    setAlert(__('maint_tier_required', 'Enterprise tier or higher required for maintenance management.'), 'warning');
    redirect('index.php');
}
if (getSetting('maintenance_enabled', '0') !== '1') {
    setAlert(__('maint_disabled', 'Maintenance module is disabled. Enable it in Settings > Modules.'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('maintenance', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Get units with property info + active tenant
$unitsStmt = $conn->query("
    SELECT u.id, u.unit_number, u.unit_label, u.property_id, u.status,
           p.name AS property_name,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.id AS tenant_id
    FROM units u
    INNER JOIN properties p ON u.property_id = p.id
    LEFT JOIN leases l ON l.unit_id = u.id AND l.status = 'active'
    LEFT JOIN tenants t ON l.tenant_id = t.id
    WHERE 1=1" . propertyScopeSql('p.id') . "
    ORDER BY p.name, u.unit_number
");
$units = $unitsStmt->fetchAll();

// Categories
$catStmt = $conn->query("SELECT id, name FROM maintenance_categories ORDER BY name");
$categories = $catStmt->fetchAll();

// Properties for filter
$propStmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name");
$properties = $propStmt->fetchAll();

// Handle form submission
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $old = [
        'unit_id' => (int)($_POST['unit_id'] ?? 0),
        'tenant_id' => (int)($_POST['tenant_id'] ?? 0),
        'category_id' => (int)($_POST['category_id'] ?? 0),
        'priority' => trim($_POST['priority'] ?? 'medium'),
        'title' => trim($_POST['title'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
    ];

    $validPriorities = ['low', 'medium', 'high', 'urgent'];

    if (!$old['unit_id']) $errors[] = __('maint_unit_required', 'Unit is required');
    if (!$old['title']) $errors[] = __('maint_title_required', 'Title is required');
    if (!in_array($old['priority'], $validPriorities)) $old['priority'] = 'medium';

    // Property-based access scoping: ensure the target unit is in scope
    if ($old['unit_id'] && !canAccessUnit($old['unit_id'])) {
        $errors[] = __('no_access_record', "You don't have access to this record");
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO maintenance_requests (unit_id, tenant_id, category_id, priority,
                    title, description, status, reported_by_type, reported_by_id)
                VALUES (:unit_id, :tenant_id, :category_id, :priority,
                    :title, :description, 'reported', 'staff', :reported_by)
            ");
            $stmt->execute([
                'unit_id' => $old['unit_id'],
                'tenant_id' => $old['tenant_id'] ?: null,
                'category_id' => $old['category_id'] ?: null,
                'priority' => $old['priority'],
                'title' => $old['title'],
                'description' => $old['description'] ?: null,
                'reported_by' => getUserId(),
            ]);

            $requestId = (int) $conn->lastInsertId();

            // Handle photo uploads
            if (isset($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
                $photoInsert = $conn->prepare("
                    INSERT INTO maintenance_photos (request_id, file_path, caption, photo_type)
                    VALUES (:rid, :path, :caption, 'before')
                ");
                $fileCount = count($_FILES['photos']['name']);
                for ($i = 0; $i < $fileCount; $i++) {
                    if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
                        $file = [
                            'name' => $_FILES['photos']['name'][$i],
                            'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                            'error' => $_FILES['photos']['error'][$i],
                            'size' => $_FILES['photos']['size'][$i],
                        ];
                        $result = uploadFile($file, 'maintenance/' . $requestId, ALLOWED_IMAGE_TYPES);
                        if ($result['success']) {
                            $photoInsert->execute([
                                'rid' => $requestId,
                                'path' => $result['filepath'],
                                'caption' => null,
                            ]);
                        }
                    }
                }
            }

            $conn->commit();

            logActivity('created', 'maintenance_request', $requestId, [
                'title' => $old['title'],
                'priority' => $old['priority'],
                'unit_id' => $old['unit_id'],
            ]);

            setAlert(__('maint_request_created', 'Maintenance request created successfully!'), 'success');
            redirect('modules/maintenance/view.php?id=' . $requestId);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-plus-circle me-2"></i><?php _e('maint_new_request', 'New Maintenance Request'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/maintenance/"><?php _e('maintenance'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('create'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('maintenance.create'); ?>

<!-- Errors -->
<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?php echo htmlspecialchars($err); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Create Form -->
<div class="card">
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" id="maintenanceForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="tenant_id" id="tenantIdHidden" value="<?php echo $old['tenant_id'] ?? 0; ?>">

            <!-- Request Details -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-tools me-1"></i> <?php _e('maint_request_details', 'Request Details'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-8">
                    <label class="form-label fw-semibold"><?php _e('maint_title', 'Title'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title" value="<?php echo htmlspecialchars($old['title'] ?? ''); ?>" placeholder="<?php _e('maint_title_placeholder', 'Brief description of the issue...'); ?>" required>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('priority'); ?> <span class="text-danger">*</span></label>
                    <select name="priority" class="form-select" required>
                        <option value="low" <?php echo ($old['priority'] ?? 'medium') === 'low' ? 'selected' : ''; ?>><?php _e('maint_priority_low', 'Low'); ?></option>
                        <option value="medium" <?php echo ($old['priority'] ?? 'medium') === 'medium' ? 'selected' : ''; ?>><?php _e('maint_priority_medium', 'Medium'); ?></option>
                        <option value="high" <?php echo ($old['priority'] ?? 'medium') === 'high' ? 'selected' : ''; ?>><?php _e('maint_priority_high', 'High'); ?></option>
                        <option value="urgent" <?php echo ($old['priority'] ?? 'medium') === 'urgent' ? 'selected' : ''; ?>><?php _e('maint_priority_urgent', 'Urgent'); ?></option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('category'); ?></label>
                    <select name="category_id" class="form-select">
                        <option value=""><?php _e('maint_select_category', 'Select...'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo ($old['category_id'] ?? 0) == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row g-3 mt-0">
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('description'); ?></label>
                    <textarea class="form-control" name="description" rows="4" placeholder="<?php _e('maint_desc_placeholder', 'Detailed description of the issue, including location, when it started, severity...'); ?>"><?php echo htmlspecialchars($old['description'] ?? ''); ?></textarea>
                </div>
            </div>

            <hr>

            <!-- Unit Selection -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-door-open me-1"></i> <?php _e('maint_location', 'Location'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('property'); ?></label>
                    <select id="propertyFilter" class="form-select">
                        <option value=""><?php _e('all_properties'); ?></option>
                        <?php foreach ($properties as $prop): ?>
                        <option value="<?php echo $prop['id']; ?>"><?php echo htmlspecialchars($prop['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted"><?php _e('maint_property_filter_help', 'Filter units by property'); ?></small>
                </div>
                <div class="col-12 col-md-8">
                    <label class="form-label fw-semibold"><?php _e('unit_number', 'Unit'); ?> <span class="text-danger">*</span></label>
                    <select name="unit_id" id="unitSelect" class="form-select" required>
                        <option value=""><?php _e('maint_select_unit', 'Select unit...'); ?></option>
                        <?php foreach ($units as $u): ?>
                        <option value="<?php echo $u['id']; ?>"
                                data-property-id="<?php echo $u['property_id']; ?>"
                                data-tenant-id="<?php echo $u['tenant_id'] ?? ''; ?>"
                                data-tenant="<?php echo htmlspecialchars($u['tenant_name'] ?? ''); ?>"
                                <?php echo ($old['unit_id'] ?? 0) == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['unit_number'] . ' — ' . $u['property_name'] . ($u['tenant_name'] ? ' (' . $u['tenant_name'] . ')' : '')); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Tenant Info Bar -->
            <div id="tenantInfoBar" class="alert alert-info mt-3 d-none">
                <i class="bi bi-person me-1"></i> <?php _e('tenant'); ?>: <strong id="infoTenant"></strong>
            </div>

            <hr>

            <!-- Photos -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-camera me-1"></i> <?php _e('maint_photos', 'Photos'); ?></h6>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('maint_attach_photos', 'Attach Photos'); ?></label>
                    <input type="file" class="form-control" name="photos[]" multiple accept="image/*">
                    <small class="text-muted"><?php _e('maint_photos_help', 'Upload photos of the issue. Multiple files allowed. Max 10MB each.'); ?></small>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/maintenance/" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('maint_submit_request', 'Submit Request'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#unitSelect').select2({ width: '100%', placeholder: '<?php echo __("maint_select_unit", "Select unit..."); ?>' });

    // Property filter for unit dropdown
    $('#propertyFilter').on('change', function() {
        const pid = $(this).val();
        $('#unitSelect option').each(function() {
            if (!$(this).val()) return; // skip placeholder
            if (!pid || $(this).data('property-id') == pid) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
        // Reset select2 to reflect filtered options
        $('#unitSelect').val('').trigger('change');
    });

    // Unit selection — auto-fill tenant
    $('#unitSelect').on('change', function() {
        const opt = $(this).find(':selected');
        const val = $(this).val();

        if (!val) {
            $('#tenantInfoBar').addClass('d-none');
            $('#tenantIdHidden').val('');
            return;
        }

        const tenantName = opt.data('tenant');
        const tenantId = opt.data('tenant-id');

        if (tenantName) {
            $('#infoTenant').text(tenantName);
            $('#tenantInfoBar').removeClass('d-none');
            $('#tenantIdHidden').val(tenantId);
        } else {
            $('#tenantInfoBar').addClass('d-none');
            $('#tenantIdHidden').val('');
        }
    });

    if ($('#unitSelect').val()) {
        $('#unitSelect').trigger('change');
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
