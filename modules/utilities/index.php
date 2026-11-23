<?php
/**
 * Fabonic Real Estate — Utility Charges
 * Generate a period's charges from recorded readings, then settle each one on
 * the charge itself (pending / paid / waived). The payments table is rent-only
 * and is intentionally untouched.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('util_billing', 'Utility Billing');

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

// Filters
$fMonth    = (int)($_GET['month'] ?? date('n'));
$fYear     = (int)($_GET['year'] ?? date('Y'));
$fStatus   = trim($_GET['status'] ?? '');
$fProperty = (int)($_GET['property_id'] ?? 0);

$where  = "c.period_month = :m AND c.period_year = :y";
$params = ['m' => $fMonth, 'y' => $fYear];
if ($fStatus !== '' && in_array($fStatus, ['pending', 'paid', 'waived'], true)) {
    $where .= " AND c.status = :st";
    $params['st'] = $fStatus;
}
if ($fProperty) {
    $where .= " AND p.id = :pid";
    $params['pid'] = $fProperty;
}

$charges = [];
$totals  = ['pending' => 0, 'paid' => 0, 'waived' => 0];
try {
    $stmt = $conn->prepare("
        SELECT c.*, ut.name AS utility_name, ut.unit_of_measure,
               u.unit_number, p.name AS property_name,
               CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone
        FROM utility_charges c
        INNER JOIN utility_types ut ON c.utility_type_id = ut.id
        INNER JOIN units u ON c.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        INNER JOIN tenants t ON c.tenant_id = t.id
        WHERE $where" . propertyScopeSql('p.id') . "
        ORDER BY p.name, u.unit_number, ut.name
    ");
    $stmt->execute($params);
    $charges = $stmt->fetchAll();

    foreach ($charges as $c) {
        $totals[$c['status']] = ($totals[$c['status']] ?? 0) + (float)$c['amount'];
    }
} catch (PDOException $e) {
    error_log('Utility charges list: ' . $e->getMessage());
}

$properties = $conn->query("
    SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name
")->fetchAll();

$activeTypes = (int)$conn->query("SELECT COUNT(*) FROM utility_types WHERE status = 'active'")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-lightning me-2"></i><?php _e('util_billing', 'Utility Billing'); ?></h4>
        <small class="text-muted"><?php _e('util_subtitle', 'Meter-based charges for water, electricity and more'); ?></small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?php echo BASE_URL; ?>modules/utilities/readings.php" class="btn btn-outline-primary">
            <i class="bi bi-speedometer2 me-1"></i> <?php _e('util_readings', 'Readings'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/utilities/types.php" class="btn btn-outline-secondary">
            <i class="bi bi-sliders me-1"></i> <?php _e('util_types', 'Types'); ?>
        </a>
    </div>
</div>

<?php pageIntro('utilities.index'); ?>

<?php if (!$activeTypes): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>
    <?php _e('util_getting_started', 'Start by adding the utilities you bill for — water, electricity, garbage — then record meter readings and generate charges.'); ?>
    <a href="<?php echo BASE_URL; ?>modules/utilities/types.php" class="alert-link"><?php _e('util_add_type', 'Add Utility Type'); ?></a>
</div>
<?php endif; ?>

<!-- Totals -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card text-center py-2"><div class="card-body py-2">
            <div class="text-warning"><i class="bi bi-hourglass-split" style="font-size:1.3rem;"></i></div>
            <div class="fw-bold"><?php echo formatCurrency($totals['pending'] ?? 0); ?></div>
            <small class="text-muted"><?php _e('util_pending', 'Pending'); ?></small>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-2"><div class="card-body py-2">
            <div class="text-success"><i class="bi bi-check-circle" style="font-size:1.3rem;"></i></div>
            <div class="fw-bold"><?php echo formatCurrency($totals['paid'] ?? 0); ?></div>
            <small class="text-muted"><?php _e('util_paid', 'Paid'); ?></small>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-2"><div class="card-body py-2">
            <div class="text-secondary"><i class="bi bi-slash-circle" style="font-size:1.3rem;"></i></div>
            <div class="fw-bold"><?php echo formatCurrency($totals['waived'] ?? 0); ?></div>
            <small class="text-muted"><?php _e('util_waived', 'Waived'); ?></small>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-2"><div class="card-body py-2">
            <div class="text-primary"><i class="bi bi-receipt" style="font-size:1.3rem;"></i></div>
            <div class="fw-bold"><?php echo count($charges); ?></div>
            <small class="text-muted"><?php _e('util_charges', 'Charges'); ?></small>
        </div></div>
    </div>
</div>

<!-- Filters + generate -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold"><?php _e('month', 'Month'); ?></label>
                <select name="month" class="form-select form-select-sm">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $m === $fMonth ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold"><?php _e('year', 'Year'); ?></label>
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = (int)date('Y') - 3; $y <= (int)date('Y') + 1; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y === $fYear ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold"><?php _e('property'); ?></label>
                <select name="property_id" class="form-select form-select-sm">
                    <option value="0"><?php _e('all_properties', 'All properties'); ?></option>
                    <?php foreach ($properties as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>" <?php echo $fProperty === (int)$p['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold"><?php _e('status'); ?></label>
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all_statuses', 'All'); ?></option>
                    <option value="pending" <?php echo $fStatus === 'pending' ? 'selected' : ''; ?>><?php _e('util_pending', 'Pending'); ?></option>
                    <option value="paid" <?php echo $fStatus === 'paid' ? 'selected' : ''; ?>><?php _e('util_paid', 'Paid'); ?></option>
                    <option value="waived" <?php echo $fStatus === 'waived' ? 'selected' : ''; ?>><?php _e('util_waived', 'Waived'); ?></option>
                </select>
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i><?php _e('filter', 'Filter'); ?></button>
                <?php if (hasPermission('utilities', 'create')): ?>
                <button type="button" class="btn btn-sm btn-success flex-grow-1" onclick="generateCharges()">
                    <i class="bi bi-magic me-1"></i><?php _e('util_generate', 'Generate'); ?>
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Charges -->
<div class="card">
    <div class="card-body p-0">
        <?php if (!$charges): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-receipt" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0"><?php _e('util_no_charges', 'No charges for this period.'); ?></p>
            <?php if (hasPermission('utilities', 'create') && $activeTypes): ?>
            <small><?php _e('util_no_charges_hint', 'Record readings, then press Generate.'); ?></small>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th><?php _e('tenant'); ?></th>
                        <th><?php _e('unit', 'Unit'); ?></th>
                        <th><?php _e('util_type', 'Utility'); ?></th>
                        <th class="text-end"><?php _e('util_consumption', 'Consumption'); ?></th>
                        <th class="text-end"><?php _e('amount'); ?></th>
                        <th><?php _e('status'); ?></th>
                        <th class="text-end"><?php _e('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($charges as $c):
                        $badge = ['pending' => 'bg-warning text-dark', 'paid' => 'bg-success', 'waived' => 'bg-secondary'][$c['status']] ?? 'bg-secondary';
                    ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($c['tenant_name']); ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($c['tenant_phone'] ?? ''); ?></small>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($c['unit_number']); ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($c['property_name']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($c['utility_name']); ?></td>
                        <td class="text-end">
                            <?php echo number_format((float)$c['consumption'], 2); ?>
                            <small class="text-muted"><?php echo htmlspecialchars($c['unit_of_measure'] ?? ''); ?></small>
                        </td>
                        <td class="text-end fw-semibold"><?php echo formatCurrency($c['amount'], $c['currency']); ?></td>
                        <td><span class="badge <?php echo $badge; ?>"><?php echo __('util_' . $c['status'], ucfirst($c['status'])); ?></span></td>
                        <td class="text-end">
                            <?php if (hasPermission('utilities', 'edit')): ?>
                            <div class="btn-group btn-group-sm">
                                <?php if ($c['status'] !== 'paid'): ?>
                                <button class="btn btn-outline-success" title="<?php _e('util_mark_paid', 'Mark paid'); ?>" onclick="setStatus(<?php echo (int)$c['id']; ?>,'paid')"><i class="bi bi-check-lg"></i></button>
                                <?php endif; ?>
                                <?php if ($c['status'] !== 'waived'): ?>
                                <button class="btn btn-outline-secondary" title="<?php _e('util_waive', 'Waive'); ?>" onclick="setStatus(<?php echo (int)$c['id']; ?>,'waived')"><i class="bi bi-slash-circle"></i></button>
                                <?php endif; ?>
                                <?php if ($c['status'] !== 'pending'): ?>
                                <button class="btn btn-outline-warning" title="<?php _e('util_mark_pending', 'Mark pending'); ?>" onclick="setStatus(<?php echo (int)$c['id']; ?>,'pending')"><i class="bi bi-arrow-counterclockwise"></i></button>
                                <?php endif; ?>
                            </div>
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

<script>
const API = BASE_URL + 'api/utilities.php';
const CSRF = '<?php echo generateCSRF(); ?>';

function setStatus(id, status) {
    const fd = new FormData();
    fd.append('action', 'set_charge_status');
    fd.append('csrf_token', CSRF);
    fd.append('id', id);
    fd.append('status', status);
    fetch(API, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.message); })
        .catch(() => alert('Request failed'));
}

function generateCharges() {
    const form = document.querySelector('form[method="GET"]');
    const month = form.month.value, year = form.year.value;
    if (!confirm('<?php echo __("util_generate_confirm", "Generate utility charges for the selected period? Existing charges are left untouched."); ?>')) return;
    const fd = new FormData();
    fd.append('action', 'generate_charges');
    fd.append('csrf_token', CSRF);
    fd.append('period_month', month);
    fd.append('period_year', year);
    fetch(API, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { alert(d.message); if (d.success) location.reload(); })
        .catch(() => alert('Request failed'));
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
