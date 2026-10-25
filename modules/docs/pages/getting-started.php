<h1><i class="bi bi-rocket-takeoff me-2"></i><?php echo __('docs_getting_started', 'Getting Started'); ?></h1>
<p><?php echo __('docs_gs_intro', 'Welcome to FIS Real Estate — a comprehensive property management system designed for landlords and property managers in Tanzania and beyond.'); ?></p>

<div class="alert alert-success">
    <i class="bi bi-lightbulb me-2"></i>
    <?php echo __('docs_gs_tip', 'This guide walks you through the essential first steps to start managing your properties effectively.'); ?>
</div>

<h2><?php echo __('docs_gs_first_login', 'First Login'); ?></h2>
<p><?php echo __('docs_gs_first_login_desc', 'After your account is provisioned, you will receive login credentials via email or WhatsApp. Use them to sign in at your tenant URL.'); ?></p>
<ol>
    <li><?php echo __('docs_gs_step_open', 'Open your browser and navigate to your tenant URL'); ?></li>
    <li><?php echo __('docs_gs_step_enter', 'Enter your username and password'); ?></li>
    <li><?php echo __('docs_gs_step_change', 'Change your password on first login (recommended)'); ?></li>
    <li><?php echo __('docs_gs_step_lang', 'Select your preferred language (English, Swahili, or Arabic)'); ?></li>
</ol>

<h2><?php echo __('docs_gs_quick_setup', 'Quick Setup Checklist'); ?></h2>
<p><?php echo __('docs_gs_quick_setup_desc', 'Follow these steps in order to set up your property management system:'); ?></p>

<table class="table">
    <thead>
        <tr>
            <th style="width:40px;">#</th>
            <th><?php echo __('docs_gs_step', 'Step'); ?></th>
            <th><?php echo __('docs_gs_description', 'Description'); ?></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><span class="badge bg-success">1</span></td>
            <td><strong><?php echo __('docs_gs_add_property', 'Add a Property'); ?></strong></td>
            <td><?php echo __('docs_gs_add_property_desc', 'Go to Properties and create your first property with address and type.'); ?></td>
        </tr>
        <tr>
            <td><span class="badge bg-success">2</span></td>
            <td><strong><?php echo __('docs_gs_add_units', 'Add Units'); ?></strong></td>
            <td><?php echo __('docs_gs_add_units_desc', 'Create units within the property — apartments, rooms, shops, etc. Set rent amounts.'); ?></td>
        </tr>
        <tr>
            <td><span class="badge bg-success">3</span></td>
            <td><strong><?php echo __('docs_gs_add_tenants', 'Register Tenants'); ?></strong></td>
            <td><?php echo __('docs_gs_add_tenants_desc', 'Add tenant profiles with contact info, ID copies, and screening details.'); ?></td>
        </tr>
        <tr>
            <td><span class="badge bg-success">4</span></td>
            <td><strong><?php echo __('docs_gs_create_leases', 'Create Leases'); ?></strong></td>
            <td><?php echo __('docs_gs_create_leases_desc', 'Link tenants to units with lease agreements. Set rent, dates, and payment frequency.'); ?></td>
        </tr>
        <tr>
            <td><span class="badge bg-success">5</span></td>
            <td><strong><?php echo __('docs_gs_set_ledger', 'Set Up the Ledger'); ?></strong></td>
            <td><?php echo __('docs_gs_set_ledger_desc', 'Add your bank account under Ledger &rsaquo; Chart of Accounts, map it as the default bank, and give two people approval rights. Payments post to the accounts from the moment you record them, so this comes first.'); ?></td>
        </tr>
        <tr>
            <td><span class="badge bg-success">6</span></td>
            <td><strong><?php echo __('docs_gs_record_payments', 'Record Payments'); ?></strong></td>
            <td><?php echo __('docs_gs_record_payments_desc', 'Start recording rent payments and generating receipts for your tenants.'); ?></td>
        </tr>
    </tbody>
</table>

<h2><?php echo __('docs_gs_system_overview', 'System Overview'); ?></h2>
<p><?php echo __('docs_gs_system_overview_desc', 'FIS Real Estate is organized into modules accessible via the sidebar navigation:'); ?></p>
<ul>
    <li><strong><?php echo __('docs_gs_core_modules', 'Core Modules'); ?></strong> — <?php echo __('docs_gs_core_desc', 'Properties, Units, Tenants, Leases, Payments, Deposits, Inspections'); ?></li>
    <li><strong><?php echo __('docs_gs_accounting', 'Accounting'); ?></strong> — <?php echo __('docs_gs_accounting_desc', 'Double-entry ledger, collector floats, payables, profit &amp; loss and balance sheet'); ?></li>
    <li><strong><?php echo __('docs_gs_reports', 'Reports & Dashboard'); ?></strong> — <?php echo __('docs_gs_reports_desc', 'Visual analytics, collection reports, occupancy tracking'); ?></li>
    <li><strong><?php echo __('docs_gs_admin', 'Administration'); ?></strong> — <?php echo __('docs_gs_admin_desc', 'Settings, Users, Roles, Backup/Export'); ?></li>
</ul>

<h3><?php echo __('docs_gs_tiers', 'Subscription Tiers'); ?></h3>
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card h-100 border-secondary">
            <div class="card-body text-center">
                <span class="tier-badge tier-basic mb-2">BASIC</span>
                <h6 class="mt-2"><?php echo number_format(TIER_BASIC_RATE); ?> TZS/<?php echo __('per_unit', 'unit'); ?>/<?php echo __('month', 'month'); ?></h6>
                <small class="text-muted"><?php echo __('docs_gs_basic_desc', 'Core property management features'); ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 border-primary">
            <div class="card-body text-center">
                <span class="tier-badge tier-professional mb-2">PROFESSIONAL</span>
                <h6 class="mt-2"><?php echo number_format(TIER_PROFESSIONAL_RATE); ?> TZS/<?php echo __('per_unit', 'unit'); ?>/<?php echo __('month', 'month'); ?></h6>
                <small class="text-muted"><?php echo __('docs_gs_pro_desc', 'Tenant portal, reminders, document management'); ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100" style="border-color:var(--re-primary);">
            <div class="card-body text-center">
                <span class="tier-badge tier-enterprise mb-2">ENTERPRISE</span>
                <h6 class="mt-2"><?php echo number_format(TIER_ENTERPRISE_RATE); ?> TZS/<?php echo __('per_unit', 'unit'); ?>/<?php echo __('month', 'month'); ?></h6>
                <small class="text-muted"><?php echo __('docs_gs_ent_desc', 'Full suite: utilities, maintenance, expenses, gateway'); ?></small>
            </div>
        </div>
    </div>
</div>

<h2><?php echo __('docs_gs_offline', 'Offline & PWA Support'); ?></h2>
<p><?php echo __('docs_gs_offline_desc', 'FIS Real Estate works offline as a Progressive Web App (PWA). You can install it on your device and continue working even without internet. Changes sync automatically when you reconnect.'); ?></p>

<h2><?php echo __('docs_gs_languages', 'Language Support'); ?></h2>
<p><?php echo __('docs_gs_languages_desc', 'The system supports English, Swahili (Kiswahili), and Arabic (with right-to-left layout). Switch languages using the globe icon in the top bar.'); ?></p>

<div class="alert alert-info mt-4">
    <i class="bi bi-info-circle me-2"></i>
    <?php echo __('docs_gs_help', 'Need help? Contact support via WhatsApp or email. Check the FAQ section for common questions.'); ?>
</div>
