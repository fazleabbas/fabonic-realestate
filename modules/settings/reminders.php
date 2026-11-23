<?php
/**
 * FIS Real Estate — Reminder Template Configuration (Part 17.1)
 * CRUD for reminder_templates. Professional+ tier required.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('rem_templates', 'Reminder Templates');

$subStatus = enforceSubscription();

if (!hasTier('professional')) {
    setAlert(__('rem_tier_required', 'Professional tier or higher required for reminders.'), 'warning');
    redirect('index.php');
}
if (getSetting('reminders_enabled', '0') !== '1') {
    setAlert(__('rem_disabled', 'Reminders module is disabled. Enable it in Settings > Modules.'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('settings', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// ------------------------------------------------------------------
// SMS gateway credentials (Sprint API). Saved straight from this page —
// the template CRUD below goes through api/reminders.php instead.
// ------------------------------------------------------------------
require_once __DIR__ . '/../../includes/sms.php';

$smsSaved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_sms') {
    checkCSRF();
    if (!hasPermission('settings', 'edit')) {
        setAlert(__('no_permission'), 'danger');
        redirect('modules/settings/reminders.php');
    }

    foreach (['sms_api_url', 'sms_api_id', 'sms_sender_id', 'sms_type', 'sms_country_code', 'sms_callback_url'] as $k) {
        updateSetting($k, trim($_POST[$k] ?? ''));
    }
    // Blank password field means "keep the stored one" — so the saved secret is
    // never wiped just because the form renders it empty.
    $pw = trim($_POST['sms_api_password'] ?? '');
    if ($pw !== '') {
        updateSetting('sms_api_password', $pw);
    }
    updateSetting('reminder_max_overdue_nags', max(1, (int)($_POST['reminder_max_overdue_nags'] ?? 4)));

    logActivity('updated', 'settings', null, ['group' => 'sms_gateway']);
    setAlert(__('rem_sms_saved', 'SMS gateway settings saved.'), 'success');
    redirect('modules/settings/reminders.php');
}

// Fetch templates
$templates = $conn->query("SELECT * FROM reminder_templates ORDER BY trigger_type, days_offset")->fetchAll();

// Stats
$stats = [];
$stats['scheduled'] = (int)$conn->query("SELECT COUNT(*) FROM reminder_schedule WHERE status = 'scheduled'")->fetchColumn();
$stats['sent'] = (int)$conn->query("SELECT COUNT(*) FROM reminder_schedule WHERE status IN ('sent','delivered')")->fetchColumn();
$stats['failed'] = (int)$conn->query("SELECT COUNT(*) FROM reminder_schedule WHERE status = 'failed'")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-bell me-2"></i><?php _e('rem_templates', 'Reminder Templates'); ?></h4>
        <p class="text-muted mb-0"><?php _e('rem_desc', 'Configure automated rent reminder messages sent to tenants'); ?></p>
    </div>
    <div class="d-flex gap-1">
        <a href="<?php echo BASE_URL; ?>modules/settings/reminder-log.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-clock-history me-1"></i> <?php _e('rem_delivery_log', 'Delivery Log'); ?>
        </a>
        <button class="btn btn-sm btn-success" onclick="generateSchedule()">
            <i class="bi bi-calendar-check me-1"></i> <?php _e('rem_generate', 'Generate Schedule'); ?>
        </button>
    </div>
</div>

<?php pageIntro('settings.reminders'); ?>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center py-2">
            <div class="card-body py-2">
                <div style="font-size:1.5rem;font-weight:700;color:#1a6b4a;"><?php echo count($templates); ?></div>
                <small class="text-muted"><?php _e('rem_total_templates', 'Templates'); ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-2">
            <div class="card-body py-2">
                <div style="font-size:1.5rem;font-weight:700;color:#0d6efd;"><?php echo $stats['scheduled']; ?></div>
                <small class="text-muted"><?php _e('rem_pending', 'Pending'); ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-2">
            <div class="card-body py-2">
                <div style="font-size:1.5rem;font-weight:700;color:#198754;"><?php echo $stats['sent']; ?></div>
                <small class="text-muted"><?php _e('rem_sent', 'Sent'); ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-2">
            <div class="card-body py-2">
                <div style="font-size:1.5rem;font-weight:700;color:#dc3545;"><?php echo $stats['failed']; ?></div>
                <small class="text-muted"><?php _e('rem_failed', 'Failed'); ?></small>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Templates List -->
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-ul me-1"></i> <?php _e('rem_all_templates', 'All Templates'); ?></span>
                <button class="btn btn-sm btn-primary" onclick="showForm()">
                    <i class="bi bi-plus-circle me-1"></i> <?php _e('rem_add', 'Add Template'); ?>
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th><?php _e('name'); ?></th>
                                <th><?php _e('rem_trigger', 'Trigger'); ?></th>
                                <th><?php _e('rem_channel', 'Channel'); ?></th>
                                <th><?php _e('status'); ?></th>
                                <th style="width:100px;"><?php _e('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($templates)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4"><?php _e('rem_no_templates', 'No templates configured'); ?></td></tr>
                            <?php else: foreach ($templates as $tpl):
                                $triggerLabel = '';
                                if ($tpl['trigger_type'] === 'before_due') {
                                    $triggerLabel = $tpl['days_offset'] . ' ' . __('rem_days_before', 'days before');
                                } elseif ($tpl['trigger_type'] === 'on_due') {
                                    $triggerLabel = __('rem_on_due', 'On due date');
                                } else {
                                    $triggerLabel = $tpl['days_offset'] . ' ' . __('rem_days_after', 'days overdue');
                                }
                                $channelBadge = ['whatsapp' => 'success', 'sms' => 'info', 'email' => 'primary'];
                            ?>
                            <tr>
                                <td>
                                    <a href="javascript:void(0)" onclick="editTemplate(<?php echo $tpl['id']; ?>)" class="fw-semibold text-decoration-none">
                                        <?php echo htmlspecialchars($tpl['name']); ?>
                                    </a>
                                    <?php if ($tpl['repeat_interval_days'] > 0): ?>
                                    <br><small class="text-muted"><i class="bi bi-arrow-repeat"></i> <?php echo __('rem_repeat_every', 'Repeat every') . ' ' . $tpl['repeat_interval_days'] . ' ' . __('days'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $tpl['trigger_type'] === 'overdue' ? 'danger' : ($tpl['trigger_type'] === 'on_due' ? 'warning' : 'info'); ?>-subtle text-<?php echo $tpl['trigger_type'] === 'overdue' ? 'danger' : ($tpl['trigger_type'] === 'on_due' ? 'warning' : 'info'); ?>">
                                        <?php echo $triggerLabel; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $channelBadge[$tpl['channel']] ?? 'secondary'; ?>">
                                        <?php echo ucfirst($tpl['channel']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $tpl['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $tpl['is_active'] ? __('active') : __('inactive'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-secondary" onclick="editTemplate(<?php echo $tpl['id']; ?>)" title="<?php _e('edit'); ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-outline-<?php echo $tpl['is_active'] ? 'warning' : 'success'; ?>" onclick="toggleTemplate(<?php echo $tpl['id']; ?>)" title="<?php echo $tpl['is_active'] ? __('rem_deactivate', 'Deactivate') : __('rem_activate', 'Activate'); ?>">
                                            <i class="bi bi-<?php echo $tpl['is_active'] ? 'pause' : 'play'; ?>"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" onclick="deleteTemplate(<?php echo $tpl['id']; ?>, '<?php echo htmlspecialchars(addslashes($tpl['name'])); ?>')" title="<?php _e('delete'); ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Template Form -->
    <div class="col-12 col-lg-5">
        <div class="card" id="templateForm">
            <div class="card-header">
                <i class="bi bi-pencil-square me-1"></i>
                <span id="formTitle"><?php _e('rem_add', 'Add Template'); ?></span>
            </div>
            <div class="card-body">
                <form id="tplForm" onsubmit="saveTemplate(event)">
                    <input type="hidden" name="id" id="tpl_id" value="">

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('name'); ?> *</label>
                        <input type="text" name="name" id="tpl_name" class="form-control" required placeholder="<?php _e('rem_name_placeholder', 'e.g. 3-Day Reminder'); ?>">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?php _e('rem_trigger_type', 'Trigger Type'); ?> *</label>
                            <select name="trigger_type" id="tpl_trigger" class="form-select" required onchange="updateDaysLabel()">
                                <option value="before_due"><?php _e('rem_before_due', 'Before Due'); ?></option>
                                <option value="on_due"><?php _e('rem_on_due', 'On Due Date'); ?></option>
                                <option value="overdue"><?php _e('rem_overdue', 'Overdue'); ?></option>
                            </select>
                        </div>
                        <div class="col-6" id="daysOffsetGroup">
                            <label class="form-label fw-semibold" id="daysLabel"><?php _e('rem_days_offset', 'Days'); ?></label>
                            <input type="number" name="days_offset" id="tpl_days" class="form-control" min="0" max="90" value="3">
                            <small class="text-muted" id="daysHint"><?php _e('rem_days_before_hint', 'Days before due date'); ?></small>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?php _e('rem_channel', 'Channel'); ?> *</label>
                            <select name="channel" id="tpl_channel" class="form-select" required>
                                <option value="whatsapp">WhatsApp</option>
                                <option value="email">Email</option>
                                <option value="sms">SMS</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?php _e('rem_repeat', 'Repeat (days)'); ?></label>
                            <input type="number" name="repeat_interval_days" id="tpl_repeat" class="form-control" min="0" max="90" value="0">
                            <small class="text-muted"><?php _e('rem_repeat_hint', '0 = no repeat'); ?></small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('rem_message', 'Message Template'); ?> *</label>
                        <textarea name="message_template" id="tpl_message" class="form-control" rows="5" required placeholder="<?php _e('rem_message_placeholder', 'Hello {tenant_name}, your rent of {currency} {amount} is due on {due_date}...'); ?>"></textarea>
                    </div>

                    <!-- Placeholders Reference -->
                    <div class="alert alert-light border mb-3 py-2" style="font-size:0.82rem;">
                        <strong><?php _e('rem_placeholders', 'Available Placeholders'); ?>:</strong><br>
                        <code>{tenant_name}</code> <code>{amount}</code> <code>{currency}</code> <code>{due_date}</code>
                        <code>{unit_number}</code> <code>{property_name}</code> <code>{balance}</code> <code>{company_name}</code>
                    </div>

                    <div class="form-check mb-3">
                        <input type="checkbox" name="is_active" id="tpl_active" class="form-check-input" checked>
                        <label class="form-check-label" for="tpl_active"><?php _e('rem_active', 'Active'); ?></label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-check-circle me-1"></i> <span id="saveBtn"><?php _e('save'); ?></span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="resetForm()">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Receipt Auto-Send Setting -->
        <div class="card mt-3">
            <div class="card-header"><i class="bi bi-whatsapp me-1"></i> <?php _e('rem_receipt_settings', 'Receipt Auto-Send'); ?></div>
            <div class="card-body">
                <div class="form-check form-switch mb-2">
                    <input type="checkbox" class="form-check-input" id="receiptAutoSend" style="width:3em;height:1.5em;"
                        <?php echo getSetting('receipt_auto_send', '1') === '1' ? 'checked' : ''; ?>
                        onchange="toggleReceiptSend(this.checked)">
                    <label class="form-check-label" for="receiptAutoSend"><?php _e('rem_receipt_auto', 'Auto-send receipt via WhatsApp after payment recording'); ?></label>
                </div>
                <small class="text-muted"><?php _e('rem_receipt_auto_hint', 'When enabled, a WhatsApp message with receipt details is automatically sent to the tenant after each payment is recorded.'); ?></small>
            </div>
        </div>

        <!-- SMS Gateway -->
        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-chat-left-text me-1"></i> <?php _e('rem_sms_gateway', 'SMS Gateway'); ?></span>
                <?php if (smsIsConfigured()): ?>
                <span class="badge bg-success"><?php _e('rem_sms_configured', 'Configured'); ?></span>
                <?php else: ?>
                <span class="badge bg-secondary"><?php _e('rem_sms_not_configured', 'Not configured'); ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    <?php _e('rem_sms_hint', 'Credentials for your SMS provider. Until these are filled in, any template using the SMS channel will fail.'); ?>
                </p>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="form_action" value="save_sms">

                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label fw-semibold small"><?php _e('rem_sms_url', 'API Base URL'); ?></label>
                            <input type="url" name="sms_api_url" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars(getSetting('sms_api_url', '')); ?>"
                                   placeholder="https://sms.example.com">
                            <small class="text-muted"><?php _e('rem_sms_url_hint', 'Base URL only — /api/SendSMS is appended automatically.'); ?></small>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold small"><?php _e('rem_sms_id', 'API ID'); ?></label>
                            <input type="text" name="sms_api_id" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars(getSetting('sms_api_id', '')); ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold small"><?php _e('rem_sms_pw', 'API Password'); ?></label>
                            <input type="password" name="sms_api_password" class="form-control form-control-sm"
                                   autocomplete="new-password"
                                   placeholder="<?php echo getSetting('sms_api_password', '') !== '' ? __('rem_sms_pw_set', '•••••••• (leave blank to keep)') : ''; ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold small"><?php _e('rem_sms_sender', 'Sender ID'); ?></label>
                            <input type="text" name="sms_sender_id" class="form-control form-control-sm" maxlength="20"
                                   value="<?php echo htmlspecialchars(getSetting('sms_sender_id', '')); ?>"
                                   placeholder="FABONIC">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-semibold small"><?php _e('rem_sms_type', 'Type'); ?></label>
                            <select name="sms_type" class="form-select form-select-sm">
                                <option value="T" <?php echo getSetting('sms_type', 'T') === 'T' ? 'selected' : ''; ?>><?php _e('rem_sms_transactional', 'Transactional'); ?></option>
                                <option value="P" <?php echo getSetting('sms_type', 'T') === 'P' ? 'selected' : ''; ?>><?php _e('rem_sms_promotional', 'Promotional'); ?></option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-semibold small"><?php _e('rem_sms_cc', 'Country Code'); ?></label>
                            <input type="text" name="sms_country_code" class="form-control form-control-sm" maxlength="5"
                                   value="<?php echo htmlspecialchars(getSetting('sms_country_code', '255')); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small"><?php _e('rem_sms_callback', 'Delivery Report URL'); ?> <span class="text-muted">(<?php _e('optional'); ?>)</span></label>
                            <input type="url" name="sms_callback_url" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars(getSetting('sms_callback_url', '')); ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold small"><?php _e('rem_max_nags', 'Max overdue reminders'); ?></label>
                            <input type="number" name="reminder_max_overdue_nags" class="form-control form-control-sm"
                                   min="1" max="20"
                                   value="<?php echo (int)getSetting('reminder_max_overdue_nags', '4'); ?>">
                            <small class="text-muted"><?php _e('rem_max_nags_hint', 'Repeating overdue reminders stop after this many, and as soon as the tenant pays.'); ?></small>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm mt-3">
                        <i class="bi bi-check-lg me-1"></i> <?php _e('save_changes'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const API_URL = BASE_URL + 'api/reminders.php';

function showForm() {
    resetForm();
    document.getElementById('templateForm').scrollIntoView({ behavior: 'smooth' });
}

function resetForm() {
    document.getElementById('tplForm').reset();
    document.getElementById('tpl_id').value = '';
    document.getElementById('formTitle').textContent = '<?php echo __("rem_add", "Add Template"); ?>';
    document.getElementById('saveBtn').textContent = '<?php echo __("save"); ?>';
    document.getElementById('tpl_active').checked = true;
    updateDaysLabel();
}

function updateDaysLabel() {
    const trigger = document.getElementById('tpl_trigger').value;
    const group = document.getElementById('daysOffsetGroup');
    const hint = document.getElementById('daysHint');

    if (trigger === 'on_due') {
        group.style.display = 'none';
    } else {
        group.style.display = '';
        hint.textContent = trigger === 'before_due'
            ? '<?php echo __("rem_days_before_hint", "Days before due date"); ?>'
            : '<?php echo __("rem_days_after_hint", "Days after due date"); ?>';
    }
}

function editTemplate(id) {
    fetch(API_URL + '?action=get_template&id=' + id)
        .then(r => r.json())
        .then(res => {
            if (!res.success) { alert(res.message); return; }
            const d = res.data;
            document.getElementById('tpl_id').value = d.id;
            document.getElementById('tpl_name').value = d.name;
            document.getElementById('tpl_trigger').value = d.trigger_type;
            document.getElementById('tpl_days').value = d.days_offset;
            document.getElementById('tpl_repeat').value = d.repeat_interval_days || 0;
            document.getElementById('tpl_channel').value = d.channel;
            document.getElementById('tpl_message').value = d.message_template;
            document.getElementById('tpl_active').checked = d.is_active == 1;
            document.getElementById('formTitle').textContent = '<?php echo __("rem_edit", "Edit Template"); ?>';
            document.getElementById('saveBtn').textContent = '<?php echo __("save_changes"); ?>';
            updateDaysLabel();
            document.getElementById('templateForm').scrollIntoView({ behavior: 'smooth' });
        });
}

function saveTemplate(e) {
    e.preventDefault();
    const fd = new FormData(document.getElementById('tplForm'));
    const id = fd.get('id');
    fd.append('action', id ? 'update_template' : 'create_template');
    if (!fd.has('is_active')) fd.append('is_active', '0');

    fetch(API_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) { location.reload(); }
            else { alert(res.message || 'Error'); }
        });
}

function toggleTemplate(id) {
    const fd = new FormData();
    fd.append('action', 'toggle_template');
    fd.append('id', id);

    fetch(API_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { if (res.success) location.reload(); else alert(res.message); });
}

function deleteTemplate(id, name) {
    if (!confirm('<?php echo __("rem_delete_confirm", "Delete this template?"); ?> "' + name + '"')) return;

    const fd = new FormData();
    fd.append('action', 'delete_template');
    fd.append('id', id);

    fetch(API_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { if (res.success) location.reload(); else alert(res.message); });
}

function generateSchedule() {
    if (!confirm('<?php echo __("rem_generate_confirm", "Generate reminder schedule for all active leases based on current templates?"); ?>')) return;

    const fd = new FormData();
    fd.append('action', 'generate_schedule');

    fetch(API_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            alert(res.message || 'Done');
            if (res.success) location.reload();
        });
}

function toggleReceiptSend(enabled) {
    const fd = new FormData();
    fd.append('action', 'save_settings');
    fd.append('setting_group', 'notifications');
    fd.append('receipt_auto_send', enabled ? '1' : '0');

    fetch(BASE_URL + 'api/settings.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) alert(res.message || 'Error');
        });
}

// Init
updateDaysLabel();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
