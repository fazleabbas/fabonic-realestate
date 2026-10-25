<?php
/**
 * FIS Real Estate — Lease Templates Management (8.2)
 * CRUD for lease_templates (name, content with HTML placeholders, is_default).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('lease_templates', 'Lease Templates');

$subStatus = enforceSubscription();

if (!hasPermission('leases', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Fetch all templates
$stmt = $conn->query("SELECT * FROM lease_templates ORDER BY is_default DESC, name");
$templates = $stmt->fetchAll();

// Check if editing
$editingTemplate = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editStmt = $conn->prepare("SELECT * FROM lease_templates WHERE id = :id");
    $editStmt->execute(['id' => $editId]);
    $editingTemplate = $editStmt->fetch();
}

// Handle form submission
$errors = [];
$formName = '';
$formContent = '';
$formIsDefault = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $postAction = trim($_POST['form_action'] ?? 'create');
    $postId = (int)($_POST['template_id'] ?? 0);
    $formName = trim($_POST['name'] ?? '');
    $formContent = trim($_POST['content'] ?? '');
    $formIsDefault = (int)($_POST['is_default'] ?? 0);

    if ($formName === '') $errors[] = __('template_name_required', 'Template name is required');
    if ($formContent === '') $errors[] = __('template_content_required', 'Template content is required');

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            if ($formIsDefault) {
                $conn->exec("UPDATE lease_templates SET is_default = 0");
            }

            if ($postAction === 'update' && $postId > 0) {
                $stmt = $conn->prepare("UPDATE lease_templates SET name = :name, content = :content, is_default = :is_default WHERE id = :id");
                $stmt->execute(['name' => $formName, 'content' => $formContent, 'is_default' => $formIsDefault, 'id' => $postId]);
                logActivity('updated', 'lease_template', $postId, ['name' => $formName]);
                setAlert(__('template_updated', 'Template updated successfully!'), 'success');
            } else {
                $stmt = $conn->prepare("INSERT INTO lease_templates (name, content, is_default) VALUES (:name, :content, :is_default)");
                $stmt->execute(['name' => $formName, 'content' => $formContent, 'is_default' => $formIsDefault]);
                $templateId = (int) $conn->lastInsertId();
                logActivity('created', 'lease_template', $templateId, ['name' => $formName]);
                setAlert(__('template_created', 'Template created successfully!'), 'success');
            }

            $conn->commit();
            redirect('modules/leases/templates.php');
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// If editing, pre-fill
if ($editingTemplate && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $formName = $editingTemplate['name'];
    $formContent = $editingTemplate['content'];
    $formIsDefault = (int)$editingTemplate['is_default'];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-file-earmark-richtext me-2"></i><?php _e('lease_templates', 'Lease Templates'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/leases/"><?php _e('leases'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('lease_templates', 'Templates'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('leases.templates'); ?>

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

<div class="row g-3">
    <!-- Templates List -->
    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list me-1"></i> <?php _e('templates', 'Templates'); ?> (<?php echo count($templates); ?>)</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($templates)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-file-earmark-richtext" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('no_templates', 'No templates yet. Create one below.'); ?></p>
                </div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($templates as $tpl): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?php echo htmlspecialchars($tpl['name']); ?></strong>
                            <?php if ($tpl['is_default']): ?>
                            <span class="badge bg-primary ms-1" style="font-size:0.65rem;"><?php _e('default', 'Default'); ?></span>
                            <?php endif; ?>
                            <br><small class="text-muted"><?php echo formatDateTime($tpl['updated_at']); ?></small>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <a href="?edit=<?php echo $tpl['id']; ?>" class="btn btn-outline-primary" title="<?php _e('edit'); ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if (hasPermission('leases', 'delete')): ?>
                            <button class="btn btn-outline-danger" onclick="deleteTemplate(<?php echo $tpl['id']; ?>, '<?php echo htmlspecialchars(addslashes($tpl['name'])); ?>')" title="<?php _e('delete'); ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Placeholder Help -->
        <div class="card mt-3">
            <div class="card-header"><i class="bi bi-code-slash me-1"></i> <?php _e('available_placeholders', 'Available Placeholders'); ?></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="fw-semibold small text-muted mb-1"><?php _e('tenant', 'Tenant'); ?></div>
                        <code class="d-block mb-1">{{tenant_name}}</code>
                        <code class="d-block mb-1">{{tenant_phone}}</code>
                        <code class="d-block mb-1">{{tenant_email}}</code>
                        <code class="d-block mb-1">{{tenant_id_type}}</code>
                        <code class="d-block mb-1">{{tenant_id_number}}</code>
                    </div>
                    <div class="col-6">
                        <div class="fw-semibold small text-muted mb-1"><?php _e('property_unit', 'Property & Unit'); ?></div>
                        <code class="d-block mb-1">{{unit_number}}</code>
                        <code class="d-block mb-1">{{unit_type}}</code>
                        <code class="d-block mb-1">{{property_name}}</code>
                        <code class="d-block mb-1">{{property_address}}</code>
                        <code class="d-block mb-1">{{property_city}}</code>
                    </div>
                    <div class="col-6">
                        <div class="fw-semibold small text-muted mb-1"><?php _e('lease', 'Lease'); ?></div>
                        <code class="d-block mb-1">{{rent_amount}}</code>
                        <code class="d-block mb-1">{{deposit_amount}}</code>
                        <code class="d-block mb-1">{{payment_frequency}}</code>
                        <code class="d-block mb-1">{{payment_due_day}}</code>
                        <code class="d-block mb-1">{{start_date}}</code>
                        <code class="d-block mb-1">{{end_date}}</code>
                        <code class="d-block mb-1">{{terms}}</code>
                        <code class="d-block mb-1">{{lease_id}}</code>
                    </div>
                    <div class="col-6">
                        <div class="fw-semibold small text-muted mb-1"><?php _e('company_document', 'Company & Document'); ?></div>
                        <code class="d-block mb-1">{{company_name}}</code>
                        <code class="d-block mb-1">{{company_phone}}</code>
                        <code class="d-block mb-1">{{company_email}}</code>
                        <code class="d-block mb-1">{{company_address}}</code>
                        <code class="d-block mb-1">{{date_today}}</code>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create/Edit Form -->
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-<?php echo $editingTemplate ? 'pencil' : 'plus-circle'; ?> me-1"></i>
                <?php echo $editingTemplate ? __('edit_template', 'Edit Template') : __('create_template', 'Create Template'); ?>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="form_action" value="<?php echo $editingTemplate ? 'update' : 'create'; ?>">
                    <?php if ($editingTemplate): ?>
                    <input type="hidden" name="template_id" value="<?php echo $editingTemplate['id']; ?>">
                    <?php endif; ?>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-8">
                            <label class="form-label fw-semibold"><?php _e('template_name', 'Template Name'); ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($formName); ?>" required placeholder="e.g. Standard Residential Lease">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">&nbsp;</label>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="is_default" value="1" id="isDefault" <?php echo $formIsDefault ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="isDefault"><?php _e('set_as_default', 'Set as default'); ?></label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php _e('template_content', 'Template Content (HTML)'); ?> <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="content" id="templateContent" rows="18" style="font-family:monospace;font-size:0.85rem;" required placeholder="<?php _e('template_content_placeholder', 'Enter HTML content with {{placeholders}}...'); ?>"><?php echo htmlspecialchars($formContent); ?></textarea>
                        <small class="text-muted"><?php _e('template_html_note', 'Use HTML for formatting. Placeholders like {{tenant_name}} will be replaced with actual data.'); ?></small>
                    </div>

                    <div class="d-flex justify-content-between">
                        <?php if ($editingTemplate): ?>
                        <a href="<?php echo BASE_URL; ?>modules/leases/templates.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-1"></i> <?php _e('cancel'); ?>
                        </a>
                        <?php else: ?>
                        <div></div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>
                            <?php echo $editingTemplate ? __('save_changes', 'Save Changes') : __('create_template', 'Create Template'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo generateCSRF(); ?>';
const apiUrl = BASE_URL + 'api/leases.php';

function deleteTemplate(id, name) {
    if (!confirm('<?php echo __("delete_template_confirm", "Delete template"); ?> "' + name + '"?')) return;

    const formData = new FormData();
    formData.append('action', 'delete_template');
    formData.append('csrf_token', csrfToken);
    formData.append('id', id);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(() => alert('Request failed'));
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
