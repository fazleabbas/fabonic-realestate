<?php
/**
 * Fabonic Real Estate — Meter Readings
 * Record the current meter reading for a unit. The previous reading is filled
 * in automatically from the last entry, so the person at the meter only types
 * the new number and consumption is worked out for them.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('util_readings', 'Meter Readings');

$subStatus = enforceSubscription();

if (!hasTier('enterprise')) {
    setAlert(__('util_tier_required', 'Enterprise tier required for utility billing.'), 'warning');
    redirect('index.php');
}
if (getSetting('utility_billing_enabled', '0') !== '1') {
    setAlert(__('util_disabled', 'Utility billing is disabled. Enable it in Settings > Modules.'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('utilities', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$properties = $conn->query("
    SELECT id, name FROM properties
    WHERE status = 'active'" . propertyScopeSql('id') . "
    ORDER BY name
")->fetchAll();

$types = $conn->query("SELECT id, name, unit_of_measure, rate_type FROM utility_types WHERE status = 'active' ORDER BY name")->fetchAll();

// Recent readings
$recent = $conn->query("
    SELECT ur.*, u.unit_number, p.name AS property_name, ut.name AS utility_name, ut.unit_of_measure,
           usr.full_name AS recorded_by_name
    FROM utility_readings ur
    INNER JOIN units u ON ur.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN utility_types ut ON ur.utility_type_id = ut.id
    LEFT JOIN users usr ON ur.recorded_by = usr.id
    WHERE 1=1" . propertyScopeSql('p.id') . "
    ORDER BY ur.reading_date DESC, ur.id DESC
    LIMIT 50
")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-speedometer2 me-2"></i><?php _e('util_readings', 'Meter Readings'); ?></h4>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/utilities/"><?php _e('util_billing', 'Utility Billing'); ?></a></li>
            <li class="breadcrumb-item active"><?php _e('util_readings', 'Readings'); ?></li>
        </ol>
    </nav>
</div>

<?php pageIntro('utilities.readings'); ?>

<?php if (!$types): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <?php _e('util_need_types_first', 'No active utility types yet.'); ?>
    <a href="<?php echo BASE_URL; ?>modules/utilities/types.php" class="alert-link"><?php _e('util_add_one', 'Add one first'); ?></a>.
</div>
<?php else: ?>

<div class="row g-3">
    <?php if (hasPermission('utilities', 'create')): ?>
    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-plus-circle me-1"></i> <?php _e('util_new_reading', 'Record Reading'); ?></div>
            <div class="card-body">
                <form id="readingForm">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('property'); ?> <span class="text-danger">*</span></label>
                        <select id="r_property" class="form-select" required onchange="loadUnits()">
                            <option value=""><?php _e('select_property', 'Select Property'); ?></option>
                            <?php foreach ($properties as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('unit', 'Unit'); ?> <span class="text-danger">*</span></label>
                        <select name="unit_id" id="r_unit" class="form-select" required onchange="loadPrevious()">
                            <option value=""><?php _e('util_select_property_first', 'Select a property first'); ?></option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('util_type', 'Utility'); ?> <span class="text-danger">*</span></label>
                        <select name="utility_type_id" id="r_type" class="form-select" required onchange="loadPrevious()">
                            <?php foreach ($types as $t): ?>
                            <option value="<?php echo (int)$t['id']; ?>" data-uom="<?php echo htmlspecialchars($t['unit_of_measure'] ?? ''); ?>" data-rate-type="<?php echo $t['rate_type']; ?>">
                                <?php echo htmlspecialchars($t['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted d-none" id="flatWarn"><?php _e('util_flat_no_reading', 'This is a flat-rate utility — readings are not needed.'); ?></small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('util_reading_date', 'Reading date'); ?></label>
                        <input type="date" name="reading_date" id="r_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small"><?php _e('util_previous', 'Previous'); ?></label>
                            <input type="number" name="previous_reading" id="r_prev" class="form-control" step="0.01" min="0" value="0">
                            <small class="text-muted" id="prevAsOf"></small>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small"><?php _e('util_current', 'Current'); ?> <span class="text-danger">*</span></label>
                            <input type="number" name="current_reading" id="r_current" class="form-control" step="0.01" min="0" required oninput="calcConsumption()">
                        </div>
                    </div>

                    <div class="alert alert-light border mb-3 py-2">
                        <?php _e('util_consumption', 'Consumption'); ?>:
                        <strong id="consumptionOut">0</strong> <span id="uomOut" class="text-muted"></span>
                    </div>

                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i> <?php _e('save'); ?></button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-12 <?php echo hasPermission('utilities', 'create') ? 'col-lg-8' : ''; ?>">
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history me-1"></i> <?php _e('util_recent_readings', 'Recent Readings'); ?></div>
            <div class="card-body p-0">
                <?php if (!$recent): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-speedometer2" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('util_no_readings', 'No readings recorded yet.'); ?></p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('date'); ?></th>
                                <th><?php _e('unit', 'Unit'); ?></th>
                                <th><?php _e('util_type', 'Utility'); ?></th>
                                <th class="text-end"><?php _e('util_previous', 'Previous'); ?></th>
                                <th class="text-end"><?php _e('util_current', 'Current'); ?></th>
                                <th class="text-end"><?php _e('util_consumption', 'Consumption'); ?></th>
                                <?php if (hasPermission('utilities', 'delete')): ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent as $r): ?>
                            <tr>
                                <td><?php echo formatDate($r['reading_date']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($r['unit_number']); ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($r['property_name']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($r['utility_name']); ?></td>
                                <td class="text-end"><?php echo number_format((float)$r['previous_reading'], 2); ?></td>
                                <td class="text-end"><?php echo number_format((float)$r['current_reading'], 2); ?></td>
                                <td class="text-end">
                                    <strong><?php echo number_format((float)$r['consumption'], 2); ?></strong>
                                    <small class="text-muted"><?php echo htmlspecialchars($r['unit_of_measure'] ?? ''); ?></small>
                                </td>
                                <?php if (hasPermission('utilities', 'delete')): ?>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-danger border-0" onclick="deleteReading(<?php echo (int)$r['id']; ?>)"><i class="bi bi-trash"></i></button>
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
    </div>
</div>

<script>
const API = BASE_URL + 'api/utilities.php';
const CSRF = '<?php echo generateCSRF(); ?>';

function loadUnits() {
    const pid = document.getElementById('r_property').value;
    const sel = document.getElementById('r_unit');
    sel.innerHTML = '<option value="">…</option>';
    if (!pid) return;
    fetch(API + '?action=units_by_property&property_id=' + encodeURIComponent(pid))
        .then(r => r.json())
        .then(d => {
            sel.innerHTML = '<option value=""><?php echo __("select", "Select"); ?></option>';
            (d.data || []).forEach(u => {
                const o = document.createElement('option');
                o.value = u.id;
                o.textContent = u.unit_number + ' (' + u.unit_type + ')';
                sel.appendChild(o);
            });
        });
}

function loadPrevious() {
    const unit = document.getElementById('r_unit').value;
    const type = document.getElementById('r_type').value;
    const opt = document.getElementById('r_type').selectedOptions[0];
    document.getElementById('uomOut').textContent = opt ? (opt.dataset.uom || '') : '';
    document.getElementById('flatWarn').classList.toggle('d-none', !opt || opt.dataset.rateType !== 'flat');
    if (!unit || !type) return;
    fetch(API + '?action=previous_reading&unit_id=' + unit + '&utility_type_id=' + type)
        .then(r => r.json())
        .then(d => {
            document.getElementById('r_prev').value = d.previous ?? 0;
            document.getElementById('prevAsOf').textContent = d.as_of ? ('<?php echo __("util_as_of", "as of"); ?> ' + d.as_of) : '';
            calcConsumption();
        });
}

function calcConsumption() {
    const prev = parseFloat(document.getElementById('r_prev').value) || 0;
    const cur = parseFloat(document.getElementById('r_current').value) || 0;
    const c = cur - prev;
    const out = document.getElementById('consumptionOut');
    out.textContent = (c).toFixed(2);
    out.className = c < 0 ? 'text-danger' : '';
}

document.getElementById('readingForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('action', 'save_reading');
    fd.append('csrf_token', CSRF);
    fetch(API, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.message); })
        .catch(() => alert('Request failed'));
});

function deleteReading(id) {
    if (!confirm('<?php echo __("util_delete_reading_confirm", "Delete this reading?"); ?>')) return;
    const fd = new FormData();
    fd.append('action', 'delete_reading');
    fd.append('csrf_token', CSRF);
    fd.append('id', id);
    fetch(API, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.message); });
}

loadPrevious();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
