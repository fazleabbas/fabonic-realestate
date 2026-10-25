<?php
/**
 * FIS Real Estate — Gateway Transactions Log (Part 21.5)
 * View all gateway transactions, filter, manual reconciliation, CSV export.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('gw_transactions', 'Gateway Transactions');

$subStatus = enforceSubscription();

if (!hasTier('enterprise')) {
    setAlert(__('gw_tier_required'), 'warning');
    redirect('index.php');
}
if (getSetting('payment_gateway_enabled', '0') !== '1') {
    setAlert(__('gw_disabled'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('payments', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Filters
$page = max(1, (int)($_GET['page'] ?? 1));
$filterGateway = trim($_GET['gateway_name'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterReconciled = $_GET['reconciled'] ?? '';
$filterSearch = trim($_GET['search'] ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');

// Stats (property-scoped by unit)
$gtScope = unitScopeSql('unit_id');
$totalTx = (int)$conn->query("SELECT COUNT(*) FROM gateway_transactions WHERE 1=1" . $gtScope)->fetchColumn();
$pendingTx = (int)$conn->query("SELECT COUNT(*) FROM gateway_transactions WHERE status IN ('initiated','pending')" . $gtScope)->fetchColumn();
$completedTx = (int)$conn->query("SELECT COUNT(*) FROM gateway_transactions WHERE status = 'completed'" . $gtScope)->fetchColumn();
$unreconciledTx = (int)$conn->query("SELECT COUNT(*) FROM gateway_transactions WHERE status = 'completed' AND reconciled = 0" . $gtScope)->fetchColumn();

// Build query
$where = "1=1";
$params = [];

if ($filterGateway) { $where .= " AND gt.gateway_name = :gn"; $params['gn'] = $filterGateway; }
if ($filterStatus) { $where .= " AND gt.status = :status"; $params['status'] = $filterStatus; }
if ($filterReconciled !== '') { $where .= " AND gt.reconciled = :rec"; $params['rec'] = (int)$filterReconciled; }
if ($filterSearch) {
    $where .= " AND (gt.external_reference LIKE :s1 OR gt.transaction_id LIKE :s2 OR gt.phone_number LIKE :s3 OR t.first_name LIKE :s4 OR t.last_name LIKE :s5)";
    $params['s1'] = "%$filterSearch%";
    $params['s2'] = "%$filterSearch%";
    $params['s3'] = "%$filterSearch%";
    $params['s4'] = "%$filterSearch%";
    $params['s5'] = "%$filterSearch%";
}
if ($filterDateFrom) { $where .= " AND DATE(gt.created_at) >= :df"; $params['df'] = $filterDateFrom; }
if ($filterDateTo) { $where .= " AND DATE(gt.created_at) <= :dt"; $params['dt'] = $filterDateTo; }

// Property-based access scoping (by unit)
$where .= unitScopeSql('gt.unit_id');

$countStmt = $conn->prepare("
    SELECT COUNT(*) FROM gateway_transactions gt
    LEFT JOIN tenants t ON gt.tenant_id = t.id
    WHERE $where
");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pagination = paginate($total, $page);

$sql = "SELECT gt.*, t.first_name, t.last_name, t.phone AS tenant_phone,
               u.unit_number, pr.name AS property_name,
               gc.display_name AS gateway_display, gc.logo_icon
        FROM gateway_transactions gt
        LEFT JOIN tenants t ON gt.tenant_id = t.id
        LEFT JOIN units u ON gt.unit_id = u.id
        LEFT JOIN properties pr ON u.property_id = pr.id
        LEFT JOIN gateway_config gc ON gt.gateway_name = gc.gateway_name
        WHERE $where
        ORDER BY gt.created_at DESC
        LIMIT :offset, :limit";
$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $pagination['records_per_page'], PDO::PARAM_INT);
$stmt->execute();
$transactions = $stmt->fetchAll();

// Gateways for filter dropdown
$gateways = $conn->query("SELECT gateway_name, display_name FROM gateway_config ORDER BY display_name")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-list-check me-2"></i><?php _e('gw_transactions', 'Gateway Transactions'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/payments/"><?php _e('payments'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('gw_transactions', 'Transactions'); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-1 flex-wrap">
        <?php if ($unreconciledTx > 0 && hasPermission('payments', 'edit')): ?>
        <button class="btn btn-sm btn-success" onclick="autoReconcile()">
            <i class="bi bi-arrow-repeat me-1"></i><?php _e('gw_auto_reconcile_btn', 'Auto-Reconcile'); ?> (<?php echo $unreconciledTx; ?>)
        </button>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>api/payment-gateway.php?action=export_transactions&gateway_name=<?php echo urlencode($filterGateway); ?>&status=<?php echo urlencode($filterStatus); ?>&date_from=<?php echo urlencode($filterDateFrom); ?>&date_to=<?php echo urlencode($filterDateTo); ?>"
           class="btn btn-sm btn-outline-success">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i><?php _e('gw_export_csv', 'Export CSV'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/payments/gateway-pay.php" class="btn btn-sm btn-primary">
            <i class="bi bi-phone me-1"></i><?php _e('gw_initiate_payment', 'Initiate Payment'); ?>
        </a>
    </div>
</div>

<?php pageIntro('payments.gateway-transactions'); ?>

<!-- Stats Cards -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-hash"></i></div>
            <div>
                <div class="stat-card-value"><?php echo number_format($totalTx); ?></div>
                <div class="stat-card-label"><?php _e('gw_total_transactions', 'Total Transactions'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="stat-card-value"><?php echo number_format($pendingTx); ?></div>
                <div class="stat-card-label"><?php _e('pending'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-check-circle"></i></div>
            <div>
                <div class="stat-card-value"><?php echo number_format($completedTx); ?></div>
                <div class="stat-card-label"><?php _e('completed'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:<?php echo $unreconciledTx > 0 ? '#fee2e2' : '#e8f5ee'; ?>;color:<?php echo $unreconciledTx > 0 ? '#dc2626' : '#1a6b4a'; ?>;"><i class="bi bi-exclamation-triangle"></i></div>
            <div>
                <div class="stat-card-value"><?php echo number_format($unreconciledTx); ?></div>
                <div class="stat-card-label"><?php _e('gw_unreconciled', 'Unreconciled'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <select name="gateway_name" class="form-select form-select-sm">
                    <option value=""><?php _e('gw_all_gateways', 'All Gateways'); ?></option>
                    <?php foreach ($gateways as $gw): ?>
                    <option value="<?php echo htmlspecialchars($gw['gateway_name']); ?>" <?php echo $filterGateway === $gw['gateway_name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($gw['display_name'] ?? $gw['gateway_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all_statuses', 'All Statuses'); ?></option>
                    <option value="initiated" <?php echo $filterStatus === 'initiated' ? 'selected' : ''; ?>><?php _e('gw_status_initiated', 'Initiated'); ?></option>
                    <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>><?php _e('pending'); ?></option>
                    <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>><?php _e('completed'); ?></option>
                    <option value="failed" <?php echo $filterStatus === 'failed' ? 'selected' : ''; ?>><?php _e('failed'); ?></option>
                    <option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>><?php _e('cancelled'); ?></option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="reconciled" class="form-select form-select-sm">
                    <option value=""><?php _e('gw_all_reconciled', 'Reconciled: All'); ?></option>
                    <option value="1" <?php echo $filterReconciled === '1' ? 'selected' : ''; ?>><?php _e('gw_reconciled_yes', 'Reconciled'); ?></option>
                    <option value="0" <?php echo $filterReconciled === '0' ? 'selected' : ''; ?>><?php _e('gw_unreconciled', 'Unreconciled'); ?></option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filterDateFrom); ?>" placeholder="From">
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filterDateTo); ?>" placeholder="To">
            </div>
            <div class="col-6 col-md-2">
                <input type="text" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="<?php _e('search'); ?>...">
            </div>
            <div class="col-12 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/payments/gateway-transactions.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i><?php _e('clear'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Transactions Table -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($transactions)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox" style="font-size:2rem;"></i><br>
            <?php _e('gw_no_transactions', 'No transactions found.'); ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php _e('gw_gateway', 'Gateway'); ?></th>
                        <th><?php _e('tenant'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('unit'); ?></th>
                        <th class="text-end"><?php _e('amount'); ?></th>
                        <th><?php _e('phone'); ?></th>
                        <th><?php _e('status'); ?></th>
                        <th class="d-none d-lg-table-cell"><?php _e('gw_reconciled_col', 'Reconciled'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('date'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx): ?>
                    <tr class="<?php echo ($tx['status'] === 'completed' && !$tx['reconciled']) ? 'table-warning' : ''; ?>">
                        <td><?php echo $tx['id']; ?></td>
                        <td>
                            <i class="bi <?php echo htmlspecialchars($tx['logo_icon'] ?? 'bi-phone'); ?> me-1"></i>
                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($tx['gateway_display'] ?? $tx['gateway_name']); ?></span>
                            <span class="d-md-none"><?php echo strtoupper(substr($tx['gateway_name'], 0, 3)); ?></span>
                        </td>
                        <td>
                            <?php if ($tx['first_name']): ?>
                            <?php echo htmlspecialchars($tx['first_name'] . ' ' . $tx['last_name']); ?>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($tx['unit_number'] ?? '-'); ?></td>
                        <td class="text-end fw-semibold"><?php echo formatCurrency($tx['amount'], $tx['currency']); ?></td>
                        <td><small><?php echo htmlspecialchars($tx['phone_number'] ?? '-'); ?></small></td>
                        <td>
                            <?php
                            $statusColors = ['initiated' => 'info', 'pending' => 'warning', 'completed' => 'success', 'failed' => 'danger', 'cancelled' => 'secondary'];
                            $color = $statusColors[$tx['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $color; ?>"><?php echo ucfirst($tx['status']); ?></span>
                        </td>
                        <td class="d-none d-lg-table-cell">
                            <?php if ($tx['reconciled']): ?>
                            <span class="badge bg-success"><i class="bi bi-check"></i></span>
                            <?php elseif ($tx['status'] === 'completed'): ?>
                            <span class="badge bg-warning text-dark"><?php _e('no'); ?></span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell"><small><?php echo date('d M Y H:i', strtotime($tx['created_at'])); ?></small></td>
                        <td>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-link text-muted p-0" data-bs-toggle="dropdown">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="viewTransaction(<?php echo $tx['id']; ?>)">
                                        <i class="bi bi-eye me-2"></i><?php _e('view'); ?>
                                    </a></li>
                                    <?php if ($tx['status'] === 'completed' && !$tx['reconciled'] && hasPermission('payments', 'edit')): ?>
                                    <li><a class="dropdown-item text-success" href="javascript:void(0)" onclick="reconcileTransaction(<?php echo $tx['id']; ?>)">
                                        <i class="bi bi-check-circle me-2"></i><?php _e('gw_reconcile', 'Reconcile'); ?>
                                    </a></li>
                                    <?php endif; ?>
                                    <?php if (in_array($tx['status'], ['initiated', 'pending', 'failed']) && hasPermission('payments', 'edit')): ?>
                                    <li><a class="dropdown-item text-primary" href="javascript:void(0)" onclick="markCompleted(<?php echo $tx['id']; ?>)">
                                        <i class="bi bi-check-lg me-2"></i><?php _e('gw_mark_completed', 'Mark Completed'); ?>
                                    </a></li>
                                    <?php endif; ?>
                                    <?php if (in_array($tx['status'], ['initiated', 'pending']) && hasPermission('payments', 'edit')): ?>
                                    <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="cancelTransaction(<?php echo $tx['id']; ?>)">
                                        <i class="bi bi-x-circle me-2"></i><?php _e('cancel'); ?>
                                    </a></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted"><?php echo sprintf(__('showing_x_of_y', 'Showing %d-%d of %d'), $pagination['offset'] + 1, min($pagination['offset'] + $pagination['records_per_page'], $total), $total); ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $pagination['total_pages']; $p++): ?>
                <li class="page-item <?php echo $p == $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $p; ?>&gateway_name=<?php echo urlencode($filterGateway); ?>&status=<?php echo urlencode($filterStatus); ?>&reconciled=<?php echo urlencode($filterReconciled); ?>&date_from=<?php echo urlencode($filterDateFrom); ?>&date_to=<?php echo urlencode($filterDateTo); ?>&search=<?php echo urlencode($filterSearch); ?>"><?php echo $p; ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- View Transaction Modal -->
<div class="modal fade" id="viewTxModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt me-2"></i><?php _e('gw_transaction_details', 'Transaction Details'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="txDetailBody">
                <div class="text-center py-3"><div class="spinner-border text-muted"></div></div>
            </div>
        </div>
    </div>
</div>

<!-- Mark Completed Modal -->
<div class="modal fade" id="markCompletedModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i><?php _e('gw_mark_completed', 'Mark as Completed'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="markCompletedForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="mc_id">
                    <p class="text-muted"><?php _e('gw_mark_completed_desc', 'Manually mark this transaction as completed. Enter the gateway transaction ID if available.'); ?></p>
                    <div class="mb-3">
                        <label class="form-label"><?php _e('gw_gateway_tx_id', 'Gateway Transaction ID'); ?></label>
                        <input type="text" name="transaction_id" class="form-control form-control-sm" placeholder="<?php _e('gw_optional', 'Optional'); ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                    <button type="submit" class="btn btn-sm btn-primary"><?php _e('gw_mark_completed', 'Mark Completed'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const API_URL = BASE_URL + 'api/payment-gateway.php';

function viewTransaction(id) {
    const body = document.getElementById('txDetailBody');
    body.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-muted"></div></div>';
    new bootstrap.Modal('#viewTxModal').show();

    $.get(API_URL, { action: 'get', id: id }, function(res) {
        if (res.success) {
            const tx = res.data;
            const statusColors = {initiated:'info', pending:'warning', completed:'success', failed:'danger', cancelled:'secondary'};
            body.innerHTML = `
                <div class="row g-3" style="font-size:0.88rem;">
                    <div class="col-6 col-md-4"><strong><?php _e('gw_gateway', 'Gateway'); ?>:</strong><br>${tx.gateway_display || tx.gateway_name}</div>
                    <div class="col-6 col-md-4"><strong><?php _e('status'); ?>:</strong><br><span class="badge bg-${statusColors[tx.status]||'secondary'}">${tx.status}</span></div>
                    <div class="col-6 col-md-4"><strong><?php _e('gw_reconciled_col', 'Reconciled'); ?>:</strong><br>${tx.reconciled == 1 ? '<span class="badge bg-success"><?php _e("yes"); ?></span>' : '<span class="badge bg-warning text-dark"><?php _e("no"); ?></span>'}</div>
                    <div class="col-6 col-md-4"><strong><?php _e('amount'); ?>:</strong><br>${tx.currency} ${parseFloat(tx.amount).toLocaleString()}</div>
                    <div class="col-6 col-md-4"><strong><?php _e('phone'); ?>:</strong><br>${tx.phone_number || '-'}</div>
                    <div class="col-6 col-md-4"><strong><?php _e('tenant'); ?>:</strong><br>${tx.first_name ? tx.first_name + ' ' + tx.last_name : '-'}</div>
                    <div class="col-6 col-md-4"><strong><?php _e('unit'); ?>:</strong><br>${tx.unit_number || '-'}</div>
                    <div class="col-6 col-md-4"><strong><?php _e('property'); ?>:</strong><br>${tx.property_name || '-'}</div>
                    <div class="col-6 col-md-4"><strong><?php _e('date'); ?>:</strong><br>${tx.created_at}</div>
                    <div class="col-12"><strong><?php _e('gw_transaction_id', 'Transaction ID'); ?>:</strong><br><code>${tx.transaction_id || '-'}</code></div>
                    <div class="col-12"><strong><?php _e('gw_reference', 'Reference'); ?>:</strong><br><code>${tx.external_reference || '-'}</code></div>
                    ${tx.description ? '<div class="col-12"><strong><?php _e("description"); ?>:</strong><br>' + tx.description + '</div>' : ''}
                    ${tx.error_message ? '<div class="col-12"><strong class="text-danger"><?php _e("gw_error", "Error"); ?>:</strong><br><span class="text-danger">' + tx.error_message + '</span></div>' : ''}
                    ${tx.callback_data ? '<div class="col-12"><strong><?php _e("gw_callback_data", "Callback Data"); ?>:</strong><br><pre class="bg-light p-2 rounded" style="font-size:0.75rem;max-height:200px;overflow:auto;">' + (typeof tx.callback_data === 'string' ? tx.callback_data : JSON.stringify(JSON.parse(tx.callback_data), null, 2)) + '</pre></div>' : ''}
                </div>
            `;
        }
    }, 'json');
}

function reconcileTransaction(id) {
    if (!confirm('<?php echo __("gw_confirm_reconcile", "Reconcile this transaction? This will create a payment record."); ?>')) return;
    $.post(API_URL, { action: 'reconcile', id: id }, function(res) {
        if (res.success) location.reload();
        else alert(res.message || 'Error');
    }, 'json');
}

function autoReconcile() {
    if (!confirm('<?php echo __("gw_confirm_auto_reconcile", "Auto-reconcile all completed unreconciled transactions?"); ?>')) return;
    $.post(API_URL, { action: 'auto_reconcile' }, function(res) {
        alert(res.message);
        if (res.success) location.reload();
    }, 'json');
}

function cancelTransaction(id) {
    if (!confirm('<?php echo __("gw_confirm_cancel_tx", "Cancel this transaction?"); ?>')) return;
    $.post(API_URL, { action: 'cancel_transaction', id: id }, function(res) {
        if (res.success) location.reload();
        else alert(res.message || 'Error');
    }, 'json');
}

function markCompleted(id) {
    $('#mc_id').val(id);
    new bootstrap.Modal('#markCompletedModal').show();
}

document.getElementById('markCompletedForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('action', 'mark_completed');
    $.ajax({
        url: API_URL, method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
        success: function(res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('markCompletedModal')).hide();
                location.reload();
            } else alert(res.message);
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
