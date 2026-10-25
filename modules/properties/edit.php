<?php
/**
 * FIS Real Estate — Edit Property (5.1)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

$subStatus = enforceSubscription();

if (!hasPermission('properties', 'edit')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Property not found', 'danger');
    redirect('modules/properties/');
}

// Fetch property
$stmt = $conn->prepare("SELECT * FROM properties WHERE id = :id");
$stmt->execute(['id' => $id]);
$property = $stmt->fetch();

if (!$property) {
    setAlert('Property not found', 'danger');
    redirect('modules/properties/');
}

if (!canAccessProperty($id)) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/properties/');
}

$pageTitle = __('edit') . ' - ' . $property['name'];

// Handle form submission
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $property['name'] = trim($_POST['name'] ?? '');
    $property['property_type'] = trim($_POST['property_type'] ?? 'residential');
    $property['address'] = trim($_POST['address'] ?? '');
    $property['city'] = trim($_POST['city'] ?? '');
    $property['region'] = trim($_POST['region'] ?? '');
    $property['country'] = trim($_POST['country'] ?? 'Tanzania');
    $property['description'] = trim($_POST['description'] ?? '');
    $property['total_units'] = max(0, (int)($_POST['total_units'] ?? 0));
    $property['status'] = trim($_POST['status'] ?? 'active');

    if ($property['name'] === '') $errors[] = __('name_required', 'Property name is required');
    if (!in_array($property['property_type'], ['residential', 'commercial', 'land', 'mixed'])) {
        $errors[] = __('invalid_type', 'Invalid property type');
    }

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                UPDATE properties SET
                    name = :name, property_type = :type, address = :address, city = :city,
                    region = :region, country = :country, description = :description,
                    total_units = :total_units, status = :status
                WHERE id = :id
            ");
            $stmt->execute([
                'name' => $property['name'],
                'type' => $property['property_type'],
                'address' => $property['address'],
                'city' => $property['city'],
                'region' => $property['region'],
                'country' => $property['country'],
                'description' => $property['description'],
                'total_units' => $property['total_units'],
                'status' => $property['status'],
                'id' => $id
            ]);

            logActivity('updated', 'property', $id, ['name' => $property['name']]);

            setAlert(__('property_updated', 'Property updated successfully!'), 'success');
            redirect('modules/properties/view.php?id=' . $id);
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-pencil me-2"></i><?php _e('edit_property', 'Edit Property'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/properties/"><?php _e('properties'); ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $id; ?>"><?php echo htmlspecialchars($property['name']); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('edit'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('properties.edit'); ?>

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
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('property_name', 'Property Name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($property['name']); ?>" required>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('property_type', 'Property Type'); ?></label>
                    <select name="property_type" class="form-select">
                        <option value="residential" <?php echo $property['property_type'] === 'residential' ? 'selected' : ''; ?>><?php _e('residential', 'Residential'); ?></option>
                        <option value="commercial" <?php echo $property['property_type'] === 'commercial' ? 'selected' : ''; ?>><?php _e('commercial', 'Commercial'); ?></option>
                        <option value="land" <?php echo $property['property_type'] === 'land' ? 'selected' : ''; ?>><?php _e('land', 'Land'); ?></option>
                        <option value="mixed" <?php echo $property['property_type'] === 'mixed' ? 'selected' : ''; ?>><?php _e('mixed', 'Mixed Use'); ?></option>
                    </select>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('status'); ?></label>
                    <select name="status" class="form-select">
                        <option value="active" <?php echo $property['status'] === 'active' ? 'selected' : ''; ?>><?php _e('active'); ?></option>
                        <option value="inactive" <?php echo $property['status'] === 'inactive' ? 'selected' : ''; ?>><?php _e('inactive'); ?></option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('address'); ?></label>
                    <textarea class="form-control" name="address" rows="2"><?php echo htmlspecialchars($property['address']); ?></textarea>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('city'); ?></label>
                    <input type="text" class="form-control" name="city" value="<?php echo htmlspecialchars($property['city']); ?>">
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('region', 'Region'); ?></label>
                    <input type="text" class="form-control" name="region" value="<?php echo htmlspecialchars($property['region']); ?>">
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('country', 'Country'); ?></label>
                    <input type="text" class="form-control" name="country" value="<?php echo htmlspecialchars($property['country']); ?>">
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('total_units', 'Total Units (Planned)'); ?></label>
                    <input type="number" class="form-control" name="total_units" value="<?php echo $property['total_units']; ?>" min="0">
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('description'); ?></label>
                    <textarea class="form-control" name="description" rows="3"><?php echo htmlspecialchars($property['description']); ?></textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('cancel'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('save_changes', 'Save Changes'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
