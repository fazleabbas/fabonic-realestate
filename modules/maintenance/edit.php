<?php
/**
 * FIS Real Estate — Edit Maintenance Request (12.1)
 * Edit existing request: title, priority, category, description.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('maint_edit_request', 'Edit Maintenance Request');

$subStatus = enforceSubscription();

if (!hasTier('enterprise')) {
    setAlert(__('maint_tier_required', 'Enterprise tier or higher required for maintenance management.'), 'warning');
    redirect('index.php');
}
if (getSetting('maintenance_enabled', '0') !== '1') {
    setAlert(__('maint_disabled', 'Maintenance module is disabled. Enable it in Settings > Modules.'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('maintenance', 'edit')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Request not found', 'danger');
    redirect('modules/maintenance/');
}

// Fetch request
$stmt = $conn->prepare("
    SELECT mr.*, u.unit_number, p.name AS property_name,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name
    FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    LEFT JOIN tenants t ON mr.tenant_id = t.id
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

// Block editing closed requests
if (in_array($request['status'], ['completed', 'closed', 'cancelled'])) {
    setAlert(__('maint_cannot_edit_closed', 'Cannot edit a closed request'), 'warning');
    redirect('modules/maintenance/view.php?id=' . $id);
}

// Categories
$catStmt = $conn->query("SELECT id, name FROM maintenance_categories ORDER BY name");
$categories = $catStmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = trim($_POST['priority'] ?? 'medium');
    $categoryId = (int)($_POST['category_id'] ?? 0);

    $validPriorities = ['low', 'medium', 'high', 'urgent'];

    if (!$title) $errors[] = __('maint_title_required', 'Title is required');
    if (!in_array($priority, $validPriorities)) $priority = 'medium';

    if (empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE maintenance_requests
            SET title = :title, description = :description, priority = :priority,
                category_id = :category_id
            WHERE id = :id
        ");
        $stmt->execute([
            'title' => $title,
            'description' => $description ?: null,
            'priority' => $priority,
            'category_id' => $categoryId ?: null,
            'id' => $id,
        ]);

        logActivity('updated', 'maintenance_request', $id, [
            'title' => $title,
            'priority' => $priority,
        ]);

        setAlert(__('maint_request_updated', 'Maintenance request updated'), 'success');
        redirect('modules/maintenance/view.php?id=' . $id);
    } else {
        // Merge posted values into request
        $request['title'] = $title;
        $request['description'] = $description;
        $request['priority'] = $priority;
        $request['category_id'] = $categoryId;
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-pencil me-2"></i><?php _e('maint_edit_request', 'Edit Maintenance Request'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/maintenance/"><?php _e('maintenance'); ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/maintenance/view.php?id=<?php echo $id; ?>">#<?php echo $id; ?></a></li>
                <li class="breadcrumb-item active"><?php _e('edit'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('maintenance.edit'); ?>

<!-- Location Info (read-only) -->
<div class="alert alert-info">
    <div class="d-flex flex-wrap gap-3">
        <span><i class="bi bi-door-open me-1"></i><strong><?php echo htmlspecialchars($request['unit_number']); ?></strong></span>
        <span><i class="bi bi-buildings me-1"></i><?php echo htmlspecialchars($request['property_name']); ?></span>
        <?php if ($request['tenant_name']): ?>
        <span><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($request['tenant_name']); ?></span>
        <?php endif; ?>
    </div>
</div>

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

<!-- Edit Form -->
<div class="card">
    <div class="card-body">
        <form method="POST">
            <?php echo csrfField(); ?>

            <div class="row g-3">
                <div class="col-12 col-md-8">
                    <label class="form-label fw-semibold"><?php _e('maint_title', 'Title'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title" value="<?php echo htmlspecialchars($request['title']); ?>" required>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('priority'); ?></label>
                    <select name="priority" class="form-select">
                        <option value="low" <?php echo $request['priority'] === 'low' ? 'selected' : ''; ?>><?php _e('maint_priority_low', 'Low'); ?></option>
                        <option value="medium" <?php echo $request['priority'] === 'medium' ? 'selected' : ''; ?>><?php _e('maint_priority_medium', 'Medium'); ?></option>
                        <option value="high" <?php echo $request['priority'] === 'high' ? 'selected' : ''; ?>><?php _e('maint_priority_high', 'High'); ?></option>
                        <option value="urgent" <?php echo $request['priority'] === 'urgent' ? 'selected' : ''; ?>><?php _e('maint_priority_urgent', 'Urgent'); ?></option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('category'); ?></label>
                    <select name="category_id" class="form-select">
                        <option value=""><?php _e('maint_select_category', 'Select...'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo ($request['category_id'] ?? 0) == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('description'); ?></label>
                    <textarea class="form-control" name="description" rows="5"><?php echo htmlspecialchars($request['description'] ?? ''); ?></textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/maintenance/view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('save_changes'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
