<?php
/**
 * FIS Real Estate — reconcile display constants in tenant configs
 *
 * WHY THIS EXISTS
 * ---------------
 * config/database.php is deliberately never synced by sync-tenants.sh, because
 * it holds each tenant's own database credentials. But it also holds constants
 * that are not tenant-specific at all — chiefly which languages the application
 * offers:
 *
 *     define('SUPPORTED_LANGUAGES', ['en', 'sw', 'ar', 'hi', 'ur', 'gu']);
 *     define('RTL_LANGUAGES', ['ar', 'ur']);
 *
 * The consequence is silent: add a language to the template, ship every
 * translated string, and existing tenants still refuse it. getCurrentLang()
 * checks ?lang= against SUPPORTED_LANGUAGES and quietly ignores anything not on
 * the list, so the page renders perfectly — in the wrong language. That is how
 * Hindi, Urdu and Gujarati were shipped complete and never appeared.
 *
 * This script rewrites ONLY the constants listed in $RECONCILE below, to the
 * literal values written there. It never touches DB_* and verifies as much
 * before writing, again after.
 *
 * USAGE
 *   php re_tenant_template/deploy/fix-tenant-constants.php --dry-run
 *   php re_tenant_template/deploy/fix-tenant-constants.php
 *   php re_tenant_template/deploy/fix-tenant-constants.php --only=demo
 *
 *   --dry-run          report what would change, write nothing
 *   --root=PATH        tenants directory (default /home/realcotz/public_html/re_tenants)
 *   --master=PATH      master config, also treated as a target
 *                      (default /home/realcotz/public_html/config/database.php)
 *   --reference=PATH   config to compare the report-only constants against,
 *                      for drift reporting (default: the master config)
 *   --only=SLUG        one tenant
 *   --backup-dir=PATH  where to keep copies (default /home/realcotz/config-backups)
 *   --no-backup        skip the copies
 *
 * Backups deliberately go OUTSIDE the web root. config/ has no .htaccess, and
 * while database.php itself is executed rather than served, a database.php.bak
 * sitting beside it would be served as plain text — credentials and all.
 */

// ---- constants this script is allowed to rewrite -------------------------
// The canonical line is written out in full here rather than read from another
// config, because the file it would have been read from is not guaranteed to
// define these at all — re_tenant_template/config/database.php does not. One
// literal, in one place, that you can read and edit.
//
// Anything not listed here is left alone no matter how far it has drifted.
$RECONCILE = [
    'SUPPORTED_LANGUAGES' => "define('SUPPORTED_LANGUAGES', ['en', 'sw', 'ar', 'hi', 'ur', 'gu']);",
    'RTL_LANGUAGES'       => "define('RTL_LANGUAGES', ['ar', 'ur']);",
];

// ---- constants reported but never changed --------------------------------
// These can legitimately differ per tenant, so drift is information, not a
// fault. Nothing matching a secret is ever listed or printed.
$REPORT_ONLY = [
    'DEFAULT_LANGUAGE',
    'DEFAULT_CURRENCY',
    'APP_CURRENCY_SYMBOL',
    'TIER_BASIC_RATE',
    'TIER_PROFESSIONAL_RATE',
    'TIER_ENTERPRISE_RATE',
    'TRIAL_DAYS',
    'TRIAL_TIER',
];

// Never printed, never rewritten, and verified unchanged across every write.
$PROTECTED = ['DB_NAME', 'DB_USER', 'DB_PASS', 'DB_HOST'];

// ---- arguments -----------------------------------------------------------
$root       = '/home/realcotz/public_html/re_tenants';
$masterPath = '/home/realcotz/public_html/config/database.php';
$referencePath = null;   // defaults to $masterPath once arguments are parsed
$backupDir  = '/home/realcotz/config-backups';
$dryRun     = false;
$noBackup   = false;
$only       = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run')                      { $dryRun = true; }
    elseif ($arg === '--no-backup')                { $noBackup = true; }
    elseif (strpos($arg, '--root=') === 0)         { $root = substr($arg, 7); }
    elseif (strpos($arg, '--master=') === 0)       { $masterPath = substr($arg, 9); }
    elseif (strpos($arg, '--reference=') === 0)    { $referencePath = substr($arg, 12); }
    elseif (strpos($arg, '--only=') === 0)         { $only = substr($arg, 7); }
    elseif (strpos($arg, '--backup-dir=') === 0)   { $backupDir = substr($arg, 13); }
    else { fwrite(STDERR, "unknown option: {$arg}\n"); exit(1); }
}

if ($referencePath === null) $referencePath = $masterPath;

// ---- helpers -------------------------------------------------------------

/**
 * Find the live (uncommented) definition of a constant.
 *
 * Tenant configs carry commented-out decoys — //define('DB_NAME', ...) — so a
 * naive match can land on a dead line. Working line by line and skipping
 * anything that starts with a comment marker avoids that, and also lets us
 * report when a constant is defined more than once.
 *
 * @return array{line:int, text:string}[]
 */
function findDefinitions($src, $name) {
    $out = [];
    foreach (explode("\n", $src) as $i => $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || $trimmed[0] === '#') continue;
        if (substr($trimmed, 0, 2) === '//' || substr($trimmed, 0, 2) === '/*') continue;
        if (substr($trimmed, 0, 1) === '*') continue;
        if (preg_match('/^define\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*,/', $trimmed)) {
            $out[] = ['line' => $i + 1, 'text' => rtrim($line, "\r")];
        }
    }
    return $out;
}

/** The value part of a define line, for display: define('X', <this>); */
function valueOf($lineText) {
    if (preg_match('/^\s*define\s*\(\s*[\'"][A-Z_0-9]+[\'"]\s*,\s*(.*?)\s*\)\s*;/s', $lineText, $m)) {
        return $m[1];
    }
    return '(unparsed)';
}

/** Read protected values without including the file (constants would collide). */
function protectedValues($src, array $keys) {
    $stripped = preg_replace('!/\*.*?\*/!s', '', $src);
    $stripped = preg_replace('!(^|\s)//[^\n]*!', '$1', $stripped);
    $stripped = preg_replace('!(^|\s)\#[^\n]*!', '$1', $stripped);
    $out = [];
    foreach ($keys as $k) {
        if (preg_match('/\bdefine\s*\(\s*[\'"]' . $k . '[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/', $stripped, $m)) {
            $out[$k] = $m[1];
        }
    }
    return $out;
}

// ---- the values to write -------------------------------------------------
$desired = $RECONCILE;

// The reference config is used ONLY to report drift on constants this script
// does not rewrite. If it cannot be read, or does not define them, drift
// reporting is skipped and the rewrite proceeds regardless.
$templateReport = [];
$referenceSrc = @file_get_contents($referencePath);
if ($referenceSrc !== false) {
    foreach ($REPORT_ONLY as $name) {
        $defs = findDefinitions($referenceSrc, $name);
        $templateReport[$name] = count($defs) === 1 ? valueOf($defs[0]['text']) : null;
    }
} else {
    foreach ($REPORT_ONLY as $name) $templateReport[$name] = null;
}

echo $dryRun ? "=== DRY RUN — nothing will be written ===\n\n" : '';
echo "Writing these canonical values:\n";
foreach ($desired as $name => $line) {
    printf("  %-22s %s\n", $name, valueOf($line));
}
echo $referenceSrc === false
    ? "Reference config unreadable ({$referencePath}) — drift reporting skipped.\n\n"
    : "Comparing other constants against {$referencePath}\n\n";

// ---- collect targets -----------------------------------------------------
$targets = [];
foreach ((glob(rtrim($root, '/') . '/*', GLOB_ONLYDIR) ?: []) as $dir) {
    $targets[basename($dir)] = $dir . '/config/database.php';
}
ksort($targets);
if (is_file($masterPath)) {
    $targets['public_html (master)'] = $masterPath;
}
if ($only !== null) {
    $targets = array_filter($targets, function ($k) use ($only) {
        return $k === $only;
    }, ARRAY_FILTER_USE_KEY);
}
if (!$targets) {
    fwrite(STDERR, "no tenant configs found under {$root}\n");
    exit(1);
}

if (!$dryRun && !$noBackup && !is_dir($backupDir)) {
    if (!@mkdir($backupDir, 0700, true)) {
        fwrite(STDERR, "cannot create backup directory: {$backupDir}\n");
        exit(1);
    }
}

// ---- work ----------------------------------------------------------------
$changed = 0; $already = 0; $failed = 0; $drift = [];
$stamp = date('Ymd-His');

foreach ($targets as $slug => $path) {
    echo "--> {$slug}\n";

    $src = @file_get_contents($path);
    if ($src === false) { echo "    SKIP: cannot read config\n"; $failed++; continue; }

    // Sanity: this must actually be a tenant config, not something else.
    $before = protectedValues($src, $PROTECTED);
    if (!isset($before['DB_NAME'])) {
        echo "    SKIP: no DB_NAME — does not look like a tenant config\n";
        $failed++;
        continue;
    }

    $new = $src; $edits = []; $abort = false;

    foreach ($desired as $name => $canonical) {
        $defs = findDefinitions($src, $name);
        if (count($defs) === 0) {
            echo "    FAIL: {$name} is not defined — fix by hand\n"; $abort = true; break;
        }
        if (count($defs) > 1) {
            echo "    FAIL: {$name} defined " . count($defs) . " times (lines "
               . implode(', ', array_column($defs, 'line')) . ") — fix by hand\n";
            $abort = true; break;
        }
        $current = $defs[0]['text'];
        if (rtrim($current) === rtrim($desired[$name])) continue;   // already right

        // Replace that one line only.
        $pos = strpos($new, $current);
        if ($pos === false || strpos($new, $current, $pos + 1) !== false) {
            echo "    FAIL: cannot locate {$name} line uniquely — fix by hand\n";
            $abort = true; break;
        }
        $new = substr($new, 0, $pos) . $desired[$name] . substr($new, $pos + strlen($current));
        $edits[$name] = [valueOf($current), valueOf($desired[$name])];
    }

    if ($abort) { $failed++; continue; }

    // Report drift on constants we do not touch.
    foreach ($REPORT_ONLY as $name) {
        if ($templateReport[$name] === null) continue;
        $defs = findDefinitions($src, $name);
        if (count($defs) !== 1) continue;
        $v = valueOf($defs[0]['text']);
        if ($v !== $templateReport[$name]) {
            $drift[] = sprintf('%s: %s is %s (template %s)', $slug, $name, $v, $templateReport[$name]);
        }
    }

    if (!$edits) { echo "    already correct\n"; $already++; continue; }

    foreach ($edits as $name => $pair) {
        printf("    %s\n        from %s\n          to %s\n", $name, $pair[0], $pair[1]);
    }

    // The guarantee that matters: credentials must survive untouched.
    $after = protectedValues($new, $PROTECTED);
    if ($after !== $before) {
        echo "    FAIL: database credentials would change — aborting this file\n";
        $failed++;
        continue;
    }

    if ($dryRun) { $changed++; continue; }

    if (!$noBackup) {
        $dest = rtrim($backupDir, '/') . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $slug)
              . '-database.php.' . $stamp;
        if (!@copy($path, $dest)) { echo "    FAIL: cannot write backup {$dest}\n"; $failed++; continue; }
        @chmod($dest, 0600);
    }

    if (@file_put_contents($path, $new) === false) {
        echo "    FAIL: cannot write config\n"; $failed++; continue;
    }

    // Read back and confirm what actually landed on disk.
    $check = @file_get_contents($path);
    $ok = ($check === $new) && (protectedValues($check, $PROTECTED) === $before);
    foreach ($desired as $name => $canonical) {
        $d = findDefinitions((string)$check, $name);
        if (count($d) !== 1 || rtrim($d[0]['text']) !== rtrim($desired[$name])) $ok = false;
    }
    if (!$ok) { echo "    FAIL: verification after write failed — restore from backup\n"; $failed++; continue; }

    echo "    written and verified\n";
    $changed++;
}

echo "\n";
if ($drift) {
    echo "Drift on constants this script does not change (informational):\n";
    foreach ($drift as $d) echo "  {$d}\n";
    echo "\n";
}
printf("%s: %d changed, %d already correct, %d failed\n",
    $dryRun ? 'Dry run' : 'Done', $changed, $already, $failed);
if ($dryRun && $changed) echo "Re-run without --dry-run to apply.\n";

exit($failed > 0 ? 1 : 0);
