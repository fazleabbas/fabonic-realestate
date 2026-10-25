<h1><i class="bi bi-journal-bookmark me-2"></i><?php echo __('docs_ledger_guide', 'Accounting Ledger'); ?></h1>
<p><?php echo __('docs_led_intro', 'A full double-entry ledger sits behind the rest of the system. Rent, payments, deposits and expenses post to it automatically, so your books stay correct without anyone typing a journal entry.'); ?></p>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>
    <?php echo __('docs_led_intro_note', 'You do not have to understand double-entry bookkeeping to use the system. Record payments and expenses as you always have; the ledger keeps itself. This page explains what it is doing, and the few screens where it asks something of you.'); ?>
</div>

<h2><?php echo __('docs_led_ideas', 'Three ideas worth knowing'); ?></h2>

<h3><?php echo __('docs_led_accrual', 'Rent is charged, then paid'); ?></h3>
<p><?php echo __('docs_led_accrual_desc', 'Each period, a charge is raised for every active lease. That is the moment the rent becomes income and the moment the tenant starts owing it — not when they hand over the money. A payment then settles one or more charges, oldest first. This is why arrears are exact: a part payment reduces what is owed by exactly what was paid, and an overpayment in one month can no longer hide a shortfall in the next.'); ?></p>

<h3><?php echo __('docs_led_float', 'Cash in a person&rsquo;s hands is tracked'); ?></h3>
<p><?php echo __('docs_led_float_desc', 'When someone collects cash or a cheque, it does not go straight to the bank — it goes into their float, which is the money they are holding on the company&rsquo;s behalf. When they bank it, they file a remittance. The float only falls once an approver confirms it. At any moment you can see exactly who is holding how much.'); ?></p>

<h3><?php echo __('docs_led_immutable', 'Posted entries never change'); ?></h3>
<p><?php echo __('docs_led_immutable_desc', 'Once an entry is posted it cannot be edited or deleted. A mistake is corrected by a reversing entry, which cancels the original and leaves both on the record. This is what makes a statement you printed last month still true today.'); ?></p>

<h2><?php echo __('docs_led_setup', 'Setting it up'); ?></h2>
<p><?php echo __('docs_led_setup_desc', 'Four things, once, before the ledger can post anything:'); ?></p>
<table class="table">
    <thead><tr><th style="width:34%;"><?php echo __('docs_gs_step', 'Step'); ?></th><th><?php echo __('docs_gs_description', 'Description'); ?></th></tr></thead>
    <tbody>
        <tr>
            <td><?php echo __('docs_led_s1', '1. Give two people approval rights'); ?></td>
            <td><?php echo __('docs_led_s1_desc', 'Settings &rsaquo; Users, tick "Can approve payments and deposits". Two people if you want money leaving to need a second pair of eyes; one is enough if you run the system alone and leave self-approval on.'); ?></td>
        </tr>
        <tr>
            <td><?php echo __('docs_led_s2', '2. Create your accounts'); ?></td>
            <td><?php echo __('docs_led_s2_desc', 'Ledger &rsaquo; Chart of Accounts &rsaquo; New Account. Add each bank account (Type: Assets, Kind: Bank account), any petty cash box, and the expense accounts you want to report on — electricity, salaries, repairs.'); ?></td>
        </tr>
        <tr>
            <td><?php echo __('docs_led_s3', '3. Map the default bank'); ?></td>
            <td><?php echo __('docs_led_s3_desc', 'Ledger &rsaquo; Account Mapping. The "Default bank account" row is highlighted until you set it. Bank transfers and gateway payments cannot post until it points at a real account.'); ?></td>
        </tr>
        <tr>
            <td><?php echo __('docs_led_s4', '4. Schedule the charge generator'); ?></td>
            <td><?php echo __('docs_led_s4_desc', 'A daily cron raises each lease&rsquo;s rent charge as it falls due. It must run before the late-fee and reminder jobs, which read those charges. Without it, arrears freeze and nobody is chased.'); ?></td>
        </tr>
    </tbody>
</table>

<h2><?php echo __('docs_led_accounts', 'The chart of accounts'); ?></h2>
<p><?php echo __('docs_led_accounts_desc', 'You can create as many accounts as you like. Some are created for you and marked System — automatic postings depend on them, so they cannot be deleted, though you can rename them.'); ?></p>
<ul>
    <li><strong><?php echo __('docs_led_a_asset', 'Assets'); ?></strong> — <?php echo __('docs_led_a_asset_d', 'bank accounts, cash boxes, floats, and what tenants owe you'); ?></li>
    <li><strong><?php echo __('docs_led_a_liab', 'Liabilities'); ?></strong> — <?php echo __('docs_led_a_liab_d', 'deposits you hold on behalf of tenants, and what you owe suppliers'); ?></li>
    <li><strong><?php echo __('docs_led_a_eq', 'Equity'); ?></strong> — <?php echo __('docs_led_a_eq_d', 'opening balances and accumulated profit'); ?></li>
    <li><strong><?php echo __('docs_led_a_inc', 'Income'); ?></strong> — <?php echo __('docs_led_a_inc_d', 'rent, late fees, recharged utilities, forfeited deposits'); ?></li>
    <li><strong><?php echo __('docs_led_a_exp', 'Expenses'); ?></strong> — <?php echo __('docs_led_a_exp_d', 'whatever you spend money on; add as many as you want to report on'); ?></li>
</ul>
<p><?php echo __('docs_led_kind_note', 'The "Kind" field is not decorative — it decides where an account is offered. Only accounts marked Bank account appear as a destination when banking cash, and only Bank or Cash accounts can be a source when paying an expense. An account left as General is available for manual journal entries only.'); ?></p>
<p><?php echo __('docs_led_currency_note', 'Each account holds one currency. A USD bank account is a separate account from a TZS one, and no entry can mix currencies. Statements are produced per currency for the same reason.'); ?></p>

<h2><?php echo __('docs_led_float_h', 'Floats and remittance'); ?></h2>
<p><?php echo __('docs_led_float_h_desc', 'Cash sits in one of two places. A personal float is created automatically the first time somebody records a cash payment and is named for that person. A shared cash box is one you create yourself in the chart of accounts — set Kind to Cash — and belongs to nobody in particular. Both are banked the same way; the difference is that a float can tell you who was holding the money and a shared box cannot.'); ?></p>
<ol>
    <li><?php echo __('docs_led_f1', 'A collector records a cash payment. Their float rises.'); ?></li>
    <li><?php echo __('docs_led_f2', 'They pay the money into the bank, then open Ledger &rsaquo; Floats and click "Bank cash", entering the amount, the destination account and the deposit slip number.'); ?></li>
    <li><?php echo __('docs_led_f3', 'Nothing moves yet. The float still shows the cash.'); ?></li>
    <li><?php echo __('docs_led_f4', 'A different person approves it. Only now does the float fall and the bank rise.'); ?></li>
</ol>
<p><?php echo __('docs_led_f_note', 'Which methods put cash in someone&rsquo;s hands is set in Ledger &rsaquo; Account Mapping. Cash only, by default: a cheque is paid into a bank rather than held, and transfers and gateway payments never pass through anyone&rsquo;s hands at all.'); ?></p>

<h2><?php echo __('docs_led_receipt', 'Choosing where a receipt lands'); ?></h2>
<p><?php echo __('docs_led_receipt_desc', 'Recording a payment or a deposit offers a "Received into" box. What it lists follows the method: a cash receipt can only go into a cash box or a float, and a transfer or cheque can only go into a bank account. That is not fussiness — putting a cash receipt straight into a bank account would claim the money reached the bank when nobody has taken it there.'); ?></p>
<p><?php echo __('docs_led_receipt_auto', 'Left on "Decide automatically" it behaves as it always did: cash goes to the float of whoever recorded it, and everything else to the account mapped as the default bank. Set it up with one cash box per place money is actually kept — a front desk, a site office — and the balances will match what is in each tin.'); ?></p>

<h2><?php echo __('docs_led_approval', 'What needs approval'); ?></h2>
<p><?php echo __('docs_led_approval_desc', 'The rule is simple: recording what happened is never blocked, but money leaving always needs a second person.'); ?></p>
<table class="table">
    <thead><tr><th><?php echo __('docs_led_action', 'Action'); ?></th><th style="width:30%;"><?php echo __('docs_led_needs', 'Approval'); ?></th></tr></thead>
    <tbody>
        <tr><td><?php echo __('docs_led_ap1', 'Recording a payment or deposit'); ?></td><td><span class="badge bg-success"><?php echo __('docs_led_no', 'No'); ?></span></td></tr>
        <tr><td><?php echo __('docs_led_ap2', 'Recording an expense that is not yet paid'); ?></td><td><span class="badge bg-success"><?php echo __('docs_led_no', 'No'); ?></span></td></tr>
        <tr><td><?php echo __('docs_led_ap3', 'Banking collected cash'); ?></td><td><span class="badge bg-warning text-dark"><?php echo __('docs_led_yes', 'Yes'); ?></span></td></tr>
        <tr><td><?php echo __('docs_led_ap4', 'Paying a supplier'); ?></td><td><span class="badge bg-warning text-dark"><?php echo __('docs_led_yes', 'Yes'); ?></span></td></tr>
        <tr><td><?php echo __('docs_led_ap5', 'Refunding or forfeiting a deposit'); ?></td><td><span class="badge bg-warning text-dark"><?php echo __('docs_led_yes', 'Yes'); ?></span></td></tr>
        <tr><td><?php echo __('docs_led_ap6', 'A manual journal entry or opening balance'); ?></td><td><span class="badge bg-warning text-dark"><?php echo __('docs_led_yes', 'Yes'); ?></span></td></tr>
    </tbody>
</table>
<p><?php echo __('docs_led_self_note', 'By default one person may approve their own request, which is what lets a landlord run the system alone. Switch \x27Allow approving your own requests\x27 off in Ledger &rsaquo; Account Mapping and money leaving always needs a second person — the only control here a single user cannot defeat. With it off and only one approver, nothing can ever be signed off.'); ?></p>

<h2><?php echo __('docs_led_statements', 'Statements'); ?></h2>
<p><?php echo __('docs_led_statements_desc', 'Ledger &rsaquo; Statements has three views, all per currency:'); ?></p>
<ul>
    <li><strong><?php echo __('docs_led_st_pl', 'Profit &amp; Loss'); ?></strong> — <?php echo __('docs_led_st_pl_d', 'income and expenses over a date range, filterable to one property. Income counts when it fell due and expenses when they were incurred, not when the cash moved.'); ?></li>
    <li><strong><?php echo __('docs_led_st_bs', 'Balance Sheet'); ?></strong> — <?php echo __('docs_led_st_bs_d', 'what you own, what you owe and what is left, as at any date. Retained earnings are calculated from all income and expenses to that date.'); ?></li>
    <li><strong><?php echo __('docs_led_st_ag', 'Aged Debtors'); ?></strong> — <?php echo __('docs_led_st_ag_d', 'who owes what, split by how overdue it is: current, 31–60, 61–90 and over 90 days.'); ?></li>
</ul>
<p><?php echo __('docs_led_drill', 'Every figure is a link. Click through to that account&rsquo;s ledger to see each movement with a running balance, and from there into the entry behind it. If a number looks wrong, that is the fastest way to find out why.'); ?></p>
<p><?php echo __('docs_led_tb', 'The Trial Balance is the health check: debits must equal credits. Every entry is validated when it is saved, so if it ever disagrees, something changed the database from outside the application.'); ?></p>

<h2><?php echo __('docs_led_closing', 'Closing a period'); ?></h2>
<p><?php echo __('docs_led_closing_desc', 'Once a month has been reported on, close it: Ledger &rsaquo; Account Mapping &rsaquo; Close the books, and set the date. Nothing can then be posted on or before it — not by a person, an API call or a scheduled job. Clear the date to reopen.'); ?></p>

<h2><?php echo __('docs_led_faq', 'Common questions'); ?></h2>
<dl>
    <dt><?php echo __('docs_led_q1', 'Everyone shows zero arrears.'); ?></dt>
    <dd><?php echo __('docs_led_q1_a', 'The charge generator has not run. Arrears are the sum of unsettled charges, so until charges exist there is nothing to owe. Run it once by hand, then schedule it daily.'); ?></dd>

    <dt><?php echo __('docs_led_q2', 'A payment did not reach the ledger.'); ?></dt>
    <dd><?php echo __('docs_led_q2_a', 'Most often the default bank account is not mapped, or there is no account in that payment&rsquo;s currency. The payment itself is still recorded correctly — only the ledger entry is missing, and it can be posted once the account exists.'); ?></dd>

    <dt><?php echo __('docs_led_q3', 'A float still shows cash that was banked days ago.'); ?></dt>
    <dd><?php echo __('docs_led_q3_a', 'The remittance is waiting for approval. Open the float&rsquo;s statement — it shows the amount declared but not yet approved, and what the balance will be once it is.'); ?></dd>

    <dt><?php echo __('docs_led_q4', 'Can I edit an entry I posted by mistake?'); ?></dt>
    <dd><?php echo __('docs_led_q4_a', 'No, and that is deliberate. Open the entry and click Reverse. That creates a mirror entry cancelling it, which itself needs approval. Both remain on the record.'); ?></dd>

    <dt><?php echo __('docs_led_q5', 'Arrears changed when the ledger was switched on.'); ?></dt>
    <dd><?php echo __('docs_led_q5_a', 'Expected. Arrears used to be estimated as months elapsed times rent, minus everything ever paid. They are now the exact sum of unpaid charges, so part payments and mid-term changes are reflected properly.'); ?></dd>
</dl>
