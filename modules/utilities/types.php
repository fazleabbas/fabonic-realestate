<?php
/**
 * Fabonic Real Estate — Utility Types
 * Configure what can be billed (water, electricity, garbage…) and how it is
 * priced: per unit of consumption, a flat monthly fee, or tiered bands.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('util_types', 'Utility Types');

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

$types = $conn->query("SELECT * FROM utility_types ORDER BY status, name")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-lightning-charge me-2"></i><?php _e('util_types', 'Utility Types'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/utilities/"><?php _e('util_billing', 'Utility Billing'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('util_types', 'Types'); ?></li>
            </ol>
        </nav>
    </div>
    <?php if (hasPermission('utilities', 'edit')): ?>
    <button class="btn btn-primary" onclick="newType()">
        <i class="bi bi-plus-circle me-1"></i> <?php _e('util_add_type', 'Add Utility Type'); ?>
    </button>
    <?php endif; ?>
</div>

<?php pageIntro('utilities.types'); ?>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-list me-1"></i> <?php _e('util_types', 'Utility Types'); ?> (<?php echo count($types); ?>)</div>
            <div class="card-body p-0">
                <?php if (!$types): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-lightning-charge" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('util_no_types_yet', 'No utility types yet. Add water, electricity or garbage to get started.'); ?></p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('name'); ?></th>
                                <th><?php _e('util_rate_type', 'Billing'); ?></th>
                                <th class="text-end"><?php _e('util_rate', 'Rate'); ?></th>
                                <th><?php _e('status'); ?></th>
                                <th class="text-end"><?php _e('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($types as $t):
                                $bands = $t['rate_type'] === 'tiered' ? (json_decode($t['tiered_rates_json'] ?? '[]', true) ?: []) : [];
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($t['name']); ?></strong>
                                    <?php if ($t['unit_of_measure']): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($t['unit_of_measure']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $labels = [
                                        'per_unit' => __('util_per_unit', 'Per unit'),
                                        'flat'     => __('util_flat', 'Flat rate'),
                                        'tiered'   => __('util_tiered', 'Tiered'),
                                    ];
                                    echo '<span class="badge bg-secondary">' . ($labels[$t['rate_type']] ?? $t['rate_type']) . '</span>';
                                    ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($t['rate_type'] === 'tiered'): ?>
                                        <?php foreach ($bands as $b): ?>
                                        <div style="font-size:.8rem;">
                                            <?php echo (float)($b['from'] ?? 0); ?>&ndash;<?php echo ($b['to'] === null || $b['to'] === '') ? '&infin;' : (float)$b['to']; ?>:
                                            <?php echo formatCurrency($b['rate'] ?? 0); ?>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?php echo formatCurrency($t['rate_amount']); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $t['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo $t['status'] === 'active' ? __('active', 'Active') : __('inactive', 'Inactive'); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <?php if (hasPermission('utilities', 'edit')): ?>
                                    <button class="btn btn-sm btn-outline-primary" onclick='editType(<?php echo json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if (hasPermission('utilities', 'delete')): ?>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteType(<?php echo (int)$t['id']; ?>, '<?php echo htmlspecialchars(addslashes($t['name'])); ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Form -->
    <?php if (hasPermission('utilities', 'edit')): ?>
    <div class="col-12 col-lg-5">
        <div class="card" id="typeCard">
            <div class="card-header"><i class="bi bi-pencil-square me-1"></i> <span id="formTitle"><?php _e('util_add_type', 'Add Utility Type'); ?></span></div>
            <div class="card-body">
                <form id="typeForm">
                    <input type="hidden" name="id" id="t_id" value="">

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="t_name" class="form-control" required maxlength="50" placeholder="<?php _e('util_name_ph', 'e.g. Water'); ?>">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?php _e('util_uom', 'Unit of measure'); ?></label>
                            <input type="text" name="unit_of_measure" id="t_uom" class="form-control" maxlength="30" placeholder="<?php _e('util_uom_ph', 'kWh, litres…'); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?php _e('status'); ?></label>
                            <select name="status" id="t_status" class="form-select">
                                <option value="active"><?php _e('active', 'Active'); ?></option>
                                <option value="inactive"><?php _e('inactive', 'Inactive'); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('util_rate_type', 'Billing method'); ?></label>
                        <select name="rate_type" id="t_rate_type" class="form-select" onchange="toggleRateType()">
                            <option value="per_unit"><?php _e('util_per_unit_full', 'Per unit consumed'); ?></option>
                            <option value="flat"><?php _e('util_flat_full', 'Flat monthly rate'); ?></option>
                            <option value="tiered"><?php _e('util_tiered_full', 'Tiered bands'); ?></option>
                        </select>
                    </div>

                    <div class="mb-3" id="rateAmountGroup">
                        <label class="form-label fw-semibold"><?php _e('util_rate', 'Rate'); ?></label>
                        <input type="number" name="rate_amount" id="t_rate" class="form-control" step="0.01" min="0" value="0">
                        <small class="text-muted" id="rateHint"><?php _e('util_rate_hint_per_unit', 'Charged for each unit consumed.'); ?></small>
                    </div>

                    <div class="mb-3 d-none" id="tierGroup">
                        <label class="form-label fw-semibold"><?php _e('util_tiers', 'Rate bands'); ?></label>
                        <div id="tierRows"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addTier()">
                            <i class="bi bi-plus"></i> <?php _e('util_add_tier', 'Add band'); ?>
                        </button>
                        <small class="d-block text-muted mt-2"><?php _e('util_tiers_hint', 'Each band prices only the consumption inside it. Leave the last "to" empty for "and above".'); ?></small>
                    </div>

                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" onclick="newType()"><?php _e('cancel'); ?></button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> <?php _e('save'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const API = BASE_URL + 'api/utilities.php';
const CSRF = '<?php echo generateCSRF(); ?>';

function toggleRateType() {
    const v = document.getElementById('t_rate_type').value;
    document.getElementById('tierGroup').classList.toggle('d-none', v !== 'tiered');
    document.getElementById('rateAmountGroup').classList.toggle('d-none', v === 'tiered');
    const hints = {
        per_unit: '<?php echo __("util_rate_hint_per_unit", "Charged for each unit consumed."); ?>',
        flat: '<?php echo __("util_rate_hint_flat", "Charged every month regardless of consumption."); ?>'
    };
    if (hints[v]) document.getElementById('rateHint').textContent = hints[v];
    if (v === 'tiered' && !document.querySelectorAll('#tierRows .tier-row').length) addTier();
}

function addTier(from = '', to = '', rate = '') {
    const row = document.createElement('div');
    row.className = 'row g-1 mb-1 tier-row';
    row.innerHTML = `
        <div class="col-3"><input type="number" name="tier_from[]" class="form-control form-control-sm" placeholder="<?php echo __('util_from', 'From'); ?>" step="0.01" min="0" value="${from}"></div>
        <div class="col-3"><input type="number" name="tier_to[]" class="form-control form-control-sm" placeholder="<?php echo __('util_to', 'To'); ?>" step="0.01" min="0" value="${to}"></div>
        <div class="col-4"><input type="number" name="tier_rate[]" class="form-control form-control-sm" placeholder="<?php echo __('util_rate', 'Rate'); ?>" step="0.01" min="0" value="${rate}"></div>
        <div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="this.closest('.tier-row').remove()"><i class="bi bi-x"></i></button></div>`;
    document.getElementById('tierRows').appendChild(row);
}

function newType() {
    document.getElementById('typeForm').reset();
    document.getElementById('t_id').value = '';
    document.getElementById('tierRows').innerHTML = '';
    document.getElementById('formTitle').textContent = '<?php echo __("util_add_type", "Add Utility Type"); ?>';
    toggleRateType();
}

function editType(t) {
    document.getElementById('t_id').value = t.id;
    document.getElementById('t_name').value = t.name || '';
    document.getElementById('t_uom').value = t.unit_of_measure || '';
    document.getElementById('t_status').value = t.status || 'active';
    document.getElementById('t_rate_type').value = t.rate_type || 'per_unit';
    document.getElementById('t_rate').value = t.rate_amount || 0;
    document.getElementById('tierRows').innerHTML = '';
    if (t.rate_type === 'tiered') {
        let bands = [];
        try { bands = JSON.parse(t.tiered_rates_json || '[]') || []; } catch (e) { bands = []; }
        bands.forEach(b => addTier(b.from ?? '', b.to ?? '', b.rate ?? ''));
    }
    document.getElementById('formTitle').textContent = '<?php echo __("edit"); ?>: ' + (t.name || '');
    toggleRateType();
    document.getElementById('typeCard').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

document.getElementById('typeForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('action', 'save_type');
    fd.append('csrf_token', CSRF);
    fetch(API, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.message); })
        .catch(() => alert('Request failed'));
});

function deleteType(id, name) {
    if (!confirm('<?php echo __("util_delete_type_confirm", "Delete utility type"); ?> "' + name + '"?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_type');
    fd.append('csrf_token', CSRF);
    fd.append('id', id);
    fetch(API, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.message); })
        .catch(() => alert('Request failed'));
}

toggleRateType();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
