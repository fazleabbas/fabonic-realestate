<?php
/**
 * FIS Real Estate — Tenant Provisioning System
 * Creates subdomain, database, deploys code, and sets up new tenant
 *
 * Called after trial request approval or by auto-provision cron.
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('PROVISION_ALLOWED')) {
    require_once __DIR__ . '/../includes/secrets.php';
    if (!appSecretMatches('PROVISION_KEY', $_GET['key'] ?? null)) {
        http_response_code(403);
        die('Access denied');
    }
}

// ============================================
// CONFIGURATION
// ============================================
require_once __DIR__ . '/../config/database.php';

// Central DB credentials for provisioning
define('CENTRAL_DB_HOST_P', DB_HOST);
define('CENTRAL_DB_NAME_P', CENTRAL_DB_NAME);
define('CENTRAL_DB_USER_P', DB_USER);
define('CENTRAL_DB_PASS_P', DB_PASS);

// Production overrides (uncomment on server)
// define('CENTRAL_DB_USER_P', 'fiscotz_reuser');
// define('CENTRAL_DB_PASS_P', 'your_password_here');

// ============================================
// LOGGING
// ============================================
function logProvision($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$type}] {$message}\n";
    echo $logLine;
    error_log($logLine, 3, __DIR__ . '/provision.log');
}

// ============================================
// CPANEL API FUNCTIONS
// ============================================

function cpanelApi($module, $function, $params = []) {
    $url = "https://" . CPANEL_HOST . ":" . CPANEL_PORT . "/execute/{$module}/{$function}";

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Authorization: cpanel ' . CPANEL_USER . ':' . CPANEL_API_TOKEN
        ],
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        logProvision("cPanel API Error: {$error}", 'ERROR');
        return ['status' => 0, 'errors' => [$error]];
    }

    $result = json_decode($response, true);

    if ($httpCode !== 200) {
        logProvision("cPanel API HTTP {$httpCode}: {$response}", 'ERROR');
        return ['status' => 0, 'errors' => ["HTTP {$httpCode}"]];
    }

    return $result;
}

function createSubdomain($subdomain) {
    logProvision("Creating subdomain: {$subdomain}." . CPANEL_DOMAIN);

    $result = cpanelApi('SubDomain', 'addsubdomain', [
        'domain' => $subdomain,
        'rootdomain' => CPANEL_DOMAIN,
        'dir' => "public_html/re_tenants/{$subdomain}"
    ]);

    if (isset($result['status']) && $result['status'] == 1) {
        logProvision("Subdomain created successfully");
        return true;
    }

    $error = $result['errors'][0] ?? 'Unknown error';
    if (strpos($error, 'already exists') !== false || strpos($error, 'DNS entry') !== false) {
        logProvision("Subdomain already exists, skipping creation", 'WARNING');
        return true;
    }

    logProvision("Failed to create subdomain: {$error}", 'ERROR');
    return false;
}

function configureTenantSsl($subdomain) {
    // Automatic HTTPS for the new tenant.
    //
    // The account uses a SINGLE-LABEL wildcard (*.real.co.tz), which covers
    // <slug>.real.co.tz but NOT www.<slug>.real.co.tz (two labels). cPanel
    // auto-adds the www. alias, its DCV always fails, and cPanel then excludes
    // the WHOLE group — blocking HTTPS on the real subdomain too.
    //
    // Fix: exclude just the phantom www. variant, make sure the real subdomain
    // is included, then run AutoSSL. cPanel is left with only the valid
    // subdomain to secure, so it succeeds — with no manual step, ever.
    $base = $subdomain . '.' . CPANEL_DOMAIN;         // fab.real.co.tz
    $www  = 'www.' . $base;                           // www.fab.real.co.tz

    // Exclude the www. alias (it can never validate under a 1-label wildcard).
    cpanelApi('SSL', 'add_autossl_excluded_domains', ['domains' => $www]);
    // Make sure the real subdomain is NOT excluded (clear any prior auto-exclusion).
    cpanelApi('SSL', 'remove_autossl_excluded_domains', ['domains' => $base]);
    logProvision("SSL: excluded {$www}; ensured {$base} is included");

    // Trigger AutoSSL now so the subdomain is secured within a couple of minutes.
    $result = cpanelApi('SSL', 'start_autossl_check');
    if (isset($result['status']) && $result['status'] == 1) {
        logProvision("AutoSSL run queued for {$base}");
        return true;
    }
    $error = $result['errors'][0] ?? 'unknown';
    logProvision("AutoSSL trigger not confirmed ({$error}); the scheduled AutoSSL run will secure it", 'WARNING');
    return false;
}

function createDatabase($dbName) {
    logProvision("Creating database: {$dbName}");

    $result = cpanelApi('Mysql', 'create_database', ['name' => $dbName]);

    if (isset($result['status']) && $result['status'] == 1) {
        logProvision("Database created successfully");
        return true;
    }

    $error = $result['errors'][0] ?? 'Unknown error';
    if (strpos($error, 'already exists') !== false) {
        logProvision("Database already exists, skipping creation", 'WARNING');
        return true;
    }

    logProvision("Failed to create database: {$error}", 'ERROR');
    return false;
}

function createDbUser($username, $password) {
    logProvision("Creating database user: {$username}");

    $result = cpanelApi('Mysql', 'create_user', ['name' => $username, 'password' => $password]);

    if (isset($result['status']) && $result['status'] == 1) {
        logProvision("Database user created successfully");
        return true;
    }

    $error = $result['errors'][0] ?? 'Unknown error';
    if (strpos($error, 'already exists') !== false) {
        logProvision("Database user already exists, updating password", 'WARNING');
        $updateResult = cpanelApi('Mysql', 'set_password', ['user' => $username, 'password' => $password]);
        if (isset($updateResult['status']) && $updateResult['status'] == 1) {
            logProvision("Database user password updated successfully");
            return true;
        }
        $updateError = $updateResult['errors'][0] ?? 'Unknown error';
        logProvision("Failed to update user password: {$updateError}", 'ERROR');
        return false;
    }

    logProvision("Failed to create database user: {$error}", 'ERROR');
    return false;
}

function grantDbPrivileges($dbName, $username) {
    logProvision("Granting privileges on {$dbName} to {$username}");

    $result = cpanelApi('Mysql', 'set_privileges_on_database', [
        'user' => $username,
        'database' => $dbName,
        'privileges' => 'ALL PRIVILEGES'
    ]);

    if (isset($result['status']) && $result['status'] == 1) {
        logProvision("Privileges granted successfully");
        return true;
    }

    $error = $result['errors'][0] ?? 'Unknown error';
    if (strpos($error, 'already') !== false) {
        logProvision("Privileges already granted, continuing", 'WARNING');
        return true;
    }

    logProvision("Failed to grant privileges: {$error}", 'ERROR');
    return false;
}

function generateSecurePassword($length = 16) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

function copyTemplateFiles($subdomain, $forceOverwrite = false) {
    $source = RE_TEMPLATE_PATH;
    $destination = RE_TENANTS_PATH . '/' . $subdomain;

    logProvision("Copying template files from {$source} to {$destination}");

    if (!$forceOverwrite && file_exists($destination . '/index.php')) {
        logProvision("Template files already exist, skipping copy", 'WARNING');
        return true;
    }

    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }

    $command = "cp -rf {$source}/* {$destination}/";
    exec($command, $output, $returnCode);

    if ($returnCode === 0) {
        logProvision("Template files copied successfully");
        return true;
    }

    logProvision("Failed to copy template files: " . implode("\n", $output), 'ERROR');
    return false;
}

function updateTenantConfig($subdomain, $dbName, $dbUser, $dbPass) {
    $configDir = RE_TENANTS_PATH . "/{$subdomain}/config";
    $configPath = $configDir . "/database.php";

    if (!is_dir($configDir)) {
        if (!mkdir($configDir, 0755, true)) {
            logProvision("Failed to create config directory", 'ERROR');
            return false;
        }
    }

    logProvision("Updating config file: {$configPath}");

    $baseUrl = "https://{$subdomain}." . CPANEL_DOMAIN . "/";

    // Central DB credentials the tenant uses to read its subscription. The
    // tenant's own DB user can't reach the central DB, so bake in an account
    // user that can (falls back to the master's own creds).
    $centralUser = addslashes(defined('CENTRAL_DB_USER') ? CENTRAL_DB_USER : DB_USER);
    $centralPass = addslashes(defined('CENTRAL_DB_PASS') ? CENTRAL_DB_PASS : DB_PASS);

    $configContent = "<?php
/**
 * Fabonic Real Estate — Database Configuration
 * Tenant: {$subdomain}
 * Generated: " . date('Y-m-d H:i:s') . "
 */

// Database credentials
define('DB_HOST', 'localhost');
define('DB_USER', '{$dbUser}');
define('DB_PASS', '{$dbPass}');
define('DB_NAME', '{$dbName}');
define('CENTRAL_DB_NAME', 'realcotz_propmanage');
define('CENTRAL_DB_USER', '{$centralUser}');
define('CENTRAL_DB_PASS', '{$centralPass}');
define('DB_CHARSET', 'utf8mb4');

// Tenant identification
define('TENANT_SLUG', '{$subdomain}');

// SaaS master instance flag — always false for tenants (operator features hidden)
define('IS_SAAS_MASTER', false);

// Timezone
date_default_timezone_set('Africa/Dar_es_Salaam');

// Error reporting (production)
error_reporting(0);
ini_set('display_errors', 0);

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.gc_maxlifetime', 86400);

// Application settings
define('APP_NAME', 'Fabonic Real Estate');
define('APP_VERSION', '1.0.0');
define('APP_TAGLINE', 'Property Management Made Simple');
define('BASE_URL', '{$baseUrl}');
define('DEMO_MODE', false);

// File upload settings
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('EXPORT_PATH', __DIR__ . '/../exports/');
define('MAX_FILE_SIZE', 10485760);
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_DOC_TYPES', ['pdf', 'doc', 'docx', 'xlsx', 'csv']);

// Date format
define('DATE_FORMAT', 'd-m-Y');
define('DATETIME_FORMAT', 'd-m-Y h:i A');
define('DB_DATE_FORMAT', 'Y-m-d');

// Pagination
define('RECORDS_PER_PAGE', 20);

// Currency defaults
define('DEFAULT_CURRENCY', 'TZS');
define('APP_CURRENCY_SYMBOL', 'TZS');

// Subscription tiers
define('TIER_BASIC_RATE', 9999);
define('TIER_PROFESSIONAL_RATE', 14999);
define('TIER_ENTERPRISE_RATE', 19999);
define('TRIAL_DAYS', 14);
define('TRIAL_TIER', 'professional');

// Supported languages
define('SUPPORTED_LANGUAGES', ['en', 'sw', 'ar', 'hi', 'ur', 'gu']);
define('DEFAULT_LANGUAGE', 'en');
define('RTL_LANGUAGES', ['ar', 'ur']);

// Email config
define('MAIL_FROM', 'noreply@real.co.tz');
define('MAIL_FROM_NAME', 'Fabonic Real Estate');

// WhatsApp API
define('WA_API_URL', 'https://wa.fis.co.tz');

// Password reset token expiry (hours)
define('PASSWORD_RESET_EXPIRY', 2);

// Remember me cookie duration (days)
define('REMEMBER_ME_DAYS', 30);
define('REMEMBER_ME_COOKIE', 'fis_re_remember');

// cPanel/provisioning (not used in tenant instances)
define('CPANEL_HOST', '');
define('CPANEL_PORT', 2083);
define('CPANEL_USER', '');
define('CPANEL_API_TOKEN', '');
define('CPANEL_DOMAIN', 'real.co.tz');
define('RE_TEMPLATE_PATH', '');
define('RE_TENANTS_PATH', '');
define('RE_SCHEMA_PATH', '');

// Payment
define('PAYMENT_AIRTEL_NUMBER', '0786 110 021');
define('PAYMENT_AIRTEL_NAME', 'Airtel Money');
define('SUBSCRIPTION_GRACE_DAYS', 7);

// Create database connection
try {
    \$conn = new PDO(
        \"mysql:host=\" . DB_HOST . \";dbname=\" . DB_NAME . \";charset=\" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException \$e) {
    die(\"Connection failed: \" . \$e->getMessage());
}
?>";

    if (file_put_contents($configPath, $configContent)) {
        logProvision("Config file updated successfully");
        return true;
    }

    logProvision("Failed to update config file", 'ERROR');
    return false;
}

function importBaseSchema($dbName, $dbUser, $dbPass) {
    logProvision("=== SCHEMA IMPORT START ===");

    $schemaPath = RE_SCHEMA_PATH;

    if (!file_exists($schemaPath)) {
        $altPaths = [
            __DIR__ . '/../sql/tenant_schema.sql',
            dirname(__DIR__) . '/sql/tenant_schema.sql'
        ];
        foreach ($altPaths as $altPath) {
            if (file_exists($altPath)) {
                logProvision("Found schema at: {$altPath}", 'WARNING');
                $schemaPath = $altPath;
                break;
            }
        }
        if (!file_exists($schemaPath)) {
            logProvision("Schema file not found. Aborting.", 'ERROR');
            return false;
        }
    }

    // Check if DB already has tables
    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname={$dbName};charset=utf8mb4",
            $dbUser, $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $stmt = $pdo->query("SHOW TABLES");
        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($existingTables) > 0) {
            logProvision("Database already has " . count($existingTables) . " tables, skipping import", 'WARNING');
            return true;
        }
    } catch (PDOException $e) {
        logProvision("Could not verify DB state: " . $e->getMessage(), 'WARNING');
    }

    $command = "mysql -u {$dbUser} -p'{$dbPass}' {$dbName} < {$schemaPath} 2>&1";
    exec($command, $output, $returnCode);

    if ($returnCode === 0) {
        logProvision("Schema imported successfully");

        // Verify critical tables
        try {
            $pdo = new PDO(
                "mysql:host=localhost;dbname={$dbName};charset=utf8mb4",
                $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            logProvision("Tables created: " . count($tables));

            $critical = ['users', 'roles', 'properties', 'units', 'tenants', 'leases', 'payments', 'settings'];
            $missing = array_diff($critical, $tables);
            if (!empty($missing)) {
                logProvision("Missing critical tables: " . implode(', ', $missing), 'WARNING');
            } else {
                logProvision("All critical tables verified");
            }
        } catch (PDOException $e) {
            logProvision("Could not verify tables: " . $e->getMessage(), 'WARNING');
        }

        logProvision("=== SCHEMA IMPORT SUCCESS ===");
        return true;
    }

    logProvision("Schema import failed (return code: {$returnCode}): " . implode("\n", $output), 'ERROR');
    return false;
}

function createAdminUser($dbName, $dbUser, $dbPass, $adminUsername, $adminPassword, $fullName, $email, $phone) {
    logProvision("Creating admin user: {$adminUsername}");

    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname={$dbName};charset=utf8mb4",
            $dbUser, $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);

        // If the admin already exists (e.g. a re-provision), UPDATE its password
        // to the one we're about to email — never leave a stale password that
        // wouldn't match the credentials email.
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username");
        $stmt->execute(['username' => $adminUsername]);
        if ($stmt->fetch()) {
            $upd = $pdo->prepare("UPDATE users SET password_hash = :p, status = 'active' WHERE username = :u");
            $upd->execute(['p' => $hashedPassword, 'u' => $adminUsername]);
            logProvision("Admin user existed; password reset to match the credentials email");
            return true;
        }
        $stmt = $pdo->prepare("
            INSERT INTO users (username, full_name, email, phone, password_hash, role_id, status)
            VALUES (:username, :full_name, :email, :phone, :password, 1, 'active')
        ");
        $stmt->execute([
            'username' => $adminUsername,
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'password' => $hashedPassword
        ]);

        logProvision("Admin user created with ID: " . $pdo->lastInsertId());
        return true;
    } catch (PDOException $e) {
        logProvision("Failed to create admin user: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

function updateCompanySettings($dbName, $dbUser, $dbPass, $companyName, $phone, $email) {
    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname={$dbName};charset=utf8mb4",
            $dbUser, $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $settings = [
            'company_name' => $companyName,
            'company_phone' => $phone,
            'company_email' => $email
        ];

        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$value, $key]);
        }

        logProvision("Company settings updated");
        return true;
    } catch (PDOException $e) {
        logProvision("Failed to update company settings: " . $e->getMessage(), 'WARNING');
        return false;
    }
}

function createSubscriptionRecord($trialRequestId, $subdomain, $dbName, $companyName, $ownerName, $ownerPhone, $ownerEmail, $unitCount) {
    try {
        $pdo = new PDO(
            "mysql:host=" . CENTRAL_DB_HOST_P . ";dbname=" . CENTRAL_DB_NAME_P . ";charset=utf8mb4",
            CENTRAL_DB_USER_P, CENTRAL_DB_PASS_P,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Check if subscription already exists for this tenant
        $stmt = $pdo->prepare("SELECT id FROM subscriptions WHERE tenant_slug = ?");
        $stmt->execute([$subdomain]);
        if ($stmt->fetch()) {
            logProvision("Subscription record already exists for {$subdomain}", 'WARNING');
            return true;
        }

        $trialDays = TRIAL_DAYS;
        $trialTier = TRIAL_TIER;
        $ratePerUnit = TIER_PROFESSIONAL_RATE;
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$trialDays} days"));

        $grossAmount = $unitCount * $ratePerUnit;

        $stmt = $pdo->prepare("
            INSERT INTO subscriptions
            (tenant_slug, tenant_db_name, company_name, owner_name, owner_phone, owner_email,
             tier, unit_count, rate_per_unit, currency, gross_amount, net_amount,
             start_date, end_date, next_billing_date, status, trial_request_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'TZS', ?, ?, ?, ?, ?, 'trial', ?)
        ");
        $stmt->execute([
            $subdomain, $dbName, $companyName, $ownerName, $ownerPhone, $ownerEmail,
            $trialTier, $unitCount, $ratePerUnit, $grossAmount, $grossAmount,
            $startDate, $endDate, $endDate, $trialRequestId
        ]);

        logProvision("Subscription record created (trial, {$trialTier} tier, {$trialDays} days)");
        return true;
    } catch (PDOException $e) {
        logProvision("Failed to create subscription: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

function updateTrialRequestStatus($trialRequestId, $status, $subdomain = null) {
    try {
        $pdo = new PDO(
            "mysql:host=" . CENTRAL_DB_HOST_P . ";dbname=" . CENTRAL_DB_NAME_P . ";charset=utf8mb4",
            CENTRAL_DB_USER_P, CENTRAL_DB_PASS_P,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $sql = "UPDATE trial_requests SET status = :status, updated_at = NOW()";
        $params = ['status' => $status, 'id' => $trialRequestId];

        if ($subdomain) {
            $sql .= ", tenant_slug = :slug, trial_start_date = CURDATE(), trial_end_date = DATE_ADD(CURDATE(), INTERVAL " . TRIAL_DAYS . " DAY)";
            $params['slug'] = $subdomain;
        }

        $sql .= " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return true;
    } catch (PDOException $e) {
        logProvision("Failed to update trial request: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

function sendSetupWhatsApp($phone, $name, $loginUrl) {
    $payload = json_encode([
        'to' => $phone,
        'template_name' => 're_setup_complete',
        'language' => 'en',
        'components' => [
            [
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $name],
                    ['type' => 'text', 'text' => $loginUrl]
                ]
            ]
        ]
    ]);

    $ch = curl_init(WA_API_URL . '/api/messages/send-template');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        logProvision("WhatsApp sent successfully");
        return true;
    }

    logProvision("WhatsApp send failed: HTTP {$httpCode} - {$response}", 'WARNING');
    return false;
}

function sendCredentialsEmail($email, $name, $loginUrl, $username, $password, $companyName) {
    $subject = "Your setup is ready! - Fabonic Real Estate ({$companyName})";

    $body = '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #003b7a, #002a57); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: white; margin: 0;">Your Setup is Ready!</h1>
        <p style="color: #f5a623; margin: 5px 0 0 0;">Fabonic Real Estate</p>
    </div>
    <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;">
        <p>Dear <strong>' . htmlspecialchars($name) . '</strong>,</p>
        <p>Your Fabonic Real Estate account for <strong>' . htmlspecialchars($companyName) . '</strong> is ready to use.</p>
        <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #003b7a;">
            <h3 style="margin-top: 0; color: #333;">Your Login Details</h3>
            <p><strong>URL:</strong> <a href="' . $loginUrl . '">' . $loginUrl . '</a></p>
            <p><strong>Username:</strong> ' . htmlspecialchars($username) . '</p>
            <p><strong>Password:</strong> ' . htmlspecialchars($password) . '</p>
        </div>
        <p style="color: #dc3545;"><strong>Important:</strong> Please change your password after first login!</p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="' . $loginUrl . '" style="background: #003b7a; color: white; padding: 15px 30px; text-decoration: none; border-radius: 50px; font-weight: bold;">Login Now</a>
        </div>
        <p>Your 14-day free trial has started. Manage your properties, tenants, and rent collections with ease!</p>
        <p>Best regards,<br><strong>Fabonic Real Estate Team</strong></p>
    </div>
</body>
</html>';

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        'Reply-To: ' . MAIL_FROM
    ];

    if (mail($email, $subject, $body, implode("\r\n", $headers))) {
        logProvision("Credentials email sent to {$email}");
        return true;
    }

    logProvision("Failed to send credentials email", 'WARNING');
    return false;
}

// ============================================
// MAIN PROVISIONING FUNCTION
// ============================================

function provisionTenant($trialRequestId) {
    logProvision("========================================");
    logProvision("Starting RE provisioning for Trial Request ID: {$trialRequestId}");
    logProvision("========================================");

    $forceReprovision = isset($_GET['force']) && $_GET['force'] === 'yes';

    // Get trial request details
    try {
        $pdo = new PDO(
            "mysql:host=" . CENTRAL_DB_HOST_P . ";dbname=" . CENTRAL_DB_NAME_P . ";charset=utf8mb4",
            CENTRAL_DB_USER_P, CENTRAL_DB_PASS_P,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $pdo->prepare("SELECT * FROM trial_requests WHERE id = :id");
        $stmt->execute(['id' => $trialRequestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            logProvision("Trial request not found", 'ERROR');
            return false;
        }

        if ($request['status'] === 'approved' && $request['tenant_slug'] && !$forceReprovision) {
            logProvision("Tenant already provisioned (use force=yes to re-provision)", 'WARNING');
            return false;
        }
    } catch (PDOException $e) {
        logProvision("Database error: " . $e->getMessage(), 'ERROR');
        return false;
    }

    // Tenant slug = clean subdomain (no prefix) => <slug>.real.co.tz
    $subdomain = $request['tenant_slug'] ?: preg_replace('/[^a-z0-9]/', '', strtolower($request['company_name'] ?: $request['full_name']));
    $subdomain = substr($subdomain, 0, 20);
    $fullName = $request['full_name'];
    $companyName = $request['company_name'] ?: $request['full_name'];
    $email = $request['email'];
    $phone = $request['phone'];
    $unitCount = max(1, $request['unit_count'] ?? 1);

    logProvision("Provisioning: {$subdomain} for {$companyName}");

    // Generate credentials — DB name/user keep an 're_' prefix so real-estate tenant
    // databases never clash with other apps sharing this cPanel account (subdomain stays clean).
    $dbName = CPANEL_USER . '_re_' . $subdomain;
    $dbUser = CPANEL_USER . '_re' . substr($subdomain, 0, 6);
    $dbPass = generateSecurePassword(16);
    $adminUsername = 'admin';
    $adminPassword = generateSecurePassword(12);

    // Step 1: Create subdomain
    if (!createSubdomain($subdomain)) {
        logProvision("FAILED at subdomain creation", 'ERROR');
        return false;
    }

    // Step 2: Create database
    if (!createDatabase($dbName)) {
        logProvision("FAILED at database creation", 'ERROR');
        return false;
    }

    // Step 3: Create database user
    if (!createDbUser($dbUser, $dbPass)) {
        logProvision("FAILED at user creation", 'ERROR');
        return false;
    }

    // Step 4: Grant privileges
    if (!grantDbPrivileges($dbName, $dbUser)) {
        logProvision("FAILED at privilege grant", 'ERROR');
        return false;
    }

    // Step 5: Copy template files
    if (!copyTemplateFiles($subdomain, $forceReprovision)) {
        logProvision("FAILED at file copy", 'ERROR');
        return false;
    }

    // Step 6: Update config
    if (!updateTenantConfig($subdomain, $dbName, $dbUser, $dbPass)) {
        logProvision("FAILED at config update", 'ERROR');
        return false;
    }

    // Step 7: Import schema
    if (!importBaseSchema($dbName, $dbUser, $dbPass)) {
        logProvision("FAILED at schema import", 'ERROR');
        return false;
    }

    // Step 8: Create admin user
    if (!createAdminUser($dbName, $dbUser, $dbPass, $adminUsername, $adminPassword, $fullName, $email, $phone)) {
        logProvision("FAILED at admin user creation", 'ERROR');
        return false;
    }

    // Step 9: Set company settings in tenant DB
    updateCompanySettings($dbName, $dbUser, $dbPass, $companyName, $phone, $email);

    // Step 10: Create subscription record (trial)
    // A tenant without this row is locked out of its own instance ("No active
    // subscription found"), so a failure here must never pass silently — retry
    // once, then carry the failure through to the final result.
    $subscriptionOk = createSubscriptionRecord($trialRequestId, $subdomain, $dbName, $companyName, $fullName, $phone, $email, $unitCount);
    if (!$subscriptionOk) {
        logProvision("Retrying subscription record creation for {$subdomain}", 'WARNING');
        $subscriptionOk = createSubscriptionRecord($trialRequestId, $subdomain, $dbName, $companyName, $fullName, $phone, $email, $unitCount);
    }
    if (!$subscriptionOk) {
        logProvision("NO SUBSCRIPTION ROW for {$subdomain} — the tenant will be blocked at login. "
            . "Run api/fix-subscription.php?slug={$subdomain}&fix=yes to repair.", 'ERROR');
    }

    // Step 11: Update trial request status
    updateTrialRequestStatus($trialRequestId, 'approved', $subdomain);

    // Step 11.5: Configure automatic HTTPS (exclude phantom www, trigger AutoSSL)
    configureTenantSsl($subdomain);

    // Step 12: Send credentials
    $loginUrl = "https://{$subdomain}." . CPANEL_DOMAIN . "/";
    sendCredentialsEmail($email, $fullName, $loginUrl, $adminUsername, $adminPassword, $companyName);
    sendSetupWhatsApp($phone, $fullName, $loginUrl);

    logProvision("========================================");
    logProvision($subscriptionOk
        ? "Provisioning completed successfully!"
        : "Provisioning completed WITH ERRORS — subscription record missing!");
    logProvision("Subdomain: {$subdomain}." . CPANEL_DOMAIN);
    logProvision("Database: {$dbName}");
    logProvision("Admin: {$adminUsername}");
    logProvision("========================================");

    return $subscriptionOk;
}

// ============================================
// CLI / WEB EXECUTION
// ============================================

if (php_sapi_name() === 'cli') {
    if (isset($argv[1])) {
        provisionTenant(intval($argv[1]));
    } else {
        echo "Usage: php provision-tenant.php <trial_request_id>\n";
    }
} elseif (isset($_GET['id'])) {
    echo "<pre>";
    provisionTenant(intval($_GET['id']));
    echo "</pre>";
}
