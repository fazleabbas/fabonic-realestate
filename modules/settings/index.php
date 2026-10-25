<?php
/**
 * FIS Real Estate — Settings & Configuration (Part 14.1 + 14.2)
 * Company info, logo, contact details, tax ID, system preferences.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('settings', 'Settings');

$subStatus = enforceSubscription();

if (getUserData('role_name') !== 'Admin') {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Active tab
$tab = trim($_GET['tab'] ?? 'company');

/**
 * Optional modules, with the subscription tier each one needs.
 *
 * Single source of truth: the save handler enforces `req` and the toggle grid
 * renders from the same list, so the two can never drift apart. `req` must match
 * the tier the sidebar checks in includes/header.php for that module.
 */
$MODULE_DEFS = [
    ['key' => 'maintenance_enabled',        'icon' => 'bi-tools',                  'label' => 'set_mod_maintenance', 'default' => 'Maintenance Management', 'req' => 'enterprise'],
    ['key' => 'tenant_portal_enabled',      'icon' => 'bi-person-badge',           'label' => 'set_mod_portal',      'default' => 'Tenant Portal',          'req' => 'professional'],
    ['key' => 'reminders_enabled',          'icon' => 'bi-bell',                   'label' => 'set_mod_reminders',   'default' => 'Automated Reminders',    'req' => 'professional'],
    ['key' => 'utility_billing_enabled',    'icon' => 'bi-lightning',              'label' => 'set_mod_utility',     'default' => 'Utility Billing',        'req' => 'enterprise'],
    ['key' => 'financial_reports_enabled',  'icon' => 'bi-graph-up',               'label' => 'set_mod_financial',   'default' => 'Financial Reporting',    'req' => 'enterprise'],
    ['key' => 'vacancy_advertising_enabled','icon' => 'bi-megaphone',              'label' => 'set_mod_vacancy',     'default' => 'Vacancy Advertising',    'req' => 'enterprise'],
    ['key' => 'payment_gateway_enabled',    'icon' => 'bi-credit-card-2-front',    'label' => 'set_mod_gateway',     'default' => 'Payment Gateway',        'req' => 'enterprise'],
    ['key' => 'documents_enabled',          'icon' => 'bi-folder2-open',           'label' => 'set_mod_documents',   'default' => 'Document Management',    'req' => 'professional'],
];

/** 'enterprise' => 'Enterprise' — for display. */
function moduleTierLabel($tier) {
    return ucfirst($tier);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $postAction = trim($_POST['form_action'] ?? '');

    if ($postAction === 'save_company') {
        $fields = ['company_name', 'company_phone', 'company_email', 'company_address', 'company_tax_id', 'company_website'];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                updateSetting($f, trim($_POST[$f]));
            }
        }
        logActivity('updated', 'settings', null, ['group' => 'company']);
        setAlert(__('set_saved', 'Settings saved successfully'), 'success');
        redirect('modules/settings/?tab=company');
    }

    if ($postAction === 'save_preferences') {
        // Session timeout (validate: 0–480 minutes)
        if (isset($_POST['session_timeout_minutes'])) {
            $timeout = max(0, min(480, (int)$_POST['session_timeout_minutes']));
            updateSetting('session_timeout_minutes', (string)$timeout);
        }
        $prefFields = ['default_currency', 'language', 'timezone', 'date_format_pref', 'fiscal_year_start'];
        foreach ($prefFields as $f) {
            if (isset($_POST[$f])) {
                updateSetting($f, trim($_POST[$f]));
            }
        }
        // Supported currencies JSON
        if (isset($_POST['supported_currencies'])) {
            $currencies = is_array($_POST['supported_currencies']) ? $_POST['supported_currencies'] : ['TZS'];
            updateSetting('supported_currencies_json', json_encode(array_values($currencies)));
        }
        // Exchange rates
        if (isset($_POST['exchange_rate_USD'])) {
            $rates = [
                'USD' => (float)($_POST['exchange_rate_USD'] ?? 2500),
                'KES' => (float)($_POST['exchange_rate_KES'] ?? 18),
                'UGX' => (float)($_POST['exchange_rate_UGX'] ?? 0.68),
            ];
            updateSetting('exchange_rates_json', json_encode($rates));
        }
        logActivity('updated', 'settings', null, ['group' => 'preferences']);
        setAlert(__('set_saved', 'Settings saved successfully'), 'success');
        redirect('modules/settings/?tab=preferences');
    }

    if ($postAction === 'save_payments') {
        if (isset($_POST['payment_methods'])) {
            $methods = is_array($_POST['payment_methods']) ? $_POST['payment_methods'] : [];
            updateSetting('payment_methods_json', json_encode(array_values($methods)));
        }
        if (isset($_POST['receipt_prefix'])) updateSetting('receipt_prefix', trim($_POST['receipt_prefix']));
        if (isset($_POST['payment_instructions'])) updateSetting('payment_instructions', trim($_POST['payment_instructions']));
        logActivity('updated', 'settings', null, ['group' => 'payments']);
        setAlert(__('set_saved', 'Settings saved successfully'), 'success');
        redirect('modules/settings/?tab=payments');
    }

    if ($postAction === 'save_notifications') {
        if (isset($_POST['notification_channels'])) {
            $channels = is_array($_POST['notification_channels']) ? $_POST['notification_channels'] : [];
            updateSetting('notification_channels_json', json_encode(array_values($channels)));
        }
        logActivity('updated', 'settings', null, ['group' => 'notifications']);
        setAlert(__('set_saved', 'Settings saved successfully'), 'success');
        redirect('modules/settings/?tab=notifications');
    }

    if ($postAction === 'save_modules') {
        // Enforce the tier server-side. Without this a Basic tenant could tick
        // an Enterprise module, be told "saved successfully", and then never see
        // it appear — because the sidebar independently checks hasTier().
        $blocked = [];
        foreach ($MODULE_DEFS as $mod) {
            $wanted = isset($_POST[$mod['key']]);
            if ($wanted && !hasTier($mod['req'])) {
                $blocked[] = __($mod['label'], $mod['default'])
                    . ' (' . moduleTierLabel($mod['req']) . ')';
                updateSetting($mod['key'], '0');   // keep it off
                continue;
            }
            updateSetting($mod['key'], $wanted ? '1' : '0');
        }

        logActivity('updated', 'settings', null, ['group' => 'modules']);

        if ($blocked) {
            $upgradeUrl = BASE_URL . 'modules/subscription/subscribe.php';
            setAlert(
                '<strong>' . __('set_mod_blocked_title', 'Not included in your current package') . '</strong><br>'
                . htmlspecialchars(implode(', ', $blocked)) . ' '
                . __('set_mod_blocked_body', 'could not be enabled on your current plan.') . ' '
                . '<a href="' . htmlspecialchars($upgradeUrl) . '" class="alert-link fw-semibold">'
                . __('set_mod_blocked_link', 'Click here to upgrade') . '</a>.',
                'warning'
            );
        } else {
            setAlert(__('set_saved', 'Settings saved successfully'), 'success');
        }
        redirect('modules/settings/?tab=modules');
    }
}

// Load all settings
$allSettings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch()) {
        $allSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {}

$s = function($key, $default = '') use ($allSettings) {
    return $allSettings[$key] ?? $default;
};

// Parse JSON settings
$supportedCurrencies = json_decode($s('supported_currencies_json', '["TZS"]'), true) ?: ['TZS'];
$exchangeRates = json_decode($s('exchange_rates_json', '{}'), true) ?: [];
$paymentMethods = json_decode($s('payment_methods_json', '["cash"]'), true) ?: [];
$notifChannels = json_decode($s('notification_channels_json', '["email"]'), true) ?: [];

// Timezones relevant to East Africa
$timezones = [
    'Africa/Dar_es_Salaam' => 'Dar es Salaam (EAT, UTC+3)',
    'Africa/Nairobi' => 'Nairobi (EAT, UTC+3)',
    'Africa/Kampala' => 'Kampala (EAT, UTC+3)',
    'Africa/Addis_Ababa' => 'Addis Ababa (EAT, UTC+3)',
    'Africa/Lagos' => 'Lagos (WAT, UTC+1)',
    'Africa/Johannesburg' => 'Johannesburg (SAST, UTC+2)',
    'Africa/Cairo' => 'Cairo (EET, UTC+2)',
    'Asia/Dubai' => 'Dubai (GST, UTC+4)',
    'Europe/London' => 'London (GMT/BST)',
    'UTC' => 'UTC',
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-gear me-2"></i><?php _e('settings', 'Settings'); ?></h4>
        <p class="text-muted mb-0"><?php _e('set_desc', 'Manage company information, system preferences, and module configuration'); ?></p>
    </div>
    <div class="d-flex gap-1">
        <a href="<?php echo BASE_URL; ?>modules/settings/backup.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-download me-1"></i> <?php _e('set_backup', 'Backup & Export'); ?>
        </a>
    </div>
</div>

<?php pageIntro('settings.index'); ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'company' ? 'active' : ''; ?>" href="?tab=company">
            <i class="bi bi-building me-1"></i> <?php _e('set_company', 'Company'); ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'preferences' ? 'active' : ''; ?>" href="?tab=preferences">
            <i class="bi bi-sliders me-1"></i> <?php _e('set_preferences', 'Preferences'); ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'payments' ? 'active' : ''; ?>" href="?tab=payments">
            <i class="bi bi-credit-card me-1"></i> <?php _e('set_payments', 'Payments'); ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'notifications' ? 'active' : ''; ?>" href="?tab=notifications">
            <i class="bi bi-bell me-1"></i> <?php _e('set_notifications', 'Notifications'); ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'modules' ? 'active' : ''; ?>" href="?tab=modules">
            <i class="bi bi-puzzle me-1"></i> <?php _e('set_modules', 'Modules'); ?>
        </a>
    </li>
</ul>

<?php if ($tab === 'company'): ?>
<!-- ============================================================
     COMPANY INFORMATION TAB
     ============================================================ -->
<div class="row g-4">
    <!-- Company Logo -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-image me-1"></i> <?php _e('set_logo', 'Company Logo'); ?></div>
            <div class="card-body text-center">
                <div id="logoPreview" class="mb-3">
                    <?php if ($s('company_logo')): ?>
                    <img src="<?php echo BASE_URL . 'uploads/' . htmlspecialchars($s('company_logo')); ?>" alt="Logo" style="max-width:200px;max-height:200px;border-radius:8px;border:1px solid #ddd;">
                    <?php else: ?>
                    <div style="width:150px;height:150px;background:#f0f0f0;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;">
                        <i class="bi bi-building" style="font-size:3rem;color:#ccc;"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <input type="file" id="logoFile" accept="image/*" class="form-control form-control-sm mb-2" style="max-width:250px;margin:0 auto;">
                <div class="d-flex gap-1 justify-content-center">
                    <button type="button" id="uploadLogoBtn" class="btn btn-sm btn-primary" onclick="uploadLogo()">
                        <i class="bi bi-upload me-1"></i> <?php _e('upload'); ?>
                    </button>
                    <?php if ($s('company_logo')): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteLogo()">
                        <i class="bi bi-trash me-1"></i> <?php _e('delete'); ?>
                    </button>
                    <?php endif; ?>
                </div>
                <small class="text-muted d-block mt-2"><?php _e('set_logo_hint', 'JPG, PNG, or GIF. Max 10MB.'); ?></small>
            </div>
        </div>
    </div>

    <!-- Company Details -->
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-building me-1"></i> <?php _e('set_company_info', 'Company Information'); ?></div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="form_action" value="save_company">

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('set_company_name', 'Business Name'); ?></label>
                            <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($s('company_name')); ?>" placeholder="<?php _e('set_company_name_ph', 'e.g. ABC Properties Ltd'); ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('set_tax_id', 'Tax ID / TIN'); ?></label>
                            <input type="text" name="company_tax_id" class="form-control" value="<?php echo htmlspecialchars($s('company_tax_id')); ?>" placeholder="<?php _e('set_tax_id_ph', 'e.g. 123-456-789'); ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('phone'); ?></label>
                            <input type="tel" name="company_phone" class="form-control" value="<?php echo htmlspecialchars($s('company_phone')); ?>" placeholder="+255 7XX XXX XXX">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('email'); ?></label>
                            <input type="email" name="company_email" class="form-control" value="<?php echo htmlspecialchars($s('company_email')); ?>" placeholder="info@company.com">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('set_website', 'Website'); ?></label>
                            <input type="url" name="company_website" class="form-control" value="<?php echo htmlspecialchars($s('company_website')); ?>" placeholder="https://www.company.com">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?php _e('address'); ?></label>
                            <textarea name="company_address" class="form-control" rows="3" placeholder="<?php _e('set_address_ph', 'Full business address'); ?>"><?php echo htmlspecialchars($s('company_address')); ?></textarea>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i> <?php _e('save_changes'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function uploadLogo() {
    const input = document.getElementById('logoFile');
    if (!input.files.length) { alert('<?php echo __("set_select_file", "Please select a file"); ?>'); return; }

    const fd = new FormData();
    fd.append('logo', input.files[0]);
    fd.append('action', 'upload_logo');

    fetch(BASE_URL + 'api/settings.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) { location.reload(); }
            else { alert(res.message || 'Upload failed'); }
        })
        .catch(() => alert('Upload failed'));
}

function deleteLogo() {
    if (!confirm('<?php echo __("set_delete_logo_confirm", "Delete company logo?"); ?>')) return;

    const fd = new FormData();
    fd.append('action', 'delete_logo');

    fetch(BASE_URL + 'api/settings.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { if (res.success) location.reload(); else alert(res.message); })
        .catch(() => alert('Failed'));
}
</script>

<?php elseif ($tab === 'preferences'): ?>
<!-- ============================================================
     SYSTEM PREFERENCES TAB
     ============================================================ -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-sliders me-1"></i> <?php _e('set_system_prefs', 'System Preferences'); ?></div>
    <div class="card-body">
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="form_action" value="save_preferences">

            <div class="row g-3">
                <!-- Default Currency -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('set_default_currency', 'Default Currency'); ?></label>
                    <select name="default_currency" class="form-select">
                        <?php foreach (['TZS', 'USD', 'KES', 'UGX'] as $cur): ?>
                        <option value="<?php echo $cur; ?>" <?php echo $s('default_currency', 'TZS') === $cur ? 'selected' : ''; ?>><?php echo $cur; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Supported Currencies -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('set_supported_currencies', 'Supported Currencies'); ?></label>
                    <div>
                        <?php foreach (['TZS', 'USD', 'KES', 'UGX'] as $cur): ?>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" name="supported_currencies[]" value="<?php echo $cur; ?>" class="form-check-input"
                                <?php echo in_array($cur, $supportedCurrencies) ? 'checked' : ''; ?>>
                            <label class="form-check-label"><?php echo $cur; ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Language -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('set_language', 'Default Language'); ?></label>
                    <select name="language" class="form-select">
                        <option value="en" <?php echo $s('language', 'en') === 'en' ? 'selected' : ''; ?>>English</option>
                        <option value="sw" <?php echo $s('language') === 'sw' ? 'selected' : ''; ?>>Kiswahili</option>
                        <option value="ar" <?php echo $s('language') === 'ar' ? 'selected' : ''; ?>>العربية (Arabic)</option>
                    </select>
                </div>

                <!-- Timezone -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('set_timezone', 'Timezone'); ?></label>
                    <select name="timezone" class="form-select">
                        <?php foreach ($timezones as $tz => $label): ?>
                        <option value="<?php echo $tz; ?>" <?php echo $s('timezone', 'Africa/Dar_es_Salaam') === $tz ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date Format -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('set_date_format', 'Date Format'); ?></label>
                    <select name="date_format_pref" class="form-select">
                        <?php
                        $formats = ['d-m-Y' => 'DD-MM-YYYY (27-02-2026)', 'Y-m-d' => 'YYYY-MM-DD (2026-02-27)', 'm/d/Y' => 'MM/DD/YYYY (02/27/2026)', 'd/m/Y' => 'DD/MM/YYYY (27/02/2026)'];
                        foreach ($formats as $fmt => $label): ?>
                        <option value="<?php echo $fmt; ?>" <?php echo $s('date_format_pref', 'd-m-Y') === $fmt ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Fiscal Year Start -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('set_fiscal_year', 'Fiscal Year Start'); ?></label>
                    <select name="fiscal_year_start" class="form-select">
                        <?php
                        $months = ['01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April', '05' => 'May', '06' => 'June',
                                   '07' => 'July', '08' => 'August', '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'];
                        foreach ($months as $num => $name): ?>
                        <option value="<?php echo $num; ?>" <?php echo $s('fiscal_year_start', '01') === $num ? 'selected' : ''; ?>><?php echo $name; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Session Timeout -->
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('set_session_timeout', 'Session Timeout (minutes)'); ?></label>
                    <input type="number" name="session_timeout_minutes" class="form-control"
                           min="0" max="480" step="1"
                           value="<?php echo htmlspecialchars($s('session_timeout_minutes', '30')); ?>"
                           placeholder="30">
                    <small class="text-muted"><?php _e('set_session_timeout_hint', 'Auto-logout after inactivity. 0 = no timeout. Max 480 min (8 hrs).'); ?></small>
                </div>
            </div>

            <!-- Exchange Rates -->
            <h6 class="mt-4 mb-3"><i class="bi bi-currency-exchange me-1"></i> <?php _e('set_exchange_rates', 'Exchange Rates (per 1 unit = TZS)'); ?></h6>
            <div class="row g-3">
                <div class="col-6 col-md-4">
                    <label class="form-label">1 USD =</label>
                    <div class="input-group">
                        <input type="number" name="exchange_rate_USD" class="form-control" step="0.01" value="<?php echo htmlspecialchars($exchangeRates['USD'] ?? 2500); ?>">
                        <span class="input-group-text">TZS</span>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label">1 KES =</label>
                    <div class="input-group">
                        <input type="number" name="exchange_rate_KES" class="form-control" step="0.01" value="<?php echo htmlspecialchars($exchangeRates['KES'] ?? 18); ?>">
                        <span class="input-group-text">TZS</span>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label">1 UGX =</label>
                    <div class="input-group">
                        <input type="number" name="exchange_rate_UGX" class="form-control" step="0.01" value="<?php echo htmlspecialchars($exchangeRates['UGX'] ?? 0.68); ?>">
                        <span class="input-group-text">TZS</span>
                    </div>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1"></i> <?php _e('save_changes'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php elseif ($tab === 'payments'): ?>
<!-- ============================================================
     PAYMENT SETTINGS TAB
     ============================================================ -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-credit-card me-1"></i> <?php _e('set_payment_config', 'Payment Configuration'); ?></div>
    <div class="card-body">
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="form_action" value="save_payments">

            <div class="row g-3">
                <!-- Payment Methods -->
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('set_payment_methods', 'Accepted Payment Methods'); ?></label>
                    <?php
                    $allMethods = ['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'mobile_money' => 'Mobile Money', 'cheque' => 'Cheque', 'gateway' => 'Payment Gateway'];
                    foreach ($allMethods as $val => $label): ?>
                    <div class="form-check">
                        <input type="checkbox" name="payment_methods[]" value="<?php echo $val; ?>" class="form-check-input"
                            <?php echo in_array($val, $paymentMethods) ? 'checked' : ''; ?>>
                        <label class="form-check-label"><?php echo __('pm_' . $val, $label); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Receipt Settings -->
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('set_receipt_prefix', 'Receipt Number Prefix'); ?></label>
                    <input type="text" name="receipt_prefix" class="form-control mb-3" value="<?php echo htmlspecialchars($s('receipt_prefix', 'RCT-')); ?>" placeholder="RCT-">
                    <small class="text-muted"><?php _e('set_receipt_hint', 'Receipts will be numbered: PREFIX000001, PREFIX000002, etc.'); ?></small>
                </div>

                <!-- Payment Instructions -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('set_payment_instructions', 'Payment Instructions'); ?></label>
                    <textarea name="payment_instructions" class="form-control" rows="4" placeholder="<?php _e('set_payment_inst_ph', 'Instructions shown to tenants on how to pay rent...'); ?>"><?php echo htmlspecialchars($s('payment_instructions')); ?></textarea>
                    <small class="text-muted"><?php _e('set_payment_inst_hint', 'Displayed on receipts and tenant-facing pages.'); ?></small>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1"></i> <?php _e('save_changes'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php elseif ($tab === 'notifications'): ?>
<!-- ============================================================
     NOTIFICATION SETTINGS TAB
     ============================================================ -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-bell me-1"></i> <?php _e('set_notification_config', 'Notification Settings'); ?></div>
    <div class="card-body">
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="form_action" value="save_notifications">

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('set_notif_channels', 'Notification Channels'); ?></label>
                    <?php
                    $allChannels = ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS'];
                    foreach ($allChannels as $val => $label): ?>
                    <div class="form-check">
                        <input type="checkbox" name="notification_channels[]" value="<?php echo $val; ?>" class="form-check-input"
                            <?php echo in_array($val, $notifChannels) ? 'checked' : ''; ?>>
                        <label class="form-check-label"><?php echo $label; ?></label>
                    </div>
                    <?php endforeach; ?>
                    <small class="text-muted mt-2 d-block"><?php _e('set_notif_hint', 'WhatsApp/SMS require integration setup.'); ?></small>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1"></i> <?php _e('save_changes'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php elseif ($tab === 'modules'): ?>
<!-- ============================================================
     MODULE TOGGLES TAB
     ============================================================ -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-puzzle me-1"></i> <?php _e('set_module_toggles', 'Module Configuration'); ?></div>
    <div class="card-body">
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="form_action" value="save_modules">

            <p class="text-muted mb-3"><?php _e('set_module_desc', 'Enable or disable optional modules. Some modules require a Professional or Enterprise subscription tier.'); ?></p>

            <div class="row g-3">
                <?php
                $upgradeUrl = BASE_URL . 'modules/subscription/subscribe.php';
                foreach ($MODULE_DEFS as $mod):
                    $isOn    = $s($mod['key'], '0') === '1';
                    $allowed = hasTier($mod['req']);
                    $tierTxt = moduleTierLabel($mod['req']);
                    // A downgraded tenant can still have the flag set to 1 while no
                    // longer being entitled. The module is inert in that state (the
                    // sidebar checks hasTier independently), so show it as OFF rather
                    // than as an active module. Saving normalises the stored flag.
                    $effectiveOn = $isOn && $allowed;
                    $staleOn     = $isOn && !$allowed;
                ?>
                <div class="col-12 col-md-6">
                    <div class="card border <?php echo $effectiveOn ? 'border-success' : ''; ?><?php echo $allowed ? '' : ' bg-light'; ?>">
                        <div class="card-body d-flex align-items-center gap-3 py-3">
                            <div style="width:40px;height:40px;background:<?php echo $effectiveOn ? '#e8f5ee' : '#f8f9fa'; ?>;border-radius:10px;display:flex;align-items:center;justify-content:center;">
                                <i class="bi <?php echo $allowed ? $mod['icon'] : 'bi-lock-fill'; ?>" style="font-size:1.2rem;color:<?php echo $effectiveOn ? '#1a6b4a' : '#999'; ?>;"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold <?php echo $allowed ? '' : 'text-muted'; ?>">
                                    <?php _e($mod['label'], $mod['default']); ?>
                                </div>
                                <?php if ($allowed): ?>
                                <small class="text-muted"><?php echo $tierTxt; ?> <?php _e('set_tier_required', 'tier required'); ?></small>
                                <?php else: ?>
                                <small>
                                    <span class="badge bg-secondary"><i class="bi bi-lock me-1"></i><?php echo $tierTxt; ?></span>
                                    <span class="text-muted ms-1">
                                        <?php if ($staleOn): ?>
                                            <?php _e('set_mod_inactive_downgrade', 'Inactive — no longer in your package'); ?>
                                        <?php else: ?>
                                            <?php _e('set_mod_not_in_plan', 'Not included in your package'); ?>
                                        <?php endif; ?>
                                    </span>
                                </small>
                                <?php endif; ?>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       name="<?php echo $mod['key']; ?>" value="1"
                                       <?php echo $effectiveOn ? 'checked' : ''; ?>
                                       <?php echo $allowed ? '' : 'data-locked="1" data-tier="' . htmlspecialchars($tierTxt) . '"'; ?>
                                       style="width:3em;height:1.5em;">
                            </div>
                        </div>
                        <?php if (!$allowed): ?>
                        <!-- Revealed when the user tries to switch a locked module on -->
                        <div class="alert alert-warning border-0 rounded-0 mb-0 py-2 px-3 d-none module-lock-msg" style="font-size:.85rem;">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <?php echo sprintf(
                                __('set_mod_locked_msg', 'This module is not available on your current package. It requires the %s plan.'),
                                '<strong>' . htmlspecialchars($tierTxt) . '</strong>'
                            ); ?>
                            <a href="<?php echo htmlspecialchars($upgradeUrl); ?>" class="alert-link fw-semibold">
                                <?php _e('set_mod_locked_link', 'Click here to upgrade'); ?>
                            </a>.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1"></i> <?php _e('save_changes'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Locked modules: switching one on immediately reverts and reveals the upgrade
// message on that card. The server enforces this too (see save_modules), so a
// user with JS disabled still cannot turn on a module above their tier.
document.querySelectorAll('.form-check-input[data-locked="1"]').forEach(function (box) {
    box.addEventListener('change', function () {
        if (!box.checked) return;              // turning OFF is always allowed
        box.checked = false;
        var card = box.closest('.card');
        var msg  = card ? card.querySelector('.module-lock-msg') : null;
        if (msg) {
            msg.classList.remove('d-none');
            msg.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
