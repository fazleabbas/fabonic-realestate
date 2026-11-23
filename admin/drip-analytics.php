<?php
/**
 * FIS Real Estate — Central Admin: Drip Campaign Analytics
 * Conversion funnel, delivery rates, step effectiveness, export.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/../includes/whatsapp.php';

// Handle logout
if (isset($_GET['logout'])) {
    adminLogout();
    header('Location: login.php');
    exit;
}

requireAdminAuth();

$db = getAdminDb();
$pageTitle = 'Drip Analytics';

// ============================================
// Handle CSV export
// ============================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="drip_campaigns_export_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Campaign ID', 'Name', 'Company', 'Phone', 'Language', 'Status', 'Steps Completed',
                    'Sent', 'Delivered', 'Failed', 'Trial Status', 'Sub Status', 'Created']);

    $exportStmt = $db->query("
        SELECT c.*, t.company_name, t.status AS trial_status,
               s.status AS sub_status,
               (SELECT COUNT(*) FROM drip_messages_re WHERE campaign_id = c.id AND status IN ('sent','delivered')) AS msgs_sent,
               (SELECT COUNT(*) FROM drip_messages_re WHERE campaign_id = c.id AND status = 'delivered') AS msgs_delivered,
               (SELECT COUNT(*) FROM drip_messages_re WHERE campaign_id = c.id AND status = 'failed') AS msgs_failed
        FROM drip_campaigns_re c
        JOIN trial_requests t ON t.id = c.trial_request_id
        LEFT JOIN subscriptions s ON s.trial_request_id = t.id
        ORDER BY c.created_at DESC
    ");

    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['id'], $row['name'], $row['company_name'], $row['phone'],
            strtoupper($row['language'] ?? 'SW'), $row['status'], $row['current_step'],
            $row['msgs_sent'], $row['msgs_delivered'], $row['msgs_failed'],
            $row['trial_status'], $row['sub_status'] ?? '-', $row['created_at']
        ]);
    }

    fclose($out);
    exit;
}

// ============================================
// Compute Analytics
// ============================================

// Overview stats
try {
    $totalCampaigns = (int) $db->query("SELECT COUNT(*) FROM drip_campaigns_re")->fetchColumn();
    $activeCampaigns = (int) $db->query("SELECT COUNT(*) FROM drip_campaigns_re WHERE status = 'active'")->fetchColumn();
    $completedCampaigns = (int) $db->query("SELECT COUNT(*) FROM drip_campaigns_re WHERE status = 'completed'")->fetchColumn();
    $stoppedCampaigns = (int) $db->query("SELECT COUNT(*) FROM drip_campaigns_re WHERE status = 'stopped'")->fetchColumn();

    $totalMessages = (int) $db->query("SELECT COUNT(*) FROM drip_messages_re WHERE status != 'pending'")->fetchColumn();
    $sentMessages = (int) $db->query("SELECT COUNT(*) FROM drip_messages_re WHERE status IN ('sent','delivered')")->fetchColumn();
    $deliveredMessages = (int) $db->query("SELECT COUNT(*) FROM drip_messages_re WHERE status = 'delivered'")->fetchColumn();
    $failedMessages = (int) $db->query("SELECT COUNT(*) FROM drip_messages_re WHERE status = 'failed'")->fetchColumn();

    $deliveryRate = $sentMessages > 0 ? round(($deliveredMessages / $sentMessages) * 100, 1) : 0;
    $failRate = $totalMessages > 0 ? round(($failedMessages / $totalMessages) * 100, 1) : 0;
} catch (PDOException $e) {
    $totalCampaigns = $activeCampaigns = $completedCampaigns = $stoppedCampaigns = 0;
    $totalMessages = $sentMessages = $deliveredMessages = $failedMessages = 0;
    $deliveryRate = $failRate = 0;
}

// Conversion funnel
try {
    $funnelTrialRequests = (int) $db->query("SELECT COUNT(*) FROM trial_requests")->fetchColumn();
    $funnelApproved = (int) $db->query("SELECT COUNT(*) FROM trial_requests WHERE status = 'approved'")->fetchColumn();
    $funnelProvisioned = (int) $db->query("SELECT COUNT(*) FROM subscriptions WHERE trial_request_id IS NOT NULL")->fetchColumn();
    $funnelActive = (int) $db->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active' AND trial_request_id IS NOT NULL")->fetchColumn();
} catch (PDOException $e) {
    $funnelTrialRequests = $funnelApproved = $funnelProvisioned = $funnelActive = 0;
}

// Average days to convert (from trial request to active subscription)
try {
    $avgDaysConvert = $db->query("
        SELECT ROUND(AVG(DATEDIFF(s.updated_at, t.created_at)), 1)
        FROM subscriptions s
        JOIN trial_requests t ON t.id = s.trial_request_id
        WHERE s.status = 'active'
    ")->fetchColumn();
    $avgDaysConvert = $avgDaysConvert ?: '-';
} catch (PDOException $e) {
    $avgDaysConvert = '-';
}

// Per-step effectiveness
try {
    $stepStats = $db->query("
        SELECT step_number,
               COUNT(*) AS total,
               SUM(CASE WHEN status IN ('sent','delivered') THEN 1 ELSE 0 END) AS sent,
               SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
               SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
        FROM drip_messages_re
        GROUP BY step_number
        ORDER BY step_number
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stepStats = [];
}

// Messages sent per day (last 30 days) for chart
try {
    $dailyStats = $db->query("
        SELECT DATE(sent_at) AS send_date,
               COUNT(*) AS total_sent,
               SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered
        FROM drip_messages_re
        WHERE sent_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        AND status IN ('sent', 'delivered')
        GROUP BY DATE(sent_at)
        ORDER BY send_date
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dailyStats = [];
}

// Queue status
try {
    $queuePending = (int) $db->query("SELECT COUNT(*) FROM drip_message_queue WHERE status IN ('queued','sending')")->fetchColumn();
    $queueFailed = (int) $db->query("SELECT COUNT(*) FROM drip_message_queue WHERE status = 'failed' AND attempts < max_attempts")->fetchColumn();
} catch (PDOException $e) {
    $queuePending = $queueFailed = 0;
}

$schedule = getDripSchedule();
$stepLabels = [
    1 => 'Welcome', 2 => 'Getting Started', 3 => 'Leases & Rent',
    4 => 'Reminders', 5 => 'Mid-Trial', 6 => 'Pro Features',
    7 => 'Testimonials', 8 => 'Urgency', 9 => 'Trial Expired',
    10 => 'Follow Up', 11 => 'Final'
];

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h4 class="fw-bold mb-0"><i class="bi bi-graph-up me-2"></i>Drip Campaign Analytics</h4>
    <div class="d-flex gap-2">
        <a href="drip.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-send me-1"></i>Manage Campaigns</a>
        <a href="?export=csv" class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
</div>

<!-- Overview Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-send"></i></div>
            <div>
                <div class="adm-stat-value"><?php echo $totalCampaigns; ?></div>
                <div class="adm-stat-label">Total Campaigns</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:#dcfce7;color:#166534;"><i class="bi bi-chat-dots"></i></div>
            <div>
                <div class="adm-stat-value"><?php echo $sentMessages; ?></div>
                <div class="adm-stat-label">Messages Sent</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-check2-all"></i></div>
            <div>
                <div class="adm-stat-value"><?php echo $deliveryRate; ?>%</div>
                <div class="adm-stat-label">Delivery Rate</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="adm-stat-card">
            <div class="adm-stat-icon" style="background:#f3e8ff;color:#6b21a8;"><i class="bi bi-calendar-check"></i></div>
            <div>
                <div class="adm-stat-value"><?php echo $avgDaysConvert; ?></div>
                <div class="adm-stat-label">Avg Days to Convert</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Conversion Funnel -->
    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-bold"><i class="bi bi-funnel me-1"></i> Conversion Funnel</div>
            <div class="card-body">
                <?php
                $funnelSteps = [
                    ['label' => 'Trial Requests', 'count' => $funnelTrialRequests, 'color' => '#6366f1', 'icon' => 'inbox'],
                    ['label' => 'Approved', 'count' => $funnelApproved, 'color' => '#f59e0b', 'icon' => 'check-circle'],
                    ['label' => 'Provisioned', 'count' => $funnelProvisioned, 'color' => '#3b82f6', 'icon' => 'gear'],
                    ['label' => 'Active Subscribers', 'count' => $funnelActive, 'color' => '#16a34a', 'icon' => 'star-fill'],
                ];
                $maxFunnel = max(1, $funnelTrialRequests);
                foreach ($funnelSteps as $i => $fs):
                    $pct = round(($fs['count'] / $maxFunnel) * 100);
                    $convRate = ($i > 0 && $funnelSteps[$i-1]['count'] > 0)
                        ? round(($fs['count'] / $funnelSteps[$i-1]['count']) * 100, 1)
                        : 100;
                ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span style="font-size:0.85rem;">
                            <i class="bi bi-<?php echo $fs['icon']; ?> me-1" style="color:<?php echo $fs['color']; ?>"></i>
                            <?php echo $fs['label']; ?>
                        </span>
                        <span>
                            <strong><?php echo $fs['count']; ?></strong>
                            <?php if ($i > 0): ?>
                            <small class="text-muted ms-1">(<?php echo $convRate; ?>%)</small>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar" style="width:<?php echo $pct; ?>%; background:<?php echo $fs['color']; ?>;"></div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if ($funnelTrialRequests > 0): ?>
                <div class="mt-3 pt-3 border-top text-center">
                    <small class="text-muted">Overall Conversion: </small>
                    <strong class="text-success"><?php echo round(($funnelActive / $funnelTrialRequests) * 100, 1); ?>%</strong>
                    <small class="text-muted"> (request to active)</small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Message Breakdown -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-bold"><i class="bi bi-pie-chart me-1"></i> Message Breakdown</div>
            <div class="card-body">
                <div class="d-flex flex-column gap-3">
                    <div>
                        <div class="d-flex justify-content-between mb-1">
                            <small>Delivered</small>
                            <strong class="text-success"><?php echo $deliveredMessages; ?></strong>
                        </div>
                        <div class="progress" style="height:6px;">
                            <div class="progress-bar bg-success" style="width:<?php echo $totalMessages ? round($deliveredMessages/$totalMessages*100) : 0; ?>%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="d-flex justify-content-between mb-1">
                            <small>Sent (no confirmation)</small>
                            <strong class="text-primary"><?php echo $sentMessages - $deliveredMessages; ?></strong>
                        </div>
                        <div class="progress" style="height:6px;">
                            <div class="progress-bar bg-primary" style="width:<?php echo $totalMessages ? round(($sentMessages-$deliveredMessages)/$totalMessages*100) : 0; ?>%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="d-flex justify-content-between mb-1">
                            <small>Failed</small>
                            <strong class="text-danger"><?php echo $failedMessages; ?></strong>
                        </div>
                        <div class="progress" style="height:6px;">
                            <div class="progress-bar bg-danger" style="width:<?php echo $totalMessages ? round($failedMessages/$totalMessages*100) : 0; ?>%"></div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top">
                    <div class="d-flex justify-content-between" style="font-size:0.85rem;">
                        <span class="text-muted">Queue Pending:</span>
                        <strong><?php echo $queuePending; ?></strong>
                    </div>
                    <div class="d-flex justify-content-between" style="font-size:0.85rem;">
                        <span class="text-muted">Queue Retryable:</span>
                        <strong><?php echo $queueFailed; ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Campaign Status Breakdown -->
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-bold"><i class="bi bi-list-task me-1"></i> Campaigns</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="ps-3"><i class="bi bi-circle-fill text-success me-1" style="font-size:0.5rem;"></i> Active</td>
                        <td class="text-end pe-3 fw-bold"><?php echo $activeCampaigns; ?></td>
                    </tr>
                    <tr>
                        <td class="ps-3"><i class="bi bi-circle-fill text-primary me-1" style="font-size:0.5rem;"></i> Completed</td>
                        <td class="text-end pe-3 fw-bold"><?php echo $completedCampaigns; ?></td>
                    </tr>
                    <tr>
                        <td class="ps-3"><i class="bi bi-circle-fill text-danger me-1" style="font-size:0.5rem;"></i> Stopped</td>
                        <td class="text-end pe-3 fw-bold"><?php echo $stoppedCampaigns; ?></td>
                    </tr>
                    <tr class="table-light">
                        <td class="ps-3 fw-bold">Total</td>
                        <td class="text-end pe-3 fw-bold"><?php echo $totalCampaigns; ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Per-Step Effectiveness -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-bold"><i class="bi bi-bar-chart me-1"></i> Step-by-Step Effectiveness</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead style="background:#f8f9fa;">
                <tr>
                    <th>Step</th>
                    <th>Day</th>
                    <th>Label</th>
                    <th>Total</th>
                    <th>Sent</th>
                    <th>Delivered</th>
                    <th>Failed</th>
                    <th>Delivery Rate</th>
                    <th>Visual</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($stepStats as $ss):
                    $step = (int) $ss['step_number'];
                    $dr = $ss['sent'] > 0 ? round(($ss['delivered'] / $ss['sent']) * 100, 1) : 0;
                ?>
                <tr>
                    <td><strong><?php echo $step; ?></strong></td>
                    <td><small class="text-muted"><?php echo $schedule[$step] ?? '-'; ?></small></td>
                    <td><small><?php echo $stepLabels[$step] ?? '-'; ?></small></td>
                    <td><?php echo $ss['total']; ?></td>
                    <td class="text-primary"><?php echo $ss['sent']; ?></td>
                    <td class="text-success"><?php echo $ss['delivered']; ?></td>
                    <td class="text-danger"><?php echo $ss['failed']; ?></td>
                    <td>
                        <strong <?php echo $dr >= 80 ? 'class="text-success"' : ($dr >= 50 ? 'class="text-warning"' : 'class="text-danger"'); ?>>
                            <?php echo $dr; ?>%
                        </strong>
                    </td>
                    <td style="min-width:120px;">
                        <div class="progress" style="height:6px;">
                            <div class="progress-bar bg-success" style="width:<?php echo $ss['total'] ? round($ss['delivered']/$ss['total']*100) : 0; ?>%" title="Delivered"></div>
                            <div class="progress-bar bg-primary" style="width:<?php echo $ss['total'] ? round(($ss['sent']-$ss['delivered'])/$ss['total']*100) : 0; ?>%" title="Sent"></div>
                            <div class="progress-bar bg-danger" style="width:<?php echo $ss['total'] ? round($ss['failed']/$ss['total']*100) : 0; ?>%" title="Failed"></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($stepStats)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No message data yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Daily Send Volume Chart -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-bold"><i class="bi bi-graph-up me-1"></i> Daily Send Volume (Last 30 Days)</div>
    <div class="card-body">
        <?php if (!empty($dailyStats)): ?>
        <canvas id="dailyChart" height="80"></canvas>
        <?php else: ?>
        <p class="text-center text-muted py-4">No send data in the last 30 days.</p>
        <?php endif; ?>
    </div>
</div>

<?php
// Prepare chart data
$chartLabels = [];
$chartSent = [];
$chartDelivered = [];
foreach ($dailyStats as $ds) {
    $chartLabels[] = date('d M', strtotime($ds['send_date']));
    $chartSent[] = (int) $ds['total_sent'];
    $chartDelivered[] = (int) $ds['delivered'];
}

$pageScripts = '';
if (!empty($dailyStats)) {
    $labelsJson = json_encode($chartLabels);
    $sentJson = json_encode($chartSent);
    $deliveredJson = json_encode($chartDelivered);
    $pageScripts = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('dailyChart'), {
    type: 'bar',
    data: {
        labels: {$labelsJson},
        datasets: [
            { label: 'Sent', data: {$sentJson}, backgroundColor: 'rgba(37,99,235,0.6)', borderRadius: 4 },
            { label: 'Delivered', data: {$deliveredJson}, backgroundColor: 'rgba(22,163,74,0.6)', borderRadius: 4 }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } },
            x: { grid: { display: false } }
        }
    }
});
</script>
JS;
}

require_once __DIR__ . '/includes/footer.php';
?>
