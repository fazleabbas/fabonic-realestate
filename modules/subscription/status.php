<?php
/**
 * FIS Real Estate — Subscription Status Page
 * Shows current subscription, tier info, payment history, and upgrade options
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

$subStatus = getSubscriptionStatus();
$subscription = $subStatus['subscription'];
$payments = getPaymentHistory(10);
$tiers = getTierConfig();
$isBlocked = isset($_GET['blocked']) && $_GET['blocked'] == '1';
$isAdmin = getUserData('role_name') === 'Admin';

$pageTitle = __('subscription', 'Subscription');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <?php if ($isBlocked): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong><?= __('subscription_blocked', 'Access Restricted') ?>!</strong>
        <?= $subStatus['message'] ?>
    </div>
    <?php endif; ?>

    <!-- Current Subscription Card -->
    <div class="row mb-4">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-credit-card me-2"></i><?= __('current_subscription', 'Current Subscription') ?></h5>
                    <?php
                    $badgeClass = 'bg-secondary';
                    if ($subStatus['status'] === 'active') $badgeClass = 'bg-success';
                    elseif ($subStatus['status'] === 'trial') $badgeClass = 'bg-info';
                    elseif ($subStatus['status'] === 'grace_period') $badgeClass = 'bg-warning text-dark';
                    elseif (in_array($subStatus['status'], ['expired','suspended','cancelled'])) $badgeClass = 'bg-danger';
                    ?>
                    <span class="badge <?= $badgeClass ?> fs-6"><?= ucfirst(str_replace('_', ' ', $subStatus['status'])) ?></span>
                </div>
                <div class="card-body">
                    <?php if ($subscription): ?>
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-borderless mb-0">
                                <tr>
                                    <td class="text-muted"><?= __('tier', 'Tier') ?></td>
                                    <td><strong class="text-primary"><?= $subStatus['tier_name'] ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="text-muted"><?= __('units', 'Units') ?></td>
                                    <td><strong><?= $subStatus['unit_count'] ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="text-muted"><?= __('rate_per_unit', 'Rate/Unit') ?></td>
                                    <td><?= formatCurrency($subStatus['rate_per_unit'], $subStatus['currency']) ?>/<?= __('month', 'month') ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted"><?= __('billing_cycle', 'Billing') ?></td>
                                    <td><?= ucfirst($subscription['billing_cycle']) ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-borderless mb-0">
                                <tr>
                                    <td class="text-muted"><?= __('gross_amount', 'Gross Amount') ?></td>
                                    <td><?= formatCurrency($subStatus['gross_amount'], $subStatus['currency']) ?></td>
                                </tr>
                                <?php if ($subStatus['discount_type'] !== 'none' && $subStatus['discount_amount'] > 0): ?>
                                <tr>
                                    <td class="text-muted"><?= __('discount', 'Discount') ?></td>
                                    <td class="text-success">
                                        -<?= formatCurrency($subStatus['discount_amount'], $subStatus['currency']) ?>
                                        <small class="text-muted">
                                            (<?= $subStatus['discount_type'] === 'percentage'
                                                ? $subStatus['discount_value'] . '%'
                                                : formatCurrency($subStatus['discount_value'], $subStatus['currency']) . '/unit' ?>)
                                        </small>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td class="text-muted"><strong><?= __('net_amount', 'Net Amount') ?></strong></td>
                                    <td><strong class="text-primary fs-5"><?= formatCurrency($subStatus['net_amount'], $subStatus['currency']) ?></strong>/<?= __('month', 'month') ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted"><?= __('period', 'Period') ?></td>
                                    <td><?= formatDate($subStatus['start_date']) ?> — <?= formatDate($subStatus['end_date']) ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted"><?= __('days_remaining', 'Days Left') ?></td>
                                    <td>
                                        <?php if ($subStatus['days_remaining'] >= 0): ?>
                                            <strong class="<?= $subStatus['days_remaining'] <= 7 ? 'text-warning' : 'text-success' ?>">
                                                <?= $subStatus['days_remaining'] ?> <?= __('days', 'days') ?>
                                            </strong>
                                        <?php else: ?>
                                            <strong class="text-danger"><?= __('expired', 'Expired') ?></strong>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <?php if (!empty($subStatus['warning'])): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?= $subStatus['message'] ?>
                    </div>
                    <?php endif; ?>

                    <?php elseif ($subStatus['status'] === 'unverified'): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-cloud-slash text-warning" style="font-size: 3rem;"></i>
                        <p class="text-muted mt-2"><?= htmlspecialchars($subStatus['message']) ?></p>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
                        <p class="text-muted mt-2">No subscription found. Contact support to get started.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($isAdmin && $subscription): ?>
                <div class="card-footer">
                    <a href="<?= BASE_URL ?>modules/subscription/subscribe.php" class="btn btn-primary">
                        <i class="bi bi-arrow-up-circle me-1"></i>
                        <?= $subStatus['is_trial'] || $subStatus['status'] === 'expired'
                            ? __('subscribe_now', 'Subscribe Now')
                            : __('upgrade_renew', 'Upgrade / Renew') ?>
                    </a>
                    <a href="<?= BASE_URL ?>modules/subscription/payment.php" class="btn btn-outline-primary ms-2">
                        <i class="bi bi-cash me-1"></i>
                        <?= __('make_payment', 'Make Payment') ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="col-lg-4">
            <div class="card shadow-sm mb-3">
                <div class="card-body text-center">
                    <h6 class="text-muted mb-2"><?= __('monthly_cost', 'Monthly Cost') ?></h6>
                    <h2 class="text-primary mb-0"><?= $subscription ? formatCurrency($subStatus['net_amount'], $subStatus['currency']) : '—' ?></h2>
                    <small class="text-muted"><?= $subscription ? $subStatus['unit_count'] . ' ' . __('units', 'units') . ' × ' . formatCurrency($subStatus['rate_per_unit'], $subStatus['currency']) : '' ?></small>
                </div>
            </div>

            <?php if ($subStatus['is_trial']): ?>
            <div class="card shadow-sm border-info mb-3">
                <div class="card-body text-center">
                    <i class="bi bi-clock-history text-info" style="font-size: 2rem;"></i>
                    <h6 class="mt-2"><?= __('trial_period', 'Trial Period') ?></h6>
                    <h3 class="text-info"><?= max(0, $subStatus['days_remaining']) ?></h3>
                    <p class="text-muted mb-0"><?= __('days_remaining', 'days remaining') ?></p>
                </div>
            </div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-body">
                    <h6 class="mb-3"><i class="bi bi-telephone me-2"></i><?= __('need_help', 'Need Help?') ?></h6>
                    <p class="small text-muted mb-2">WhatsApp: +255 786 110 021</p>
                    <p class="small text-muted mb-0">Email: support@fis.co.tz</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Pricing Tiers Comparison -->
    <div class="card shadow-sm mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-grid-3x3-gap me-2"></i><?= __('pricing_tiers', 'Pricing Tiers') ?></h5>
        </div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($tiers as $tierKey => $tierInfo): ?>
                <div class="col-md-4 mb-3">
                    <div class="card h-100 <?= ($subStatus['tier'] ?? '') === $tierKey ? 'border-primary' : '' ?>">
                        <div class="card-header text-center <?= ($subStatus['tier'] ?? '') === $tierKey ? 'bg-primary text-white' : '' ?>">
                            <h5 class="mb-0"><?= $tierInfo['name'] ?></h5>
                            <?php if (($subStatus['tier'] ?? '') === $tierKey): ?>
                                <small>(<?= __('current_plan', 'Current Plan') ?>)</small>
                            <?php endif; ?>
                        </div>
                        <div class="card-body text-center">
                            <h3 class="text-primary mb-0"><?= formatCurrency($tierInfo['rate'], DEFAULT_CURRENCY, 0) ?></h3>
                            <p class="text-muted">/<?= __('per_unit', 'unit') ?>/<?= __('month', 'month') ?></p>
                            <p class="small text-muted"><?= $tierInfo['description'] ?></p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Payment History -->
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i><?= __('payment_history', 'Payment History') ?></h5>
        </div>
        <div class="card-body">
            <?php if (!empty($payments)): ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?= __('date', 'Date') ?></th>
                            <th><?= __('amount', 'Amount') ?></th>
                            <th><?= __('method', 'Method') ?></th>
                            <th><?= __('reference', 'Reference') ?></th>
                            <th><?= __('period', 'Period') ?></th>
                            <th><?= __('status', 'Status') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?= formatDate($payment['payment_date']) ?></td>
                            <td><strong><?= formatCurrency($payment['amount'], $payment['currency']) ?></strong></td>
                            <td><?= ucfirst(str_replace('_', ' ', $payment['payment_method'])) ?></td>
                            <td><code><?= htmlspecialchars($payment['reference_number'] ?? '—') ?></code></td>
                            <td>
                                <?= $payment['period_from'] ? formatDate($payment['period_from']) . ' — ' . formatDate($payment['period_to']) : '—' ?>
                            </td>
                            <td>
                                <?php
                                $pBadge = 'bg-secondary';
                                if ($payment['status'] === 'confirmed') $pBadge = 'bg-success';
                                elseif ($payment['status'] === 'pending') $pBadge = 'bg-warning text-dark';
                                elseif ($payment['status'] === 'rejected') $pBadge = 'bg-danger';
                                ?>
                                <span class="badge <?= $pBadge ?>"><?= ucfirst($payment['status']) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="text-muted text-center py-3 mb-0"><?= __('no_payments', 'No payment records yet.') ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
