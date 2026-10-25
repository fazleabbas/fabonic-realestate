<?php
/**
 * FIS Real Estate — Ledger statements (Part 33, Phase 4)
 *
 * Reading the books. Every figure here comes from posted journal lines, so a
 * statement can never disagree with the ledger that produced it — there is no
 * second calculation to drift.
 *
 * Statements are per currency, as decided during alignment: accounts hold one
 * currency each and conversion happens only at presentation, never at posting.
 */

require_once __DIR__ . '/payables.php';

/**
 * Profit & loss for a period.
 *
 * Income and expense accounts only, netted against their normal side, so an
 * income account reads positive when it has earned and an expense positive
 * when it has cost.
 *
 * @param array $filters  from, to, currency, property_id
 */
function plStatement($filters = []) {
    global $conn;

    $currency = $filters['currency'] ?? DEFAULT_CURRENCY;
    $where  = ["e.status = 'posted'", "a.currency = :cur", "a.type IN ('income','expense')"];
    $params = ['cur' => $currency];

    if (!empty($filters['from'])) { $where[] = 'e.entry_date >= :from'; $params['from'] = $filters['from']; }
    if (!empty($filters['to']))   { $where[] = 'e.entry_date <= :to';   $params['to']   = $filters['to']; }
    if (!empty($filters['property_id'])) {
        $where[] = 'l.property_id = :pid';
        $params['pid'] = (int) $filters['property_id'];
    }

    $sql = 'SELECT a.id, a.code, a.name, a.type,
                   COALESCE(SUM(l.debit), 0)  AS debit,
                   COALESCE(SUM(l.credit), 0) AS credit
            FROM journal_lines l
            INNER JOIN journal_entries e ON e.id = l.entry_id
            INNER JOIN accounts a ON a.id = l.account_id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY a.id, a.code, a.name, a.type
            HAVING debit <> 0 OR credit <> 0
            ORDER BY a.type DESC, a.code, a.name';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $income = [];
    $expense = [];
    $totalIncome = 0.0;
    $totalExpense = 0.0;

    foreach ($stmt->fetchAll() as $r) {
        $debit  = (float) $r['debit'];
        $credit = (float) $r['credit'];
        if ($r['type'] === 'income') {
            $r['amount'] = $credit - $debit;   // credit is the earning side
            $totalIncome += $r['amount'];
            $income[] = $r;
        } else {
            $r['amount'] = $debit - $credit;   // debit is the spending side
            $totalExpense += $r['amount'];
            $expense[] = $r;
        }
    }

    return [
        'currency'      => $currency,
        'income'        => $income,
        'expense'       => $expense,
        'total_income'  => $totalIncome,
        'total_expense' => $totalExpense,
        'net'           => $totalIncome - $totalExpense,
    ];
}

/**
 * Balance sheet as at a date.
 *
 * Retained earnings are computed, not stored: everything income and expense
 * has ever done, up to the date. That is what makes the sheet balance without
 * a year-end closing entry.
 */
function balanceSheet($asOf = null, $currency = null) {
    global $conn;

    $asOf     = $asOf ?: date('Y-m-d');
    $currency = $currency ?: DEFAULT_CURRENCY;

    $stmt = $conn->prepare("
        SELECT a.id, a.code, a.name, a.type,
               COALESCE(SUM(l.debit), 0)  AS debit,
               COALESCE(SUM(l.credit), 0) AS credit
        FROM journal_lines l
        INNER JOIN journal_entries e ON e.id = l.entry_id
        INNER JOIN accounts a ON a.id = l.account_id
        WHERE e.status = 'posted' AND e.entry_date <= :asof AND a.currency = :cur
        GROUP BY a.id, a.code, a.name, a.type
        ORDER BY FIELD(a.type,'asset','liability','equity','income','expense'), a.code, a.name
    ");
    $stmt->execute(['asof' => $asOf, 'cur' => $currency]);

    $assets = [];
    $liabilities = [];
    $equity = [];
    $totalAssets = 0.0;
    $totalLiabilities = 0.0;
    $totalEquity = 0.0;
    $retained = 0.0;

    foreach ($stmt->fetchAll() as $r) {
        $debit  = (float) $r['debit'];
        $credit = (float) $r['credit'];

        if ($r['type'] === 'asset') {
            $r['amount'] = $debit - $credit;
            if (abs($r['amount']) < 0.005) continue;
            $totalAssets += $r['amount'];
            $assets[] = $r;
        } elseif ($r['type'] === 'liability') {
            $r['amount'] = $credit - $debit;
            if (abs($r['amount']) < 0.005) continue;
            $totalLiabilities += $r['amount'];
            $liabilities[] = $r;
        } elseif ($r['type'] === 'equity') {
            $r['amount'] = $credit - $debit;
            if (abs($r['amount']) < 0.005) continue;
            $totalEquity += $r['amount'];
            $equity[] = $r;
        } elseif ($r['type'] === 'income') {
            $retained += $credit - $debit;
        } else { // expense
            $retained -= $debit - $credit;
        }
    }

    if (abs($retained) >= 0.005) {
        $equity[] = [
            'id' => null,
            'code' => '',
            'name' => __('rep_retained', 'Retained earnings'),
            'type' => 'equity',
            'amount' => $retained,
            'computed' => true,
        ];
        $totalEquity += $retained;
    }

    return [
        'as_of'             => $asOf,
        'currency'          => $currency,
        'assets'            => $assets,
        'liabilities'       => $liabilities,
        'equity'            => $equity,
        'total_assets'      => $totalAssets,
        'total_liabilities' => $totalLiabilities,
        'total_equity'      => $totalEquity,
        'balanced'          => abs($totalAssets - ($totalLiabilities + $totalEquity)) < LEDGER_EPSILON,
    ];
}

/**
 * Every posted movement on one account, with a running balance.
 * This is the drill-down that makes the other statements trustworthy — any
 * figure can be opened up and traced to the entries behind it.
 */
function accountLedger($accountId, $from = null, $to = null) {
    global $conn;

    $account = ledgerAccount($accountId);
    if (!$account) return null;

    // Everything before the window is folded into an opening balance, so the
    // running balance is meaningful rather than starting from nowhere.
    $opening = 0.0;
    if ($from) {
        $op = $conn->prepare("SELECT COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c
                              FROM journal_lines l
                              INNER JOIN journal_entries e ON e.id = l.entry_id
                              WHERE l.account_id = ? AND e.status = 'posted' AND e.entry_date < ?");
        $op->execute([(int) $accountId, $from]);
        $row = $op->fetch();
        $opening = ledgerNormalSide($account['type']) === 'debit'
            ? (float) $row['d'] - (float) $row['c']
            : (float) $row['c'] - (float) $row['d'];
    }

    $where  = ['l.account_id = :aid', "e.status = 'posted'"];
    $params = ['aid' => (int) $accountId];
    if ($from) { $where[] = 'e.entry_date >= :from'; $params['from'] = $from; }
    if ($to)   { $where[] = 'e.entry_date <= :to';   $params['to']   = $to; }

    $stmt = $conn->prepare('
        SELECT l.*, e.entry_no, e.entry_date, e.memo AS entry_memo, e.source_type, e.source_id,
               p.name AS property_name
        FROM journal_lines l
        INNER JOIN journal_entries e ON e.id = l.entry_id
        LEFT JOIN properties p ON p.id = l.property_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY e.entry_date, e.id, l.line_no
    ');
    $stmt->execute($params);

    $running = $opening;
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $movement = ledgerNormalSide($account['type']) === 'debit'
            ? (float) $r['debit'] - (float) $r['credit']
            : (float) $r['credit'] - (float) $r['debit'];
        $running += $movement;
        $r['running'] = $running;
        $rows[] = $r;
    }

    return [
        'account' => $account,
        'opening' => $opening,
        'rows'    => $rows,
        'closing' => $running,
    ];
}

/**
 * Aged debtors — unsettled charges bucketed by how overdue they are.
 * Read from charges rather than the ledger, because the buckets depend on each
 * charge's own due date rather than when it was posted.
 */
function agedDebtors($asOf = null, $currency = null) {
    global $conn;

    $asOf = $asOf ?: date('Y-m-d');
    $where  = ["c.status IN ('open','part')", 'c.due_date <= :asof'];
    $params = ['asof' => $asOf];
    if ($currency) { $where[] = 'c.currency = :cur'; $params['cur'] = $currency; }

    $stmt = $conn->prepare("
        SELECT c.tenant_id, c.currency,
               CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
               t.phone,
               GROUP_CONCAT(DISTINCT u.unit_number ORDER BY u.unit_number SEPARATOR ', ') AS units,
               SUM(CASE WHEN DATEDIFF(:asof2, c.due_date) <= 30                                    THEN c.amount - c.amount_settled ELSE 0 END) AS b0,
               SUM(CASE WHEN DATEDIFF(:asof3, c.due_date) BETWEEN 31 AND 60                        THEN c.amount - c.amount_settled ELSE 0 END) AS b31,
               SUM(CASE WHEN DATEDIFF(:asof4, c.due_date) BETWEEN 61 AND 90                        THEN c.amount - c.amount_settled ELSE 0 END) AS b61,
               SUM(CASE WHEN DATEDIFF(:asof5, c.due_date) > 90                                     THEN c.amount - c.amount_settled ELSE 0 END) AS b90,
               SUM(c.amount - c.amount_settled) AS total,
               MIN(c.due_date) AS oldest
        FROM charges c
        LEFT JOIN tenants t ON t.id = c.tenant_id
        LEFT JOIN units u ON u.id = c.unit_id
        WHERE " . implode(' AND ', $where) . unitScopeSql('c.unit_id') . "
        GROUP BY c.tenant_id, c.currency, tenant_name, t.phone
        HAVING total > 0
        ORDER BY total DESC
    ");
    $params['asof2'] = $asOf; $params['asof3'] = $asOf;
    $params['asof4'] = $asOf; $params['asof5'] = $asOf;
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $totals = ['b0' => 0.0, 'b31' => 0.0, 'b61' => 0.0, 'b90' => 0.0, 'total' => 0.0];
    foreach ($rows as $r) {
        foreach (array_keys($totals) as $k) $totals[$k] += (float) $r[$k];
    }

    return ['as_of' => $asOf, 'rows' => $rows, 'totals' => $totals];
}

/**
 * A collector's float over a period: what they held, took in, handed over,
 * and are still holding.
 */
function floatStatement($accountId, $from = null, $to = null) {
    global $conn;

    $ledger = accountLedger($accountId, $from, $to);
    if (!$ledger) return null;

    $collected = 0.0;
    $remitted  = 0.0;
    foreach ($ledger['rows'] as $r) {
        if ((float) $r['debit'] > 0)  $collected += (float) $r['debit'];
        if ((float) $r['credit'] > 0) $remitted  += (float) $r['credit'];
    }

    // What has been declared but not yet signed off — money the collector has
    // handed over on paper while the ledger still says they are holding it.
    $pending = 0.0;
    try {
        $p = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM remittances
                             WHERE float_account_id = ? AND status = 'pending'");
        $p->execute([(int) $accountId]);
        $pending = (float) $p->fetchColumn();
    } catch (PDOException $e) {
        // remittances table absent — leave at zero.
    }

    return $ledger + [
        'collected'        => $collected,
        'remitted'         => $remitted,
        'pending_remitted' => $pending,
        'unremitted'       => $ledger['closing'] - $pending,
    ];
}

/** Currencies that actually have posted activity, for statement pickers. */
function ledgerActiveCurrencies() {
    global $conn;
    try {
        $rows = $conn->query("SELECT DISTINCT currency FROM journal_entries WHERE status = 'posted' ORDER BY currency")
                     ->fetchAll(PDO::FETCH_COLUMN);
        return $rows ?: ledgerCurrencies();
    } catch (PDOException $e) {
        return [DEFAULT_CURRENCY];
    }
}

/**
 * Per-unit profitability for a period (Part 37).
 *
 * What a unit earned, what it cost, and what it kept. Income and directly
 * attributed costs come straight from posted journal lines — postExpense() and
 * chargeCreate() have been stamping property_id and unit_id on every line since
 * the ledger went in, so this reads history that already exists.
 *
 * SHARED COSTS. Most real costs are not a unit's: a roof, a guard, the water
 * main. Those post against a property with unit_id NULL. Reporting them nowhere
 * would flatter every unit, so they are apportioned by RENT SHARE — a unit's
 * rent over the total rent of that property's units in the same currency.
 * Chosen because rent_amount is never null, it self-adjusts as rents change,
 * and the unit earning most carries most.
 *
 * Vacant units are deliberately included. A vacant unit still costs you a share
 * of the guard and the roof while earning nothing, so it shows a loss — which
 * is the number that tells you what the vacancy is costing.
 *
 * TIES BACK. Unit income plus unallocated income equals the P&L income for the
 * same property, period and currency; likewise costs. Apportioned shares are
 * rounded to 2dp and the residual given to the largest share, so the column
 * sums to the shared total exactly rather than being out by a few cents.
 *
 * LIMITATION, worth knowing before you quote a figure: the share uses each
 * unit's CURRENT rent, not the rent that applied during the period. Change a
 * rent and last quarter's apportionment shifts. Direct costs and income are
 * unaffected — they are historical ledger lines.
 *
 * @param array $filters  from, to, currency, property_id
 */
function unitProfitability($filters = []) {
    global $conn;

    $currency = $filters['currency'] ?? DEFAULT_CURRENCY;
    $where  = ["e.status = 'posted'", "a.currency = :cur", "a.type IN ('income','expense')"];
    $params = ['cur' => $currency];

    if (!empty($filters['from'])) { $where[] = 'e.entry_date >= :from'; $params['from'] = $filters['from']; }
    if (!empty($filters['to']))   { $where[] = 'e.entry_date <= :to';   $params['to']   = $filters['to']; }
    if (!empty($filters['property_id'])) {
        $where[] = 'l.property_id = :pid';
        $params['pid'] = (int) $filters['property_id'];
    }

    // One pass over the ledger, grouped by the dimensions we report on.
    $sql = 'SELECT l.property_id, l.unit_id, a.type,
                   COALESCE(SUM(l.debit), 0)  AS debit,
                   COALESCE(SUM(l.credit), 0) AS credit
            FROM journal_lines l
            INNER JOIN journal_entries e ON e.id = l.entry_id
            INNER JOIN accounts a ON a.id = l.account_id
            WHERE ' . implode(' AND ', $where) . propertyScopeSql('l.property_id') . '
            GROUP BY l.property_id, l.unit_id, a.type';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $unitIncome = [];      // unit_id  => income
    $unitDirect = [];      // unit_id  => directly attributed cost
    $sharedCost = [];      // prop_id  => cost carrying no unit
    $sharedIncome = [];    // prop_id  => income carrying no unit (rare)
    $noPropIncome = 0.0;   // attributable to neither
    $noPropCost   = 0.0;

    foreach ($stmt->fetchAll() as $r) {
        $debit  = (float) $r['debit'];
        $credit = (float) $r['credit'];
        $amount = $r['type'] === 'income' ? $credit - $debit : $debit - $credit;
        $pid = $r['property_id'] !== null ? (int) $r['property_id'] : null;
        $uid = $r['unit_id']     !== null ? (int) $r['unit_id']     : null;

        if ($pid === null) {
            if ($r['type'] === 'income') $noPropIncome += $amount; else $noPropCost += $amount;
            continue;
        }
        if ($uid === null) {
            if ($r['type'] === 'income') {
                $sharedIncome[$pid] = ($sharedIncome[$pid] ?? 0) + $amount;
            } else {
                $sharedCost[$pid] = ($sharedCost[$pid] ?? 0) + $amount;
            }
            continue;
        }
        if ($r['type'] === 'income') {
            $unitIncome[$uid] = ($unitIncome[$uid] ?? 0) + $amount;
        } else {
            $unitDirect[$uid] = ($unitDirect[$uid] ?? 0) + $amount;
        }
    }

    // Units carrying the apportionment. Vacant ones included on purpose.
    $uWhere  = ['u.currency = :cur'];
    $uParams = ['cur' => $currency];
    if (!empty($filters['property_id'])) {
        $uWhere[] = 'u.property_id = :pid';
        $uParams['pid'] = (int) $filters['property_id'];
    }
    $uStmt = $conn->prepare(
        'SELECT u.id, u.unit_number, u.unit_label, u.property_id, u.rent_amount, u.status,
                p.name AS property_name
         FROM units u
         INNER JOIN properties p ON p.id = u.property_id
         WHERE ' . implode(' AND ', $uWhere) . propertyScopeSql('u.property_id') . '
         ORDER BY p.name, u.unit_number');
    $uStmt->execute($uParams);

    $properties = [];
    foreach ($uStmt->fetchAll() as $u) {
        $pid = (int) $u['property_id'];
        if (!isset($properties[$pid])) {
            $properties[$pid] = [
                'property_id'   => $pid,
                'property_name' => $u['property_name'],
                'shared_cost'   => round((float) ($sharedCost[$pid] ?? 0), 2),
                'shared_income' => round((float) ($sharedIncome[$pid] ?? 0), 2),
                'basis'         => 'rent',
                'units'         => [],
            ];
        }
        $uid = (int) $u['id'];
        $properties[$pid]['units'][] = [
            'unit_id'     => $uid,
            'unit_number' => $u['unit_number'],
            'unit_label'  => $u['unit_label'],
            'status'      => $u['status'],
            'rent_amount' => (float) $u['rent_amount'],
            'income'      => round((float) ($unitIncome[$uid] ?? 0), 2),
            'direct_cost' => round((float) ($unitDirect[$uid] ?? 0), 2),
            'apportioned' => 0.0,
        ];
    }

    // Apportion each property's shared cost across its units.
    foreach ($properties as $pid => &$p) {
        $shared = $p['shared_cost'];
        $count  = count($p['units']);
        if ($count === 0) continue;

        $totalRent = 0.0;
        foreach ($p['units'] as $u) $totalRent += $u['rent_amount'];

        // Every unit on zero rent would divide by zero. Fall back to an equal
        // split and say so, rather than silently apportioning nothing.
        $useRent = $totalRent > 0;
        $p['basis'] = $useRent ? 'rent' : 'equal';

        if (abs($shared) > 0.00001) {
            $running = 0.0; $largest = 0; $largestShare = -1.0;
            foreach ($p['units'] as $i => &$u) {
                $share = $useRent ? ($u['rent_amount'] / $totalRent) : (1 / $count);
                $u['apportioned'] = round($shared * $share, 2);
                $running += $u['apportioned'];
                if ($share > $largestShare) { $largestShare = $share; $largest = $i; }
            }
            unset($u);
            // Rounding residual to the largest share so the column ties exactly.
            $residual = round($shared - $running, 2);
            if (abs($residual) >= 0.01) {
                $p['units'][$largest]['apportioned'] =
                    round($p['units'][$largest]['apportioned'] + $residual, 2);
            }
        }

        $tIncome = 0.0; $tDirect = 0.0; $tApp = 0.0;
        foreach ($p['units'] as &$u) {
            $u['total_cost'] = round($u['direct_cost'] + $u['apportioned'], 2);
            $u['net']        = round($u['income'] - $u['total_cost'], 2);
            $u['margin']     = $u['income'] > 0 ? round($u['net'] / $u['income'] * 100, 1) : null;
            $tIncome += $u['income']; $tDirect += $u['direct_cost']; $tApp += $u['apportioned'];
        }
        unset($u);
        $p['totals'] = [
            'income'      => round($tIncome, 2),
            'direct_cost' => round($tDirect, 2),
            'apportioned' => round($tApp, 2),
            'total_cost'  => round($tDirect + $tApp, 2),
            'net'         => round($tIncome - $tDirect - $tApp, 2),
        ];
    }
    unset($p);

    return [
        'currency'   => $currency,
        'from'       => $filters['from'] ?? null,
        'to'         => $filters['to'] ?? null,
        'properties' => array_values($properties),
        // Reported, never pushed down — so the figures below reconcile to the P&L.
        'unallocated' => [
            'income'        => round($noPropIncome, 2),
            'expense'       => round($noPropCost, 2),
            'shared_income' => round(array_sum($sharedIncome), 2),
        ],
    ];
}
