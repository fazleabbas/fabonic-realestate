<?php
/**
 * FIS Real Estate — Edit Unit (6.1 + 6.2)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

$subStatus = enforceSubscription();

if (!hasPermission('units', 'edit')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Unit not found', 'danger');
    redirect('modules/units/');
}

// Fetch unit
$stmt = $conn->prepare("SELECT u.*, p.name AS property_name FROM units u INNER JOIN properties p ON u.property_id = p.id WHERE u.id = :id");
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

$pageTitle = __('edit') . ' - ' . $unit['unit_number'];

// Get properties for dropdown
$properties = $conn->query("SELECT id, name, property_type FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name ASC")->fetchAll();

// Supported currencies
$currencies = json_decode(getSetting('supported_currencies_json', '["TZS","USD","KES","UGX"]'), true) ?: ['TZS'];

// Handle form submission
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $unit['property_id'] = (int)($_POST['property_id'] ?? 0);
    $unit['unit_number'] = trim($_POST['unit_number'] ?? '');
    $unit['unit_label'] = trim($_POST['unit_label'] ?? '');
    $unit['unit_type'] = trim($_POST['unit_type'] ?? 'apartment');
    $unit['floor'] = trim($_POST['floor'] ?? '');
    $unit['size_sqm'] = trim($_POST['size_sqm'] ?? '');
    $unit['bedrooms'] = trim($_POST['bedrooms'] ?? '');
    $unit['bathrooms'] = trim($_POST['bathrooms'] ?? '');
    $unit['rent_amount'] = trim($_POST['rent_amount'] ?? '0');
    $unit['currency'] = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
    $unit['deposit_amount'] = trim($_POST['deposit_amount'] ?? '0');
    $unit['status'] = trim($_POST['status'] ?? 'vacant');
    $unit['description'] = trim($_POST['description'] ?? '');

    $validTypes = ['apartment','room','shop','office','warehouse','plot','house','flat','hostel','godown'];
    $validStatuses = ['vacant','occupied','maintenance','reserved'];

    if (!$unit['property_id']) $errors[] = __('property_required', 'Property is required');
    if ($unit['unit_number'] === '') $errors[] = __('unit_number_required', 'Unit number is required');
    if (!in_array($unit['unit_type'], $validTypes)) $errors[] = __('invalid_unit_type', 'Invalid unit type');
    if (!in_array($unit['status'], $validStatuses)) $errors[] = __('invalid_status', 'Invalid status');

    // Check unique unit_number within property (exclude self)
    if ($unit['property_id'] && $unit['unit_number'] !== '') {
        $dupeCheck = $conn->prepare("SELECT id FROM units WHERE property_id = :pid AND unit_number = :num AND id != :id");
        $dupeCheck->execute(['pid' => $unit['property_id'], 'num' => $unit['unit_number'], 'id' => $id]);
        if ($dupeCheck->fetch()) {
            $errors[] = __('unit_number_exists', 'Unit number already exists in this property');
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                UPDATE units SET
                    property_id = :property_id, unit_number = :unit_number, unit_label = :unit_label,
                    unit_type = :unit_type, floor = :floor, size_sqm = :size_sqm,
                    bedrooms = :bedrooms, bathrooms = :bathrooms,
                    rent_amount = :rent_amount, currency = :currency, deposit_amount = :deposit_amount,
                    status = :status, description = :description
                WHERE id = :id
            ");
            $stmt->execute([
                'property_id' => $unit['property_id'],
                'unit_number' => $unit['unit_number'],
                'unit_label' => $unit['unit_label'] ?: null,
                'unit_type' => $unit['unit_type'],
                'floor' => $unit['floor'] ?: null,
                'size_sqm' => $unit['size_sqm'] !== '' ? (float)$unit['size_sqm'] : null,
                'bedrooms' => $unit['bedrooms'] !== '' ? (int)$unit['bedrooms'] : null,
                'bathrooms' => $unit['bathrooms'] !== '' ? (int)$unit['bathrooms'] : null,
                'rent_amount' => max(0, (float)$unit['rent_amount']),
                'currency' => $unit['currency'],
                'deposit_amount' => max(0, (float)$unit['deposit_amount']),
                'status' => $unit['status'],
                'description' => $unit['description'],
                'id' => $id
            ]);

            logActivity('updated', 'unit', $id, ['unit_number' => $unit['unit_number']]);

            setAlert(__('unit_updated', 'Unit updated successfully!'), 'success');
            redirect('modules/units/view.php?id=' . $id);
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
        <h4><i class="bi bi-pencil me-2"></i><?php _e('edit_unit', 'Edit Unit'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/units/"><?php _e('units'); ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/units/view.php?id=<?php echo $id; ?>"><?php echo htmlspecialchars($unit['unit_number']); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('edit'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('units.edit'); ?>

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
        <form method="POST" id="unitForm">
            <?php echo csrfField(); ?>

            <div class="row g-3">
                <!-- Property Selector -->
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('property', 'Property'); ?> <span class="text-danger">*</span></label>
                    <select name="property_id" class="form-select" required id="propertySelect">
                        <option value=""><?php _e('select_property', 'Select Property'); ?></option>
                        <?php foreach ($properties as $prop): ?>
                        <option value="<?php echo $prop['id']; ?>" <?php echo $unit['property_id'] == $prop['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($prop['name']); ?> (<?php echo ucfirst($prop['property_type']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Unit Number -->
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('unit_number', 'Unit #'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="unit_number" value="<?php echo htmlspecialchars($unit['unit_number']); ?>" required>
                </div>

                <!-- Unit Label -->
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('unit_label', 'Label'); ?> <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                    <input type="text" class="form-control" name="unit_label" value="<?php echo htmlspecialchars($unit['unit_label'] ?? ''); ?>">
                </div>

                <!-- Unit Type -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('unit_type', 'Unit Type'); ?></label>
                    <select name="unit_type" class="form-select">
                        <?php
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
                        foreach ($typeLabels as $val => $label):
                        ?>
                        <option value="<?php echo $val; ?>" <?php echo $unit['unit_type'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Floor -->
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('floor', 'Floor'); ?></label>
                    <input type="text" class="form-control" name="floor" value="<?php echo htmlspecialchars($unit['floor'] ?? ''); ?>">
                </div>

                <!-- Size -->
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('size_sqm', 'Size (m²)'); ?></label>
                    <input type="number" class="form-control" name="size_sqm" value="<?php echo $unit['size_sqm'] ? $unit['size_sqm'] : ''; ?>" min="0" step="0.01">
                </div>

                <!-- Bedrooms -->
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('bedrooms', 'Bedrooms'); ?></label>
                    <input type="number" class="form-control" name="bedrooms" value="<?php echo $unit['bedrooms'] !== null ? $unit['bedrooms'] : ''; ?>" min="0" max="20">
                </div>

                <!-- Bathrooms -->
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('bathrooms', 'Bathrooms'); ?></label>
                    <input type="number" class="form-control" name="bathrooms" value="<?php echo $unit['bathrooms'] !== null ? $unit['bathrooms'] : ''; ?>" min="0" max="20">
                </div>
            </div>

            <hr>

            <!-- Rent & Deposit Section -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-cash-stack me-1"></i> <?php _e('rent_deposit', 'Rent & Deposit'); ?></h6>
            <div class="row g-3">
                <!-- Rent Amount -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('rent_amount', 'Rent Amount'); ?></label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="rent_amount" value="<?php echo $unit['rent_amount']; ?>" min="0" step="0.01">
                        <select name="currency" class="form-select" style="max-width:90px;">
                            <?php foreach ($currencies as $curr): ?>
                            <option value="<?php echo $curr; ?>" <?php echo $unit['currency'] === $curr ? 'selected' : ''; ?>><?php echo $curr; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Deposit Amount -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('deposit_amount', 'Deposit Amount'); ?></label>
                    <input type="number" class="form-control" name="deposit_amount" value="<?php echo $unit['deposit_amount']; ?>" min="0" step="0.01">
                </div>

                <!-- Status -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('status'); ?></label>
                    <select name="status" class="form-select">
                        <option value="vacant" <?php echo $unit['status'] === 'vacant' ? 'selected' : ''; ?>><?php _e('vacant', 'Vacant'); ?></option>
                        <option value="occupied" <?php echo $unit['status'] === 'occupied' ? 'selected' : ''; ?>><?php _e('occupied', 'Occupied'); ?></option>
                        <option value="maintenance" <?php echo $unit['status'] === 'maintenance' ? 'selected' : ''; ?>><?php _e('maintenance', 'Maintenance'); ?></option>
                        <option value="reserved" <?php echo $unit['status'] === 'reserved' ? 'selected' : ''; ?>><?php _e('reserved', 'Reserved'); ?></option>
                    </select>
                </div>
            </div>

            <hr>

            <!-- Description -->
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('description'); ?></label>
                    <textarea class="form-control" name="description" rows="3"><?php echo htmlspecialchars($unit['description'] ?? ''); ?></textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/units/view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('cancel'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('save_changes', 'Save Changes'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof $.fn.select2 !== 'undefined') {
        $('#propertySelect').select2({
            theme: 'bootstrap-5',
            placeholder: '<?php echo __("select_property", "Select Property"); ?>',
            allowClear: true,
            width: '100%'
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
