<?php
/**
 * FIS Real Estate — Create/Edit Vacancy Listing (Part 20.4)
 */
$editId = (int)($_GET['id'] ?? 0);
$isEdit = $editId > 0;
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = $isEdit ? __('vac_edit', 'Edit Listing') : __('vac_add', 'Add Listing');

$subStatus = enforceSubscription();

if (!hasTier('enterprise')) {
    setAlert(__('vac_tier_required'), 'warning');
    redirect('index.php');
}
if (getSetting('vacancy_advertising_enabled', '0') !== '1') {
    setAlert(__('vac_disabled'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('vacancies', $isEdit ? 'edit' : 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$listing = null;
if ($isEdit) {
    $stmt = $conn->prepare("
        SELECT vl.*, u.property_id, u.unit_number, u.rent_amount AS unit_rent, u.deposit_amount AS unit_deposit
        FROM vacancy_listings vl
        INNER JOIN units u ON vl.unit_id = u.id
        WHERE vl.id = ?
    ");
    $stmt->execute([$editId]);
    $listing = $stmt->fetch();
    if (!$listing) {
        setAlert('Listing not found', 'danger');
        redirect('modules/vacancies/');
    }
    if (!canAccessUnit($listing['unit_id'])) {
        setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
        redirect('modules/vacancies/');
    }
}

// Available units (vacant, not already listed — except current edit)
$unitsSql = "
    SELECT u.id, u.unit_number, u.rent_amount, u.deposit_amount, u.currency, u.unit_type,
           u.bedrooms, u.bathrooms, u.size_sqm, p.name AS property_name, u.property_id
    FROM units u
    INNER JOIN properties p ON u.property_id = p.id
    WHERE u.status = 'vacant'" . propertyScopeSql('u.property_id') . "
    AND u.id NOT IN (SELECT unit_id FROM vacancy_listings WHERE status = 'active'" . ($isEdit ? " AND id != ?" : "") . ")
    ORDER BY p.name, u.unit_number
";
$unitsStmt = $conn->prepare($unitsSql);
$unitsStmt->execute($isEdit ? [$editId] : []);
$availableUnits = $unitsStmt->fetchAll();

// If editing, include the current unit
if ($isEdit) {
    $currentUnitInList = false;
    foreach ($availableUnits as $u) {
        if ($u['id'] == $listing['unit_id']) { $currentUnitInList = true; break; }
    }
    if (!$currentUnitInList) {
        $cuStmt = $conn->prepare("SELECT u.id, u.unit_number, u.rent_amount, u.deposit_amount, u.currency, u.unit_type, u.bedrooms, u.bathrooms, u.size_sqm, p.name AS property_name, u.property_id FROM units u INNER JOIN properties p ON u.property_id = p.id WHERE u.id = ?");
        $cuStmt->execute([$listing['unit_id']]);
        $cu = $cuStmt->fetch();
        if ($cu) array_unshift($availableUnits, $cu);
    }
}

$properties = $conn->query("SELECT id, name FROM properties WHERE status = 'active' ORDER BY name")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-<?php echo $isEdit ? 'pencil' : 'plus-circle'; ?> me-2"></i><?php echo $pageTitle; ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/vacancies/"><?php _e('vac_vacancies', 'Vacancies'); ?></a></li>
                <li class="breadcrumb-item active"><?php echo $isEdit ? __('edit') : __('create'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('vacancies.create'); ?>

<div class="card">
    <div class="card-body">
        <form id="listingForm">
            <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?php echo $editId; ?>">
            <?php endif; ?>

            <div class="row g-3">
                <!-- Unit Selection -->
                <div class="col-md-6">
                    <label class="form-label"><?php _e('unit'); ?> <span class="text-danger">*</span></label>
                    <select name="unit_id" id="unitSelect" class="form-select" required <?php echo $isEdit ? 'disabled' : ''; ?>>
                        <option value=""><?php _e('vac_select_unit', 'Select vacant unit'); ?>...</option>
                        <?php foreach ($availableUnits as $u): ?>
                        <option value="<?php echo $u['id']; ?>"
                                data-rent="<?php echo $u['rent_amount']; ?>"
                                data-deposit="<?php echo $u['deposit_amount']; ?>"
                                data-currency="<?php echo $u['currency']; ?>"
                                data-type="<?php echo $u['unit_type']; ?>"
                                data-bedrooms="<?php echo $u['bedrooms']; ?>"
                                data-bathrooms="<?php echo $u['bathrooms']; ?>"
                                data-size="<?php echo $u['size_sqm']; ?>"
                                <?php echo ($isEdit && $listing['unit_id'] == $u['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['property_name'] . ' — ' . $u['unit_number'] . ' (' . ucfirst($u['unit_type']) . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isEdit): ?>
                    <input type="hidden" name="unit_id" value="<?php echo $listing['unit_id']; ?>">
                    <?php endif; ?>
                </div>

                <!-- Title -->
                <div class="col-md-6">
                    <label class="form-label"><?php _e('vac_title', 'Listing Title'); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="title" id="titleInput" class="form-control" required
                           value="<?php echo $isEdit ? htmlspecialchars($listing['title']) : ''; ?>"
                           placeholder="<?php _e('vac_title_placeholder', 'e.g., Spacious 2BR Apartment in City Center'); ?>">
                </div>

                <!-- Rent -->
                <div class="col-md-4">
                    <label class="form-label"><?php _e('exp_rent', 'Rent Amount'); ?> <span class="text-danger">*</span></label>
                    <input type="number" name="rent_amount" id="rentInput" class="form-control" step="0.01" min="0.01" required
                           value="<?php echo $isEdit ? $listing['rent_amount'] : ''; ?>">
                </div>

                <!-- Deposit -->
                <div class="col-md-4">
                    <label class="form-label"><?php _e('vac_deposit', 'Deposit Amount'); ?></label>
                    <input type="number" name="deposit_amount" id="depositInput" class="form-control" step="0.01" min="0"
                           value="<?php echo $isEdit ? $listing['deposit_amount'] : ''; ?>">
                </div>

                <!-- Currency -->
                <div class="col-md-4">
                    <label class="form-label"><?php _e('currency'); ?></label>
                    <input type="text" name="currency" class="form-control" value="<?php echo $isEdit ? $listing['currency'] : DEFAULT_CURRENCY; ?>" readonly>
                </div>

                <!-- Available From -->
                <div class="col-md-4">
                    <label class="form-label"><?php _e('vac_available_from', 'Available From'); ?></label>
                    <input type="date" name="available_from" class="form-control"
                           value="<?php echo $isEdit ? ($listing['available_from'] ?? '') : date('Y-m-d'); ?>">
                </div>

                <!-- Contact Name -->
                <div class="col-md-4">
                    <label class="form-label"><?php _e('vac_contact_name', 'Contact Name'); ?></label>
                    <input type="text" name="contact_name" class="form-control"
                           value="<?php echo htmlspecialchars($isEdit ? ($listing['contact_name'] ?? '') : getSetting('vacancy_company_name', getSetting('company_name', ''))); ?>">
                </div>

                <!-- Contact Phone -->
                <div class="col-md-4">
                    <label class="form-label"><?php _e('vac_contact_phone', 'Contact Phone'); ?></label>
                    <input type="text" name="contact_phone" class="form-control"
                           value="<?php echo htmlspecialchars($isEdit ? ($listing['contact_phone'] ?? '') : getSetting('vacancy_contact_phone', getSetting('company_phone', ''))); ?>">
                </div>

                <!-- Contact Email -->
                <div class="col-md-6">
                    <label class="form-label"><?php _e('vac_contact_email', 'Contact Email'); ?></label>
                    <input type="email" name="contact_email" class="form-control"
                           value="<?php echo htmlspecialchars($isEdit ? ($listing['contact_email'] ?? '') : getSetting('vacancy_contact_email', getSetting('company_email', ''))); ?>">
                </div>

                <!-- Features -->
                <div class="col-md-6">
                    <label class="form-label"><?php _e('vac_features', 'Features / Amenities'); ?></label>
                    <input type="text" name="features" class="form-control"
                           value="<?php echo $isEdit && $listing['features_json'] ? htmlspecialchars(implode(', ', json_decode($listing['features_json'], true) ?? [])) : ''; ?>"
                           placeholder="<?php _e('vac_features_hint', 'Comma-separated: Parking, WiFi, Security, Pool'); ?>">
                </div>

                <!-- Description -->
                <div class="col-12">
                    <label class="form-label"><?php _e('description'); ?></label>
                    <textarea name="description" class="form-control" rows="4"
                              placeholder="<?php _e('vac_desc_placeholder', 'Describe the unit, its features, neighborhood...'); ?>"><?php echo $isEdit ? htmlspecialchars($listing['description'] ?? '') : ''; ?></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="bi bi-check-circle me-1"></i><?php echo $isEdit ? __('save') : __('vac_publish', 'Publish Listing'); ?>
                </button>
                <a href="<?php echo BASE_URL; ?>modules/vacancies/" class="btn btn-secondary"><?php _e('cancel'); ?></a>
            </div>
        </form>
    </div>
</div>

<script>
// Auto-fill rent/deposit from unit selection
document.getElementById('unitSelect')?.addEventListener('change', function() {
    const opt = this.selectedOptions[0];
    if (opt && opt.value) {
        document.getElementById('rentInput').value = opt.dataset.rent || '';
        document.getElementById('depositInput').value = opt.dataset.deposit || '';
        if (!document.getElementById('titleInput').value) {
            const type = opt.dataset.type ? opt.dataset.type.charAt(0).toUpperCase() + opt.dataset.type.slice(1) : '';
            const beds = opt.dataset.bedrooms ? opt.dataset.bedrooms + 'BR ' : '';
            document.getElementById('titleInput').value = beds + type + ' — ' + opt.textContent.trim().split(' — ')[0];
        }
    }
});

document.getElementById('listingForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('action', <?php echo $isEdit ? "'update'" : "'create'"; ?>);

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

    fetch(BASE_URL + 'api/vacancies.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i><?php echo $isEdit ? __("save") : __("vac_publish", "Publish"); ?>';
            if (res.success) {
                location.href = BASE_URL + 'modules/vacancies/';
            } else {
                alert(res.message);
            }
        })
        .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Save'; });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
