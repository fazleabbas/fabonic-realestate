<?php
/**
 * FIS Real Estate — Create Unit (6.1 + 6.2)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('add_unit', 'Add Unit');

$subStatus = enforceSubscription();

if (!hasPermission('units', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Subscription unit quota
$unitUsage = getUnitUsage();

// Get properties for dropdown
$properties = $conn->query("SELECT id, name, property_type FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name ASC")->fetchAll();

// Pre-select property if passed via URL
$preselectedProperty = (int)($_GET['property_id'] ?? 0);

// Supported currencies
$currencies = json_decode(getSetting('supported_currencies_json', '["TZS","USD","KES","UGX"]'), true) ?: ['TZS'];

// Handle form submission
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $old = [
        'property_id' => (int)($_POST['property_id'] ?? 0),
        'unit_number' => trim($_POST['unit_number'] ?? ''),
        'unit_label' => trim($_POST['unit_label'] ?? ''),
        'unit_type' => trim($_POST['unit_type'] ?? 'apartment'),
        'floor' => trim($_POST['floor'] ?? ''),
        'size_sqm' => trim($_POST['size_sqm'] ?? ''),
        'bedrooms' => trim($_POST['bedrooms'] ?? ''),
        'bathrooms' => trim($_POST['bathrooms'] ?? ''),
        'rent_amount' => trim($_POST['rent_amount'] ?? '0'),
        'currency' => trim($_POST['currency'] ?? DEFAULT_CURRENCY),
        'deposit_amount' => trim($_POST['deposit_amount'] ?? '0'),
        'status' => trim($_POST['status'] ?? 'vacant'),
        'description' => trim($_POST['description'] ?? ''),
    ];

    $validTypes = ['apartment','room','shop','office','warehouse','plot','house','flat','hostel','godown'];
    $validStatuses = ['vacant','occupied','maintenance','reserved'];

    // Enforce subscription unit quota
    if (!canAddUnits(1)) {
        $errors[] = sprintf(
            __('unit_limit_reached', 'You have reached your subscription unit limit (%1$d of %2$d units used). Upgrade your subscription to add more units.'),
            $unitUsage['used'], $unitUsage['limit']
        );
    }

    // Validate
    if (!$old['property_id']) $errors[] = __('property_required', 'Property is required');
    if ($old['unit_number'] === '') $errors[] = __('unit_number_required', 'Unit number is required');
    if (!in_array($old['unit_type'], $validTypes)) $errors[] = __('invalid_unit_type', 'Invalid unit type');
    if (!in_array($old['status'], $validStatuses)) $errors[] = __('invalid_status', 'Invalid status');

    // Check unique unit_number within property
    if ($old['property_id'] && $old['unit_number'] !== '') {
        $dupeCheck = $conn->prepare("SELECT id FROM units WHERE property_id = :pid AND unit_number = :num");
        $dupeCheck->execute(['pid' => $old['property_id'], 'num' => $old['unit_number']]);
        if ($dupeCheck->fetch()) {
            $errors[] = __('unit_number_exists', 'Unit number already exists in this property');
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO units (property_id, unit_number, unit_label, unit_type, floor, size_sqm, bedrooms, bathrooms, rent_amount, currency, deposit_amount, status, description)
                VALUES (:property_id, :unit_number, :unit_label, :unit_type, :floor, :size_sqm, :bedrooms, :bathrooms, :rent_amount, :currency, :deposit_amount, :status, :description)
            ");
            $stmt->execute([
                'property_id' => $old['property_id'],
                'unit_number' => $old['unit_number'],
                'unit_label' => $old['unit_label'] ?: null,
                'unit_type' => $old['unit_type'],
                'floor' => $old['floor'] ?: null,
                'size_sqm' => $old['size_sqm'] !== '' ? (float)$old['size_sqm'] : null,
                'bedrooms' => $old['bedrooms'] !== '' ? (int)$old['bedrooms'] : null,
                'bathrooms' => $old['bathrooms'] !== '' ? (int)$old['bathrooms'] : null,
                'rent_amount' => max(0, (float)$old['rent_amount']),
                'currency' => $old['currency'],
                'deposit_amount' => max(0, (float)$old['deposit_amount']),
                'status' => $old['status'],
                'description' => $old['description']
            ]);

            $unitId = (int) $conn->lastInsertId();

            logActivity('created', 'unit', $unitId, ['unit_number' => $old['unit_number'], 'property_id' => $old['property_id']]);

            setAlert(__('unit_created', 'Unit created successfully!'), 'success');
            redirect('modules/units/view.php?id=' . $unitId);
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
        <h4><i class="bi bi-plus-circle me-2"></i><?php _e('add_unit', 'Add Unit'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/units/"><?php _e('units'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('create'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('units.create'); ?>

<!-- Unit quota reached -->
<?php if ($unitUsage['at_limit']): ?>
<div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?php echo sprintf(__('unit_limit_reached', 'You have reached your subscription unit limit (%1$d of %2$d units used). Upgrade your subscription to add more units.'), $unitUsage['used'], $unitUsage['limit']); ?>
    </div>
    <a href="<?php echo BASE_URL; ?>modules/subscription/subscribe.php" class="btn btn-sm btn-warning">
        <i class="bi bi-arrow-up-circle me-1"></i> <?php _e('unit_limit_upgrade', 'Upgrade Plan'); ?>
    </a>
</div>
<?php endif; ?>

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
        <form method="POST" id="unitForm">
            <?php echo csrfField(); ?>

            <div class="row g-3">
                <!-- Property Selector -->
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('property', 'Property'); ?> <span class="text-danger">*</span></label>
                    <select name="property_id" class="form-select" required id="propertySelect">
                        <option value=""><?php _e('select_property', 'Select Property'); ?></option>
                        <?php foreach ($properties as $prop): ?>
                        <option value="<?php echo $prop['id']; ?>" <?php echo ($old['property_id'] ?? $preselectedProperty) == $prop['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($prop['name']); ?> (<?php echo ucfirst($prop['property_type']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Unit Number -->
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('unit_number', 'Unit #'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="unit_number" value="<?php echo htmlspecialchars($old['unit_number'] ?? ''); ?>" required placeholder="e.g. A101, Room 5">
                </div>

                <!-- Unit Label -->
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('unit_label', 'Label'); ?> <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                    <input type="text" class="form-control" name="unit_label" value="<?php echo htmlspecialchars($old['unit_label'] ?? ''); ?>" placeholder="e.g. Master Suite">
                </div>

                <!-- Unit Type -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('unit_type', 'Unit Type'); ?> <span class="text-danger">*</span></label>
                    <select name="unit_type" class="form-select" required>
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
                        <option value="<?php echo $val; ?>" <?php echo ($old['unit_type'] ?? 'apartment') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Floor -->
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('floor', 'Floor'); ?></label>
                    <input type="text" class="form-control" name="floor" value="<?php echo htmlspecialchars($old['floor'] ?? ''); ?>" placeholder="e.g. 1, GF, B1">
                </div>

                <!-- Size -->
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('size_sqm', 'Size (m²)'); ?></label>
                    <input type="number" class="form-control" name="size_sqm" value="<?php echo htmlspecialchars($old['size_sqm'] ?? ''); ?>" min="0" step="0.01">
                </div>

                <!-- Bedrooms -->
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('bedrooms', 'Bedrooms'); ?></label>
                    <input type="number" class="form-control" name="bedrooms" value="<?php echo htmlspecialchars($old['bedrooms'] ?? ''); ?>" min="0" max="20">
                </div>

                <!-- Bathrooms -->
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('bathrooms', 'Bathrooms'); ?></label>
                    <input type="number" class="form-control" name="bathrooms" value="<?php echo htmlspecialchars($old['bathrooms'] ?? ''); ?>" min="0" max="20">
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
                        <input type="number" class="form-control" name="rent_amount" value="<?php echo htmlspecialchars($old['rent_amount'] ?? '0'); ?>" min="0" step="0.01">
                        <select name="currency" class="form-select" style="max-width:90px;">
                            <?php foreach ($currencies as $curr): ?>
                            <option value="<?php echo $curr; ?>" <?php echo ($old['currency'] ?? DEFAULT_CURRENCY) === $curr ? 'selected' : ''; ?>><?php echo $curr; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Deposit Amount -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('deposit_amount', 'Deposit Amount'); ?></label>
                    <input type="number" class="form-control" name="deposit_amount" value="<?php echo htmlspecialchars($old['deposit_amount'] ?? '0'); ?>" min="0" step="0.01">
                </div>

                <!-- Status -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('status'); ?></label>
                    <select name="status" class="form-select">
                        <option value="vacant" <?php echo ($old['status'] ?? 'vacant') === 'vacant' ? 'selected' : ''; ?>><?php _e('vacant', 'Vacant'); ?></option>
                        <option value="occupied" <?php echo ($old['status'] ?? '') === 'occupied' ? 'selected' : ''; ?>><?php _e('occupied', 'Occupied'); ?></option>
                        <option value="maintenance" <?php echo ($old['status'] ?? '') === 'maintenance' ? 'selected' : ''; ?>><?php _e('maintenance', 'Maintenance'); ?></option>
                        <option value="reserved" <?php echo ($old['status'] ?? '') === 'reserved' ? 'selected' : ''; ?>><?php _e('reserved', 'Reserved'); ?></option>
                    </select>
                </div>
            </div>

            <hr>

            <!-- Description -->
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('description'); ?></label>
                    <textarea class="form-control" name="description" rows="3" placeholder="<?php _e('unit_description_placeholder', 'Brief description of the unit, amenities, features'); ?>"><?php echo htmlspecialchars($old['description'] ?? ''); ?></textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/units/" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
                </a>
                <button type="submit" class="btn btn-primary" <?php echo $unitUsage['at_limit'] ? 'disabled' : ''; ?>>
                    <i class="bi bi-check-lg me-1"></i> <?php _e('save_unit', 'Save Unit'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<script>
$(function() {
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
