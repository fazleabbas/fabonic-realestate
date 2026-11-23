<?php
/**
 * FIS Real Estate — Reminder Delivery Log (Part 17.5)
 * View delivery history for reminders and receipts. Professional+ tier required.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('rem_delivery_log', 'Delivery Log');

$subStatus = enforceSubscription();

if (!hasTier('professional')) {
    setAlert(__('rem_tier_required', 'Professional tier or higher required for reminders.'), 'warning');
    redirect('index.php');
}
if (getSetting('reminders_enabled', '0') !== '1') {
    setAlert(__('rem_disabled', 'Reminders module is disabled. Enable it in Settings > Modules.'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('settings', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// --- Filters ---
$tab = $_GET['tab'] ?? 'reminders'; // reminders | receipts
$filterStatus = $_GET['status'] ?? '';
$filterChannel = $_GET['channel'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

// --- Stats ---
$reminderStats = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END), 0) AS sent,
        COALESCE(SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END), 0) AS delivered,
        COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
        COALESCE(SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END), 0) AS scheduled
    FROM reminder_schedule
")->fetch();

$receiptStats = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END), 0) AS sent,
        COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending
    FROM receipt_messages
")->fetch();

// --- Query based on tab ---
if ($tab === 'receipts') {
    $where = "1=1";
    $params = [];

    if ($filterStatus) {
        $where .= " AND rm.status = :status";
        $params['status'] = $filterStatus;
    }
    if ($filterChannel) {
        $where .= " AND rm.channel = :channel";
        $params['channel'] = $filterChannel;
    }
    if ($filterDateFrom) {
        $where .= " AND DATE(rm.created_at) >= :date_from";
        $params['date_from'] = $filterDateFrom;
    }
    if ($filterDateTo) {
        $where .= " AND DATE(rm.created_at) <= :date_to";
        $params['date_to'] = $filterDateTo;
    }
    if ($search) {
        $where .= " AND (t.first_name LIKE :s OR t.last_name LIKE :s2 OR rm.recipient_phone LIKE :s3 OR rm.recipient_email LIKE :s4)";
        $params['s'] = "%$search%";
        $params['s2'] = "%$search%";
        $params['s3'] = "%$search%";
        $params['s4'] = "%$search%";
    }

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM receipt_messages rm LEFT JOIN tenants t ON rm.tenant_id = t.id WHERE $where");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();
    $pagination = paginate($totalRecords, $page, $perPage);

    $stmt = $conn->prepare("
        SELECT rm.*, t.first_name, t.last_name, t.phone AS tenant_phone, t.email AS tenant_email,
               p.receipt_number
        FROM receipt_messages rm
        LEFT JOIN tenants t ON rm.tenant_id = t.id
        LEFT JOIN receipts p ON rm.receipt_id = p.id
        WHERE $where
        ORDER BY rm.created_at DESC
        LIMIT {$pagination['records_per_page']} OFFSET {$pagination['offset']}
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll();
} else {
    $where = "1=1";
    $params = [];

    if ($filterStatus) {
        $where .= " AND rs.status = :status";
        $params['status'] = $filterStatus;
    }
    if ($filterChannel) {
        $where .= " AND rs.channel = :channel";
        $params['channel'] = $filterChannel;
    }
    if ($filterDateFrom) {
        $where .= " AND rs.scheduled_date >= :date_from";
        $params['date_from'] = $filterDateFrom;
    }
    if ($filterDateTo) {
        $where .= " AND rs.scheduled_date <= :date_to";
        $params['date_to'] = $filterDateTo;
    }
    if ($search) {
        $where .= " AND (t.first_name LIKE :s OR t.last_name LIKE :s2 OR rs.recipient_phone LIKE :s3 OR rs.recipient_email LIKE :s4)";
        $params['s'] = "%$search%";
        $params['s2'] = "%$search%";
        $params['s3'] = "%$search%";
        $params['s4'] = "%$search%";
    }

    $countStmt = $conn->prepare("
        SELECT COUNT(*) FROM reminder_schedule rs
        LEFT JOIN tenants t ON rs.tenant_id = t.id
        WHERE $where
    ");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();
    $pagination = paginate($totalRecords, $page, $perPage);

    $stmt = $conn->prepare("
        SELECT rs.*, rt.name AS template_name, rt.trigger_type, rt.days_offset,
               t.first_name, t.last_name, t.phone AS tenant_phone, t.email AS tenant_email,
               u.unit_number, pr.name AS property_name
        FROM reminder_schedule rs
        LEFT JOIN reminder_templates rt ON rs.template_id = rt.id
        LEFT JOIN tenants t ON rs.tenant_id = t.id
        LEFT JOIN leases l ON rs.lease_id = l.id
        LEFT JOIN units u ON l.unit_id = u.id
        LEFT JOIN properties pr ON u.property_id = pr.id
        WHERE $where
        ORDER BY rs.scheduled_date DESC
        LIMIT {$pagination['records_per_page']} OFFSET {$pagination['offset']}
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll();
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-primary mb-1"><i class="bi bi-send-check" style="font-size:1.5rem;"></i></div>
                <div class="fw-bold fs-5"><?php echo number_format($reminderStats['sent'] + $reminderStats['delivered']); ?></div>
                <small class="text-muted"><?php _e('rem_log_sent', 'Reminders Sent'); ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-warning mb-1"><i class="bi bi-clock-history" style="font-size:1.5rem;"></i></div>
                <div class="fw-bold fs-5"><?php echo number_format($reminderStats['scheduled']); ?></div>
                <small class="text-muted"><?php _e('rem_log_scheduled', 'Scheduled'); ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-success mb-1"><i class="bi bi-receipt" style="font-size:1.5rem;"></i></div>
                <div class="fw-bold fs-5"><?php echo number_format($receiptStats['sent']); ?></div>
                <small class="text-muted"><?php _e('rem_log_receipts_sent', 'Receipts Sent'); ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-danger mb-1"><i class="bi bi-exclamation-triangle" style="font-size:1.5rem;"></i></div>
                <div class="fw-bold fs-5"><?php echo number_format($reminderStats['failed'] + $receiptStats['failed']); ?></div>
                <small class="text-muted"><?php _e('rem_log_failed', 'Failed'); ?></small>
            </div>
        </div>
    </div>
</div>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'reminders' ? 'active' : ''; ?>"
           href="?tab=reminders"><?php _e('rem_log_tab_reminders', 'Reminders'); ?>
            <span class="badge bg-secondary ms-1"><?php echo number_format($reminderStats['total']); ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'receipts' ? 'active' : ''; ?>"
           href="?tab=receipts"><?php _e('rem_log_tab_receipts', 'Receipts'); ?>
            <span class="badge bg-secondary ms-1"><?php echo number_format($receiptStats['total']); ?></span>
        </a>
    </li>
</ul>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">

            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:0.78rem;"><?php _e('search'); ?></label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="<?php _e('rem_log_search_ph', 'Name, phone, email...'); ?>" value="<?php echo htmlspecialchars($search); ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:0.78rem;"><?php _e('status'); ?></label>
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all_statuses'); ?></option>
                    <?php if ($tab === 'reminders'): ?>
                    <option value="scheduled" <?php echo $filterStatus === 'scheduled' ? 'selected' : ''; ?>><?php _e('rem_scheduled', 'Scheduled'); ?></option>
                    <option value="sent" <?php echo $filterStatus === 'sent' ? 'selected' : ''; ?>><?php _e('rem_sent', 'Sent'); ?></option>
                    <option value="delivered" <?php echo $filterStatus === 'delivered' ? 'selected' : ''; ?>><?php _e('rem_delivered', 'Delivered'); ?></option>
                    <option value="failed" <?php echo $filterStatus === 'failed' ? 'selected' : ''; ?>><?php _e('rem_failed', 'Failed'); ?></option>
                    <option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>><?php _e('cancelled'); ?></option>
                    <?php else: ?>
                    <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>><?php _e('pending'); ?></option>
                    <option value="sent" <?php echo $filterStatus === 'sent' ? 'selected' : ''; ?>><?php _e('rem_sent', 'Sent'); ?></option>
                    <option value="failed" <?php echo $filterStatus === 'failed' ? 'selected' : ''; ?>><?php _e('rem_failed', 'Failed'); ?></option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:0.78rem;"><?php _e('rem_channel', 'Channel'); ?></label>
                <select name="channel" class="form-select form-select-sm">
                    <option value=""><?php _e('all'); ?></option>
                    <option value="whatsapp" <?php echo $filterChannel === 'whatsapp' ? 'selected' : ''; ?>>WhatsApp</option>
                    <option value="sms" <?php echo $filterChannel === 'sms' ? 'selected' : ''; ?>>SMS</option>
                    <option value="email" <?php echo $filterChannel === 'email' ? 'selected' : ''; ?>>Email</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:0.78rem;"><?php _e('from_date', 'From'); ?></label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filterDateFrom); ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:0.78rem;"><?php _e('to_date', 'To'); ?></label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filterDateTo); ?>">
            </div>

            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="?tab=<?php echo $tab; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Navigation -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="<?php echo BASE_URL; ?>modules/settings/reminders.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-arrow-left me-1"></i><?php _e('rem_templates', 'Reminder Templates'); ?>
        </a>
    </div>
    <div class="text-muted" style="font-size:0.85rem;">
        <?php echo number_format($totalRecords); ?> <?php _e('records'); ?>
    </div>
</div>

<!-- Table -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:0.85rem;">
            <thead class="table-light">
                <tr>
                    <th style="width:40px;">#</th>
                    <th><?php _e('tenant'); ?></th>
                    <?php if ($tab === 'reminders'): ?>
                    <th><?php _e('rem_template', 'Template'); ?></th>
                    <th><?php _e('rem_trigger', 'Trigger'); ?></th>
                    <th><?php _e('property'); ?> / <?php _e('unit'); ?></th>
                    <th><?php _e('rem_scheduled_date', 'Scheduled'); ?></th>
                    <?php else: ?>
                    <th><?php _e('rem_receipt_no', 'Receipt #'); ?></th>
                    <th><?php _e('rem_created', 'Created'); ?></th>
                    <?php endif; ?>
                    <th><?php _e('rem_channel', 'Channel'); ?></th>
                    <th><?php _e('rem_recipient', 'Recipient'); ?></th>
                    <th><?php _e('status'); ?></th>
                    <th><?php _e('rem_sent_at', 'Sent At'); ?></th>
                    <th style="width:80px;"><?php _e('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                <tr><td colspan="<?php echo $tab === 'reminders' ? 11 : 9; ?>" class="text-center text-muted py-4"><?php _e('no_records'); ?></td></tr>
                <?php else: ?>
                <?php foreach ($records as $i => $rec): ?>
                <tr>
                    <td class="text-muted"><?php echo $pagination['offset'] + $i + 1; ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars(trim(($rec['first_name'] ?? '') . ' ' . ($rec['last_name'] ?? ''))); ?></strong>
                    </td>

                    <?php if ($tab === 'reminders'): ?>
                    <td><?php echo htmlspecialchars($rec['template_name'] ?? '-'); ?></td>
                    <td>
                        <?php
                        $triggerType = $rec['trigger_type'] ?? '';
                        $daysOffset = (int)($rec['days_offset'] ?? 0);
                        if ($triggerType === 'before_due') {
                            $badge = 'info';
                            $label = $daysOffset . 'd before';
                        } elseif ($triggerType === 'on_due') {
                            $badge = 'primary';
                            $label = 'On due';
                        } else {
                            $badge = 'warning';
                            $label = $daysOffset . 'd overdue';
                        }
                        ?>
                        <span class="badge bg-<?php echo $badge; ?>"><?php echo $label; ?></span>
                    </td>
                    <td>
                        <?php echo htmlspecialchars(($rec['property_name'] ?? '') . ' — ' . ($rec['unit_number'] ?? '')); ?>
                    </td>
                    <td><?php echo formatDate($rec['scheduled_date'] ?? ''); ?></td>
                    <?php else: ?>
                    <td><?php echo htmlspecialchars($rec['receipt_number'] ?? '-'); ?></td>
                    <td><?php echo formatDate($rec['created_at'] ?? ''); ?></td>
                    <?php endif; ?>

                    <td>
                        <?php
                        $ch = $rec['channel'] ?? '';
                        $chIcon = $ch === 'whatsapp' ? 'bi-whatsapp text-success' : ($ch === 'email' ? 'bi-envelope text-primary' : 'bi-chat-dots text-info');
                        ?>
                        <i class="bi <?php echo $chIcon; ?>"></i>
                        <span class="text-capitalize"><?php echo $ch; ?></span>
                    </td>
                    <td style="font-size:0.8rem;">
                        <?php echo htmlspecialchars($rec['recipient_phone'] ?? $rec['recipient_email'] ?? '-'); ?>
                    </td>
                    <td>
                        <?php
                        $st = $rec['status'] ?? '';
                        $stColors = [
                            'scheduled' => 'secondary',
                            'pending' => 'secondary',
                            'sent' => 'success',
                            'delivered' => 'primary',
                            'failed' => 'danger',
                            'cancelled' => 'dark'
                        ];
                        ?>
                        <span class="badge bg-<?php echo $stColors[$st] ?? 'light'; ?>"><?php echo ucfirst($st); ?></span>
                        <?php if ($st === 'failed' && !empty($rec['error_message'])): ?>
                        <i class="bi bi-info-circle text-danger ms-1" data-bs-toggle="tooltip" title="<?php echo htmlspecialchars($rec['error_message']); ?>"></i>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.8rem;">
                        <?php echo ($rec['actual_sent_date'] ?? $rec['sent_at'] ?? '') ? formatDate($rec['actual_sent_date'] ?? $rec['sent_at'] ?? '') : '-'; ?>
                    </td>
                    <td>
                        <?php if ($st === 'failed'): ?>
                        <button class="btn btn-outline-warning btn-sm py-0 px-1" onclick="retryMessage(<?php echo $rec['id']; ?>, '<?php echo $tab; ?>')" title="<?php _e('rem_retry', 'Retry'); ?>">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                        <?php endif; ?>
                        <?php if ($tab === 'reminders' && $st === 'scheduled'): ?>
                        <button class="btn btn-outline-danger btn-sm py-0 px-1" onclick="cancelSchedule(<?php echo $rec['id']; ?>)" title="<?php _e('cancel'); ?>">
                            <i class="bi bi-x-lg"></i>
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-outline-info btn-sm py-0 px-1" onclick="viewMessage(<?php echo $rec['id']; ?>, '<?php echo $tab; ?>')" title="<?php _e('rem_view_message', 'View Message'); ?>">
                            <i class="bi bi-eye"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($pagination['total_pages'] > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php
        $qp = $_GET;
        if ($pagination['has_prev']):
            $qp['page'] = $page - 1;
        ?>
        <li class="page-item"><a class="page-link" href="?<?php echo http_build_query($qp); ?>">&laquo;</a></li>
        <?php endif; ?>

        <?php
        $start = max(1, $page - 2);
        $end = min($pagination['total_pages'], $page + 2);
        for ($p = $start; $p <= $end; $p++):
            $qp['page'] = $p;
        ?>
        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query($qp); ?>"><?php echo $p; ?></a>
        </li>
        <?php endfor; ?>

        <?php if ($pagination['has_next']):
            $qp['page'] = $page + 1;
        ?>
        <li class="page-item"><a class="page-link" href="?<?php echo http_build_query($qp); ?>">&raquo;</a></li>
        <?php endif; ?>
    </ul>
</nav>
<?php endif; ?>

<!-- View Message Modal -->
<div class="modal fade" id="messageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><?php _e('rem_message_preview', 'Message Preview'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="messageContent" class="p-3 bg-light rounded" style="white-space:pre-wrap;font-size:0.9rem;"></div>
                <div class="mt-3">
                    <small class="text-muted">
                        <strong><?php _e('rem_recipient', 'Recipient'); ?>:</strong>
                        <span id="messageRecipient"></span>
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php _e('close'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
const API_URL = BASE_URL + 'api/reminders.php';

function retryMessage(id, type) {
    if (!confirm('<?php _e('rem_retry_confirm', 'Retry sending this message?'); ?>')) return;

    const action = type === 'receipts' ? 'retry_receipt' : 'retry_failed';
    const formData = new FormData();
    formData.append('action', action);
    formData.append('id', id);
    formData.append('csrf_token', '<?php echo generateCSRF(); ?>');

    $.post(API_URL, { action: action, id: id, csrf_token: '<?php echo generateCSRF(); ?>' }, function(res) {
        if (res.success) {
            location.reload();
        } else {
            alert(res.message || 'Failed');
        }
    }, 'json').fail(function() { alert('Request failed'); });
}

function cancelSchedule(id) {
    if (!confirm('<?php _e('rem_cancel_confirm', 'Cancel this scheduled reminder?'); ?>')) return;

    $.post(API_URL, { action: 'cancel_schedule', id: id, csrf_token: '<?php echo generateCSRF(); ?>' }, function(res) {
        if (res.success) {
            location.reload();
        } else {
            alert(res.message || 'Failed');
        }
    }, 'json').fail(function() { alert('Request failed'); });
}

function viewMessage(id, type) {
    if (type === 'receipts') {
        $.get(API_URL, { action: 'receipt_log', id: id }, function(res) {
            if (res.success && res.data && res.data.length > 0) {
                const msg = res.data[0];
                $('#messageContent').text(msg.message_body || '(empty)');
                $('#messageRecipient').text(msg.recipient_phone || msg.recipient_email || '-');
                new bootstrap.Modal($('#messageModal')[0]).show();
            }
        }, 'json');
    } else {
        // For reminders, fetch single schedule entry
        $.get(API_URL, { action: 'list_schedule', id: id }, function(res) {
            if (res.success && res.data && res.data.length > 0) {
                const msg = res.data[0];
                $('#messageContent').text(msg.message_body || '(not yet sent)');
                $('#messageRecipient').text(msg.recipient_phone || msg.recipient_email || '-');
                new bootstrap.Modal($('#messageModal')[0]).show();
            }
        }, 'json');
    }
}

// Init tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltips = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltips.forEach(function(el) { new bootstrap.Tooltip(el); });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
