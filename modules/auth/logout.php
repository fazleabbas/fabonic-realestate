<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Log audit before destroying session
if (isLoggedIn()) {
    // Clear remember me token
    $userId = getUserId();
    $conn->prepare("UPDATE users SET remember_token = NULL WHERE id = ?")->execute([$userId]);
    logActivity('logout', 'user', $userId);
}

// Clear remember me cookie
if (isset($_COOKIE[REMEMBER_ME_COOKIE])) {
    setcookie(REMEMBER_ME_COOKIE, '', time() - 3600, '/', '', false, true);
}

// Destroy session
session_unset();
session_destroy();

// Redirect to login
header("Location: " . BASE_URL . "modules/auth/login.php");
exit();
