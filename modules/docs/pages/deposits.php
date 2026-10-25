<h1><i class="bi bi-safe me-2"></i><?php echo __('docs_deposits_guide', 'Deposits & Late Fees'); ?></h1>
<p><?php echo __('docs_dep_intro', 'Manage security deposits and late fee rules for your properties. Track deposit collection, deductions, and refunds through the entire tenancy lifecycle.'); ?></p>

<h2><?php echo __('docs_dep_collection', 'Deposit Collection'); ?></h2>
<p><?php echo __('docs_dep_collection_desc', 'When a new lease is created, the system tracks the required security deposit. Record the deposit payment with date, amount, method, and reference number.'); ?></p>

<h2><?php echo __('docs_dep_deductions', 'Deposit Deductions'); ?></h2>
<p><?php echo __('docs_dep_deductions_desc', 'When a tenant moves out, create itemized deductions against their deposit. Each deduction includes a description and amount. Deductions can be linked to move-out inspection findings.'); ?></p>

<div class="alert alert-warning">
    <i class="bi bi-shield-check me-1"></i>
    <strong><?php echo __('docs_dep_approval_h', 'Refunds and forfeitures need approval'); ?></strong><br>
    <?php echo __('docs_dep_approval_d', 'Clicking Refund or Forfeit no longer completes the action — it raises a request. Nothing moves until someone with approval rights signs it off under Ledger &rsaquo; Payables, and nobody can approve their own request. A refund also asks which bank or cash account the money comes from.'); ?>
</div>

<h2><?php echo __('docs_dep_refund', 'Deposit Refund'); ?></h2>
<p><?php echo __('docs_dep_refund_desc', 'After deductions, the remaining deposit is refunded to the tenant. The system calculates: Refund = Deposit Amount − Total Deductions. Record the refund method and reference.'); ?></p>
<ul>
    <li><span class="badge bg-success">Held</span> — <?php echo __('docs_dep_held', 'Deposit is held during active tenancy'); ?></li>
    <li><span class="badge bg-warning text-dark">Partially Refunded</span> — <?php echo __('docs_dep_partial', 'Some amount deducted, rest refunded'); ?></li>
    <li><span class="badge bg-info text-dark">Refunded</span> — <?php echo __('docs_dep_refunded', 'Full deposit returned to tenant'); ?></li>
    <li><span class="badge bg-danger">Forfeited</span> — <?php echo __('docs_dep_forfeited', 'Entire deposit kept due to damages/violations'); ?></li>
</ul>

<h2><?php echo __('docs_dep_late_fees', 'Late Fee Configuration'); ?></h2>
<p><?php echo __('docs_dep_late_fees_desc', 'Configure late fees in Settings or per-property:'); ?></p>
<table class="table">
    <thead><tr><th><?php echo __('setting', 'Setting'); ?></th><th><?php echo __('docs_gs_description', 'Description'); ?></th></tr></thead>
    <tbody>
        <tr><td><?php echo __('docs_dep_fee_type', 'Fee Type'); ?></td><td><?php echo __('docs_dep_fee_type_desc', 'Percentage of rent or fixed amount'); ?></td></tr>
        <tr><td><?php echo __('docs_dep_fee_value', 'Fee Value'); ?></td><td><?php echo __('docs_dep_fee_value_desc', 'The percentage or fixed amount to charge'); ?></td></tr>
        <tr><td><?php echo __('docs_dep_grace', 'Grace Period'); ?></td><td><?php echo __('docs_dep_grace_desc', 'Number of days after due date before fee applies'); ?></td></tr>
        <tr><td><?php echo __('docs_dep_max', 'Maximum Fee'); ?></td><td><?php echo __('docs_dep_max_desc', 'Cap on total late fee (prevents excessive charges)'); ?></td></tr>
    </tbody>
</table>

<h2><?php echo __('docs_dep_auto_calc', 'Automatic Late Fee Calculation'); ?></h2>
<p><?php echo __('docs_dep_auto_calc_desc', 'Late fees are automatically calculated by the system (via cron job or on-access). The system checks each active lease, identifies overdue payments, and applies fees based on your configuration. Late fees can be waived by an admin with a recorded reason.'); ?></p>

<h2><?php echo __('docs_dep_ledger', 'How deposits reach the accounts'); ?></h2>
<p><?php echo __('docs_dep_ledger_d', 'A deposit is the tenant&rsquo;s money, not yours, so it is recorded as a liability rather than income. Collecting one increases whichever account received it and increases Tenant Deposits Held. Refunding reverses that; forfeiting moves the amount from the liability into income, because at that point it has genuinely become yours.'); ?></p>
<p><?php echo __('docs_dep_ledger_link', 'See the Accounting Ledger guide for the full picture.'); ?></p>
