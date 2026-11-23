<?php
/**
 * FIS Real Estate — run one tenant page from the CLI and show what it hides
 *
 * Tenant configs set error_reporting(0) and display_errors 0, which is right for
 * production but means a fatal renders as a blank page with nothing in any log
 * if log_errors is off too. This runs the page in-process with errors forced on
 * and a shutdown handler that catches the fatal PHP will not let you try/catch,
 * so the actual message is printed instead of vanishing.
 *
 * CLI ONLY — refuses to run over the web, because it fakes an admin session.
 *
 * USAGE
 *   php re_tenant_template/deploy/diagnose-page.php \
 *       --tenant=/home/realcotz/public_html/re_tenants/demo \
 *       --page=modules/payments/create.php
 *
 *   --tenant=PATH   tenant root (required)
 *   --page=REL      page relative to that root (required)
 *   --query=STR     query string, e.g. "id=1&lease_id=2"
 *   --user=ID       session user id (default 1)
 *   --body          also print the rendered HTML
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$tenant = null; $page = null; $query = ''; $userId = 1; $showBody = false;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--tenant=') === 0)     $tenant = rtrim(substr($arg, 9), '/');
    elseif (strpos($arg, '--page=') === 0)   $page = ltrim(substr($arg, 7), '/');
    elseif (strpos($arg, '--query=') === 0)  $query = substr($arg, 8);
    elseif (strpos($arg, '--user=') === 0)   $userId = (int) substr($arg, 7);
    elseif ($arg === '--body')               $showBody = true;
    else { fwrite(STDERR, "unknown option: {$arg}\n"); exit(1); }
}

if (!$tenant || !$page) { fwrite(STDERR, "need --tenant= and --page=\n"); exit(1); }
$full = $tenant . '/' . $page;
if (!is_file($full)) { fwrite(STDERR, "no such page: {$full}\n"); exit(1); }

// Force everything visible, whatever the tenant config later tries to set.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// A fatal cannot be caught — this is the only way to see it.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\n=== FATAL ===\n";
        echo $e['message'], "\n  at ", $e['file'], ':', $e['line'], "\n";
    }
});

// Enough of a logged-in admin for guards to pass.
session_start();
$_SESSION['user_id']            = $userId;
$_SESSION['user_permissions']   = ['all' => true];
$_SESSION['last_activity']      = time();
$_SESSION['role_name']          = 'Admin';
$_SESSION['user_data']          = ['role_name' => 'Admin'];

parse_str($query, $_GET);
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/' . $page . ($query ? '?' . $query : '');
$_SERVER['HTTP_HOST']      = 'cli.local';
$_SERVER['SCRIPT_NAME']    = '/' . $page;

echo "Tenant: {$tenant}\nPage:   {$page}\nPHP:    ", PHP_VERSION, "\n\n";

chdir(dirname($full));
ob_start();
$threw = null;
try {
    include basename($full);
} catch (Throwable $t) {
    $threw = $t;
}
$html = ob_get_clean();

if ($threw) {
    echo "=== THROWN ===\n", get_class($threw), ': ', $threw->getMessage(), "\n";
    echo '  at ', $threw->getFile(), ':', $threw->getLine(), "\n\n";
    echo $threw->getTraceAsString(), "\n\n";
}

printf("Output: %d bytes, %s</html>\n", strlen($html), substr_count($html, '</html>') ? 'has ' : 'NO ');

// A redirect exits before printing anything — that is not a crash, it is a guard.
foreach (headers_list() as $h) {
    if (stripos($h, 'Location:') === 0) echo "REDIRECT -> ", trim(substr($h, 9)), "\n";
}

if (strlen($html) < 400 && !$threw) {
    echo "\n=== SHORT OUTPUT (likely exited early) ===\n", $html, "\n";
} elseif ($showBody) {
    echo "\n=== BODY ===\n", $html, "\n";
}
