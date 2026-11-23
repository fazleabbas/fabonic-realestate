<?php
/**
 * Fabonic Real Estate — Master Tenant Cron Runner
 * ------------------------------------------------------------
 * Runs each ACTIVE tenant's per-tenant crons — rent-due reminders and
 * automatic late fees — against that tenant's OWN database.
 *
 * Why this exists: reminder-cron.php and late-fee-cron.php are tenant-side
 * (each connects to one tenant's DB via its own config). In a multi-tenant
 * SaaS, this master script fans out and runs them for every tenant, so you
 * only schedule ONE cron on the master.
 *
 * Each tenant still self-gates: reminder-cron only acts if that tenant has
 * turned reminders on in its settings; late-fee-cron only if late fees are on.
 *
 * Schedule ONE cron on the master (daily, ~8 AM):
 *   0 8 * * * /usr/local/bin/php /home/realcotz/public_html/api/run-tenant-crons.php >/dev/null 2>&1
 *
 * Or run by hand / via web (guarded):
 *   php run-tenant-crons.php
 *   https://real.co.tz/api/run-tenant-crons.php?key=<RUN_TENANT_CRONS_KEY>
 */

$isCli = (php_sapi_name() === 'cli');
require_once __DIR__ . '/../config/database.php';

// Secrets live in config/secrets.php, which is never committed (see
// config/secrets.example.php). Master only.
require_once __DIR__ . '/../includes/secrets.php';
if (!$isCli) {
    if (!appSecretMatches('RUN_TENANT_CRONS_KEY', $_GET['key'] ?? null)) {
        http_response_code(403);
        die('Access denied');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// The per-tenant cron scripts to run, in order.
// Order matters. charge-cron raises each lease's rent charge as it falls due,
// and both of the others read those charges to decide who is overdue — so it
// must run first, or late fees and reminders act on a stale picture.
$TENANT_CRONS = ['charge-cron.php', 'reminder-cron.php', 'late-fee-cron.php'];

function rtcLog($msg) {
    $line = '[' . date('Y-m-d H:i:s') . "] {$msg}\n";
    echo $line;
    @error_log($line, 3, __DIR__ . '/run-tenant-crons.log');
    @flush();
}

rtcLog('=== TENANT CRON RUN START ===');

// 1. Active tenants from the central DB.
try {
    $central = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . CENTRAL_DB_NAME . ';charset=utf8mb4',
        defined('CENTRAL_DB_USER') ? CENTRAL_DB_USER : DB_USER,
        defined('CENTRAL_DB_PASS') ? CENTRAL_DB_PASS : DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $central->query(
        "SELECT tenant_slug FROM subscriptions WHERE status IN ('trial','active') ORDER BY tenant_slug"
    );
    $tenants = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    rtcLog('ERROR: cannot read central DB: ' . $e->getMessage());
    exit(1);
}

rtcLog('Active tenants found: ' . count($tenants));

$php = PHP_BINARY ?: 'php';
$ran = 0;
$skipped = 0;

foreach ($tenants as $slug) {
    $dir = RE_TENANTS_PATH . '/' . $slug;
    if (!is_dir($dir)) {
        rtcLog("  SKIP {$slug}: tenant folder not found");
        $skipped++;
        continue;
    }
    foreach ($TENANT_CRONS as $cronFile) {
        $script = $dir . '/api/' . $cronFile;
        if (!is_file($script)) {
            rtcLog("  SKIP {$slug}/{$cronFile}: script not present");
            continue;
        }
        $out = [];
        $rc = 0;
        // Run the tenant's cron synchronously against its own DB (its script
        // requires ../config/database.php from inside the tenant folder).
        exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $rc);
        $tail = count($out) ? ' | ' . trim(implode(' ', array_slice($out, -2))) : '';
        rtcLog("  {$slug}/{$cronFile}: exit={$rc}{$tail}");
        $ran++;
    }
}

rtcLog("=== DONE: {$ran} cron runs across " . count($tenants) . " tenants, {$skipped} skipped ===");
