<?php
/**
 * FIS Real Estate — Sales Agent Integration API (Part 22)
 * Handles: commission config, agent management, referral tracking,
 * commission processing, stats, CSV exports.
 * Admin only — runs against central DB (fiscotz_propmanage).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

// SaaS operator only (master instance) — sales agents live in the central DB
if (!isSaasOperator()) {
    jsonResponse(['success' => false, 'message' => __('sa_admin_only', 'Admin access required')], 403);
}

// Get central DB connection
$central = getCentralDb();
if (!$central) {
    jsonResponse(['success' => false, 'message' => 'Central database connection failed'], 500);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // SETTINGS — GET
    // ============================
    case 'get_settings':
        $keys = [
            're_commission_enabled', 're_commission_type',
            're_commission_direct_m1', 're_commission_direct_m2',
            're_commission_direct_m3', 're_commission_direct_recurring',
            're_commission_override_m1', 're_commission_override_m2',
            're_commission_override_m3', 're_commission_override_recurring',
            're_min_payout', 're_max_commission_months'
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
    // SETTINGS — SAVE
    // ============================
    case 'save_settings':
        $allowedKeys = [
            're_commission_enabled', 're_commission_type',
            're_commission_direct_m1', 're_commission_direct_m2',
            're_commission_direct_m3', 're_commission_direct_recurring',
            're_commission_override_m1', 're_commission_override_m2',
            're_commission_override_m3', 're_commission_override_recurring',
            're_min_payout', 're_max_commission_months'
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

        logActivity('updated', 'sales_agent_settings', 0, ['keys_updated' => $updated]);
        jsonResponse(['success' => true, 'message' => __('sa_settings_saved', 'Commission settings saved')]);
        break;

    // ============================
    // AGENTS — LIST
    // ============================
    case 'list_agents':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $perPage = RECORDS_PER_PAGE;

        $where = "1=1";
        $params = [];

        if ($search) {
            $where .= " AND (a.name LIKE :s1 OR a.phone LIKE :s2 OR a.email LIKE :s3 OR a.referral_code LIKE :s4)";
            $params['s1'] = "%$search%";
            $params['s2'] = "%$search%";
            $params['s3'] = "%$search%";
            $params['s4'] = "%$search%";
        }
        if ($status) {
            $where .= " AND a.status = :status";
            $params['status'] = $status;
        }

        $countStmt = $central->prepare("SELECT COUNT(*) FROM sales_agents a WHERE $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $totalPages = max(1, ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT a.*,
                       (SELECT COUNT(*) FROM agent_referrals_re r WHERE r.agent_id = a.id) AS re_referrals,
                       (SELECT COUNT(*) FROM agent_referrals_re r WHERE r.agent_id = a.id AND r.status = 'converted') AS re_converted,
                       (SELECT COALESCE(SUM(c.amount), 0) FROM agent_commissions_re c WHERE c.agent_id = a.id) AS re_total_earned,
                       (SELECT COUNT(*) FROM sales_agents s WHERE s.parent_agent_id = a.id) AS team_size
                FROM sales_agents a
                WHERE $where
                ORDER BY a.created_at DESC
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
    // AGENTS — GET
    // ============================
    case 'get_agent':
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $central->prepare("
            SELECT a.*,
                   (SELECT COUNT(*) FROM agent_referrals_re r WHERE r.agent_id = a.id) AS re_referrals,
                   (SELECT COUNT(*) FROM agent_referrals_re r WHERE r.agent_id = a.id AND r.status = 'converted') AS re_converted,
                   (SELECT COALESCE(SUM(c.amount), 0) FROM agent_commissions_re c WHERE c.agent_id = a.id) AS re_total_earned,
                   p.name AS parent_name
            FROM sales_agents a
            LEFT JOIN sales_agents p ON a.parent_agent_id = p.id
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        $agent = $stmt->fetch();
        if (!$agent) {
            jsonResponse(['success' => false, 'message' => 'Agent not found'], 404);
        }
        // Mask password
        unset($agent['password_hash']);
        jsonResponse(['success' => true, 'data' => $agent]);
        break;

    // ============================
    // REFERRALS — LIST
    // ============================
    case 'list_referrals':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $agentId = (int)($_GET['agent_id'] ?? 0);
        $status = trim($_GET['status'] ?? '');
        $search = trim($_GET['search'] ?? '');
        $perPage = RECORDS_PER_PAGE;

        $where = "1=1";
        $params = [];

        if ($agentId) {
            $where .= " AND r.agent_id = :aid";
            $params['aid'] = $agentId;
        }
        if ($status) {
            $where .= " AND r.status = :status";
            $params['status'] = $status;
        }
        if ($search) {
            $where .= " AND (r.customer_name LIKE :s1 OR r.customer_phone LIKE :s2 OR r.customer_company LIKE :s3 OR a.name LIKE :s4)";
            $params['s1'] = "%$search%";
            $params['s2'] = "%$search%";
            $params['s3'] = "%$search%";
            $params['s4'] = "%$search%";
        }

        $countStmt = $central->prepare("
            SELECT COUNT(*) FROM agent_referrals_re r
            LEFT JOIN sales_agents a ON r.agent_id = a.id
            WHERE $where
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $totalPages = max(1, ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT r.*, a.name AS agent_name, a.phone AS agent_phone,
                       t.full_name AS trial_name, t.company_name, t.phone AS trial_phone,
                       s.tier, s.status AS sub_status, s.net_amount, s.unit_count
                FROM agent_referrals_re r
                LEFT JOIN sales_agents a ON r.agent_id = a.id
                LEFT JOIN trial_requests t ON r.trial_request_id = t.id
                LEFT JOIN subscriptions s ON r.subscription_id = s.id
                WHERE $where
                ORDER BY r.created_at DESC
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
    // COMMISSIONS — LIST
    // ============================
    case 'list_commissions':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $agentId = (int)($_GET['agent_id'] ?? 0);
        $status = trim($_GET['status'] ?? '');
        $type = trim($_GET['type'] ?? '');
        $perPage = RECORDS_PER_PAGE;

        $where = "1=1";
        $params = [];

        if ($agentId) {
            $where .= " AND c.agent_id = :aid";
            $params['aid'] = $agentId;
        }
        if ($status) {
            $where .= " AND c.status = :status";
            $params['status'] = $status;
        }
        if ($type) {
            $where .= " AND c.commission_type = :type";
            $params['type'] = $type;
        }

        $countStmt = $central->prepare("SELECT COUNT(*) FROM agent_commissions_re c WHERE $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $totalPages = max(1, ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT c.*, a.name AS agent_name, a.phone AS agent_phone,
                       r.customer_name, r.customer_company,
                       s.tier, s.net_amount AS subscription_amount, s.unit_count
                FROM agent_commissions_re c
                LEFT JOIN sales_agents a ON c.agent_id = a.id
                LEFT JOIN agent_referrals_re r ON c.referral_id = r.id
                LEFT JOIN subscriptions s ON c.subscription_id = s.id
                WHERE $where
                ORDER BY c.created_at DESC
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
    // PROCESS COMMISSIONS (manual trigger)
    // ============================
    case 'process_commissions':
        $result = processRECommissions($central);
        logActivity('created', 'commission_processing', 0, $result);
        jsonResponse(['success' => true, 'message' => sprintf(
            __('sa_commissions_processed', 'Commissions processed: %d credited, %d skipped, %d errors'),
            $result['credited'], $result['skipped'], $result['errors']
        ), 'data' => $result]);
        break;

    // ============================
    // MARK COMMISSION PAID
    // ============================
    case 'mark_paid':
        $id = (int)($_POST['id'] ?? 0);
        $reference = trim($_POST['payment_reference'] ?? '');

        $stmt = $central->prepare("SELECT * FROM agent_commissions_re WHERE id = ?");
        $stmt->execute([$id]);
        $comm = $stmt->fetch();
        if (!$comm) jsonResponse(['success' => false, 'message' => 'Commission not found'], 404);
        if ($comm['status'] === 'paid') jsonResponse(['success' => false, 'message' => __('sa_already_paid')]);

        $central->prepare("
            UPDATE agent_commissions_re SET status = 'paid', paid_at = NOW(), payment_reference = ? WHERE id = ?
        ")->execute([$reference ?: null, $id]);

        logActivity('updated', 'agent_commission_re', $id, ['status' => 'paid']);
        jsonResponse(['success' => true, 'message' => __('sa_commission_marked_paid', 'Commission marked as paid')]);
        break;

    // ============================
    // STATS
    // ============================
    case 'stats':
        $totalAgents = (int)$central->query("SELECT COUNT(*) FROM sales_agents WHERE status = 'active'")->fetchColumn();
        $totalReferrals = (int)$central->query("SELECT COUNT(*) FROM agent_referrals_re")->fetchColumn();
        $convertedReferrals = (int)$central->query("SELECT COUNT(*) FROM agent_referrals_re WHERE status = 'converted'")->fetchColumn();
        $totalCommissions = (float)$central->query("SELECT COALESCE(SUM(amount), 0) FROM agent_commissions_re")->fetchColumn();
        $pendingCommissions = (float)$central->query("SELECT COALESCE(SUM(amount), 0) FROM agent_commissions_re WHERE status = 'pending'")->fetchColumn();
        $paidCommissions = (float)$central->query("SELECT COALESCE(SUM(amount), 0) FROM agent_commissions_re WHERE status = 'paid'")->fetchColumn();

        $thisMonthComm = (float)$central->query("
            SELECT COALESCE(SUM(amount), 0) FROM agent_commissions_re
            WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
        ")->fetchColumn();

        $activeSubscriptions = (int)$central->query("
            SELECT COUNT(*) FROM agent_referrals_re r
            INNER JOIN subscriptions s ON r.subscription_id = s.id
            WHERE r.status = 'converted' AND s.status IN ('active','trial')
        ")->fetchColumn();

        jsonResponse(['success' => true, 'data' => [
            'total_agents' => $totalAgents,
            'total_referrals' => $totalReferrals,
            'converted_referrals' => $convertedReferrals,
            'active_subscriptions' => $activeSubscriptions,
            'total_commissions' => $totalCommissions,
            'pending_commissions' => $pendingCommissions,
            'paid_commissions' => $paidCommissions,
            'this_month_commissions' => $thisMonthComm
        ]]);
        break;

    // ============================
    // EXPORT COMMISSIONS CSV
    // ============================
    case 'export_commissions':
        $agentId = (int)($_GET['agent_id'] ?? 0);
        $status = trim($_GET['status'] ?? '');

        $where = "1=1";
        $params = [];
        if ($agentId) { $where .= " AND c.agent_id = ?"; $params[] = $agentId; }
        if ($status) { $where .= " AND c.status = ?"; $params[] = $status; }

        $stmt = $central->prepare("
            SELECT c.id, a.name AS agent, a.phone AS agent_phone,
                   r.customer_name, r.customer_company,
                   c.commission_type, c.month_number, c.base_amount, c.amount,
                   c.currency, c.subscription_billing, s.tier, s.unit_count,
                   c.status, c.paid_at, c.payment_reference, c.credited_at
            FROM agent_commissions_re c
            LEFT JOIN sales_agents a ON c.agent_id = a.id
            LEFT JOIN agent_referrals_re r ON c.referral_id = r.id
            LEFT JOIN subscriptions s ON c.subscription_id = s.id
            WHERE $where
            ORDER BY c.created_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="re_commissions_' . date('Y-m-d') . '.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['ID', 'Agent', 'Agent Phone', 'Customer', 'Company', 'Type', 'Month',
                       'Base Amount', 'Amount', 'Currency', 'Subscription Billing', 'Tier',
                       'Units', 'Status', 'Paid At', 'Reference', 'Credited At']);
        foreach ($rows as $row) {
            fputcsv($fp, array_values($row));
        }
        fclose($fp);
        exit;

    // ============================
    // EXPORT REFERRALS CSV
    // ============================
    case 'export_referrals':
        $agentId = (int)($_GET['agent_id'] ?? 0);

        $where = "1=1";
        $params = [];
        if ($agentId) { $where .= " AND r.agent_id = ?"; $params[] = $agentId; }

        $stmt = $central->prepare("
            SELECT r.id, a.name AS agent, a.phone AS agent_phone,
                   r.customer_name, r.customer_phone, r.customer_company,
                   r.status, r.subscription_month_count,
                   t.company_name AS trial_company, t.unit_count,
                   s.tier, s.status AS sub_status, s.net_amount,
                   r.last_commission_at, r.created_at
            FROM agent_referrals_re r
            LEFT JOIN sales_agents a ON r.agent_id = a.id
            LEFT JOIN trial_requests t ON r.trial_request_id = t.id
            LEFT JOIN subscriptions s ON r.subscription_id = s.id
            WHERE $where
            ORDER BY r.created_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="re_referrals_' . date('Y-m-d') . '.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['ID', 'Agent', 'Agent Phone', 'Customer', 'Customer Phone', 'Company',
                       'Status', 'Months Active', 'Trial Company', 'Units', 'Tier',
                       'Sub Status', 'Billing Amount', 'Last Commission', 'Referred At']);
        foreach ($rows as $row) {
            fputcsv($fp, array_values($row));
        }
        fclose($fp);
        exit;

    // ============================
    // DROPDOWNS
    // ============================
    case 'dropdowns':
        $agents = $central->query("SELECT id, name, phone, referral_code FROM sales_agents WHERE status = 'active' ORDER BY name")->fetchAll();
        jsonResponse(['success' => true, 'data' => ['agents' => $agents]]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Process RE commissions for all active referred subscriptions
 * Called by admin trigger or cron job
 */
function processRECommissions($central) {
    $result = ['credited' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];

    // Check if commissions are enabled
    $enabled = getCentralSetting($central, 're_commission_enabled', '0');
    if ($enabled !== '1') {
        $result['details'][] = 'Commissions are disabled';
        return $result;
    }

    $commType = getCentralSetting($central, 're_commission_type', 'fixed');
    $maxMonths = (int)getCentralSetting($central, 're_max_commission_months', '12');

    // Get all converted referrals with active subscriptions
    $stmt = $central->query("
        SELECT r.*, s.net_amount, s.unit_count, s.tier, s.status AS sub_status,
               a.parent_agent_id
        FROM agent_referrals_re r
        INNER JOIN subscriptions s ON r.subscription_id = s.id
        INNER JOIN sales_agents a ON r.agent_id = a.id
        WHERE r.status = 'converted'
        AND s.status IN ('active', 'trial')
        AND a.status = 'active'
    ");
    $referrals = $stmt->fetchAll();

    foreach ($referrals as $ref) {
        $nextMonth = $ref['subscription_month_count'] + 1;

        // Check max months limit
        if ($maxMonths > 0 && $nextMonth > $maxMonths) {
            $result['skipped']++;
            continue;
        }

        // Check if this month's commission already exists
        $checkStmt = $central->prepare("
            SELECT id FROM agent_commissions_re
            WHERE referral_id = ? AND month_number = ?
        ");
        $checkStmt->execute([$ref['id'], $nextMonth]);
        if ($checkStmt->fetch()) {
            $result['skipped']++;
            continue;
        }

        try {
            $central->beginTransaction();

            $billing = (float)$ref['net_amount'];

            // Calculate direct commission
            $directAmount = calculateCommission($central, 'direct', $nextMonth, $billing, $commType);
            if ($directAmount > 0) {
                creditCommission($central, $ref['agent_id'], $ref['id'], $ref['subscription_id'],
                    'direct', $nextMonth, $directAmount, $billing);
            }

            // Calculate override commission (parent agent)
            if ($ref['parent_agent_id']) {
                $overrideAmount = calculateCommission($central, 'override', $nextMonth, $billing, $commType);
                if ($overrideAmount > 0) {
                    creditCommission($central, $ref['parent_agent_id'], $ref['id'], $ref['subscription_id'],
                        'override', $nextMonth, $overrideAmount, $billing);
                }
            }

            // Update referral tracking
            $central->prepare("
                UPDATE agent_referrals_re
                SET subscription_month_count = ?, last_commission_at = NOW()
                WHERE id = ?
            ")->execute([$nextMonth, $ref['id']]);

            $central->commit();
            $result['credited']++;
        } catch (Exception $e) {
            $central->rollBack();
            $result['errors']++;
            $result['details'][] = "Referral #{$ref['id']}: " . $e->getMessage();
        }
    }

    return $result;
}

/**
 * Calculate commission amount based on month and type
 */
function calculateCommission($central, $roleType, $monthNumber, $billing, $commType) {
    $prefix = ($roleType === 'direct') ? 're_commission_direct' : 're_commission_override';

    if ($monthNumber <= 3) {
        $rateKey = $prefix . '_m' . $monthNumber;
    } else {
        $rateKey = $prefix . '_recurring';
    }

    $rate = (float)getCentralSetting($central, $rateKey, '0');
    if ($rate <= 0) return 0;

    if ($commType === 'percentage') {
        return round($billing * ($rate / 100), 2);
    }
    return $rate; // fixed amount
}

/**
 * Credit commission to agent wallet
 */
function creditCommission($central, $agentId, $referralId, $subscriptionId, $type, $month, $amount, $billing) {
    // Insert commission record
    $stmt = $central->prepare("
        INSERT INTO agent_commissions_re
        (agent_id, referral_id, subscription_id, commission_type, base_amount, amount, subscription_billing,
         currency, month_number, status, credited_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'TZS', ?, 'pending', NOW())
    ");
    $stmt->execute([$agentId, $referralId, $subscriptionId, $type, $amount, $amount, $billing, $month]);
    $commId = (int)$central->lastInsertId();

    // Update agent wallet balance
    $central->prepare("
        UPDATE sales_agents
        SET wallet_balance_re = wallet_balance_re + ?, total_earned_re = total_earned_re + ?
        WHERE id = ?
    ")->execute([$amount, $amount, $agentId]);

    // Get new balance
    $balStmt = $central->prepare("SELECT wallet_balance_re FROM sales_agents WHERE id = ?");
    $balStmt->execute([$agentId]);
    $newBalance = (float)$balStmt->fetchColumn();

    // Record wallet transaction
    $central->prepare("
        INSERT INTO agent_wallet_transactions_re
        (agent_id, type, amount, balance_after, reference_type, reference_id, description)
        VALUES (?, 'credit', ?, ?, 'commission', ?, ?)
    ")->execute([
        $agentId, $amount, $newBalance, $commId,
        ucfirst($type) . " commission — Month $month"
    ]);
}

/**
 * Get a central DB system setting
 */
function getCentralSetting($central, $key, $default = '') {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];

    try {
        $stmt = $central->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = $row ? $row['setting_value'] : $default;
        return $cache[$key];
    } catch (Exception $e) {
        return $default;
    }
}
