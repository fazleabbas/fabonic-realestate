<?php
/**
 * FIS Real Estate — SaaS Admin Panel API (Part 23)
 * Handles: dashboard stats, subscriber management, payment verification,
 * discount management, announcements, global settings.
 * Admin only — runs against central DB (fiscotz_propmanage).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

// Admin only
if (!isSaasOperator()) {
    jsonResponse(['success' => false, 'message' => __('adm_admin_only', 'Admin access required')], 403);
}

// Get central DB connection
$central = getCentralDb();
if (!$central) {
    jsonResponse(['success' => false, 'message' => 'Central database connection failed'], 500);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // DASHBOARD STATS
    // ============================
    case 'dashboard_stats':
        // Subscribers by tier
        $tierStmt = $central->query("
            SELECT tier, COUNT(*) AS cnt FROM subscriptions
            WHERE status IN ('active','trial')
            GROUP BY tier
        ");
        $byTier = ['basic' => 0, 'professional' => 0, 'enterprise' => 0];
        foreach ($tierStmt->fetchAll() as $row) {
            $byTier[$row['tier']] = (int)$row['cnt'];
        }

        // Total subscribers (active + trial)
        $totalActive = array_sum($byTier);

        // MRR (Monthly Recurring Revenue)
        $mrr = (float)$central->query("
            SELECT COALESCE(SUM(net_amount), 0) FROM subscriptions
            WHERE status = 'active'
        ")->fetchColumn();

        // Active trials
        $activeTrials = (int)$central->query("
            SELECT COUNT(*) FROM subscriptions WHERE status = 'trial'
        ")->fetchColumn();

        // Total subscribers ever
        $totalEver = (int)$central->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn();

        // Churned (cancelled + expired)
        $churned = (int)$central->query("
            SELECT COUNT(*) FROM subscriptions WHERE status IN ('cancelled','expired')
        ")->fetchColumn();

        // Churn rate
        $churnRate = $totalEver > 0 ? round(($churned / $totalEver) * 100, 1) : 0;

        // Pending payments
        $pendingPayments = (int)$central->query("
            SELECT COUNT(*) FROM subscription_payments WHERE status = 'pending'
        ")->fetchColumn();

        $pendingAmount = (float)$central->query("
            SELECT COALESCE(SUM(amount), 0) FROM subscription_payments WHERE status = 'pending'
        ")->fetchColumn();

        // Total units managed
        $totalUnits = (int)$central->query("
            SELECT COALESCE(SUM(unit_count), 0) FROM subscriptions
            WHERE status IN ('active','trial')
        ")->fetchColumn();

        // Suspended
        $suspended = (int)$central->query("
            SELECT COUNT(*) FROM subscriptions WHERE status = 'suspended'
        ")->fetchColumn();

        // Growth: new subscribers this month vs last month
        $thisMonth = (int)$central->query("
            SELECT COUNT(*) FROM subscriptions
            WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
        ")->fetchColumn();

        $lastMonth = (int)$central->query("
            SELECT COUNT(*) FROM subscriptions
            WHERE MONTH(created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
            AND YEAR(created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
        ")->fetchColumn();

        // Monthly growth (last 12 months)
        $growthStmt = $central->query("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
                   COUNT(*) AS new_subscribers,
                   COALESCE(SUM(net_amount), 0) AS revenue
            FROM subscriptions
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ");
        $growth = $growthStmt->fetchAll();

        // Revenue last 12 months (confirmed payments)
        $revenueStmt = $central->query("
            SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month,
                   COALESCE(SUM(amount), 0) AS total
            FROM subscription_payments
            WHERE status = 'confirmed'
            AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
            ORDER BY month ASC
        ");
        $revenue = $revenueStmt->fetchAll();

        // Trial conversion rate
        $totalTrialRequests = (int)$central->query("SELECT COUNT(*) FROM trial_requests")->fetchColumn();
        $approvedTrials = (int)$central->query("SELECT COUNT(*) FROM trial_requests WHERE status = 'approved'")->fetchColumn();
        $conversionRate = $totalTrialRequests > 0 ? round(($approvedTrials / $totalTrialRequests) * 100, 1) : 0;

        jsonResponse(['success' => true, 'data' => [
            'total_active' => $totalActive,
            'by_tier' => $byTier,
            'mrr' => $mrr,
            'active_trials' => $activeTrials,
            'total_ever' => $totalEver,
            'churned' => $churned,
            'churn_rate' => $churnRate,
            'pending_payments' => $pendingPayments,
            'pending_amount' => $pendingAmount,
            'total_units' => $totalUnits,
            'suspended' => $suspended,
            'this_month_new' => $thisMonth,
            'last_month_new' => $lastMonth,
            'growth' => $growth,
            'revenue' => $revenue,
            'trial_requests' => $totalTrialRequests,
            'conversion_rate' => $conversionRate
        ]]);
        break;

    // ============================
    // SUBSCRIBERS — LIST
    // ============================
    case 'list_subscribers':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $search = trim($_GET['search'] ?? '');
        $tier = trim($_GET['tier'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $perPage = RECORDS_PER_PAGE;

        $where = "1=1";
        $params = [];

        if ($search) {
            $where .= " AND (s.company_name LIKE :s1 OR s.owner_name LIKE :s2 OR s.owner_phone LIKE :s3 OR s.owner_email LIKE :s4 OR s.tenant_slug LIKE :s5)";
            $params['s1'] = "%$search%";
            $params['s2'] = "%$search%";
            $params['s3'] = "%$search%";
            $params['s4'] = "%$search%";
            $params['s5'] = "%$search%";
        }
        if ($tier) {
            $where .= " AND s.tier = :tier";
            $params['tier'] = $tier;
        }
        if ($status) {
            $where .= " AND s.status = :status";
            $params['status'] = $status;
        }

        $countStmt = $central->prepare("SELECT COUNT(*) FROM subscriptions s WHERE $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $totalPages = max(1, ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT s.*,
                       (SELECT COUNT(*) FROM subscription_payments p WHERE p.subscription_id = s.id AND p.status = 'confirmed') AS confirmed_payments,
                       (SELECT COALESCE(SUM(p.amount), 0) FROM subscription_payments p WHERE p.subscription_id = s.id AND p.status = 'confirmed') AS total_paid,
                       (SELECT COUNT(*) FROM subscription_payments p WHERE p.subscription_id = s.id AND p.status = 'pending') AS pending_payments
                FROM subscriptions s
                WHERE $where
                ORDER BY s.created_at DESC
                LIMIT :offset, :limit";
        $stmt = $central->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(), 'pagination' => [
            'total' => $total, 'page' => $page, 'total_pages' => $totalPages, 'records_per_page' => $perPage
        ]]);
        break;

    // ============================
    // SUBSCRIBERS — GET
    // ============================
    case 'get_subscriber':
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $central->prepare("SELECT * FROM subscriptions WHERE id = ?");
        $stmt->execute([$id]);
        $sub = $stmt->fetch();
        if (!$sub) {
            jsonResponse(['success' => false, 'message' => 'Subscriber not found'], 404);
        }

        // Payment history
        $payStmt = $central->prepare("
            SELECT * FROM subscription_payments
            WHERE subscription_id = ?
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $payStmt->execute([$id]);
        $payments = $payStmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $sub, 'payments' => $payments]);
        break;

    // ============================
    // SUBSCRIBERS — UPDATE
    // ============================
    case 'update_subscriber':
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $central->prepare("SELECT * FROM subscriptions WHERE id = ?");
        $stmt->execute([$id]);
        $sub = $stmt->fetch();
        if (!$sub) jsonResponse(['success' => false, 'message' => 'Subscriber not found'], 404);

        $tier = trim($_POST['tier'] ?? $sub['tier']);
        $unitCount = (int)($_POST['unit_count'] ?? $sub['unit_count']);
        $status = trim($_POST['status'] ?? $sub['status']);
        $notes = trim($_POST['admin_notes'] ?? $sub['admin_notes'] ?? '');

        // Recalculate billing
        $discountType = $sub['discount_type'];
        $discountValue = (float)$sub['discount_value'];
        $billing = calculateBilling($unitCount, $tier, $discountType, $discountValue, $sub['currency']);

        $updateSql = "UPDATE subscriptions SET
            tier = :tier, unit_count = :units, rate_per_unit = :rate,
            gross_amount = :gross, discount_amount = :disc_amt, net_amount = :net,
            status = :status, admin_notes = :notes, updated_at = NOW()";
        $updateParams = [
            'tier' => $tier, 'units' => $unitCount, 'rate' => $billing['rate_per_unit'],
            'gross' => $billing['gross_amount'], 'disc_amt' => $billing['discount_amount'],
            'net' => $billing['net_amount'], 'status' => $status, 'notes' => $notes, 'id' => $id
        ];

        // Handle suspension
        if ($status === 'suspended' && $sub['status'] !== 'suspended') {
            $updateSql .= ", suspended_at = NOW(), suspended_reason = :reason";
            $updateParams['reason'] = trim($_POST['suspended_reason'] ?? 'Suspended by admin');
        } elseif ($status !== 'suspended' && $sub['status'] === 'suspended') {
            $updateSql .= ", suspended_at = NULL, suspended_reason = NULL";
        }

        $updateSql .= " WHERE id = :id";
        $central->prepare($updateSql)->execute($updateParams);

        logActivity('updated', 'subscription', $id, ['tier' => $tier, 'status' => $status, 'units' => $unitCount]);
        jsonResponse(['success' => true, 'message' => __('adm_subscriber_updated', 'Subscriber updated successfully')]);
        break;

    // ============================
    // SUBSCRIBERS — SUSPEND / ACTIVATE
    // ============================
    case 'toggle_status':
        $id = (int)($_POST['id'] ?? 0);
        $newStatus = trim($_POST['new_status'] ?? '');

        if (!in_array($newStatus, ['active', 'suspended', 'cancelled'])) {
            jsonResponse(['success' => false, 'message' => 'Invalid status']);
        }

        $stmt = $central->prepare("SELECT * FROM subscriptions WHERE id = ?");
        $stmt->execute([$id]);
        $sub = $stmt->fetch();
        if (!$sub) jsonResponse(['success' => false, 'message' => 'Subscriber not found'], 404);

        $sql = "UPDATE subscriptions SET status = :status, updated_at = NOW()";
        $params = ['status' => $newStatus, 'id' => $id];

        if ($newStatus === 'suspended') {
            $sql .= ", suspended_at = NOW(), suspended_reason = :reason";
            $params['reason'] = trim($_POST['reason'] ?? 'Suspended by admin');
        } elseif ($newStatus === 'active') {
            $sql .= ", suspended_at = NULL, suspended_reason = NULL";
        }

        $sql .= " WHERE id = :id";
        $central->prepare($sql)->execute($params);

        logActivity('updated', 'subscription', $id, ['status' => $newStatus]);
        jsonResponse(['success' => true, 'message' => __('adm_status_changed', 'Subscription status updated')]);
        break;

    // ============================
    // PAYMENTS — LIST PENDING
    // ============================
    case 'list_payments':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $perPage = RECORDS_PER_PAGE;

        $where = "1=1";
        $params = [];

        if ($status) {
            $where .= " AND p.status = :status";
            $params['status'] = $status;
        }
        if ($search) {
            $where .= " AND (s.company_name LIKE :s1 OR s.owner_name LIKE :s2 OR p.reference_number LIKE :s3 OR s.owner_phone LIKE :s4)";
            $params['s1'] = "%$search%";
            $params['s2'] = "%$search%";
            $params['s3'] = "%$search%";
            $params['s4'] = "%$search%";
        }

        $countStmt = $central->prepare("
            SELECT COUNT(*) FROM subscription_payments p
            LEFT JOIN subscriptions s ON p.subscription_id = s.id
            WHERE $where
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $totalPages = max(1, ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT p.*, s.company_name, s.owner_name, s.owner_phone, s.owner_email,
                       s.tier, s.unit_count, s.net_amount AS subscription_amount, s.tenant_slug
                FROM subscription_payments p
                LEFT JOIN subscriptions s ON p.subscription_id = s.id
                WHERE $where
                ORDER BY p.created_at DESC
                LIMIT :offset, :limit";
        $stmt = $central->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(), 'pagination' => [
            'total' => $total, 'page' => $page, 'total_pages' => $totalPages
        ]]);
        break;

    // ============================
    // PAYMENTS — CONFIRM
    // ============================
    case 'confirm_payment':
        $id = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['verification_notes'] ?? '');

        $stmt = $central->prepare("SELECT * FROM subscription_payments WHERE id = ?");
        $stmt->execute([$id]);
        $payment = $stmt->fetch();
        if (!$payment) jsonResponse(['success' => false, 'message' => 'Payment not found'], 404);
        if ($payment['status'] === 'confirmed') jsonResponse(['success' => false, 'message' => __('adm_already_confirmed', 'Payment already confirmed')]);

        $central->beginTransaction();
        try {
            // Confirm payment
            $central->prepare("
                UPDATE subscription_payments
                SET status = 'confirmed', confirmed_by = :by, confirmed_at = NOW(),
                    verified_at = NOW(), verification_notes = :notes
                WHERE id = :id
            ")->execute([
                'by' => getUserData('name'),
                'notes' => $notes,
                'id' => $id
            ]);

            // Auto-activate subscription if it's pending/suspended/trial
            $subStmt = $central->prepare("SELECT * FROM subscriptions WHERE id = ?");
            $subStmt->execute([$payment['subscription_id']]);
            $sub = $subStmt->fetch();

            if ($sub && in_array($sub['status'], ['trial', 'suspended', 'expired'])) {
                // Calculate new period
                $months = 1;
                if ($payment['period_from'] && $payment['period_to']) {
                    $from = new DateTime($payment['period_from']);
                    $to = new DateTime($payment['period_to']);
                    $months = max(1, (int)$from->diff($to)->format('%m') + ($from->diff($to)->format('%y') * 12));
                    if ($months < 1) $months = 1;
                }

                $startDate = date('Y-m-d');
                $endDate = date('Y-m-d', strtotime("+{$months} months"));

                $central->prepare("
                    UPDATE subscriptions
                    SET status = 'active', start_date = :start, end_date = :end,
                        next_billing_date = :next, suspended_at = NULL, suspended_reason = NULL,
                        updated_at = NOW()
                    WHERE id = :id
                ")->execute([
                    'start' => $startDate,
                    'end' => $endDate,
                    'next' => $endDate,
                    'id' => $payment['subscription_id']
                ]);
            }

            $central->commit();
            logActivity('confirmed', 'subscription_payment', $id, ['subscription_id' => $payment['subscription_id']]);
            jsonResponse(['success' => true, 'message' => __('adm_payment_confirmed', 'Payment confirmed and subscription activated')]);
        } catch (Exception $e) {
            $central->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
        break;

    // ============================
    // PAYMENTS — REJECT
    // ============================
    case 'reject_payment':
        $id = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['verification_notes'] ?? '');

        $stmt = $central->prepare("SELECT * FROM subscription_payments WHERE id = ?");
        $stmt->execute([$id]);
        $payment = $stmt->fetch();
        if (!$payment) jsonResponse(['success' => false, 'message' => 'Payment not found'], 404);

        $central->prepare("
            UPDATE subscription_payments
            SET status = 'rejected', verified_at = NOW(), verification_notes = :notes
            WHERE id = :id
        ")->execute(['notes' => $notes, 'id' => $id]);

        logActivity('rejected', 'subscription_payment', $id, ['notes' => $notes]);
        jsonResponse(['success' => true, 'message' => __('adm_payment_rejected', 'Payment rejected')]);
        break;

    // ============================
    // PAYMENTS — STATS
    // ============================
    case 'payment_stats':
        $pendingCount = (int)$central->query("SELECT COUNT(*) FROM subscription_payments WHERE status = 'pending'")->fetchColumn();
        $pendingAmt = (float)$central->query("SELECT COALESCE(SUM(amount), 0) FROM subscription_payments WHERE status = 'pending'")->fetchColumn();
        $confirmedCount = (int)$central->query("SELECT COUNT(*) FROM subscription_payments WHERE status = 'confirmed'")->fetchColumn();
        $confirmedAmt = (float)$central->query("SELECT COALESCE(SUM(amount), 0) FROM subscription_payments WHERE status = 'confirmed'")->fetchColumn();
        $rejectedCount = (int)$central->query("SELECT COUNT(*) FROM subscription_payments WHERE status = 'rejected'")->fetchColumn();
        $thisMonthAmt = (float)$central->query("
            SELECT COALESCE(SUM(amount), 0) FROM subscription_payments
            WHERE status = 'confirmed' AND MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())
        ")->fetchColumn();

        jsonResponse(['success' => true, 'data' => [
            'pending_count' => $pendingCount, 'pending_amount' => $pendingAmt,
            'confirmed_count' => $confirmedCount, 'confirmed_amount' => $confirmedAmt,
            'rejected_count' => $rejectedCount, 'this_month_amount' => $thisMonthAmt
        ]]);
        break;

    // ============================
    // DISCOUNTS — APPLY / UPDATE
    // ============================
    case 'apply_discount':
        $id = (int)($_POST['id'] ?? 0);
        $discountType = trim($_POST['discount_type'] ?? 'none');
        $discountValue = (float)($_POST['discount_value'] ?? 0);

        if (!in_array($discountType, ['none', 'percentage', 'fixed'])) {
            jsonResponse(['success' => false, 'message' => 'Invalid discount type']);
        }

        $stmt = $central->prepare("SELECT * FROM subscriptions WHERE id = ?");
        $stmt->execute([$id]);
        $sub = $stmt->fetch();
        if (!$sub) jsonResponse(['success' => false, 'message' => 'Subscriber not found'], 404);

        if ($discountType === 'none') {
            $discountValue = 0;
        }

        // Recalculate billing
        $billing = calculateBilling($sub['unit_count'], $sub['tier'], $discountType, $discountValue, $sub['currency']);

        $central->prepare("
            UPDATE subscriptions
            SET discount_type = :dtype, discount_value = :dval,
                discount_amount = :damt, net_amount = :net, updated_at = NOW()
            WHERE id = :id
        ")->execute([
            'dtype' => $discountType,
            'dval' => $discountValue,
            'damt' => $billing['discount_amount'],
            'net' => $billing['net_amount'],
            'id' => $id
        ]);

        logActivity('updated', 'subscription_discount', $id, [
            'discount_type' => $discountType, 'discount_value' => $discountValue
        ]);
        jsonResponse(['success' => true, 'message' => __('adm_discount_applied', 'Discount applied successfully'), 'billing' => $billing]);
        break;

    // ============================
    // ANNOUNCEMENTS — LIST
    // ============================
    case 'list_announcements':
        $stmt = $central->query("SELECT * FROM system_announcements ORDER BY created_at DESC LIMIT 50");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // ANNOUNCEMENTS — CREATE / UPDATE
    // ============================
    case 'save_announcement':
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $type = trim($_POST['type'] ?? 'info');
        $target = trim($_POST['target'] ?? 'all');
        $isActive = ($_POST['is_active'] ?? '1') === '1' ? 1 : 0;
        $startDate = trim($_POST['start_date'] ?? '') ?: null;
        $endDate = trim($_POST['end_date'] ?? '') ?: null;

        if (!$title || !$message) {
            jsonResponse(['success' => false, 'message' => __('adm_title_message_required', 'Title and message are required')]);
        }

        if ($id > 0) {
            $central->prepare("
                UPDATE system_announcements
                SET title = :title, message = :msg, type = :type, target = :target,
                    is_active = :active, start_date = :start, end_date = :end, updated_at = NOW()
                WHERE id = :id
            ")->execute([
                'title' => $title, 'msg' => $message, 'type' => $type, 'target' => $target,
                'active' => $isActive, 'start' => $startDate, 'end' => $endDate, 'id' => $id
            ]);
            $msg = __('adm_announcement_updated', 'Announcement updated');
        } else {
            $central->prepare("
                INSERT INTO system_announcements (title, message, type, target, is_active, start_date, end_date, created_by)
                VALUES (:title, :msg, :type, :target, :active, :start, :end, :by)
            ")->execute([
                'title' => $title, 'msg' => $message, 'type' => $type, 'target' => $target,
                'active' => $isActive, 'start' => $startDate, 'end' => $endDate,
                'by' => getUserData('name')
            ]);
            $id = (int)$central->lastInsertId();
            $msg = __('adm_announcement_created', 'Announcement created');
        }

        logActivity($id ? 'updated' : 'created', 'system_announcement', $id);
        jsonResponse(['success' => true, 'message' => $msg]);
        break;

    // ============================
    // ANNOUNCEMENTS — DELETE
    // ============================
    case 'delete_announcement':
        $id = (int)($_POST['id'] ?? 0);
        $central->prepare("DELETE FROM system_announcements WHERE id = ?")->execute([$id]);
        logActivity('deleted', 'system_announcement', $id);
        jsonResponse(['success' => true, 'message' => __('adm_announcement_deleted', 'Announcement deleted')]);
        break;

    // ============================
    // ANNOUNCEMENTS — TOGGLE
    // ============================
    case 'toggle_announcement':
        $id = (int)($_POST['id'] ?? 0);
        $central->prepare("UPDATE system_announcements SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?")->execute([$id]);
        jsonResponse(['success' => true, 'message' => __('adm_announcement_toggled', 'Announcement status toggled')]);
        break;

    // ============================
    // GLOBAL SETTINGS — GET
    // ============================
    case 'get_settings':
        $keys = [
            'maintenance_mode', 'maintenance_message',
            'feature_new_registrations', 'feature_payment_gateway', 'feature_whatsapp_api',
            'admin_email', 'admin_whatsapp',
            'pricing_basic_rate', 'pricing_professional_rate', 'pricing_enterprise_rate',
            'pricing_trial_days', 'system_version'
        ];

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $central->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);

        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        jsonResponse(['success' => true, 'data' => $settings]);
        break;

    // ============================
    // GLOBAL SETTINGS — SAVE
    // ============================
    case 'save_settings':
        $allowedKeys = [
            'maintenance_mode', 'maintenance_message',
            'feature_new_registrations', 'feature_payment_gateway', 'feature_whatsapp_api',
            'admin_email', 'admin_whatsapp',
            'pricing_basic_rate', 'pricing_professional_rate', 'pricing_enterprise_rate',
            'pricing_trial_days'
        ];

        $updated = 0;
        foreach ($allowedKeys as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt = $central->prepare("
                    INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = ?
                ");
                $stmt->execute([$key, $val, $val]);
                $updated++;
            }
        }

        logActivity('updated', 'system_settings', 0, ['keys_updated' => $updated]);
        jsonResponse(['success' => true, 'message' => __('adm_settings_saved', 'Global settings saved')]);
        break;

    // ============================
    // EXPORT SUBSCRIBERS CSV
    // ============================
    case 'export_subscribers':
        $tier = trim($_GET['tier'] ?? '');
        $status = trim($_GET['status'] ?? '');

        $where = "1=1";
        $params = [];
        if ($tier) { $where .= " AND s.tier = ?"; $params[] = $tier; }
        if ($status) { $where .= " AND s.status = ?"; $params[] = $status; }

        $stmt = $central->prepare("
            SELECT s.id, s.company_name, s.owner_name, s.owner_phone, s.owner_email,
                   s.tenant_slug, s.tier, s.unit_count, s.rate_per_unit, s.currency,
                   s.discount_type, s.discount_value, s.gross_amount, s.discount_amount, s.net_amount,
                   s.start_date, s.end_date, s.status, s.created_at
            FROM subscriptions s
            WHERE $where
            ORDER BY s.created_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="subscribers_' . date('Y-m-d') . '.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['ID', 'Company', 'Owner', 'Phone', 'Email', 'Slug', 'Tier', 'Units',
                       'Rate/Unit', 'Currency', 'Discount Type', 'Discount Value', 'Gross',
                       'Discount', 'Net', 'Start', 'End', 'Status', 'Created']);
        foreach ($rows as $row) {
            fputcsv($fp, array_values($row));
        }
        fclose($fp);
        exit;

    // ============================
    // EXPORT PAYMENTS CSV
    // ============================
    case 'export_payments':
        $status = trim($_GET['status'] ?? '');

        $where = "1=1";
        $params = [];
        if ($status) { $where .= " AND p.status = ?"; $params[] = $status; }

        $stmt = $central->prepare("
            SELECT p.id, s.company_name, s.owner_name, s.owner_phone, s.tier,
                   p.amount, p.currency, p.payment_date, p.payment_method, p.reference_number,
                   p.period_from, p.period_to, p.status, p.confirmed_by, p.confirmed_at,
                   p.verification_notes, p.created_at
            FROM subscription_payments p
            LEFT JOIN subscriptions s ON p.subscription_id = s.id
            WHERE $where
            ORDER BY p.created_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="payments_' . date('Y-m-d') . '.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['ID', 'Company', 'Owner', 'Phone', 'Tier', 'Amount', 'Currency',
                       'Payment Date', 'Method', 'Reference', 'Period From', 'Period To',
                       'Status', 'Confirmed By', 'Confirmed At', 'Notes', 'Created']);
        foreach ($rows as $row) {
            fputcsv($fp, array_values($row));
        }
        fclose($fp);
        exit;

    // ============================
    // DROPDOWNS
    // ============================
    case 'dropdowns':
        $tiers = ['basic' => 'Basic', 'professional' => 'Professional', 'enterprise' => 'Enterprise'];
        $statuses = ['active' => 'Active', 'trial' => 'Trial', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled', 'expired' => 'Expired'];
        jsonResponse(['success' => true, 'data' => ['tiers' => $tiers, 'statuses' => $statuses]]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}
