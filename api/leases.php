<?php
/**
 * FIS Real Estate — Leases API
 * Handles CRUD operations for leases, lease templates, renewal, termination.
 * Used by AJAX calls from the leases module pages.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Must be logged in
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // LIST LEASES (with filters, search, pagination)
    // ============================
    case 'list':
        if (!hasPermission('leases', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $propertyId = (int)($_GET['property_id'] ?? 0);
        $frequency = trim($_GET['frequency'] ?? '');
        $expiring = trim($_GET['expiring'] ?? '');
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
            $where[] = "l.status = :status";
            $params['status'] = $status;
        }
        if ($propertyId > 0) {
            $where[] = "u.property_id = :property_id";
            $params['property_id'] = $propertyId;
        }
        if ($frequency !== '') {
            $where[] = "l.payment_frequency = :frequency";
            $params['frequency'] = $frequency;
        }
        if ($expiring === 'soon') {
            $where[] = "l.status = 'active' AND l.end_date <= DATE_ADD(CURDATE(), INTERVAL l.renewal_alert_days DAY)";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Property-based access scoping (units joined as u)
        $scopeSql = propertyScopeSql('u.property_id');
        if ($scopeSql !== '') {
            $whereSql = ($whereSql === '' ? 'WHERE 1=1' : $whereSql) . $scopeSql;
        }

        // Count total
        $countStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM leases l
            INNER JOIN units u ON l.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            INNER JOIN tenants t ON l.tenant_id = t.id
            {$whereSql}
        ");
        $countStmt->execute($params);
        $totalRecords = (int) $countStmt->fetchColumn();

        $pagination = paginate($totalRecords, $page, $perPage);

        // Fetch records
        $sql = "SELECT l.*, u.unit_number, p.name AS property_name, p.id AS property_id_val,
                CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone
                FROM leases l
                INNER JOIN units u ON l.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                INNER JOIN tenants t ON l.tenant_id = t.id
                {$whereSql}
                ORDER BY l.created_at DESC
                LIMIT :offset, :limit";

        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":{$k}", $v);
        }
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $leases = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'data' => $leases,
            'pagination' => $pagination
        ]);
        break;

    // ============================
    // GET SINGLE LEASE
    // ============================
    case 'get':
        if (!hasPermission('leases', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Lease ID required'], 400);
        }

        $stmt = $conn->prepare("
            SELECT l.*, u.unit_number, p.name AS property_name, p.id AS property_id_val,
            CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone, t.email AS tenant_email
            FROM leases l
            INNER JOIN units u ON l.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            INNER JOIN tenants t ON l.tenant_id = t.id
            WHERE l.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $lease = $stmt->fetch();

        if (!$lease) {
            jsonResponse(['success' => false, 'message' => 'Lease not found'], 404);
        }

        if (!canAccessLease($id)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        jsonResponse(['success' => true, 'data' => $lease]);
        break;

    // ============================
    // CREATE LEASE
    // ============================
    case 'create':
        if (!hasPermission('leases', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $unitId = (int)($_POST['unit_id'] ?? 0);
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');
        $rentAmount = (float)($_POST['rent_amount'] ?? 0);
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $depositAmount = (float)($_POST['deposit_amount'] ?? 0);
        $depositCurrency = trim($_POST['deposit_currency'] ?? DEFAULT_CURRENCY);
        $paymentFrequency = trim($_POST['payment_frequency'] ?? 'monthly');
        $paymentDueDay = (int)($_POST['payment_due_day'] ?? 1);
        $terms = trim($_POST['terms'] ?? '');
        $renewalAlertDays = (int)($_POST['renewal_alert_days'] ?? 30);

        $validFrequencies = paymentFrequencies();

        // Validate
        $errors = [];
        if (!$unitId) $errors[] = 'Unit is required';
        if (!$tenantId) $errors[] = 'Tenant is required';
        if ($startDate === '') $errors[] = 'Start date is required';
        if ($endDate === '') $errors[] = 'End date is required';
        if ($rentAmount <= 0) $errors[] = 'Rent amount must be greater than 0';
        if (!in_array($paymentFrequency, $validFrequencies)) $errors[] = 'Invalid payment frequency';
        if ($paymentDueDay < 1 || $paymentDueDay > 28) $errors[] = 'Payment due day must be between 1 and 28';
        if ($startDate && $endDate && strtotime($endDate) <= strtotime($startDate)) $errors[] = 'End date must be after start date';

        // Check unit exists and is available
        if ($unitId) {
            $unitCheck = $conn->prepare("SELECT id, status FROM units WHERE id = :id");
            $unitCheck->execute(['id' => $unitId]);
            $unitData = $unitCheck->fetch();
            if (!$unitData) {
                $errors[] = 'Unit not found';
            } elseif (!in_array($unitData['status'], ['vacant', 'reserved'])) {
                $errors[] = 'Unit is not available (current status: ' . $unitData['status'] . ')';
            }
        }

        // Check tenant exists
        if ($tenantId) {
            $tenantCheck = $conn->prepare("SELECT id, status FROM tenants WHERE id = :id");
            $tenantCheck->execute(['id' => $tenantId]);
            $tenantData = $tenantCheck->fetch();
            if (!$tenantData) {
                $errors[] = 'Tenant not found';
            } elseif ($tenantData['status'] !== 'active') {
                $errors[] = 'Tenant is not active';
            }
        }

        if ($errors) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors], 422);
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO leases (unit_id, tenant_id, start_date, end_date, rent_amount, currency,
                    deposit_amount, deposit_currency, payment_frequency, payment_due_day,
                    terms, renewal_alert_days, status)
                VALUES (:unit_id, :tenant_id, :start_date, :end_date, :rent_amount, :currency,
                    :deposit_amount, :deposit_currency, :payment_frequency, :payment_due_day,
                    :terms, :renewal_alert_days, 'active')
            ");
            $stmt->execute([
                'unit_id' => $unitId,
                'tenant_id' => $tenantId,
                'start_date' => date('Y-m-d', strtotime($startDate)),
                'end_date' => date('Y-m-d', strtotime($endDate)),
                'rent_amount' => $rentAmount,
                'currency' => $currency,
                'deposit_amount' => $depositAmount,
                'deposit_currency' => $depositCurrency,
                'payment_frequency' => $paymentFrequency,
                'payment_due_day' => $paymentDueDay,
                'terms' => $terms ?: null,
                'renewal_alert_days' => $renewalAlertDays,
            ]);

            $leaseId = (int) $conn->lastInsertId();

            // Auto-set unit status to 'occupied'
            $conn->prepare("UPDATE units SET status = 'occupied' WHERE id = :id")
                ->execute(['id' => $unitId]);

            $conn->commit();

            logActivity('created', 'lease', $leaseId, [
                'unit_id' => $unitId,
                'tenant_id' => $tenantId,
                'rent_amount' => $rentAmount,
                'unit_set_occupied' => true
            ]);

            jsonResponse(['success' => true, 'message' => 'Lease created successfully', 'id' => $leaseId]);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Failed to create lease: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // UPDATE LEASE
    // ============================
    case 'update':
        if (!hasPermission('leases', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Lease ID required'], 400);
        }

        $existing = $conn->prepare("SELECT * FROM leases WHERE id = :id");
        $existing->execute(['id' => $id]);
        $old = $existing->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'message' => 'Lease not found'], 404);
        }

        if (!canAccessLease($id)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        if (!isLeaseEditable($old)) {
            jsonResponse(['success' => false, 'message' => 'Only active or expired leases can be edited'], 422);
        }

        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');
        $rentAmount = (float)($_POST['rent_amount'] ?? 0);
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $depositAmount = (float)($_POST['deposit_amount'] ?? 0);
        $depositCurrency = trim($_POST['deposit_currency'] ?? DEFAULT_CURRENCY);
        $paymentFrequency = trim($_POST['payment_frequency'] ?? 'monthly');
        $paymentDueDay = (int)($_POST['payment_due_day'] ?? 1);
        $terms = trim($_POST['terms'] ?? '');
        $renewalAlertDays = (int)($_POST['renewal_alert_days'] ?? 30);

        $validFrequencies = paymentFrequencies();

        $errors = [];
        if ($startDate === '') $errors[] = 'Start date is required';
        if ($endDate === '') $errors[] = 'End date is required';
        if ($rentAmount <= 0) $errors[] = 'Rent amount must be greater than 0';
        if (!in_array($paymentFrequency, $validFrequencies)) $errors[] = 'Invalid payment frequency';
        if ($paymentDueDay < 1 || $paymentDueDay > 28) $errors[] = 'Payment due day must be between 1 and 28';
        if ($startDate && $endDate && strtotime($endDate) <= strtotime($startDate)) $errors[] = 'End date must be after start date';

        if ($errors) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors], 422);
        }

        try {
            $stmt = $conn->prepare("
                UPDATE leases SET
                    start_date = :start_date, end_date = :end_date,
                    rent_amount = :rent_amount, currency = :currency,
                    deposit_amount = :deposit_amount, deposit_currency = :deposit_currency,
                    payment_frequency = :payment_frequency, payment_due_day = :payment_due_day,
                    terms = :terms, renewal_alert_days = :renewal_alert_days
                WHERE id = :id
            ");
            $stmt->execute([
                'start_date' => date('Y-m-d', strtotime($startDate)),
                'end_date' => date('Y-m-d', strtotime($endDate)),
                'rent_amount' => $rentAmount,
                'currency' => $currency,
                'deposit_amount' => $depositAmount,
                'deposit_currency' => $depositCurrency,
                'payment_frequency' => $paymentFrequency,
                'payment_due_day' => $paymentDueDay,
                'terms' => $terms ?: null,
                'renewal_alert_days' => $renewalAlertDays,
                'id' => $id
            ]);

            logActivity('updated', 'lease', $id, [
                'rent_amount' => $old['rent_amount'] . ' -> ' . $rentAmount
            ]);

            jsonResponse(['success' => true, 'message' => 'Lease updated successfully']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to update lease: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // DELETE LEASE
    // ============================
    case 'delete':
        if (!hasPermission('leases', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Lease ID required'], 400);
        }

        $existing = $conn->prepare("SELECT * FROM leases WHERE id = :id");
        $existing->execute(['id' => $id]);
        $lease = $existing->fetch();
        if (!$lease) {
            jsonResponse(['success' => false, 'message' => 'Lease not found'], 404);
        }

        if (!canAccessLease($id)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        // Check if lease has payments
        $payCheck = $conn->prepare("SELECT COUNT(*) FROM payments WHERE lease_id = :id");
        $payCheck->execute(['id' => $id]);
        if ((int)$payCheck->fetchColumn() > 0) {
            jsonResponse(['success' => false, 'message' => 'Cannot delete: lease has payment records. Terminate instead.'], 422);
        }

        try {
            $conn->beginTransaction();

            // If lease was active, set unit back to vacant
            if ($lease['status'] === 'active') {
                // Check no other active lease on this unit
                $otherLease = $conn->prepare("SELECT COUNT(*) FROM leases WHERE unit_id = :uid AND status = 'active' AND id != :id");
                $otherLease->execute(['uid' => $lease['unit_id'], 'id' => $id]);
                if ((int)$otherLease->fetchColumn() === 0) {
                    $conn->prepare("UPDATE units SET status = 'vacant' WHERE id = :id")
                        ->execute(['id' => $lease['unit_id']]);
                }
            }

            $conn->prepare("DELETE FROM leases WHERE id = :id")->execute(['id' => $id]);
            $conn->commit();

            logActivity('deleted', 'lease', $id);

            jsonResponse(['success' => true, 'message' => 'Lease deleted successfully']);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Failed to delete lease: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // TERMINATE LEASE (8.5)
    // ============================
    case 'terminate':
        if (!hasPermission('leases', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        $terminatedReason = trim($_POST['terminated_reason'] ?? '');
        $terminatedDate = trim($_POST['terminated_date'] ?? '');

        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Lease ID required'], 400);
        }
        if ($terminatedDate === '') {
            jsonResponse(['success' => false, 'message' => 'Termination date is required'], 400);
        }

        $existing = $conn->prepare("SELECT * FROM leases WHERE id = :id");
        $existing->execute(['id' => $id]);
        $lease = $existing->fetch();
        if (!$lease) {
            jsonResponse(['success' => false, 'message' => 'Lease not found'], 404);
        }
        if (!canAccessLease($id)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }
        if ($lease['status'] !== 'active') {
            jsonResponse(['success' => false, 'message' => 'Only active leases can be terminated'], 422);
        }

        try {
            $conn->beginTransaction();

            $conn->prepare("
                UPDATE leases SET status = 'terminated', terminated_reason = :reason, terminated_date = :tdate WHERE id = :id
            ")->execute([
                'reason' => $terminatedReason ?: null,
                'tdate' => date('Y-m-d', strtotime($terminatedDate)),
                'id' => $id
            ]);

            // Set unit back to vacant (if no other active leases on same unit)
            $otherLease = $conn->prepare("SELECT COUNT(*) FROM leases WHERE unit_id = :uid AND status = 'active' AND id != :id");
            $otherLease->execute(['uid' => $lease['unit_id'], 'id' => $id]);
            if ((int)$otherLease->fetchColumn() === 0) {
                $conn->prepare("UPDATE units SET status = 'vacant' WHERE id = :id")
                    ->execute(['id' => $lease['unit_id']]);
            }

            $conn->commit();

            logActivity('updated', 'lease', $id, [
                'status_change' => 'active -> terminated',
                'terminated_reason' => $terminatedReason,
                'terminated_date' => $terminatedDate,
                'unit_set_vacant' => true
            ]);

            jsonResponse(['success' => true, 'message' => 'Lease terminated successfully']);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Failed to terminate lease: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // RENEW LEASE (8.4)
    // ============================
    case 'renew':
        if (!hasPermission('leases', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $oldLeaseId = (int)($_POST['old_lease_id'] ?? 0);
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');
        $rentAmount = (float)($_POST['rent_amount'] ?? 0);
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $depositAmount = (float)($_POST['deposit_amount'] ?? 0);
        $depositCurrency = trim($_POST['deposit_currency'] ?? DEFAULT_CURRENCY);
        $paymentFrequency = trim($_POST['payment_frequency'] ?? 'monthly');
        $paymentDueDay = (int)($_POST['payment_due_day'] ?? 1);
        $terms = trim($_POST['terms'] ?? '');
        $renewalAlertDays = (int)($_POST['renewal_alert_days'] ?? 30);

        if (!$oldLeaseId) {
            jsonResponse(['success' => false, 'message' => 'Original lease ID required'], 400);
        }

        // Get old lease
        $existing = $conn->prepare("SELECT * FROM leases WHERE id = :id");
        $existing->execute(['id' => $oldLeaseId]);
        $oldLease = $existing->fetch();
        if (!$oldLease) {
            jsonResponse(['success' => false, 'message' => 'Original lease not found'], 404);
        }
        if (!canAccessLease($oldLeaseId)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }
        if ($oldLease['status'] !== 'active') {
            jsonResponse(['success' => false, 'message' => 'Only active leases can be renewed'], 422);
        }

        $validFrequencies = paymentFrequencies();

        $errors = [];
        if ($startDate === '') $errors[] = 'Start date is required';
        if ($endDate === '') $errors[] = 'End date is required';
        if ($rentAmount <= 0) $errors[] = 'Rent amount must be greater than 0';
        if (!in_array($paymentFrequency, $validFrequencies)) $errors[] = 'Invalid payment frequency';
        if ($paymentDueDay < 1 || $paymentDueDay > 28) $errors[] = 'Payment due day must be between 1 and 28';
        if ($startDate && $endDate && strtotime($endDate) <= strtotime($startDate)) $errors[] = 'End date must be after start date';

        if ($errors) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors], 422);
        }

        try {
            $conn->beginTransaction();

            // Set old lease status to 'renewed'
            $conn->prepare("UPDATE leases SET status = 'renewed' WHERE id = :id")
                ->execute(['id' => $oldLeaseId]);

            // Create new lease linked to old one
            $stmt = $conn->prepare("
                INSERT INTO leases (unit_id, tenant_id, start_date, end_date, rent_amount, currency,
                    deposit_amount, deposit_currency, payment_frequency, payment_due_day,
                    terms, renewal_alert_days, status, previous_lease_id)
                VALUES (:unit_id, :tenant_id, :start_date, :end_date, :rent_amount, :currency,
                    :deposit_amount, :deposit_currency, :payment_frequency, :payment_due_day,
                    :terms, :renewal_alert_days, 'active', :previous_lease_id)
            ");
            $stmt->execute([
                'unit_id' => $oldLease['unit_id'],
                'tenant_id' => $oldLease['tenant_id'],
                'start_date' => date('Y-m-d', strtotime($startDate)),
                'end_date' => date('Y-m-d', strtotime($endDate)),
                'rent_amount' => $rentAmount,
                'currency' => $currency,
                'deposit_amount' => $depositAmount,
                'deposit_currency' => $depositCurrency,
                'payment_frequency' => $paymentFrequency,
                'payment_due_day' => $paymentDueDay,
                'terms' => $terms ?: null,
                'renewal_alert_days' => $renewalAlertDays,
                'previous_lease_id' => $oldLeaseId,
            ]);

            $newLeaseId = (int) $conn->lastInsertId();

            $conn->commit();

            logActivity('created', 'lease', $newLeaseId, [
                'renewal_of' => $oldLeaseId,
                'rent_amount' => $rentAmount,
            ]);

            jsonResponse(['success' => true, 'message' => 'Lease renewed successfully', 'id' => $newLeaseId]);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Failed to renew lease: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // GET VACANT/RESERVED UNITS (for lease creation dropdown)
    // ============================
    case 'available_units':
        if (!hasPermission('leases', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("
            SELECT u.id, u.unit_number, u.unit_label, u.rent_amount, u.currency, u.deposit_amount,
                   u.status, p.name AS property_name, p.id AS property_id
            FROM units u
            INNER JOIN properties p ON u.property_id = p.id
            WHERE u.status IN ('vacant', 'reserved')" . propertyScopeSql('u.property_id') . "
            ORDER BY p.name, u.unit_number
        ");
        $units = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $units]);
        break;

    // ============================
    // GET ACTIVE TENANTS (for lease creation dropdown)
    // ============================
    case 'active_tenants':
        if (!hasPermission('leases', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("
            SELECT id, first_name, last_name, phone, email
            FROM tenants
            WHERE status = 'active'" . tenantScopeSql('id') . "
            ORDER BY first_name, last_name
        ");
        $tenants = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $tenants]);
        break;

    // ============================
    // GET PROPERTIES LIST (for filter dropdown)
    // ============================
    case 'properties':
        if (!hasPermission('leases', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name");
        $properties = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $properties]);
        break;

    // ============================
    // LEASE TEMPLATES CRUD (8.2)
    // ============================
    case 'list_templates':
        if (!hasPermission('leases', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("SELECT id, name, is_default, created_at, updated_at FROM lease_templates ORDER BY is_default DESC, name");
        $templates = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $templates]);
        break;

    case 'get_template':
        if (!hasPermission('leases', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Template ID required'], 400);
        }

        $stmt = $conn->prepare("SELECT * FROM lease_templates WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $template = $stmt->fetch();

        if (!$template) {
            jsonResponse(['success' => false, 'message' => 'Template not found'], 404);
        }

        jsonResponse(['success' => true, 'data' => $template]);
        break;

    case 'create_template':
        if (!hasPermission('leases', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $name = trim($_POST['name'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $isDefault = (int)($_POST['is_default'] ?? 0);

        if ($name === '') {
            jsonResponse(['success' => false, 'message' => 'Template name is required'], 422);
        }
        if ($content === '') {
            jsonResponse(['success' => false, 'message' => 'Template content is required'], 422);
        }

        try {
            $conn->beginTransaction();

            // If setting as default, unset others
            if ($isDefault) {
                $conn->exec("UPDATE lease_templates SET is_default = 0");
            }

            $stmt = $conn->prepare("INSERT INTO lease_templates (name, content, is_default) VALUES (:name, :content, :is_default)");
            $stmt->execute(['name' => $name, 'content' => $content, 'is_default' => $isDefault]);
            $templateId = (int) $conn->lastInsertId();

            $conn->commit();

            logActivity('created', 'lease_template', $templateId, ['name' => $name]);

            jsonResponse(['success' => true, 'message' => 'Template created successfully', 'id' => $templateId]);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Failed to create template: ' . $e->getMessage()], 500);
        }
        break;

    case 'update_template':
        if (!hasPermission('leases', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $isDefault = (int)($_POST['is_default'] ?? 0);

        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Template ID required'], 400);
        }
        if ($name === '') {
            jsonResponse(['success' => false, 'message' => 'Template name is required'], 422);
        }
        if ($content === '') {
            jsonResponse(['success' => false, 'message' => 'Template content is required'], 422);
        }

        try {
            $conn->beginTransaction();

            if ($isDefault) {
                $conn->exec("UPDATE lease_templates SET is_default = 0");
            }

            $stmt = $conn->prepare("UPDATE lease_templates SET name = :name, content = :content, is_default = :is_default WHERE id = :id");
            $stmt->execute(['name' => $name, 'content' => $content, 'is_default' => $isDefault, 'id' => $id]);

            $conn->commit();

            logActivity('updated', 'lease_template', $id, ['name' => $name]);

            jsonResponse(['success' => true, 'message' => 'Template updated successfully']);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Failed to update template: ' . $e->getMessage()], 500);
        }
        break;

    case 'delete_template':
        if (!hasPermission('leases', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Template ID required'], 400);
        }

        $conn->prepare("DELETE FROM lease_templates WHERE id = :id")->execute(['id' => $id]);

        logActivity('deleted', 'lease_template', $id);

        jsonResponse(['success' => true, 'message' => 'Template deleted successfully']);
        break;

    // ============================
    // GET LEASE DATA FOR PRINT (8.3)
    // ============================
    case 'print_data':
        if (!hasPermission('leases', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $leaseId = (int)($_GET['lease_id'] ?? 0);
        $templateId = (int)($_GET['template_id'] ?? 0);

        if (!$leaseId) {
            jsonResponse(['success' => false, 'message' => 'Lease ID required'], 400);
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
            jsonResponse(['success' => false, 'message' => 'Lease not found'], 404);
        }

        if (!canAccessLease($leaseId)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        // Get template
        if ($templateId > 0) {
            $tplStmt = $conn->prepare("SELECT * FROM lease_templates WHERE id = :id");
            $tplStmt->execute(['id' => $templateId]);
        } else {
            $tplStmt = $conn->prepare("SELECT * FROM lease_templates WHERE is_default = 1 LIMIT 1");
            $tplStmt->execute();
        }
        $template = $tplStmt->fetch();

        jsonResponse(['success' => true, 'lease' => $lease, 'template' => $template ?: null]);
        break;

    // ============================
    // RENEWAL CHAIN (8.4) — get linked leases
    // ============================
    case 'renewal_chain':
        if (!hasPermission('leases', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $leaseId = (int)($_GET['lease_id'] ?? 0);
        if (!$leaseId) {
            jsonResponse(['success' => false, 'message' => 'Lease ID required'], 400);
        }

        if (!canAccessLease($leaseId)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        // Walk backward to find the root
        $currentId = $leaseId;
        $chain = [];
        $visited = [];
        while ($currentId && !in_array($currentId, $visited)) {
            $visited[] = $currentId;
            $stmt = $conn->prepare("SELECT id, previous_lease_id, start_date, end_date, rent_amount, currency, status FROM leases WHERE id = :id");
            $stmt->execute(['id' => $currentId]);
            $row = $stmt->fetch();
            if (!$row) break;
            array_unshift($chain, $row);
            $currentId = $row['previous_lease_id'];
        }

        // Walk forward from the original lease to find renewals
        $currentId = $leaseId;
        $forwardVisited = [$leaseId];
        while (true) {
            $stmt = $conn->prepare("SELECT id, previous_lease_id, start_date, end_date, rent_amount, currency, status FROM leases WHERE previous_lease_id = :id");
            $stmt->execute(['id' => $currentId]);
            $row = $stmt->fetch();
            if (!$row || in_array($row['id'], $forwardVisited)) break;
            $forwardVisited[] = $row['id'];
            $chain[] = $row;
            $currentId = $row['id'];
        }

        // De-duplicate by id
        $seen = [];
        $unique = [];
        foreach ($chain as $item) {
            if (!in_array($item['id'], $seen)) {
                $seen[] = $item['id'];
                $unique[] = $item;
            }
        }

        jsonResponse(['success' => true, 'data' => $unique]);
        break;

    // ============================
    // ACTIVITY LOG (for lease)
    // ============================
    case 'activity':
        if (!hasPermission('leases', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $leaseId = (int)($_GET['lease_id'] ?? 0);
        if (!$leaseId) {
            jsonResponse(['success' => false, 'message' => 'Lease ID required'], 400);
        }

        if (!canAccessLease($leaseId)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        $stmt = $conn->prepare("
            SELECT al.*, u.full_name AS user_name
            FROM activity_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.entity_type = 'lease'
              AND al.entity_id = :lid
            ORDER BY al.created_at DESC
            LIMIT 20
        ");
        $stmt->execute(['lid' => $leaseId]);
        $history = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $history]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
}
