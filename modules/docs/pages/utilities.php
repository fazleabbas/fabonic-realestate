<h1><i class="bi bi-lightning me-2"></i><?php echo __('docs_utilities_guide', 'Utility Billing'); ?></h1>
<p><span class="tier-badge tier-enterprise">Enterprise</span></p>
<p><?php echo __('docs_util_intro', 'Manage utility billing for water, electricity, garbage, and custom utility types. Record meter readings and auto-calculate charges for tenants.'); ?></p>

<h2><?php echo __('docs_util_types', 'Utility Types'); ?></h2>
<p><?php echo __('docs_util_types_desc', 'Configure utility types with their billing method:'); ?></p>
<ul>
    <li><strong><?php echo __('docs_util_per_unit', 'Per Unit'); ?></strong> — <?php echo __('docs_util_per_unit_desc', 'Charged based on consumption (e.g., per kWh, per liter)'); ?></li>
    <li><strong><?php echo __('docs_util_flat', 'Flat Rate'); ?></strong> — <?php echo __('docs_util_flat_desc', 'Fixed monthly charge regardless of usage'); ?></li>
    <li><strong><?php echo __('docs_util_tiered', 'Tiered'); ?></strong> — <?php echo __('docs_util_tiered_desc', 'Different rates for different consumption levels'); ?></li>
</ul>

<h2><?php echo __('docs_util_readings', 'Recording Meter Readings'); ?></h2>
<ol>
    <li><?php echo __('docs_util_read1', 'Go to the utility billing section and select the unit'); ?></li>
    <li><?php echo __('docs_util_read2', 'Enter the current meter reading — the system shows the previous reading'); ?></li>
    <li><?php echo __('docs_util_read3', 'Consumption is auto-calculated (current − previous)'); ?></li>
    <li><?php echo __('docs_util_read4', 'Charges are calculated based on the utility type rate'); ?></li>
</ol>

<h2><?php echo __('docs_util_charges', 'Charge Posting'); ?></h2>
<p><?php echo __('docs_util_charges_desc', 'Calculated charges are automatically posted to the tenant\'s account with a due date. Tenants can view utility charges in their payment history.'); ?></p>

<h2><?php echo __('docs_util_reports', 'Utility Reports'); ?></h2>
<p><?php echo __('docs_util_reports_desc', 'View utility billing history per tenant, per property, or per utility type. Export data as CSV for analysis.'); ?></p>
