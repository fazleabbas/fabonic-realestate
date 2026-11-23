<h1><i class="bi bi-graph-up-arrow me-2"></i><?php echo __('docs_expenses_guide', 'Expenses & Financial'); ?></h1>
<p><span class="tier-badge tier-enterprise">Enterprise</span></p>
<p><?php echo __('docs_exp_intro', 'Track property expenses, generate profit/loss statements, and get a complete financial picture of your portfolio.'); ?></p>

<h2><?php echo __('docs_exp_categories', 'Expense Categories'); ?></h2>
<p><?php echo __('docs_exp_categories_desc', 'Organize expenses by category:'); ?></p>
<ul>
    <li><?php echo __('docs_exp_cat1', 'Repairs & Maintenance'); ?></li>
    <li><?php echo __('docs_exp_cat2', 'Taxes & Insurance'); ?></li>
    <li><?php echo __('docs_exp_cat3', 'Staff & Management Fees'); ?></li>
    <li><?php echo __('docs_exp_cat4', 'Utilities (landlord-paid)'); ?></li>
    <li><?php echo __('docs_exp_cat5', 'Other / Custom categories'); ?></li>
</ul>

<h2><?php echo __('docs_exp_recording', 'Recording Expenses'); ?></h2>
<ol>
    <li><?php echo __('docs_exp_rec1', 'Go to <strong>Expenses</strong> and click <strong>Add Expense</strong>'); ?></li>
    <li><?php echo __('docs_exp_rec2', 'Select the property (and optionally a specific unit)'); ?></li>
    <li><?php echo __('docs_exp_rec3', 'Choose the category, enter the amount, date, and vendor'); ?></li>
    <li><?php echo __('docs_exp_rec4', 'Upload a receipt image for record-keeping'); ?></li>
</ol>

<h2><?php echo __('docs_exp_reports', 'Financial Reports'); ?></h2>
<ul>
    <li><strong><?php echo __('docs_exp_income_expense', 'Income vs Expense'); ?></strong> — <?php echo __('docs_exp_income_expense_desc', 'Compare rental income against expenses for any period'); ?></li>
    <li><strong><?php echo __('docs_exp_pnl', 'Profit/Loss per Property'); ?></strong> — <?php echo __('docs_exp_pnl_desc', 'See net income for each property (monthly, quarterly, or yearly)'); ?></li>
    <li><strong><?php echo __('docs_exp_void', 'Expense Voiding'); ?></strong> — <?php echo __('docs_exp_void_desc', 'Void incorrect expenses while keeping them in the audit trail'); ?></li>
</ul>

<h2><?php echo __('docs_exp_payables', 'Unpaid expenses and approval'); ?></h2>
<p><?php echo __('docs_exp_payables_d', 'Recording an expense is never blocked — log the bill the moment it arrives. The cost is counted against the month it was incurred, which is what keeps a monthly profit figure honest, and the amount sits as owed to the supplier until it is settled.'); ?></p>
<p><?php echo __('docs_exp_payables_d2', 'Paying it is a separate step and needs approval: Ledger &rsaquo; Payables lists everything outstanding, and a person with approval rights chooses which bank, cash box or float the money came from. You cannot approve payment of an expense you recorded yourself.'); ?></p>
<p><?php echo __('docs_exp_cat_account', 'Each expense category can post to its own account, so the Profit &amp; Loss breaks down the way you want to read it. Categories without one fall back to General Expenses.'); ?></p>
