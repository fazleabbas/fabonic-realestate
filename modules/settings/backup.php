<?php
/**
 * FIS Real Estate — Backup & Data Export (Part 14.5)
 * Full data export, selective module export, CSV import capability.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('set_backup', 'Backup & Export');

$subStatus = enforceSubscription();

if (getUserData('role_name') !== 'Admin') {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Gather system stats
$tableStats = [];
$exportModules = [
    'properties' => ['label' => __('properties', 'Properties'), 'icon' => 'bi-buildings', 'tables' => ['properties', 'property_photos', 'property_settings'], 'color' => '#1a6b4a'],
    'units' => ['label' => __('units', 'Units'), 'icon' => 'bi-door-open', 'tables' => ['units', 'unit_photos'], 'color' => '#1e40af'],
    'tenants' => ['label' => __('tenants', 'Tenants'), 'icon' => 'bi-people', 'tables' => ['tenants'], 'color' => '#6b21a8'],
    'leases' => ['label' => __('leases', 'Leases'), 'icon' => 'bi-file-earmark-text', 'tables' => ['leases', 'lease_templates'], 'color' => '#0d6efd'],
    'payments' => ['label' => __('payments', 'Payments'), 'icon' => 'bi-cash-coin', 'tables' => ['payments', 'receipts'], 'color' => '#198754'],
    'deposits' => ['label' => __('deposits', 'Deposits'), 'icon' => 'bi-safe', 'tables' => ['deposits', 'deposit_deductions'], 'color' => '#dc3545'],
    'late_fees' => ['label' => __('dep_late_fees', 'Late Fees'), 'icon' => 'bi-exclamation-triangle', 'tables' => ['late_fees'], 'color' => '#ffc107'],
    'inspections' => ['label' => __('inspections', 'Inspections'), 'icon' => 'bi-clipboard-check', 'tables' => ['inspections', 'inspection_items', 'inspection_templates'], 'color' => '#0dcaf0'],
    'maintenance' => ['label' => __('maintenance', 'Maintenance'), 'icon' => 'bi-tools', 'tables' => ['maintenance_requests', 'maintenance_photos', 'maintenance_categories', 'contractors', 'maintenance_assignments'], 'color' => '#fd7e14'],
    'users' => ['label' => __('users', 'Users'), 'icon' => 'bi-person-badge', 'tables' => ['users', 'roles'], 'color' => '#6f42c1'],
    'settings' => ['label' => __('settings', 'Settings'), 'icon' => 'bi-gear', 'tables' => ['settings'], 'color' => '#6c757d'],
    'activity' => ['label' => __('bak_activity_log', 'Activity Log'), 'icon' => 'bi-clock-history', 'tables' => ['activity_log'], 'color' => '#20c997'],
];

$totalRecords = 0;
$totalTables = 0;
foreach ($exportModules as $modKey => &$mod) {
    $mod['records'] = 0;
    foreach ($mod['tables'] as $table) {
        try {
            $count = (int)$conn->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            $tableStats[$table] = $count;
            $mod['records'] += $count;
            $totalRecords += $count;
            $totalTables++;
        } catch (PDOException $e) {
            $tableStats[$table] = 0;
        }
    }
}
unset($mod);

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-download me-2"></i><?php _e('set_backup', 'Backup & Export'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/settings/"><?php _e('settings'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('set_backup', 'Backup & Export'); ?></li>
            </ol>
        </nav>
    </div>
    <a href="<?php echo BASE_URL; ?>api/settings.php?action=export_full&module=all" class="btn btn-primary btn-sm">
        <i class="bi bi-cloud-download me-1"></i> <?php _e('bak_full_export', 'Full Backup (ZIP)'); ?>
    </a>
</div>

<?php pageIntro('settings.backup'); ?>

<!-- Summary Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-database"></i></div>
            <div>
                <div class="stat-card-value"><?php echo number_format($totalRecords); ?></div>
                <div class="stat-card-label"><?php _e('bak_total_records', 'Total Records'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-table"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $totalTables; ?></div>
                <div class="stat-card-label"><?php _e('bak_total_tables', 'Data Tables'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#f3e8ff;color:#6b21a8;"><i class="bi bi-collection"></i></div>
            <div>
                <div class="stat-card-value"><?php echo count($exportModules); ?></div>
                <div class="stat-card-label"><?php _e('bak_modules', 'Export Modules'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-shield-check"></i></div>
            <div>
                <div class="stat-card-value"><?php echo date('d M Y'); ?></div>
                <div class="stat-card-label"><?php _e('bak_date', 'Export Date'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Module Export Cards -->
<h5 class="mb-3"><i class="bi bi-box-arrow-up me-1"></i> <?php _e('bak_selective', 'Selective Module Export'); ?></h5>
<p class="text-muted mb-3"><?php _e('bak_selective_desc', 'Download data for specific modules as a ZIP archive containing CSV files.'); ?></p>

<div class="row g-3 mb-4">
    <?php foreach ($exportModules as $modKey => $mod): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center p-3">
                <div style="width:44px;height:44px;background:<?php echo $mod['color']; ?>15;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:0.75rem;">
                    <i class="bi <?php echo $mod['icon']; ?>" style="font-size:1.2rem;color:<?php echo $mod['color']; ?>;"></i>
                </div>
                <h6 class="fw-bold mb-1" style="font-size:0.9rem;"><?php echo $mod['label']; ?></h6>
                <small class="text-muted d-block mb-2"><?php echo number_format($mod['records']); ?> <?php _e('records', 'records'); ?></small>
                <small class="text-muted d-block mb-2" style="font-size:0.7rem;">
                    <?php echo implode(', ', $mod['tables']); ?>
                </small>
                <a href="<?php echo BASE_URL; ?>api/settings.php?action=export_full&module=<?php echo $modKey; ?>" class="btn btn-outline-primary btn-sm w-100">
                    <i class="bi bi-download me-1"></i> <?php _e('bak_export', 'Export'); ?>
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Single Table Export -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-file-earmark-spreadsheet me-1"></i> <?php _e('bak_table_export', 'Individual Table Export (CSV)'); ?></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th><?php _e('bak_table_name', 'Table'); ?></th>
                        <th class="text-end"><?php _e('records', 'Records'); ?></th>
                        <th class="text-center" style="width:100px;"><?php _e('bak_export', 'Export'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tableStats as $table => $count): ?>
                    <tr>
                        <td class="fw-semibold"><code><?php echo $table; ?></code></td>
                        <td class="text-end"><?php echo number_format($count); ?></td>
                        <td class="text-center">
                            <a href="<?php echo BASE_URL; ?>api/settings.php?action=export_table&table=<?php echo $table; ?>" class="btn btn-sm btn-outline-success" title="<?php _e('bak_download_csv', 'Download CSV'); ?>">
                                <i class="bi bi-download"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Importing moved to its own page (part 40): the raw table insert that lived
     here could not match names, explain errors or post opening balances. -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-box-arrow-in-down me-1"></i> <?php _e('bak_import', 'Import Data (CSV)'); ?></div>
    <div class="card-body">
        <p class="mb-2"><?php _e('bak_import_moved', 'Importing has moved to the Data Import page, which checks every row before anything is saved, matches records by name, and can be undone.'); ?></p>
        <a href="<?php echo BASE_URL; ?>modules/import/" class="btn btn-primary"><i class="bi bi-box-arrow-in-down me-1"></i> <?php _e('imp_title', 'Data Import'); ?></a>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
