<?php
/**
 * FIS Real Estate — Account Mapping (Part 30, Phase 1)
 *
 * Wires each automatic event to an account. Nothing posts automatically in
 * phase 1, but the wiring has to exist and be correct before phase 2 turns
 * those postings on.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/ledger_reports.php';
$pageTitle = __('led_mappings', 'Account Mapping');

$subStatus = enforceSubscription();

if (!hasPermission('ledger', 'edit')) {
    setAlert(__('no_permission'), 'danger');
    redirect('modules/ledger/');
}

$mappings = ledgerMappings();
$accounts = ledgerAccounts();

// What each key means, and which account types are sensible for it.
$keyInfo = [
    'ar'                     => ['label' => __('led_map_ar', 'Tenant receivables'),        'desc' => __('led_map_ar_d', 'Where rent, utility and late-fee charges are recorded as owed'),  'types' => ['asset']],
    'bank_default'           => ['label' => __('led_map_bank', 'Default bank account'),      'desc' => __('led_map_bank_d', 'Where bank transfers and gateway payments land'),      'types' => ['asset']],
    'ap'                     => ['label' => __('led_map_ap', 'Supplier payables'),         'desc' => __('led_map_ap_d', 'Where unpaid expenses sit until settled'),                        'types' => ['liability']],
    'deposits_held'          => ['label' => __('led_map_dep', 'Tenant deposits held'),     'desc' => __('led_map_dep_d', 'Deposits are a liability — the money belongs to the tenant'),   'types' => ['liability']],
    'opening_equity'         => ['label' => __('led_map_oe', 'Opening balance equity'),    'desc' => __('led_map_oe_d', 'The contra account for opening balances'),                       'types' => ['equity']],
    'retained_earnings'      => ['label' => __('led_map_re', 'Retained earnings'),         'desc' => __('led_map_re_d', 'Accumulated profit carried forward'),                            'types' => ['equity']],
    'rent_income'            => ['label' => __('led_map_rent', 'Rent income'),             'desc' => __('led_map_rent_d', 'Recognised when rent falls due, not when it is paid'),         'types' => ['income']],
    'late_fee_income'        => ['label' => __('led_map_lf', 'Late fee income'),           'desc' => __('led_map_lf_d', 'Charges applied for late payment'),                              'types' => ['income']],
    'utility_income'         => ['label' => __('led_map_ut', 'Utility income'),            'desc' => __('led_map_ut_d', 'Utilities recharged to tenants'),                                'types' => ['income']],
    'deposit_forfeit_income' => ['label' => __('led_map_df', 'Deposit forfeited'),         'desc' => __('led_map_df_d', 'Deposits retained rather than refunded'),                        'types' => ['income']],
];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-diagram-3 me-2"></i><?php _e('led_mappings', 'Account Mapping'); ?></h4>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/ledger/"><?php _e('led_ledger', 'Ledger'); ?></a></li>
            <li class="breadcrumb-item active"><?php _e('led_mappings', 'Account Mapping'); ?></li>
        </ol>
    </nav>
</div>

<?php pageIntro('ledger.mappings'); ?>

<?php ledgerNav('mappings'); ?>

<div class="alert alert-info py-2 small">
    <i class="bi bi-info-circle me-1"></i>
    <?php _e('led_map_help', 'These decide where automatic entries land. You can point an event at your own account instead of the system one, but it can never be left empty.'); ?>
</div>


<div class="card mb-3">
    <div class="card-header"><i class="bi bi-cash-stack me-1"></i> <?php _e('led_cash_policy', 'Cash and approvals'); ?></div>
    <div class="card-body">
        <form id="cashPolicyForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_cash_policy">

            <div class="mb-3">
                <label class="form-label small fw-semibold mb-1"><?php _e('led_cash_methods', 'Methods that put cash in someone\'s hands'); ?></label>
                <p class="small text-muted mb-2">
                    <?php _e('led_cash_methods_help', 'These go into a cash box or float and have to be banked before they count as money at the bank. Everything else posts straight to a bank account.'); ?>
                </p>
                <?php
                $selectedMethods = floatPaymentMethods();
                $methodLabels = [
                    'cash'          => __('cash', 'Cash'),
                    'cheque'        => __('cheque', 'Cheque'),
                    'bank_transfer' => __('bank_transfer', 'Bank Transfer'),
                    'mobile_money'  => __('mobile_money', 'Mobile Money'),
                ];
                foreach ($methodLabels as $value => $label): ?>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="float_methods[]"
                           id="fm_<?php echo $value; ?>" value="<?php echo $value; ?>"
                           <?php echo in_array($value, $selectedMethods, true) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="fm_<?php echo $value; ?>"><?php echo htmlspecialchars($label); ?></label>
                </div>
                <?php endforeach; ?>
            </div>

            <hr>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="allow_self_approval" id="allowSelfApproval"
                           value="1" <?php echo ledgerAllowSelfApproval() ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-semibold" for="allowSelfApproval">
                        <?php _e('led_self_approve', 'Allow approving your own requests'); ?>
                    </label>
                </div>
                <p class="small text-muted mb-0 mt-1">
                    <?php _e('led_self_approve_help', 'On, one person can record cash and sign off banking it — necessary if you run the system alone. Off, money leaving always needs a second person, which is the only control here that a single user cannot defeat.'); ?>
                </p>
            </div>

            <button class="btn btn-outline-primary"><i class="bi bi-check-lg me-1"></i><?php _e('save'); ?></button>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><i class="bi bi-lock me-1"></i> <?php _e('rep_close_period', 'Close the books'); ?></div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            <?php _e('rep_lock_help', 'Nothing can be posted on or before this date — by a person, an API call or a cron. Leave it empty to keep the books fully open.'); ?>
        </p>
        <form id="lockForm" class="row g-2 align-items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_lock_date">
            <div class="col-12 col-md-4">
                <label class="form-label small fw-semibold"><?php _e('rep_locked_upto', 'Closed up to and including'); ?></label>
                <input type="date" name="lock_date" class="form-control" value="<?php echo htmlspecialchars(ledgerLockDate() ?? ''); ?>">
            </div>
            <div class="col-12 col-md-4">
                <button class="btn btn-outline-primary"><i class="bi bi-check-lg me-1"></i><?php _e('save'); ?></button>
                <?php if (ledgerLockDate()): ?>
                <span class="badge bg-warning text-dark ms-2">
                    <i class="bi bi-lock-fill me-1"></i><?php echo formatDate(ledgerLockDate()); ?>
                </span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<form id="mapForm">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="save_mappings">

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:38%;"><?php _e('led_event', 'Event'); ?></th>
                        <th><?php _e('led_posts_to', 'Posts to'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keyInfo as $key => $info): ?>
                    <?php $current = $mappings[$key]['account_id'] ?? null; ?>
                    <tr class="<?php echo empty($current) ? 'table-warning' : ''; ?>">
                        <td>
                            <div class="fw-semibold"><?php echo htmlspecialchars($info['label']); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($info['desc']); ?></small>
                        </td>
                        <td>
                            <select name="mapping[<?php echo htmlspecialchars($key); ?>]" class="form-select form-select-sm" required>
                                <?php if (empty($current)): ?>
                                <?php // Unmapped: force a deliberate choice. Without this the browser
                                      // pre-selects the first account and Save would silently wire the
                                      // event to whatever happened to be at the top of the list. ?>
                                <option value="" selected disabled><?php _e('led_map_choose', '— choose an account —'); ?></option>
                                <?php endif; ?>
                                <?php foreach ($accounts as $a): ?>
                                <?php if (!in_array($a['type'], $info['types'], true)) continue; ?>
                                <option value="<?php echo (int)$a['id']; ?>" <?php echo (int)$current === (int)$a['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(($a['code'] ? $a['code'] . ' · ' : '') . $a['name'] . ' (' . $a['currency'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($mappings[$key]['name'])): ?>
                            <small class="text-danger"><?php _e('led_map_missing', 'Not mapped'); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <a href="<?php echo BASE_URL; ?>modules/ledger/" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
            </a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> <?php _e('save'); ?></button>
        </div>
    </div>
</form>

<script>
document.getElementById('cashPolicyForm').addEventListener('submit', function (e) {
    e.preventDefault();
    fetch('<?php echo BASE_URL; ?>api/ledger.php', { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(d => { showToast(d.message, d.success ? 'success' : 'danger'); if (d.success) setTimeout(() => location.reload(), 600); });
});
document.getElementById('lockForm').addEventListener('submit', function (e) {
    e.preventDefault();
    fetch('<?php echo BASE_URL; ?>api/ledger.php', { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.message); })
        .catch(() => alert('Could not reach the server'));
});

document.getElementById('mapForm').addEventListener('submit', function (e) {
    e.preventDefault();
    fetch('<?php echo BASE_URL; ?>api/ledger.php', { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(d => {
            if (d.success) location.reload();
            else alert(d.message);
        })
        .catch(() => alert('Could not reach the server'));
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
