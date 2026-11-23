<?php
/**
 * FIS Real Estate — Documentation System (Part 24.1)
 * Standalone docs: layout, sidebar nav, breadcrumbs, full-text search
 * No auth required — public documentation pages
 */

// Minimal bootstrap — no auth needed
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Determine which doc page to show
$page = isset($_GET['page']) ? preg_replace('/[^a-z0-9_-]/', '', $_GET['page']) : 'getting-started';
$section = isset($_GET['section']) ? preg_replace('/[^a-z0-9_-]/', '', $_GET['section']) : '';

// Documentation structure
$docSections = [
    'getting-started' => [
        'title' => __('docs_getting_started', 'Getting Started'),
        'icon' => 'bi-rocket-takeoff',
        'group' => 'guides'
    ],
    'properties' => [
        'title' => __('docs_properties_guide', 'Properties'),
        'icon' => 'bi-buildings',
        'group' => 'guides'
    ],
    'units' => [
        'title' => __('docs_units_guide', 'Units'),
        'icon' => 'bi-door-open',
        'group' => 'guides'
    ],
    'tenants' => [
        'title' => __('docs_tenants_guide', 'Tenants'),
        'icon' => 'bi-people',
        'group' => 'guides'
    ],
    'leases' => [
        'title' => __('docs_leases_guide', 'Leases'),
        'icon' => 'bi-file-earmark-text',
        'group' => 'guides'
    ],
    'payments' => [
        'title' => __('docs_payments_guide', 'Payments & Receipts'),
        'icon' => 'bi-cash-coin',
        'group' => 'guides'
    ],
    'deposits' => [
        'title' => __('docs_deposits_guide', 'Deposits & Late Fees'),
        'icon' => 'bi-safe',
        'group' => 'guides'
    ],
    'ledger' => [
        'title' => __('docs_ledger_guide', 'Accounting Ledger'),
        'icon' => 'bi-journal-bookmark',
        'group' => 'guides'
    ],
    'inspections' => [
        'title' => __('docs_inspections_guide', 'Inspections'),
        'icon' => 'bi-clipboard-check',
        'group' => 'guides'
    ],
    'reports' => [
        'title' => __('docs_reports_guide', 'Reports & Dashboard'),
        'icon' => 'bi-bar-chart-line',
        'group' => 'guides'
    ],
    'settings' => [
        'title' => __('docs_settings_guide', 'Settings & Users'),
        'icon' => 'bi-gear',
        'group' => 'guides'
    ],
    'maintenance' => [
        'title' => __('docs_maintenance_guide', 'Maintenance'),
        'icon' => 'bi-tools',
        'group' => 'enterprise'
    ],
    'utilities' => [
        'title' => __('docs_utilities_guide', 'Utility Billing'),
        'icon' => 'bi-lightning',
        'group' => 'enterprise'
    ],
    'expenses' => [
        'title' => __('docs_expenses_guide', 'Expenses & Financial'),
        'icon' => 'bi-graph-up-arrow',
        'group' => 'enterprise'
    ],
    'vacancies' => [
        'title' => __('docs_vacancies_guide', 'Vacancy Advertising'),
        'icon' => 'bi-megaphone',
        'group' => 'enterprise'
    ],
    'gateway' => [
        'title' => __('docs_gateway_guide', 'Payment Gateway'),
        'icon' => 'bi-phone',
        'group' => 'enterprise'
    ],
    'reminders' => [
        'title' => __('docs_reminders_guide', 'Automated Reminders'),
        'icon' => 'bi-bell',
        'group' => 'professional'
    ],
    'documents' => [
        'title' => __('docs_documents_guide', 'Document Management'),
        'icon' => 'bi-folder2-open',
        'group' => 'professional'
    ],
    'tenant-portal' => [
        'title' => __('docs_tenant_portal', 'Tenant Portal Guide'),
        'icon' => 'bi-person-badge',
        'group' => 'portal'
    ],
    'tenant-portal-login' => [
        'title' => __('docs_tp_login', 'Portal Login'),
        'icon' => 'bi-box-arrow-in-right',
        'group' => 'portal'
    ],
    'tenant-portal-payments' => [
        'title' => __('docs_tp_payments', 'View Payments'),
        'icon' => 'bi-receipt',
        'group' => 'portal'
    ],
    'tenant-portal-profile' => [
        'title' => __('docs_tp_profile', 'Profile Management'),
        'icon' => 'bi-person-circle',
        'group' => 'portal'
    ],
    'api-overview' => [
        'title' => __('docs_api_overview', 'API Overview'),
        'icon' => 'bi-code-slash',
        'group' => 'api'
    ],
    'api-properties' => [
        'title' => __('docs_api_properties', 'Properties API'),
        'icon' => 'bi-braces',
        'group' => 'api'
    ],
    'api-tenants' => [
        'title' => __('docs_api_tenants', 'Tenants API'),
        'icon' => 'bi-braces',
        'group' => 'api'
    ],
    'api-payments' => [
        'title' => __('docs_api_payments', 'Payments API'),
        'icon' => 'bi-braces',
        'group' => 'api'
    ],
    'api-maintenance' => [
        'title' => __('docs_api_maintenance', 'Maintenance API'),
        'icon' => 'bi-braces',
        'group' => 'api'
    ],
    'videos' => [
        'title' => __('docs_video_tutorials', 'Video Tutorials'),
        'icon' => 'bi-play-circle',
        'group' => 'videos'
    ],
    'faq' => [
        'title' => __('docs_faq', 'FAQ'),
        'icon' => 'bi-question-circle',
        'group' => 'guides'
    ],
];

// Group labels
$groupLabels = [
    'guides'       => __('docs_group_guides', 'Landlord / Admin Guides'),
    'professional' => __('docs_group_professional', 'Professional Tier'),
    'enterprise'   => __('docs_group_enterprise', 'Enterprise Tier'),
    'portal'       => __('docs_group_portal', 'Tenant Portal'),
    'api'          => __('docs_group_api', 'API Documentation'),
    'videos'       => __('docs_group_videos', 'Video Tutorials'),
];

// Breadcrumb
$currentDoc = $docSections[$page] ?? $docSections['getting-started'];
$currentLang = getCurrentLang();
$dir = isRTL() ? 'rtl' : 'ltr';
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>" dir="<?php echo $dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($currentDoc['title']); ?> — <?php echo APP_NAME; ?> <?php echo __('docs_title', 'Documentation'); ?></title>
    <meta name="description" content="<?php echo APP_NAME; ?> documentation — property management system user guides, API reference, and tutorials.">
    <meta name="theme-color" content="#1a6b4a">

    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo BASE_URL; ?>assets/icons/icon-192x192.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --re-primary: #1a6b4a;
            --re-accent: #e8a838;
            --re-sidebar-w: 280px;
        }
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: #f8f9fa; }

        /* Top navbar */
        .docs-topbar {
            position: fixed; top: 0; left: 0; right: 0; z-index: 1030;
            height: 56px; background: var(--re-primary); color: #fff;
            display: flex; align-items: center; padding: 0 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .docs-topbar a { color: #fff; text-decoration: none; }
        .docs-topbar .brand { font-weight: 700; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem; }
        .docs-topbar .brand small { font-weight: 400; opacity: 0.8; font-size: 0.8rem; }

        /* Search */
        .docs-search { position: relative; max-width: 320px; flex: 1; margin: 0 1rem; }
        .docs-search input {
            width: 100%; border: none; border-radius: 6px; padding: 0.4rem 0.75rem 0.4rem 2rem;
            font-size: 0.85rem; background: rgba(255,255,255,0.15); color: #fff;
            transition: background 0.2s;
        }
        .docs-search input::placeholder { color: rgba(255,255,255,0.6); }
        .docs-search input:focus { background: rgba(255,255,255,0.25); outline: none; }
        .docs-search .bi-search { position: absolute; left: 0.6rem; top: 50%; transform: translateY(-50%); opacity: 0.6; }
        .docs-search-results {
            position: absolute; top: 100%; left: 0; right: 0;
            background: #fff; border-radius: 0 0 8px 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            max-height: 400px; overflow-y: auto; display: none; z-index: 1050;
        }
        .docs-search-results.show { display: block; }
        .docs-search-results a {
            display: block; padding: 0.6rem 1rem; color: #333; text-decoration: none;
            border-bottom: 1px solid #f0f0f0; font-size: 0.85rem;
        }
        .docs-search-results a:hover { background: #f0f7f4; }
        .docs-search-results a .match-title { font-weight: 600; }
        .docs-search-results a .match-snippet { color: #666; font-size: 0.8rem; }
        .docs-search-results mark { background: #fff3cd; padding: 0; }

        /* Sidebar */
        .docs-sidebar {
            position: fixed; top: 56px; left: 0; bottom: 0; width: var(--re-sidebar-w);
            background: #fff; border-right: 1px solid #e9ecef; overflow-y: auto;
            padding: 1rem 0; z-index: 1020; transition: transform 0.3s;
        }
        .docs-sidebar .group-label {
            padding: 0.6rem 1.25rem 0.3rem; font-size: 0.7rem; text-transform: uppercase;
            letter-spacing: 0.05em; color: #999; font-weight: 600;
        }
        .docs-sidebar a {
            display: flex; align-items: center; gap: 0.5rem;
            padding: 0.45rem 1.25rem; color: #555; text-decoration: none;
            font-size: 0.85rem; border-left: 3px solid transparent; transition: all 0.15s;
        }
        .docs-sidebar a:hover { background: #f0f7f4; color: var(--re-primary); }
        .docs-sidebar a.active {
            background: #e8f5ee; color: var(--re-primary); font-weight: 600;
            border-left-color: var(--re-primary);
        }
        .docs-sidebar a i { font-size: 0.9rem; width: 1.2rem; text-align: center; }

        /* Main content */
        .docs-main {
            margin-left: var(--re-sidebar-w); margin-top: 56px;
            padding: 2rem; min-height: calc(100vh - 56px);
        }

        /* Breadcrumbs */
        .docs-breadcrumb {
            background: none; padding: 0; margin-bottom: 1.5rem;
            font-size: 0.85rem;
        }
        .docs-breadcrumb a { color: var(--re-primary); text-decoration: none; }
        .docs-breadcrumb a:hover { text-decoration: underline; }

        /* Content styles */
        .docs-content { max-width: 860px; }
        .docs-content h1 { font-size: 1.8rem; font-weight: 700; color: #1a1a1a; margin-bottom: 1rem; }
        .docs-content h2 { font-size: 1.35rem; font-weight: 600; color: var(--re-primary); margin-top: 2rem; margin-bottom: 0.75rem; padding-bottom: 0.5rem; border-bottom: 2px solid #e8f5ee; }
        .docs-content h3 { font-size: 1.1rem; font-weight: 600; color: #333; margin-top: 1.5rem; margin-bottom: 0.5rem; }
        .docs-content p { line-height: 1.7; color: #444; margin-bottom: 1rem; }
        .docs-content ul, .docs-content ol { margin-bottom: 1rem; color: #444; }
        .docs-content li { margin-bottom: 0.3rem; line-height: 1.6; }
        .docs-content code { background: #f0f7f4; padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.85em; color: var(--re-primary); }
        .docs-content pre {
            background: #1e293b; color: #e2e8f0; padding: 1rem 1.25rem; border-radius: 8px;
            font-size: 0.82rem; overflow-x: auto; margin-bottom: 1rem;
        }
        .docs-content pre code { background: none; color: inherit; padding: 0; }
        .docs-content .alert { font-size: 0.9rem; }
        .docs-content table { width: 100%; margin-bottom: 1rem; }
        .docs-content table th { background: #f0f7f4; font-weight: 600; }
        .docs-content table th, .docs-content table td { padding: 0.6rem 0.75rem; border: 1px solid #dee2e6; font-size: 0.88rem; }
        .docs-content .tier-badge { display: inline-block; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
        .tier-basic { background: #e9ecef; color: #495057; }
        .tier-professional { background: #cfe2ff; color: #084298; }
        .tier-enterprise { background: #d1e7dd; color: #0f5132; }

        /* Footer */
        .docs-footer { text-align: center; padding: 2rem 0; color: #999; font-size: 0.8rem; border-top: 1px solid #e9ecef; margin-top: 3rem; }

        /* Mobile toggle */
        .docs-sidebar-toggle { display: none; background: none; border: none; color: #fff; font-size: 1.4rem; cursor: pointer; }

        /* Language selector */
        .docs-lang-selector { position: relative; margin-left: auto; margin-right: 0.75rem; }
        .docs-lang-btn {
            background: rgba(255,255,255,0.15); border: none; color: #fff; padding: 0.3rem 0.6rem;
            border-radius: 6px; font-size: 0.8rem; cursor: pointer; display: flex; align-items: center; gap: 0.35rem;
            transition: background 0.2s;
        }
        .docs-lang-btn:hover { background: rgba(255,255,255,0.25); }
        .docs-lang-dropdown {
            display: none; position: absolute; top: 100%; right: 0; margin-top: 0.35rem;
            background: #fff; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            min-width: 160px; z-index: 1050; overflow: hidden;
        }
        .docs-lang-dropdown.show { display: block; }
        .docs-lang-dropdown a {
            display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.85rem;
            color: #333; text-decoration: none; font-size: 0.85rem; border-bottom: 1px solid #f0f0f0;
        }
        .docs-lang-dropdown a:last-child { border-bottom: none; }
        .docs-lang-dropdown a:hover { background: #f0f7f4; }
        .docs-lang-dropdown a.active { background: #e8f5ee; color: var(--re-primary); font-weight: 600; }
        .docs-lang-dropdown a .lang-code { font-size: 0.7rem; opacity: 0.5; margin-left: auto; text-transform: uppercase; }

        /* Back to app link */
        .docs-back-link { font-size: 0.85rem; opacity: 0.85; }
        .docs-back-link:hover { opacity: 1; }

        /* Responsive */
        @media (max-width: 991.98px) {
            .docs-sidebar { transform: translateX(-100%); }
            .docs-sidebar.show { transform: translateX(0); box-shadow: 4px 0 16px rgba(0,0,0,0.1); }
            .docs-main { margin-left: 0; }
            .docs-sidebar-toggle { display: block; }
            .docs-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.3); z-index: 1015; }
            .docs-overlay.show { display: block; }
        }
    </style>
</head>
<body>

<!-- Top Bar -->
<nav class="docs-topbar">
    <button class="docs-sidebar-toggle" id="docsSidebarToggle" aria-label="Toggle sidebar">
        <i class="bi bi-list"></i>
    </button>
    <a href="<?php echo BASE_URL; ?>modules/docs/" class="brand">
        <i class="bi bi-building"></i>
        <?php echo APP_NAME; ?>
        <small><?php echo __('docs_title', 'Documentation'); ?></small>
    </a>
    <div class="docs-search">
        <i class="bi bi-search"></i>
        <input type="text" id="docsSearchInput" placeholder="<?php echo __('docs_search_placeholder', 'Search documentation...'); ?>" autocomplete="off">
        <div class="docs-search-results" id="docsSearchResults"></div>
    </div>
    <?php
    $langNames = [
        'en' => 'English',
        'sw' => 'Kiswahili',
        'ar' => 'العربية',
        'hi' => 'हिन्दी',
        'ur' => 'اردو',
        'gu' => 'ગુજરાતી'
    ];
    $langCodes = array_keys($langNames);
    $currentLangCode = strtoupper($currentLang);
    // Build URL keeping current page parameter
    $langBaseUrl = BASE_URL . 'modules/docs/?page=' . urlencode($page);
    ?>
    <div class="docs-lang-selector">
        <button class="docs-lang-btn" id="docsLangBtn" aria-label="Select language">
            <i class="bi bi-globe2"></i>
            <?php echo $currentLangCode; ?>
            <i class="bi bi-chevron-down" style="font-size:0.65rem;"></i>
        </button>
        <div class="docs-lang-dropdown" id="docsLangDropdown">
            <?php foreach ($langNames as $code => $name): ?>
            <a href="<?php echo $langBaseUrl . '&lang=' . $code; ?>" class="<?php echo ($currentLang === $code) ? 'active' : ''; ?>">
                <?php echo $name; ?>
                <span class="lang-code"><?php echo $code; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <a href="<?php echo BASE_URL; ?>" class="docs-back-link">
        <i class="bi bi-arrow-left me-1"></i><?php echo __('docs_back_to_app', 'Back to App'); ?>
    </a>
</nav>

<!-- Mobile overlay -->
<div class="docs-overlay" id="docsOverlay"></div>

<!-- Sidebar -->
<aside class="docs-sidebar" id="docsSidebar">
    <?php
    $lastGroup = '';
    foreach ($docSections as $slug => $doc):
        if ($doc['group'] !== $lastGroup):
            $lastGroup = $doc['group'];
    ?>
    <div class="group-label"><?php echo $groupLabels[$doc['group']] ?? $doc['group']; ?></div>
    <?php endif; ?>
    <a href="<?php echo BASE_URL; ?>modules/docs/?page=<?php echo $slug; ?>" class="<?php echo ($page === $slug) ? 'active' : ''; ?>">
        <i class="bi <?php echo $doc['icon']; ?>"></i>
        <?php echo $doc['title']; ?>
    </a>
    <?php endforeach; ?>
</aside>

<!-- Main Content -->
<div class="docs-main">
    <!-- Breadcrumb -->
    <nav class="docs-breadcrumb" aria-label="breadcrumb">
        <a href="<?php echo BASE_URL; ?>modules/docs/"><?php echo __('docs_title', 'Documentation'); ?></a>
        <span class="mx-2">/</span>
        <span><?php echo htmlspecialchars($currentDoc['title']); ?></span>
    </nav>

    <div class="docs-content" id="docsContent">
        <?php
        // Load the page content
        $pageFile = __DIR__ . '/pages/' . $page . '.php';
        if (file_exists($pageFile)) {
            include $pageFile;
        } else {
            // Default to getting started
            include __DIR__ . '/pages/getting-started.php';
        }
        ?>
    </div>

    <!-- Page nav -->
    <?php
    $keys = array_keys($docSections);
    $currentIdx = array_search($page, $keys);
    $prevPage = ($currentIdx > 0) ? $keys[$currentIdx - 1] : null;
    $nextPage = ($currentIdx !== false && $currentIdx < count($keys) - 1) ? $keys[$currentIdx + 1] : null;
    ?>
    <div class="d-flex justify-content-between mt-5 pt-3 border-top">
        <?php if ($prevPage): ?>
        <a href="?page=<?php echo $prevPage; ?>" class="text-decoration-none" style="color:var(--re-primary);">
            <i class="bi bi-arrow-left me-1"></i> <?php echo $docSections[$prevPage]['title']; ?>
        </a>
        <?php else: ?><span></span><?php endif; ?>

        <?php if ($nextPage): ?>
        <a href="?page=<?php echo $nextPage; ?>" class="text-decoration-none" style="color:var(--re-primary);">
            <?php echo $docSections[$nextPage]['title']; ?> <i class="bi bi-arrow-right ms-1"></i>
        </a>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <div class="docs-footer">
        <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?> &copy; <?php echo date('Y'); ?> &mdash;
        <?php echo __('docs_footer_text', 'Property Management Made Simple'); ?>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Mobile sidebar toggle
document.getElementById('docsSidebarToggle').addEventListener('click', function() {
    document.getElementById('docsSidebar').classList.toggle('show');
    document.getElementById('docsOverlay').classList.toggle('show');
});
document.getElementById('docsOverlay').addEventListener('click', function() {
    document.getElementById('docsSidebar').classList.remove('show');
    this.classList.remove('show');
});

// Full-text search index (client-side)
const docsIndex = [
    <?php foreach ($docSections as $slug => $doc): ?>
    { slug: '<?php echo $slug; ?>', title: <?php echo json_encode($doc['title']); ?>, group: <?php echo json_encode($groupLabels[$doc['group']] ?? $doc['group']); ?>,
      keywords: <?php
        // Build keyword index per page
        $kw = mb_strtolower($doc['title'], 'UTF-8');
        // Add common keywords by group
        $extraKw = '';
        if ($doc['group'] === 'guides') $extraKw = 'landlord admin management setup create edit delete';
        if ($doc['group'] === 'enterprise') $extraKw = 'enterprise tier advanced';
        if ($doc['group'] === 'professional') $extraKw = 'professional tier';
        if ($doc['group'] === 'portal') $extraKw = 'tenant portal self-service login';
        if ($doc['group'] === 'api') $extraKw = 'api endpoint request response json authentication';
        if ($doc['group'] === 'videos') $extraKw = 'video tutorial youtube guide';
        // Page-specific keywords
        $pageKw = [
            'getting-started' => 'first steps setup login property unit tenant lease payment quick start guide welcome',
            'properties' => 'property building address create edit delete photos settings residential commercial land',
            'units' => 'unit room apartment shop office floor rent deposit status vacant occupied',
            'tenants' => 'tenant occupant profile phone email id screening reference emergency contact',
            'leases' => 'lease contract agreement start end date rent frequency template pdf renewal termination',
            'payments' => 'payment receipt cash bank mobile money collection recording bulk import period',
            'deposits' => 'deposit security refund deduction late fee grace period penalty',
            'inspections' => 'inspection move-in move-out condition checklist room item photo report',
            'reports' => 'report dashboard chart revenue occupancy financial collection aging export csv',
            'settings' => 'settings user role permission configuration company backup export notification language currency',
            'maintenance' => 'maintenance request work order contractor repair category priority status cost',
            'utilities' => 'utility billing water electricity garbage meter reading consumption charge',
            'expenses' => 'expense income profit loss financial report category vendor receipt',
            'vacancies' => 'vacancy listing public page inquiry lead advertising available',
            'gateway' => 'payment gateway mobile money selcom mpesa airtel tigo callback reconciliation',
            'reminders' => 'reminder notification whatsapp sms email template schedule automatic',
            'documents' => 'document upload file storage category drag drop management',
            'tenant-portal' => 'tenant portal self service dashboard balance due payment history profile',
            'tenant-portal-login' => 'tenant login password authentication phone email access',
            'tenant-portal-payments' => 'tenant payment history receipt download view balance outstanding',
            'tenant-portal-profile' => 'tenant profile update phone email emergency contact',
            'api-overview' => 'api authentication session csrf token endpoint base url request response error',
            'api-properties' => 'api properties list create update delete units settings photos',
            'api-tenants' => 'api tenants list create update screening search status',
            'api-payments' => 'api payments record receipt bulk reverse period allocation',
            'api-maintenance' => 'api maintenance request contractor assignment work order status',
            'videos' => 'video tutorial walkthrough demo youtube vimeo guide',
            'faq' => 'faq frequently asked questions help troubleshooting common issues',
        ];
        $kwString = mb_strtolower($kw . ' ' . $extraKw . ' ' . ($pageKw[$slug] ?? ''), 'UTF-8');
        $encoded = json_encode($kwString, JSON_UNESCAPED_UNICODE);
        echo ($encoded !== false) ? $encoded : json_encode($extraKw . ' ' . ($pageKw[$slug] ?? ''));
      ?> },
    <?php endforeach; ?>
];

const searchInput = document.getElementById('docsSearchInput');
const searchResults = document.getElementById('docsSearchResults');

searchInput.addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    if (q.length < 2) { searchResults.classList.remove('show'); return; }

    const matches = docsIndex.filter(d => d.keywords.includes(q) || d.title.toLowerCase().includes(q));
    if (matches.length === 0) {
        searchResults.innerHTML = '<div class="p-3 text-muted text-center" style="font-size:0.85rem;">' + <?php echo json_encode(__('docs_no_results', 'No results found')); ?> + '</div>';
    } else {
        searchResults.innerHTML = matches.map(m =>
            '<a href="?page=' + m.slug + '"><span class="match-title">' + m.title + '</span><br><span class="match-snippet">' + m.group + '</span></a>'
        ).join('');
    }
    searchResults.classList.add('show');
});

document.addEventListener('click', function(e) {
    if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
        searchResults.classList.remove('show');
    }
    if (!document.getElementById('docsLangBtn').contains(e.target) && !document.getElementById('docsLangDropdown').contains(e.target)) {
        document.getElementById('docsLangDropdown').classList.remove('show');
    }
});

// Keyboard shortcut: Ctrl+K or / to focus search
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey && e.key === 'k') || (e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA')) {
        e.preventDefault();
        searchInput.focus();
    }
});

// Language selector toggle
const langBtn = document.getElementById('docsLangBtn');
const langDropdown = document.getElementById('docsLangDropdown');
langBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    langDropdown.classList.toggle('show');
    searchResults.classList.remove('show');
});
</script>
</body>
</html>
