<?php
/**
 * FIS Real Estate — Sales Agent Management (Part 22)
 * Admin page: commission config, agent list, referrals, commissions
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/header.php';
$pageTitle = __('sa_sales_agents', 'Sales Agents');

// Admin only
if (!isSaasOperator()) {
    echo '<div class="alert alert-danger">' . __('sa_admin_only', 'Admin access required') . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-people-fill me-2"></i><?php _e('sa_sales_agents', 'Sales Agents'); ?></h4>
        <small class="text-muted"><?php _e('sa_subtitle', 'Manage commission structure, referrals, and agent performance'); ?></small>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-success btn-sm" onclick="processCommissions()">
            <i class="bi bi-play-circle me-1"></i><?php _e('sa_process_commissions', 'Process Commissions'); ?>
        </button>
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-download me-1"></i><?php _e('sa_export', 'Export'); ?>
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>api/sales-agent.php?action=export_commissions" target="_blank">
                    <i class="bi bi-file-earmark-spreadsheet me-2"></i><?php _e('sa_export_commissions', 'Export Commissions CSV'); ?>
                </a></li>
                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>api/sales-agent.php?action=export_referrals" target="_blank">
                    <i class="bi bi-file-earmark-spreadsheet me-2"></i><?php _e('sa_export_referrals', 'Export Referrals CSV'); ?>
                </a></li>
            </ul>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4" id="statsCards">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 me-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:45px;height:45px;background:rgba(26,107,74,0.1);"><i class="bi bi-people text-success" style="font-size:1.3rem;"></i></div>
                    </div>
                    <div>
                        <h5 class="mb-0" id="statAgents">-</h5>
                        <small class="text-muted"><?php _e('sa_active_agents', 'Active Agents'); ?></small>
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
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:45px;height:45px;background:rgba(232,168,56,0.1);"><i class="bi bi-share text-warning" style="font-size:1.3rem;"></i></div>
                    </div>
                    <div>
                        <h5 class="mb-0" id="statReferrals">-</h5>
                        <small class="text-muted"><?php _e('sa_total_referrals', 'Total Referrals'); ?></small>
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
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:45px;height:45px;background:rgba(13,110,253,0.1);"><i class="bi bi-check-circle text-primary" style="font-size:1.3rem;"></i></div>
                    </div>
                    <div>
                        <h5 class="mb-0" id="statConverted">-</h5>
                        <small class="text-muted"><?php _e('sa_converted', 'Converted'); ?></small>
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
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:45px;height:45px;background:rgba(25,135,84,0.1);"><i class="bi bi-cash-stack text-success" style="font-size:1.3rem;"></i></div>
                    </div>
                    <div>
                        <h5 class="mb-0" id="statCommissions">-</h5>
                        <small class="text-muted"><?php _e('sa_total_commissions', 'Total Commissions'); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#tabConfig">
            <i class="bi bi-gear me-1"></i><?php _e('sa_commission_config', 'Commission Config'); ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tabAgents">
            <i class="bi bi-people me-1"></i><?php _e('sa_agents', 'Agents'); ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tabReferrals">
            <i class="bi bi-share me-1"></i><?php _e('sa_referrals', 'Referrals'); ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tabCommissions">
            <i class="bi bi-cash-stack me-1"></i><?php _e('sa_commissions', 'Commissions'); ?>
        </a>
    </li>
</ul>

<div class="tab-content">

    <!-- Commission Config Tab -->
    <div class="tab-pane fade show active" id="tabConfig">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-sliders me-2"></i><?php _e('sa_commission_structure', 'Commission Structure'); ?></h6>
            </div>
            <div class="card-body">
                <form id="configForm">
                    <div class="row g-3">
                        <!-- Enable / Type -->
                        <div class="col-md-6">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="cfgEnabled" name="re_commission_enabled">
                                <label class="form-check-label fw-semibold"><?php _e('sa_commissions_enabled', 'Enable Agent Commissions'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('sa_commission_type', 'Commission Type'); ?></label>
                            <select class="form-select form-select-sm" name="re_commission_type" id="cfgType">
                                <option value="fixed"><?php _e('sa_type_fixed', 'Fixed Amount (TZS)'); ?></option>
                                <option value="percentage"><?php _e('sa_type_percentage', 'Percentage of Billing'); ?></option>
                            </select>
                        </div>

                        <!-- Direct Commission Rates -->
                        <div class="col-12"><h6 class="text-success mb-0 mt-2"><i class="bi bi-arrow-right me-1"></i><?php _e('sa_direct_commissions', 'Direct Commissions (Agent → Customer)'); ?></h6></div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('sa_month_1', 'Month 1'); ?></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text commRateLabel">TZS</span>
                                <input type="number" class="form-control" name="re_commission_direct_m1" id="cfgDirect1" min="0" step="any">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('sa_month_2', 'Month 2'); ?></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text commRateLabel">TZS</span>
                                <input type="number" class="form-control" name="re_commission_direct_m2" id="cfgDirect2" min="0" step="any">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('sa_month_3', 'Month 3'); ?></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text commRateLabel">TZS</span>
                                <input type="number" class="form-control" name="re_commission_direct_m3" id="cfgDirect3" min="0" step="any">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('sa_month_4_plus', 'Month 4+'); ?></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text commRateLabel">TZS</span>
                                <input type="number" class="form-control" name="re_commission_direct_recurring" id="cfgDirectR" min="0" step="any">
                            </div>
                        </div>

                        <!-- Override Commission Rates -->
                        <div class="col-12"><h6 class="text-primary mb-0 mt-2"><i class="bi bi-diagram-3 me-1"></i><?php _e('sa_override_commissions', 'Override Commissions (Parent Agent)'); ?></h6></div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('sa_month_1', 'Month 1'); ?></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text commRateLabel">TZS</span>
                                <input type="number" class="form-control" name="re_commission_override_m1" id="cfgOverride1" min="0" step="any">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('sa_month_2', 'Month 2'); ?></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text commRateLabel">TZS</span>
                                <input type="number" class="form-control" name="re_commission_override_m2" id="cfgOverride2" min="0" step="any">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('sa_month_3', 'Month 3'); ?></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text commRateLabel">TZS</span>
                                <input type="number" class="form-control" name="re_commission_override_m3" id="cfgOverride3" min="0" step="any">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('sa_month_4_plus', 'Month 4+'); ?></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text commRateLabel">TZS</span>
                                <input type="number" class="form-control" name="re_commission_override_recurring" id="cfgOverrideR" min="0" step="any">
                            </div>
                        </div>

                        <!-- Additional settings -->
                        <div class="col-md-4">
                            <label class="form-label"><?php _e('sa_min_payout', 'Minimum Payout (TZS)'); ?></label>
                            <input type="number" class="form-control form-control-sm" name="re_min_payout" id="cfgMinPayout" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php _e('sa_max_months', 'Max Commission Months'); ?></label>
                            <input type="number" class="form-control form-control-sm" name="re_max_commission_months" id="cfgMaxMonths" min="0">
                            <small class="text-muted"><?php _e('sa_max_months_hint', '0 = unlimited'); ?></small>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i><?php _e('save', 'Save'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Agents Tab -->
    <div class="tab-pane fade" id="tabAgents">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0"><?php _e('sa_agent_list', 'Agent List'); ?></h6>
                    <div class="d-flex gap-2">
                        <select class="form-select form-select-sm" id="agentStatusFilter" style="width:auto;">
                            <option value=""><?php _e('sa_all_statuses', 'All Statuses'); ?></option>
                            <option value="active"><?php _e('status_active', 'Active'); ?></option>
                            <option value="inactive"><?php _e('status_inactive', 'Inactive'); ?></option>
                            <option value="pending"><?php _e('status_pending', 'Pending'); ?></option>
                        </select>
                        <input type="text" class="form-control form-control-sm" id="agentSearch" placeholder="<?php _e('search', 'Search'); ?>..." style="width:200px;">
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('name', 'Name'); ?></th>
                                <th><?php _e('phone', 'Phone'); ?></th>
                                <th><?php _e('sa_referral_code', 'Referral Code'); ?></th>
                                <th><?php _e('sa_re_referrals', 'RE Referrals'); ?></th>
                                <th><?php _e('sa_re_converted', 'Converted'); ?></th>
                                <th><?php _e('sa_re_earned', 'RE Earned'); ?></th>
                                <th><?php _e('sa_re_balance', 'RE Balance'); ?></th>
                                <th><?php _e('status', 'Status'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="agentTableBody">
                            <tr><td colspan="8" class="text-center py-4 text-muted"><?php _e('loading', 'Loading...'); ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white" id="agentPagination"></div>
        </div>
    </div>

    <!-- Referrals Tab -->
    <div class="tab-pane fade" id="tabReferrals">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0"><?php _e('sa_referral_list', 'Referral List'); ?></h6>
                    <div class="d-flex gap-2">
                        <select class="form-select form-select-sm" id="refStatusFilter" style="width:auto;">
                            <option value=""><?php _e('sa_all_statuses', 'All Statuses'); ?></option>
                            <option value="referred"><?php _e('sa_status_referred', 'Referred'); ?></option>
                            <option value="trial"><?php _e('sa_status_trial', 'Trial'); ?></option>
                            <option value="converted"><?php _e('sa_status_converted', 'Converted'); ?></option>
                            <option value="churned"><?php _e('sa_status_churned', 'Churned'); ?></option>
                        </select>
                        <input type="text" class="form-control form-control-sm" id="refSearch" placeholder="<?php _e('search', 'Search'); ?>..." style="width:200px;">
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('sa_agent', 'Agent'); ?></th>
                                <th><?php _e('sa_customer', 'Customer'); ?></th>
                                <th><?php _e('sa_company', 'Company'); ?></th>
                                <th><?php _e('sa_tier', 'Tier'); ?></th>
                                <th><?php _e('sa_billing', 'Billing'); ?></th>
                                <th><?php _e('sa_months_active', 'Months'); ?></th>
                                <th><?php _e('status', 'Status'); ?></th>
                                <th><?php _e('sa_referred_at', 'Referred'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="refTableBody">
                            <tr><td colspan="8" class="text-center py-4 text-muted"><?php _e('loading', 'Loading...'); ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white" id="refPagination"></div>
        </div>
    </div>

    <!-- Commissions Tab -->
    <div class="tab-pane fade" id="tabCommissions">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0"><?php _e('sa_commission_log', 'Commission Log'); ?></h6>
                    <div class="d-flex gap-2">
                        <select class="form-select form-select-sm" id="commStatusFilter" style="width:auto;">
                            <option value=""><?php _e('all', 'All'); ?></option>
                            <option value="pending"><?php _e('status_pending', 'Pending'); ?></option>
                            <option value="paid"><?php _e('sa_status_paid', 'Paid'); ?></option>
                        </select>
                        <select class="form-select form-select-sm" id="commTypeFilter" style="width:auto;">
                            <option value=""><?php _e('sa_all_types', 'All Types'); ?></option>
                            <option value="direct"><?php _e('sa_type_direct', 'Direct'); ?></option>
                            <option value="override"><?php _e('sa_type_override', 'Override'); ?></option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php _e('sa_agent', 'Agent'); ?></th>
                                <th><?php _e('sa_customer', 'Customer'); ?></th>
                                <th><?php _e('sa_type', 'Type'); ?></th>
                                <th><?php _e('sa_month_col', 'Month'); ?></th>
                                <th><?php _e('amount', 'Amount'); ?></th>
                                <th><?php _e('sa_billing', 'Billing'); ?></th>
                                <th><?php _e('status', 'Status'); ?></th>
                                <th><?php _e('sa_credited_at', 'Credited'); ?></th>
                                <th><?php _e('actions', 'Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="commTableBody">
                            <tr><td colspan="9" class="text-center py-4 text-muted"><?php _e('loading', 'Loading...'); ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white" id="commPagination"></div>
        </div>
    </div>
</div>

<!-- Mark Paid Modal -->
<div class="modal fade" id="markPaidModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-check-circle me-2"></i><?php _e('sa_mark_paid', 'Mark as Paid'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="markPaidId">
                <div class="mb-3">
                    <label class="form-label"><?php _e('sa_payment_ref', 'Payment Reference'); ?></label>
                    <input type="text" class="form-control" id="markPaidRef" placeholder="<?php _e('sa_payment_ref_hint', 'Transaction reference / receipt number'); ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php _e('cancel', 'Cancel'); ?></button>
                <button class="btn btn-success btn-sm" onclick="submitMarkPaid()">
                    <i class="bi bi-check-lg me-1"></i><?php _e('sa_mark_paid', 'Mark as Paid'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const API_URL = BASE_URL + 'api/sales-agent.php';

// Load stats
function loadStats() {
    $.get(API_URL, {action: 'stats'}, function(res) {
        if (res.success) {
            const d = res.data;
            $('#statAgents').text(d.total_agents.toLocaleString());
            $('#statReferrals').text(d.total_referrals.toLocaleString());
            $('#statConverted').text(d.converted_referrals.toLocaleString());
            $('#statCommissions').text('TZS ' + d.total_commissions.toLocaleString());
        }
    });
}

// Load config
function loadConfig() {
    $.get(API_URL, {action: 'get_settings'}, function(res) {
        if (res.success) {
            const s = res.data;
            $('#cfgEnabled').prop('checked', s.re_commission_enabled === '1');
            $('#cfgType').val(s.re_commission_type || 'fixed');
            $('#cfgDirect1').val(s.re_commission_direct_m1 || 0);
            $('#cfgDirect2').val(s.re_commission_direct_m2 || 0);
            $('#cfgDirect3').val(s.re_commission_direct_m3 || 0);
            $('#cfgDirectR').val(s.re_commission_direct_recurring || 0);
            $('#cfgOverride1').val(s.re_commission_override_m1 || 0);
            $('#cfgOverride2').val(s.re_commission_override_m2 || 0);
            $('#cfgOverride3').val(s.re_commission_override_m3 || 0);
            $('#cfgOverrideR').val(s.re_commission_override_recurring || 0);
            $('#cfgMinPayout').val(s.re_min_payout || 0);
            $('#cfgMaxMonths').val(s.re_max_commission_months || 12);
            updateRateLabels();
        }
    });
}

// Update rate labels
function updateRateLabels() {
    const type = $('#cfgType').val();
    $('.commRateLabel').text(type === 'percentage' ? '%' : 'TZS');
}
$('#cfgType').on('change', updateRateLabels);

// Save config
$('#configForm').on('submit', function(e) {
    e.preventDefault();
    const data = {action: 'save_settings'};
    data.re_commission_enabled = $('#cfgEnabled').is(':checked') ? '1' : '0';
    $(this).find('input[name], select[name]').each(function() {
        data[$(this).attr('name')] = $(this).val();
    });
    data.re_commission_enabled = $('#cfgEnabled').is(':checked') ? '1' : '0';
    $.post(API_URL, data, function(res) {
        alert(res.message || 'Saved');
    });
});

// Load agents
function loadAgents(page) {
    page = page || 1;
    const params = {
        action: 'list_agents', page: page,
        status: $('#agentStatusFilter').val(),
        search: $('#agentSearch').val()
    };
    $.get(API_URL, params, function(res) {
        if (!res.success) return;
        const tbody = $('#agentTableBody');
        tbody.empty();
        if (!res.data.length) {
            tbody.html('<tr><td colspan="8" class="text-center py-4 text-muted"><?php _e('sa_no_agents', 'No agents found'); ?></td></tr>');
            return;
        }
        res.data.forEach(function(a) {
            const statusBadge = a.status === 'active' ? '<span class="badge bg-success">Active</span>'
                : a.status === 'pending' ? '<span class="badge bg-warning text-dark">Pending</span>'
                : '<span class="badge bg-secondary">Inactive</span>';
            tbody.append(`<tr>
                <td><strong>${esc(a.name)}</strong><br><small class="text-muted">${esc(a.email || '')}</small></td>
                <td>${esc(a.phone)}</td>
                <td><code>${esc(a.referral_code || '-')}</code></td>
                <td>${parseInt(a.re_referrals) || 0}</td>
                <td>${parseInt(a.re_converted) || 0}</td>
                <td class="text-success fw-semibold">TZS ${Number(a.re_total_earned || 0).toLocaleString()}</td>
                <td>TZS ${Number(a.wallet_balance_re || 0).toLocaleString()}</td>
                <td>${statusBadge}</td>
            </tr>`);
        });
        renderPagination('#agentPagination', res.pagination, loadAgents);
    });
}

// Load referrals
function loadReferrals(page) {
    page = page || 1;
    const params = {
        action: 'list_referrals', page: page,
        status: $('#refStatusFilter').val(),
        search: $('#refSearch').val()
    };
    $.get(API_URL, params, function(res) {
        if (!res.success) return;
        const tbody = $('#refTableBody');
        tbody.empty();
        if (!res.data.length) {
            tbody.html('<tr><td colspan="8" class="text-center py-4 text-muted"><?php _e('sa_no_referrals', 'No referrals found'); ?></td></tr>');
            return;
        }
        res.data.forEach(function(r) {
            const statusColors = {referred:'secondary', trial:'info', converted:'success', churned:'danger'};
            const badge = `<span class="badge bg-${statusColors[r.status] || 'secondary'}">${r.status}</span>`;
            tbody.append(`<tr>
                <td>${esc(r.agent_name || '-')}</td>
                <td>${esc(r.customer_name || r.trial_name || '-')}<br><small class="text-muted">${esc(r.customer_phone || r.trial_phone || '')}</small></td>
                <td>${esc(r.customer_company || r.company_name || '-')}</td>
                <td>${esc(r.tier || '-')}</td>
                <td>${r.net_amount ? 'TZS ' + Number(r.net_amount).toLocaleString() : '-'}</td>
                <td>${parseInt(r.subscription_month_count) || 0}</td>
                <td>${badge}</td>
                <td><small>${r.created_at ? r.created_at.substring(0, 10) : '-'}</small></td>
            </tr>`);
        });
        renderPagination('#refPagination', res.pagination, loadReferrals);
    });
}

// Load commissions
function loadCommissions(page) {
    page = page || 1;
    const params = {
        action: 'list_commissions', page: page,
        status: $('#commStatusFilter').val(),
        type: $('#commTypeFilter').val()
    };
    $.get(API_URL, params, function(res) {
        if (!res.success) return;
        const tbody = $('#commTableBody');
        tbody.empty();
        if (!res.data.length) {
            tbody.html('<tr><td colspan="9" class="text-center py-4 text-muted"><?php _e('sa_no_commissions', 'No commissions found'); ?></td></tr>');
            return;
        }
        res.data.forEach(function(c) {
            const typeBadge = c.commission_type === 'direct'
                ? '<span class="badge bg-success">Direct</span>'
                : '<span class="badge bg-primary">Override</span>';
            const statusBadge = c.status === 'paid'
                ? '<span class="badge bg-success">Paid</span>'
                : '<span class="badge bg-warning text-dark">Pending</span>';
            const actions = c.status === 'pending'
                ? `<button class="btn btn-sm btn-outline-success" onclick="showMarkPaid(${c.id})" title="Mark Paid"><i class="bi bi-check-circle"></i></button>`
                : '<small class="text-muted">' + (c.payment_reference || '-') + '</small>';
            tbody.append(`<tr>
                <td>${esc(c.agent_name || '-')}</td>
                <td>${esc(c.customer_name || '-')}</td>
                <td>${typeBadge}</td>
                <td>Month ${c.month_number}</td>
                <td class="fw-semibold text-success">TZS ${Number(c.amount).toLocaleString()}</td>
                <td>TZS ${Number(c.subscription_billing || c.subscription_amount || 0).toLocaleString()}</td>
                <td>${statusBadge}</td>
                <td><small>${c.credited_at ? c.credited_at.substring(0, 10) : (c.created_at ? c.created_at.substring(0, 10) : '-')}</small></td>
                <td>${actions}</td>
            </tr>`);
        });
        renderPagination('#commPagination', res.pagination, loadCommissions);
    });
}

// Mark paid
function showMarkPaid(id) {
    $('#markPaidId').val(id);
    $('#markPaidRef').val('');
    new bootstrap.Modal('#markPaidModal').show();
}
function submitMarkPaid() {
    $.post(API_URL, {
        action: 'mark_paid',
        id: $('#markPaidId').val(),
        payment_reference: $('#markPaidRef').val()
    }, function(res) {
        bootstrap.Modal.getInstance(document.getElementById('markPaidModal')).hide();
        alert(res.message);
        loadCommissions();
        loadStats();
    });
}

// Process commissions
function processCommissions() {
    if (!confirm('<?php _e('sa_confirm_process', 'Process commissions for all active referred subscriptions?'); ?>')) return;
    $.post(API_URL, {action: 'process_commissions'}, function(res) {
        alert(res.message);
        loadStats();
        loadCommissions();
        loadReferrals();
    });
}

// Pagination renderer
function renderPagination(selector, p, callback) {
    if (!p || p.total_pages <= 1) { $(selector).html(''); return; }
    let html = '<nav><ul class="pagination pagination-sm mb-0 justify-content-center">';
    for (let i = 1; i <= p.total_pages; i++) {
        html += `<li class="page-item ${i === p.page ? 'active' : ''}"><a class="page-link" href="#" onclick="event.preventDefault();${callback.name}(${i})">${i}</a></li>`;
    }
    html += '</ul></nav>';
    $(selector).html(html);
}

// Escape helper
function esc(s) { return $('<span>').text(s || '').html(); }

// Event bindings
$('#agentStatusFilter, #agentSearch').on('change keyup', function() { loadAgents(); });
$('#refStatusFilter, #refSearch').on('change keyup', function() { loadReferrals(); });
$('#commStatusFilter, #commTypeFilter').on('change', function() { loadCommissions(); });

// Tab load triggers
$('a[data-bs-toggle="tab"]').on('shown.bs.tab', function(e) {
    const target = $(e.target).attr('href');
    if (target === '#tabAgents') loadAgents();
    else if (target === '#tabReferrals') loadReferrals();
    else if (target === '#tabCommissions') loadCommissions();
});

// Init
$(document).ready(function() {
    loadStats();
    loadConfig();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
