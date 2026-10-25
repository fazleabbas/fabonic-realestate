<?php
/**
 * FIS Real Estate — Auto Provision Cron Job
 * Automatically provisions pending trial requests
 *
 * CPANEL CRON:
 * Command: /usr/bin/php /home/realcotz/public_html/api/auto-provision-cron.php
 * Schedule: Every 5 minutes
 */

// Security
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../includes/secrets.php';
    if (!appSecretMatches('AUTO_PROVISION_KEY', $_GET['key'] ?? null)) {
        http_response_code(403);
        die('Access denied');
    }
}

require_once __DIR__ . '/../config/database.php';

define('MAX_PER_RUN', 5);

function logCron($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$type}] {$message}\n";
    echo $logLine;
    error_log($logLine, 3, __DIR__ . '/auto-provision-cron.log');
}

function getPendingRequests() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $pdo->prepare("
            SELECT id, full_name, phone, email, company_name, unit_count, tenant_slug, status
            FROM trial_requests
            WHERE status = 'pending'
            ORDER BY created_at ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', MAX_PER_RUN, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logCron("Database error: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

function markAsProvisioning($requestId) {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $pdo->prepare("UPDATE trial_requests SET status = 'pending', updated_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $requestId]);
        return true;
    } catch (PDOException $e) {
        logCron("Failed to mark as provisioning: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

function runProvision($requestId) {
    logCron("Provisioning trial request ID: {$requestId}");

    $provisionScript = __DIR__ . '/provision-tenant.php';
    if (!file_exists($provisionScript)) {
        logCron("Provision script not found: {$provisionScript}", 'ERROR');
        return false;
    }

    // Use the exact PHP binary running this cron (PHP_BINARY) — cron's PATH is
    // minimal, so a bare "php" would fail to launch the provisioning subprocess.
    $php = PHP_BINARY ?: 'php';
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = sprintf('start /B %s %s %d > %s\\provision-%d.log 2>&1',
            escapeshellarg($php), escapeshellarg($provisionScript), $requestId, escapeshellarg(__DIR__), $requestId);
    } else {
        $command = sprintf('%s %s %d > %s/provision-%d.log 2>&1 &',
            escapeshellarg($php), escapeshellarg($provisionScript), $requestId, escapeshellarg(__DIR__), $requestId);
    }

    logCron("Executing: {$command}");
    exec($command, $output, $returnCode);

    if ($returnCode === 0) {
        logCron("Provisioning started for ID: {$requestId}");
        return true;
    }

    logCron("Failed to start provisioning (return code: {$returnCode})", 'ERROR');
    return false;
}

function runAutoProvision() {
    logCron("========================================");
    logCron("FIS Real Estate — Auto-Provision Cron");
    logCron("========================================");

    $pending = getPendingRequests();

    if (empty($pending)) {
        logCron("No pending trial requests");
        logCron("========================================");
        return;
    }

    logCron("Found " . count($pending) . " pending request(s)");

    $success = 0;
    $fail = 0;

    foreach ($pending as $request) {
        logCron("Processing: {$request['full_name']} ({$request['company_name']})");

        if (runProvision($request['id'])) {
            $success++;
        } else {
            $fail++;
        }

        sleep(2);
    }

    logCron("========================================");
    logCron("Done. Success: {$success}, Failed: {$fail}");
    logCron("========================================");
}

// Run
if (php_sapi_name() === 'cli') {
    runAutoProvision();
} else {
    echo "<pre>";
    runAutoProvision();
    echo "</pre>";
}
