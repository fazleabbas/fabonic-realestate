<?php
/**
 * FIS Real Estate — Account ledger (Part 33, Phase 4)
 *
 * Every posted movement on one account with a running balance. This is the
 * drill-down that makes the statements trustworthy: any figure on the P&L or
 * balance sheet opens up into the entries behind it.
 *
 * For a float account it also shows what has been declared but not yet
 * approved — money the collector has handed over on paper while the ledger
 * still says they are holding it.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/ledger_reports.php';

$subStatus = enforceSubscription();

if (!hasPermission('ledger', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id   = (int) ($_GET['id'] ?? 0);
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');

$isFloat = false;
$account = ledgerAccount($id);
if (!$account) {
    setAlert(__('rep_err_account', 'Account not found'), 'danger');
    redirect('modules/ledger/');
}
$isFloat = ($account['system_key'] === 'float');

// A collector may open their own float; anything else needs approval rights.
if ($isFloat && (int) $account['user_id'] !== (int) getUserId() && !ledgerCanApprove()) {
    setAlert(__('rep_err_not_yours', 'That float belongs to someone else'), 'danger');
    redirect('modules/ledger/floats.php');
}

$data = $isFloat
    ? floatStatement($id, $from ?: null, $to ?: null)
    : accountLedger($id, $from ?: null, $to ?: null);

$pageTitle = $account['name'];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4>
            <i class="bi bi-<?php echo $isFloat ? 'wallet2' : 'journal-text'; ?> me-2"></i>
            <?php echo htmlspecialchars($account['name']); ?>
        </h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/ledger/"><?php _e('led_ledger', 'Ledger'); ?></a></li>
                <?php if ($isFloat): ?>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/ledger/floats.php"><?php _e('flt_floats', 'Floats'); ?></a></li>
                <?php endif; ?>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($account['code'] ?: $account['name']); ?></li>
            </ol>
        </nav>
    </div>
    <button class="btn btn-outline-secondary btn-sm d-print-none" onclick="window.print()">
        <i class="bi bi-printer me-1"></i> <?php _e('print'); ?>
    </button>
</div>

<?php pageIntro('ledger.account'); ?>

<?php ledgerNav('accounts'); ?>

<div class="card mb-3 d-print-none">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1"><?php _e('date_from', 'From'); ?></label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($from); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1"><?php _e('date_to', 'To'); ?></label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($to); ?>">
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="?id=<?php echo (int)$id; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-cash"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($data['closing'], $account['currency'], 2); ?></div>
                <div class="stat-card-label"><?php echo $isFloat ? __('flt_held', 'held in hand') : __('led_balance', 'Balance'); ?></div>
            </div>
        </div>
    </div>
    <?php if ($isFloat): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-arrow-down"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($data['collected'], $account['currency'], 2); ?></div>
                <div class="stat-card-label"><?php _e('rep_collected', 'Collected'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#f3e8ff;color:#6b21a8;"><i class="bi bi-bank"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($data['remitted'], $account['currency'], 2); ?></div>
                <div class="stat-card-label"><?php _e('rep_remitted', 'Banked'); ?></div>
            </div>
        </div>
    </div>
    <?php if ($data['pending_remitted'] > 0): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="stat-card-value"><?php echo formatCurrency($data['pending_remitted'], $account['currency'], 2); ?></div>
                <div class="stat-card-label"><?php _e('rep_pending_remit', 'Declared, not yet approved'); ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <?php echo htmlspecialchars($account['code'] ? $account['code'] . ' · ' : ''); ?><?php echo htmlspecialchars($account['name']); ?>
            <small class="text-muted ms-1">(<?php echo htmlspecialchars($account['currency']); ?>)</small>
        </span>
        <small class="text-muted">
            <?php echo count($data['rows']); ?> <?php _e('rep_movements', 'movements'); ?>
            <?php if ($from || $to): ?> · <?php echo $from ? formatDate($from) : '…'; ?> – <?php echo $to ? formatDate($to) : '…'; ?><?php endif; ?>
        </small>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:105px;"><?php _e('date'); ?></th>
                    <th style="width:110px;"><?php _e('led_entry_no', 'Entry'); ?></th>
                    <th><?php _e('led_memo', 'Memo'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('property'); ?></th>
                    <th class="text-end" style="width:140px;"><?php _e('led_debit', 'Debit'); ?></th>
                    <th class="text-end" style="width:140px;"><?php _e('led_credit', 'Credit'); ?></th>
                    <th class="text-end" style="width:150px;"><?php _e('led_balance', 'Balance'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($from): ?>
                <tr class="table-light">
                    <td colspan="6" class="fw-semibold small"><?php _e('rep_opening', 'Opening balance'); ?></td>
                    <td class="text-end fw-semibold"><?php echo formatCurrency($data['opening'], $account['currency'], 2); ?></td>
                </tr>
                <?php endif; ?>

                <?php if (!$data['rows']): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">
                    <i class="bi bi-inbox d-block mb-2" style="font-size:2rem;opacity:.4;"></i>
                    <?php _e('rep_no_movements', 'No movements in this period'); ?>
                </td></tr>
                <?php endif; ?>

                <?php foreach ($data['rows'] as $r): ?>
                <tr>
                    <td class="small"><?php echo formatDate($r['entry_date']); ?></td>
                    <td class="font-monospace small">
                        <a href="<?php echo BASE_URL; ?>modules/ledger/view.php?id=<?php echo (int)$r['entry_id']; ?>">
                            <?php echo htmlspecialchars($r['entry_no']); ?>
                        </a>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($r['memo'] ?: $r['entry_memo'] ?: '—'); ?>
                        <?php if ($r['source_type'] !== 'manual'): ?>
                        <span class="badge bg-light text-secondary border ms-1"><?php echo htmlspecialchars(str_replace('_', ' ', $r['source_type'])); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted d-none d-md-table-cell"><?php echo htmlspecialchars($r['property_name'] ?? '—'); ?></td>
                    <td class="text-end"><?php echo (float)$r['debit']  > 0 ? formatCurrency($r['debit'],  $account['currency'], 2) : ''; ?></td>
                    <td class="text-end"><?php echo (float)$r['credit'] > 0 ? formatCurrency($r['credit'], $account['currency'], 2) : ''; ?></td>
                    <td class="text-end fw-semibold"><?php echo formatCurrency($r['running'], $account['currency'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="fw-bold border-top">
                    <td colspan="6" class="text-end"><?php _e('rep_closing', 'Closing balance'); ?></td>
                    <td class="text-end"><?php echo formatCurrency($data['closing'], $account['currency'], 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php if ($isFloat && $data['pending_remitted'] > 0): ?>
<p class="text-muted small mt-2">
    <i class="bi bi-info-circle me-1"></i>
    <?php printf(
        __('rep_float_note', 'This float still shows %s because %s has been declared as banked but not yet approved. Once approved, the balance drops to %s.'),
        formatCurrency($data['closing'], $account['currency'], 2),
        formatCurrency($data['pending_remitted'], $account['currency'], 2),
        formatCurrency($data['unremitted'], $account['currency'], 2)
    ); ?>
</p>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
