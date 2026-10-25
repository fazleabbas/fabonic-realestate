<?php
/**
 * FIS Real Estate — Trial Balance (Part 30, Phase 1)
 *
 * Per currency, as decided during alignment: accounts are single-currency and
 * each currency balances on its own. No conversion happens here.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/ledger.php';
$pageTitle = __('led_trial_balance', 'Trial Balance');

$subStatus = enforceSubscription();

if (!hasPermission('ledger', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$currencies = ledgerCurrencies();
$currency   = trim($_GET['currency'] ?? '');
if (!in_array($currency, $currencies, true)) $currency = $currencies[0] ?? DEFAULT_CURRENCY;
$asOf = trim($_GET['as_of'] ?? '');

$tb = ledgerTrialBalance($currency, $asOf ?: null);

$typeLabels = [
    'asset'     => __('led_asset', 'Assets'),
    'liability' => __('led_liability', 'Liabilities'),
    'equity'    => __('led_equity', 'Equity'),
    'income'    => __('led_income', 'Income'),
    'expense'   => __('led_expense', 'Expenses'),
];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-clipboard-data me-2"></i><?php _e('led_trial_balance', 'Trial Balance'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/ledger/"><?php _e('led_ledger', 'Ledger'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('led_trial_balance', 'Trial Balance'); ?></li>
            </ol>
        </nav>
    </div>
    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
        <i class="bi bi-printer me-1"></i> <?php _e('print'); ?>
    </button>
</div>

<?php pageIntro('ledger.trial-balance'); ?>

<?php ledgerNav('trial'); ?>

<div class="card mb-3 d-print-none">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1"><?php _e('currency'); ?></label>
                <select name="currency" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($currencies as $c): ?>
                    <option value="<?php echo $c; ?>" <?php echo $currency === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1"><?php _e('led_as_of', 'As at'); ?></label>
                <input type="date" name="as_of" class="form-control form-control-sm" value="<?php echo htmlspecialchars($asOf); ?>">
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-arrow-repeat me-1"></i><?php _e('led_refresh', 'Refresh'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/ledger/trial-balance.php?currency=<?php echo urlencode($currency); ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="alert <?php echo $tb['balanced'] ? 'alert-success' : 'alert-danger'; ?> py-2">
    <i class="bi bi-<?php echo $tb['balanced'] ? 'check-circle' : 'exclamation-triangle'; ?> me-1"></i>
    <?php if ($tb['balanced']): ?>
        <?php _e('led_tb_balanced', 'Debits equal credits.'); ?>
    <?php else: ?>
        <strong><?php _e('led_tb_unbalanced', 'This ledger does not balance.'); ?></strong>
        <?php _e('led_tb_unbalanced_help', 'Every entry is validated on save, so a difference here means data was changed outside the application.'); ?>
    <?php endif; ?>
    <span class="ms-2 text-muted small">
        <?php echo htmlspecialchars($currency); ?><?php echo $asOf ? ' · ' . __('led_as_of', 'As at') . ' ' . formatDate($asOf) : ''; ?>
    </span>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th style="width:90px;"><?php _e('led_code', 'Code'); ?></th>
                    <th><?php _e('led_account', 'Account'); ?></th>
                    <th class="text-end" style="width:170px;"><?php _e('led_debit', 'Debit'); ?></th>
                    <th class="text-end" style="width:170px;"><?php _e('led_credit', 'Credit'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $currentType = null;
                $anyRows = false;
                foreach ($tb['rows'] as $r):
                    if ($r['net_debit'] == 0 && $r['net_credit'] == 0) continue;
                    $anyRows = true;
                    if ($r['type'] !== $currentType):
                        $currentType = $r['type'];
                ?>
                <tr class="table-light">
                    <td colspan="4" class="fw-semibold small text-uppercase text-muted"><?php echo htmlspecialchars($typeLabels[$r['type']] ?? $r['type']); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="text-muted small font-monospace"><?php echo htmlspecialchars($r['code'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($r['name']); ?></td>
                    <td class="text-end"><?php echo $r['net_debit']  > 0 ? formatCurrency($r['net_debit'],  $currency, 2) : ''; ?></td>
                    <td class="text-end"><?php echo $r['net_credit'] > 0 ? formatCurrency($r['net_credit'], $currency, 2) : ''; ?></td>
                </tr>
                <?php endforeach; ?>

                <?php if (!$anyRows): ?>
                <tr><td colspan="4" class="text-center text-muted py-5">
                    <i class="bi bi-clipboard d-block mb-2" style="font-size:2rem;opacity:.4;"></i>
                    <?php _e('led_tb_empty', 'Nothing posted in this currency yet'); ?>
                </td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="fw-semibold border-top">
                    <td colspan="2" class="text-end"><?php _e('led_totals', 'Totals'); ?></td>
                    <td class="text-end"><?php echo formatCurrency($tb['total_debit'],  $currency, 2); ?></td>
                    <td class="text-end"><?php echo formatCurrency($tb['total_credit'], $currency, 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<p class="text-muted small mt-2">
    <i class="bi bi-info-circle me-1"></i>
    <?php _e('led_tb_note', 'Only posted entries are included. Entries awaiting approval affect nothing until approved.'); ?>
</p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
