<?php
/**
 * FIS Real Estate — one-off repair of opening balances (part 39). CLI only.
 *
 * Two things, both safe to re-run:
 *
 *  1. billed_through backfill. A lease carried in with opening arrears
 *     (charges.source_type = 'opening_balance') gets billed_through = the last
 *     period those arrears cover. Without it the rent generator walks the lease
 *     from start_date and bills those periods a second time, because its
 *     duplicate check only matches its own 'rent_generator' charges.
 *
 *  2. Reclassification. Opening arrears were posted Dr Receivables / Cr Income,
 *     which puts income earned before go-live into this system's P&L. Each such
 *     entry gets a matching Dr Income / Cr Opening Balance Equity on the SAME
 *     date, tagged to the same property/unit/lease, so the income nets to nil
 *     in whichever period it landed. Receivables are untouched.
 *
 * It also REPORTS (changes nothing) active leases without billed_through whose
 * next generator run would raise past periods, so they can be looked at.
 *
 * Runs inside one tenant folder, using that tenant's own config and ledger
 * code, so entry numbering and period locks behave exactly as in the app.
 *
 * Usage, from /home/realcotz:
 *   for d in public_html/re_tenants/*; do php "$d/api/fix-opening-balances.php" --dry-run; done
 *   for d in public_html/re_tenants/*; do php "$d/api/fix-opening-balances.php"; done
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/charges.php';

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);
$slug   = basename(dirname(__DIR__));
$out    = function ($line) { echo $line . PHP_EOL; };

$out(sprintf('--> %s (%s)%s', $slug, DB_NAME, $dryRun ? '  [DRY RUN]' : ''));

$hasColumn = $conn->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leases'
                             AND COLUMN_NAME = 'billed_through'")->fetchColumn();
if (!(int) $hasColumn) {
    $out('    STOP: leases.billed_through missing — run deploy/migrate-tenants.php first');
    exit(1);
}
$hasCharges = $conn->query("SELECT COUNT(*) FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'charges'")->fetchColumn();
if (!(int) $hasCharges) {
    $out('    nothing to do: no ledger tables');
    exit(0);
}

$problems = 0;

// ---- 1. billed_through from opening arrears ----
$rows = $conn->query("
    SELECT l.id, u.unit_number, MAX(COALESCE(c.period_to, c.due_date)) AS covered_to
    FROM leases l
    INNER JOIN units u ON u.id = l.unit_id
    INNER JOIN charges c ON c.lease_id = l.id AND c.source_type = 'opening_balance'
    WHERE l.billed_through IS NULL
    GROUP BY l.id, u.unit_number
")->fetchAll();

$set = $conn->prepare('UPDATE leases SET billed_through = ? WHERE id = ? AND billed_through IS NULL');
foreach ($rows as $r) {
    if (!$dryRun) $set->execute([$r['covered_to'], $r['id']]);
}
$out(sprintf('    billed_through   %s %d lease(s) from their opening arrears',
    $dryRun ? 'would set' : 'set', count($rows)));

// ---- 2. reclassify opening income to Opening Balance Equity ----
// Found through charges.entry_id rather than the entry's source_type, because
// seeded tenants (Tandika) wrote their entries by hand.
$lines = $conn->query("
    SELECT je.id AS entry_id, je.entry_no, je.entry_date, je.currency, je.memo,
           jl.account_id, jl.credit, jl.property_id, jl.unit_id, jl.lease_id, jl.tenant_id
    FROM charges c
    INNER JOIN journal_entries je ON je.id = c.entry_id AND je.status = 'posted'
    INNER JOIN journal_lines jl   ON jl.entry_id = je.id AND jl.credit > 0
    INNER JOIN accounts a         ON a.id = jl.account_id AND a.type = 'income'
    WHERE c.source_type = 'opening_balance'
      AND NOT EXISTS (SELECT 1 FROM journal_entries r
                      WHERE r.source_type = 'opening_reclass' AND r.source_id = je.id
                        AND r.status <> 'rejected')
    ORDER BY je.entry_date, je.id
")->fetchAll();

$byEntry = [];
foreach ($lines as $l) $byEntry[$l['entry_id']][] = $l;

$posted = 0; $totals = [];
foreach ($byEntry as $entryId => $entryLines) {
    $first    = $entryLines[0];
    $currency = $first['currency'];
    $equity   = ledgerSystemAccountFor('opening_equity', $currency);
    if (!$equity) {
        $out("    FAILED           {$first['entry_no']}: no Opening Balance Equity account in {$currency}");
        $problems++;
        continue;
    }

    $journal = [];
    $amount  = 0;
    foreach ($entryLines as $l) {
        $tags = ['property_id' => $l['property_id'], 'unit_id' => $l['unit_id'],
                 'lease_id' => $l['lease_id'], 'tenant_id' => $l['tenant_id']];
        $journal[] = ['account_id' => $l['account_id'], 'debit' => $l['credit'], 'credit' => 0] + $tags;
        $journal[] = ['account_id' => $equity['id'], 'debit' => 0, 'credit' => $l['credit']] + $tags;
        $amount += (float) $l['credit'];
    }
    $totals[$currency] = ($totals[$currency] ?? 0) + $amount;

    if ($dryRun) { $posted++; continue; }

    $result = ledgerCreateEntry([
        'entry_date'    => $first['entry_date'],
        'currency'      => $currency,
        'memo'          => 'Opening balance reclassified to equity — ' . $first['entry_no'],
        'source_type'   => 'opening_reclass',
        'source_id'     => (int) $entryId,
        'status'        => 'posted',
        'system_posted' => true,
    ], $journal);

    if ($result['success']) {
        $posted++;
    } else {
        $out("    FAILED           {$first['entry_no']}: " . implode(' | ', $result['errors']));
        $problems++;
    }
}
$totalText = $totals
    ? implode(', ', array_map(function ($c, $v) { return $c . ' ' . number_format($v, 2); }, array_keys($totals), $totals))
    : 'nothing';
$out(sprintf('    reclassified     %s %d entr%s (%s)',
    $dryRun ? 'would post' : 'posted', $posted, $posted === 1 ? 'y' : 'ies', $totalText));

// ---- 3. report: leases the next generator run would back-bill ----
$asOf    = date('Y-m-d');
$leases  = $conn->query("
    SELECT l.id, l.start_date, l.end_date, l.rent_amount, l.currency, l.payment_frequency, u.unit_number
    FROM leases l INNER JOIN units u ON u.id = l.unit_id
    WHERE l.status = 'active' AND l.billed_through IS NULL AND l.rent_amount > 0
")->fetchAll();
$exists = $conn->prepare("SELECT COUNT(*) FROM charges WHERE lease_id = ? AND type = 'rent'
                          AND period_from = ? AND source_type = 'rent_generator'");
$atRisk = 0;
foreach ($leases as $l) {
    $months = paymentFrequencyMonths($l['payment_frequency']);
    $start  = new DateTime($l['start_date']);
    $end    = new DateTime($l['end_date']);
    $today  = new DateTime($asOf);
    $missing = 0; $guard = 0;
    // Periods that END before today: the current one is billed normally.
    while ($start <= $end && $guard++ < 500) {
        $periodEnd = (clone $start)->modify('+' . $months . ' months')->modify('-1 day');
        if ($periodEnd >= $today) break;
        $exists->execute([$l['id'], $start->format('Y-m-d')]);
        if (!(int) $exists->fetchColumn()) $missing++;
        $start->modify('+' . $months . ' months');
    }
    if ($missing > 0) {
        $atRisk++;
        $out(sprintf('    CHECK            lease %d (unit %s): next run would bill %d past period(s), %s %s',
            $l['id'], $l['unit_number'], $missing, $l['currency'], number_format($missing * $l['rent_amount'], 2)));
    }
}
if (!$atRisk) $out('    back-billing     no active lease would be billed for past periods');

if ($problems) {
    $out("    {$problems} problem(s) — see above");
    exit(1);
}
