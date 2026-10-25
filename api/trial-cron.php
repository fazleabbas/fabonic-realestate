<?php
/**
 * FIS Real Estate — Trial Expiry & Subscription Cron
 * Checks for expired trials and subscriptions, auto-suspends them.
 *
 * CPANEL CRON:
 * Command: /usr/bin/php /home/realcotz/public_html/api/trial-cron.php
 * Schedule: Once daily at 1:00 AM
 *
 * Actions:
 * 1. Expire trials that passed their end_date
 * 2. Suspend active subscriptions past end_date + grace period
 * 3. Send expiry warning notifications (3 days, 1 day before)
 */

// Security
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../includes/secrets.php';
    if (!appSecretMatches('TRIAL_CRON_KEY', $_GET['key'] ?? null)) {
        http_response_code(403);
        die('Access denied');
    }
}

require_once __DIR__ . '/../config/database.php';

function logTrial($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$type}] {$message}\n";
    echo $logLine;
    error_log($logLine, 3, __DIR__ . '/trial-cron.log');
}

function getCentralConn() {
    return new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

// ============================================
// 1. EXPIRE TRIALS
// ============================================
function expireTrials() {
    logTrial("--- Checking expired trials ---");
    $pdo = getCentralConn();

    // Find trial subscriptions past their end_date
    $stmt = $pdo->prepare("
        SELECT id, tenant_slug, company_name, owner_name, owner_phone, owner_email,
               end_date, tier, unit_count
        FROM subscriptions
        WHERE status = 'trial'
        AND end_date < CURDATE()
    ");
    $stmt->execute();
    $expired = $stmt->fetchAll();

    if (empty($expired)) {
        logTrial("No expired trials found");
        return 0;
    }

    logTrial("Found " . count($expired) . " expired trial(s)");

    $count = 0;
    foreach ($expired as $sub) {
        logTrial("Expiring trial: {$sub['tenant_slug']} ({$sub['company_name']}) — ended {$sub['end_date']}");

        // Mark subscription as expired
        $update = $pdo->prepare("UPDATE subscriptions SET status = 'expired', updated_at = NOW() WHERE id = ?");
        $update->execute([$sub['id']]);

        // Update linked trial request
        $pdo->prepare("
            UPDATE trial_requests SET status = 'expired', updated_at = NOW()
            WHERE tenant_slug = ? AND status NOT IN ('converted', 'rejected')
        ")->execute([$sub['tenant_slug']]);

        // Send expiry notification email
        sendExpiryEmail($sub);

        $count++;
    }

    logTrial("Expired {$count} trial(s)");
    return $count;
}

// ============================================
// 2. SUSPEND OVERDUE SUBSCRIPTIONS
// ============================================
function suspendOverdue() {
    logTrial("--- Checking overdue subscriptions ---");
    $pdo = getCentralConn();

    $graceDays = defined('SUBSCRIPTION_GRACE_DAYS') ? SUBSCRIPTION_GRACE_DAYS : 7;

    // Find active subscriptions past end_date + grace period
    $stmt = $pdo->prepare("
        SELECT id, tenant_slug, company_name, owner_name, owner_phone, owner_email,
               end_date, tier, unit_count
        FROM subscriptions
        WHERE status = 'active'
        AND DATE_ADD(end_date, INTERVAL :grace DAY) < CURDATE()
    ");
    $stmt->execute(['grace' => $graceDays]);
    $overdue = $stmt->fetchAll();

    if (empty($overdue)) {
        logTrial("No overdue subscriptions");
        return 0;
    }

    logTrial("Found " . count($overdue) . " overdue subscription(s)");

    $count = 0;
    foreach ($overdue as $sub) {
        logTrial("Suspending: {$sub['tenant_slug']} ({$sub['company_name']}) — grace ended");

        $update = $pdo->prepare("UPDATE subscriptions SET status = 'suspended', updated_at = NOW() WHERE id = ?");
        $update->execute([$sub['id']]);

        sendSuspensionEmail($sub);

        $count++;
    }

    logTrial("Suspended {$count} subscription(s)");
    return $count;
}

// ============================================
// 3. SEND WARNING NOTIFICATIONS
// ============================================
function sendExpiryWarnings() {
    logTrial("--- Checking for expiry warnings ---");
    $pdo = getCentralConn();

    // 3-day warning for trials
    $stmt = $pdo->prepare("
        SELECT id, tenant_slug, company_name, owner_name, owner_phone, owner_email,
               end_date, tier, unit_count, status
        FROM subscriptions
        WHERE status IN ('trial', 'active')
        AND end_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ");
    $stmt->execute();
    $threeDay = $stmt->fetchAll();

    foreach ($threeDay as $sub) {
        logTrial("3-day warning: {$sub['tenant_slug']} ({$sub['company_name']})");
        sendWarningEmail($sub, 3);
    }

    // 1-day warning for trials
    $stmt = $pdo->prepare("
        SELECT id, tenant_slug, company_name, owner_name, owner_phone, owner_email,
               end_date, tier, unit_count, status
        FROM subscriptions
        WHERE status IN ('trial', 'active')
        AND end_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    ");
    $stmt->execute();
    $oneDay = $stmt->fetchAll();

    foreach ($oneDay as $sub) {
        logTrial("1-day warning: {$sub['tenant_slug']} ({$sub['company_name']})");
        sendWarningEmail($sub, 1);
    }

    $totalWarnings = count($threeDay) + count($oneDay);
    logTrial("Sent {$totalWarnings} warning(s)");
    return $totalWarnings;
}

// ============================================
// EMAIL FUNCTIONS
// ============================================

function sendWarningEmail($sub, $daysLeft) {
    $isTrial = ($sub['status'] === 'trial');
    $type = $isTrial ? 'trial' : 'subscription';
    $subject = "FIS Real Estate: Your {$type} expires in {$daysLeft} day(s)!";

    $loginUrl = "https://{$sub['tenant_slug']}." . CPANEL_DOMAIN . "/";
    $subscribeUrl = $loginUrl . "modules/subscription/subscribe.php";

    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">
<div style="background:linear-gradient(135deg,#1a6b4a,#134f37);padding:25px;text-align:center;border-radius:10px 10px 0 0;">
<h2 style="color:white;margin:0;">Your ' . ucfirst($type) . ' Expires Soon!</h2>
<p style="color:#e8a838;margin:5px 0 0;">FIS Real Estate</p>
</div>
<div style="background:#f8f9fa;padding:25px;border-radius:0 0 10px 10px;">
<p>Dear <strong>' . htmlspecialchars($sub['owner_name']) . '</strong>,</p>
<p>Your FIS Real Estate ' . $type . ' for <strong>' . htmlspecialchars($sub['company_name']) . '</strong> expires in <strong>' . $daysLeft . ' day(s)</strong> (on ' . date('d M Y', strtotime($sub['end_date'])) . ').</p>
<p>' . ($isTrial ? 'Subscribe now to keep all your data and continue managing your properties!' : 'Renew your subscription to avoid service interruption.') . '</p>
<div style="text-align:center;margin:25px 0;">
<a href="' . $subscribeUrl . '" style="background:#1a6b4a;color:white;padding:12px 30px;text-decoration:none;border-radius:50px;font-weight:bold;">' . ($isTrial ? 'Subscribe Now' : 'Renew Now') . '</a>
</div>
<p style="color:#666;font-size:0.9em;">Current plan: ' . ucfirst($sub['tier']) . ' (' . $sub['unit_count'] . ' units)</p>
<p>Best regards,<br><strong>FIS Real Estate Team</strong></p>
</div></body></html>';

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: FIS Real Estate <noreply@fis.co.tz>'
    ];

    return @mail($sub['owner_email'], $subject, $body, implode("\r\n", $headers));
}

function sendExpiryEmail($sub) {
    $subject = "FIS Real Estate: Your trial has expired";
    $loginUrl = "https://{$sub['tenant_slug']}." . CPANEL_DOMAIN . "/";
    $subscribeUrl = $loginUrl . "modules/subscription/subscribe.php";

    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">
<div style="background:#dc3545;padding:25px;text-align:center;border-radius:10px 10px 0 0;">
<h2 style="color:white;margin:0;">Trial Period Ended</h2>
</div>
<div style="background:#f8f9fa;padding:25px;border-radius:0 0 10px 10px;">
<p>Dear <strong>' . htmlspecialchars($sub['owner_name']) . '</strong>,</p>
<p>Your 14-day free trial for <strong>' . htmlspecialchars($sub['company_name']) . '</strong> has ended.</p>
<p><strong>Your data is still safe!</strong> We keep it for 30 days. Subscribe now to regain access.</p>
<div style="background:white;padding:15px;border-radius:8px;margin:15px 0;border-left:4px solid #1a6b4a;">
<p style="margin:0;"><strong>Pricing:</strong></p>
<p style="margin:5px 0;">Basic: TZS 9,999/unit/month</p>
<p style="margin:5px 0;">Professional: TZS 14,999/unit/month</p>
<p style="margin:5px 0;">Enterprise: TZS 19,999/unit/month</p>
</div>
<div style="text-align:center;margin:25px 0;">
<a href="' . $subscribeUrl . '" style="background:#1a6b4a;color:white;padding:12px 30px;text-decoration:none;border-radius:50px;font-weight:bold;">Subscribe Now</a>
</div>
<p>Questions? WhatsApp us at +255 786 110 021 or reply to this email.</p>
<p>Best regards,<br><strong>FIS Real Estate Team</strong></p>
</div></body></html>';

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: FIS Real Estate <noreply@fis.co.tz>'
    ];

    return @mail($sub['owner_email'], $subject, $body, implode("\r\n", $headers));
}

function sendSuspensionEmail($sub) {
    $subject = "FIS Real Estate: Account suspended — action required";
    $subscribeUrl = "https://{$sub['tenant_slug']}." . CPANEL_DOMAIN . "/modules/subscription/subscribe.php";

    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">
<div style="background:#dc3545;padding:25px;text-align:center;border-radius:10px 10px 0 0;">
<h2 style="color:white;margin:0;">Account Suspended</h2>
</div>
<div style="background:#f8f9fa;padding:25px;border-radius:0 0 10px 10px;">
<p>Dear <strong>' . htmlspecialchars($sub['owner_name']) . '</strong>,</p>
<p>Your FIS Real Estate account for <strong>' . htmlspecialchars($sub['company_name']) . '</strong> has been suspended due to non-payment.</p>
<p>Your data is still safe, but you cannot access the system until payment is made.</p>
<div style="text-align:center;margin:25px 0;">
<a href="' . $subscribeUrl . '" style="background:#1a6b4a;color:white;padding:12px 30px;text-decoration:none;border-radius:50px;font-weight:bold;">Make Payment</a>
</div>
<p>Need help? Contact us at +255 786 110 021.</p>
<p>Best regards,<br><strong>FIS Real Estate Team</strong></p>
</div></body></html>';

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: FIS Real Estate <noreply@fis.co.tz>'
    ];

    return @mail($sub['owner_email'], $subject, $body, implode("\r\n", $headers));
}

// ============================================
// MAIN EXECUTION
// ============================================

function runTrialCron() {
    logTrial("========================================");
    logTrial("FIS Real Estate — Trial & Subscription Cron");
    logTrial("========================================");

    try {
        $warnings = sendExpiryWarnings();
        $expired = expireTrials();
        $suspended = suspendOverdue();

        logTrial("========================================");
        logTrial("Summary: {$warnings} warnings, {$expired} expired, {$suspended} suspended");
        logTrial("========================================");
    } catch (Exception $e) {
        logTrial("FATAL: " . $e->getMessage(), 'ERROR');
    }
}

if (php_sapi_name() === 'cli') {
    runTrialCron();
} else {
    echo "<pre>";
    runTrialCron();
    echo "</pre>";
}
