<?php
/**
 * FIS Real Estate — Gateway Payment Initiation (Part 21.2)
 * Select tenant, amount, phone → push USSD → waiting screen.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('gw_initiate_payment', 'Initiate Gateway Payment');

$subStatus = enforceSubscription();

if (!hasTier('enterprise')) {
    setAlert(__('gw_tier_required'), 'warning');
    redirect('index.php');
}
if (getSetting('payment_gateway_enabled', '0') !== '1') {
    setAlert(__('gw_disabled'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('payments', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Pre-fill from query params
$presetTenantId = (int)($_GET['tenant_id'] ?? 0);

// Active gateways
$gateways = $conn->query("SELECT gateway_name, display_name, logo_icon FROM gateway_config WHERE is_active = 1 ORDER BY display_name")->fetchAll();

// Active tenants with leases
$tenants = $conn->query("
    SELECT t.id, CONCAT(t.first_name, ' ', t.last_name) AS name, t.phone,
           l.id AS lease_id, l.rent_amount, l.currency,
           u.id AS unit_id, u.unit_number, p.name AS property_name
    FROM tenants t
    INNER JOIN leases l ON t.id = l.tenant_id AND l.status = 'active'
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    WHERE t.status = 'active'" . propertyScopeSql('p.id') . "
    ORDER BY t.first_name, t.last_name
")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-phone me-2"></i><?php _e('gw_initiate_payment', 'Initiate Gateway Payment'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/payments/"><?php _e('payments'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('gw_initiate_payment', 'Gateway Payment'); ?></li>
            </ol>
        </nav>
    </div>
    <div>
        <a href="<?php echo BASE_URL; ?>modules/payments/gateway-transactions.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-list-check me-1"></i><?php _e('gw_transactions', 'Transactions'); ?>
        </a>
    </div>
</div>

<?php pageIntro('payments.gateway-pay'); ?>

<?php if (empty($gateways)): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <?php _e('gw_no_active', 'No active payment gateways. Please configure gateways first.'); ?>
    <a href="<?php echo BASE_URL; ?>modules/payments/gateway-settings.php" class="alert-link ms-1"><?php _e('gw_settings', 'Gateway Settings'); ?></a>
</div>
<?php else: ?>

<div class="row g-3">
    <!-- Payment Form -->
    <div class="col-12 col-lg-7" id="paymentFormSection">
        <div class="card">
            <div class="card-header"><i class="bi bi-cash-coin me-1"></i> <?php _e('gw_payment_details', 'Payment Details'); ?></div>
            <div class="card-body">
                <form id="paymentForm" class="row g-3">
                    <!-- Gateway Selection -->
                    <div class="col-12">
                        <label class="form-label"><?php _e('gw_select_gateway', 'Select Gateway'); ?> <span class="text-danger">*</span></label>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php foreach ($gateways as $i => $gw): ?>
                            <label class="gateway-option <?php echo $i === 0 ? 'active' : ''; ?>" style="cursor:pointer;">
                                <input type="radio" name="gateway_name" value="<?php echo htmlspecialchars($gw['gateway_name']); ?>"
                                       class="d-none" <?php echo $i === 0 ? 'checked' : ''; ?>>
                                <div class="card text-center p-2" style="min-width:120px;">
                                    <i class="bi <?php echo htmlspecialchars($gw['logo_icon'] ?? 'bi-phone'); ?>" style="font-size:1.5rem;color:#1a6b4a;"></i>
                                    <div class="small fw-semibold mt-1"><?php echo htmlspecialchars($gw['display_name'] ?? $gw['gateway_name']); ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Tenant -->
                    <div class="col-12">
                        <label class="form-label"><?php _e('tenant'); ?> <span class="text-danger">*</span></label>
                        <select name="tenant_id" id="tenantSelect" class="form-select form-select-sm" required>
                            <option value=""><?php _e('gw_select_tenant', 'Select tenant...'); ?></option>
                            <?php foreach ($tenants as $t): ?>
                            <option value="<?php echo $t['id']; ?>"
                                    data-lease="<?php echo $t['lease_id']; ?>"
                                    data-unit="<?php echo $t['unit_id']; ?>"
                                    data-phone="<?php echo htmlspecialchars($t['phone']); ?>"
                                    data-rent="<?php echo $t['rent_amount']; ?>"
                                    data-currency="<?php echo htmlspecialchars($t['currency']); ?>"
                                    data-unit-number="<?php echo htmlspecialchars($t['unit_number']); ?>"
                                    data-property="<?php echo htmlspecialchars($t['property_name']); ?>"
                                    <?php echo $presetTenantId == $t['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['name'] . ' — ' . $t['unit_number'] . ' (' . $t['property_name'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Hidden fields auto-filled from tenant selection -->
                    <input type="hidden" name="lease_id" id="leaseId">
                    <input type="hidden" name="unit_id" id="unitId">

                    <!-- Amount -->
                    <div class="col-6">
                        <label class="form-label"><?php _e('amount'); ?> <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text" id="currencyLabel"><?php echo DEFAULT_CURRENCY; ?></span>
                            <input type="number" name="amount" id="amountInput" class="form-control" step="0.01" min="1" required placeholder="0.00">
                        </div>
                    </div>

                    <!-- Phone Number -->
                    <div class="col-6">
                        <label class="form-label"><?php _e('gw_phone_number', 'Phone Number'); ?> <span class="text-danger">*</span></label>
                        <input type="tel" name="phone_number" id="phoneInput" class="form-control form-control-sm" required placeholder="0712 345 678">
                    </div>

                    <!-- Description -->
                    <div class="col-12">
                        <label class="form-label"><?php _e('description'); ?></label>
                        <input type="text" name="description" id="descInput" class="form-control form-control-sm" placeholder="<?php _e('gw_desc_placeholder', 'Rent payment for...'); ?>">
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="bi bi-send me-1"></i><?php _e('gw_send_payment_request', 'Send Payment Request'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Info Panel -->
    <div class="col-12 col-lg-5" id="infoPanel">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> <?php _e('gw_how_it_works', 'How It Works'); ?></div>
            <div class="card-body" style="font-size:0.88rem;">
                <ol class="mb-0">
                    <li class="mb-2"><?php _e('gw_step1', 'Select the payment gateway and tenant'); ?></li>
                    <li class="mb-2"><?php _e('gw_step2', 'Enter the amount and mobile money phone number'); ?></li>
                    <li class="mb-2"><?php _e('gw_step3', 'Click "Send Payment Request" to push USSD prompt to the phone'); ?></li>
                    <li class="mb-2"><?php _e('gw_step4', 'The customer confirms the payment on their phone'); ?></li>
                    <li><?php _e('gw_step5', 'Payment is automatically recorded and a receipt is generated'); ?></li>
                </ol>
            </div>
        </div>

        <!-- Tenant Info Card (shown when tenant is selected) -->
        <div class="card d-none" id="tenantInfoCard">
            <div class="card-header"><i class="bi bi-person me-1"></i> <?php _e('gw_tenant_info', 'Tenant Info'); ?></div>
            <div class="card-body" style="font-size:0.85rem;">
                <div class="mb-1"><strong><?php _e('property'); ?>:</strong> <span id="infoProperty">-</span></div>
                <div class="mb-1"><strong><?php _e('unit'); ?>:</strong> <span id="infoUnit">-</span></div>
                <div class="mb-1"><strong><?php _e('gw_monthly_rent', 'Monthly Rent'); ?>:</strong> <span id="infoRent">-</span></div>
                <div><strong><?php _e('phone'); ?>:</strong> <span id="infoPhone">-</span></div>
            </div>
        </div>
    </div>
</div>

<!-- Waiting/Status Section (hidden by default) -->
<div class="row g-3 d-none" id="waitingSection">
    <div class="col-12 col-lg-8 mx-auto">
        <div class="card text-center">
            <div class="card-body py-5">
                <div id="waitingSpinner" class="mb-3">
                    <div class="spinner-border text-success" style="width:3rem;height:3rem;" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
                <div id="waitingIcon" class="mb-3 d-none"></div>

                <h5 id="waitingTitle"><?php _e('gw_waiting', 'Waiting for Payment...'); ?></h5>
                <p class="text-muted" id="waitingMessage"><?php _e('gw_waiting_msg', 'A payment prompt has been sent to the phone. Please ask the customer to confirm.'); ?></p>

                <div class="my-3 p-3 rounded" style="background:#f8f9fa;">
                    <div class="row text-center" style="font-size:0.88rem;">
                        <div class="col-4">
                            <div class="text-muted"><?php _e('amount'); ?></div>
                            <strong id="waitAmount">-</strong>
                        </div>
                        <div class="col-4">
                            <div class="text-muted"><?php _e('phone'); ?></div>
                            <strong id="waitPhone">-</strong>
                        </div>
                        <div class="col-4">
                            <div class="text-muted"><?php _e('status'); ?></div>
                            <span id="waitStatus" class="badge bg-warning">Pending</span>
                        </div>
                    </div>
                </div>

                <div class="text-muted small mb-3" id="waitReference"></div>

                <div id="waitingActions">
                    <button class="btn btn-sm btn-outline-secondary me-1" onclick="cancelWaiting()">
                        <i class="bi bi-x-circle me-1"></i><?php _e('cancel'); ?>
                    </button>
                    <button class="btn btn-sm btn-outline-primary" onclick="checkStatusManual()">
                        <i class="bi bi-arrow-clockwise me-1"></i><?php _e('gw_check_status', 'Check Status'); ?>
                    </button>
                </div>
                <div id="completedActions" class="d-none">
                    <a href="<?php echo BASE_URL; ?>modules/payments/gateway-pay.php" class="btn btn-sm btn-primary me-1">
                        <i class="bi bi-plus-circle me-1"></i><?php _e('gw_new_payment', 'New Payment'); ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>modules/payments/gateway-transactions.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-list-check me-1"></i><?php _e('gw_transactions', 'Transactions'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<style>
.gateway-option .card { border:2px solid #dee2e6; transition:all 0.2s; }
.gateway-option.active .card, .gateway-option input:checked + .card { border-color:#1a6b4a; background:#e8f5ee; }
.gateway-option:hover .card { border-color:#1a6b4a; }
</style>

<script>
const API_URL = BASE_URL + 'api/payment-gateway.php';
let pollTimer = null;
let currentTxId = null;

// Gateway radio selection
document.querySelectorAll('.gateway-option input[type="radio"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.gateway-option').forEach(o => o.classList.remove('active'));
        this.closest('.gateway-option').classList.add('active');
    });
});

// Tenant selection auto-fill
$('#tenantSelect').on('change', function() {
    const opt = this.options[this.selectedIndex];
    if (!opt.value) {
        $('#leaseId, #unitId').val('');
        $('#tenantInfoCard').addClass('d-none');
        return;
    }
    const data = opt.dataset;
    $('#leaseId').val(data.lease);
    $('#unitId').val(data.unit);
    $('#amountInput').val(data.rent);
    $('#phoneInput').val(data.phone);
    $('#currencyLabel').text(data.currency || '<?php echo DEFAULT_CURRENCY; ?>');
    $('#descInput').val('Rent payment — ' + data.unitNumber + ' (' + data.property + ')');

    $('#infoProperty').text(data.property);
    $('#infoUnit').text(data.unitNumber);
    $('#infoRent').text((data.currency || '<?php echo DEFAULT_CURRENCY; ?>') + ' ' + parseFloat(data.rent).toLocaleString());
    $('#infoPhone').text(data.phone);
    $('#tenantInfoCard').removeClass('d-none');
});

// Trigger change if pre-selected
if ($('#tenantSelect').val()) $('#tenantSelect').trigger('change');

// Initialize Select2
$(function() { if ($.fn.select2) $('#tenantSelect').select2({ width: '100%' }); });

// Submit payment
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span><?php echo __("gw_processing", "Processing..."); ?>';

    const fd = new FormData(this);
    fd.append('action', 'initiate_payment');

    $.ajax({
        url: API_URL, method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
        success: function(res) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send me-1"></i><?php echo __("gw_send_payment_request", "Send Payment Request"); ?>';

            if (res.success) {
                currentTxId = res.data.transaction_id;
                showWaitingScreen(fd.get('amount'), fd.get('phone_number'), res.data.reference);
                startPolling(currentTxId);
            } else {
                showFormAlert('danger', res.message || 'Payment initiation failed');
            }
        },
        error: function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send me-1"></i><?php echo __("gw_send_payment_request", "Send Payment Request"); ?>';
            showFormAlert('danger', 'Network error');
        }
    });
});

function showWaitingScreen(amount, phone, ref) {
    document.getElementById('paymentFormSection').classList.add('d-none');
    document.getElementById('infoPanel').classList.add('d-none');
    document.getElementById('waitingSection').classList.remove('d-none');

    document.getElementById('waitAmount').textContent = parseFloat(amount).toLocaleString() + ' ' + ($('#currencyLabel').text() || '<?php echo DEFAULT_CURRENCY; ?>');
    document.getElementById('waitPhone').textContent = phone;
    document.getElementById('waitReference').textContent = '<?php echo __("gw_reference", "Reference"); ?>: ' + ref;
}

function startPolling(txId) {
    let pollCount = 0;
    const maxPolls = 60; // Poll for ~5 minutes

    pollTimer = setInterval(function() {
        pollCount++;
        if (pollCount > maxPolls) {
            clearInterval(pollTimer);
            updateWaitingStatus('timeout');
            return;
        }

        $.get(API_URL, { action: 'check_status', id: txId }, function(res) {
            if (res.success && res.data) {
                const status = res.data.status;
                if (status === 'completed') {
                    clearInterval(pollTimer);
                    updateWaitingStatus('completed');
                } else if (status === 'failed') {
                    clearInterval(pollTimer);
                    updateWaitingStatus('failed', res.data.error_message);
                } else if (status === 'cancelled') {
                    clearInterval(pollTimer);
                    updateWaitingStatus('cancelled');
                }
                // 'pending' and 'initiated' keep polling
            }
        }, 'json');
    }, 5000); // Poll every 5 seconds
}

function updateWaitingStatus(status, errorMsg) {
    const spinner = document.getElementById('waitingSpinner');
    const icon = document.getElementById('waitingIcon');
    const title = document.getElementById('waitingTitle');
    const msg = document.getElementById('waitingMessage');
    const badge = document.getElementById('waitStatus');
    const waitActions = document.getElementById('waitingActions');
    const doneActions = document.getElementById('completedActions');

    spinner.classList.add('d-none');
    icon.classList.remove('d-none');

    switch (status) {
        case 'completed':
            icon.innerHTML = '<i class="bi bi-check-circle-fill text-success" style="font-size:3rem;"></i>';
            title.textContent = '<?php echo __("gw_payment_successful", "Payment Successful!"); ?>';
            msg.textContent = '<?php echo __("gw_payment_recorded", "The payment has been recorded and a receipt has been generated."); ?>';
            badge.className = 'badge bg-success';
            badge.textContent = '<?php echo __("completed"); ?>';
            waitActions.classList.add('d-none');
            doneActions.classList.remove('d-none');
            break;
        case 'failed':
            icon.innerHTML = '<i class="bi bi-x-circle-fill text-danger" style="font-size:3rem;"></i>';
            title.textContent = '<?php echo __("gw_payment_failed", "Payment Failed"); ?>';
            msg.textContent = errorMsg || '<?php echo __("gw_payment_failed_msg", "The payment was not completed. Please try again."); ?>';
            badge.className = 'badge bg-danger';
            badge.textContent = '<?php echo __("failed"); ?>';
            waitActions.classList.add('d-none');
            doneActions.classList.remove('d-none');
            break;
        case 'cancelled':
            icon.innerHTML = '<i class="bi bi-dash-circle-fill text-secondary" style="font-size:3rem;"></i>';
            title.textContent = '<?php echo __("gw_payment_cancelled", "Payment Cancelled"); ?>';
            msg.textContent = '<?php echo __("gw_cancelled_msg", "The payment request was cancelled."); ?>';
            badge.className = 'badge bg-secondary';
            badge.textContent = '<?php echo __("cancelled"); ?>';
            waitActions.classList.add('d-none');
            doneActions.classList.remove('d-none');
            break;
        case 'timeout':
            icon.innerHTML = '<i class="bi bi-clock-fill text-warning" style="font-size:3rem;"></i>';
            title.textContent = '<?php echo __("gw_timeout", "Request Timed Out"); ?>';
            msg.textContent = '<?php echo __("gw_timeout_msg", "The payment is still pending. Check the transaction log for updates."); ?>';
            badge.className = 'badge bg-warning text-dark';
            badge.textContent = '<?php echo __("pending"); ?>';
            waitActions.classList.add('d-none');
            doneActions.classList.remove('d-none');
            break;
    }
}

function cancelWaiting() {
    if (!confirm('<?php echo __("gw_confirm_cancel", "Cancel this payment request?"); ?>')) return;
    clearInterval(pollTimer);
    if (currentTxId) {
        $.post(API_URL, { action: 'cancel_transaction', id: currentTxId }, function() {}, 'json');
    }
    updateWaitingStatus('cancelled');
}

function checkStatusManual() {
    if (!currentTxId) return;
    $.get(API_URL, { action: 'check_status', id: currentTxId }, function(res) {
        if (res.success) {
            const s = res.data.status;
            if (s === 'completed') { clearInterval(pollTimer); updateWaitingStatus('completed'); }
            else if (s === 'failed') { clearInterval(pollTimer); updateWaitingStatus('failed', res.data.error_message); }
            else { showFormAlert('info', '<?php echo __("gw_still_pending", "Payment is still pending..."); ?>'); }
        }
    }, 'json');
}

function showFormAlert(type, msg) {
    const html = `<div class="alert alert-${type} alert-dismissible fade show" role="alert" style="font-size:0.88rem;">${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
    document.querySelector('.re-main').insertAdjacentHTML('afterbegin', html);
    setTimeout(() => { const a = document.querySelector('.alert-dismissible'); if (a) a.remove(); }, 5000);
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
