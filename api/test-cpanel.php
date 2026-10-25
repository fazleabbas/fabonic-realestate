<?php
/**
 * cPanel API connectivity test — verifies CPANEL_HOST / CPANEL_USER / CPANEL_API_TOKEN.
 * TEMPORARY diagnostic. DELETE this file after go-live.
 *
 * Run:  https://real.co.tz/api/test-cpanel.php?key=<TEST_CPANEL_KEY>
 *   or  php /home/realcotz/public_html/api/test-cpanel.php   (cPanel Terminal)
 */
require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/../includes/secrets.php';
if (php_sapi_name() !== 'cli' && (!appSecretMatches('TEST_CPANEL_KEY', $_GET['key'] ?? null))) {
    http_response_code(403);
    die('Access denied');
}
header('Content-Type: text/plain; charset=utf-8');

echo "Host:  " . CPANEL_HOST . " (port " . CPANEL_PORT . ")\n";
echo "User:  " . CPANEL_USER . "\n";
echo "Token: " . (CPANEL_API_TOKEN ? '(set, ' . strlen(CPANEL_API_TOKEN) . " chars)\n" : "(EMPTY!)\n");
echo str_repeat('-', 40) . "\n";

if (!CPANEL_API_TOKEN) {
    echo "FAILED: CPANEL_API_TOKEN is empty — paste your token into config/database.php.\n";
    exit;
}

$url = 'https://' . CPANEL_HOST . ':' . CPANEL_PORT . '/execute/Mysql/list_databases';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . CPANEL_USER . ':' . CPANEL_API_TOKEN],
    CURLOPT_TIMEOUT => 30,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    echo "CURL ERROR: {$err}\n";
    echo "=> Check CPANEL_HOST is your real server hostname and reachable on port " . CPANEL_PORT . ".\n";
    exit;
}

echo "HTTP {$code}\n";
$data = json_decode($resp, true);
if ($code === 200 && isset($data['status']) && $data['status'] == 1) {
    echo "SUCCESS - cPanel API token works. Databases visible: " . count($data['data'] ?? []) . "\n";
    echo "You can proceed. (Remember to DELETE this file.)\n";
} else {
    echo "FAILED\nResponse: " . substr((string) $resp, 0, 600) . "\n";
    echo "=> 401/permission: token is wrong or lacks privileges.\n";
    echo "=> Recreate the token with FULL access (or at least Mysql + SubDomain).\n";
}
