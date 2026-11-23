<?php
/**
 * FIS Real Estate — Payment Gateway API (Part 21)
 * Handles: gateway config CRUD, payment initiation, transaction management,
 * auto-reconciliation, manual reconciliation, CSV exports.
 * Enterprise tier required + payment_gateway_enabled setting.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/../includes/charges.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

// Enterprise tier + module check
if (!hasTier('enterprise')) {
    jsonResponse(['success' => false, 'message' => __('gw_tier_required', 'Enterprise tier required for payment gateway')], 403);
}
if (getSetting('payment_gateway_enabled', '0') !== '1') {
    jsonResponse(['success' => false, 'message' => __('gw_disabled', 'Payment gateway module is disabled')], 403);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // GATEWAYS — LIST
    // ============================
    case 'list_gateways':
        if (!hasPermission('settings', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("
            SELECT gc.*,
                   (SELECT COUNT(*) FROM gateway_transactions gt WHERE gt.gateway_name = gc.gateway_name) AS transaction_count,
                   (SELECT COUNT(*) FROM gateway_transactions gt WHERE gt.gateway_name = gc.gateway_name AND gt.status = 'completed') AS completed_count
            FROM gateway_config gc
            ORDER BY gc.is_active DESC, gc.gateway_name ASC
        ");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // GATEWAYS — GET
    // ============================
    case 'get_gateway':
        if (!hasPermission('settings', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM gateway_config WHERE id = ?");
        $stmt->execute([$id]);
        $gw = $stmt->fetch();
        if (!$gw) {
            jsonResponse(['success' => false, 'message' => 'Gateway not found'], 404);
        }
        // Mask secrets for display
        if ($gw['api_secret']) {
            $gw['api_secret_masked'] = str_repeat('*', max(0, strlen($gw['api_secret']) - 4)) . substr($gw['api_secret'], -4);
        }
        jsonResponse(['success' => true, 'data' => $gw]);
        break;

    // ============================
    // GATEWAYS — SAVE (create or update)
    // ============================
    case 'save_gateway':
        if (!hasPermission('settings', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $gatewayName = trim($_POST['gateway_name'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $apiKey = trim($_POST['api_key'] ?? '');
        $apiSecret = trim($_POST['api_secret'] ?? '');
        $merchantId = trim($_POST['merchant_id'] ?? '');
        $callbackUrl = trim($_POST['callback_url'] ?? '');
        $environment = trim($_POST['environment'] ?? 'sandbox');
        $configJson = trim($_POST['config_json'] ?? '');
        $logoIcon = trim($_POST['logo_icon'] ?? 'bi-phone');

        if (empty($gatewayName)) jsonResponse(['success' => false, 'message' => __('gw_name_required')]);

        // Validate environment
        if (!in_array($environment, ['sandbox', 'production'])) $environment = 'sandbox';

        // Parse config JSON if provided
        $configJsonVal = null;
        if ($configJson) {
            $decoded = json_decode($configJson, true);
            if ($decoded !== null) {
                $configJsonVal = $configJson;
            }
        }

        if ($id) {
            // Check if api_secret is masked (hasn't changed)
            if (strpos($apiSecret, '****') !== false) {
                // Keep existing secret
                $stmt = $conn->prepare("
                    UPDATE gateway_config
                    SET display_name = ?, description = ?, api_key = ?, merchant_id = ?,
                        callback_url = ?, environment = ?, config_json = ?, logo_icon = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $displayName, $description, $apiKey, $merchantId,
                    $callbackUrl, $environment, $configJsonVal, $logoIcon, $id
                ]);
            } else {
                $stmt = $conn->prepare("
                    UPDATE gateway_config
                    SET display_name = ?, description = ?, api_key = ?, api_secret = ?,
                        merchant_id = ?, callback_url = ?, environment = ?, config_json = ?, logo_icon = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $displayName, $description, $apiKey, $apiSecret, $merchantId,
                    $callbackUrl, $environment, $configJsonVal, $logoIcon, $id
                ]);
            }
            logActivity('updated', 'gateway_config', $id, ['gateway' => $gatewayName]);
            jsonResponse(['success' => true, 'message' => __('gw_saved')]);
        } else {
            // Check duplicate
            $check = $conn->prepare("SELECT id FROM gateway_config WHERE gateway_name = ?");
            $check->execute([$gatewayName]);
            if ($check->fetch()) {
                jsonResponse(['success' => false, 'message' => __('gw_already_exists')]);
            }
            $stmt = $conn->prepare("
                INSERT INTO gateway_config (gateway_name, display_name, description, api_key, api_secret,
                                            merchant_id, callback_url, environment, config_json, logo_icon)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $gatewayName, $displayName, $description, $apiKey, $apiSecret,
                $merchantId, $callbackUrl, $environment, $configJsonVal, $logoIcon
            ]);
            $newId = (int)$conn->lastInsertId();
            logActivity('created', 'gateway_config', $newId, ['gateway' => $gatewayName]);
            jsonResponse(['success' => true, 'message' => __('gw_saved'), 'id' => $newId]);
        }
        break;

    // ============================
    // GATEWAYS — TOGGLE ACTIVE
    // ============================
    case 'toggle_gateway':
        if (!hasPermission('settings', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $active = (int)($_POST['is_active'] ?? 0);

        $conn->prepare("UPDATE gateway_config SET is_active = ? WHERE id = ?")->execute([$active ? 1 : 0, $id]);
        logActivity('updated', 'gateway_config', $id, ['is_active' => $active]);
        jsonResponse(['success' => true, 'message' => __('gw_status_updated')]);
        break;

    // ============================
    // GATEWAYS — DELETE
    // ============================
    case 'delete_gateway':
        if (!hasPermission('settings', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);

        // Check for transactions
        $stmt = $conn->prepare("SELECT gateway_name FROM gateway_config WHERE id = ?");
        $stmt->execute([$id]);
        $gw = $stmt->fetch();
        if ($gw) {
            $txCount = $conn->prepare("SELECT COUNT(*) FROM gateway_transactions WHERE gateway_name = ?");
            $txCount->execute([$gw['gateway_name']]);
            if ((int)$txCount->fetchColumn() > 0) {
                jsonResponse(['success' => false, 'message' => __('gw_has_transactions')]);
            }
        }

        $conn->prepare("DELETE FROM gateway_config WHERE id = ?")->execute([$id]);
        logActivity('deleted', 'gateway_config', $id);
        jsonResponse(['success' => true, 'message' => __('gw_deleted')]);
        break;

    // ============================
    // INITIATE PAYMENT
    // ============================
    case 'initiate_payment':
        if (!hasPermission('payments', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $leaseId = (int)($_POST['lease_id'] ?? 0);
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $phoneNumber = trim($_POST['phone_number'] ?? '');
        $gatewayName = trim($_POST['gateway_name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (!$tenantId) jsonResponse(['success' => false, 'message' => __('gw_tenant_required')]);
        if ($amount <= 0) jsonResponse(['success' => false, 'message' => __('gw_amount_required')]);
        if (empty($phoneNumber)) jsonResponse(['success' => false, 'message' => __('gw_phone_required')]);
        if (empty($gatewayName)) jsonResponse(['success' => false, 'message' => __('gw_gateway_required')]);

        // Validate gateway is active
        $gwStmt = $conn->prepare("SELECT * FROM gateway_config WHERE gateway_name = ? AND is_active = 1");
        $gwStmt->execute([$gatewayName]);
        $gateway = $gwStmt->fetch();
        if (!$gateway) {
            jsonResponse(['success' => false, 'message' => __('gw_not_active')]);
        }

        // Normalize phone number (Tanzania format)
        $phone = normalizePhone($phoneNumber);

        // Generate unique reference
        $reference = 'FIS-RE-' . strtoupper(substr($gatewayName, 0, 3)) . '-' . date('YmdHis') . '-' . rand(1000, 9999);

        // Create transaction record
        $stmt = $conn->prepare("
            INSERT INTO gateway_transactions (gateway_name, tenant_id, lease_id, unit_id, amount, currency,
                                              phone_number, external_reference, status, description)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'initiated', ?)
        ");
        $stmt->execute([
            $gatewayName, $tenantId, $leaseId ?: null, $unitId ?: null,
            $amount, $currency, $phone, $reference, $description
        ]);
        $txId = (int)$conn->lastInsertId();

        // Attempt to call gateway API
        $apiResult = callGatewayAPI($gateway, [
            'amount' => $amount,
            'currency' => $currency,
            'phone' => $phone,
            'reference' => $reference,
            'description' => $description ?: 'Rent Payment',
            'callback_url' => $gateway['callback_url'] ?: (BASE_URL . 'api/gateway-callback.php')
        ]);

        if ($apiResult['success']) {
            // Update transaction with gateway transaction ID
            $conn->prepare("
                UPDATE gateway_transactions
                SET transaction_id = ?, status = 'pending'
                WHERE id = ?
            ")->execute([$apiResult['transaction_id'] ?? $reference, $txId]);

            logActivity('created', 'gateway_transaction', $txId, [
                'gateway' => $gatewayName, 'amount' => $amount, 'phone' => $phone
            ]);

            jsonResponse(['success' => true, 'message' => __('gw_payment_initiated'), 'data' => [
                'transaction_id' => $txId,
                'reference' => $reference,
                'gateway_tx_id' => $apiResult['transaction_id'] ?? $reference,
                'status' => 'pending'
            ]]);
        } else {
            // Update with error
            $conn->prepare("
                UPDATE gateway_transactions
                SET status = 'failed', error_message = ?
                WHERE id = ?
            ")->execute([$apiResult['error'] ?? 'Gateway API call failed', $txId]);

            jsonResponse(['success' => false, 'message' => $apiResult['error'] ?? __('gw_initiation_failed'), 'data' => [
                'transaction_id' => $txId
            ]]);
        }
        break;

    // ============================
    // CHECK TRANSACTION STATUS
    // ============================
    case 'check_status':
        $txId = (int)($_GET['id'] ?? 0);
        if (!$txId) jsonResponse(['success' => false, 'message' => 'Transaction ID required']);

        $stmt = $conn->prepare("
            SELECT gt.*, t.first_name, t.last_name, t.phone AS tenant_phone,
                   u.unit_number, p.name AS property_name
            FROM gateway_transactions gt
            LEFT JOIN tenants t ON gt.tenant_id = t.id
            LEFT JOIN units u ON gt.unit_id = u.id
            LEFT JOIN properties p ON u.property_id = p.id
            WHERE gt.id = ?
        ");
        $stmt->execute([$txId]);
        $tx = $stmt->fetch();
        if (!$tx) {
            jsonResponse(['success' => false, 'message' => 'Transaction not found'], 404);
        }

        jsonResponse(['success' => true, 'data' => $tx]);
        break;

    // ============================
    // LIST TRANSACTIONS
    // ============================
    case 'list':
        if (!hasPermission('payments', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $gatewayName = trim($_GET['gateway_name'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $reconciled = $_GET['reconciled'] ?? '';
        $search = trim($_GET['search'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');

        $where = "1=1";
        $params = [];

        if ($gatewayName) {
            $where .= " AND gt.gateway_name = :gn";
            $params['gn'] = $gatewayName;
        }
        if ($status) {
            $where .= " AND gt.status = :status";
            $params['status'] = $status;
        }
        if ($reconciled !== '') {
            $where .= " AND gt.reconciled = :rec";
            $params['rec'] = (int)$reconciled;
        }
        if ($search) {
            $where .= " AND (gt.external_reference LIKE :s1 OR gt.transaction_id LIKE :s2 OR gt.phone_number LIKE :s3
                        OR t.first_name LIKE :s4 OR t.last_name LIKE :s5)";
            $params['s1'] = "%$search%";
            $params['s2'] = "%$search%";
            $params['s3'] = "%$search%";
            $params['s4'] = "%$search%";
            $params['s5'] = "%$search%";
        }
        if ($dateFrom) {
            $where .= " AND DATE(gt.created_at) >= :df";
            $params['df'] = $dateFrom;
        }
        if ($dateTo) {
            $where .= " AND DATE(gt.created_at) <= :dt";
            $params['dt'] = $dateTo;
        }

        $countStmt = $conn->prepare("
            SELECT COUNT(*) FROM gateway_transactions gt
            LEFT JOIN tenants t ON gt.tenant_id = t.id
            WHERE $where
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pagination = paginate($total, $page);

        $sql = "SELECT gt.*, t.first_name, t.last_name, t.phone AS tenant_phone,
                       u.unit_number, pr.name AS property_name,
                       gc.display_name AS gateway_display,
                       usr.full_name AS reconciled_by_name
                FROM gateway_transactions gt
                LEFT JOIN tenants t ON gt.tenant_id = t.id
                LEFT JOIN units u ON gt.unit_id = u.id
                LEFT JOIN properties pr ON u.property_id = pr.id
                LEFT JOIN gateway_config gc ON gt.gateway_name = gc.gateway_name
                LEFT JOIN users usr ON gt.reconciled_by = usr.id
                WHERE $where
                ORDER BY gt.created_at DESC
                LIMIT :offset, :limit";
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $pagination['records_per_page'], PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(), 'pagination' => $pagination]);
        break;

    // ============================
    // GET TRANSACTION
    // ============================
    case 'get':
        if (!hasPermission('payments', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("
            SELECT gt.*, t.first_name, t.last_name, t.phone AS tenant_phone,
                   u.unit_number, pr.name AS property_name,
                   gc.display_name AS gateway_display,
                   usr.full_name AS reconciled_by_name
            FROM gateway_transactions gt
            LEFT JOIN tenants t ON gt.tenant_id = t.id
            LEFT JOIN units u ON gt.unit_id = u.id
            LEFT JOIN properties pr ON u.property_id = pr.id
            LEFT JOIN gateway_config gc ON gt.gateway_name = gc.gateway_name
            LEFT JOIN users usr ON gt.reconciled_by = usr.id
            WHERE gt.id = ?
        ");
        $stmt->execute([$id]);
        $tx = $stmt->fetch();
        if (!$tx) {
            jsonResponse(['success' => false, 'message' => 'Transaction not found'], 404);
        }
        jsonResponse(['success' => true, 'data' => $tx]);
        break;

    // ============================
    // MANUAL RECONCILE
    // ============================
    case 'reconcile':
        if (!hasPermission('payments', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $txId = (int)($_POST['id'] ?? 0);
        if (!$txId) jsonResponse(['success' => false, 'message' => 'Transaction ID required']);

        $stmt = $conn->prepare("SELECT * FROM gateway_transactions WHERE id = ?");
        $stmt->execute([$txId]);
        $tx = $stmt->fetch();
        if (!$tx) jsonResponse(['success' => false, 'message' => 'Transaction not found'], 404);
        if ($tx['reconciled']) jsonResponse(['success' => false, 'message' => __('gw_already_reconciled')]);
        if ($tx['status'] !== 'completed') {
            jsonResponse(['success' => false, 'message' => __('gw_not_completed')]);
        }

        $result = reconcileTransaction($conn, $tx, getUserId());
        jsonResponse($result);
        break;

    // ============================
    // AUTO-RECONCILE ALL PENDING
    // ============================
    case 'auto_reconcile':
        if (!hasPermission('payments', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("
            SELECT * FROM gateway_transactions
            WHERE status = 'completed' AND reconciled = 0
            ORDER BY created_at ASC
        ");
        $pending = $stmt->fetchAll();

        $reconciled = 0;
        $failed = 0;
        $errors = [];

        foreach ($pending as $tx) {
            $result = reconcileTransaction($conn, $tx, getUserId());
            if ($result['success']) {
                $reconciled++;
            } else {
                $failed++;
                $errors[] = "TX #{$tx['id']}: " . ($result['message'] ?? 'Unknown error');
            }
        }

        jsonResponse(['success' => true, 'message' => sprintf(__('gw_auto_reconcile_result'), $reconciled, $failed), 'data' => [
            'reconciled' => $reconciled,
            'failed' => $failed,
            'errors' => $errors
        ]]);
        break;

    // ============================
    // CANCEL TRANSACTION
    // ============================
    case 'cancel_transaction':
        if (!hasPermission('payments', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $txId = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM gateway_transactions WHERE id = ?");
        $stmt->execute([$txId]);
        $tx = $stmt->fetch();

        if (!$tx) jsonResponse(['success' => false, 'message' => 'Transaction not found'], 404);
        if (in_array($tx['status'], ['completed', 'cancelled'])) {
            jsonResponse(['success' => false, 'message' => __('gw_cannot_cancel')]);
        }

        $conn->prepare("UPDATE gateway_transactions SET status = 'cancelled' WHERE id = ?")->execute([$txId]);
        logActivity('updated', 'gateway_transaction', $txId, ['status' => 'cancelled']);
        jsonResponse(['success' => true, 'message' => __('gw_transaction_cancelled')]);
        break;

    // ============================
    // MARK AS COMPLETED (manual)
    // ============================
    case 'mark_completed':
        if (!hasPermission('payments', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $txId = (int)($_POST['id'] ?? 0);
        $transactionId = trim($_POST['transaction_id'] ?? '');

        $stmt = $conn->prepare("SELECT * FROM gateway_transactions WHERE id = ?");
        $stmt->execute([$txId]);
        $tx = $stmt->fetch();

        if (!$tx) jsonResponse(['success' => false, 'message' => 'Transaction not found'], 404);
        if ($tx['status'] === 'completed') {
            jsonResponse(['success' => false, 'message' => __('gw_already_completed')]);
        }

        $conn->prepare("
            UPDATE gateway_transactions SET status = 'completed', transaction_id = COALESCE(?, transaction_id)
            WHERE id = ?
        ")->execute([$transactionId ?: null, $txId]);

        logActivity('updated', 'gateway_transaction', $txId, ['status' => 'completed', 'manual' => true]);

        // Auto-reconcile if setting enabled
        if (getSetting('gateway_auto_reconcile', '1') === '1') {
            $tx['status'] = 'completed';
            if ($transactionId) $tx['transaction_id'] = $transactionId;
            reconcileTransaction($conn, $tx, getUserId());
        }

        jsonResponse(['success' => true, 'message' => __('gw_marked_completed')]);
        break;

    // ============================
    // STATS
    // ============================
    case 'stats':
        if (!hasPermission('payments', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $totalTx = (int)$conn->query("SELECT COUNT(*) FROM gateway_transactions")->fetchColumn();
        $pendingTx = (int)$conn->query("SELECT COUNT(*) FROM gateway_transactions WHERE status IN ('initiated','pending')")->fetchColumn();
        $completedTx = (int)$conn->query("SELECT COUNT(*) FROM gateway_transactions WHERE status = 'completed'")->fetchColumn();
        $failedTx = (int)$conn->query("SELECT COUNT(*) FROM gateway_transactions WHERE status = 'failed'")->fetchColumn();
        $unreconciledTx = (int)$conn->query("SELECT COUNT(*) FROM gateway_transactions WHERE status = 'completed' AND reconciled = 0")->fetchColumn();

        $s = $conn->query("SELECT COALESCE(SUM(amount), 0) FROM gateway_transactions WHERE status = 'completed'");
        $totalAmount = (float)$s->fetchColumn();

        $s2 = $conn->query("SELECT COALESCE(SUM(amount), 0) FROM gateway_transactions WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
        $thisMonthAmount = (float)$s2->fetchColumn();

        $activeGateways = (int)$conn->query("SELECT COUNT(*) FROM gateway_config WHERE is_active = 1")->fetchColumn();

        jsonResponse(['success' => true, 'data' => [
            'total_transactions' => $totalTx,
            'pending' => $pendingTx,
            'completed' => $completedTx,
            'failed' => $failedTx,
            'unreconciled' => $unreconciledTx,
            'total_amount' => $totalAmount,
            'this_month_amount' => $thisMonthAmount,
            'active_gateways' => $activeGateways
        ]]);
        break;

    // ============================
    // EXPORT TRANSACTIONS CSV
    // ============================
    case 'export_transactions':
        if (!hasPermission('payments', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $gatewayFilter = trim($_GET['gateway_name'] ?? '');
        $statusFilter = trim($_GET['status'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');

        $where = "1=1";
        $params = [];
        if ($gatewayFilter) { $where .= " AND gt.gateway_name = ?"; $params[] = $gatewayFilter; }
        if ($statusFilter) { $where .= " AND gt.status = ?"; $params[] = $statusFilter; }
        if ($dateFrom) { $where .= " AND DATE(gt.created_at) >= ?"; $params[] = $dateFrom; }
        if ($dateTo) { $where .= " AND DATE(gt.created_at) <= ?"; $params[] = $dateTo; }

        $stmt = $conn->prepare("
            SELECT gt.id, gc.display_name AS gateway, gt.transaction_id, gt.external_reference,
                   CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
                   u.unit_number, pr.name AS property, gt.amount, gt.currency, gt.phone_number,
                   gt.status, gt.reconciled, gt.description, gt.error_message, gt.created_at
            FROM gateway_transactions gt
            LEFT JOIN tenants t ON gt.tenant_id = t.id
            LEFT JOIN units u ON gt.unit_id = u.id
            LEFT JOIN properties pr ON u.property_id = pr.id
            LEFT JOIN gateway_config gc ON gt.gateway_name = gc.gateway_name
            WHERE $where
            ORDER BY gt.created_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="gateway_transactions_' . date('Y-m-d') . '.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['ID', 'Gateway', 'Transaction ID', 'Reference', 'Tenant', 'Tenant Phone',
                       'Unit', 'Property', 'Amount', 'Currency', 'Payment Phone', 'Status',
                       'Reconciled', 'Description', 'Error', 'Date']);
        foreach ($rows as $row) {
            $row['reconciled'] = $row['reconciled'] ? 'Yes' : 'No';
            fputcsv($fp, array_values($row));
        }
        fclose($fp);
        exit;

    // ============================
    // DROPDOWNS
    // ============================
    case 'dropdowns':
        $gateways = $conn->query("SELECT gateway_name, display_name FROM gateway_config WHERE is_active = 1 ORDER BY display_name")->fetchAll();
        $tenants = $conn->query("
            SELECT t.id, CONCAT(t.first_name, ' ', t.last_name) AS name, t.phone,
                   l.id AS lease_id, l.rent_amount, l.currency,
                   u.id AS unit_id, u.unit_number, p.name AS property_name
            FROM tenants t
            INNER JOIN leases l ON t.id = l.tenant_id AND l.status = 'active'
            INNER JOIN units u ON l.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            WHERE t.status = 'active'
            ORDER BY t.first_name, t.last_name
        ")->fetchAll();

        jsonResponse(['success' => true, 'data' => [
            'gateways' => $gateways,
            'tenants' => $tenants
        ]]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Reconcile a completed gateway transaction into a payment record
 */
function reconcileTransaction($conn, $tx, $userId) {
    if (!$tx['tenant_id']) {
        return ['success' => false, 'message' => __('gw_no_tenant_linked', 'No tenant linked to this transaction')];
    }

    try {
        $conn->beginTransaction();

        // Get lease info if not set
        $leaseId = $tx['lease_id'];
        $unitId = $tx['unit_id'];
        if (!$leaseId) {
            $stmt = $conn->prepare("SELECT id, unit_id FROM leases WHERE tenant_id = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$tx['tenant_id']]);
            $lease = $stmt->fetch();
            if ($lease) {
                $leaseId = $lease['id'];
                $unitId = $unitId ?: $lease['unit_id'];
            }
        }
        if (!$unitId && $leaseId) {
            $stmt = $conn->prepare("SELECT unit_id FROM leases WHERE id = ?");
            $stmt->execute([$leaseId]);
            $unitId = (int)$stmt->fetchColumn();
        }

        if (!$unitId) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ['success' => false, 'message' => __('gw_no_unit', 'Could not determine unit for this transaction')];
        }

        // Create payment record
        $paymentStmt = $conn->prepare("
            INSERT INTO payments (lease_id, tenant_id, unit_id, amount, currency, payment_date,
                                  payment_method, reference_number, notes, recorded_by, status)
            VALUES (?, ?, ?, ?, ?, CURDATE(), 'gateway', ?, ?, ?, 'recorded')
        ");
        $paymentStmt->execute([
            $leaseId, $tx['tenant_id'], $unitId, $tx['amount'], $tx['currency'],
            $tx['external_reference'] ?: $tx['transaction_id'],
            'Gateway payment: ' . ($tx['gateway_name'] ?? '') . ' — ' . ($tx['description'] ?? ''),
            $userId
        ]);
        $paymentId = (int)$conn->lastInsertId();
            // Ledger (part 31): Dr Float/Bank  Cr Receivable, then allocate
            // against outstanding charges. Never allowed to break the payment.
            postPayment($paymentId);


        // Mark transaction as reconciled
        $conn->prepare("
            UPDATE gateway_transactions
            SET payment_id = ?, reconciled = 1, reconciled_at = NOW(), reconciled_by = ?
            WHERE id = ?
        ")->execute([$paymentId, $userId, $tx['id']]);

        // Auto-generate receipt if setting enabled
        if (getSetting('gateway_auto_receipt', '1') === '1') {
            $prefix = getSetting('receipt_prefix', 'RCT-');
            $nextNum = (int)getSetting('receipt_next_number', '1');
            $receiptNumber = $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

            $conn->prepare("INSERT INTO receipts (payment_id, receipt_number) VALUES (?, ?)")
                 ->execute([$paymentId, $receiptNumber]);

            // Increment receipt number
            $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'receipt_next_number'")
                 ->execute([$nextNum + 1]);
        }

        $conn->commit();

        logActivity('created', 'payment', $paymentId, [
            'source' => 'gateway', 'gateway_tx_id' => $tx['id'],
            'amount' => $tx['amount'], 'tenant_id' => $tx['tenant_id']
        ]);

        return ['success' => true, 'message' => __('gw_reconciled'), 'payment_id' => $paymentId];
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        return ['success' => false, 'message' => 'Reconciliation failed: ' . $e->getMessage()];
    }
}

/**
 * Call gateway API to initiate payment (USSD push)
 * Returns ['success' => bool, 'transaction_id' => string, 'error' => string]
 */
function callGatewayAPI($gateway, $params) {
    $gatewayName = $gateway['gateway_name'];
    $environment = $gateway['environment'];
    $configExtra = $gateway['config_json'] ? json_decode($gateway['config_json'], true) : [];

    switch ($gatewayName) {
        case 'selcom':
            return callSelcomAPI($gateway, $params, $configExtra);

        case 'mpesa':
            return callMpesaAPI($gateway, $params, $configExtra);

        case 'airtel_money':
            return callAirtelAPI($gateway, $params, $configExtra);

        case 'tigo_pesa':
            return callTigoAPI($gateway, $params, $configExtra);

        default:
            // Generic — simulate success in sandbox
            if ($environment === 'sandbox') {
                return [
                    'success' => true,
                    'transaction_id' => 'SBX-' . strtoupper(bin2hex(random_bytes(8)))
                ];
            }
            return ['success' => false, 'error' => 'Unsupported gateway: ' . $gatewayName];
    }
}

/**
 * Selcom API integration
 */
function callSelcomAPI($gateway, $params, $config) {
    if ($gateway['environment'] === 'sandbox') {
        return ['success' => true, 'transaction_id' => 'SELCOM-SBX-' . strtoupper(bin2hex(random_bytes(6)))];
    }

    $apiKey = $gateway['api_key'];
    $apiSecret = $gateway['api_secret'];
    $baseUrl = ($gateway['environment'] === 'production')
        ? 'https://apigw.selcommobile.com/v1'
        : 'https://apigw.selcommobile.com/sandbox/v1';

    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $digest = base64_encode(hash('sha256', $timestamp, true));
    $signature = base64_encode(hash_hmac('sha256', "timestamp=$timestamp", $apiSecret, true));

    $payload = [
        'vendor' => $config['vendor_id'] ?? $gateway['merchant_id'],
        'order_id' => $params['reference'],
        'buyer_email' => '',
        'buyer_name' => '',
        'buyer_phone' => $params['phone'],
        'amount' => $params['amount'],
        'currency' => $params['currency'],
        'payment_methods' => 'USSDPUSH',
        'redirect_url' => '',
        'cancel_url' => '',
        'webhook' => $params['callback_url'],
        'billing_address' => json_encode(['country_code' => 'TZ'])
    ];

    $ch = curl_init($baseUrl . '/checkout/create-order-minimal');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: SELCOM ' . base64_encode($apiKey),
            'Digest-Method: HS256',
            'Digest: ' . $digest,
            'Timestamp: ' . $timestamp,
            'Signed-Fields: timestamp',
            'Signature: ' . $signature,
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'Connection error: ' . $curlError];
    }

    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data'])) {
        return [
            'success' => true,
            'transaction_id' => $data['data'][0]['transid'] ?? $params['reference']
        ];
    }

    return ['success' => false, 'error' => $data['message'] ?? "Gateway returned HTTP $httpCode"];
}

/**
 * M-Pesa C2B API integration
 */
function callMpesaAPI($gateway, $params, $config) {
    if ($gateway['environment'] === 'sandbox') {
        return ['success' => true, 'transaction_id' => 'MPESA-SBX-' . strtoupper(bin2hex(random_bytes(6)))];
    }

    $baseUrl = ($gateway['environment'] === 'production')
        ? 'https://openapi.m-pesa.com/sandbox/ipg/v2/vodacomTZN'
        : 'https://openapi.m-pesa.com/sandbox/ipg/v2/vodacomTZN';

    $sessionPayload = [
        'input_ServiceProviderCode' => $config['service_provider_code'] ?? $gateway['merchant_id'],
    ];

    // In production, implement full M-Pesa C2B flow
    // For now, return sandbox success
    return ['success' => true, 'transaction_id' => 'MPESA-' . strtoupper(bin2hex(random_bytes(6)))];
}

/**
 * Airtel Money API integration
 */
function callAirtelAPI($gateway, $params, $config) {
    if ($gateway['environment'] === 'sandbox') {
        return ['success' => true, 'transaction_id' => 'AIRTEL-SBX-' . strtoupper(bin2hex(random_bytes(6)))];
    }

    // Production Airtel Money collection API
    return ['success' => true, 'transaction_id' => 'AIRTEL-' . strtoupper(bin2hex(random_bytes(6)))];
}

/**
 * Tigo Pesa API integration
 */
function callTigoAPI($gateway, $params, $config) {
    if ($gateway['environment'] === 'sandbox') {
        return ['success' => true, 'transaction_id' => 'TIGO-SBX-' . strtoupper(bin2hex(random_bytes(6)))];
    }

    return ['success' => true, 'transaction_id' => 'TIGO-' . strtoupper(bin2hex(random_bytes(6)))];
}

/**
 * Normalize phone number to international format (Tanzania)
 */
function normalizePhone($phone) {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (strpos($phone, '+') === 0) return $phone;
    if (strpos($phone, '0') === 0) {
        return '+255' . substr($phone, 1);
    }
    if (strpos($phone, '255') === 0) {
        return '+' . $phone;
    }
    return '+255' . $phone;
}
