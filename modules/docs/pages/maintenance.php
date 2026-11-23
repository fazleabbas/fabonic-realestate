<h1><i class="bi bi-tools me-2"></i><?php echo __('docs_maintenance_guide', 'Maintenance'); ?></h1>
<p><span class="tier-badge tier-enterprise">Enterprise</span></p>
<p><?php echo __('docs_mnt_intro', 'The Maintenance module manages work orders, contractor assignments, and repair cost tracking. Report issues, assign contractors, and track work to completion.'); ?></p>

<h2><?php echo __('docs_mnt_requests', 'Maintenance Requests'); ?></h2>
<ol>
    <li><?php echo __('docs_mnt_r1', 'Go to <strong>Maintenance</strong> and click <strong>New Request</strong>'); ?></li>
    <li><?php echo __('docs_mnt_r2', 'Select the unit and category (Plumbing, Electrical, Structural, etc.)'); ?></li>
    <li><?php echo __('docs_mnt_r3', 'Set priority: Low, Medium, High, or Urgent'); ?></li>
    <li><?php echo __('docs_mnt_r4', 'Add a description and upload photos of the issue'); ?></li>
</ol>

<h2><?php echo __('docs_mnt_workflow', 'Status Workflow'); ?></h2>
<p><?php echo __('docs_mnt_workflow_desc', 'Requests flow through these statuses:'); ?></p>
<p>
    <span class="badge bg-secondary">Reported</span> →
    <span class="badge bg-info text-dark">Assigned</span> →
    <span class="badge bg-primary">In Progress</span> →
    <span class="badge bg-success">Completed</span> →
    <span class="badge bg-dark">Closed</span>
</p>

<h2><?php echo __('docs_mnt_contractors', 'Contractor Management'); ?></h2>
<p><?php echo __('docs_mnt_contractors_desc', 'Maintain a list of contractors with their specialties, hourly rates, and contact information. Assign contractors to specific maintenance requests and track their work.'); ?></p>

<h2><?php echo __('docs_mnt_costs', 'Cost Tracking'); ?></h2>
<p><?php echo __('docs_mnt_costs_desc', 'Record estimated and actual costs for each maintenance job. Track costs per property, per category, and per contractor through the maintenance reports.'); ?></p>

<h2><?php echo __('docs_mnt_reports', 'Maintenance Reports'); ?></h2>
<p><?php echo __('docs_mnt_reports_desc', 'Export maintenance data as CSV. View reports on cost per property, per category, contractor performance, and response times.'); ?></p>
