<?php
/**
 * FIS Real Estate — Announcements & Global Settings (Part 23.5)
 * System announcements, feature flags, pricing config, maintenance mode
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
require_once __DIR__ . '/../../includes/header.php';
$pageTitle = __('adm_announcements_settings', 'Announcements & Settings');

if (!isSaasOperator()) {
    echo '<div class="alert alert-danger">' . __('adm_admin_only', 'Admin access required') . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-megaphone me-2"></i><?php _e('adm_announcements_settings', 'Announcements & Settings'); ?></h4>
        <small class="text-muted"><?php _e('adm_announcements_subtitle', 'System-wide announcements, feature flags, and pricing configuration'); ?></small>
    </div>
    <a href="<?php echo BASE_URL; ?>modules/admin/dashboard.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i><?php _e('adm_back_dashboard', 'Back to Dashboard'); ?>
    </a>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#tabAnnouncements">
            <i class="bi bi-megaphone me-1"></i><?php _e('adm_announcements', 'Announcements'); ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tabSettings" id="settingsTabLink">
            <i class="bi bi-sliders me-1"></i><?php _e('adm_global_settings', 'Global Settings'); ?>
        </a>
    </li>
</ul>

<div class="tab-content">

    <!-- Announcements Tab -->
    <div class="tab-pane fade show active" id="tabAnnouncements">
        <div class="row g-4">
            <!-- Create/Edit Form -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h6 class="mb-0" id="annFormTitle"><i class="bi bi-plus-circle me-2"></i><?php _e('adm_new_announcement', 'New Announcement'); ?></h6>
                    </div>
                    <div class="card-body">
                        <form id="announcementForm">
                            <input type="hidden" id="annId" value="0">
                            <div class="mb-3">
                                <label class="form-label"><?php _e('adm_ann_title', 'Title'); ?></label>
                                <input type="text" class="form-control" id="annTitle" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label"><?php _e('adm_ann_message', 'Message'); ?></label>
                                <textarea class="form-control" id="annMessage" rows="3" required></textarea>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?php _e('adm_ann_type', 'Type'); ?></label>
                                    <select class="form-select form-select-sm" id="annType">
                                        <option value="info"><?php _e('adm_type_info', 'Info'); ?></option>
                                        <option value="success"><?php _e('adm_type_success', 'Success'); ?></option>
                                        <option value="warning"><?php _e('adm_type_warning', 'Warning'); ?></option>
                                        <option value="danger"><?php _e('adm_type_danger', 'Danger'); ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php _e('adm_ann_target', 'Target'); ?></label>
                                    <select class="form-select form-select-sm" id="annTarget">
                                        <option value="all"><?php _e('adm_target_all', 'All Subscribers'); ?></option>
                                        <option value="tier_basic"><?php _e('adm_target_basic', 'Basic Only'); ?></option>
                                        <option value="tier_professional"><?php _e('adm_target_professional', 'Professional Only'); ?></option>
                                        <option value="tier_enterprise"><?php _e('adm_target_enterprise', 'Enterprise Only'); ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php _e('adm_ann_start', 'Start Date'); ?></label>
                                    <input type="date" class="form-control form-control-sm" id="annStartDate">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php _e('adm_ann_end', 'End Date'); ?></label>
                                    <input type="date" class="form-control form-control-sm" id="annEndDate">
                                </div>
                            </div>
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" id="annActive" checked>
                                <label class="form-check-label"><?php _e('adm_ann_active', 'Active'); ?></label>
                            </div>
                            <div class="mt-3 d-flex gap-2">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-check-lg me-1"></i><?php _e('save', 'Save'); ?>
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetAnnForm()">
                                    <i class="bi bi-x me-1"></i><?php _e('cancel', 'Cancel'); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Announcements List -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i><?php _e('adm_announcements_list', 'Announcements'); ?></h6>
                    </div>
                    <div class="card-body p-0">
                        <div id="announcementsList"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Global Settings Tab -->
    <div class="tab-pane fade" id="tabSettings">
        <form id="globalSettingsForm">
            <div class="row g-4">
                <!-- Maintenance Mode -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white">
                            <h6 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i><?php _e('adm_maintenance_mode', 'Maintenance Mode'); ?></h6>
                        </div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="settMaintenance" name="maintenance_mode">
                                <label class="form-check-label fw-semibold text-danger"><?php _e('adm_enable_maintenance', 'Enable Maintenance Mode'); ?></label>
                            </div>
                            <div class="mb-3">
                                <label class="form-label"><?php _e('adm_maintenance_msg', 'Maintenance Message'); ?></label>
                                <textarea class="form-control" id="settMaintenanceMsg" name="maintenance_message" rows="2"></textarea>
                            </div>
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                <small><?php _e('adm_maintenance_warning', 'Enabling maintenance mode will block all tenant access.'); ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Feature Flags -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white">
                            <h6 class="mb-0"><i class="bi bi-toggles me-2"></i><?php _e('adm_feature_flags', 'Feature Flags'); ?></h6>
                        </div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="settNewReg" name="feature_new_registrations">
                                <label class="form-check-label"><?php _e('adm_allow_registrations', 'Allow New Trial Registrations'); ?></label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="settGateway" name="feature_payment_gateway">
                                <label class="form-check-label"><?php _e('adm_enable_gateway', 'Enable Payment Gateway (Global)'); ?></label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="settWhatsApp" name="feature_whatsapp_api">
                                <label class="form-check-label"><?php _e('adm_enable_whatsapp', 'Enable WhatsApp API (Global)'); ?></label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pricing Config -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white">
                            <h6 class="mb-0"><i class="bi bi-tag me-2"></i><?php _e('adm_pricing_config', 'Pricing Configuration'); ?></h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?php _e('adm_basic_rate', 'Basic Rate (TZS/unit/month)'); ?></label>
                                    <input type="number" class="form-control form-control-sm" id="settBasicRate" name="pricing_basic_rate" min="0">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php _e('adm_professional_rate', 'Professional Rate'); ?></label>
                                    <input type="number" class="form-control form-control-sm" id="settProfRate" name="pricing_professional_rate" min="0">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php _e('adm_enterprise_rate', 'Enterprise Rate'); ?></label>
                                    <input type="number" class="form-control form-control-sm" id="settEntRate" name="pricing_enterprise_rate" min="0">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php _e('adm_trial_days', 'Trial Days'); ?></label>
                                    <input type="number" class="form-control form-control-sm" id="settTrialDays" name="pricing_trial_days" min="1" max="90">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Admin Contact -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white">
                            <h6 class="mb-0"><i class="bi bi-envelope me-2"></i><?php _e('adm_admin_contact', 'Admin Contact'); ?></h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label"><?php _e('adm_admin_email', 'Admin Email'); ?></label>
                                <input type="email" class="form-control form-control-sm" id="settAdminEmail" name="admin_email" placeholder="admin@fis.co.tz">
                            </div>
                            <div class="mb-3">
                                <label class="form-label"><?php _e('adm_admin_whatsapp', 'Admin WhatsApp'); ?></label>
                                <input type="text" class="form-control form-control-sm" id="settAdminWA" name="admin_whatsapp" placeholder="+255...">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 mb-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i><?php _e('adm_save_settings', 'Save Global Settings'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const API_URL = BASE_URL + 'api/saas-admin.php';

// ==================
// Announcements
// ==================
function loadAnnouncements() {
    $.get(API_URL, {action: 'list_announcements'}, function(res) {
        if (!res.success) return;
        const list = $('#announcementsList');
        list.empty();
        if (!res.data.length) {
            list.html('<div class="p-4 text-center text-muted"><?php _e('adm_no_announcements', 'No announcements yet'); ?></div>');
            return;
        }
        res.data.forEach(function(a) {
            const typeColors = {info:'primary', success:'success', warning:'warning', danger:'danger'};
            const targetLabels = {all:'All', tier_basic:'Basic', tier_professional:'Professional', tier_enterprise:'Enterprise'};
            const activeClass = a.is_active == 1 ? '' : 'opacity-50';
            list.append(`<div class="border-bottom p-3 ${activeClass}">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-${typeColors[a.type] || 'secondary'}">${a.type}</span>
                            <span class="badge bg-light text-dark">${targetLabels[a.target] || a.target}</span>
                            ${a.is_active == 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}
                        </div>
                        <strong>${esc(a.title)}</strong>
                        <p class="mb-1 small text-muted">${esc(a.message)}</p>
                        <small class="text-muted">
                            ${a.start_date ? a.start_date + ' — ' : ''}${a.end_date || ''}
                            ${a.created_by ? ' | by ' + esc(a.created_by) : ''}
                        </small>
                    </div>
                    <div class="btn-group btn-group-sm ms-2">
                        <button class="btn btn-outline-primary" onclick="editAnnouncement(${a.id})" title="Edit"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-outline-${a.is_active == 1 ? 'warning' : 'success'}" onclick="toggleAnnouncement(${a.id})" title="Toggle">
                            <i class="bi bi-${a.is_active == 1 ? 'eye-slash' : 'eye'}"></i>
                        </button>
                        <button class="btn btn-outline-danger" onclick="deleteAnnouncement(${a.id})" title="Delete"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            </div>`);
        });
    });
}

// Store data for editing
let announcementsData = [];
function loadAnnouncementsData() {
    $.get(API_URL, {action: 'list_announcements'}, function(res) {
        if (res.success) announcementsData = res.data;
    });
}

function editAnnouncement(id) {
    $.get(API_URL, {action: 'list_announcements'}, function(res) {
        if (!res.success) return;
        const a = res.data.find(x => x.id == id);
        if (!a) return;
        $('#annId').val(a.id);
        $('#annTitle').val(a.title);
        $('#annMessage').val(a.message);
        $('#annType').val(a.type);
        $('#annTarget').val(a.target);
        $('#annStartDate').val(a.start_date || '');
        $('#annEndDate').val(a.end_date || '');
        $('#annActive').prop('checked', a.is_active == 1);
        $('#annFormTitle').html('<i class="bi bi-pencil-square me-2"></i><?php _e('adm_edit_announcement', 'Edit Announcement'); ?>');
    });
}

function resetAnnForm() {
    $('#annId').val(0);
    $('#annTitle').val('');
    $('#annMessage').val('');
    $('#annType').val('info');
    $('#annTarget').val('all');
    $('#annStartDate').val('');
    $('#annEndDate').val('');
    $('#annActive').prop('checked', true);
    $('#annFormTitle').html('<i class="bi bi-plus-circle me-2"></i><?php _e('adm_new_announcement', 'New Announcement'); ?>');
}

$('#announcementForm').on('submit', function(e) {
    e.preventDefault();
    $.post(API_URL, {
        action: 'save_announcement',
        id: $('#annId').val(),
        title: $('#annTitle').val(),
        message: $('#annMessage').val(),
        type: $('#annType').val(),
        target: $('#annTarget').val(),
        start_date: $('#annStartDate').val(),
        end_date: $('#annEndDate').val(),
        is_active: $('#annActive').is(':checked') ? '1' : '0'
    }, function(res) {
        alert(res.message);
        if (res.success) { resetAnnForm(); loadAnnouncements(); }
    });
});

function toggleAnnouncement(id) {
    $.post(API_URL, {action: 'toggle_announcement', id: id}, function(res) {
        if (res.success) loadAnnouncements();
    });
}

function deleteAnnouncement(id) {
    if (!confirm('<?php _e('adm_confirm_delete_ann', 'Delete this announcement?'); ?>')) return;
    $.post(API_URL, {action: 'delete_announcement', id: id}, function(res) {
        alert(res.message);
        if (res.success) loadAnnouncements();
    });
}

// ==================
// Global Settings
// ==================
function loadSettings() {
    $.get(API_URL, {action: 'get_settings'}, function(res) {
        if (!res.success) return;
        const s = res.data;
        $('#settMaintenance').prop('checked', s.maintenance_mode === '1');
        $('#settMaintenanceMsg').val(s.maintenance_message || '');
        $('#settNewReg').prop('checked', s.feature_new_registrations === '1');
        $('#settGateway').prop('checked', s.feature_payment_gateway === '1');
        $('#settWhatsApp').prop('checked', s.feature_whatsapp_api === '1');
        $('#settBasicRate').val(s.pricing_basic_rate || 9999);
        $('#settProfRate').val(s.pricing_professional_rate || 14999);
        $('#settEntRate').val(s.pricing_enterprise_rate || 19999);
        $('#settTrialDays').val(s.pricing_trial_days || 14);
        $('#settAdminEmail').val(s.admin_email || '');
        $('#settAdminWA').val(s.admin_whatsapp || '');
    });
}

$('#globalSettingsForm').on('submit', function(e) {
    e.preventDefault();
    const data = {action: 'save_settings'};
    data.maintenance_mode = $('#settMaintenance').is(':checked') ? '1' : '0';
    data.maintenance_message = $('#settMaintenanceMsg').val();
    data.feature_new_registrations = $('#settNewReg').is(':checked') ? '1' : '0';
    data.feature_payment_gateway = $('#settGateway').is(':checked') ? '1' : '0';
    data.feature_whatsapp_api = $('#settWhatsApp').is(':checked') ? '1' : '0';
    data.pricing_basic_rate = $('#settBasicRate').val();
    data.pricing_professional_rate = $('#settProfRate').val();
    data.pricing_enterprise_rate = $('#settEntRate').val();
    data.pricing_trial_days = $('#settTrialDays').val();
    data.admin_email = $('#settAdminEmail').val();
    data.admin_whatsapp = $('#settAdminWA').val();
    $.post(API_URL, data, function(res) {
        alert(res.message);
    });
});

function esc(s) { return $('<span>').text(s || '').html(); }

// Hash-based tab activation
if (window.location.hash === '#tabSettings') {
    new bootstrap.Tab(document.getElementById('settingsTabLink')).show();
}

// Tab load triggers
$('a[data-bs-toggle="tab"]').on('shown.bs.tab', function(e) {
    if ($(e.target).attr('href') === '#tabSettings') loadSettings();
});

$(document).ready(function() {
    loadAnnouncements();
    loadSettings();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
