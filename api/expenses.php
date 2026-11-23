<?php
/**
 * FIS Real Estate — Expenses & Financial Reports API (Part 20)
 * Handles: expense CRUD, categories CRUD, income vs expense reports,
 * profit/loss statements, CSV exports.
 * Enterprise tier required + financial_reports_enabled setting.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payables.php';
require_once __DIR__ . '/../includes/subscription.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

// Enterprise tier + module check
if (!hasTier('enterprise')) {
    jsonResponse(['success' => false, 'message' => __('exp_tier_required', 'Enterprise tier required for financial reporting')], 403);
}
if (getSetting('financial_reports_enabled', '0') !== '1') {
    jsonResponse(['success' => false, 'message' => __('exp_disabled', 'Financial reports module is disabled')], 403);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // EXPENSES — LIST
    // ============================
    case 'list':
        if (!hasPermission('expenses', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $propertyId = (int)($_GET['property_id'] ?? 0);
        $categoryId = (int)($_GET['category_id'] ?? 0);
        $search = trim($_GET['search'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');
        $status = trim($_GET['status'] ?? 'active');

        $where = "1=1" . propertyScopeSql('e.property_id');
        $params = [];

        if ($propertyId) {
            $where .= " AND e.property_id = :pid";
            $params['pid'] = $propertyId;
        }
        if ($categoryId) {
            $where .= " AND e.category_id = :cid";
            $params['cid'] = $categoryId;
        }
        if ($search) {
            $where .= " AND (e.description LIKE :s1 OR e.vendor_name LIKE :s2)";
            $params['s1'] = "%$search%";
            $params['s2'] = "%$search%";
        }
        if ($dateFrom) {
            $where .= " AND e.expense_date >= :df";
            $params['df'] = $dateFrom;
        }
        if ($dateTo) {
            $where .= " AND e.expense_date <= :dt";
            $params['dt'] = $dateTo;
        }
        if ($status) {
            $where .= " AND e.status = :status";
            $params['status'] = $status;
        }

        $countStmt = $conn->prepare("SELECT COUNT(*) FROM expenses e WHERE $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pagination = paginate($total, $page);

        $sql = "SELECT e.*, ec.name AS category_name, p.name AS property_name,
                       u2.unit_number, usr.full_name AS recorded_by_name
                FROM expenses e
                LEFT JOIN expense_categories ec ON e.category_id = ec.id
                LEFT JOIN properties p ON e.property_id = p.id
                LEFT JOIN units u2 ON e.unit_id = u2.id
                LEFT JOIN users usr ON e.recorded_by = usr.id
                WHERE $where
                ORDER BY e.expense_date DESC, e.id DESC
                LIMIT :offset, :limit";
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $pagination['records_per_page'], PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(), 'pagination' => $pagination]);
        break;

    // ============================
    // EXPENSES — GET
    // ============================
    case 'get':
        if (!hasPermission('expenses', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("
            SELECT e.*, ec.name AS category_name, p.name AS property_name,
                   u2.unit_number, usr.full_name AS recorded_by_name
            FROM expenses e
            LEFT JOIN expense_categories ec ON e.category_id = ec.id
            LEFT JOIN properties p ON e.property_id = p.id
            LEFT JOIN units u2 ON e.unit_id = u2.id
            LEFT JOIN users usr ON e.recorded_by = usr.id
            WHERE e.id = ?
        ");
        $stmt->execute([$id]);
        $exp = $stmt->fetch();
        if (!$exp) {
            jsonResponse(['success' => false, 'message' => 'Expense not found'], 404);
        }
        if (!canAccessProperty($exp['property_id'])) {
            jsonResponse(['success' => false, 'message' => __('no_access_record', "You don't have access to this record")], 403);
        }
        jsonResponse(['success' => true, 'data' => $exp]);
        break;

    // ============================
    // EXPENSES — CREATE
    // ============================
    case 'create':
        if (!hasPermission('expenses', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $propertyId = (int)($_POST['property_id'] ?? 0);
        $unitId = (int)($_POST['unit_id'] ?? 0) ?: null;
        $categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
        $description = trim($_POST['description'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $expenseDate = trim($_POST['expense_date'] ?? '');
        $vendorName = trim($_POST['vendor_name'] ?? '');
        $maintReqId = (int)($_POST['maintenance_request_id'] ?? 0) ?: null;

        if (!$propertyId) jsonResponse(['success' => false, 'message' => __('exp_property_required')]);
        if ($amount <= 0) jsonResponse(['success' => false, 'message' => __('exp_amount_required')]);
        if (!$expenseDate) jsonResponse(['success' => false, 'message' => __('exp_date_required')]);
        if (!canAccessProperty($propertyId)) {
            jsonResponse(['success' => false, 'message' => __('no_access_record', "You don't have access to this record")], 403);
        }

        // Handle receipt upload
        $receiptPath = null;
        if (!empty($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
            $result = uploadFile($_FILES['receipt'], 'expenses');
            if ($result['success']) {
                $receiptPath = $result['filepath'];
            }
        }

        $stmt = $conn->prepare("
            INSERT INTO expenses (property_id, unit_id, category_id, description, amount, currency,
                                  expense_date, vendor_name, receipt_path, recorded_by, maintenance_request_id, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([
            $propertyId, $unitId, $categoryId, $description, $amount, $currency,
            $expenseDate, $vendorName, $receiptPath, getUserId(), $maintReqId
        ]);
        $newId = (int)$conn->lastInsertId();

        // Ledger (part 32): recognise the cost in the month it was incurred —
        // Dr Expense / Cr Accounts Payable. Paying it is a separate, approved step.
        postExpense($newId);

        logActivity('created', 'expense', $newId, ['amount' => $amount, 'vendor' => $vendorName]);
        jsonResponse(['success' => true, 'message' => __('exp_created'), 'id' => $newId]);
        break;

    // ============================
    // EXPENSES — UPDATE
    // ============================
    case 'update':
        if (!hasPermission('expenses', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'Expense ID required']);

        $propertyId = (int)($_POST['property_id'] ?? 0);
        $unitId = (int)($_POST['unit_id'] ?? 0) ?: null;
        $categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
        $description = trim($_POST['description'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $expenseDate = trim($_POST['expense_date'] ?? '');
        $vendorName = trim($_POST['vendor_name'] ?? '');

        if (!$propertyId) jsonResponse(['success' => false, 'message' => __('exp_property_required')]);
        if ($amount <= 0) jsonResponse(['success' => false, 'message' => __('exp_amount_required')]);
        if (!$expenseDate) jsonResponse(['success' => false, 'message' => __('exp_date_required')]);

        // Scope guard: must have access to both the existing record's property and the target property
        $curStmt = $conn->prepare("SELECT property_id FROM expenses WHERE id = ?");
        $curStmt->execute([$id]);
        $curPid = $curStmt->fetchColumn();
        if ($curPid === false) jsonResponse(['success' => false, 'message' => 'Expense not found'], 404);
        if (!canAccessProperty($curPid) || !canAccessProperty($propertyId)) {
            jsonResponse(['success' => false, 'message' => __('no_access_record', "You don't have access to this record")], 403);
        }

        // Handle receipt upload (replace if new one uploaded)
        $receiptSql = '';
        $receiptParams = [];
        if (!empty($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
            $result = uploadFile($_FILES['receipt'], 'expenses');
            if ($result['success']) {
                $receiptSql = ', receipt_path = ?';
                $receiptParams[] = $result['filepath'];
            }
        }

        $sql = "UPDATE expenses
                SET property_id = ?, unit_id = ?, category_id = ?, description = ?,
                    amount = ?, currency = ?, expense_date = ?, vendor_name = ? $receiptSql
                WHERE id = ?";
        $params = [$propertyId, $unitId, $categoryId, $description, $amount, $currency, $expenseDate, $vendorName];
        $params = array_merge($params, $receiptParams, [$id]);

        $conn->prepare($sql)->execute($params);

        logActivity('updated', 'expense', $id, ['amount' => $amount]);
        jsonResponse(['success' => true, 'message' => __('exp_updated')]);
        break;

    // ============================
    // EXPENSES — DELETE (void)
    // ============================
    case 'delete':
        if (!hasPermission('expenses', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'Expense ID required']);

        $delStmt = $conn->prepare("SELECT property_id FROM expenses WHERE id = ?");
        $delStmt->execute([$id]);
        $delPid = $delStmt->fetchColumn();
        if ($delPid === false) jsonResponse(['success' => false, 'message' => 'Expense not found'], 404);
        if (!canAccessProperty($delPid)) {
            jsonResponse(['success' => false, 'message' => __('no_access_record', "You don't have access to this record")], 403);
        }

        $conn->prepare("UPDATE expenses SET status = 'voided' WHERE id = ?")->execute([$id]);
        logActivity('deleted', 'expense', $id);
        jsonResponse(['success' => true, 'message' => __('exp_voided')]);
        break;

    // ============================
    // CATEGORIES — LIST
    // ============================
    case 'list_categories':
        if (!hasPermission('expenses', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("
            SELECT ec.*, (SELECT COUNT(*) FROM expenses e WHERE e.category_id = ec.id AND e.status = 'active') AS expense_count,
                   (SELECT COALESCE(SUM(e.amount), 0) FROM expenses e WHERE e.category_id = ec.id AND e.status = 'active') AS total_amount
            FROM expense_categories ec
            ORDER BY ec.name ASC
        ");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // CATEGORIES — CREATE
    // ============================
    case 'create_category':
        if (!hasPermission('expenses', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $name = trim($_POST['name'] ?? '');
        if (empty($name)) jsonResponse(['success' => false, 'message' => __('exp_cat_name_required')]);

        $check = $conn->prepare("SELECT id FROM expense_categories WHERE name = ?");
        $check->execute([$name]);
        if ($check->fetch()) {
            jsonResponse(['success' => false, 'message' => __('exp_cat_exists')]);
        }

        $conn->prepare("INSERT INTO expense_categories (name) VALUES (?)")->execute([$name]);
        logActivity('created', 'expense_category', (int)$conn->lastInsertId(), ['name' => $name]);
        jsonResponse(['success' => true, 'message' => __('exp_cat_created')]);
        break;

    // ============================
    // CATEGORIES — UPDATE
    // ============================
    case 'update_category':
        if (!hasPermission('expenses', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) jsonResponse(['success' => false, 'message' => __('exp_cat_name_required')]);

        $conn->prepare("UPDATE expense_categories SET name = ? WHERE id = ?")->execute([$name, $id]);
        logActivity('updated', 'expense_category', $id, ['name' => $name]);
        jsonResponse(['success' => true, 'message' => __('exp_cat_updated')]);
        break;

    // ============================
    // CATEGORIES — DELETE
    // ============================
    case 'delete_category':
        if (!hasPermission('expenses', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        // Null-out expenses in this category
        $conn->prepare("UPDATE expenses SET category_id = NULL WHERE category_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM expense_categories WHERE id = ?")->execute([$id]);
        logActivity('deleted', 'expense_category', $id);
        jsonResponse(['success' => true, 'message' => __('exp_cat_deleted')]);
        break;

    // ============================
    // INCOME VS EXPENSE REPORT
    // ============================
    case 'income_expense':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $propertyId = (int)($_GET['property_id'] ?? 0);
        $year = (int)($_GET['year'] ?? date('Y'));

        $propFilter = $propertyId ? "AND u.property_id = :pid" : "";
        $propFilterExp = $propertyId ? "AND e.property_id = :pid" : "";
        $params = $propertyId ? ['pid' => $propertyId] : [];

        // Monthly rent income
        $rentSql = "
            SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS month,
                   COALESCE(SUM(p.amount), 0) AS total
            FROM payments p
            INNER JOIN units u ON p.unit_id = u.id
            WHERE p.status = 'recorded'
            AND YEAR(p.payment_date) = :year
            $propFilter" . propertyScopeSql('u.property_id') . "
            GROUP BY month ORDER BY month
        ";
        $rentStmt = $conn->prepare($rentSql);
        $rentStmt->execute(array_merge($params, ['year' => $year]));
        $rentByMonth = [];
        foreach ($rentStmt->fetchAll() as $r) {
            $rentByMonth[$r['month']] = (float)$r['total'];
        }

        // Monthly utility income
        $utilSql = "
            SELECT CONCAT(:year2, '-', LPAD(uc.period_month, 2, '0')) AS month,
                   COALESCE(SUM(uc.amount), 0) AS total
            FROM utility_charges uc
            INNER JOIN units u ON uc.unit_id = u.id
            WHERE uc.status = 'paid'
            AND uc.period_year = :year
            $propFilter" . propertyScopeSql('u.property_id') . "
            GROUP BY month ORDER BY month
        ";
        $utilStmt = $conn->prepare($utilSql);
        $utilStmt->execute(array_merge($params, ['year' => $year, 'year2' => $year]));
        $utilByMonth = [];
        foreach ($utilStmt->fetchAll() as $r) {
            $utilByMonth[$r['month']] = (float)$r['total'];
        }

        // Monthly expenses
        $expSql = "
            SELECT DATE_FORMAT(e.expense_date, '%Y-%m') AS month,
                   COALESCE(SUM(e.amount), 0) AS total
            FROM expenses e
            WHERE e.status = 'active'
            AND YEAR(e.expense_date) = :year
            $propFilterExp" . propertyScopeSql('e.property_id') . "
            GROUP BY month ORDER BY month
        ";
        $expStmt = $conn->prepare($expSql);
        $expStmt->execute(array_merge($params, ['year' => $year]));
        $expByMonth = [];
        foreach ($expStmt->fetchAll() as $r) {
            $expByMonth[$r['month']] = (float)$r['total'];
        }

        // Build monthly data
        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $key = $year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
            $rent = $rentByMonth[$key] ?? 0;
            $util = $utilByMonth[$key] ?? 0;
            $exp = $expByMonth[$key] ?? 0;
            $monthly[] = [
                'month' => $key,
                'rent_income' => $rent,
                'utility_income' => $util,
                'total_income' => $rent + $util,
                'expenses' => $exp,
                'net_income' => ($rent + $util) - $exp
            ];
        }

        // Totals
        $totalRent = array_sum($rentByMonth);
        $totalUtil = array_sum($utilByMonth);
        $totalExp = array_sum($expByMonth);

        jsonResponse(['success' => true, 'data' => [
            'year' => $year,
            'monthly' => $monthly,
            'totals' => [
                'rent_income' => $totalRent,
                'utility_income' => $totalUtil,
                'total_income' => $totalRent + $totalUtil,
                'expenses' => $totalExp,
                'net_income' => ($totalRent + $totalUtil) - $totalExp
            ]
        ]]);
        break;

    // ============================
    // PROFIT/LOSS PER PROPERTY
    // ============================
    case 'profit_loss':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $year = (int)($_GET['year'] ?? date('Y'));
        $period = trim($_GET['period'] ?? 'monthly'); // monthly, quarterly, yearly

        // Get all properties
        $properties = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name")->fetchAll();

        $results = [];
        foreach ($properties as $prop) {
            $pid = $prop['id'];

            // Rent income
            $rentStmt = $conn->prepare("
                SELECT COALESCE(SUM(p.amount), 0) FROM payments p
                INNER JOIN units u ON p.unit_id = u.id
                WHERE p.status = 'recorded' AND YEAR(p.payment_date) = ? AND u.property_id = ?
            ");
            $rentStmt->execute([$year, $pid]);
            $rentIncome = (float)$rentStmt->fetchColumn();

            // Utility income
            $utilStmt = $conn->prepare("
                SELECT COALESCE(SUM(uc.amount), 0) FROM utility_charges uc
                INNER JOIN units u ON uc.unit_id = u.id
                WHERE uc.status = 'paid' AND uc.period_year = ? AND u.property_id = ?
            ");
            $utilStmt->execute([$year, $pid]);
            $utilIncome = (float)$utilStmt->fetchColumn();

            // Expenses
            $expStmt = $conn->prepare("
                SELECT COALESCE(SUM(e.amount), 0) FROM expenses e
                WHERE e.status = 'active' AND YEAR(e.expense_date) = ? AND e.property_id = ?
            ");
            $expStmt->execute([$year, $pid]);
            $expenses = (float)$expStmt->fetchColumn();

            // Units & occupancy
            $unitStmt = $conn->prepare("SELECT COUNT(*) FROM units WHERE property_id = ?");
            $unitStmt->execute([$pid]);
            $totalUnits = (int)$unitStmt->fetchColumn();

            $occStmt = $conn->prepare("SELECT COUNT(*) FROM units WHERE property_id = ? AND status = 'occupied'");
            $occStmt->execute([$pid]);
            $occupiedUnits = (int)$occStmt->fetchColumn();

            $totalIncome = $rentIncome + $utilIncome;
            $netIncome = $totalIncome - $expenses;

            $results[] = [
                'property_id' => $pid,
                'property_name' => $prop['name'],
                'rent_income' => $rentIncome,
                'utility_income' => $utilIncome,
                'total_income' => $totalIncome,
                'expenses' => $expenses,
                'net_income' => $netIncome,
                'margin_percent' => $totalIncome > 0 ? round(($netIncome / $totalIncome) * 100, 1) : 0,
                'total_units' => $totalUnits,
                'occupied_units' => $occupiedUnits,
                'occupancy_rate' => $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 1) : 0
            ];
        }

        // Sort by net income desc
        usort($results, function($a, $b) { return $b['net_income'] <=> $a['net_income']; });

        // Grand totals
        $grandRent = array_sum(array_column($results, 'rent_income'));
        $grandUtil = array_sum(array_column($results, 'utility_income'));
        $grandExp = array_sum(array_column($results, 'expenses'));
        $grandIncome = $grandRent + $grandUtil;
        $grandNet = $grandIncome - $grandExp;

        jsonResponse(['success' => true, 'data' => [
            'year' => $year,
            'properties' => $results,
            'grand_totals' => [
                'rent_income' => $grandRent,
                'utility_income' => $grandUtil,
                'total_income' => $grandIncome,
                'expenses' => $grandExp,
                'net_income' => $grandNet,
                'margin_percent' => $grandIncome > 0 ? round(($grandNet / $grandIncome) * 100, 1) : 0
            ]
        ]]);
        break;

    // ============================
    // EXPENSE BREAKDOWN BY CATEGORY
    // ============================
    case 'by_category':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $propertyId = (int)($_GET['property_id'] ?? 0);
        $year = (int)($_GET['year'] ?? date('Y'));

        $propFilter = $propertyId ? "AND e.property_id = :pid" : "";
        $params = $propertyId ? ['pid' => $propertyId, 'year' => $year] : ['year' => $year];

        $stmt = $conn->prepare("
            SELECT COALESCE(ec.name, 'Uncategorized') AS category_name,
                   COUNT(*) AS count,
                   COALESCE(SUM(e.amount), 0) AS total
            FROM expenses e
            LEFT JOIN expense_categories ec ON e.category_id = ec.id
            WHERE e.status = 'active' AND YEAR(e.expense_date) = :year $propFilter" . propertyScopeSql('e.property_id') . "
            GROUP BY ec.id, ec.name
            ORDER BY total DESC
        ");
        $stmt->execute($params);

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // EXPORT EXPENSES CSV
    // ============================
    case 'export_expenses':
        if (!hasPermission('expenses', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $propertyId = (int)($_GET['property_id'] ?? 0);
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');

        $where = "e.status = 'active'" . propertyScopeSql('e.property_id');
        $params = [];
        if ($propertyId) { $where .= " AND e.property_id = ?"; $params[] = $propertyId; }
        if ($dateFrom) { $where .= " AND e.expense_date >= ?"; $params[] = $dateFrom; }
        if ($dateTo) { $where .= " AND e.expense_date <= ?"; $params[] = $dateTo; }

        $stmt = $conn->prepare("
            SELECT e.id, p.name AS property, u2.unit_number AS unit,
                   ec.name AS category, e.description, e.amount, e.currency,
                   e.expense_date, e.vendor_name, usr.full_name AS recorded_by, e.created_at
            FROM expenses e
            LEFT JOIN expense_categories ec ON e.category_id = ec.id
            LEFT JOIN properties p ON e.property_id = p.id
            LEFT JOIN units u2 ON e.unit_id = u2.id
            LEFT JOIN users usr ON e.recorded_by = usr.id
            WHERE $where
            ORDER BY e.expense_date DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="expenses_export_' . date('Y-m-d') . '.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['ID', 'Property', 'Unit', 'Category', 'Description', 'Amount', 'Currency', 'Date', 'Vendor', 'Recorded By', 'Created']);
        foreach ($rows as $row) {
            fputcsv($fp, [
                $row['id'], $row['property'], $row['unit'] ?? '', $row['category'] ?? '',
                $row['description'], $row['amount'], $row['currency'],
                $row['expense_date'], $row['vendor_name'], $row['recorded_by'], $row['created_at']
            ]);
        }
        fclose($fp);
        exit;

    // ============================
    // EXPORT INCOME REPORT CSV
    // ============================
    case 'export_income':
        if (!hasPermission('reports', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $year = (int)($_GET['year'] ?? date('Y'));
        $propertyId = (int)($_GET['property_id'] ?? 0);

        $propFilter = $propertyId ? "AND u.property_id = ?" : "";
        $propFilterExp = $propertyId ? "AND e.property_id = ?" : "";

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="income_expense_' . $year . '.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['Month', 'Rent Income', 'Utility Income', 'Total Income', 'Expenses', 'Net Income']);

        for ($m = 1; $m <= 12; $m++) {
            $key = $year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);

            // Rent
            $sql = "SELECT COALESCE(SUM(p.amount), 0) FROM payments p INNER JOIN units u ON p.unit_id = u.id WHERE p.status = 'recorded' AND DATE_FORMAT(p.payment_date, '%Y-%m') = ? $propFilter" . propertyScopeSql('u.property_id');
            $ps = [$key];
            if ($propertyId) $ps[] = $propertyId;
            $s = $conn->prepare($sql);
            $s->execute($ps);
            $rent = (float)$s->fetchColumn();

            // Utility
            $sql2 = "SELECT COALESCE(SUM(uc.amount), 0) FROM utility_charges uc INNER JOIN units u ON uc.unit_id = u.id WHERE uc.status = 'paid' AND uc.period_year = ? AND uc.period_month = ? $propFilter" . propertyScopeSql('u.property_id');
            $ps2 = [$year, $m];
            if ($propertyId) $ps2[] = $propertyId;
            $s2 = $conn->prepare($sql2);
            $s2->execute($ps2);
            $util = (float)$s2->fetchColumn();

            // Expenses
            $sql3 = "SELECT COALESCE(SUM(e.amount), 0) FROM expenses e WHERE e.status = 'active' AND DATE_FORMAT(e.expense_date, '%Y-%m') = ? $propFilterExp" . propertyScopeSql('e.property_id');
            $ps3 = [$key];
            if ($propertyId) $ps3[] = $propertyId;
            $s3 = $conn->prepare($sql3);
            $s3->execute($ps3);
            $exp = (float)$s3->fetchColumn();

            fputcsv($fp, [$key, $rent, $util, $rent + $util, $exp, ($rent + $util) - $exp]);
        }
        fclose($fp);
        exit;

    // ============================
    // STATS
    // ============================
    case 'stats':
        if (!hasPermission('expenses', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $year = (int)($_GET['year'] ?? date('Y'));

        $totalExp = (float)$conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = 'active' AND YEAR(expense_date) = ?")->execute([$year]) ? 0 : 0;
        $s = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = 'active' AND YEAR(expense_date) = ?" . propertyScopeSql('property_id'));
        $s->execute([$year]);
        $totalExpenses = (float)$s->fetchColumn();

        $s2 = $conn->prepare("SELECT COUNT(*) FROM expenses WHERE status = 'active' AND YEAR(expense_date) = ?" . propertyScopeSql('property_id'));
        $s2->execute([$year]);
        $expenseCount = (int)$s2->fetchColumn();

        $s3 = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = 'active' AND MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())" . propertyScopeSql('property_id'));
        $s3->execute();
        $thisMonthExpenses = (float)$s3->fetchColumn();

        jsonResponse(['success' => true, 'data' => [
            'total_expenses' => $totalExpenses,
            'expense_count' => $expenseCount,
            'this_month' => $thisMonthExpenses,
            'year' => $year
        ]]);
        break;

    // ============================
    // DROPDOWNS
    // ============================
    case 'dropdowns':
        $properties = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name")->fetchAll();
        $categories = $conn->query("SELECT id, name FROM expense_categories ORDER BY name")->fetchAll();
        $units = $conn->query("SELECT u.id, u.unit_number, p.name AS property_name, u.property_id FROM units u INNER JOIN properties p ON u.property_id = p.id WHERE 1=1" . propertyScopeSql('u.property_id') . " ORDER BY p.name, u.unit_number")->fetchAll();

        jsonResponse(['success' => true, 'data' => [
            'properties' => $properties,
            'categories' => $categories,
            'units' => $units
        ]]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}
