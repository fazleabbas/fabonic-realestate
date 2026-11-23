<?php
/**
 * FIS Real Estate — View Property (5.1 + 5.3 Dashboard Widgets)
 * Property detail overview page with dashboard stats, photo gallery, settings summary.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

$subStatus = enforceSubscription();

if (!hasPermission('properties', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Property not found', 'danger');
    redirect('modules/properties/');
}

// Fetch property
$stmt = $conn->prepare("SELECT * FROM properties WHERE id = :id");
$stmt->execute(['id' => $id]);
$property = $stmt->fetch();

if (!$property) {
    setAlert('Property not found', 'danger');
    redirect('modules/properties/');
}

if (!canAccessProperty($id)) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/properties/');
}

$pageTitle = $property['name'];

// --- Dashboard Stats ---

// Unit counts
$unitStmt = $conn->prepare("SELECT
    COUNT(*) AS total_units,
    SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) AS occupied,
    SUM(CASE WHEN status = 'vacant' THEN 1 ELSE 0 END) AS vacant,
    SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_count,
    SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) AS reserved
    FROM units WHERE property_id = :pid
");
$unitStmt->execute(['pid' => $id]);
$unitStats = $unitStmt->fetch();

$totalUnits = (int) $unitStats['total_units'];
$occupiedUnits = (int) $unitStats['occupied'];
$vacantUnits = (int) $unitStats['vacant'];
$occupancyRate = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 1) : 0;

// Revenue this month
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$revStmt = $conn->prepare("
    SELECT COALESCE(SUM(pay.amount), 0) AS revenue
    FROM payments pay
    INNER JOIN units u ON pay.unit_id = u.id
    WHERE u.property_id = :pid AND pay.payment_date BETWEEN :start AND :end AND pay.status = 'recorded'
");
$revStmt->execute(['pid' => $id, 'start' => $monthStart, 'end' => $monthEnd]);
$revenueThisMonth = (float) $revStmt->fetchColumn();

// Expected rent (active leases)
$expStmt = $conn->prepare("
    SELECT COALESCE(SUM(l.rent_amount), 0) AS expected
    FROM leases l INNER JOIN units u ON l.unit_id = u.id
    WHERE u.property_id = :pid AND l.status = 'active'
");
$expStmt->execute(['pid' => $id]);
$expectedRent = (float) $expStmt->fetchColumn();
$overdueAmount = max(0, $expectedRent - $revenueThisMonth);

// Active tenants & leases
$tenantsStmt = $conn->prepare("SELECT COUNT(DISTINCT l.tenant_id) FROM leases l INNER JOIN units u ON l.unit_id = u.id WHERE u.property_id = :pid AND l.status = 'active'");
$tenantsStmt->execute(['pid' => $id]);
$activeTenants = (int) $tenantsStmt->fetchColumn();

$leasesStmt = $conn->prepare("SELECT COUNT(*) FROM leases l INNER JOIN units u ON l.unit_id = u.id WHERE u.property_id = :pid AND l.status = 'active'");
$leasesStmt->execute(['pid' => $id]);
$activeLeases = (int) $leasesStmt->fetchColumn();

// Revenue last 6 months for chart
$revenueChart = [];
for ($i = 5; $i >= 0; $i--) {
    $mStart = date('Y-m-01', strtotime("-{$i} months"));
    $mEnd = date('Y-m-t', strtotime("-{$i} months"));
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay
        INNER JOIN units u ON pay.unit_id = u.id
        WHERE u.property_id = :pid AND pay.payment_date BETWEEN :start AND :end AND pay.status = 'recorded'
    ");
    $stmt->execute(['pid' => $id, 'start' => $mStart, 'end' => $mEnd]);
    $revenueChart[] = [
        'month' => date('M', strtotime($mStart)),
        'revenue' => (float) $stmt->fetchColumn()
    ];
}

// Photos (first 6 for gallery preview)
$photoStmt = $conn->prepare("SELECT * FROM property_photos WHERE property_id = :id ORDER BY sort_order ASC, id ASC LIMIT 6");
$photoStmt->execute(['id' => $id]);
$photos = $photoStmt->fetchAll();

$totalPhotos = (int) $conn->prepare("SELECT COUNT(*) FROM property_photos WHERE property_id = ?")
    ->execute([$id]) ? $conn->query("SELECT COUNT(*) FROM property_photos WHERE property_id = {$id}")->fetchColumn() : 0;

// Property settings
$settingsStmt = $conn->prepare("SELECT * FROM property_settings WHERE property_id = :id");
$settingsStmt->execute(['id' => $id]);
$propSettings = $settingsStmt->fetch();

// Recent units
$recentUnits = $conn->prepare("SELECT * FROM units WHERE property_id = :pid ORDER BY created_at DESC LIMIT 5");
$recentUnits->execute(['pid' => $id]);
$recentUnits = $recentUnits->fetchAll();

$typeLabels = [
    'residential' => __('residential', 'Residential'),
    'commercial' => __('commercial', 'Commercial'),
    'land' => __('land', 'Land'),
    'mixed' => __('mixed', 'Mixed Use')
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-buildings me-2"></i><?php echo htmlspecialchars($property['name']); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/properties/"><?php _e('properties'); ?></a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($property['name']); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <?php if (hasPermission('properties', 'edit')): ?>
        <a href="<?php echo BASE_URL; ?>modules/properties/edit.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-pencil me-1"></i> <?php _e('edit'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/properties/photos.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-images me-1"></i> <?php _e('photos', 'Photos'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/properties/settings.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i> <?php _e('settings'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('properties.view'); ?>

<!-- Property Info Bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span class="badge bg-<?php echo $property['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($property['status']); ?></span>
            <span><i class="bi bi-building me-1"></i> <?php echo $typeLabels[$property['property_type']] ?? ucfirst($property['property_type']); ?></span>
            <?php if ($property['city']): ?>
            <span><i class="bi bi-geo-alt me-1"></i> <?php echo htmlspecialchars($property['city']); ?><?php echo $property['region'] ? ', ' . htmlspecialchars($property['region']) : ''; ?></span>
            <?php endif; ?>
            <?php if ($property['address']): ?>
            <span class="text-muted"><i class="bi bi-pin-map me-1"></i> <?php echo htmlspecialchars($property['address']); ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Dashboard Stat Cards (5.3) -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;">
                <i class="bi bi-door-open"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo $totalUnits; ?></div>
                <div class="stat-card-label"><?php _e('total_units_label', 'Total Units'); ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dcfce7;color:#166534;">
                <i class="bi bi-percent"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo $occupancyRate; ?>%</div>
                <div class="stat-card-label"><?php _e('occupancy_rate', 'Occupancy Rate'); ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;">
                <i class="bi bi-cash-stack"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($revenueThisMonth); ?></div>
                <div class="stat-card-label"><?php _e('revenue_this_month', 'Revenue (This Month)'); ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:<?php echo $overdueAmount > 0 ? '#fee2e2' : '#f0f0f0'; ?>;color:<?php echo $overdueAmount > 0 ? '#991b1b' : '#666'; ?>;">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($overdueAmount); ?></div>
                <div class="stat-card-label"><?php _e('overdue_amount', 'Overdue Amount'); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Revenue Chart (Mini) -->
    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-graph-up me-1"></i> <?php _e('revenue_trend', 'Revenue Trend (6 Months)'); ?></span>
            </div>
            <div class="card-body">
                <canvas id="revenueChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <!-- Unit Status Breakdown -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-pie-chart me-1"></i> <?php _e('unit_status', 'Unit Status'); ?>
            </div>
            <div class="card-body">
                <?php if ($totalUnits > 0): ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <small><?php _e('occupied', 'Occupied'); ?></small>
                        <small class="fw-semibold"><?php echo $occupiedUnits; ?></small>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar bg-success" style="width:<?php echo $totalUnits > 0 ? round($occupiedUnits/$totalUnits*100) : 0; ?>%"></div>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <small><?php _e('vacant', 'Vacant'); ?></small>
                        <small class="fw-semibold"><?php echo $vacantUnits; ?></small>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar bg-danger" style="width:<?php echo $totalUnits > 0 ? round($vacantUnits/$totalUnits*100) : 0; ?>%"></div>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <small><?php _e('maintenance', 'Maintenance'); ?></small>
                        <small class="fw-semibold"><?php echo (int)$unitStats['maintenance_count']; ?></small>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar bg-warning" style="width:<?php echo $totalUnits > 0 ? round((int)$unitStats['maintenance_count']/$totalUnits*100) : 0; ?>%"></div>
                    </div>
                </div>
                <div>
                    <div class="d-flex justify-content-between mb-1">
                        <small><?php _e('reserved', 'Reserved'); ?></small>
                        <small class="fw-semibold"><?php echo (int)$unitStats['reserved']; ?></small>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar bg-info" style="width:<?php echo $totalUnits > 0 ? round((int)$unitStats['reserved']/$totalUnits*100) : 0; ?>%"></div>
                    </div>
                </div>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-door-open" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('no_units_yet', 'No units added yet'); ?></p>
                </div>
                <?php endif; ?>

                <hr>
                <div class="d-flex justify-content-between small">
                    <span><i class="bi bi-people me-1"></i><?php _e('tenants'); ?>: <strong><?php echo $activeTenants; ?></strong></span>
                    <span><i class="bi bi-file-earmark-text me-1"></i><?php _e('leases'); ?>: <strong><?php echo $activeLeases; ?></strong></span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Photo Gallery Preview -->
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-images me-1"></i> <?php _e('photos', 'Photos'); ?> (<?php echo $totalPhotos; ?>)</span>
                <?php if (hasPermission('properties', 'edit')): ?>
                <a href="<?php echo BASE_URL; ?>modules/properties/photos.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-pencil me-1"></i><?php _e('manage', 'Manage'); ?>
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($photos)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-camera" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('no_photos', 'No photos uploaded yet'); ?></p>
                </div>
                <?php else: ?>
                <div class="row g-2">
                    <?php foreach ($photos as $photo): ?>
                    <div class="col-4 col-md-3 col-lg-2">
                        <div style="aspect-ratio:1;border-radius:8px;overflow:hidden;background:#f0f0f0;">
                            <img src="<?php echo BASE_URL; ?>uploads/<?php echo htmlspecialchars($photo['file_path']); ?>"
                                 alt="<?php echo htmlspecialchars($photo['caption'] ?? ''); ?>"
                                 style="width:100%;height:100%;object-fit:cover;cursor:pointer;"
                                 onclick="window.open(this.src, '_blank')"
                                 loading="lazy">
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($totalPhotos > 6): ?>
                    <div class="col-4 col-md-3 col-lg-2">
                        <a href="<?php echo BASE_URL; ?>modules/properties/photos.php?id=<?php echo $id; ?>" class="d-flex align-items-center justify-content-center" style="aspect-ratio:1;border-radius:8px;background:#f0f0f0;text-decoration:none;">
                            <span class="text-muted">+<?php echo $totalPhotos - 6; ?> <?php _e('more', 'more'); ?></span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Property Settings Summary -->
    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-gear me-1"></i> <?php _e('property_settings', 'Property Settings'); ?></span>
                <?php if (hasPermission('properties', 'edit')): ?>
                <a href="<?php echo BASE_URL; ?>modules/properties/settings.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-pencil me-1"></i><?php _e('edit'); ?>
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($propSettings): ?>
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted"><?php _e('rent_cycle', 'Rent Cycle'); ?></td>
                        <td class="fw-semibold"><?php echo ucfirst($propSettings['rent_cycle']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('late_fee', 'Late Fee'); ?></td>
                        <td class="fw-semibold">
                            <?php if ($propSettings['late_fee_type'] === 'none'): ?>
                                <?php _e('none', 'None'); ?>
                            <?php elseif ($propSettings['late_fee_type'] === 'percentage'): ?>
                                <?php echo $propSettings['late_fee_value']; ?>%
                            <?php else: ?>
                                <?php echo formatCurrency($propSettings['late_fee_value']); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('grace_period', 'Grace Period'); ?></td>
                        <td class="fw-semibold"><?php echo $propSettings['grace_period_days']; ?> <?php _e('days'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('deposit_months', 'Deposit (Months)'); ?></td>
                        <td class="fw-semibold"><?php echo $propSettings['deposit_months']; ?></td>
                    </tr>
                </table>
                <?php else: ?>
                <p class="text-muted mb-0"><?php _e('no_settings', 'No settings configured'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Units -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-door-open me-1"></i> <?php _e('recent_units', 'Recent Units'); ?></span>
        <?php if (hasPermission('units', 'view')): ?>
        <a href="<?php echo BASE_URL; ?>modules/units/?property_id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">
            <?php _e('view_all', 'View All'); ?>
        </a>
        <?php endif; ?>
    </div>
    <?php if (empty($recentUnits)): ?>
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-door-open" style="font-size:2rem;"></i>
        <p class="mt-2 mb-0"><?php _e('no_units_yet', 'No units added yet'); ?></p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th><?php _e('unit_number', 'Unit #'); ?></th>
                    <th><?php _e('type'); ?></th>
                    <th><?php _e('rent', 'Rent'); ?></th>
                    <th><?php _e('status'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentUnits as $unit): ?>
                <tr>
                    <td class="fw-semibold"><?php echo htmlspecialchars($unit['unit_number']); ?></td>
                    <td><span class="badge bg-light text-dark"><?php echo ucfirst(str_replace('_', ' ', $unit['unit_type'])); ?></span></td>
                    <td><?php echo formatCurrency($unit['rent_amount'], $unit['currency']); ?></td>
                    <td>
                        <?php
                        $statusColors = ['vacant' => 'danger', 'occupied' => 'success', 'maintenance' => 'warning', 'reserved' => 'info'];
                        ?>
                        <span class="badge bg-<?php echo $statusColors[$unit['status']] ?? 'secondary'; ?>">
                            <?php echo ucfirst($unit['status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Description -->
<?php if ($property['description']): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-text-paragraph me-1"></i> <?php _e('description'); ?></div>
    <div class="card-body">
        <p class="mb-0"><?php echo nl2br(htmlspecialchars($property['description'])); ?></p>
    </div>
</div>
<?php endif; ?>

<!-- Chart.js for Revenue -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('revenueChart');
    if (!ctx) return;

    const chartData = <?php echo json_encode($revenueChart); ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: chartData.map(d => d.month),
            datasets: [{
                label: '<?php echo __("revenue", "Revenue"); ?>',
                data: chartData.map(d => d.revenue),
                backgroundColor: 'rgba(26, 107, 74, 0.7)',
                borderColor: '#1a6b4a',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(val) {
                            return val >= 1000000 ? (val/1000000).toFixed(1) + 'M' :
                                   val >= 1000 ? (val/1000).toFixed(0) + 'K' : val;
                        }
                    }
                }
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
