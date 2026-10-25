<?php
/**
 * FIS Real Estate — Financial statements (Part 33, Phase 4)
 *
 * Profit & loss, balance sheet and aged debtors on one page, switched by tab,
 * because they are read together — you check the profit, then what backs it,
 * then who still owes you.
 *
 * Every figure comes from posted journal lines, so a statement can never
 * disagree with the ledger behind it.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/ledger_reports.php';
$pageTitle = __('rep_statements', 'Statements');

$subStatus = enforceSubscription();

if (!hasPermission('ledger', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$tab        = in_array($_GET['tab'] ?? '', ['pl', 'bs', 'aged', 'unit'], true) ? $_GET['tab'] : 'pl';
$currencies = ledgerActiveCurrencies();
$currency   = in_array($_GET['currency'] ?? '', $currencies, true) ? $_GET['currency'] : ($currencies[0] ?? DEFAULT_CURRENCY);
$from       = trim($_GET['from'] ?? date('Y-01-01'));
$to         = trim($_GET['to'] ?? date('Y-m-d'));
$asOf       = trim($_GET['as_of'] ?? date('Y-m-d'));
$propertyId = (int) ($_GET['property_id'] ?? 0);

$properties = $conn->query('SELECT id, name FROM properties WHERE status = \'active\''
                . propertyScopeSql('id') . ' ORDER BY name')->fetchAll();

$pl   = $tab === 'pl'   ? plStatement(['currency' => $currency, 'from' => $from, 'to' => $to, 'property_id' => $propertyId ?: null]) : null;
$bs   = $tab === 'bs'   ? balanceSheet($asOf, $currency) : null;
$aged = $tab === 'aged' ? agedDebtors($asOf, $currency) : null;
$unit = $tab === 'unit' ? unitProfitability(['currency' => $currency, 'from' => $from, 'to' => $to, 'property_id' => $propertyId ?: null]) : null;

/** Keep the other filters when switching tab. */
function tabUrl($tab) {
    $qs = $_GET;
    $qs['tab'] = $tab;
    return BASE_URL . 'modules/ledger/statements.php?' . http_build_query($qs);
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-file-earmark-bar-graph me-2"></i><?php _e('rep_statements', 'Statements'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/ledger/"><?php _e('led_ledger', 'Ledger'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('rep_statements', 'Statements'); ?></li>
            </ol>
        </nav>
    </div>
    <button class="btn btn-outline-secondary btn-sm d-print-none" onclick="window.print()">
        <i class="bi bi-printer me-1"></i> <?php _e('print'); ?>
    </button>
</div>

<?php pageIntro('ledger.statements'); ?>

<?php ledgerNav('statements'); ?>

<ul class="nav nav-tabs mb-3 d-print-none">
    <li class="nav-item"><a class="nav-link <?php echo $tab==='pl'?'active':''; ?>"   href="<?php echo htmlspecialchars(tabUrl('pl')); ?>"><i class="bi bi-graph-up-arrow me-1"></i><?php _e('rep_pl', 'Profit &amp; Loss'); ?></a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab==='bs'?'active':''; ?>"   href="<?php echo htmlspecialchars(tabUrl('bs')); ?>"><i class="bi bi-scale me-1"></i><?php _e('rep_bs', 'Balance Sheet'); ?></a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab==='aged'?'active':''; ?>" href="<?php echo htmlspecialchars(tabUrl('aged')); ?>"><i class="bi bi-hourglass-bottom me-1"></i><?php _e('rep_aged', 'Aged Debtors'); ?></a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab==='unit'?'active':''; ?>" href="<?php echo htmlspecialchars(tabUrl('unit')); ?>"><i class="bi bi-building-gear me-1"></i><?php _e('rep_unit_pl', 'Unit Profitability'); ?></a></li>
</ul>

<div class="card mb-3 d-print-none">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1"><?php _e('currency'); ?></label>
                <select name="currency" class="form-select form-select-sm">
                    <?php foreach ($currencies as $c): ?>
                    <option value="<?php echo $c; ?>" <?php echo $currency === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($tab === 'pl' || $tab === 'unit'): ?>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1"><?php _e('date_from', 'From'); ?></label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($from); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1"><?php _e('date_to', 'To'); ?></label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($to); ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1"><?php _e('property'); ?></label>
                <select name="property_id" class="form-select form-select-sm">
                    <option value=""><?php _e('rep_all_properties', 'All properties'); ?></option>
                    <?php foreach ($properties as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>" <?php echo $propertyId === (int)$p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1"><?php _e('led_as_of', 'As at'); ?></label>
                <input type="date" name="as_of" class="form-control form-control-sm" value="<?php echo htmlspecialchars($asOf); ?>">
            </div>
            <?php endif; ?>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-arrow-repeat me-1"></i><?php _e('led_refresh', 'Refresh'); ?></button>
            </div>
        </form>
    </div>
</div>

<?php if ($tab === 'pl'): ?>
<!-- ============ Profit & Loss ============ -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-graph-up-arrow me-1"></i> <?php _e('rep_pl', 'Profit &amp; Loss'); ?></span>
        <small class="text-muted">
            <?php echo htmlspecialchars($currency); ?> · <?php echo formatDate($from); ?> – <?php echo formatDate($to); ?>
            <?php if ($propertyId): ?> · <?php echo htmlspecialchars(array_column($properties,'name','id')[$propertyId] ?? ''); ?><?php endif; ?>
        </small>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <tbody>
                <tr class="table-light"><td colspan="2" class="fw-semibold text-uppercase small text-muted"><?php _e('led_income', 'Income'); ?></td></tr>
                <?php if (!$pl['income']): ?>
                <tr><td colspan="2" class="text-muted small ps-4"><?php _e('rep_none', 'Nothing in this period'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($pl['income'] as $r): ?>
                <tr>
                    <td class="ps-4">
                        <a href="<?php echo BASE_URL; ?>modules/ledger/account.php?id=<?php echo (int)$r['id']; ?>&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>">
                            <?php echo htmlspecialchars($r['name']); ?>
                        </a>
                    </td>
                    <td class="text-end" style="width:180px;"><?php echo formatCurrency($r['amount'], $currency, 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="fw-semibold border-top">
                    <td class="ps-4"><?php _e('rep_total_income', 'Total income'); ?></td>
                    <td class="text-end"><?php echo formatCurrency($pl['total_income'], $currency, 2); ?></td>
                </tr>

                <tr class="table-light"><td colspan="2" class="fw-semibold text-uppercase small text-muted pt-3"><?php _e('led_expense', 'Expenses'); ?></td></tr>
                <?php if (!$pl['expense']): ?>
                <tr><td colspan="2" class="text-muted small ps-4"><?php _e('rep_none', 'Nothing in this period'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($pl['expense'] as $r): ?>
                <tr>
                    <td class="ps-4">
                        <a href="<?php echo BASE_URL; ?>modules/ledger/account.php?id=<?php echo (int)$r['id']; ?>&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>">
                            <?php echo htmlspecialchars($r['name']); ?>
                        </a>
                    </td>
                    <td class="text-end"><?php echo formatCurrency($r['amount'], $currency, 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="fw-semibold border-top">
                    <td class="ps-4"><?php _e('rep_total_expense', 'Total expenses'); ?></td>
                    <td class="text-end"><?php echo formatCurrency($pl['total_expense'], $currency, 2); ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="fw-bold border-top" style="font-size:1.05rem;">
                    <td><?php echo $pl['net'] >= 0 ? __('rep_net_profit', 'Net profit') : __('rep_net_loss', 'Net loss'); ?></td>
                    <td class="text-end <?php echo $pl['net'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatCurrency(abs($pl['net']), $currency, 2); ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<p class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i><?php _e('rep_pl_note', 'Income is recognised when it falls due and expenses when incurred, not when the money moves.'); ?></p>

<?php elseif ($tab === 'bs'): ?>
<!-- ============ Balance sheet ============ -->
<div class="alert <?php echo $bs['balanced'] ? 'alert-success' : 'alert-danger'; ?> py-2">
    <i class="bi bi-<?php echo $bs['balanced'] ? 'check-circle' : 'exclamation-triangle'; ?> me-1"></i>
    <?php if ($bs['balanced']): ?>
        <?php _e('rep_bs_balanced', 'Assets equal liabilities plus equity.'); ?>
    <?php else: ?>
        <strong><?php _e('rep_bs_unbalanced', 'This balance sheet does not balance.'); ?></strong>
        <?php _e('rep_bs_unbalanced_help', 'Every entry is validated on save, so a difference means data was changed outside the application.'); ?>
    <?php endif; ?>
    <span class="ms-2 text-muted small"><?php echo htmlspecialchars($currency); ?> · <?php _e('led_as_of', 'As at'); ?> <?php echo formatDate($asOf); ?></span>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-box-seam me-1"></i> <?php _e('led_asset', 'Assets'); ?></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php foreach ($bs['assets'] as $r): ?>
                        <tr>
                            <td><a href="<?php echo BASE_URL; ?>modules/ledger/account.php?id=<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></a></td>
                            <td class="text-end" style="width:170px;"><?php echo formatCurrency($r['amount'], $currency, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$bs['assets']): ?><tr><td class="text-muted small"><?php _e('rep_none', 'Nothing in this period'); ?></td><td></td></tr><?php endif; ?>
                    </tbody>
                    <tfoot><tr class="fw-bold border-top">
                        <td><?php _e('rep_total_assets', 'Total assets'); ?></td>
                        <td class="text-end"><?php echo formatCurrency($bs['total_assets'], $currency, 2); ?></td>
                    </tr></tfoot>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-arrow-down-left-square me-1"></i> <?php _e('led_liability', 'Liabilities'); ?></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php foreach ($bs['liabilities'] as $r): ?>
                        <tr>
                            <td><a href="<?php echo BASE_URL; ?>modules/ledger/account.php?id=<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></a></td>
                            <td class="text-end" style="width:170px;"><?php echo formatCurrency($r['amount'], $currency, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$bs['liabilities']): ?><tr><td class="text-muted small"><?php _e('rep_none', 'Nothing in this period'); ?></td><td></td></tr><?php endif; ?>
                    </tbody>
                    <tfoot><tr class="fw-semibold border-top">
                        <td><?php _e('rep_total_liabilities', 'Total liabilities'); ?></td>
                        <td class="text-end"><?php echo formatCurrency($bs['total_liabilities'], $currency, 2); ?></td>
                    </tr></tfoot>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="bi bi-pie-chart me-1"></i> <?php _e('led_equity', 'Equity'); ?></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php foreach ($bs['equity'] as $r): ?>
                        <tr>
                            <td>
                                <?php if (!empty($r['computed'])): ?>
                                <?php echo htmlspecialchars($r['name']); ?>
                                <small class="text-muted d-block"><?php _e('rep_computed', 'Computed from income and expenses to date'); ?></small>
                                <?php else: ?>
                                <a href="<?php echo BASE_URL; ?>modules/ledger/account.php?id=<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></a>
                                <?php endif; ?>
                            </td>
                            <td class="text-end" style="width:170px;"><?php echo formatCurrency($r['amount'], $currency, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$bs['equity']): ?><tr><td class="text-muted small"><?php _e('rep_none', 'Nothing in this period'); ?></td><td></td></tr><?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-semibold border-top">
                            <td><?php _e('rep_total_equity', 'Total equity'); ?></td>
                            <td class="text-end"><?php echo formatCurrency($bs['total_equity'], $currency, 2); ?></td>
                        </tr>
                        <tr class="fw-bold">
                            <td><?php _e('rep_total_le', 'Liabilities + equity'); ?></td>
                            <td class="text-end"><?php echo formatCurrency($bs['total_liabilities'] + $bs['total_equity'], $currency, 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'aged'): ?>
<!-- ============ Aged debtors ============ -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-hourglass-bottom me-1"></i> <?php _e('rep_aged', 'Aged Debtors'); ?></span>
        <small class="text-muted"><?php echo htmlspecialchars($currency); ?> · <?php _e('led_as_of', 'As at'); ?> <?php echo formatDate($asOf); ?></small>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th><?php _e('tenant'); ?></th>
                    <th class="d-none d-md-table-cell"><?php _e('unit'); ?></th>
                    <th class="text-end"><?php _e('rep_b0', 'Current'); ?></th>
                    <th class="text-end"><?php _e('rep_b31', '31–60'); ?></th>
                    <th class="text-end"><?php _e('rep_b61', '61–90'); ?></th>
                    <th class="text-end"><?php _e('rep_b90', 'Over 90'); ?></th>
                    <th class="text-end"><?php _e('rep_total', 'Total'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$aged['rows']): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">
                    <i class="bi bi-check2-circle d-block mb-2" style="font-size:2rem;opacity:.4;"></i>
                    <?php _e('rep_no_debtors', 'Nobody is in arrears'); ?>
                </td></tr>
                <?php endif; ?>
                <?php foreach ($aged['rows'] as $r): ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars($r['tenant_name'] ?: '—'); ?>
                        <?php if ($r['phone']): ?><br><small class="text-muted"><?php echo htmlspecialchars($r['phone']); ?></small><?php endif; ?>
                    </td>
                    <td class="small text-muted d-none d-md-table-cell"><?php echo htmlspecialchars($r['units'] ?: '—'); ?></td>
                    <td class="text-end"><?php echo (float)$r['b0']  > 0 ? formatCurrency($r['b0'],  $currency, 2) : ''; ?></td>
                    <td class="text-end"><?php echo (float)$r['b31'] > 0 ? formatCurrency($r['b31'], $currency, 2) : ''; ?></td>
                    <td class="text-end text-warning"><?php echo (float)$r['b61'] > 0 ? formatCurrency($r['b61'], $currency, 2) : ''; ?></td>
                    <td class="text-end text-danger fw-semibold"><?php echo (float)$r['b90'] > 0 ? formatCurrency($r['b90'], $currency, 2) : ''; ?></td>
                    <td class="text-end fw-semibold"><?php echo formatCurrency($r['total'], $currency, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="fw-bold border-top">
                    <td colspan="2" class="text-end d-none d-md-table-cell"><?php _e('led_totals', 'Totals'); ?></td>
                    <td class="d-md-none"><?php _e('led_totals', 'Totals'); ?></td>
                    <td class="text-end"><?php echo formatCurrency($aged['totals']['b0'],  $currency, 2); ?></td>
                    <td class="text-end"><?php echo formatCurrency($aged['totals']['b31'], $currency, 2); ?></td>
                    <td class="text-end"><?php echo formatCurrency($aged['totals']['b61'], $currency, 2); ?></td>
                    <td class="text-end"><?php echo formatCurrency($aged['totals']['b90'], $currency, 2); ?></td>
                    <td class="text-end"><?php echo formatCurrency($aged['totals']['total'], $currency, 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<p class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i><?php _e('rep_aged_note', 'Buckets count days since each charge fell due. Partly paid charges show only what is still owed.'); ?></p>

<?php elseif ($tab === 'unit'): ?>

<?php if (!$unit['properties']): ?>
<div class="alert alert-light border"><?php _e('rep_none', 'Nothing in this period'); ?></div>
<?php endif; ?>

<?php foreach ($unit['properties'] as $p): ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-building me-1"></i><strong><?php echo htmlspecialchars($p['property_name']); ?></strong></span>
        <span class="small text-muted">
            <?php if ($p['shared_cost'] != 0): ?>
                <?php _e('rep_up_shared', 'Shared costs'); ?>:
                <strong><?php echo formatCurrency($p['shared_cost'], $currency, 2); ?></strong>
                <?php if ($p['basis'] === 'equal'): ?>
                    <span class="badge bg-warning text-dark ms-1"><?php _e('rep_up_equal', 'split equally'); ?></span>
                <?php else: ?>
                    <span class="badge bg-light text-dark border ms-1"><?php _e('rep_up_by_rent', 'by rent share'); ?></span>
                <?php endif; ?>
            <?php else: ?>
                <?php _e('rep_up_no_shared', 'No shared costs in this period'); ?>
            <?php endif; ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th><?php _e('unit'); ?></th>
                    <th class="text-end"><?php _e('rep_up_income', 'Income'); ?></th>
                    <th class="text-end d-none d-md-table-cell"><?php _e('rep_up_direct', 'Direct cost'); ?></th>
                    <th class="text-end d-none d-md-table-cell"><?php _e('rep_up_apportioned', 'Share of shared'); ?></th>
                    <th class="text-end"><?php _e('rep_up_total_cost', 'Total cost'); ?></th>
                    <th class="text-end"><?php _e('rep_up_net', 'Net'); ?></th>
                    <th class="text-end d-none d-lg-table-cell"><?php _e('rep_up_margin', 'Margin'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($p['units'] as $u): ?>
                <tr>
                    <td>
                        <a href="<?php echo BASE_URL; ?>modules/units/view.php?id=<?php echo (int)$u['unit_id']; ?>">
                            <?php echo htmlspecialchars($u['unit_number']); ?></a>
                        <?php if ($u['status'] === 'vacant'): ?>
                            <span class="badge bg-secondary ms-1"><?php _e('vacant', 'Vacant'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?php echo formatCurrency($u['income'], $currency, 2); ?></td>
                    <td class="text-end d-none d-md-table-cell text-muted"><?php echo formatCurrency($u['direct_cost'], $currency, 2); ?></td>
                    <td class="text-end d-none d-md-table-cell text-muted"><?php echo formatCurrency($u['apportioned'], $currency, 2); ?></td>
                    <td class="text-end"><?php echo formatCurrency($u['total_cost'], $currency, 2); ?></td>
                    <td class="text-end fw-semibold <?php echo $u['net'] < 0 ? 'text-danger' : ''; ?>">
                        <?php echo formatCurrency($u['net'], $currency, 2); ?></td>
                    <td class="text-end d-none d-lg-table-cell text-muted small">
                        <?php echo $u['margin'] === null ? '—' : $u['margin'] . '%'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-semibold">
                <tr>
                    <td><?php _e('total', 'Total'); ?></td>
                    <td class="text-end"><?php echo formatCurrency($p['totals']['income'], $currency, 2); ?></td>
                    <td class="text-end d-none d-md-table-cell"><?php echo formatCurrency($p['totals']['direct_cost'], $currency, 2); ?></td>
                    <td class="text-end d-none d-md-table-cell"><?php echo formatCurrency($p['totals']['apportioned'], $currency, 2); ?></td>
                    <td class="text-end"><?php echo formatCurrency($p['totals']['total_cost'], $currency, 2); ?></td>
                    <td class="text-end <?php echo $p['totals']['net'] < 0 ? 'text-danger' : ''; ?>">
                        <?php echo formatCurrency($p['totals']['net'], $currency, 2); ?></td>
                    <td class="d-none d-lg-table-cell"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php if ($unit['unallocated']['income'] != 0 || $unit['unallocated']['expense'] != 0): ?>
<div class="alert alert-warning small">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <strong><?php _e('rep_up_unallocated', 'Not attributed to any property'); ?>:</strong>
    <?php _e('rep_up_income', 'Income'); ?> <?php echo formatCurrency($unit['unallocated']['income'], $currency, 2); ?>,
    <?php _e('rep_up_direct', 'Direct cost'); ?> <?php echo formatCurrency($unit['unallocated']['expense'], $currency, 2); ?>.
    <?php _e('rep_up_unallocated_help', 'These are shown here rather than pushed onto units, which is why the totals above are lower than the Profit &amp; Loss for the same period.'); ?>
</div>
<?php endif; ?>

<p class="text-muted small mt-2">
    <i class="bi bi-info-circle me-1"></i>
    <?php _e('rep_up_note', 'Costs recorded against a property but no unit — a roof, a guard, the water main — are shared out in proportion to each unit\'s rent. Vacant units are included and carry their share, so their loss is what the vacancy is costing you. The share uses each unit\'s current rent, so changing a rent also changes past periods.'); ?>
</p>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
