<?php
/**
 * FIS Real Estate — Journal Entry detail (Part 30, Phase 1)
 * Approve, reject, or reverse. A posted entry is never edited.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/ledger.php';

$subStatus = enforceSubscription();

if (!hasPermission('ledger', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int) ($_GET['id'] ?? 0);
$entry = ledgerEntry($id);
if (!$entry) {
    setAlert(__('led_err_not_found', 'Entry not found'), 'danger');
    redirect('modules/ledger/journal.php');
}
$pageTitle = $entry['entry_no'];

$canApprove   = ledgerCanApprove();
$isOwnEntry   = (int) $entry['created_by'] === (int) getUserId();
// Signing off your own entry is a tenant setting now (part 38).
$blockedOwn   = $isOwnEntry && !ledgerAllowSelfApproval();
$statusColors = ['pending' => 'warning text-dark', 'posted' => 'success', 'rejected' => 'secondary'];

// The reversing entry, or the entry this one reverses.
$related = null;
if (!empty($entry['reversed_by_entry_id'])) {
    $related = ledgerEntry($entry['reversed_by_entry_id']);
    $relatedLabel = __('led_reversed_by', 'Reversed by');
} elseif (!empty($entry['reverses_entry_id'])) {
    $related = ledgerEntry($entry['reverses_entry_id']);
    $relatedLabel = __('led_reverses', 'Reverses');
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4 class="font-monospace"><i class="bi bi-receipt me-2"></i><?php echo htmlspecialchars($entry['entry_no']); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/ledger/"><?php _e('led_ledger', 'Ledger'); ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/ledger/journal.php"><?php _e('led_journal', 'Journal'); ?></a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($entry['entry_no']); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <span class="badge bg-<?php echo $statusColors[$entry['status']] ?? 'secondary'; ?> fs-6"><?php echo ledgerStatusLabel($entry['status']); ?></span>

        <?php if ($entry['status'] === 'pending' && $canApprove && !$blockedOwn): ?>
        <button class="btn btn-success btn-sm" onclick="act('approve_entry')">
            <i class="bi bi-check-lg me-1"></i> <?php _e('led_approve', 'Approve'); ?>
        </button>
        <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal">
            <i class="bi bi-x-lg me-1"></i> <?php _e('led_reject', 'Reject'); ?>
        </button>
        <?php endif; ?>

        <?php if ($entry['status'] === 'posted' && empty($entry['reversed_by_entry_id']) && hasPermission('ledger', 'create')): ?>
        <button class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#reverseModal">
            <i class="bi bi-arrow-counterclockwise me-1"></i> <?php _e('led_reverse', 'Reverse'); ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('ledger.view'); ?>

<?php ledgerNav('journal'); ?>

<?php if ($entry['status'] === 'pending' && $blockedOwn): ?>
<div class="alert alert-secondary py-2 small">
    <i class="bi bi-info-circle me-1"></i><?php _e('led_own_entry_note', 'You created this entry, so someone else must approve it.'); ?>
</div>
<?php elseif ($entry['status'] === 'pending' && !$canApprove): ?>
<div class="alert alert-secondary py-2 small">
    <i class="bi bi-hourglass-split me-1"></i><?php _e('led_awaiting_approval', 'This entry is waiting for approval and does not affect any balance yet.'); ?>
</div>
<?php endif; ?>

<?php if ($entry['status'] === 'rejected' && $entry['rejection_reason']): ?>
<div class="alert alert-danger py-2">
    <strong><?php _e('led_rejected', 'Rejected'); ?>:</strong> <?php echo htmlspecialchars($entry['rejection_reason']); ?>
</div>
<?php endif; ?>

<?php if ($related): ?>
<div class="alert alert-warning py-2 small">
    <i class="bi bi-arrow-left-right me-1"></i>
    <?php echo htmlspecialchars($relatedLabel); ?>
    <a href="<?php echo BASE_URL; ?>modules/ledger/view.php?id=<?php echo (int)$related['id']; ?>" class="fw-semibold font-monospace"><?php echo htmlspecialchars($related['entry_no']); ?></a>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-list-ol me-1"></i> <?php _e('led_lines', 'Lines'); ?></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr>
                            <th><?php _e('led_account', 'Account'); ?></th>
                            <th class="d-none d-md-table-cell"><?php _e('property'); ?></th>
                            <th class="text-end" style="width:150px;"><?php _e('led_debit', 'Debit'); ?></th>
                            <th class="text-end" style="width:150px;"><?php _e('led_credit', 'Credit'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entry['lines'] as $l): ?>
                        <tr>
                            <td>
                                <?php if ($l['account_code']): ?><span class="text-muted font-monospace small me-1"><?php echo htmlspecialchars($l['account_code']); ?></span><?php endif; ?>
                                <?php echo htmlspecialchars($l['account_name']); ?>
                                <?php if ($l['memo']): ?><br><small class="text-muted"><?php echo htmlspecialchars($l['memo']); ?></small><?php endif; ?>
                            </td>
                            <td class="small text-muted d-none d-md-table-cell"><?php echo htmlspecialchars($l['property_name'] ?? '—'); ?></td>
                            <td class="text-end"><?php echo (float)$l['debit']  > 0 ? formatCurrency($l['debit'],  $entry['currency'], 2) : ''; ?></td>
                            <td class="text-end"><?php echo (float)$l['credit'] > 0 ? formatCurrency($l['credit'], $entry['currency'], 2) : ''; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-semibold border-top">
                            <td colspan="2" class="text-end d-none d-md-table-cell"><?php _e('led_totals', 'Totals'); ?></td>
                            <td class="text-end d-md-none"><?php _e('led_totals', 'Totals'); ?></td>
                            <td class="text-end"><?php echo formatCurrency($entry['total_debit'], $entry['currency'], 2); ?></td>
                            <td class="text-end"><?php echo formatCurrency($entry['total_credit'], $entry['currency'], 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> <?php _e('led_details', 'Details'); ?></div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width:120px;"><?php _e('date'); ?></td><td><?php echo formatDate($entry['entry_date']); ?></td></tr>
                    <tr><td class="text-muted"><?php _e('currency'); ?></td><td><?php echo htmlspecialchars($entry['currency']); ?></td></tr>
                    <tr><td class="text-muted"><?php _e('led_memo', 'Memo'); ?></td><td><?php echo htmlspecialchars($entry['memo'] ?: '—'); ?></td></tr>
                    <tr><td class="text-muted"><?php _e('led_source', 'Source'); ?></td><td><?php echo htmlspecialchars(str_replace('_', ' ', $entry['source_type'])); ?></td></tr>
                    <tr><td class="text-muted"><?php _e('led_created_by', 'Created by'); ?></td><td><?php echo htmlspecialchars($entry['created_by_name'] ?? '—'); ?><br><small class="text-muted"><?php echo formatDate($entry['created_at']); ?></small></td></tr>
                    <?php if ($entry['approved_by_name']): ?>
                    <tr>
                        <td class="text-muted"><?php echo $entry['status'] === 'rejected' ? __('led_rejected_by', 'Rejected by') : __('led_approved_by', 'Approved by'); ?></td>
                        <td><?php echo htmlspecialchars($entry['approved_by_name']); ?><br><small class="text-muted"><?php echo formatDate($entry['approved_at']); ?></small></td>
                    </tr>
                    <?php endif; ?>
                </table>
                <?php if ($entry['status'] === 'posted'): ?>
                <hr>
                <p class="small text-muted mb-0">
                    <i class="bi bi-lock me-1"></i><?php _e('led_immutable_note', 'Posted entries cannot be edited or deleted. Correct a mistake with a reversing entry.'); ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Reject -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title"><?php _e('led_reject_entry', 'Reject entry'); ?></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php _e('close', 'Close'); ?>"></button>
    </div>
    <div class="modal-body">
      <label class="form-label fw-semibold"><?php _e('led_reject_reason', 'Reason'); ?> <span class="text-danger">*</span></label>
      <textarea class="form-control" id="rejectReason" rows="3" maxlength="255" required></textarea>
      <small class="text-muted"><?php _e('led_reject_help', 'The reason is recorded against the entry.'); ?></small>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
      <button type="button" class="btn btn-danger" onclick="act('reject_entry')"><?php _e('led_reject', 'Reject'); ?></button>
    </div>
  </div></div>
</div>

<!-- Reverse -->
<div class="modal fade" id="reverseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title"><?php _e('led_reverse_entry', 'Reverse entry'); ?></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php _e('close', 'Close'); ?>"></button>
    </div>
    <div class="modal-body">
      <p class="small text-muted"><?php _e('led_reverse_help', 'This creates a mirror entry that cancels this one. The original stays exactly as it is. The reversal needs approval like any other entry.'); ?></p>
      <label class="form-label fw-semibold"><?php _e('date'); ?></label>
      <input type="date" class="form-control mb-2" id="reverseDate" value="<?php echo date('Y-m-d'); ?>">
      <label class="form-label fw-semibold"><?php _e('led_memo', 'Memo'); ?></label>
      <input type="text" class="form-control" id="reverseMemo" maxlength="255"
             placeholder="<?php echo htmlspecialchars(sprintf(__('led_reversal_of', 'Reversal of %s'), $entry['entry_no'])); ?>">
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
      <button type="button" class="btn btn-warning" onclick="act('reverse_entry')"><?php _e('led_reverse', 'Reverse'); ?></button>
    </div>
  </div></div>
</div>

<form id="actForm" class="d-none"><?php echo csrfField(); ?></form>

<script>
function act(action) {
    const fd = new FormData(document.getElementById('actForm'));
    fd.append('action', action);
    fd.append('id', <?php echo (int)$entry['id']; ?>);

    if (action === 'reject_entry') {
        const reason = document.getElementById('rejectReason').value.trim();
        if (!reason) { alert('<?php echo addslashes(__('led_err_reason', 'A rejection reason is required')); ?>'); return; }
        fd.append('reason', reason);
    }
    if (action === 'reverse_entry') {
        fd.append('date', document.getElementById('reverseDate').value);
        fd.append('memo', document.getElementById('reverseMemo').value);
    }

    fetch('<?php echo BASE_URL; ?>api/ledger.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { alert(d.message); return; }
            location.href = (action === 'reverse_entry' && d.id)
                ? '<?php echo BASE_URL; ?>modules/ledger/view.php?id=' + d.id
                : location.href;
        })
        .catch(() => alert('Could not reach the server'));
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
