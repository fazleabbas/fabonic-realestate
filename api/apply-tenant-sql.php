<?php
/**
 * Fabonic Real Estate — Tenant Migration Runner
 * ------------------------------------------------------------
 * Applies an idempotent .sql migration to EVERY tenant database.
 *
 * Why this exists: several sql/part*.sql migrations were never folded into
 * sql/tenant_schema.sql, so tenants provisioned from the schema are missing the
 * columns/tables those files add. The tenant portal was the first symptom —
 * portal login 500'd on a missing `tenants.portal_last_login`.
 *
 * Same two modes as the seeders, because the master does NOT know each tenant's
 * DB password (those live only in the tenant's own config):
 *
 *   MASTER MODE   (config defines RE_TENANTS_PATH — i.e. public_html)
 *       Lists tenants (central DB + any folder on disk) and executes each
 *       tenant's OWN copy of this script with --self.
 *
 *   SELF MODE     (--self, or a config with no RE_TENANTS_PATH)
 *       Applies the migration to the database this instance is configured for.
 *
 * ONLY run files that are safe to re-apply — i.e. every statement uses
 * IF NOT EXISTS / IF EXISTS. The part16 file qualifies. Statements that fail
 * are reported per tenant and do not abort the run.
 *
 * Usage (from the master):
 *   php .../apply-tenant-sql.php --file=sql/part16_tenant_portal.sql
 *   php .../apply-tenant-sql.php --file=sql/part16_tenant_portal.sql --dry-run
 *   php .../apply-tenant-sql.php --file=sql/part16_tenant_portal.sql --slug=demo
 *
 * Or via browser (guarded):
 *   https://real.co.tz/api/apply-tenant-sql.php?key=<APPLY_TENANT_SQL_KEY>&file=sql/part16_tenant_portal.sql
 */

$isCli = (php_sapi_name() === 'cli');

$argvList  = $isCli ? array_slice($_SERVER['argv'] ?? [], 1) : [];
$hasFlag   = function (string $flag) use ($isCli, $argvList) {
    return $isCli ? in_array('--' . $flag, $argvList, true) : isset($_GET[$flag]);
};
$flagValue = function (string $flag) use ($isCli, $argvList) {
    if (!$isCli) {
        return trim((string)($_GET[$flag] ?? ''));
    }
    foreach ($argvList as $arg) {
        if (strpos($arg, '--' . $flag . '=') === 0) {
            return substr($arg, strlen($flag) + 3);
        }
    }
    return '';
};

$selfMode = $hasFlag('self');
$dryRun   = $hasFlag('dry-run');
$onlySlug = $flagValue('slug');
$relFile  = $flagValue('file');

if (!$isCli) {
    require_once __DIR__ . '/../includes/secrets.php';
    if (!appSecretMatches('APPLY_TENANT_SQL_KEY', $_GET['key'] ?? null)) {
        http_response_code(403);
        die('Access denied');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

function atsLog(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . "] {$msg}\n";
    echo $line;
    @error_log($line, 3, __DIR__ . '/apply-tenant-sql.log');
    @flush();
}

if ($relFile === '') {
    atsLog('ERROR: --file=<path relative to app root> is required');
    exit(1);
}

// Resolve inside this instance's own app root, and refuse to escape it.
$appRoot = realpath(__DIR__ . '/..');
$sqlPath = realpath($appRoot . '/' . ltrim($relFile, '/\\'));
if ($sqlPath === false || strpos($sqlPath, $appRoot) !== 0 || !is_file($sqlPath)) {
    atsLog("ERROR: file not found or outside the app root: {$relFile}");
    exit(1);
}

/**
 * Split a .sql file into statements, ignoring semicolons inside quotes and
 * stripping -- line comments.
 */
function atsSplitStatements(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $out = [];
    $buf = '';
    $inS = false;   // '
    $inD = false;   // "
    $inB = false;   // `
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($c === '\\' && ($inS || $inD)) {      // escaped char inside a string
            $buf .= $c . ($sql[$i + 1] ?? '');
            $i++;
            continue;
        }
        if ($c === "'" && !$inD && !$inB) $inS = !$inS;
        elseif ($c === '"' && !$inS && !$inB) $inD = !$inD;
        elseif ($c === '`' && !$inS && !$inD) $inB = !$inB;

        if ($c === ';' && !$inS && !$inD && !$inB) {
            if (trim($buf) !== '') $out[] = trim($buf);
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}

require_once __DIR__ . '/../config/database.php';

// =========================================================================
// SELF MODE
// =========================================================================
if ($selfMode || !defined('RE_TENANTS_PATH')) {
    $label = defined('TENANT_SLUG') ? TENANT_SLUG : 'local';
    $statements = atsSplitStatements(file_get_contents($sqlPath));

    $ok = 0;
    $failed = 0;
    foreach ($statements as $stmt) {
        if ($dryRun) {
            $ok++;
            continue;
        }
        try {
            $conn->exec($stmt);
            $ok++;
        } catch (PDOException $e) {
            $failed++;
            atsLog("  [{$label}] FAILED: " . str_replace("\n", ' ', substr($stmt, 0, 70))
                . ' ... -> ' . $e->getMessage());
        }
    }

    $prefix = $dryRun ? 'DRY-RUN ' : '';
    atsLog("{$prefix}[{$label}] statements_ok={$ok} failed={$failed} (" . basename($sqlPath) . ')');
    exit($failed > 0 ? 1 : 0);
}

// =========================================================================
// MASTER MODE
// =========================================================================
atsLog('=== APPLY ' . basename($sqlPath) . ' TO ALL TENANTS ===');

$tenants = [];
try {
    $central = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . CENTRAL_DB_NAME . ';charset=utf8mb4',
        defined('CENTRAL_DB_USER') ? CENTRAL_DB_USER : DB_USER,
        defined('CENTRAL_DB_PASS') ? CENTRAL_DB_PASS : DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $tenants = $central->query(
        "SELECT tenant_slug FROM subscriptions WHERE status IN ('trial','active') ORDER BY tenant_slug"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    atsLog('WARNING: cannot read central DB (' . $e->getMessage() . ') — using on-disk folders only');
}

// Include any tenant folder on disk that central didn't list (e.g. demo).
$onDisk = [];
foreach (glob(RE_TENANTS_PATH . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
    if (is_file($dir . '/index.php')) {
        $onDisk[] = basename($dir);
    }
}
$missing = array_diff($onDisk, $tenants);
if ($missing) {
    atsLog('Also found on disk but not active in central: ' . implode(', ', $missing));
    $tenants = array_merge($tenants, $missing);
}

if ($onlySlug !== '') {
    $tenants = array_values(array_filter($tenants, function ($s) use ($onlySlug) {
        return $s === $onlySlug;
    }));
    if (!$tenants) {
        atsLog("ERROR: tenant '{$onlySlug}' not found");
        exit(1);
    }
}

atsLog('Tenants to migrate: ' . count($tenants) . ($dryRun ? ' (DRY RUN)' : ''));

$php     = PHP_BINARY ?: 'php';
$done    = 0;
$skipped = 0;
$errors  = 0;

foreach ($tenants as $slug) {
    $script = RE_TENANTS_PATH . '/' . $slug . '/api/apply-tenant-sql.php';
    if (!is_file($script)) {
        atsLog("  SKIP {$slug}: runner not present in tenant folder (copy this file across first)");
        $skipped++;
        continue;
    }

    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script)
         . ' --self --file=' . escapeshellarg($relFile);
    if ($dryRun) {
        $cmd .= ' --dry-run';
    }

    $out = [];
    $rc  = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    if ($rc !== 0) $errors++;
    $tail = count($out) ? ' | ' . trim(implode(' ', array_slice($out, -1))) : '';
    atsLog("  {$slug}: exit={$rc}{$tail}");
    $done++;
}

atsLog("=== DONE: {$done} tenants, {$skipped} skipped, {$errors} with errors ===");
