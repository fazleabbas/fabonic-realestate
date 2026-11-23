<?php
/**
 * FIS Real Estate — tenant schema migration runner.
 *
 * Applies the TIN + Next of Kin columns to every tenant database. Each tenant
 * has its own MySQL user, so rather than asking for a dozen passwords this reads
 * the credentials out of each tenant's own config/database.php.
 *
 * Passwords are never printed, logged or passed on a command line.
 *
 * The checks are done in PHP rather than SQL so this needs no DELIMITER support
 * and runs fine over PDO.
 *
 * Usage (from /home/realcotz):
 *   php re_tenant_template/deploy/migrate-tenants.php --dry-run
 *   php re_tenant_template/deploy/migrate-tenants.php
 *   php re_tenant_template/deploy/migrate-tenants.php --root=/some/other/re_tenants
 *   php re_tenant_template/deploy/migrate-tenants.php --only=demo
 *
 * Safe to re-run: existing columns are reported as "already present".
 */

// ---- columns this migration adds, in order ----
$COLUMNS = [
    'tin' => "VARCHAR(20) DEFAULT NULL COMMENT 'Taxpayer Identification Number, stored NNN-NNN-NNN when 9 digits' AFTER `emergency_contact_relation`",
    'next_of_kin_name'         => "VARCHAR(150) DEFAULT NULL AFTER `tin`",
    'next_of_kin_relationship' => "VARCHAR(50)  DEFAULT NULL AFTER `next_of_kin_name`",
    'next_of_kin_phone'        => "VARCHAR(20)  DEFAULT NULL AFTER `next_of_kin_relationship`",
    'next_of_kin_address'      => "VARCHAR(255) DEFAULT NULL AFTER `next_of_kin_phone`",
];

// ---- enum values this migration adds ----
$ENUMS = [
    [
        'table'  => 'documents',
        'column' => 'entity_type',
        'value'  => 'expense',
        'definition' => "ENUM('general','property','unit','tenant','lease','payment','deposit','remittance','expense') NOT NULL DEFAULT 'general'",
    ],
    [
        'table'  => 'leases',
        'column' => 'payment_frequency',
        'value'  => 'half_yearly',
        'definition' => "ENUM('monthly','quarterly','yearly','half_yearly') NOT NULL DEFAULT 'monthly'",
    ],
];

// ---- columns added to OTHER tables ----
$OTHER_COLUMNS = [
    'users' => [
        'can_approve' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'May approve ledger entries (part 30)' AFTER `role_id`",
    ],
    'payments' => [
        // Where the money actually went. NULL keeps the old derived behaviour,
        // so existing rows need no backfill (part 38).
        'ledger_account_id' => "INT UNSIGNED DEFAULT NULL COMMENT 'Cash box or bank account chosen on receipt (part 38)'",
    ],
    'deposits' => [
        'ledger_account_id' => "INT UNSIGNED DEFAULT NULL COMMENT 'Cash box or bank account chosen on receipt (part 38)'",
        // Without this a collected deposit cannot be attributed to anyone's float.
        'recorded_by' => "INT UNSIGNED DEFAULT NULL COMMENT 'Who collected it (part 31)' AFTER `notes`",
        // Approval state for money leaving (part 32). Kept on the deposit so a
        // refund can never drift away from the record it belongs to.
        'payout_status'           => "ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none' COMMENT 'Refund/forfeit approval state (part 32)'",
        'payout_type'             => "ENUM('refund','forfeit') DEFAULT NULL",
        'payout_from_account_id'  => "INT UNSIGNED DEFAULT NULL COMMENT 'Bank or float a cash refund comes out of'",
        'payout_requested_by'     => "INT UNSIGNED DEFAULT NULL",
        'payout_approved_by'      => "INT UNSIGNED DEFAULT NULL",
        'payout_approved_at'      => "DATETIME DEFAULT NULL",
        'payout_rejection_reason' => "VARCHAR(255) DEFAULT NULL",
        'payout_entry_id'         => "INT UNSIGNED DEFAULT NULL",
    ],
    // No AFTER clauses below: the expenses table has drifted between tenants
    // (some have a `status` column, some do not), so a positional reference
    // would fail on exactly the tenants that need the column most.
    'expenses' => [
        // Declared in tenant_schema.sql but absent from tenants provisioned
        // before it was added. The payables screen filters on it, so a tenant
        // missing it would 500 rather than show an empty list.
        'status'               => "ENUM('active','voided') NOT NULL DEFAULT 'active'",
        'payment_status'       => "ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid' COMMENT 'Payable settled? (part 32)'",
        'paid_date'            => "DATE DEFAULT NULL",
        'paid_from_account_id' => "INT UNSIGNED DEFAULT NULL COMMENT 'Bank or float the payment came from'",
        'entry_id'             => "INT UNSIGNED DEFAULT NULL COMMENT 'Journal entry recognising the expense'",
    ],
    'expense_categories' => [
        'account_id' => "INT UNSIGNED DEFAULT NULL COMMENT 'Expense account this category posts to (part 32)'",
    ],
    'leases' => [
        // The rent generator only raises periods that start after this date, so
        // a lease carried in with opening arrears is never billed twice. NULL
        // keeps the old behaviour of billing from start_date (part 39).
        'billed_through' => "DATE DEFAULT NULL COMMENT 'Rent billed up to and including this date (part 39)'",
    ],
];

// ---- SQL files applied wholesale (statement by statement, no DELIMITER) ----
$SQL_FILES = [
    'part30_ledger.sql'        => 'accounts',    // chart of accounts + journals
    'part31_charges_float.sql' => 'charges',     // charges, allocation, remittance
    'part32_payables.sql'      => 'accounts',    // default expense account + mapping
    // Moves cheques off the float and onto the bank, but only where the tenant
    // still holds the seeded value — a deliberate choice is left alone.
    'part38_cheque_to_bank.sql' => 'settings',
    'part40_import.sql'         => 'import_batches',  // opening data import batches
];

// ---- arguments ----
$root = '/home/realcotz/public_html/re_tenants';
$dryRun = false;
$only = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run')                  { $dryRun = true; }
    elseif (strpos($arg, '--root=') === 0)     { $root = substr($arg, 7); }
    elseif (strpos($arg, '--only=') === 0)     { $only = substr($arg, 7); }
    else { fwrite(STDERR, "unknown option: {$arg}\n"); exit(1); }
}

if (!is_dir($root)) {
    fwrite(STDERR, "tenants directory not found: {$root}\n");
    exit(1);
}

/**
 * Pull DB_NAME / DB_USER / DB_PASS out of a tenant config WITHOUT including it —
 * including would define constants that then collide on the next tenant.
 */
function readTenantConfig($path) {
    $src = @file_get_contents($path);
    if ($src === false) return null;

    // Strip comments first. Tenant configs carry commented-out decoys such as
    // //define('DB_NAME', 'realcotz_propmanage'); — matching one of those would
    // point this migration at the WRONG database.
    $src = preg_replace('!/\*.*?\*/!s', '', $src);       // /* block */
    $src = preg_replace('!(^|\s)//[^\n]*!', '$1', $src);  // // line
    $src = preg_replace('!(^|\s)#[^\n]*!', '$1', $src);   // # line

    $out = [];
    foreach (['DB_NAME', 'DB_USER', 'DB_PASS', 'DB_HOST'] as $key) {
        if (preg_match('/\bdefine\s*\(\s*[\'"]' . $key . '[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/', $src, $m)) {
            $out[$key] = $m[1];
        }
    }
    if (!isset($out['DB_NAME'], $out['DB_USER'], $out['DB_PASS'])) return null;
    if (!isset($out['DB_HOST'])) $out['DB_HOST'] = 'localhost';
    return $out;
}

$dirs = glob(rtrim($root, '/') . '/*', GLOB_ONLYDIR) ?: [];
if ($only !== null) {
    $dirs = array_filter($dirs, function ($d) use ($only) { return basename($d) === $only; });
}

if (!$dirs) {
    fwrite(STDERR, "no tenant directories found under {$root}\n");
    exit(1);
}

echo $dryRun ? "=== DRY RUN — no changes will be made ===\n\n" : "";
echo 'Scanning ' . count($dirs) . " tenant(s) under {$root}\n\n";

$totalAdded = 0;
$failed = 0;

foreach ($dirs as $dir) {
    $slug = basename($dir);
    $configPath = $dir . '/config/database.php';
    echo "--> {$slug}\n";

    if (!is_file($configPath)) {
        echo "    SKIP: no config/database.php\n";
        $failed++;
        continue;
    }

    $cfg = readTenantConfig($configPath);
    if ($cfg === null) {
        echo "    SKIP: could not read DB credentials from config\n";
        $failed++;
        continue;
    }

    try {
        $pdo = new PDO(
            "mysql:host={$cfg['DB_HOST']};dbname={$cfg['DB_NAME']};charset=utf8mb4",
            $cfg['DB_USER'],
            $cfg['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        // Deliberately does not echo the exception: DSNs can carry credentials.
        echo "    FAIL: cannot connect to {$cfg['DB_NAME']}\n";
        $failed++;
        continue;
    }

    // Does this database even have a tenants table?
    $has = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tenants'");
    $has->execute([$cfg['DB_NAME']]);
    if (!(int) $has->fetchColumn()) {
        echo "    SKIP: no tenants table in {$cfg['DB_NAME']}\n";
        $failed++;
        continue;
    }

    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tenants' AND COLUMN_NAME = ?");

    foreach ($COLUMNS as $col => $definition) {
        $check->execute([$cfg['DB_NAME'], $col]);
        if ((int) $check->fetchColumn()) {
            echo "    already present  {$col}\n";
            continue;
        }

        if ($dryRun) {
            echo "    WOULD ADD        {$col}\n";
            continue;
        }

        try {
            $pdo->exec("ALTER TABLE `tenants` ADD COLUMN `{$col}` {$definition}");
            echo "    added            {$col}\n";
            $totalAdded++;
        } catch (PDOException $e) {
            echo "    FAILED           {$col}: " . $e->getMessage() . "\n";
            $failed++;
        }
    }

    // ---- columns on tables other than `tenants` ----
    foreach ($OTHER_COLUMNS as $table => $cols) {
        foreach ($cols as $col => $definition) {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $exists->execute([$cfg['DB_NAME'], $table, $col]);
            if ((int) $exists->fetchColumn()) {
                echo "    already present  {$table}.{$col}
";
                continue;
            }
            if ($dryRun) { echo "    WOULD ADD        {$table}.{$col}
"; continue; }
            try {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$definition}");
                echo "    added            {$table}.{$col}
";
                $totalAdded++;
            } catch (PDOException $e) {
                echo "    FAILED           {$table}.{$col}: " . $e->getMessage() . "
";
                $failed++;
            }
        }
    }

    // ---- whole SQL files (ledger schema and seeds) ----
    foreach ($SQL_FILES as $file => $probeTable) {
        $path = __DIR__ . '/../sql/' . $file;
        if (!is_file($path)) {
            echo "    SKIP: {$file} not found beside this script
";
            $failed++;
            continue;
        }
        if ($dryRun) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
            $chk->execute([$cfg['DB_NAME'], $probeTable]);
            echo ((int) $chk->fetchColumn())
                ? "    WOULD RE-RUN     {$file} (idempotent)
"
                : "    WOULD APPLY      {$file}
";
            continue;
        }
        try {
            // The file is written to be safely re-runnable, so applying it
            // again on an up-to-date tenant is a no-op rather than an error.
            $sql = file_get_contents($path);

            // Strip whole-line SQL comments FIRST. Left in place they ride
            // along with the statement that follows, and a naive splitter then
            // treats the whole chunk as a comment and skips the statement.
            $sql = preg_replace('/^[ 	]*--.*$/m', '', $sql);

            $statements = array_filter(array_map('trim', preg_split('/;[ 	]*$/m', $sql)));
            $ran = 0;
            foreach ($statements as $stmtSql) {
                if ($stmtSql === '') continue;
                $pdo->exec($stmtSql);
                $ran++;
            }

            // Confirm the file actually did something, so a silent no-op can
            // never be reported as success.
            $verify = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
            $verify->execute([$cfg['DB_NAME'], $probeTable]);
            if (!(int) $verify->fetchColumn()) {
                echo "    FAILED           {$file}: ran {$ran} statement(s) but `{$probeTable}` still missing
";
                $failed++;
            } else {
                echo "    applied          {$file} ({$ran} statements)
";
                $totalAdded++;
            }
        } catch (PDOException $e) {
            echo "    FAILED           {$file}: " . $e->getMessage() . "
";
            $failed++;
        }
    }

    // ---- leases.payment_frequency: add the half_yearly option ----
    // Appended to the end of the enum on purpose: inserting mid-list would
    // renumber the internal index of existing values.
    foreach ($ENUMS as $spec) {
        $table  = $spec['table'];
        $column = $spec['column'];
        $value  = $spec['value'];

        $tblChk = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $tblChk->execute([$cfg['DB_NAME'], $table, $column]);
        $current = $tblChk->fetchColumn();

        if ($current === false) {
            echo "    SKIP: no {$table}.{$column} column\n";
            continue;
        }
        if (strpos($current, "'{$value}'") !== false) {
            echo "    already present  {$table}.{$column} = {$value}\n";
            continue;
        }
        if ($dryRun) {
            echo "    WOULD EXTEND     {$table}.{$column} with '{$value}'\n";
            continue;
        }

        try {
            $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$spec['definition']}");
            echo "    extended         {$table}.{$column} with '{$value}'\n";
            $totalAdded++;
        } catch (PDOException $e) {
            echo "    FAILED           {$table}.{$column}: " . $e->getMessage() . "\n";
            $failed++;
        }
    }
}

echo "\n";
if ($dryRun) {
    echo "Dry run complete — re-run without --dry-run to apply.\n";
} else {
    echo "Done. {$totalAdded} column(s) added.\n";
}
if ($failed) {
    echo "{$failed} problem(s) — see the lines above.\n";
    exit(1);
}
