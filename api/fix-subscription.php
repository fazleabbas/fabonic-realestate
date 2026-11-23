<?php
/**
 * Fabonic Real Estate — Subscription Diagnostic & Repair
 *
 * Answers the question "why does this tenant see 'No active subscription found'?"
 * and repairs it. A tenant is blocked by that message for exactly three reasons:
 *
 *   1. NO ROW      — provisioning created the instance but the central
 *                    `subscriptions` insert failed (it used to fail silently).
 *   2. SLUG MISMATCH — the row exists under a different tenant_slug than the one
 *                    baked into the tenant's config, so the lookup never matches.
 *   3. NO ACCESS   — the tenant's config lacks (or has wrong) CENTRAL_DB_USER /
 *                    CENTRAL_DB_PASS, so it cannot read the central DB at all.
 *                    The row exists; the tenant simply can't see it.
 *
 * Usage (master instance only):
 *   Report on every tenant:   php api/fix-subscription.php
 *   Report on one tenant:     php api/fix-subscription.php acme
 *   Repair one tenant:        php api/fix-subscription.php acme fix
 *   Repair every tenant:      php api/fix-subscription.php all fix
 *
 *   Web: /api/fix-subscription.php?key=<REPAIR_KEY>[&slug=acme][&fix=yes]
 */


if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../includes/secrets.php';
    if (!appSecretMatches('REPAIR_KEY', $_GET['key'] ?? null)) {
        http_response_code(403);
        die('Access denied');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../config/database.php';

if (!defined('IS_SAAS_MASTER') || !IS_SAAS_MASTER) {
    die("This tool runs on the master instance only.\n");
}

// ============================================================
// Arguments
// ============================================================

if (php_sapi_name() === 'cli') {
    $targetSlug = isset($argv[1]) && $argv[1] !== 'all' ? strtolower($argv[1]) : null;
    $doFix      = isset($argv[2]) && $argv[2] === 'fix';
} else {
    $targetSlug = isset($_GET['slug']) && $_GET['slug'] !== '' ? strtolower($_GET['slug']) : null;
    $doFix      = isset($_GET['fix']) && $_GET['fix'] === 'yes';
}

$out = function ($line = '') { echo $line . "\n"; };

// ============================================================
// Central DB (master credentials)
// ============================================================

try {
    $central = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("Cannot reach the central DB with master credentials: " . $e->getMessage() . "\n");
}

// ============================================================
// Tenant discovery — read each deployed tenant's own config
// ============================================================

/**
 * Pull a define('NAME', 'value') out of a tenant config without executing it
 * (we cannot include it — the constants would collide with the master's).
 */
function readDefine($source, $name) {
    if (preg_match("/define\(\s*'" . preg_quote($name, '/') . "'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'\s*\)/", $source, $m)) {
        return stripslashes($m[1]);
    }
    // define('CENTRAL_DB_USER', DB_USER) — a constant alias, not a literal
    if (preg_match("/define\(\s*'" . preg_quote($name, '/') . "'\s*,\s*([A-Z_]+)\s*\)/", $source, $m)) {
        return ['alias' => $m[1]];
    }
    return null;
}

function discoverTenants($targetSlug) {
    $tenants = [];
    $root = RE_TENANTS_PATH;

    if (!$root || !is_dir($root)) {
        return $tenants;
    }

    foreach (scandir($root) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $configPath = $root . '/' . $entry . '/config/database.php';
        if (!is_file($configPath)) continue;

        $source = file_get_contents($configPath);
        $slug   = readDefine($source, 'TENANT_SLUG');
        if (is_array($slug) || !$slug) $slug = $entry;   // fall back to the directory name
        if ($targetSlug && strtolower($slug) !== $targetSlug) continue;

        $centralUser = readDefine($source, 'CENTRAL_DB_USER');
        $centralPass = readDefine($source, 'CENTRAL_DB_PASS');
        $dbUser      = readDefine($source, 'DB_USER');
        $dbPass      = readDefine($source, 'DB_PASS');

        // An alias (or a missing define) means the tenant falls back to its OWN
        // DB user, which has no grant on the central DB — cause #3.
        $centralUserResolved = is_array($centralUser) ? $dbUser : $centralUser;
        $centralPassResolved = is_array($centralPass) ? $dbPass : $centralPass;

        $tenants[] = [
            'dir'            => $entry,
            'slug'           => $slug,
            'config_path'    => $configPath,
            'db_name'        => readDefine($source, 'DB_NAME'),
            'db_user'        => $dbUser,
            'db_pass'        => $dbPass,
            'central_db'     => readDefine($source, 'CENTRAL_DB_NAME'),
            'central_user'   => $centralUserResolved,
            'central_pass'   => $centralPassResolved,
            'central_defined' => ($centralUser !== null && !is_array($centralUser)),
        ];
    }

    return $tenants;
}

// ============================================================
// Checks
// ============================================================

function findSubscription($central, $slug) {
    $stmt = $central->prepare("SELECT * FROM subscriptions WHERE tenant_slug = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

/** A row provisioned under a near-miss slug (truncated, prefixed, different case). */
function findOrphanSubscription($central, $tenant) {
    $stmt = $central->prepare("
        SELECT * FROM subscriptions
        WHERE tenant_db_name = ? OR tenant_slug LIKE ? OR ? LIKE CONCAT(tenant_slug, '%')
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$tenant['db_name'], $tenant['slug'] . '%', $tenant['slug']]);
    return $stmt->fetch() ?: null;
}

function findTrialRequest($central, $tenant) {
    $stmt = $central->prepare("SELECT * FROM trial_requests WHERE tenant_slug = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$tenant['slug']]);
    return $stmt->fetch() ?: null;
}

/** Can the tenant itself read the central DB with the credentials in its config? */
function testTenantCentralAccess($tenant) {
    if (!$tenant['central_user'] || !$tenant['central_db']) {
        return 'no credentials in config';
    }
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . $tenant['central_db'] . ";charset=utf8mb4",
            $tenant['central_user'],
            $tenant['central_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $stmt = $pdo->prepare("SELECT id FROM subscriptions WHERE tenant_slug = ?");
        $stmt->execute([$tenant['slug']]);
        $stmt->fetch();
        return true;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

/** Owner/company details, preferring the trial request, falling back to the tenant DB. */
function tenantOwnerDetails($tenant, $trial) {
    $details = [
        'company_name' => $trial['company_name'] ?? null,
        'owner_name'   => $trial['full_name'] ?? null,
        'owner_phone'  => $trial['phone'] ?? null,
        'owner_email'  => $trial['email'] ?? null,
        'unit_count'   => isset($trial['unit_count']) ? (int) $trial['unit_count'] : null,
    ];

    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . $tenant['db_name'] . ";charset=utf8mb4",
            $tenant['db_user'],
            $tenant['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        $settings = [];
        foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        $details['company_name'] = $details['company_name'] ?: ($settings['company_name'] ?? null);
        $details['owner_phone']  = $details['owner_phone'] ?: ($settings['company_phone'] ?? null);
        $details['owner_email']  = $details['owner_email'] ?: ($settings['company_email'] ?? null);

        if (!$details['owner_name']) {
            $admin = $pdo->query("SELECT full_name FROM users WHERE role_id = 1 ORDER BY id ASC LIMIT 1")->fetch();
            $details['owner_name'] = $admin['full_name'] ?? null;
        }

        // Bill for what they actually manage, never fewer than 1 unit.
        $unitsUsed = (int) $pdo->query("SELECT COUNT(*) FROM units")->fetchColumn();
        $details['unit_count'] = max(1, $details['unit_count'] ?: 0, $unitsUsed);
    } catch (PDOException $e) {
        $details['unit_count'] = max(1, (int) $details['unit_count']);
    }

    $details['company_name'] = $details['company_name'] ?: $tenant['slug'];
    $details['owner_name']   = $details['owner_name'] ?: $details['company_name'];
    $details['owner_phone']  = $details['owner_phone'] ?: '';

    return $details;
}

// ============================================================
// Repairs
// ============================================================

function createTrialSubscription($central, $tenant, $trial) {
    $d = tenantOwnerDetails($tenant, $trial);

    $tier        = TRIAL_TIER;
    $ratePerUnit = TIER_PROFESSIONAL_RATE;
    $gross       = $d['unit_count'] * $ratePerUnit;

    // Date the trial from the tenant's own signup, not from today, so a repair
    // never silently hands out a second full trial period.
    $startDate = $trial['trial_start_date'] ?? ($trial['created_at'] ?? null);
    $startDate = $startDate ? date('Y-m-d', strtotime($startDate)) : date('Y-m-d');
    $endDate   = date('Y-m-d', strtotime($startDate . " +" . TRIAL_DAYS . " days"));

    // ...but never leave the repaired tenant already expired: a support repair
    // must restore access, so give at least the remainder of the grace window.
    $minEnd = date('Y-m-d', strtotime('+3 days'));
    if ($endDate < $minEnd) {
        $endDate = $minEnd;
    }

    $stmt = $central->prepare("
        INSERT INTO subscriptions
        (tenant_slug, tenant_db_name, company_name, owner_name, owner_phone, owner_email,
         tier, unit_count, rate_per_unit, currency, gross_amount, net_amount,
         start_date, end_date, next_billing_date, status, trial_request_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'TZS', ?, ?, ?, ?, ?, 'trial', ?)
    ");
    $stmt->execute([
        $tenant['slug'], $tenant['db_name'], $d['company_name'], $d['owner_name'],
        $d['owner_phone'], $d['owner_email'],
        $tier, $d['unit_count'], $ratePerUnit, $gross, $gross,
        $startDate, $endDate, $endDate, $trial['id'] ?? null
    ]);

    return [$central->lastInsertId(), $tier, $d['unit_count'], $startDate, $endDate];
}

function repointSubscriptionSlug($central, $subscriptionId, $slug) {
    $stmt = $central->prepare("UPDATE subscriptions SET tenant_slug = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$slug, $subscriptionId]);
}

/** Write the master's central-DB credentials into the tenant's config. */
function repairTenantCentralCreds($tenant) {
    $source = file_get_contents($tenant['config_path']);
    if ($source === false) {
        return 'could not read config';
    }

    $values = [
        'CENTRAL_DB_NAME' => CENTRAL_DB_NAME,
        'CENTRAL_DB_USER' => CENTRAL_DB_USER,
        'CENTRAL_DB_PASS' => CENTRAL_DB_PASS,
    ];

    $updated = $source;
    foreach ($values as $name => $value) {
        $line = "define('{$name}', '" . addslashes($value) . "');";
        $pattern = "/define\(\s*'" . $name . "'\s*,[^;]*\);/";
        if (preg_match($pattern, $updated)) {
            $updated = preg_replace($pattern, $line, $updated, 1);
        } else {
            // Older config with no central defines at all — add them after DB_NAME.
            $updated = preg_replace("/(define\(\s*'DB_NAME'\s*,[^;]*\);)/", "$1\n" . $line, $updated, 1);
        }
    }

    if ($updated === $source) {
        return 'no change';
    }

    // Keep a one-time backup before touching a live tenant's config.
    $backup = $tenant['config_path'] . '.bak-' . date('YmdHis');
    copy($tenant['config_path'], $backup);

    if (file_put_contents($tenant['config_path'], $updated) === false) {
        return 'could not write config';
    }

    return true;
}

// ============================================================
// Run
// ============================================================

$out("========================================");
$out("Subscription diagnostic — " . date('Y-m-d H:i:s'));
$out($doFix ? "MODE: REPAIR" : "MODE: REPORT ONLY (add 'fix' to repair)");
$out("========================================");
$out();

$tenants = discoverTenants($targetSlug);

if (empty($tenants)) {
    $out($targetSlug
        ? "No deployed tenant found for slug '{$targetSlug}' under " . RE_TENANTS_PATH
        : "No tenants found under " . RE_TENANTS_PATH);

    // The instance may not be deployed yet — still report what central knows.
    if ($targetSlug) {
        $sub = findSubscription($central, $targetSlug);
        $out($sub
            ? "Central DB DOES have a subscription row for '{$targetSlug}' (id {$sub['id']}, status {$sub['status']})."
            : "Central DB has no subscription row for '{$targetSlug}' either.");
    }
    exit;
}

$problems = 0;
$repaired = 0;

foreach ($tenants as $tenant) {
    $slug = $tenant['slug'];
    $out("--- {$slug} ({$tenant['dir']}) ---");
    $out("    database:        " . $tenant['db_name']);

    $sub    = findSubscription($central, $slug);
    $trial  = findTrialRequest($central, $tenant);
    $access = testTenantCentralAccess($tenant);

    // --- Check 1: is there a subscription row for this exact slug?
    if ($sub) {
        $out("    subscription:    OK — id {$sub['id']}, {$sub['tier']}, {$sub['status']}, ends {$sub['end_date']}");
    } else {
        $problems++;
        $orphan = findOrphanSubscription($central, $tenant);

        if ($orphan) {
            $out("    subscription:    SLUG MISMATCH — row id {$orphan['id']} is filed under '{$orphan['tenant_slug']}', tenant looks for '{$slug}'");
            if ($doFix) {
                repointSubscriptionSlug($central, $orphan['id'], $slug);
                $repaired++;
                $out("    REPAIRED:        row {$orphan['id']} re-pointed to '{$slug}'");
            }
        } else {
            $out("    subscription:    MISSING — no row in central DB (provisioning did not create one)");
            if ($doFix) {
                try {
                    list($id, $tier, $units, $start, $end) = createTrialSubscription($central, $tenant, $trial);
                    $repaired++;
                    $out("    REPAIRED:        created row {$id} — {$tier} trial, {$units} unit(s), {$start} to {$end}");
                } catch (PDOException $e) {
                    $out("    REPAIR FAILED:   " . $e->getMessage());
                }
            }
        }
    }

    // --- Check 2: can the tenant actually read the central DB?
    if ($access === true) {
        $out("    central access:  OK");
    } else {
        $problems++;
        $out("    central access:  FAILED — {$access}");
        if (!$tenant['central_defined']) {
            $out("                     (config has no CENTRAL_DB_USER — it falls back to its own DB user,");
            $out("                      which has no grant on " . CENTRAL_DB_NAME . ")");
        }
        if ($doFix) {
            $result = repairTenantCentralCreds($tenant);
            if ($result === true) {
                $tenant['central_user'] = CENTRAL_DB_USER;
                $tenant['central_pass'] = CENTRAL_DB_PASS;
                $tenant['central_db']   = CENTRAL_DB_NAME;
                $recheck = testTenantCentralAccess($tenant);
                if ($recheck === true) {
                    $repaired++;
                    $out("    REPAIRED:        central credentials written to tenant config (backup kept)");
                } else {
                    $out("    REPAIR FAILED:   credentials written but access still fails — {$recheck}");
                    $out("                     Grant the account DB user access to " . CENTRAL_DB_NAME . " in cPanel.");
                }
            } else {
                $out("    REPAIR FAILED:   {$result}");
            }
        }
    }

    // --- Context
    if ($trial) {
        $out("    trial request:   #{$trial['id']} — {$trial['status']}, created {$trial['created_at']}");
    } else {
        $out("    trial request:   none found for this slug");
    }

    $out();
}

$out("========================================");
$out("Tenants checked: " . count($tenants) . " | Problems: {$problems} | Repaired: {$repaired}");
if ($problems > 0 && !$doFix) {
    $out("Re-run with 'fix' (CLI) or &fix=yes (web) to repair.");
}
$out("========================================");
