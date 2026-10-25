<?php
/**
 * FIS Real Estate — Tenant Portal API (Part 16)
 * Handles: dashboard stats, payment history, receipt download, profile update, password change.
 * Auth: uses $_SESSION['tenant_portal_id'] (NOT staff session).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/charges.php';
require_once __DIR__ . '/../includes/subscription.php';

// Tenant portal auth check
if (empty($_SESSION['tenant_portal_id'])) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized — please log in to the tenant portal'], 401);
}

// Check system-level portal enabled + tier
$portalEnabled = getSetting('tenant_portal_enabled', '0');
if (!$portalEnabled) {
    jsonResponse(['success' => false, 'message' => 'Tenant portal is disabled'], 403);
}

$tenantId = (int) $_SESSION['tenant_portal_id'];
$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // DASHBOARD STATS
    // ============================
    case 'dashboard_stats':
        $stats = [
            'tenant' => null,
            'active_lease' => null,
            'current_balance' => 0,
            'total_paid' => 0,
            'next_due_date' => null,
            'next_due_amount' => 0,
            'recent_payments' => [],
            'pending_late_fees' => 0,
        ];

        // Tenant info
        $stmt = $conn->prepare("SELECT id, first_name, last_name, phone, email, status, move_in_date FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $stats['tenant'] = $stmt->fetch();

        if (!$stats['tenant']) {
            jsonResponse(['success' => false, 'message' => 'Tenant not found'], 404);
        }

        // Active lease
        $stmt = $conn->prepare("
            SELECT l.*, u.unit_number, u.unit_type, p.name AS property_name
            FROM leases l
            INNER JOIN units u ON l.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            WHERE l.tenant_id = ? AND l.status = 'active'
            ORDER BY l.start_date DESC LIMIT 1
        ");
        $stmt->execute([$tenantId]);
        $stats['active_lease'] = $stmt->fetch();

        // Total paid (all time)
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE tenant_id = ? AND status = 'recorded'");
        $stmt->execute([$tenantId]);
        $stats['total_paid'] = (float) $stmt->fetchColumn();

        // Payments this year
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE tenant_id = ? AND status = 'recorded' AND YEAR(payment_date) = YEAR(CURDATE())");
        $stmt->execute([$tenantId]);
        $stats['paid_this_year'] = (float) $stmt->fetchColumn();

        // Calculate balance if there's an active lease
        if ($stats['active_lease']) {
            $lease = $stats['active_lease'];

            // Part 31: the tenant's balance is the sum of charges still
            // unsettled. Months-elapsed times rent overstated anyone who paid
            // ahead and understated anyone mid-term.
            $stats['current_balance'] = outstandingTotal([
                'lease_id' => $lease['id'],
                'due_by'   => date('Y-m-d'),
            ]);

            // Next due date
            $dueDay = (int) $lease['payment_due_day'];
            $nextDue = new DateTime();
            $nextDue->setDate($nextDue->format('Y'), $nextDue->format('m'), min($dueDay, (int) $nextDue->format('t')));
            if ($nextDue <= $now) {
                $nextDue->modify('+1 month');
                $nextDue->setDate($nextDue->format('Y'), $nextDue->format('m'), min($dueDay, (int) $nextDue->format('t')));
            }
            $stats['next_due_date'] = $nextDue->format('Y-m-d');
            $stats['next_due_amount'] = (float) $lease['rent_amount'];
        }

        // Pending late fees
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM late_fees WHERE tenant_id = ? AND status = 'pending'");
        $stmt->execute([$tenantId]);
        $stats['pending_late_fees'] = (float) $stmt->fetchColumn();

        // Recent payments (last 5)
        $stmt = $conn->prepare("
            SELECT p.id, p.amount, p.currency, p.payment_date, p.payment_method, p.period_from, p.period_to, p.status,
                   r.receipt_number, r.pdf_path
            FROM payments p
            LEFT JOIN receipts r ON r.payment_id = p.id
            WHERE p.tenant_id = ? AND p.status = 'recorded'
            ORDER BY p.payment_date DESC LIMIT 5
        ");
        $stmt->execute([$tenantId]);
        $stats['recent_payments'] = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $stats]);
        break;

    // ============================
    // PAYMENT HISTORY (paginated)
    // ============================
    case 'payment_history':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');

        $where = "p.tenant_id = :tid AND p.status = 'recorded'";
        $params = ['tid' => $tenantId];

        if ($dateFrom) {
            $where .= " AND p.payment_date >= :df";
            $params['df'] = $dateFrom;
        }
        if ($dateTo) {
            $where .= " AND p.payment_date <= :dt";
            $params['dt'] = $dateTo;
        }

        // Count
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM payments p WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pagination = paginate($total, $page);

        // Fetch
        $sql = "SELECT p.id, p.amount, p.currency, p.payment_date, p.payment_method,
                       p.reference_number, p.period_from, p.period_to, p.notes,
                       r.receipt_number, r.pdf_path,
                       u.unit_number, pr.name AS property_name
                FROM payments p
                LEFT JOIN receipts r ON r.payment_id = p.id
                LEFT JOIN units u ON p.unit_id = u.id
                LEFT JOIN properties pr ON u.property_id = pr.id
                WHERE {$where}
                ORDER BY p.payment_date DESC
                LIMIT :offset, :limit";
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $pagination['records_per_page'], PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(), 'pagination' => $pagination]);
        break;

    // ============================
    // RECEIPT DOWNLOAD
    // ============================
    case 'download_receipt':
        $paymentId = (int)($_GET['payment_id'] ?? 0);

        // Verify this payment belongs to this tenant
        $stmt = $conn->prepare("
            SELECT r.pdf_path, r.receipt_number
            FROM receipts r
            INNER JOIN payments p ON r.payment_id = p.id
            WHERE p.id = ? AND p.tenant_id = ?
        ");
        $stmt->execute([$paymentId, $tenantId]);
        $receipt = $stmt->fetch();

        if (!$receipt || !$receipt['pdf_path']) {
            jsonResponse(['success' => false, 'message' => 'Receipt not found'], 404);
        }

        $filePath = UPLOAD_PATH . $receipt['pdf_path'];
        if (!file_exists($filePath)) {
            jsonResponse(['success' => false, 'message' => 'Receipt file not found'], 404);
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="receipt_' . $receipt['receipt_number'] . '.pdf"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;

    // ============================
    // TENANT PROFILE — GET
    // ============================
    case 'get_profile':
        $stmt = $conn->prepare("
            SELECT id, first_name, last_name, phone, email,
                   emergency_contact_name, emergency_contact_phone, emergency_contact_relation,
                   status, move_in_date, portal_last_login
            FROM tenants WHERE id = ?
        ");
        $stmt->execute([$tenantId]);
        $profile = $stmt->fetch();

        if (!$profile) {
            jsonResponse(['success' => false, 'message' => 'Tenant not found'], 404);
        }

        jsonResponse(['success' => true, 'data' => $profile]);
        break;

    // ============================
    // TENANT PROFILE — UPDATE
    // ============================
    case 'update_profile':
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $emergencyName = trim($_POST['emergency_contact_name'] ?? '');
        $emergencyPhone = trim($_POST['emergency_contact_phone'] ?? '');
        $emergencyRelation = trim($_POST['emergency_contact_relation'] ?? '');

        if (empty($phone)) {
            jsonResponse(['success' => false, 'message' => __('tp_phone_required', 'Phone number is required')]);
        }

        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['success' => false, 'message' => __('invalid_email', 'Invalid email address')]);
        }

        try {
            $stmt = $conn->prepare("
                UPDATE tenants SET
                    phone = ?, email = ?,
                    emergency_contact_name = ?, emergency_contact_phone = ?, emergency_contact_relation = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $phone,
                $email ?: null,
                $emergencyName ?: null,
                $emergencyPhone ?: null,
                $emergencyRelation ?: null,
                $tenantId
            ]);

            // Update session phone
            $_SESSION['tenant_portal_phone'] = $phone;
            $_SESSION['tenant_portal_email'] = $email;

            logActivity('updated', 'tenant_portal_profile', $tenantId, ['fields' => 'phone,email,emergency']);
            jsonResponse(['success' => true, 'message' => __('tp_profile_updated', 'Profile updated successfully')]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        break;

    // ============================
    // CHANGE PASSWORD
    // ============================
    case 'change_password':
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword)) {
            jsonResponse(['success' => false, 'message' => __('tp_all_fields_required', 'All fields are required')]);
        }

        if (strlen($newPassword) < 6) {
            jsonResponse(['success' => false, 'message' => __('tp_password_min', 'Password must be at least 6 characters')]);
        }

        if ($newPassword !== $confirmPassword) {
            jsonResponse(['success' => false, 'message' => __('passwords_dont_match')]);
        }

        // Verify current password
        $stmt = $conn->prepare("SELECT portal_password_hash FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch();

        if (!$tenant || !password_verify($currentPassword, $tenant['portal_password_hash'])) {
            jsonResponse(['success' => false, 'message' => __('current_password_wrong')]);
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE tenants SET portal_password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $tenantId]);

        logActivity('updated', 'tenant_portal_password', $tenantId);
        jsonResponse(['success' => true, 'message' => __('tp_password_changed', 'Password changed successfully')]);
        break;

    // ============================
    // LEASE DETAILS
    // ============================
    case 'lease_details':
        $stmt = $conn->prepare("
            SELECT l.*, u.unit_number, u.unit_type, u.floor, u.size_sqm, u.bedrooms, u.bathrooms,
                   p.name AS property_name, p.address AS property_address
            FROM leases l
            INNER JOIN units u ON l.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            WHERE l.tenant_id = ? AND l.status = 'active'
            ORDER BY l.start_date DESC LIMIT 1
        ");
        $stmt->execute([$tenantId]);
        $lease = $stmt->fetch();

        if (!$lease) {
            jsonResponse(['success' => false, 'message' => 'No active lease found']);
        }

        jsonResponse(['success' => true, 'data' => $lease]);
        break;

    // ============================
    // LATE FEES LIST
    // ============================
    case 'late_fees':
        $stmt = $conn->prepare("
            SELECT lf.id, lf.amount, lf.currency, lf.fee_date, lf.period_reference, lf.status
            FROM late_fees lf
            WHERE lf.tenant_id = ?
            ORDER BY lf.fee_date DESC
            LIMIT 20
        ");
        $stmt->execute([$tenantId]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}
