<?php
/**
 * FIS Real Estate — Main Dashboard (Part 13.1)
 * Occupancy overview, revenue summary, recent activity, expiring leases, charts.
 */
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/subscription.php';
require_once 'includes/charges.php';

$subStatus = enforceSubscription();

$pageTitle = __('dashboard');

// ============================================================
// Gather dashboard stats
// ============================================================
$stats = [
    'total_properties' => 0, 'total_units' => 0,
    'occupied_units' => 0, 'vacant_units' => 0,
    'maintenance_units' => 0, 'reserved_units' => 0,
    'active_tenants' => 0, 'active_leases' => 0,
];

try {
    $stats['total_properties'] = (int) $conn->query("SELECT COUNT(*) FROM properties WHERE status = 'active'" . propertyScopeSql('id'))->fetchColumn();
    $stats['total_units'] = (int) $conn->query("SELECT COUNT(*) FROM units WHERE 1=1" . propertyScopeSql('property_id'))->fetchColumn();
    $stats['occupied_units'] = (int) $conn->query("SELECT COUNT(*) FROM units WHERE status = 'occupied'" . propertyScopeSql('property_id'))->fetchColumn();
    $stats['vacant_units'] = (int) $conn->query("SELECT COUNT(*) FROM units WHERE status = 'vacant'" . propertyScopeSql('property_id'))->fetchColumn();
    $stats['maintenance_units'] = (int) $conn->query("SELECT COUNT(*) FROM units WHERE status = 'maintenance'" . propertyScopeSql('property_id'))->fetchColumn();
    $stats['reserved_units'] = (int) $conn->query("SELECT COUNT(*) FROM units WHERE status = 'reserved'" . propertyScopeSql('property_id'))->fetchColumn();
    $stats['active_tenants'] = (int) $conn->query("SELECT COUNT(*) FROM tenants WHERE status = 'active'" . tenantScopeSql('id'))->fetchColumn();
    $stats['active_leases'] = (int) $conn->query("SELECT COUNT(*) FROM leases WHERE status = 'active'" . unitScopeSql('unit_id'))->fetchColumn();
} catch (PDOException $e) { /* Tables may not exist yet */ }

$occupancyRate = $stats['total_units'] > 0 ? round(($stats['occupied_units'] / $stats['total_units']) * 100) : 0;

// Revenue this month
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$revenueThisMonth = 0;
$revenueLastMonth = 0;
$overdueAmount = 0;
$pendingLateFees = 0;
$expiringLeases = 0;
$openMaintenance = 0;

try {
    $revStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'recorded' AND payment_date BETWEEN ? AND ?" . unitScopeSql('unit_id'));
    $revStmt->execute([$monthStart, $monthEnd]);
    $revenueThisMonth = (float) $revStmt->fetchColumn();

    $lastMonthStart = date('Y-m-01', strtotime('-1 month'));
    $lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
    $revLastStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'recorded' AND payment_date BETWEEN ? AND ?" . unitScopeSql('unit_id'));
    $revLastStmt->execute([$lastMonthStart, $lastMonthEnd]);
    $revenueLastMonth = (float) $revLastStmt->fetchColumn();

    // Expected rent this month
    // Part 31: what tenants actually still owe — charges past due and unsettled.
    // Previously this was expected-rent minus revenue-this-month, which mixed a
    // tenant figure with a company one and moved every time a payment was banked.
    $overdueAmount = (float) $conn->query("
        SELECT COALESCE(SUM(c.amount - c.amount_settled), 0)
        FROM charges c
        WHERE c.status IN ('open','part') AND c.due_date <= CURDATE()" . unitScopeSql('c.unit_id'))->fetchColumn();

    $pendingLateFees = (float) $conn->query("SELECT COALESCE(SUM(amount), 0) FROM late_fees WHERE status = 'pending' AND lease_id IN (SELECT id FROM leases WHERE 1=1" . unitScopeSql('unit_id') . ")")->fetchColumn();

    $expiringLeases = (int) $conn->query("SELECT COUNT(*) FROM leases WHERE status = 'active' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)" . unitScopeSql('unit_id'))->fetchColumn();

    $openMaintenance = (int) $conn->query("SELECT COUNT(*) FROM maintenance_requests WHERE status NOT IN ('completed','closed','cancelled')" . unitScopeSql('unit_id'))->fetchColumn();
} catch (PDOException $e) { /* ok */ }

// Revenue trend (6 months)
$revenueTrend = [];
try {
    $trendStmt = $conn->query("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, COALESCE(SUM(amount), 0) AS total
        FROM payments WHERE status = 'recorded' AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)" . unitScopeSql('unit_id') . "
        GROUP BY month ORDER BY month
    ");
    $revenueTrend = $trendStmt->fetchAll();
} catch (PDOException $e) { /* ok */ }

// Expiring leases (next 30 days)
$expiringList = [];
try {
    $expStmt = $conn->query("
        SELECT l.id, l.end_date, l.rent_amount, l.currency,
               DATEDIFF(l.end_date, CURDATE()) AS days_remaining,
               u.unit_number, p.name AS property_name,
               CONCAT(t.first_name, ' ', t.last_name) AS tenant_name
        FROM leases l
        INNER JOIN units u ON l.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        INNER JOIN tenants t ON l.tenant_id = t.id
        WHERE l.status = 'active' AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)" . propertyScopeSql('u.property_id') . "
        ORDER BY l.end_date ASC LIMIT 10
    ");
    $expiringList = $expStmt->fetchAll();
} catch (PDOException $e) { /* ok */ }

// Recent activity (last 15)
$recentActivity = [];
try {
    $actStmt = $conn->query("
        SELECT al.action, al.entity_type, al.entity_id, al.created_at, al.details,
               u.full_name AS user_name
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC LIMIT 15
    ");
    $recentActivity = $actStmt->fetchAll();
} catch (PDOException $e) { /* ok */ }

// Revenue change indicator
$revChange = 0;
if ($revenueLastMonth > 0) {
    $revChange = round((($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100, 1);
}

// ------------------------------------------------------------
// Drill-down targets for the stat cards.
// Each filter reproduces EXACTLY the figure on its card, so the list a user
// lands on adds up to the number they clicked. A card whose module the user
// cannot view renders unlinked rather than as a dead link.
// ------------------------------------------------------------
// BASE_URL is written with a trailing slash on the server, but not in every
// local config — normalise so these links are right either way.
$appUrl = rtrim(BASE_URL, '/') . '/';

$cardLinks = [
    'revenue'     => hasPermission('payments', 'view')
        ? $appUrl . 'modules/payments/?status=recorded&date_from=' . $monthStart . '&date_to=' . $monthEnd : null,
    'overdue'     => hasPermission('reports', 'view')
        ? $appUrl . 'modules/reports/financial.php' : null,
    'occupancy'   => hasPermission('units', 'view')
        ? $appUrl . 'modules/units/?status=occupied' : null,
    'vacant'      => hasPermission('units', 'view')
        ? $appUrl . 'modules/units/?status=vacant' : null,
    'properties'  => hasPermission('properties', 'view')
        ? $appUrl . 'modules/properties/?status=active' : null,
    'tenants'     => hasPermission('tenants', 'view')
        ? $appUrl . 'modules/tenants/?status=active' : null,
    'leases'      => hasPermission('leases', 'view')
        ? $appUrl . 'modules/leases/?status=active' : null,
    'maintenance' => (hasTier('enterprise') && getSetting('maintenance_enabled', '0') === '1' && hasPermission('maintenance', 'view'))
        ? $appUrl . 'modules/maintenance/?status=open' : null,
];

/** Open a stat card, wrapping it in a link when the user can reach the target. */
function statCardOpen($key, $title = '') {
    global $cardLinks;
    $url = $cardLinks[$key] ?? null;
    if ($url === null) return '';
    return '<a href="' . htmlspecialchars($url) . '" class="stat-card-link"'
        . ($title !== '' ? ' title="' . htmlspecialchars($title) . '"' : '') . '>';
}

/** Close whatever statCardOpen() opened. */
function statCardClose($key) {
    global $cardLinks;
    return ($cardLinks[$key] ?? null) === null ? '' : '</a>';
}

require_once 'includes/header.php';
?>

<?php if ($subStatus && !empty($subStatus['warning'])): ?>
<div class="alert alert-<?php echo $subStatus['is_trial'] ? 'info' : 'warning'; ?> alert-dismissible fade show mb-3" role="alert">
    <i class="bi bi-<?php echo $subStatus['is_trial'] ? 'clock-history' : 'exclamation-triangle'; ?> me-2"></i>
    <?php echo $subStatus['message']; ?>
    <?php if (getUserData('role_name') === 'Admin'): ?>
    <a href="<?php echo BASE_URL; ?>modules/subscription/<?php echo $subStatus['is_trial'] ? 'subscribe' : 'status'; ?>.php" class="alert-link ms-2">
        <?php echo $subStatus['is_trial'] ? __('subscribe_now', 'Subscribe Now') : __('renew_now', 'Renew Now'); ?> &rarr;
    </a>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-grid-1x2 me-2"></i><?php _e('dashboard'); ?></h4>
        <p class="text-muted mb-0"><?php _e('welcome_back'); ?>, <?php echo htmlspecialchars($userName); ?>!</p>
    </div>
    <?php if (hasPermission('reports', 'view')): ?>
    <a href="<?php echo BASE_URL; ?>modules/reports/" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-bar-chart-line me-1"></i> <?php _e('dash_view_reports', 'View Reports'); ?>
    </a>
    <?php endif; ?>
</div>

<!-- Key Financial Metrics -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <?php echo statCardOpen('revenue', __('dash_link_revenue', 'View payments recorded this month')); ?>
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-cash-stack"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($revenueThisMonth); ?></div>
                <div class="stat-card-label"><?php _e('dash_revenue_month', 'Revenue (This Month)'); ?></div>
                <?php if ($revChange != 0): ?>
                <small class="<?php echo $revChange > 0 ? 'text-success' : 'text-danger'; ?>">
                    <i class="bi bi-arrow-<?php echo $revChange > 0 ? 'up' : 'down'; ?>"></i> <?php echo abs($revChange); ?>%
                </small>
                <?php endif; ?>
            </div>
        </div>
        <?php echo statCardClose('revenue'); ?>
    </div>
    <div class="col-6 col-lg-3">
        <?php echo statCardOpen('overdue', __('dash_link_overdue', 'Open the financial report')); ?>
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fee2e2;color:#991b1b;"><i class="bi bi-exclamation-triangle"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($overdueAmount); ?></div>
                <div class="stat-card-label"><?php _e('dash_overdue', 'Overdue Amount'); ?></div>
            </div>
        </div>
        <?php echo statCardClose('overdue'); ?>
    </div>
    <div class="col-6 col-lg-3">
        <?php echo statCardOpen('occupancy', __('dash_link_occupancy', 'View occupied units')); ?>
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-percent"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $occupancyRate; ?>%</div>
                <div class="stat-card-label"><?php _e('dash_occupancy', 'Occupancy Rate'); ?></div>
            </div>
        </div>
        <?php echo statCardClose('occupancy'); ?>
    </div>
    <div class="col-6 col-lg-3">
        <?php echo statCardOpen('vacant', __('dash_link_vacant', 'View vacant units')); ?>
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-door-closed"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $stats['vacant_units']; ?></div>
                <div class="stat-card-label"><?php _e('dash_vacant', 'Vacant Units'); ?></div>
            </div>
        </div>
        <?php echo statCardClose('vacant'); ?>
    </div>
</div>

<!-- Secondary Metrics -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <?php echo statCardOpen('properties', __('dash_link_properties', 'View active properties')); ?>
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-buildings"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $stats['total_properties']; ?></div>
                <div class="stat-card-label"><?php _e('properties'); ?></div>
            </div>
        </div>
        <?php echo statCardClose('properties'); ?>
    </div>
    <div class="col-6 col-md-3">
        <?php echo statCardOpen('tenants', __('dash_link_tenants', 'View active tenants')); ?>
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#f3e8ff;color:#6b21a8;"><i class="bi bi-people"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $stats['active_tenants']; ?></div>
                <div class="stat-card-label"><?php _e('dash_active_tenants', 'Active Tenants'); ?></div>
            </div>
        </div>
        <?php echo statCardClose('tenants'); ?>
    </div>
    <div class="col-6 col-md-3">
        <?php echo statCardOpen('leases', __('dash_link_leases', 'View active leases')); ?>
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-file-earmark-text"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $stats['active_leases']; ?></div>
                <div class="stat-card-label"><?php _e('dash_active_leases', 'Active Leases'); ?></div>
            </div>
        </div>
        <?php echo statCardClose('leases'); ?>
    </div>
    <div class="col-6 col-md-3">
        <?php echo statCardOpen('maintenance', __('dash_link_maintenance', 'View open maintenance requests')); ?>
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-tools"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $openMaintenance; ?></div>
                <div class="stat-card-label"><?php _e('dash_open_maintenance', 'Open Maintenance'); ?></div>
            </div>
        </div>
        <?php echo statCardClose('maintenance'); ?>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
    <!-- Revenue Trend -->
    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-graph-up me-1"></i> <?php _e('dash_revenue_trend', 'Revenue Trend (6 Months)'); ?></div>
            <div class="card-body">
                <canvas id="revenueChart" height="250"></canvas>
            </div>
        </div>
    </div>
    <!-- Occupancy Breakdown -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-1"></i> <?php _e('dash_unit_status', 'Unit Status'); ?></div>
            <div class="card-body">
                <canvas id="occupancyChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Expiring Leases -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-1"></i> <?php _e('dash_expiring_leases', 'Expiring Leases (30 days)'); ?></span>
                <?php if ($expiringLeases > 0): ?>
                <span class="badge bg-warning text-dark"><?php echo $expiringLeases; ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($expiringList)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-check-circle" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('dash_no_expiring', 'No leases expiring soon'); ?></p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr>
                            <th><?php _e('tenant'); ?></th>
                            <th><?php _e('unit_number'); ?></th>
                            <th class="d-none d-md-table-cell"><?php _e('property'); ?></th>
                            <th class="text-end"><?php _e('dash_days_left', 'Days Left'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($expiringList as $exp): ?>
                            <tr>
                                <td><a href="<?php echo BASE_URL; ?>modules/leases/view.php?id=<?php echo $exp['id']; ?>" class="text-decoration-none fw-semibold"><?php echo htmlspecialchars($exp['tenant_name']); ?></a></td>
                                <td><?php echo htmlspecialchars($exp['unit_number']); ?></td>
                                <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($exp['property_name']); ?></small></td>
                                <td class="text-end">
                                    <span class="badge bg-<?php echo $exp['days_remaining'] <= 7 ? 'danger' : ($exp['days_remaining'] <= 14 ? 'warning' : 'info'); ?>">
                                        <?php echo $exp['days_remaining']; ?> <?php _e('days'); ?>
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
    </div>

    <!-- Recent Activity -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-activity me-1"></i> <?php _e('dash_recent_activity', 'Recent Activity'); ?></div>
            <div class="card-body" style="max-height:350px;overflow-y:auto;">
                <?php if (empty($recentActivity)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-clock" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('no_history'); ?></p>
                </div>
                <?php else: ?>
                <div class="timeline-simple">
                    <?php foreach ($recentActivity as $act): ?>
                    <div class="d-flex gap-2 mb-2 pb-2 border-bottom">
                        <div><i class="bi bi-circle-fill text-primary" style="font-size:0.4rem;margin-top:8px;"></i></div>
                        <div class="flex-grow-1">
                            <small>
                                <strong><?php echo htmlspecialchars($act['user_name'] ?? 'System'); ?></strong>
                                <?php echo ucfirst($act['action']); ?>
                                <?php echo str_replace('_', ' ', $act['entity_type'] ?? ''); ?>
                                <?php if ($act['entity_id']): ?>#<?php echo $act['entity_id']; ?><?php endif; ?>
                            </small>
                            <br><small class="text-muted"><?php echo formatDateTime($act['created_at']); ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-lightning me-1"></i> <?php _e('dash_quick_actions', 'Quick Actions'); ?></div>
            <div class="card-body">
                <div class="row g-2">
                    <?php if (hasPermission('properties', 'create')): ?>
                    <div class="col-6 col-md-3">
                        <a href="<?php echo BASE_URL; ?>modules/properties/create.php" class="btn btn-outline-primary w-100">
                            <i class="bi bi-plus-circle me-1"></i> <?php _e('add_property'); ?>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if (hasPermission('tenants', 'create')): ?>
                    <div class="col-6 col-md-3">
                        <a href="<?php echo BASE_URL; ?>modules/tenants/create.php" class="btn btn-outline-primary w-100">
                            <i class="bi bi-person-plus me-1"></i> <?php _e('add_tenant'); ?>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if (hasPermission('payments', 'create')): ?>
                    <div class="col-6 col-md-3">
                        <a href="<?php echo BASE_URL; ?>modules/payments/create.php" class="btn btn-outline-primary w-100">
                            <i class="bi bi-cash-coin me-1"></i> <?php _e('record_payment'); ?>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if (hasPermission('reports', 'view')): ?>
                    <div class="col-6 col-md-3">
                        <a href="<?php echo BASE_URL; ?>modules/reports/" class="btn btn-outline-primary w-100">
                            <i class="bi bi-bar-chart me-1"></i> <?php _e('dash_view_reports', 'View Reports'); ?>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Getting Started Guide (shown when no properties yet) -->
<?php if ($stats['total_properties'] === 0): ?>
<div class="row">
    <div class="col-12 col-lg-8 mx-auto">
        <div class="card border-0" style="background:linear-gradient(135deg,#e8f5ee,#dbeafe);border-radius:1rem;">
            <div class="card-body p-4 text-center">
                <div style="width:64px;height:64px;background:white;border-radius:16px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
                    <i class="bi bi-rocket-takeoff" style="font-size:1.8rem;color:#1a6b4a;"></i>
                </div>
                <h4 class="fw-bold mb-2"><?php _e('dash_getting_started', 'Getting Started'); ?></h4>
                <p class="text-muted mb-3"><?php _e('dash_getting_started_desc', 'Welcome to FIS Real Estate! Set up your first property to get started.'); ?></p>
                <div class="d-flex flex-column gap-2" style="max-width:350px;margin:0 auto;text-align:left;">
                    <div class="d-flex align-items-center gap-2"><span class="badge rounded-pill" style="background:#1a6b4a;">1</span><span><?php _e('dash_step_1', 'Add your property details'); ?></span></div>
                    <div class="d-flex align-items-center gap-2"><span class="badge rounded-pill" style="background:#1a6b4a;">2</span><span><?php _e('dash_step_2', 'Create units within the property'); ?></span></div>
                    <div class="d-flex align-items-center gap-2"><span class="badge rounded-pill" style="background:#1a6b4a;">3</span><span><?php _e('dash_step_3', 'Add tenants and create leases'); ?></span></div>
                    <div class="d-flex align-items-center gap-2"><span class="badge rounded-pill" style="background:#1a6b4a;">4</span><span><?php _e('dash_step_4', 'Start recording rent payments'); ?></span></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Revenue Trend Chart
const revenueData = <?php echo json_encode($revenueTrend); ?>;
if (revenueData.length > 0) {
    new Chart(document.getElementById('revenueChart'), {
        type: 'bar',
        data: {
            labels: revenueData.map(d => d.month),
            datasets: [{
                label: '<?php echo __("revenue"); ?>',
                data: revenueData.map(d => parseFloat(d.total)),
                backgroundColor: 'rgba(26, 107, 74, 0.4)',
                borderColor: '#1a6b4a',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } } },
            plugins: { legend: { display: false } }
        }
    });
} else {
    document.getElementById('revenueChart').parentElement.innerHTML = '<div class="text-center text-muted py-5"><?php echo __("no_data", "No data yet"); ?></div>';
}

// Occupancy Doughnut
const occData = {
    occupied: <?php echo $stats['occupied_units']; ?>,
    vacant: <?php echo $stats['vacant_units']; ?>,
    maintenance: <?php echo $stats['maintenance_units']; ?>,
    reserved: <?php echo $stats['reserved_units']; ?>
};
if (occData.occupied + occData.vacant + occData.maintenance + occData.reserved > 0) {
    new Chart(document.getElementById('occupancyChart'), {
        type: 'doughnut',
        data: {
            labels: ['<?php echo __("occupied"); ?>', '<?php echo __("vacant"); ?>', '<?php echo __("set_maintenance", "Maintenance"); ?>', '<?php echo __("reserved"); ?>'],
            datasets: [{
                data: [occData.occupied, occData.vacant, occData.maintenance, occData.reserved],
                backgroundColor: ['#198754', '#dc3545', '#ffc107', '#0d6efd'],
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } }
        }
    });
} else {
    document.getElementById('occupancyChart').parentElement.innerHTML = '<div class="text-center text-muted py-5"><?php echo __("no_data", "No data yet"); ?></div>';
}
</script>

<?php require_once 'includes/footer.php'; ?>
