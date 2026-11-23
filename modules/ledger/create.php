<?php
/**
 * FIS Real Estate — New Journal Entry (Part 30, Phase 1)
 *
 * Also serves opening balances: same form, different source type. The running
 * debit/credit totals are shown live so an unbalanced entry is obvious before
 * it is submitted, though the server validates again regardless.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/ledger.php';

$subStatus = enforceSubscription();

if (!hasPermission('ledger', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('modules/ledger/');
}

$isOpening = ($_GET['type'] ?? '') === 'opening';
$pageTitle = $isOpening
    ? __('led_opening_balances', 'Opening Balances')
    : __('led_new_entry', 'New Entry');

$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $old = [
        'entry_date' => trim($_POST['entry_date'] ?? date('Y-m-d')),
        'currency'   => trim($_POST['currency'] ?? DEFAULT_CURRENCY),
        'memo'       => trim($_POST['memo'] ?? ''),
    ];

    $lines = [];
    foreach (($_POST['line_account'] ?? []) as $i => $accountId) {
        $lines[] = [
            'account_id'  => (int) $accountId,
            'debit'       => (float) str_replace(',', '', $_POST['line_debit'][$i] ?? '0'),
            'credit'      => (float) str_replace(',', '', $_POST['line_credit'][$i] ?? '0'),
            'property_id' => (int) ($_POST['line_property'][$i] ?? 0) ?: null,
            'memo'        => trim($_POST['line_memo'][$i] ?? ''),
        ];
    }
    $old['lines'] = $lines;

    $result = ledgerCreateEntry([
        'entry_date'  => $old['entry_date'],
        'currency'    => $old['currency'],
        'memo'        => $old['memo'],
        'source_type' => $isOpening ? 'opening_balance' : 'manual',
    ], $lines);

    if ($result['success']) {
        setAlert(__('led_entry_created', 'Entry submitted for approval'), 'success');
        redirect('modules/ledger/view.php?id=' . $result['id']);
    }
    $errors = $result['errors'];
}

$accounts   = ledgerAccounts();
$currencies = array_keys(getSupportedCurrencies());
$properties = $conn->query('SELECT id, name FROM properties WHERE status = \'active\''
                 . propertyScopeSql('id') . ' ORDER BY name')->fetchAll();

// Opening balances start against equity, which is the whole point of that flow.
$openingEquity = ledgerMappedAccount('opening_equity');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-<?php echo $isOpening ? 'flag' : 'plus-circle'; ?> me-2"></i><?php echo htmlspecialchars($pageTitle); ?></h4>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/ledger/"><?php _e('led_ledger', 'Ledger'); ?></a></li>
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/ledger/journal.php"><?php _e('led_journal', 'Journal'); ?></a></li>
            <li class="breadcrumb-item active"><?php _e('create'); ?></li>
        </ol>
    </nav>
</div>

<?php pageIntro('ledger.create'); ?>

<?php ledgerNav('journal'); ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if ($isOpening): ?>
<div class="alert alert-info py-2 small">
    <i class="bi bi-info-circle me-1"></i>
    <?php _e('led_opening_help', 'Enter each account\'s real balance as at the go-live date. Post the balancing amount to Opening Balance Equity so the entry balances.'); ?>
    <?php if (getUserData('role_name') === 'Admin'): ?>
    <a href="<?php echo BASE_URL; ?>modules/import/upload.php?type=trial_balance" class="ms-1"><?php _e('imp_tb_link', 'Or import them from a file.'); ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="POST" id="entryForm">
    <?php echo csrfField(); ?>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('date'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="entry_date" required
                           value="<?php echo htmlspecialchars($old['entry_date'] ?? date('Y-m-d')); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('currency'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" name="currency" id="entryCurrency" required>
                        <?php foreach ($currencies as $c): ?>
                        <option value="<?php echo $c; ?>" <?php echo ($old['currency'] ?? DEFAULT_CURRENCY) === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted"><?php _e('led_currency_help', 'All lines must use accounts in this currency.'); ?></small>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('led_memo', 'Memo'); ?></label>
                    <input type="text" class="form-control" name="memo" maxlength="255"
                           value="<?php echo htmlspecialchars($old['memo'] ?? ($isOpening ? __('led_opening_balances', 'Opening Balances') : '')); ?>"
                           placeholder="<?php _e('led_memo_help', 'What this entry is for'); ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ol me-1"></i> <?php _e('led_lines', 'Lines'); ?></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addLine()">
                <i class="bi bi-plus-lg me-1"></i><?php _e('led_add_line', 'Add line'); ?>
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="linesTable">
                <thead>
                    <tr>
                        <th style="min-width:220px;"><?php _e('led_account', 'Account'); ?></th>
                        <th style="min-width:140px;"><?php _e('property'); ?></th>
                        <th style="min-width:150px;"><?php _e('led_memo', 'Memo'); ?></th>
                        <th class="text-end" style="width:150px;"><?php _e('led_debit', 'Debit'); ?></th>
                        <th class="text-end" style="width:150px;"><?php _e('led_credit', 'Credit'); ?></th>
                        <th style="width:44px;"></th>
                    </tr>
                </thead>
                <tbody id="linesBody"></tbody>
                <tfoot>
                    <tr class="fw-semibold">
                        <td colspan="3" class="text-end"><?php _e('led_totals', 'Totals'); ?></td>
                        <td class="text-end" id="totalDebit">0.00</td>
                        <td class="text-end" id="totalCredit">0.00</td>
                        <td></td>
                    </tr>
                    <tr id="diffRow">
                        <td colspan="3" class="text-end text-muted small"><?php _e('led_difference', 'Difference'); ?></td>
                        <td colspan="2" class="text-end" id="diffCell">0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="<?php echo BASE_URL; ?>modules/ledger/journal.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
        </a>
        <button type="submit" class="btn btn-primary" id="submitBtn">
            <i class="bi bi-check-lg me-1"></i> <?php _e('led_submit_entry', 'Submit for approval'); ?>
        </button>
    </div>
</form>

<script>
const ACCOUNTS = <?php echo json_encode(array_map(function ($a) {
    return ['id' => (int)$a['id'], 'name' => $a['name'], 'code' => $a['code'],
            'type' => $a['type'], 'currency' => $a['currency']];
}, $accounts)); ?>;
const PROPERTIES = <?php echo json_encode(array_map(function ($p) {
    return ['id' => (int)$p['id'], 'name' => $p['name']];
}, $properties)); ?>;
const OLD_LINES = <?php echo json_encode($old['lines'] ?? []); ?>;
const OPENING_EQUITY = <?php echo json_encode($isOpening && $openingEquity ? (int)$openingEquity['id'] : null); ?>;

let lineIndex = 0;

function accountOptions(currency, selected) {
    let html = '<option value="">—</option>';
    ACCOUNTS.filter(a => a.currency === currency).forEach(a => {
        const label = (a.code ? a.code + ' · ' : '') + a.name;
        html += `<option value="${a.id}" ${Number(selected) === a.id ? 'selected' : ''}>${label}</option>`;
    });
    return html;
}

function propertyOptions(selected) {
    let html = '<option value="">—</option>';
    PROPERTIES.forEach(p => {
        html += `<option value="${p.id}" ${Number(selected) === p.id ? 'selected' : ''}>${p.name}</option>`;
    });
    return html;
}

function addLine(data) {
    const i = lineIndex++;
    const cur = document.getElementById('entryCurrency').value;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><select name="line_account[${i}]" class="form-select form-select-sm acct-select">${accountOptions(cur, data && data.account_id)}</select></td>
        <td><select name="line_property[${i}]" class="form-select form-select-sm">${propertyOptions(data && data.property_id)}</select></td>
        <td><input type="text" name="line_memo[${i}]" class="form-control form-control-sm" maxlength="255" value="${data && data.memo ? String(data.memo).replace(/"/g, '&quot;') : ''}"></td>
        <td><input type="number" step="0.01" min="0" name="line_debit[${i}]" class="form-control form-control-sm text-end amt dr" value="${data && Number(data.debit) ? Number(data.debit).toFixed(2) : ''}"></td>
        <td><input type="number" step="0.01" min="0" name="line_credit[${i}]" class="form-control form-control-sm text-end amt cr" value="${data && Number(data.credit) ? Number(data.credit).toFixed(2) : ''}"></td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-secondary" onclick="this.closest('tr').remove(); recalc();" aria-label="<?php echo addslashes(__('led_remove_line', 'Remove line')); ?>"><i class="bi bi-x-lg"></i></button></td>
    `;
    document.getElementById('linesBody').appendChild(tr);

    // A line is one side or the other, never both.
    tr.querySelector('.dr').addEventListener('input', function () {
        if (this.value) tr.querySelector('.cr').value = '';
        recalc();
    });
    tr.querySelector('.cr').addEventListener('input', function () {
        if (this.value) tr.querySelector('.dr').value = '';
        recalc();
    });
    recalc();
}

function recalc() {
    let dr = 0, cr = 0;
    document.querySelectorAll('#linesBody .dr').forEach(i => dr += parseFloat(i.value) || 0);
    document.querySelectorAll('#linesBody .cr').forEach(i => cr += parseFloat(i.value) || 0);

    const fmt = n => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('totalDebit').textContent = fmt(dr);
    document.getElementById('totalCredit').textContent = fmt(cr);

    const diff = Math.round((dr - cr) * 100) / 100;
    const cell = document.getElementById('diffCell');
    const balanced = Math.abs(diff) < 0.005 && dr > 0;
    cell.textContent = balanced ? '<?php echo addslashes(__('led_balanced', 'Balanced')); ?>' : fmt(diff);
    cell.className = 'text-end fw-semibold ' + (balanced ? 'text-success' : 'text-danger');
    document.getElementById('submitBtn').disabled = !balanced;
}

// Rebuilding the account lists on currency change keeps the single-currency
// rule visible in the UI, not just enforced on the server.
document.getElementById('entryCurrency').addEventListener('change', function () {
    document.querySelectorAll('.acct-select').forEach(sel => {
        const keep = sel.value;
        sel.innerHTML = accountOptions(this.value, keep);
    });
});

if (OLD_LINES.length) {
    OLD_LINES.forEach(l => addLine(l));
} else {
    addLine(OPENING_EQUITY ? null : null);
    addLine(OPENING_EQUITY ? { account_id: OPENING_EQUITY } : null);
}
recalc();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
