<?php
/**
 * FIS Real Estate — Payables, refunds and forfeitures (Part 32, Phase 3)
 *
 * Money going out. The rule throughout: RECORDING an obligation is never
 * blocked, PAYING it always needs a second person.
 *
 *   expense incurred   Dr Expense              Cr Accounts Payable   free
 *   expense paid       Dr Accounts Payable     Cr Bank or Float      approval
 *   deposit refunded   Dr Tenant Deposits Held Cr Bank or Float      approval
 *   deposit forfeited  Dr Tenant Deposits Held Cr Forfeit Income     approval
 *
 * A caretaker can log the electricity bill the moment it arrives; only
 * settling it moves money, and that is what an approver signs off.
 */

require_once __DIR__ . '/charges.php';

// ============================================================
// Expense accounts
// ============================================================

/**
 * The expense account a category posts to, falling back to the default so a
 * category nobody has mapped still lands somewhere real.
 */
function expenseAccountFor($categoryId, $currency) {
    global $conn;

    if ($categoryId) {
        $stmt = $conn->prepare('
            SELECT a.* FROM expense_categories ec
            INNER JOIN accounts a ON a.id = ec.account_id
            WHERE ec.id = ? AND a.status = \'active\' AND a.currency = ?
        ');
        $stmt->execute([(int) $categoryId, $currency]);
        $account = $stmt->fetch();
        if ($account) return $account;
    }
    return ledgerSystemAccountFor('expense_default', $currency);
}

/** Categories with whichever account they post to, for the settings screen. */
function expenseCategoriesWithAccounts() {
    global $conn;
    return $conn->query('
        SELECT ec.id, ec.name, ec.account_id, a.name AS account_name, a.code AS account_code
        FROM expense_categories ec
        LEFT JOIN accounts a ON a.id = ec.account_id
        ORDER BY ec.name
    ')->fetchAll();
}

// ============================================================
// Expenses as payables
// ============================================================

/**
 * Recognise an expense: Dr Expense  Cr Accounts Payable.
 *
 * Called when the expense is RECORDED, regardless of whether it has been paid,
 * because that is what accrual means — the cost belongs to the month it was
 * incurred, not the month someone got round to paying it.
 */
function postExpense($expenseId) {
    global $conn;
    if (!ledgerAutoPost()) return ['success' => false, 'reason' => 'auto-post off'];

    try {
        $stmt = $conn->prepare('SELECT * FROM expenses WHERE id = ?');
        $stmt->execute([(int) $expenseId]);
        $expense = $stmt->fetch();
        if (!$expense) return ['success' => false, 'reason' => 'expense not found'];

        $dupe = $conn->prepare("SELECT COUNT(*) FROM journal_entries
                                WHERE source_type = 'expense' AND source_id = ? AND status <> 'rejected'");
        $dupe->execute([(int) $expenseId]);
        if ((int) $dupe->fetchColumn() > 0) return ['success' => false, 'reason' => 'already posted'];

        $currency = $expense['currency'];
        $amount   = round((float) $expense['amount'], 2);
        if ($amount <= 0) return ['success' => false, 'reason' => 'zero amount'];

        $expenseAccount = expenseAccountFor($expense['category_id'], $currency);
        $payable        = ledgerSystemAccountFor('ap', $currency);
        if (!$expenseAccount) return ['success' => false, 'reason' => 'no expense account in ' . $currency];
        if (!$payable)       return ['success' => false, 'reason' => 'no payable account in ' . $currency];

        $entry = ledgerCreateEntry([
            'entry_date'    => $expense['expense_date'],
            'currency'      => $currency,
            'memo'          => $expense['description'] ?: sprintf(__('exp_memo', 'Expense #%s'), $expenseId),
            'source_type'   => 'expense',
            'source_id'     => (int) $expenseId,
            'status'        => 'posted',
            'system_posted' => true,
        ], [
            ['account_id' => $expenseAccount['id'], 'debit' => $amount, 'credit' => 0,
             'property_id' => $expense['property_id'], 'unit_id' => $expense['unit_id']],
            ['account_id' => $payable['id'], 'debit' => 0, 'credit' => $amount,
             'property_id' => $expense['property_id'], 'unit_id' => $expense['unit_id']],
        ]);

        if (!$entry['success']) {
            error_log('postExpense failed for #' . $expenseId . ': ' . implode(' ', $entry['errors']));
            return ['success' => false, 'reason' => implode(' ', $entry['errors'])];
        }

        $conn->prepare('UPDATE expenses SET entry_id = ? WHERE id = ?')->execute([$entry['id'], (int) $expenseId]);
        return ['success' => true, 'entry_id' => $entry['id']];
    } catch (PDOException $e) {
        error_log('postExpense error for #' . $expenseId . ': ' . $e->getMessage());
        return ['success' => false, 'reason' => $e->getMessage()];
    }
}

/**
 * Settle a payable: Dr Accounts Payable  Cr Bank or Float.
 *
 * This is money actually leaving, so it needs an approver — and one who did not
 * record the expense.
 */
function payExpense($expenseId, $accountId, $paidDate = null, $userId = null) {
    global $conn;
    $userId = $userId ?? getUserId();

    if (!ledgerCanApprove($userId)) {
        return ['success' => false, 'errors' => [__('led_no_approve_right', 'You do not have approval rights')]];
    }

    $stmt = $conn->prepare('SELECT * FROM expenses WHERE id = ?');
    $stmt->execute([(int) $expenseId]);
    $expense = $stmt->fetch();
    if (!$expense) return ['success' => false, 'errors' => [__('exp_err_not_found', 'Expense not found')]];
    if ($expense['payment_status'] === 'paid') {
        return ['success' => false, 'errors' => [__('exp_err_already_paid', 'This expense is already paid')]];
    }
    if (!ledgerAllowSelfApproval() && (int) $expense['recorded_by'] === (int) $userId) {
        return ['success' => false, 'errors' => [__('exp_err_self', 'You cannot approve payment of an expense you recorded')]];
    }

    $source = ledgerAccount($accountId);
    if (!$source || $source['type'] !== 'asset') {
        return ['success' => false, 'errors' => [__('exp_err_source', 'Choose the bank or float the money came from')]];
    }
    if ($source['currency'] !== $expense['currency']) {
        return ['success' => false, 'errors' => [__('exp_err_currency', 'That account is in a different currency to the expense')]];
    }

    $amount  = round((float) $expense['amount'], 2);
    $payable = ledgerSystemAccountFor('ap', $expense['currency']);
    if (!$payable) return ['success' => false, 'errors' => ['No payable account in ' . $expense['currency']]];

    // Paying out of a float cannot take more cash than that person is holding.
    if ($source['system_key'] === 'float' && $amount > ledgerAccountBalance($source['id']) + 0.005) {
        return ['success' => false, 'errors' => [__('exp_err_float_short', 'That float does not hold enough cash')]];
    }

    $ownTransaction = !$conn->inTransaction();
    if ($ownTransaction) $conn->beginTransaction();
    try {
        $entry = ledgerCreateEntry([
            'entry_date'    => $paidDate ?: date('Y-m-d'),
            'currency'      => $expense['currency'],
            'memo'          => sprintf(__('exp_paid_memo', 'Payment of expense #%s'), $expenseId),
            'source_type'   => 'expense_payment',
            'source_id'     => (int) $expenseId,
            'status'        => 'posted',
            'system_posted' => true,
            'approved_by'   => $userId,
        ], [
            ['account_id' => $payable['id'], 'debit' => $amount, 'credit' => 0,
             'property_id' => $expense['property_id']],
            ['account_id' => $source['id'], 'debit' => 0, 'credit' => $amount,
             'property_id' => $expense['property_id']],
        ]);

        if (!$entry['success']) {
            if ($ownTransaction) $conn->rollBack();
            return ['success' => false, 'errors' => $entry['errors']];
        }

        $conn->prepare("UPDATE expenses
                        SET payment_status = 'paid', paid_date = :dt, paid_from_account_id = :acc
                        WHERE id = :id")
             ->execute(['dt' => $paidDate ?: date('Y-m-d'), 'acc' => $source['id'], 'id' => (int) $expenseId]);

        if ($ownTransaction) $conn->commit();
        logActivity('paid', 'expense', (int) $expenseId, ['account' => $source['name'], 'amount' => $amount]);
        return ['success' => true, 'errors' => []];
    } catch (PDOException $e) {
        if ($ownTransaction && $conn->inTransaction()) $conn->rollBack();
        return ['success' => false, 'errors' => [$e->getMessage()]];
    }
}

/** Unpaid payables, for the payables screen and the approval queue. */
function unpaidExpenses($limit = 200) {
    global $conn;
    $limit = max(1, min(1000, (int) $limit));
    return $conn->query("
        SELECT e.*, p.name AS property_name, ec.name AS category_name, u.full_name AS recorded_by_name
        FROM expenses e
        LEFT JOIN properties p ON p.id = e.property_id
        LEFT JOIN expense_categories ec ON ec.id = e.category_id
        LEFT JOIN users u ON u.id = e.recorded_by
        WHERE e.payment_status = 'unpaid' AND (e.status IS NULL OR e.status <> 'voided')
        ORDER BY e.expense_date, e.id
        LIMIT {$limit}
    ")->fetchAll();
}

/** What we owe suppliers, per currency. */
function payablesTotal() {
    global $conn;
    return $conn->query("
        SELECT e.currency, COALESCE(SUM(e.amount), 0) AS owed
        FROM expenses e
        WHERE e.payment_status = 'unpaid' AND (e.status IS NULL OR e.status <> 'voided')
        GROUP BY e.currency
    ")->fetchAll();
}

// ============================================================
// Deposit refunds and forfeitures
// ============================================================

/**
 * Request a refund or forfeit. Nothing moves and the deposit's own status is
 * untouched until an approver accepts it.
 */
function depositPayoutRequest($depositId, $type, $amount, $fromAccountId = null, $notes = null) {
    global $conn;

    if (!in_array($type, ['refund', 'forfeit'], true)) {
        return ['success' => false, 'errors' => [__('dep_err_type', 'Choose refund or forfeit')]];
    }

    $stmt = $conn->prepare('SELECT * FROM deposits WHERE id = ?');
    $stmt->execute([(int) $depositId]);
    $deposit = $stmt->fetch();
    if (!$deposit) return ['success' => false, 'errors' => [__('dep_err_not_found', 'Deposit not found')]];
    if ($deposit['payout_status'] === 'pending') {
        return ['success' => false, 'errors' => [__('dep_err_pending', 'This deposit already has a request awaiting approval')]];
    }
    if (in_array($deposit['status'], ['refunded', 'forfeited'], true)) {
        return ['success' => false, 'errors' => [__('dep_err_closed', 'This deposit has already been settled')]];
    }

    $amount = round((float) $amount, 2);
    $held   = round((float) $deposit['amount'] - (float) ($deposit['refund_amount'] ?? 0), 2);
    if ($amount <= 0)      return ['success' => false, 'errors' => [__('dep_err_amount', 'Amount must be greater than zero')]];
    if ($amount > $held + 0.005) {
        return ['success' => false, 'errors' => [sprintf(__('dep_err_exceeds', 'That is more than the %s held'), formatCurrency($held, $deposit['currency'], 2))]];
    }

    // A refund pays money out, so it needs a source. A forfeit does not — the
    // money stays with us and simply stops being the tenant's.
    if ($type === 'refund') {
        $source = ledgerAccount($fromAccountId);
        if (!$source || $source['type'] !== 'asset') {
            return ['success' => false, 'errors' => [__('dep_err_source', 'Choose the bank or float the refund comes from')]];
        }
        if ($source['currency'] !== $deposit['currency']) {
            return ['success' => false, 'errors' => [__('dep_err_currency', 'That account is in a different currency to the deposit')]];
        }
    }

    try {
        $conn->prepare("UPDATE deposits
                        SET payout_status = 'pending', payout_type = :type,
                            payout_from_account_id = :acc, payout_requested_by = :by,
                            refund_amount = :amt, notes = CONCAT(COALESCE(notes,''), :note),
                            payout_rejection_reason = NULL
                        WHERE id = :id")
             ->execute([
                 'type' => $type,
                 'acc'  => $type === 'refund' ? (int) $fromAccountId : null,
                 'by'   => getUserId(),
                 'amt'  => $amount,
                 'note' => $notes ? "\n" . $notes : '',
                 'id'   => (int) $depositId,
             ]);
        logActivity('requested', 'deposit', (int) $depositId, ['payout' => $type, 'amount' => $amount]);
        return ['success' => true, 'errors' => []];
    } catch (PDOException $e) {
        return ['success' => false, 'errors' => [$e->getMessage()]];
    }
}

/**
 * Approve the request and post it.
 *   refund   Dr Tenant Deposits Held  Cr Bank or Float
 *   forfeit  Dr Tenant Deposits Held  Cr Deposit Forfeited Income
 */
function depositPayoutApprove($depositId, $userId = null) {
    global $conn;
    $userId = $userId ?? getUserId();

    if (!ledgerCanApprove($userId)) {
        return ['success' => false, 'errors' => [__('led_no_approve_right', 'You do not have approval rights')]];
    }

    $stmt = $conn->prepare('SELECT * FROM deposits WHERE id = ?');
    $stmt->execute([(int) $depositId]);
    $deposit = $stmt->fetch();
    if (!$deposit) return ['success' => false, 'errors' => [__('dep_err_not_found', 'Deposit not found')]];
    if ($deposit['payout_status'] !== 'pending') {
        return ['success' => false, 'errors' => [__('dep_err_not_pending', 'There is nothing awaiting approval on this deposit')]];
    }
    if (!ledgerAllowSelfApproval() && (int) $deposit['payout_requested_by'] === (int) $userId) {
        return ['success' => false, 'errors' => [__('dep_err_self', 'You cannot approve a request you made')]];
    }

    $currency = $deposit['currency'];
    $amount   = round((float) $deposit['refund_amount'], 2);
    $held     = ledgerSystemAccountFor('deposits_held', $currency);
    if (!$held) return ['success' => false, 'errors' => ['No deposits-held account in ' . $currency]];

    if ($deposit['payout_type'] === 'refund') {
        $credit = ledgerAccount($deposit['payout_from_account_id']);
        if (!$credit) return ['success' => false, 'errors' => [__('dep_err_source', 'Choose the bank or float the refund comes from')]];
        if ($credit['system_key'] === 'float' && $amount > ledgerAccountBalance($credit['id']) + 0.005) {
            return ['success' => false, 'errors' => [__('dep_err_float_short', 'That float does not hold enough cash')]];
        }
    } else {
        $credit = ledgerSystemAccountFor('deposit_forfeit_income', $currency);
        if (!$credit) return ['success' => false, 'errors' => ['No forfeit income account in ' . $currency]];
    }

    $ownTransaction = !$conn->inTransaction();
    if ($ownTransaction) $conn->beginTransaction();
    try {
        $entry = ledgerCreateEntry([
            'entry_date'    => date('Y-m-d'),
            'currency'      => $currency,
            'memo'          => sprintf(
                $deposit['payout_type'] === 'refund'
                    ? __('dep_refund_memo', 'Refund of deposit #%s')
                    : __('dep_forfeit_memo', 'Forfeit of deposit #%s'),
                $depositId
            ),
            'source_type'   => 'deposit_payout',
            'source_id'     => (int) $depositId,
            'status'        => 'posted',
            'system_posted' => true,
            'approved_by'   => $userId,
        ], [
            ['account_id' => $held['id'], 'debit' => $amount, 'credit' => 0,
             'unit_id' => $deposit['unit_id'], 'lease_id' => $deposit['lease_id'], 'tenant_id' => $deposit['tenant_id']],
            ['account_id' => $credit['id'], 'debit' => 0, 'credit' => $amount,
             'unit_id' => $deposit['unit_id'], 'lease_id' => $deposit['lease_id'], 'tenant_id' => $deposit['tenant_id']],
        ]);

        if (!$entry['success']) {
            if ($ownTransaction) $conn->rollBack();
            return ['success' => false, 'errors' => $entry['errors']];
        }

        // A part refund leaves the rest still held.
        $fullySettled = $amount >= (float) $deposit['amount'] - 0.005;
        $newStatus = $deposit['payout_type'] === 'forfeit'
            ? 'forfeited'
            : ($fullySettled ? 'refunded' : 'partially_refunded');

        $conn->prepare("UPDATE deposits
                        SET payout_status = 'approved', payout_approved_by = :by, payout_approved_at = NOW(),
                            payout_entry_id = :eid, status = :st, refund_date = CURDATE()
                        WHERE id = :id")
             ->execute(['by' => $userId, 'eid' => $entry['id'], 'st' => $newStatus, 'id' => (int) $depositId]);

        if ($ownTransaction) $conn->commit();
        logActivity('approved', 'deposit', (int) $depositId, ['payout' => $deposit['payout_type'], 'amount' => $amount]);
        return ['success' => true, 'errors' => []];
    } catch (PDOException $e) {
        if ($ownTransaction && $conn->inTransaction()) $conn->rollBack();
        return ['success' => false, 'errors' => [$e->getMessage()]];
    }
}

/** Reject the request. The deposit stays exactly as it was. */
function depositPayoutReject($depositId, $reason, $userId = null) {
    global $conn;
    $userId = $userId ?? getUserId();

    if (!ledgerCanApprove($userId)) {
        return ['success' => false, 'errors' => [__('led_no_approve_right', 'You do not have approval rights')]];
    }
    if (trim((string) $reason) === '') {
        return ['success' => false, 'errors' => [__('led_err_reason', 'A rejection reason is required')]];
    }

    $stmt = $conn->prepare("UPDATE deposits
                            SET payout_status = 'rejected', payout_approved_by = ?, payout_approved_at = NOW(),
                                payout_rejection_reason = ?, refund_amount = NULL
                            WHERE id = ? AND payout_status = 'pending'");
    $stmt->execute([$userId, trim($reason), (int) $depositId]);

    if (!$stmt->rowCount()) {
        return ['success' => false, 'errors' => [__('dep_err_not_pending', 'There is nothing awaiting approval on this deposit')]];
    }
    logActivity('rejected', 'deposit', (int) $depositId, ['reason' => trim($reason)]);
    return ['success' => true, 'errors' => []];
}

/** Deposit payouts waiting for approval. */
function depositPayoutsPending() {
    global $conn;
    try {
        return $conn->query("
            SELECT d.*, CONCAT(t.first_name,' ',t.last_name) AS tenant_name, u.unit_number,
                   ru.full_name AS requested_by_name, a.name AS source_name
            FROM deposits d
            LEFT JOIN tenants t ON t.id = d.tenant_id
            LEFT JOIN units u ON u.id = d.unit_id
            LEFT JOIN users ru ON ru.id = d.payout_requested_by
            LEFT JOIN accounts a ON a.id = d.payout_from_account_id
            WHERE d.payout_status = 'pending'
            ORDER BY d.id
        ")->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** Everything awaiting an approver, for the sidebar badge. */
function payablesPendingCount() {
    global $conn;
    try {
        $deposits = (int) $conn->query("SELECT COUNT(*) FROM deposits WHERE payout_status = 'pending'")->fetchColumn();
        $expenses = (int) $conn->query("SELECT COUNT(*) FROM expenses WHERE payment_status = 'unpaid'
                                        AND (status IS NULL OR status <> 'voided')")->fetchColumn();
        return $deposits + $expenses;
    } catch (PDOException $e) {
        return 0;
    }
}
