<?php
/**
 * FIS Real Estate — Reports API (Part 13)
 * Provides dashboard stats, financial data, occupancy data, CSV exports.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // DASHBOARD STATS
    // ============================
    case 'dashboard_stats':
        if (!hasPermission('reports', 'view') && getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stats = [];

        // Basic counts
        $stats['total_properties'] = (int) $conn->query("SELECT COUNT(*) FROM properties WHERE status = 'active'" . propertyScopeSql('id'))->fetchColumn();
        $stats['total_units'] = (int) $conn->query("SELECT COUNT(*) FROM units WHERE 1=1" . propertyScopeSql('property_id'))->fetchColumn();
        $stats['occupied_units'] = (int) $conn->query("SELECT COUNT(*) FROM units WHERE status = 'occupied'" . propertyScopeSql('property_id'))->fetchColumn();
        $stats['vacant_units'] = (int) $conn->query("SELECT COUNT(*) FROM units WHERE status = 'vacant'" . propertyScopeSql('property_id'))->fetchColumn();
        $stats['maintenance_units'] = (int) $conn->query("SELECT COUNT(*) FROM units WHERE status = 'maintenance'" . propertyScopeSql('property_id'))->fetchColumn();
        $stats['reserved_units'] = (int) $conn->query("SELECT COUNT(*) FROM units WHERE status = 'reserved'" . propertyScopeSql('property_id'))->fetchColumn();
        $stats['active_tenants'] = (int) $conn->query("SELECT COUNT(*) FROM tenants WHERE status = 'active'" . tenantScopeSql('id'))->fetchColumn();
        $stats['active_leases'] = (int) $conn->query("SELECT COUNT(*) FROM leases WHERE status = 'active'" . unitScopeSql('unit_id'))->fetchColumn();
        $stats['occupancy_rate'] = $stats['total_units'] > 0 ? round(($stats['occupied_units'] / $stats['total_units']) * 100, 1) : 0;

        // Revenue this month
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $revStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'recorded' AND payment_date BETWEEN ? AND ?" . unitScopeSql('unit_id'));
        $revStmt->execute([$monthStart, $monthEnd]);
        $stats['revenue_this_month'] = (float) $revStmt->fetchColumn();

        // Revenue last month
        $lastMonthStart = date('Y-m-01', strtotime('-1 month'));
        $lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
        $revLastStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'recorded' AND payment_date BETWEEN ? AND ?" . unitScopeSql('unit_id'));
        $revLastStmt->execute([$lastMonthStart, $lastMonthEnd]);
        $stats['revenue_last_month'] = (float) $revLastStmt->fetchColumn();

        // Part 31: overdue is the sum of charges past their due date and still
        // unsettled. The old query counted a whole month's rent for any lease
        // with no payment this month, so a part payment counted as nothing and
        // arrears older than a month were invisible.
        $overdueStmt = $conn->query("
            SELECT COALESCE(SUM(c.amount - c.amount_settled), 0)
            FROM charges c
            WHERE c.status IN ('open','part')
              AND c.due_date <= CURDATE()" . unitScopeSql('c.unit_id') . "
        ");
        $stats['overdue_amount'] = (float) $overdueStmt->fetchColumn();

        // Pending deposits
        $stats['deposits_held'] = (float) $conn->query("SELECT COALESCE(SUM(amount), 0) FROM deposits WHERE status = 'held'" . unitScopeSql('unit_id'))->fetchColumn();

        // Pending late fees
        $stats['pending_late_fees'] = (float) $conn->query("SELECT COALESCE(SUM(amount), 0) FROM late_fees WHERE status = 'pending' AND lease_id IN (SELECT id FROM leases WHERE 1=1" . unitScopeSql('unit_id') . ")")->fetchColumn();

        // Expiring leases (within 30 days)
        $stats['expiring_leases'] = (int) $conn->query("
            SELECT COUNT(*) FROM leases
            WHERE status = 'active'
            AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)" . unitScopeSql('unit_id') . "
        ")->fetchColumn();

        // Open maintenance
        $stats['open_maintenance'] = (int) $conn->query("
            SELECT COUNT(*) FROM maintenance_requests
            WHERE status NOT IN ('completed', 'closed', 'cancelled')" . unitScopeSql('unit_id') . "
        ")->fetchColumn();

        jsonResponse(['success' => true, 'data' => $stats]);
        break;

    // ============================
    // REVENUE TREND (6 months)
    // ============================
    case 'revenue_trend':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $propertyId = (int)($_GET['property_id'] ?? 0);
        $where = "p.status = 'recorded'" . propertyScopeSql('u.property_id');
        $params = [];

        if ($propertyId > 0) {
            $where .= " AND u.property_id = :pid";
            $params['pid'] = $propertyId;
        }

        $sql = "SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS month,
                       COALESCE(SUM(p.amount), 0) AS total
                FROM payments p
                INNER JOIN units u ON p.unit_id = u.id
                WHERE {$where}
                AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY month ORDER BY month";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // RECENT ACTIVITY
    // ============================
    case 'recent_activity':
        $limit = min(20, max(5, (int)($_GET['limit'] ?? 10)));

        $stmt = $conn->prepare("
            SELECT al.*, u.full_name AS user_name
            FROM activity_log al
            LEFT JOIN users u ON al.user_id = u.id
            ORDER BY al.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // FINANCIAL: RENT ROLL
    // ============================
    case 'rent_roll':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $propertyId = (int)($_GET['property_id'] ?? 0);
        $where = "l.status = 'active'" . propertyScopeSql('u.property_id');
        $params = [];

        if ($propertyId > 0) {
            $where .= " AND u.property_id = :pid";
            $params['pid'] = $propertyId;
        }

        $sql = "SELECT l.id AS lease_id, l.rent_amount, l.currency, l.payment_frequency,
                       l.start_date, l.end_date, l.payment_due_day,
                       u.unit_number, u.id AS unit_id,
                       p.name AS property_name, p.id AS property_id,
                       CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                       t.phone AS tenant_phone,
                       (SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay WHERE pay.lease_id = l.id AND pay.status = 'recorded') AS total_paid,
                       (SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay WHERE pay.lease_id = l.id AND pay.status = 'recorded' AND pay.payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS paid_this_month
                FROM leases l
                INNER JOIN units u ON l.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                INNER JOIN tenants t ON l.tenant_id = t.id
                WHERE {$where}
                ORDER BY p.name, u.unit_number";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // FINANCIAL: PAYMENT AGING
    // ============================
    case 'payment_aging':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $propertyId = (int)($_GET['property_id'] ?? 0);
        $where = "l.status = 'active'" . propertyScopeSql('u.property_id');
        $params = [];

        if ($propertyId > 0) {
            $where .= " AND u.property_id = :pid";
            $params['pid'] = $propertyId;
        }

        $sql = "SELECT l.id AS lease_id, l.rent_amount, l.currency, l.payment_due_day,
                       u.unit_number, p.name AS property_name,
                       CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                       t.phone AS tenant_phone,
                       (SELECT MAX(pay.payment_date) FROM payments pay WHERE pay.lease_id = l.id AND pay.status = 'recorded') AS last_payment_date,
                       (SELECT COALESCE(SUM(lf.amount), 0) FROM late_fees lf WHERE lf.lease_id = l.id AND lf.status = 'pending') AS pending_fees
                FROM leases l
                INNER JOIN units u ON l.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                INNER JOIN tenants t ON l.tenant_id = t.id
                WHERE {$where}
                ORDER BY last_payment_date ASC NULLS FIRST, p.name, u.unit_number";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // FINANCIAL: INCOME SUMMARY
    // ============================
    case 'income_summary':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $dateFrom = trim($_GET['date_from'] ?? date('Y-01-01'));
        $dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));
        $propertyId = (int)($_GET['property_id'] ?? 0);

        $where = "p.status = 'recorded' AND p.payment_date BETWEEN :df AND :dt" . propertyScopeSql('u.property_id');
        $params = ['df' => $dateFrom, 'dt' => $dateTo];

        if ($propertyId > 0) {
            $where .= " AND u.property_id = :pid";
            $params['pid'] = $propertyId;
        }

        // Monthly breakdown
        $sql = "SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS month,
                       p.payment_method,
                       COUNT(*) AS payment_count,
                       COALESCE(SUM(p.amount), 0) AS total
                FROM payments p
                INNER JOIN units u ON p.unit_id = u.id
                WHERE {$where}
                GROUP BY month, p.payment_method
                ORDER BY month, p.payment_method";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $breakdown = $stmt->fetchAll();

        // Totals by method
        $sql2 = "SELECT p.payment_method, COUNT(*) AS count, COALESCE(SUM(p.amount), 0) AS total
                 FROM payments p
                 INNER JOIN units u ON p.unit_id = u.id
                 WHERE {$where}
                 GROUP BY p.payment_method";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->execute($params);
        $byMethod = $stmt2->fetchAll();

        // Grand total
        $sql3 = "SELECT COALESCE(SUM(p.amount), 0) AS grand_total, COUNT(*) AS total_count
                 FROM payments p
                 INNER JOIN units u ON p.unit_id = u.id
                 WHERE {$where}";
        $stmt3 = $conn->prepare($sql3);
        $stmt3->execute($params);
        $totals = $stmt3->fetch();

        jsonResponse(['success' => true, 'data' => [
            'breakdown' => $breakdown,
            'by_method' => $byMethod,
            'grand_total' => (float)$totals['grand_total'],
            'total_count' => (int)$totals['total_count'],
        ]]);
        break;

    // ============================
    // OCCUPANCY: OVERVIEW
    // ============================
    case 'occupancy_overview':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $propertyId = (int)($_GET['property_id'] ?? 0);
        $where = "1=1" . propertyScopeSql('p.id');
        $params = [];

        if ($propertyId > 0) {
            $where .= " AND p.id = :pid";
            $params['pid'] = $propertyId;
        }

        // Per-property occupancy
        $sql = "SELECT p.id, p.name, p.property_type,
                       COUNT(u.id) AS total_units,
                       SUM(CASE WHEN u.status = 'occupied' THEN 1 ELSE 0 END) AS occupied,
                       SUM(CASE WHEN u.status = 'vacant' THEN 1 ELSE 0 END) AS vacant,
                       SUM(CASE WHEN u.status = 'maintenance' THEN 1 ELSE 0 END) AS maint,
                       SUM(CASE WHEN u.status = 'reserved' THEN 1 ELSE 0 END) AS reserved
                FROM properties p
                LEFT JOIN units u ON u.property_id = p.id
                WHERE p.status = 'active' AND {$where}
                GROUP BY p.id, p.name, p.property_type
                ORDER BY p.name";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $byProperty = $stmt->fetchAll();

        // By unit type
        $sql2 = "SELECT u.unit_type, COUNT(*) AS total,
                        SUM(CASE WHEN u.status = 'occupied' THEN 1 ELSE 0 END) AS occupied,
                        SUM(CASE WHEN u.status = 'vacant' THEN 1 ELSE 0 END) AS vacant
                 FROM units u
                 INNER JOIN properties p ON u.property_id = p.id
                 WHERE p.status = 'active' " . ($propertyId > 0 ? "AND p.id = :pid2" : "") . propertyScopeSql('p.id') . "
                 GROUP BY u.unit_type ORDER BY total DESC";
        $params2 = $propertyId > 0 ? ['pid2' => $propertyId] : [];
        $stmt2 = $conn->prepare($sql2);
        $stmt2->execute($params2);
        $byType = $stmt2->fetchAll();

        jsonResponse(['success' => true, 'data' => [
            'by_property' => $byProperty,
            'by_type' => $byType,
        ]]);
        break;

    // ============================
    // OCCUPANCY: TURNOVER
    // ============================
    case 'turnover':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        // Lease turnover by month (last 12 months)
        $sql = "SELECT DATE_FORMAT(l.start_date, '%Y-%m') AS month,
                       SUM(CASE WHEN l.status IN ('active','renewed','expired') THEN 1 ELSE 0 END) AS new_leases,
                       0 AS ended_leases
                FROM leases l
                WHERE l.start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . unitScopeSql('l.unit_id') . "
                GROUP BY month

                UNION ALL

                SELECT DATE_FORMAT(COALESCE(l.terminated_date, l.end_date), '%Y-%m') AS month,
                       0 AS new_leases,
                       COUNT(*) AS ended_leases
                FROM leases l
                WHERE l.status IN ('expired', 'terminated')
                AND COALESCE(l.terminated_date, l.end_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)" . unitScopeSql('l.unit_id') . "
                GROUP BY month
                ORDER BY month";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // EXPIRING LEASES
    // ============================
    case 'expiring_leases':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $days = max(7, min(180, (int)($_GET['days'] ?? 30)));

        $stmt = $conn->prepare("
            SELECT l.id, l.start_date, l.end_date, l.rent_amount, l.currency,
                   DATEDIFF(l.end_date, CURDATE()) AS days_remaining,
                   u.unit_number, p.name AS property_name,
                   CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                   t.phone AS tenant_phone
            FROM leases l
            INNER JOIN units u ON l.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            INNER JOIN tenants t ON l.tenant_id = t.id
            WHERE l.status = 'active'
            AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)" . propertyScopeSql('u.property_id') . "
            ORDER BY l.end_date ASC
        ");
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // CSV EXPORT: RENT ROLL
    // ============================
    case 'export_rent_roll':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $propertyId = (int)($_GET['property_id'] ?? 0);
        $where = "l.status = 'active'" . propertyScopeSql('u.property_id');
        $params = [];

        if ($propertyId > 0) {
            $where .= " AND u.property_id = :pid";
            $params['pid'] = $propertyId;
        }

        $sql = "SELECT p.name AS property_name, u.unit_number,
                       CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                       t.phone AS tenant_phone,
                       l.rent_amount, l.currency, l.payment_frequency,
                       l.start_date, l.end_date, l.payment_due_day,
                       (SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay WHERE pay.lease_id = l.id AND pay.status = 'recorded') AS total_paid,
                       (SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay WHERE pay.lease_id = l.id AND pay.status = 'recorded' AND pay.payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS paid_this_month
                FROM leases l
                INNER JOIN units u ON l.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                INNER JOIN tenants t ON l.tenant_id = t.id
                WHERE {$where}
                ORDER BY p.name, u.unit_number";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="rent_roll_' . date('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Property', 'Unit', 'Tenant', 'Phone', 'Rent', 'Currency', 'Frequency', 'Start', 'End', 'Due Day', 'Total Paid', 'Paid This Month']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['property_name'], $r['unit_number'], $r['tenant_name'], $r['tenant_phone'],
                $r['rent_amount'], $r['currency'], $r['payment_frequency'],
                $r['start_date'], $r['end_date'], $r['payment_due_day'],
                $r['total_paid'], $r['paid_this_month']
            ]);
        }
        fclose($out);
        exit;

    // ============================
    // CSV EXPORT: PAYMENTS
    // ============================
    case 'export_payments':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $dateFrom = trim($_GET['date_from'] ?? date('Y-01-01'));
        $dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));
        $propertyId = (int)($_GET['property_id'] ?? 0);

        $where = "p.status = 'recorded' AND p.payment_date BETWEEN :df AND :dt" . propertyScopeSql('u.property_id');
        $params = ['df' => $dateFrom, 'dt' => $dateTo];

        if ($propertyId > 0) {
            $where .= " AND u.property_id = :pid";
            $params['pid'] = $propertyId;
        }

        $sql = "SELECT p.payment_date, pr.name AS property_name, u.unit_number,
                       CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                       p.amount, p.currency, p.payment_method, p.reference_number,
                       p.period_from, p.period_to,
                       r.receipt_number
                FROM payments p
                INNER JOIN units u ON p.unit_id = u.id
                INNER JOIN properties pr ON u.property_id = pr.id
                INNER JOIN tenants t ON p.tenant_id = t.id
                LEFT JOIN receipts r ON r.payment_id = p.id
                WHERE {$where}
                ORDER BY p.payment_date DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="payments_' . date('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Property', 'Unit', 'Tenant', 'Amount', 'Currency', 'Method', 'Reference', 'Period From', 'Period To', 'Receipt #']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['payment_date'], $r['property_name'], $r['unit_number'], $r['tenant_name'],
                $r['amount'], $r['currency'], $r['payment_method'], $r['reference_number'],
                $r['period_from'], $r['period_to'], $r['receipt_number']
            ]);
        }
        fclose($out);
        exit;

    // ============================
    // CSV EXPORT: OCCUPANCY
    // ============================
    case 'export_occupancy':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $sql = "SELECT p.name AS property_name, u.unit_number, u.unit_type, u.status,
                       u.rent_amount, u.currency,
                       CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                       l.start_date AS lease_start, l.end_date AS lease_end
                FROM units u
                INNER JOIN properties p ON u.property_id = p.id
                LEFT JOIN leases l ON l.unit_id = u.id AND l.status = 'active'
                LEFT JOIN tenants t ON l.tenant_id = t.id
                WHERE p.status = 'active'" . propertyScopeSql('u.property_id') . "
                ORDER BY p.name, u.unit_number";

        $rows = $conn->query($sql)->fetchAll();

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="occupancy_' . date('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Property', 'Unit', 'Type', 'Status', 'Rent', 'Currency', 'Tenant', 'Lease Start', 'Lease End']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['property_name'], $r['unit_number'], $r['unit_type'], $r['status'],
                $r['rent_amount'], $r['currency'], $r['tenant_name'],
                $r['lease_start'], $r['lease_end']
            ]);
        }
        fclose($out);
        exit;

    // ============================
    // CUSTOM REPORT: BUILD
    // ============================
    case 'custom_report':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $reportType = trim($_GET['report_type'] ?? 'payments');
        $dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
        $dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));
        $propertyId = (int)($_GET['property_id'] ?? 0);
        $unitId = (int)($_GET['unit_id'] ?? 0);

        $data = [];

        switch ($reportType) {
            case 'payments':
                $where = "p.status = 'recorded' AND p.payment_date BETWEEN :df AND :dt" . propertyScopeSql('u.property_id');
                $params = ['df' => $dateFrom, 'dt' => $dateTo];
                if ($propertyId > 0) { $where .= " AND u.property_id = :pid"; $params['pid'] = $propertyId; }
                if ($unitId > 0) { $where .= " AND p.unit_id = :uid"; $params['uid'] = $unitId; }

                $stmt = $conn->prepare("
                    SELECT p.id, p.payment_date, p.amount, p.currency, p.payment_method, p.reference_number,
                           u.unit_number, pr.name AS property_name,
                           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name
                    FROM payments p
                    INNER JOIN units u ON p.unit_id = u.id
                    INNER JOIN properties pr ON u.property_id = pr.id
                    INNER JOIN tenants t ON p.tenant_id = t.id
                    WHERE {$where}
                    ORDER BY p.payment_date DESC
                ");
                $stmt->execute($params);
                $data = $stmt->fetchAll();
                break;

            case 'leases':
                $where = "1=1" . propertyScopeSql('u.property_id');
                $params = [];
                if ($propertyId > 0) { $where .= " AND u.property_id = :pid"; $params['pid'] = $propertyId; }
                if ($unitId > 0) { $where .= " AND l.unit_id = :uid"; $params['uid'] = $unitId; }

                $stmt = $conn->prepare("
                    SELECT l.id, l.status, l.start_date, l.end_date, l.rent_amount, l.currency, l.payment_frequency,
                           u.unit_number, p.name AS property_name,
                           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name
                    FROM leases l
                    INNER JOIN units u ON l.unit_id = u.id
                    INNER JOIN properties p ON u.property_id = p.id
                    INNER JOIN tenants t ON l.tenant_id = t.id
                    WHERE {$where}
                    ORDER BY l.start_date DESC
                ");
                $stmt->execute($params);
                $data = $stmt->fetchAll();
                break;

            case 'tenants':
                $where = "1=1" . tenantScopeSql('t.id');
                $params = [];

                $stmt = $conn->prepare("
                    SELECT t.id, CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                           t.phone, t.email, t.status, t.move_in_date, t.move_out_date,
                           (SELECT COUNT(*) FROM leases l WHERE l.tenant_id = t.id AND l.status = 'active') AS active_leases,
                           (SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay WHERE pay.tenant_id = t.id AND pay.status = 'recorded') AS total_paid
                    FROM tenants t
                    WHERE {$where}
                    ORDER BY t.first_name, t.last_name
                ");
                $stmt->execute($params);
                $data = $stmt->fetchAll();
                break;

            case 'deposits':
                $where = "1=1" . propertyScopeSql('u.property_id');
                $params = [];
                if ($propertyId > 0) { $where .= " AND u.property_id = :pid"; $params['pid'] = $propertyId; }

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
                $data = $stmt->fetchAll();
                break;

            case 'late_fees':
                $where = "lf.fee_date BETWEEN :df AND :dt" . propertyScopeSql('u.property_id');
                $params = ['df' => $dateFrom, 'dt' => $dateTo];
                if ($propertyId > 0) { $where .= " AND u.property_id = :pid"; $params['pid'] = $propertyId; }

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
                $data = $stmt->fetchAll();
                break;
        }

        jsonResponse(['success' => true, 'data' => $data, 'report_type' => $reportType]);
        break;

    // ============================
    // PROPERTIES DROPDOWN
    // ============================
    case 'properties':
        $stmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // UNITS BY PROPERTY
    // ============================
    case 'units_by_property':
        $propertyId = (int)($_GET['property_id'] ?? 0);
        if ($propertyId > 0) {
            $stmt = $conn->prepare("SELECT id, unit_number, unit_label FROM units WHERE property_id = ?" . propertyScopeSql('property_id') . " ORDER BY unit_number");
            $stmt->execute([$propertyId]);
        } else {
            $stmt = $conn->query("SELECT u.id, u.unit_number, u.unit_label, p.name AS property_name FROM units u INNER JOIN properties p ON u.property_id = p.id WHERE 1=1" . propertyScopeSql('u.property_id') . " ORDER BY p.name, u.unit_number");
        }
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}
