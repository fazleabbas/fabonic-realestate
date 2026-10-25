<?php
/**
 * FIS Real Estate — wipe operational data from tenant databases.
 *
 * IRREVERSIBLE. Refuses to delete anything unless --confirm is passed, and takes
 * a mysqldump backup first unless --no-backup is given. Read the dry run first.
 *
 * Two scopes:
 *
 *   transactions  Money and activity only. Payments, deposits, charges, the
 *                 ledger, invoices, inspections, maintenance, documents and
 *                 logs go. Properties, units, tenants, leases, users, settings
 *                 and the chart of accounts stay.
 *
 *   operations    The above PLUS leases, tenants, units and properties — an
 *                 empty system with only users, settings, roles, categories,
 *                 templates and the chart of accounts left standing.
 *
 * Usage (from /home/realcotz):
 *   php re_tenant_template/deploy/wipe-tenant-data.php --only=demo
 *   php re_tenant_template/deploy/wipe-tenant-data.php --only=demo --scope=operations --confirm
 *   php re_tenant_template/deploy/wipe-tenant-data.php --all --confirm
 *   php re_tenant_template/deploy/wipe-tenant-data.php --all --confirm --no-backup
 *
 * Without --confirm it only reports what it would delete.
 */

// Order matters only for readability; foreign key checks are disabled around
// the truncate so a child table can never block its parent.
$TRANSACTIONS = [
    'payment_allocations', 'charges',
    'journal_lines', 'journal_entries', 'remittances',
    'receipts', 'payments',
    'late_fees', 'deposit_deductions', 'deposits',
    'utility_charges', 'utility_readings',
    'gateway_transactions',
    'expenses',
    'inspection_items', 'inspections',
    'maintenance_time_logs', 'maintenance_assignments', 'maintenance_photos',
    'maintenance_notifications', 'maintenance_requests',
    'vacancy_inquiries', 'vacancy_listings',
    'reminder_schedule',
    'documents', 'file_uploads',
    'activity_log', 'notifications',
    // Import batches point at the rows above; left behind, Undo would act on ids
    // that now belong to new records (part 40).
    'import_batch_items', 'import_batches',
];

$OPERATIONS = array_merge($TRANSACTIONS, [
    'leases', 'tenants',
    'unit_photos', 'units',
    'property_photos', 'property_settings', 'user_properties', 'properties',
]);

// Never touched by either scope. Listed so the intent is auditable.
$PRESERVED = [
    'users', 'roles', 'settings',
    'accounts', 'account_mappings',
    'document_categories', 'expense_categories', 'maintenance_categories',
    'utility_types', 'contractors',
    'lease_templates', 'inspection_templates', 'reminder_templates', 'receipt_messages',
    'gateway_config',
];

// ---- arguments ----
$root      = '/home/realcotz/public_html/re_tenants';
$only      = null;
$all       = false;
$scope     = 'transactions';
$confirmed = false;
$backupDir = '/home/realcotz/backups';
$skipBackup = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--confirm')                     { $confirmed = true; }
    elseif ($arg === '--all')                     { $all = true; }
    elseif (strpos($arg, '--only=') === 0)        { $only = substr($arg, 7); }
    elseif (strpos($arg, '--scope=') === 0)       { $scope = substr($arg, 8); }
    elseif (strpos($arg, '--root=') === 0)        { $root = substr($arg, 7); }
    elseif (strpos($arg, '--backup-dir=') === 0)  { $backupDir = substr($arg, 13); }
    elseif ($arg === '--no-backup')               { $skipBackup = true; }
    else { fwrite(STDERR, "unknown option: {$arg}\n"); exit(1); }
}

if (!in_array($scope, ['transactions', 'operations'], true)) {
    fwrite(STDERR, "--scope must be 'transactions' or 'operations'\n");
    exit(1);
}
if (!$only && !$all) {
    fwrite(STDERR, "Refusing to run without a target. Pass --only=<slug> or --all.\n");
    exit(1);
}
if (!is_dir($root)) {
    fwrite(STDERR, "tenants directory not found: {$root}\n");
    exit(1);
}

$TABLES = $scope === 'operations' ? $OPERATIONS : $TRANSACTIONS;

/** Read DB credentials without including the config (constants would collide). */
function readTenantConfig($path) {
    $src = @file_get_contents($path);
    if ($src === false) return null;
    $src = preg_replace('!/\*.*?\*/!s', '', $src);
    $src = preg_replace('!(^|\s)//[^\n]*!', '$1', $src);
    $src = preg_replace('!(^|\s)#[^\n]*!', '$1', $src);
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

/**
 * Dump the database before touching it. The password goes in a 0600 options
 * file rather than the command line, where `ps` would expose it.
 */
function backupDatabase($cfg, $backupDir, $slug) {
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0700, true)) {
        return [false, "cannot create backup directory {$backupDir}"];
    }
    $file = rtrim($backupDir, '/') . '/' . $slug . '-' . date('Ymd-His') . '.sql.gz';
    $optFile = tempnam(sys_get_temp_dir(), 'redump');
    if ($optFile === false) return [false, 'cannot create temporary options file'];

    chmod($optFile, 0600);
    file_put_contents($optFile, "[client]\nuser=" . $cfg['DB_USER'] . "\npassword=\"" . $cfg['DB_PASS'] . "\"\nhost=" . $cfg['DB_HOST'] . "\n");

    $cmd = sprintf('mysqldump --defaults-extra-file=%s --single-transaction --quick %s 2>&1 | gzip > %s',
        escapeshellarg($optFile), escapeshellarg($cfg['DB_NAME']), escapeshellarg($file));
    exec($cmd, $out, $code);
    @unlink($optFile);

    if ($code !== 0 || !is_file($file) || filesize($file) < 100) {
        return [false, 'mysqldump failed' . ($out ? ': ' . implode(' ', array_slice($out, 0, 2)) : '')];
    }
    return [true, $file];
}

$dirs = glob(rtrim($root, '/') . '/*', GLOB_ONLYDIR) ?: [];
if ($only !== null) {
    $dirs = array_filter($dirs, function ($d) use ($only) { return basename($d) === $only; });
}
if (!$dirs) {
    fwrite(STDERR, "no matching tenant found under {$root}\n");
    exit(1);
}

echo str_repeat('=', 66) . "\n";
echo $confirmed ? "  WIPING DATA — this cannot be undone\n" : "  DRY RUN — nothing will be deleted\n";
echo '  scope: ' . $scope . '   tenants: ' . count($dirs) . "\n";
echo str_repeat('=', 66) . "\n\n";

if ($confirmed) {
    echo "Preserved in every case: " . implode(', ', $PRESERVED) . "\n\n";
}

$problems = 0;

foreach ($dirs as $dir) {
    $slug = basename($dir);
    echo "--> {$slug}\n";

    $cfg = readTenantConfig($dir . '/config/database.php');
    if ($cfg === null) { echo "    SKIP: cannot read DB credentials\n"; $problems++; continue; }

    try {
        $pdo = new PDO("mysql:host={$cfg['DB_HOST']};dbname={$cfg['DB_NAME']};charset=utf8mb4",
                       $cfg['DB_USER'], $cfg['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        echo "    FAIL: cannot connect to {$cfg['DB_NAME']}\n"; $problems++; continue;
    }

    // Count what is there, so the dry run is a real preview.
    $present = [];
    $totalRows = 0;
    foreach ($TABLES as $table) {
        try {
            $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            if ($n > 0) { $present[$table] = $n; $totalRows += $n; }
        } catch (PDOException $e) {
            // Table absent in this tenant — nothing to wipe.
        }
    }

    if (!$totalRows) { echo "    already empty\n\n"; continue; }

    foreach ($present as $table => $n) {
        printf("    %-28s %8s rows\n", $table, number_format($n));
    }
    printf("    %-28s %8s rows\n", '(total)', number_format($totalRows));

    if (!$confirmed) { echo "\n"; continue; }

    $info = null;
    if ($skipBackup) {
        echo "    backup skipped (--no-backup)
";
    } else {
        [$ok, $info] = backupDatabase($cfg, $backupDir, $slug);
        if (!$ok) {
            echo "    ABORTED: backup failed — {$info}
";
            echo "    Nothing deleted here. Pass --no-backup to proceed without one.

";
            $problems++;
            continue;
        }
        echo "    backup: {$info}
";
    }

    try {
        // TRUNCATE rather than DELETE so AUTO_INCREMENT restarts and the system
        // looks genuinely new. FK checks are off only for the duration.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_keys($present) as $table) {
            $pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        // Deliberately no unit-status reset here. Under 'transactions' scope
        // leases survive, so a unit's occupied status is still correct; under
        // 'operations' scope the units are deleted anyway. Resetting would have
        // left active leases pointing at units marked vacant.

        echo "    wiped {$totalRows} rows\n\n";
    } catch (PDOException $e) {
        echo "    FAILED: " . $e->getMessage() . "\n";
        echo "    Restore from {$info} if the database is inconsistent.\n\n";
        $problems++;
    }
}

if (!$confirmed) {
    echo "Dry run complete. Add --confirm to actually delete, after reading the counts above.\n";
} else {
    echo "Done." . ($problems ? " {$problems} problem(s) — see above." : '') . "\n";
    echo "Next: run api/charge-cron.php so charges exist again, or arrears will read zero.\n";
}
exit($problems ? 1 : 0);
