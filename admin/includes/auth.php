<?php
/**
 * FIS Real Estate — Central Admin Auth
 * Manages admin login/logout for the SaaS admin panel (fiscotz_propmanage.admin_users).
 */

session_start();

require_once __DIR__ . '/../../config/database.php';

/**
 * Get central DB connection for admin panel
 */
function getAdminDb() {
    static $db = null;
    if ($db !== null) return $db;

    try {
        $db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . CENTRAL_DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        return $db;
    } catch (PDOException $e) {
        error_log("Admin DB error: " . $e->getMessage());
        die("Database connection failed");
    }
}

/**
 * Check if admin is logged in
 */
function isAdminLoggedIn() {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

/**
 * Get current admin user data
 */
function getAdminUser() {
    return $_SESSION['admin_user'] ?? null;
}

/**
 * Require admin login — redirects to login page if not authenticated
 */
function requireAdminAuth() {
    if (!isAdminLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Attempt admin login
 */
function adminLogin($username, $password) {
    $db = getAdminDb();

    $stmt = $db->prepare("SELECT * FROM admin_users WHERE username = :u AND is_active = 1 LIMIT 1");
    $stmt->execute(['u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Update last login
    $stmt = $db->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);

    // Set session
    $_SESSION['admin_id'] = $user['id'];
    $_SESSION['admin_user'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role']
    ];

    return true;
}

/**
 * Admin logout
 */
function adminLogout() {
    unset($_SESSION['admin_id'], $_SESSION['admin_user']);
    session_destroy();
}

/**
 * Generate CSRF token for admin forms
 */
function adminCsrf() {
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf'];
}

function adminCsrfField() {
    return '<input type="hidden" name="csrf_token" value="' . adminCsrf() . '">';
}

function checkAdminCsrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (empty($token) || $token !== ($_SESSION['admin_csrf'] ?? '')) {
            die('CSRF validation failed');
        }
    }
}
