<?php
/**
 * FIS Real Estate — Double-entry ledger core (Part 30, Phase 1)
 *
 * Every write to the ledger goes through this file. The rules it enforces are
 * the ones that make the ledger worth having:
 *
 *   - an entry whose debits and credits differ is refused, inside the same
 *     transaction that would have written it
 *   - all lines of an entry share one currency, and match their accounts'
 *     currency — there are no cross-currency entries
 *   - a posted entry is immutable: no edit, no delete. A mistake is corrected
 *     by a reversing entry, which is itself a normal entry
 *   - balances are always derived from lines, never stored, so a balance
 *     cannot drift away from the ledger that produced it
 */

if (!defined('LEDGER_EPSILON')) {
    // Money is DECIMAL(14,2); anything under half a cent is float noise.
    define('LEDGER_EPSILON', 0.005);
}

// ============================================================
// Permissions
// ============================================================

/**
 * May a user approve ledger entries? Admin always can; everyone else needs the
 * per-user flag.
 *
 * Read from the database rather than the session, so granting or revoking the
 * right takes effect immediately instead of at the user's next login. Cached
 * per request, so it costs one query.
 */
/**
 * May a person approve their own request? (part 38)
 *
 * Two people signing is the only control here that needs a second pair of
 * eyes rather than leaving evidence — everything else (immutability,
 * reversing entries, period locking) works on one person. But a landlord
 * running the system alone genuinely cannot have two, so this is a per-tenant
 * choice rather than a rule. Allowed by default; a company with staff should
 * turn it off in Ledger > Account Mapping.
 */
function ledgerAllowSelfApproval() {
    return getSetting('allow_self_approval', '1') === '1';
}

function ledgerCanApprove($userId = null) {
    global $conn;
    static $cache = [];

    $userId = $userId ?? getUserId();
    if (!$userId) return false;
    if ($userId === getUserId() && getUserData('role_name') === 'Admin') return true;
    if (array_key_exists($userId, $cache)) return $cache[$userId];

    try {
        $stmt = $conn->prepare('SELECT can_approve, (SELECT name FROM roles WHERE id = users.role_id) AS role_name
                                FROM users WHERE id = ? AND status = \'active\'');
        $stmt->execute([(int) $userId]);
        $row = $stmt->fetch();
        $cache[$userId] = $row ? ($row['role_name'] === 'Admin' || (int) $row['can_approve'] === 1) : false;
    } catch (PDOException $e) {
        // Column missing means the migration has not run — fail closed.
        $cache[$userId] = false;
    }
    return $cache[$userId];
}

/**
 * Everything on or before this date is closed — no entry may be posted into it.
 * Empty means the books are fully open.
 */
function ledgerLockDate() {
    $d = trim((string) getSetting('ledger_lock_date', ''));
    return ($d !== '' && $d !== '0000-00-00') ? $d : null;
}

/**
 * Is this date inside a closed period?
 * Checked at posting time rather than only in the UI, so a cron or an API
 * caller cannot slip an entry into a month that has already been reported on.
 */
function ledgerDateLocked($date) {
    $lock = ledgerLockDate();
    return $lock !== null && $date <= $lock;
}

/** Guard for pages that only approvers may open. */
function ledgerRequireApprover() {
    if (!ledgerCanApprove()) {
        setAlert(__('led_no_approve_right', 'You do not have approval rights'), 'danger');
        redirect('modules/ledger/');
    }
}

// ============================================================
// Accounts
// ============================================================

/** Account types and their normal (increasing) side. */
function ledgerAccountTypes() {
    return [
        'asset'     => 'debit',
        'expense'   => 'debit',
        'liability' => 'credit',
        'equity'    => 'credit',
        'income'    => 'credit',
    ];
}

/** Which side increases this account. */
function ledgerNormalSide($type) {
    $types = ledgerAccountTypes();
    return $types[$type] ?? 'debit';
}

/**
 * Fetch accounts. $filters: type, currency, status, subtype, search, include_archived.
 */
function ledgerAccounts($filters = []) {
    global $conn;
    $where = ['1=1'];
    $params = [];

    if (empty($filters['include_archived'])) {
        $where[] = "a.status = 'active'";
    }
    if (!empty($filters['type'])) {
        $where[] = 'a.type = :type';
        $params['type'] = $filters['type'];
    }
    if (!empty($filters['currency'])) {
        $where[] = 'a.currency = :currency';
        $params['currency'] = $filters['currency'];
    }
    if (!empty($filters['subtype'])) {
        $where[] = 'a.subtype = :subtype';
        $params['subtype'] = $filters['subtype'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(a.name LIKE :s OR a.code LIKE :s)';
        $params['s'] = '%' . $filters['search'] . '%';
    }

    $sql = 'SELECT a.* FROM accounts a WHERE ' . implode(' AND ', $where)
         . " ORDER BY FIELD(a.type,'asset','liability','equity','income','expense'), a.code, a.name";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** One account by id, or null. */
function ledgerAccount($id) {
    global $conn;
    $stmt = $conn->prepare('SELECT * FROM accounts WHERE id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch() ?: null;
}

/**
 * The account a system key currently maps to. Automatic postings use this
 * rather than looking up by system_key, so a client can repoint an event at
 * their own account without the engine changing.
 */
function ledgerMappedAccount($systemKey) {
    global $conn;
    $stmt = $conn->prepare('
        SELECT a.* FROM account_mappings m
        INNER JOIN accounts a ON a.id = m.account_id
        WHERE m.system_key = ? AND a.status = \'active\'
    ');
    $stmt->execute([$systemKey]);
    return $stmt->fetch() ?: null;
}

/**
 * The system account for a key IN A GIVEN CURRENCY, created on demand.
 *
 * Accounts are single-currency by design, but a mapping points at one account.
 * Without this, a lease in USD would silently fail to post against TZS system
 * accounts — the charge would exist while the ledger knew nothing about it.
 */
function ledgerSystemAccountFor($systemKey, $currency) {
    global $conn;

    $mapped = ledgerMappedAccount($systemKey);
    if ($mapped && $mapped['currency'] === $currency) return $mapped;

    $stmt = $conn->prepare("SELECT * FROM accounts
                            WHERE system_key = ? AND currency = ? AND user_id = 0 AND status = 'active'");
    $stmt->execute([$systemKey, $currency]);
    $existing = $stmt->fetch();
    if ($existing) return $existing;

    // Nothing to model the new account on, so we cannot guess its type.
    if (!$mapped) return null;

    try {
        $ins = $conn->prepare("
            INSERT INTO accounts (code, name, type, subtype, currency, is_system, system_key, description)
            VALUES (:code, :name, :type, :subtype, :cur, 1, :key, :desc)
        ");
        $ins->execute([
            'code'    => $mapped['code'] ? $mapped['code'] . '-' . $currency : null,
            'name'    => $mapped['name'] . ' (' . $currency . ')',
            'type'    => $mapped['type'],
            'subtype' => $mapped['subtype'],
            'cur'     => $currency,
            'key'     => $systemKey,
            'desc'    => $mapped['description'],
        ]);
    } catch (PDOException $e) {
        // Raced with another request; fall through and read it back.
    }

    $stmt->execute([$systemKey, $currency]);
    return $stmt->fetch() ?: null;
}

/** All mappings with their current account, for the settings screen. */
function ledgerMappings() {
    global $conn;
    $rows = $conn->query('
        SELECT m.system_key, m.account_id, a.name, a.code, a.type, a.currency, a.status
        FROM account_mappings m
        LEFT JOIN accounts a ON a.id = m.account_id
        ORDER BY m.system_key
    ')->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['system_key']] = $r;
    return $out;
}

/**
 * Balance of one account, derived from its posted lines.
 * Returned signed against the account's normal side, so an asset with more
 * debits than credits is positive.
 */
function ledgerAccountBalance($accountId, $asOf = null) {
    global $conn;
    $account = ledgerAccount($accountId);
    if (!$account) return 0.0;

    $sql = 'SELECT COALESCE(SUM(l.debit),0) AS d, COALESCE(SUM(l.credit),0) AS c
            FROM journal_lines l
            INNER JOIN journal_entries e ON e.id = l.entry_id
            WHERE l.account_id = :aid AND e.status = \'posted\'';
    $params = ['aid' => (int) $accountId];
    if ($asOf) {
        $sql .= ' AND e.entry_date <= :asof';
        $params['asof'] = $asOf;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    $debit = (float) $row['d'];
    $credit = (float) $row['c'];
    return ledgerNormalSide($account['type']) === 'debit'
        ? $debit - $credit
        : $credit - $debit;
}

/**
 * Trial balance for one currency. Only posted entries count.
 * Returns rows plus totals; a correct ledger always has totals equal.
 */
function ledgerTrialBalance($currency, $asOf = null) {
    global $conn;
    // The posted-only filter belongs inside the joined set, not on a LEFT JOIN
    // condition: there it would merely null the entry while the line still
    // contributed its amounts, letting pending and rejected entries into the
    // totals.
    $sql = "
        SELECT a.id, a.code, a.name, a.type, a.currency,
               COALESCE(SUM(l.debit), 0)  AS debit,
               COALESCE(SUM(l.credit), 0) AS credit
        FROM accounts a
        LEFT JOIN (
            SELECT jl.account_id, jl.debit, jl.credit
            FROM journal_lines jl
            INNER JOIN journal_entries je ON je.id = jl.entry_id
            WHERE je.status = 'posted'"
            . ($asOf ? ' AND je.entry_date <= :asof' : '') . "
        ) l ON l.account_id = a.id
        WHERE a.currency = :cur AND (a.status = 'active' OR l.account_id IS NOT NULL)
        GROUP BY a.id, a.code, a.name, a.type, a.currency
        ORDER BY FIELD(a.type,'asset','liability','equity','income','expense'), a.code, a.name";
    $params = ['cur' => $currency];
    if ($asOf) $params['asof'] = $asOf;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $totalDebit = 0.0;
    $totalCredit = 0.0;
    $out = [];
    foreach ($rows as $r) {
        $debit = (float) $r['debit'];
        $credit = (float) $r['credit'];
        // Present each account on one side only — its net position.
        $net = $debit - $credit;
        $r['net_debit']  = $net > 0 ? $net : 0.0;
        $r['net_credit'] = $net < 0 ? -$net : 0.0;
        $totalDebit  += $r['net_debit'];
        $totalCredit += $r['net_credit'];
        $out[] = $r;
    }

    return [
        'rows' => $out,
        'total_debit' => $totalDebit,
        'total_credit' => $totalCredit,
        'balanced' => abs($totalDebit - $totalCredit) < LEDGER_EPSILON,
    ];
}

/** Currencies that actually have accounts, for statement pickers. */
function ledgerCurrencies() {
    global $conn;
    $rows = $conn->query('SELECT DISTINCT currency FROM accounts ORDER BY currency')->fetchAll(PDO::FETCH_COLUMN);
    return $rows ?: [DEFAULT_CURRENCY];
}

// ============================================================
// Journal entries
// ============================================================

/** Next entry number, JE-000001 style. */
function ledgerNextEntryNo() {
    global $conn;
    // Only consider numbers this function itself produced. Reading the last row
    // whatever it looks like meant one odd entry_no — an import, a fixture —
    // sent the sequence back to 1 and straight into a duplicate-key error.
    $last = $conn->query("SELECT entry_no FROM journal_entries
                          WHERE entry_no REGEXP '^JE-[0-9]+$'
                          ORDER BY id DESC LIMIT 1")->fetchColumn();
    $n = ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int) $m[1]) + 1 : 1;
    return 'JE-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

/**
 * Validate a set of lines. Returns an array of error strings; empty means valid.
 * Called before any write, and again inside the transaction.
 *
 * @param array $lines  each: account_id, debit, credit, and optional dimensions
 */
function ledgerValidateLines($lines, $currency) {
    $errors = [];

    $usable = [];
    foreach ($lines as $l) {
        $debit  = round((float) ($l['debit'] ?? 0), 2);
        $credit = round((float) ($l['credit'] ?? 0), 2);
        if (empty($l['account_id']) || ($debit <= 0 && $credit <= 0)) {
            continue; // blank row from the form
        }
        if ($debit > 0 && $credit > 0) {
            $errors[] = __('led_err_both_sides', 'A line cannot have both a debit and a credit');
        }
        if ($debit < 0 || $credit < 0) {
            $errors[] = __('led_err_negative', 'Amounts cannot be negative');
        }
        $usable[] = ['account_id' => (int) $l['account_id'], 'debit' => $debit, 'credit' => $credit] + $l;
    }

    if (count($usable) < 2) {
        $errors[] = __('led_err_two_lines', 'An entry needs at least two lines');
        return $errors; // no point checking further
    }

    $totalDebit  = array_sum(array_column($usable, 'debit'));
    $totalCredit = array_sum(array_column($usable, 'credit'));
    if (abs($totalDebit - $totalCredit) >= LEDGER_EPSILON) {
        $errors[] = sprintf(
            __('led_err_unbalanced', 'Entry does not balance: debits %s, credits %s'),
            number_format($totalDebit, 2),
            number_format($totalCredit, 2)
        );
    }
    if ($totalDebit < LEDGER_EPSILON) {
        $errors[] = __('led_err_zero', 'Entry total cannot be zero');
    }

    // Every account must exist, be active, and share the entry's currency.
    foreach ($usable as $l) {
        $acct = ledgerAccount($l['account_id']);
        if (!$acct) {
            $errors[] = __('led_err_no_account', 'An account on this entry no longer exists');
            continue;
        }
        if ($acct['status'] !== 'active') {
            $errors[] = sprintf(__('led_err_archived', 'Account "%s" is archived'), $acct['name']);
        }
        if ($acct['currency'] !== $currency) {
            $errors[] = sprintf(
                __('led_err_currency', 'Account "%s" is in %s but the entry is in %s'),
                $acct['name'], $acct['currency'], $currency
            );
        }
    }

    return array_values(array_unique($errors));
}

/**
 * Create a journal entry with its lines, inside one transaction.
 *
 * @param array $header  entry_date, currency, memo, source_type, source_id, status
 * @param array $lines   account_id, debit, credit, property_id, unit_id, lease_id, tenant_id, memo
 * @return array         ['success'=>bool, 'id'=>int|null, 'errors'=>string[]]
 */
function ledgerCreateEntry($header, $lines) {
    global $conn;

    $currency = $header['currency'] ?? DEFAULT_CURRENCY;
    $status   = $header['status'] ?? 'pending';
    if (!in_array($status, ['pending', 'posted'], true)) $status = 'pending';

    $errors = ledgerValidateLines($lines, $currency);

    // A closed period is closed to everyone, including automatic postings —
    // otherwise a cron could silently change a month someone has reported on.
    $entryDate = $header['entry_date'] ?? date('Y-m-d');
    if (ledgerDateLocked($entryDate)) {
        $errors[] = sprintf(
            __('led_err_locked_period', 'The books are closed up to %s, so nothing can be posted on %s'),
            formatDate(ledgerLockDate()), formatDate($entryDate)
        );
    }
    if ($errors) return ['success' => false, 'id' => null, 'errors' => $errors];

    // Only approvers may create an already-posted entry (automatic postings in
    // phase 2 pass 'posted' from server-side code, never from user input).
    if ($status === 'posted' && empty($header['system_posted']) && !ledgerCanApprove()) {
        $status = 'pending';
    }

    $usable = [];
    foreach ($lines as $l) {
        $debit  = round((float) ($l['debit'] ?? 0), 2);
        $credit = round((float) ($l['credit'] ?? 0), 2);
        if (empty($l['account_id']) || ($debit <= 0 && $credit <= 0)) continue;
        $usable[] = ['account_id' => (int) $l['account_id'], 'debit' => $debit, 'credit' => $credit] + $l;
    }

    $totalDebit  = array_sum(array_column($usable, 'debit'));
    $totalCredit = array_sum(array_column($usable, 'credit'));

    $ownTransaction = !$conn->inTransaction();
    if ($ownTransaction) $conn->beginTransaction();

    try {
        $stmt = $conn->prepare("
            INSERT INTO journal_entries
                (entry_no, entry_date, currency, memo, source_type, source_id, status,
                 total_debit, total_credit, reverses_entry_id, created_by, approved_by, approved_at)
            VALUES (:no, :dt, :cur, :memo, :stype, :sid, :status,
                    :td, :tc, :rev, :by, :appby, :appat)
        ");
        $stmt->execute([
            'no'     => ledgerNextEntryNo(),
            'dt'     => $header['entry_date'] ?? date('Y-m-d'),
            'cur'    => $currency,
            'memo'   => $header['memo'] ?? null,
            'stype'  => $header['source_type'] ?? 'manual',
            'sid'    => $header['source_id'] ?? null,
            'status' => $status,
            'td'     => $totalDebit,
            'tc'     => $totalCredit,
            'rev'    => $header['reverses_entry_id'] ?? null,
            'by'     => $header['created_by'] ?? getUserId(),
            'appby'  => $status === 'posted' ? ($header['approved_by'] ?? getUserId()) : null,
            'appat'  => $status === 'posted' ? date('Y-m-d H:i:s') : null,
        ]);
        $entryId = (int) $conn->lastInsertId();

        $lineStmt = $conn->prepare("
            INSERT INTO journal_lines
                (entry_id, line_no, account_id, debit, credit, property_id, unit_id, lease_id, tenant_id, memo)
            VALUES (:eid, :ln, :aid, :dr, :cr, :pid, :uid, :lid, :tid, :memo)
        ");
        $n = 1;
        foreach ($usable as $l) {
            $lineStmt->execute([
                'eid'  => $entryId,
                'ln'   => $n++,
                'aid'  => $l['account_id'],
                'dr'   => $l['debit'],
                'cr'   => $l['credit'],
                'pid'  => !empty($l['property_id']) ? (int) $l['property_id'] : null,
                'uid'  => !empty($l['unit_id']) ? (int) $l['unit_id'] : null,
                'lid'  => !empty($l['lease_id']) ? (int) $l['lease_id'] : null,
                'tid'  => !empty($l['tenant_id']) ? (int) $l['tenant_id'] : null,
                'memo' => !empty($l['memo']) ? $l['memo'] : null,
            ]);
        }

        if ($ownTransaction) $conn->commit();

        logActivity('created', 'journal_entry', $entryId, [
            'status' => $status,
            'amount' => $totalDebit,
            'currency' => $currency,
        ]);

        return ['success' => true, 'id' => $entryId, 'errors' => []];
    } catch (PDOException $e) {
        if ($ownTransaction && $conn->inTransaction()) $conn->rollBack();
        return ['success' => false, 'id' => null, 'errors' => ['Database error: ' . $e->getMessage()]];
    }
}

/** One entry with its lines and account names. */
function ledgerEntry($id) {
    global $conn;
    $stmt = $conn->prepare('
        SELECT e.*, u.full_name AS created_by_name, ap.full_name AS approved_by_name
        FROM journal_entries e
        LEFT JOIN users u  ON u.id  = e.created_by
        LEFT JOIN users ap ON ap.id = e.approved_by
        WHERE e.id = ?
    ');
    $stmt->execute([(int) $id]);
    $entry = $stmt->fetch();
    if (!$entry) return null;

    $lineStmt = $conn->prepare('
        SELECT l.*, a.name AS account_name, a.code AS account_code, a.type AS account_type,
               p.name AS property_name
        FROM journal_lines l
        INNER JOIN accounts a ON a.id = l.account_id
        LEFT JOIN properties p ON p.id = l.property_id
        WHERE l.entry_id = ?
        ORDER BY l.line_no
    ');
    $lineStmt->execute([(int) $id]);
    $entry['lines'] = $lineStmt->fetchAll();
    return $entry;
}

/**
 * Approve a pending entry. This is what makes it real: only posted entries
 * appear in balances and the trial balance.
 */
function ledgerApproveEntry($id, $userId = null) {
    global $conn;
    $userId = $userId ?? getUserId();
    $entry = ledgerEntry($id);

    if (!$entry) return ['success' => false, 'errors' => [__('led_err_not_found', 'Entry not found')]];
    if (!ledgerCanApprove($userId)) {
        return ['success' => false, 'errors' => [__('led_no_approve_right', 'You do not have approval rights')]];
    }
    if ($entry['status'] !== 'pending') {
        return ['success' => false, 'errors' => [__('led_err_not_pending', 'Only a pending entry can be approved')]];
    }
    if (!ledgerAllowSelfApproval() && (int) $entry['created_by'] === (int) $userId) {
        return ['success' => false, 'errors' => [__('led_err_self_approve', 'You cannot approve an entry you created')]];
    }

    // Re-validate: an account may have been archived while the entry waited.
    $errors = ledgerValidateLines($entry['lines'], $entry['currency']);
    if ($errors) return ['success' => false, 'errors' => $errors];

    $stmt = $conn->prepare("
        UPDATE journal_entries
        SET status = 'posted', approved_by = :by, approved_at = NOW(), rejection_reason = NULL
        WHERE id = :id AND status = 'pending'
    ");
    $stmt->execute(['by' => $userId, 'id' => (int) $id]);

    logActivity('approved', 'journal_entry', (int) $id, ['entry_no' => $entry['entry_no']]);
    return ['success' => true, 'errors' => []];
}

/** Reject a pending entry with a reason. It never affects any balance. */
function ledgerRejectEntry($id, $reason, $userId = null) {
    global $conn;
    $userId = $userId ?? getUserId();
    $entry = ledgerEntry($id);

    if (!$entry) return ['success' => false, 'errors' => [__('led_err_not_found', 'Entry not found')]];
    if (!ledgerCanApprove($userId)) {
        return ['success' => false, 'errors' => [__('led_no_approve_right', 'You do not have approval rights')]];
    }
    if ($entry['status'] !== 'pending') {
        return ['success' => false, 'errors' => [__('led_err_not_pending', 'Only a pending entry can be rejected')]];
    }
    if (trim((string) $reason) === '') {
        return ['success' => false, 'errors' => [__('led_err_reason', 'A rejection reason is required')]];
    }

    $stmt = $conn->prepare("
        UPDATE journal_entries
        SET status = 'rejected', approved_by = :by, approved_at = NOW(), rejection_reason = :r
        WHERE id = :id AND status = 'pending'
    ");
    $stmt->execute(['by' => $userId, 'r' => trim($reason), 'id' => (int) $id]);

    logActivity('rejected', 'journal_entry', (int) $id, ['reason' => trim($reason)]);
    return ['success' => true, 'errors' => []];
}

/**
 * Reverse a posted entry by creating its mirror. The original is never
 * touched beyond being marked as reversed — that is what immutability means.
 */
function ledgerReverseEntry($id, $date = null, $memo = null) {
    global $conn;
    $entry = ledgerEntry($id);

    if (!$entry) return ['success' => false, 'id' => null, 'errors' => [__('led_err_not_found', 'Entry not found')]];
    if ($entry['status'] !== 'posted') {
        return ['success' => false, 'id' => null, 'errors' => [__('led_err_reverse_posted', 'Only a posted entry can be reversed')]];
    }
    if (!empty($entry['reversed_by_entry_id'])) {
        return ['success' => false, 'id' => null, 'errors' => [__('led_err_already_reversed', 'This entry has already been reversed')]];
    }

    $mirror = [];
    foreach ($entry['lines'] as $l) {
        $mirror[] = [
            'account_id'  => $l['account_id'],
            'debit'       => (float) $l['credit'],   // sides swapped
            'credit'      => (float) $l['debit'],
            'property_id' => $l['property_id'],
            'unit_id'     => $l['unit_id'],
            'lease_id'    => $l['lease_id'],
            'tenant_id'   => $l['tenant_id'],
            'memo'        => $l['memo'],
        ];
    }

    $ownTransaction = !$conn->inTransaction();
    if ($ownTransaction) $conn->beginTransaction();
    try {
        $result = ledgerCreateEntry([
            'entry_date'        => $date ?: date('Y-m-d'),
            'currency'          => $entry['currency'],
            'memo'              => $memo ?: sprintf(__('led_reversal_of', 'Reversal of %s'), $entry['entry_no']),
            'source_type'       => 'reversal',
            'source_id'         => (int) $id,
            'status'            => 'pending',
            'reverses_entry_id' => (int) $id,
        ], $mirror);

        if (!$result['success']) {
            if ($ownTransaction) $conn->rollBack();
            return $result;
        }

        $conn->prepare('UPDATE journal_entries SET reversed_by_entry_id = ? WHERE id = ?')
             ->execute([$result['id'], (int) $id]);

        if ($ownTransaction) $conn->commit();
        logActivity('reversed', 'journal_entry', (int) $id, ['reversal_entry_id' => $result['id']]);
        return $result;
    } catch (PDOException $e) {
        if ($ownTransaction && $conn->inTransaction()) $conn->rollBack();
        return ['success' => false, 'id' => null, 'errors' => ['Database error: ' . $e->getMessage()]];
    }
}

/** How many entries are waiting for approval — for the sidebar badge. */
function ledgerPendingCount() {
    global $conn;
    try {
        return (int) $conn->query("SELECT COUNT(*) FROM journal_entries WHERE status = 'pending'")->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/** Human label for a status. */
function ledgerStatusLabel($status) {
    $map = [
        'pending'  => __('led_pending', 'Pending'),
        'posted'   => __('led_posted', 'Posted'),
        'rejected' => __('led_rejected', 'Rejected'),
    ];
    return $map[$status] ?? ucfirst($status);
}
