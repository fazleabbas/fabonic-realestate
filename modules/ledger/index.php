<?php
/**
 * FIS Real Estate — Chart of Accounts (Part 30, Phase 1)
 * Client-managed accounts plus protected system accounts.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/ledger.php';
$pageTitle = __('led_chart_of_accounts', 'Chart of Accounts');

$subStatus = enforceSubscription();

if (!hasPermission('ledger', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$filterType     = trim($_GET['type'] ?? '');
$filterCurrency = trim($_GET['currency'] ?? '');
$search         = trim($_GET['search'] ?? '');
$showArchived   = !empty($_GET['archived']);

$accounts = ledgerAccounts([
    'type' => $filterType,
    'currency' => $filterCurrency,
    'search' => $search,
    'include_archived' => $showArchived,
]);

// Group by type so the chart reads like a chart, not a flat list.
$grouped = [];
foreach ($accounts as $a) {
    $a['balance'] = ledgerAccountBalance($a['id']);
    $grouped[$a['type']][] = $a;
}

$typeLabels = [
    'asset'     => __('led_asset', 'Assets'),
    'liability' => __('led_liability', 'Liabilities'),
    'equity'    => __('led_equity', 'Equity'),
    'income'    => __('led_income', 'Income'),
    'expense'   => __('led_expense', 'Expenses'),
];
$currencies = array_keys(getSupportedCurrencies());

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-journal-bookmark me-2"></i><?php _e('led_chart_of_accounts', 'Chart of Accounts'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/ledger/"><?php _e('led_ledger', 'Ledger'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('led_accounts', 'Accounts'); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?php echo BASE_URL; ?>modules/ledger/charges.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-receipt-cutoff me-1"></i> <?php _e('chg_charges', 'Charges'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/ledger/payables.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-cash-coin me-1"></i> <?php _e('pay_payables', 'Payables'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/ledger/floats.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-wallet2 me-1"></i> <?php _e('flt_floats', 'Floats'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/ledger/journal.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-list-columns me-1"></i> <?php _e('led_journal', 'Journal'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/ledger/statements.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-file-earmark-bar-graph me-1"></i> <?php _e('rep_statements', 'Statements'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/ledger/trial-balance.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-clipboard-data me-1"></i> <?php _e('led_trial_balance', 'Trial Balance'); ?>
        </a>
        <?php if (hasPermission('ledger', 'edit')): ?>
        <a href="<?php echo BASE_URL; ?>modules/ledger/mappings.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-diagram-3 me-1"></i> <?php _e('led_mappings', 'Account Mapping'); ?>
        </a>
        <button class="btn btn-primary btn-sm" onclick="openAccount()">
            <i class="bi bi-plus-lg me-1"></i> <?php _e('led_new_account', 'New Account'); ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('ledger.index'); ?>

<?php ledgerNav('accounts'); ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1"><?php _e('search'); ?></label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php _e('led_search_accounts', 'Name or code'); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1"><?php _e('led_type', 'Type'); ?></label>
                <select name="type" class="form-select form-select-sm">
                    <option value=""><?php _e('all'); ?></option>
                    <?php foreach ($typeLabels as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo $filterType === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1"><?php _e('currency'); ?></label>
                <select name="currency" class="form-select form-select-sm">
                    <option value=""><?php _e('all'); ?></option>
                    <?php foreach ($currencies as $c): ?>
                    <option value="<?php echo $c; ?>" <?php echo $filterCurrency === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" name="archived" value="1" id="showArchived" <?php echo $showArchived ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="showArchived"><?php _e('led_show_archived', 'Show archived'); ?></label>
                </div>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/ledger/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<?php if (!$accounts): ?>
<div class="card"><div class="card-body text-center text-muted py-5">
    <i class="bi bi-journal-x" style="font-size:2.5rem;opacity:.4;"></i>
    <p class="mt-2 mb-0"><?php _e('led_no_accounts', 'No accounts match this filter'); ?></p>
</div></div>
<?php endif; ?>

<?php foreach ($typeLabels as $type => $label): ?>
<?php if (empty($grouped[$type])) continue; ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-folder2 me-1"></i> <?php echo htmlspecialchars($label); ?></span>
        <small class="text-muted"><?php echo count($grouped[$type]); ?> <?php _e('led_accounts_lower', 'accounts'); ?> &middot; <?php _e('led_normal', 'normal'); ?>: <?php echo ledgerNormalSide($type) === 'debit' ? __('led_debit', 'Debit') : __('led_credit', 'Credit'); ?></small>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th style="width:90px;"><?php _e('led_code', 'Code'); ?></th>
                    <th><?php _e('led_account', 'Account'); ?></th>
                    <th style="width:80px;"><?php _e('currency'); ?></th>
                    <th class="text-end" style="width:160px;"><?php _e('led_balance', 'Balance'); ?></th>
                    <th style="width:110px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grouped[$type] as $a): ?>
                <tr class="<?php echo $a['status'] === 'archived' ? 'opacity-50' : ''; ?>">
                    <td class="text-muted small font-monospace"><?php echo htmlspecialchars($a['code'] ?? '—'); ?></td>
                    <td>
                        <a href="<?php echo BASE_URL; ?>modules/ledger/account.php?id=<?php echo (int)$a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?></a>
                        <?php if ((int)$a['is_system'] === 1): ?>
                        <span class="badge bg-light text-secondary border ms-1" title="<?php _e('led_system_hint', 'Created by the system; automatic postings depend on it'); ?>"><?php _e('led_system', 'System'); ?></span>
                        <?php endif; ?>
                        <?php if ($a['status'] === 'archived'): ?>
                        <span class="badge bg-secondary ms-1"><?php _e('led_archived', 'Archived'); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($a['description'])): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($a['description']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?php echo htmlspecialchars($a['currency']); ?></td>
                    <td class="text-end fw-semibold"><?php echo formatCurrency($a['balance'], $a['currency'], 2); ?></td>
                    <td class="text-end">
                        <?php if (hasPermission('ledger', 'edit')): ?>
                        <button class="btn btn-sm btn-outline-secondary" title="<?php _e('edit'); ?>"
                                onclick='openAccount(<?php echo json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php if ((int)$a['is_system'] !== 1): ?>
                        <button class="btn btn-sm btn-outline-secondary" title="<?php echo $a['status'] === 'active' ? __('led_archive', 'Archive') : __('led_restore', 'Restore'); ?>"
                                onclick="toggleArchive(<?php echo (int)$a['id']; ?>)">
                            <i class="bi bi-<?php echo $a['status'] === 'active' ? 'archive' : 'arrow-counterclockwise'; ?>"></i>
                        </button>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php if (hasPermission('ledger', 'edit')): ?>
<!-- Account modal -->
<div class="modal fade" id="accountModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="accountForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_account">
        <input type="hidden" name="id" id="acctId" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="acctModalTitle"><?php _e('led_new_account', 'New Account'); ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php _e('close', 'Close'); ?>"></button>
        </div>
        <div class="modal-body">
          <div id="acctSystemNote" class="alert alert-secondary py-2 small d-none">
            <i class="bi bi-lock me-1"></i><?php _e('led_system_edit_note', 'This is a system account. Only its name, code and description can be changed.'); ?>
          </div>
          <div class="row g-2">
            <div class="col-4">
              <label class="form-label small fw-semibold"><?php _e('led_code', 'Code'); ?></label>
              <input type="text" class="form-control" name="code" id="acctCode" placeholder="1200">
            </div>
            <div class="col-8">
              <label class="form-label small fw-semibold"><?php _e('led_account_name', 'Account name'); ?> <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="name" id="acctName" required>
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold"><?php _e('led_type', 'Type'); ?> <span class="text-danger">*</span></label>
              <select class="form-select" name="type" id="acctType" required>
                <?php foreach ($typeLabels as $val => $label): ?>
                <option value="<?php echo $val; ?>"><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold"><?php _e('currency'); ?> <span class="text-danger">*</span></label>
              <select class="form-select" name="currency" id="acctCurrency" required>
                <?php foreach ($currencies as $c): ?>
                <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold"><?php _e('led_subtype', 'Kind'); ?></label>
              <select class="form-select" name="subtype" id="acctSubtype">
                <option value=""><?php _e('led_subtype_none', 'General'); ?></option>
                <option value="bank"><?php _e('led_subtype_bank', 'Bank account'); ?></option>
                <option value="cash"><?php _e('led_subtype_cash', 'Cash / petty cash'); ?></option>
              </select>
              <small class="text-muted"><?php _e('led_subtype_help', 'Used later to offer the right accounts when banking cash.'); ?></small>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold"><?php _e('description'); ?></label>
              <input type="text" class="form-control" name="description" id="acctDesc">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?php _e('save'); ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<form id="archiveForm" class="d-none"><?php echo csrfField(); ?></form>

<script>
const LEDGER_API = '<?php echo BASE_URL; ?>api/ledger.php';

function openAccount(account) {
    const f = document.getElementById('accountForm');
    f.reset();
    const isSystem = account && Number(account.is_system) === 1;

    document.getElementById('acctId').value       = account ? account.id : '';
    document.getElementById('acctCode').value     = account && account.code ? account.code : '';
    document.getElementById('acctName').value     = account ? account.name : '';
    document.getElementById('acctType').value     = account ? account.type : 'asset';
    document.getElementById('acctCurrency').value = account ? account.currency : '<?php echo DEFAULT_CURRENCY; ?>';
    document.getElementById('acctSubtype').value  = account && account.subtype ? account.subtype : '';
    document.getElementById('acctDesc').value     = account && account.description ? account.description : '';

    // System accounts: label only. Type and currency are what postings rely on.
    document.getElementById('acctType').disabled     = isSystem;
    document.getElementById('acctCurrency').disabled = isSystem;
    document.getElementById('acctSubtype').disabled  = isSystem;
    document.getElementById('acctSystemNote').classList.toggle('d-none', !isSystem);
    document.getElementById('acctModalTitle').textContent = account
        ? '<?php echo addslashes(__('led_edit_account', 'Edit Account')); ?>'
        : '<?php echo addslashes(__('led_new_account', 'New Account')); ?>';

    new bootstrap.Modal(document.getElementById('accountModal')).show();
}

document.getElementById('accountForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const fd = new FormData(this);
    // Disabled controls are not submitted; send the stored values instead.
    if (document.getElementById('acctType').disabled) {
        fd.set('type', document.getElementById('acctType').value);
        fd.set('currency', document.getElementById('acctCurrency').value);
        fd.set('subtype', document.getElementById('acctSubtype').value);
    }
    fetch(LEDGER_API, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) { location.reload(); }
            else { alert(d.message || 'Could not save the account'); }
        })
        .catch(() => alert('Could not reach the server'));
});

function toggleArchive(id) {
    if (!confirm('<?php echo addslashes(__('led_archive_confirm', 'Archive or restore this account? Existing entries are never changed.')); ?>')) return;
    const fd = new FormData(document.getElementById('archiveForm'));
    fd.append('action', 'archive_account');
    fd.append('id', id);
    fetch(LEDGER_API, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.message); })
        .catch(() => alert('Could not reach the server'));
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
