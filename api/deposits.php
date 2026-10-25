<?php
/**
 * FIS Real Estate — Deposits & Late Fees API
 * Handles CRUD for security deposits, deposit deductions, refund workflow,
 * and late fee management (list, waive, apply).
 * Used by AJAX calls from the deposits module pages.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payables.php';
require_once __DIR__ . '/../includes/charges.php';

// Must be logged in
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // LIST DEPOSITS (with filters, search, pagination)
    // ============================
    case 'list':
        if (!hasPermission('deposits', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $propertyId = (int)($_GET['property_id'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int)($_GET['per_page'] ?? RECORDS_PER_PAGE)));

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(CONCAT(t.first_name, ' ', t.last_name) LIKE :search OR u.unit_number LIKE :search2 OR p.name LIKE :search3 OR d.reference_number LIKE :search4)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
            $params['search4'] = "%{$search}%";
        }
        if ($status !== '') {
            $where[] = "d.status = :status";
            $params['status'] = $status;
        }
        if ($propertyId > 0) {
            $where[] = "u.property_id = :property_id";
            $params['property_id'] = $propertyId;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Property-based access scoping (query JOINs units u)
        $scopeSql = propertyScopeSql('u.property_id');
        if ($scopeSql !== '') {
            $whereSql = ($whereSql !== '' ? $whereSql : 'WHERE 1=1') . $scopeSql;
        }

        // Count total
        $countStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM deposits d
            INNER JOIN units u ON d.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            INNER JOIN tenants t ON d.tenant_id = t.id
            {$whereSql}
        ");
        $countStmt->execute($params);
        $totalRecords = (int) $countStmt->fetchColumn();

        $pagination = paginate($totalRecords, $page, $perPage);

        // Fetch records
        $sql = "SELECT d.*, u.unit_number, p.name AS property_name, p.id AS property_id_val,
                CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
                l.start_date AS lease_start, l.end_date AS lease_end, l.status AS lease_status,
                (SELECT COALESCE(SUM(dd.amount),0) FROM deposit_deductions dd WHERE dd.deposit_id = d.id) AS total_deductions
                FROM deposits d
                INNER JOIN units u ON d.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                INNER JOIN tenants t ON d.tenant_id = t.id
                INNER JOIN leases l ON d.lease_id = l.id
                {$whereSql}
                ORDER BY d.created_at DESC
                LIMIT :offset, :limit";

        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":{$k}", $v);
        }
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $deposits = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'data' => $deposits,
            'pagination' => $pagination
        ]);
        break;

    // ============================
    // GET SINGLE DEPOSIT
    // ============================
    case 'get':
        if (!hasPermission('deposits', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Deposit ID required'], 400);
        }

        $stmt = $conn->prepare("
            SELECT d.*, u.unit_number, u.unit_label, p.name AS property_name, p.id AS property_id_val,
            CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone, t.email AS tenant_email,
            t.id AS tenant_id_val,
            l.start_date AS lease_start, l.end_date AS lease_end, l.rent_amount AS lease_rent,
            l.status AS lease_status, l.deposit_amount AS lease_deposit
            FROM deposits d
            INNER JOIN units u ON d.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            INNER JOIN tenants t ON d.tenant_id = t.id
            INNER JOIN leases l ON d.lease_id = l.id
            WHERE d.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $deposit = $stmt->fetch();

        if (!$deposit) {
            jsonResponse(['success' => false, 'message' => 'Deposit not found'], 404);
        }
        if (!canAccessUnit($deposit['unit_id'])) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        // Get deductions
        $dedStmt = $conn->prepare("SELECT * FROM deposit_deductions WHERE deposit_id = :did ORDER BY created_at");
        $dedStmt->execute(['did' => $id]);
        $deductions = $dedStmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $deposit, 'deductions' => $deductions]);
        break;

    // ============================
    // CREATE DEPOSIT (10.1)
    // ============================
    case 'create':
        if (!hasPermission('deposits', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $leaseId = (int)($_POST['lease_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $paidDate = trim($_POST['paid_date'] ?? '');
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $validMethods = ['cash', 'bank_transfer', 'mobile_money', 'cheque'];

        // Validate
        $errors = [];
        if (!$leaseId) $errors[] = 'Lease is required';
        if ($amount <= 0) $errors[] = 'Amount must be greater than 0';
        if ($paidDate === '') $errors[] = 'Paid date is required';
        if (!in_array($paymentMethod, $validMethods)) $errors[] = 'Invalid payment method';

        // Look up lease
        $tenantId = 0;
        $unitId = 0;
        if ($leaseId) {
            $leaseCheck = $conn->prepare("SELECT l.*, u.property_id FROM leases l INNER JOIN units u ON l.unit_id = u.id WHERE l.id = :id");
            $leaseCheck->execute(['id' => $leaseId]);
            $leaseData = $leaseCheck->fetch();
            if (!$leaseData) {
                $errors[] = 'Lease not found';
            } else {
                $tenantId = (int)$leaseData['tenant_id'];
                $unitId = (int)$leaseData['unit_id'];
                if (!canAccessUnit($unitId)) {
                    $errors[] = 'No access to this record';
                }
                if (empty($currency)) $currency = $leaseData['currency'];

                // Check if deposit already exists for this lease
                $existCheck = $conn->prepare("SELECT id FROM deposits WHERE lease_id = :lid");
                $existCheck->execute(['lid' => $leaseId]);
                if ($existCheck->fetch()) {
                    $errors[] = 'A deposit already exists for this lease';
                }
            }
        }

        if ($errors) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors], 422);
        }

        try {
            $stmt = $conn->prepare("
                INSERT INTO deposits (lease_id, tenant_id, unit_id, amount, currency, paid_date,
                    payment_method, reference_number, notes, ledger_account_id, status)
                VALUES (:lease_id, :tenant_id, :unit_id, :amount, :currency, :paid_date,
                    :payment_method, :reference_number, :notes, :ledger_account_id, 'held')
            ");
            $stmt->execute([
                'lease_id' => $leaseId,
                'tenant_id' => $tenantId,
                'unit_id' => $unitId,
                'amount' => $amount,
                'currency' => $currency,
                'paid_date' => date('Y-m-d', strtotime($paidDate)),
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber ?: null,
                'notes' => $notes ?: null,
                'ledger_account_id' => (int)($_POST['ledger_account_id'] ?? 0) ?: null,
            ]);

            $depositId = (int) $conn->lastInsertId();
            // Ledger (part 31): Dr Float/Bank  Cr Tenant Deposits Held.
            postDeposit($depositId);


            logActivity('created', 'deposit', $depositId, [
                'amount' => $amount,
                'currency' => $currency,
                'lease_id' => $leaseId,
                'tenant_id' => $tenantId,
            ]);

            jsonResponse(['success' => true, 'message' => 'Deposit recorded successfully', 'id' => $depositId]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to record deposit: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // UPDATE DEPOSIT
    // ============================
    case 'update':
        if (!hasPermission('deposits', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Deposit ID required'], 400);
        }

        $existing = $conn->prepare("SELECT * FROM deposits WHERE id = :id");
        $existing->execute(['id' => $id]);
        $old = $existing->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'message' => 'Deposit not found'], 404);
        }
        if (!canAccessUnit($old['unit_id'])) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }
        if (in_array($old['status'], ['refunded', 'forfeited'])) {
            jsonResponse(['success' => false, 'message' => 'Refunded/forfeited deposits cannot be edited'], 422);
        }

        $amount = (float)($_POST['amount'] ?? 0);
        $paidDate = trim($_POST['paid_date'] ?? '');
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $validMethods = ['cash', 'bank_transfer', 'mobile_money', 'cheque'];

        $errors = [];
        if ($amount <= 0) $errors[] = 'Amount must be greater than 0';
        if ($paidDate === '') $errors[] = 'Paid date is required';
        if (!in_array($paymentMethod, $validMethods)) $errors[] = 'Invalid payment method';

        if ($errors) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors], 422);
        }

        try {
            $stmt = $conn->prepare("
                UPDATE deposits SET
                    amount = :amount, paid_date = :paid_date,
                    payment_method = :payment_method, reference_number = :reference_number,
                    notes = :notes
                WHERE id = :id
            ");
            $stmt->execute([
                'amount' => $amount,
                'paid_date' => date('Y-m-d', strtotime($paidDate)),
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber ?: null,
                'notes' => $notes ?: null,
                'id' => $id,
            ]);

            logActivity('updated', 'deposit', $id, [
                'amount' => $old['amount'] . ' -> ' . $amount
            ]);

            jsonResponse(['success' => true, 'message' => 'Deposit updated successfully']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to update deposit: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // DELETE DEPOSIT
    // ============================
    case 'delete':
        if (!hasPermission('deposits', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Deposit ID required'], 400);
        }

        $existing = $conn->prepare("SELECT * FROM deposits WHERE id = :id");
        $existing->execute(['id' => $id]);
        $deposit = $existing->fetch();
        if (!$deposit) {
            jsonResponse(['success' => false, 'message' => 'Deposit not found'], 404);
        }
        if (!canAccessUnit($deposit['unit_id'])) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        try {
            $conn->beginTransaction();
            $conn->prepare("DELETE FROM deposit_deductions WHERE deposit_id = :id")->execute(['id' => $id]);
            $conn->prepare("DELETE FROM deposits WHERE id = :id")->execute(['id' => $id]);
            $conn->commit();

            logActivity('deleted', 'deposit', $id, [
                'amount' => $deposit['amount'],
                'tenant_id' => $deposit['tenant_id']
            ]);

            jsonResponse(['success' => true, 'message' => 'Deposit deleted successfully']);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Failed to delete deposit: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // ADD DEDUCTION (10.2)
    // ============================
    case 'add_deduction':
        if (!hasPermission('deposits', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $depositId = (int)($_POST['deposit_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $inspectionItemId = (int)($_POST['inspection_item_id'] ?? 0) ?: null;

        $errors = [];
        if (!$depositId) $errors[] = 'Deposit ID required';
        if ($description === '') $errors[] = 'Description is required';
        if ($amount <= 0) $errors[] = 'Amount must be greater than 0';

        // Check deposit exists and is held/partially_refunded
        if ($depositId) {
            $depCheck = $conn->prepare("SELECT * FROM deposits WHERE id = :id");
            $depCheck->execute(['id' => $depositId]);
            $deposit = $depCheck->fetch();
            if (!$deposit) {
                $errors[] = 'Deposit not found';
            } elseif (!canAccessUnit($deposit['unit_id'])) {
                $errors[] = 'No access to this record';
            } elseif (in_array($deposit['status'], ['refunded', 'forfeited'])) {
                $errors[] = 'Cannot add deduction to refunded/forfeited deposit';
            } else {
                // Check total deductions don't exceed deposit amount
                $totalDed = (float)$conn->prepare("SELECT COALESCE(SUM(amount),0) FROM deposit_deductions WHERE deposit_id = :did")
                    ->execute(['did' => $depositId]) ?: 0;
                $dedStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM deposit_deductions WHERE deposit_id = :did");
                $dedStmt->execute(['did' => $depositId]);
                $totalDed = (float)$dedStmt->fetchColumn();

                if (($totalDed + $amount) > (float)$deposit['amount']) {
                    $errors[] = 'Total deductions (' . formatCurrency($totalDed + $amount, $deposit['currency']) . ') would exceed deposit amount (' . formatCurrency($deposit['amount'], $deposit['currency']) . ')';
                }
            }
        }

        if ($errors) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors], 422);
        }

        try {
            $stmt = $conn->prepare("
                INSERT INTO deposit_deductions (deposit_id, description, amount, inspection_item_id)
                VALUES (:deposit_id, :description, :amount, :inspection_item_id)
            ");
            $stmt->execute([
                'deposit_id' => $depositId,
                'description' => $description,
                'amount' => $amount,
                'inspection_item_id' => $inspectionItemId,
            ]);

            $deductionId = (int)$conn->lastInsertId();

            logActivity('created', 'deposit_deduction', $deductionId, [
                'deposit_id' => $depositId,
                'description' => $description,
                'amount' => $amount
            ]);

            jsonResponse(['success' => true, 'message' => 'Deduction added successfully', 'id' => $deductionId]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to add deduction: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // DELETE DEDUCTION (10.2)
    // ============================
    case 'delete_deduction':
        if (!hasPermission('deposits', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Deduction ID required'], 400);
        }

        $existing = $conn->prepare("SELECT dd.*, d.status AS deposit_status, d.unit_id AS deposit_unit_id FROM deposit_deductions dd INNER JOIN deposits d ON dd.deposit_id = d.id WHERE dd.id = :id");
        $existing->execute(['id' => $id]);
        $deduction = $existing->fetch();
        if (!$deduction) {
            jsonResponse(['success' => false, 'message' => 'Deduction not found'], 404);
        }
        if (!canAccessUnit($deduction['deposit_unit_id'])) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }
        if (in_array($deduction['deposit_status'], ['refunded', 'forfeited'])) {
            jsonResponse(['success' => false, 'message' => 'Cannot remove deduction from refunded/forfeited deposit'], 422);
        }

        try {
            $conn->prepare("DELETE FROM deposit_deductions WHERE id = :id")->execute(['id' => $id]);

            logActivity('deleted', 'deposit_deduction', $id, [
                'deposit_id' => $deduction['deposit_id'],
                'description' => $deduction['description'],
                'amount' => $deduction['amount']
            ]);

            jsonResponse(['success' => true, 'message' => 'Deduction removed successfully']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to remove deduction: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // GET DEDUCTIONS FOR DEPOSIT
    // ============================
    case 'deductions':
        if (!hasPermission('deposits', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $depositId = (int)($_GET['deposit_id'] ?? 0);
        if (!$depositId) {
            jsonResponse(['success' => false, 'message' => 'Deposit ID required'], 400);
        }

        $stmt = $conn->prepare("SELECT * FROM deposit_deductions WHERE deposit_id = :did ORDER BY created_at");
        $stmt->execute(['did' => $depositId]);
        $deductions = $stmt->fetchAll();

        $totalStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM deposit_deductions WHERE deposit_id = :did");
        $totalStmt->execute(['did' => $depositId]);
        $totalDeductions = (float)$totalStmt->fetchColumn();

        jsonResponse(['success' => true, 'data' => $deductions, 'total' => $totalDeductions]);
        break;

    // ============================
    // PROCESS REFUND (10.3)
    // ============================
    case 'process_refund':
        if (!hasPermission('deposits', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        // Part 32: a refund is money leaving, so this now RAISES A REQUEST rather
        // than paying out. depositPayoutApprove() posts the entry and updates the
        // deposit's status once a second person signs it off.
        $r = depositPayoutRequest(
            (int) ($_POST['deposit_id'] ?? 0),
            'refund',
            (float) str_replace(',', '', $_POST['refund_amount'] ?? 0),
            (int) ($_POST['from_account_id'] ?? 0) ?: null,
            trim($_POST['refund_method'] ?? '') . ' ' . trim($_POST['refund_reference'] ?? '')
        );
        jsonResponse($r['success']
            ? ['success' => true, 'message' => __('dep_requested', 'Submitted for approval')]
            : ['success' => false, 'message' => implode(' ', $r['errors'])],
            $r['success'] ? 200 : 400);
        break;

    case 'forfeit':
        if (!hasPermission('deposits', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        // Part 32: forfeiting takes the deposit from the tenant, so it needs the
        // same sign-off as a refund. The amount forfeited is whatever is held.
        $depositId = (int) ($_POST['deposit_id'] ?? 0);
        $held = $conn->prepare('SELECT amount FROM deposits WHERE id = ?');
        $held->execute([$depositId]);
        $amount = (float) $held->fetchColumn();

        $r = depositPayoutRequest($depositId, 'forfeit', $amount, null, trim($_POST['reason'] ?? ''));
        jsonResponse($r['success']
            ? ['success' => true, 'message' => __('dep_requested', 'Submitted for approval')]
            : ['success' => false, 'message' => implode(' ', $r['errors'])],
            $r['success'] ? 200 : 400);
        break;

    case 'leases_for_deposit':
        if (!hasPermission('deposits', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        // Active leases that don't already have a deposit
        $stmt = $conn->query("
            SELECT l.id, l.rent_amount, l.deposit_amount, l.currency, l.deposit_currency,
                   l.start_date, l.end_date,
                   u.unit_number, u.id AS unit_id, p.name AS property_name, p.id AS property_id,
                   CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
                   t.id AS tenant_id
            FROM leases l
            INNER JOIN units u ON l.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            INNER JOIN tenants t ON l.tenant_id = t.id
            LEFT JOIN deposits d ON d.lease_id = l.id
            WHERE l.status = 'active' AND d.id IS NULL" . propertyScopeSql('u.property_id') . "
            ORDER BY t.first_name, t.last_name, u.unit_number
        ");
        $leases = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $leases]);
        break;

    // ============================
    // GET PROPERTIES LIST
    // ============================
    case 'properties':
        if (!hasPermission('deposits', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name");
        $properties = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $properties]);
        break;

    // ============================
    // DEPOSIT STATS (for dashboard)
    // ============================
    case 'stats':
        if (!hasPermission('deposits', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $totalHeld = (float)$conn->query("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status = 'held'")->fetchColumn();
        $totalCount = (int)$conn->query("SELECT COUNT(*) FROM deposits")->fetchColumn();
        $heldCount = (int)$conn->query("SELECT COUNT(*) FROM deposits WHERE status = 'held'")->fetchColumn();
        $refundedCount = (int)$conn->query("SELECT COUNT(*) FROM deposits WHERE status IN ('refunded','partially_refunded')")->fetchColumn();

        jsonResponse([
            'success' => true,
            'total_held' => $totalHeld,
            'total_count' => $totalCount,
            'held_count' => $heldCount,
            'refunded_count' => $refundedCount
        ]);
        break;

    // ============================
    // LATE FEES — LIST (10.5)
    // ============================
    case 'late_fees':
        if (!hasPermission('deposits', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $propertyId = (int)($_GET['property_id'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int)($_GET['per_page'] ?? RECORDS_PER_PAGE)));

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(CONCAT(t.first_name, ' ', t.last_name) LIKE :search OR u.unit_number LIKE :search2 OR p.name LIKE :search3)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
        }
        if ($status !== '') {
            $where[] = "lf.status = :status";
            $params['status'] = $status;
        }
        if ($propertyId > 0) {
            $where[] = "u.property_id = :property_id";
            $params['property_id'] = $propertyId;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Property-based access scoping (query JOINs units u)
        $scopeSql = propertyScopeSql('u.property_id');
        if ($scopeSql !== '') {
            $whereSql = ($whereSql !== '' ? $whereSql : 'WHERE 1=1') . $scopeSql;
        }

        $countStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM late_fees lf
            INNER JOIN leases l ON lf.lease_id = l.id
            INNER JOIN units u ON l.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            INNER JOIN tenants t ON lf.tenant_id = t.id
            {$whereSql}
        ");
        $countStmt->execute($params);
        $totalRecords = (int)$countStmt->fetchColumn();

        $pagination = paginate($totalRecords, $page, $perPage);

        $sql = "SELECT lf.*, u.unit_number, p.name AS property_name,
                CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
                l.rent_amount AS lease_rent
                FROM late_fees lf
                INNER JOIN leases l ON lf.lease_id = l.id
                INNER JOIN units u ON l.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                INNER JOIN tenants t ON lf.tenant_id = t.id
                {$whereSql}
                ORDER BY lf.fee_date DESC, lf.created_at DESC
                LIMIT :offset, :limit";

        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":{$k}", $v);
        }
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $lateFees = $stmt->fetchAll();

        // Sum pending
        $pendingSum = $conn->prepare("
            SELECT COALESCE(SUM(lf.amount),0)
            FROM late_fees lf
            INNER JOIN leases l ON lf.lease_id = l.id
            INNER JOIN units u ON l.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            INNER JOIN tenants t ON lf.tenant_id = t.id
            {$whereSql} " . ($whereSql ? "AND" : "WHERE") . " lf.status = 'pending'
        ");
        $pendingSum->execute($params);
        $pendingTotal = (float)$pendingSum->fetchColumn();

        jsonResponse([
            'success' => true,
            'data' => $lateFees,
            'pagination' => $pagination,
            'pending_total' => $pendingTotal
        ]);
        break;

    // ============================
    // WAIVE LATE FEE (10.5)
    // ============================
    case 'waive_late_fee':
        if (!hasPermission('deposits', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Late fee ID required'], 400);
        }

        $existing = $conn->prepare("SELECT * FROM late_fees WHERE id = :id");
        $existing->execute(['id' => $id]);
        $fee = $existing->fetch();
        if (!$fee) {
            jsonResponse(['success' => false, 'message' => 'Late fee not found'], 404);
        }
        if (!canAccessLease($fee['lease_id'])) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }
        if ($fee['status'] !== 'pending') {
            jsonResponse(['success' => false, 'message' => 'Only pending late fees can be waived'], 422);
        }

        try {
            $conn->prepare("UPDATE late_fees SET status = 'waived', waived_by = :user_id, waived_reason = :reason WHERE id = :id")
                ->execute([
                    'user_id' => getUserId(),
                    'reason' => $reason ?: null,
                    'id' => $id
                ]);

            logActivity('updated', 'late_fee', $id, [
                'status_change' => 'pending -> waived',
                'reason' => $reason,
                'amount' => $fee['amount']
            ]);

            jsonResponse(['success' => true, 'message' => 'Late fee waived successfully']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to waive late fee: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // APPLY LATE FEES MANUALLY (10.5)
    // ============================
    case 'apply_late_fees':
        if (!hasPermission('deposits', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $result = applyLateFees($conn);

        jsonResponse([
            'success' => true,
            'message' => $result['applied'] . ' late fee(s) applied',
            'applied' => $result['applied'],
            'skipped' => $result['skipped']
        ]);
        break;

    // ============================
    // LATE FEE SETTINGS (10.4)
    // ============================
    case 'get_late_fee_settings':
        if (!hasPermission('deposits', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        jsonResponse([
            'success' => true,
            'data' => [
                'late_fee_enabled' => getSetting('late_fee_enabled', '0'),
                'late_fee_type' => getSetting('late_fee_type', 'percentage'),
                'late_fee_value' => getSetting('late_fee_value', '5'),
                'grace_period_days' => getSetting('grace_period_days', '5'),
                'max_late_fee' => getSetting('max_late_fee', '0'),
                'late_fee_compound' => getSetting('late_fee_compound', '0'),
            ]
        ]);
        break;

    case 'save_late_fee_settings':
        if (!hasPermission('deposits', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $enabled = (int)($_POST['late_fee_enabled'] ?? 0);
        $type = trim($_POST['late_fee_type'] ?? 'percentage');
        $value = (float)($_POST['late_fee_value'] ?? 0);
        $graceDays = (int)($_POST['grace_period_days'] ?? 5);
        $maxFee = (float)($_POST['max_late_fee'] ?? 0);
        $compound = (int)($_POST['late_fee_compound'] ?? 0);

        if (!in_array($type, ['percentage', 'fixed'])) $type = 'percentage';
        if ($graceDays < 0) $graceDays = 0;
        if ($value < 0) $value = 0;

        updateSetting('late_fee_enabled', $enabled ? '1' : '0');
        updateSetting('late_fee_type', $type);
        updateSetting('late_fee_value', (string)$value);
        updateSetting('grace_period_days', (string)$graceDays);
        updateSetting('max_late_fee', (string)$maxFee);
        updateSetting('late_fee_compound', $compound ? '1' : '0');

        logActivity('updated', 'settings', null, [
            'late_fee_settings' => compact('enabled', 'type', 'value', 'graceDays', 'maxFee', 'compound')
        ]);

        jsonResponse(['success' => true, 'message' => 'Late fee settings saved successfully']);
        break;

    // ============================
    // ACTIVITY LOG (for deposit)
    // ============================
    case 'activity':
        if (!hasPermission('deposits', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $depositId = (int)($_GET['deposit_id'] ?? 0);
        if (!$depositId) {
            jsonResponse(['success' => false, 'message' => 'Deposit ID required'], 400);
        }

        $stmt = $conn->prepare("
            SELECT al.*, u.full_name AS user_name
            FROM activity_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.entity_type IN ('deposit', 'deposit_deduction')
              AND (al.entity_id = :did OR JSON_EXTRACT(al.details, '$.deposit_id') = :did2)
            ORDER BY al.created_at DESC
            LIMIT 20
        ");
        $stmt->execute(['did' => $depositId, 'did2' => $depositId]);
        $history = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $history]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
}

// ============================
// HELPER: Apply late fees to overdue leases
// ============================
function applyLateFees($conn) {
    $enabled = getSetting('late_fee_enabled', '0');
    if ($enabled !== '1') {
        return ['applied' => 0, 'skipped' => 0, 'message' => 'Late fees disabled'];
    }

    $feeType = getSetting('late_fee_type', 'percentage');
    $feeValue = (float)getSetting('late_fee_value', '5');
    $graceDays = (int)getSetting('grace_period_days', '5');
    $maxFee = (float)getSetting('max_late_fee', '0');
    $compound = getSetting('late_fee_compound', '0') === '1';

    if ($feeValue <= 0) {
        return ['applied' => 0, 'skipped' => 0, 'message' => 'Fee value is 0'];
    }

    $today = date('Y-m-d');
    $currentMonth = date('Y-m');

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
        // If due date hasn't passed yet this month, check previous month
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

        // Check if payment has been made for this period
        $paidCheck = $conn->prepare("
            SELECT COALESCE(SUM(amount),0)
            FROM payments
            WHERE lease_id = :lid AND status = 'recorded'
              AND (period_from <= :due AND period_to >= :due)
        ");
        $paidCheck->execute(['lid' => $leaseId, 'due' => $dueDate]);
        $paidAmount = (float)$paidCheck->fetchColumn();

        // Also check by month if no explicit period set
        if ($paidAmount <= 0) {
            $monthStart = date('Y-m-01', strtotime($dueDate));
            $monthEnd = date('Y-m-t', strtotime($dueDate));
            $paidCheck2 = $conn->prepare("
                SELECT COALESCE(SUM(amount),0)
                FROM payments
                WHERE lease_id = :lid AND status = 'recorded'
                  AND payment_date BETWEEN :ms AND :me
                  AND period_from IS NULL
            ");
            $paidCheck2->execute(['lid' => $leaseId, 'ms' => $monthStart, 'me' => $monthEnd]);
            $paidAmount += (float)$paidCheck2->fetchColumn();
        }

        if ($paidAmount >= $rentAmount) {
            $skipped++;
            continue;
        }

        // Calculate fee
        $feeAmount = 0;
        if ($feeType === 'percentage') {
            $feeAmount = ($rentAmount * $feeValue) / 100;
        } else {
            $feeAmount = $feeValue;
        }

        // Apply max cap
        if ($maxFee > 0 && $feeAmount > $maxFee) {
            $feeAmount = $maxFee;
        }

        // Check property-level overrides
        $propSettings = $conn->prepare("SELECT * FROM property_settings WHERE property_id = :pid");
        $propSettings->execute(['pid' => $lease['property_id']]);
        $ps = $propSettings->fetch();
        if ($ps && $ps['late_fee_type'] !== 'none') {
            $propFeeType = $ps['late_fee_type'];
            $propFeeValue = (float)$ps['late_fee_value'];
            $propMaxFee = $ps['max_late_fee'] ? (float)$ps['max_late_fee'] : 0;

            if ($propFeeValue > 0) {
                if ($propFeeType === 'percentage') {
                    $feeAmount = ($rentAmount * $propFeeValue) / 100;
                } else {
                    $feeAmount = $propFeeValue;
                }
                if ($propMaxFee > 0 && $feeAmount > $propMaxFee) {
                    $feeAmount = $propMaxFee;
                }
            }
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
                'auto' => true
            ]);

            $applied++;
        } catch (PDOException $e) {
            error_log("Late fee insert error: " . $e->getMessage());
            $skipped++;
        }
    }

    return ['applied' => $applied, 'skipped' => $skipped];
}
