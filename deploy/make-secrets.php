<?php
/**
 * Create or complete config/secrets.php from the keys that are still written
 * inside the LIVE scripts — run this BEFORE deploying the versions that read
 * config/secrets.php, so every existing cron URL and webhook keeps its key.
 *
 *   php ~/repo/deploy/make-secrets.php                 master + every tenant folder
 *   php ~/repo/deploy/make-secrets.php /path/to/site   just that folder
 *
 * Only ADDS keys that are missing; an existing value is never changed.
 * No value is ever printed.
 */
if (php_sapi_name() !== 'cli') exit("CLI only\n");

// Where each secret is found in the old scripts, in each of the shapes used.
$SOURCES = [
    'RE_DEMO_RESET_KEY'             => 'api/reset-demo.php',
    'DEMO_DB_PASS'                  => 'api/reset-demo.php',
    'DEMO_ADMIN_PASS'               => 'api/reset-demo.php',
    'RUN_TENANT_CRONS_KEY'          => 'api/run-tenant-crons.php',
    'APPLY_TENANT_SQL_KEY'          => 'api/apply-tenant-sql.php',
    'REPAIR_KEY'                    => 'api/fix-subscription.php',
    'SEED_INSPECTION_TEMPLATES_KEY' => 'api/seed-inspection-templates.php',
    'SEED_LEASE_TEMPLATES_KEY'      => 'api/seed-lease-templates.php',
    'AUTO_PROVISION_KEY'            => 'api/auto-provision-cron.php',
    'DRIP_CRON_KEY'                 => 'api/drip-cron.php',
    'PROVISION_KEY'                 => 'api/provision-tenant.php',
    'TRIAL_CRON_KEY'                => 'api/trial-cron.php',
    'WA_CALLBACK_KEY'               => 'api/whatsapp-callback.php',
    'TEST_MAIL_KEY'                 => 'api/test-mail.php',
    'TEST_CPANEL_KEY'               => 'api/test-cpanel.php',
];

function findSecret($src, $name) {
    $str = "'((?:[^'\\\\]|\\\\.)*)'";
    $patterns = [
        "/define\\(\\s*'" . $name . "'\\s*,\\s*" . $str . "\\s*\\)/",   // define('NAME', 'value')
        "/\\\$_GET\\['key'\\]\\s*!==\\s*" . $str . "/",                 // $_GET['key'] !== 'value'
        "/\\\$KEY\\s*=\\s*" . $str . "\\s*;/",                          // $KEY = 'value';
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $src, $m)) return $m[1];
    }
    return null;
}

function completeSecrets($site, $sources) {
    $target = rtrim($site, '/') . '/config/secrets.php';
    if (!is_dir(dirname($target))) return "SKIP (no config folder)";

    $existing = is_file($target) ? (string) file_get_contents($target) : '';
    $added = [];
    $lines = '';
    foreach ($sources as $name => $file) {
        if (preg_match("/define\\(\\s*'" . $name . "'/", $existing)) continue;
        $src = @file_get_contents(rtrim($site, '/') . '/' . $file);
        if ($src === false) continue;               // script not on this site
        $value = findSecret($src, $name);
        if ($value === null) continue;              // already the new version, or no key
        $lines .= "define('{$name}', '{$value}');\n";
        $added[] = $name;
    }
    if (!$added) return $existing === '' ? 'nothing to add' : 'complete already';

    if ($existing === '') {
        $existing = "<?php\n/**\n * Secrets for this site. NEVER commit this file (it is in .gitignore).\n */\n";
    }
    file_put_contents($target, rtrim($existing) . "\n" . $lines);
    chmod($target, 0640);
    return 'added ' . count($added) . ': ' . implode(', ', $added);
}

$sites = array_slice($argv, 1);
if (!$sites) {
    $home = getenv('HOME');
    $sites = array_merge([$home . '/public_html'], glob($home . '/public_html/re_tenants/*', GLOB_ONLYDIR) ?: []);
}
foreach ($sites as $site) {
    printf("%-60s %s\n", $site, completeSecrets($site, $SOURCES));
}
