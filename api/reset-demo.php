<?php
/**
 * Fabonic Real Estate — Demo Tenant Reset
 * ------------------------------------------------------------
 * Wipes the demo.real.co.tz database back to a clean, fully-loaded
 * sample state. Intended to run nightly from cron so the public demo
 * always looks fresh no matter what visitors do to it.
 *
 * Run:
 *   CLI : php reset-demo.php
 *   Cron: /usr/bin/php /home/realcotz/public_html/api/reset-demo.php
 *   Web : https://app.real.co.tz/api/reset-demo.php?key=<RE_DEMO_RESET_KEY>
 *
 * SETUP: fill in the three DEMO_DB_* constants below with the demo
 * tenant's own database name / user / password (created when the demo
 * tenant was provisioned). See DEPLOY_REAL_CO_TZ.md.
 * ------------------------------------------------------------
 */

// ---- Access guard (web) --------------------------------------------------
// Secrets live in config/secrets.php, which is never committed (see
// config/secrets.example.php). Master only.
require_once __DIR__ . '/../includes/secrets.php';
if (php_sapi_name() !== 'cli') {
    if (!appSecretMatches('RE_DEMO_RESET_KEY', $_GET['key'] ?? null)) {
        http_response_code(403);
        die('Access denied');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// ---- Demo database credentials (SET THESE ON DEPLOY) ---------------------
define('DEMO_DB_HOST', 'localhost');
define('DEMO_DB_NAME', 'realcotz_re_demo');   // demo tenant DB
define('DEMO_DB_USER', 'realcotz_redemo');    // demo tenant DB user

// ---- Demo admin login (advertised on the landing page) -------------------
define('DEMO_ADMIN_USER', 'demo');
define('DEMO_ADMIN_NAME', 'Demo Administrator');
define('DEMO_COMPANY_NAME', 'Fabonic Demo Properties');

$SCHEMA_FILE = __DIR__ . '/../sql/tenant_schema.sql';
$SEED_FILE   = __DIR__ . '/../sql/demo_seed.sql';

// Operational tables cleared on every reset. Reference/config tables
// (roles, settings, *_templates, *_categories, utility_types) are kept so
// the app's defaults survive.
$TRUNCATE_TABLES = [
    // Ledger (parts 30-31). Without these the nightly reset would re-seed fresh
    // leases and tenants while leaving charges and journal entries behind,
    // pointing at rows that no longer exist — a demo ledger that never balances.
    // `accounts` and `account_mappings` are deliberately NOT here: the chart of
    // accounts is configuration, not demo data.
    'payment_allocations', 'charges',
    'journal_lines', 'journal_entries', 'remittances',
    'payments', 'receipts', 'late_fees', 'deposit_deductions', 'deposits',
    'leases', 'inspection_items', 'inspections',
    'utility_charges', 'utility_readings',
    'maintenance_notifications', 'maintenance_assignments', 'maintenance_photos', 'maintenance_requests',
    'expenses', 'vacancy_inquiries', 'vacancy_listings',
    'gateway_transactions', 'documents', 'file_uploads',
    'activity_log', 'notifications',
    'unit_photos', 'units', 'property_photos', 'property_settings', 'properties',
    'tenants', 'users',
    'import_batch_items', 'import_batches',
];

// Setup state a visitor may have changed on the Data Import page. Left set, one
// click on "Finish setup" would close the demo ledger for every later visitor.
$RESET_SETTINGS = ['go_live_date', 'setup_finished_at', 'setup_finished_by', 'ledger_lock_date'];

function out($msg) {
    echo '[' . date('H:i:s') . "] {$msg}\n";
    @flush();
}

// ---- Run one .sql file through PDO (simple statement splitter) ------------
function runSqlFile(PDO $pdo, $path) {
    if (!is_file($path)) {
        out("SQL file not found: {$path}");
        return false;
    }
    $sql = file_get_contents($path);
    // Strip line comments (-- ...) so semicolons inside comments don't confuse us.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    // Split on semicolon followed by newline. Our seed/schema use no procedures,
    // stored routines, or semicolons inside string values, so this is safe.
    $statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]/', $sql)));
    $count = 0;
    foreach ($statements as $stmt) {
        if ($stmt === '' || strtoupper(substr($stmt, 0, 2)) === '/*') continue;
        $pdo->exec($stmt);
        $count++;
    }
    out("  executed {$count} statements from " . basename($path));
    return true;
}

// ==========================================================================
out('=== DEMO RESET START ===');

// Loads config/secrets.php. The cron runs from the command line and never reaches
// the key check above, so without this the password constants would not exist.
if (appSecret('DEMO_DB_PASS') === '' || appSecret('DEMO_ADMIN_PASS') === '') {
    out('ERROR: DEMO_DB_PASS / DEMO_ADMIN_PASS missing from config/secrets.php (run deploy/make-secrets.php).');
    exit(1);
}

try {
    $pdo = new PDO(
        'mysql:host=' . DEMO_DB_HOST . ';dbname=' . DEMO_DB_NAME . ';charset=utf8mb4',
        DEMO_DB_USER, DEMO_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (PDOException $e) {
    out('ERROR: cannot connect to demo DB: ' . $e->getMessage());
    exit(1);
}

// 1) Ensure schema exists (first-ever run on an empty DB).
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (count($tables) === 0) {
    out('Empty database — importing base schema...');
    runSqlFile($pdo, $GLOBALS['SCHEMA_FILE']);
}

// 2) Wipe operational data.
out('Clearing operational tables...');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($TRUNCATE_TABLES as $t) {
    try {
        $pdo->exec("TRUNCATE TABLE `{$t}`");
    } catch (PDOException $e) {
        out("  (skip {$t}: " . $e->getMessage() . ')');
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
try {
    $in = implode(',', array_fill(0, count($RESET_SETTINGS), '?'));
    $pdo->prepare("UPDATE settings SET setting_value = '' WHERE setting_key IN ({$in})")->execute($RESET_SETTINGS);
} catch (PDOException $e) {
    out('  (skip settings reset: ' . $e->getMessage() . ')');
}

// 3) Reload sample data.
out('Loading demo sample data...');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
runSqlFile($pdo, $GLOBALS['SEED_FILE']);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// 4) (Re)create the demo admin login with id = 1 (matches payments.recorded_by).
out('Creating demo admin user (' . DEMO_ADMIN_USER . ')...');
$hash = password_hash(DEMO_ADMIN_PASS, PASSWORD_DEFAULT);
$stmt = $pdo->prepare(
    "INSERT INTO users (id, username, full_name, email, phone, password_hash, role_id, status)
     VALUES (1, :u, :n, :e, :p, :h, 1, 'active')
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), status = 'active'"
);
$stmt->execute([
    'u' => DEMO_ADMIN_USER,
    'n' => DEMO_ADMIN_NAME,
    'e' => 'demo@real.co.tz',
    'p' => '255786110021',
    'h' => $hash,
]);

// 5) Friendly company name in settings (table + rows are created by schema).
try {
    $stmt = $pdo->prepare("UPDATE settings SET setting_value = :v WHERE setting_key = 'company_name'");
    $stmt->execute(['v' => DEMO_COMPANY_NAME]);
} catch (PDOException $e) {
    out('  (settings update skipped: ' . $e->getMessage() . ')');
}

out('=== DEMO RESET COMPLETE ===');
out('Login: ' . DEMO_ADMIN_USER . ' / ' . DEMO_ADMIN_PASS . ' at https://demo.real.co.tz/');
