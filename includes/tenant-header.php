<?php
/**
 * FIS Real Estate — Tenant Portal Header
 * Simplified layout: topbar only (no sidebar). Used by all tenant-portal pages.
 */

// The nav gates the maintenance link on hasTier(); pull in its definition so
// this file never depends on the caller having required it (the staff header
// had exactly this bug and 500'd the profile page). Definitions only, no output.
require_once __DIR__ . '/subscription.php';

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

// Check tenant portal session
if (!isset($_SESSION['tenant_portal_id']) || empty($_SESSION['tenant_portal_id'])) {
    header('Location: ' . BASE_URL . 'modules/tenant-portal/login.php');
    exit;
}

$tpTenantName = $_SESSION['tenant_portal_name'] ?? '';
$tpInitials = '';
$tpParts = explode(' ', $tpTenantName);
foreach ($tpParts as $part) {
    $tpInitials .= mb_strtoupper(mb_substr($part, 0, 1));
}
$tpInitials = mb_substr($tpInitials, 0, 2);

$currentLang = getCurrentLang();
$dir = isRTL() ? 'rtl' : 'ltr';
$companyName = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>" dir="<?php echo $dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle ?? __('tp_portal', 'Tenant Portal'); ?> - <?php echo htmlspecialchars($companyName); ?></title>

    <meta name="theme-color" content="#1a6b4a">
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo BASE_URL; ?>assets/icons/icon-192x192.png">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- App CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css?v=1.0">

    <style>
        :root {
            --re-primary: #1a6b4a;
            --re-primary-dark: #134f37;
            --re-accent: #e8a838;
        }

        body {
            background: #f5f7fa;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            padding-top: 60px;
        }

        .tp-topbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 60px;
            background: var(--re-primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            z-index: 1030;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .tp-topbar-brand {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
        }

        .tp-topbar-brand:hover { color: rgba(255,255,255,0.9); }

        .tp-topbar-brand-icon {
            width: 36px;
            height: 36px;
            background: rgba(255,255,255,0.15);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .tp-topbar-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .tp-nav-link {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            font-size: 0.85rem;
            padding: 0.4rem 0.6rem;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .tp-nav-link:hover, .tp-nav-link.active {
            color: white;
            background: rgba(255,255,255,0.15);
        }

        .tp-user-avatar {
            width: 32px;
            height: 32px;
            background: var(--re-accent);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            color: #333;
        }

        .tp-main {
            max-width: 1000px;
            margin: 1.5rem auto;
            padding: 0 1rem;
        }

        .tp-lang-btn {
            background: none;
            border: 1px solid rgba(255,255,255,0.3);
            color: rgba(255,255,255,0.8);
            font-size: 0.78rem;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            cursor: pointer;
        }

        .tp-lang-btn:hover { color: white; border-color: rgba(255,255,255,0.6); }

        .tp-footer {
            text-align: center;
            padding: 1.5rem;
            color: #999;
            font-size: 0.8rem;
        }

        @media (max-width: 576px) {
            .tp-nav-link span { display: none; }
            .tp-main { margin: 1rem auto; }
        }
    </style>

    <script>const BASE_URL = '<?php echo BASE_URL; ?>';</script>
</head>
<body>

<!-- ============================================================
     TENANT PORTAL TOPBAR
     ============================================================ -->
<header class="tp-topbar">
    <a href="<?php echo BASE_URL; ?>modules/tenant-portal/" class="tp-topbar-brand">
        <div class="tp-topbar-brand-icon">
            <i class="bi bi-house-door"></i>
        </div>
        <span class="d-none d-sm-inline"><?php echo htmlspecialchars($companyName); ?></span>
    </a>

    <!-- Navigation -->
    <nav class="d-flex align-items-center gap-1">
        <?php
        $tpUri = $_SERVER['REQUEST_URI'] ?? '';
        function isTpActive($path) { global $tpUri; return strpos($tpUri, $path) !== false && strpos($tpUri, $path) === strrpos($tpUri, $path) ? 'active' : ''; }
        ?>
        <a href="<?php echo BASE_URL; ?>modules/tenant-portal/" class="tp-nav-link <?php echo (basename($tpUri) === '' || basename($tpUri) === 'index.php' || rtrim(parse_url($tpUri, PHP_URL_PATH), '/') === rtrim(parse_url(BASE_URL . 'modules/tenant-portal', PHP_URL_PATH), '/')) ? 'active' : ''; ?>">
            <i class="bi bi-grid-1x2"></i> <span><?php _e('dashboard'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/tenant-portal/payments.php" class="tp-nav-link <?php echo strpos($tpUri, 'payments.php') !== false ? 'active' : ''; ?>">
            <i class="bi bi-cash-coin"></i> <span><?php _e('payments'); ?></span>
        </a>
        <?php
        // Maintenance requests are offered to tenants only when the maintenance
        // module is on AND in tier — the same condition the staff sidebar uses.
        if (getSetting('maintenance_enabled', '0') === '1' && hasTier('enterprise')):
        ?>
        <a href="<?php echo BASE_URL; ?>modules/tenant-portal/maintenance.php" class="tp-nav-link <?php echo strpos($tpUri, 'maintenance.php') !== false ? 'active' : ''; ?>">
            <i class="bi bi-tools"></i> <span><?php _e('tp_maintenance', 'Maintenance'); ?></span>
        </a>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>modules/tenant-portal/profile.php" class="tp-nav-link <?php echo strpos($tpUri, 'profile.php') !== false ? 'active' : ''; ?>">
            <i class="bi bi-person"></i> <span><?php _e('profile'); ?></span>
        </a>
    </nav>

    <div class="tp-topbar-right">
        <!-- Language -->
        <div class="dropdown">
            <button class="tp-lang-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <?php
                $langLabels = ['en' => 'EN', 'sw' => 'SW', 'ar' => 'AR', 'hi' => 'HI', 'ur' => 'UR', 'gu' => 'GU'];
                echo $langLabels[$currentLang] ?? 'EN';
                ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php
                $langNames = ['en' => 'English', 'sw' => 'Kiswahili', 'ar' => 'العربية', 'hi' => 'हिन्दी', 'ur' => 'اردو', 'gu' => 'ગુજરાતી'];
                $currentParams = $_GET;
                foreach ($langNames as $code => $label):
                    $currentParams['lang'] = $code;
                    $url = '?' . http_build_query($currentParams);
                ?>
                <li><a class="dropdown-item <?php echo $currentLang === $code ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($url); ?>"><?php echo $label; ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- User -->
        <div class="dropdown">
            <button class="d-flex align-items-center gap-1 bg-transparent border-0 text-white dropdown-toggle" type="button" data-bs-toggle="dropdown" style="font-size:0.85rem;">
                <span class="tp-user-avatar"><?php echo $tpInitials; ?></span>
                <span class="d-none d-md-inline"><?php echo htmlspecialchars($tpTenantName); ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header"><?php _e('tp_portal', 'Tenant Portal'); ?></h6></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/tenant-portal/profile.php"><i class="bi bi-person me-2"></i><?php _e('profile'); ?></a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>modules/tenant-portal/login.php?logout=1"><i class="bi bi-box-arrow-right me-2"></i><?php _e('logout'); ?></a></li>
            </ul>
        </div>
    </div>
</header>

<!-- ============================================================
     MAIN CONTENT AREA
     ============================================================ -->
<main class="tp-main">

<?php
// Flash alert
$alert = getAlert();
if ($alert):
?>
<div class="alert alert-<?php echo $alert['type'] === 'error' ? 'danger' : $alert['type']; ?> alert-dismissible fade show" role="alert">
    <?php echo $alert['message']; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
