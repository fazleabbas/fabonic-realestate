<?php
/**
 * FIS Real Estate — Financial Reports (Part 20.2 + 20.3)
 * Income vs Expense reports, Profit/Loss per property.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('exp_financial_reports', 'Financial Reports');

$subStatus = enforceSubscription();

if (!hasTier('enterprise')) {
    setAlert(__('exp_tier_required', 'Enterprise tier or higher required for financial reporting.'), 'warning');
    redirect('index.php');
}
if (getSetting('financial_reports_enabled', '0') !== '1') {
    setAlert(__('exp_disabled', 'Financial reports module is disabled. Enable it in Settings > Modules.'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('reports', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$year = (int)($_GET['year'] ?? date('Y'));
$filterProperty = (int)($_GET['property_id'] ?? 0);
$tab = trim($_GET['tab'] ?? 'income_expense');

// Properties dropdown
$properties = $conn->query("SELECT id, name FROM properties WHERE status = 'active' ORDER BY name")->fetchAll();

// ===== Income vs Expense Data =====
$propFilter = $filterProperty ? "AND u.property_id = :pid" : "";
$propFilterExp = $filterProperty ? "AND e.property_id = :pid" : "";
$params = $filterProperty ? ['pid' => $filterProperty] : [];

// Monthly rent income
$rentStmt = $conn->prepare("
    SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS month, COALESCE(SUM(p.amount), 0) AS total
    FROM payments p INNER JOIN units u ON p.unit_id = u.id
    WHERE p.status = 'recorded' AND YEAR(p.payment_date) = :year $propFilter" . propertyScopeSql('u.property_id') . "
    GROUP BY month ORDER BY month
");
$rentStmt->execute(array_merge($params, ['year' => $year]));
$rentByMonth = [];
foreach ($rentStmt->fetchAll() as $r) $rentByMonth[$r['month']] = (float)$r['total'];

// Monthly utility income
$utilStmt = $conn->prepare("
    SELECT CONCAT(:year2, '-', LPAD(uc.period_month, 2, '0')) AS month, COALESCE(SUM(uc.amount), 0) AS total
    FROM utility_charges uc INNER JOIN units u ON uc.unit_id = u.id
    WHERE uc.status = 'paid' AND uc.period_year = :year $propFilter" . propertyScopeSql('u.property_id') . "
    GROUP BY month ORDER BY month
");
$utilStmt->execute(array_merge($params, ['year' => $year, 'year2' => $year]));
$utilByMonth = [];
foreach ($utilStmt->fetchAll() as $r) $utilByMonth[$r['month']] = (float)$r['total'];

// Monthly expenses
$expStmt = $conn->prepare("
    SELECT DATE_FORMAT(e.expense_date, '%Y-%m') AS month, COALESCE(SUM(e.amount), 0) AS total
    FROM expenses e WHERE e.status = 'active' AND YEAR(e.expense_date) = :year $propFilterExp" . propertyScopeSql('e.property_id') . "
    GROUP BY month ORDER BY month
");
$expStmt->execute(array_merge($params, ['year' => $year]));
$expByMonth = [];
foreach ($expStmt->fetchAll() as $r) $expByMonth[$r['month']] = (float)$r['total'];

// Build monthly data
$monthly = [];
$totalRent = $totalUtil = $totalExp = 0;
for ($m = 1; $m <= 12; $m++) {
    $key = $year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
    $rent = $rentByMonth[$key] ?? 0;
    $util = $utilByMonth[$key] ?? 0;
    $exp = $expByMonth[$key] ?? 0;
    $monthly[] = ['month' => $key, 'rent' => $rent, 'utility' => $util, 'income' => $rent + $util, 'expense' => $exp, 'net' => ($rent + $util) - $exp];
    $totalRent += $rent;
    $totalUtil += $util;
    $totalExp += $exp;
}
$totalIncome = $totalRent + $totalUtil;
$totalNet = $totalIncome - $totalExp;

// ===== Expense by category =====
$catStmt = $conn->prepare("
    SELECT COALESCE(ec.name, 'Uncategorized') AS name, COALESCE(SUM(e.amount), 0) AS total, COUNT(*) AS count
    FROM expenses e LEFT JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.status = 'active' AND YEAR(e.expense_date) = :year $propFilterExp" . propertyScopeSql('e.property_id') . "
    GROUP BY ec.id, ec.name ORDER BY total DESC
");
$catStmt->execute(array_merge($params, ['year' => $year]));
$expByCategory = $catStmt->fetchAll();

// ===== Profit/Loss per property =====
$plData = [];
$allProps = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name")->fetchAll();
foreach ($allProps as $prop) {
    $pid = $prop['id'];

    $s = $conn->prepare("SELECT COALESCE(SUM(p.amount), 0) FROM payments p INNER JOIN units u ON p.unit_id = u.id WHERE p.status = 'recorded' AND YEAR(p.payment_date) = ? AND u.property_id = ?");
    $s->execute([$year, $pid]);
    $pRent = (float)$s->fetchColumn();

    $s2 = $conn->prepare("SELECT COALESCE(SUM(uc.amount), 0) FROM utility_charges uc INNER JOIN units u ON uc.unit_id = u.id WHERE uc.status = 'paid' AND uc.period_year = ? AND u.property_id = ?");
    $s2->execute([$year, $pid]);
    $pUtil = (float)$s2->fetchColumn();

    $s3 = $conn->prepare("SELECT COALESCE(SUM(e.amount), 0) FROM expenses e WHERE e.status = 'active' AND YEAR(e.expense_date) = ? AND e.property_id = ?");
    $s3->execute([$year, $pid]);
    $pExp = (float)$s3->fetchColumn();

    $s4 = $conn->prepare("SELECT COUNT(*) FROM units WHERE property_id = ?");
    $s4->execute([$pid]);
    $pUnits = (int)$s4->fetchColumn();

    $s5 = $conn->prepare("SELECT COUNT(*) FROM units WHERE property_id = ? AND status = 'occupied'");
    $s5->execute([$pid]);
    $pOcc = (int)$s5->fetchColumn();

    $pIncome = $pRent + $pUtil;
    $pNet = $pIncome - $pExp;
    $plData[] = [
        'name' => $prop['name'], 'rent' => $pRent, 'utility' => $pUtil,
        'income' => $pIncome, 'expense' => $pExp, 'net' => $pNet,
        'margin' => $pIncome > 0 ? round(($pNet / $pIncome) * 100, 1) : 0,
        'units' => $pUnits, 'occupied' => $pOcc,
        'occupancy' => $pUnits > 0 ? round(($pOcc / $pUnits) * 100, 1) : 0
    ];
}
usort($plData, function($a, $b) { return $b['net'] <=> $a['net']; });

$monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-bar-chart-line me-2"></i><?php _e('exp_financial_reports', 'Financial Reports'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/expenses/"><?php _e('exp_expenses', 'Expenses'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('reports'); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-1 flex-wrap">
        <a href="<?php echo BASE_URL; ?>api/expenses.php?action=export_income&year=<?php echo $year; ?><?php echo $filterProperty ? '&property_id=' . $filterProperty : ''; ?>" class="btn btn-sm btn-outline-success">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i><?php _e('exp_export_income_csv', 'Export Income/Expense CSV'); ?>
        </a>
    </div>
</div>

<?php pageIntro('expenses.reports'); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <div class="col-6 col-md-3">
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <select name="property_id" class="form-select form-select-sm">
                    <option value=""><?php _e('all_properties'); ?></option>
                    <?php foreach ($properties as $prop): ?>
                    <option value="<?php echo $prop['id']; ?>" <?php echo $filterProperty == $prop['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prop['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/expenses/reports.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-arrow-down-circle"></i></div>
            <div>
                <div class="stat-card-value text-success"><?php echo formatCurrency($totalIncome); ?></div>
                <div class="stat-card-label"><?php _e('exp_total_income', 'Total Income'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-arrow-up-circle"></i></div>
            <div>
                <div class="stat-card-value text-danger"><?php echo formatCurrency($totalExp); ?></div>
                <div class="stat-card-label"><?php _e('exp_total_expenses', 'Total Expenses'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:<?php echo $totalNet >= 0 ? '#e8f5ee' : '#fee2e2'; ?>;color:<?php echo $totalNet >= 0 ? '#1a6b4a' : '#dc2626'; ?>;"><i class="bi bi-graph-up-arrow"></i></div>
            <div>
                <div class="stat-card-value <?php echo $totalNet >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo formatCurrency($totalNet); ?></div>
                <div class="stat-card-label"><?php _e('exp_net_income', 'Net Income'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-percent"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $totalIncome > 0 ? round(($totalNet / $totalIncome) * 100, 1) : 0; ?>%</div>
                <div class="stat-card-label"><?php _e('exp_profit_margin', 'Profit Margin'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'income_expense' ? 'active' : ''; ?>" href="?tab=income_expense&year=<?php echo $year; ?>&property_id=<?php echo $filterProperty; ?>">
            <i class="bi bi-bar-chart me-1"></i><?php _e('exp_income_vs_expense', 'Income vs Expense'); ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'profit_loss' ? 'active' : ''; ?>" href="?tab=profit_loss&year=<?php echo $year; ?>&property_id=<?php echo $filterProperty; ?>">
            <i class="bi bi-calculator me-1"></i><?php _e('exp_profit_loss', 'Profit / Loss'); ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'expense_breakdown' ? 'active' : ''; ?>" href="?tab=expense_breakdown&year=<?php echo $year; ?>&property_id=<?php echo $filterProperty; ?>">
            <i class="bi bi-pie-chart me-1"></i><?php _e('exp_breakdown', 'Expense Breakdown'); ?>
        </a>
    </li>
</ul>

<?php if ($tab === 'income_expense'): ?>
<!-- Income vs Expense Tab -->
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-graph-up me-1"></i> <?php _e('exp_monthly_trend', 'Monthly Income & Expense Trend'); ?> — <?php echo $year; ?></div>
            <div class="card-body">
                <canvas id="incomeExpenseChart" height="280"></canvas>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-1"></i> <?php _e('exp_income_split', 'Income Split'); ?></div>
            <div class="card-body">
                <canvas id="incomeSplitChart" height="280"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-table me-1"></i> <?php _e('exp_monthly_detail', 'Monthly Detail'); ?> — <?php echo $year; ?></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th><?php _e('month'); ?></th>
                        <th class="text-end"><?php _e('exp_rent_income', 'Rent Income'); ?></th>
                        <th class="text-end d-none d-md-table-cell"><?php _e('exp_utility_income', 'Utility Income'); ?></th>
                        <th class="text-end"><?php _e('exp_total_income', 'Total Income'); ?></th>
                        <th class="text-end"><?php _e('exp_expenses_col', 'Expenses'); ?></th>
                        <th class="text-end"><?php _e('exp_net_income', 'Net Income'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthly as $i => $m): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo $monthNames[$i]; ?></td>
                        <td class="text-end"><?php echo formatCurrency($m['rent']); ?></td>
                        <td class="text-end d-none d-md-table-cell"><?php echo formatCurrency($m['utility']); ?></td>
                        <td class="text-end text-success"><?php echo formatCurrency($m['income']); ?></td>
                        <td class="text-end text-danger"><?php echo formatCurrency($m['expense']); ?></td>
                        <td class="text-end fw-semibold <?php echo $m['net'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo formatCurrency($m['net']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light fw-bold">
                        <td><?php _e('total'); ?></td>
                        <td class="text-end"><?php echo formatCurrency($totalRent); ?></td>
                        <td class="text-end d-none d-md-table-cell"><?php echo formatCurrency($totalUtil); ?></td>
                        <td class="text-end text-success"><?php echo formatCurrency($totalIncome); ?></td>
                        <td class="text-end text-danger"><?php echo formatCurrency($totalExp); ?></td>
                        <td class="text-end <?php echo $totalNet >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo formatCurrency($totalNet); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php elseif ($tab === 'profit_loss'): ?>
<!-- Profit/Loss Tab -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-calculator me-1"></i> <?php _e('exp_pl_by_property', 'Profit / Loss by Property'); ?> — <?php echo $year; ?></div>
    <div class="card-body p-0">
        <?php if (empty($plData)): ?>
        <div class="text-center text-muted py-5"><?php _e('no_data'); ?></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th><?php _e('property'); ?></th>
                        <th class="text-end"><?php _e('exp_rent_income', 'Rent'); ?></th>
                        <th class="text-end d-none d-md-table-cell"><?php _e('exp_utility_income', 'Utilities'); ?></th>
                        <th class="text-end"><?php _e('exp_total_income', 'Income'); ?></th>
                        <th class="text-end"><?php _e('exp_expenses_col', 'Expenses'); ?></th>
                        <th class="text-end"><?php _e('exp_net_income', 'Net'); ?></th>
                        <th class="text-center d-none d-md-table-cell"><?php _e('exp_margin', 'Margin'); ?></th>
                        <th class="text-center d-none d-md-table-cell"><?php _e('exp_occupancy', 'Occupancy'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plData as $pl): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo htmlspecialchars($pl['name']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($pl['rent']); ?></td>
                        <td class="text-end d-none d-md-table-cell"><?php echo formatCurrency($pl['utility']); ?></td>
                        <td class="text-end text-success"><?php echo formatCurrency($pl['income']); ?></td>
                        <td class="text-end text-danger"><?php echo formatCurrency($pl['expense']); ?></td>
                        <td class="text-end fw-semibold <?php echo $pl['net'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo formatCurrency($pl['net']); ?></td>
                        <td class="text-center d-none d-md-table-cell">
                            <span class="badge bg-<?php echo $pl['margin'] >= 50 ? 'success' : ($pl['margin'] >= 0 ? 'warning' : 'danger'); ?>"><?php echo $pl['margin']; ?>%</span>
                        </td>
                        <td class="text-center d-none d-md-table-cell">
                            <div class="d-flex align-items-center justify-content-center gap-1">
                                <div class="progress" style="height:6px;width:40px;">
                                    <div class="progress-bar bg-<?php echo $pl['occupancy'] >= 80 ? 'success' : ($pl['occupancy'] >= 50 ? 'warning' : 'danger'); ?>" style="width:<?php echo $pl['occupancy']; ?>%"></div>
                                </div>
                                <small><?php echo $pl['occupancy']; ?>%</small>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light fw-bold">
                        <td><?php _e('total'); ?></td>
                        <td class="text-end"><?php echo formatCurrency(array_sum(array_column($plData, 'rent'))); ?></td>
                        <td class="text-end d-none d-md-table-cell"><?php echo formatCurrency(array_sum(array_column($plData, 'utility'))); ?></td>
                        <td class="text-end text-success"><?php echo formatCurrency(array_sum(array_column($plData, 'income'))); ?></td>
                        <td class="text-end text-danger"><?php echo formatCurrency(array_sum(array_column($plData, 'expense'))); ?></td>
                        <td class="text-end <?php echo array_sum(array_column($plData, 'net')) >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo formatCurrency(array_sum(array_column($plData, 'net'))); ?></td>
                        <td class="d-none d-md-table-cell"></td>
                        <td class="d-none d-md-table-cell"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- P/L Bar Chart -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-bar-chart me-1"></i> <?php _e('exp_pl_chart', 'Profit / Loss Comparison'); ?></div>
    <div class="card-body">
        <canvas id="plChart" height="300"></canvas>
    </div>
</div>

<?php else: ?>
<!-- Expense Breakdown Tab -->
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-1"></i> <?php _e('exp_by_category', 'Expenses by Category'); ?></div>
            <div class="card-body">
                <canvas id="catPieChart" height="300"></canvas>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-list-ul me-1"></i> <?php _e('exp_category_detail', 'Category Detail'); ?></div>
            <div class="card-body p-0">
                <?php if (empty($expByCategory)): ?>
                <div class="text-center text-muted py-5"><?php _e('no_data'); ?></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th><?php _e('category'); ?></th><th class="text-end"><?php _e('count'); ?></th><th class="text-end"><?php _e('amount'); ?></th><th class="text-end">%</th></tr></thead>
                        <tbody>
                            <?php foreach ($expByCategory as $cat):
                                $pct = $totalExp > 0 ? round(($cat['total'] / $totalExp) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($cat['name']); ?></td>
                                <td class="text-end"><?php echo (int)$cat['count']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($cat['total']); ?></td>
                                <td class="text-end">
                                    <div class="d-flex align-items-center justify-content-end gap-1">
                                        <div class="progress" style="height:6px;width:50px;">
                                            <div class="progress-bar bg-primary" style="width:<?php echo $pct; ?>%"></div>
                                        </div>
                                        <small><?php echo $pct; ?>%</small>
                                    </div>
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
</div>
<?php endif; ?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
<?php if ($tab === 'income_expense'): ?>
// Income vs Expense Chart
const monthlyData = <?php echo json_encode($monthly); ?>;
const monthNames = <?php echo json_encode($monthNames); ?>;

new Chart(document.getElementById('incomeExpenseChart'), {
    type: 'bar',
    data: {
        labels: monthNames,
        datasets: [
            { label: '<?php echo __("exp_rent_income", "Rent"); ?>', data: monthlyData.map(d => d.rent), backgroundColor: 'rgba(26, 107, 74, 0.7)', borderColor: '#1a6b4a', borderWidth: 1 },
            { label: '<?php echo __("exp_utility_income", "Utilities"); ?>', data: monthlyData.map(d => d.utility), backgroundColor: 'rgba(232, 168, 56, 0.7)', borderColor: '#e8a838', borderWidth: 1 },
            { label: '<?php echo __("exp_expenses_col", "Expenses"); ?>', data: monthlyData.map(d => d.expense), backgroundColor: 'rgba(220, 53, 69, 0.5)', borderColor: '#dc3545', borderWidth: 1 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        scales: { x: { stacked: false }, y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } } },
        plugins: { legend: { position: 'top' } }
    }
});

// Income Split
if (<?php echo $totalRent + $totalUtil; ?> > 0) {
    new Chart(document.getElementById('incomeSplitChart'), {
        type: 'doughnut',
        data: {
            labels: ['<?php echo __("exp_rent_income", "Rent"); ?>', '<?php echo __("exp_utility_income", "Utilities"); ?>'],
            datasets: [{ data: [<?php echo $totalRent; ?>, <?php echo $totalUtil; ?>], backgroundColor: ['#1a6b4a', '#e8a838'] }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
} else {
    document.getElementById('incomeSplitChart').parentElement.innerHTML = '<div class="text-center text-muted py-5"><?php echo __("no_data"); ?></div>';
}

<?php elseif ($tab === 'profit_loss'): ?>
// P/L Chart
const plData = <?php echo json_encode($plData); ?>;
if (plData.length > 0) {
    new Chart(document.getElementById('plChart'), {
        type: 'bar',
        data: {
            labels: plData.map(d => d.name),
            datasets: [
                { label: '<?php echo __("exp_total_income", "Income"); ?>', data: plData.map(d => d.income), backgroundColor: 'rgba(26, 107, 74, 0.7)' },
                { label: '<?php echo __("exp_expenses_col", "Expenses"); ?>', data: plData.map(d => d.expense), backgroundColor: 'rgba(220, 53, 69, 0.5)' },
                { label: '<?php echo __("exp_net_income", "Net"); ?>', data: plData.map(d => d.net), type: 'line', borderColor: '#0d6efd', borderWidth: 2, pointRadius: 4, fill: false }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } } },
            plugins: { legend: { position: 'top' } }
        }
    });
} else {
    document.getElementById('plChart').parentElement.innerHTML = '<div class="text-center text-muted py-5"><?php echo __("no_data"); ?></div>';
}

<?php else: ?>
// Category Pie
const catData = <?php echo json_encode($expByCategory); ?>;
const catColors = ['#1a6b4a','#e8a838','#dc3545','#0d6efd','#6f42c1','#20c997','#fd7e14','#6c757d','#0dcaf0','#d63384'];
if (catData.length > 0) {
    new Chart(document.getElementById('catPieChart'), {
        type: 'doughnut',
        data: {
            labels: catData.map(d => d.name),
            datasets: [{ data: catData.map(d => parseFloat(d.total)), backgroundColor: catColors.slice(0, catData.length) }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
    });
} else {
    document.getElementById('catPieChart').parentElement.innerHTML = '<div class="text-center text-muted py-5"><?php echo __("no_data"); ?></div>';
}
<?php endif; ?>
</script>

<!-- Fabonic Branding (visible on print) -->
<div class="fabonic-brand d-none d-print-block" style="text-align:center; margin-top:20px; padding-top:10px; border-top:1px solid #e9ecef; font-size:10px; color:#adb5bd;">
    Powered by <span style="color:#1a6b4a; font-weight:600;">Fabonic</span> | realestate.fis.co.tz
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
