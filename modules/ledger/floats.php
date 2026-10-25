<?php
/**
 * FIS Real Estate — Collector floats and remittances (Part 31, Phase 2)
 *
 * A float is the cash a person is holding for the company. Collectors see
 * their own; approvers see everyone's.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/charges.php';
require_once __DIR__ . '/../../includes/entity_documents.php';
$pageTitle = __('flt_floats', 'Floats');

$subStatus = enforceSubscription();

if (!hasPermission('ledger', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// A collector sees only their own float; an approver sees them all.
$isApprover = ledgerCanApprove();
$floats     = $isApprover ? floatAccounts() : floatAccounts(getUserId());

$bankAccounts = ledgerAccounts(['type' => 'asset', 'subtype' => 'bank']);
$floatLimit   = (float) getSetting('float_limit', '0');

// Remittances, filtered the same way.
$remWhere = $isApprover ? '1=1' : 'r.requested_by = ' . (int) getUserId();
$remittances = $conn->query("
    SELECT r.*, fa.name AS float_name, ba.name AS bank_name,
           ru.full_name AS requested_by_name, au.full_name AS approved_by_name
    FROM remittances r
    LEFT JOIN accounts fa ON fa.id = r.float_account_id
    LEFT JOIN accounts ba ON ba.id = r.bank_account_id
    LEFT JOIN users ru ON ru.id = r.requested_by
    LEFT JOIN users au ON au.id = r.approved_by
    WHERE {$remWhere}
    ORDER BY FIELD(r.status,'pending','approved','rejected'), r.remitted_date DESC, r.id DESC
    LIMIT 100
")->fetchAll();

$statusColors = ['pending' => 'warning text-dark', 'approved' => 'success', 'rejected' => 'secondary'];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-wallet2 me-2"></i><?php _e('flt_floats', 'Floats'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/ledger/"><?php _e('led_ledger', 'Ledger'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('flt_floats', 'Floats'); ?></li>
            </ol>
        </nav>
    </div>
    <?php if ($floats): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#remitModal">
        <i class="bi bi-bank me-1"></i> <?php _e('flt_bank_cash', 'Bank cash'); ?>
    </button>
    <?php endif; ?>
</div>

<?php pageIntro('ledger.floats'); ?>

<?php ledgerNav('floats'); ?>

<?php if (!$floats): ?>
<div class="card"><div class="card-body text-center text-muted py-5">
    <i class="bi bi-wallet2" style="font-size:2.5rem;opacity:.4;"></i>
    <p class="mt-2 mb-0"><?php _e('flt_none', 'No float yet. A float is created the first time someone collects cash.'); ?></p>
</div></div>
<?php else: ?>

<div class="row g-3 mb-4">
    <?php foreach ($floats as $f): ?>
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($f['owner_name'] ?? $f['name']); ?></div>
                        <small class="text-muted"><?php echo htmlspecialchars($f['currency']); ?> · <?php _e('flt_held', 'held in hand'); ?></small>
                    </div>
                    <i class="bi bi-wallet2 fs-4 text-muted"></i>
                </div>
                <div class="fs-4 fw-bold mt-2"><?php echo formatCurrency($f['balance'], $f['currency'], 2); ?></div>
                <?php if ($floatLimit > 0 && $f['balance'] > $floatLimit): ?>
                <div class="badge bg-warning text-dark mt-1">
                    <i class="bi bi-exclamation-triangle me-1"></i><?php _e('flt_over_limit', 'Above the float limit'); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-bank me-1"></i> <?php _e('flt_remittances', 'Remittances'); ?></span>
        <small class="text-muted"><?php _e('flt_rem_note', 'The float only falls once a remittance is approved'); ?></small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:110px;"><?php _e('flt_ref', 'Reference'); ?></th>
                    <th style="width:105px;"><?php _e('date'); ?></th>
                    <th><?php _e('flt_from_to', 'From → To'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('flt_bank_ref', 'Bank ref'); ?></th>
                    <th class="text-end" style="width:140px;"><?php _e('amount'); ?></th>
                    <th style="width:100px;"><?php _e('status'); ?></th>
                    <th style="width:130px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$remittances): ?>
                <tr><td colspan="7" class="text-center text-muted py-4"><?php _e('flt_no_rem', 'No remittances yet'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($remittances as $r): ?>
                <tr>
                    <td class="font-monospace small"><?php echo htmlspecialchars($r['reference_no']); ?></td>
                    <td class="small"><?php echo formatDate($r['remitted_date']); ?></td>
                    <td class="small">
                        <?php echo htmlspecialchars($r['float_name']); ?>
                        <i class="bi bi-arrow-right mx-1 text-muted"></i>
                        <?php echo htmlspecialchars($r['bank_name']); ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($r['requested_by_name'] ?? ''); ?></small>
                    </td>
                    <td class="small text-muted d-none d-md-table-cell"><?php echo htmlspecialchars($r['bank_reference'] ?: '—'); ?></td>
                    <td class="text-end fw-semibold"><?php echo formatCurrency($r['amount'], $r['currency'], 2); ?></td>
                    <td>
                        <span class="badge bg-<?php echo $statusColors[$r['status']] ?? 'secondary'; ?>"><?php echo htmlspecialchars(ucfirst($r['status'])); ?></span>
                        <?php if ($r['status'] === 'rejected' && $r['rejection_reason']): ?>
                        <br><small class="text-muted" title="<?php echo htmlspecialchars($r['rejection_reason']); ?>"><?php echo htmlspecialchars(mb_substr($r['rejection_reason'], 0, 24)); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php // The deposit slip belongs on the remittance it evidences.
                              echo entityDocsButton('remittance', $r['id'], __('doc_attach_slip', 'Attach the deposit slip')); ?>
                        <?php
                        // Whether you may sign off your own filing is a setting now
                        // (part 38), so the button has to ask rather than assume.
                        $mine     = (int) $r['requested_by'] === (int) getUserId();
                        $canSign  = $isApprover && (!$mine || ledgerAllowSelfApproval());
                        ?>
                        <?php if ($r['status'] === 'pending' && $canSign): ?>
                        <button class="btn btn-sm btn-success" onclick="remAct('approve_remittance', <?php echo (int)$r['id']; ?>)" title="<?php _e('led_approve', 'Approve'); ?>"><i class="bi bi-check-lg"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="rejectRem(<?php echo (int)$r['id']; ?>)" title="<?php _e('led_reject', 'Reject'); ?>"><i class="bi bi-x-lg"></i></button>
                        <?php elseif ($r['status'] === 'pending' && $mine): ?>
                        <small class="text-muted"><?php _e('flt_yours', 'Awaiting someone else'); ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Bank cash -->
<div class="modal fade" id="remitModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form id="remitForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="create_remittance">
      <div class="modal-header">
        <h5 class="modal-title"><?php _e('flt_bank_cash', 'Bank cash'); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php _e('close', 'Close'); ?>"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted"><?php _e('flt_help', 'Record cash you have paid into the bank. Your float falls only once this is approved.'); ?></p>
        <div class="mb-2">
          <label class="form-label fw-semibold small"><?php _e('flt_from_float', 'From float'); ?> <span class="text-danger">*</span></label>
          <select name="float_account_id" id="remFloat" class="form-select" required>
            <?php foreach ($floats as $f): ?>
            <option value="<?php echo (int)$f['id']; ?>" data-balance="<?php echo (float)$f['balance']; ?>" data-currency="<?php echo htmlspecialchars($f['currency']); ?>">
              <?php echo htmlspecialchars(($f['owner_name'] ?? $f['name']) . ' — ' . formatCurrency($f['balance'], $f['currency'], 2)); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold small"><?php _e('flt_to_bank', 'Into bank account'); ?> <span class="text-danger">*</span></label>
          <select name="bank_account_id" id="remBank" class="form-select" required>
            <?php foreach ($bankAccounts as $b): ?>
            <option value="<?php echo (int)$b['id']; ?>" data-currency="<?php echo htmlspecialchars($b['currency']); ?>">
              <?php echo htmlspecialchars($b['name'] . ' (' . $b['currency'] . ')'); ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$bankAccounts): ?>
          <small class="text-danger"><?php _e('flt_no_bank', 'Create a bank account first, under Chart of Accounts.'); ?></small>
          <?php endif; ?>
        </div>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label fw-semibold small"><?php _e('amount'); ?> <span class="text-danger">*</span></label>
            <input type="number" step="0.01" min="0.01" name="amount" id="remAmount" class="form-control" required>
            <small class="text-muted" id="remHint"></small>
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold small"><?php _e('date'); ?> <span class="text-danger">*</span></label>
            <input type="date" name="remitted_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small"><?php _e('flt_bank_ref', 'Bank ref'); ?></label>
            <input type="text" name="bank_reference" class="form-control" maxlength="100" placeholder="<?php _e('flt_bank_ref_ph', 'Deposit slip number'); ?>">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small"><?php _e('notes'); ?></label>
            <input type="text" name="notes" class="form-control" maxlength="255">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
        <button type="submit" class="btn btn-primary" <?php echo !$bankAccounts ? 'disabled' : ''; ?>>
          <i class="bi bi-check-lg me-1"></i><?php _e('flt_submit', 'Submit for approval'); ?>
        </button>
      </div>
    </form>
  </div></div>
</div>

<form id="remActForm" class="d-none"><?php echo csrfField(); ?></form>

<script>
const API = '<?php echo BASE_URL; ?>api/ledger.php';

function syncHint() {
    const f = document.getElementById('remFloat').selectedOptions[0];
    if (!f) return;
    document.getElementById('remAmount').max = f.dataset.balance;
    document.getElementById('remHint').textContent =
        '<?php echo addslashes(__('flt_available', 'Available')); ?>: ' +
        Number(f.dataset.balance).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) +
        ' ' + f.dataset.currency;
}
document.getElementById('remFloat').addEventListener('change', syncHint);
syncHint();

document.getElementById('remitForm').addEventListener('submit', function (e) {
    e.preventDefault();
    fetch(API, { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.message); })
        .catch(() => alert('Could not reach the server'));
});

function remAct(action, id, reason) {
    const fd = new FormData(document.getElementById('remActForm'));
    fd.append('action', action);
    fd.append('id', id);
    if (reason) fd.append('reason', reason);
    fetch(API, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.message); })
        .catch(() => alert('Could not reach the server'));
}

function rejectRem(id) {
    const reason = prompt('<?php echo addslashes(__('flt_reject_prompt', 'Why is this remittance being rejected?')); ?>');
    if (reason === null) return;
    if (!reason.trim()) { alert('<?php echo addslashes(__('led_err_reason', 'A rejection reason is required')); ?>'); return; }
    remAct('reject_remittance', id, reason.trim());
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
