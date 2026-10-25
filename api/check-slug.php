<?php
/**
 * FIS Real Estate — AJAX Slug Availability Check
 * GET /api/check-slug.php?slug=mycompany
 * Returns JSON: {available: true/false, slug: "cleaned_slug", message: "..."}
 */

header('Content-Type: application/json');

// Load config for central DB connection
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/../includes/slug_filter.php';

$slug = strtolower(trim($_GET['slug'] ?? ''));
$slug = preg_replace('/[^a-z0-9]/', '', $slug);

// Validate minimum requirements
if (strlen($slug) < 3) {
    echo json_encode(['available' => false, 'slug' => $slug, 'message' => 'Slug must be at least 3 characters']);
    exit;
}

if (strlen($slug) > 20) {
    $slug = substr($slug, 0, 20);
}

if (!preg_match('/^[a-z][a-z0-9]*$/', $slug)) {
    echo json_encode(['available' => false, 'slug' => $slug, 'message' => 'Slug must start with a letter and contain only letters and numbers']);
    exit;
}

// Reserved slugs
$reserved = [
    'admin', 'api', 'www', 'demo', 'test', 'staging', 'dev', 'app',
    'mail', 'email', 'smtp', 'ftp', 'ssh', 'cpanel', 'webmail',
    'support', 'help', 'billing', 'payment', 'pay', 'checkout',
    'login', 'register', 'signup', 'signin', 'auth', 'oauth',
    'static', 'assets', 'cdn', 'media', 'images', 'img', 'files',
    'download', 'downloads', 'upload', 'uploads',
    'fis', 'fislite', 'lite', 'fabonic', 'fabapps', 'realestate', 'real',
    'root', 'system', 'null', 'undefined', 'default', 'public'
];

if (in_array($slug, $reserved)) {
    echo json_encode(['available' => false, 'slug' => $slug, 'message' => 'This name is reserved. Please choose another.']);
    exit;
}

// Offensive / unethical word filter
if (slugIsBlocked($slug)) {
    echo json_encode(['available' => false, 'slug' => $slug, 'message' => 'This name is not allowed. Please choose another.']);
    exit;
}

// Check central DB for existing slugs
$central = getCentralDb();
if (!$central) {
    echo json_encode(['available' => false, 'slug' => $slug, 'message' => 'Unable to verify availability. Please try again.']);
    exit;
}

try {
    // Check trial_requests (pending/approved)
    $stmt = $central->prepare("
        SELECT COUNT(*) FROM trial_requests
        WHERE (tenant_slug = :slug1 OR LOWER(REPLACE(company_name, ' ', '')) = :slug2)
        AND status IN ('pending', 'approved')
    ");
    $stmt->execute(['slug1' => $slug, 'slug2' => $slug]);
    $trialCount = (int) $stmt->fetchColumn();

    // Check subscriptions
    $stmt2 = $central->prepare("SELECT COUNT(*) FROM subscriptions WHERE tenant_slug = :slug");
    $stmt2->execute(['slug' => $slug]);
    $subCount = (int) $stmt2->fetchColumn();

    if ($trialCount > 0 || $subCount > 0) {
        echo json_encode(['available' => false, 'slug' => $slug, 'message' => 'This name is already taken. Please choose another.']);
    } else {
        echo json_encode(['available' => true, 'slug' => $slug, 'message' => '✓ Available! Your URL will be: ' . $slug . '.real.co.tz']);
    }
} catch (PDOException $e) {
    error_log("check-slug error: " . $e->getMessage());
    echo json_encode(['available' => false, 'slug' => $slug, 'message' => 'Unable to verify availability. Please try again.']);
}
