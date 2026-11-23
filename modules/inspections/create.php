<?php
/**
 * FIS Real Estate — Create Inspection (11.2)
 * Move-in/move-out/routine inspection form.
 * Room-by-room checklist with condition rating, photo upload per item.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('new_inspection', 'New Inspection');

$subStatus = enforceSubscription();

if (!hasPermission('inspections', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Get leases for dropdown (active + recently expired/terminated)
$leasesStmt = $conn->query("
    SELECT l.id, l.start_date, l.end_date, l.status AS lease_status,
           u.unit_number, u.id AS unit_id, p.name AS property_name, p.id AS property_id,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.id AS tenant_id
    FROM leases l
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON l.tenant_id = t.id
    WHERE l.status IN ('active', 'expired', 'terminated')" . propertyScopeSql('p.id') . "
    ORDER BY l.status = 'active' DESC, t.first_name, t.last_name
");
$leases = $leasesStmt->fetchAll();

// Get templates for dropdown
$templateStmt = $conn->query("SELECT id, name, rooms_json, is_default FROM inspection_templates ORDER BY is_default DESC, name");
$templates = $templateStmt->fetchAll();

// Pre-select type if passed via query param
$preType = $_GET['type'] ?? '';
$preLeaseId = (int)($_GET['lease_id'] ?? 0);

// Handle form submission
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $old = [
        'lease_id' => (int)($_POST['lease_id'] ?? 0),
        'unit_id' => (int)($_POST['unit_id'] ?? 0),
        'tenant_id' => (int)($_POST['tenant_id'] ?? 0),
        'inspection_type' => trim($_POST['inspection_type'] ?? 'move_in'),
        'inspection_date' => trim($_POST['inspection_date'] ?? ''),
        'inspector_name' => trim($_POST['inspector_name'] ?? ''),
        'overall_notes' => trim($_POST['overall_notes'] ?? ''),
        'template_id' => (int)($_POST['template_id'] ?? 0),
    ];

    $validTypes = ['move_in', 'move_out', 'routine'];

    if (!$old['unit_id'] && !$old['lease_id']) $errors[] = __('insp_unit_or_lease_required', 'Unit or lease is required');
    if (!in_array($old['inspection_type'], $validTypes)) $errors[] = __('insp_invalid_type', 'Invalid inspection type');
    if (!$old['inspection_date']) $errors[] = __('insp_date_required', 'Inspection date is required');

    // If lease selected, derive unit and tenant
    if ($old['lease_id']) {
        $leaseCheck = $conn->prepare("SELECT l.unit_id, l.tenant_id FROM leases l WHERE l.id = :id");
        $leaseCheck->execute(['id' => $old['lease_id']]);
        $leaseData = $leaseCheck->fetch();
        if (!$leaseData) {
            $errors[] = __('lease_not_found');
        } else {
            $old['unit_id'] = (int)$leaseData['unit_id'];
            $old['tenant_id'] = (int)$leaseData['tenant_id'];
        }
    }

    // Property-based access scoping: ensure the target unit is in scope
    if (empty($errors) && $old['unit_id'] && !canAccessUnit($old['unit_id'])) {
        $errors[] = __('no_access_record', "You don't have access to this record");
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO inspections (unit_id, lease_id, tenant_id, inspection_type,
                    inspection_date, inspector_name, status, overall_notes)
                VALUES (:unit_id, :lease_id, :tenant_id, :type,
                    :date, :inspector, 'in_progress', :notes)
            ");
            $stmt->execute([
                'unit_id' => $old['unit_id'],
                'lease_id' => $old['lease_id'] ?: null,
                'tenant_id' => $old['tenant_id'] ?: null,
                'type' => $old['inspection_type'],
                'date' => date('Y-m-d', strtotime($old['inspection_date'])),
                'inspector' => $old['inspector_name'] ?: null,
                'notes' => $old['overall_notes'] ?: null,
            ]);

            $inspectionId = (int) $conn->lastInsertId();

            // Pre-populate items from template
            if ($old['template_id']) {
                $tplStmt = $conn->prepare("SELECT rooms_json FROM inspection_templates WHERE id = :id");
                $tplStmt->execute(['id' => $old['template_id']]);
                $tplData = $tplStmt->fetch();

                if ($tplData && $tplData['rooms_json']) {
                    $rooms = json_decode($tplData['rooms_json'], true);
                    if (is_array($rooms)) {
                        $itemInsert = $conn->prepare("
                            INSERT INTO inspection_items (inspection_id, room, item_name, `condition`, notes)
                            VALUES (:iid, :room, :item, 'good', '')
                        ");
                        foreach ($rooms as $roomData) {
                            foreach ($roomData['items'] ?? [] as $itemName) {
                                $itemInsert->execute([
                                    'iid' => $inspectionId,
                                    'room' => $roomData['room'],
                                    'item' => $itemName,
                                ]);
                            }
                        }
                    }
                }
            }

            $conn->commit();

            logActivity('created', 'inspection', $inspectionId, [
                'type' => $old['inspection_type'],
                'unit_id' => $old['unit_id'],
            ]);

            setAlert(__('inspection_created', 'Inspection created! Now rate each item.'), 'success');
            redirect('modules/inspections/view.php?id=' . $inspectionId);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-plus-circle me-2"></i><?php _e('new_inspection', 'New Inspection'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/inspections/"><?php _e('inspections'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('create'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('inspections.create'); ?>

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

<!-- Create Form -->
<div class="card">
    <div class="card-body">
        <form method="POST" id="inspectionForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="unit_id" id="unitIdHidden" value="<?php echo $old['unit_id'] ?? 0; ?>">
            <input type="hidden" name="tenant_id" id="tenantIdHidden" value="<?php echo $old['tenant_id'] ?? 0; ?>">

            <!-- Inspection Type -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-clipboard-check me-1"></i> <?php _e('insp_type_section', 'Inspection Type'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('insp_type', 'Type'); ?> <span class="text-danger">*</span></label>
                    <select name="inspection_type" id="inspType" class="form-select" required>
                        <option value="move_in" <?php echo ($old['inspection_type'] ?? $preType) === 'move_in' ? 'selected' : ''; ?>><?php _e('insp_move_in', 'Move-in Inspection'); ?></option>
                        <option value="move_out" <?php echo ($old['inspection_type'] ?? $preType) === 'move_out' ? 'selected' : ''; ?>><?php _e('insp_move_out', 'Move-out Inspection'); ?></option>
                        <option value="routine" <?php echo ($old['inspection_type'] ?? $preType) === 'routine' ? 'selected' : ''; ?>><?php _e('insp_routine', 'Routine Inspection'); ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('insp_date', 'Inspection Date'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="inspection_date" value="<?php echo htmlspecialchars($old['inspection_date'] ?? date('Y-m-d')); ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('inspector_name', 'Inspector Name'); ?></label>
                    <input type="text" class="form-control" name="inspector_name" value="<?php echo htmlspecialchars($old['inspector_name'] ?? getUserData('name')); ?>" placeholder="<?php _e('insp_inspector_placeholder', 'Name of person conducting inspection'); ?>">
                </div>
            </div>

            <hr>

            <!-- Lease Selection -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-link-45deg me-1"></i> <?php _e('select_lease'); ?></h6>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('lease', 'Lease'); ?></label>
                    <select name="lease_id" id="leaseSelect" class="form-select">
                        <option value=""><?php _e('insp_select_lease', 'Select a lease (tenant — unit — property)...'); ?></option>
                        <?php foreach ($leases as $l): ?>
                        <option value="<?php echo $l['id']; ?>"
                                data-unit-id="<?php echo $l['unit_id']; ?>"
                                data-tenant-id="<?php echo $l['tenant_id']; ?>"
                                data-tenant="<?php echo htmlspecialchars($l['tenant_name']); ?>"
                                data-unit="<?php echo htmlspecialchars($l['unit_number']); ?>"
                                data-property="<?php echo htmlspecialchars($l['property_name']); ?>"
                                data-status="<?php echo $l['lease_status']; ?>"
                                <?php echo ($old['lease_id'] ?? $preLeaseId) == $l['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($l['tenant_name'] . ' — ' . $l['unit_number'] . ' (' . $l['property_name'] . ') — ' . ucfirst($l['lease_status'])); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Lease Info Bar -->
            <div id="leaseInfoBar" class="alert alert-info mt-3 d-none">
                <div class="d-flex flex-wrap gap-3">
                    <span><i class="bi bi-person me-1"></i><strong id="infoTenant"></strong></span>
                    <span><i class="bi bi-door-open me-1"></i><span id="infoUnit"></span></span>
                    <span><i class="bi bi-buildings me-1"></i><span id="infoProperty"></span></span>
                </div>
            </div>

            <hr>

            <!-- Template Selection -->
            <h6 class="fw-semibold mb-3"><i class="bi bi-clipboard-data me-1"></i> <?php _e('insp_template', 'Inspection Template'); ?></h6>
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('insp_select_template', 'Select Template'); ?></label>
                    <select name="template_id" id="templateSelect" class="form-select">
                        <option value=""><?php _e('insp_no_template', 'No template (add items manually later)'); ?></option>
                        <?php foreach ($templates as $tpl): ?>
                        <option value="<?php echo $tpl['id']; ?>" <?php echo $tpl['is_default'] ? 'selected' : ''; ?>
                                data-rooms='<?php echo htmlspecialchars($tpl['rooms_json']); ?>'>
                            <?php echo htmlspecialchars($tpl['name']); ?> <?php echo $tpl['is_default'] ? '(' . __('default') . ')' : ''; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted"><?php _e('insp_template_help', 'Template pre-populates the checklist items. You can add more items after creating the inspection.'); ?></small>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold"><?php _e('insp_template_preview', 'Template Preview'); ?></label>
                    <div id="templatePreview" class="border rounded p-2" style="max-height:200px;overflow-y:auto;font-size:0.85rem;">
                        <span class="text-muted"><?php _e('insp_select_to_preview', 'Select a template to see its rooms and items'); ?></span>
                    </div>
                </div>
            </div>

            <hr>

            <!-- Notes -->
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold"><?php _e('insp_overall_notes', 'Overall Notes'); ?></label>
                    <textarea class="form-control" name="overall_notes" rows="3" placeholder="<?php _e('insp_notes_placeholder', 'General notes about the inspection...'); ?>"><?php echo htmlspecialchars($old['overall_notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/inspections/" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> <?php _e('create_inspection', 'Create Inspection'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#leaseSelect').select2({ width: '100%', placeholder: '<?php echo __("insp_select_lease", "Select a lease..."); ?>' });

    // Lease selection
    $('#leaseSelect').on('change', function() {
        const opt = $(this).find(':selected');
        const val = $(this).val();

        if (!val) {
            $('#leaseInfoBar').addClass('d-none');
            $('#unitIdHidden').val('');
            $('#tenantIdHidden').val('');
            return;
        }

        $('#infoTenant').text(opt.data('tenant'));
        $('#infoUnit').text(opt.data('unit'));
        $('#infoProperty').text(opt.data('property'));
        $('#leaseInfoBar').removeClass('d-none');
        $('#unitIdHidden').val(opt.data('unit-id'));
        $('#tenantIdHidden').val(opt.data('tenant-id'));
    });

    if ($('#leaseSelect').val()) {
        $('#leaseSelect').trigger('change');
    }

    // Template preview
    function updatePreview() {
        const opt = $('#templateSelect').find(':selected');
        const roomsStr = opt.data('rooms');
        const preview = $('#templatePreview');

        if (!roomsStr) {
            preview.html('<span class="text-muted"><?php echo __("insp_select_to_preview", "Select a template to see its rooms and items"); ?></span>');
            return;
        }

        try {
            const rooms = typeof roomsStr === 'string' ? JSON.parse(roomsStr) : roomsStr;
            let html = '';
            rooms.forEach(r => {
                html += '<strong>' + r.room + '</strong><br>';
                r.items.forEach(item => {
                    html += '<small class="ms-2 text-muted">• ' + item + '</small><br>';
                });
            });
            preview.html(html);
        } catch (e) {
            preview.html('<span class="text-danger">Error parsing template</span>');
        }
    }

    $('#templateSelect').on('change', updatePreview);
    updatePreview();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
