<?php
/**
 * FIS Real Estate — Trial Request API
 * POST endpoint. Validates input, inserts into central DB, triggers WhatsApp drip #1, sends email.
 */

session_start();
header('Content-Type: application/json');

// Rate limiting
$rateKey = 'trial_rate_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (isset($_SESSION[$rateKey]) && $_SESSION[$rateKey] > time()) {
    echo json_encode(['success' => false, 'message' => 'Please wait a moment before trying again.']);
    exit;
}

// CSRF validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (empty($csrfToken) || $csrfToken !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh the page and try again.']);
    exit;
}

// Honeypot check
if (!empty($_POST['website'])) {
    echo json_encode(['success' => true, 'message' => 'Request submitted successfully.']);
    exit;
}

// Load config and helpers
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/slug_filter.php';

// Sanitize inputs
function cleanInput($val, $maxLen = 255) {
    return mb_substr(htmlspecialchars(strip_tags(trim($val ?? '')), ENT_QUOTES, 'UTF-8'), 0, $maxLen);
}

$companyName = cleanInput($_POST['company_name'] ?? '', 200);
$fullName = cleanInput($_POST['full_name'] ?? '', 150);
$phone = preg_replace('/[^0-9+\s\-]/', '', trim($_POST['phone'] ?? ''));
$email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? trim($_POST['email']) : '';
$preferredSlug = strtolower(preg_replace('/[^a-z0-9]/', '', trim($_POST['preferred_slug'] ?? '')));
$unitCount = max(1, min(10000, intval($_POST['unit_count'] ?? 1)));
$city = cleanInput($_POST['city'] ?? '', 100);
$referralSource = cleanInput($_POST['referral_source'] ?? '', 100);

// Validate required fields
$errors = [];
if (empty($companyName)) $errors[] = 'Company name is required';
if (empty($fullName)) $errors[] = 'Full name is required';
if (empty($phone) || strlen(preg_replace('/[^0-9]/', '', $phone)) < 9) $errors[] = 'Valid phone number is required';

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode('. ', $errors)]);
    exit;
}

// Clean phone for storage
$phoneClean = preg_replace('/[^0-9]/', '', $phone);
if (substr($phoneClean, 0, 1) === '0') {
    $phoneClean = '255' . substr($phoneClean, 1);
}
if (strlen($phoneClean) === 9) {
    $phoneClean = '255' . $phoneClean;
}

// Validate slug availability if provided
$tenantSlug = null;
if (!empty($preferredSlug) && strlen($preferredSlug) >= 3) {
    // Validate slug format
    if (!preg_match('/^[a-z][a-z0-9]*$/', $preferredSlug)) {
        echo json_encode(['success' => false, 'message' => 'URL slug must start with a letter and contain only letters and numbers']);
        exit;
    }

    $reserved = [
        'admin', 'api', 'www', 'demo', 'test', 'staging', 'dev', 'app',
        'mail', 'email', 'smtp', 'ftp', 'ssh', 'cpanel', 'webmail',
        'support', 'help', 'billing', 'payment', 'pay', 'checkout',
        'login', 'register', 'signup', 'signin', 'auth', 'oauth',
        'fis', 'fislite', 'lite', 'fabonic', 'realestate', 'real', 'public'
    ];

    if (in_array($preferredSlug, $reserved)) {
        echo json_encode(['success' => false, 'message' => 'This URL name is reserved. Please choose another.']);
        exit;
    }

    // Offensive / unethical word filter
    if (slugIsBlocked($preferredSlug)) {
        echo json_encode(['success' => false, 'message' => 'This URL name is not allowed. Please choose another.']);
        exit;
    }

    $tenantSlug = $preferredSlug;
}

// Connect to central DB
$central = getCentralDb();
if (!$central) {
    echo json_encode(['success' => false, 'message' => 'Service temporarily unavailable. Please try again later.']);
    exit;
}

try {
    // Check for duplicate phone (prevent spam)
    $stmt = $central->prepare("
        SELECT id, status FROM trial_requests
        WHERE phone = :phone AND status IN ('pending', 'approved')
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute(['phone' => $phoneClean]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($existing['status'] === 'approved') {
            echo json_encode(['success' => false, 'message' => 'An account already exists for this phone number. Please login to your account.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'A trial request for this phone number is already being processed. We will contact you shortly.']);
        }
        exit;
    }

    // Check slug uniqueness if provided
    if ($tenantSlug) {
        $stmt = $central->prepare("SELECT COUNT(*) FROM trial_requests WHERE tenant_slug = ? AND status IN ('pending','approved')");
        $stmt->execute([$tenantSlug]);
        if ((int)$stmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'The chosen URL is already taken. Please choose a different one.']);
            exit;
        }
        $stmt = $central->prepare("SELECT COUNT(*) FROM subscriptions WHERE tenant_slug = ?");
        $stmt->execute([$tenantSlug]);
        if ((int)$stmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'The chosen URL is already in use. Please choose a different one.']);
            exit;
        }
    }

    // Insert trial request
    $stmt = $central->prepare("
        INSERT INTO trial_requests
        (full_name, phone, email, company_name, unit_count, city, referral_source, tenant_slug, status, created_at)
        VALUES (:name, :phone, :email, :company, :units, :city, :ref, :slug, 'pending', NOW())
    ");
    $stmt->execute([
        'name' => $companyName ? $fullName : $fullName, // full_name always stores the person's name
        'phone' => $phoneClean,
        'email' => $email ?: null,
        'company' => $companyName,
        'units' => $unitCount,
        'city' => $city ?: null,
        'ref' => $referralSource ?: null,
        'slug' => $tenantSlug
    ]);

    $trialRequestId = $central->lastInsertId();

    // Set rate limit (1 request per 60 seconds per IP)
    $_SESSION[$rateKey] = time() + 60;

    // --- Trigger WhatsApp drip message #1 (immediate confirmation) ---
    $waResult = sendDripMessage1($phoneClean, $fullName, $companyName, $unitCount);

    // --- Initialize drip campaign ---
    initDripCampaign($trialRequestId, $phoneClean, $fullName);

    // --- Send confirmation email ---
    if ($email) {
        sendTrialConfirmationEmail($email, $fullName, $companyName);
    }

    // --- Notify admin via WhatsApp ---
    $adminMsg = "New RE Trial Request!\n\n"
        . "Company: {$companyName}\n"
        . "Name: {$fullName}\n"
        . "Phone: {$phoneClean}\n"
        . "Email: {$email}\n"
        . "Units: {$unitCount}\n"
        . "City: {$city}\n"
        . "Slug: {$tenantSlug}\n\n"
        . "Review: https://real.co.tz/admin/trials.php";
    sendWhatsApp('255786110021', $adminMsg);

    echo json_encode([
        'success' => true,
        'message' => 'Trial request submitted successfully! We will contact you within 24 hours.',
        'request_id' => $trialRequestId
    ]);

} catch (PDOException $e) {
    error_log("Trial request error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again later.']);
}
