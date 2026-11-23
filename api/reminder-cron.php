<?php
/**
 * FIS Real Estate — Reminder Cron (Part 17)
 * Standalone cron script to process scheduled reminders and generate new schedules.
 *
 * Usage: Set up a cron job to run this script daily (e.g. 8 AM):
 *   0 8 * * * php /path/to/realestate/api/reminder-cron.php
 *
 * Or call via HTTP with the cron key:
 *   GET /api/reminder-cron.php?key=YOUR_CRON_KEY
 */

// Allow CLI or authenticated HTTP
$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/charges.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/../includes/sms.php';

// Security: verify access
if (!$isCli) {
    $cronKey = trim($_GET['key'] ?? '');
    $expectedKey = getSetting('cron_key', '');

    if ($expectedKey === '' || $cronKey !== $expectedKey) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid cron key']);
        exit;
    }
}

// Check if reminders are enabled
if (getSetting('reminders_enabled', '0') !== '1') {
    $msg = 'Reminders module is disabled';
    if ($isCli) {
        echo "[" . date('Y-m-d H:i:s') . "] Reminder Cron: {$msg}\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $msg]);
    }
    exit;
}

// Check tier
if (!hasTier('professional')) {
    $msg = 'Professional tier required for reminders';
    if ($isCli) {
        echo "[" . date('Y-m-d H:i:s') . "] Reminder Cron: {$msg}\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $msg]);
    }
    exit;
}

// Include the helper functions from reminders API (we need them but can't include
// the full file as it has action dispatch). Define them here for cron context.

/**
 * Send WhatsApp message via wa.fis.co.tz API
 */
function cronSendWhatsApp($phone, $message) {
    $apiUrl = WA_API_URL;

    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (strpos($phone, '0') === 0) {
        $phone = '255' . substr($phone, 1);
    }
    if (strpos($phone, '+') !== 0 && strpos($phone, '255') === 0) {
        $phone = '+' . $phone;
    }

    $postData = ['phone' => $phone, 'message' => $message];

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

    if ($error) return ['success' => false, 'message' => 'cURL error: ' . $error];

    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && ($data['success'] ?? false)) {
        return ['success' => true, 'message' => 'Sent'];
    }
    return ['success' => false, 'message' => $data['message'] ?? ('HTTP ' . $httpCode)];
}

/**
 * Send email reminder
 */
function cronSendEmail($email, $subject, $body) {
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
    return $sent ? ['success' => true] : ['success' => false, 'message' => 'mail() failed'];
}

// ============================================================
// STEP 1: Auto-generate schedule for upcoming reminders
// ============================================================
function cronGenerateSchedule($conn) {
    $templates = $conn->query("SELECT * FROM reminder_templates WHERE is_active = 1")->fetchAll();
    $leases = $conn->query("
        SELECT l.id AS lease_id, l.tenant_id, l.rent_amount, l.currency,
               l.payment_due_day, l.start_date, l.end_date
        FROM leases l WHERE l.status = 'active'
    ")->fetchAll();

    $created = 0;
    $today = new DateTime();
    // Cap on repeated overdue nags per period, so a template set to "repeat
    // every 7 days" cannot chase a tenant indefinitely.
    $maxNags = max(1, (int)getSetting('reminder_max_overdue_nags', '4'));

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
            // How many times this template fires for this period. An overdue
            // template with repeat_interval_days > 0 nags repeatedly; everything
            // else fires exactly once.
            $repeatDays = (int)($tpl['repeat_interval_days'] ?? 0);
            $isRepeating = ($tpl['trigger_type'] === 'overdue' && $repeatDays > 0);
            $occurrences = $isRepeating ? max(1, $maxNags) : 1;

            for ($k = 0; $k < $occurrences; $k++) {
                $scheduledDate = clone $nextDue;

                if ($tpl['trigger_type'] === 'before_due') {
                    $scheduledDate->modify('-' . $tpl['days_offset'] . ' days');
                } elseif ($tpl['trigger_type'] === 'overdue') {
                    $scheduledDate->modify('+' . ((int)$tpl['days_offset'] + ($k * $repeatDays)) . ' days');
                }

                if ($scheduledDate < $today) continue;

                // Each nag needs its own period key, otherwise the duplicate
                // check below would collapse them all into the first one.
                $occurrenceRef = $k === 0 ? $periodRef : $periodRef . '#' . ($k + 1);

                $check = $conn->prepare("
                    SELECT id FROM reminder_schedule
                    WHERE lease_id = ? AND template_id = ? AND period_reference = ? AND status IN ('scheduled','sent','delivered')
                ");
                $check->execute([$lease['lease_id'], $tpl['id'], $occurrenceRef]);
                if ($check->fetch()) continue;

                $conn->prepare("
                    INSERT INTO reminder_schedule (lease_id, tenant_id, template_id, scheduled_date, period_reference, channel, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'scheduled')
                ")->execute([
                    $lease['lease_id'],
                    $lease['tenant_id'],
                    $tpl['id'],
                    $scheduledDate->format('Y-m-d'),
                    $occurrenceRef,
                    $tpl['channel']
                ]);
                $created++;
            }
        }
    }

    return $created;
}

// ============================================================
// STEP 2: Process due reminders
// ============================================================
function cronProcessReminders($conn) {
    $today = date('Y-m-d');
    $companyName = getSetting('company_name', 'Property Management');

    $stmt = $conn->prepare("
        SELECT rs.*, rt.message_template, rt.trigger_type,
               t.first_name, t.last_name, t.phone, t.email,
               l.rent_amount, l.currency, l.payment_due_day, l.start_date,
               u.unit_number, p.name AS property_name
        FROM reminder_schedule rs
        INNER JOIN reminder_templates rt ON rs.template_id = rt.id
        INNER JOIN tenants t ON rs.tenant_id = t.id
        INNER JOIN leases l ON rs.lease_id = l.id
        INNER JOIN units u ON l.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        WHERE rs.scheduled_date <= :today AND rs.status = 'scheduled'
        ORDER BY rs.scheduled_date ASC
        LIMIT 200
    ");
    $stmt->execute(['today' => $today]);
    $reminders = $stmt->fetchAll();

    $sent = 0;
    $failed = 0;

    foreach ($reminders as $rem) {
        $tenantName = trim($rem['first_name'] . ' ' . $rem['last_name']);
        $dueDay = (int)$rem['payment_due_day'];
        $periodMonth = $rem['period_reference'] ?? date('Y-m');
        $dueDate = $periodMonth . '-' . str_pad($dueDay, 2, '0', STR_PAD_LEFT);

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

        // Stop chasing a tenant who has already paid. Checked at send time (not
        // when the schedule was generated) so a payment made yesterday cancels
        // today's nag. Only applies to overdue chasers — a "due in 3 days"
        // notice is informational and still goes out.
        if ($rem['trigger_type'] === 'overdue' && $balance <= 0) {
            $conn->prepare("
                UPDATE reminder_schedule
                SET status = 'cancelled', actual_sent_date = NOW(),
                    error_message = 'Balance cleared - reminder not needed'
                WHERE id = ?
            ")->execute([$rem['id']]);
            continue;
        }

        $sendResult = ['success' => false, 'message' => 'No delivery channel'];
        $channel = $rem['channel'];

        if ($channel === 'whatsapp' && $rem['phone']) {
            $sendResult = cronSendWhatsApp($rem['phone'], $message);
        } elseif ($channel === 'email' && $rem['email']) {
            $sendResult = cronSendEmail($rem['email'], 'Rent Reminder - ' . $companyName, $message);
        } elseif ($channel === 'sms' && $rem['phone']) {
            // uid lets the provider's delivery report be tied back to this row.
            $sendResult = sendSMS($rem['phone'], $message, 'rem-' . $rem['id']);
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

// ============================================================
// STEP 3: Process pending receipt messages
// ============================================================
function cronProcessReceipts($conn) {
    $stmt = $conn->query("
        SELECT * FROM receipt_messages WHERE status = 'pending' ORDER BY created_at ASC LIMIT 50
    ");
    $messages = $stmt->fetchAll();

    $sent = 0;
    $failed = 0;

    foreach ($messages as $msg) {
        $result = ['success' => false];

        if ($msg['channel'] === 'whatsapp' && $msg['recipient_phone']) {
            $result = cronSendWhatsApp($msg['recipient_phone'], $msg['message_body']);
        } elseif ($msg['channel'] === 'email' && $msg['recipient_email']) {
            $result = cronSendEmail($msg['recipient_email'], 'Payment Receipt', $msg['message_body']);
        }

        $newStatus = $result['success'] ? 'sent' : 'failed';
        $error = $result['success'] ? null : ($result['message'] ?? 'Unknown error');

        $conn->prepare("
            UPDATE receipt_messages SET status = ?, error_message = ?, sent_at = NOW(), retry_count = retry_count + 1 WHERE id = ?
        ")->execute([$newStatus, $error, $msg['id']]);

        if ($result['success']) $sent++; else $failed++;
    }

    return ['sent' => $sent, 'failed' => $failed, 'total' => count($messages)];
}

// ============================================================
// RUN ALL STEPS
// ============================================================

$scheduleCreated = cronGenerateSchedule($conn);
$reminderResult = cronProcessReminders($conn);
$receiptResult = cronProcessReceipts($conn);

$output = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'schedule_generated' => $scheduleCreated,
    'reminders' => $reminderResult,
    'receipts' => $receiptResult,
    'message' => sprintf(
        'Schedule: %d generated. Reminders: %d sent, %d failed. Receipts: %d sent, %d failed.',
        $scheduleCreated,
        $reminderResult['sent'], $reminderResult['failed'],
        $receiptResult['sent'], $receiptResult['failed']
    )
];

if ($isCli) {
    echo "[" . date('Y-m-d H:i:s') . "] Reminder Cron: " . $output['message'] . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($output);
}
