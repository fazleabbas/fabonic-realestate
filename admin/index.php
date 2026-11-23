<?php
/**
 * FIS Real Estate — Central Admin Dashboard
 * Redirects to trials for now; will be expanded in Part 23.
 */
require_once __DIR__ . '/includes/auth.php';

// Handle logout
if (isset($_GET['logout'])) {
    adminLogout();
    header('Location: login.php');
    exit;
}

requireAdminAuth();

$db = getAdminDb();
$pageTitle = 'Dashboard';

// Quick stats
try {
    $totalTrials = (int) $db->query("SELECT COUNT(*) FROM trial_requests")->fetchColumn();
    $pendingTrials = (int) $db->query("SELECT COUNT(*) FROM trial_requests WHERE status = 'pending'")->fetchColumn();
    $approvedTrials = (int) $db->query("SELECT COUNT(*) FROM trial_requests WHERE status = 'approved'")->fetchColumn();
    $totalSubs = (int) $db->query("SELECT COUNT(*) FROM subscriptions WHERE status IN ('trial','active')")->fetchColumn();
    $trialSubs = (int) $db->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'trial'")->fetchColumn();
    $activeSubs = (int) $db->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'")->fetchColumn();
    $totalUnits = (int) $db->query("SELECT COALESCE(SUM(unit_count),0) FROM subscriptions WHERE status IN ('trial','active')")->fetchColumn();
} catch (PDOException $e) {
    $totalTrials = $pendingTrials = $approvedTrials = $totalSubs = $trialSubs = $activeSubs = $totalUnits = 0;
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-speedometer2 me-2"></i>Dashboard</h4>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-clock-history"></i></div>
            <div>
                <div class="adm-stat-value"><?php echo $pendingTrials; ?></div>
                <div class="adm-stat-label">Pending Trials</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:#dcfce7;color:#166534;"><i class="bi bi-check-circle"></i></div>
            <div>
                <div class="adm-stat-value"><?php echo $totalSubs; ?></div>
                <div class="adm-stat-label">Active Subscribers</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-building"></i></div>
            <div>
                <div class="adm-stat-value"><?php echo number_format($totalUnits); ?></div>
                <div class="adm-stat-label">Total Units Managed</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:#f3e8ff;color:#6b21a8;"><i class="bi bi-people"></i></div>
            <div>
                <div class="adm-stat-value"><?php echo $totalTrials; ?></div>
                <div class="adm-stat-label">Total Requests</div>
            </div>
        </div>
    </div>
</div>

<!-- Quick breakdown -->
<div class="row g-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold"><i class="bi bi-clock-history me-1"></i> Trial Requests</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><td class="ps-3">Pending</td><td class="text-end pe-3"><span class="adm-badge adm-badge-pending"><?php echo $pendingTrials; ?></span></td></tr>
                    <tr><td class="ps-3">Approved</td><td class="text-end pe-3"><span class="adm-badge adm-badge-approved"><?php echo $approvedTrials; ?></span></td></tr>
                    <tr><td class="ps-3">Total</td><td class="text-end pe-3 fw-bold"><?php echo $totalTrials; ?></td></tr>
                </table>
            </div>
            <div class="card-footer bg-white text-end">
                <a href="trials.php" class="btn btn-sm btn-outline-success">View All Trials &rarr;</a>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold"><i class="bi bi-credit-card me-1"></i> Subscriptions</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><td class="ps-3">Trial</td><td class="text-end pe-3"><span class="adm-badge adm-badge-pending"><?php echo $trialSubs; ?></span></td></tr>
                    <tr><td class="ps-3">Active (Paid)</td><td class="text-end pe-3"><span class="adm-badge adm-badge-approved"><?php echo $activeSubs; ?></span></td></tr>
                    <tr><td class="ps-3">Total Active</td><td class="text-end pe-3 fw-bold"><?php echo $totalSubs; ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
