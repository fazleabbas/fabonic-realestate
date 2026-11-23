<?php
/**
 * FIS Real Estate — Commission Processing Cron (Part 22)
 * Processes monthly commissions for agent referrals.
 * Can be run via CLI or HTTP with cron_key auth.
 *
 * CLI:    php commission-cron.php
 * HTTP:   GET api/commission-cron.php?cron_key=YOUR_KEY
 * Cron:   0 2 1 * * php /path/to/commission-cron.php  (1st of each month at 2am)
 */

$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/../config/database.php';

// Auth: CLI passes, HTTP requires cron_key
if (!$isCli) {
    $cronKey = $_GET['cron_key'] ?? '';
    // Get key from central DB
    try {
        $centralConn = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'DB connection failed']);
        exit;
    }

    $stmt = $centralConn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'cron_key'");
    $stmt->execute();
    $storedKey = $stmt->fetchColumn();

    if (empty($storedKey) || $cronKey !== $storedKey) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid cron key']);
        exit;
    }
    $central = $centralConn;
} else {
    try {
        $central = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        echo "ERROR: DB connection failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// ============================================================
// Process commissions
// ============================================================

$startTime = microtime(true);
$log = [];

function cronLog($msg) {
    global $log, $isCli;
    $ts = date('H:i:s');
    $log[] = "[$ts] $msg";
    if ($isCli) echo "[$ts] $msg\n";
}

cronLog("Starting RE commission processing...");

// Check if enabled
$enabledStmt = $central->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 're_commission_enabled'");
$enabledStmt->execute();
$enabled = $enabledStmt->fetchColumn();

if ($enabled !== '1') {
    cronLog("RE commissions are disabled. Exiting.");
    outputResult($log, 0, 0, 0);
    exit;
}

// Load settings
function getCronSetting($central, $key, $default = '') {
    $stmt = $central->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return ($val !== false) ? $val : $default;
}

$commType = getCronSetting($central, 're_commission_type', 'fixed');
$maxMonths = (int)getCronSetting($central, 're_max_commission_months', '12');

// Get all converted referrals with active subscriptions
$stmt = $central->query("
    SELECT r.*, s.net_amount, s.unit_count, s.tier, s.status AS sub_status,
           a.parent_agent_id, a.name AS agent_name
    FROM agent_referrals_re r
    INNER JOIN subscriptions s ON r.subscription_id = s.id
    INNER JOIN sales_agents a ON r.agent_id = a.id
    WHERE r.status = 'converted'
    AND s.status IN ('active', 'trial')
    AND a.status = 'active'
");
$referrals = $stmt->fetchAll();

cronLog("Found " . count($referrals) . " active converted referrals.");

$credited = 0;
$skipped = 0;
$errors = 0;

foreach ($referrals as $ref) {
    $nextMonth = $ref['subscription_month_count'] + 1;

    // Max months check
    if ($maxMonths > 0 && $nextMonth > $maxMonths) {
        cronLog("  Referral #{$ref['id']} ({$ref['agent_name']}): Skipped — month $nextMonth exceeds max $maxMonths");
        $skipped++;
        continue;
    }

    // Already processed check
    $checkStmt = $central->prepare("SELECT id FROM agent_commissions_re WHERE referral_id = ? AND month_number = ?");
    $checkStmt->execute([$ref['id'], $nextMonth]);
    if ($checkStmt->fetch()) {
        $skipped++;
        continue;
    }

    try {
        $central->beginTransaction();

        $billing = (float)$ref['net_amount'];

        // Direct commission
        $directRate = getMonthRate($central, 'direct', $nextMonth);
        $directAmount = ($commType === 'percentage') ? round($billing * ($directRate / 100), 2) : $directRate;

        if ($directAmount > 0) {
            cronCreditCommission($central, $ref['agent_id'], $ref['id'], $ref['subscription_id'],
                'direct', $nextMonth, $directAmount, $billing);
            cronLog("  Referral #{$ref['id']}: Direct TZS " . number_format($directAmount) . " to agent #{$ref['agent_id']} (month $nextMonth)");
        }

        // Override commission (parent agent)
        if ($ref['parent_agent_id']) {
            $overrideRate = getMonthRate($central, 'override', $nextMonth);
            $overrideAmount = ($commType === 'percentage') ? round($billing * ($overrideRate / 100), 2) : $overrideRate;

            if ($overrideAmount > 0) {
                cronCreditCommission($central, $ref['parent_agent_id'], $ref['id'], $ref['subscription_id'],
                    'override', $nextMonth, $overrideAmount, $billing);
                cronLog("  Referral #{$ref['id']}: Override TZS " . number_format($overrideAmount) . " to parent #{$ref['parent_agent_id']}");
            }
        }

        // Update referral
        $central->prepare("
            UPDATE agent_referrals_re SET subscription_month_count = ?, last_commission_at = NOW() WHERE id = ?
        ")->execute([$nextMonth, $ref['id']]);

        $central->commit();
        $credited++;
    } catch (Exception $e) {
        $central->rollBack();
        $errors++;
        cronLog("  ERROR Referral #{$ref['id']}: " . $e->getMessage());
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
cronLog("Done. Credited: $credited, Skipped: $skipped, Errors: $errors. Time: {$elapsed}s");

outputResult($log, $credited, $skipped, $errors);

// ============================================================
// HELPER FUNCTIONS
// ============================================================

function getMonthRate($central, $roleType, $monthNumber) {
    $prefix = ($roleType === 'direct') ? 're_commission_direct' : 're_commission_override';
    $key = ($monthNumber <= 3) ? $prefix . '_m' . $monthNumber : $prefix . '_recurring';
    return (float)getCronSetting($central, $key, '0');
}

function cronCreditCommission($central, $agentId, $referralId, $subscriptionId, $type, $month, $amount, $billing) {
    $stmt = $central->prepare("
        INSERT INTO agent_commissions_re
        (agent_id, referral_id, subscription_id, commission_type, base_amount, amount,
         subscription_billing, currency, month_number, status, credited_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'TZS', ?, 'pending', NOW())
    ");
    $stmt->execute([$agentId, $referralId, $subscriptionId, $type, $amount, $amount, $billing, $month]);
    $commId = (int)$central->lastInsertId();

    // Update wallet
    $central->prepare("
        UPDATE sales_agents SET wallet_balance_re = wallet_balance_re + ?, total_earned_re = total_earned_re + ? WHERE id = ?
    ")->execute([$amount, $amount, $agentId]);

    $balStmt = $central->prepare("SELECT wallet_balance_re FROM sales_agents WHERE id = ?");
    $balStmt->execute([$agentId]);
    $newBalance = (float)$balStmt->fetchColumn();

    $central->prepare("
        INSERT INTO agent_wallet_transactions_re
        (agent_id, type, amount, balance_after, reference_type, reference_id, description)
        VALUES (?, 'credit', ?, ?, 'commission', ?, ?)
    ")->execute([$agentId, $amount, $newBalance, $commId, ucfirst($type) . " commission — Month $month"]);
}

function outputResult($log, $credited, $skipped, $errors) {
    global $isCli;
    if (!$isCli) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'credited' => $credited,
            'skipped' => $skipped,
            'errors' => $errors,
            'log' => $log
        ]);
    }
}
