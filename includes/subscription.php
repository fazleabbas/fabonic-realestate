<?php
/**
 * FIS Real Estate — Subscription Management Functions
 * Queries the central DB (fiscotz_propmanage) for subscription status.
 * Included by tenant application code.
 */

// ============================================================
// Central DB Connection
// ============================================================

/**
 * Get a PDO connection to the central database
 */
/**
 * Whether the last central-DB access failed (connection or query).
 * Lets callers tell "this tenant has no subscription" apart from
 * "we could not reach the central database" — the two must not be
 * treated the same, or an outage locks every tenant out.
 */
function centralDbFailed($set = null) {
    static $failed = false;
    if ($set !== null) {
        $failed = (bool) $set;
    }
    return $failed;
}

function getCentralDb() {
    static $centralConn = null;

    if ($centralConn !== null) {
        return $centralConn;
    }

    // One failed connection per request is enough — a page calls this several
    // times, and retrying a dead/timing-out central DB would stall every load.
    if (centralDbFailed()) {
        return null;
    }

    try {
        // Tenant instances read the central DB with dedicated credentials
        // (their own DB user has no access to it). The master has no
        // CENTRAL_DB_USER defined, so it falls back to its own account user.
        $centralUser = defined('CENTRAL_DB_USER') ? CENTRAL_DB_USER : DB_USER;
        $centralPass = defined('CENTRAL_DB_PASS') ? CENTRAL_DB_PASS : DB_PASS;
        $centralConn = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
            $centralUser,
            $centralPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        return $centralConn;
    } catch (PDOException $e) {
        error_log("FIS RE Subscription: Central DB connection failed: " . $e->getMessage());
        centralDbFailed(true);
        return null;
    }
}

// ============================================================
// Tier Configuration
// ============================================================

/**
 * Get tier configuration array
 */
function getTierConfig($tier = null) {
    $tiers = [
        'basic' => [
            'name' => 'Basic',
            'rate' => TIER_BASIC_RATE,
            'features' => [
                'properties', 'units', 'tenants', 'leases', 'payments',
                'deposits', 'late_fees', 'inspections', 'roles_permissions',
                'audit_trail', 'data_export', 'dashboard', 'reports',
                'settings', 'multi_language', 'multi_currency', 'pwa_offline'
            ],
            'description' => 'Core property management features'
        ],
        'professional' => [
            'name' => 'Professional',
            'rate' => TIER_PROFESSIONAL_RATE,
            'features' => [
                'properties', 'units', 'tenants', 'leases', 'payments',
                'deposits', 'late_fees', 'inspections', 'roles_permissions',
                'audit_trail', 'data_export', 'dashboard', 'reports',
                'settings', 'multi_language', 'multi_currency', 'pwa_offline',
                'tenant_portal', 'automated_reminders', 'whatsapp_receipts',
                'lease_renewal_automation'
            ],
            'description' => 'Everything in Basic, plus tenant portal & automated reminders'
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'rate' => TIER_ENTERPRISE_RATE,
            'features' => [
                'properties', 'units', 'tenants', 'leases', 'payments',
                'deposits', 'late_fees', 'inspections', 'roles_permissions',
                'audit_trail', 'data_export', 'dashboard', 'reports',
                'settings', 'multi_language', 'multi_currency', 'pwa_offline',
                'tenant_portal', 'automated_reminders', 'whatsapp_receipts',
                'lease_renewal_automation',
                'utility_billing', 'maintenance', 'financial_reports',
                'vacancy_advertising', 'payment_gateway', 'priority_support'
            ],
            'description' => 'Everything in Professional, plus utilities, maintenance & financial reports'
        ]
    ];

    if ($tier) {
        return $tiers[$tier] ?? null;
    }
    return $tiers;
}

/**
 * Get rate per unit for a tier
 */
function getTierRate($tier) {
    $config = getTierConfig($tier);
    return $config ? $config['rate'] : TIER_BASIC_RATE;
}

/**
 * Check if a feature is available in a given tier
 */
function isTierFeature($feature, $tier) {
    $config = getTierConfig($tier);
    if (!$config) return false;
    return in_array($feature, $config['features']);
}

// ============================================================
// Subscription Queries
// ============================================================

/**
 * Get current subscription for this tenant from central DB
 */
function getSubscription() {
    $central = getCentralDb();
    if (!$central) return null;

    try {
        $stmt = $central->prepare("
            SELECT * FROM subscriptions
            WHERE tenant_slug = :slug
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(['slug' => TENANT_SLUG]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getSubscription error: " . $e->getMessage());
        centralDbFailed(true);
        return null;
    }
}

/**
 * Get subscription status with calculated fields
 */
function getSubscriptionStatus() {
    $subscription = getSubscription();

    if (!$subscription) {
        // Central DB unreachable is an OUR-side fault, not a lapsed customer.
        // Fail open (same stance as getUnitUsage) with a distinct message so a
        // database blip never locks a paying tenant out of its own data.
        if (centralDbFailed()) {
            return [
                'valid' => true,
                'status' => 'unverified',
                'tier' => 'professional',
                'message' => 'Subscription could not be verified right now. Access continues — please contact support if this persists.',
                'blocked' => false,
                'warning' => true,
                'is_trial' => false,
                'subscription' => null
            ];
        }

        return [
            'valid' => false,
            'status' => 'no_subscription',
            'tier' => 'basic',
            'message' => 'No active subscription found',
            'blocked' => true,
            'is_trial' => false,
            'subscription' => null
        ];
    }

    $today = new DateTime();
    $endDate = new DateTime($subscription['end_date']);
    $graceEndDate = clone $endDate;
    $graceEndDate->modify('+' . SUBSCRIPTION_GRACE_DAYS . ' days');

    $daysRemaining = (int) $today->diff($endDate)->format('%R%a');
    $graceDaysRemaining = (int) $today->diff($graceEndDate)->format('%R%a');
    $isTrial = ($subscription['status'] === 'trial');
    $isSuspended = ($subscription['status'] === 'suspended');
    $isCancelled = ($subscription['status'] === 'cancelled');

    $status = [
        'subscription' => $subscription,
        'tier' => $subscription['tier'],
        'tier_name' => getTierConfig($subscription['tier'])['name'] ?? ucfirst($subscription['tier']),
        'unit_count' => (int) $subscription['unit_count'],
        'rate_per_unit' => (float) $subscription['rate_per_unit'],
        'currency' => $subscription['currency'],
        'discount_type' => $subscription['discount_type'],
        'discount_value' => (float) $subscription['discount_value'],
        'gross_amount' => (float) $subscription['gross_amount'],
        'discount_amount' => (float) $subscription['discount_amount'],
        'net_amount' => (float) $subscription['net_amount'],
        'days_remaining' => $daysRemaining,
        'grace_days_remaining' => $graceDaysRemaining,
        'start_date' => $subscription['start_date'],
        'end_date' => $subscription['end_date'],
        'grace_end_date' => $graceEndDate->format('Y-m-d'),
        'next_billing_date' => $subscription['next_billing_date'],
        'is_trial' => $isTrial,
    ];

    if ($isCancelled) {
        $status['valid'] = false;
        $status['status'] = 'cancelled';
        $status['blocked'] = true;
        $status['message'] = 'Subscription has been cancelled.';
    } elseif ($isSuspended) {
        $status['valid'] = false;
        $status['status'] = 'suspended';
        $status['blocked'] = true;
        $status['message'] = 'Subscription is suspended. Please contact support or make a payment.';
    } elseif ($daysRemaining >= 0) {
        $status['valid'] = true;
        $status['status'] = $isTrial ? 'trial' : 'active';
        $status['blocked'] = false;

        if ($isTrial && $daysRemaining <= 3) {
            $status['warning'] = true;
            $status['message'] = "Trial expires in {$daysRemaining} day(s). Subscribe now to keep your data!";
        } elseif ($daysRemaining <= 7) {
            $status['warning'] = true;
            $status['message'] = "Subscription expires in {$daysRemaining} day(s). Please renew.";
        } else {
            $status['warning'] = false;
            $status['message'] = ($isTrial ? "Trial" : "Subscription") . " active. {$daysRemaining} days remaining.";
        }
    } elseif ($graceDaysRemaining >= 0) {
        $status['valid'] = true;
        $status['status'] = 'grace_period';
        $status['blocked'] = false;
        $status['warning'] = true;
        $status['message'] = "GRACE PERIOD: {$graceDaysRemaining} day(s) left. Pay immediately to avoid service interruption.";
    } else {
        $status['valid'] = false;
        $status['status'] = 'expired';
        $status['blocked'] = true;
        $status['message'] = 'Subscription expired. Please renew to continue using the system.';
    }

    return $status;
}

/**
 * Check if subscription is blocked
 */
function isSubscriptionBlocked() {
    $status = getSubscriptionStatus();
    return $status['blocked'];
}

/**
 * Enforce subscription — redirect to subscription page if blocked
 */
function enforceSubscription() {
    // Skip enforcement for subscription pages and auth pages
    $currentPath = $_SERVER['REQUEST_URI'] ?? '';
    $skipPaths = ['/modules/subscription/', '/modules/auth/', '/api/', '/ajax/'];
    foreach ($skipPaths as $skip) {
        if (strpos($currentPath, $skip) !== false) {
            return null;
        }
    }

    $status = getSubscriptionStatus();

    if ($status['blocked']) {
        header('Location: ' . BASE_URL . 'modules/subscription/status.php?blocked=1');
        exit;
    }

    return $status;
}

/**
 * Check if a feature is available in the current subscription tier
 */
function hasFeature($feature) {
    $status = getSubscriptionStatus();
    if ($status['blocked']) return false;
    return isTierFeature($feature, $status['tier']);
}

/**
 * Check if current tier meets the minimum required tier
 */
function hasTier($requiredTier) {
    $tierOrder = ['basic' => 1, 'professional' => 2, 'enterprise' => 3];
    $status = getSubscriptionStatus();
    if ($status['blocked']) return false;

    $currentLevel = $tierOrder[$status['tier']] ?? 0;
    $requiredLevel = $tierOrder[$requiredTier] ?? 0;

    return $currentLevel >= $requiredLevel;
}

// ============================================================
// Unit Quota Enforcement
// ============================================================

/**
 * Count units currently stored in this account's database.
 */
function getUnitCountUsed() {
    global $conn;
    try {
        if (isset($conn) && $conn instanceof PDO) {
            return (int) $conn->query("SELECT COUNT(*) FROM units")->fetchColumn();
        }
    } catch (Throwable $e) {
        error_log('getUnitCountUsed error: ' . $e->getMessage());
    }
    return 0;
}

/**
 * Unit usage vs the subscribed quota for the current account.
 *
 * @return array{used:int,limit:int,remaining:int,unlimited:bool,at_limit:bool}
 */
function getUnitUsage() {
    $used = getUnitCountUsed();
    $status = getSubscriptionStatus();
    $limit = (int) ($status['unit_count'] ?? 0);

    // Fail-open: a missing/zero quota (bad data, or the central DB being
    // unreachable) must never hard-lock unit creation for a real account.
    $unlimited = ($limit <= 0);
    $remaining = $unlimited ? PHP_INT_MAX : max(0, $limit - $used);

    return [
        'used'      => $used,
        'limit'     => $limit,
        'remaining' => $remaining,
        'unlimited' => $unlimited,
        'at_limit'  => (!$unlimited && $used >= $limit),
    ];
}

/**
 * Whether the account may create $n more unit(s) under its subscription quota.
 * The SaaS operator (master instance) is never limited.
 */
function canAddUnits($n = 1) {
    if (function_exists('isSaasOperator') && isSaasOperator()) {
        return true;
    }
    $usage = getUnitUsage();
    return $usage['unlimited'] || $usage['remaining'] >= $n;
}

// ============================================================
// Billing Calculations (with discount support)
// ============================================================

/**
 * Calculate billing amount with optional discount
 *
 * @param int $unitCount Number of units
 * @param string $tier Subscription tier
 * @param string $discountType 'none', 'percentage', or 'fixed'
 * @param float $discountValue Discount value
 * @param string $currency Currency code
 * @return array Billing breakdown
 */
function calculateBilling($unitCount, $tier, $discountType = 'none', $discountValue = 0, $currency = 'TZS') {
    $ratePerUnit = getTierRate($tier);
    $grossAmount = $unitCount * $ratePerUnit;

    $discountAmount = 0;
    if ($discountType === 'percentage' && $discountValue > 0) {
        $discountAmount = round($grossAmount * ($discountValue / 100), 2);
    } elseif ($discountType === 'fixed' && $discountValue > 0) {
        // Fixed discount is per unit
        $discountAmount = $unitCount * $discountValue;
    }

    // Ensure discount doesn't exceed gross
    $discountAmount = min($discountAmount, $grossAmount);
    $netAmount = $grossAmount - $discountAmount;

    return [
        'tier' => $tier,
        'tier_name' => getTierConfig($tier)['name'] ?? ucfirst($tier),
        'unit_count' => $unitCount,
        'rate_per_unit' => $ratePerUnit,
        'gross_amount' => $grossAmount,
        'discount_type' => $discountType,
        'discount_value' => $discountValue,
        'discount_amount' => $discountAmount,
        'net_amount' => $netAmount,
        'currency' => $currency,
        'billing_cycle' => 'monthly',
        'label_gross' => formatCurrency($grossAmount, $currency),
        'label_discount' => formatCurrency($discountAmount, $currency),
        'label_net' => formatCurrency($netAmount, $currency),
        'label_rate' => formatCurrency($ratePerUnit, $currency) . '/unit/month'
    ];
}

/**
 * Update subscription tier/units in central DB
 */
function updateSubscription($data) {
    $central = getCentralDb();
    if (!$central) return ['success' => false, 'error' => 'Cannot connect to central database'];

    try {
        $sub = getSubscription();
        if (!$sub) return ['success' => false, 'error' => 'No subscription found'];

        // Calculate new billing
        $discountType = $data['discount_type'] ?? $sub['discount_type'];
        $discountValue = $data['discount_value'] ?? $sub['discount_value'];
        $unitCount = $data['unit_count'] ?? $sub['unit_count'];
        $tier = $data['tier'] ?? $sub['tier'];

        $billing = calculateBilling($unitCount, $tier, $discountType, $discountValue, $sub['currency']);

        $stmt = $central->prepare("
            UPDATE subscriptions SET
                tier = :tier,
                unit_count = :unit_count,
                rate_per_unit = :rate,
                discount_type = :disc_type,
                discount_value = :disc_value,
                gross_amount = :gross,
                discount_amount = :disc_amount,
                net_amount = :net,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'tier' => $tier,
            'unit_count' => $unitCount,
            'rate' => $billing['rate_per_unit'],
            'disc_type' => $discountType,
            'disc_value' => $discountValue,
            'gross' => $billing['gross_amount'],
            'disc_amount' => $billing['discount_amount'],
            'net' => $billing['net_amount'],
            'id' => $sub['id']
        ]);

        return ['success' => true, 'billing' => $billing];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Activate subscription (from trial or after payment confirmation)
 */
function activateSubscription($tier, $unitCount, $months = 1) {
    $central = getCentralDb();
    if (!$central) return ['success' => false, 'error' => 'Cannot connect to central database'];

    try {
        $sub = getSubscription();
        if (!$sub) return ['success' => false, 'error' => 'No subscription found'];

        $discountType = $sub['discount_type'];
        $discountValue = (float) $sub['discount_value'];

        $billing = calculateBilling($unitCount, $tier, $discountType, $discountValue, $sub['currency']);

        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$months} months"));
        $nextBilling = $endDate;

        $stmt = $central->prepare("
            UPDATE subscriptions SET
                tier = :tier,
                unit_count = :unit_count,
                rate_per_unit = :rate,
                gross_amount = :gross,
                discount_amount = :disc_amount,
                net_amount = :net,
                start_date = :start_date,
                end_date = :end_date,
                next_billing_date = :next_billing,
                status = 'active',
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'tier' => $tier,
            'unit_count' => $unitCount,
            'rate' => $billing['rate_per_unit'],
            'gross' => $billing['gross_amount'],
            'disc_amount' => $billing['discount_amount'],
            'net' => $billing['net_amount'],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'next_billing' => $nextBilling,
            'id' => $sub['id']
        ]);

        return ['success' => true, 'billing' => $billing, 'end_date' => $endDate];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================================
// Payment Functions
// ============================================================

/**
 * Submit a subscription payment record
 */
function submitPayment($amount, $paymentMethod, $referenceNumber, $periodFrom, $periodTo, $notes = '') {
    $central = getCentralDb();
    if (!$central) return ['success' => false, 'error' => 'Cannot connect to central database'];

    try {
        $sub = getSubscription();
        if (!$sub) return ['success' => false, 'error' => 'No subscription found'];

        $stmt = $central->prepare("
            INSERT INTO subscription_payments
            (subscription_id, amount, currency, payment_date, payment_method, reference_number, period_from, period_to, status, notes)
            VALUES (:sub_id, :amount, :currency, CURDATE(), :method, :ref, :from, :to, 'pending', :notes)
        ");

        $stmt->execute([
            'sub_id' => $sub['id'],
            'amount' => $amount,
            'currency' => $sub['currency'],
            'method' => $paymentMethod,
            'ref' => $referenceNumber,
            'from' => $periodFrom,
            'to' => $periodTo,
            'notes' => $notes
        ]);

        return ['success' => true, 'payment_id' => $central->lastInsertId()];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get payment history for this tenant
 */
function getPaymentHistory($limit = 20) {
    $central = getCentralDb();
    if (!$central) return [];

    try {
        $sub = getSubscription();
        if (!$sub) return [];

        $stmt = $central->prepare("
            SELECT * FROM subscription_payments
            WHERE subscription_id = :sub_id
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':sub_id', $sub['id'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get payment instructions for manual payment
 */
function getPaymentInstructions($amount, $currency = 'TZS') {
    return [
        'method' => PAYMENT_AIRTEL_NAME,
        'number' => PAYMENT_AIRTEL_NUMBER,
        'amount' => $amount,
        'currency' => $currency,
        'instructions' => [
            "1. Dial *150*60#",
            "2. Select 'Send Money'",
            "3. Enter number: " . PAYMENT_AIRTEL_NUMBER,
            "4. Enter amount: {$currency} " . number_format($amount),
            "5. Confirm the transaction",
            "6. Save the transaction reference",
            "7. Enter the reference below for confirmation"
        ]
    ];
}

/**
 * Format subscription amount with currency
 */
function formatSubscriptionAmount($amount, $currency = null) {
    $sub = getSubscription();
    $currency = $currency ?? ($sub['currency'] ?? DEFAULT_CURRENCY);
    return $currency . ' ' . number_format($amount, 0);
}
