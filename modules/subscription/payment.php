<?php
/**
 * FIS Real Estate — Subscription Payment Page
 * Submit payment reference for admin confirmation
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

if (getUserData('role_name') !== 'Admin') {
    setAlert(__('no_permission', 'You do not have permission to access this page.'), 'danger');
    redirect('index.php');
}

$subStatus = getSubscriptionStatus();
$subscription = $subStatus['subscription'];

// Get selected plan from session or use current subscription
$selectedPlan = $_SESSION['selected_plan'] ?? null;
if ($selectedPlan && (time() - ($selectedPlan['timestamp'] ?? 0)) > 3600) {
    // Expire after 1 hour
    unset($_SESSION['selected_plan']);
    $selectedPlan = null;
}

// Determine billing
if ($selectedPlan) {
    $billing = $selectedPlan['billing'];
    $tier = $selectedPlan['tier'];
    $unitCount = $selectedPlan['unit_count'];
} elseif ($subscription) {
    $tier = $subscription['tier'];
    $unitCount = (int) $subscription['unit_count'];
    $billing = calculateBilling(
        $unitCount, $tier,
        $subscription['discount_type'], (float) $subscription['discount_value'],
        $subscription['currency']
    );
} else {
    setAlert('No subscription to pay for. Please choose a plan first.', 'warning');
    redirect('modules/subscription/subscribe.php');
}

$paymentInstructions = getPaymentInstructions($billing['net_amount'], $billing['currency']);

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $referenceNumber = clean($_POST['reference_number'] ?? '');
    $paymentMethod = clean($_POST['payment_method'] ?? 'airtel_money');
    $notes = clean($_POST['notes'] ?? '');

    if (empty($referenceNumber)) {
        setAlert(__('reference_required', 'Transaction reference number is required.'), 'danger');
    } else {
        // Calculate period
        $periodFrom = date('Y-m-d');
        $periodTo = date('Y-m-d', strtotime('+1 month'));

        // If upgrading, update subscription first
        if ($selectedPlan) {
            $updateResult = updateSubscription([
                'tier' => $tier,
                'unit_count' => $unitCount
            ]);
        }

        // Submit payment
        $result = submitPayment(
            $billing['net_amount'],
            $paymentMethod,
            $referenceNumber,
            $periodFrom,
            $periodTo,
            $notes
        );

        if ($result['success']) {
            unset($_SESSION['selected_plan']);
            logActivity('created', 'subscription_payment', $result['payment_id'], [
                'amount' => $billing['net_amount'],
                'reference' => $referenceNumber,
                'method' => $paymentMethod,
                'tier' => $tier
            ]);
            setAlert(__('payment_submitted', 'Payment submitted! We will verify and activate your subscription shortly.'), 'success');
            redirect('modules/subscription/status.php');
        } else {
            setAlert('Failed to submit payment: ' . ($result['error'] ?? 'Unknown error'), 'danger');
        }
    }
}

$pageTitle = __('make_payment', 'Make Payment');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8">

            <!-- Step Indicator -->
            <div class="d-flex justify-content-center mb-4">
                <div class="d-flex align-items-center">
                    <span class="badge bg-success rounded-circle" style="width:30px;height:30px;line-height:22px;"><i class="bi bi-check"></i></span>
                    <span class="ms-2 text-success"><?= __('choose_plan', 'Choose Plan') ?></span>
                    <span class="mx-3 text-muted">—</span>
                    <span class="badge bg-primary rounded-circle" style="width:30px;height:30px;line-height:22px;">2</span>
                    <span class="ms-2 fw-bold text-primary"><?= __('make_payment', 'Make Payment') ?></span>
                    <span class="mx-3 text-muted">—</span>
                    <span class="badge bg-secondary rounded-circle" style="width:30px;height:30px;line-height:22px;">3</span>
                    <span class="ms-2 text-muted"><?= __('activation', 'Activation') ?></span>
                </div>
            </div>

            <!-- Order Summary -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-receipt me-2"></i><?= __('order_summary', 'Order Summary') ?></h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless mb-0">
                        <tr>
                            <td class="text-muted"><?= __('plan', 'Plan') ?></td>
                            <td class="text-end"><strong><?= $billing['tier_name'] ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted"><?= __('units', 'Units') ?></td>
                            <td class="text-end"><?= $billing['unit_count'] ?> × <?= formatCurrency($billing['rate_per_unit'], $billing['currency'], 0) ?>/month</td>
                        </tr>
                        <tr>
                            <td class="text-muted"><?= __('gross_amount', 'Subtotal') ?></td>
                            <td class="text-end"><?= formatCurrency($billing['gross_amount'], $billing['currency'], 0) ?></td>
                        </tr>
                        <?php if ($billing['discount_amount'] > 0): ?>
                        <tr class="text-success">
                            <td>
                                <i class="bi bi-tag-fill me-1"></i><?= __('discount', 'Discount') ?>
                                <small>
                                    (<?= $billing['discount_type'] === 'percentage'
                                        ? $billing['discount_value'] . '%'
                                        : formatCurrency($billing['discount_value'], $billing['currency'], 0) . '/unit' ?>)
                                </small>
                            </td>
                            <td class="text-end">-<?= formatCurrency($billing['discount_amount'], $billing['currency'], 0) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="border-top">
                            <td><strong class="fs-5"><?= __('total_due', 'Total Due') ?></strong></td>
                            <td class="text-end"><strong class="fs-5 text-primary"><?= formatCurrency($billing['net_amount'], $billing['currency'], 0) ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted"><?= __('billing_cycle', 'Billing Cycle') ?></td>
                            <td class="text-end">Monthly</td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Payment Instructions -->
            <div class="card shadow-sm mb-4 border-primary">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-phone me-2"></i><?= __('payment_instructions', 'Payment Instructions') ?></h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-light border mb-3">
                        <h6 class="mb-2"><?= $paymentInstructions['method'] ?></h6>
                        <ol class="mb-0">
                            <?php foreach ($paymentInstructions['instructions'] as $step): ?>
                            <li class="mb-1"><?= $step ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                    <div class="text-center">
                        <p class="mb-1 text-muted"><?= __('send_to', 'Send to') ?>:</p>
                        <h4 class="text-primary mb-0"><?= PAYMENT_AIRTEL_NUMBER ?></h4>
                        <small class="text-muted"><?= PAYMENT_AIRTEL_NAME ?></small>
                    </div>
                </div>
            </div>

            <!-- Payment Reference Form -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-send me-2"></i><?= __('submit_payment', 'Submit Payment Reference') ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?= csrfField() ?>

                        <div class="mb-3">
                            <label class="form-label fw-bold"><?= __('payment_method', 'Payment Method') ?></label>
                            <select class="form-select" name="payment_method" required>
                                <option value="airtel_money">Airtel Money</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="cash">Cash</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold"><?= __('reference_number', 'Transaction Reference Number') ?> *</label>
                            <input type="text" class="form-control form-control-lg" name="reference_number"
                                   placeholder="e.g. TZ123456789" required maxlength="100">
                            <small class="text-muted"><?= __('reference_help', 'Enter the transaction reference from your payment receipt') ?></small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold"><?= __('notes', 'Notes') ?> (<?= __('optional', 'optional') ?>)</label>
                            <textarea class="form-control" name="notes" rows="2" maxlength="500"></textarea>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="<?= BASE_URL ?>modules/subscription/subscribe.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i><?= __('back', 'Back') ?>
                            </a>
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-check-circle me-1"></i>
                                <?= __('submit_payment', 'Submit Payment') ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
