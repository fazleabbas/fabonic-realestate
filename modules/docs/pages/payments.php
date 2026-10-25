<h1><i class="bi bi-cash-coin me-2"></i><?php echo __('docs_payments_guide', 'Payments & Receipts'); ?></h1>
<p><?php echo __('docs_pay_intro', 'The Payments module handles rent collection, payment recording, receipt generation, and payment history tracking.'); ?></p>

<h2><?php echo __('docs_pay_record', 'Recording a Payment'); ?></h2>
<ol>
    <li><?php echo __('docs_pay_step1', 'Go to <strong>Payments</strong> and click <strong>Record Payment</strong>'); ?></li>
    <li><?php echo __('docs_pay_step2', 'Select the tenant (or unit)'); ?></li>
    <li><?php echo __('docs_pay_step3', 'Enter the payment amount, date, and method (Cash, Bank Transfer, Mobile Money, Cheque)'); ?></li>
    <li><?php echo __('docs_pay_step4', 'Add a reference number (e.g., M-Pesa transaction ID)'); ?></li>
    <li><?php echo __('docs_pay_step5', 'The system auto-calculates which rent period(s) the payment covers'); ?></li>
    <li><?php echo __('docs_pay_step6', 'Save — a receipt is auto-generated'); ?></li>
</ol>

<h2><?php echo __('docs_pay_methods', 'Payment Methods'); ?></h2>
<table class="table">
    <thead><tr><th><?php echo __('method', 'Method'); ?></th><th><?php echo __('docs_gs_description', 'Description'); ?></th></tr></thead>
    <tbody>
        <tr><td>Cash</td><td><?php echo __('docs_pay_cash', 'Physical cash payment'); ?></td></tr>
        <tr><td>Bank Transfer</td><td><?php echo __('docs_pay_bank', 'Direct bank deposit or wire transfer'); ?></td></tr>
        <tr><td>Mobile Money</td><td><?php echo __('docs_pay_mobile', 'M-Pesa, Airtel Money, Tigo Pesa'); ?></td></tr>
        <tr><td>Cheque</td><td><?php echo __('docs_pay_cheque', 'Bank cheque or cashier\'s cheque'); ?></td></tr>
        <tr><td>Gateway</td><td><?php echo __('docs_pay_gateway', 'Online payment via integrated gateway'); ?> <span class="tier-badge tier-enterprise">Enterprise</span></td></tr>
    </tbody>
</table>

<h2><?php echo __('docs_pay_receipts', 'Receipt Generation'); ?></h2>
<p><?php echo __('docs_pay_receipts_desc', 'Receipts are auto-generated with a sequential number. Each receipt includes tenant info, payment amount, period covered, and payment method. You can download as PDF, print, or send via WhatsApp.'); ?></p>

<h2><?php echo __('docs_pay_bulk', 'Bulk Payment Recording'); ?></h2>
<p><?php echo __('docs_pay_bulk_desc', 'Record payments for multiple tenants at once. Ideal for when you receive many payments on the same day. Navigate to the bulk payment form, enter amounts per tenant, and save all at once.'); ?></p>

<h2><?php echo __('docs_pay_reversal', 'Payment Reversal'); ?></h2>
<p><?php echo __('docs_pay_reversal_desc', 'If a payment was recorded incorrectly, you can reverse it. Reversed payments are marked as such and excluded from reports. The original record is preserved for audit purposes.'); ?></p>

<h2><?php echo __('docs_pay_history', 'Payment History'); ?></h2>
<p><?php echo __('docs_pay_history_desc', 'View payment history per tenant, per property, or system-wide. Filter by date range, payment method, or status. Export to CSV for accounting.'); ?></p>

<h2><?php echo __('docs_pay_allocation', 'How a payment is applied'); ?></h2>
<p><?php echo __('docs_pay_allocation_d', 'Rent is charged period by period, and a payment settles those charges oldest first. Pay part of what is owed and the charge is marked part-settled, with the remainder still showing as arrears — a part payment is never treated as none. Pay more than is owed and the surplus sits as a credit that the next charge consumes automatically.'); ?></p>

<h2><?php echo __('docs_pay_float', 'Cash payments and floats'); ?></h2>
<p><?php echo __('docs_pay_float_d', 'When you record a cash or cheque payment, that money is tracked as being in your hands until you bank it. Ledger &rsaquo; Floats shows what you are holding; the "Bank cash" button declares a deposit, and your float falls once an approver confirms it. Bank transfers and gateway payments skip this entirely — they were never in anyone&rsquo;s hands.'); ?></p>

<h2><?php echo __('docs_pay_reversal', 'Reversing a payment'); ?></h2>
<p><?php echo __('docs_pay_reversal_d', 'Reversing a payment also unwinds it in the accounts and reopens the charges it had settled, so the tenant&rsquo;s arrears go back to what they were. The original payment stays on the record, marked reversed.'); ?></p>
