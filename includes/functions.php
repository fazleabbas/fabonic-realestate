<?php
/**
 * FIS Real Estate — Common Functions
 */

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Idle Session Timeout ---
// Check if session has expired due to inactivity
if (isset($_SESSION['user_id']) && isset($_SESSION['last_activity'])) {
    // Get timeout from DB setting (cached), default 30 minutes
    $timeoutMinutes = 30;
    try {
        global $conn;
        if ($conn) {
            $stmtTO = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'session_timeout_minutes' LIMIT 1");
            $rowTO = $stmtTO ? $stmtTO->fetch(PDO::FETCH_ASSOC) : null;
            if ($rowTO && is_numeric($rowTO['setting_value'])) {
                $timeoutMinutes = (int)$rowTO['setting_value'];
            }
        }
    } catch (Exception $e) {
        // Use default on error
    }

    if ($timeoutMinutes > 0) {
        $idleSeconds = time() - $_SESSION['last_activity'];
        if ($idleSeconds > ($timeoutMinutes * 60)) {
            // Session expired — destroy and redirect
            $_SESSION['session_expired'] = true;
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['session_expired'] = true;
            header("Location: " . (defined('BASE_URL') ? BASE_URL : '/') . "modules/auth/login.php?timeout=1");
            exit();
        }
    }
}
// Update last activity timestamp on every request
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
}

// --- Currency symbol safety net ---
// Tenant configs generated before the APP_CURRENCY_SYMBOL rename do not define it.
// On PHP 8 an undefined constant is a fatal Error, which takes down every page that
// prints money. Define it here so an old config degrades instead of dying.
// NEVER derive this from CURRENCY_SYMBOL: Imunify/CloudLinux defines that as the
// integer 262145 in the PHP 8 web SAPI, which is the bug the rename fixed.
if (!defined('APP_CURRENCY_SYMBOL')) {
    define('APP_CURRENCY_SYMBOL',
        (defined('DEFAULT_CURRENCY') && is_string(DEFAULT_CURRENCY)) ? DEFAULT_CURRENCY : 'TZS');
}

// Handle language switch early (before any translations are used)
if (isset($_GET['lang'])) {
    $allowedLangs = SUPPORTED_LANGUAGES;
    if (in_array($_GET['lang'], $allowedLangs)) {
        $_SESSION['lang'] = $_GET['lang'];
    }
}

// Language translations cache
$GLOBALS['lang_cache'] = null;

/**
 * Get current language code
 */
function getCurrentLang() {
    return $_SESSION['lang'] ?? DEFAULT_LANGUAGE;
}

/**
 * Set current language
 */
function setLang($lang) {
    if (in_array($lang, SUPPORTED_LANGUAGES)) {
        $_SESSION['lang'] = $lang;
        $GLOBALS['lang_cache'] = null;
    }
}

/**
 * Check if current language is RTL
 */
function isRTL() {
    return in_array(getCurrentLang(), RTL_LANGUAGES);
}

/**
 * Load language file
 */
function loadLang($lang = null) {
    if ($lang === null) {
        $lang = getCurrentLang();
    }

    $langFile = __DIR__ . '/../lang/' . $lang . '.php';
    if (file_exists($langFile)) {
        return require $langFile;
    }

    // Fallback to English
    return require __DIR__ . '/../lang/en.php';
}

/**
 * Get translation for a key
 */
function __($key, $default = null) {
    if ($GLOBALS['lang_cache'] === null) {
        $GLOBALS['lang_cache'] = loadLang();
    }

    return $GLOBALS['lang_cache'][$key] ?? $default ?? $key;
}

/**
 * Echo translation for a key
 */
function _e($key, $default = null) {
    echo __($key, $default);
}

// ============================================================
// Auth Functions
// ============================================================

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current user ID
 */
function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user data
 */
function getUserData($key = null) {
    if ($key) {
        return $_SESSION['user_data'][$key] ?? null;
    }
    return $_SESSION['user_data'] ?? [];
}

/**
 * Is the current user the SaaS platform operator (you), not a tenant admin?
 * True only on the master instance (IS_SAAS_MASTER === true) AND for the Admin
 * role. Tenant instances — and any tenant config predating the IS_SAAS_MASTER
 * flag — return false, hiding all SaaS-operator menus and blocking their pages.
 */
function isSaasOperator() {
    return getUserData('role_name') === 'Admin'
        && defined('IS_SAAS_MASTER') && IS_SAAS_MASTER === true;
}

/**
 * Check if user has permission for a module action
 * Permissions stored as JSON in roles table:
 * {"all": true} for admin, or {"properties": ["view","create","edit","delete"], ...}
 */
function hasPermission($module, $action = 'view') {
    // Admin has all permissions
    if (getUserData('role_name') === 'Admin') {
        return true;
    }

    $permissions = $_SESSION['user_permissions'] ?? [];

    // Check for global admin flag
    if (!empty($permissions['all'])) {
        return true;
    }

    // Check specific module permission
    if (isset($permissions[$module])) {
        if (is_array($permissions[$module])) {
            return in_array($action, $permissions[$module]);
        }
        return (bool) $permissions[$module];
    }

    return false;
}

// ============================================================
// Property Access Scoping (Part 24)
// Non-admin users can be restricted to a set of assigned properties.
// Everything below is DORMANT until the 'property_scoping_enabled' setting
// is '1'. While off, userPropertyScope() returns null (= unrestricted) for
// everyone, so no query behaviour changes.
// ============================================================

/**
 * Resolve the current user's allowed property IDs.
 *
 * @return array|null  null  = unrestricted (Admin, or scoping disabled)
 *                     array = the exact property IDs this user may access
 *                             (an EMPTY array means "see nothing" — strict mode)
 */
function userPropertyScope() {
    global $conn;
    static $scope = false; // false = not yet computed this request

    if ($scope !== false) return $scope;

    // Master switch off → no scoping at all.
    if (getSetting('property_scoping_enabled', '0') !== '1') { $scope = null; return $scope; }

    // Admin always bypasses scoping.
    if (getUserData('role_name') === 'Admin') { $scope = null; return $scope; }

    $uid = getUserId();
    if (!$uid) { $scope = null; return $scope; }

    try {
        $stmt = $conn->prepare("SELECT property_id FROM user_properties WHERE user_id = ?");
        $stmt->execute([$uid]);
        $scope = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        // Table missing / misconfigured — fail open so the app stays usable.
        error_log('userPropertyScope error: ' . $e->getMessage());
        $scope = null;
    }
    return $scope;
}

/**
 * SQL fragment scoping a query by a direct property-id column (e.g. p.id, u.property_id).
 * IDs are integer-cast and inlined, so it composes with any query style (named or positional).
 *
 * @return string  '' (no restriction), ' AND 1=0' (strict/empty), or ' AND <col> IN (ids)'
 */
function propertyScopeSql($column) {
    $scope = userPropertyScope();
    if ($scope === null) return '';
    if (empty($scope)) return ' AND 1=0';
    $ids = implode(',', array_map('intval', $scope));
    return " AND {$column} IN ({$ids})";
}

/**
 * SQL fragment scoping a query that only has a unit-id column
 * (payments, deposits, inspections, maintenance, leases).
 */
function unitScopeSql($unitColumn) {
    $scope = userPropertyScope();
    if ($scope === null) return '';
    if (empty($scope)) return ' AND 1=0';
    $ids = implode(',', array_map('intval', $scope));
    return " AND {$unitColumn} IN (SELECT id FROM units WHERE property_id IN ({$ids}))";
}

/**
 * SQL fragment scoping a query by tenant (tenants have no direct property;
 * a tenant is in scope only via a lease in an allowed property).
 */
function tenantScopeSql($tenantColumn) {
    $scope = userPropertyScope();
    if ($scope === null) return '';
    if (empty($scope)) return ' AND 1=0';
    $ids = implode(',', array_map('intval', $scope));
    return " AND {$tenantColumn} IN (
                SELECT l.tenant_id FROM leases l
                INNER JOIN units u ON l.unit_id = u.id
                WHERE u.property_id IN ({$ids}))";
}

/**
 * Guard for detail pages: may the current user access this property?
 */
function canAccessProperty($propertyId) {
    $scope = userPropertyScope();
    if ($scope === null) return true;
    return in_array((int)$propertyId, $scope, true);
}

/**
 * Guard for detail pages keyed by unit: may the current user access this unit's property?
 */
function canAccessUnit($unitId) {
    global $conn;
    $scope = userPropertyScope();
    if ($scope === null) return true;
    if (empty($scope)) return false;
    try {
        $stmt = $conn->prepare("SELECT property_id FROM units WHERE id = ?");
        $stmt->execute([(int)$unitId]);
        $pid = (int)$stmt->fetchColumn();
        return in_array($pid, $scope, true);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Guard for lease detail pages: may the current user access this lease's property?
 */
function canAccessLease($leaseId) {
    global $conn;
    $scope = userPropertyScope();
    if ($scope === null) return true;
    if (empty($scope)) return false;
    try {
        $stmt = $conn->prepare("SELECT u.property_id FROM leases l INNER JOIN units u ON l.unit_id = u.id WHERE l.id = ?");
        $stmt->execute([(int)$leaseId]);
        $pid = (int)$stmt->fetchColumn();
        return in_array($pid, $scope, true);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Lease statuses whose records may still be edited.
 * Expired leases stay editable so end dates / rent figures can be corrected after
 * the term has lapsed. Terminated and renewed leases are closed records.
 */
function leaseEditableStatuses() {
    return ['active', 'expired'];
}

/**
 * Whether a lease row (or bare status string) may be opened in the edit form.
 */
function isLeaseEditable($lease) {
    $status = is_array($lease) ? ($lease['status'] ?? '') : (string)$lease;
    return in_array($status, leaseEditableStatuses(), true);
}

/**
 * Guard for tenant detail pages: a tenant is accessible only if they hold a lease
 * in one of the current user's allowed properties (lease-less tenants → Admin-only).
 */
function canAccessTenant($tenantId) {
    global $conn;
    $scope = userPropertyScope();
    if ($scope === null) return true;
    if (empty($scope)) return false;
    $ids = implode(',', array_map('intval', $scope));
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM leases l
            INNER JOIN units u ON l.unit_id = u.id
            WHERE l.tenant_id = ? AND u.property_id IN ({$ids})
        ");
        $stmt->execute([(int)$tenantId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Guard for documents: a document inherits the scope of whatever it is linked to.
 * Unlinked ('general') documents are not property-specific, so they stay visible.
 */
function documentInScope($conn, $entityType, $entityId) {
    if (userPropertyScope() === null) return true;
    if (!$entityId) return true; // general / unlinked

    switch ($entityType) {
        case 'property': return canAccessProperty($entityId);
        case 'unit':     return canAccessUnit($entityId);
        case 'lease':    return canAccessLease($entityId);
        case 'tenant':   return canAccessTenant($entityId);
        case 'payment':
            try {
                $stmt = $conn->prepare("SELECT unit_id FROM payments WHERE id = ?");
                $stmt->execute([(int)$entityId]);
                $unitId = (int)$stmt->fetchColumn();
                return $unitId ? canAccessUnit($unitId) : false;
            } catch (PDOException $e) {
                return false;
            }
        default: return true;
    }
}

/**
 * Redirect to a page
 */
function redirect($url) {
    header("Location: " . BASE_URL . $url);
    exit();
}

/**
 * Display alert message
 */
function setAlert($message, $type = 'success') {
    $_SESSION['alert'] = [
        'message' => $message,
        'type' => $type
    ];
}

/**
 * Get and clear alert message
 */
function getAlert() {
    if (isset($_SESSION['alert'])) {
        $alert = $_SESSION['alert'];
        unset($_SESSION['alert']);
        return $alert;
    }
    return null;
}

// ============================================================
// Sanitization & Formatting
// ============================================================

/**
 * Sanitize input
 */
function clean($data) {
    if (is_array($data)) {
        return array_map('clean', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Format date for display
 */
function formatDate($date, $format = DATE_FORMAT) {
    if (empty($date) || $date == '0000-00-00') {
        return '';
    }
    return date($format, strtotime($date));
}

/**
 * Format datetime for display
 */
function formatDateTime($datetime, $format = DATETIME_FORMAT) {
    if (empty($datetime) || $datetime == '0000-00-00 00:00:00') {
        return '';
    }
    return date($format, strtotime($datetime));
}

/**
 * Format number for display
 */
function formatNumber($number, $decimals = 2) {
    return number_format($number, $decimals);
}

/**
 * Format currency
 */
function formatCurrency($amount, $symbol = null, $decimals = 0) {
    // APP_CURRENCY_SYMBOL is guaranteed by the safety net at the top of this file.
    $symbol = $symbol ?? APP_CURRENCY_SYMBOL;
    return $symbol . ' ' . number_format($amount, $decimals);
}

// ============================================================
// Lease payment frequency
// ------------------------------------------------------------
// One place defines the valid frequencies, how many months each spans, and how
// each is labelled. Anything that validates, schedules or prints a frequency
// must go through these — a frequency missing from the months map would
// silently be treated as monthly and generate the wrong payment periods.
// ============================================================

/**
 * Valid lease payment frequencies, in display order.
 * Note: the DB enum appends new values at the end, so this order is
 * deliberately not the enum order.
 */
function paymentFrequencies() {
    return ['monthly', 'quarterly', 'half_yearly', 'yearly'];
}

/**
 * Number of months one payment period spans.
 */
function paymentFrequencyMonths($frequency) {
    $map = [
        'monthly'     => 1,
        'quarterly'   => 3,
        'half_yearly' => 6,
        'yearly'      => 12,
    ];
    return $map[$frequency] ?? 1;
}

/**
 * Turn the lease form's "Bill automatically from" date into leases.billed_through
 * (the day before). Blank, or on/before the start date, means bill from the
 * start as before, stored as NULL. Appends to $errors on a bad date.
 */
function leaseBilledThroughFromInput($billFrom, $startDate, $endDate, &$errors) {
    if ($billFrom === '' || $billFrom === null) return null;
    $from = strtotime($billFrom);
    if ($from === false) {
        $errors[] = __('lease_bill_from_invalid', 'Bill automatically from is not a valid date');
        return null;
    }
    if ($endDate !== '' && $from > strtotime($endDate)) {
        $errors[] = __('lease_bill_from_after_end', 'Bill automatically from cannot be after the end date');
        return null;
    }
    if ($startDate !== '' && $from <= strtotime($startDate)) return null;
    return date('Y-m-d', strtotime('-1 day', $from));
}

/**
 * "Import" button for a list screen's header (part 40). Admins get the link into
 * the import flow; anyone else sees it disabled with the reason, so they know
 * the option exists and whom to ask.
 */
function importButton($type, $class = 'btn btn-outline-primary') {
    $label = '<i class="bi bi-box-arrow-in-down me-1"></i> ' . __('imp_import', 'Import');
    if (getUserData('role_name') !== 'Admin') {
        return '<span class="d-inline-block" tabindex="0" title="' . htmlspecialchars(__('imp_admins_only', 'Only admins can import data.')) . '">'
             . '<button type="button" class="' . $class . '" disabled>' . $label . '</button></span>';
    }
    return '<a href="' . BASE_URL . 'modules/import/upload.php?type=' . urlencode($type) . '" class="' . $class . '">' . $label . '</a>';
}

/** The form value for a stored billed_through: the day after it, or blank. */
function leaseBillFromValue($billedThrough) {
    return $billedThrough ? date('Y-m-d', strtotime($billedThrough . ' +1 day')) : '';
}

/**
 * Translated label for a frequency. Never use ucfirst() on the raw value —
 * it renders 'half_yearly' as "Half_yearly".
 */
function paymentFrequencyLabel($frequency) {
    $defaults = [
        'monthly'     => 'Monthly',
        'quarterly'   => 'Quarterly',
        'half_yearly' => 'Half Yearly',
        'yearly'      => 'Yearly',
    ];
    if (isset($defaults[$frequency])) {
        return __($frequency, $defaults[$frequency]);
    }
    return ucfirst(str_replace('_', ' ', (string) $frequency));
}

/**
 * Normalise a Tanzanian TIN (Taxpayer Identification Number).
 *
 * Soft validation: a 9-digit TIN is reformatted to NNN-NNN-NNN, anything else is
 * kept exactly as typed so foreign or not-yet-issued numbers can still be saved.
 * Callers surface ['valid' => false] as a warning, never as a blocking error.
 *
 * @return array{value: ?string, valid: bool}
 */
function normalizeTin($tin) {
    $tin = trim((string) $tin);
    if ($tin === '') {
        return ['value' => null, 'valid' => true];
    }

    // Strip separators only — never letters, or a foreign TIN like "AB123456789"
    // would be silently rewritten as a Tanzanian one.
    $digits = preg_replace('/[\s.\-]/', '', $tin);
    if (preg_match('/^\d{9}$/', $digits)) {
        return [
            'value' => substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 3),
            'valid' => true,
        ];
    }

    return ['value' => $tin, 'valid' => false];
}

/**
 * Convert date from display format to database format
 */
function dbDate($date) {
    if (empty($date)) return '';
    return date('Y-m-d', strtotime($date));
}

// ============================================================
// CSRF Protection
// ============================================================

function generateCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRF() . '">';
}

function checkCSRF() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            die('CSRF token validation failed');
        }
    }
}

// ============================================================
// Activity Log
// ============================================================

function logActivity($action, $entityType = null, $entityId = null, $details = null) {
    global $conn;

    $userId = getUserId();
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    try {
        $stmt = $conn->prepare("
            INSERT INTO activity_log (user_id, user_type, action, entity_type, entity_id, details, ip_address, user_agent)
            VALUES (?, 'staff', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $details ? json_encode($details) : null,
            $ipAddress,
            $userAgent
        ]);
    } catch (PDOException $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

// ============================================================
// Settings
// ============================================================

function getSetting($key, $default = null) {
    global $conn;

    static $settings = [];
    static $loadedVersion = -1;

    // updateSetting() bumps this, so a value saved earlier in the same request
    // is read back correctly instead of coming from a stale cache.
    $version = $GLOBALS['__settings_version'] ?? 0;
    if ($loadedVersion !== $version) {
        $settings = [];
        $loadedVersion = $version;
    }

    if (empty($settings)) {
        try {
            $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
            while ($row = $stmt->fetch()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (PDOException $e) {
            return $default;
        }
    }

    return $settings[$key] ?? $default;
}

function updateSetting($key, $value) {
    global $conn;

    $stmt = $conn->prepare("
        INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
    ");
    $ok = $stmt->execute([$key, $value, $value]);

    // Invalidate the read cache, or anything reading this key later in the same
    // request would still see the old value.
    $GLOBALS['__settings_version'] = ($GLOBALS['__settings_version'] ?? 0) + 1;

    return $ok;
}

// ============================================================
// Pagination
// ============================================================

function paginate($totalRecords, $currentPage = 1, $recordsPerPage = RECORDS_PER_PAGE) {
    $totalPages = max(1, ceil($totalRecords / $recordsPerPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $recordsPerPage;

    return [
        'total_records' => $totalRecords,
        'total_pages' => $totalPages,
        'current_page' => $currentPage,
        'records_per_page' => $recordsPerPage,
        'offset' => $offset,
        'has_prev' => $currentPage > 1,
        'has_next' => $currentPage < $totalPages,
        'prev_page' => $currentPage - 1,
        'next_page' => $currentPage + 1
    ];
}

// ============================================================
// Utilities
// ============================================================

function getNextNumber($table, $column, $prefix) {
    global $conn;

    $stmt = $conn->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $result = $stmt->fetch();

    if ($result) {
        $numericPart = intval(substr($result[$column], strlen($prefix))) + 1;
    } else {
        $numericPart = 1;
    }

    return $prefix . str_pad($numericPart, 6, '0', STR_PAD_LEFT);
}

function generatePassword($length = 10) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&';
    return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

function uploadFile($file, $directory, $allowedTypes = null) {
    if ($allowedTypes === null) {
        $allowedTypes = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_DOC_TYPES);
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload failed with error code: ' . $file['error']];
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'message' => 'File size exceeds maximum allowed size'];
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedTypes)) {
        return ['success' => false, 'message' => 'File type not allowed'];
    }

    $targetDir = UPLOAD_PATH . $directory . '/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = $targetDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename, 'filepath' => $directory . '/' . $filename];
    }

    return ['success' => false, 'message' => 'Failed to move uploaded file'];
}

/**
 * Generate a secure random token
 */
function generateToken($length = 64) {
    return bin2hex(random_bytes($length / 2));
}

// ============================================================
// Auto-include currency helpers
// ============================================================
require_once __DIR__ . '/currency.php';
