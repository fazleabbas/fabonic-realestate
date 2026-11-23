<?php
/**
 * FIS Real Estate — Reminders API (Part 17)
 * Handles: template CRUD, schedule management, delivery log, receipt sending, stats.
 * Professional+ tier required.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/charges.php';
require_once __DIR__ . '/../includes/subscription.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

// Check tier and module
if (!hasTier('professional')) {
    jsonResponse(['success' => false, 'message' => 'Professional tier or higher required'], 403);
}
if (getSetting('reminders_enabled', '0') !== '1') {
    jsonResponse(['success' => false, 'message' => 'Reminders module is disabled'], 403);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // TEMPLATES — LIST
    // ============================
    case 'list_templates':
        if (!hasPermission('settings', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("SELECT * FROM reminder_templates ORDER BY trigger_type, days_offset ASC");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // TEMPLATES — GET
    // ============================
    case 'get_template':
        if (!hasPermission('settings', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM reminder_templates WHERE id = ?");
        $stmt->execute([$id]);
        $template = $stmt->fetch();
        if (!$template) {
            jsonResponse(['success' => false, 'message' => 'Template not found'], 404);
        }
        jsonResponse(['success' => true, 'data' => $template]);
        break;

    // ============================
    // TEMPLATES — CREATE
    // ============================
    case 'create_template':
        if (!hasPermission('settings', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $name = trim($_POST['name'] ?? '');
        $triggerType = trim($_POST['trigger_type'] ?? '');
        $daysOffset = (int)($_POST['days_offset'] ?? 0);
        $repeatInterval = (int)($_POST['repeat_interval_days'] ?? 0);
        $channel = trim($_POST['channel'] ?? 'whatsapp');
        $messageTemplate = trim($_POST['message_template'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $validTriggers = ['before_due', 'on_due', 'overdue'];
        $validChannels = ['whatsapp', 'sms', 'email'];

        if (empty($name)) jsonResponse(['success' => false, 'message' => __('rem_name_required')]);
        if (!in_array($triggerType, $validTriggers)) jsonResponse(['success' => false, 'message' => __('rem_invalid_trigger')]);
        if (!in_array($channel, $validChannels)) jsonResponse(['success' => false, 'message' => __('rem_invalid_channel')]);
        if (empty($messageTemplate)) jsonResponse(['success' => false, 'message' => __('rem_message_required')]);

        $stmt = $conn->prepare("
            INSERT INTO reminder_templates (name, trigger_type, days_offset, repeat_interval_days, channel, message_template, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $triggerType, $daysOffset, $repeatInterval, $channel, $messageTemplate, $isActive]);
        $newId = (int)$conn->lastInsertId();

        logActivity('created', 'reminder_template', $newId, ['name' => $name, 'trigger_type' => $triggerType]);
        jsonResponse(['success' => true, 'message' => __('rem_template_created'), 'id' => $newId]);
        break;

    // ============================
    // TEMPLATES — UPDATE
    // ============================
    case 'update_template':
        if (!hasPermission('settings', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $triggerType = trim($_POST['trigger_type'] ?? '');
        $daysOffset = (int)($_POST['days_offset'] ?? 0);
        $repeatInterval = (int)($_POST['repeat_interval_days'] ?? 0);
        $channel = trim($_POST['channel'] ?? 'whatsapp');
        $messageTemplate = trim($_POST['message_template'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $validTriggers = ['before_due', 'on_due', 'overdue'];
        $validChannels = ['whatsapp', 'sms', 'email'];

        if (!$id) jsonResponse(['success' => false, 'message' => 'Template ID required']);
        if (empty($name)) jsonResponse(['success' => false, 'message' => __('rem_name_required')]);
        if (!in_array($triggerType, $validTriggers)) jsonResponse(['success' => false, 'message' => __('rem_invalid_trigger')]);
        if (!in_array($channel, $validChannels)) jsonResponse(['success' => false, 'message' => __('rem_invalid_channel')]);
        if (empty($messageTemplate)) jsonResponse(['success' => false, 'message' => __('rem_message_required')]);

        $stmt = $conn->prepare("
            UPDATE reminder_templates
            SET name = ?, trigger_type = ?, days_offset = ?, repeat_interval_days = ?,
                channel = ?, message_template = ?, is_active = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $triggerType, $daysOffset, $repeatInterval, $channel, $messageTemplate, $isActive, $id]);

        logActivity('updated', 'reminder_template', $id, ['name' => $name]);
        jsonResponse(['success' => true, 'message' => __('rem_template_updated')]);
        break;

    // ============================
    // TEMPLATES — DELETE
    // ============================
    case 'delete_template':
        if (!hasPermission('settings', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'Template ID required']);

        $conn->prepare("DELETE FROM reminder_templates WHERE id = ?")->execute([$id]);
        logActivity('deleted', 'reminder_template', $id);
        jsonResponse(['success' => true, 'message' => __('rem_template_deleted')]);
        break;

    // ============================
    // TEMPLATES — TOGGLE ACTIVE
    // ============================
    case 'toggle_template':
        if (!hasPermission('settings', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE reminder_templates SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$id]);

        logActivity('updated', 'reminder_template', $id, ['toggled' => true]);
        jsonResponse(['success' => true, 'message' => __('rem_template_toggled')]);
        break;

    // ============================
    // SCHEDULE — GENERATE
    // ============================
    case 'generate_schedule':
        if (!hasPermission('settings', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $result = generateReminderSchedule($conn);
        logActivity('created', 'reminder_schedule', null, ['generated' => $result['created']]);
        jsonResponse(['success' => true, 'message' => $result['created'] . ' reminder(s) scheduled', 'data' => $result]);
        break;

    // ============================
    // SCHEDULE — LIST
    // ============================
    case 'list_schedule':
        if (!hasPermission('settings', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $status = trim($_GET['status'] ?? '');
        $channel = trim($_GET['channel'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');

        $where = "1=1";
        $params = [];

        if ($status) {
            $where .= " AND rs.status = :status";
            $params['status'] = $status;
        }
        if ($channel) {
            $where .= " AND rs.channel = :channel";
            $params['channel'] = $channel;
        }
        if ($dateFrom) {
            $where .= " AND rs.scheduled_date >= :df";
            $params['df'] = $dateFrom;
        }
        if ($dateTo) {
            $where .= " AND rs.scheduled_date <= :dt";
            $params['dt'] = $dateTo;
        }

        $countStmt = $conn->prepare("SELECT COUNT(*) FROM reminder_schedule rs WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pagination = paginate($total, $page);

        $sql = "SELECT rs.*, t.first_name, t.last_name, t.phone AS tenant_phone,
                       rt.name AS template_name, rt.trigger_type,
                       u.unit_number, p.name AS property_name
                FROM reminder_schedule rs
                INNER JOIN tenants t ON rs.tenant_id = t.id
                INNER JOIN reminder_templates rt ON rs.template_id = rt.id
                INNER JOIN leases l ON rs.lease_id = l.id
                INNER JOIN units u ON l.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                WHERE {$where}
                ORDER BY rs.scheduled_date DESC, rs.id DESC
                LIMIT :offset, :limit";
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $pagination['records_per_page'], PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(), 'pagination' => $pagination]);
        break;

    // ============================
    // SCHEDULE — CANCEL
    // ============================
    case 'cancel_schedule':
        if (!hasPermission('settings', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE reminder_schedule SET status = 'cancelled' WHERE id = ? AND status = 'scheduled'");
        $stmt->execute([$id]);

        logActivity('updated', 'reminder_schedule', $id, ['cancelled' => true]);
        jsonResponse(['success' => true, 'message' => __('rem_cancelled')]);
        break;

    // ============================
    // SCHEDULE — RETRY FAILED
    // ============================
    case 'retry_failed':
        if (!hasPermission('settings', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE reminder_schedule SET status = 'scheduled', error_message = NULL WHERE id = ? AND status = 'failed'");
        $stmt->execute([$id]);

        logActivity('updated', 'reminder_schedule', $id, ['retried' => true]);
        jsonResponse(['success' => true, 'message' => __('rem_retry_queued')]);
        break;

    // ============================
    // RECEIPT LOG — LIST
    // ============================
    case 'receipt_log':
        if (!hasPermission('settings', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $status = trim($_GET['status'] ?? '');

        $where = "1=1";
        $params = [];
        if ($status) {
            $where .= " AND rm.status = :status";
            $params['status'] = $status;
        }

        $countStmt = $conn->prepare("SELECT COUNT(*) FROM receipt_messages rm WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pagination = paginate($total, $page);

        $sql = "SELECT rm.*, t.first_name, t.last_name, t.phone AS tenant_phone,
                       r.receipt_number, p2.amount AS payment_amount
                FROM receipt_messages rm
                INNER JOIN tenants t ON rm.tenant_id = t.id
                LEFT JOIN receipts r ON rm.receipt_id = r.id
                LEFT JOIN payments p2 ON rm.payment_id = p2.id
                WHERE {$where}
                ORDER BY rm.created_at DESC
                LIMIT :offset, :limit";
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $pagination['records_per_page'], PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(), 'pagination' => $pagination]);
        break;

    // ============================
    // RECEIPT — RETRY FAILED
    // ============================
    case 'retry_receipt':
        if (!hasPermission('settings', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE receipt_messages SET status = 'pending', error_message = NULL WHERE id = ? AND status = 'failed'");
        $stmt->execute([$id]);

        // Attempt to re-send now
        $msg = $conn->prepare("SELECT * FROM receipt_messages WHERE id = ?")->fetch();
        if ($msg) {
            sendReceiptMessage($conn, $id);
        }

        jsonResponse(['success' => true, 'message' => __('rem_retry_queued')]);
        break;

    // ============================
    // SEND RECEIPT (manual trigger)
    // ============================
    case 'send_receipt':
        if (!hasPermission('payments', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $paymentId = (int)($_POST['payment_id'] ?? 0);
        if (!$paymentId) {
            jsonResponse(['success' => false, 'message' => 'Payment ID required']);
        }

        $result = queueReceiptMessage($conn, $paymentId);
        jsonResponse($result);
        break;

    // ============================
    // STATS
    // ============================
    case 'stats':
        if (!hasPermission('settings', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stats = [];

        // Template count
        $stats['templates'] = (int)$conn->query("SELECT COUNT(*) FROM reminder_templates")->fetchColumn();
        $stats['active_templates'] = (int)$conn->query("SELECT COUNT(*) FROM reminder_templates WHERE is_active = 1")->fetchColumn();

        // Schedule stats
        $stats['scheduled'] = (int)$conn->query("SELECT COUNT(*) FROM reminder_schedule WHERE status = 'scheduled'")->fetchColumn();
        $stats['sent'] = (int)$conn->query("SELECT COUNT(*) FROM reminder_schedule WHERE status IN ('sent','delivered')")->fetchColumn();
        $stats['failed'] = (int)$conn->query("SELECT COUNT(*) FROM reminder_schedule WHERE status = 'failed'")->fetchColumn();

        // Receipt stats
        $stats['receipts_sent'] = (int)$conn->query("SELECT COUNT(*) FROM receipt_messages WHERE status IN ('sent','delivered')")->fetchColumn();
        $stats['receipts_failed'] = (int)$conn->query("SELECT COUNT(*) FROM receipt_messages WHERE status = 'failed'")->fetchColumn();

        // Today's reminders
        $todayStmt = $conn->prepare("SELECT COUNT(*) FROM reminder_schedule WHERE scheduled_date = ? AND status = 'scheduled'");
        $todayStmt->execute([date('Y-m-d')]);
        $stats['due_today'] = (int)$todayStmt->fetchColumn();

        jsonResponse(['success' => true, 'data' => $stats]);
        break;

    // ============================
    // SEND TEST REMINDER
    // ============================
    case 'send_test':
        if (!hasPermission('settings', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $phone = trim($_POST['phone'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $channel = trim($_POST['channel'] ?? 'whatsapp');

        if (empty($phone) || empty($message)) {
            jsonResponse(['success' => false, 'message' => 'Phone and message required']);
        }

        if ($channel === 'whatsapp') {
            $result = sendWhatsAppMessage($phone, $message);
        } elseif ($channel === 'email') {
            $email = trim($_POST['email'] ?? '');
            $result = sendEmailReminder($email, 'Test Reminder', $message);
        } else {
            $result = ['success' => false, 'message' => 'SMS not yet supported'];
        }

        jsonResponse($result);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Generate reminder schedule for active leases based on templates
 */
function generateReminderSchedule($conn) {
    $templates = $conn->query("SELECT * FROM reminder_templates WHERE is_active = 1")->fetchAll();
    $leases = $conn->query("
        SELECT l.id AS lease_id, l.tenant_id, l.rent_amount, l.currency,
               l.payment_due_day, l.start_date, l.end_date
        FROM leases l WHERE l.status = 'active'
    ")->fetchAll();

    $created = 0;
    $skipped = 0;
    $today = new DateTime();

    foreach ($leases as $lease) {
        $dueDay = (int)$lease['payment_due_day'];

        // Calculate next due date
        $nextDue = new DateTime();
        $nextDue->setDate((int)$nextDue->format('Y'), (int)$nextDue->format('m'), min($dueDay, (int)$nextDue->format('t')));
        if ($nextDue < $today) {
            $nextDue->modify('+1 month');
            $nextDue->setDate((int)$nextDue->format('Y'), (int)$nextDue->format('m'), min($dueDay, (int)$nextDue->format('t')));
        }

        $periodRef = $nextDue->format('Y-m');

        foreach ($templates as $tpl) {
            $scheduledDate = clone $nextDue;

            if ($tpl['trigger_type'] === 'before_due') {
                $scheduledDate->modify('-' . $tpl['days_offset'] . ' days');
            } elseif ($tpl['trigger_type'] === 'overdue') {
                $scheduledDate->modify('+' . $tpl['days_offset'] . ' days');
            }
            // on_due: scheduledDate = nextDue (no change)

            // Skip if scheduled date is in the past
            if ($scheduledDate < $today) {
                $skipped++;
                continue;
            }

            // Check if already scheduled for this lease + template + period
            $check = $conn->prepare("
                SELECT id FROM reminder_schedule
                WHERE lease_id = ? AND template_id = ? AND period_reference = ? AND status IN ('scheduled','sent','delivered')
            ");
            $check->execute([$lease['lease_id'], $tpl['id'], $periodRef]);
            if ($check->fetch()) {
                $skipped++;
                continue;
            }

            // Insert schedule entry
            $conn->prepare("
                INSERT INTO reminder_schedule (lease_id, tenant_id, template_id, scheduled_date, period_reference, channel, status)
                VALUES (?, ?, ?, ?, ?, ?, 'scheduled')
            ")->execute([
                $lease['lease_id'],
                $lease['tenant_id'],
                $tpl['id'],
                $scheduledDate->format('Y-m-d'),
                $periodRef,
                $tpl['channel']
            ]);
            $created++;
        }
    }

    return ['created' => $created, 'skipped' => $skipped];
}

/**
 * Process due reminders — called by cron
 */
function processReminders($conn) {
    $today = date('Y-m-d');
    $companyName = getSetting('company_name', 'Property Management');

    // Get scheduled reminders due today or earlier
    $stmt = $conn->prepare("
        SELECT rs.*, rt.message_template, rt.trigger_type, rt.name AS template_name,
               t.first_name, t.last_name, t.phone, t.email,
               l.rent_amount, l.currency, l.payment_due_day,
               u.unit_number, p.name AS property_name
        FROM reminder_schedule rs
        INNER JOIN reminder_templates rt ON rs.template_id = rt.id
        INNER JOIN tenants t ON rs.tenant_id = t.id
        INNER JOIN leases l ON rs.lease_id = l.id
        INNER JOIN units u ON l.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        WHERE rs.scheduled_date <= :today AND rs.status = 'scheduled'
        ORDER BY rs.scheduled_date ASC
        LIMIT 100
    ");
    $stmt->execute(['today' => $today]);
    $reminders = $stmt->fetchAll();

    $sent = 0;
    $failed = 0;

    foreach ($reminders as $rem) {
        $tenantName = trim($rem['first_name'] . ' ' . $rem['last_name']);
        $dueDay = (int)$rem['payment_due_day'];
        $dueDate = date('Y-m-', strtotime($rem['period_reference'] . '-01')) . str_pad($dueDay, 2, '0', STR_PAD_LEFT);

        // Calculate balance
        // Part 31: the balance is what the tenant actually still owes —
        // the sum of unsettled charges — rather than months-elapsed times
        // rent minus everything ever paid, which drifted on any partial
        // payment or mid-term change.
        $balance = outstandingTotal([
            'lease_id' => $rem['lease_id'],
            'due_by'   => date('Y-m-d'),
        ]);

        // Resolve placeholders
        $message = $rem['message_template'];
        $message = str_replace('{tenant_name}', $tenantName, $message);
        $message = str_replace('{amount}', number_format((float)$rem['rent_amount'], 0), $message);
        $message = str_replace('{currency}', $rem['currency'], $message);
        $message = str_replace('{due_date}', formatDate($dueDate), $message);
        $message = str_replace('{unit_number}', $rem['unit_number'], $message);
        $message = str_replace('{property_name}', $rem['property_name'], $message);
        $message = str_replace('{balance}', number_format($balance, 0), $message);
        $message = str_replace('{company_name}', $companyName, $message);

        $sendResult = ['success' => false];
        $channel = $rem['channel'];

        if ($channel === 'whatsapp' && $rem['phone']) {
            $sendResult = sendWhatsAppMessage($rem['phone'], $message);
        } elseif ($channel === 'email' && $rem['email']) {
            $sendResult = sendEmailReminder($rem['email'], 'Rent Reminder - ' . $companyName, $message);
        } elseif ($channel === 'sms' && $rem['phone']) {
            $sendResult = ['success' => false, 'message' => 'SMS not yet implemented'];
        }

        $newStatus = $sendResult['success'] ? 'sent' : 'failed';
        $errorMsg = $sendResult['success'] ? null : ($sendResult['message'] ?? 'Unknown error');

        $conn->prepare("
            UPDATE reminder_schedule
            SET status = ?, actual_sent_date = NOW(), message_body = ?,
                recipient_phone = ?, recipient_email = ?, error_message = ?,
                retry_count = retry_count + 1
            WHERE id = ?
        ")->execute([$newStatus, $message, $rem['phone'], $rem['email'], $errorMsg, $rem['id']]);

        if ($sendResult['success']) {
            $sent++;
        } else {
            $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'total' => count($reminders)];
}

/**
 * Queue a receipt message for a payment
 */
function queueReceiptMessage($conn, $paymentId) {
    // Get payment + tenant + receipt info
    $stmt = $conn->prepare("
        SELECT p.id AS payment_id, p.amount, p.currency, p.payment_date, p.payment_method,
               p.period_from, p.period_to, p.reference_number,
               t.id AS tenant_id, t.first_name, t.last_name, t.phone, t.email,
               r.id AS receipt_id, r.receipt_number,
               u.unit_number, pr.name AS property_name
        FROM payments p
        INNER JOIN tenants t ON p.tenant_id = t.id
        LEFT JOIN receipts r ON r.payment_id = p.id
        INNER JOIN units u ON p.unit_id = u.id
        INNER JOIN properties pr ON u.property_id = pr.id
        WHERE p.id = ?
    ");
    $stmt->execute([$paymentId]);
    $data = $stmt->fetch();

    if (!$data) {
        return ['success' => false, 'message' => 'Payment not found'];
    }

    if (!$data['phone']) {
        return ['success' => false, 'message' => 'Tenant has no phone number'];
    }

    $companyName = getSetting('company_name', 'Property Management');
    $tenantName = trim($data['first_name'] . ' ' . $data['last_name']);

    // Build receipt message
    $message = "Payment Receipt\n";
    $message .= "----\n";
    if ($data['receipt_number']) {
        $message .= "Receipt #: " . $data['receipt_number'] . "\n";
    }
    $message .= "Tenant: " . $tenantName . "\n";
    $message .= "Unit: " . $data['unit_number'] . " (" . $data['property_name'] . ")\n";
    $message .= "Amount: " . $data['currency'] . " " . number_format((float)$data['amount'], 0) . "\n";
    $message .= "Date: " . formatDate($data['payment_date']) . "\n";
    $message .= "Method: " . ucwords(str_replace('_', ' ', $data['payment_method'])) . "\n";
    if ($data['reference_number']) {
        $message .= "Ref: " . $data['reference_number'] . "\n";
    }
    if ($data['period_from'] && $data['period_to']) {
        $message .= "Period: " . formatDate($data['period_from']) . " - " . formatDate($data['period_to']) . "\n";
    }
    $message .= "----\n";
    $message .= "Thank you for your payment!\n";
    $message .= $companyName;

    // Check if already sent for this payment
    $existCheck = $conn->prepare("SELECT id FROM receipt_messages WHERE payment_id = ? AND status IN ('sent','delivered','pending')");
    $existCheck->execute([$paymentId]);
    if ($existCheck->fetch()) {
        return ['success' => false, 'message' => 'Receipt already sent for this payment'];
    }

    // Insert receipt message
    $conn->prepare("
        INSERT INTO receipt_messages (payment_id, receipt_id, tenant_id, channel, recipient_phone, recipient_email, message_body, status)
        VALUES (?, ?, ?, 'whatsapp', ?, ?, ?, 'pending')
    ")->execute([
        $paymentId,
        $data['receipt_id'] ?: null,
        $data['tenant_id'],
        $data['phone'],
        $data['email'],
        $message
    ]);

    $msgId = (int)$conn->lastInsertId();

    // Attempt to send immediately
    $sendResult = sendReceiptMessage($conn, $msgId);

    // Update receipt sent_via
    if ($sendResult['success'] && $data['receipt_id']) {
        $conn->prepare("UPDATE receipts SET sent_via = 'whatsapp', sent_at = NOW() WHERE id = ?")->execute([$data['receipt_id']]);
    }

    return $sendResult;
}

/**
 * Send a queued receipt message
 */
function sendReceiptMessage($conn, $msgId) {
    $stmt = $conn->prepare("SELECT * FROM receipt_messages WHERE id = ?");
    $stmt->execute([$msgId]);
    $msg = $stmt->fetch();

    if (!$msg) return ['success' => false, 'message' => 'Message not found'];

    $result = ['success' => false];

    if ($msg['channel'] === 'whatsapp' && $msg['recipient_phone']) {
        $result = sendWhatsAppMessage($msg['recipient_phone'], $msg['message_body']);
    } elseif ($msg['channel'] === 'email' && $msg['recipient_email']) {
        $result = sendEmailReminder($msg['recipient_email'], 'Payment Receipt', $msg['message_body']);
    }

    $newStatus = $result['success'] ? 'sent' : 'failed';
    $error = $result['success'] ? null : ($result['message'] ?? 'Unknown error');

    $conn->prepare("
        UPDATE receipt_messages SET status = ?, error_message = ?, sent_at = NOW(), retry_count = retry_count + 1
        WHERE id = ?
    ")->execute([$newStatus, $error, $msgId]);

    return $result;
}

/**
 * Send WhatsApp message via wa.fis.co.tz API
 */
function sendWhatsAppMessage($phone, $message) {
    $apiUrl = WA_API_URL;

    // Clean phone number
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (strpos($phone, '0') === 0) {
        $phone = '255' . substr($phone, 1);
    }
    if (strpos($phone, '+') !== 0 && strpos($phone, '255') === 0) {
        $phone = '+' . $phone;
    }

    $postData = [
        'phone' => $phone,
        'message' => $message,
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl . '/api/send',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'message' => 'cURL error: ' . $error];
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && ($data['success'] ?? false)) {
        return ['success' => true, 'message' => 'WhatsApp message sent'];
    }

    return ['success' => false, 'message' => $data['message'] ?? ('HTTP ' . $httpCode . ': ' . substr($response, 0, 200))];
}

/**
 * Send email reminder
 */
function sendEmailReminder($email, $subject, $body) {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email'];
    }

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";

    $htmlBody = "<html><body style='font-family:Arial,sans-serif;'>";
    $htmlBody .= "<div style='max-width:600px;margin:0 auto;padding:20px;'>";
    $htmlBody .= nl2br(htmlspecialchars($body));
    $htmlBody .= "</div></body></html>";

    $sent = @mail($email, $subject, $htmlBody, $headers);

    return $sent
        ? ['success' => true, 'message' => 'Email sent']
        : ['success' => false, 'message' => 'Failed to send email'];
}
