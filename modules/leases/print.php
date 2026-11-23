<?php
/**
 * FIS Real Estate — Printable Lease Document (8.3)
 * Generates a printable HTML page with lease data populated into template.
 * User can print to PDF via browser print dialog.
 * If no template exists, renders a default simple document.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Must be logged in
if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

if (!hasPermission('leases', 'view')) {
    die('No permission');
}

$leaseId = (int)($_GET['lease_id'] ?? 0);
$templateId = (int)($_GET['template_id'] ?? 0);

if (!$leaseId) {
    die('Lease ID required');
}

// Get lease with full details
$stmt = $conn->prepare("
    SELECT l.*, u.unit_number, u.unit_label, u.unit_type,
           p.name AS property_name, p.address AS property_address, p.city AS property_city,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
           t.phone AS tenant_phone, t.email AS tenant_email,
           t.id_type AS tenant_id_type, t.id_number AS tenant_id_number
    FROM leases l
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON l.tenant_id = t.id
    WHERE l.id = :id
");
$stmt->execute(['id' => $leaseId]);
$lease = $stmt->fetch();

if (!$lease) {
    die('Lease not found');
}

// Property-based access scoping guard
if (!canAccessLease($leaseId)) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/leases/');
}

// Get template
$template = null;
if ($templateId > 0) {
    $tplStmt = $conn->prepare("SELECT * FROM lease_templates WHERE id = :id");
    $tplStmt->execute(['id' => $templateId]);
    $template = $tplStmt->fetch();
} else {
    $tplStmt = $conn->prepare("SELECT * FROM lease_templates WHERE is_default = 1 LIMIT 1");
    $tplStmt->execute();
    $template = $tplStmt->fetch();
}

// Get company info
$companyName = getSetting('company_name', APP_NAME);
$companyPhone = getSetting('company_phone', '');
$companyEmail = getSetting('company_email', '');
$companyAddress = getSetting('company_address', '');

// Placeholder replacement
$placeholders = [
    '{{tenant_name}}' => htmlspecialchars($lease['tenant_name']),
    '{{tenant_phone}}' => htmlspecialchars($lease['tenant_phone']),
    '{{tenant_email}}' => htmlspecialchars($lease['tenant_email'] ?? ''),
    '{{tenant_id_type}}' => htmlspecialchars(ucfirst(str_replace('_', ' ', $lease['tenant_id_type'] ?? ''))),
    '{{tenant_id_number}}' => htmlspecialchars($lease['tenant_id_number'] ?? ''),
    '{{unit_number}}' => htmlspecialchars($lease['unit_number']),
    '{{unit_type}}' => htmlspecialchars(ucfirst($lease['unit_type'])),
    '{{property_name}}' => htmlspecialchars($lease['property_name']),
    '{{property_address}}' => htmlspecialchars($lease['property_address'] ?? ''),
    '{{property_city}}' => htmlspecialchars($lease['property_city'] ?? ''),
    '{{rent_amount}}' => formatCurrency($lease['rent_amount'], $lease['currency']),
    '{{deposit_amount}}' => formatCurrency($lease['deposit_amount'], $lease['deposit_currency']),
    '{{payment_frequency}}' => paymentFrequencyLabel($lease['payment_frequency']),
    '{{payment_due_day}}' => $lease['payment_due_day'],
    '{{start_date}}' => formatDate($lease['start_date']),
    '{{end_date}}' => formatDate($lease['end_date']),
    '{{terms}}' => nl2br(htmlspecialchars($lease['terms'] ?? '')),
    '{{company_name}}' => htmlspecialchars($companyName),
    '{{company_phone}}' => htmlspecialchars($companyPhone),
    '{{company_email}}' => htmlspecialchars($companyEmail),
    '{{company_address}}' => htmlspecialchars($companyAddress),
    '{{date_today}}' => formatDate(date('Y-m-d')),
    '{{lease_id}}' => $leaseId,
];

// Build document body
if ($template) {
    $documentBody = str_replace(array_keys($placeholders), array_values($placeholders), $template['content']);
} else {
    // Default template if none exist
    $documentBody = buildDefaultTemplate($lease, $placeholders, $companyName);
}

// Save document_path reference (optional)
// We don't save a file here — just generate HTML for printing.

/**
 * Build a default lease document when no template exists
 */
function buildDefaultTemplate($lease, $placeholders, $companyName) {
    $html = '
    <div style="text-align:center;margin-bottom:30px;">
        <h1 style="font-size:24px;margin-bottom:5px;">' . htmlspecialchars($companyName) . '</h1>
        <h2 style="font-size:18px;color:#555;margin-top:0;">LEASE AGREEMENT</h2>
        <hr style="border:2px solid #1a6b4a;">
    </div>

    <p>This Lease Agreement is entered into on <strong>' . $placeholders['{{date_today}}'] . '</strong>.</p>

    <h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">1. PARTIES</h3>
    <p><strong>Landlord:</strong> ' . $placeholders['{{company_name}}'] . '</p>
    <p><strong>Tenant:</strong> ' . $placeholders['{{tenant_name}}'] . '<br>
    Phone: ' . $placeholders['{{tenant_phone}}'] . '<br>
    Email: ' . $placeholders['{{tenant_email}}'] . '</p>
    ' . ($placeholders['{{tenant_id_type}}'] ? '<p>ID: ' . $placeholders['{{tenant_id_type}}'] . ' — ' . $placeholders['{{tenant_id_number}}'] . '</p>' : '') . '

    <h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">2. PROPERTY</h3>
    <p><strong>Property:</strong> ' . $placeholders['{{property_name}}'] . '<br>
    Address: ' . $placeholders['{{property_address}}'] . '<br>
    Unit: ' . $placeholders['{{unit_number}}'] . ' (' . $placeholders['{{unit_type}}'] . ')</p>

    <h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">3. LEASE TERM</h3>
    <p>From: <strong>' . $placeholders['{{start_date}}'] . '</strong> To: <strong>' . $placeholders['{{end_date}}'] . '</strong></p>

    <h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">4. RENT</h3>
    <p>Rent Amount: <strong>' . $placeholders['{{rent_amount}}'] . '</strong> (' . $placeholders['{{payment_frequency}}'] . ')<br>
    Payment Due: Day <strong>' . $placeholders['{{payment_due_day}}'] . '</strong> of each period</p>

    <h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">5. SECURITY DEPOSIT</h3>
    <p>Deposit Amount: <strong>' . $placeholders['{{deposit_amount}}'] . '</strong></p>
    ';

    if ($placeholders['{{terms}}']) {
        $html .= '
    <h3 style="font-size:16px;color:#1a6b4a;border-bottom:1px solid #ccc;padding-bottom:5px;">6. ADDITIONAL TERMS</h3>
    <p>' . $placeholders['{{terms}}'] . '</p>
        ';
    }

    $html .= '
    <div style="margin-top:60px;">
        <div style="display:inline-block;width:45%;vertical-align:top;">
            <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
                <strong>Landlord Signature</strong><br>
                ' . $placeholders['{{company_name}}'] . '<br>
                Date: ____________________
            </p>
        </div>
        <div style="display:inline-block;width:45%;vertical-align:top;float:right;">
            <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
                <strong>Tenant Signature</strong><br>
                ' . $placeholders['{{tenant_name}}'] . '<br>
                Date: ____________________
            </p>
        </div>
    </div>
    ';

    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('lease_document', 'Lease Agreement'); ?> #<?php echo $leaseId; ?></title>
    <style>
        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none !important; }
            @page { margin: 20mm; }
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 40px 30px;
            background: #fff;
        }
        h1, h2, h3 { margin-top: 20px; }
        .no-print {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 999;
            display: flex;
            gap: 8px;
        }
        .no-print button, .no-print a {
            padding: 8px 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            background: #fff;
            color: #333;
        }
        .no-print button:hover, .no-print a:hover {
            background: #f0f0f0;
        }
        .no-print .btn-print {
            background: #1a6b4a;
            color: #fff;
            border-color: #1a6b4a;
        }
        .no-print .btn-print:hover {
            background: #155a3e;
        }
    </style>
</head>
<body>

<!-- Print/Close Buttons -->
<div class="no-print">
    <button class="btn-print" onclick="window.print()">
        Print / Save PDF
    </button>
    <a href="<?php echo BASE_URL; ?>modules/leases/view.php?id=<?php echo $leaseId; ?>">
        Back to Lease
    </a>
</div>

<!-- Document Content -->
<?php echo $documentBody; ?>

</body>
</html>
