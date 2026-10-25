<?php
/**
 * FIS Real Estate — Central Admin: Drip Template Editor
 * View and edit all 11 drip message templates (Swahili + English).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/../includes/whatsapp.php';

// Handle logout
if (isset($_GET['logout'])) {
    adminLogout();
    header('Location: login.php');
    exit;
}

requireAdminAuth();

$db = getAdminDb();
$pageTitle = 'Drip Templates';
$alert = null;

$schedule = getDripSchedule();
$stepLabels = [
    1 => 'Welcome', 2 => 'Getting Started', 3 => 'Leases & Rent',
    4 => 'Reminders', 5 => 'Mid-Trial', 6 => 'Pro Features',
    7 => 'Testimonials', 8 => 'Urgency', 9 => 'Trial Expired',
    10 => 'Follow Up', 11 => 'Final'
];

// ============================================
// Handle POST: save template
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkAdminCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_template') {
        $stepNumber = intval($_POST['step_number'] ?? 0);
        $language = in_array($_POST['language'] ?? '', ['sw', 'en']) ? $_POST['language'] : 'sw';
        $subject = trim($_POST['subject'] ?? '');
        $messageBody = trim($_POST['message_body'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($stepNumber >= 1 && $stepNumber <= 11 && !empty($messageBody)) {
            try {
                $adminUser = getAdminUser();
                $stmt = $db->prepare("
                    INSERT INTO drip_templates_re (step_number, language, subject, message_body, is_active, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        subject = VALUES(subject),
                        message_body = VALUES(message_body),
                        is_active = VALUES(is_active),
                        updated_by = VALUES(updated_by),
                        updated_at = NOW()
                ");
                $stmt->execute([$stepNumber, $language, $subject, $messageBody, $isActive, $adminUser['username'] ?? 'admin']);
                $alert = ['type' => 'success', 'msg' => "Template for Step {$stepNumber} ({$language}) saved."];
            } catch (PDOException $e) {
                $alert = ['type' => 'danger', 'msg' => 'Error saving: ' . $e->getMessage()];
            }
        } else {
            $alert = ['type' => 'warning', 'msg' => 'Please fill in all required fields.'];
        }
    } elseif ($action === 'save_settings') {
        // Save drip settings
        $settings = [
            'drip_enabled' => ($_POST['drip_enabled'] ?? '0') === '1' ? '1' : '0',
            'drip_default_language' => in_array($_POST['drip_default_language'] ?? '', ['sw', 'en']) ? $_POST['drip_default_language'] : 'sw',
            'drip_send_hour' => max(0, min(23, intval($_POST['drip_send_hour'] ?? 9))),
            'drip_max_retries' => max(0, min(10, intval($_POST['drip_max_retries'] ?? 3))),
            'drip_skip_weekends' => ($_POST['drip_skip_weekends'] ?? '0') === '1' ? '1' : '0',
            'drip_rate_limit' => max(1, min(100, intval($_POST['drip_rate_limit'] ?? 30))),
        ];

        try {
            $stmt = $db->prepare("
                INSERT INTO system_settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            foreach ($settings as $key => $val) {
                $stmt->execute([$key, $val]);
            }
            $alert = ['type' => 'success', 'msg' => 'Drip settings saved.'];
        } catch (PDOException $e) {
            $alert = ['type' => 'danger', 'msg' => 'Error saving settings: ' . $e->getMessage()];
        }
    }
}

// ============================================
// Load templates & settings
// ============================================
$templates = [];
try {
    $stmt = $db->query("SELECT * FROM drip_templates_re ORDER BY step_number, language");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $templates[$row['step_number']][$row['language']] = $row;
    }
} catch (PDOException $e) {}

// Load settings
$dripSettings = [
    'drip_enabled' => getDripSetting('drip_enabled', '1'),
    'drip_default_language' => getDripSetting('drip_default_language', 'sw'),
    'drip_send_hour' => getDripSetting('drip_send_hour', '9'),
    'drip_max_retries' => getDripSetting('drip_max_retries', '3'),
    'drip_skip_weekends' => getDripSetting('drip_skip_weekends', '0'),
    'drip_rate_limit' => getDripSetting('drip_rate_limit', '30'),
];

// Which template to edit?
$editStep = intval($_GET['edit'] ?? 0);
$editLang = in_array($_GET['lang'] ?? '', ['sw', 'en']) ? $_GET['lang'] : 'sw';
$editTemplate = null;
if ($editStep >= 1 && $editStep <= 11) {
    $editTemplate = $templates[$editStep][$editLang] ?? [
        'step_number' => $editStep,
        'language' => $editLang,
        'subject' => ($stepLabels[$editStep] ?? "Step {$editStep}") . ' - Day ' . ($schedule[$editStep] ?? '?'),
        'message_body' => '',
        'is_active' => 1
    ];
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($alert): ?>
<div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show py-2" role="alert">
    <?php echo $alert['msg']; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h4 class="fw-bold mb-0"><i class="bi bi-file-text me-2"></i>Drip Message Templates</h4>
</div>

<div class="row g-3">
    <!-- Settings Panel -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-bold"><i class="bi bi-gear me-1"></i> Drip Settings</div>
            <div class="card-body">
                <form method="POST">
                    <?php echo adminCsrfField(); ?>
                    <input type="hidden" name="action" value="save_settings">

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="drip_enabled" value="1" id="dripEnabled"
                            <?php echo $dripSettings['drip_enabled'] === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="dripEnabled">Drip campaigns enabled</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:0.85rem;">Default Language</label>
                        <select name="drip_default_language" class="form-select form-select-sm">
                            <option value="sw" <?php echo $dripSettings['drip_default_language'] === 'sw' ? 'selected' : ''; ?>>Swahili</option>
                            <option value="en" <?php echo $dripSettings['drip_default_language'] === 'en' ? 'selected' : ''; ?>>English</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:0.85rem;">Send Hour (EAT, 0-23)</label>
                        <input type="number" name="drip_send_hour" class="form-control form-control-sm"
                               value="<?php echo $dripSettings['drip_send_hour']; ?>" min="0" max="23">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:0.85rem;">Max Retries (failed messages)</label>
                        <input type="number" name="drip_max_retries" class="form-control form-control-sm"
                               value="<?php echo $dripSettings['drip_max_retries']; ?>" min="0" max="10">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:0.85rem;">Rate Limit (msgs/minute)</label>
                        <input type="number" name="drip_rate_limit" class="form-control form-control-sm"
                               value="<?php echo $dripSettings['drip_rate_limit']; ?>" min="1" max="100">
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="drip_skip_weekends" value="1" id="skipWeekends"
                            <?php echo $dripSettings['drip_skip_weekends'] === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="skipWeekends">Skip weekends</label>
                    </div>

                    <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-check-lg me-1"></i>Save Settings</button>
                </form>
            </div>
        </div>

        <!-- Placeholders reference -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold"><i class="bi bi-braces me-1"></i> Placeholders</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:0.8rem;">
                    <tr><td class="ps-3"><code>{name}</code></td><td>Lead's full name</td></tr>
                    <tr><td class="ps-3"><code>{company}</code></td><td>Company name</td></tr>
                    <tr><td class="ps-3"><code>{units}</code></td><td>Unit count</td></tr>
                    <tr><td class="ps-3"><code>{login_url}</code></td><td>Tenant login URL</td></tr>
                    <tr><td class="ps-3"><code>{trial_end_date}</code></td><td>Trial expiry date</td></tr>
                    <tr><td class="ps-3"><code>{demo_url}</code></td><td>Landing page URL</td></tr>
                    <tr><td class="ps-3"><code>{basic_total}</code></td><td>units x 1,000 TZS</td></tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Templates List / Editor -->
    <div class="col-md-8">
        <?php if ($editTemplate): ?>
        <!-- Template Editor -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">
                    <i class="bi bi-pencil me-1"></i>
                    Edit Step <?php echo $editStep; ?>: <?php echo $stepLabels[$editStep] ?? ''; ?>
                    (<?php echo strtoupper($editLang); ?>)
                </span>
                <a href="drip-templates.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo adminCsrfField(); ?>
                    <input type="hidden" name="action" value="save_template">
                    <input type="hidden" name="step_number" value="<?php echo $editStep; ?>">
                    <input type="hidden" name="language" value="<?php echo $editLang; ?>">

                    <div class="mb-3">
                        <label class="form-label" style="font-size:0.85rem;">Subject / Label</label>
                        <input type="text" name="subject" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($editTemplate['subject'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:0.85rem;">Message Body</label>
                        <textarea name="message_body" class="form-control" rows="15" style="font-family:monospace; font-size:0.85rem;"
                        ><?php echo htmlspecialchars($editTemplate['message_body'] ?? ''); ?></textarea>
                        <small class="text-muted">Use \n for line breaks. Placeholders like {name} will be replaced at send time.</small>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive"
                            <?php echo ($editTemplate['is_active'] ?? 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="isActive">Active</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Save Template</button>
                        <a href="drip-templates.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>
        <!-- Templates List -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold"><i class="bi bi-list-ul me-1"></i> All Templates (11 steps x 2 languages)</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead style="background:#f8f9fa;">
                        <tr>
                            <th>Step</th>
                            <th>Day</th>
                            <th>Label</th>
                            <th>SW</th>
                            <th>EN</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($s = 1; $s <= 11; $s++): ?>
                        <tr>
                            <td><strong><?php echo $s; ?></strong></td>
                            <td><small class="text-muted">Day <?php echo $schedule[$s] ?? '?'; ?></small></td>
                            <td><?php echo $stepLabels[$s] ?? '-'; ?></td>
                            <td>
                                <?php
                                $sw = $templates[$s]['sw'] ?? null;
                                if ($sw):
                                ?>
                                <a href="?edit=<?php echo $s; ?>&lang=sw" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.75rem;">
                                    <i class="bi bi-pencil"></i> Edit
                                    <?php if (!$sw['is_active']): ?><span class="text-danger ms-1">(off)</span><?php endif; ?>
                                </a>
                                <?php else: ?>
                                <a href="?edit=<?php echo $s; ?>&lang=sw" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:0.75rem;">
                                    <i class="bi bi-plus"></i> Create
                                </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $en = $templates[$s]['en'] ?? null;
                                if ($en):
                                ?>
                                <a href="?edit=<?php echo $s; ?>&lang=en" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.75rem;">
                                    <i class="bi bi-pencil"></i> Edit
                                    <?php if (!$en['is_active']): ?><span class="text-danger ms-1">(off)</span><?php endif; ?>
                                </a>
                                <?php else: ?>
                                <a href="?edit=<?php echo $s; ?>&lang=en" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:0.75rem;">
                                    <i class="bi bi-plus"></i> Create
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
