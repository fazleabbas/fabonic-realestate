<h1><i class="bi bi-people me-2"></i><?php echo __('docs_tenants_guide', 'Tenants'); ?></h1>
<p><?php echo __('docs_ten_intro', 'The Tenants module manages all occupant information — personal details, identification, screening, emergency contacts, and rental history.'); ?></p>

<h2><?php echo __('docs_ten_create', 'Adding a Tenant'); ?></h2>
<ol>
    <li><?php echo __('docs_ten_step1', 'Navigate to <strong>Tenants</strong> and click <strong>Add Tenant</strong>'); ?></li>
    <li><?php echo __('docs_ten_step2', 'Enter first name, last name, phone number, and email'); ?></li>
    <li><?php echo __('docs_ten_step3', 'Select ID type (National ID, Passport, Driving License, Voter ID) and enter the ID number'); ?></li>
    <li><?php echo __('docs_ten_step4', 'Upload a copy of the ID document'); ?></li>
    <li><?php echo __('docs_ten_step5', 'Fill in occupation, employer, and employer phone (optional)'); ?></li>
    <li><?php echo __('docs_ten_step6', 'Add emergency contact and references'); ?></li>
</ol>

<h2><?php echo __('docs_ten_screening', 'Tenant Screening'); ?></h2>
<p><?php echo __('docs_ten_screening_desc', 'Use the screening notes field to record background check results, landlord references, and any important notes about the tenant before approving their application.'); ?></p>

<h2><?php echo __('docs_ten_statuses', 'Tenant Statuses'); ?></h2>
<ul>
    <li><span class="badge bg-success">Active</span> — <?php echo __('docs_ten_active', 'Currently renting a unit'); ?></li>
    <li><span class="badge bg-warning text-dark">Notice Given</span> — <?php echo __('docs_ten_notice', 'Tenant has given notice to vacate'); ?></li>
    <li><span class="badge bg-secondary">Moved Out</span> — <?php echo __('docs_ten_moved_out', 'Tenant has left the property'); ?></li>
</ul>

<h2><?php echo __('docs_ten_history', 'Tenant History'); ?></h2>
<p><?php echo __('docs_ten_history_desc', 'View a complete history for each tenant including all leases, payments, inspections, and activity notes. Use the tabs on the tenant detail page to navigate between sections.'); ?></p>

<h2><?php echo __('docs_ten_search', 'Search & Filters'); ?></h2>
<p><?php echo __('docs_ten_search_desc', 'Search tenants by name, phone, or email. Filter by status (active, notice given, moved out) or by property.'); ?></p>
