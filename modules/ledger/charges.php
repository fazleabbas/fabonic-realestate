<?php
/**
 * FIS Real Estate — Charges / Arrears (Part 31, Phase 2)
 * What tenants owe, and what has been settled against it.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/charges.php';
$pageTitle = __('chg_charges', 'Charges');

$subStatus = enforceSubscription();

if (!hasPermission('ledger', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$filterStatus = trim($_GET['status'] ?? 'outstanding');
$filterType   = trim($_GET['type'] ?? '');
$search       = trim($_GET['search'] ?? '');
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = RECORDS_PER_PAGE;

$where = ['1=1'];
$params = [];
if ($filterStatus === 'outstanding')      { $where[] = "c.status IN ('open','part')"; }
elseif ($filterStatus === 'overdue')      { $where[] = "c.status IN ('open','part') AND c.due_date <= CURDATE()"; }
elseif ($filterStatus !== '')             { $where[] = 'c.status = :st'; $params['st'] = $filterStatus; }
if ($filterType !== '') { $where[] = 'c.type = :ty'; $params['ty'] = $filterType; }
if ($search !== '') {
    $where[] = "(CONCAT(t.first_name,' ',t.last_name) LIKE :s OR c.description LIKE :s OR u.unit_number LIKE :s)";
    $params['s'] = '%' . $search . '%';
}
$where[] = '1=1' . unitScopeSql('c.unit_id');
$whereSql = implode(' AND ', $where);

$countStmt = $conn->prepare("SELECT COUNT(*) FROM charges c
                             LEFT JOIN tenants t ON t.id = c.tenant_id
                             LEFT JOIN units u ON u.id = c.unit_id
                             WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $conn->prepare("
    SELECT c.*, CONCAT(t.first_name,' ',t.last_name) AS tenant_name, u.unit_number, p.name AS property_name
    FROM charges c
    LEFT JOIN tenants t ON t.id = c.tenant_id
    LEFT JOIN units u ON u.id = c.unit_id
    LEFT JOIN properties p ON p.id = c.property_id
    WHERE {$whereSql}
    ORDER BY c.due_date DESC, c.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$charges = $stmt->fetchAll();

// Totals across the whole filter, not just this page.
$sumStmt = $conn->prepare("SELECT c.currency,
                                  COALESCE(SUM(c.amount),0) AS billed,
                                  COALESCE(SUM(c.amount - c.amount_settled),0) AS owed
                           FROM charges c
                           LEFT JOIN tenants t ON t.id = c.tenant_id
                           LEFT JOIN units u ON u.id = c.unit_id
                           WHERE {$whereSql} GROUP BY c.currency");
$sumStmt->execute($params);
$totals = $sumStmt->fetchAll();

$typeLabels = [
    'rent'     => __('chg_type_rent', 'Rent'),
    'utility'  => __('chg_type_utility', 'Utility'),
    'late_fee' => __('chg_type_late_fee', 'Late fee'),
    'other'    => __('chg_type_other', 'Other'),
];
$statusColors = ['open' => 'danger', 'part' => 'warning text-dark', 'settled' => 'success',
                 'waived' => 'secondary', 'cancelled' => 'secondary'];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-receipt-cutoff me-2"></i><?php _e('chg_charges', 'Charges'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/ledger/"><?php _e('led_ledger', 'Ledger'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('chg_charges', 'Charges'); ?></li>
            </ol>
        </nav>
    </div>
    <?php if (hasPermission('ledger', 'create')): ?>
    <button class="btn btn-outline-primary btn-sm" onclick="runGenerator()">
        <i class="bi bi-arrow-repeat me-1"></i> <?php _e('chg_generate', 'Raise due charges'); ?>
    </button>
    <?php endif; ?>
</div>

<?php pageIntro('ledger.charges'); ?>

<?php ledgerNav('charges'); ?>

<div class="row g-3 mb-3">
    <?php foreach ($totals as $tot): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fee2e2;color:#991b1b;"><i class="bi bi-exclamation-circle"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($tot['owed'], $tot['currency'], 2); ?></div>
                <div class="stat-card-label"><?php _e('chg_outstanding', 'Outstanding'); ?> · <?php echo htmlspecialchars($tot['currency']); ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1"><?php _e('search'); ?></label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php _e('chg_search', 'Tenant, unit or description'); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1"><?php _e('status'); ?></label>
                <select name="status" class="form-select form-select-sm">
                    <option value="outstanding" <?php echo $filterStatus === 'outstanding' ? 'selected' : ''; ?>><?php _e('chg_f_outstanding', 'Outstanding'); ?></option>
                    <option value="overdue"     <?php echo $filterStatus === 'overdue' ? 'selected' : ''; ?>><?php _e('chg_f_overdue', 'Overdue'); ?></option>
                    <option value="settled"     <?php echo $filterStatus === 'settled' ? 'selected' : ''; ?>><?php _e('chg_f_settled', 'Settled'); ?></option>
                    <option value=""            <?php echo $filterStatus === '' ? 'selected' : ''; ?>><?php _e('all'); ?></option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1"><?php _e('led_type', 'Type'); ?></label>
                <select name="type" class="form-select form-select-sm">
                    <option value=""><?php _e('all'); ?></option>
                    <?php foreach ($typeLabels as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo $filterType === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/ledger/charges.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:105px;"><?php _e('chg_due', 'Due'); ?></th>
                    <th><?php _e('tenant'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('chg_period', 'Period'); ?></th>
                    <th style="width:90px;"><?php _e('led_type', 'Type'); ?></th>
                    <th class="text-end" style="width:130px;"><?php _e('amount'); ?></th>
                    <th class="text-end" style="width:130px;"><?php _e('chg_owed', 'Still owed'); ?></th>
                    <th style="width:95px;"><?php _e('status'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$charges): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">
                    <i class="bi bi-check2-circle d-block mb-2" style="font-size:2rem;opacity:.4;"></i>
                    <?php _e('chg_none', 'Nothing outstanding'); ?>
                </td></tr>
                <?php endif; ?>
                <?php foreach ($charges as $c): ?>
                <?php $owed = (float)$c['amount'] - (float)$c['amount_settled']; ?>
                <tr>
                    <td class="small <?php echo ($c['due_date'] <= date('Y-m-d') && $owed > 0) ? 'text-danger fw-semibold' : ''; ?>">
                        <?php echo formatDate($c['due_date']); ?>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($c['tenant_name'] ?: '—'); ?>
                        <?php if ($c['unit_number']): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($c['unit_number'] . ' · ' . ($c['property_name'] ?? '')); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted d-none d-md-table-cell">
                        <?php echo $c['period_from'] ? formatDate($c['period_from']) . ' – ' . formatDate($c['period_to']) : '—'; ?>
                    </td>
                    <td><small><?php echo htmlspecialchars($typeLabels[$c['type']] ?? $c['type']); ?></small></td>
                    <td class="text-end"><?php echo formatCurrency($c['amount'], $c['currency'], 2); ?></td>
                    <td class="text-end fw-semibold"><?php echo $owed > 0 ? formatCurrency($owed, $c['currency'], 2) : '—'; ?></td>
                    <td><span class="badge bg-<?php echo $statusColors[$c['status']] ?? 'secondary'; ?>"><?php echo htmlspecialchars(ucfirst($c['status'])); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted"><?php echo $total; ?> <?php _e('chg_charges_lower', 'charges'); ?></small>
        <nav><ul class="pagination pagination-sm mb-0">
            <?php $qs = $_GET; for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): $qs['page'] = $p; ?>
            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($qs)); ?>"><?php echo $p; ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php if (hasPermission('ledger', 'create')): ?>
<form id="genForm" class="d-none"><?php echo csrfField(); ?></form>
<script>
function runGenerator() {
    const fd = new FormData(document.getElementById('genForm'));
    fd.append('action', 'generate_charges');
    fetch('<?php echo BASE_URL; ?>api/ledger.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { alert(d.message); if (d.success) location.reload(); })
        .catch(() => alert('Could not reach the server'));
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
