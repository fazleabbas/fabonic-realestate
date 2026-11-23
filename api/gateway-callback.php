<?php
/**
 * FIS Real Estate — Payment Gateway Callback Handler (Part 21.3)
 * Standalone webhook endpoint — NO auth required.
 * Receives payment confirmations from gateway providers,
 * validates, updates transaction records, and auto-reconciles.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/../includes/charges.php';

// Log all callbacks for debugging
$rawInput = file_get_contents('php://input');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$headers = getallheaders() ?: [];

error_log("FIS RE Gateway Callback: method=$method, body=" . substr($rawInput, 0, 2000));

// Check if module is enabled
if (getSetting('payment_gateway_enabled', '0') !== '1') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Payment gateway disabled']);
    exit;
}

// Determine gateway from URL param or headers
$gatewayName = trim($_GET['gateway'] ?? $_POST['gateway'] ?? '');

// Parse input based on content type
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$callbackData = [];

if (strpos($contentType, 'application/json') !== false) {
    $callbackData = json_decode($rawInput, true) ?: [];
} else {
    $callbackData = $_POST;
}

if (empty($gatewayName)) {
    // Try to detect gateway from callback data structure
    if (isset($callbackData['transid']) || isset($callbackData['order_id'])) {
        $gatewayName = 'selcom';
    } elseif (isset($callbackData['input_TransactionID'])) {
        $gatewayName = 'mpesa';
    } elseif (isset($callbackData['transaction'])) {
        $gatewayName = 'airtel_money';
    }
}

if (empty($gatewayName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Cannot determine gateway']);
    exit;
}

// Validate callback secret (if configured)
$secret = getSetting('gateway_callback_secret', '');
if ($secret) {
    $providedSecret = $_GET['secret'] ?? $callbackData['secret'] ?? '';
    $signatureHeader = $headers['X-Callback-Secret'] ?? $headers['x-callback-secret'] ?? '';
    if ($providedSecret !== $secret && $signatureHeader !== $secret) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid callback secret']);
        exit;
    }
}

// Process based on gateway
try {
    switch ($gatewayName) {

        case 'selcom':
            processSelcomCallback($conn, $callbackData, $rawInput);
            break;

        case 'mpesa':
            processMpesaCallback($conn, $callbackData, $rawInput);
            break;

        case 'airtel_money':
            processAirtelCallback($conn, $callbackData, $rawInput);
            break;

        case 'tigo_pesa':
            processTigoCallback($conn, $callbackData, $rawInput);
            break;

        default:
            processGenericCallback($conn, $gatewayName, $callbackData, $rawInput);
            break;
    }

    echo json_encode(['success' => true, 'message' => 'Callback processed']);

} catch (Exception $e) {
    error_log("FIS RE Gateway Callback ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Processing error']);
}

// ============================================================
// GATEWAY-SPECIFIC CALLBACK PROCESSORS
// ============================================================

/**
 * Process Selcom callback
 */
function processSelcomCallback($conn, $data, $raw) {
    $orderId = $data['order_id'] ?? $data['reference'] ?? '';
    $transId = $data['transid'] ?? '';
    $resultCode = $data['result'] ?? $data['resultcode'] ?? '';
    $amount = (float)($data['amount'] ?? 0);

    if (empty($orderId) && empty($transId)) {
        error_log("Selcom callback: missing order_id/transid");
        return;
    }

    $tx = findTransaction($conn, $orderId, $transId, 'selcom');
    if (!$tx) {
        error_log("Selcom callback: transaction not found for order=$orderId, trans=$transId");
        return;
    }

    // Selcom result codes: 000 = success
    $isSuccess = ($resultCode === '000' || strtolower($resultCode) === 'success');
    $newStatus = $isSuccess ? 'completed' : 'failed';
    $errorMsg = $isSuccess ? null : ($data['message'] ?? "Result code: $resultCode");

    updateTransactionFromCallback($conn, $tx, $newStatus, $transId, $raw, $errorMsg);
}

/**
 * Process M-Pesa callback
 */
function processMpesaCallback($conn, $data, $raw) {
    $transId = $data['input_TransactionID'] ?? $data['TransactionID'] ?? '';
    $resultCode = $data['input_ResultCode'] ?? $data['ResultCode'] ?? '';
    $thirdPartyRef = $data['input_ThirdPartyConversationID'] ?? $data['ThirdPartyConversationID'] ?? '';

    $tx = findTransaction($conn, $thirdPartyRef, $transId, 'mpesa');
    if (!$tx) {
        error_log("M-Pesa callback: transaction not found");
        return;
    }

    $isSuccess = ($resultCode === 'INS-0' || $resultCode === '0');
    $newStatus = $isSuccess ? 'completed' : 'failed';
    $errorMsg = $isSuccess ? null : ($data['input_ResultDesc'] ?? "Result: $resultCode");

    updateTransactionFromCallback($conn, $tx, $newStatus, $transId, $raw, $errorMsg);
}

/**
 * Process Airtel Money callback
 */
function processAirtelCallback($conn, $data, $raw) {
    $transaction = $data['transaction'] ?? [];
    $transId = $transaction['id'] ?? $data['transaction_id'] ?? '';
    $status = $transaction['status_code'] ?? $data['status'] ?? '';
    $reference = $transaction['airtel_money_id'] ?? $data['reference'] ?? '';
    $externalRef = $data['reference'] ?? $transaction['message'] ?? '';

    $tx = findTransaction($conn, $externalRef, $transId, 'airtel_money');
    if (!$tx) {
        error_log("Airtel callback: transaction not found");
        return;
    }

    $isSuccess = (strtolower($status) === 'ts' || $status === '200' || strtolower($status) === 'success');
    $newStatus = $isSuccess ? 'completed' : 'failed';
    $errorMsg = $isSuccess ? null : ($transaction['message'] ?? "Status: $status");

    updateTransactionFromCallback($conn, $tx, $newStatus, $transId ?: $reference, $raw, $errorMsg);
}

/**
 * Process Tigo Pesa callback
 */
function processTigoCallback($conn, $data, $raw) {
    $transId = $data['MasterMerchantID'] ?? $data['TransactionID'] ?? '';
    $reference = $data['ReferenceID'] ?? $data['reference'] ?? '';
    $status = $data['ResponseCode'] ?? $data['Status'] ?? '';

    $tx = findTransaction($conn, $reference, $transId, 'tigo_pesa');
    if (!$tx) {
        error_log("Tigo callback: transaction not found");
        return;
    }

    $isSuccess = ($status === 'BILLER-30-0000-S' || strtolower($status) === 'success' || $status === '0');
    $newStatus = $isSuccess ? 'completed' : 'failed';
    $errorMsg = $isSuccess ? null : ($data['ResponseDescription'] ?? "Status: $status");

    updateTransactionFromCallback($conn, $tx, $newStatus, $transId, $raw, $errorMsg);
}

/**
 * Process generic/unknown gateway callback
 */
function processGenericCallback($conn, $gatewayName, $data, $raw) {
    $transId = $data['transaction_id'] ?? $data['transid'] ?? $data['id'] ?? '';
    $reference = $data['reference'] ?? $data['order_id'] ?? $data['external_reference'] ?? '';
    $status = $data['status'] ?? $data['result'] ?? '';

    $tx = findTransaction($conn, $reference, $transId, $gatewayName);
    if (!$tx) {
        error_log("Generic callback ($gatewayName): transaction not found");
        return;
    }

    $isSuccess = in_array(strtolower($status), ['success', 'completed', 'paid', '000', '0']);
    $newStatus = $isSuccess ? 'completed' : 'failed';
    $errorMsg = $isSuccess ? null : ($data['message'] ?? "Status: $status");

    updateTransactionFromCallback($conn, $tx, $newStatus, $transId, $raw, $errorMsg);
}

// ============================================================
// SHARED HELPER FUNCTIONS
// ============================================================

/**
 * Find a transaction by reference or transaction ID
 */
function findTransaction($conn, $reference, $transId, $gatewayName) {
    // Try by external_reference first
    if ($reference) {
        $stmt = $conn->prepare("SELECT * FROM gateway_transactions WHERE external_reference = ? AND gateway_name = ?");
        $stmt->execute([$reference, $gatewayName]);
        $tx = $stmt->fetch();
        if ($tx) return $tx;
    }
    // Try by transaction_id
    if ($transId) {
        $stmt = $conn->prepare("SELECT * FROM gateway_transactions WHERE transaction_id = ? AND gateway_name = ?");
        $stmt->execute([$transId, $gatewayName]);
        $tx = $stmt->fetch();
        if ($tx) return $tx;
    }
    // Broader search: any matching reference
    if ($reference) {
        $stmt = $conn->prepare("SELECT * FROM gateway_transactions WHERE external_reference = ?");
        $stmt->execute([$reference]);
        $tx = $stmt->fetch();
        if ($tx) return $tx;
    }
    return null;
}

/**
 * Update transaction from callback and optionally auto-reconcile
 */
function updateTransactionFromCallback($conn, $tx, $newStatus, $transId, $rawCallback, $errorMsg) {
    // Don't update if already in a terminal state
    if (in_array($tx['status'], ['completed', 'cancelled'])) {
        error_log("Gateway callback: TX #{$tx['id']} already in terminal state: {$tx['status']}");
        return;
    }

    $conn->prepare("
        UPDATE gateway_transactions
        SET status = ?, transaction_id = COALESCE(?, transaction_id),
            callback_data = ?, error_message = ?
        WHERE id = ?
    ")->execute([$newStatus, $transId ?: null, $rawCallback, $errorMsg, $tx['id']]);

    // Auto-reconcile if completed and setting enabled
    if ($newStatus === 'completed' && getSetting('gateway_auto_reconcile', '1') === '1') {
        $tx['status'] = 'completed';
        if ($transId) $tx['transaction_id'] = $transId;
        autoReconcileTransaction($conn, $tx);
    }
}

/**
 * Auto-reconcile a transaction (system-initiated, no user ID)
 */
function autoReconcileTransaction($conn, $tx) {
    if (!$tx['tenant_id']) return;
    if ($tx['reconciled'] ?? false) return;

    try {
        $conn->beginTransaction();

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
        if (!$unitId) {
            if ($conn->inTransaction()) $conn->rollBack();
            return;
        }

        // Create payment
        $stmt = $conn->prepare("
            INSERT INTO payments (lease_id, tenant_id, unit_id, amount, currency, payment_date,
                                  payment_method, reference_number, notes, status)
            VALUES (?, ?, ?, ?, ?, CURDATE(), 'gateway', ?, ?, 'recorded')
        ");
        $stmt->execute([
            $leaseId, $tx['tenant_id'], $unitId, $tx['amount'], $tx['currency'],
            $tx['external_reference'] ?: $tx['transaction_id'],
            'Auto-reconciled gateway payment: ' . $tx['gateway_name']
        ]);
        $paymentId = (int)$conn->lastInsertId();
            // Ledger (part 31): Dr Float/Bank  Cr Receivable, then allocate
            // against outstanding charges. Never allowed to break the payment.
            postPayment($paymentId);


        // Mark reconciled
        $conn->prepare("
            UPDATE gateway_transactions
            SET payment_id = ?, reconciled = 1, reconciled_at = NOW()
            WHERE id = ?
        ")->execute([$paymentId, $tx['id']]);

        // Auto receipt
        if (getSetting('gateway_auto_receipt', '1') === '1') {
            $prefix = getSetting('receipt_prefix', 'RCT-');
            $nextNum = (int)getSetting('receipt_next_number', '1');
            $receiptNumber = $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

            $conn->prepare("INSERT INTO receipts (payment_id, receipt_number) VALUES (?, ?)")
                 ->execute([$paymentId, $receiptNumber]);
            $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'receipt_next_number'")
                 ->execute([$nextNum + 1]);
        }

        $conn->commit();
        error_log("FIS RE Gateway: Auto-reconciled TX #{$tx['id']} → Payment #$paymentId");

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log("FIS RE Gateway auto-reconcile error: " . $e->getMessage());
    }
}
