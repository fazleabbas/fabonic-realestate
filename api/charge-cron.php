<?php
/**
 * FIS Real Estate — Rent charge generator (Part 31)
 *
 * Raises the rent charge for each active lease as its period comes due, and
 * recognises the income in the ledger. Everything downstream — arrears, late
 * fees, reminders, the tenant portal — now reads those charges, so this must
 * run before the late-fee and reminder crons each day.
 *
 * Idempotent: a unique index on (lease, type, period, source) means running it
 * twice raises nothing twice. Safe to run by hand at any time.
 *
 * Cron (daily, ahead of the others):
 *   0 6 * * * /usr/bin/php /home/realcotz/public_html/re_tenants/<slug>/api/charge-cron.php key=<guard>
 */

// Guard: same pattern as the other crons, so the endpoint is not open to the web.
$cliMode = (php_sapi_name() === 'cli');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/charges.php';

if (!$cliMode) {
    $providedKey = $_GET['key'] ?? '';
    $expectedKey = getSetting('cron_key', '');   // shared with the other crons
    if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$asOf    = $_GET['as_of'] ?? ($argv[1] ?? null);
$leaseId = isset($_GET['lease_id']) ? (int) $_GET['lease_id'] : null;

$started = microtime(true);
$result  = generateRentCharges($asOf, $leaseId);
$elapsed = round(microtime(true) - $started, 2);

$summary = sprintf(
    'Charge generator: %d raised, %d already present, %d failed, across %d active lease(s) in %ss',
    $result['created'], $result['skipped'], $result['failed'], $result['leases'], $elapsed
);

// A silent failure count is useless at 6am in a log. Say why.
if (!empty($result['reasons'])) {
    foreach ($result['reasons'] as $reason => $n) {
        $summary .= sprintf("
  %d x %s", $n, $reason);
    }
}

logActivity('generated', 'charge', null, $result);

if ($cliMode) {
    // error_log() already writes to stderr under CLI, so logging as well would
    // print the summary twice in cron output.
    echo $summary . PHP_EOL;
} else {
    error_log($summary);
    header('Content-Type: text/plain; charset=utf-8');
    echo $summary . PHP_EOL;
}
