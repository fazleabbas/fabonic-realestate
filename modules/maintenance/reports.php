<?php
/**
 * FIS Real Estate — Maintenance Reports & Analytics (12.5 + 13.4 Enhancement)
 * Response time, cost analysis, category breakdown, monthly trend,
 * contractor performance, completion rates, priority response times.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('maint_reports', 'Maintenance Reports');

$subStatus = enforceSubscription();

if (!hasTier('enterprise')) {
    setAlert(__('maint_tier_required', 'Enterprise tier or higher required for maintenance management.'), 'warning');
    redirect('index.php');
}
if (getSetting('maintenance_enabled', '0') !== '1') {
    setAlert(__('maint_disabled', 'Maintenance module is disabled. Enable it in Settings > Modules.'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('maintenance', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Filter params
$filterProperty = (int)($_GET['property_id'] ?? 0);
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

// Build WHERE
$where = [];
$params = [];

if ($filterProperty > 0) {
    $where[] = "u.property_id = :pid";
    $params['pid'] = $filterProperty;
}
if ($dateFrom) {
    $where[] = "mr.created_at >= :date_from";
    $params['date_from'] = date('Y-m-d', strtotime($dateFrom));
}
if ($dateTo) {
    $where[] = "mr.created_at <= :date_to";
    $params['date_to'] = date('Y-m-d', strtotime($dateTo)) . ' 23:59:59';
}

// Property-based access scoping (all aggregate queries join units as alias u).
// Injected as a plain condition so it participates in the existing WHERE/AND logic.
$scopeFrag = propertyScopeSql('u.property_id');
if ($scopeFrag !== '') {
    $where[] = substr($scopeFrag, 5); // strip leading " AND "
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total requests
$totalStmt = $conn->prepare("
    SELECT COUNT(*) FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    {$whereSql}
");
$totalStmt->execute($params);
$totalRequests = (int)$totalStmt->fetchColumn();

// Open requests
$openStmt = $conn->prepare("
    SELECT COUNT(*) FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    {$whereSql} " . ($where ? "AND" : "WHERE") . " mr.status NOT IN ('completed', 'closed', 'cancelled')
");
$openStmt->execute($params);
$openRequests = (int)$openStmt->fetchColumn();

// Completed
$completedStmt = $conn->prepare("
    SELECT COUNT(*) FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    {$whereSql} " . ($where ? "AND" : "WHERE") . " mr.status IN ('completed', 'closed')
");
$completedStmt->execute($params);
$completedRequests = (int)$completedStmt->fetchColumn();

// Avg response time (days from created to first assignment)
$avgRespStmt = $conn->prepare("
    SELECT AVG(DATEDIFF(ma.assigned_date, mr.created_at)) AS avg_days
    FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    INNER JOIN maintenance_assignments ma ON ma.request_id = mr.id
    {$whereSql}
");
$avgRespStmt->execute($params);
$avgResponse = round((float)$avgRespStmt->fetchColumn(), 1);

// Total cost
$costStmt = $conn->prepare("
    SELECT COALESCE(SUM(ma.actual_cost), 0) AS total_cost
    FROM maintenance_assignments ma
    INNER JOIN maintenance_requests mr ON ma.request_id = mr.id
    INNER JOIN units u ON mr.unit_id = u.id
    {$whereSql}
");
$costStmt->execute($params);
$totalCost = (float)$costStmt->fetchColumn();

// By category
$catStmt = $conn->prepare("
    SELECT COALESCE(mc.name, 'Uncategorized') AS name, COUNT(*) AS count
    FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    LEFT JOIN maintenance_categories mc ON mr.category_id = mc.id
    {$whereSql}
    GROUP BY mc.id, mc.name
    ORDER BY count DESC
");
$catStmt->execute($params);
$byCategory = $catStmt->fetchAll();

// By priority
$priStmt = $conn->prepare("
    SELECT mr.priority, COUNT(*) AS count
    FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    {$whereSql}
    GROUP BY mr.priority
    ORDER BY FIELD(mr.priority, 'urgent', 'high', 'medium', 'low')
");
$priStmt->execute($params);
$byPriority = $priStmt->fetchAll();

// By status
$statusStmt = $conn->prepare("
    SELECT mr.status, COUNT(*) AS count
    FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    {$whereSql}
    GROUP BY mr.status
");
$statusStmt->execute($params);
$byStatus = $statusStmt->fetchAll();

// Monthly trend (last 6 months)
$trendStmt = $conn->prepare("
    SELECT DATE_FORMAT(mr.created_at, '%Y-%m') AS month,
           COUNT(*) AS total,
           SUM(CASE WHEN mr.status IN ('completed','closed') THEN 1 ELSE 0 END) AS completed
    FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    WHERE mr.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    " . ($filterProperty > 0 ? "AND u.property_id = :pid2" : "") . propertyScopeSql('u.property_id') . "
    GROUP BY month ORDER BY month
");
$trendParams = [];
if ($filterProperty > 0) $trendParams['pid2'] = $filterProperty;
$trendStmt->execute($trendParams);
$monthlyTrend = $trendStmt->fetchAll();

// Top contractors
$topStmt = $conn->prepare("
    SELECT c.name, c.specialty, c.rating,
           COUNT(ma.id) AS job_count,
           SUM(CASE WHEN ma.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
           COALESCE(SUM(ma.actual_cost), 0) AS total_cost
    FROM maintenance_assignments ma
    INNER JOIN contractors c ON ma.contractor_id = c.id
    INNER JOIN maintenance_requests mr ON ma.request_id = mr.id
    INNER JOIN units u ON mr.unit_id = u.id
    {$whereSql}
    GROUP BY c.id, c.name, c.specialty, c.rating
    ORDER BY job_count DESC
    LIMIT 10
");
$topStmt->execute($params);
$topContractors = $topStmt->fetchAll();

// Cost by category
$costByCatStmt = $conn->prepare("
    SELECT COALESCE(mc.name, 'Uncategorized') AS name,
           COALESCE(SUM(ma.actual_cost), 0) AS total_cost,
           COUNT(DISTINCT mr.id) AS request_count
    FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    LEFT JOIN maintenance_categories mc ON mr.category_id = mc.id
    LEFT JOIN maintenance_assignments ma ON ma.request_id = mr.id
    {$whereSql}
    GROUP BY mc.id, mc.name
    ORDER BY total_cost DESC
");
$costByCatStmt->execute($params);
$costByCategory = $costByCatStmt->fetchAll();

// === Part 13.4 Enhancements ===

// Completion rate
$completionRate = $totalRequests > 0 ? round(($completedRequests / $totalRequests) * 100, 1) : 0;

// Avg completion time (days from created to completed_date)
// This query already has its own leading WHERE, so any filter/scope conditions
// (held in $where, which now includes the property scope) must be AND-joined.
$whereAnd = $where ? ' AND ' . implode(' AND ', $where) : '';
$avgCompStmt = $conn->prepare("
    SELECT AVG(DATEDIFF(mr.completed_date, mr.created_at)) AS avg_days
    FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    WHERE mr.completed_date IS NOT NULL{$whereAnd} AND mr.status IN ('completed', 'closed')
");
$avgCompStmt->execute($params);
$avgCompletionTime = round((float)$avgCompStmt->fetchColumn(), 1);

// Response time by priority
$respByPriStmt = $conn->prepare("
    SELECT mr.priority,
           AVG(DATEDIFF(ma.assigned_date, mr.created_at)) AS avg_response,
           AVG(DATEDIFF(mr.completed_date, mr.created_at)) AS avg_completion,
           COUNT(DISTINCT mr.id) AS count
    FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    LEFT JOIN maintenance_assignments ma ON ma.request_id = mr.id
    {$whereSql}
    GROUP BY mr.priority
    ORDER BY FIELD(mr.priority, 'urgent', 'high', 'medium', 'low')
");
$respByPriStmt->execute($params);
$responseByPriority = $respByPriStmt->fetchAll();

// Cost trend (monthly cost for last 6 months)
$costTrendStmt = $conn->prepare("
    SELECT DATE_FORMAT(ma.assigned_date, '%Y-%m') AS month,
           COALESCE(SUM(ma.actual_cost), 0) AS total_cost,
           COUNT(DISTINCT ma.id) AS assignment_count
    FROM maintenance_assignments ma
    INNER JOIN maintenance_requests mr ON ma.request_id = mr.id
    INNER JOIN units u ON mr.unit_id = u.id
    WHERE ma.assigned_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    " . ($filterProperty > 0 ? "AND u.property_id = :pid3" : "") . propertyScopeSql('u.property_id') . "
    GROUP BY month ORDER BY month
");
$costTrendParams = [];
if ($filterProperty > 0) $costTrendParams['pid3'] = $filterProperty;
$costTrendStmt->execute($costTrendParams);
$costTrend = $costTrendStmt->fetchAll();

// Avg cost per request
$avgCostPerRequest = $totalRequests > 0 ? round($totalCost / $totalRequests, 2) : 0;

// Contractor performance (completion rate & avg cost)
$contractorPerfStmt = $conn->prepare("
    SELECT c.id, c.name, c.specialty, c.rating,
           COUNT(ma.id) AS total_jobs,
           SUM(CASE WHEN ma.status = 'completed' THEN 1 ELSE 0 END) AS completed_jobs,
           COALESCE(AVG(ma.actual_cost), 0) AS avg_cost,
           COALESCE(SUM(ma.actual_cost), 0) AS total_cost,
           COALESCE(AVG(DATEDIFF(mr.completed_date, ma.assigned_date)), 0) AS avg_days_to_complete
    FROM maintenance_assignments ma
    INNER JOIN contractors c ON ma.contractor_id = c.id
    INNER JOIN maintenance_requests mr ON ma.request_id = mr.id
    INNER JOIN units u ON mr.unit_id = u.id
    {$whereSql}
    GROUP BY c.id, c.name, c.specialty, c.rating
    HAVING total_jobs >= 1
    ORDER BY completed_jobs DESC
    LIMIT 10
");
$contractorPerfStmt->execute($params);
$contractorPerformance = $contractorPerfStmt->fetchAll();

// Top properties by maintenance requests
$topPropStmt = $conn->prepare("
    SELECT p.id, p.name,
           COUNT(mr.id) AS request_count,
           SUM(CASE WHEN mr.status NOT IN ('completed','closed','cancelled') THEN 1 ELSE 0 END) AS open_count,
           COALESCE(SUM(ma2.actual_cost), 0) AS total_cost
    FROM maintenance_requests mr
    INNER JOIN units u ON mr.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    LEFT JOIN maintenance_assignments ma2 ON ma2.request_id = mr.id
    {$whereSql}
    GROUP BY p.id, p.name
    ORDER BY request_count DESC
    LIMIT 10
");
$topPropStmt->execute($params);
$topProperties = $topPropStmt->fetchAll();

// Properties dropdown
$propStmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name");
$properties = $propStmt->fetchAll();

$priorityColors = ['low' => '#0dcaf0', 'medium' => '#0d6efd', 'high' => '#ffc107', 'urgent' => '#dc3545'];
$statusColors = [
    'reported' => '#6c757d', 'acknowledged' => '#0dcaf0', 'assigned' => '#0d6efd',
    'in_progress' => '#ffc107', 'completed' => '#198754', 'closed' => '#212529', 'cancelled' => '#dc3545',
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-bar-chart-line me-2"></i><?php _e('maint_reports', 'Maintenance Reports'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/maintenance/"><?php _e('maintenance'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('reports'); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-1 flex-wrap">
        <a href="<?php echo BASE_URL; ?>api/maintenance.php?action=export_costs<?php echo $filterProperty ? '&property_id=' . $filterProperty : ''; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?>" class="btn btn-sm btn-outline-success">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i><?php _e('maint_export_costs', 'Export Costs CSV'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>api/maintenance.php?action=export_contractors" class="btn btn-sm btn-outline-success">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i><?php _e('maint_export_contractors', 'Export Contractors CSV'); ?>
        </a>
    </div>
</div>

<?php pageIntro('maintenance.reports'); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <select name="property_id" class="form-select form-select-sm">
                    <option value=""><?php _e('all_properties'); ?></option>
                    <?php foreach ($properties as $prop): ?>
                    <option value="<?php echo $prop['id']; ?>" <?php echo $filterProperty == $prop['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prop['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" placeholder="<?php _e('from'); ?>">
            </div>
            <div class="col-6 col-md-2">
                <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" placeholder="<?php _e('to'); ?>">
            </div>
            <div class="col-6 col-md-5 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/maintenance/reports.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards (Row 1) -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-tools"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $totalRequests; ?></div>
                <div class="stat-card-label"><?php _e('maint_total_requests', 'Total Requests'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-exclamation-circle"></i></div>
            <div>
                <div class="stat-card-value text-warning"><?php echo $openRequests; ?></div>
                <div class="stat-card-label"><?php _e('maint_open_requests', 'Open Requests'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-clock"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $avgResponse; ?> <?php _e('maint_days', 'days'); ?></div>
                <div class="stat-card-label"><?php _e('maint_avg_response', 'Avg Response Time'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#f3e8ff;color:#6b21a8;"><i class="bi bi-cash"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($totalCost); ?></div>
                <div class="stat-card-label"><?php _e('maint_total_cost', 'Total Cost'); ?></div>
            </div>
        </div>
    </div>
</div>
<!-- Summary Cards (Row 2 — Part 13.4 Enhancement) -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-check-circle"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $completionRate; ?>%</div>
                <div class="stat-card-label"><?php _e('maint_completion_rate', 'Completion Rate'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $avgCompletionTime; ?> <?php _e('maint_days', 'days'); ?></div>
                <div class="stat-card-label"><?php _e('maint_avg_completion', 'Avg Completion Time'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-calculator"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($avgCostPerRequest); ?></div>
                <div class="stat-card-label"><?php _e('maint_avg_cost', 'Avg Cost / Request'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#198754;"><i class="bi bi-check2-all"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $completedRequests; ?></div>
                <div class="stat-card-label"><?php _e('maint_completed', 'Completed'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
    <!-- Monthly Trend -->
    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-graph-up me-1"></i> <?php _e('maint_monthly_trend', 'Monthly Trend'); ?></div>
            <div class="card-body">
                <canvas id="trendChart" height="250"></canvas>
            </div>
        </div>
    </div>
    <!-- Status Breakdown -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-1"></i> <?php _e('maint_by_status', 'By Status'); ?></div>
            <div class="card-body">
                <canvas id="statusChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- By Category -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-tags me-1"></i> <?php _e('maint_by_category', 'By Category'); ?></div>
            <div class="card-body p-0">
                <?php if (empty($byCategory)): ?>
                <div class="text-center text-muted py-4"><?php _e('no_data'); ?></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th><?php _e('category'); ?></th><th class="text-end"><?php _e('count'); ?></th><th class="text-end"><?php _e('maint_cost', 'Cost'); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ($costByCategory as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td class="text-end"><?php echo (int)$row['request_count']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($row['total_cost']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- By Priority -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-exclamation-triangle me-1"></i> <?php _e('maint_by_priority', 'By Priority'); ?></div>
            <div class="card-body p-0">
                <?php if (empty($byPriority)): ?>
                <div class="text-center text-muted py-4"><?php _e('no_data'); ?></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th><?php _e('priority'); ?></th><th class="text-end"><?php _e('count'); ?></th><th class="text-end">%</th></tr></thead>
                        <tbody>
                            <?php foreach ($byPriority as $row):
                                $pct = $totalRequests > 0 ? round(($row['count'] / $totalRequests) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td>
                                    <span class="badge bg-<?php echo ['low'=>'info','medium'=>'primary','high'=>'warning','urgent'=>'danger'][$row['priority']] ?? 'secondary'; ?>">
                                        <?php echo ucfirst($row['priority']); ?>
                                    </span>
                                </td>
                                <td class="text-end"><?php echo (int)$row['count']; ?></td>
                                <td class="text-end"><?php echo $pct; ?>%</td>
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

<!-- Top Contractors -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-trophy me-1"></i> <?php _e('maint_top_contractors', 'Top Contractors'); ?></div>
    <div class="card-body p-0">
        <?php if (empty($topContractors)): ?>
        <div class="text-center text-muted py-4"><?php _e('no_data'); ?></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th><?php _e('maint_contractor', 'Contractor'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('maint_specialty', 'Specialty'); ?></th>
                        <th class="text-center"><?php _e('maint_jobs', 'Jobs'); ?></th>
                        <th class="text-center"><?php _e('maint_completed', 'Completed'); ?></th>
                        <th class="d-none d-md-table-cell text-end"><?php _e('maint_total_cost', 'Total Cost'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('maint_rating', 'Rating'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topContractors as $tc): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo htmlspecialchars($tc['name']); ?></td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($tc['specialty'] ?? '—'); ?></small></td>
                        <td class="text-center"><?php echo (int)$tc['job_count']; ?></td>
                        <td class="text-center"><?php echo (int)$tc['completed_count']; ?></td>
                        <td class="d-none d-md-table-cell text-end"><?php echo formatCurrency($tc['total_cost']); ?></td>
                        <td class="d-none d-md-table-cell">
                            <?php if ($tc['rating']): ?>
                            <span class="text-warning">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="bi bi-star<?php echo $i <= round($tc['rating']) ? '-fill' : ''; ?>" style="font-size:0.7rem;"></i>
                                <?php endfor; ?>
                            </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Response Time by Priority (13.4) -->
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-speedometer me-1"></i> <?php _e('maint_response_by_priority', 'Response Time by Priority'); ?></div>
            <div class="card-body p-0">
                <?php if (empty($responseByPriority)): ?>
                <div class="text-center text-muted py-4"><?php _e('no_data'); ?></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr>
                            <th><?php _e('priority'); ?></th>
                            <th class="text-end"><?php _e('count'); ?></th>
                            <th class="text-end"><?php _e('maint_avg_response', 'Avg Response'); ?></th>
                            <th class="text-end"><?php _e('maint_avg_completion', 'Avg Completion'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($responseByPriority as $row):
                                $badgeColor = ['urgent'=>'danger','high'=>'warning','medium'=>'primary','low'=>'info'][$row['priority']] ?? 'secondary';
                            ?>
                            <tr>
                                <td><span class="badge bg-<?php echo $badgeColor; ?>"><?php echo ucfirst($row['priority']); ?></span></td>
                                <td class="text-end"><?php echo (int)$row['count']; ?></td>
                                <td class="text-end"><?php echo $row['avg_response'] !== null ? round($row['avg_response'], 1) . ' ' . __('maint_days', 'days') : '—'; ?></td>
                                <td class="text-end"><?php echo $row['avg_completion'] !== null ? round($row['avg_completion'], 1) . ' ' . __('maint_days', 'days') : '—'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Cost Trend Chart (13.4) -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-cash-coin me-1"></i> <?php _e('maint_cost_trend', 'Cost Trend (6 Months)'); ?></div>
            <div class="card-body">
                <canvas id="costTrendChart" height="220"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Contractor Performance (13.4) -->
<?php if (!empty($contractorPerformance)): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-people me-1"></i> <?php _e('maint_contractor_perf', 'Contractor Performance'); ?></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th><?php _e('maint_contractor', 'Contractor'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('maint_specialty', 'Specialty'); ?></th>
                        <th class="text-center"><?php _e('maint_jobs', 'Jobs'); ?></th>
                        <th class="text-center"><?php _e('maint_completion_rate', 'Completion %'); ?></th>
                        <th class="text-end d-none d-md-table-cell"><?php _e('maint_avg_cost', 'Avg Cost'); ?></th>
                        <th class="text-center d-none d-md-table-cell"><?php _e('maint_avg_days', 'Avg Days'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('maint_rating', 'Rating'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contractorPerformance as $cp):
                        $compRate = $cp['total_jobs'] > 0 ? round(($cp['completed_jobs'] / $cp['total_jobs']) * 100) : 0;
                    ?>
                    <tr>
                        <td class="fw-semibold"><?php echo htmlspecialchars($cp['name']); ?></td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($cp['specialty'] ?? '—'); ?></small></td>
                        <td class="text-center"><?php echo (int)$cp['total_jobs']; ?></td>
                        <td class="text-center">
                            <div class="d-flex align-items-center justify-content-center gap-1">
                                <div class="progress" style="height:6px;width:40px;">
                                    <div class="progress-bar bg-<?php echo $compRate >= 80 ? 'success' : ($compRate >= 50 ? 'warning' : 'danger'); ?>" style="width:<?php echo $compRate; ?>%"></div>
                                </div>
                                <small><?php echo $compRate; ?>%</small>
                            </div>
                        </td>
                        <td class="text-end d-none d-md-table-cell"><?php echo formatCurrency($cp['avg_cost']); ?></td>
                        <td class="text-center d-none d-md-table-cell"><?php echo round($cp['avg_days_to_complete'], 1); ?></td>
                        <td class="d-none d-md-table-cell">
                            <?php if ($cp['rating']): ?>
                            <span class="text-warning">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="bi bi-star<?php echo $i <= round($cp['rating']) ? '-fill' : ''; ?>" style="font-size:0.7rem;"></i>
                                <?php endfor; ?>
                            </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Properties with Most Requests (13.4) -->
<?php if (!empty($topProperties)): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-buildings me-1"></i> <?php _e('maint_top_properties', 'Properties with Most Requests'); ?></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th><?php _e('property'); ?></th>
                        <th class="text-end"><?php _e('maint_total_requests', 'Total'); ?></th>
                        <th class="text-end"><?php _e('maint_open_requests', 'Open'); ?></th>
                        <th class="text-end"><?php _e('maint_total_cost', 'Cost'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topProperties as $tp): ?>
                    <tr>
                        <td class="fw-semibold">
                            <a href="<?php echo BASE_URL; ?>modules/properties/view.php?id=<?php echo $tp['id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($tp['name']); ?></a>
                        </td>
                        <td class="text-end"><?php echo (int)$tp['request_count']; ?></td>
                        <td class="text-end"><?php echo (int)$tp['open_count'] > 0 ? '<span class="text-warning">' . (int)$tp['open_count'] . '</span>' : '0'; ?></td>
                        <td class="text-end"><?php echo formatCurrency($tp['total_cost']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Monthly Trend Chart
const trendData = <?php echo json_encode($monthlyTrend); ?>;
if (trendData.length > 0) {
    new Chart(document.getElementById('trendChart'), {
        type: 'bar',
        data: {
            labels: trendData.map(d => d.month),
            datasets: [
                {
                    label: '<?php echo __("maint_total_requests", "Total"); ?>',
                    data: trendData.map(d => d.total),
                    backgroundColor: 'rgba(26, 107, 74, 0.3)',
                    borderColor: '#1a6b4a',
                    borderWidth: 1
                },
                {
                    label: '<?php echo __("maint_completed", "Completed"); ?>',
                    data: trendData.map(d => d.completed),
                    backgroundColor: 'rgba(25, 135, 84, 0.6)',
                    borderColor: '#198754',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            plugins: { legend: { position: 'top' } }
        }
    });
} else {
    document.getElementById('trendChart').parentElement.innerHTML = '<div class="text-center text-muted py-5"><?php echo __("no_data"); ?></div>';
}

// Status Pie Chart
const statusData = <?php echo json_encode($byStatus); ?>;
const statusColorMap = <?php echo json_encode($statusColors); ?>;
if (statusData.length > 0) {
    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: statusData.map(d => d.status.charAt(0).toUpperCase() + d.status.slice(1).replace('_', ' ')),
            datasets: [{
                data: statusData.map(d => d.count),
                backgroundColor: statusData.map(d => statusColorMap[d.status] || '#6c757d'),
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } }
        }
    });
} else {
    document.getElementById('statusChart').parentElement.innerHTML = '<div class="text-center text-muted py-5"><?php echo __("no_data"); ?></div>';
}

// Cost Trend Chart (13.4)
const costTrendData = <?php echo json_encode($costTrend); ?>;
if (costTrendData.length > 0) {
    new Chart(document.getElementById('costTrendChart'), {
        type: 'line',
        data: {
            labels: costTrendData.map(d => d.month),
            datasets: [{
                label: '<?php echo __("maint_total_cost", "Total Cost"); ?>',
                data: costTrendData.map(d => parseFloat(d.total_cost)),
                borderColor: '#6b21a8',
                backgroundColor: 'rgba(107, 33, 168, 0.1)',
                fill: true,
                tension: 0.3,
                borderWidth: 2,
                pointRadius: 4
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } } },
            plugins: { legend: { display: false } }
        }
    });
} else {
    document.getElementById('costTrendChart').parentElement.innerHTML = '<div class="text-center text-muted py-5"><?php echo __("no_data"); ?></div>';
}
</script>

<!-- Fabonic Branding (visible on print) -->
<div class="fabonic-brand d-none d-print-block" style="text-align:center; margin-top:20px; padding-top:10px; border-top:1px solid #e9ecef; font-size:10px; color:#adb5bd;">
    Powered by <span style="color:#1a6b4a; font-weight:600;">Fabonic</span> | realestate.fis.co.tz
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
