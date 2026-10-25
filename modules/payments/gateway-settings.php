<?php
/**
 * FIS Real Estate — Payment Gateway Settings (Part 21.1)
 * Configure payment gateways: Selcom, M-Pesa, Airtel Money, Tigo Pesa.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('gw_settings', 'Payment Gateway Settings');

$subStatus = enforceSubscription();

if (!hasTier('enterprise')) {
    setAlert(__('gw_tier_required', 'Enterprise tier or higher required for payment gateway.'), 'warning');
    redirect('index.php');
}
if (getSetting('payment_gateway_enabled', '0') !== '1') {
    setAlert(__('gw_disabled', 'Payment gateway module is disabled. Enable it in Settings > Modules.'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('settings', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Fetch gateways
$gateways = $conn->query("
    SELECT gc.*,
           (SELECT COUNT(*) FROM gateway_transactions gt WHERE gt.gateway_name = gc.gateway_name) AS tx_count,
           (SELECT COUNT(*) FROM gateway_transactions gt WHERE gt.gateway_name = gc.gateway_name AND gt.status = 'completed') AS tx_completed
    FROM gateway_config gc ORDER BY gc.is_active DESC, gc.gateway_name
")->fetchAll();

// Gateway settings
$callbackSecret = getSetting('gateway_callback_secret', '');
$autoReconcile = getSetting('gateway_auto_reconcile', '1');
$autoReceipt = getSetting('gateway_auto_receipt', '1');

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-credit-card-2-front me-2"></i><?php _e('gw_settings', 'Payment Gateway Settings'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/payments/"><?php _e('payments'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('gw_settings', 'Gateway Settings'); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-1 flex-wrap">
        <a href="<?php echo BASE_URL; ?>modules/payments/gateway-pay.php" class="btn btn-sm btn-primary">
            <i class="bi bi-phone me-1"></i><?php _e('gw_initiate_payment', 'Initiate Payment'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/payments/gateway-transactions.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-list-check me-1"></i><?php _e('gw_transactions', 'Transactions'); ?>
        </a>
    </div>
</div>

<?php pageIntro('payments.gateway-settings'); ?>

<!-- Global Gateway Settings Card -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-gear me-1"></i> <?php _e('gw_global_settings', 'Global Gateway Settings'); ?></span>
    </div>
    <div class="card-body">
        <form id="globalSettingsForm" class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label"><?php _e('gw_callback_secret', 'Callback Secret Key'); ?></label>
                <div class="input-group input-group-sm">
                    <input type="text" name="gateway_callback_secret" class="form-control" value="<?php echo htmlspecialchars($callbackSecret); ?>" placeholder="<?php _e('gw_secret_placeholder', 'Leave blank for no validation'); ?>">
                    <button type="button" class="btn btn-outline-secondary" onclick="generateSecret()" title="<?php _e('gw_generate', 'Generate'); ?>">
                        <i class="bi bi-key"></i>
                    </button>
                </div>
                <small class="text-muted"><?php _e('gw_secret_help', 'Append ?secret=KEY to callback URLs for validation'); ?></small>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label"><?php _e('gw_auto_reconcile', 'Auto-Reconcile'); ?></label>
                <select name="gateway_auto_reconcile" class="form-select form-select-sm">
                    <option value="1" <?php echo $autoReconcile === '1' ? 'selected' : ''; ?>><?php _e('enabled'); ?></option>
                    <option value="0" <?php echo $autoReconcile !== '1' ? 'selected' : ''; ?>><?php _e('disabled'); ?></option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label"><?php _e('gw_auto_receipt', 'Auto-Receipt'); ?></label>
                <select name="gateway_auto_receipt" class="form-select form-select-sm">
                    <option value="1" <?php echo $autoReceipt === '1' ? 'selected' : ''; ?>><?php _e('enabled'); ?></option>
                    <option value="0" <?php echo $autoReceipt !== '1' ? 'selected' : ''; ?>><?php _e('disabled'); ?></option>
                </select>
            </div>
            <div class="col-12 col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i><?php _e('save'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Gateways Grid -->
<div class="row g-3 mb-3">
    <?php foreach ($gateways as $gw): ?>
    <div class="col-12 col-md-6">
        <div class="card h-100 <?php echo $gw['is_active'] ? 'border-success' : ''; ?>">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi <?php echo htmlspecialchars($gw['logo_icon'] ?? 'bi-phone'); ?> me-1"></i>
                    <strong><?php echo htmlspecialchars($gw['display_name'] ?: $gw['gateway_name']); ?></strong>
                    <?php if ($gw['is_active']): ?>
                    <span class="badge bg-success ms-1"><?php _e('active'); ?></span>
                    <?php else: ?>
                    <span class="badge bg-secondary ms-1"><?php _e('inactive'); ?></span>
                    <?php endif; ?>
                    <span class="badge bg-<?php echo $gw['environment'] === 'production' ? 'danger' : 'info'; ?> ms-1">
                        <?php echo ucfirst($gw['environment']); ?>
                    </span>
                </span>
                <div>
                    <?php if (hasPermission('settings', 'edit')): ?>
                    <button class="btn btn-sm btn-outline-<?php echo $gw['is_active'] ? 'warning' : 'success'; ?>"
                            onclick="toggleGateway(<?php echo $gw['id']; ?>, <?php echo $gw['is_active'] ? 0 : 1; ?>)"
                            title="<?php echo $gw['is_active'] ? __('deactivate') : __('activate'); ?>">
                        <i class="bi bi-<?php echo $gw['is_active'] ? 'pause-circle' : 'play-circle'; ?>"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if ($gw['description']): ?>
                <p class="text-muted small mb-2"><?php echo htmlspecialchars($gw['description']); ?></p>
                <?php endif; ?>

                <div class="row g-2 mb-2" style="font-size:0.85rem;">
                    <div class="col-6">
                        <span class="text-muted"><?php _e('gw_api_key', 'API Key'); ?>:</span><br>
                        <code><?php echo $gw['api_key'] ? substr($gw['api_key'], 0, 12) . '...' : '<span class="text-warning">' . __('gw_not_set', 'Not set') . '</span>'; ?></code>
                    </div>
                    <div class="col-6">
                        <span class="text-muted"><?php _e('gw_merchant_id', 'Merchant ID'); ?>:</span><br>
                        <code><?php echo htmlspecialchars($gw['merchant_id'] ?: '-'); ?></code>
                    </div>
                    <div class="col-12">
                        <span class="text-muted"><?php _e('gw_callback_url', 'Callback URL'); ?>:</span><br>
                        <code class="text-break" style="font-size:0.75rem;"><?php echo htmlspecialchars($gw['callback_url'] ?: BASE_URL . 'api/gateway-callback.php?gateway=' . $gw['gateway_name']); ?></code>
                    </div>
                </div>

                <div class="d-flex gap-3 text-muted small">
                    <span><i class="bi bi-hash me-1"></i><?php echo (int)$gw['tx_count']; ?> <?php _e('gw_transactions_short', 'txns'); ?></span>
                    <span><i class="bi bi-check-circle me-1"></i><?php echo (int)$gw['tx_completed']; ?> <?php _e('completed'); ?></span>
                </div>
            </div>
            <div class="card-footer bg-transparent">
                <?php if (hasPermission('settings', 'edit')): ?>
                <button class="btn btn-sm btn-outline-primary" onclick="editGateway(<?php echo $gw['id']; ?>)">
                    <i class="bi bi-pencil me-1"></i><?php _e('edit'); ?>
                </button>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-secondary" onclick="copyCallbackUrl('<?php echo htmlspecialchars($gw['callback_url'] ?: BASE_URL . 'api/gateway-callback.php?gateway=' . $gw['gateway_name']); ?>')">
                    <i class="bi bi-clipboard me-1"></i><?php _e('gw_copy_url', 'Copy URL'); ?>
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Edit Gateway Modal -->
<div class="modal fade" id="editGatewayModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-credit-card me-2"></i><span id="modalTitle"><?php _e('gw_edit_gateway', 'Edit Gateway'); ?></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="gatewayForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="gw_id">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label"><?php _e('gw_name', 'Gateway Name'); ?> <span class="text-danger">*</span></label>
                            <input type="text" name="gateway_name" id="gw_name" class="form-control form-control-sm" required readonly>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?php _e('gw_display_name', 'Display Name'); ?></label>
                            <input type="text" name="display_name" id="gw_display" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php _e('description'); ?></label>
                            <textarea name="description" id="gw_desc" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?php _e('gw_api_key', 'API Key'); ?></label>
                            <input type="text" name="api_key" id="gw_apikey" class="form-control form-control-sm">
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?php _e('gw_api_secret', 'API Secret'); ?></label>
                            <input type="text" name="api_secret" id="gw_apisecret" class="form-control form-control-sm">
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?php _e('gw_merchant_id', 'Merchant ID'); ?></label>
                            <input type="text" name="merchant_id" id="gw_merchant" class="form-control form-control-sm">
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?php _e('gw_environment', 'Environment'); ?></label>
                            <select name="environment" id="gw_env" class="form-select form-select-sm">
                                <option value="sandbox">Sandbox</option>
                                <option value="production">Production</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php _e('gw_callback_url', 'Callback URL'); ?></label>
                            <input type="url" name="callback_url" id="gw_callback" class="form-control form-control-sm"
                                   placeholder="<?php echo BASE_URL; ?>api/gateway-callback.php?gateway=...">
                            <small class="text-muted"><?php _e('gw_callback_help', 'Leave blank to use default callback URL'); ?></small>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php _e('gw_extra_config', 'Extra Configuration (JSON)'); ?></label>
                            <textarea name="config_json" id="gw_config" class="form-control form-control-sm font-monospace" rows="3"
                                      placeholder='{"vendor_id": "...", "shortcode": "..."}'></textarea>
                            <small class="text-muted"><?php _e('gw_config_help', 'Gateway-specific parameters in JSON format'); ?></small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i><?php _e('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const API_URL = BASE_URL + 'api/payment-gateway.php';

// Global settings save
document.getElementById('globalSettingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    // Save each setting individually via settings API
    const settings = {};
    fd.forEach((v, k) => settings[k] = v);

    $.post(BASE_URL + 'api/settings.php', {
        action: 'save',
        settings: JSON.stringify(settings)
    }, function(res) {
        if (res.success) {
            showAlert('success', '<?php echo __("saved_successfully"); ?>');
        } else {
            showAlert('danger', res.message || 'Error');
        }
    }, 'json').fail(() => showAlert('danger', 'Network error'));
});

function generateSecret() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    for (let i = 0; i < 32; i++) result += chars.charAt(Math.floor(Math.random() * chars.length));
    document.querySelector('input[name="gateway_callback_secret"]').value = result;
}

function editGateway(id) {
    $.get(API_URL, { action: 'get_gateway', id: id }, function(res) {
        if (res.success) {
            const gw = res.data;
            $('#gw_id').val(gw.id);
            $('#gw_name').val(gw.gateway_name);
            $('#gw_display').val(gw.display_name);
            $('#gw_desc').val(gw.description);
            $('#gw_apikey').val(gw.api_key);
            $('#gw_apisecret').val(gw.api_secret_masked || gw.api_secret || '');
            $('#gw_merchant').val(gw.merchant_id);
            $('#gw_env').val(gw.environment);
            $('#gw_callback').val(gw.callback_url);
            $('#gw_config').val(gw.config_json ? (typeof gw.config_json === 'string' ? gw.config_json : JSON.stringify(gw.config_json, null, 2)) : '');
            new bootstrap.Modal('#editGatewayModal').show();
        }
    }, 'json');
}

document.getElementById('gatewayForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('action', 'save_gateway');

    $.ajax({
        url: API_URL, method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
        success: function(res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('editGatewayModal')).hide();
                location.reload();
            } else {
                showAlert('danger', res.message);
            }
        },
        error: () => showAlert('danger', 'Network error')
    });
});

function toggleGateway(id, active) {
    if (!confirm(active ? '<?php echo __("gw_confirm_activate", "Activate this gateway?"); ?>' : '<?php echo __("gw_confirm_deactivate", "Deactivate this gateway?"); ?>')) return;
    $.post(API_URL, { action: 'toggle_gateway', id: id, is_active: active }, function(res) {
        if (res.success) location.reload();
        else showAlert('danger', res.message);
    }, 'json');
}

function copyCallbackUrl(url) {
    navigator.clipboard.writeText(url).then(() => showAlert('success', '<?php echo __("gw_url_copied", "Callback URL copied to clipboard"); ?>'));
}

function showAlert(type, msg) {
    const html = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
    document.querySelector('.re-main').insertAdjacentHTML('afterbegin', html);
    setTimeout(() => { const a = document.querySelector('.alert-dismissible'); if (a) a.remove(); }, 4000);
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
