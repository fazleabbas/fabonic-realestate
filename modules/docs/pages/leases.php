<h1><i class="bi bi-file-earmark-text me-2"></i><?php echo __('docs_leases_guide', 'Leases'); ?></h1>
<p><?php echo __('docs_lease_intro', 'Leases link tenants to units with formal rental agreements. They track rent amounts, dates, payment frequency, and terms.'); ?></p>

<h2><?php echo __('docs_lease_create', 'Creating a Lease'); ?></h2>
<ol>
    <li><?php echo __('docs_lease_step1', 'Go to <strong>Leases</strong> and click <strong>Add Lease</strong>'); ?></li>
    <li><?php echo __('docs_lease_step2', 'Select the unit and tenant'); ?></li>
    <li><?php echo __('docs_lease_step3', 'Set the start date, end date, rent amount, and deposit amount'); ?></li>
    <li><?php echo __('docs_lease_step4', 'Choose payment frequency: monthly, quarterly, or yearly'); ?></li>
    <li><?php echo __('docs_lease_step5', 'Set the payment due day (1–28) and renewal alert days'); ?></li>
    <li><?php echo __('docs_lease_step6', 'Add any additional terms or conditions'); ?></li>
    <li><?php echo __('docs_lease_step7', 'Save — the unit status will auto-change to Occupied'); ?></li>
</ol>

<h2><?php echo __('docs_lease_templates', 'Lease Templates'); ?></h2>
<p><?php echo __('docs_lease_templates_desc', 'Create reusable lease templates with placeholders like <code>{{tenant_name}}</code>, <code>{{rent_amount}}</code>, <code>{{start_date}}</code>. When generating a lease PDF, placeholders are automatically filled in.'); ?></p>

<h2><?php echo __('docs_lease_pdf', 'PDF Generation'); ?></h2>
<p><?php echo __('docs_lease_pdf_desc', 'Generate professional lease agreement PDFs from templates. You can download, print, or email the PDF directly to the tenant.'); ?></p>

<h2><?php echo __('docs_lease_renewal', 'Lease Renewal'); ?></h2>
<p><?php echo __('docs_lease_renewal_desc', 'The system alerts you when leases are about to expire (based on the renewal_alert_days setting). You can renew with the same terms or update the rent amount and conditions. Renewed leases maintain a chain linking back to the original.'); ?></p>

<h2><?php echo __('docs_lease_termination', 'Lease Termination'); ?></h2>
<p><?php echo __('docs_lease_termination_desc', 'Terminate a lease early with a reason and date. The system tracks the notice period and links to the deposit settlement workflow.'); ?></p>

<h2><?php echo __('docs_lease_statuses', 'Lease Statuses'); ?></h2>
<ul>
    <li><span class="badge bg-success">Active</span> — <?php echo __('docs_lease_active', 'Currently in effect'); ?></li>
    <li><span class="badge bg-secondary">Expired</span> — <?php echo __('docs_lease_expired', 'End date has passed'); ?></li>
    <li><span class="badge bg-danger">Terminated</span> — <?php echo __('docs_lease_terminated', 'Ended early'); ?></li>
    <li><span class="badge bg-info text-dark">Renewed</span> — <?php echo __('docs_lease_renewed', 'Replaced by a new lease'); ?></li>
</ul>
