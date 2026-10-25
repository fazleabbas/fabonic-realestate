<h1><i class="bi bi-person-badge me-2"></i><?php echo __('docs_tenant_portal', 'Tenant Portal Guide'); ?></h1>
<p><span class="tier-badge tier-professional">Professional</span></p>
<p><?php echo __('docs_tp_intro', 'The Tenant Portal is a self-service interface for tenants to view their account balance, payment history, lease details, and manage their profile. Landlords can enable or disable portal access per tenant.'); ?></p>

<h2><?php echo __('docs_tp_enabling', 'Enabling the Tenant Portal'); ?></h2>
<p><?php echo __('docs_tp_enabling_desc', 'To enable the portal for a tenant:'); ?></p>
<ol>
    <li><?php echo __('docs_tp_en1', 'Make sure your subscription is on the Professional or Enterprise tier'); ?></li>
    <li><?php echo __('docs_tp_en2', 'Go to <strong>Settings</strong> and enable the Tenant Portal module'); ?></li>
    <li><?php echo __('docs_tp_en3', 'Open the tenant\'s profile and toggle <strong>Portal Access</strong> on'); ?></li>
    <li><?php echo __('docs_tp_en4', 'Set a portal password for the tenant (or let them set it on first login)'); ?></li>
    <li><?php echo __('docs_tp_en5', 'Share the portal URL and credentials with the tenant'); ?></li>
</ol>

<h2><?php echo __('docs_tp_features', 'Portal Features'); ?></h2>
<ul>
    <li><i class="bi bi-grid me-2 text-success"></i><strong><?php echo __('docs_tp_dashboard', 'Dashboard'); ?></strong> — <?php echo __('docs_tp_dashboard_desc', 'Current balance, lease summary, next payment due date, and recent payments at a glance'); ?></li>
    <li><i class="bi bi-receipt me-2 text-primary"></i><strong><?php echo __('docs_tp_pay_history', 'Payment History'); ?></strong> — <?php echo __('docs_tp_pay_history_desc', 'View all past payments with dates, amounts, and methods. Download receipts as PDF.'); ?></li>
    <li><i class="bi bi-person-circle me-2 text-warning"></i><strong><?php echo __('docs_tp_profile_mgmt', 'Profile Management'); ?></strong> — <?php echo __('docs_tp_profile_mgmt_desc', 'Update phone number, email, and emergency contact information'); ?></li>
</ul>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    <?php echo __('docs_tp_note', 'The tenant portal is a read-only interface. Tenants can view their information but cannot make changes to lease terms or record payments.'); ?>
</div>
