<?php
/**
 * FIS Real Estate — Occupancy Reports (Part 13.3)
 * Vacancy rates, unit status, turnover analysis, expiring leases forecast.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('rpt_occupancy', 'Occupancy Reports');

$subStatus = enforceSubscription();

if (!hasPermission('reports', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Filter params
$filterProperty = (int)($_GET['property_id'] ?? 0);
$tab = trim($_GET['tab'] ?? 'overview');

// === OCCUPANCY OVERVIEW ===
$occWhere = "p.status = 'active'" . propertyScopeSql('p.id');
$occParams = [];
if ($filterProperty > 0) {
    $occWhere .= " AND p.id = :pid";
    $occParams['pid'] = $filterProperty;
}

// Per-property occupancy
$propOccStmt = $conn->prepare("
    SELECT p.id, p.name, p.property_type,
           COUNT(u.id) AS total_units,
           SUM(CASE WHEN u.status = 'occupied' THEN 1 ELSE 0 END) AS occupied,
           SUM(CASE WHEN u.status = 'vacant' THEN 1 ELSE 0 END) AS vacant,
           SUM(CASE WHEN u.status = 'maintenance' THEN 1 ELSE 0 END) AS maint,
           SUM(CASE WHEN u.status = 'reserved' THEN 1 ELSE 0 END) AS reserved,
           COALESCE(SUM(u.rent_amount), 0) AS total_rent,
           COALESCE(SUM(CASE WHEN u.status = 'vacant' THEN u.rent_amount ELSE 0 END), 0) AS lost_rent
    FROM properties p
    LEFT JOIN units u ON u.property_id = p.id
    WHERE {$occWhere}
    GROUP BY p.id, p.name, p.property_type
    ORDER BY p.name
");
$propOccStmt->execute($occParams);
$byProperty = $propOccStmt->fetchAll();

// By unit type
$typeStmt = $conn->prepare("
    SELECT u.unit_type, COUNT(*) AS total,
           SUM(CASE WHEN u.status = 'occupied' THEN 1 ELSE 0 END) AS occupied,
           SUM(CASE WHEN u.status = 'vacant' THEN 1 ELSE 0 END) AS vacant,
           SUM(CASE WHEN u.status = 'maintenance' THEN 1 ELSE 0 END) AS maint,
           SUM(CASE WHEN u.status = 'reserved' THEN 1 ELSE 0 END) AS reserved
    FROM units u
    INNER JOIN properties p ON u.property_id = p.id
    WHERE {$occWhere}
    GROUP BY u.unit_type ORDER BY total DESC
");
$typeStmt->execute($occParams);
$byType = $typeStmt->fetchAll();

// Overall totals
$totalUnits = $totalOccupied = $totalVacant = $totalMaint = $totalReserved = 0;
$totalRent = $totalLostRent = 0;
foreach ($byProperty as $bp) {
    $totalUnits += (int)$bp['total_units'];
    $totalOccupied += (int)$bp['occupied'];
    $totalVacant += (int)$bp['vacant'];
    $totalMaint += (int)$bp['maint'];
    $totalReserved += (int)$bp['reserved'];
    $totalRent += (float)$bp['total_rent'];
    $totalLostRent += (float)$bp['lost_rent'];
}
$occupancyRate = $totalUnits > 0 ? round(($totalOccupied / $totalUnits) * 100, 1) : 0;
$vacancyRate = $totalUnits > 0 ? round(($totalVacant / $totalUnits) * 100, 1) : 0;

// === TURNOVER ANALYSIS (last 12 months) ===
$turnoverStmt = $conn->prepare("
    SELECT DATE_FORMAT(l.start_date, '%Y-%m') AS month,
           COUNT(*) AS new_leases
    FROM leases l
    WHERE l.start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . unitScopeSql('l.unit_id') . "
    GROUP BY month
");
$turnoverStmt->execute();
$newLeases = $turnoverStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$endedStmt = $conn->prepare("
    SELECT DATE_FORMAT(COALESCE(l.terminated_date, l.end_date), '%Y-%m') AS month,
           COUNT(*) AS ended_leases
    FROM leases l
    WHERE l.status IN ('expired', 'terminated')
    AND COALESCE(l.terminated_date, l.end_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . unitScopeSql('l.unit_id') . "
    GROUP BY month
");
$endedStmt->execute();
$endedLeases = $endedStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Merge into ordered array
$turnoverData = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-{$i} months"));
    $turnoverData[] = [
        'month' => $month,
        'new' => (int)($newLeases[$month] ?? 0),
        'ended' => (int)($endedLeases[$month] ?? 0),
    ];
}

// === EXPIRING LEASES FORECAST ===
$expiringStmt = $conn->prepare("
    SELECT l.id, l.start_date, l.end_date, l.rent_amount, l.currency,
           DATEDIFF(l.end_date, CURDATE()) AS days_remaining,
           u.unit_number, p.name AS property_name,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
           t.phone AS tenant_phone
    FROM leases l
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON l.tenant_id = t.id
    WHERE l.status = 'active'
    AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    " . ($filterProperty > 0 ? "AND u.property_id = :pid2" : "") . propertyScopeSql('u.property_id') . "
    ORDER BY l.end_date ASC
");
$expParams = $filterProperty > 0 ? ['pid2' => $filterProperty] : [];
$expiringStmt->execute($expParams);
$expiringLeases = $expiringStmt->fetchAll();

// Expiring buckets
$exp30 = $exp60 = $exp90 = 0;
foreach ($expiringLeases as $el) {
    $dr = (int)$el['days_remaining'];
    if ($dr <= 30) $exp30++;
    elseif ($dr <= 60) $exp60++;
    else $exp90++;
}

// Vacant units list
$vacantStmt = $conn->prepare("
    SELECT u.id, u.unit_number, u.unit_type, u.rent_amount, u.currency, u.size_sqm,
           p.name AS property_name,
           (SELECT MAX(l.end_date) FROM leases l WHERE l.unit_id = u.id) AS last_lease_end
    FROM units u
    INNER JOIN properties p ON u.property_id = p.id
    WHERE u.status = 'vacant' AND {$occWhere}
    ORDER BY p.name, u.unit_number
");
$vacantStmt->execute($occParams);
$vacantUnits = $vacantStmt->fetchAll();

// Properties dropdown
$propDdStmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active' ORDER BY name");
$properties = $propDdStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-pie-chart me-2"></i><?php _e('rpt_occupancy', 'Occupancy Reports'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/reports/"><?php _e('reports'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('rpt_occupancy', 'Occupancy'); ?></li>
            </ol>
        </nav>
    </div>
    <a href="<?php echo BASE_URL; ?>api/reports.php?action=export_occupancy" class="btn btn-sm btn-outline-success">
        <i class="bi bi-download me-1"></i> <?php _e('rpt_export_csv', 'Export CSV'); ?>
    </a>
</div>

<?php pageIntro('reports.occupancy'); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <div class="col-6 col-md-4">
                <select name="property_id" class="form-select form-select-sm">
                    <option value=""><?php _e('all_properties'); ?></option>
                    <?php foreach ($properties as $prop): ?>
                    <option value="<?php echo $prop['id']; ?>" <?php echo $filterProperty == $prop['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prop['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-8 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/reports/occupancy.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-percent"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $occupancyRate; ?>%</div>
                <div class="stat-card-label"><?php _e('rpt_occupancy_rate', 'Occupancy Rate'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fee2e2;color:#991b1b;"><i class="bi bi-door-closed"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $vacancyRate; ?>%</div>
                <div class="stat-card-label"><?php _e('rpt_vacancy_rate', 'Vacancy Rate'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-door-open"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $totalVacant; ?> / <?php echo $totalUnits; ?></div>
                <div class="stat-card-label"><?php _e('rpt_vacant_total', 'Vacant / Total Units'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#f3e8ff;color:#6b21a8;"><i class="bi bi-cash"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($totalLostRent); ?></div>
                <div class="stat-card-label"><?php _e('rpt_lost_rent', 'Potential Lost Rent'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'overview' ? 'active' : ''; ?>" href="?tab=overview<?php echo $filterProperty ? "&property_id={$filterProperty}" : ''; ?>">
            <i class="bi bi-grid me-1"></i> <?php _e('rpt_overview', 'Overview'); ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'turnover' ? 'active' : ''; ?>" href="?tab=turnover<?php echo $filterProperty ? "&property_id={$filterProperty}" : ''; ?>">
            <i class="bi bi-arrow-left-right me-1"></i> <?php _e('rpt_turnover', 'Turnover'); ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'expiring' ? 'active' : ''; ?>" href="?tab=expiring<?php echo $filterProperty ? "&property_id={$filterProperty}" : ''; ?>">
            <i class="bi bi-clock-history me-1"></i> <?php _e('rpt_expiring', 'Expiring Leases'); ?>
            <?php if (count($expiringLeases) > 0): ?><span class="badge bg-warning text-dark ms-1"><?php echo count($expiringLeases); ?></span><?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'vacant' ? 'active' : ''; ?>" href="?tab=vacant<?php echo $filterProperty ? "&property_id={$filterProperty}" : ''; ?>">
            <i class="bi bi-door-closed me-1"></i> <?php _e('rpt_vacant_units', 'Vacant Units'); ?>
            <?php if ($totalVacant > 0): ?><span class="badge bg-danger ms-1"><?php echo $totalVacant; ?></span><?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($tab === 'overview'): ?>
<!-- ============================================================
     OVERVIEW TAB
     ============================================================ -->
<div class="row g-3 mb-4">
    <!-- Occupancy Chart -->
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-1"></i> <?php _e('rpt_unit_breakdown', 'Unit Status Breakdown'); ?></div>
            <div class="card-body">
                <canvas id="occupancyChart" height="280"></canvas>
            </div>
        </div>
    </div>
    <!-- By Unit Type -->
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-diagram-3 me-1"></i> <?php _e('rpt_by_unit_type', 'By Unit Type'); ?></div>
            <div class="card-body p-0">
                <?php if (empty($byType)): ?>
                <div class="text-center text-muted py-4"><?php _e('no_data'); ?></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr>
                            <th><?php _e('type'); ?></th>
                            <th class="text-end"><?php _e('total'); ?></th>
                            <th class="text-end"><?php _e('occupied'); ?></th>
                            <th class="text-end"><?php _e('vacant'); ?></th>
                            <th class="text-end"><?php _e('rpt_occ_rate', 'Occ %'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($byType as $row):
                                $rate = $row['total'] > 0 ? round(($row['occupied'] / $row['total']) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td class="fw-semibold"><?php echo ucfirst($row['unit_type']); ?></td>
                                <td class="text-end"><?php echo (int)$row['total']; ?></td>
                                <td class="text-end"><?php echo (int)$row['occupied']; ?></td>
                                <td class="text-end"><?php echo (int)$row['vacant']; ?></td>
                                <td class="text-end">
                                    <div class="d-flex align-items-center justify-content-end gap-1">
                                        <div class="progress flex-grow-1" style="height:6px;max-width:60px;">
                                            <div class="progress-bar bg-<?php echo $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger'); ?>" style="width:<?php echo $rate; ?>%"></div>
                                        </div>
                                        <small><?php echo $rate; ?>%</small>
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

<!-- Per-Property Occupancy -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-buildings me-1"></i> <?php _e('rpt_by_property', 'Occupancy by Property'); ?></div>
    <div class="card-body p-0">
        <?php if (empty($byProperty)): ?>
        <div class="text-center text-muted py-4"><?php _e('no_data'); ?></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr>
                    <th><?php _e('property'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('type'); ?></th>
                    <th class="text-end"><?php _e('total'); ?></th>
                    <th class="text-end"><?php _e('occupied'); ?></th>
                    <th class="text-end"><?php _e('vacant'); ?></th>
                    <th class="text-end d-none d-md-table-cell"><?php _e('rpt_lost_rent', 'Lost Rent'); ?></th>
                    <th class="text-end"><?php _e('rpt_occ_rate', 'Occ %'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($byProperty as $row):
                        $rate = $row['total_units'] > 0 ? round(($row['occupied'] / $row['total_units']) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td class="fw-semibold">
                            <a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $row['id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($row['name']); ?></a>
                        </td>
                        <td class="d-none d-md-table-cell"><small><?php echo ucfirst($row['property_type']); ?></small></td>
                        <td class="text-end"><?php echo (int)$row['total_units']; ?></td>
                        <td class="text-end"><?php echo (int)$row['occupied']; ?></td>
                        <td class="text-end"><?php echo (int)$row['vacant']; ?></td>
                        <td class="text-end d-none d-md-table-cell"><?php echo formatCurrency($row['lost_rent']); ?></td>
                        <td class="text-end">
                            <div class="d-flex align-items-center justify-content-end gap-1">
                                <div class="progress flex-grow-1" style="height:6px;max-width:60px;">
                                    <div class="progress-bar bg-<?php echo $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger'); ?>" style="width:<?php echo $rate; ?>%"></div>
                                </div>
                                <small class="fw-bold"><?php echo $rate; ?>%</small>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td><?php _e('total'); ?></td>
                        <td class="d-none d-md-table-cell"></td>
                        <td class="text-end"><?php echo $totalUnits; ?></td>
                        <td class="text-end"><?php echo $totalOccupied; ?></td>
                        <td class="text-end"><?php echo $totalVacant; ?></td>
                        <td class="text-end d-none d-md-table-cell"><?php echo formatCurrency($totalLostRent); ?></td>
                        <td class="text-end"><?php echo $occupancyRate; ?>%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const occTotal = <?php echo $totalOccupied + $totalVacant + $totalMaint + $totalReserved; ?>;
if (occTotal > 0) {
    new Chart(document.getElementById('occupancyChart'), {
        type: 'doughnut',
        data: {
            labels: ['<?php echo __("occupied"); ?>', '<?php echo __("vacant"); ?>', '<?php echo __("set_maintenance", "Maintenance"); ?>', '<?php echo __("reserved"); ?>'],
            datasets: [{
                data: [<?php echo $totalOccupied; ?>, <?php echo $totalVacant; ?>, <?php echo $totalMaint; ?>, <?php echo $totalReserved; ?>],
                backgroundColor: ['#198754', '#dc3545', '#ffc107', '#0d6efd'],
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } }
        }
    });
} else {
    document.getElementById('occupancyChart').parentElement.innerHTML = '<div class="text-center text-muted py-5"><?php echo __("no_data"); ?></div>';
}
</script>

<?php elseif ($tab === 'turnover'): ?>
<!-- ============================================================
     TURNOVER TAB
     ============================================================ -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-arrow-left-right me-1"></i> <?php _e('rpt_lease_turnover', 'Lease Turnover (12 Months)'); ?></div>
            <div class="card-body">
                <canvas id="turnoverChart" height="300"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Turnover Table -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-table me-1"></i> <?php _e('rpt_turnover_detail', 'Turnover Detail'); ?></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr>
                    <th><?php _e('month'); ?></th>
                    <th class="text-end text-success"><?php _e('rpt_new_leases', 'New Leases'); ?></th>
                    <th class="text-end text-danger"><?php _e('rpt_ended_leases', 'Ended Leases'); ?></th>
                    <th class="text-end"><?php _e('rpt_net_change', 'Net Change'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($turnoverData as $td):
                        $net = $td['new'] - $td['ended'];
                    ?>
                    <tr>
                        <td class="fw-semibold"><?php echo $td['month']; ?></td>
                        <td class="text-end text-success"><?php echo $td['new'] > 0 ? '+' . $td['new'] : '0'; ?></td>
                        <td class="text-end text-danger"><?php echo $td['ended'] > 0 ? '-' . $td['ended'] : '0'; ?></td>
                        <td class="text-end">
                            <span class="badge bg-<?php echo $net > 0 ? 'success' : ($net < 0 ? 'danger' : 'secondary'); ?>">
                                <?php echo ($net > 0 ? '+' : '') . $net; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const turnoverData = <?php echo json_encode($turnoverData); ?>;
new Chart(document.getElementById('turnoverChart'), {
    type: 'bar',
    data: {
        labels: turnoverData.map(d => d.month),
        datasets: [
            {
                label: '<?php echo __("rpt_new_leases", "New Leases"); ?>',
                data: turnoverData.map(d => d.new),
                backgroundColor: 'rgba(25, 135, 84, 0.5)',
                borderColor: '#198754',
                borderWidth: 1
            },
            {
                label: '<?php echo __("rpt_ended_leases", "Ended Leases"); ?>',
                data: turnoverData.map(d => d.ended),
                backgroundColor: 'rgba(220, 53, 69, 0.5)',
                borderColor: '#dc3545',
                borderWidth: 1
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        plugins: { legend: { position: 'top' } }
    }
});
</script>

<?php elseif ($tab === 'expiring'): ?>
<!-- ============================================================
     EXPIRING LEASES TAB
     ============================================================ -->
<div class="row g-3 mb-3">
    <div class="col-4">
        <div class="stat-card">
            <div><div class="stat-card-value text-danger"><?php echo $exp30; ?></div><div class="stat-card-label"><?php _e('rpt_within_30', 'Within 30 Days'); ?></div></div>
        </div>
    </div>
    <div class="col-4">
        <div class="stat-card">
            <div><div class="stat-card-value text-warning"><?php echo $exp60; ?></div><div class="stat-card-label"><?php _e('rpt_within_60', '31-60 Days'); ?></div></div>
        </div>
    </div>
    <div class="col-4">
        <div class="stat-card">
            <div><div class="stat-card-value text-info"><?php echo $exp90; ?></div><div class="stat-card-label"><?php _e('rpt_within_90', '61-90 Days'); ?></div></div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-clock-history me-1"></i> <?php _e('rpt_expiring_forecast', 'Expiring Leases Forecast (90 Days)'); ?></div>
    <div class="card-body p-0">
        <?php if (empty($expiringLeases)): ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-check-circle" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0"><?php _e('dash_no_expiring', 'No leases expiring soon'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr>
                    <th><?php _e('property'); ?></th>
                    <th><?php _e('unit_number'); ?></th>
                    <th><?php _e('tenant'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('phone'); ?></th>
                    <th class="text-end"><?php _e('rent'); ?></th>
                    <th class="text-center"><?php _e('rpt_expires', 'Expires'); ?></th>
                    <th class="text-center"><?php _e('dash_days_left', 'Days Left'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($expiringLeases as $el):
                        $dr = (int)$el['days_remaining'];
                        $badge = $dr <= 7 ? 'danger' : ($dr <= 30 ? 'warning' : 'info');
                    ?>
                    <tr>
                        <td><small><?php echo htmlspecialchars($el['property_name']); ?></small></td>
                        <td class="fw-semibold">
                            <a href="<?php echo BASE_URL; ?>modules/leases/view.php?id=<?php echo $el['id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($el['unit_number']); ?></a>
                        </td>
                        <td><?php echo htmlspecialchars($el['tenant_name']); ?></td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($el['tenant_phone']); ?></small></td>
                        <td class="text-end"><?php echo formatCurrency($el['rent_amount']); ?></td>
                        <td class="text-center"><small><?php echo formatDate($el['end_date']); ?></small></td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo $badge; ?>"><?php echo $dr; ?> <?php _e('days'); ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'vacant'): ?>
<!-- ============================================================
     VACANT UNITS TAB
     ============================================================ -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-door-closed me-1"></i> <?php _e('rpt_vacant_units', 'Vacant Units'); ?></span>
        <span class="badge bg-danger"><?php echo count($vacantUnits); ?> <?php _e('units'); ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($vacantUnits)): ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-check-circle text-success" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0"><?php _e('rpt_no_vacant', 'No vacant units'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr>
                    <th><?php _e('property'); ?></th>
                    <th><?php _e('unit_number'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('type'); ?></th>
                    <th class="text-end"><?php _e('rent'); ?></th>
                    <th class="d-none d-md-table-cell text-end"><?php _e('rpt_size', 'Size (sqm)'); ?></th>
                    <th class="text-center"><?php _e('rpt_last_lease', 'Last Lease End'); ?></th>
                    <th class="text-center"><?php _e('rpt_vacant_days', 'Days Vacant'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($vacantUnits as $vu):
                        $vacantDays = $vu['last_lease_end'] ? max(0, (int)((time() - strtotime($vu['last_lease_end'])) / 86400)) : null;
                    ?>
                    <tr>
                        <td><small><?php echo htmlspecialchars($vu['property_name']); ?></small></td>
                        <td class="fw-semibold">
                            <a href="<?php echo BASE_URL; ?>modules/units/view.php?id=<?php echo $vu['id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($vu['unit_number']); ?></a>
                        </td>
                        <td class="d-none d-md-table-cell"><?php echo ucfirst($vu['unit_type']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($vu['rent_amount']); ?></td>
                        <td class="d-none d-md-table-cell text-end"><?php echo $vu['size_sqm'] ? number_format($vu['size_sqm'], 1) : '—'; ?></td>
                        <td class="text-center"><small><?php echo $vu['last_lease_end'] ? formatDate($vu['last_lease_end']) : '—'; ?></small></td>
                        <td class="text-center">
                            <?php if ($vacantDays !== null): ?>
                            <span class="badge bg-<?php echo $vacantDays > 60 ? 'danger' : ($vacantDays > 30 ? 'warning' : 'info'); ?>">
                                <?php echo $vacantDays; ?> <?php _e('days'); ?>
                            </span>
                            <?php else: ?>
                            <small class="text-muted"><?php _e('rpt_never_leased', 'Never leased'); ?></small>
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
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
