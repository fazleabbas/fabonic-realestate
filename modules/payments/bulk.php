<?php
/**
 * FIS Real Estate — Bulk Payment Recording (9.5)
 * Record payments for multiple tenants at once.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('bulk_payment', 'Bulk Payment');

$subStatus = enforceSubscription();

if (!hasPermission('payments', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Get active leases with outstanding info
$leasesStmt = $conn->query("
    SELECT l.id, l.rent_amount, l.currency, l.payment_frequency, l.payment_due_day,
           l.start_date, l.end_date,
           u.unit_number, u.id AS unit_id, p.name AS property_name, p.id AS property_id,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.phone AS tenant_phone,
           t.id AS tenant_id,
           (SELECT COALESCE(SUM(py.amount),0) FROM payments py WHERE py.lease_id = l.id AND py.status = 'recorded') AS total_paid
    FROM leases l
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON l.tenant_id = t.id
    WHERE l.status = 'active'" . propertyScopeSql('u.property_id') . "
    ORDER BY p.name, u.unit_number
");
$activeLeases = $leasesStmt->fetchAll();

// Properties for filter
$propStmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name");
$properties = $propStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-list-check me-2"></i><?php _e('bulk_payment', 'Bulk Payment'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/payments/"><?php _e('payments'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('bulk_payment', 'Bulk'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('payments.bulk'); ?>

<!-- Instructions -->
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>
    <?php _e('bulk_payment_info', 'Select the leases you want to record payments for, fill in the details, and submit all at once. Each row will create a separate payment with an auto-generated receipt.'); ?>
</div>

<!-- Filter by Property -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-center">
            <div class="col-auto">
                <label class="form-label mb-0 fw-semibold small"><?php _e('filter_by_property', 'Filter by Property'); ?>:</label>
            </div>
            <div class="col-12 col-md-4">
                <select id="propertyFilter" class="form-select form-select-sm">
                    <option value=""><?php _e('all_properties', 'All Properties'); ?></option>
                    <?php foreach ($properties as $prop): ?>
                    <option value="<?php echo $prop['id']; ?>"><?php echo htmlspecialchars($prop['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-sm btn-outline-primary" id="selectAllBtn"><?php _e('select_all', 'Select All'); ?></button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAllBtn"><?php _e('deselect_all', 'Deselect All'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php if (empty($activeLeases)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-file-earmark-text" style="font-size:3rem;color:#ccc;"></i>
        <h5 class="mt-3 text-muted"><?php _e('no_active_leases', 'No active leases found'); ?></h5>
    </div>
</div>
<?php else: ?>

<!-- Bulk Form -->
<form id="bulkPaymentForm">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRF(); ?>">

    <!-- Global Settings -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-gear me-1"></i> <?php _e('bulk_settings', 'Bulk Settings'); ?></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('payment_date'); ?></label>
                    <input type="date" class="form-control form-control-sm" id="bulkDate" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('payment_method'); ?></label>
                    <select class="form-select form-select-sm" id="bulkMethod">
                        <option value="cash"><?php _e('cash', 'Cash'); ?></option>
                        <option value="bank_transfer"><?php _e('bank_transfer', 'Bank Transfer'); ?></option>
                        <option value="mobile_money"><?php _e('mobile_money', 'Mobile Money'); ?></option>
                        <option value="cheque"><?php _e('cheque', 'Cheque'); ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('period_from', 'Period From'); ?></label>
                    <input type="date" class="form-control form-control-sm" id="bulkPeriodFrom" value="<?php echo date('Y-m-01'); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold"><?php _e('period_to', 'Period To'); ?></label>
                    <input type="date" class="form-control form-control-sm" id="bulkPeriodTo" value="<?php echo date('Y-m-t'); ?>">
                </div>
            </div>
            <div class="mt-2">
                <button type="button" class="btn btn-sm btn-outline-primary" id="applyBulkSettingsBtn">
                    <i class="bi bi-arrow-down me-1"></i><?php _e('apply_to_all', 'Apply to All Rows'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Lease Rows -->
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="bulkTable">
                <thead>
                    <tr>
                        <th style="width:40px;">
                            <input type="checkbox" class="form-check-input" id="masterCheck">
                        </th>
                        <th><?php _e('tenant'); ?></th>
                        <th><?php _e('unit_number', 'Unit'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('property'); ?></th>
                        <th class="text-end"><?php _e('rent'); ?></th>
                        <th class="text-end"><?php _e('amount'); ?></th>
                        <th class="d-none d-lg-table-cell"><?php _e('reference', 'Ref'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeLeases as $l): ?>
                    <tr class="bulk-row" data-property-id="<?php echo $l['property_id']; ?>" data-lease-id="<?php echo $l['id']; ?>">
                        <td>
                            <input type="checkbox" class="form-check-input row-check" data-lease="<?php echo $l['id']; ?>">
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($l['tenant_name']); ?></strong>
                            <br><small class="text-muted"><?php echo htmlspecialchars($l['tenant_phone']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($l['unit_number']); ?></td>
                        <td class="d-none d-md-table-cell"><small><?php echo htmlspecialchars($l['property_name']); ?></small></td>
                        <td class="text-end"><small class="text-muted"><?php echo formatCurrency($l['rent_amount'], $l['currency']); ?></small></td>
                        <td class="text-end">
                            <input type="number" class="form-control form-control-sm row-amount" style="width:120px;display:inline-block;" step="0.01" min="0" value="<?php echo $l['rent_amount']; ?>" data-rent="<?php echo $l['rent_amount']; ?>">
                        </td>
                        <td class="d-none d-lg-table-cell">
                            <input type="text" class="form-control form-control-sm row-reference" style="width:120px;" placeholder="<?php _e('reference', 'Ref'); ?>">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Summary & Submit -->
    <div class="card mt-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <span class="fw-semibold"><?php _e('selected', 'Selected'); ?>: <span id="selectedCount">0</span></span>
                <span class="ms-3"><?php _e('total'); ?>: <strong id="bulkTotal"><?php echo DEFAULT_CURRENCY; ?> 0</strong></span>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo BASE_URL; ?>modules/payments/" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
                </a>
                <button type="button" class="btn btn-primary" id="submitBulkBtn" disabled>
                    <i class="bi bi-check-lg me-1"></i> <?php _e('record_payments', 'Record Payments'); ?>
                </button>
            </div>
        </div>
    </div>
</form>

<?php endif; ?>

<!-- Result Modal -->
<div class="modal fade" id="resultModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-check-circle me-1"></i> <?php _e('bulk_result', 'Bulk Payment Result'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="resultBody"></div>
            <div class="modal-footer">
                <a href="<?php echo BASE_URL; ?>modules/payments/" class="btn btn-primary"><?php _e('view_payments', 'View Payments'); ?></a>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo generateCSRF(); ?>';
const apiUrl = BASE_URL + 'api/payments.php';

// Property filter
$('#propertyFilter').on('change', function() {
    const propId = $(this).val();
    if (propId) {
        $('.bulk-row').hide().filter('[data-property-id="'+propId+'"]').show();
    } else {
        $('.bulk-row').show();
    }
    updateSummary();
});

// Master checkbox
$('#masterCheck').on('change', function() {
    const checked = this.checked;
    $('.bulk-row:visible .row-check').prop('checked', checked);
    updateSummary();
});

// Select/deselect all buttons
$('#selectAllBtn').on('click', function() {
    $('.bulk-row:visible .row-check').prop('checked', true);
    updateSummary();
});
$('#deselectAllBtn').on('click', function() {
    $('.bulk-row:visible .row-check').prop('checked', false);
    updateSummary();
});

// Individual checkbox
$(document).on('change', '.row-check', function() { updateSummary(); });
$(document).on('input', '.row-amount', function() { updateSummary(); });

// Apply bulk settings
$('#applyBulkSettingsBtn').on('click', function() {
    // Nothing extra needed — bulk date/method/period are read at submit time
    // But we can flash feedback
    $(this).text('<?php echo __("applied", "Applied!"); ?>');
    setTimeout(() => $(this).html('<i class="bi bi-arrow-down me-1"></i><?php echo __("apply_to_all", "Apply to All Rows"); ?>'), 1500);
});

function updateSummary() {
    let count = 0, total = 0;
    $('.bulk-row:visible').each(function() {
        if ($(this).find('.row-check').is(':checked')) {
            count++;
            total += parseFloat($(this).find('.row-amount').val()) || 0;
        }
    });
    $('#selectedCount').text(count);
    $('#bulkTotal').text('<?php echo DEFAULT_CURRENCY; ?> ' + total.toLocaleString());
    $('#submitBulkBtn').prop('disabled', count === 0);
}

// Submit bulk payments
$('#submitBulkBtn').on('click', function() {
    const btn = $(this);
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> <?php echo __("processing", "Processing..."); ?>');

    const bulkDate = $('#bulkDate').val();
    const bulkMethod = $('#bulkMethod').val();
    const bulkPeriodFrom = $('#bulkPeriodFrom').val();
    const bulkPeriodTo = $('#bulkPeriodTo').val();

    const payments = [];
    $('.bulk-row:visible').each(function() {
        if ($(this).find('.row-check').is(':checked')) {
            payments.push({
                lease_id: $(this).data('lease-id'),
                amount: parseFloat($(this).find('.row-amount').val()) || 0,
                payment_date: bulkDate,
                payment_method: bulkMethod,
                reference_number: $(this).find('.row-reference').val() || '',
                period_from: bulkPeriodFrom,
                period_to: bulkPeriodTo
            });
        }
    });

    if (payments.length === 0) {
        alert('<?php echo __("no_selection", "No payments selected"); ?>');
        btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i> <?php echo __("record_payments", "Record Payments"); ?>');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'bulk_create');
    formData.append('csrf_token', csrfToken);
    formData.append('payments_json', JSON.stringify(payments));

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            let html = '';
            if (data.success) {
                html = '<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>' + data.message + '</div>';
                if (data.errors && data.errors.length > 0) {
                    html += '<div class="alert alert-warning"><ul class="mb-0">';
                    data.errors.forEach(e => html += '<li>' + e + '</li>');
                    html += '</ul></div>';
                }
            } else {
                html = '<div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i>' + data.message + '</div>';
            }
            $('#resultBody').html(html);
            new bootstrap.Modal(document.getElementById('resultModal')).show();
            btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i> <?php echo __("record_payments", "Record Payments"); ?>');
        })
        .catch(() => {
            alert('Request failed');
            btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i> <?php echo __("record_payments", "Record Payments"); ?>');
        });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
