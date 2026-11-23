<?php
/**
 * FIS Real Estate — Late Fee Settings (10.4)
 * System-level late fee configuration: enable/disable, type, value, grace period, max cap, compound.
 * Property-level overrides are managed in property settings.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('late_fee_settings', 'Late Fee Settings');

$subStatus = enforceSubscription();

if (!hasPermission('deposits', 'edit')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Load current settings
$settings = [
    'late_fee_enabled' => getSetting('late_fee_enabled', '0'),
    'late_fee_type' => getSetting('late_fee_type', 'percentage'),
    'late_fee_value' => getSetting('late_fee_value', '5'),
    'grace_period_days' => getSetting('grace_period_days', '5'),
    'max_late_fee' => getSetting('max_late_fee', '0'),
    'late_fee_compound' => getSetting('late_fee_compound', '0'),
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $settings['late_fee_enabled'] = (int)($_POST['late_fee_enabled'] ?? 0) ? '1' : '0';
    $settings['late_fee_type'] = trim($_POST['late_fee_type'] ?? 'percentage');
    $settings['late_fee_value'] = (string)max(0, (float)($_POST['late_fee_value'] ?? 0));
    $settings['grace_period_days'] = (string)max(0, (int)($_POST['grace_period_days'] ?? 5));
    $settings['max_late_fee'] = (string)max(0, (float)($_POST['max_late_fee'] ?? 0));
    $settings['late_fee_compound'] = (int)($_POST['late_fee_compound'] ?? 0) ? '1' : '0';

    if (!in_array($settings['late_fee_type'], ['percentage', 'fixed'])) {
        $errors[] = __('invalid_late_fee_type', 'Invalid late fee type');
    }
    if ((float)$settings['late_fee_value'] <= 0 && $settings['late_fee_enabled'] === '1') {
        $errors[] = __('late_fee_value_required', 'Late fee value must be greater than 0 when enabled');
    }

    if (empty($errors)) {
        try {
            updateSetting('late_fee_enabled', $settings['late_fee_enabled']);
            updateSetting('late_fee_type', $settings['late_fee_type']);
            updateSetting('late_fee_value', $settings['late_fee_value']);
            updateSetting('grace_period_days', $settings['grace_period_days']);
            updateSetting('max_late_fee', $settings['max_late_fee']);
            updateSetting('late_fee_compound', $settings['late_fee_compound']);

            logActivity('updated', 'settings', null, [
                'late_fee_settings' => $settings
            ]);

            setAlert(__('late_fee_settings_saved', 'Late fee settings saved successfully!'), 'success');
            redirect('modules/deposits/late-fee-settings.php');
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Count properties with overrides
$overrideCount = (int)$conn->query("SELECT COUNT(*) FROM property_settings WHERE late_fee_type != 'none' AND late_fee_value > 0")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-gear me-2"></i><?php _e('late_fee_settings', 'Late Fee Settings'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/deposits/"><?php _e('deposits'); ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/deposits/late-fees.php"><?php _e('late_fees', 'Late Fees'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('settings'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('deposits.late-fee-settings'); ?>

<!-- Errors -->
<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?php echo htmlspecialchars($err); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST">
    <?php echo csrfField(); ?>

    <div class="row g-3">
        <!-- Enable/Disable -->
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-toggle-on me-1"></i> <?php _e('late_fee_status', 'Late Fee Status'); ?></span>
                </div>
                <div class="card-body">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="late_fee_enabled" value="1" id="enableSwitch" <?php echo $settings['late_fee_enabled'] === '1' ? 'checked' : ''; ?> onchange="toggleSettings()">
                        <label class="form-check-label fw-semibold" for="enableSwitch">
                            <?php _e('enable_late_fees', 'Enable automatic late fee charges'); ?>
                        </label>
                    </div>
                    <small class="text-muted d-block mt-1">
                        <?php _e('late_fee_enable_help', 'When enabled, late fees will be automatically calculated for overdue rent payments based on the rules below.'); ?>
                    </small>
                </div>
            </div>
        </div>

        <!-- Fee Configuration -->
        <div class="col-12 col-lg-6 settings-fields" style="<?php echo $settings['late_fee_enabled'] !== '1' ? 'opacity:0.5;' : ''; ?>">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-calculator me-1"></i> <?php _e('fee_calculation', 'Fee Calculation'); ?>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('late_fee_type', 'Late Fee Type'); ?> <span class="text-danger">*</span></label>
                            <select name="late_fee_type" class="form-select" id="feeType" onchange="updateUnit()">
                                <option value="percentage" <?php echo $settings['late_fee_type'] === 'percentage' ? 'selected' : ''; ?>><?php _e('percentage_of_rent', 'Percentage of Rent'); ?></option>
                                <option value="fixed" <?php echo $settings['late_fee_type'] === 'fixed' ? 'selected' : ''; ?>><?php _e('fixed_amount', 'Fixed Amount'); ?></option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('late_fee_value', 'Late Fee Value'); ?> <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="late_fee_value" value="<?php echo htmlspecialchars($settings['late_fee_value']); ?>" min="0" step="0.01">
                                <span class="input-group-text" id="feeUnit"><?php echo $settings['late_fee_type'] === 'percentage' ? '%' : APP_CURRENCY_SYMBOL; ?></span>
                            </div>
                            <small class="text-muted" id="feeHelp">
                                <?php echo $settings['late_fee_type'] === 'percentage'
                                    ? __('percentage_help', 'e.g. 5 = 5% of monthly rent')
                                    : __('fixed_help', 'Fixed amount charged per late period'); ?>
                            </small>
                        </div>
                    </div>

                    <hr>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold"><?php _e('max_late_fee', 'Maximum Late Fee'); ?></label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="max_late_fee" value="<?php echo htmlspecialchars($settings['max_late_fee']); ?>" min="0" step="0.01">
                                <span class="input-group-text"><?php echo APP_CURRENCY_SYMBOL; ?></span>
                            </div>
                            <small class="text-muted"><?php _e('max_fee_help', '0 = unlimited'); ?></small>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold d-block"><?php _e('compound_fees', 'Compound Fees'); ?></label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch" name="late_fee_compound" value="1" id="compoundSwitch" <?php echo $settings['late_fee_compound'] === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="compoundSwitch">
                                    <?php _e('enable_compound', 'Enable compound late fees'); ?>
                                </label>
                            </div>
                            <small class="text-muted"><?php _e('compound_help', 'When enabled, late fees are calculated on rent + existing unpaid late fees'); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Grace Period & Timing -->
        <div class="col-12 col-lg-6 settings-fields" style="<?php echo $settings['late_fee_enabled'] !== '1' ? 'opacity:0.5;' : ''; ?>">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-calendar-check me-1"></i> <?php _e('grace_timing', 'Grace Period & Timing'); ?>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('grace_period', 'Grace Period (Days)'); ?></label>
                        <input type="number" class="form-control" name="grace_period_days" value="<?php echo htmlspecialchars($settings['grace_period_days']); ?>" min="0" max="60">
                        <small class="text-muted"><?php _e('grace_period_help', 'Number of days after the rent due date before a late fee is applied. e.g. 5 means late fee starts on day 6.'); ?></small>
                    </div>

                    <hr>

                    <div class="mb-0">
                        <h6 class="fw-semibold"><?php _e('how_it_works', 'How It Works'); ?></h6>
                        <ul class="small text-muted mb-0">
                            <li><?php _e('late_fee_rule_1', 'The system checks active leases for unpaid rent each time fees are applied.'); ?></li>
                            <li><?php _e('late_fee_rule_2', 'If rent is unpaid after the due date + grace period, a late fee is charged.'); ?></li>
                            <li><?php _e('late_fee_rule_3', 'Only one late fee is charged per lease per billing period.'); ?></li>
                            <li><?php _e('late_fee_rule_4', 'Property-level late fee settings override these system defaults.'); ?></li>
                            <li><?php _e('late_fee_rule_5', 'Late fees can be waived individually from the Late Fees list.'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Property Overrides Info -->
        <?php if ($overrideCount > 0): ?>
        <div class="col-12">
            <div class="alert alert-info d-flex align-items-center gap-2 mb-0">
                <i class="bi bi-info-circle-fill"></i>
                <div>
                    <strong><?php echo $overrideCount; ?> <?php _e('properties_with_overrides', 'properties have custom late fee rules'); ?></strong>
                    <br><small><?php _e('override_note', 'Property-level settings override these system defaults. Edit individual property settings to manage overrides.'); ?></small>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <hr>

    <div class="d-flex justify-content-between">
        <a href="<?php echo BASE_URL; ?>modules/deposits/late-fees.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> <?php _e('back_to_late_fees', 'Back to Late Fees'); ?>
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i> <?php _e('save_settings', 'Save Settings'); ?>
        </button>
    </div>
</form>

<script>
function toggleSettings() {
    const enabled = document.getElementById('enableSwitch').checked;
    document.querySelectorAll('.settings-fields').forEach(el => {
        el.style.opacity = enabled ? '1' : '0.5';
    });
}

function updateUnit() {
    const type = document.getElementById('feeType').value;
    document.getElementById('feeUnit').textContent = type === 'percentage' ? '%' : '<?php echo APP_CURRENCY_SYMBOL; ?>';
    document.getElementById('feeHelp').textContent = type === 'percentage'
        ? '<?php echo __("percentage_help", "e.g. 5 = 5% of monthly rent"); ?>'
        : '<?php echo __("fixed_help", "Fixed amount charged per late period"); ?>';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
