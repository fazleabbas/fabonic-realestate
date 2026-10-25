<?php
/**
 * FIS Real Estate — Tenant Portal Dashboard (Part 16.2)
 * Current balance, lease summary, next payment due, recent payments.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/charges.php';
require_once __DIR__ . '/../../includes/subscription.php';

// Check portal enabled + tier
if (!getSetting('tenant_portal_enabled', '0') || !hasTier('professional')) {
    header('Location: ' . BASE_URL . 'modules/tenant-portal/login.php');
    exit;
}

$pageTitle = __('tp_dashboard', 'My Dashboard');
$tenantId = (int) ($_SESSION['tenant_portal_id'] ?? 0);

// ============================================================
// Gather dashboard data
// ============================================================
$tenantData = null;
$activeLease = null;
$currentBalance = 0;
$totalPaid = 0;
$paidThisYear = 0;
$nextDueDate = null;
$nextDueAmount = 0;
$pendingLateFees = 0;
$recentPayments = [];

try {
    // Tenant info
    $stmt = $conn->prepare("SELECT id, first_name, last_name, phone, email, status, move_in_date FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $tenantData = $stmt->fetch();

    // Active lease
    $stmt = $conn->prepare("
        SELECT l.*, u.unit_number, u.unit_type, p.name AS property_name, p.address AS property_address
        FROM leases l
        INNER JOIN units u ON l.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        WHERE l.tenant_id = ? AND l.status = 'active'
        ORDER BY l.start_date DESC LIMIT 1
    ");
    $stmt->execute([$tenantId]);
    $activeLease = $stmt->fetch();

    // Total paid
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE tenant_id = ? AND status = 'recorded'");
    $stmt->execute([$tenantId]);
    $totalPaid = (float) $stmt->fetchColumn();

    // Paid this year
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE tenant_id = ? AND status = 'recorded' AND YEAR(payment_date) = YEAR(CURDATE())");
    $stmt->execute([$tenantId]);
    $paidThisYear = (float) $stmt->fetchColumn();

    // Calculate balance
    if ($activeLease) {
        // Part 31: balance is the sum of unsettled charges.
        $currentBalance = outstandingTotal([
            'lease_id' => $activeLease['id'],
            'due_by'   => date('Y-m-d'),
        ]);

        // Next due date
        $dueDay = (int) $activeLease['payment_due_day'];
        $nextDue = new DateTime();
        $nextDue->setDate($nextDue->format('Y'), $nextDue->format('m'), min($dueDay, (int) $nextDue->format('t')));
        if ($nextDue <= $now) {
            $nextDue->modify('+1 month');
            $nextDue->setDate($nextDue->format('Y'), $nextDue->format('m'), min($dueDay, (int) $nextDue->format('t')));
        }
        $nextDueDate = $nextDue->format('Y-m-d');
        $nextDueAmount = (float) $activeLease['rent_amount'];
    }

    // Pending late fees
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM late_fees WHERE tenant_id = ? AND status = 'pending'");
    $stmt->execute([$tenantId]);
    $pendingLateFees = (float) $stmt->fetchColumn();

    // Recent payments (last 5)
    $stmt = $conn->prepare("
        SELECT p.id, p.amount, p.currency, p.payment_date, p.payment_method, p.period_from, p.period_to,
               r.receipt_number, r.pdf_path
        FROM payments p
        LEFT JOIN receipts r ON r.payment_id = p.id
        WHERE p.tenant_id = ? AND p.status = 'recorded'
        ORDER BY p.payment_date DESC LIMIT 5
    ");
    $stmt->execute([$tenantId]);
    $recentPayments = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Tenant portal dashboard error: " . $e->getMessage());
}

$currency = $activeLease['currency'] ?? DEFAULT_CURRENCY;
$daysUntilDue = $nextDueDate ? (int)(new DateTime())->diff(new DateTime($nextDueDate))->format('%R%a') : null;

require_once __DIR__ . '/../../includes/tenant-header.php';
?>

<!-- Welcome -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-grid-1x2 me-2" style="color:var(--re-primary);"></i><?php _e('tp_dashboard', 'My Dashboard'); ?></h4>
        <p class="text-muted mb-0"><?php _e('welcome_back'); ?>, <?php echo htmlspecialchars($tenantData['first_name'] ?? ''); ?>!</p>
    </div>
</div>

<!-- Key Metrics -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;background:<?php echo $currentBalance > 0 ? '#fee2e2' : '#e8f5ee'; ?>;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-wallet2" style="font-size:1.3rem;color:<?php echo $currentBalance > 0 ? '#991b1b' : '#1a6b4a'; ?>;"></i>
                </div>
                <div>
                    <div class="fw-bold" style="font-size:1.1rem;"><?php echo formatCurrency($currentBalance, $currency); ?></div>
                    <small class="text-muted"><?php _e('tp_current_balance', 'Current Balance'); ?></small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;background:#dbeafe;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-calendar-event" style="font-size:1.3rem;color:#1e40af;"></i>
                </div>
                <div>
                    <?php if ($nextDueDate): ?>
                    <div class="fw-bold" style="font-size:1.1rem;"><?php echo formatDate($nextDueDate); ?></div>
                    <small class="text-muted"><?php _e('tp_next_due', 'Next Due Date'); ?></small>
                    <?php if ($daysUntilDue !== null): ?>
                    <br><small class="<?php echo $daysUntilDue <= 3 ? 'text-danger fw-semibold' : 'text-muted'; ?>"><?php echo $daysUntilDue; ?> <?php _e('days'); ?></small>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="fw-bold" style="font-size:1.1rem;">—</div>
                    <small class="text-muted"><?php _e('tp_next_due', 'Next Due Date'); ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;background:#e8f5ee;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-cash-stack" style="font-size:1.3rem;color:#1a6b4a;"></i>
                </div>
                <div>
                    <div class="fw-bold" style="font-size:1.1rem;"><?php echo formatCurrency($paidThisYear, $currency); ?></div>
                    <small class="text-muted"><?php _e('tp_paid_year', 'Paid This Year'); ?></small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;background:<?php echo $pendingLateFees > 0 ? '#fef3c7' : '#f3e8ff'; ?>;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-exclamation-circle" style="font-size:1.3rem;color:<?php echo $pendingLateFees > 0 ? '#92400e' : '#6b21a8'; ?>;"></i>
                </div>
                <div>
                    <div class="fw-bold" style="font-size:1.1rem;"><?php echo formatCurrency($pendingLateFees, $currency); ?></div>
                    <small class="text-muted"><?php _e('tp_late_fees', 'Pending Late Fees'); ?></small>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Lease Summary -->
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom"><i class="bi bi-file-earmark-text me-1"></i> <?php _e('tp_lease_summary', 'Lease Summary'); ?></div>
            <div class="card-body">
                <?php if ($activeLease): ?>
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td class="text-muted fw-semibold" style="width:40%;"><?php _e('property'); ?></td><td><?php echo htmlspecialchars($activeLease['property_name']); ?></td></tr>
                        <tr><td class="text-muted fw-semibold"><?php _e('unit_number'); ?></td><td><?php echo htmlspecialchars($activeLease['unit_number']); ?> <small class="text-muted">(<?php echo ucfirst($activeLease['unit_type']); ?>)</small></td></tr>
                        <tr><td class="text-muted fw-semibold"><?php _e('tp_rent_amount', 'Rent Amount'); ?></td><td class="fw-bold"><?php echo formatCurrency($activeLease['rent_amount'], $activeLease['currency']); ?> <small class="text-muted">/ <?php echo paymentFrequencyLabel($activeLease['payment_frequency']); ?></small></td></tr>
                        <tr><td class="text-muted fw-semibold"><?php _e('tp_lease_period', 'Lease Period'); ?></td><td><?php echo formatDate($activeLease['start_date']); ?> — <?php echo formatDate($activeLease['end_date']); ?></td></tr>
                        <tr><td class="text-muted fw-semibold"><?php _e('tp_due_day', 'Payment Due Day'); ?></td><td><?php _e('tp_day_of_month', 'Day'); ?> <?php echo $activeLease['payment_due_day']; ?></td></tr>
                        <tr>
                            <td class="text-muted fw-semibold"><?php _e('status'); ?></td>
                            <td>
                                <?php
                                $leaseEnd = new DateTime($activeLease['end_date']);
                                $daysLeft = (int)(new DateTime())->diff($leaseEnd)->format('%R%a');
                                ?>
                                <span class="badge bg-success"><?php _e('active', 'Active'); ?></span>
                                <?php if ($daysLeft <= 30): ?>
                                <span class="badge bg-warning text-dark ms-1"><?php echo $daysLeft; ?> <?php _e('days'); ?> <?php _e('tp_remaining', 'remaining'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-file-earmark-x" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('tp_no_active_lease', 'No active lease found.'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Next Payment -->
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom"><i class="bi bi-credit-card me-1"></i> <?php _e('tp_next_payment', 'Next Payment'); ?></div>
            <div class="card-body">
                <?php if ($activeLease && $nextDueDate): ?>
                <div class="text-center py-3">
                    <div style="width:80px;height:80px;background:linear-gradient(135deg,#e8f5ee,#dbeafe);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                        <i class="bi bi-cash-coin" style="font-size:2rem;color:#1a6b4a;"></i>
                    </div>
                    <h3 class="fw-bold mb-1"><?php echo formatCurrency($nextDueAmount, $currency); ?></h3>
                    <p class="text-muted mb-2"><?php _e('tp_due_on', 'Due on'); ?> <?php echo formatDate($nextDueDate); ?></p>
                    <?php if ($daysUntilDue !== null && $daysUntilDue <= 0): ?>
                    <span class="badge bg-danger"><?php _e('tp_overdue', 'OVERDUE'); ?></span>
                    <?php elseif ($daysUntilDue !== null && $daysUntilDue <= 7): ?>
                    <span class="badge bg-warning text-dark"><?php echo $daysUntilDue; ?> <?php _e('days'); ?> <?php _e('tp_remaining', 'remaining'); ?></span>
                    <?php elseif ($daysUntilDue !== null): ?>
                    <span class="badge bg-info"><?php echo $daysUntilDue; ?> <?php _e('days'); ?> <?php _e('tp_remaining', 'remaining'); ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($currentBalance > 0): ?>
                <div class="alert alert-warning py-2 mt-2 mb-0">
                    <small><i class="bi bi-exclamation-triangle me-1"></i>
                    <?php _e('tp_outstanding_balance', 'Outstanding balance'); ?>: <strong><?php echo formatCurrency($currentBalance, $currency); ?></strong></small>
                </div>
                <?php else: ?>
                <div class="alert alert-success py-2 mt-2 mb-0">
                    <small><i class="bi bi-check-circle me-1"></i> <?php _e('tp_all_paid', 'All payments are up to date!'); ?></small>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-calendar-x" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('tp_no_upcoming', 'No upcoming payments.'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Payments -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-1"></i> <?php _e('tp_recent_payments', 'Recent Payments'); ?></span>
        <a href="<?php echo BASE_URL; ?>modules/tenant-portal/payments.php" class="btn btn-sm btn-outline-primary">
            <?php _e('tp_view_all', 'View All'); ?> <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentPayments)): ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-inbox" style="font-size:2rem;"></i>
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
                        <th><?php _e('tp_receipt', 'Receipt'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentPayments as $pay): ?>
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
                        <td>
                            <?php if ($pay['receipt_number']): ?>
                            <span class="badge bg-light text-dark"><?php echo $pay['receipt_number']; ?></span>
                            <?php if ($pay['pdf_path']): ?>
                            <a href="<?php echo BASE_URL; ?>api/tenant-portal.php?action=download_receipt&payment_id=<?php echo $pay['id']; ?>" class="ms-1" title="<?php _e('download'); ?>"><i class="bi bi-download"></i></a>
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
</div>

<?php require_once __DIR__ . '/../../includes/tenant-footer.php'; ?>
