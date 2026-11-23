<?php
/**
 * FIS Real Estate — Subscriber Management (Part 23.2 + 23.4)
 * List subscribers, edit, suspend/activate, manage discounts
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/header.php';
$pageTitle = __('adm_subscribers', 'Subscribers');

if (!isSaasOperator()) {
    echo '<div class="alert alert-danger">' . __('adm_admin_only', 'Admin access required') . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-people-fill me-2"></i><?php _e('adm_subscriber_management', 'Subscriber Management'); ?></h4>
        <small class="text-muted"><?php _e('adm_subscriber_subtitle', 'View, edit, and manage all tenant subscriptions'); ?></small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i><?php _e('adm_back_dashboard', 'Back to Dashboard'); ?>
        </a>
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-download me-1"></i><?php _e('export', 'Export'); ?>
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>api/saas-admin.php?action=export_subscribers" target="_blank">
                    <i class="bi bi-file-earmark-spreadsheet me-2"></i><?php _e('adm_export_all', 'Export All CSV'); ?>
                </a></li>
            </ul>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1"><?php _e('adm_filter_tier', 'Tier'); ?></label>
                <select class="form-select form-select-sm" id="filterTier">
                    <option value=""><?php _e('all', 'All'); ?></option>
                    <option value="basic">Basic</option>
                    <option value="professional">Professional</option>
                    <option value="enterprise">Enterprise</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1"><?php _e('adm_filter_status', 'Status'); ?></label>
                <select class="form-select form-select-sm" id="filterStatus">
                    <option value=""><?php _e('all', 'All'); ?></option>
                    <option value="active"><?php _e('status_active', 'Active'); ?></option>
                    <option value="trial"><?php _e('adm_trial', 'Trial'); ?></option>
                    <option value="suspended"><?php _e('adm_suspended', 'Suspended'); ?></option>
                    <option value="cancelled"><?php _e('adm_cancelled', 'Cancelled'); ?></option>
                    <option value="expired"><?php _e('adm_expired', 'Expired'); ?></option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1"><?php _e('search', 'Search'); ?></label>
                <input type="text" class="form-control form-control-sm" id="filterSearch" placeholder="<?php _e('adm_search_hint', 'Company, owner, phone, email, slug...'); ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100" onclick="loadSubscribers()">
                    <i class="bi bi-search me-1"></i><?php _e('search', 'Search'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Subscribers Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?php _e('adm_company', 'Company'); ?></th>
                        <th><?php _e('adm_owner', 'Owner'); ?></th>
                        <th><?php _e('adm_tier_col', 'Tier'); ?></th>
                        <th><?php _e('adm_units_col', 'Units'); ?></th>
                        <th><?php _e('adm_billing_col', 'Billing'); ?></th>
                        <th><?php _e('adm_discount_col', 'Discount'); ?></th>
                        <th><?php _e('status', 'Status'); ?></th>
                        <th><?php _e('adm_expires', 'Expires'); ?></th>
                        <th><?php _e('actions', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="subscriberTableBody">
                    <tr><td colspan="9" class="text-center py-4 text-muted"><?php _e('loading', 'Loading...'); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white" id="subscriberPagination"></div>
</div>

<!-- Edit Subscriber Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-pencil-square me-2"></i><?php _e('adm_edit_subscriber', 'Edit Subscriber'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?php _e('adm_company', 'Company'); ?></label>
                        <input type="text" class="form-control" id="editCompany" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?php _e('adm_owner', 'Owner'); ?></label>
                        <input type="text" class="form-control" id="editOwner" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?php _e('adm_tier_col', 'Tier'); ?></label>
                        <select class="form-select" id="editTier">
                            <option value="basic">Basic</option>
                            <option value="professional">Professional</option>
                            <option value="enterprise">Enterprise</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?php _e('adm_units_col', 'Units'); ?></label>
                        <input type="number" class="form-control" id="editUnits" min="1">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?php _e('status', 'Status'); ?></label>
                        <select class="form-select" id="editStatus">
                            <option value="active"><?php _e('status_active', 'Active'); ?></option>
                            <option value="trial"><?php _e('adm_trial', 'Trial'); ?></option>
                            <option value="suspended"><?php _e('adm_suspended', 'Suspended'); ?></option>
                            <option value="cancelled"><?php _e('adm_cancelled', 'Cancelled'); ?></option>
                        </select>
                    </div>
                    <div class="col-md-12" id="suspendReasonGroup" style="display:none;">
                        <label class="form-label"><?php _e('adm_suspend_reason', 'Suspension Reason'); ?></label>
                        <input type="text" class="form-control" id="editSuspendReason" placeholder="<?php _e('adm_suspend_reason_hint', 'Reason for suspension'); ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label"><?php _e('adm_admin_notes', 'Admin Notes'); ?></label>
                        <textarea class="form-control" id="editNotes" rows="2"></textarea>
                    </div>
                </div>
                <!-- Payment History -->
                <hr>
                <h6 class="mb-3"><i class="bi bi-clock-history me-1"></i><?php _e('adm_payment_history', 'Recent Payments'); ?></h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('date', 'Date'); ?></th>
                                <th><?php _e('amount', 'Amount'); ?></th>
                                <th><?php _e('adm_method', 'Method'); ?></th>
                                <th><?php _e('adm_reference', 'Reference'); ?></th>
                                <th><?php _e('status', 'Status'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="editPayments"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php _e('cancel', 'Cancel'); ?></button>
                <button class="btn btn-primary btn-sm" onclick="saveSubscriber()">
                    <i class="bi bi-check-lg me-1"></i><?php _e('save', 'Save'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Discount Modal -->
<div class="modal fade" id="discountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-percent me-2"></i><?php _e('adm_manage_discount', 'Manage Discount'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="discountId">
                <div class="mb-3">
                    <label class="form-label"><?php _e('adm_subscriber_info', 'Subscriber'); ?></label>
                    <p class="form-control-plaintext fw-semibold" id="discountSubInfo">-</p>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?php _e('adm_discount_type', 'Discount Type'); ?></label>
                        <select class="form-select" id="discountType">
                            <option value="none"><?php _e('adm_no_discount', 'No Discount'); ?></option>
                            <option value="percentage"><?php _e('adm_percentage', 'Percentage (%)'); ?></option>
                            <option value="fixed"><?php _e('adm_fixed_per_unit', 'Fixed per Unit (TZS)'); ?></option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?php _e('adm_discount_value', 'Discount Value'); ?></label>
                        <input type="number" class="form-control" id="discountValue" min="0" step="any">
                    </div>
                </div>
                <div class="mt-3 p-3 bg-light rounded" id="discountPreview"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php _e('cancel', 'Cancel'); ?></button>
                <button class="btn btn-success btn-sm" onclick="applyDiscount()">
                    <i class="bi bi-check-lg me-1"></i><?php _e('adm_apply_discount', 'Apply Discount'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Suspend Modal -->
<div class="modal fade" id="suspendModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title"><i class="bi bi-pause-circle me-2"></i><?php _e('adm_suspend_subscriber', 'Suspend Subscriber'); ?></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="suspendId">
                <p><?php _e('adm_suspend_warning', 'This will immediately block the subscriber from accessing the system.'); ?></p>
                <div class="mb-3">
                    <label class="form-label"><?php _e('adm_suspend_reason', 'Reason'); ?></label>
                    <input type="text" class="form-control" id="suspendReason" placeholder="<?php _e('adm_suspend_reason_hint', 'Reason for suspension'); ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php _e('cancel', 'Cancel'); ?></button>
                <button class="btn btn-danger btn-sm" onclick="confirmSuspend()">
                    <i class="bi bi-pause-circle me-1"></i><?php _e('adm_confirm_suspend', 'Suspend'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const API_URL = BASE_URL + 'api/saas-admin.php';

function loadSubscribers(page) {
    page = page || 1;
    const params = {
        action: 'list_subscribers', page: page,
        tier: $('#filterTier').val(),
        status: $('#filterStatus').val(),
        search: $('#filterSearch').val()
    };
    $.get(API_URL, params, function(res) {
        if (!res.success) return;
        const tbody = $('#subscriberTableBody');
        tbody.empty();
        if (!res.data.length) {
            tbody.html('<tr><td colspan="9" class="text-center py-4 text-muted"><?php _e('adm_no_subscribers', 'No subscribers found'); ?></td></tr>');
            $('#subscriberPagination').html('');
            return;
        }
        res.data.forEach(function(s) {
            const tierColors = {basic:'secondary', professional:'primary', enterprise:'success'};
            const statusColors = {active:'success', trial:'info', suspended:'danger', cancelled:'dark', expired:'warning'};
            const tierBadge = `<span class="badge bg-${tierColors[s.tier] || 'secondary'}">${s.tier}</span>`;
            const statusBadge = `<span class="badge bg-${statusColors[s.status] || 'secondary'}">${s.status}</span>`;
            const discount = s.discount_type === 'none' || !s.discount_type ? '<small class="text-muted">-</small>'
                : s.discount_type === 'percentage' ? `<span class="badge bg-warning text-dark">${s.discount_value}%</span>`
                : `<span class="badge bg-warning text-dark">TZS ${Number(s.discount_value).toLocaleString()}/u</span>`;

            let actions = `<div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="editSubscriber(${s.id})" title="Edit"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-outline-warning" onclick="showDiscount(${s.id}, '${esc(s.company_name)}', '${s.discount_type || 'none'}', ${s.discount_value || 0})" title="Discount"><i class="bi bi-percent"></i></button>`;
            if (s.status === 'active' || s.status === 'trial') {
                actions += `<button class="btn btn-outline-danger" onclick="showSuspend(${s.id})" title="Suspend"><i class="bi bi-pause-circle"></i></button>`;
            } else if (s.status === 'suspended') {
                actions += `<button class="btn btn-outline-success" onclick="activateSubscriber(${s.id})" title="Activate"><i class="bi bi-play-circle"></i></button>`;
            }
            actions += '</div>';

            tbody.append(`<tr>
                <td><strong>${esc(s.company_name)}</strong><br><small class="text-muted"><code>${esc(s.tenant_slug)}</code></small></td>
                <td>${esc(s.owner_name)}<br><small class="text-muted">${esc(s.owner_phone)}</small></td>
                <td>${tierBadge}</td>
                <td>${s.unit_count}</td>
                <td>TZS ${Number(s.net_amount).toLocaleString()}<br><small class="text-muted">${Number(s.gross_amount).toLocaleString()} gross</small></td>
                <td>${discount}</td>
                <td>${statusBadge}</td>
                <td><small>${s.end_date || '-'}</small></td>
                <td>${actions}</td>
            </tr>`);
        });
        renderPagination('#subscriberPagination', res.pagination, loadSubscribers);
    });
}

function editSubscriber(id) {
    $.get(API_URL, {action: 'get_subscriber', id: id}, function(res) {
        if (!res.success) return;
        const s = res.data;
        $('#editId').val(s.id);
        $('#editCompany').val(s.company_name);
        $('#editOwner').val(s.owner_name + ' (' + s.owner_phone + ')');
        $('#editTier').val(s.tier);
        $('#editUnits').val(s.unit_count);
        $('#editStatus').val(s.status);
        $('#editNotes').val(s.admin_notes || '');
        $('#editSuspendReason').val(s.suspended_reason || '');
        toggleSuspendReason();

        const ptbody = $('#editPayments');
        ptbody.empty();
        if (res.payments && res.payments.length) {
            res.payments.forEach(function(p) {
                const pStatus = p.status === 'confirmed' ? '<span class="badge bg-success">Confirmed</span>'
                    : p.status === 'rejected' ? '<span class="badge bg-danger">Rejected</span>'
                    : '<span class="badge bg-warning text-dark">Pending</span>';
                ptbody.append(`<tr>
                    <td><small>${p.payment_date || p.created_at?.substring(0,10) || '-'}</small></td>
                    <td>TZS ${Number(p.amount).toLocaleString()}</td>
                    <td><small>${esc(p.payment_method || '-')}</small></td>
                    <td><small>${esc(p.reference_number || '-')}</small></td>
                    <td>${pStatus}</td>
                </tr>`);
            });
        } else {
            ptbody.html('<tr><td colspan="5" class="text-center text-muted">No payments</td></tr>');
        }

        new bootstrap.Modal('#editModal').show();
    });
}

function toggleSuspendReason() {
    if ($('#editStatus').val() === 'suspended') {
        $('#suspendReasonGroup').show();
    } else {
        $('#suspendReasonGroup').hide();
    }
}
$('#editStatus').on('change', toggleSuspendReason);

function saveSubscriber() {
    $.post(API_URL, {
        action: 'update_subscriber',
        id: $('#editId').val(),
        tier: $('#editTier').val(),
        unit_count: $('#editUnits').val(),
        status: $('#editStatus').val(),
        admin_notes: $('#editNotes').val(),
        suspended_reason: $('#editSuspendReason').val()
    }, function(res) {
        bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
        alert(res.message);
        if (res.success) loadSubscribers();
    });
}

function showDiscount(id, company, type, value) {
    $('#discountId').val(id);
    $('#discountSubInfo').text(company);
    $('#discountType').val(type || 'none');
    $('#discountValue').val(value || 0);
    updateDiscountPreview();
    new bootstrap.Modal('#discountModal').show();
}

function updateDiscountPreview() {
    const type = $('#discountType').val();
    const val = parseFloat($('#discountValue').val()) || 0;
    if (type === 'none') {
        $('#discountPreview').html('<small class="text-muted"><?php _e('adm_no_discount_applied', 'No discount will be applied'); ?></small>');
    } else if (type === 'percentage') {
        $('#discountPreview').html(`<small><strong>${val}%</strong> <?php _e('adm_discount_off_gross', 'off gross billing amount'); ?></small>`);
    } else {
        $('#discountPreview').html(`<small><strong>TZS ${val.toLocaleString()}</strong> <?php _e('adm_discount_per_unit', 'discount per unit per month'); ?></small>`);
    }
}
$('#discountType, #discountValue').on('change keyup', updateDiscountPreview);

function applyDiscount() {
    $.post(API_URL, {
        action: 'apply_discount',
        id: $('#discountId').val(),
        discount_type: $('#discountType').val(),
        discount_value: $('#discountValue').val()
    }, function(res) {
        bootstrap.Modal.getInstance(document.getElementById('discountModal')).hide();
        alert(res.message);
        if (res.success) loadSubscribers();
    });
}

function showSuspend(id) {
    $('#suspendId').val(id);
    $('#suspendReason').val('');
    new bootstrap.Modal('#suspendModal').show();
}

function confirmSuspend() {
    $.post(API_URL, {
        action: 'toggle_status',
        id: $('#suspendId').val(),
        new_status: 'suspended',
        reason: $('#suspendReason').val()
    }, function(res) {
        bootstrap.Modal.getInstance(document.getElementById('suspendModal')).hide();
        alert(res.message);
        if (res.success) loadSubscribers();
    });
}

function activateSubscriber(id) {
    if (!confirm('<?php _e('adm_confirm_activate', 'Activate this subscriber?'); ?>')) return;
    $.post(API_URL, {action: 'toggle_status', id: id, new_status: 'active'}, function(res) {
        alert(res.message);
        if (res.success) loadSubscribers();
    });
}

function renderPagination(selector, p, callback) {
    if (!p || p.total_pages <= 1) { $(selector).html(''); return; }
    let html = '<nav><ul class="pagination pagination-sm mb-0 justify-content-center">';
    for (let i = 1; i <= p.total_pages; i++) {
        html += `<li class="page-item ${i === p.page ? 'active' : ''}"><a class="page-link" href="#" onclick="event.preventDefault();${callback.name}(${i})">${i}</a></li>`;
    }
    html += '</ul></nav>';
    $(selector).html(html);
}

function esc(s) { return $('<span>').text(s || '').html(); }

$('#filterTier, #filterStatus').on('change', function() { loadSubscribers(); });
$('#filterSearch').on('keyup', function(e) { if (e.key === 'Enter') loadSubscribers(); });

$(document).ready(function() { loadSubscribers(); });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
