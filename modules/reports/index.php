<?php
/**
 * FIS Real Estate — Reports Hub (Part 13)
 * Central page linking to all report types.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('reports', 'Reports');

$subStatus = enforceSubscription();

if (!hasPermission('reports', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Quick stats for the hub cards
try {
    $totalProperties = (int) $conn->query("SELECT COUNT(*) FROM properties WHERE status = 'active'" . propertyScopeSql('id'))->fetchColumn();
    $totalUnits = (int) $conn->query("SELECT COUNT(*) FROM units WHERE 1=1" . propertyScopeSql('property_id'))->fetchColumn();
    $occupiedUnits = (int) $conn->query("SELECT COUNT(*) FROM units WHERE status = 'occupied'" . propertyScopeSql('property_id'))->fetchColumn();
    $occupancyRate = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 1) : 0;

    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $revStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'recorded' AND payment_date BETWEEN ? AND ?" . unitScopeSql('unit_id'));
    $revStmt->execute([$monthStart, $monthEnd]);
    $revenueThisMonth = (float) $revStmt->fetchColumn();

    $openMaint = (int) $conn->query("SELECT COUNT(*) FROM maintenance_requests WHERE status NOT IN ('completed','closed','cancelled')" . unitScopeSql('unit_id'))->fetchColumn();
} catch (PDOException $e) {
    $totalProperties = $totalUnits = $occupiedUnits = $openMaint = 0;
    $occupancyRate = 0;
    $revenueThisMonth = 0;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-bar-chart-line me-2"></i><?php _e('reports', 'Reports'); ?></h4>
        <p class="text-muted mb-0"><?php _e('rpt_hub_desc', 'View financial, occupancy, maintenance, and custom reports'); ?></p>
    </div>
</div>

<?php pageIntro('reports.index'); ?>

<!-- Quick Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-cash-stack"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($revenueThisMonth); ?></div>
                <div class="stat-card-label"><?php _e('dash_revenue_month', 'Revenue (This Month)'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-percent"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $occupancyRate; ?>%</div>
                <div class="stat-card-label"><?php _e('dash_occupancy', 'Occupancy Rate'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#f3e8ff;color:#6b21a8;"><i class="bi bi-buildings"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $totalProperties; ?></div>
                <div class="stat-card-label"><?php _e('properties'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-tools"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $openMaint; ?></div>
                <div class="stat-card-label"><?php _e('dash_open_maintenance', 'Open Maintenance'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Report Sections -->
<div class="row g-3 mb-4">
    <!-- Financial Reports -->
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center p-4">
                <div style="width:56px;height:56px;background:#e8f5ee;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                    <i class="bi bi-cash-stack" style="font-size:1.5rem;color:#1a6b4a;"></i>
                </div>
                <h5 class="fw-bold mb-2"><?php _e('rpt_financial', 'Financial Reports'); ?></h5>
                <p class="text-muted small mb-3"><?php _e('rpt_financial_desc', 'Income summary, rent roll, payment aging analysis, and CSV exports.'); ?></p>
                <a href="<?php echo BASE_URL; ?>modules/reports/financial.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-arrow-right me-1"></i> <?php _e('rpt_view_report', 'View Report'); ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Occupancy Reports -->
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center p-4">
                <div style="width:56px;height:56px;background:#dbeafe;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                    <i class="bi bi-pie-chart" style="font-size:1.5rem;color:#1e40af;"></i>
                </div>
                <h5 class="fw-bold mb-2"><?php _e('rpt_occupancy', 'Occupancy Reports'); ?></h5>
                <p class="text-muted small mb-3"><?php _e('rpt_occupancy_desc', 'Vacancy rates, unit status, turnover analysis, and expiring leases forecast.'); ?></p>
                <a href="<?php echo BASE_URL; ?>modules/reports/occupancy.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-arrow-right me-1"></i> <?php _e('rpt_view_report', 'View Report'); ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Maintenance Reports -->
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center p-4">
                <div style="width:56px;height:56px;background:#fef3c7;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                    <i class="bi bi-tools" style="font-size:1.5rem;color:#92400e;"></i>
                </div>
                <h5 class="fw-bold mb-2"><?php _e('rpt_maintenance', 'Maintenance Reports'); ?></h5>
                <p class="text-muted small mb-3"><?php _e('rpt_maintenance_desc', 'Response times, cost analysis, category breakdown, contractor performance.'); ?></p>
                <a href="<?php echo BASE_URL; ?>modules/maintenance/reports.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-arrow-right me-1"></i> <?php _e('rpt_view_report', 'View Report'); ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Custom Report Builder -->
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center p-4">
                <div style="width:56px;height:56px;background:#f3e8ff;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                    <i class="bi bi-sliders" style="font-size:1.5rem;color:#6b21a8;"></i>
                </div>
                <h5 class="fw-bold mb-2"><?php _e('rpt_custom', 'Custom Report Builder'); ?></h5>
                <p class="text-muted small mb-3"><?php _e('rpt_custom_desc', 'Build custom reports with flexible date ranges, property/unit filters, and print-friendly views.'); ?></p>
                <a href="<?php echo BASE_URL; ?>modules/reports/custom.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-arrow-right me-1"></i> <?php _e('rpt_build_report', 'Build Report'); ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Quick CSV Exports -->
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center p-4">
                <div style="width:56px;height:56px;background:#fee2e2;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                    <i class="bi bi-file-earmark-spreadsheet" style="font-size:1.5rem;color:#991b1b;"></i>
                </div>
                <h5 class="fw-bold mb-2"><?php _e('rpt_csv_exports', 'Quick CSV Exports'); ?></h5>
                <p class="text-muted small mb-3"><?php _e('rpt_csv_desc', 'Download rent roll, payments, and occupancy data as CSV files.'); ?></p>
                <div class="d-flex flex-column gap-1">
                    <a href="<?php echo BASE_URL; ?>api/reports.php?action=export_rent_roll" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-download me-1"></i> <?php _e('rpt_rent_roll', 'Rent Roll'); ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>api/reports.php?action=export_payments" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-download me-1"></i> <?php _e('payments'); ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>api/reports.php?action=export_occupancy" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-download me-1"></i> <?php _e('rpt_occupancy', 'Occupancy'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
