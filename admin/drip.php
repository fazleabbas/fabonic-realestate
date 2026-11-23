<?php
/**
 * FIS Real Estate — Central Admin: Drip Campaign Management
 * View all campaigns, step history, resend failed, stop/resume, timeline visualization.
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
$pageTitle = 'Drip Campaigns';
$alert = null;

// ============================================
// Handle POST actions
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkAdminCsrf();

    $action = $_POST['action'] ?? '';
    $campaignId = intval($_POST['campaign_id'] ?? 0);
    $messageId = intval($_POST['message_id'] ?? 0);

    try {
        switch ($action) {
            case 'stop':
                if ($campaignId > 0) {
                    $stmt = $db->prepare("UPDATE drip_campaigns_re SET status = 'stopped', updated_at = NOW() WHERE id = ? AND status = 'active'");
                    $stmt->execute([$campaignId]);
                    if ($stmt->rowCount()) {
                        $alert = ['type' => 'warning', 'msg' => "Campaign #{$campaignId} stopped."];
                    }
                }
                break;

            case 'resume':
                if ($campaignId > 0) {
                    // Resume: recalculate next_send_at from current_step
                    $camp = $db->prepare("SELECT * FROM drip_campaigns_re WHERE id = ?")->execute([$campaignId]);
                    $camp = $db->prepare("SELECT * FROM drip_campaigns_re WHERE id = ?");
                    $camp->execute([$campaignId]);
                    $campData = $camp->fetch(PDO::FETCH_ASSOC);

                    if ($campData && $campData['status'] === 'stopped') {
                        $schedule = getDripSchedule();
                        $nextStep = (int) $campData['current_step'] + 1;
                        $maxStep = max(array_keys($schedule));

                        if ($nextStep <= $maxStep) {
                            $sendHour = (int) getDripSetting('drip_send_hour', 9);
                            $nextSendAt = date('Y-m-d') . ' ' . sprintf('%02d', $sendHour) . ':00:00';
                            if ($nextSendAt <= date('Y-m-d H:i:s')) {
                                $nextSendAt = date('Y-m-d', strtotime('+1 day')) . ' ' . sprintf('%02d', $sendHour) . ':00:00';
                            }

                            $db->prepare("UPDATE drip_campaigns_re SET status = 'active', next_send_at = ?, updated_at = NOW() WHERE id = ?")
                                ->execute([$nextSendAt, $campaignId]);
                            $alert = ['type' => 'success', 'msg' => "Campaign #{$campaignId} resumed. Next send: {$nextSendAt}"];
                        } else {
                            $db->prepare("UPDATE drip_campaigns_re SET status = 'completed', updated_at = NOW() WHERE id = ?")
                                ->execute([$campaignId]);
                            $alert = ['type' => 'info', 'msg' => "Campaign #{$campaignId} was already on last step. Marked as completed."];
                        }
                    }
                }
                break;

            case 'resend':
                if ($messageId > 0) {
                    // Re-queue a failed message
                    $msg = $db->prepare("
                        SELECT m.*, c.phone, c.name, c.language, c.id AS cid,
                               t.company_name, t.unit_count, t.tenant_slug, t.trial_end_date
                        FROM drip_messages_re m
                        JOIN drip_campaigns_re c ON c.id = m.campaign_id
                        JOIN trial_requests t ON t.id = c.trial_request_id
                        WHERE m.id = ?
                    ");
                    $msg->execute([$messageId]);
                    $msgData = $msg->fetch(PDO::FETCH_ASSOC);

                    if ($msgData) {
                        $loginUrl = $msgData['tenant_slug']
                            ? "https://{$msgData['tenant_slug']}." . CPANEL_DOMAIN . "/"
                            : "https://realestate.fis.co.tz";
                        $trialEndDate = $msgData['trial_end_date'] ? date('d/m/Y', strtotime($msgData['trial_end_date'])) : '';

                        $message = buildDripMessage(
                            (int) $msgData['step_number'],
                            $msgData['name'],
                            $msgData['company_name'] ?? $msgData['name'],
                            (int) ($msgData['unit_count'] ?? 1),
                            $loginUrl,
                            $trialEndDate,
                            $msgData['language'] ?? 'sw'
                        );

                        if ($message) {
                            $queueId = enqueueWhatsApp($msgData['phone'], $message, $msgData['cid'], $messageId, 2);
                            if ($queueId) {
                                $db->prepare("UPDATE drip_messages_re SET status = 'pending', retry_count = retry_count + 1 WHERE id = ?")
                                    ->execute([$messageId]);
                                $alert = ['type' => 'success', 'msg' => "Message #{$messageId} (step {$msgData['step_number']}) re-queued for sending."];
                            } else {
                                $alert = ['type' => 'danger', 'msg' => 'Failed to enqueue message.'];
                            }
                        } else {
                            $alert = ['type' => 'danger', 'msg' => 'Could not build message template.'];
                        }
                    }
                }
                break;
        }
    } catch (PDOException $e) {
        $alert = ['type' => 'danger', 'msg' => 'Error: ' . $e->getMessage()];
    }
}

// ============================================
// View mode: detail or list
// ============================================
$viewCampaignId = intval($_GET['view'] ?? 0);

// ============================================
// Filters for campaign list
// ============================================
$statusFilter = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

$where = [];
$params = [];

if ($statusFilter !== 'all') {
    $where[] = "c.status = :status";
    $params['status'] = $statusFilter;
}

if ($search) {
    $where[] = "(c.name LIKE :search OR c.phone LIKE :search2 OR t.company_name LIKE :search3)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Status counts
$statusCounts = ['all' => 0, 'active' => 0, 'completed' => 0, 'stopped' => 0];
try {
    $countSql = $db->query("SELECT status, COUNT(*) as cnt FROM drip_campaigns_re GROUP BY status");
    while ($row = $countSql->fetch()) {
        $statusCounts[$row['status']] = (int) $row['cnt'];
    }
    $statusCounts['all'] = array_sum($statusCounts) - ($statusCounts['all'] ?? 0);
    $statusCounts['all'] = (int) $db->query("SELECT COUNT(*) FROM drip_campaigns_re")->fetchColumn();
} catch (PDOException $e) {}

// If viewing a specific campaign detail
$campaignDetail = null;
$campaignMessages = [];

if ($viewCampaignId > 0) {
    try {
        $stmt = $db->prepare("
            SELECT c.*, t.company_name, t.unit_count, t.city, t.phone AS t_phone,
                   t.email, t.trial_start_date, t.trial_end_date, t.status AS trial_status, t.tenant_slug,
                   s.status AS sub_status, s.tier AS sub_tier
            FROM drip_campaigns_re c
            JOIN trial_requests t ON t.id = c.trial_request_id
            LEFT JOIN subscriptions s ON s.trial_request_id = t.id
            WHERE c.id = ?
        ");
        $stmt->execute([$viewCampaignId]);
        $campaignDetail = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($campaignDetail) {
            $stmt2 = $db->prepare("
                SELECT * FROM drip_messages_re
                WHERE campaign_id = ?
                ORDER BY step_number ASC
            ");
            $stmt2->execute([$viewCampaignId]);
            $campaignMessages = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {}
}

// Fetch campaign list (only if not in detail view)
$campaigns = [];
$totalRecords = 0;
$totalPages = 1;

if (!$viewCampaignId) {
    try {
        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM drip_campaigns_re c
            JOIN trial_requests t ON t.id = c.trial_request_id
            {$whereClause}
        ");
        $countStmt->execute($params);
        $totalRecords = (int) $countStmt->fetchColumn();
        $totalPages = max(1, ceil($totalRecords / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT c.*, t.company_name, t.unit_count, t.trial_start_date, t.trial_end_date,
                   t.status AS trial_status, t.tenant_slug,
                   s.status AS sub_status, s.tier AS sub_tier,
                   (SELECT COUNT(*) FROM drip_messages_re WHERE campaign_id = c.id AND status = 'sent') AS msgs_sent,
                   (SELECT COUNT(*) FROM drip_messages_re WHERE campaign_id = c.id AND status = 'delivered') AS msgs_delivered,
                   (SELECT COUNT(*) FROM drip_messages_re WHERE campaign_id = c.id AND status = 'failed') AS msgs_failed
            FROM drip_campaigns_re c
            JOIN trial_requests t ON t.id = c.trial_request_id
            LEFT JOIN subscriptions s ON s.trial_request_id = t.id
            {$whereClause}
            ORDER BY c.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $campaigns = $stmt->fetchAll();
    } catch (PDOException $e) {}
}

require_once __DIR__ . '/includes/header.php';

$schedule = getDripSchedule();
$stepLabels = [
    1 => 'Welcome', 2 => 'Getting Started', 3 => 'Leases & Rent',
    4 => 'Reminders', 5 => 'Mid-Trial', 6 => 'Pro Features',
    7 => 'Testimonials', 8 => 'Urgency', 9 => 'Trial Expired',
    10 => 'Follow Up', 11 => 'Final'
];
?>

<?php if ($alert): ?>
<div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show py-2" role="alert">
    <?php echo $alert['msg']; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($campaignDetail): ?>
<!-- ============================================ -->
<!-- CAMPAIGN DETAIL VIEW -->
<!-- ============================================ -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <a href="drip.php" class="text-decoration-none text-muted" style="font-size:0.85rem;"><i class="bi bi-arrow-left me-1"></i>Back to Campaigns</a>
        <h4 class="fw-bold mb-0 mt-1"><i class="bi bi-send me-2"></i>Campaign #<?php echo $campaignDetail['id']; ?></h4>
    </div>
    <div class="d-flex gap-2">
        <?php if ($campaignDetail['status'] === 'active'): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Stop this campaign?');">
            <?php echo adminCsrfField(); ?>
            <input type="hidden" name="action" value="stop">
            <input type="hidden" name="campaign_id" value="<?php echo $campaignDetail['id']; ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-stop-circle me-1"></i>Stop Campaign</button>
        </form>
        <?php elseif ($campaignDetail['status'] === 'stopped'): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Resume this campaign?');">
            <?php echo adminCsrfField(); ?>
            <input type="hidden" name="action" value="resume">
            <input type="hidden" name="campaign_id" value="<?php echo $campaignDetail['id']; ?>">
            <button class="btn btn-sm btn-outline-success"><i class="bi bi-play-circle me-1"></i>Resume Campaign</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Campaign Info Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-muted mb-2" style="font-size:0.8rem;">LEAD INFO</h6>
                <p class="mb-1"><strong><?php echo htmlspecialchars($campaignDetail['name']); ?></strong></p>
                <p class="mb-1" style="font-size:0.85rem;">
                    <i class="bi bi-building me-1 text-muted"></i><?php echo htmlspecialchars($campaignDetail['company_name'] ?? '-'); ?>
                </p>
                <p class="mb-1" style="font-size:0.85rem;">
                    <i class="bi bi-telephone me-1 text-muted"></i><?php echo htmlspecialchars($campaignDetail['phone']); ?>
                </p>
                <?php if ($campaignDetail['email']): ?>
                <p class="mb-0" style="font-size:0.85rem;">
                    <i class="bi bi-envelope me-1 text-muted"></i><?php echo htmlspecialchars($campaignDetail['email']); ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-muted mb-2" style="font-size:0.8rem;">CAMPAIGN STATUS</h6>
                <?php
                $statusColors = ['active' => 'success', 'completed' => 'primary', 'stopped' => 'danger'];
                $sc = $statusColors[$campaignDetail['status']] ?? 'secondary';
                ?>
                <p class="mb-1"><span class="badge bg-<?php echo $sc; ?>"><?php echo ucfirst($campaignDetail['status']); ?></span></p>
                <p class="mb-1" style="font-size:0.85rem;">Step: <strong><?php echo $campaignDetail['current_step']; ?>/11</strong></p>
                <p class="mb-1" style="font-size:0.85rem;">Language: <strong><?php echo strtoupper($campaignDetail['language'] ?? 'SW'); ?></strong></p>
                <?php if ($campaignDetail['next_send_at']): ?>
                <p class="mb-0" style="font-size:0.85rem;">Next: <strong><?php echo date('d M Y H:i', strtotime($campaignDetail['next_send_at'])); ?></strong></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-muted mb-2" style="font-size:0.8rem;">TRIAL & SUBSCRIPTION</h6>
                <p class="mb-1" style="font-size:0.85rem;">
                    Trial:
                    <?php
                    $trialBadge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'expired' => 'secondary'];
                    ?>
                    <span class="badge bg-<?php echo $trialBadge[$campaignDetail['trial_status']] ?? 'secondary'; ?>">
                        <?php echo ucfirst($campaignDetail['trial_status']); ?>
                    </span>
                </p>
                <?php if ($campaignDetail['trial_start_date']): ?>
                <p class="mb-1" style="font-size:0.85rem;">Period: <?php echo date('d M', strtotime($campaignDetail['trial_start_date'])); ?> - <?php echo date('d M Y', strtotime($campaignDetail['trial_end_date'])); ?></p>
                <?php endif; ?>
                <p class="mb-1" style="font-size:0.85rem;">Units: <strong><?php echo $campaignDetail['unit_count']; ?></strong></p>
                <?php if ($campaignDetail['sub_status']): ?>
                <p class="mb-0" style="font-size:0.85rem;">
                    Subscription: <span class="badge bg-<?php echo $campaignDetail['sub_status'] === 'active' ? 'success' : 'warning'; ?>">
                        <?php echo ucfirst($campaignDetail['sub_status']); ?> (<?php echo ucfirst($campaignDetail['sub_tier']); ?>)
                    </span>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Timeline Visualization -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-bold"><i class="bi bi-clock-history me-1"></i> Campaign Timeline</div>
    <div class="card-body">
        <div class="position-relative" style="padding-left: 40px;">
            <?php foreach ($schedule as $step => $day): ?>
            <?php
            $msg = null;
            foreach ($campaignMessages as $m) {
                if ((int)$m['step_number'] === $step) { $msg = $m; break; }
            }
            $isCurrent = ($step === (int)$campaignDetail['current_step'] + 1);
            $isPast = ($step <= (int)$campaignDetail['current_step']);
            $isFuture = (!$isPast && !$isCurrent);

            if ($msg) {
                switch ($msg['status']) {
                    case 'delivered': $dotColor = '#16a34a'; $dotIcon = 'check-circle-fill'; break;
                    case 'sent': $dotColor = '#2563eb'; $dotIcon = 'check-circle'; break;
                    case 'failed': $dotColor = '#dc2626'; $dotIcon = 'x-circle-fill'; break;
                    case 'pending': $dotColor = $isCurrent ? '#e8a838' : '#9ca3af'; $dotIcon = 'circle'; break;
                    default: $dotColor = '#9ca3af'; $dotIcon = 'circle'; break;
                }
            } else {
                $dotColor = '#9ca3af'; $dotIcon = 'circle';
            }
            ?>
            <div class="d-flex mb-3 align-items-start" style="position: relative;">
                <!-- Timeline line -->
                <?php if ($step < 11): ?>
                <div style="position:absolute; left:-22px; top:20px; bottom:-12px; width:2px; background:<?php echo $isPast ? '#16a34a' : '#e5e7eb'; ?>;"></div>
                <?php endif; ?>
                <!-- Dot -->
                <div style="position:absolute; left:-30px; top:2px; z-index:1;">
                    <i class="bi bi-<?php echo $dotIcon; ?>" style="font-size:1.1rem; color:<?php echo $dotColor; ?>; background:white; border-radius:50%;"></i>
                </div>
                <!-- Content -->
                <div class="flex-grow-1 ms-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong style="font-size:0.88rem;">Step <?php echo $step; ?>: <?php echo $stepLabels[$step] ?? "Step {$step}"; ?></strong>
                            <small class="text-muted ms-2">Day <?php echo $day; ?></small>
                            <?php if ($isCurrent && $campaignDetail['status'] === 'active'): ?>
                            <span class="badge bg-warning text-dark ms-1" style="font-size:0.7rem;">NEXT</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($msg): ?>
                                <?php if ($msg['status'] === 'delivered'): ?>
                                <span class="badge bg-success" style="font-size:0.72rem;">Delivered</span>
                                <?php elseif ($msg['status'] === 'sent'): ?>
                                <span class="badge bg-primary" style="font-size:0.72rem;">Sent</span>
                                <?php elseif ($msg['status'] === 'failed'): ?>
                                <span class="badge bg-danger" style="font-size:0.72rem;">Failed</span>
                                <!-- Resend button -->
                                <form method="POST" style="display:inline;" class="ms-1">
                                    <?php echo adminCsrfField(); ?>
                                    <input type="hidden" name="action" value="resend">
                                    <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:0.72rem;" title="Resend">
                                        <i class="bi bi-arrow-clockwise"></i> Resend
                                    </button>
                                </form>
                                <?php elseif ($msg['status'] === 'pending'): ?>
                                <span class="badge bg-secondary" style="font-size:0.72rem;">Pending</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($msg && $msg['sent_at']): ?>
                    <small class="text-muted">Sent: <?php echo date('d M Y H:i', strtotime($msg['sent_at'])); ?></small>
                    <?php if ($msg['delivered_at']): ?>
                    <small class="text-success ms-2">Delivered: <?php echo date('d M Y H:i', strtotime($msg['delivered_at'])); ?></small>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($msg && $msg['status'] === 'failed' && $msg['last_error']): ?>
                    <br><small class="text-danger">Error: <?php echo htmlspecialchars($msg['last_error']); ?> (retries: <?php echo $msg['retry_count']; ?>)</small>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Messages Detail Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-bold"><i class="bi bi-list-check me-1"></i> Message Details</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead style="background:#f8f9fa;">
                <tr>
                    <th>Step</th>
                    <th>Day</th>
                    <th>Label</th>
                    <th>Status</th>
                    <th>Sent At</th>
                    <th>Delivered</th>
                    <th>Retries</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($campaignMessages as $msg): ?>
                <tr>
                    <td><?php echo $msg['step_number']; ?></td>
                    <td><small class="text-muted">Day <?php echo $msg['send_after_days']; ?></small></td>
                    <td><small><?php echo $stepLabels[(int)$msg['step_number']] ?? '-'; ?></small></td>
                    <td>
                        <?php
                        $sBadge = ['pending' => 'secondary', 'sent' => 'primary', 'delivered' => 'success', 'failed' => 'danger'];
                        ?>
                        <span class="badge bg-<?php echo $sBadge[$msg['status']] ?? 'secondary'; ?>" style="font-size:0.72rem;">
                            <?php echo ucfirst($msg['status']); ?>
                        </span>
                    </td>
                    <td><small><?php echo $msg['sent_at'] ? date('d M H:i', strtotime($msg['sent_at'])) : '-'; ?></small></td>
                    <td><small><?php echo $msg['delivered_at'] ? date('d M H:i', strtotime($msg['delivered_at'])) : ($msg['delivery_status'] ?? '-'); ?></small></td>
                    <td><small><?php echo $msg['retry_count']; ?></small></td>
                    <td>
                        <?php if ($msg['status'] === 'failed'): ?>
                        <form method="POST" style="display:inline;">
                            <?php echo adminCsrfField(); ?>
                            <input type="hidden" name="action" value="resend">
                            <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size:0.75rem;">
                                <i class="bi bi-arrow-clockwise"></i> Resend
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- ============================================ -->
<!-- CAMPAIGN LIST VIEW -->
<!-- ============================================ -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h4 class="fw-bold mb-0"><i class="bi bi-send me-2"></i>Drip Campaigns</h4>
    <div class="d-flex gap-2">
        <form class="d-flex gap-2" method="GET">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
            <div class="input-group input-group-sm" style="width:250px;">
                <input type="text" class="form-control" name="search" placeholder="Search name, phone, company..." value="<?php echo htmlspecialchars($search); ?>">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
</div>

<!-- Status Filter Tabs -->
<div class="d-flex gap-1 mb-3 flex-wrap">
    <?php
    $statuses = ['all' => 'All', 'active' => 'Active', 'completed' => 'Completed', 'stopped' => 'Stopped'];
    foreach ($statuses as $sKey => $sLabel):
        $count = $statusCounts[$sKey] ?? 0;
        $isActive = ($statusFilter === $sKey);
    ?>
    <a href="?status=<?php echo $sKey; ?>&search=<?php echo urlencode($search); ?>"
       class="btn btn-sm <?php echo $isActive ? 'btn-dark' : 'btn-outline-secondary'; ?>">
        <?php echo $sLabel; ?>
        <span class="badge bg-<?php echo $isActive ? 'light text-dark' : 'secondary'; ?> ms-1"><?php echo $count; ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Campaigns Table -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead style="background:#f8f9fa;">
                <tr>
                    <th>#</th>
                    <th>Lead</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Progress</th>
                    <th>Messages</th>
                    <th>Next Send</th>
                    <th>Started</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($campaigns)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No campaigns found.</td></tr>
                <?php else: ?>
                <?php foreach ($campaigns as $c): ?>
                <tr>
                    <td class="text-muted"><?php echo $c['id']; ?></td>
                    <td>
                        <strong style="font-size:0.88rem;"><?php echo htmlspecialchars($c['name']); ?></strong>
                        <?php if ($c['company_name']): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($c['company_name']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><small><?php echo htmlspecialchars($c['phone']); ?></small></td>
                    <td>
                        <?php
                        $sColors = ['active' => 'success', 'completed' => 'primary', 'stopped' => 'danger'];
                        ?>
                        <span class="badge bg-<?php echo $sColors[$c['status']] ?? 'secondary'; ?>" style="font-size:0.72rem;">
                            <?php echo ucfirst($c['status']); ?>
                        </span>
                        <br>
                        <small class="text-muted"><?php echo strtoupper($c['language'] ?? 'SW'); ?></small>
                    </td>
                    <td>
                        <?php
                        $stepPct = $c['current_step'] > 0 ? round(($c['current_step'] / 11) * 100) : 0;
                        ?>
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:6px; min-width:60px;">
                                <div class="progress-bar bg-success" style="width:<?php echo $stepPct; ?>%"></div>
                            </div>
                            <small class="text-muted"><?php echo $c['current_step']; ?>/11</small>
                        </div>
                    </td>
                    <td>
                        <small>
                            <?php if ($c['msgs_sent'] > 0): ?>
                            <span class="text-primary"><?php echo $c['msgs_sent']; ?> sent</span>
                            <?php endif; ?>
                            <?php if ($c['msgs_delivered'] > 0): ?>
                            <span class="text-success ms-1"><?php echo $c['msgs_delivered']; ?> dlvd</span>
                            <?php endif; ?>
                            <?php if ($c['msgs_failed'] > 0): ?>
                            <span class="text-danger ms-1"><?php echo $c['msgs_failed']; ?> fail</span>
                            <?php endif; ?>
                            <?php if ($c['msgs_sent'] + $c['msgs_delivered'] + $c['msgs_failed'] === 0): ?>
                            <span class="text-muted">No messages yet</span>
                            <?php endif; ?>
                        </small>
                    </td>
                    <td>
                        <small>
                            <?php if ($c['next_send_at'] && $c['status'] === 'active'): ?>
                            <?php echo date('d M H:i', strtotime($c['next_send_at'])); ?>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </small>
                    </td>
                    <td><small><?php echo date('d M Y', strtotime($c['created_at'])); ?></small></td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="?view=<?php echo $c['id']; ?>" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.75rem;" title="View Details">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if ($c['status'] === 'active'): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Stop campaign for <?php echo htmlspecialchars(addslashes($c['name'])); ?>?');">
                                <?php echo adminCsrfField(); ?>
                                <input type="hidden" name="action" value="stop">
                                <input type="hidden" name="campaign_id" value="<?php echo $c['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:0.75rem;" title="Stop">
                                    <i class="bi bi-stop-circle"></i>
                                </button>
                            </form>
                            <?php elseif ($c['status'] === 'stopped'): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Resume campaign?');">
                                <?php echo adminCsrfField(); ?>
                                <input type="hidden" name="action" value="resume">
                                <input type="hidden" name="campaign_id" value="<?php echo $c['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:0.75rem;" title="Resume">
                                    <i class="bi bi-play-circle"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <small class="text-muted">Showing <?php echo ($page - 1) * $perPage + 1; ?>-<?php echo min($page * $perPage, $totalRecords); ?> of <?php echo $totalRecords; ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php if ($page > 1): ?>
                <li class="page-item"><a class="page-link" href="?status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">&laquo;</a></li>
                <?php endif; ?>
                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $p; ?>"><?php echo $p; ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                <li class="page-item"><a class="page-link" href="?status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">&raquo;</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
