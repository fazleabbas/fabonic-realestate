<?php
/**
 * FIS Real Estate — Late Fee Cron (10.5)
 * Standalone cron script to automatically apply late fees to overdue leases.
 *
 * Usage: Set up a cron job to run this script daily:
 *   0 8 * * * php /path/to/realestate/api/late-fee-cron.php
 *
 * Or call via HTTP with the cron key:
 *   GET /api/late-fee-cron.php?key=YOUR_CRON_KEY
 */

// Allow CLI or authenticated HTTP
$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

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

// NOTE: do NOT include deposits.php here — it's an auth-gated API endpoint that
// runs its own dispatch on include and would abort this cron with "Unauthorized".
// cronApplyLateFees() below is fully self-contained (uses $conn + getSetting +
// logActivity, already available from config/database.php and functions.php).

/**
 * Apply late fees to all overdue active leases.
 * This is a standalone version for the cron context.
 */
function cronApplyLateFees($conn) {
    $enabled = getSetting('late_fee_enabled', '0');
    if ($enabled !== '1') {
        return ['applied' => 0, 'skipped' => 0, 'message' => 'Late fees disabled'];
    }

    $feeType = getSetting('late_fee_type', 'percentage');
    $feeValue = (float)getSetting('late_fee_value', '5');
    $graceDays = (int)getSetting('grace_period_days', '5');
    $maxFee = (float)getSetting('max_late_fee', '0');

    if ($feeValue <= 0) {
        return ['applied' => 0, 'skipped' => 0, 'message' => 'Fee value is 0'];
    }

    $today = date('Y-m-d');

    // Get active leases with their payment info
    $leases = $conn->query("
        SELECT l.id AS lease_id, l.tenant_id, l.rent_amount, l.currency,
               l.payment_due_day, l.payment_frequency, l.start_date, l.end_date,
               u.property_id
        FROM leases l
        INNER JOIN units u ON l.unit_id = u.id
        WHERE l.status = 'active'
    ")->fetchAll();

    $applied = 0;
    $skipped = 0;

    foreach ($leases as $lease) {
        $leaseId = $lease['lease_id'];
        $tenantId = $lease['tenant_id'];
        $rentAmount = (float)$lease['rent_amount'];
        $dueDay = (int)$lease['payment_due_day'];
        $currency = $lease['currency'];

        // Determine the current period's due date
        $dueDate = date('Y-m-') . str_pad($dueDay, 2, '0', STR_PAD_LEFT);
        if ($dueDate > $today) {
            $dueDate = date('Y-m-', strtotime('-1 month')) . str_pad($dueDay, 2, '0', STR_PAD_LEFT);
        }

        $periodRef = date('Y-m', strtotime($dueDate));

        // Check grace period
        $graceEnd = date('Y-m-d', strtotime($dueDate . ' + ' . $graceDays . ' days'));
        if ($today <= $graceEnd) {
            $skipped++;
            continue;
        }

        // Check if already has a late fee for this period
        $existCheck = $conn->prepare("SELECT id FROM late_fees WHERE lease_id = :lid AND period_reference = :pref");
        $existCheck->execute(['lid' => $leaseId, 'pref' => $periodRef]);
        if ($existCheck->fetch()) {
            $skipped++;
            continue;
        }

        // Part 31: overdue is now decided by the CHARGE for this period, not by
        // guessing from payment periods. A charge knows exactly how much of it
        // remains unsettled, so a partial payment no longer looks like none and
        // an overpayment elsewhere no longer masks this period.
        $chargeCheck = $conn->prepare("
            SELECT COALESCE(SUM(c.amount - c.amount_settled), 0)
            FROM charges c
            WHERE c.lease_id = :lid AND c.type = 'rent'
              AND c.status IN ('open','part')
              AND c.due_date = :due
        ");
        $chargeCheck->execute(['lid' => $leaseId, 'due' => $dueDate]);
        $stillOwed = (float) $chargeCheck->fetchColumn();

        // No charge for this period means the generator has not reached it yet;
        // charging a late fee for rent we never billed would be wrong.
        $hasCharge = $conn->prepare("SELECT COUNT(*) FROM charges
                                     WHERE lease_id = ? AND type = 'rent' AND due_date = ?");
        $hasCharge->execute([$leaseId, $dueDate]);
        if (!(int) $hasCharge->fetchColumn()) {
            $skipped++;
            continue;
        }

        $paidAmount = $rentAmount - $stillOwed;

        if ($paidAmount >= $rentAmount) {
            $skipped++;
            continue;
        }

        // Calculate fee — start with system defaults
        $feeAmount = 0;
        $usedType = $feeType;
        $usedValue = $feeValue;
        $usedMax = $maxFee;

        // Check property-level overrides
        $propSettings = $conn->prepare("SELECT * FROM property_settings WHERE property_id = :pid");
        $propSettings->execute(['pid' => $lease['property_id']]);
        $ps = $propSettings->fetch();
        if ($ps && $ps['late_fee_type'] !== 'none' && (float)$ps['late_fee_value'] > 0) {
            $usedType = $ps['late_fee_type'];
            $usedValue = (float)$ps['late_fee_value'];
            $usedMax = $ps['max_late_fee'] ? (float)$ps['max_late_fee'] : 0;
        }

        if ($usedType === 'percentage') {
            $feeAmount = ($rentAmount * $usedValue) / 100;
        } else {
            $feeAmount = $usedValue;
        }

        // Apply max cap
        if ($usedMax > 0 && $feeAmount > $usedMax) {
            $feeAmount = $usedMax;
        }

        if ($feeAmount <= 0) {
            $skipped++;
            continue;
        }

        // Insert late fee
        try {
            $conn->prepare("
                INSERT INTO late_fees (lease_id, tenant_id, amount, currency, fee_date, period_reference, status)
                VALUES (:lease_id, :tenant_id, :amount, :currency, :fee_date, :period_reference, 'pending')
            ")->execute([
                'lease_id' => $leaseId,
                'tenant_id' => $tenantId,
                'amount' => $feeAmount,
                'currency' => $currency,
                'fee_date' => $today,
                'period_reference' => $periodRef,
            ]);

            logActivity('created', 'late_fee', (int)$conn->lastInsertId(), [
                'lease_id' => $leaseId,
                'amount' => $feeAmount,
                'period' => $periodRef,
                'auto' => true,
                'cron' => true
            ]);

            $applied++;
        } catch (PDOException $e) {
            error_log("Late fee cron insert error: " . $e->getMessage());
            $skipped++;
        }
    }

    return ['applied' => $applied, 'skipped' => $skipped];
}

// Run the late fee application
$result = cronApplyLateFees($conn);

$output = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'applied' => $result['applied'],
    'skipped' => $result['skipped'],
    'message' => $result['message'] ?? ($result['applied'] . ' late fee(s) applied, ' . $result['skipped'] . ' skipped')
];

if ($isCli) {
    echo "[" . date('Y-m-d H:i:s') . "] Late Fee Cron: " . $output['message'] . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($output);
}
