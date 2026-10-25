<?php
/**
 * FIS Real Estate — SaaS Admin Dashboard (Part 23.1)
 * Overview: subscriber stats, MRR, trials, churn, growth charts
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/header.php';
$pageTitle = __('adm_admin_dashboard', 'SaaS Admin Dashboard');

// Admin only
if (!isSaasOperator()) {
    echo '<div class="alert alert-danger">' . __('adm_admin_only', 'Admin access required') . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-speedometer2 me-2"></i><?php _e('adm_admin_dashboard', 'SaaS Admin Dashboard'); ?></h4>
        <small class="text-muted"><?php _e('adm_dashboard_subtitle', 'Monitor subscribers, revenue, and system health'); ?></small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?php echo BASE_URL; ?>modules/admin/subscribers.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-people me-1"></i><?php _e('adm_manage_subscribers', 'Manage Subscribers'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/admin/payments.php" class="btn btn-outline-success btn-sm">
            <i class="bi bi-credit-card me-1"></i><?php _e('adm_verify_payments', 'Verify Payments'); ?>
        </a>
    </div>
</div>

<!-- Primary Stats -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 me-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:45px;height:45px;background:rgba(26,107,74,0.1);">
                            <i class="bi bi-people-fill text-success" style="font-size:1.3rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h5 class="mb-0" id="statActiveSubscribers">-</h5>
                        <small class="text-muted"><?php _e('adm_active_subscribers', 'Active Subscribers'); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 me-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:45px;height:45px;background:rgba(232,168,56,0.1);">
                            <i class="bi bi-currency-exchange text-warning" style="font-size:1.3rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h5 class="mb-0" id="statMRR">-</h5>
                        <small class="text-muted"><?php _e('adm_mrr', 'Monthly Revenue (MRR)'); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 me-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:45px;height:45px;background:rgba(13,110,253,0.1);">
                            <i class="bi bi-hourglass-split text-primary" style="font-size:1.3rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h5 class="mb-0" id="statTrials">-</h5>
                        <small class="text-muted"><?php _e('adm_active_trials', 'Active Trials'); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 me-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:45px;height:45px;background:rgba(220,53,69,0.1);">
                            <i class="bi bi-arrow-down-circle text-danger" style="font-size:1.3rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h5 class="mb-0" id="statChurn">-</h5>
                        <small class="text-muted"><?php _e('adm_churn_rate', 'Churn Rate'); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Secondary Stats -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted"><?php _e('adm_total_units', 'Total Units'); ?></small>
                        <h6 class="mb-0" id="statUnits">-</h6>
                    </div>
                    <i class="bi bi-door-open text-success" style="font-size:1.5rem;opacity:0.3;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted"><?php _e('adm_pending_payments', 'Pending Payments'); ?></small>
                        <h6 class="mb-0"><span id="statPendingCount">-</span> <small class="text-warning" id="statPendingAmt"></small></h6>
                    </div>
                    <i class="bi bi-clock-history text-warning" style="font-size:1.5rem;opacity:0.3;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted"><?php _e('adm_suspended', 'Suspended'); ?></small>
                        <h6 class="mb-0" id="statSuspended">-</h6>
                    </div>
                    <i class="bi bi-pause-circle text-danger" style="font-size:1.5rem;opacity:0.3;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted"><?php _e('adm_conversion_rate', 'Trial Conversion'); ?></small>
                        <h6 class="mb-0" id="statConversion">-</h6>
                    </div>
                    <i class="bi bi-graph-up text-primary" style="font-size:1.5rem;opacity:0.3;"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tier Breakdown + Charts -->
<div class="row g-3 mb-4">
    <!-- Tier Breakdown -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i><?php _e('adm_subscribers_by_tier', 'Subscribers by Tier'); ?></h6>
            </div>
            <div class="card-body">
                <canvas id="tierChart" height="200"></canvas>
                <div class="mt-3" id="tierBreakdown">
                    <div class="d-flex justify-content-between mb-2">
                        <span><span class="badge bg-secondary me-1">&nbsp;</span> Basic</span>
                        <strong id="tierBasic">0</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span><span class="badge bg-primary me-1">&nbsp;</span> Professional</span>
                        <strong id="tierProfessional">0</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span><span class="badge bg-success me-1">&nbsp;</span> Enterprise</span>
                        <strong id="tierEnterprise">0</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Revenue Chart -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i><?php _e('adm_revenue_trend', 'Revenue Trend (12 Months)'); ?></h6>
            </div>
            <div class="card-body">
                <canvas id="revenueChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Growth Chart -->
<div class="row g-3 mb-4">
    <div class="col-lg-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i><?php _e('adm_subscriber_growth', 'Subscriber Growth (12 Months)'); ?></h6>
                <div>
                    <span class="badge bg-light text-dark me-2" id="growthThisMonth">-</span>
                    <span class="badge bg-light text-dark" id="growthLastMonth">-</span>
                </div>
            </div>
            <div class="card-body">
                <canvas id="growthChart" height="120"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Quick Links -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <a href="<?php echo BASE_URL; ?>modules/admin/subscribers.php" class="card border-0 shadow-sm text-decoration-none h-100">
            <div class="card-body text-center py-4">
                <i class="bi bi-people-fill text-success" style="font-size:2rem;"></i>
                <h6 class="mt-2 mb-0 text-dark"><?php _e('adm_subscribers', 'Subscribers'); ?></h6>
                <small class="text-muted"><?php _e('adm_manage_all', 'Manage all'); ?></small>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="<?php echo BASE_URL; ?>modules/admin/payments.php" class="card border-0 shadow-sm text-decoration-none h-100">
            <div class="card-body text-center py-4">
                <i class="bi bi-credit-card-2-front text-warning" style="font-size:2rem;"></i>
                <h6 class="mt-2 mb-0 text-dark"><?php _e('adm_payments', 'Payments'); ?></h6>
                <small class="text-muted"><?php _e('adm_verify_confirm', 'Verify & confirm'); ?></small>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="<?php echo BASE_URL; ?>modules/admin/announcements.php" class="card border-0 shadow-sm text-decoration-none h-100">
            <div class="card-body text-center py-4">
                <i class="bi bi-megaphone-fill text-primary" style="font-size:2rem;"></i>
                <h6 class="mt-2 mb-0 text-dark"><?php _e('adm_announcements', 'Announcements'); ?></h6>
                <small class="text-muted"><?php _e('adm_system_notices', 'System notices'); ?></small>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="<?php echo BASE_URL; ?>modules/admin/announcements.php#tabSettings" class="card border-0 shadow-sm text-decoration-none h-100">
            <div class="card-body text-center py-4">
                <i class="bi bi-sliders text-danger" style="font-size:2rem;"></i>
                <h6 class="mt-2 mb-0 text-dark"><?php _e('adm_global_settings', 'Global Settings'); ?></h6>
                <small class="text-muted"><?php _e('adm_pricing_features', 'Pricing & features'); ?></small>
            </div>
        </a>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
const API_URL = BASE_URL + 'api/saas-admin.php';
let tierChartInst, revenueChartInst, growthChartInst;

function loadDashboard() {
    $.get(API_URL, {action: 'dashboard_stats'}, function(res) {
        if (!res.success) return;
        const d = res.data;

        // Primary stats
        $('#statActiveSubscribers').text(d.total_active.toLocaleString());
        $('#statMRR').text('TZS ' + Number(d.mrr).toLocaleString());
        $('#statTrials').text(d.active_trials.toLocaleString());
        $('#statChurn').text(d.churn_rate + '%');

        // Secondary stats
        $('#statUnits').text(d.total_units.toLocaleString());
        $('#statPendingCount').text(d.pending_payments);
        $('#statPendingAmt').text(d.pending_amount > 0 ? '(TZS ' + Number(d.pending_amount).toLocaleString() + ')' : '');
        $('#statSuspended').text(d.suspended);
        $('#statConversion').text(d.conversion_rate + '%');

        // Tier breakdown
        $('#tierBasic').text(d.by_tier.basic);
        $('#tierProfessional').text(d.by_tier.professional);
        $('#tierEnterprise').text(d.by_tier.enterprise);

        // Growth badges
        $('#growthThisMonth').html('<i class="bi bi-calendar-event me-1"></i>This month: ' + d.this_month_new);
        $('#growthLastMonth').html('<i class="bi bi-calendar me-1"></i>Last month: ' + d.last_month_new);

        // Tier chart
        const tierCtx = document.getElementById('tierChart').getContext('2d');
        if (tierChartInst) tierChartInst.destroy();
        tierChartInst = new Chart(tierCtx, {
            type: 'doughnut',
            data: {
                labels: ['Basic', 'Professional', 'Enterprise'],
                datasets: [{
                    data: [d.by_tier.basic, d.by_tier.professional, d.by_tier.enterprise],
                    backgroundColor: ['#6c757d', '#0d6efd', '#1a6b4a'],
                    borderWidth: 2
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });

        // Revenue chart
        const revCtx = document.getElementById('revenueChart').getContext('2d');
        if (revenueChartInst) revenueChartInst.destroy();
        const revLabels = d.revenue.map(r => r.month);
        const revData = d.revenue.map(r => parseFloat(r.total));
        revenueChartInst = new Chart(revCtx, {
            type: 'line',
            data: {
                labels: revLabels,
                datasets: [{
                    label: '<?php _e('adm_confirmed_revenue', 'Confirmed Revenue'); ?> (TZS)',
                    data: revData,
                    borderColor: '#1a6b4a',
                    backgroundColor: 'rgba(26,107,74,0.1)',
                    fill: true, tension: 0.3
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => 'TZS ' + v.toLocaleString() } } }
            }
        });

        // Growth chart
        const growCtx = document.getElementById('growthChart').getContext('2d');
        if (growthChartInst) growthChartInst.destroy();
        const growLabels = d.growth.map(g => g.month);
        const growData = d.growth.map(g => parseInt(g.new_subscribers));
        growthChartInst = new Chart(growCtx, {
            type: 'bar',
            data: {
                labels: growLabels,
                datasets: [{
                    label: '<?php _e('adm_new_subscribers', 'New Subscribers'); ?>',
                    data: growData,
                    backgroundColor: 'rgba(232,168,56,0.7)',
                    borderColor: '#e8a838',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    });
}

$(document).ready(function() { loadDashboard(); });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
