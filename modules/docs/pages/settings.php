<h1><i class="bi bi-gear me-2"></i><?php echo __('docs_settings_guide', 'Settings & Users'); ?></h1>
<p><?php echo __('docs_set_intro', 'Configure your system preferences, manage users and roles, and handle backup/export operations.'); ?></p>

<h2><?php echo __('docs_set_company', 'Company Settings'); ?></h2>
<p><?php echo __('docs_set_company_desc', 'Update your company information displayed on receipts and documents:'); ?></p>
<ul>
    <li><?php echo __('docs_set_c1', 'Company name, phone, email, and address'); ?></li>
    <li><?php echo __('docs_set_c2', 'Company logo (appears on receipts and PDFs)'); ?></li>
    <li><?php echo __('docs_set_c3', 'Default currency and supported currencies'); ?></li>
    <li><?php echo __('docs_set_c4', 'Timezone and date format preferences'); ?></li>
</ul>

<h2><?php echo __('docs_set_payment', 'Payment Settings'); ?></h2>
<p><?php echo __('docs_set_payment_desc', 'Configure which payment methods are available, receipt numbering prefix, and payment instructions shown to tenants.'); ?></p>

<h2><?php echo __('docs_set_late', 'Late Fee Settings'); ?></h2>
<p><?php echo __('docs_set_late_desc', 'Set system-wide late fee defaults: fee type (percentage or fixed), grace period, and maximum cap. These can be overridden per property.'); ?></p>

<h2><?php echo __('docs_set_modules', 'Module Toggles'); ?></h2>
<p><?php echo __('docs_set_modules_desc', 'Enable or disable optional modules based on your subscription tier:'); ?></p>
<ul>
    <li><span class="tier-badge tier-professional">Professional</span> <?php echo __('docs_set_mod_portal', 'Tenant Portal, Automated Reminders, Document Management'); ?></li>
    <li><span class="tier-badge tier-enterprise">Enterprise</span> <?php echo __('docs_set_mod_ent', 'Utility Billing, Maintenance, Financial Reports, Vacancy Advertising, Payment Gateway'); ?></li>
</ul>

<h2><?php echo __('docs_set_users', 'User Management'); ?></h2>
<p><?php echo __('docs_set_users_desc', 'Admins can create and manage user accounts. Each user has a role that determines their permissions. Available fields: username, full name, email, phone, role, and status.'); ?></p>

<h2><?php echo __('docs_set_roles', 'Roles & Permissions'); ?></h2>
<p><?php echo __('docs_set_roles_desc', 'Create custom roles (e.g., Manager, Accountant, Caretaker) with granular permissions per module and action (view, create, edit, delete). The Admin role has full access and cannot be deleted.'); ?></p>

<h2><?php echo __('docs_set_backup', 'Backup & Export'); ?></h2>
<p><?php echo __('docs_set_backup_desc', 'Export your data as CSV or create database backups. Admins can download backups for offline storage. Regular backups are recommended.'); ?></p>

<h2><?php echo __('docs_set_session', 'Session & Security'); ?></h2>
<p><?php echo __('docs_set_session_desc', 'Configure session timeout (auto-logout after inactivity), password policies, and view the activity log for audit purposes.'); ?></p>
