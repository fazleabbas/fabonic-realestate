<?php
/**
 * FIS Real Estate — Payments API
 * Handles CRUD operations for rent payments, receipt generation, bulk recording, reversal.
 * Used by AJAX calls from the payments module pages.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/charges.php';

// Must be logged in
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // LIST PAYMENTS (with filters, search, pagination)
    // ============================
    case 'list':
        if (!hasPermission('payments', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $propertyId = (int)($_GET['property_id'] ?? 0);
        $method = trim($_GET['payment_method'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');
        $tenantId = (int)($_GET['tenant_id'] ?? 0);
        $leaseId = (int)($_GET['lease_id'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int)($_GET['per_page'] ?? RECORDS_PER_PAGE)));

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(CONCAT(t.first_name, ' ', t.last_name) LIKE :search OR u.unit_number LIKE :search2 OR p.name LIKE :search3 OR py.reference_number LIKE :search4)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
            $params['search4'] = "%{$search}%";
        }
        if ($status !== '') {
            $where[] = "py.status = :status";
            $params['status'] = $status;
        }
        if ($propertyId > 0) {
            $where[] = "u.property_id = :property_id";
            $params['property_id'] = $propertyId;
        }
        if ($method !== '') {
            $where[] = "py.payment_method = :method";
            $params['method'] = $method;
        }
        if ($dateFrom !== '') {
            $where[] = "py.payment_date >= :date_from";
            $params['date_from'] = date('Y-m-d', strtotime($dateFrom));
        }
        if ($dateTo !== '') {
            $where[] = "py.payment_date <= :date_to";
            $params['date_to'] = date('Y-m-d', strtotime($dateTo));
        }
        if ($tenantId > 0) {
            $where[] = "py.tenant_id = :tenant_id";
            $params['tenant_id'] = $tenantId;
        }
        if ($leaseId > 0) {
            $where[] = "py.lease_id = :lease_id";
            $params['lease_id'] = $leaseId;
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
            FROM payments py
            INNER JOIN units u ON py.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            INNER JOIN tenants t ON py.tenant_id = t.id
            {$whereSql}
        ");
        $countStmt->execute($params);
        $totalRecords = (int) $countStmt->fetchColumn();

        $pagination = paginate($totalRecords, $page, $perPage);

        // Fetch records
        $sql = "SELECT py.*, u.unit_number, p.name AS property_name, p.id AS property_id_val,
                CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
                r.receipt_number, r.id AS receipt_id
                FROM payments py
                INNER JOIN units u ON py.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                INNER JOIN tenants t ON py.tenant_id = t.id
                LEFT JOIN receipts r ON r.payment_id = py.id
                {$whereSql}
                ORDER BY py.payment_date DESC, py.created_at DESC
                LIMIT :offset, :limit";

        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":{$k}", $v);
        }
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $payments = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'data' => $payments,
            'pagination' => $pagination
        ]);
        break;

    // ============================
    // GET SINGLE PAYMENT
    // ============================
    case 'get':
        if (!hasPermission('payments', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Payment ID required'], 400);
        }

        $stmt = $conn->prepare("
            SELECT py.*, u.unit_number, u.unit_label, p.name AS property_name, p.id AS property_id_val,
            CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone, t.email AS tenant_email,
            t.id AS tenant_id_val,
            l.start_date AS lease_start, l.end_date AS lease_end, l.rent_amount AS lease_rent,
            l.payment_frequency AS lease_frequency,
            rec.full_name AS recorded_by_name
            FROM payments py
            INNER JOIN units u ON py.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            INNER JOIN tenants t ON py.tenant_id = t.id
            LEFT JOIN leases l ON py.lease_id = l.id
            LEFT JOIN users rec ON py.recorded_by = rec.id
            WHERE py.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $payment = $stmt->fetch();

        if (!$payment) {
            jsonResponse(['success' => false, 'message' => 'Payment not found'], 404);
        }
        if (!canAccessUnit($payment['unit_id'])) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        // Get receipt if exists
        $rcptStmt = $conn->prepare("SELECT * FROM receipts WHERE payment_id = :pid");
        $rcptStmt->execute(['pid' => $id]);
        $receipt = $rcptStmt->fetch();

        jsonResponse(['success' => true, 'data' => $payment, 'receipt' => $receipt]);
        break;

    // ============================
    // CREATE PAYMENT (9.1)
    // ============================
    case 'create':
        if (!hasPermission('payments', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $leaseId = (int)($_POST['lease_id'] ?? 0);
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $paymentDate = trim($_POST['payment_date'] ?? '');
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        $periodFrom = trim($_POST['period_from'] ?? '');
        $periodTo = trim($_POST['period_to'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $generateReceipt = (int)($_POST['generate_receipt'] ?? 1);

        $validMethods = ['cash', 'bank_transfer', 'mobile_money', 'cheque', 'gateway'];

        // Validate
        $errors = [];
        if (!$leaseId) $errors[] = 'Lease is required';
        if ($amount <= 0) $errors[] = 'Amount must be greater than 0';
        if ($paymentDate === '') $errors[] = 'Payment date is required';
        if (!in_array($paymentMethod, $validMethods)) $errors[] = 'Invalid payment method';

        // Look up lease to get tenant_id and unit_id
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
            }
        }

        if ($errors) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors], 422);
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO payments (lease_id, tenant_id, unit_id, amount, currency, payment_date,
                    payment_method, reference_number, period_from, period_to, notes, recorded_by, ledger_account_id, status)
                VALUES (:lease_id, :tenant_id, :unit_id, :amount, :currency, :payment_date,
                    :payment_method, :reference_number, :period_from, :period_to, :notes, :recorded_by, :ledger_account_id, 'recorded')
            ");
            $stmt->execute([
                'lease_id' => $leaseId ?: null,
                'tenant_id' => $tenantId,
                'unit_id' => $unitId,
                'amount' => $amount,
                'currency' => $currency,
                'payment_date' => date('Y-m-d', strtotime($paymentDate)),
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber ?: null,
                'period_from' => $periodFrom ? date('Y-m-d', strtotime($periodFrom)) : null,
                'period_to' => $periodTo ? date('Y-m-d', strtotime($periodTo)) : null,
                'notes' => $notes ?: null,
                'recorded_by' => getUserId(),
                'ledger_account_id' => (int)($_POST['ledger_account_id'] ?? 0) ?: null,
            ]);

            $paymentId = (int) $conn->lastInsertId();
            // Ledger (part 31): Dr Float/Bank  Cr Receivable, then allocate
            // against outstanding charges. Never allowed to break the payment.
            postPayment($paymentId);


            // Auto-generate receipt
            $receiptNumber = null;
            if ($generateReceipt) {
                $receiptNumber = generateReceiptNumber();
                $conn->prepare("
                    INSERT INTO receipts (payment_id, receipt_number) VALUES (:pid, :rnum)
                ")->execute(['pid' => $paymentId, 'rnum' => $receiptNumber]);
            }

            $conn->commit();

            logActivity('created', 'payment', $paymentId, [
                'amount' => $amount,
                'currency' => $currency,
                'method' => $paymentMethod,
                'lease_id' => $leaseId,
                'tenant_id' => $tenantId,
                'receipt_number' => $receiptNumber
            ]);

            // Auto-send receipt via WhatsApp/Email (Part 17)
            if ($receiptNumber && hasTier('professional') && getSetting('receipt_auto_send', '0') === '1' && getSetting('reminders_enabled', '0') === '1') {
                try {
                    $tenant = $conn->prepare("SELECT first_name, last_name, phone, email FROM tenants WHERE id = ?");
                    $tenant->execute([$tenantId]);
                    $tenantData = $tenant->fetch();

                    if ($tenantData) {
                        $tenantName = trim($tenantData['first_name'] . ' ' . $tenantData['last_name']);
                        $companyName = getSetting('company_name', 'Property Management');

                        // Get unit/property info
                        $unitInfo = '';
                        if ($unitId) {
                            $uStmt = $conn->prepare("SELECT u.unit_number, p.name AS property_name FROM units u INNER JOIN properties p ON u.property_id = p.id WHERE u.id = ?");
                            $uStmt->execute([$unitId]);
                            $uData = $uStmt->fetch();
                            if ($uData) $unitInfo = $uData['property_name'] . ' — ' . $uData['unit_number'];
                        }

                        $receiptMsg = "Dear {$tenantName},\n\n"
                            . "Payment of {$currency} " . number_format($amount, 0) . " has been received and recorded.\n"
                            . "Receipt #: {$receiptNumber}\n"
                            . "Date: " . formatDate($paymentDate) . "\n"
                            . "Method: " . ucfirst(str_replace('_', ' ', $paymentMethod)) . "\n";
                        if ($unitInfo) $receiptMsg .= "Unit: {$unitInfo}\n";
                        $receiptMsg .= "\nThank you for your payment.\n— {$companyName}";

                        // Get receipt ID
                        $rcptStmt = $conn->prepare("SELECT id FROM receipts WHERE payment_id = ? LIMIT 1");
                        $rcptStmt->execute([$paymentId]);
                        $receiptId = (int)$rcptStmt->fetchColumn();

                        // Queue WhatsApp message
                        if (getSetting('reminder_wa_enabled', '1') === '1' && !empty($tenantData['phone'])) {
                            $conn->prepare("
                                INSERT INTO receipt_messages (payment_id, receipt_id, tenant_id, channel, recipient_phone, message_body, status)
                                VALUES (?, ?, ?, 'whatsapp', ?, ?, 'pending')
                            ")->execute([$paymentId, $receiptId ?: null, $tenantId, $tenantData['phone'], $receiptMsg]);
                        }

                        // Queue Email message
                        if (getSetting('reminder_email_enabled', '1') === '1' && !empty($tenantData['email'])) {
                            $conn->prepare("
                                INSERT INTO receipt_messages (payment_id, receipt_id, tenant_id, channel, recipient_email, message_body, status)
                                VALUES (?, ?, ?, 'email', ?, ?, 'pending')
                            ")->execute([$paymentId, $receiptId ?: null, $tenantId, $tenantData['email'], $receiptMsg]);
                        }
                    }
                } catch (Exception $e) {
                    // Don't fail payment if receipt queuing fails — cron will pick it up
                    error_log('Receipt auto-send queue error: ' . $e->getMessage());
                }
            }

            jsonResponse(['success' => true, 'message' => 'Payment recorded successfully', 'id' => $paymentId, 'receipt_number' => $receiptNumber]);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Failed to record payment: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // UPDATE PAYMENT
    // ============================
    case 'update':
        if (!hasPermission('payments', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Payment ID required'], 400);
        }

        $existing = $conn->prepare("SELECT * FROM payments WHERE id = :id");
        $existing->execute(['id' => $id]);
        $old = $existing->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'message' => 'Payment not found'], 404);
        }
        if (!canAccessUnit($old['unit_id'])) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }
        if ($old['status'] === 'reversed') {
            jsonResponse(['success' => false, 'message' => 'Reversed payments cannot be edited'], 422);
        }

        $amount = (float)($_POST['amount'] ?? 0);
        $paymentDate = trim($_POST['payment_date'] ?? '');
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        $periodFrom = trim($_POST['period_from'] ?? '');
        $periodTo = trim($_POST['period_to'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $validMethods = ['cash', 'bank_transfer', 'mobile_money', 'cheque', 'gateway'];

        $errors = [];
        if ($amount <= 0) $errors[] = 'Amount must be greater than 0';
        if ($paymentDate === '') $errors[] = 'Payment date is required';
        if (!in_array($paymentMethod, $validMethods)) $errors[] = 'Invalid payment method';

        if ($errors) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors], 422);
        }

        try {
            $stmt = $conn->prepare("
                UPDATE payments SET
                    amount = :amount, payment_date = :payment_date,
                    payment_method = :payment_method, reference_number = :reference_number,
                    period_from = :period_from, period_to = :period_to, notes = :notes
                WHERE id = :id
            ");
            $stmt->execute([
                'amount' => $amount,
                'payment_date' => date('Y-m-d', strtotime($paymentDate)),
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber ?: null,
                'period_from' => $periodFrom ? date('Y-m-d', strtotime($periodFrom)) : null,
                'period_to' => $periodTo ? date('Y-m-d', strtotime($periodTo)) : null,
                'notes' => $notes ?: null,
                'id' => $id,
            ]);

            logActivity('updated', 'payment', $id, [
                'amount' => $old['amount'] . ' -> ' . $amount
            ]);

            jsonResponse(['success' => true, 'message' => 'Payment updated successfully']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to update payment: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // REVERSE PAYMENT
    // ============================
    case 'reverse':
        if (!hasPermission('payments', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Payment ID required'], 400);
        }

        $existing = $conn->prepare("SELECT * FROM payments WHERE id = :id");
        $existing->execute(['id' => $id]);
        $payment = $existing->fetch();
        if (!$payment) {
            jsonResponse(['success' => false, 'message' => 'Payment not found'], 404);
        }
        if (!canAccessUnit($payment['unit_id'])) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }
        if ($payment['status'] === 'reversed') {
            jsonResponse(['success' => false, 'message' => 'Payment already reversed'], 422);
        }

        try {
            $conn->prepare("UPDATE payments SET status = 'reversed', notes = CONCAT(COALESCE(notes,''), :reason) WHERE id = :id")
                ->execute([
                    'reason' => ($payment['notes'] ? "\n" : '') . '[REVERSED] ' . ($reason ?: 'No reason given'),
                    'id' => $id
                ]);

            // Ledger (part 31): mirror the original entry and put the money back
            // on the charges it settled, so they become outstanding again.
            reversePaymentPosting($id);

            logActivity('updated', 'payment', $id, [
                'status_change' => 'recorded -> reversed',
                'reason' => $reason,
                'amount' => $payment['amount']
            ]);

            jsonResponse(['success' => true, 'message' => 'Payment reversed successfully']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to reverse payment: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // DELETE PAYMENT
    // ============================
    case 'delete':
        if (!hasPermission('payments', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Payment ID required'], 400);
        }

        $existing = $conn->prepare("SELECT * FROM payments WHERE id = :id");
        $existing->execute(['id' => $id]);
        $payment = $existing->fetch();
        if (!$payment) {
            jsonResponse(['success' => false, 'message' => 'Payment not found'], 404);
        }
        if (!canAccessUnit($payment['unit_id'])) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        try {
            $conn->beginTransaction();
            // Delete associated receipt first (cascade should handle, but explicit)
            $conn->prepare("DELETE FROM receipts WHERE payment_id = :id")->execute(['id' => $id]);
            $conn->prepare("DELETE FROM payments WHERE id = :id")->execute(['id' => $id]);
            $conn->commit();

            logActivity('deleted', 'payment', $id, [
                'amount' => $payment['amount'],
                'tenant_id' => $payment['tenant_id']
            ]);

            jsonResponse(['success' => true, 'message' => 'Payment deleted successfully']);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Failed to delete payment: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // BULK CREATE PAYMENTS (9.5)
    // ============================
    case 'bulk_create':
        if (!hasPermission('payments', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $paymentsData = json_decode($_POST['payments_json'] ?? '[]', true);
        if (empty($paymentsData) || !is_array($paymentsData)) {
            jsonResponse(['success' => false, 'message' => 'No payments data provided'], 422);
        }

        $validMethods = ['cash', 'bank_transfer', 'mobile_money', 'cheque', 'gateway'];
        $created = 0;
        $failed = 0;
        $errors = [];

        try {
            $conn->beginTransaction();

            foreach ($paymentsData as $idx => $pd) {
                $leaseId = (int)($pd['lease_id'] ?? 0);
                $amount = (float)($pd['amount'] ?? 0);
                $paymentDate = trim($pd['payment_date'] ?? '');
                $paymentMethod = trim($pd['payment_method'] ?? 'cash');
                $referenceNumber = trim($pd['reference_number'] ?? '');
                $periodFrom = trim($pd['period_from'] ?? '');
                $periodTo = trim($pd['period_to'] ?? '');

                if (!$leaseId || $amount <= 0 || $paymentDate === '' || !in_array($paymentMethod, $validMethods)) {
                    $failed++;
                    $errors[] = "Row " . ($idx + 1) . ": Invalid data";
                    continue;
                }

                // Get lease info
                $leaseCheck = $conn->prepare("SELECT tenant_id, unit_id, currency FROM leases WHERE id = :id");
                $leaseCheck->execute(['id' => $leaseId]);
                $leaseData = $leaseCheck->fetch();
                if (!$leaseData) {
                    $failed++;
                    $errors[] = "Row " . ($idx + 1) . ": Lease not found";
                    continue;
                }
                if (!canAccessUnit($leaseData['unit_id'])) {
                    $failed++;
                    $errors[] = "Row " . ($idx + 1) . ": No access to this record";
                    continue;
                }

                $stmt = $conn->prepare("
                    INSERT INTO payments (lease_id, tenant_id, unit_id, amount, currency, payment_date,
                        payment_method, reference_number, period_from, period_to, recorded_by, ledger_account_id, status)
                    VALUES (:lease_id, :tenant_id, :unit_id, :amount, :currency, :payment_date,
                        :payment_method, :reference_number, :period_from, :period_to, :recorded_by, :ledger_account_id, 'recorded')
                ");
                $stmt->execute([
                    'lease_id' => $leaseId,
                    'tenant_id' => $leaseData['tenant_id'],
                    'unit_id' => $leaseData['unit_id'],
                    'amount' => $amount,
                    'currency' => $leaseData['currency'],
                    'payment_date' => date('Y-m-d', strtotime($paymentDate)),
                    'payment_method' => $paymentMethod,
                    'reference_number' => $referenceNumber ?: null,
                    'period_from' => $periodFrom ? date('Y-m-d', strtotime($periodFrom)) : null,
                    'period_to' => $periodTo ? date('Y-m-d', strtotime($periodTo)) : null,
                    'recorded_by' => getUserId(),
                    // One destination for the whole batch — you bank a run of
                    // receipts into one place, not row by row.
                    'ledger_account_id' => (int)($_POST['ledger_account_id'] ?? 0) ?: null,
                ]);

                $paymentId = (int) $conn->lastInsertId();
                // Ledger (part 31): Dr Float/Bank  Cr Receivable, then allocate.
                postPayment($paymentId);


                // Generate receipt
                $receiptNumber = generateReceiptNumber();
                $conn->prepare("INSERT INTO receipts (payment_id, receipt_number) VALUES (:pid, :rnum)")
                    ->execute(['pid' => $paymentId, 'rnum' => $receiptNumber]);

                $created++;
            }

            $conn->commit();

            logActivity('created', 'payment', null, [
                'bulk' => true,
                'created' => $created,
                'failed' => $failed
            ]);

            jsonResponse([
                'success' => true,
                'message' => "{$created} payment(s) recorded successfully" . ($failed ? ", {$failed} failed" : ''),
                'created' => $created,
                'failed' => $failed,
                'errors' => $errors
            ]);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Bulk payment failed: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // GENERATE RECEIPT (9.3)
    // ============================
    case 'generate_receipt':
        if (!hasPermission('payments', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $paymentId = (int)($_GET['payment_id'] ?? (int)($_POST['payment_id'] ?? 0));
        if (!$paymentId) {
            jsonResponse(['success' => false, 'message' => 'Payment ID required'], 400);
        }

        // Resolve payment's unit for access scoping
        $puStmt = $conn->prepare("SELECT unit_id FROM payments WHERE id = :id");
        $puStmt->execute(['id' => $paymentId]);
        $payUnitId = $puStmt->fetchColumn();
        if ($payUnitId === false) {
            jsonResponse(['success' => false, 'message' => 'Payment not found'], 404);
        }
        if (!canAccessUnit($payUnitId)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        // Check if receipt already exists
        $rcptStmt = $conn->prepare("SELECT * FROM receipts WHERE payment_id = :pid");
        $rcptStmt->execute(['pid' => $paymentId]);
        $receipt = $rcptStmt->fetch();

        if (!$receipt) {
            // Generate new receipt
            checkCSRF();
            $receiptNumber = generateReceiptNumber();
            $conn->prepare("INSERT INTO receipts (payment_id, receipt_number) VALUES (:pid, :rnum)")
                ->execute(['pid' => $paymentId, 'rnum' => $receiptNumber]);

            $receipt = [
                'payment_id' => $paymentId,
                'receipt_number' => $receiptNumber,
                'id' => (int)$conn->lastInsertId()
            ];

            logActivity('created', 'receipt', $receipt['id'], ['payment_id' => $paymentId, 'receipt_number' => $receiptNumber]);
        }

        jsonResponse(['success' => true, 'data' => $receipt]);
        break;

    // ============================
    // GET RECEIPT DATA FOR PRINT (9.3)
    // ============================
    case 'receipt_data':
        if (!hasPermission('payments', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $paymentId = (int)($_GET['payment_id'] ?? 0);
        if (!$paymentId) {
            jsonResponse(['success' => false, 'message' => 'Payment ID required'], 400);
        }

        $stmt = $conn->prepare("
            SELECT py.*, u.unit_number, u.unit_label,
                   p.name AS property_name, p.address AS property_address, p.city AS property_city,
                   CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                   t.phone AS tenant_phone, t.email AS tenant_email,
                   l.rent_amount AS lease_rent, l.payment_frequency AS lease_frequency,
                   r.receipt_number, r.sent_via, r.sent_at
            FROM payments py
            INNER JOIN units u ON py.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            INNER JOIN tenants t ON py.tenant_id = t.id
            LEFT JOIN leases l ON py.lease_id = l.id
            LEFT JOIN receipts r ON r.payment_id = py.id
            WHERE py.id = :id
        ");
        $stmt->execute(['id' => $paymentId]);
        $data = $stmt->fetch();

        if (!$data) {
            jsonResponse(['success' => false, 'message' => 'Payment not found'], 404);
        }
        if (!canAccessUnit($data['unit_id'])) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        // Company info from settings
        $company = [
            'name' => getSetting('company_name', APP_NAME),
            'phone' => getSetting('company_phone', ''),
            'email' => getSetting('company_email', ''),
            'address' => getSetting('company_address', ''),
            'logo' => getSetting('company_logo', ''),
        ];

        jsonResponse(['success' => true, 'data' => $data, 'company' => $company]);
        break;

    // ============================
    // GET ACTIVE LEASES (for payment creation dropdown)
    // ============================
    case 'active_leases':
        if (!hasPermission('payments', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("
            SELECT l.id, l.rent_amount, l.currency, l.payment_frequency, l.payment_due_day,
                   l.start_date, l.end_date,
                   u.unit_number, u.id AS unit_id, p.name AS property_name, p.id AS property_id,
                   CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
                   t.id AS tenant_id
            FROM leases l
            INNER JOIN units u ON l.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            INNER JOIN tenants t ON l.tenant_id = t.id
            WHERE l.status = 'active'" . propertyScopeSql('u.property_id') . "
            ORDER BY t.first_name, t.last_name, u.unit_number
        ");
        $leases = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $leases]);
        break;

    // ============================
    // GET PROPERTIES LIST (for filter dropdown)
    // ============================
    case 'properties':
        if (!hasPermission('payments', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name");
        $properties = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $properties]);
        break;

    // ============================
    // PERIOD CALCULATION (9.2)
    // Auto-calculate which months a payment covers
    // ============================
    case 'calculate_period':
        if (!hasPermission('payments', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $leaseId = (int)($_GET['lease_id'] ?? 0);
        $amount = (float)($_GET['amount'] ?? 0);

        if (!$leaseId) {
            jsonResponse(['success' => false, 'message' => 'Lease ID required'], 400);
        }

        $leaseStmt = $conn->prepare("SELECT * FROM leases WHERE id = :id");
        $leaseStmt->execute(['id' => $leaseId]);
        $lease = $leaseStmt->fetch();

        if (!$lease) {
            jsonResponse(['success' => false, 'message' => 'Lease not found'], 404);
        }

        $rentAmount = (float)$lease['rent_amount'];
        if ($rentAmount <= 0) {
            jsonResponse(['success' => true, 'periods' => [], 'message' => 'Rent amount is 0']);
        }

        // Get already paid periods
        $paidStmt = $conn->prepare("
            SELECT period_from, period_to FROM payments
            WHERE lease_id = :lid AND status = 'recorded' AND period_from IS NOT NULL
            ORDER BY period_from
        ");
        $paidStmt->execute(['lid' => $leaseId]);
        $paidPeriods = $paidStmt->fetchAll();

        // Determine frequency multiplier for period calculation
        $freqMonths = paymentFrequencyMonths($lease['payment_frequency']);

        // Calculate how many periods this payment covers
        $periodsCount = max(1, floor($amount / $rentAmount));
        $remainder = $amount - ($periodsCount * $rentAmount);

        // Find next unpaid period
        $startDate = new DateTime($lease['start_date']);
        $endDate = new DateTime($lease['end_date']);

        // Build all expected periods
        $allPeriods = [];
        $current = clone $startDate;
        while ($current < $endDate) {
            $periodEnd = clone $current;
            $periodEnd->modify('+' . $freqMonths . ' months');
            $periodEnd->modify('-1 day');
            if ($periodEnd > $endDate) $periodEnd = clone $endDate;

            $allPeriods[] = [
                'from' => $current->format('Y-m-d'),
                'to' => $periodEnd->format('Y-m-d')
            ];

            $current->modify('+' . $freqMonths . ' months');
        }

        // Filter out already paid periods
        $unpaid = [];
        foreach ($allPeriods as $ap) {
            $isPaid = false;
            foreach ($paidPeriods as $pp) {
                if ($pp['period_from'] === $ap['from']) {
                    $isPaid = true;
                    break;
                }
            }
            if (!$isPaid) $unpaid[] = $ap;
        }

        // Suggest periods for the amount
        $suggested = array_slice($unpaid, 0, $periodsCount);

        jsonResponse([
            'success' => true,
            'rent_amount' => $rentAmount,
            'frequency' => $lease['payment_frequency'],
            'periods_covered' => count($suggested),
            'remainder' => round($remainder, 2),
            'suggested_periods' => $suggested,
            'all_unpaid' => $unpaid
        ]);
        break;

    // ============================
    // DASHBOARD STATS
    // ============================
    case 'stats':
        if (!hasPermission('payments', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');

        // Total collected this month
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='recorded' AND payment_date BETWEEN :from AND :to");
        $stmt->execute(['from' => $monthStart, 'to' => $monthEnd]);
        $monthlyCollection = (float)$stmt->fetchColumn();

        // Total all time
        $totalAll = (float)$conn->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='recorded'")->fetchColumn();

        // Total payments count
        $totalCount = (int)$conn->query("SELECT COUNT(*) FROM payments WHERE status='recorded'")->fetchColumn();

        // Recent payments
        $recent = $conn->query("
            SELECT py.id, py.amount, py.currency, py.payment_date, py.payment_method,
                   CONCAT(t.first_name,' ',t.last_name) AS tenant_name, u.unit_number
            FROM payments py
            INNER JOIN tenants t ON py.tenant_id = t.id
            INNER JOIN units u ON py.unit_id = u.id
            WHERE py.status = 'recorded'
            ORDER BY py.created_at DESC LIMIT 5
        ")->fetchAll();

        jsonResponse([
            'success' => true,
            'monthly_collection' => $monthlyCollection,
            'total_collection' => $totalAll,
            'total_count' => $totalCount,
            'recent' => $recent
        ]);
        break;

    // ============================
    // ACTIVITY LOG (for payment)
    // ============================
    case 'activity':
        if (!hasPermission('payments', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $paymentId = (int)($_GET['payment_id'] ?? 0);
        if (!$paymentId) {
            jsonResponse(['success' => false, 'message' => 'Payment ID required'], 400);
        }

        $stmt = $conn->prepare("
            SELECT al.*, u.full_name AS user_name
            FROM activity_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.entity_type = 'payment'
              AND al.entity_id = :pid
            ORDER BY al.created_at DESC
            LIMIT 20
        ");
        $stmt->execute(['pid' => $paymentId]);
        $history = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $history]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
}

// ============================
// HELPER: Generate receipt number
// ============================
function generateReceiptNumber() {
    global $conn;

    $prefix = getSetting('receipt_prefix', 'RCT-');
    $nextNum = (int)getSetting('receipt_next_number', 1);

    $receiptNumber = $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

    // Increment the next number
    updateSetting('receipt_next_number', $nextNum + 1);

    return $receiptNumber;
}
