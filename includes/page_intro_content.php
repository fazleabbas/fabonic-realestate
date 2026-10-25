<?php
/**
 * FIS Real Estate — Page introduction content
 *
 * One entry per page: what it is for, how to use it, and where to go next.
 *
 * The English text here is the fallback. Translations live in the language
 * files under keys derived from the registry key — 'leases.index' becomes
 * intro_leases_index_what and intro_leases_index_how — so a language without a
 * translation falls back to English rather than showing a blank panel.
 *
 * The "next" links are the workflow. They are what let someone follow the
 * system from an empty database to a working one without being told the order.
 * A step with a `permission` is hidden from users who cannot open it.
 */

function pageIntroContent() {
    static $intros = null;
    if ($intros !== null) return $intros;

    $intros = [

    // ============================================================
    // Setting up — the order a new system is built in
    // ============================================================
    'properties.index' => [
        'what' => 'Every building you manage. A property holds units, and everything else — tenants, leases, rent — hangs off those units.',
        'how'  => 'Start here on a new system. Add a property, then add its units. Click any property to see its units, photos and running costs.',
        'next' => [
            ['url' => 'modules/units/', 'label' => 'Add units', 'label_key' => 'intro_go_units', 'permission' => 'units:view'],
        ],
    ],
    'properties.create' => [
        'what' => 'Add a building. Only the name is required — everything else can be filled in later.',
        'how'  => 'Give it a name people will recognise on a report. Once saved, add its units.',
        'next' => [
            ['url' => 'modules/units/create.php', 'label' => 'Add a unit', 'label_key' => 'intro_go_unit_new', 'permission' => 'units:create'],
        ],
    ],
    'properties.view' => [
        'what' => 'Everything about one property: its units, how many are occupied, its photos and what it has cost you.',
        'how'  => 'Use the tabs to move between units, photos and expenses. Occupancy updates itself as leases start and end.',
        'next' => [
            ['url' => 'modules/units/', 'label' => 'Units', 'label_key' => 'intro_go_units', 'permission' => 'units:view'],
        ],
    ],
    'units.index' => [
        'what' => 'Every rentable space across your properties, with its rent and whether anyone is in it.',
        'how'  => 'A unit becomes occupied automatically when you create a lease on it, and vacant again when that lease ends. Set the rent here — new leases pick it up as their default.',
        'next' => [
            ['url' => 'modules/tenants/', 'label' => 'Register tenants', 'label_key' => 'intro_go_tenants', 'permission' => 'tenants:view'],
        ],
    ],
    'units.create' => [
        'what' => 'Add a rentable space to a property — a flat, room, shop or office.',
        'how'  => 'The rent and currency you set here become the default when you create a lease on this unit. Leave the status as vacant; it changes itself once a lease starts.',
        'next' => [
            ['url' => 'modules/tenants/create.php', 'label' => 'Add a tenant', 'label_key' => 'intro_go_tenant_new', 'permission' => 'tenants:create'],
        ],
    ],
    'units.view' => [
        'what' => 'One unit: its current lease, its rent, its photos and its history.',
        'how'  => 'If the unit shows as occupied but you see no lease, an old lease was removed without releasing it — fix that by editing the unit status.',
        'next' => [
            ['url' => 'modules/leases/create.php', 'label' => 'Create a lease', 'label_key' => 'intro_go_lease_new', 'permission' => 'leases:create'],
        ],
    ],

    // ============================================================
    // Tenants and leases
    // ============================================================
    'tenants.index' => [
        'what' => 'Everyone renting from you, past and present.',
        'how'  => 'Register a tenant before you can put them on a lease. Click a name to see their leases, payments, documents and ID copy.',
        'next' => [
            ['url' => 'modules/leases/create.php', 'label' => 'Create a lease', 'label_key' => 'intro_go_lease_new', 'permission' => 'leases:create'],
        ],
    ],
    'tenants.create' => [
        'what' => 'Register someone so they can be put on a lease.',
        'how'  => 'Name and phone are all that is required. TIN, next of kin and employment can be added now or later — the ID copy is uploaded from the tenant page after saving.',
        'next' => [
            ['url' => 'modules/leases/create.php', 'label' => 'Create a lease', 'label_key' => 'intro_go_lease_new', 'permission' => 'leases:create'],
        ],
    ],
    'tenants.view' => [
        'what' => 'One tenant: their leases, what they have paid, what they owe, their documents and ID.',
        'how'  => 'Upload their ID copy in the ID Document card on the right. Use the Documents tab for anything else — contracts, correspondence, references.',
        'next' => [
            ['url' => 'modules/payments/create.php', 'label' => 'Record a payment', 'label_key' => 'intro_go_payment_new', 'permission' => 'payments:create'],
        ],
    ],
    'tenants.edit' => [
        'what' => 'Change a tenant’s details.',
        'how'  => 'The ID copy is not here — it is managed from the tenant’s own page, because it is a file rather than a field.',
        'next' => [],
    ],
    'leases.index' => [
        'what' => 'Every agreement linking a tenant to a unit, with its rent, term and payment frequency.',
        'how'  => 'A lease is what makes rent get charged. Until a unit has an active lease, nothing is billed and no arrears appear.',
        'next' => [
            ['url' => 'modules/payments/', 'label' => 'Payments', 'label_key' => 'intro_go_payments', 'permission' => 'payments:view'],
        ],
    ],
    'leases.create' => [
        'what' => 'Put a tenant into a unit on agreed terms.',
        'how'  => 'Only vacant or reserved units appear in the list — if a unit is missing, it is still marked occupied by an earlier lease. Rent and deposit fill in from the unit, and the payment frequency decides how often rent is charged.',
        'next' => [
            ['url' => 'modules/deposits/create.php', 'label' => 'Record the deposit', 'label_key' => 'intro_go_deposit_new', 'permission' => 'deposits:create'],
        ],
    ],
    'leases.view' => [
        'what' => 'One lease: its terms, what has been paid against it, and its documents.',
        'how'  => 'Rent is charged automatically each period from the start date. Use Print for a signable agreement built from your lease template.',
        'next' => [
            ['url' => 'modules/payments/create.php', 'label' => 'Record a payment', 'label_key' => 'intro_go_payment_new', 'permission' => 'payments:create'],
        ],
    ],

    // ============================================================
    // Money in
    // ============================================================
    'payments.index' => [
        'what' => 'Every payment received, and the receipts issued for them.',
        'how'  => 'A payment settles the tenant’s oldest unpaid charges first. Pay part of what is owed and the rest stays as arrears — a part payment is never treated as none.',
        'next' => [
            ['url' => 'modules/ledger/charges.php', 'label' => 'See what is owed', 'label_key' => 'intro_go_charges', 'permission' => 'ledger:view'],
        ],
    ],
    'payments.create' => [
        'what' => 'Record money received from a tenant.',
        'how'  => 'Choose how it was paid: cash and cheque go into your float until you bank them, while bank transfers go straight to the bank. The amount is applied to their oldest unpaid charges automatically.',
        'next' => [
            ['url' => 'modules/ledger/floats.php', 'label' => 'Bank the cash', 'label_key' => 'intro_go_floats', 'permission' => 'ledger:view'],
        ],
    ],
    'payments.view' => [
        'what' => 'One payment: what it settled, its receipt, and any vouchers attached to it.',
        'how'  => 'Reversing a payment also reopens the charges it had settled, so the tenant’s arrears return to what they were.',
        'next' => [],
    ],
    'deposits.index' => [
        'what' => 'Security deposits you are holding, and what has happened to them.',
        'how'  => 'A deposit is the tenant’s money until they forfeit it, so it is held rather than earned. Refunding or forfeiting one needs a second person to approve it.',
        'next' => [
            ['url' => 'modules/ledger/payables.php', 'label' => 'Approvals', 'label_key' => 'intro_go_payables', 'permission' => 'ledger:view'],
        ],
    ],
    'deposits.create' => [
        'what' => 'Record a deposit received against a lease.',
        'how'  => 'Cash goes into your float exactly like a rent payment. The deposit is recorded as money you hold for the tenant, not as income.',
        'next' => [],
    ],
    'deposits.view' => [
        'what' => 'One deposit: what is held, any deductions against it, and its refund status.',
        'how'  => 'Itemise deductions before refunding. Refund and Forfeit raise a request rather than acting immediately — someone else approves it under Ledger, Payables.',
        'next' => [
            ['url' => 'modules/ledger/payables.php', 'label' => 'Approvals', 'label_key' => 'intro_go_payables', 'permission' => 'ledger:view'],
        ],
    ],

    // ============================================================
    // Money out
    // ============================================================
    'expenses.index' => [
        'what' => 'What you spend running the properties — electricity, salaries, repairs.',
        'how'  => 'Record a bill when it arrives, whether or not it is paid. The cost counts against the month it was incurred, and it stays as owed until someone approves the payment.',
        'next' => [
            ['url' => 'modules/ledger/payables.php', 'label' => 'Pay it', 'label_key' => 'intro_go_payables', 'permission' => 'ledger:view'],
        ],
    ],

    // ============================================================
    // The ledger
    // ============================================================
    'ledger.index' => [
        'what' => 'Your chart of accounts — the bank accounts, cash boxes, income and expense categories everything is recorded against.',
        'how'  => 'Set this up before recording payments. Add your bank accounts, then map the default bank so transfers know where to land. Accounts marked System are created for you and cannot be deleted.',
        'next' => [
            ['url' => 'modules/ledger/mappings.php', 'label' => 'Account mapping', 'label_key' => 'intro_go_mappings', 'permission' => 'ledger:edit'],
        ],
    ],
    'ledger.mappings' => [
        'what' => 'Where automatic entries land. Each event — rent, deposits, bank transfers — points at one account.',
        'how'  => 'Highlighted rows are not yet mapped and must be set before those events can post. Closing the books at the bottom stops anything being recorded on or before a date.',
        'next' => [
            ['url' => 'modules/ledger/charges.php', 'label' => 'Charges', 'label_key' => 'intro_go_charges', 'permission' => 'ledger:view'],
        ],
    ],
    'ledger.charges' => [
        'what' => 'What tenants owe you: rent as it falls due, utilities and late fees.',
        'how'  => 'Charges are raised automatically each period for every active lease. If this is empty, the daily generator has not run yet — nothing is owed until charges exist.',
        'next' => [
            ['url' => 'modules/ledger/statements.php?tab=aged', 'label' => 'Aged debtors', 'label_key' => 'intro_go_aged', 'permission' => 'ledger:view'],
        ],
    ],
    'ledger.floats' => [
        'what' => 'Cash your people are holding on the company’s behalf, and the deposits they have declared.',
        'how'  => 'A float appears by itself the first time someone records a cash payment. Use Bank cash when the money reaches the bank — the float only falls once a second person approves it.',
        'next' => [
            ['url' => 'modules/ledger/statements.php', 'label' => 'Statements', 'label_key' => 'intro_go_statements', 'permission' => 'ledger:view'],
        ],
    ],
    'ledger.payables' => [
        'what' => 'Everything waiting to be paid or approved: supplier bills, and deposit refunds a colleague has requested.',
        'how'  => 'Approving is how money actually leaves. You cannot approve your own request, so at least two people need approval rights.',
        'next' => [
            ['url' => 'modules/ledger/statements.php', 'label' => 'Statements', 'label_key' => 'intro_go_statements', 'permission' => 'ledger:view'],
        ],
    ],
    'ledger.journal' => [
        'what' => 'Every entry in the books, whether recorded by a person or by the system.',
        'how'  => 'Entries awaiting approval affect nothing until approved. Posted entries can never be edited — correct a mistake by reversing it, which leaves both on the record.',
        'next' => [
            ['url' => 'modules/ledger/trial-balance.php', 'label' => 'Trial balance', 'label_key' => 'intro_go_tb', 'permission' => 'ledger:view'],
        ],
    ],
    'ledger.create' => [
        'what' => 'Record something by hand that the system cannot work out for itself — an opening balance, a correction, an adjustment.',
        'how'  => 'Debits must equal credits before it can be submitted, and every line must use an account in the same currency. Someone else then approves it.',
        'next' => [
            ['url' => 'modules/ledger/journal.php', 'label' => 'Journal', 'label_key' => 'intro_go_journal', 'permission' => 'ledger:view'],
        ],
    ],
    'ledger.view' => [
        'what' => 'One entry, its lines, and who created and approved it.',
        'how'  => 'A posted entry cannot be edited or deleted. Use Reverse to cancel it with a mirror entry — the original stays visible, marked reversed.',
        'next' => [],
    ],
    'ledger.trial-balance' => [
        'what' => 'The health check: total debits against total credits, per currency.',
        'how'  => 'These must be equal. Every entry is validated when saved, so if they ever differ, something changed the database outside the application.',
        'next' => [
            ['url' => 'modules/ledger/statements.php', 'label' => 'Statements', 'label_key' => 'intro_go_statements', 'permission' => 'ledger:view'],
        ],
    ],
    'ledger.statements' => [
        'what' => 'Profit and loss, balance sheet, unit-by-unit profitability, and who is behind on payments.',
        'how'  => 'Every figure is a link — click through to the account, then to the entries behind it. Income counts when it fell due and expenses when incurred, not when the cash moved.',
        'next' => [
            ['url' => 'modules/ledger/charges.php', 'label' => 'Charges', 'label_key' => 'intro_go_charges', 'permission' => 'ledger:view'],
        ],
    ],
    'ledger.account' => [
        'what' => 'Every movement on one account, with a running balance.',
        'how'  => 'This is where a figure on a statement gets explained. Each entry number links to the full entry it came from.',
        'next' => [],
    ],

    // ============================================================
    // Batch 2 — the remaining pages that carry a header
    // ============================================================

    // ---- editing existing records ----
    'properties.edit' => [
        'what' => 'Change a property\'s details.',
        'how'  => 'Renaming it changes how it reads on every report and statement, past ones included.',
        'next' => [],
    ],
    'properties.photos' => [
        'what' => 'Pictures of the building — useful on vacancy listings and for condition records.',
        'how'  => 'Photos here describe the property as a whole. Pictures of a particular flat belong on that unit instead.',
        'next' => [],
    ],
    'properties.settings' => [
        'what' => 'Rules that apply to this property only, such as its rent cycle and late-fee terms.',
        'how'  => 'Anything set here overrides the system-wide setting for this property alone. Leave a field empty to keep following the global rule.',
        'next' => [],
    ],
    'units.edit' => [
        'what' => 'Change a unit\'s details or its rent.',
        'how'  => 'Changing the rent does not alter existing leases — they keep the figure agreed when they were signed. Set the rent on the lease itself to change what is charged.',
        'next' => [],
    ],
    'units.photos' => [
        'what' => 'Pictures of this unit, for listings and for recording its condition.',
        'how'  => 'Take these at move-in and move-out; they settle most deposit arguments before they start.',
        'next' => [],
    ],
    'leases.edit' => [
        'what' => 'Change the terms of an existing lease.',
        'how'  => 'Changing the rent or frequency affects charges raised from now on. Charges already raised keep their original amounts — reverse them if they are wrong.',
        'next' => [],
    ],
    'leases.templates' => [
        'what' => 'The wording used when you print a lease agreement.',
        'how'  => 'Placeholders like {{rent_amount}} are filled in from the lease when it prints. Mark one as default and new leases use it.',
        'next' => [],
    ],
    'deposits.edit' => [
        'what' => 'Correct the details of a deposit already recorded.',
        'how'  => 'Refunds and forfeitures are not done here — they are requested from the deposit\'s own page and need approval.',
        'next' => [],
    ],
    'payments.edit' => [
        'what' => 'Correct the details of a payment already recorded.',
        'how'  => 'To undo a payment entirely, reverse it from its own page instead — that reopens the charges it settled and unwinds it in the accounts.',
        'next' => [],
    ],
    'payments.bulk' => [
        'what' => 'Record several payments at once, for when rent day brings a queue.',
        'how'  => 'Each row is applied to that tenant\'s oldest unpaid charges, exactly as a single payment would be.',
        'next' => [
            ['url' => 'modules/ledger/charges.php', 'label' => 'See what is owed', 'label_key' => 'intro_go_charges', 'permission' => 'ledger:view'],
        ],
    ],

    // ---- deposits: late fees ----
    'deposits.late-fees' => [
        'what' => 'Charges added when rent was not paid on time.',
        'how'  => 'These are raised automatically by the daily job, based on your late-fee rules. A fee can be waived, with the reason recorded.',
        'next' => [],
    ],
    'deposits.late-fee-settings' => [
        'what' => 'The rules deciding when a late fee applies and how much it is.',
        'how'  => 'Set a grace period so a payment a day late is not penalised, and a maximum so fees cannot run away on a long-overdue account.',
        'next' => [],
    ],

    // ---- documents ----
    'documents.index' => [
        'what' => 'Every file stored in the system, and what each one is attached to.',
        'how'  => 'Filter by category or by what a document belongs to. Files marked confidential are flagged but still visible to anyone who can open this page.',
        'next' => [],
    ],
    'documents.upload' => [
        'what' => 'Add a file and attach it to a property, unit, tenant, lease, payment, deposit or expense.',
        'how'  => 'Arriving here from a record pre-selects it, which is the surest way to file something against the right thing.',
        'next' => [],
    ],
    'documents.view' => [
        'what' => 'One document: a preview where possible, and where it belongs.',
        'how'  => 'Use Download for anything the browser cannot show. Editing changes the record, not the file — re-upload to replace the file itself.',
        'next' => [],
    ],

    // ---- inspections ----
    'inspections.index' => [
        'what' => 'Condition checks on units, at move-in, move-out or routine.',
        'how'  => 'A move-out inspection is what justifies a deposit deduction, so do one before returning any deposit.',
        'next' => [
            ['url' => 'modules/deposits/', 'label' => 'Deposits', 'label_key' => 'intro_go_deposits', 'permission' => 'deposits:view'],
        ],
    ],
    'inspections.create' => [
        'what' => 'Start a condition check on a unit.',
        'how'  => 'Pick a template to get a ready-made checklist. Photograph anything you might later want to charge for.',
        'next' => [],
    ],
    'inspections.view' => [
        'what' => 'One inspection: each item checked, its condition and its photos.',
        'how'  => 'Items marked as damaged can be turned into deposit deductions, carrying their description and photos with them.',
        'next' => [],
    ],
    'inspections.move-out' => [
        'what' => 'The check done when a tenant leaves, comparing the unit against how they found it.',
        'how'  => 'Complete this before refunding the deposit — deductions made without it are hard to defend.',
        'next' => [
            ['url' => 'modules/inspections/deductions.php', 'label' => 'Deductions', 'label_key' => 'intro_go_deductions', 'permission' => 'deposits:edit'],
        ],
    ],
    'inspections.deductions' => [
        'what' => 'Amounts withheld from a deposit for damage or cleaning.',
        'how'  => 'Each deduction should point at an inspection finding. What remains after deductions is what gets refunded.',
        'next' => [],
    ],
    'inspections.templates' => [
        'what' => 'Reusable checklists so every inspection covers the same ground.',
        'how'  => 'Build one per property type. A consistent checklist is what makes two inspections comparable.',
        'next' => [],
    ],

    // ---- maintenance ----
    'maintenance.index' => [
        'what' => 'Repair and maintenance requests, from reported to resolved.',
        'how'  => 'Tenants can raise these from their portal. Assign a contractor, track the cost, and it can become an expense when done.',
        'next' => [
            ['url' => 'modules/expenses/', 'label' => 'Expenses', 'label_key' => 'intro_go_expenses', 'permission' => 'expenses:view'],
        ],
    ],
    'maintenance.create' => [
        'what' => 'Log something that needs fixing.',
        'how'  => 'Photographs and a clear description save a second visit. Set the priority honestly — everything marked urgent means nothing is.',
        'next' => [],
    ],
    'maintenance.view' => [
        'what' => 'One request: its history, photos, who is assigned and what it cost.',
        'how'  => 'Costs recorded here can be turned into an expense, which is what puts them into the accounts.',
        'next' => [],
    ],
    'maintenance.edit' => [
        'what' => 'Change the details of a maintenance request.',
        'how'  => 'Updating the status is what tells the tenant something is happening, if they raised it from the portal.',
        'next' => [],
    ],
    'maintenance.contractors' => [
        'what' => 'The people and firms you call on for repairs.',
        'how'  => 'Recording a trade and rate here makes assigning work quicker and gives you something to compare when costs drift.',
        'next' => [],
    ],
    'maintenance.reports' => [
        'what' => 'What maintenance is costing, by property, category and contractor.',
        'how'  => 'Use it to spot a building that costs more than it should, or a recurring fault being patched rather than fixed.',
        'next' => [],
    ],

    // ---- utilities ----
    'utilities.index' => [
        'what' => 'Water, electricity and other services recharged to tenants.',
        'how'  => 'Record a meter reading, and the charge is worked out from your rate and added to what that tenant owes.',
        'next' => [
            ['url' => 'modules/ledger/charges.php', 'label' => 'See what is owed', 'label_key' => 'intro_go_charges', 'permission' => 'ledger:view'],
        ],
    ],
    'utilities.readings' => [
        'what' => 'Meter readings, and the charges calculated from them.',
        'how'  => 'Enter the current reading; consumption is the difference from last time. Check the first reading of a new tenancy carefully — everything after depends on it.',
        'next' => [],
    ],
    'utilities.types' => [
        'what' => 'The services you bill for, and how each is priced.',
        'how'  => 'Choose per-unit for metered supplies, flat for a fixed monthly charge, or tiered where the rate changes with consumption.',
        'next' => [],
    ],

    // ---- vacancies ----
    'vacancies.index' => [
        'what' => 'Empty units you are advertising, and the enquiries they attract.',
        'how'  => 'Only vacant units can be listed. A listing closes itself when you create a lease on that unit.',
        'next' => [
            ['url' => 'modules/vacancies/inquiries.php', 'label' => 'Enquiries', 'label_key' => 'intro_go_inquiries', 'permission' => 'vacancies:view'],
        ],
    ],
    'vacancies.create' => [
        'what' => 'Advertise an empty unit.',
        'how'  => 'The rent and description come from the unit. Add photos — a listing without them gets far fewer enquiries.',
        'next' => [],
    ],
    'vacancies.view' => [
        'what' => 'One listing: how it reads, and who has asked about it.',
        'how'  => 'Turn a promising enquiry into a tenant, then a lease — that closes the listing automatically.',
        'next' => [
            ['url' => 'modules/tenants/create.php', 'label' => 'Add a tenant', 'label_key' => 'intro_go_tenant_new', 'permission' => 'tenants:create'],
        ],
    ],
    'vacancies.inquiries' => [
        'what' => 'People who have asked about a vacant unit.',
        'how'  => 'Mark each as contacted or closed so nobody is chased twice or forgotten.',
        'next' => [],
    ],

    // ---- reports ----
    'reports.index' => [
        'what' => 'Operational reports — collections, occupancy and arrears.',
        'how'  => 'These read your day-to-day records. For the accounts themselves, use the Ledger statements instead.',
        'next' => [
            ['url' => 'modules/ledger/statements.php', 'label' => 'Statements', 'label_key' => 'intro_go_statements', 'permission' => 'ledger:view'],
        ],
    ],
    'reports.financial' => [
        'what' => 'Money collected and spent over a period.',
        'how'  => 'This counts cash as it moved. The Profit and Loss under Ledger counts income when it fell due instead, so the two answer different questions.',
        'next' => [
            ['url' => 'modules/ledger/statements.php', 'label' => 'Statements', 'label_key' => 'intro_go_statements', 'permission' => 'ledger:view'],
        ],
    ],
    'reports.occupancy' => [
        'what' => 'How much of your space is earning, and how much is standing empty.',
        'how'  => 'Occupancy follows lease status, so it is only as accurate as your leases. A unit still marked occupied after a lease ended will overstate it.',
        'next' => [],
    ],
    'reports.custom' => [
        'what' => 'Build your own report by choosing the columns and filters you want.',
        'how'  => 'Export to spreadsheet for anything you need to rework or send on.',
        'next' => [],
    ],
    'expenses.reports' => [
        'what' => 'What you have spent, broken down by property and category.',
        'how'  => 'Costs are counted against the month they were incurred, so a bill paid late still lands in the right month.',
        'next' => [],
    ],

    // ---- payments: gateway ----
    'payments.gateway-transactions' => [
        'what' => 'Payments that came in through the online gateway.',
        'how'  => 'These record themselves and post straight to the bank, since nobody handled the cash. A failed transaction here means the tenant was not charged.',
        'next' => [],
    ],
    'payments.gateway-settings' => [
        'what' => 'Connect an online payment provider so tenants can pay themselves.',
        'how'  => 'Test with a small live payment before announcing it — a wrong key fails silently at the tenant\'s end.',
        'next' => [],
    ],
    'payments.gateway-pay' => [
        'what' => 'The page a tenant sees when paying online.',
        'how'  => 'Open it to check what they will see. A successful payment records itself and settles their oldest charges.',
        'next' => [],
    ],

    // ---- settings and administration ----
    'settings.index' => [
        'what' => 'Company details, currency, modules and system-wide rules.',
        'how'  => 'The Modules tab switches whole features on and off. Some need a higher plan, and those are marked.',
        'next' => [
            ['url' => 'modules/masters/users.php', 'label' => 'Users', 'label_key' => 'intro_go_users', 'permission' => 'users:view'],
        ],
    ],
    'settings.reminders' => [
        'what' => 'Automatic messages telling tenants what is due and what is overdue.',
        'how'  => 'Reminders read what is actually owed, so a tenant who has paid is never chased. Test a template on yourself before switching it on.',
        'next' => [],
    ],
    'settings.backup' => [
        'what' => 'Export your data, or bring it into another system.',
        'how'  => 'Take an export before anything large — a bulk import, a data clean-up, or a change you are unsure of.',
        'next' => [],
    ],
    'masters.users' => [
        'what' => 'Who can log in, and what each of them is allowed to do.',
        'how'  => 'Give at least two people approval rights — nobody can approve their own work, so with one approver nothing can ever be signed off.',
        'next' => [
            ['url' => 'modules/ledger/payables.php', 'label' => 'Approvals', 'label_key' => 'intro_go_payables', 'permission' => 'ledger:view'],
        ],
    ],
    'masters.roles' => [
        'what' => 'Groups of permissions that decide what a user can see and change.',
        'how'  => 'Give each role the least it needs. A caretaker who cannot delete a payment cannot delete one by accident.',
        'next' => [],
    ],

    ];

    return $intros;
}
