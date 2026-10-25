<?php
/**
 * FIS Real Estate — Payment Verification (Part 23.3)
 * View pending payments, confirm Airtel Money, auto-activate
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/header.php';
$pageTitle = __('adm_payment_verification', 'Payment Verification');

if (!isSaasOperator()) {
    echo '<div class="alert alert-danger">' . __('adm_admin_only', 'Admin access required') . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-credit-card-2-front me-2"></i><?php _e('adm_payment_verification', 'Payment Verification'); ?></h4>
        <small class="text-muted"><?php _e('adm_payment_subtitle', 'Review and confirm subscription payments'); ?></small>
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
                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>api/saas-admin.php?action=export_payments" target="_blank">
                    <i class="bi bi-file-earmark-spreadsheet me-2"></i><?php _e('adm_export_payments', 'Export Payments CSV'); ?>
                </a></li>
            </ul>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4" id="paymentStats">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 me-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:45px;height:45px;background:rgba(255,193,7,0.1);">
                            <i class="bi bi-clock-history text-warning" style="font-size:1.3rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h5 class="mb-0" id="statPending">-</h5>
                        <small class="text-muted"><?php _e('adm_pending', 'Pending'); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 me-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:45px;height:45px;background:rgba(255,193,7,0.1);">
                            <i class="bi bi-cash text-warning" style="font-size:1.3rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h5 class="mb-0" id="statPendingAmt">-</h5>
                        <small class="text-muted"><?php _e('adm_pending_amount', 'Pending Amount'); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 me-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:45px;height:45px;background:rgba(25,135,84,0.1);">
                            <i class="bi bi-check-circle text-success" style="font-size:1.3rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h5 class="mb-0" id="statConfirmed">-</h5>
                        <small class="text-muted"><?php _e('adm_confirmed', 'Confirmed'); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 me-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:45px;height:45px;background:rgba(26,107,74,0.1);">
                            <i class="bi bi-calendar-check text-success" style="font-size:1.3rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h5 class="mb-0" id="statThisMonth">-</h5>
                        <small class="text-muted"><?php _e('adm_this_month', 'This Month'); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1"><?php _e('status', 'Status'); ?></label>
                <select class="form-select form-select-sm" id="payFilterStatus">
                    <option value=""><?php _e('all', 'All'); ?></option>
                    <option value="pending" selected><?php _e('status_pending', 'Pending'); ?></option>
                    <option value="confirmed"><?php _e('adm_confirmed', 'Confirmed'); ?></option>
                    <option value="rejected"><?php _e('adm_rejected', 'Rejected'); ?></option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small mb-1"><?php _e('search', 'Search'); ?></label>
                <input type="text" class="form-control form-control-sm" id="payFilterSearch" placeholder="<?php _e('adm_pay_search_hint', 'Company, owner, phone, reference...'); ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100" onclick="loadPayments()">
                    <i class="bi bi-search me-1"></i><?php _e('search', 'Search'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Payments Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?php _e('adm_subscriber_col', 'Subscriber'); ?></th>
                        <th><?php _e('adm_tier_col', 'Tier'); ?></th>
                        <th><?php _e('amount', 'Amount'); ?></th>
                        <th><?php _e('adm_method', 'Method'); ?></th>
                        <th><?php _e('adm_reference', 'Reference'); ?></th>
                        <th><?php _e('adm_period', 'Period'); ?></th>
                        <th><?php _e('status', 'Status'); ?></th>
                        <th><?php _e('date', 'Date'); ?></th>
                        <th><?php _e('actions', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="paymentTableBody">
                    <tr><td colspan="9" class="text-center py-4 text-muted"><?php _e('loading', 'Loading...'); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white" id="paymentPagination"></div>
</div>

<!-- Confirm Payment Modal -->
<div class="modal fade" id="confirmPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h6 class="modal-title"><i class="bi bi-check-circle me-2"></i><?php _e('adm_confirm_payment', 'Confirm Payment'); ?></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="confirmPayId">
                <div class="mb-3">
                    <div class="p-3 bg-light rounded">
                        <strong id="confirmPayInfo"></strong>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php _e('adm_verification_notes', 'Verification Notes'); ?></label>
                    <textarea class="form-control" id="confirmPayNotes" rows="2" placeholder="<?php _e('adm_verification_notes_hint', 'Optional: transaction verified via...'); ?>"></textarea>
                </div>
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    <?php _e('adm_confirm_info', 'Confirming will auto-activate the subscription if it is in trial, suspended, or expired status.'); ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php _e('cancel', 'Cancel'); ?></button>
                <button class="btn btn-success btn-sm" onclick="submitConfirm()">
                    <i class="bi bi-check-lg me-1"></i><?php _e('adm_confirm_payment', 'Confirm Payment'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Reject Payment Modal -->
<div class="modal fade" id="rejectPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title"><i class="bi bi-x-circle me-2"></i><?php _e('adm_reject_payment', 'Reject Payment'); ?></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rejectPayId">
                <div class="mb-3">
                    <label class="form-label"><?php _e('adm_rejection_reason', 'Rejection Reason'); ?></label>
                    <textarea class="form-control" id="rejectPayNotes" rows="2" placeholder="<?php _e('adm_rejection_reason_hint', 'Why is this payment being rejected?'); ?>"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php _e('cancel', 'Cancel'); ?></button>
                <button class="btn btn-danger btn-sm" onclick="submitReject()">
                    <i class="bi bi-x-lg me-1"></i><?php _e('adm_reject_payment', 'Reject Payment'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const API_URL = BASE_URL + 'api/saas-admin.php';

function loadStats() {
    $.get(API_URL, {action: 'payment_stats'}, function(res) {
        if (!res.success) return;
        const d = res.data;
        $('#statPending').text(d.pending_count);
        $('#statPendingAmt').text('TZS ' + Number(d.pending_amount).toLocaleString());
        $('#statConfirmed').text(d.confirmed_count.toLocaleString());
        $('#statThisMonth').text('TZS ' + Number(d.this_month_amount).toLocaleString());
    });
}

function loadPayments(page) {
    page = page || 1;
    const params = {
        action: 'list_payments', page: page,
        status: $('#payFilterStatus').val(),
        search: $('#payFilterSearch').val()
    };
    $.get(API_URL, params, function(res) {
        if (!res.success) return;
        const tbody = $('#paymentTableBody');
        tbody.empty();
        if (!res.data.length) {
            tbody.html('<tr><td colspan="9" class="text-center py-4 text-muted"><?php _e('adm_no_payments', 'No payments found'); ?></td></tr>');
            $('#paymentPagination').html('');
            return;
        }
        res.data.forEach(function(p) {
            const statusColors = {pending:'warning', confirmed:'success', rejected:'danger'};
            const statusBadge = `<span class="badge bg-${statusColors[p.status] || 'secondary'} ${p.status === 'pending' ? 'text-dark' : ''}">${p.status}</span>`;
            const tierBadge = p.tier ? `<span class="badge bg-${p.tier === 'enterprise' ? 'success' : p.tier === 'professional' ? 'primary' : 'secondary'}">${p.tier}</span>` : '-';

            let actions = '';
            if (p.status === 'pending') {
                actions = `<div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-success" onclick="showConfirm(${p.id}, '${esc(p.company_name || '')}', 'TZS ${Number(p.amount).toLocaleString()}', '${esc(p.reference_number || '')}')" title="Confirm">
                        <i class="bi bi-check-circle"></i>
                    </button>
                    <button class="btn btn-outline-danger" onclick="showReject(${p.id})" title="Reject">
                        <i class="bi bi-x-circle"></i>
                    </button>
                </div>`;
            } else if (p.status === 'confirmed') {
                actions = '<small class="text-success"><i class="bi bi-check-lg"></i> ' + esc(p.confirmed_by || '') + '</small>';
            } else {
                actions = '<small class="text-danger">' + esc(p.verification_notes || 'Rejected') + '</small>';
            }

            const period = (p.period_from && p.period_to) ? `${p.period_from} — ${p.period_to}` : '-';

            tbody.append(`<tr class="${p.status === 'pending' ? 'table-warning' : ''}">
                <td><strong>${esc(p.company_name || '-')}</strong><br><small class="text-muted">${esc(p.owner_name || '')} | ${esc(p.owner_phone || '')}</small></td>
                <td>${tierBadge}</td>
                <td class="fw-semibold">TZS ${Number(p.amount).toLocaleString()}</td>
                <td><small>${esc(p.payment_method || '-')}</small></td>
                <td><code>${esc(p.reference_number || '-')}</code></td>
                <td><small>${period}</small></td>
                <td>${statusBadge}</td>
                <td><small>${p.payment_date || p.created_at?.substring(0,10) || '-'}</small></td>
                <td>${actions}</td>
            </tr>`);
        });
        renderPagination('#paymentPagination', res.pagination, loadPayments);
    });
}

function showConfirm(id, company, amount, ref) {
    $('#confirmPayId').val(id);
    $('#confirmPayInfo').html(company + ' &mdash; ' + amount + (ref ? ' (Ref: ' + ref + ')' : ''));
    $('#confirmPayNotes').val('');
    new bootstrap.Modal('#confirmPaymentModal').show();
}

function submitConfirm() {
    $.post(API_URL, {
        action: 'confirm_payment',
        id: $('#confirmPayId').val(),
        verification_notes: $('#confirmPayNotes').val()
    }, function(res) {
        bootstrap.Modal.getInstance(document.getElementById('confirmPaymentModal')).hide();
        alert(res.message);
        if (res.success) { loadPayments(); loadStats(); }
    });
}

function showReject(id) {
    $('#rejectPayId').val(id);
    $('#rejectPayNotes').val('');
    new bootstrap.Modal('#rejectPaymentModal').show();
}

function submitReject() {
    $.post(API_URL, {
        action: 'reject_payment',
        id: $('#rejectPayId').val(),
        verification_notes: $('#rejectPayNotes').val()
    }, function(res) {
        bootstrap.Modal.getInstance(document.getElementById('rejectPaymentModal')).hide();
        alert(res.message);
        if (res.success) { loadPayments(); loadStats(); }
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

$('#payFilterStatus').on('change', function() { loadPayments(); });
$('#payFilterSearch').on('keyup', function(e) { if (e.key === 'Enter') loadPayments(); });

$(document).ready(function() { loadStats(); loadPayments(); });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
