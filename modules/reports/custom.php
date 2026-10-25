<?php
/**
 * FIS Real Estate — Custom Report Builder (Part 13.5)
 * Flexible date ranges, property/unit filters, multiple report types, print-friendly views.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('rpt_custom', 'Custom Report Builder');

$subStatus = enforceSubscription();

if (!hasPermission('reports', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Report parameters
$reportType = trim($_GET['report_type'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));
$filterProperty = (int)($_GET['property_id'] ?? 0);
$filterUnit = (int)($_GET['unit_id'] ?? 0);
$isPrint = isset($_GET['print']);

$reportData = [];
$reportTitle = '';
$reportGenerated = false;

if ($reportType) {
    $reportGenerated = true;

    switch ($reportType) {
        case 'payments':
            $reportTitle = __('rpt_cr_payments', 'Payment Report');
            $where = "p.status = 'recorded' AND p.payment_date BETWEEN :df AND :dt" . propertyScopeSql('u.property_id');
            $params = ['df' => $dateFrom, 'dt' => $dateTo];
            if ($filterProperty > 0) { $where .= " AND u.property_id = :pid"; $params['pid'] = $filterProperty; }
            if ($filterUnit > 0) { $where .= " AND p.unit_id = :uid"; $params['uid'] = $filterUnit; }

            $stmt = $conn->prepare("
                SELECT p.id, p.payment_date, p.amount, p.currency, p.payment_method, p.reference_number,
                       p.period_from, p.period_to,
                       u.unit_number, pr.name AS property_name,
                       CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                       r.receipt_number
                FROM payments p
                INNER JOIN units u ON p.unit_id = u.id
                INNER JOIN properties pr ON u.property_id = pr.id
                INNER JOIN tenants t ON p.tenant_id = t.id
                LEFT JOIN receipts r ON r.payment_id = p.id
                WHERE {$where}
                ORDER BY p.payment_date DESC
            ");
            $stmt->execute($params);
            $reportData = $stmt->fetchAll();
            break;

        case 'leases':
            $reportTitle = __('rpt_cr_leases', 'Lease Report');
            $where = "1=1" . propertyScopeSql('u.property_id');
            $params = [];
            if ($filterProperty > 0) { $where .= " AND u.property_id = :pid"; $params['pid'] = $filterProperty; }
            if ($filterUnit > 0) { $where .= " AND l.unit_id = :uid"; $params['uid'] = $filterUnit; }

            $stmt = $conn->prepare("
                SELECT l.id, l.status, l.start_date, l.end_date, l.rent_amount, l.currency,
                       l.payment_frequency, l.deposit_amount,
                       u.unit_number, p.name AS property_name,
                       CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
                       (SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay WHERE pay.lease_id = l.id AND pay.status = 'recorded') AS total_paid
                FROM leases l
                INNER JOIN units u ON l.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                INNER JOIN tenants t ON l.tenant_id = t.id
                WHERE {$where}
                ORDER BY l.start_date DESC
            ");
            $stmt->execute($params);
            $reportData = $stmt->fetchAll();
            break;

        case 'tenants':
            $reportTitle = __('rpt_cr_tenants', 'Tenant Report');
            $stmt = $conn->prepare("
                SELECT t.id, CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                       t.phone, t.email, t.status, t.move_in_date, t.move_out_date,
                       t.id_type, t.id_number, t.occupation,
                       (SELECT COUNT(*) FROM leases l WHERE l.tenant_id = t.id AND l.status = 'active') AS active_leases,
                       (SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay WHERE pay.tenant_id = t.id AND pay.status = 'recorded') AS total_paid
                FROM tenants t
                WHERE 1=1" . tenantScopeSql('t.id') . "
                ORDER BY t.first_name, t.last_name
            ");
            $stmt->execute();
            $reportData = $stmt->fetchAll();
            break;

        case 'deposits':
            $reportTitle = __('rpt_cr_deposits', 'Deposit Report');
            $where = "1=1" . propertyScopeSql('u.property_id');
            $params = [];
            if ($filterProperty > 0) { $where .= " AND u.property_id = :pid"; $params['pid'] = $filterProperty; }

            $stmt = $conn->prepare("
                SELECT d.id, d.amount, d.currency, d.status, d.paid_date,
                       d.refund_amount, d.refund_date,
                       u.unit_number, p.name AS property_name,
                       CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                       (SELECT COALESCE(SUM(dd.amount), 0) FROM deposit_deductions dd WHERE dd.deposit_id = d.id) AS total_deductions
                FROM deposits d
                INNER JOIN units u ON d.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                INNER JOIN tenants t ON d.tenant_id = t.id
                WHERE {$where}
                ORDER BY d.paid_date DESC
            ");
            $stmt->execute($params);
            $reportData = $stmt->fetchAll();
            break;

        case 'late_fees':
            $reportTitle = __('rpt_cr_late_fees', 'Late Fee Report');
            $where = "lf.fee_date BETWEEN :df AND :dt" . propertyScopeSql('u.property_id');
            $params = ['df' => $dateFrom, 'dt' => $dateTo];
            if ($filterProperty > 0) { $where .= " AND u.property_id = :pid"; $params['pid'] = $filterProperty; }

            $stmt = $conn->prepare("
                SELECT lf.id, lf.amount, lf.currency, lf.fee_date, lf.status, lf.period_reference,
                       u.unit_number, p.name AS property_name,
                       CONCAT(t.first_name, ' ', t.last_name) AS tenant_name
                FROM late_fees lf
                INNER JOIN leases l ON lf.lease_id = l.id
                INNER JOIN units u ON l.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                INNER JOIN tenants t ON lf.tenant_id = t.id
                WHERE {$where}
                ORDER BY lf.fee_date DESC
            ");
            $stmt->execute($params);
            $reportData = $stmt->fetchAll();
            break;

        case 'maintenance':
            $reportTitle = __('rpt_cr_maintenance', 'Maintenance Report');
            $where = "mr.created_at BETWEEN :df AND :dt" . propertyScopeSql('u.property_id');
            $params = ['df' => $dateFrom, 'dt' => $dateTo . ' 23:59:59'];
            if ($filterProperty > 0) { $where .= " AND u.property_id = :pid"; $params['pid'] = $filterProperty; }
            if ($filterUnit > 0) { $where .= " AND mr.unit_id = :uid"; $params['uid'] = $filterUnit; }

            $stmt = $conn->prepare("
                SELECT mr.id, mr.title, mr.priority, mr.status, mr.created_at,
                       mr.completed_date,
                       u.unit_number, p.name AS property_name,
                       COALESCE(mc.name, '—') AS category_name,
                       (SELECT COALESCE(SUM(ma.actual_cost), 0) FROM maintenance_assignments ma WHERE ma.request_id = mr.id) AS total_cost
                FROM maintenance_requests mr
                INNER JOIN units u ON mr.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                LEFT JOIN maintenance_categories mc ON mr.category_id = mc.id
                WHERE {$where}
                ORDER BY mr.created_at DESC
            ");
            $stmt->execute($params);
            $reportData = $stmt->fetchAll();
            break;
    }
}

// Compute totals for summary
$totalAmount = 0;
$totalRecords = count($reportData);
if ($reportType === 'payments' || $reportType === 'late_fees') {
    foreach ($reportData as $r) $totalAmount += (float)$r['amount'];
} elseif ($reportType === 'deposits') {
    foreach ($reportData as $r) $totalAmount += (float)$r['amount'];
} elseif ($reportType === 'maintenance') {
    foreach ($reportData as $r) $totalAmount += (float)$r['total_cost'];
}

// Properties dropdown
$propStmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active' ORDER BY name");
$properties = $propStmt->fetchAll();

// Units dropdown (fetched via AJAX if property selected, but also pre-load for current filter)
$units = [];
if ($filterProperty > 0) {
    $unitStmt = $conn->prepare("SELECT id, unit_number FROM units WHERE property_id = ?" . propertyScopeSql('property_id') . " ORDER BY unit_number");
    $unitStmt->execute([$filterProperty]);
    $units = $unitStmt->fetchAll();
}

// Print mode — standalone HTML
if ($isPrint && $reportGenerated):
?>
<!DOCTYPE html>
<html lang="<?php echo getCurrentLang(); ?>" dir="<?php echo isRTL() ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($reportTitle); ?> — <?php echo APP_NAME; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; line-height: 1.5; color: #333; }
        .print-header { text-align: center; padding: 20px 0; border-bottom: 2px solid #1a6b4a; margin-bottom: 20px; }
        .print-header h2 { color: #1a6b4a; margin-bottom: 4px; }
        .print-header p { color: #666; font-size: 11px; }
        .print-meta { display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 11px; }
        .print-meta span { color: #666; }
        .summary-box { background: #f8f9fa; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; display: flex; gap: 30px; }
        .summary-box .item { }
        .summary-box .item strong { font-size: 14px; color: #1a6b4a; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background: #1a6b4a; color: white; font-weight: 600; font-size: 11px; text-transform: uppercase; }
        tr:nth-child(even) { background: #f8f9fa; }
        .text-end { text-align: right; }
        .text-center { text-align: center; }
        .print-footer { text-align: center; padding-top: 15px; border-top: 1px solid #ddd; margin-top: 20px; font-size: 10px; color: #999; }
        @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } .no-print { display: none !important; } }
        .btn-print { position: fixed; top: 10px; right: 10px; background: #1a6b4a; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 13px; }
        .btn-print:hover { background: #155d3f; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; }
        .badge-success { background: #d1e7dd; color: #0f5132; }
        .badge-warning { background: #fff3cd; color: #664d03; }
        .badge-danger { background: #f8d7da; color: #842029; }
        .badge-info { background: #cff4fc; color: #055160; }
        .badge-secondary { background: #e2e3e5; color: #41464b; }
    </style>
</head>
<body>
    <button class="btn-print no-print" onclick="window.print()"><?php _e('rpt_print', 'Print'); ?></button>

    <div style="max-width:900px;margin:0 auto;padding:20px;">
        <div class="print-header">
            <h2><?php echo htmlspecialchars($reportTitle); ?></h2>
            <p><?php echo APP_NAME; ?> — <?php _e('rpt_generated', 'Generated'); ?>: <?php echo date('Y-m-d H:i'); ?></p>
        </div>

        <div class="print-meta">
            <span><?php _e('rpt_period', 'Period'); ?>: <?php echo htmlspecialchars($dateFrom); ?> — <?php echo htmlspecialchars($dateTo); ?></span>
            <?php if ($filterProperty > 0):
                $pName = '';
                foreach ($properties as $p) { if ($p['id'] == $filterProperty) { $pName = $p['name']; break; } }
            ?>
            <span><?php _e('property'); ?>: <?php echo htmlspecialchars($pName); ?></span>
            <?php endif; ?>
            <span><?php _e('rpt_records', 'Records'); ?>: <?php echo $totalRecords; ?></span>
        </div>

        <?php if ($totalAmount > 0): ?>
        <div class="summary-box">
            <div class="item"><?php _e('rpt_total_records', 'Total Records'); ?>: <strong><?php echo $totalRecords; ?></strong></div>
            <div class="item"><?php _e('rpt_total_amount', 'Total Amount'); ?>: <strong><?php echo formatCurrency($totalAmount); ?></strong></div>
        </div>
        <?php endif; ?>

        <?php if (empty($reportData)): ?>
        <p style="text-align:center;padding:40px;color:#999;"><?php _e('no_data'); ?></p>
        <?php else: ?>
        <table>
            <?php if ($reportType === 'payments'): ?>
            <thead><tr><th><?php _e('date'); ?></th><th><?php _e('property'); ?></th><th><?php _e('unit_number'); ?></th><th><?php _e('tenant'); ?></th><th class="text-end"><?php _e('amount'); ?></th><th><?php _e('payment_method'); ?></th><th><?php _e('reference'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($reportData as $r): ?>
                <tr>
                    <td><?php echo $r['payment_date']; ?></td>
                    <td><?php echo htmlspecialchars($r['property_name']); ?></td>
                    <td><?php echo htmlspecialchars($r['unit_number']); ?></td>
                    <td><?php echo htmlspecialchars($r['tenant_name']); ?></td>
                    <td class="text-end"><?php echo number_format($r['amount'], 0); ?></td>
                    <td><?php echo ucfirst(str_replace('_', ' ', $r['payment_method'])); ?></td>
                    <td><?php echo htmlspecialchars($r['reference_number'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>

            <?php elseif ($reportType === 'leases'): ?>
            <thead><tr><th><?php _e('property'); ?></th><th><?php _e('unit_number'); ?></th><th><?php _e('tenant'); ?></th><th><?php _e('status'); ?></th><th class="text-end"><?php _e('rent'); ?></th><th><?php _e('start_date'); ?></th><th><?php _e('end_date'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($reportData as $r):
                    $statusBadge = ['active'=>'success','expired'=>'warning','terminated'=>'danger','renewed'=>'info'][$r['status']] ?? 'secondary';
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['property_name']); ?></td>
                    <td><?php echo htmlspecialchars($r['unit_number']); ?></td>
                    <td><?php echo htmlspecialchars($r['tenant_name']); ?></td>
                    <td><span class="badge badge-<?php echo $statusBadge; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                    <td class="text-end"><?php echo number_format($r['rent_amount'], 0); ?></td>
                    <td><?php echo $r['start_date']; ?></td>
                    <td><?php echo $r['end_date']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>

            <?php elseif ($reportType === 'tenants'): ?>
            <thead><tr><th><?php _e('name'); ?></th><th><?php _e('phone'); ?></th><th><?php _e('email'); ?></th><th><?php _e('status'); ?></th><th class="text-center"><?php _e('rpt_active_leases', 'Active Leases'); ?></th><th class="text-end"><?php _e('rpt_total_paid', 'Total Paid'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($reportData as $r):
                    $statusBadge = ['active'=>'success','notice_given'=>'warning','moved_out'=>'secondary'][$r['status']] ?? 'secondary';
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['tenant_name']); ?></td>
                    <td><?php echo htmlspecialchars($r['phone']); ?></td>
                    <td><?php echo htmlspecialchars($r['email'] ?? ''); ?></td>
                    <td><span class="badge badge-<?php echo $statusBadge; ?>"><?php echo ucfirst(str_replace('_', ' ', $r['status'])); ?></span></td>
                    <td class="text-center"><?php echo (int)$r['active_leases']; ?></td>
                    <td class="text-end"><?php echo number_format($r['total_paid'], 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>

            <?php elseif ($reportType === 'deposits'): ?>
            <thead><tr><th><?php _e('property'); ?></th><th><?php _e('unit_number'); ?></th><th><?php _e('tenant'); ?></th><th class="text-end"><?php _e('amount'); ?></th><th><?php _e('status'); ?></th><th class="text-end"><?php _e('rpt_deductions', 'Deductions'); ?></th><th class="text-end"><?php _e('rpt_refund', 'Refund'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($reportData as $r):
                    $statusBadge = ['held'=>'info','partially_refunded'=>'warning','refunded'=>'success','forfeited'=>'danger'][$r['status']] ?? 'secondary';
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['property_name']); ?></td>
                    <td><?php echo htmlspecialchars($r['unit_number']); ?></td>
                    <td><?php echo htmlspecialchars($r['tenant_name']); ?></td>
                    <td class="text-end"><?php echo number_format($r['amount'], 0); ?></td>
                    <td><span class="badge badge-<?php echo $statusBadge; ?>"><?php echo ucfirst(str_replace('_', ' ', $r['status'])); ?></span></td>
                    <td class="text-end"><?php echo number_format($r['total_deductions'], 0); ?></td>
                    <td class="text-end"><?php echo $r['refund_amount'] ? number_format($r['refund_amount'], 0) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>

            <?php elseif ($reportType === 'late_fees'): ?>
            <thead><tr><th><?php _e('date'); ?></th><th><?php _e('property'); ?></th><th><?php _e('unit_number'); ?></th><th><?php _e('tenant'); ?></th><th class="text-end"><?php _e('amount'); ?></th><th><?php _e('status'); ?></th><th><?php _e('rpt_period_ref', 'Period'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($reportData as $r):
                    $statusBadge = ['pending'=>'warning','paid'=>'success','waived'=>'secondary'][$r['status']] ?? 'secondary';
                ?>
                <tr>
                    <td><?php echo $r['fee_date']; ?></td>
                    <td><?php echo htmlspecialchars($r['property_name']); ?></td>
                    <td><?php echo htmlspecialchars($r['unit_number']); ?></td>
                    <td><?php echo htmlspecialchars($r['tenant_name']); ?></td>
                    <td class="text-end"><?php echo number_format($r['amount'], 0); ?></td>
                    <td><span class="badge badge-<?php echo $statusBadge; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                    <td><?php echo htmlspecialchars($r['period_reference'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>

            <?php elseif ($reportType === 'maintenance'): ?>
            <thead><tr><th><?php _e('date'); ?></th><th><?php _e('property'); ?></th><th><?php _e('unit_number'); ?></th><th><?php _e('title'); ?></th><th><?php _e('priority'); ?></th><th><?php _e('status'); ?></th><th class="text-end"><?php _e('maint_cost', 'Cost'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($reportData as $r):
                    $priBadge = ['urgent'=>'danger','high'=>'warning','medium'=>'info','low'=>'secondary'][$r['priority']] ?? 'secondary';
                    $stsBadge = ['completed'=>'success','closed'=>'success','in_progress'=>'warning','open'=>'info','cancelled'=>'danger'][$r['status']] ?? 'secondary';
                ?>
                <tr>
                    <td><?php echo date('Y-m-d', strtotime($r['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($r['property_name']); ?></td>
                    <td><?php echo htmlspecialchars($r['unit_number']); ?></td>
                    <td><?php echo htmlspecialchars($r['title']); ?></td>
                    <td><span class="badge badge-<?php echo $priBadge; ?>"><?php echo ucfirst($r['priority']); ?></span></td>
                    <td><span class="badge badge-<?php echo $stsBadge; ?>"><?php echo ucfirst(str_replace('_', ' ', $r['status'])); ?></span></td>
                    <td class="text-end"><?php echo $r['total_cost'] > 0 ? number_format($r['total_cost'], 0) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <?php endif; ?>
        </table>
        <?php endif; ?>

        <div class="print-footer">
            <?php echo APP_NAME; ?> — <?php _e('rpt_generated', 'Generated'); ?> <?php echo date('Y-m-d H:i'); ?> — <?php _e('rpt_page', 'Page'); ?> 1
        </div>

        <!-- Fabonic Branding -->
        <div style="text-align:center; margin-top:10px; font-size:9px; color:#adb5bd;">
            Powered by <span style="color:#1a6b4a; font-weight:600;">Fabonic</span> | realestate.fis.co.tz
        </div>
    </div>
</body>
</html>
<?php exit; endif; // End print mode ?>

<?php require_once __DIR__ . '/../../includes/header.php'; ?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-sliders me-2"></i><?php _e('rpt_custom', 'Custom Report Builder'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/reports/"><?php _e('reports'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('rpt_custom', 'Custom'); ?></li>
            </ol>
        </nav>
    </div>
    <?php if ($reportGenerated && !empty($reportData)): ?>
    <div class="d-flex gap-1">
        <a href="?<?php echo http_build_query(array_merge($_GET, ['print' => 1])); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-printer me-1"></i> <?php _e('rpt_print', 'Print'); ?>
        </a>
    </div>
    <?php endif; ?>
</div>

<?php pageIntro('reports.custom'); ?>

<!-- Report Builder Form -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-gear me-1"></i> <?php _e('rpt_configure', 'Configure Report'); ?></div>
    <div class="card-body">
        <form method="GET" id="reportForm">
            <div class="row g-3">
                <!-- Report Type -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('rpt_report_type', 'Report Type'); ?></label>
                    <select name="report_type" class="form-select" id="reportType" required>
                        <option value=""><?php _e('rpt_select_type', '— Select Report Type —'); ?></option>
                        <option value="payments" <?php echo $reportType === 'payments' ? 'selected' : ''; ?>><?php _e('rpt_cr_payments', 'Payment Report'); ?></option>
                        <option value="leases" <?php echo $reportType === 'leases' ? 'selected' : ''; ?>><?php _e('rpt_cr_leases', 'Lease Report'); ?></option>
                        <option value="tenants" <?php echo $reportType === 'tenants' ? 'selected' : ''; ?>><?php _e('rpt_cr_tenants', 'Tenant Report'); ?></option>
                        <option value="deposits" <?php echo $reportType === 'deposits' ? 'selected' : ''; ?>><?php _e('rpt_cr_deposits', 'Deposit Report'); ?></option>
                        <option value="late_fees" <?php echo $reportType === 'late_fees' ? 'selected' : ''; ?>><?php _e('rpt_cr_late_fees', 'Late Fee Report'); ?></option>
                        <option value="maintenance" <?php echo $reportType === 'maintenance' ? 'selected' : ''; ?>><?php _e('rpt_cr_maintenance', 'Maintenance Report'); ?></option>
                    </select>
                </div>

                <!-- Date From -->
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('from'); ?></label>
                    <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>

                <!-- Date To -->
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('to'); ?></label>
                    <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>

                <!-- Property Filter -->
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('property'); ?></label>
                    <select name="property_id" class="form-select" id="propertyFilter">
                        <option value=""><?php _e('all_properties'); ?></option>
                        <?php foreach ($properties as $prop): ?>
                        <option value="<?php echo $prop['id']; ?>" <?php echo $filterProperty == $prop['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prop['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Unit Filter -->
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold"><?php _e('unit'); ?></label>
                    <select name="unit_id" class="form-select" id="unitFilter">
                        <option value=""><?php _e('rpt_all_units', 'All Units'); ?></option>
                        <?php foreach ($units as $unit): ?>
                        <option value="<?php echo $unit['id']; ?>" <?php echo $filterUnit == $unit['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($unit['unit_number']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Quick Date Presets -->
            <div class="mt-3 d-flex flex-wrap gap-1">
                <span class="text-muted small me-2" style="line-height:30px;"><?php _e('rpt_quick_dates', 'Quick:'); ?></span>
                <button type="button" class="btn btn-outline-secondary btn-sm date-preset" data-from="<?php echo date('Y-m-01'); ?>" data-to="<?php echo date('Y-m-d'); ?>"><?php _e('rpt_this_month', 'This Month'); ?></button>
                <button type="button" class="btn btn-outline-secondary btn-sm date-preset" data-from="<?php echo date('Y-m-01', strtotime('-1 month')); ?>" data-to="<?php echo date('Y-m-t', strtotime('-1 month')); ?>"><?php _e('rpt_last_month', 'Last Month'); ?></button>
                <button type="button" class="btn btn-outline-secondary btn-sm date-preset" data-from="<?php echo date('Y-m-d', strtotime('-3 months')); ?>" data-to="<?php echo date('Y-m-d'); ?>"><?php _e('rpt_last_3m', 'Last 3 Months'); ?></button>
                <button type="button" class="btn btn-outline-secondary btn-sm date-preset" data-from="<?php echo date('Y-m-d', strtotime('-6 months')); ?>" data-to="<?php echo date('Y-m-d'); ?>"><?php _e('rpt_last_6m', 'Last 6 Months'); ?></button>
                <button type="button" class="btn btn-outline-secondary btn-sm date-preset" data-from="<?php echo date('Y-01-01'); ?>" data-to="<?php echo date('Y-m-d'); ?>"><?php _e('rpt_ytd', 'Year to Date'); ?></button>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-play-circle me-1"></i> <?php _e('rpt_generate', 'Generate Report'); ?>
                </button>
                <?php if ($reportGenerated): ?>
                <a href="<?php echo BASE_URL; ?>modules/reports/custom.php" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle me-1"></i> <?php _e('clear'); ?>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($reportGenerated): ?>
<!-- Report Results -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-file-text me-1"></i> <?php echo htmlspecialchars($reportTitle); ?></span>
        <div class="d-flex align-items-center gap-2">
            <small class="text-muted"><?php echo $totalRecords; ?> <?php _e('rpt_records', 'records'); ?></small>
            <?php if ($totalAmount > 0): ?>
            <span class="badge bg-success"><?php _e('total'); ?>: <?php echo formatCurrency($totalAmount); ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($reportData)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox" style="font-size:2.5rem;"></i>
            <p class="mt-2 mb-0"><?php _e('rpt_no_results', 'No results found for the selected criteria.'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <?php if ($reportType === 'payments'): ?>
                <thead><tr>
                    <th><?php _e('date'); ?></th>
                    <th><?php _e('property'); ?></th>
                    <th><?php _e('unit_number'); ?></th>
                    <th><?php _e('tenant'); ?></th>
                    <th class="text-end"><?php _e('amount'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('payment_method'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('reference'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('receipt'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($reportData as $r): ?>
                    <tr>
                        <td><?php echo formatDate($r['payment_date']); ?></td>
                        <td><small><?php echo htmlspecialchars($r['property_name']); ?></small></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($r['unit_number']); ?></td>
                        <td><?php echo htmlspecialchars($r['tenant_name']); ?></td>
                        <td class="text-end fw-semibold"><?php echo formatCurrency($r['amount']); ?></td>
                        <td class="d-none d-md-table-cell"><small><?php echo ucfirst(str_replace('_', ' ', $r['payment_method'])); ?></small></td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($r['reference_number'] ?? ''); ?></small></td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($r['receipt_number'] ?? ''); ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>

                <?php elseif ($reportType === 'leases'): ?>
                <thead><tr>
                    <th><?php _e('property'); ?></th>
                    <th><?php _e('unit_number'); ?></th>
                    <th><?php _e('tenant'); ?></th>
                    <th class="text-center"><?php _e('status'); ?></th>
                    <th class="text-end"><?php _e('rent'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('start_date'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('end_date'); ?></th>
                    <th class="text-end d-none d-md-table-cell"><?php _e('rpt_total_paid', 'Total Paid'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($reportData as $r): ?>
                    <tr>
                        <td><small><?php echo htmlspecialchars($r['property_name']); ?></small></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($r['unit_number']); ?></td>
                        <td><?php echo htmlspecialchars($r['tenant_name']); ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo ['active'=>'success','expired'=>'warning','terminated'=>'danger','renewed'=>'info'][$r['status']] ?? 'secondary'; ?>">
                                <?php echo ucfirst($r['status']); ?>
                            </span>
                        </td>
                        <td class="text-end"><?php echo formatCurrency($r['rent_amount']); ?></td>
                        <td class="d-none d-md-table-cell"><small><?php echo formatDate($r['start_date']); ?></small></td>
                        <td class="d-none d-md-table-cell"><small><?php echo formatDate($r['end_date']); ?></small></td>
                        <td class="text-end d-none d-md-table-cell"><?php echo formatCurrency($r['total_paid']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>

                <?php elseif ($reportType === 'tenants'): ?>
                <thead><tr>
                    <th><?php _e('name'); ?></th>
                    <th><?php _e('phone'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('email'); ?></th>
                    <th class="text-center"><?php _e('status'); ?></th>
                    <th class="text-center"><?php _e('rpt_active_leases', 'Active Leases'); ?></th>
                    <th class="text-end"><?php _e('rpt_total_paid', 'Total Paid'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($reportData as $r): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo htmlspecialchars($r['tenant_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['phone']); ?></td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($r['email'] ?? ''); ?></small></td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo ['active'=>'success','notice_given'=>'warning','moved_out'=>'secondary'][$r['status']] ?? 'secondary'; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $r['status'])); ?>
                            </span>
                        </td>
                        <td class="text-center"><?php echo (int)$r['active_leases']; ?></td>
                        <td class="text-end"><?php echo formatCurrency($r['total_paid']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>

                <?php elseif ($reportType === 'deposits'): ?>
                <thead><tr>
                    <th><?php _e('property'); ?></th>
                    <th><?php _e('unit_number'); ?></th>
                    <th><?php _e('tenant'); ?></th>
                    <th class="text-end"><?php _e('amount'); ?></th>
                    <th class="text-center"><?php _e('status'); ?></th>
                    <th class="text-end d-none d-md-table-cell"><?php _e('rpt_deductions', 'Deductions'); ?></th>
                    <th class="text-end d-none d-md-table-cell"><?php _e('rpt_refund', 'Refund'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($reportData as $r): ?>
                    <tr>
                        <td><small><?php echo htmlspecialchars($r['property_name']); ?></small></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($r['unit_number']); ?></td>
                        <td><?php echo htmlspecialchars($r['tenant_name']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($r['amount']); ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo ['held'=>'info','partially_refunded'=>'warning','refunded'=>'success','forfeited'=>'danger'][$r['status']] ?? 'secondary'; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $r['status'])); ?>
                            </span>
                        </td>
                        <td class="text-end d-none d-md-table-cell"><?php echo formatCurrency($r['total_deductions']); ?></td>
                        <td class="text-end d-none d-md-table-cell"><?php echo $r['refund_amount'] ? formatCurrency($r['refund_amount']) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>

                <?php elseif ($reportType === 'late_fees'): ?>
                <thead><tr>
                    <th><?php _e('date'); ?></th>
                    <th><?php _e('property'); ?></th>
                    <th><?php _e('unit_number'); ?></th>
                    <th><?php _e('tenant'); ?></th>
                    <th class="text-end"><?php _e('amount'); ?></th>
                    <th class="text-center"><?php _e('status'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('rpt_period_ref', 'Period'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($reportData as $r): ?>
                    <tr>
                        <td><?php echo formatDate($r['fee_date']); ?></td>
                        <td><small><?php echo htmlspecialchars($r['property_name']); ?></small></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($r['unit_number']); ?></td>
                        <td><?php echo htmlspecialchars($r['tenant_name']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($r['amount']); ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo ['pending'=>'warning','paid'=>'success','waived'=>'secondary'][$r['status']] ?? 'secondary'; ?>">
                                <?php echo ucfirst($r['status']); ?>
                            </span>
                        </td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($r['period_reference'] ?? ''); ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>

                <?php elseif ($reportType === 'maintenance'): ?>
                <thead><tr>
                    <th><?php _e('date'); ?></th>
                    <th><?php _e('property'); ?></th>
                    <th><?php _e('unit_number'); ?></th>
                    <th><?php _e('title'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('category'); ?></th>
                    <th class="text-center"><?php _e('priority'); ?></th>
                    <th class="text-center"><?php _e('status'); ?></th>
                    <th class="text-end"><?php _e('maint_cost', 'Cost'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($reportData as $r): ?>
                    <tr>
                        <td><small><?php echo formatDate(substr($r['created_at'], 0, 10)); ?></small></td>
                        <td><small><?php echo htmlspecialchars($r['property_name']); ?></small></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($r['unit_number']); ?></td>
                        <td><?php echo htmlspecialchars($r['title']); ?></td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($r['category_name']); ?></small></td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo ['urgent'=>'danger','high'=>'warning','medium'=>'primary','low'=>'info'][$r['priority']] ?? 'secondary'; ?>">
                                <?php echo ucfirst($r['priority']); ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo ['completed'=>'success','closed'=>'success','in_progress'=>'warning','open'=>'info','on_hold'=>'secondary','cancelled'=>'danger'][$r['status']] ?? 'secondary'; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $r['status'])); ?>
                            </span>
                        </td>
                        <td class="text-end"><?php echo $r['total_cost'] > 0 ? formatCurrency($r['total_cost']) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
// Date preset buttons
document.querySelectorAll('.date-preset').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelector('[name="date_from"]').value = this.dataset.from;
        document.querySelector('[name="date_to"]').value = this.dataset.to;
    });
});

// Dynamic units dropdown based on property selection
document.getElementById('propertyFilter').addEventListener('change', function() {
    const pid = this.value;
    const unitSelect = document.getElementById('unitFilter');
    unitSelect.innerHTML = '<option value=""><?php echo __("rpt_all_units", "All Units"); ?></option>';

    if (!pid) return;

    fetch(BASE_URL + 'api/reports.php?action=units_by_property&property_id=' + pid)
        .then(r => r.json())
        .then(res => {
            if (res.success && res.data) {
                res.data.forEach(u => {
                    const opt = document.createElement('option');
                    opt.value = u.id;
                    opt.textContent = u.unit_number;
                    unitSelect.appendChild(opt);
                });
            }
        });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
