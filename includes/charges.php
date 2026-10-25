<?php
/**
 * FIS Real Estate — Charges, allocation, floats and automatic postings
 * (Part 31, Phase 2)
 *
 * This is where the accrual basis actually lives. Rent is CHARGED when it falls
 * due, which is what makes "what tenants owe" a stored fact rather than a
 * calculation, and what lets a payment settle specific periods.
 *
 * Everything here is guarded by the `ledger_auto_post` setting. Turning it off
 * stops the ledger being written without stopping the app — a deliberate escape
 * hatch, since these hooks sit inside the existing payment and deposit paths.
 */

require_once __DIR__ . '/ledger.php';

// ============================================================
// Settings
// ============================================================

/** Is automatic posting switched on for this tenant? */
function ledgerAutoPost() {
    return getSetting('ledger_auto_post', '1') === '1';
}

/**
 * Payment methods that put cash in a person's hands.
 *
 * Cash only, by default. A cheque is not cash: it is paid into a bank, and
 * treating it as a float meant it sat in someone's hands in the books until a
 * remittance was filed, which is not what happens (part 38 — it used to default
 * to 'cash,cheque'). A tenant that genuinely holds cheques can put it back by
 * setting float_payment_methods to 'cash,cheque'.
 */
function floatPaymentMethods() {
    $raw = getSetting('float_payment_methods', 'cash');
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

/** Does this payment method raise the collector's float? */
function methodCreatesFloat($method) {
    return in_array($method, floatPaymentMethods(), true);
}

// ============================================================
// Float accounts
// ============================================================

/**
 * The float account for a user in a currency, created on first use.
 *
 * Created rather than required, because a collector should never be blocked
 * from recording a payment by a missing account.
 */
function floatAccountFor($userId, $currency) {
    global $conn;
    $userId = (int) $userId;
    if (!$userId) return null;

    $stmt = $conn->prepare("SELECT * FROM accounts
                            WHERE system_key = 'float' AND user_id = ? AND currency = ?");
    $stmt->execute([$userId, $currency]);
    $account = $stmt->fetch();
    if ($account) return $account;

    $nameStmt = $conn->prepare('SELECT full_name FROM users WHERE id = ?');
    $nameStmt->execute([$userId]);
    $fullName = $nameStmt->fetchColumn() ?: ('User ' . $userId);

    try {
        $ins = $conn->prepare("
            INSERT INTO accounts (code, name, type, subtype, currency, user_id, is_system, system_key, description)
            VALUES (:code, :name, 'asset', 'float', :cur, :uid, 1, 'float', :desc)
        ");
        $ins->execute([
            'code' => '1200-' . $userId,
            'name' => sprintf(__('flt_account_name', 'Float — %s'), $fullName),
            'cur'  => $currency,
            'uid'  => $userId,
            'desc' => __('flt_account_desc', 'Cash held by this person on the company behalf'),
        ]);
    } catch (PDOException $e) {
        // A concurrent request may have created it between our check and insert.
    }

    $stmt->execute([$userId, $currency]);
    return $stmt->fetch() ?: null;
}

/** Every float account, with its derived balance. For the float statement. */
function floatAccounts($userId = null) {
    global $conn;
    // Personal floats and shared cash boxes both hold cash and both get banked
    // the same way, so the Floats page shows both (part 38).
    $sql = "SELECT a.*, u.full_name AS owner_name
            FROM accounts a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.type = 'asset'
              AND (a.system_key = 'float' OR a.subtype = 'cash')
              AND a.status = 'active'";
    $params = [];
    // A collector sees their own float — but a shared box belongs to nobody, so
    // narrowing by user must never hide one, or they could not bank it.
    if ($userId) {
        $sql .= " AND (a.subtype = 'cash' OR a.user_id = ?)";
        $params[] = (int) $userId;
    }
    $sql .= " ORDER BY (a.system_key = 'float') DESC, u.full_name, a.name, a.currency";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['balance'] = ledgerAccountBalance($r['id']);
        $r['unremitted'] = $r['balance'];
    }
    return $rows;
}


/**
 * Accounts that can hold cash in someone's hands (part 38).
 *
 * Two kinds now count as cash-holding, and the rest of the system stops caring
 * which it is dealing with:
 *
 *   - a personal float  (system_key = 'float', one per person per currency,
 *                        created automatically on first receipt)
 *   - a shared cash box (subtype = 'cash', created by hand in the chart of
 *                        accounts, belonging to no one in particular)
 *
 * Both can receive a cash payment and both can be banked by remittance. The
 * difference is accountability: a float names the person holding the money, a
 * shared box does not. That is a deliberate choice by whoever set it up.
 */
function cashHoldingAccounts($currency = null) {
    global $conn;
    $sql = "SELECT a.*, u.full_name AS owner_name
            FROM accounts a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.type = 'asset'
              AND (a.system_key = 'float' OR a.subtype = 'cash')
              AND a.status = 'active'";
    $params = [];
    if ($currency !== null) { $sql .= ' AND a.currency = ?'; $params[] = $currency; }
    $sql .= ' ORDER BY (a.system_key = \'float\') DESC, u.full_name, a.name';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Is this account one that can hold cash — a float or a shared box? */
function isCashHoldingAccount($account) {
    if (!$account || $account['type'] !== 'asset') return false;
    return $account['system_key'] === 'float' || $account['subtype'] === 'cash';
}

/**
 * The account a receipt should post to.
 *
 * An explicit choice wins, but only if it is real, active, in the right
 * currency and of a kind that suits the payment method — a cash receipt cannot
 * be dropped straight into a bank account, because that would assert the money
 * reached the bank when nobody has banked it.
 *
 * With no valid choice this falls back to precisely the pre-part-38 behaviour,
 * which is what keeps every existing row and every untouched form working.
 *
 * @param int|null $chosenId  accounts.id the user picked, or null
 * @param string   $method    payment_method on the record
 * @param string   $currency
 * @param int|null $userId    who recorded it, for the personal-float fallback
 * @return array|null
 */
function receiptAccountFor($chosenId, $method, $currency, $userId = null) {
    $wantsCash = methodCreatesFloat($method);

    if ($chosenId) {
        $chosen = ledgerAccount((int) $chosenId);
        if ($chosen
            && $chosen['status'] === 'active'
            && $chosen['currency'] === $currency
            && ($wantsCash ? isCashHoldingAccount($chosen)
                           : ($chosen['type'] === 'asset' && $chosen['subtype'] === 'bank'))) {
            return $chosen;
        }
        // Wrong currency, archived, or the wrong kind for this method. Fall
        // through rather than refuse: the receipt itself is not in doubt, and
        // silently posting nowhere would be worse than posting to the default.
        error_log(sprintf('receiptAccountFor: ignoring account #%d for a %s receipt in %s',
            (int) $chosenId, $method, $currency));
    }

    return $wantsCash
        ? floatAccountFor($userId ?: getUserId(), $currency)
        : ledgerSystemAccountFor('bank_default', $currency);
}

// ============================================================
// Charges
// ============================================================

/**
 * Create a charge and, when auto-posting is on, recognise the income:
 *   Dr Accounts Receivable   Cr <income account>
 *
 * Idempotent for rent: the unique index on (lease, type, period, source) means
 * a repeated generator run updates nothing rather than double-charging.
 */
function chargeCreate($data) {
    global $conn;

    $type     = $data['type'] ?? 'rent';
    $currency = $data['currency'] ?? DEFAULT_CURRENCY;
    $amount   = round((float) ($data['amount'] ?? 0), 2);
    if ($amount <= 0) return ['success' => false, 'id' => null, 'errors' => ['Charge amount must be positive']];

    $incomeKeys = [
        'rent'     => 'rent_income',
        'utility'  => 'utility_income',
        'late_fee' => 'late_fee_income',
        'other'    => 'rent_income',
    ];

    $ownTransaction = !$conn->inTransaction();
    if ($ownTransaction) $conn->beginTransaction();

    try {
        $stmt = $conn->prepare("
            INSERT INTO charges (type, lease_id, tenant_id, unit_id, property_id, period_from, period_to,
                                 due_date, amount, currency, description, source_type, source_id, created_by)
            VALUES (:type, :lease, :tenant, :unit, :prop, :pfrom, :pto,
                    :due, :amt, :cur, :desc, :stype, :sid, :by)
        ");
        $stmt->execute([
            'type'   => $type,
            'lease'  => $data['lease_id'] ?? null,
            'tenant' => (int) $data['tenant_id'],
            'unit'   => $data['unit_id'] ?? null,
            'prop'   => $data['property_id'] ?? null,
            'pfrom'  => $data['period_from'] ?? null,
            'pto'    => $data['period_to'] ?? null,
            'due'    => $data['due_date'] ?? date('Y-m-d'),
            'amt'    => $amount,
            'cur'    => $currency,
            'desc'   => $data['description'] ?? null,
            'stype'  => $data['source_type'] ?? 'rent_generator',
            'sid'    => $data['source_id'] ?? null,
            'by'     => $data['created_by'] ?? getUserId(),
        ]);
        $chargeId = (int) $conn->lastInsertId();

        if (ledgerAutoPost()) {
            // Resolved per currency, so a USD lease posts to USD accounts rather
            // than silently falling outside the ledger.
            $ar     = ledgerSystemAccountFor('ar', $currency);
            $income = ledgerSystemAccountFor($incomeKeys[$type] ?? 'rent_income', $currency);

            if (!$ar || !$income) {
                // No account to post to. Roll the charge back rather than leave
                // it outside the ledger — a charge the books know nothing about
                // makes arrears and receivables disagree for ever after.
                if ($ownTransaction) $conn->rollBack();
                $reason = sprintf('no %s account in %s', !$ar ? 'receivable' : 'income', $currency);
                error_log('chargeCreate: not raised — ' . $reason);
                return ['success' => false, 'id' => null, 'errors' => [$reason]];
            } else {
                $entry = ledgerCreateEntry([
                    'entry_date'    => $data['due_date'] ?? date('Y-m-d'),
                    'currency'      => $currency,
                    'memo'          => $data['description'] ?: sprintf(__('chg_memo', '%s charge'), ucfirst($type)),
                    'source_type'   => 'charge',
                    'source_id'     => $chargeId,
                    'status'        => 'posted',
                    'system_posted' => true,   // machine posting, not a user asking to skip approval
                ], [
                    ['account_id' => $ar['id'],     'debit' => $amount, 'credit' => 0,
                     'property_id' => $data['property_id'] ?? null, 'unit_id' => $data['unit_id'] ?? null,
                     'lease_id' => $data['lease_id'] ?? null, 'tenant_id' => (int) $data['tenant_id']],
                    ['account_id' => $income['id'], 'debit' => 0, 'credit' => $amount,
                     'property_id' => $data['property_id'] ?? null, 'unit_id' => $data['unit_id'] ?? null,
                     'lease_id' => $data['lease_id'] ?? null, 'tenant_id' => (int) $data['tenant_id']],
                ]);
                if ($entry['success']) {
                    $conn->prepare('UPDATE charges SET entry_id = ? WHERE id = ?')
                         ->execute([$entry['id'], $chargeId]);
                    // Money the tenant already paid ahead settles the new charge at once.
                    applyOpenCredit((int) $data['tenant_id'], $data['lease_id'] ?? null, $currency);
                } else {
                    // The commonest cause is a closed period: the charge is
                    // back-dated into months that have been locked. Either way
                    // the charge must not survive without its entry, and the
                    // reason must reach the caller instead of being swallowed.
                    if ($ownTransaction) $conn->rollBack();
                    $reason = implode(' | ', $entry['errors']);
                    error_log('chargeCreate: not raised — ' . $reason);
                    return ['success' => false, 'id' => null, 'errors' => $entry['errors']];
                }
            }
        }

        if ($ownTransaction) $conn->commit();
        return ['success' => true, 'id' => $chargeId, 'errors' => []];

    } catch (PDOException $e) {
        if ($ownTransaction && $conn->inTransaction()) $conn->rollBack();
        // Duplicate key means the generator already raised this period. Not an error.
        if ($e->getCode() === '23000') {
            return ['success' => true, 'id' => null, 'errors' => [], 'duplicate' => true];
        }
        return ['success' => false, 'id' => null, 'errors' => [$e->getMessage()]];
    }
}

/**
 * Raise rent charges for every active lease up to today + advance window.
 * Idempotent, so it is safe to run from cron and by hand.
 */
function generateRentCharges($asOf = null, $leaseId = null) {
    global $conn;

    $asOf    = $asOf ?: date('Y-m-d');
    $advance = (int) getSetting('charge_advance_days', '0');
    $horizon = date('Y-m-d', strtotime($asOf . ' +' . $advance . ' days'));

    $sql = "SELECT l.id, l.unit_id, l.tenant_id, l.start_date, l.end_date, l.rent_amount,
                   l.currency, l.payment_frequency, l.payment_due_day, l.billed_through,
                   u.property_id, u.unit_number
            FROM leases l
            INNER JOIN units u ON u.id = l.unit_id
            WHERE l.status = 'active'";
    $params = [];
    if ($leaseId) { $sql .= ' AND l.id = ?'; $params[] = (int) $leaseId; }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $leases = $stmt->fetchAll();

    $created = 0; $skipped = 0; $failed = 0; $reasons = [];

    foreach ($leases as $lease) {
        $months = paymentFrequencyMonths($lease['payment_frequency']);
        $rent   = (float) $lease['rent_amount'];
        if ($rent <= 0) continue;

        $periodStart = new DateTime($lease['start_date']);
        $leaseEnd    = new DateTime($lease['end_date']);
        $horizonDate = new DateTime($horizon);

        // Periods up to billed_through were billed before this system took
        // over (opening arrears carry a different source_type, so the unique
        // index would not catch them). A period that merely straddles the date
        // is skipped too: its rent belongs in the opening figures.
        $billedThrough = $lease['billed_through'] ? new DateTime($lease['billed_through']) : null;

        // Walk the lease period by period, stopping at the horizon.
        $guard = 0;
        while ($periodStart <= $leaseEnd && $periodStart <= $horizonDate && $guard++ < 500) {
            if ($billedThrough && $periodStart <= $billedThrough) {
                $periodStart = (clone $periodStart)->modify('+' . $months . ' months');
                continue;
            }

            $periodEnd = (clone $periodStart)->modify('+' . $months . ' months')->modify('-1 day');
            if ($periodEnd > $leaseEnd) $periodEnd = clone $leaseEnd;

            // Due day sits inside the period's first month, clamped to 1-28 by
            // the lease form, so it can never fall off the end of a month.
            $due = (clone $periodStart);
            $dueDay = max(1, min(28, (int) $lease['payment_due_day']));
            $due->setDate((int) $due->format('Y'), (int) $due->format('n'), $dueDay);
            if ($due < $periodStart) $due = clone $periodStart;

            $result = chargeCreate([
                'type'        => 'rent',
                'lease_id'    => (int) $lease['id'],
                'tenant_id'   => (int) $lease['tenant_id'],
                'unit_id'     => (int) $lease['unit_id'],
                'property_id' => (int) $lease['property_id'],
                'period_from' => $periodStart->format('Y-m-d'),
                'period_to'   => $periodEnd->format('Y-m-d'),
                'due_date'    => $due->format('Y-m-d'),
                'amount'      => $rent,
                'currency'    => $lease['currency'],
                'description' => sprintf(
                    __('chg_rent_desc', 'Rent %s — %s to %s'),
                    $lease['unit_number'],
                    $periodStart->format('d/m/Y'),
                    $periodEnd->format('d/m/Y')
                ),
                'source_type' => 'rent_generator',
            ]);

            if (!empty($result['duplicate'])) {
                $skipped++;
            } elseif ($result['success']) {
                $created++;
            } else {
                $failed++;
                // Surface why, so "0 raised, 47 failed" is actionable rather
                // than a mystery. Dates are stripped so forty-seven charges
                // failing for one reason report as one line, not forty-seven.
                $reason = $result['errors'][0] ?? 'unknown';
                $reason = preg_replace('/\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4}/', '…', $reason);
                if (!isset($reasons[$reason])) $reasons[$reason] = 0;
                $reasons[$reason]++;
            }

            $periodStart = (clone $periodStart)->modify('+' . $months . ' months');
        }
    }

    return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed,
            'leases' => count($leases), 'reasons' => $reasons];
}

/** Charges still owed. This replaces the old expected-minus-received arithmetic. */
function outstandingCharges($filters = []) {
    global $conn;
    $where = ["c.status IN ('open','part')"];
    $params = [];

    if (!empty($filters['tenant_id'])) { $where[] = 'c.tenant_id = :tid'; $params['tid'] = (int) $filters['tenant_id']; }
    if (!empty($filters['lease_id']))  { $where[] = 'c.lease_id = :lid';  $params['lid'] = (int) $filters['lease_id']; }
    if (!empty($filters['due_by']))    { $where[] = 'c.due_date <= :due'; $params['due'] = $filters['due_by']; }
    if (!empty($filters['type']))      { $where[] = 'c.type = :type';     $params['type'] = $filters['type']; }
    if (!empty($filters['currency']))  { $where[] = 'c.currency = :cur'; $params['cur'] = $filters['currency']; }

    $stmt = $conn->prepare('SELECT c.* FROM charges c WHERE ' . implode(' AND ', $where) . ' ORDER BY c.due_date, c.id');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Total still owed, in one number. */
function outstandingTotal($filters = []) {
    global $conn;
    $where = ["c.status IN ('open','part')"];
    $params = [];
    if (!empty($filters['tenant_id'])) { $where[] = 'c.tenant_id = :tid'; $params['tid'] = (int) $filters['tenant_id']; }
    if (!empty($filters['lease_id']))  { $where[] = 'c.lease_id = :lid';  $params['lid'] = (int) $filters['lease_id']; }
    if (!empty($filters['due_by']))    { $where[] = 'c.due_date <= :due'; $params['due'] = $filters['due_by']; }

    $stmt = $conn->prepare('SELECT COALESCE(SUM(c.amount - c.amount_settled), 0)
                            FROM charges c WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

// ============================================================
// Allocation
// ============================================================

/**
 * Apply a payment to outstanding charges, oldest first.
 * Returns how much was allocated and what was left over as a credit.
 */
function allocatePayment($paymentId, $amount, $tenantId, $leaseId = null, $currency = null) {
    global $conn;

    $remaining = round((float) $amount, 2);
    $charges = outstandingCharges(array_filter([
        'tenant_id' => $tenantId,
        'lease_id'  => $leaseId,
        // A payment must never settle a charge raised in another currency.
        'currency'  => $currency,
    ]));

    $allocStmt  = $conn->prepare('INSERT INTO payment_allocations (payment_id, charge_id, amount, created_by)
                                  VALUES (?, ?, ?, ?)');
    $updateStmt = $conn->prepare("UPDATE charges
                                  SET status = CASE WHEN amount_settled + :amt >= amount THEN 'settled' ELSE 'part' END,
                                      amount_settled = amount_settled + :amt2
                                  WHERE id = :id");

    $allocated = 0.0;
    foreach ($charges as $charge) {
        if ($remaining < 0.005) break;
        $owed = round((float) $charge['amount'] - (float) $charge['amount_settled'], 2);
        if ($owed <= 0) continue;

        $take = min($owed, $remaining);
        $allocStmt->execute([(int) $paymentId, (int) $charge['id'], $take, getUserId()]);
        $updateStmt->execute(['amt' => $take, 'amt2' => $take, 'id' => (int) $charge['id']]);

        $remaining = round($remaining - $take, 2);
        $allocated = round($allocated + $take, 2);
    }

    return ['allocated' => $allocated, 'unallocated' => $remaining];
}

/**
 * Apply any unused credit — an overpayment or an opening advance — to charges
 * still open, oldest first. allocatePayment() only ever looks at charges that
 * exist when the money arrives, so without this a credit sat unused for ever.
 *
 * Only payments that are posted count: their credit is already in Receivables,
 * so applying them changes the arrears and leaves the ledger as it is.
 */
function applyOpenCredit($tenantId, $leaseId = null, $currency = null) {
    global $conn;
    if (!ledgerAutoPost()) return 0.0;

    $sql = "SELECT p.id, p.lease_id, p.currency, p.amount - COALESCE(SUM(a.amount), 0) AS remaining
            FROM payments p
            LEFT JOIN payment_allocations a ON a.payment_id = p.id
            WHERE p.status = 'recorded' AND p.tenant_id = ?
              AND EXISTS (SELECT 1 FROM journal_entries je
                          WHERE je.source_type = 'payment' AND je.source_id = p.id AND je.status = 'posted')";
    $params = [(int) $tenantId];
    if ($leaseId)  { $sql .= ' AND p.lease_id = ?'; $params[] = (int) $leaseId; }
    if ($currency) { $sql .= ' AND p.currency = ?'; $params[] = $currency; }
    $sql .= ' GROUP BY p.id, p.lease_id, p.currency, p.amount HAVING remaining > 0.005 ORDER BY p.payment_date, p.id';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $applied = 0.0;
    foreach ($stmt->fetchAll() as $p) {
        $r = allocatePayment((int) $p['id'], (float) $p['remaining'], (int) $tenantId, $p['lease_id'], $p['currency']);
        $applied += $r['allocated'];
    }
    return $applied;
}

// ============================================================
// Automatic postings
// ============================================================

/**
 * Post a recorded payment:
 *   Dr Float (collector) or Bank    Cr Accounts Receivable
 * and allocate it against outstanding charges.
 *
 * Never throws into the caller's path — a ledger problem must not stop a
 * payment being recorded. Failures are logged and surfaced in the ledger's own
 * screens instead.
 */
function postPayment($paymentId) {
    global $conn;
    if (!ledgerAutoPost()) return ['success' => false, 'reason' => 'auto-post off'];

    // Declared before the try so the catch can rely on it even if something
    // throws on the way to the transaction.
    $ownTransaction = false;

    try {
        $stmt = $conn->prepare('SELECT p.*, u.property_id FROM payments p
                                LEFT JOIN units u ON u.id = p.unit_id WHERE p.id = ?');
        $stmt->execute([(int) $paymentId]);
        $payment = $stmt->fetch();
        if (!$payment || $payment['status'] !== 'recorded') {
            return ['success' => false, 'reason' => 'payment not found or not recorded'];
        }

        // Already posted? Never post the same payment twice.
        $dupe = $conn->prepare("SELECT COUNT(*) FROM journal_entries
                                WHERE source_type = 'payment' AND source_id = ? AND status <> 'rejected'");
        $dupe->execute([(int) $paymentId]);
        if ((int) $dupe->fetchColumn() > 0) return ['success' => false, 'reason' => 'already posted'];

        $currency = $payment['currency'];
        $amount   = round((float) $payment['amount'], 2);
        $ar       = ledgerSystemAccountFor('ar', $currency);
        if (!$ar) return ['success' => false, 'reason' => 'no receivable account in ' . $currency];

        // Cash and cheque land in the collector's hands; everything else goes
        // straight to the bank.
        // The account chosen on the form wins; with none, this is the old
        // derived behaviour unchanged (part 38).
        $debitAccount = receiptAccountFor(
            $payment['ledger_account_id'] ?? null,
            $payment['payment_method'],
            $currency,
            $payment['recorded_by'] ?: getUserId()
        );
        if (!$debitAccount) return ['success' => false, 'reason' => 'no destination account'];

        // Callers such as modules/payments/create.php and api/gateway-callback.php
        // wrap the whole save in their own transaction. PDO cannot nest, so
        // beginning one unconditionally threw "there is already an active
        // transaction", and the catch below then rolled back the CALLER's work —
        // losing the payment and leaving them to fatal on a dead commit. Own the
        // transaction only when there isn't one, exactly as chargeCreate() does.
        $ownTransaction = !$conn->inTransaction();
        if ($ownTransaction) $conn->beginTransaction();

        $entry = ledgerCreateEntry([
            'entry_date'    => $payment['payment_date'],
            'currency'      => $currency,
            'memo'          => sprintf(__('chg_payment_memo', 'Payment #%s'), $paymentId),
            'source_type'   => 'payment',
            'source_id'     => (int) $paymentId,
            'status'        => 'posted',
            'system_posted' => true,
        ], [
            ['account_id' => $debitAccount['id'], 'debit' => $amount, 'credit' => 0,
             'property_id' => $payment['property_id'], 'unit_id' => $payment['unit_id'],
             'lease_id' => $payment['lease_id'], 'tenant_id' => $payment['tenant_id']],
            ['account_id' => $ar['id'], 'debit' => 0, 'credit' => $amount,
             'property_id' => $payment['property_id'], 'unit_id' => $payment['unit_id'],
             'lease_id' => $payment['lease_id'], 'tenant_id' => $payment['tenant_id']],
        ]);

        if (!$entry['success']) {
            // Only unwind what we started. A caller's transaction is theirs to
            // roll back — we report the failure and let them decide.
            if ($ownTransaction) $conn->rollBack();
            error_log('postPayment failed for #' . $paymentId . ': ' . implode(' ', $entry['errors']));
            return ['success' => false, 'reason' => implode(' ', $entry['errors'])];
        }

        $alloc = allocatePayment($paymentId, $amount, $payment['tenant_id'], $payment['lease_id'], $currency);
        if ($ownTransaction) $conn->commit();

        return ['success' => true, 'entry_id' => $entry['id']] + $alloc;
    } catch (PDOException $e) {
        if ($ownTransaction && $conn->inTransaction()) $conn->rollBack();
        error_log('postPayment error for #' . $paymentId . ': ' . $e->getMessage());
        return ['success' => false, 'reason' => $e->getMessage()];
    }
}

/**
 * Post a collected deposit:
 *   Dr Float or Bank    Cr Tenant Deposits Held
 * A deposit is the tenant's money, so it is a liability, never income.
 */
function postDeposit($depositId) {
    global $conn;
    if (!ledgerAutoPost()) return ['success' => false, 'reason' => 'auto-post off'];

    try {
        $stmt = $conn->prepare('SELECT d.*, u.property_id FROM deposits d
                                LEFT JOIN units u ON u.id = d.unit_id WHERE d.id = ?');
        $stmt->execute([(int) $depositId]);
        $deposit = $stmt->fetch();
        if (!$deposit) return ['success' => false, 'reason' => 'deposit not found'];

        $dupe = $conn->prepare("SELECT COUNT(*) FROM journal_entries
                                WHERE source_type = 'deposit' AND source_id = ? AND status <> 'rejected'");
        $dupe->execute([(int) $depositId]);
        if ((int) $dupe->fetchColumn() > 0) return ['success' => false, 'reason' => 'already posted'];

        $currency = $deposit['currency'];
        $amount   = round((float) $deposit['amount'], 2);
        if ($amount <= 0) return ['success' => false, 'reason' => 'zero amount'];

        $held = ledgerSystemAccountFor('deposits_held', $currency);
        if (!$held) return ['success' => false, 'reason' => 'no deposits-held account in ' . $currency];

        $method = $deposit['payment_method'] ?: 'cash';
        // Chosen account wins; without one this is the old behaviour (part 38).
        $debitAccount = receiptAccountFor(
            $deposit['ledger_account_id'] ?? null,
            $method,
            $currency,
            $deposit['recorded_by'] ?: getUserId()
        );
        if (!$debitAccount) return ['success' => false, 'reason' => 'no destination account'];

        $entry = ledgerCreateEntry([
            'entry_date'    => $deposit['paid_date'] ?: date('Y-m-d'),
            'currency'      => $currency,
            'memo'          => sprintf(__('chg_deposit_memo', 'Deposit #%s'), $depositId),
            'source_type'   => 'deposit',
            'source_id'     => (int) $depositId,
            'status'        => 'posted',
            'system_posted' => true,
        ], [
            ['account_id' => $debitAccount['id'], 'debit' => $amount, 'credit' => 0,
             'property_id' => $deposit['property_id'], 'unit_id' => $deposit['unit_id'],
             'lease_id' => $deposit['lease_id'], 'tenant_id' => $deposit['tenant_id']],
            ['account_id' => $held['id'], 'debit' => 0, 'credit' => $amount,
             'property_id' => $deposit['property_id'], 'unit_id' => $deposit['unit_id'],
             'lease_id' => $deposit['lease_id'], 'tenant_id' => $deposit['tenant_id']],
        ]);

        if (!$entry['success']) {
            error_log('postDeposit failed for #' . $depositId . ': ' . implode(' ', $entry['errors']));
            return ['success' => false, 'reason' => implode(' ', $entry['errors'])];
        }
        return ['success' => true, 'entry_id' => $entry['id']];
    } catch (PDOException $e) {
        error_log('postDeposit error for #' . $depositId . ': ' . $e->getMessage());
        return ['success' => false, 'reason' => $e->getMessage()];
    }
}

/**
 * Reverse a payment's ledger effect and undo its allocations, so the charges
 * it settled become outstanding again.
 */
function reversePaymentPosting($paymentId) {
    global $conn;

    $stmt = $conn->prepare("SELECT id FROM journal_entries
                            WHERE source_type = 'payment' AND source_id = ? AND status = 'posted'
                              AND reversed_by_entry_id IS NULL");
    $stmt->execute([(int) $paymentId]);
    $entryId = (int) $stmt->fetchColumn();

    // Put the money back on the charges first, then mirror the entry.
    $allocs = $conn->prepare('SELECT charge_id, amount FROM payment_allocations WHERE payment_id = ?');
    $allocs->execute([(int) $paymentId]);
    $undo = $conn->prepare("UPDATE charges
                            SET status = CASE WHEN GREATEST(0, amount_settled - :amt) <= 0 THEN 'open' ELSE 'part' END,
                                amount_settled = GREATEST(0, amount_settled - :amt2)
                            WHERE id = :id");
    foreach ($allocs->fetchAll() as $a) {
        $undo->execute(['amt' => $a['amount'], 'amt2' => $a['amount'], 'id' => $a['charge_id']]);
    }
    $conn->prepare('DELETE FROM payment_allocations WHERE payment_id = ?')->execute([(int) $paymentId]);

    if ($entryId) {
        $reversal = ledgerReverseEntry($entryId, date('Y-m-d'),
            sprintf(__('chg_payment_reversed', 'Reversal of payment #%s'), $paymentId));
        // A machine reversal posts straight away; there is nothing for a human
        // to weigh up, and leaving it pending would strand the float.
        if ($reversal['success']) {
            $conn->prepare("UPDATE journal_entries SET status = 'posted', approved_at = NOW() WHERE id = ?")
                 ->execute([$reversal['id']]);
        }
        return $reversal;
    }
    return ['success' => true, 'id' => null, 'errors' => []];
}

// ============================================================
// Remittance
// ============================================================

/** Next remittance reference. */
function remittanceNextRef() {
    global $conn;
    $last = $conn->query('SELECT reference_no FROM remittances ORDER BY id DESC LIMIT 1')->fetchColumn();
    $n = ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int) $m[1]) + 1 : 1;
    return 'RM-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

/**
 * File a remittance. Nothing moves until it is approved — that is the whole
 * point of the float.
 */
function remittanceCreate($data) {
    global $conn;

    $floatAccount = ledgerAccount($data['float_account_id'] ?? 0);
    $bankAccount  = ledgerAccount($data['bank_account_id'] ?? 0);
    $amount       = round((float) ($data['amount'] ?? 0), 2);

    $errors = [];
    // A personal float or a shared cash box — both hold cash, both get banked
    // the same way (part 38).
    if (!isCashHoldingAccount($floatAccount)) $errors[] = __('flt_err_float', 'Select a cash account');
    if (!$bankAccount || $bankAccount['type'] !== 'asset')          $errors[] = __('flt_err_bank', 'Select a bank account');
    if ($amount <= 0)                                               $errors[] = __('flt_err_amount', 'Amount must be greater than zero');
    if ($floatAccount && $bankAccount && $floatAccount['currency'] !== $bankAccount['currency']) {
        $errors[] = __('flt_err_currency', 'Float and bank account must use the same currency');
    }
    if ($floatAccount && $amount > ledgerAccountBalance($floatAccount['id']) + 0.005) {
        $errors[] = __('flt_err_exceeds', 'That is more than the float currently holds');
    }
    if ($errors) return ['success' => false, 'id' => null, 'errors' => $errors];

    try {
        $stmt = $conn->prepare('
            INSERT INTO remittances (reference_no, float_account_id, bank_account_id, amount, currency,
                                     remitted_date, bank_reference, notes, requested_by)
            VALUES (:ref, :fa, :ba, :amt, :cur, :dt, :bref, :notes, :by)
        ');
        $stmt->execute([
            'ref'   => remittanceNextRef(),
            'fa'    => (int) $floatAccount['id'],
            'ba'    => (int) $bankAccount['id'],
            'amt'   => $amount,
            'cur'   => $floatAccount['currency'],
            'dt'    => $data['remitted_date'] ?? date('Y-m-d'),
            'bref'  => $data['bank_reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'by'    => getUserId(),
        ]);
        $id = (int) $conn->lastInsertId();
        logActivity('created', 'remittance', $id, ['amount' => $amount]);
        return ['success' => true, 'id' => $id, 'errors' => []];
    } catch (PDOException $e) {
        return ['success' => false, 'id' => null, 'errors' => [$e->getMessage()]];
    }
}

/**
 * Approve a remittance: Dr Bank  Cr Float. Only now does the float fall.
 */
function remittanceApprove($id, $userId = null) {
    global $conn;
    $userId = $userId ?? getUserId();

    if (!ledgerCanApprove($userId)) {
        return ['success' => false, 'errors' => [__('led_no_approve_right', 'You do not have approval rights')]];
    }

    $stmt = $conn->prepare('SELECT * FROM remittances WHERE id = ?');
    $stmt->execute([(int) $id]);
    $rem = $stmt->fetch();
    if (!$rem) return ['success' => false, 'errors' => [__('flt_err_not_found', 'Remittance not found')]];
    if ($rem['status'] !== 'pending') return ['success' => false, 'errors' => [__('flt_err_not_pending', 'Only a pending remittance can be approved')]];
    if (!ledgerAllowSelfApproval() && (int) $rem['requested_by'] === (int) $userId) {
        return ['success' => false, 'errors' => [__('flt_err_self', 'You cannot approve a remittance you filed')]];
    }

    // The float may have moved while this waited.
    if ((float) $rem['amount'] > ledgerAccountBalance($rem['float_account_id']) + 0.005) {
        return ['success' => false, 'errors' => [__('flt_err_exceeds', 'That is more than the float currently holds')]];
    }

    $ownTransaction = !$conn->inTransaction();
    if ($ownTransaction) $conn->beginTransaction();
    try {
        $entry = ledgerCreateEntry([
            'entry_date'    => $rem['remitted_date'],
            'currency'      => $rem['currency'],
            'memo'          => sprintf(__('flt_memo', 'Remittance %s'), $rem['reference_no']),
            'source_type'   => 'remittance',
            'source_id'     => (int) $id,
            'status'        => 'posted',
            'system_posted' => true,
            'approved_by'   => $userId,
        ], [
            ['account_id' => (int) $rem['bank_account_id'],  'debit' => (float) $rem['amount'], 'credit' => 0],
            ['account_id' => (int) $rem['float_account_id'], 'debit' => 0, 'credit' => (float) $rem['amount']],
        ]);

        if (!$entry['success']) {
            if ($ownTransaction) $conn->rollBack();
            return ['success' => false, 'errors' => $entry['errors']];
        }

        $conn->prepare("UPDATE remittances
                        SET status = 'approved', approved_by = ?, approved_at = NOW(), entry_id = ?
                        WHERE id = ?")->execute([$userId, $entry['id'], (int) $id]);
        if ($ownTransaction) $conn->commit();

        logActivity('approved', 'remittance', (int) $id, ['entry_id' => $entry['id']]);
        return ['success' => true, 'errors' => []];
    } catch (PDOException $e) {
        if ($ownTransaction && $conn->inTransaction()) $conn->rollBack();
        return ['success' => false, 'errors' => [$e->getMessage()]];
    }
}

/** Reject a remittance. The float stays exactly where it was. */
function remittanceReject($id, $reason, $userId = null) {
    global $conn;
    $userId = $userId ?? getUserId();

    if (!ledgerCanApprove($userId)) {
        return ['success' => false, 'errors' => [__('led_no_approve_right', 'You do not have approval rights')]];
    }
    if (trim((string) $reason) === '') {
        return ['success' => false, 'errors' => [__('led_err_reason', 'A rejection reason is required')]];
    }

    $stmt = $conn->prepare("UPDATE remittances
                            SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ?
                            WHERE id = ? AND status = 'pending'");
    $stmt->execute([$userId, trim($reason), (int) $id]);

    if (!$stmt->rowCount()) return ['success' => false, 'errors' => [__('flt_err_not_pending', 'Only a pending remittance can be rejected')]];
    logActivity('rejected', 'remittance', (int) $id, ['reason' => trim($reason)]);
    return ['success' => true, 'errors' => []];
}

/** Remittances awaiting approval — for the badge and queue. */
function remittancePendingCount() {
    global $conn;
    try {
        return (int) $conn->query("SELECT COUNT(*) FROM remittances WHERE status = 'pending'")->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}
