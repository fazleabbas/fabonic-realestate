<?php
/**
 * FIS Real Estate — Inspection Template Builder (11.1)
 * Manage inspection templates: rooms, items per room, condition options.
 * Two-column layout: template list + editor (same pattern as lease templates).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('inspection_templates', 'Inspection Templates');

$subStatus = enforceSubscription();

if (!hasPermission('inspections', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Get templates
$templates = $conn->query("SELECT * FROM inspection_templates ORDER BY is_default DESC, name")->fetchAll();

// If editing, load template
$editTemplate = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId) {
    $stmt = $conn->prepare("SELECT * FROM inspection_templates WHERE id = :id");
    $stmt->execute(['id' => $editId]);
    $editTemplate = $stmt->fetch();
}

// Handle form submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    if (!hasPermission('inspections', 'edit')) {
        setAlert(__('no_permission'), 'danger');
        redirect('modules/inspections/templates.php');
    }

    $postAction = $_POST['form_action'] ?? '';

    if ($postAction === 'save_template') {
        $tplId = (int)($_POST['template_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $isDefault = (int)($_POST['is_default'] ?? 0);

        // Build rooms_json from form arrays
        $roomNames = $_POST['room_name'] ?? [];
        $roomItems = $_POST['room_items'] ?? [];

        if (!$name) {
            $errors[] = __('template_name_required');
        }

        $rooms = [];
        if (is_array($roomNames)) {
            foreach ($roomNames as $idx => $rName) {
                $rName = trim($rName);
                if ($rName === '') continue;
                $items = [];
                $rawItems = $roomItems[$idx] ?? '';
                foreach (explode("\n", $rawItems) as $line) {
                    $line = trim($line);
                    if ($line !== '') $items[] = $line;
                }
                if (!empty($items)) {
                    $rooms[] = ['room' => $rName, 'items' => $items];
                }
            }
        }

        if (empty($rooms)) {
            $errors[] = __('insp_rooms_required', 'At least one room with items is required');
        }

        if (empty($errors)) {
            try {
                $conn->beginTransaction();

                if ($isDefault) {
                    $conn->exec("UPDATE inspection_templates SET is_default = 0");
                }

                $roomsJson = json_encode($rooms, JSON_UNESCAPED_UNICODE);

                if ($tplId) {
                    $stmt = $conn->prepare("UPDATE inspection_templates SET name = :name, rooms_json = :rooms, is_default = :def WHERE id = :id");
                    $stmt->execute(['name' => $name, 'rooms' => $roomsJson, 'def' => $isDefault, 'id' => $tplId]);
                } else {
                    $stmt = $conn->prepare("INSERT INTO inspection_templates (name, rooms_json, is_default) VALUES (:name, :rooms, :def)");
                    $stmt->execute(['name' => $name, 'rooms' => $roomsJson, 'def' => $isDefault]);
                    $tplId = (int) $conn->lastInsertId();
                }

                $conn->commit();
                logActivity($editId ? 'updated' : 'created', 'inspection_template', $tplId, ['name' => $name]);
                setAlert(__('template_saved', 'Template saved successfully!'), 'success');
                redirect('modules/inspections/templates.php');
            } catch (PDOException $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($postAction === 'delete_template') {
        if (!hasPermission('inspections', 'delete')) {
            setAlert(__('no_permission'), 'danger');
            redirect('modules/inspections/templates.php');
        }
        $delId = (int)($_POST['template_id'] ?? 0);
        if ($delId) {
            $conn->prepare("DELETE FROM inspection_templates WHERE id = :id")->execute(['id' => $delId]);
            logActivity('deleted', 'inspection_template', $delId);
            setAlert(__('template_deleted', 'Template deleted'), 'success');
        }
        redirect('modules/inspections/templates.php');
    }
}

// Prepare edit data
$editRooms = [];
if ($editTemplate) {
    $editRooms = json_decode($editTemplate['rooms_json'], true) ?: [];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-clipboard-data me-2"></i><?php _e('inspection_templates', 'Inspection Templates'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/inspections/"><?php _e('inspections'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('templates'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('inspections.templates'); ?>

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
    <!-- Template List -->
    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-ul me-1"></i> <?php _e('templates'); ?> (<?php echo count($templates); ?>)</span>
                <a href="<?php echo BASE_URL; ?>modules/inspections/templates.php" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-plus me-1"></i><?php _e('new', 'New'); ?>
                </a>
            </div>
            <div class="list-group list-group-flush">
                <?php if (empty($templates)): ?>
                <div class="list-group-item text-center text-muted py-4">
                    <i class="bi bi-clipboard-data" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0"><?php _e('no_templates'); ?></p>
                </div>
                <?php else: ?>
                <?php foreach ($templates as $tpl):
                    $rooms = json_decode($tpl['rooms_json'], true) ?: [];
                    $totalItems = 0;
                    foreach ($rooms as $r) $totalItems += count($r['items'] ?? []);
                ?>
                <a href="?edit=<?php echo $tpl['id']; ?>" class="list-group-item list-group-item-action <?php echo $editId === (int)$tpl['id'] ? 'active' : ''; ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong><?php echo htmlspecialchars($tpl['name']); ?></strong>
                            <?php if ($tpl['is_default']): ?>
                            <span class="badge bg-success ms-1"><?php _e('default'); ?></span>
                            <?php endif; ?>
                            <br><small class="<?php echo $editId === (int)$tpl['id'] ? '' : 'text-muted'; ?>">
                                <?php echo count($rooms); ?> <?php _e('insp_rooms', 'rooms'); ?>, <?php echo $totalItems; ?> <?php _e('insp_items', 'items'); ?>
                            </small>
                        </div>
                        <?php if (hasPermission('inspections', 'delete')): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo __('delete_template_confirm'); ?>?');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="form_action" value="delete_template">
                            <input type="hidden" name="template_id" value="<?php echo $tpl['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger border-0" onclick="event.stopPropagation();" title="<?php _e('delete'); ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Template Editor -->
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-pencil-square me-1"></i>
                <?php echo $editTemplate ? __('edit_template') : __('create_template'); ?>
            </div>
            <div class="card-body">
                <?php if (hasPermission('inspections', 'edit')): ?>
                <form method="POST" id="templateForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="form_action" value="save_template">
                    <input type="hidden" name="template_id" value="<?php echo $editTemplate ? $editTemplate['id'] : 0; ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-8">
                            <label class="form-label fw-semibold"><?php _e('template_name'); ?> <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($editTemplate['name'] ?? ''); ?>" placeholder="<?php _e('insp_tpl_name_placeholder', 'e.g. Standard Residential, Commercial Unit...'); ?>" required>
                        </div>
                        <div class="col-12 col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="is_default" value="1" id="isDefault" <?php echo ($editTemplate['is_default'] ?? 0) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="isDefault"><?php _e('set_as_default'); ?></label>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h6 class="fw-semibold mb-3"><i class="bi bi-house-door me-1"></i> <?php _e('insp_rooms_items', 'Rooms & Items'); ?></h6>
                    <p class="text-muted small mb-3"><?php _e('insp_rooms_help', 'Add rooms and list items to inspect (one item per line). Items will become checklist rows during inspections.'); ?></p>

                    <div id="roomsContainer">
                        <?php
                        $roomIndex = 0;
                        if (!empty($editRooms)):
                            foreach ($editRooms as $room):
                        ?>
                        <div class="room-block card border mb-3" data-index="<?php echo $roomIndex; ?>">
                            <div class="card-header py-2 d-flex justify-content-between align-items-center bg-light">
                                <div class="flex-grow-1 me-2">
                                    <input type="text" name="room_name[<?php echo $roomIndex; ?>]" class="form-control form-control-sm fw-semibold" value="<?php echo htmlspecialchars($room['room']); ?>" placeholder="<?php _e('insp_room_name', 'Room name...'); ?>">
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-danger remove-room" title="<?php _e('delete'); ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                            <div class="card-body py-2">
                                <textarea name="room_items[<?php echo $roomIndex; ?>]" class="form-control form-control-sm" rows="4" placeholder="<?php _e('insp_items_placeholder', 'One item per line: Walls, Floor, Ceiling...'); ?>"><?php echo htmlspecialchars(implode("\n", $room['items'] ?? [])); ?></textarea>
                            </div>
                        </div>
                        <?php
                            $roomIndex++;
                            endforeach;
                        else:
                        ?>
                        <div class="room-block card border mb-3" data-index="0">
                            <div class="card-header py-2 d-flex justify-content-between align-items-center bg-light">
                                <div class="flex-grow-1 me-2">
                                    <input type="text" name="room_name[0]" class="form-control form-control-sm fw-semibold" placeholder="<?php _e('insp_room_name', 'Room name...'); ?>">
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-danger remove-room" title="<?php _e('delete'); ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                            <div class="card-body py-2">
                                <textarea name="room_items[0]" class="form-control form-control-sm" rows="4" placeholder="<?php _e('insp_items_placeholder', 'One item per line: Walls, Floor, Ceiling...'); ?>"></textarea>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="addRoomBtn">
                        <i class="bi bi-plus-circle me-1"></i> <?php _e('insp_add_room', 'Add Room'); ?>
                    </button>

                    <hr>

                    <div class="d-flex justify-content-between">
                        <a href="<?php echo BASE_URL; ?>modules/inspections/" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i> <?php echo $editTemplate ? __('save_changes') : __('create_template'); ?>
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <p><?php _e('no_permission'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Condition Reference Card -->
        <div class="card mt-3">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> <?php _e('insp_condition_options', 'Condition Options'); ?></div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge bg-success"><?php _e('cond_excellent', 'Excellent'); ?></span>
                    <span class="badge bg-primary"><?php _e('cond_good', 'Good'); ?></span>
                    <span class="badge bg-info text-dark"><?php _e('cond_fair', 'Fair'); ?></span>
                    <span class="badge bg-warning text-dark"><?php _e('cond_poor', 'Poor'); ?></span>
                    <span class="badge bg-danger"><?php _e('cond_damaged', 'Damaged'); ?></span>
                    <span class="badge bg-dark"><?php _e('cond_missing', 'Missing'); ?></span>
                </div>
                <small class="text-muted d-block mt-2"><?php _e('insp_condition_help', 'These are the condition ratings inspectors will choose from for each item during inspections.'); ?></small>
            </div>
        </div>
    </div>
</div>

<script>
let roomIndex = <?php echo $roomIndex ?: 1; ?>;

document.getElementById('addRoomBtn').addEventListener('click', function() {
    const html = `
    <div class="room-block card border mb-3" data-index="${roomIndex}">
        <div class="card-header py-2 d-flex justify-content-between align-items-center bg-light">
            <div class="flex-grow-1 me-2">
                <input type="text" name="room_name[${roomIndex}]" class="form-control form-control-sm fw-semibold" placeholder="<?php echo __('insp_room_name', 'Room name...'); ?>">
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger remove-room" title="<?php echo __('delete'); ?>">
                <i class="bi bi-trash"></i>
            </button>
        </div>
        <div class="card-body py-2">
            <textarea name="room_items[${roomIndex}]" class="form-control form-control-sm" rows="4" placeholder="<?php echo __('insp_items_placeholder', 'One item per line: Walls, Floor, Ceiling...'); ?>"></textarea>
        </div>
    </div>`;
    document.getElementById('roomsContainer').insertAdjacentHTML('beforeend', html);
    roomIndex++;
});

document.addEventListener('click', function(e) {
    if (e.target.closest('.remove-room')) {
        const blocks = document.querySelectorAll('.room-block');
        if (blocks.length > 1) {
            e.target.closest('.room-block').remove();
        } else {
            alert('<?php echo __('insp_min_one_room', 'At least one room is required'); ?>');
        }
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
