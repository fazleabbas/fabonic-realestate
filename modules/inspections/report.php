<?php
/**
 * FIS Real Estate — Inspection Report (11.4)
 * Standalone printable inspection report with side-by-side
 * move-in vs move-out comparison (when applicable).
 * Same pattern as payments/receipt.php and leases/print.php.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn()) {
    die('Unauthorized');
}
if (!hasPermission('inspections', 'view')) {
    die('No permission');
}

$inspectionId = (int)($_GET['id'] ?? 0);
if (!$inspectionId) {
    die('Inspection ID required');
}

// Fetch inspection with related data
$stmt = $conn->prepare("
    SELECT i.*, u.unit_number, u.unit_label,
           p.name AS property_name, p.address AS property_address, p.city AS property_city,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
           t.phone AS tenant_phone, t.email AS tenant_email,
           l.start_date AS lease_start, l.end_date AS lease_end
    FROM inspections i
    INNER JOIN units u ON i.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    LEFT JOIN tenants t ON i.tenant_id = t.id
    LEFT JOIN leases l ON i.lease_id = l.id
    WHERE i.id = :id
");
$stmt->execute(['id' => $inspectionId]);
$inspection = $stmt->fetch();

if (!$inspection) {
    die('Inspection not found');
}

if (!canAccessUnit($inspection['unit_id'])) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/inspections/');
}

// Fetch inspection items grouped by room
$itemStmt = $conn->prepare("SELECT * FROM inspection_items WHERE inspection_id = :iid ORDER BY room, item_name");
$itemStmt->execute(['iid' => $inspectionId]);
$items = $itemStmt->fetchAll();

$itemsByRoom = [];
foreach ($items as $item) {
    $itemsByRoom[$item['room']][] = $item;
}

// If move-out, also get the move-in inspection for comparison
$moveIn = null;
$moveInItems = [];
$moveInByKey = [];
$hasComparison = false;

if ($inspection['inspection_type'] === 'move_out' && $inspection['lease_id']) {
    $miStmt = $conn->prepare("
        SELECT i.* FROM inspections i
        WHERE i.lease_id = :lid AND i.inspection_type = 'move_in' AND i.status = 'completed'
        ORDER BY i.inspection_date DESC LIMIT 1
    ");
    $miStmt->execute(['lid' => $inspection['lease_id']]);
    $moveIn = $miStmt->fetch();

    if ($moveIn) {
        $miItemStmt = $conn->prepare("SELECT * FROM inspection_items WHERE inspection_id = :iid ORDER BY room, item_name");
        $miItemStmt->execute(['iid' => $moveIn['id']]);
        $moveInItems = $miItemStmt->fetchAll();
        $hasComparison = true;

        foreach ($moveInItems as $mi) {
            $moveInByKey[$mi['room'] . '|' . $mi['item_name']] = $mi;
        }
    }
}

// Company info
$companyName = getSetting('company_name', APP_NAME);
$companyPhone = getSetting('company_phone', '');
$companyEmail = getSetting('company_email', '');
$companyAddress = getSetting('company_address', '');

// Condition labels and colors for print
$condLabels = [
    'excellent' => __('cond_excellent', 'Excellent'),
    'good' => __('cond_good', 'Good'),
    'fair' => __('cond_fair', 'Fair'),
    'poor' => __('cond_poor', 'Poor'),
    'damaged' => __('cond_damaged', 'Damaged'),
    'missing' => __('cond_missing', 'Missing'),
];
$condColors = [
    'excellent' => '#198754', 'good' => '#0d6efd', 'fair' => '#0dcaf0',
    'poor' => '#ffc107', 'damaged' => '#dc3545', 'missing' => '#212529'
];
$condOrder = ['excellent' => 1, 'good' => 2, 'fair' => 3, 'poor' => 4, 'damaged' => 5, 'missing' => 6];

$typeLabels = [
    'move_in' => __('insp_move_in', 'Move-in'),
    'move_out' => __('insp_move_out', 'Move-out'),
    'routine' => __('insp_routine', 'Routine'),
];

$statusLabels = [
    'scheduled' => __('insp_scheduled', 'Scheduled'),
    'in_progress' => __('insp_in_progress', 'In Progress'),
    'completed' => __('insp_completed', 'Completed'),
];

// Count issues (poor/damaged/missing)
$totalItems = count($items);
$issueItems = 0;
$deteriorated = 0;
foreach ($items as $item) {
    if (in_array($item['condition'], ['poor', 'damaged', 'missing'])) {
        $issueItems++;
    }
    if ($hasComparison) {
        $key = $item['room'] . '|' . $item['item_name'];
        if (isset($moveInByKey[$key])) {
            $miOrd = $condOrder[$moveInByKey[$key]['condition']] ?? 2;
            $moOrd = $condOrder[$item['condition']] ?? 2;
            if ($moOrd > $miOrd) $deteriorated++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('insp_report', 'Inspection Report'); ?> — <?php echo htmlspecialchars($typeLabels[$inspection['inspection_type']] ?? 'Inspection'); ?> #<?php echo $inspectionId; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
            font-size: 13px;
            color: #333;
            background: #f5f5f5;
            padding: 20px;
        }
        .report-container {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            border: 2px solid #1a6b4a;
            padding: 30px;
        }

        /* Header */
        .report-header {
            text-align: center;
            border-bottom: 3px double #1a6b4a;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .report-header h1 {
            font-size: 22px;
            color: #1a6b4a;
            margin-bottom: 5px;
        }
        .report-header .company-info {
            font-size: 12px;
            color: #666;
        }

        .report-title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            color: #1a6b4a;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .report-subtitle {
            text-align: center;
            font-size: 13px;
            color: #666;
            margin-bottom: 20px;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            background: #f8f9fa;
        }
        .info-grid .info-row {
            display: flex;
            gap: 8px;
        }
        .info-grid .info-label {
            color: #666;
            font-weight: 600;
            min-width: 100px;
        }
        .info-grid .info-value {
            font-weight: 500;
        }

        /* Summary Stats */
        .stats-bar {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .stat-box {
            text-align: center;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 10px 20px;
            min-width: 120px;
        }
        .stat-box .stat-value {
            font-size: 20px;
            font-weight: bold;
        }
        .stat-box .stat-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
        }

        /* Room Tables */
        .room-section {
            margin-bottom: 15px;
            page-break-inside: avoid;
        }
        .room-header {
            background: #1a6b4a;
            color: #fff;
            padding: 6px 12px;
            font-weight: 600;
            font-size: 14px;
            border-radius: 4px 4px 0 0;
        }
        .room-table {
            width: 100%;
            border-collapse: collapse;
        }
        .room-table th {
            background: #f8f9fa;
            padding: 6px 10px;
            border: 1px solid #dee2e6;
            font-size: 12px;
            text-align: left;
            font-weight: 600;
        }
        .room-table td {
            padding: 5px 10px;
            border: 1px solid #dee2e6;
            font-size: 12px;
            vertical-align: middle;
        }
        .cond-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            color: #fff;
        }
        .cond-excellent { background: #198754; }
        .cond-good { background: #0d6efd; }
        .cond-fair { background: #0dcaf0; color: #000; }
        .cond-poor { background: #ffc107; color: #000; }
        .cond-damaged { background: #dc3545; }
        .cond-missing { background: #212529; }

        .arrow-worse {
            color: #dc3545;
            font-weight: bold;
        }
        .arrow-better {
            color: #198754;
            font-weight: bold;
        }
        .arrow-same {
            color: #6c757d;
        }

        /* Notes */
        .notes-section {
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 12px;
            background: #f8f9fa;
        }
        .notes-section h3 {
            font-size: 14px;
            color: #1a6b4a;
            margin-bottom: 8px;
        }

        /* Signatures */
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

        /* Footer */
        .report-footer {
            text-align: center;
            padding-top: 20px;
            margin-top: 20px;
            border-top: 1px dashed #ccc;
            font-size: 11px;
            color: #999;
        }

        /* Print Button */
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
        .back-btn {
            display: inline-block;
            margin: 0 auto 10px auto;
            padding: 8px 20px;
            background: #6c757d;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
        }
        .back-btn:hover { background: #565e64; }
        .btn-row {
            text-align: center;
            margin-bottom: 10px;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .report-container { border: none; max-width: 100%; padding: 15px; }
            .print-btn { display: none !important; }
            .no-print { display: none !important; }
            .room-section { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="btn-row no-print">
    <a href="<?php echo BASE_URL; ?>modules/inspections/view.php?id=<?php echo $inspectionId; ?>" class="back-btn">
        &larr; <?php echo __('back_to_inspection', 'Back to Inspection'); ?>
    </a>
    <button class="print-btn" onclick="window.print();">
        &#128424; <?php echo __('print_report', 'Print Report'); ?>
    </button>
</div>

<div class="report-container">
    <!-- Header -->
    <div class="report-header">
        <h1><?php echo htmlspecialchars($companyName); ?></h1>
        <div class="company-info">
            <?php if ($companyAddress): ?><?php echo htmlspecialchars($companyAddress); ?><br><?php endif; ?>
            <?php if ($companyPhone): ?><?php echo __('phone', 'Phone'); ?>: <?php echo htmlspecialchars($companyPhone); ?><?php endif; ?>
            <?php if ($companyPhone && $companyEmail): ?> | <?php endif; ?>
            <?php if ($companyEmail): ?><?php echo __('email', 'Email'); ?>: <?php echo htmlspecialchars($companyEmail); ?><?php endif; ?>
        </div>
    </div>

    <!-- Title -->
    <div class="report-title">
        <?php echo $typeLabels[$inspection['inspection_type']] ?? ucfirst(str_replace('_', ' ', $inspection['inspection_type'])); ?>
        <?php echo __('insp_report', 'Inspection Report'); ?>
    </div>
    <div class="report-subtitle">
        <?php echo __('insp_report_id', 'Report'); ?> #<?php echo $inspectionId; ?>
        &mdash;
        <span style="color: <?php echo $inspection['status'] === 'completed' ? '#198754' : '#ffc107'; ?>; font-weight:600;">
            <?php echo $statusLabels[$inspection['status']] ?? ucfirst($inspection['status']); ?>
        </span>
    </div>

    <!-- Info Grid -->
    <div class="info-grid">
        <div class="info-row">
            <span class="info-label"><?php echo __('insp_date', 'Inspection Date'); ?>:</span>
            <span class="info-value"><?php echo formatDate($inspection['inspection_date']); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label"><?php echo __('inspector', 'Inspector'); ?>:</span>
            <span class="info-value"><?php echo htmlspecialchars($inspection['inspector_name'] ?: '—'); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label"><?php echo __('tenant', 'Tenant'); ?>:</span>
            <span class="info-value"><?php echo htmlspecialchars($inspection['tenant_name'] ?: '—'); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label"><?php echo __('phone', 'Phone'); ?>:</span>
            <span class="info-value"><?php echo htmlspecialchars($inspection['tenant_phone'] ?: '—'); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label"><?php echo __('property'); ?>:</span>
            <span class="info-value"><?php echo htmlspecialchars($inspection['property_name']); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label"><?php echo __('unit_number', 'Unit'); ?>:</span>
            <span class="info-value"><?php echo htmlspecialchars($inspection['unit_number']); ?><?php echo $inspection['unit_label'] ? ' (' . htmlspecialchars($inspection['unit_label']) . ')' : ''; ?></span>
        </div>
        <?php if ($inspection['lease_start']): ?>
        <div class="info-row">
            <span class="info-label"><?php echo __('lease_period', 'Lease Period'); ?>:</span>
            <span class="info-value"><?php echo formatDate($inspection['lease_start']); ?> — <?php echo formatDate($inspection['lease_end']); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($hasComparison && $moveIn): ?>
        <div class="info-row">
            <span class="info-label"><?php echo __('insp_movein_date', 'Move-in Date'); ?>:</span>
            <span class="info-value"><?php echo formatDate($moveIn['inspection_date']); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Summary Stats -->
    <div class="stats-bar">
        <div class="stat-box">
            <div class="stat-value"><?php echo $totalItems; ?></div>
            <div class="stat-label"><?php echo __('insp_total_items', 'Total Items'); ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: <?php echo $issueItems > 0 ? '#dc3545' : '#198754'; ?>;"><?php echo $issueItems; ?></div>
            <div class="stat-label"><?php echo __('insp_issues', 'Issues Found'); ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?php echo count($itemsByRoom); ?></div>
            <div class="stat-label"><?php echo __('insp_rooms', 'Rooms'); ?></div>
        </div>
        <?php if ($hasComparison): ?>
        <div class="stat-box">
            <div class="stat-value" style="color: <?php echo $deteriorated > 0 ? '#dc3545' : '#198754'; ?>;"><?php echo $deteriorated; ?></div>
            <div class="stat-label"><?php echo __('insp_deteriorated', 'Deteriorated'); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Room-by-Room Checklist -->
    <?php foreach ($itemsByRoom as $roomName => $roomItems): ?>
    <div class="room-section">
        <div class="room-header"><?php echo htmlspecialchars($roomName); ?> (<?php echo count($roomItems); ?> <?php echo __('insp_items', 'items'); ?>)</div>
        <table class="room-table">
            <thead>
                <tr>
                    <th style="width:25%;"><?php echo __('insp_item', 'Item'); ?></th>
                    <?php if ($hasComparison): ?>
                    <th style="width:15%;"><?php echo __('insp_movein_condition', 'Move-in'); ?></th>
                    <th style="width:5%; text-align:center;">&nbsp;</th>
                    <th style="width:15%;"><?php echo __('insp_moveout_condition', 'Move-out'); ?></th>
                    <?php else: ?>
                    <th style="width:15%;"><?php echo __('condition', 'Condition'); ?></th>
                    <?php endif; ?>
                    <th><?php echo __('notes', 'Notes'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roomItems as $item):
                    $key = $item['room'] . '|' . $item['item_name'];
                    $miItem = $moveInByKey[$key] ?? null;
                    $miOrd = $miItem ? ($condOrder[$miItem['condition']] ?? 2) : null;
                    $moOrd = $condOrder[$item['condition']] ?? 2;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <?php if ($hasComparison): ?>
                    <td>
                        <?php if ($miItem): ?>
                        <span class="cond-badge cond-<?php echo $miItem['condition']; ?>"><?php echo $condLabels[$miItem['condition']] ?? ucfirst($miItem['condition']); ?></span>
                        <?php else: ?>
                        <span style="color:#999;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($miItem):
                            if ($moOrd > $miOrd): ?>
                                <span class="arrow-worse">&darr;</span>
                            <?php elseif ($moOrd < $miOrd): ?>
                                <span class="arrow-better">&uarr;</span>
                            <?php else: ?>
                                <span class="arrow-same">=</span>
                            <?php endif;
                        endif; ?>
                    </td>
                    <td>
                        <span class="cond-badge cond-<?php echo $item['condition']; ?>"><?php echo $condLabels[$item['condition']] ?? ucfirst($item['condition']); ?></span>
                    </td>
                    <?php else: ?>
                    <td>
                        <span class="cond-badge cond-<?php echo $item['condition']; ?>"><?php echo $condLabels[$item['condition']] ?? ucfirst($item['condition']); ?></span>
                    </td>
                    <?php endif; ?>
                    <td><small><?php echo htmlspecialchars($item['notes'] ?? ''); ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <!-- Overall Notes -->
    <?php if ($inspection['overall_notes']): ?>
    <div class="notes-section">
        <h3><?php echo __('insp_overall_notes', 'Overall Notes'); ?></h3>
        <p><?php echo nl2br(htmlspecialchars($inspection['overall_notes'])); ?></p>
    </div>
    <?php endif; ?>

    <!-- Condition Legend -->
    <div style="margin-bottom:20px; text-align:center;">
        <small style="color:#666; font-weight:600;"><?php echo __('insp_condition_legend', 'Condition Legend'); ?>:</small>
        <?php foreach ($condLabels as $key => $label): ?>
        <span class="cond-badge cond-<?php echo $key; ?>" style="margin:2px;"><?php echo $label; ?></span>
        <?php endforeach; ?>
        <?php if ($hasComparison): ?>
        <br><small style="color:#666; margin-top:4px; display:inline-block;">
            <span class="arrow-worse">&darr;</span> = <?php echo __('insp_condition_worse', 'Condition worsened'); ?>
            &nbsp;|&nbsp;
            <span class="arrow-better">&uarr;</span> = <?php echo __('insp_condition_better', 'Condition improved'); ?>
            &nbsp;|&nbsp;
            <span class="arrow-same">=</span> = <?php echo __('insp_condition_same', 'No change'); ?>
        </small>
        <?php endif; ?>
    </div>

    <!-- Signature Lines -->
    <div class="signature-line">
        <div>
            <div class="line"><?php echo __('inspector_signature', 'Inspector Signature'); ?></div>
        </div>
        <div>
            <div class="line"><?php echo __('tenant_signature', 'Tenant Signature'); ?></div>
        </div>
    </div>

    <!-- Footer -->
    <div class="report-footer">
        <?php echo __('insp_report_generated', 'Report generated on'); ?>: <?php echo date(DATETIME_FORMAT); ?><br>
        <?php echo htmlspecialchars($companyName); ?> — <?php echo APP_TAGLINE; ?>
    </div>

    <!-- Fabonic Branding -->
    <div style="text-align:center; margin-top:12px; padding-top:10px; border-top:1px solid #e9ecef; font-size:10px; color:#adb5bd;">
        Powered by <span style="color:#1a6b4a; font-weight:600;">Fabonic</span> | realestate.fis.co.tz
    </div>
</div>

</body>
</html>
