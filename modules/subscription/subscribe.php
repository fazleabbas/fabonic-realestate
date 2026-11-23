<?php
/**
 * FIS Real Estate — Subscribe / Upgrade Page
 * Allows admin to choose tier, set unit count, see pricing, and proceed to payment
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

// Only admin can manage subscription
if (getUserData('role_name') !== 'Admin') {
    setAlert(__('no_permission', 'You do not have permission to access this page.'), 'danger');
    redirect('index.php');
}

$subStatus = getSubscriptionStatus();
$subscription = $subStatus['subscription'];
$tiers = getTierConfig();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $tier = clean($_POST['tier'] ?? 'basic');
    $unitCount = max(1, intval($_POST['unit_count'] ?? 1));

    // Calculate billing
    $discountType = $subscription['discount_type'] ?? 'none';
    $discountValue = (float) ($subscription['discount_value'] ?? 0);
    $billing = calculateBilling($unitCount, $tier, $discountType, $discountValue, $subscription['currency'] ?? DEFAULT_CURRENCY);

    // Store selected plan in session for payment page
    $_SESSION['selected_plan'] = [
        'tier' => $tier,
        'unit_count' => $unitCount,
        'billing' => $billing,
        'timestamp' => time()
    ];

    redirect('modules/subscription/payment.php');
}

// Current values for pre-filling
$currentTier = $subscription['tier'] ?? 'basic';
$currentUnits = (int) ($subscription['unit_count'] ?? 1);

$pageTitle = $subStatus['is_trial'] || $subStatus['status'] === 'expired'
    ? __('subscribe_now', 'Subscribe Now')
    : __('upgrade_renew', 'Upgrade / Renew');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-10">

            <!-- Step Indicator -->
            <div class="d-flex justify-content-center mb-4">
                <div class="d-flex align-items-center">
                    <span class="badge bg-primary rounded-circle" style="width:30px;height:30px;line-height:22px;">1</span>
                    <span class="ms-2 fw-bold text-primary"><?= __('choose_plan', 'Choose Plan') ?></span>
                    <span class="mx-3 text-muted">—</span>
                    <span class="badge bg-secondary rounded-circle" style="width:30px;height:30px;line-height:22px;">2</span>
                    <span class="ms-2 text-muted"><?= __('make_payment', 'Make Payment') ?></span>
                    <span class="mx-3 text-muted">—</span>
                    <span class="badge bg-secondary rounded-circle" style="width:30px;height:30px;line-height:22px;">3</span>
                    <span class="ms-2 text-muted"><?= __('activation', 'Activation') ?></span>
                </div>
            </div>

            <form method="POST" id="subscribeForm">
                <?= csrfField() ?>

                <!-- Tier Selection -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-grid-3x3-gap me-2"></i><?= __('select_tier', 'Select Your Plan') ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php
                            $tierFeatures = [
                                'basic' => [
                                    'Properties & Units Management',
                                    'Tenant Management & Screening',
                                    'Lease Management & PDF Generation',
                                    'Rent Collection & Receipts',
                                    'Security Deposits & Late Fees',
                                    'Move-in/Move-out Inspections',
                                    'Custom Roles & Permissions',
                                    'Dashboard & Basic Reports',
                                    'Multi-language (EN/SW/AR)',
                                    'Multi-currency Support',
                                    'PWA / Offline Support'
                                ],
                                'professional' => [
                                    'Everything in Basic, plus:',
                                    'Tenant Self-Service Portal',
                                    'Automated Rent Reminders (WhatsApp/SMS)',
                                    'WhatsApp Receipt Sending',
                                    'Lease Renewal Automation'
                                ],
                                'enterprise' => [
                                    'Everything in Professional, plus:',
                                    'Utility Billing (Water, Electricity, etc.)',
                                    'Maintenance Management',
                                    'Financial Reporting (P&L)',
                                    'Vacancy Advertising & Leads',
                                    'Payment Gateway Integration',
                                    'Priority Support'
                                ]
                            ];
                            foreach ($tiers as $tierKey => $tierInfo):
                                $isCurrentTier = $currentTier === $tierKey;
                            ?>
                            <div class="col-md-4 mb-3">
                                <div class="card h-100 tier-card <?= $isCurrentTier ? 'border-primary selected' : '' ?>"
                                     data-tier="<?= $tierKey ?>" data-rate="<?= $tierInfo['rate'] ?>"
                                     style="cursor: pointer;">
                                    <?php if ($tierKey === 'professional'): ?>
                                    <div class="text-center" style="margin-top:-12px;">
                                        <span class="badge bg-warning text-dark px-3 py-1">Most Popular</span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="card-body text-center">
                                        <h4 class="mb-1"><?= $tierInfo['name'] ?></h4>
                                        <h2 class="text-primary mb-0"><?= formatCurrency($tierInfo['rate'], DEFAULT_CURRENCY, 0) ?></h2>
                                        <p class="text-muted mb-3">/unit/month</p>

                                        <ul class="list-unstyled text-start small">
                                            <?php foreach ($tierFeatures[$tierKey] as $feature): ?>
                                            <li class="mb-1">
                                                <i class="bi bi-check-circle-fill text-success me-1"></i>
                                                <?= $feature ?>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <div class="card-footer text-center">
                                        <div class="form-check d-inline-block">
                                            <input class="form-check-input tier-radio" type="radio" name="tier"
                                                   value="<?= $tierKey ?>" id="tier_<?= $tierKey ?>"
                                                   <?= $isCurrentTier ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="tier_<?= $tierKey ?>">
                                                <?= $isCurrentTier ? __('current_plan', 'Current Plan') : __('select', 'Select') ?>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Unit Count & Price Calculator -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-calculator me-2"></i><?= __('calculate_price', 'Calculate Price') ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="row align-items-end">
                            <div class="col-md-4">
                                <label class="form-label fw-bold"><?= __('number_of_units', 'Number of Units') ?></label>
                                <input type="number" class="form-control form-control-lg" name="unit_count"
                                       id="unitCount" min="1" max="9999" value="<?= $currentUnits ?>" required>
                                <small class="text-muted"><?= __('units_help', 'Total rental units across all your properties') ?></small>
                            </div>
                            <div class="col-md-8">
                                <div class="bg-light rounded p-3">
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <small class="text-muted d-block"><?= __('rate_per_unit', 'Rate/Unit') ?></small>
                                            <strong id="displayRate"><?= formatCurrency(getTierRate($currentTier), DEFAULT_CURRENCY, 0) ?></strong>
                                        </div>
                                        <div class="col-4">
                                            <small class="text-muted d-block"><?= __('units', 'Units') ?></small>
                                            <strong id="displayUnits"><?= $currentUnits ?></strong>
                                        </div>
                                        <div class="col-4">
                                            <small class="text-muted d-block"><?= __('monthly_total', 'Monthly Total') ?></small>
                                            <strong class="text-primary fs-5" id="displayTotal">
                                                <?= formatCurrency($currentUnits * getTierRate($currentTier), DEFAULT_CURRENCY, 0) ?>
                                            </strong>
                                        </div>
                                    </div>
                                    <?php if ($subscription && $subscription['discount_type'] !== 'none' && (float)$subscription['discount_value'] > 0): ?>
                                    <div class="text-center mt-2 pt-2 border-top">
                                        <small class="text-success">
                                            <i class="bi bi-tag-fill me-1"></i>
                                            <?= __('discount_applied', 'Discount applied') ?>:
                                            <?= $subscription['discount_type'] === 'percentage'
                                                ? $subscription['discount_value'] . '%'
                                                : formatCurrency($subscription['discount_value'], DEFAULT_CURRENCY, 0) . '/unit' ?>
                                            —
                                            <?= __('net_total', 'Net total') ?>:
                                            <strong id="displayNet"><?= formatCurrency($subStatus['net_amount'], DEFAULT_CURRENCY, 0) ?></strong>
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <a href="<?= BASE_URL ?>modules/subscription/status.php" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-arrow-left me-1"></i><?= __('back', 'Back') ?>
                        </a>
                        <button type="submit" class="btn btn-primary btn-lg">
                            <?= __('proceed_to_payment', 'Proceed to Payment') ?>
                            <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.tier-card { transition: all 0.2s ease; }
.tier-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
.tier-card.selected { border-color: var(--re-primary) !important; border-width: 2px; background: var(--re-primary-light); }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tierCards = document.querySelectorAll('.tier-card');
    const tierRadios = document.querySelectorAll('.tier-radio');
    const unitInput = document.getElementById('unitCount');
    const displayRate = document.getElementById('displayRate');
    const displayUnits = document.getElementById('displayUnits');
    const displayTotal = document.getElementById('displayTotal');

    const discountType = '<?= $subscription['discount_type'] ?? 'none' ?>';
    const discountValue = <?= (float)($subscription['discount_value'] ?? 0) ?>;

    function getSelectedRate() {
        const card = document.querySelector('.tier-card.selected');
        return card ? parseInt(card.dataset.rate) : <?= getTierRate($currentTier) ?>;
    }

    function formatNum(n) {
        return '<?= DEFAULT_CURRENCY ?> ' + n.toLocaleString();
    }

    function recalculate() {
        const rate = getSelectedRate();
        const units = Math.max(1, parseInt(unitInput.value) || 1);
        const gross = rate * units;
        let discount = 0;

        if (discountType === 'percentage' && discountValue > 0) {
            discount = Math.round(gross * (discountValue / 100));
        } else if (discountType === 'fixed' && discountValue > 0) {
            discount = units * discountValue;
        }
        discount = Math.min(discount, gross);
        const net = gross - discount;

        displayRate.textContent = formatNum(rate);
        displayUnits.textContent = units;
        displayTotal.textContent = formatNum(gross);

        const netEl = document.getElementById('displayNet');
        if (netEl) netEl.textContent = formatNum(net);
    }

    tierCards.forEach(card => {
        card.addEventListener('click', function() {
            tierCards.forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');
            this.querySelector('.tier-radio').checked = true;
            recalculate();
        });
    });

    unitInput.addEventListener('input', recalculate);
    recalculate();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
