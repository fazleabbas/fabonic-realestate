<?php
/**
 * FIS Real Estate — Tenant Portal Payment History (Part 16.3)
 * List of all payments with receipt downloads, date range filters.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

// Check portal enabled + tier
if (!getSetting('tenant_portal_enabled', '0') || !hasTier('professional')) {
    header('Location: ' . BASE_URL . 'modules/tenant-portal/login.php');
    exit;
}

$pageTitle = __('tp_payment_history', 'Payment History');
$tenantId = (int) ($_SESSION['tenant_portal_id'] ?? 0);

// Filters
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

// Build query
$where = "p.tenant_id = :tid AND p.status = 'recorded'";
$params = ['tid' => $tenantId];

if ($dateFrom) {
    $where .= " AND p.payment_date >= :df";
    $params['df'] = $dateFrom;
}
if ($dateTo) {
    $where .= " AND p.payment_date <= :dt";
    $params['dt'] = $dateTo;
}

// Count
$countStmt = $conn->prepare("SELECT COUNT(*) FROM payments p WHERE {$where}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pagination = paginate($total, $page);

// Fetch
$sql = "SELECT p.id, p.amount, p.currency, p.payment_date, p.payment_method,
               p.reference_number, p.period_from, p.period_to, p.notes,
               r.receipt_number, r.pdf_path,
               u.unit_number, pr.name AS property_name
        FROM payments p
        LEFT JOIN receipts r ON r.payment_id = p.id
        LEFT JOIN units u ON p.unit_id = u.id
        LEFT JOIN properties pr ON u.property_id = pr.id
        WHERE {$where}
        ORDER BY p.payment_date DESC
        LIMIT :offset, :limit";
$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $pagination['records_per_page'], PDO::PARAM_INT);
$stmt->execute();
$payments = $stmt->fetchAll();

// Summary
$totalPaid = 0;
$totalPaidStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE tenant_id = ? AND status = 'recorded'");
$totalPaidStmt->execute([$tenantId]);
$totalPaid = (float) $totalPaidStmt->fetchColumn();

require_once __DIR__ . '/../../includes/tenant-header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-cash-coin me-2" style="color:var(--re-primary);"></i><?php _e('tp_payment_history', 'Payment History'); ?></h4>
        <p class="text-muted mb-0"><?php _e('tp_total_paid', 'Total paid'); ?>: <strong><?php echo formatCurrency($totalPaid); ?></strong> &mdash; <?php echo $total; ?> <?php _e('tp_records', 'records'); ?></p>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label mb-1" style="font-size:0.8rem;"><?php _e('tp_date_from', 'From'); ?></label>
                <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label mb-1" style="font-size:0.8rem;"><?php _e('tp_date_to', 'To'); ?></label>
                <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-funnel me-1"></i><?php _e('filter', 'Filter'); ?>
                </button>
                <?php if ($dateFrom || $dateTo): ?>
                <a href="<?php echo BASE_URL; ?>modules/tenant-portal/payments.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i> <?php _e('clear', 'Clear'); ?>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Payments List -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-0">
        <?php if (empty($payments)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox" style="font-size:2.5rem;"></i>
            <p class="mt-2 mb-0"><?php _e('tp_no_payments', 'No payments recorded yet.'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?php _e('date'); ?></th>
                        <th><?php _e('amount'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('tp_period', 'Period'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('tp_method', 'Method'); ?></th>
                        <th class="d-none d-lg-table-cell"><?php _e('tp_reference', 'Reference'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('unit_number'); ?></th>
                        <th><?php _e('tp_receipt', 'Receipt'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $pay): ?>
                    <tr>
                        <td><?php echo formatDate($pay['payment_date']); ?></td>
                        <td class="fw-semibold"><?php echo formatCurrency($pay['amount'], $pay['currency']); ?></td>
                        <td class="d-none d-md-table-cell">
                            <?php if ($pay['period_from'] && $pay['period_to']): ?>
                            <small><?php echo formatDate($pay['period_from']); ?> — <?php echo formatDate($pay['period_to']); ?></small>
                            <?php else: ?>
                            <small class="text-muted">—</small>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell"><small><?php echo ucfirst(str_replace('_', ' ', $pay['payment_method'])); ?></small></td>
                        <td class="d-none d-lg-table-cell"><small><?php echo htmlspecialchars($pay['reference_number'] ?? '—'); ?></small></td>
                        <td class="d-none d-md-table-cell">
                            <small><?php echo htmlspecialchars($pay['unit_number'] ?? ''); ?>
                            <?php if ($pay['property_name']): ?><span class="text-muted"> (<?php echo htmlspecialchars($pay['property_name']); ?>)</span><?php endif; ?></small>
                        </td>
                        <td>
                            <?php if ($pay['receipt_number']): ?>
                            <span class="badge bg-light text-dark"><?php echo $pay['receipt_number']; ?></span>
                            <?php if ($pay['pdf_path']): ?>
                            <a href="<?php echo BASE_URL; ?>api/tenant-portal.php?action=download_receipt&payment_id=<?php echo $pay['id']; ?>" class="ms-1" title="<?php _e('download'); ?>">
                                <i class="bi bi-download"></i>
                            </a>
                            <?php endif; ?>
                            <?php else: ?>
                            <small class="text-muted">—</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer bg-white border-top">
        <nav>
            <ul class="pagination pagination-sm mb-0 justify-content-center">
                <?php if ($pagination['has_prev']): ?>
                <li class="page-item"><a class="page-link" href="?page=<?php echo $pagination['prev_page']; ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>">&laquo;</a></li>
                <?php endif; ?>

                <?php
                $startP = max(1, $pagination['current_page'] - 2);
                $endP = min($pagination['total_pages'], $pagination['current_page'] + 2);
                for ($i = $startP; $i <= $endP; $i++):
                ?>
                <li class="page-item <?php echo $i === $pagination['current_page'] ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor; ?>

                <?php if ($pagination['has_next']): ?>
                <li class="page-item"><a class="page-link" href="?page=<?php echo $pagination['next_page']; ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>">&raquo;</a></li>
                <?php endif; ?>
            </ul>
        </nav>
        <div class="text-center mt-1">
            <small class="text-muted"><?php echo $pagination['current_page']; ?> / <?php echo $pagination['total_pages']; ?> (<?php echo $total; ?> <?php _e('tp_records', 'records'); ?>)</small>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/tenant-footer.php'; ?>
