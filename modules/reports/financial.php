<?php
/**
 * FIS Real Estate — Financial Reports (Part 13.2)
 * Income summary, rent roll, payment aging, CSV exports.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('rpt_financial', 'Financial Reports');

$subStatus = enforceSubscription();

if (!hasPermission('reports', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Filter params
$filterProperty = (int)($_GET['property_id'] ?? 0);
$dateFrom = trim($_GET['date_from'] ?? date('Y-01-01'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));
$tab = trim($_GET['tab'] ?? 'income');

// Build shared WHERE for payments
$payWhere = "p.status = 'recorded' AND p.payment_date BETWEEN :df AND :dt" . propertyScopeSql('u.property_id');
$payParams = ['df' => $dateFrom, 'dt' => $dateTo];
if ($filterProperty > 0) {
    $payWhere .= " AND u.property_id = :pid";
    $payParams['pid'] = $filterProperty;
}

// === INCOME SUMMARY ===
// Monthly breakdown
$monthlyStmt = $conn->prepare("
    SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS month,
           COALESCE(SUM(p.amount), 0) AS total,
           COUNT(*) AS count
    FROM payments p
    INNER JOIN units u ON p.unit_id = u.id
    WHERE {$payWhere}
    GROUP BY month ORDER BY month
");
$monthlyStmt->execute($payParams);
$monthlyBreakdown = $monthlyStmt->fetchAll();

// By payment method
$methodStmt = $conn->prepare("
    SELECT p.payment_method, COUNT(*) AS count, COALESCE(SUM(p.amount), 0) AS total
    FROM payments p
    INNER JOIN units u ON p.unit_id = u.id
    WHERE {$payWhere}
    GROUP BY p.payment_method
");
$methodStmt->execute($payParams);
$byMethod = $methodStmt->fetchAll();

// Grand total
$totalStmt = $conn->prepare("
    SELECT COALESCE(SUM(p.amount), 0) AS grand_total, COUNT(*) AS total_count
    FROM payments p
    INNER JOIN units u ON p.unit_id = u.id
    WHERE {$payWhere}
");
$totalStmt->execute($payParams);
$totals = $totalStmt->fetch();
$grandTotal = (float)$totals['grand_total'];
$totalCount = (int)$totals['total_count'];

// Late fees in period
$lfStmt = $conn->prepare("
    SELECT COALESCE(SUM(lf.amount), 0) AS total_fees, COUNT(*) AS fee_count
    FROM late_fees lf
    WHERE lf.fee_date BETWEEN :df AND :dt
    " . ($filterProperty > 0 ? "AND lf.lease_id IN (SELECT l.id FROM leases l INNER JOIN units u ON l.unit_id = u.id WHERE u.property_id = :pid2)" : "")
    . " AND lf.lease_id IN (SELECT l.id FROM leases l WHERE 1=1" . unitScopeSql('l.unit_id') . ")"
);
$lfParams = ['df' => $dateFrom, 'dt' => $dateTo];
if ($filterProperty > 0) $lfParams['pid2'] = $filterProperty;
$lfStmt->execute($lfParams);
$lateFees = $lfStmt->fetch();
$totalLateFees = (float)$lateFees['total_fees'];

// Expected rent (from active leases)
$expectedStmt = $conn->prepare("
    SELECT COALESCE(SUM(l.rent_amount), 0) FROM leases l
    INNER JOIN units u ON l.unit_id = u.id
    WHERE l.status = 'active'" . ($filterProperty > 0 ? " AND u.property_id = :pid3" : "") . propertyScopeSql('u.property_id')
);
$expParams = $filterProperty > 0 ? ['pid3' => $filterProperty] : [];
$expectedStmt->execute($expParams);
$expectedMonthlyRent = (float)$expectedStmt->fetchColumn();

// === RENT ROLL ===
$rrWhere = "l.status = 'active'" . propertyScopeSql('u.property_id');
$rrParams = [];
if ($filterProperty > 0) {
    $rrWhere .= " AND u.property_id = :pid";
    $rrParams['pid'] = $filterProperty;
}

$rrStmt = $conn->prepare("
    SELECT l.id AS lease_id, l.rent_amount, l.currency, l.payment_frequency,
           l.start_date, l.end_date, l.payment_due_day,
           u.unit_number, u.id AS unit_id,
           p.name AS property_name, p.id AS property_id,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
           t.phone AS tenant_phone,
           (SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay WHERE pay.lease_id = l.id AND pay.status = 'recorded') AS total_paid,
           (SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay WHERE pay.lease_id = l.id AND pay.status = 'recorded' AND pay.payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS paid_this_month
    FROM leases l
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON l.tenant_id = t.id
    WHERE {$rrWhere}
    ORDER BY p.name, u.unit_number
");
$rrStmt->execute($rrParams);
$rentRoll = $rrStmt->fetchAll();

$rrTotalRent = 0;
$rrTotalPaidMonth = 0;
foreach ($rentRoll as $rr) {
    $rrTotalRent += (float)$rr['rent_amount'];
    $rrTotalPaidMonth += (float)$rr['paid_this_month'];
}

// === PAYMENT AGING ===
$agingStmt = $conn->prepare("
    SELECT l.id AS lease_id, l.rent_amount, l.currency, l.payment_due_day,
           u.unit_number, p.name AS property_name,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
           t.phone AS tenant_phone,
           (SELECT MAX(pay.payment_date) FROM payments pay WHERE pay.lease_id = l.id AND pay.status = 'recorded') AS last_payment_date,
           (SELECT COALESCE(SUM(lf.amount), 0) FROM late_fees lf WHERE lf.lease_id = l.id AND lf.status = 'pending') AS pending_fees
    FROM leases l
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON l.tenant_id = t.id
    WHERE l.status = 'active'" . ($filterProperty > 0 ? " AND u.property_id = :pid" : "") . propertyScopeSql('u.property_id') . "
    ORDER BY last_payment_date ASC
");
$agingParams = $filterProperty > 0 ? ['pid' => $filterProperty] : [];
$agingStmt->execute($agingParams);
$agingData = $agingStmt->fetchAll();

// Categorize aging buckets
$agingBuckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
foreach ($agingData as $row) {
    if (!$row['last_payment_date']) {
        $agingBuckets['over_90']++;
    } else {
        $daysSince = (int)((time() - strtotime($row['last_payment_date'])) / 86400);
        if ($daysSince <= 30) $agingBuckets['current']++;
        elseif ($daysSince <= 60) $agingBuckets['1_30']++;
        elseif ($daysSince <= 90) $agingBuckets['31_60']++;
        elseif ($daysSince <= 120) $agingBuckets['61_90']++;
        else $agingBuckets['over_90']++;
    }
}

// Properties dropdown
$propStmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active' ORDER BY name");
$properties = $propStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-cash-stack me-2"></i><?php _e('rpt_financial', 'Financial Reports'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/reports/"><?php _e('reports'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('rpt_financial', 'Financial'); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-1">
        <a href="<?php echo BASE_URL; ?>api/reports.php?action=export_rent_roll<?php echo $filterProperty ? "&property_id={$filterProperty}" : ''; ?>" class="btn btn-sm btn-outline-success">
            <i class="bi bi-download me-1"></i> <?php _e('rpt_export_rent_roll', 'Export Rent Roll'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>api/reports.php?action=export_payments&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?><?php echo $filterProperty ? "&property_id={$filterProperty}" : ''; ?>" class="btn btn-sm btn-outline-success">
            <i class="bi bi-download me-1"></i> <?php _e('rpt_export_payments', 'Export Payments'); ?>
        </a>
    </div>
</div>

<?php pageIntro('reports.financial'); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <div class="col-6 col-md-3">
                <select name="property_id" class="form-select form-select-sm">
                    <option value=""><?php _e('all_properties'); ?></option>
                    <?php foreach ($properties as $prop): ?>
                    <option value="<?php echo $prop['id']; ?>" <?php echo $filterProperty == $prop['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prop['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="col-6 col-md-2">
                <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="col-6 col-md-5 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/reports/financial.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-cash-stack"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($grandTotal); ?></div>
                <div class="stat-card-label"><?php _e('rpt_total_income', 'Total Income'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-receipt"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $totalCount; ?></div>
                <div class="stat-card-label"><?php _e('rpt_total_payments', 'Total Payments'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-calendar-month"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($expectedMonthlyRent); ?></div>
                <div class="stat-card-label"><?php _e('rpt_expected_rent', 'Expected Monthly Rent'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fee2e2;color:#991b1b;"><i class="bi bi-exclamation-triangle"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($totalLateFees); ?></div>
                <div class="stat-card-label"><?php _e('rpt_late_fees', 'Late Fees'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'income' ? 'active' : ''; ?>" href="?tab=income&property_id=<?php echo $filterProperty; ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>">
            <i class="bi bi-graph-up me-1"></i> <?php _e('rpt_income_summary', 'Income Summary'); ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'rentroll' ? 'active' : ''; ?>" href="?tab=rentroll&property_id=<?php echo $filterProperty; ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>">
            <i class="bi bi-list-check me-1"></i> <?php _e('rpt_rent_roll', 'Rent Roll'); ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'aging' ? 'active' : ''; ?>" href="?tab=aging&property_id=<?php echo $filterProperty; ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>">
            <i class="bi bi-clock-history me-1"></i> <?php _e('rpt_payment_aging', 'Payment Aging'); ?>
        </a>
    </li>
</ul>

<?php if ($tab === 'income'): ?>
<!-- ============================================================
     INCOME SUMMARY TAB
     ============================================================ -->
<div class="row g-3 mb-4">
    <!-- Monthly Revenue Chart -->
    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-bar-chart me-1"></i> <?php _e('rpt_monthly_revenue', 'Monthly Revenue'); ?></div>
            <div class="card-body">
                <canvas id="monthlyChart" height="280"></canvas>
            </div>
        </div>
    </div>
    <!-- By Payment Method -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-1"></i> <?php _e('rpt_by_method', 'By Payment Method'); ?></div>
            <div class="card-body">
                <canvas id="methodChart" height="280"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Monthly Breakdown Table -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-table me-1"></i> <?php _e('rpt_monthly_breakdown', 'Monthly Breakdown'); ?></div>
    <div class="card-body p-0">
        <?php if (empty($monthlyBreakdown)): ?>
        <div class="text-center text-muted py-4"><?php _e('no_data'); ?></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr>
                    <th><?php _e('month'); ?></th>
                    <th class="text-end"><?php _e('rpt_payments_count', 'Payments'); ?></th>
                    <th class="text-end"><?php _e('amount'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($monthlyBreakdown as $row): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo htmlspecialchars($row['month']); ?></td>
                        <td class="text-end"><?php echo (int)$row['count']; ?></td>
                        <td class="text-end"><?php echo formatCurrency($row['total']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td><?php _e('total'); ?></td>
                        <td class="text-end"><?php echo $totalCount; ?></td>
                        <td class="text-end"><?php echo formatCurrency($grandTotal); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- By Method Table -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-credit-card me-1"></i> <?php _e('rpt_by_method', 'By Payment Method'); ?></div>
    <div class="card-body p-0">
        <?php if (empty($byMethod)): ?>
        <div class="text-center text-muted py-4"><?php _e('no_data'); ?></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr>
                    <th><?php _e('payment_method'); ?></th>
                    <th class="text-end"><?php _e('count'); ?></th>
                    <th class="text-end"><?php _e('amount'); ?></th>
                    <th class="text-end">%</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($byMethod as $row):
                        $pct = $grandTotal > 0 ? round(($row['total'] / $grandTotal) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td class="fw-semibold"><?php echo ucfirst(str_replace('_', ' ', $row['payment_method'])); ?></td>
                        <td class="text-end"><?php echo (int)$row['count']; ?></td>
                        <td class="text-end"><?php echo formatCurrency($row['total']); ?></td>
                        <td class="text-end"><?php echo $pct; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const monthlyData = <?php echo json_encode($monthlyBreakdown); ?>;
if (monthlyData.length > 0) {
    new Chart(document.getElementById('monthlyChart'), {
        type: 'bar',
        data: {
            labels: monthlyData.map(d => d.month),
            datasets: [{
                label: '<?php echo __("revenue"); ?>',
                data: monthlyData.map(d => parseFloat(d.total)),
                backgroundColor: 'rgba(26, 107, 74, 0.4)',
                borderColor: '#1a6b4a',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } } },
            plugins: { legend: { display: false } }
        }
    });
} else {
    document.getElementById('monthlyChart').parentElement.innerHTML = '<div class="text-center text-muted py-5"><?php echo __("no_data"); ?></div>';
}

const methodData = <?php echo json_encode($byMethod); ?>;
const methodColors = ['#1a6b4a', '#e8a838', '#1e40af', '#dc3545', '#6b21a8', '#0dcaf0'];
if (methodData.length > 0) {
    new Chart(document.getElementById('methodChart'), {
        type: 'doughnut',
        data: {
            labels: methodData.map(d => d.payment_method.charAt(0).toUpperCase() + d.payment_method.slice(1).replace('_', ' ')),
            datasets: [{
                data: methodData.map(d => parseFloat(d.total)),
                backgroundColor: methodColors.slice(0, methodData.length),
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } }
        }
    });
} else {
    document.getElementById('methodChart').parentElement.innerHTML = '<div class="text-center text-muted py-5"><?php echo __("no_data"); ?></div>';
}
</script>

<?php elseif ($tab === 'rentroll'): ?>
<!-- ============================================================
     RENT ROLL TAB
     ============================================================ -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-house-door"></i></div>
            <div>
                <div class="stat-card-value"><?php echo count($rentRoll); ?></div>
                <div class="stat-card-label"><?php _e('rpt_active_leases', 'Active Leases'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-cash"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($rrTotalRent); ?></div>
                <div class="stat-card-label"><?php _e('rpt_total_monthly_rent', 'Total Monthly Rent'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-check-circle"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($rrTotalPaidMonth); ?></div>
                <div class="stat-card-label"><?php _e('rpt_collected_month', 'Collected This Month'); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-check me-1"></i> <?php _e('rpt_rent_roll', 'Rent Roll'); ?></span>
        <a href="<?php echo BASE_URL; ?>api/reports.php?action=export_rent_roll<?php echo $filterProperty ? "&property_id={$filterProperty}" : ''; ?>" class="btn btn-sm btn-outline-success">
            <i class="bi bi-download me-1"></i> CSV
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($rentRoll)): ?>
        <div class="text-center text-muted py-4"><?php _e('no_data'); ?></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr>
                    <th><?php _e('property'); ?></th>
                    <th><?php _e('unit_number'); ?></th>
                    <th><?php _e('tenant'); ?></th>
                    <th class="text-end"><?php _e('rent'); ?></th>
                    <th class="text-center d-none d-md-table-cell"><?php _e('rpt_due_day', 'Due Day'); ?></th>
                    <th class="text-end d-none d-md-table-cell"><?php _e('rpt_paid_month', 'Paid (Month)'); ?></th>
                    <th class="text-center"><?php _e('status'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($rentRoll as $rr):
                        $paidPct = $rr['rent_amount'] > 0 ? round(($rr['paid_this_month'] / $rr['rent_amount']) * 100) : 0;
                        $paidStatus = $paidPct >= 100 ? 'success' : ($paidPct > 0 ? 'warning' : 'danger');
                    ?>
                    <tr>
                        <td><small><?php echo htmlspecialchars($rr['property_name']); ?></small></td>
                        <td class="fw-semibold">
                            <a href="<?php echo BASE_URL; ?>modules/leases/view.php?id=<?php echo $rr['lease_id']; ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars($rr['unit_number']); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($rr['tenant_name']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($rr['rent_amount']); ?></td>
                        <td class="text-center d-none d-md-table-cell"><?php echo (int)$rr['payment_due_day']; ?></td>
                        <td class="text-end d-none d-md-table-cell"><?php echo formatCurrency($rr['paid_this_month']); ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo $paidStatus; ?>">
                                <?php echo $paidPct >= 100 ? __('paid') : ($paidPct > 0 ? __('rpt_partial', 'Partial') : __('rpt_unpaid', 'Unpaid')); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'aging'): ?>
<!-- ============================================================
     PAYMENT AGING TAB
     ============================================================ -->
<!-- Aging Buckets -->
<div class="row g-3 mb-3">
    <div class="col-4 col-md">
        <div class="stat-card">
            <div><div class="stat-card-value text-success"><?php echo $agingBuckets['current']; ?></div><div class="stat-card-label"><?php _e('rpt_current', 'Current'); ?></div></div>
        </div>
    </div>
    <div class="col-4 col-md">
        <div class="stat-card">
            <div><div class="stat-card-value text-info"><?php echo $agingBuckets['1_30']; ?></div><div class="stat-card-label">1-30 <?php _e('days'); ?></div></div>
        </div>
    </div>
    <div class="col-4 col-md">
        <div class="stat-card">
            <div><div class="stat-card-value text-warning"><?php echo $agingBuckets['31_60']; ?></div><div class="stat-card-label">31-60 <?php _e('days'); ?></div></div>
        </div>
    </div>
    <div class="col-4 col-md">
        <div class="stat-card">
            <div><div class="stat-card-value text-danger"><?php echo $agingBuckets['61_90']; ?></div><div class="stat-card-label">61-90 <?php _e('days'); ?></div></div>
        </div>
    </div>
    <div class="col-4 col-md">
        <div class="stat-card">
            <div><div class="stat-card-value text-danger"><?php echo $agingBuckets['over_90']; ?></div><div class="stat-card-label">90+ <?php _e('days'); ?></div></div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-clock-history me-1"></i> <?php _e('rpt_payment_aging', 'Payment Aging'); ?></div>
    <div class="card-body p-0">
        <?php if (empty($agingData)): ?>
        <div class="text-center text-muted py-4"><?php _e('no_data'); ?></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr>
                    <th><?php _e('property'); ?></th>
                    <th><?php _e('unit_number'); ?></th>
                    <th><?php _e('tenant'); ?></th>
                    <th class="text-end"><?php _e('rent'); ?></th>
                    <th class="text-center"><?php _e('rpt_last_payment', 'Last Payment'); ?></th>
                    <th class="text-center"><?php _e('rpt_days_since', 'Days Since'); ?></th>
                    <th class="text-end"><?php _e('rpt_pending_fees', 'Pending Fees'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($agingData as $row):
                        $daysSince = $row['last_payment_date'] ? (int)((time() - strtotime($row['last_payment_date'])) / 86400) : null;
                        if ($daysSince === null) $agingColor = 'danger';
                        elseif ($daysSince <= 30) $agingColor = 'success';
                        elseif ($daysSince <= 60) $agingColor = 'warning';
                        else $agingColor = 'danger';
                    ?>
                    <tr>
                        <td><small><?php echo htmlspecialchars($row['property_name']); ?></small></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($row['unit_number']); ?></td>
                        <td><?php echo htmlspecialchars($row['tenant_name']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($row['rent_amount']); ?></td>
                        <td class="text-center"><?php echo $row['last_payment_date'] ? formatDate($row['last_payment_date']) : '<span class="text-danger">' . __('rpt_never', 'Never') . '</span>'; ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo $agingColor; ?>">
                                <?php echo $daysSince !== null ? $daysSince . ' ' . __('days') : __('rpt_never', 'Never'); ?>
                            </span>
                        </td>
                        <td class="text-end"><?php echo $row['pending_fees'] > 0 ? formatCurrency($row['pending_fees']) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
