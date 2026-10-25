<?php
/**
 * FIS Real Estate — Print Receipt (9.3)
 * Standalone printable receipt page (like lease print.php).
 * Opens in new tab, uses @media print for clean output.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn()) {
    die('Unauthorized');
}
if (!hasPermission('payments', 'view')) {
    die('No permission');
}

$paymentId = (int)($_GET['payment_id'] ?? 0);
if (!$paymentId) {
    die('Payment ID required');
}

// Fetch payment with all related data
$stmt = $conn->prepare("
    SELECT py.*, u.unit_number, u.unit_label,
           p.name AS property_name, p.address AS property_address, p.city AS property_city,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
           t.phone AS tenant_phone, t.email AS tenant_email,
           t.id_number AS tenant_id_number,
           l.rent_amount AS lease_rent, l.payment_frequency AS lease_frequency,
           l.start_date AS lease_start, l.end_date AS lease_end
    FROM payments py
    INNER JOIN units u ON py.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON py.tenant_id = t.id
    LEFT JOIN leases l ON py.lease_id = l.id
    WHERE py.id = :id
");
$stmt->execute(['id' => $paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    die('Payment not found');
}

if (!canAccessUnit($payment['unit_id'])) {
    die('No access');
}

// Get or create receipt
$rcptStmt = $conn->prepare("SELECT * FROM receipts WHERE payment_id = :pid");
$rcptStmt->execute(['pid' => $paymentId]);
$receipt = $rcptStmt->fetch();

if (!$receipt) {
    // Auto-generate
    $prefix = getSetting('receipt_prefix', 'RCT-');
    $nextNum = (int)getSetting('receipt_next_number', 1);
    $receiptNumber = $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
    updateSetting('receipt_next_number', $nextNum + 1);

    $conn->prepare("INSERT INTO receipts (payment_id, receipt_number) VALUES (:pid, :rnum)")
        ->execute(['pid' => $paymentId, 'rnum' => $receiptNumber]);
    $receipt = ['receipt_number' => $receiptNumber];
}

// Company info
$companyName = getSetting('company_name', APP_NAME);
$companyPhone = getSetting('company_phone', '');
$companyEmail = getSetting('company_email', '');
$companyAddress = getSetting('company_address', '');

$methodLabels = [
    'cash' => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'mobile_money' => 'Mobile Money',
    'cheque' => 'Cheque',
    'gateway' => 'Payment Gateway',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('receipt', 'Receipt'); ?> — <?php echo htmlspecialchars($receipt['receipt_number']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
            font-size: 14px;
            color: #333;
            background: #f5f5f5;
            padding: 20px;
        }
        .receipt-container {
            max-width: 700px;
            margin: 0 auto;
            background: #fff;
            border: 2px solid #1a6b4a;
            padding: 30px;
        }
        .receipt-header {
            text-align: center;
            border-bottom: 3px double #1a6b4a;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .receipt-header h1 {
            font-size: 22px;
            color: #1a6b4a;
            margin-bottom: 5px;
        }
        .receipt-header .company-info {
            font-size: 12px;
            color: #666;
        }
        .receipt-title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            color: #1a6b4a;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .receipt-number {
            text-align: center;
            font-size: 14px;
            color: #666;
            margin-bottom: 20px;
        }
        .receipt-status {
            text-align: center;
            padding: 4px 12px;
            border-radius: 4px;
            display: inline-block;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .receipt-status.recorded { background: #dcfce7; color: #166534; }
        .receipt-status.reversed { background: #fecaca; color: #991b1b; }
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .details-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        .details-table td:first-child {
            width: 160px;
            color: #666;
            font-weight: 600;
        }
        .amount-box {
            background: #f8fdf8;
            border: 2px solid #1a6b4a;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 20px 0;
        }
        .amount-box .label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .amount-box .value {
            font-size: 28px;
            font-weight: bold;
            color: #1a6b4a;
        }
        .receipt-footer {
            text-align: center;
            padding-top: 20px;
            margin-top: 20px;
            border-top: 1px dashed #ccc;
            font-size: 12px;
            color: #999;
        }
        .signature-line {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        .signature-line div {
            width: 200px;
            text-align: center;
        }
        .signature-line .line {
            border-top: 1px solid #333;
            margin-top: 40px;
            padding-top: 5px;
            font-size: 12px;
            color: #666;
        }
        .print-btn {
            display: block;
            margin: 20px auto;
            padding: 10px 30px;
            background: #1a6b4a;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .print-btn:hover { background: #145839; }

        @media print {
            body { background: #fff; padding: 0; }
            .receipt-container { border: none; max-width: 100%; padding: 15px; }
            .print-btn { display: none !important; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<button class="print-btn no-print" onclick="window.print();">
    <span style="margin-right:5px;">&#128424;</span> <?php echo __('print_receipt', 'Print Receipt'); ?>
</button>

<div class="receipt-container">
    <!-- Header -->
    <div class="receipt-header">
        <h1><?php echo htmlspecialchars($companyName); ?></h1>
        <div class="company-info">
            <?php if ($companyAddress): ?><?php echo htmlspecialchars($companyAddress); ?><br><?php endif; ?>
            <?php if ($companyPhone): ?><?php echo __('phone', 'Phone'); ?>: <?php echo htmlspecialchars($companyPhone); ?><?php endif; ?>
            <?php if ($companyPhone && $companyEmail): ?> | <?php endif; ?>
            <?php if ($companyEmail): ?><?php echo __('email', 'Email'); ?>: <?php echo htmlspecialchars($companyEmail); ?><?php endif; ?>
        </div>
    </div>

    <!-- Title -->
    <div class="receipt-title"><?php echo __('payment_receipt', 'Payment Receipt'); ?></div>
    <div class="receipt-number">
        <?php echo htmlspecialchars($receipt['receipt_number']); ?>
        <span class="receipt-status <?php echo $payment['status']; ?>"><?php echo ucfirst($payment['status']); ?></span>
    </div>

    <!-- Amount Box -->
    <div class="amount-box">
        <div class="label"><?php echo __('amount_paid', 'Amount Paid'); ?></div>
        <div class="value <?php echo $payment['status'] === 'reversed' ? 'text-decoration-line-through' : ''; ?>">
            <?php echo $payment['currency']; ?> <?php echo number_format($payment['amount'], 0); ?>
        </div>
    </div>

    <!-- Details -->
    <table class="details-table">
        <tr>
            <td><?php echo __('receipt_number', 'Receipt #'); ?></td>
            <td><strong><?php echo htmlspecialchars($receipt['receipt_number']); ?></strong></td>
        </tr>
        <tr>
            <td><?php echo __('payment_date', 'Payment Date'); ?></td>
            <td><?php echo formatDate($payment['payment_date']); ?></td>
        </tr>
        <tr>
            <td><?php echo __('tenant', 'Tenant'); ?></td>
            <td><strong><?php echo htmlspecialchars($payment['tenant_name']); ?></strong>
                <?php if ($payment['tenant_phone']): ?><br><small><?php echo htmlspecialchars($payment['tenant_phone']); ?></small><?php endif; ?>
            </td>
        </tr>
        <tr>
            <td><?php echo __('property'); ?></td>
            <td><?php echo htmlspecialchars($payment['property_name']); ?></td>
        </tr>
        <tr>
            <td><?php echo __('unit_number', 'Unit'); ?></td>
            <td><?php echo htmlspecialchars($payment['unit_number']); ?><?php echo $payment['unit_label'] ? ' (' . htmlspecialchars($payment['unit_label']) . ')' : ''; ?></td>
        </tr>
        <tr>
            <td><?php echo __('payment_method', 'Method'); ?></td>
            <td><?php echo $methodLabels[$payment['payment_method']] ?? ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
        </tr>
        <?php if ($payment['reference_number']): ?>
        <tr>
            <td><?php echo __('reference_number', 'Reference'); ?></td>
            <td><?php echo htmlspecialchars($payment['reference_number']); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($payment['period_from']): ?>
        <tr>
            <td><?php echo __('period_covered', 'Period Covered'); ?></td>
            <td><?php echo formatDate($payment['period_from']); ?> — <?php echo formatDate($payment['period_to']); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($payment['lease_rent']): ?>
        <tr>
            <td><?php echo __('lease_rent', 'Lease Rent'); ?></td>
            <td><?php echo $payment['currency']; ?> <?php echo number_format($payment['lease_rent'], 0); ?>/<?php echo $payment['lease_frequency']; ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($payment['notes']): ?>
        <tr>
            <td><?php echo __('notes', 'Notes'); ?></td>
            <td><?php echo htmlspecialchars($payment['notes']); ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <!-- Signature Lines -->
    <div class="signature-line">
        <div>
            <div class="line"><?php echo __('received_by', 'Received By'); ?></div>
        </div>
        <div>
            <div class="line"><?php echo __('tenant_signature', 'Tenant Signature'); ?></div>
        </div>
    </div>

    <!-- Footer -->
    <div class="receipt-footer">
        <?php echo __('receipt_thank_you', 'Thank you for your payment.'); ?><br>
        <?php echo __('receipt_generated_on', 'Generated on'); ?>: <?php echo date(DATETIME_FORMAT); ?><br>
        <?php echo htmlspecialchars($companyName); ?> — <?php echo APP_TAGLINE; ?>
    </div>

    <!-- Fabonic Branding -->
    <div style="text-align:center; margin-top:12px; padding-top:10px; border-top:1px solid #e9ecef; font-size:10px; color:#adb5bd;">
        Powered by <span style="color:#1a6b4a; font-weight:600;">Fabonic</span> | realestate.fis.co.tz
    </div>
</div>

</body>
</html>
