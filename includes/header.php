<?php
// The sidebar gates entries on hasTier(); pull in its definition so this file
// works on pages that don't require subscription.php themselves (the account
// pages under modules/auth/). Function definitions only — no output.
require_once __DIR__ . '/subscription.php';
require_once __DIR__ . '/payables.php';
require_once __DIR__ . '/page_intro.php';
require_once __DIR__ . '/ledger_nav.php';

// Prevent browser caching of authenticated pages
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

$userName = getUserData('name');
$userRole = getUserData('role_name');
$userInitials = '';
$nameParts = explode(' ', $userName);
foreach ($nameParts as $part) {
    $userInitials .= mb_strtoupper(mb_substr($part, 0, 1));
}
$userInitials = mb_substr($userInitials, 0, 2);

$currentLang = getCurrentLang();
$dir = isRTL() ? 'rtl' : 'ltr';

// Detect current page for active nav highlighting
$currentUri = $_SERVER['REQUEST_URI'] ?? '';
function isNavActive($path) {
    global $currentUri;
    return strpos($currentUri, $path) !== false ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>" dir="<?php echo $dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle ?? APP_NAME; ?></title>

    <meta name="description" content="FIS Real Estate — Property Management System">
    <meta name="theme-color" content="#1a6b4a">

    <!-- PWA Manifest & Icons -->
    <link rel="manifest" href="<?php echo BASE_URL; ?>manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo BASE_URL; ?>assets/icons/icon-192x192.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>assets/icons/icon-192x192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo APP_NAME; ?>">
    <meta name="msapplication-TileImage" content="<?php echo BASE_URL; ?>assets/icons/icon-144x144.png">
    <meta name="msapplication-TileColor" content="#1a6b4a">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <!-- Select2 Bootstrap 5 theme (required by selects using theme: 'bootstrap-5') -->
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <!-- App CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css?v=1.0">

    <?php if (isset($customCSS)): ?>
    <link rel="stylesheet" href="<?php echo BASE_URL . $customCSS; ?>">
    <?php endif; ?>

    <script>const BASE_URL = '<?php echo BASE_URL; ?>';</script>
</head>
<body data-user-id="<?php echo (int) getUserId(); ?>">

<!-- Overlay for mobile sidebar -->
<div class="re-overlay" id="sidebarOverlay"></div>

<!-- ============================================================
     SIDEBAR
     ============================================================ -->
<aside class="re-sidebar" id="sidebar">
    <!-- Brand -->
    <a href="<?php echo BASE_URL; ?>" class="re-sidebar-brand">
        <div class="re-sidebar-brand-icon">
            <i class="bi bi-building"></i>
        </div>
        <div class="re-sidebar-brand-text">
            <?php echo APP_NAME; ?>
            <small>Property Management</small>
        </div>
    </a>

    <!-- Main Navigation -->
    <div class="re-sidebar-section">Main</div>
    <ul class="re-sidebar-nav">
        <li>
            <a href="<?php echo BASE_URL; ?>" class="<?php echo (rtrim($currentUri, '/') === rtrim(parse_url(BASE_URL, PHP_URL_PATH), '/')) ? 'active' : ''; ?>">
                <i class="bi bi-grid-1x2"></i> <?php _e('dashboard'); ?>
            </a>
        </li>
    </ul>

    <!-- Property Management -->
    <div class="re-sidebar-section">Property Management</div>
    <ul class="re-sidebar-nav">
        <?php if (hasPermission('properties', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/properties/" class="<?php echo isNavActive('modules/properties'); ?>">
                <i class="bi bi-buildings"></i> <?php _e('properties'); ?>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasPermission('units', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/units/" class="<?php echo isNavActive('modules/units'); ?>">
                <i class="bi bi-door-open"></i> <?php _e('units'); ?>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasPermission('tenants', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/tenants/" class="<?php echo isNavActive('modules/tenants'); ?>">
                <i class="bi bi-people"></i> <?php _e('tenants'); ?>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasPermission('leases', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/leases/" class="<?php echo isNavActive('modules/leases'); ?>">
                <i class="bi bi-file-earmark-text"></i> <?php _e('leases'); ?>
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <!-- Financial -->
    <div class="re-sidebar-section">Financial</div>
    <ul class="re-sidebar-nav">
        <?php if (hasPermission('payments', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/payments/" class="<?php echo isNavActive('modules/payments'); ?>">
                <i class="bi bi-cash-coin"></i> <?php _e('payments'); ?>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasPermission('deposits', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/deposits/" class="<?php echo isNavActive('modules/deposits'); ?>">
                <i class="bi bi-safe"></i> <?php _e('deposits'); ?>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasTier('enterprise') && getSetting('financial_reports_enabled', '0') === '1' && hasPermission('expenses', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/expenses/" class="<?php echo isNavActive('modules/expenses'); ?>">
                <i class="bi bi-receipt-cutoff"></i> <?php _e('exp_expenses', 'Expenses'); ?>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/expenses/reports.php" class="<?php echo isNavActive('expenses/reports'); ?>">
                <i class="bi bi-graph-up-arrow"></i> <?php _e('exp_financial_reports', 'Financial Reports'); ?>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasTier('enterprise') && getSetting('payment_gateway_enabled', '0') === '1' && hasPermission('payments', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/payments/gateway-transactions.php" class="<?php echo isNavActive('gateway-transactions') || isNavActive('gateway-pay') ? 'active' : ''; ?>">
                <i class="bi bi-phone"></i> <?php _e('gw_gateway_payments', 'Gateway Payments'); ?>
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <!-- Operations -->
    <div class="re-sidebar-section">Operations</div>
    <ul class="re-sidebar-nav">
        <?php if (hasPermission('inspections', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/inspections/" class="<?php echo isNavActive('modules/inspections'); ?>">
                <i class="bi bi-clipboard-check"></i> <?php _e('inspections'); ?>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasTier('enterprise') && getSetting('maintenance_enabled', '0') === '1' && hasPermission('maintenance', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/maintenance/" class="<?php echo isNavActive('modules/maintenance'); ?>">
                <i class="bi bi-tools"></i> <?php _e('maintenance'); ?>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasTier('enterprise') && getSetting('utility_billing_enabled', '0') === '1' && hasPermission('utilities', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/utilities/" class="<?php echo isNavActive('modules/utilities'); ?>">
                <i class="bi bi-lightning"></i> <?php _e('util_billing', 'Utility Billing'); ?>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasTier('enterprise') && getSetting('vacancy_advertising_enabled', '0') === '1' && hasPermission('vacancies', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/vacancies/" class="<?php echo isNavActive('modules/vacancies'); ?>">
                <i class="bi bi-megaphone"></i> <?php _e('vac_vacancies', 'Vacancies'); ?>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/vacancies/inquiries.php" class="<?php echo isNavActive('vacancies/inquiries'); ?>">
                <i class="bi bi-chat-left-dots"></i> <?php _e('vac_inquiries', 'Inquiries'); ?>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasPermission('reports', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/reports/" class="<?php echo isNavActive('modules/reports'); ?>">
                <i class="bi bi-bar-chart-line"></i> <?php _e('reports'); ?>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasPermission('ledger', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/ledger/" class="<?php echo isNavActive('modules/ledger'); ?>">
                <i class="bi bi-journal-bookmark"></i> <?php _e('led_ledger', 'Ledger'); ?>
                <?php
                // Only approvers see the pending badge — it is a call to action,
                // not a general statistic.
                if (function_exists('ledgerCanApprove') && ledgerCanApprove()):
                    $ledPending = ledgerPendingCount()
                                + (function_exists('remittancePendingCount') ? remittancePendingCount() : 0)
                                + (function_exists('depositPayoutsPending') ? count(depositPayoutsPending()) : 0);
                    if ($ledPending > 0):
                ?>
                <span class="badge bg-warning text-dark ms-1"><?php echo $ledPending; ?></span>
                <?php endif; endif; ?>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasTier('professional') && getSetting('reminders_enabled', '0') === '1' && hasPermission('settings', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/settings/reminders.php" class="<?php echo isNavActive('settings/reminders') || isNavActive('settings/reminder-log') ? 'active' : ''; ?>">
                <i class="bi bi-bell"></i> <?php _e('rem_reminders', 'Reminders'); ?>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasTier('professional') && getSetting('documents_enabled', '0') === '1' && hasPermission('documents', 'view')): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/documents/" class="<?php echo isNavActive('modules/documents'); ?>">
                <i class="bi bi-folder2-open"></i> <?php _e('doc_documents', 'Documents'); ?>
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <!-- Admin (only for Admin role) -->
    <?php if (getUserData('role_name') === 'Admin'): ?>
    <div class="re-sidebar-section">Administration</div>
    <ul class="re-sidebar-nav">
        <li>
            <a href="<?php echo BASE_URL; ?>modules/settings/" class="<?php echo isNavActive('modules/settings'); ?>">
                <i class="bi bi-gear"></i> <?php _e('settings'); ?>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/masters/users.php" class="<?php echo isNavActive('masters/users'); ?>">
                <i class="bi bi-person-badge"></i> <?php _e('users'); ?>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/masters/roles.php" class="<?php echo isNavActive('masters/roles'); ?>">
                <i class="bi bi-shield-lock"></i> <?php _e('roles'); ?>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/settings/backup.php" class="<?php echo isNavActive('settings/backup'); ?>">
                <i class="bi bi-cloud-download"></i> <?php _e('set_backup', 'Backup & Export'); ?>
            </a>
        </li>
        <?php if (getUserData('role_name') === 'Admin'): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/import/" class="<?php echo isNavActive('modules/import'); ?>">
                <i class="bi bi-box-arrow-in-down"></i> <?php _e('imp_title', 'Data Import'); ?>
            </a>
        </li>
        <?php endif; ?>
        <?php if (isSaasOperator()): ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/settings/sales-agents.php" class="<?php echo isNavActive('settings/sales-agents'); ?>">
                <i class="bi bi-people-fill"></i> <?php _e('sa_sales_agents', 'Sales Agents'); ?>
            </a>
        </li>
        <?php endif; ?>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/subscription/status.php" class="<?php echo isNavActive('modules/subscription'); ?>">
                <i class="bi bi-credit-card-2-front"></i> <?php _e('subscription', 'Subscription'); ?>
            </a>
        </li>
    </ul>

    <?php if (isSaasOperator()): ?>
    <!-- SaaS Admin Panel (master/operator instance only) -->
    <div class="re-sidebar-section"><?php _e('adm_saas_admin', 'SaaS Admin'); ?></div>
    <ul class="re-sidebar-nav">
        <li>
            <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php" class="<?php echo isNavActive('admin/dashboard'); ?>">
                <i class="bi bi-speedometer2"></i> <?php _e('adm_admin_dashboard', 'SaaS Dashboard'); ?>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/admin/subscribers.php" class="<?php echo isNavActive('admin/subscribers'); ?>">
                <i class="bi bi-building-gear"></i> <?php _e('adm_subscribers', 'Subscribers'); ?>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/admin/payments.php" class="<?php echo isNavActive('admin/payments'); ?>">
                <i class="bi bi-credit-card-2-back"></i> <?php _e('adm_verify_payments', 'Verify Payments'); ?>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/admin/announcements.php" class="<?php echo isNavActive('admin/announcements'); ?>">
                <i class="bi bi-megaphone"></i> <?php _e('adm_announcements', 'Announcements'); ?>
            </a>
        </li>
    </ul>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Documentation (visible to all roles) -->
    <div class="re-sidebar-section"><?php _e('docs_help', 'Help'); ?></div>
    <ul class="re-sidebar-nav">
        <li>
            <a href="<?php echo BASE_URL; ?>modules/docs/" class="<?php echo isNavActive('modules/docs'); ?>" target="_blank">
                <i class="bi bi-book"></i> <?php _e('docs_documentation', 'Documentation'); ?>
            </a>
        </li>
    </ul>

    <!-- Sidebar footer -->
    <div class="re-sidebar-footer">
        <a href="<?php echo BASE_URL; ?>modules/auth/logout.php" class="btn btn-outline-light btn-sm w-100" style="border-color:rgba(255,255,255,0.15);color:rgba(255,255,255,0.6);font-size:0.8rem;">
            <i class="bi bi-box-arrow-left me-1"></i> <?php _e('sign_out'); ?>
        </a>
    </div>
</aside>

<!-- ============================================================
     TOPBAR
     ============================================================ -->
<header class="re-topbar">
    <div class="re-topbar-left">
        <button class="re-hamburger" id="sidebarToggle" aria-label="Toggle menu">
            <i class="bi bi-list"></i>
        </button>
        <h6 class="mb-0 fw-semibold" style="font-size:0.95rem;">
            <?php echo $pageTitle ?? __('dashboard'); ?>
        </h6>
    </div>

    <div class="re-topbar-right">
        <!-- Offline Status Indicator -->
        <div id="offlineIndicator" class="re-offline-indicator d-none" title="<?php _e('pwa_offline_mode', 'Offline Mode'); ?>">
            <span class="status-dot offline" id="offlineStatusDot"></span>
            <span class="d-none d-md-inline" id="offlineStatusText" style="font-size:0.78rem;"><?php _e('offline_mode', 'Offline'); ?></span>
        </div>

        <!-- Sync Status -->
        <div id="syncIndicator" class="re-sync-indicator d-none" title="<?php _e('sync_syncing', 'Syncing...'); ?>">
            <i class="bi bi-arrow-repeat"></i>
        </div>

        <!-- Pending Sync Badge -->
        <div class="position-relative" style="margin-right:0.25rem;" title="<?php _e('sync_pending', 'Pending sync items'); ?>">
            <i class="bi bi-cloud-arrow-up" style="font-size:1.1rem;opacity:0.7;"></i>
            <span id="syncPendingBadge" class="badge bg-warning text-dark rounded-pill position-absolute d-none" style="top:-6px;right:-8px;font-size:0.6rem;padding:2px 5px;">0</span>
        </div>

        <!-- Language Selector -->
        <div class="dropdown">
            <button class="re-lang-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-globe2 me-1"></i>
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
                <li>
                    <a class="dropdown-item <?php echo $currentLang === $code ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($url); ?>">
                        <?php echo $label; ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- User Dropdown -->
        <div class="dropdown">
            <button class="re-user-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <span class="re-user-avatar"><?php echo $userInitials; ?></span>
                <span class="d-none d-md-inline"><?php echo htmlspecialchars($userName); ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header"><?php echo htmlspecialchars($userRole); ?></h6></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/auth/profile.php">
                    <i class="bi bi-person me-2"></i><?php _e('profile'); ?>
                </a></li>
                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/auth/change_password.php">
                    <i class="bi bi-key me-2"></i><?php _e('change_password'); ?>
                </a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>modules/auth/logout.php">
                    <i class="bi bi-box-arrow-right me-2"></i><?php _e('logout'); ?>
                </a></li>
            </ul>
        </div>
    </div>
</header>

<!-- ============================================================
     MAIN CONTENT AREA
     ============================================================ -->
<main class="re-main">

<!-- PWA Install Banner -->
<div id="pwaInstallBanner" class="alert alert-info alert-dismissible d-none mb-3" role="alert" style="border:1px solid #b6d4fe;background:linear-gradient(135deg,#e8f5ee,#dbeafe);border-radius:10px;">
    <div class="d-flex align-items-center gap-3">
        <div style="width:42px;height:42px;background:#1a6b4a;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-download text-white" style="font-size:1.2rem;"></i>
        </div>
        <div class="flex-grow-1">
            <strong><?php _e('pwa_install_title', 'Install FIS Real Estate'); ?></strong><br>
            <small class="text-muted"><?php _e('pwa_install_desc', 'Add to your home screen for quick access, even offline.'); ?></small>
        </div>
        <button type="button" class="btn btn-sm btn-primary" onclick="pwaInstall()" style="white-space:nowrap;">
            <i class="bi bi-plus-circle me-1"></i><?php _e('pwa_install_btn', 'Install'); ?>
        </button>
    </div>
    <button type="button" class="btn-close" onclick="pwaDismissInstall()" style="top:0.5rem;right:0.5rem;"></button>
</div>

<!-- PWA Update Banner -->
<div id="pwaUpdateBanner" class="alert alert-warning alert-dismissible d-none mb-3" role="alert">
    <i class="bi bi-arrow-repeat me-2"></i>
    <?php _e('pwa_update_available', 'A new version is available.'); ?>
    <a href="javascript:void(0)" onclick="pwaUpdate()" class="alert-link ms-1"><?php _e('pwa_update_now', 'Update now'); ?></a>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>

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
