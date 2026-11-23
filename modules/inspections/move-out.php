<?php
/**
 * FIS Real Estate — Move-out Inspection (11.3)
 * Pre-populated from the move-in inspection with condition comparison.
 * Creates a new move_out inspection with items from the move-in.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('insp_create_moveout', 'Create Move-out Inspection');

$subStatus = enforceSubscription();

if (!hasPermission('inspections', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$leaseId = (int)($_GET['lease_id'] ?? 0);
if (!$leaseId) {
    setAlert(__('lease_required'), 'danger');
    redirect('modules/inspections/');
}

// Fetch lease info
$leaseStmt = $conn->prepare("
    SELECT l.*, u.unit_number, u.id AS unit_id, p.name AS property_name,
           CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.id AS tenant_id
    FROM leases l
    INNER JOIN units u ON l.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    INNER JOIN tenants t ON l.tenant_id = t.id
    WHERE l.id = :id
");
$leaseStmt->execute(['id' => $leaseId]);
$lease = $leaseStmt->fetch();

if (!$lease) {
    setAlert(__('lease_not_found'), 'danger');
    redirect('modules/inspections/');
}

if (!canAccessUnit($lease['unit_id'])) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/inspections/');
}

// Find existing move-in inspection for this lease
$moveInStmt = $conn->prepare("
    SELECT i.* FROM inspections i
    WHERE i.lease_id = :lid AND i.inspection_type = 'move_in' AND i.status = 'completed'
    ORDER BY i.inspection_date DESC LIMIT 1
");
$moveInStmt->execute(['lid' => $leaseId]);
$moveIn = $moveInStmt->fetch();

$moveInItems = [];
if ($moveIn) {
    $miItemStmt = $conn->prepare("SELECT * FROM inspection_items WHERE inspection_id = :iid ORDER BY room, item_name");
    $miItemStmt->execute(['iid' => $moveIn['id']]);
    $moveInItems = $miItemStmt->fetchAll();
}

// Group move-in items by room
$moveInByRoom = [];
foreach ($moveInItems as $item) {
    $moveInByRoom[$item['room']][] = $item;
}

// Handle form submission — create move-out inspection with pre-populated items
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $inspectionDate = trim($_POST['inspection_date'] ?? date('Y-m-d'));
    $inspectorName = trim($_POST['inspector_name'] ?? '');
    $overallNotes = trim($_POST['overall_notes'] ?? '');

    if (!$inspectionDate) $errors[] = __('insp_date_required', 'Inspection date is required');

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Create the move-out inspection
            $stmt = $conn->prepare("
                INSERT INTO inspections (unit_id, lease_id, tenant_id, inspection_type,
                    inspection_date, inspector_name, status, overall_notes)
                VALUES (:unit_id, :lease_id, :tenant_id, 'move_out',
                    :date, :inspector, 'in_progress', :notes)
            ");
            $stmt->execute([
                'unit_id' => $lease['unit_id'],
                'lease_id' => $leaseId,
                'tenant_id' => $lease['tenant_id'],
                'date' => date('Y-m-d', strtotime($inspectionDate)),
                'inspector' => $inspectorName ?: null,
                'notes' => $overallNotes ?: null,
            ]);

            $inspectionId = (int) $conn->lastInsertId();

            // Pre-populate items from move-in inspection (keeps same rooms/items)
            if (!empty($moveInItems)) {
                $itemInsert = $conn->prepare("
                    INSERT INTO inspection_items (inspection_id, room, item_name, `condition`, notes)
                    VALUES (:iid, :room, :item, :cond, '')
                ");
                foreach ($moveInItems as $mi) {
                    $itemInsert->execute([
                        'iid' => $inspectionId,
                        'room' => $mi['room'],
                        'item' => $mi['item_name'],
                        'cond' => $mi['condition'], // Start with move-in condition
                    ]);
                }
            } else {
                // No move-in inspection — use default template
                $tplStmt = $conn->query("SELECT rooms_json FROM inspection_templates WHERE is_default = 1 LIMIT 1");
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
                'type' => 'move_out',
                'lease_id' => $leaseId,
                'move_in_id' => $moveIn ? $moveIn['id'] : null,
            ]);

            setAlert(__('insp_moveout_created', 'Move-out inspection created! Now update conditions for each item.'), 'success');
            redirect('modules/inspections/view.php?id=' . $inspectionId);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$condLabels = [
    'excellent' => __('cond_excellent', 'Excellent'),
    'good' => __('cond_good', 'Good'),
    'fair' => __('cond_fair', 'Fair'),
    'poor' => __('cond_poor', 'Poor'),
    'damaged' => __('cond_damaged', 'Damaged'),
    'missing' => __('cond_missing', 'Missing'),
];
$condColors = [
    'excellent' => 'success', 'good' => 'primary', 'fair' => 'info',
    'poor' => 'warning', 'damaged' => 'danger', 'missing' => 'dark'
];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-box-arrow-right me-2"></i><?php _e('insp_create_moveout', 'Create Move-out Inspection'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/inspections/"><?php _e('inspections'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('insp_move_out', 'Move-out'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('inspections.move-out'); ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?php echo htmlspecialchars($err); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Lease Info -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span><i class="bi bi-person me-1"></i><strong><?php echo htmlspecialchars($lease['tenant_name']); ?></strong></span>
            <span><i class="bi bi-door-open me-1"></i><?php echo htmlspecialchars($lease['unit_number']); ?></span>
            <span><i class="bi bi-buildings me-1"></i><?php echo htmlspecialchars($lease['property_name']); ?></span>
            <span><i class="bi bi-file-earmark-text me-1"></i><?php _e('lease'); ?> #<?php echo $leaseId; ?></span>
            <?php if ($moveIn): ?>
            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i><?php _e('insp_has_movein', 'Move-in inspection found'); ?></span>
            <?php else: ?>
            <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i><?php _e('insp_no_movein', 'No move-in inspection found'); ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Move-in Inspection Preview -->
<?php if ($moveIn && !empty($moveInByRoom)): ?>
<div class="card mb-3">
    <div class="card-header bg-light">
        <i class="bi bi-clipboard-check me-1"></i> <?php _e('insp_movein_reference', 'Move-in Inspection Reference'); ?>
        <small class="text-muted ms-2">(<?php echo formatDate($moveIn['inspection_date']); ?>)</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th><?php _e('insp_room', 'Room'); ?></th>
                        <th><?php _e('insp_item', 'Item'); ?></th>
                        <th><?php _e('insp_movein_condition', 'Move-in Condition'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('notes'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($moveInByRoom as $roomName => $items): ?>
                    <?php foreach ($items as $idx => $item): ?>
                    <tr>
                        <?php if ($idx === 0): ?>
                        <td rowspan="<?php echo count($items); ?>" class="fw-semibold align-middle bg-light"><?php echo htmlspecialchars($roomName); ?></td>
                        <?php endif; ?>
                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $condColors[$item['condition']] ?? 'secondary'; ?>">
                                <?php echo $condLabels[$item['condition']] ?? ucfirst($item['condition']); ?>
                            </span>
                        </td>
                        <td class="d-none d-md-table-cell"><small class="text-muted"><?php echo htmlspecialchars($item['notes'] ?? ''); ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Create Move-out Form -->
<div class="card">
    <div class="card-body">
        <form method="POST">
            <?php echo csrfField(); ?>

            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('insp_date', 'Inspection Date'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="inspection_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('inspector_name', 'Inspector Name'); ?></label>
                    <input type="text" class="form-control" name="inspector_name" value="<?php echo htmlspecialchars(getUserData('name')); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold"><?php _e('insp_overall_notes', 'Overall Notes'); ?></label>
                    <input type="text" class="form-control" name="overall_notes" placeholder="<?php _e('insp_notes_placeholder', 'General notes...'); ?>">
                </div>
            </div>

            <div class="alert alert-info mt-3">
                <i class="bi bi-info-circle me-1"></i>
                <?php if ($moveIn): ?>
                <?php _e('insp_moveout_prefill_info', 'Items from the move-in inspection will be pre-populated. You will update conditions for each item on the next screen.'); ?>
                <?php else: ?>
                <?php _e('insp_moveout_default_info', 'No move-in inspection found. The default template will be used to populate items.'); ?>
                <?php endif; ?>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <a href="<?php echo BASE_URL; ?>modules/inspections/" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> <?php _e('back'); ?>
                </a>
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-box-arrow-right me-1"></i> <?php _e('insp_create_moveout', 'Create Move-out Inspection'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
