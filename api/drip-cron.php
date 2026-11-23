<?php
/**
 * FIS Real Estate — WhatsApp Drip Campaign Cron
 *
 * Part 4 Enhanced:
 *   - Timezone-aware sending (9am EAT configurable via system_settings)
 *   - Batch processing with configurable limits
 *   - Retry failed messages (max 3 retries, configurable)
 *   - Skip weekends option (per campaign, configurable)
 *   - Recalculate schedule from approval_date when available
 *   - Rate-limited sending via message queue
 *
 * Run via cron every hour: php /path/to/api/drip-cron.php
 * Or via web: /api/drip-cron.php?key=<DRIP_CRON_KEY>
 *
 * Drip schedule (days after trial start):
 *   Step 1: Day 0  — Welcome (sent on approval/provisioning)
 *   Step 2: Day 1  — Getting started
 *   Step 3: Day 3  — Lease & rent
 *   Step 4: Day 5  — Reminders & automation
 *   Step 5: Day 7  — Mid-trial check-in
 *   Step 6: Day 9  — Professional features highlight
 *   Step 7: Day 11 — Testimonial / social proof
 *   Step 8: Day 13 — Urgency (1 day before expiry)
 *   Step 9: Day 14 — Trial expired
 *   Step 10: Day 17 — Follow up (3 days after expiry)
 *   Step 11: Day 21 — Final follow up
 */

// Access control
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../includes/secrets.php';
    if (!appSecretMatches('DRIP_CRON_KEY', $_GET['key'] ?? null)) {
        http_response_code(403);
        die('Access denied');
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/../includes/whatsapp.php';

// Logging
function dripLog($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] [{$type}] {$message}\n";
    echo $line;
    error_log($line, 3, __DIR__ . '/drip-cron.log');
}

dripLog("========================================");
dripLog("Drip campaign cron started");
dripLog("========================================");

$central = getCentralDb();
if (!$central) {
    dripLog("Cannot connect to central database", 'ERROR');
    exit(1);
}

// Load drip settings
$dripEnabled = getDripSetting('drip_enabled', '1');
if ($dripEnabled !== '1') {
    dripLog("Drip campaigns are disabled globally. Exiting.");
    exit(0);
}

$sendHour = (int) getDripSetting('drip_send_hour', 9);
$maxRetries = (int) getDripSetting('drip_max_retries', 3);
$batchLimit = 50; // Max campaigns to process per cron run

$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$currentHour = (int) date('H');
$dayOfWeek = (int) date('w'); // 0=Sun, 6=Sat

$processed = 0;
$sent = 0;
$queued = 0;
$retried = 0;
$errors = 0;
$skipped = 0;

try {
    // ================================================================
    // PHASE 1: Retry failed messages (max retries not exceeded)
    // ================================================================
    $retryStmt = $central->prepare("
        SELECT m.*, c.phone, c.name, c.language, c.trial_request_id,
               t.company_name, t.unit_count, t.tenant_slug,
               t.trial_start_date, t.trial_end_date
        FROM drip_messages_re m
        JOIN drip_campaigns_re c ON c.id = m.campaign_id
        JOIN trial_requests t ON t.id = c.trial_request_id
        WHERE m.status = 'failed'
        AND m.retry_count < :max_retries
        AND c.status = 'active'
        ORDER BY m.retry_count ASC, m.created_at ASC
        LIMIT 20
    ");
    $retryStmt->execute(['max_retries' => $maxRetries]);
    $failedMessages = $retryStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($failedMessages)) {
        dripLog("Found " . count($failedMessages) . " failed messages to retry");

        foreach ($failedMessages as $fm) {
            $loginUrl = $fm['tenant_slug']
                ? "https://{$fm['tenant_slug']}." . CPANEL_DOMAIN . "/"
                : "https://realestate.fis.co.tz";
            $trialEndDate = $fm['trial_end_date'] ? date('d/m/Y', strtotime($fm['trial_end_date'])) : '';

            $message = buildDripMessage(
                (int) $fm['step_number'],
                $fm['name'],
                $fm['company_name'] ?? $fm['name'],
                (int) ($fm['unit_count'] ?? 1),
                $loginUrl,
                $trialEndDate,
                $fm['language'] ?? 'sw'
            );

            if (!$message) continue;

            // Enqueue for rate-limited sending
            $queueId = enqueueWhatsApp($fm['phone'], $message, $fm['campaign_id'], $fm['id'], 3);
            if ($queueId) {
                // Update retry count
                $central->prepare("
                    UPDATE drip_messages_re SET retry_count = retry_count + 1, status = 'pending'
                    WHERE id = ?
                ")->execute([$fm['id']]);
                $retried++;
                dripLog("Retry queued for message #{$fm['id']} (step {$fm['step_number']}, attempt " . ($fm['retry_count'] + 1) . ")");
            }
        }
    }

    // ================================================================
    // PHASE 2: Process active campaigns due for next message
    // ================================================================
    $stmt = $central->prepare("
        SELECT c.*, t.company_name, t.unit_count, t.tenant_slug,
               t.trial_start_date, t.trial_end_date, t.status AS trial_status,
               s.tier AS sub_tier, s.status AS sub_status
        FROM drip_campaigns_re c
        JOIN trial_requests t ON t.id = c.trial_request_id
        LEFT JOIN subscriptions s ON s.trial_request_id = t.id
        WHERE c.status = 'active'
        AND c.next_send_at <= :now
        ORDER BY c.next_send_at ASC
        LIMIT :batch_limit
    ");
    $stmt->bindValue(':now', $now, PDO::PARAM_STR);
    $stmt->bindValue(':batch_limit', $batchLimit, PDO::PARAM_INT);
    $stmt->execute();
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    dripLog("Found " . count($campaigns) . " campaigns due for processing");

    foreach ($campaigns as $campaign) {
        $campaignId = $campaign['id'];
        $currentStep = (int) $campaign['current_step'];
        $nextStep = $currentStep + 1;
        $language = $campaign['language'] ?? 'sw';
        $skipWeekends = (int) ($campaign['skip_weekends'] ?? 0);

        $name = $campaign['name'];
        $phone = $campaign['phone'];
        $company = $campaign['company_name'] ?? $name;
        $unitCount = (int) ($campaign['unit_count'] ?? 1);
        $trialEndDate = $campaign['trial_end_date'] ? date('d/m/Y', strtotime($campaign['trial_end_date'])) : '';
        $slug = $campaign['tenant_slug'] ?? '';
        $loginUrl = $slug ? "https://{$slug}." . CPANEL_DOMAIN . "/" : "https://realestate.fis.co.tz";

        dripLog("Processing campaign #{$campaignId} for {$name} (step {$currentStep} -> {$nextStep})");

        // Check: skip weekends if enabled
        if ($skipWeekends && ($dayOfWeek === 0 || $dayOfWeek === 6)) {
            // Reschedule to next Monday at send hour
            $daysUntilMonday = ($dayOfWeek === 0) ? 1 : 2;
            $mondayDate = date('Y-m-d', strtotime("+{$daysUntilMonday} days"));
            $rescheduleTo = $mondayDate . ' ' . sprintf('%02d', $sendHour) . ':00:00';
            $central->prepare("UPDATE drip_campaigns_re SET next_send_at = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$rescheduleTo, $campaignId]);
            dripLog("Campaign #{$campaignId} rescheduled to Monday {$rescheduleTo} (skip weekends)");
            $skipped++;
            continue;
        }

        // Check: trial converted to paid (stop after step 9)
        if ($campaign['sub_status'] === 'active' && $nextStep > 9) {
            $central->prepare("UPDATE drip_campaigns_re SET status = 'completed', updated_at = NOW() WHERE id = ?")
                ->execute([$campaignId]);
            dripLog("Campaign #{$campaignId} completed (subscriber converted to active)");
            continue;
        }

        // Check: trial rejected
        if ($campaign['trial_status'] === 'rejected') {
            $central->prepare("UPDATE drip_campaigns_re SET status = 'stopped', updated_at = NOW() WHERE id = ?")
                ->execute([$campaignId]);
            dripLog("Campaign #{$campaignId} stopped (trial rejected)");
            continue;
        }

        // Get the schedule
        $schedule = getDripSchedule();
        $maxStep = max(array_keys($schedule));

        if ($nextStep > $maxStep) {
            $central->prepare("UPDATE drip_campaigns_re SET status = 'completed', updated_at = NOW() WHERE id = ?")
                ->execute([$campaignId]);
            dripLog("Campaign #{$campaignId} completed (all steps sent)");
            continue;
        }

        // Build the message using DB templates (with language support)
        $message = buildDripMessage($nextStep, $name, $company, $unitCount, $loginUrl, $trialEndDate, $language);

        if (!$message) {
            dripLog("No message template for step {$nextStep} (lang: {$language})", 'WARNING');
            continue;
        }

        // Get the drip_messages_re record ID for this step
        $msgRow = $central->prepare("
            SELECT id FROM drip_messages_re WHERE campaign_id = ? AND step_number = ?
        ");
        $msgRow->execute([$campaignId, $nextStep]);
        $messageRecordId = $msgRow->fetchColumn() ?: null;

        // Enqueue for rate-limited sending
        $queueId = enqueueWhatsApp($phone, $message, $campaignId, $messageRecordId, 5);
        $processed++;

        if ($queueId) {
            $queued++;
            dripLog("Step {$nextStep} queued for {$phone} (queue #{$queueId})");
        } else {
            $errors++;
            dripLog("Step {$nextStep} FAILED to queue for {$phone}", 'ERROR');
        }

        // Calculate next send time using approval_date or trial_start_date
        $baseDate = $campaign['approval_date']
            ?: $campaign['trial_start_date']
            ?: date('Y-m-d', strtotime($campaign['created_at']));

        $nextNextStep = $nextStep + 1;
        if ($nextNextStep <= $maxStep && isset($schedule[$nextNextStep])) {
            $daysFromStart = $schedule[$nextNextStep];
            $nextSendAt = date('Y-m-d', strtotime($baseDate . " +{$daysFromStart} days"))
                . ' ' . sprintf('%02d', $sendHour) . ':00:00';

            // If calculated time is in the past, send next hour
            if ($nextSendAt <= $now) {
                $nextSendAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
            }

            // Skip weekends if enabled: push to Monday
            if ($skipWeekends) {
                $nextDow = (int) date('w', strtotime($nextSendAt));
                if ($nextDow === 0) { // Sunday -> Monday
                    $nextSendAt = date('Y-m-d', strtotime($nextSendAt . ' +1 day'))
                        . ' ' . sprintf('%02d', $sendHour) . ':00:00';
                } elseif ($nextDow === 6) { // Saturday -> Monday
                    $nextSendAt = date('Y-m-d', strtotime($nextSendAt . ' +2 days'))
                        . ' ' . sprintf('%02d', $sendHour) . ':00:00';
                }
            }
        } else {
            $nextSendAt = null;
        }

        // Update campaign
        $updateStmt = $central->prepare("
            UPDATE drip_campaigns_re
            SET current_step = :step,
                next_send_at = :next_send,
                status = :status,
                updated_at = NOW()
            WHERE id = :id
        ");
        $updateStmt->execute([
            'step' => $nextStep,
            'next_send' => $nextSendAt,
            'status' => ($nextNextStep > $maxStep) ? 'completed' : 'active',
            'id' => $campaignId
        ]);
    }

    // ================================================================
    // PHASE 3: Process message queue (rate-limited sending)
    // ================================================================
    dripLog("Processing message queue...");
    $queueResult = processMessageQueue();
    dripLog("Queue processed: sent={$queueResult['sent']}, failed={$queueResult['failed']}, remaining={$queueResult['remaining']}");

    $sent = $queueResult['sent'];
    $errors += $queueResult['failed'];

} catch (PDOException $e) {
    dripLog("Database error: " . $e->getMessage(), 'ERROR');
}

dripLog("========================================");
dripLog("Drip cron complete: processed={$processed}, queued={$queued}, sent={$sent}, retried={$retried}, skipped={$skipped}, errors={$errors}");
dripLog("========================================");
