<?php
/**
 * FIS Real Estate — Payables and payouts awaiting approval (Part 32, Phase 3)
 *
 * Everything where money is about to leave: unpaid supplier bills, and deposit
 * refunds or forfeitures a colleague has requested.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/payables.php';
require_once __DIR__ . '/../../includes/entity_documents.php';
$pageTitle = __('pay_payables', 'Payables');

$subStatus = enforceSubscription();

if (!hasPermission('ledger', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$isApprover = ledgerCanApprove();
$expenses   = unpaidExpenses();
$payouts    = depositPayoutsPending();
$totals     = payablesTotal();

// Only asset accounts can be a source of payment.
$sourceAccounts = array_merge(
    ledgerAccounts(['type' => 'asset', 'subtype' => 'bank']),
    ledgerAccounts(['type' => 'asset', 'subtype' => 'cash']),
    ledgerAccounts(['type' => 'asset', 'subtype' => 'float'])
);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-cash-coin me-2"></i><?php _e('pay_payables', 'Payables'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/ledger/"><?php _e('led_ledger', 'Ledger'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('pay_payables', 'Payables'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('ledger.payables'); ?>

<?php ledgerNav('payables'); ?>

<?php if (!$isApprover): ?>
<div class="alert alert-secondary py-2 small">
    <i class="bi bi-eye me-1"></i><?php _e('pay_view_only', 'You can see what is outstanding, but paying it needs approval rights.'); ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach ($totals as $tot): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-receipt"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($tot['owed'], $tot['currency'], 2); ?></div>
                <div class="stat-card-label"><?php _e('pay_owed_suppliers', 'Owed to suppliers'); ?> · <?php echo htmlspecialchars($tot['currency']); ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if ($payouts): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fee2e2;color:#991b1b;"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="stat-card-value"><?php echo count($payouts); ?></div>
                <div class="stat-card-label"><?php _e('pay_awaiting', 'Deposit payouts awaiting approval'); ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Deposit refunds and forfeitures -->
<?php if ($payouts): ?>
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-hourglass-split me-1"></i> <?php _e('pay_deposit_payouts', 'Deposit refunds and forfeitures'); ?>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th><?php _e('tenant'); ?></th>
                    <th style="width:110px;"><?php _e('led_type', 'Type'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('pay_source', 'From'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('pay_requested_by', 'Requested by'); ?></th>
                    <th class="text-end" style="width:140px;"><?php _e('amount'); ?></th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payouts as $p): ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars($p['tenant_name'] ?: '—'); ?>
                        <?php if ($p['unit_number']): ?><br><small class="text-muted"><?php echo htmlspecialchars($p['unit_number']); ?></small><?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?php echo $p['payout_type'] === 'forfeit' ? 'danger' : 'primary'; ?>">
                            <?php echo $p['payout_type'] === 'forfeit' ? __('pay_forfeit', 'Forfeit') : __('pay_refund', 'Refund'); ?>
                        </span>
                    </td>
                    <td class="small text-muted d-none d-md-table-cell">
                        <?php echo htmlspecialchars($p['source_name'] ?: ($p['payout_type'] === 'forfeit' ? __('pay_no_source', 'n/a — becomes income') : '—')); ?>
                    </td>
                    <td class="small text-muted d-none d-md-table-cell"><?php echo htmlspecialchars($p['requested_by_name'] ?? '—'); ?></td>
                    <td class="text-end fw-semibold"><?php echo formatCurrency($p['refund_amount'], $p['currency'], 2); ?></td>
                    <td class="text-end">
                        <?php $mineP = (int)$p['payout_requested_by'] === (int)getUserId(); ?>
                        <?php if ($isApprover && (!$mineP || ledgerAllowSelfApproval())): ?>
                        <button class="btn btn-sm btn-success" onclick="act('approve_payout', <?php echo (int)$p['id']; ?>)" title="<?php _e('led_approve', 'Approve'); ?>"><i class="bi bi-check-lg"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="rejectPayout(<?php echo (int)$p['id']; ?>)" title="<?php _e('led_reject', 'Reject'); ?>"><i class="bi bi-x-lg"></i></button>
                        <?php elseif ($mineP): ?>
                        <small class="text-muted"><?php _e('pay_yours', 'Awaiting someone else'); ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Unpaid expenses -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-receipt me-1"></i> <?php _e('pay_unpaid_expenses', 'Unpaid expenses'); ?></span>
        <small class="text-muted"><?php _e('pay_note', 'Recorded when incurred; paying needs approval'); ?></small>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:105px;"><?php _e('date'); ?></th>
                    <th><?php _e('description'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('property'); ?></th>
                    <th class="d-none d-lg-table-cell"><?php _e('pay_recorded_by', 'Recorded by'); ?></th>
                    <th class="text-end" style="width:140px;"><?php _e('amount'); ?></th>
                    <th style="width:110px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$expenses): ?>
                <tr><td colspan="6" class="text-center text-muted py-5">
                    <i class="bi bi-check2-circle d-block mb-2" style="font-size:2rem;opacity:.4;"></i>
                    <?php _e('pay_none', 'Nothing owed to suppliers'); ?>
                </td></tr>
                <?php endif; ?>
                <?php foreach ($expenses as $e): ?>
                <tr>
                    <td class="small"><?php echo formatDate($e['expense_date']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($e['description'] ?: '—'); ?>
                        <?php if ($e['vendor_name']): ?><br><small class="text-muted"><?php echo htmlspecialchars($e['vendor_name']); ?></small><?php endif; ?>
                    </td>
                    <td class="small text-muted d-none d-md-table-cell"><?php echo htmlspecialchars($e['property_name'] ?? '—'); ?></td>
                    <td class="small text-muted d-none d-lg-table-cell"><?php echo htmlspecialchars($e['recorded_by_name'] ?? '—'); ?></td>
                    <td class="text-end fw-semibold"><?php echo formatCurrency($e['amount'], $e['currency'], 2); ?></td>
                    <td class="text-end">
                        <?php // An approver should be able to see the invoice before paying it.
                              echo entityDocsButton('expense', $e['id'], __('doc_attach_invoice', 'Attach the invoice')); ?>
                        <?php $mineE = (int)$e['recorded_by'] === (int)getUserId(); ?>
                        <?php if ($isApprover && (!$mineE || ledgerAllowSelfApproval())): ?>
                        <button class="btn btn-sm btn-primary"
                                onclick='openPay(<?php echo json_encode([
                                    "id" => (int)$e["id"],
                                    "label" => ($e["description"] ?: "Expense #".$e["id"]),
                                    "amount" => formatCurrency($e["amount"], $e["currency"], 2),
                                    "currency" => $e["currency"],
                                ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <?php _e('pay_pay', 'Pay'); ?>
                        </button>
                        <?php elseif ($mineE && $isApprover): ?>
                        <small class="text-muted"><?php _e('pay_yours', 'Awaiting someone else'); ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($isApprover): ?>
<!-- Pay an expense -->
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form id="payForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="pay_expense">
      <input type="hidden" name="id" id="payId">
      <div class="modal-header">
        <h5 class="modal-title"><?php _e('pay_pay_expense', 'Pay expense'); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php _e('close', 'Close'); ?>"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1 fw-semibold" id="payLabel"></p>
        <p class="fs-5 mb-3" id="payAmount"></p>
        <div class="mb-2">
          <label class="form-label fw-semibold small"><?php _e('pay_source_full', 'Paid from'); ?> <span class="text-danger">*</span></label>
          <select name="account_id" id="paySource" class="form-select" required>
            <?php foreach ($sourceAccounts as $a): ?>
            <option value="<?php echo (int)$a['id']; ?>" data-currency="<?php echo htmlspecialchars($a['currency']); ?>">
              <?php echo htmlspecialchars($a['name'] . ' (' . $a['currency'] . ')'); ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$sourceAccounts): ?>
          <small class="text-danger"><?php _e('pay_no_source_account', 'Create a bank or cash account first, under Chart of Accounts.'); ?></small>
          <?php endif; ?>
        </div>
        <div>
          <label class="form-label fw-semibold small"><?php _e('pay_paid_date', 'Payment date'); ?></label>
          <input type="date" name="paid_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
        <button type="submit" class="btn btn-primary" <?php echo !$sourceAccounts ? 'disabled' : ''; ?>>
          <i class="bi bi-check-lg me-1"></i><?php _e('pay_confirm', 'Approve and pay'); ?>
        </button>
      </div>
    </form>
  </div></div>
</div>

<form id="actForm" class="d-none"><?php echo csrfField(); ?></form>

<script>
const API = '<?php echo BASE_URL; ?>api/ledger.php';

function openPay(e) {
    document.getElementById('payId').value = e.id;
    document.getElementById('payLabel').textContent = e.label;
    document.getElementById('payAmount').textContent = e.amount;
    // Only offer accounts in the expense's own currency — the ledger refuses
    // cross-currency entries, so showing the rest would only produce errors.
    Array.from(document.getElementById('paySource').options).forEach(o => {
        o.hidden = o.dataset.currency !== e.currency;
    });
    const first = Array.from(document.getElementById('paySource').options).find(o => !o.hidden);
    if (first) first.selected = true;
    new bootstrap.Modal(document.getElementById('payModal')).show();
}

document.getElementById('payForm').addEventListener('submit', function (ev) {
    ev.preventDefault();
    fetch(API, { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.message); })
        .catch(() => alert('Could not reach the server'));
});

function act(action, id, reason) {
    const fd = new FormData(document.getElementById('actForm'));
    fd.append('action', action);
    fd.append('id', id);
    if (reason) fd.append('reason', reason);
    fetch(API, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.message); })
        .catch(() => alert('Could not reach the server'));
}

function rejectPayout(id) {
    const reason = prompt('<?php echo addslashes(__('pay_reject_prompt', 'Why is this being rejected?')); ?>');
    if (reason === null) return;
    if (!reason.trim()) { alert('<?php echo addslashes(__('led_err_reason', 'A rejection reason is required')); ?>'); return; }
    act('reject_payout', id, reason.trim());
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
