<?php
/**
 * FIS Real Estate — Create Property (5.1)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('add_property', 'Add Property');

$subStatus = enforceSubscription();

if (!hasPermission('properties', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Handle form submission
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $old = [
        'name' => trim($_POST['name'] ?? ''),
        'property_type' => trim($_POST['property_type'] ?? 'residential'),
        'address' => trim($_POST['address'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'region' => trim($_POST['region'] ?? ''),
        'country' => trim($_POST['country'] ?? 'Tanzania'),
        'description' => trim($_POST['description'] ?? ''),
        'total_units' => (int)($_POST['total_units'] ?? 0),
        'status' => trim($_POST['status'] ?? 'active'),
    ];

    // Validate
    if ($old['name'] === '') $errors[] = __('name_required', 'Property name is required');
    if (!in_array($old['property_type'], ['residential', 'commercial', 'land', 'mixed'])) {
        $errors[] = __('invalid_type', 'Invalid property type');
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO properties (name, property_type, address, city, region, country, description, total_units, status)
                VALUES (:name, :type, :address, :city, :region, :country, :description, :total_units, :status)
            ");
            $stmt->execute([
                'name' => $old['name'],
                'type' => $old['property_type'],
                'address' => $old['address'],
                'city' => $old['city'],
                'region' => $old['region'],
                'country' => $old['country'],
                'description' => $old['description'],
                'total_units' => $old['total_units'],
                'status' => $old['status']
            ]);

            $propertyId = (int) $conn->lastInsertId();

            // Create default property_settings row
            $conn->prepare("
                INSERT INTO property_settings (property_id, rent_cycle, late_fee_type, late_fee_value, grace_period_days, deposit_months)
                VALUES (:pid, 'monthly', 'none', 0, 5, 1)
            ")->execute(['pid' => $propertyId]);

            $conn->commit();

            logActivity('created', 'property', $propertyId, ['name' => $old['name']]);

            setAlert(__('property_created', 'Property created successfully!'), 'success');
            redirect('modules/properties/view.php?id=' . $propertyId);
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
        <h4><i class="bi bi-plus-circle me-2"></i><?php _e('add_property', 'Add Property'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/properties/"><?php _e('properties'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('create'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('properties.create'); ?>

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
        <form method="POST" id="propertyForm">
            <?php echo csrfField(); ?>

            <div class="row g-3">
                <!-- Name -->
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('property_name', 'Property Name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($old['name'] ?? ''); ?>" required placeholder="e.g. Sunset Apartments">
                </div>

                <!-- Type -->
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('property_type', 'Property Type'); ?> <span class="text-danger">*</span></label>
                    <select name="property_type" class="form-select" required>
                        <option value="residential" <?php echo ($old['property_type'] ?? '') === 'residential' ? 'selected' : ''; ?>><?php _e('residential', 'Residential'); ?></option>
                        <option value="commercial" <?php echo ($old['property_type'] ?? '') === 'commercial' ? 'selected' : ''; ?>><?php _e('commercial', 'Commercial'); ?></option>
                        <option value="land" <?php echo ($old['property_type'] ?? '') === 'land' ? 'selected' : ''; ?>><?php _e('land', 'Land'); ?></option>
                        <option value="mixed" <?php echo ($old['property_type'] ?? '') === 'mixed' ? 'selected' : ''; ?>><?php _e('mixed', 'Mixed Use'); ?></option>
                    </select>
                </div>

                <!-- Status -->
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('status'); ?></label>
                    <select name="status" class="form-select">
                        <option value="active" <?php echo ($old['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>><?php _e('active'); ?></option>
                        <option value="inactive" <?php echo ($old['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>><?php _e('inactive'); ?></option>
                    </select>
                </div>

                <!-- Address -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('address'); ?></label>
                    <textarea class="form-control" name="address" rows="2" placeholder="<?php _e('address_placeholder', 'Street address, area, landmark'); ?>"><?php echo htmlspecialchars($old['address'] ?? ''); ?></textarea>
                </div>

                <!-- City -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('city'); ?></label>
                    <input type="text" class="form-control" name="city" value="<?php echo htmlspecialchars($old['city'] ?? ''); ?>" placeholder="e.g. Dar es Salaam">
                </div>

                <!-- Region -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('region', 'Region'); ?></label>
                    <input type="text" class="form-control" name="region" value="<?php echo htmlspecialchars($old['region'] ?? ''); ?>" placeholder="e.g. Kinondoni">
                </div>

                <!-- Country -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('country', 'Country'); ?></label>
                    <input type="text" class="form-control" name="country" value="<?php echo htmlspecialchars($old['country'] ?? 'Tanzania'); ?>">
                </div>

                <!-- Total Units (planned) -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('total_units', 'Total Units (Planned)'); ?></label>
                    <input type="number" class="form-control" name="total_units" value="<?php echo $old['total_units'] ?? 0; ?>" min="0">
                    <small class="text-muted"><?php _e('total_units_help', 'Planned/expected number of units'); ?></small>
                </div>

                <!-- Description -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('description'); ?></label>
                    <textarea class="form-control" name="description" rows="3" placeholder="<?php _e('description_placeholder', 'Brief description of the property'); ?>"><?php echo htmlspecialchars($old['description'] ?? ''); ?></textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/properties/" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('save_property', 'Save Property'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
