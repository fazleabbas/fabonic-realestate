<?php
/**
 * FIS Real Estate — Inspections API
 * Handles CRUD for inspections, inspection items, templates,
 * move-in/move-out workflows, report data, and deposit deduction linking.
 * Used by AJAX calls from the inspections module pages.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Must be logged in
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$action = $_REQUEST['action'] ?? '';

// --- Property-based access scoping helpers (local to this API) ---
// Resolve the unit_id behind an inspection / inspection item and guard it.
if (!function_exists('apiInspUnitId')) {
    function apiInspUnitId($conn, $inspectionId) {
        $s = $conn->prepare("SELECT unit_id FROM inspections WHERE id = ?");
        $s->execute([(int)$inspectionId]);
        return (int)$s->fetchColumn();
    }
    function apiInspItemUnitId($conn, $itemId) {
        $s = $conn->prepare("SELECT i.unit_id FROM inspection_items ii INNER JOIN inspections i ON ii.inspection_id = i.id WHERE ii.id = ?");
        $s->execute([(int)$itemId]);
        return (int)$s->fetchColumn();
    }
    function apiScopeDeny() {
        jsonResponse(['success' => false, 'message' => "You don't have access to this record"], 403);
    }
}

switch ($action) {

    // ============================
    // LIST INSPECTIONS (with filters, search, pagination)
    // ============================
    case 'list':
        if (!hasPermission('inspections', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $search = trim($_GET['search'] ?? '');
        $type = trim($_GET['type'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $propertyId = (int)($_GET['property_id'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int)($_GET['per_page'] ?? RECORDS_PER_PAGE)));

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(CONCAT(t.first_name, ' ', t.last_name) LIKE :search OR u.unit_number LIKE :search2 OR p.name LIKE :search3 OR i.inspector_name LIKE :search4)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
            $params['search4'] = "%{$search}%";
        }
        if ($type !== '') {
            $where[] = "i.inspection_type = :type";
            $params['type'] = $type;
        }
        if ($status !== '') {
            $where[] = "i.status = :status";
            $params['status'] = $status;
        }
        if ($propertyId > 0) {
            $where[] = "u.property_id = :property_id";
            $params['property_id'] = $propertyId;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Property-based access scoping (units joined as alias u)
        $scopeSql = propertyScopeSql('u.property_id');
        if ($scopeSql !== '') {
            $whereSql = $whereSql === '' ? 'WHERE 1=1' . $scopeSql : $whereSql . $scopeSql;
        }

        $countStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM inspections i
            INNER JOIN units u ON i.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            LEFT JOIN tenants t ON i.tenant_id = t.id
            {$whereSql}
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;

        $sql = "SELECT i.*, u.unit_number, p.name AS property_name, p.id AS property_id,
                CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                (SELECT COUNT(*) FROM inspection_items ii WHERE ii.inspection_id = i.id) AS item_count
                FROM inspections i
                INNER JOIN units u ON i.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                LEFT JOIN tenants t ON i.tenant_id = t.id
                {$whereSql}
                ORDER BY i.inspection_date DESC, i.created_at DESC
                LIMIT :offset, :limit";

        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":{$k}", $v);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse([
            'success' => true,
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage
        ]);
        break;

    // ============================
    // GET SINGLE INSPECTION
    // ============================
    case 'get':
        if (!hasPermission('inspections', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'ID required'], 400);
        }

        $stmt = $conn->prepare("
            SELECT i.*, u.unit_number, u.unit_label, u.id AS unit_id_val,
                   p.name AS property_name, p.id AS property_id_val,
                   CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                   t.phone AS tenant_phone, t.id AS tenant_id_val,
                   l.start_date AS lease_start, l.end_date AS lease_end,
                   l.rent_amount AS lease_rent, l.currency AS lease_currency,
                   l.status AS lease_status
            FROM inspections i
            INNER JOIN units u ON i.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            LEFT JOIN tenants t ON i.tenant_id = t.id
            LEFT JOIN leases l ON i.lease_id = l.id
            WHERE i.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $inspection = $stmt->fetch();

        if (!$inspection) {
            jsonResponse(['success' => false, 'message' => 'Inspection not found'], 404);
        }
        if (!canAccessUnit($inspection['unit_id'])) apiScopeDeny();

        // Get items
        $itemStmt = $conn->prepare("SELECT * FROM inspection_items WHERE inspection_id = :iid ORDER BY room, item_name");
        $itemStmt->execute(['iid' => $id]);
        $inspection['items'] = $itemStmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $inspection]);
        break;

    // ============================
    // CREATE INSPECTION
    // ============================
    case 'create':
        if (!hasPermission('inspections', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $unitId = (int)($_POST['unit_id'] ?? 0);
        $leaseId = (int)($_POST['lease_id'] ?? 0) ?: null;
        $tenantId = (int)($_POST['tenant_id'] ?? 0) ?: null;
        $inspectionType = trim($_POST['inspection_type'] ?? '');
        $inspectionDate = trim($_POST['inspection_date'] ?? '');
        $inspectorName = trim($_POST['inspector_name'] ?? '');
        $overallNotes = trim($_POST['overall_notes'] ?? '');
        $templateId = (int)($_POST['template_id'] ?? 0);
        $statusVal = trim($_POST['status'] ?? 'in_progress');

        $validTypes = ['move_in', 'move_out', 'routine'];
        $validStatuses = ['scheduled', 'in_progress', 'completed'];

        $errors = [];
        if (!$unitId) $errors[] = 'Unit is required';
        if (!in_array($inspectionType, $validTypes)) $errors[] = 'Invalid inspection type';
        if (!$inspectionDate) $errors[] = 'Inspection date is required';
        if (!in_array($statusVal, $validStatuses)) $statusVal = 'in_progress';

        // Verify unit exists
        if ($unitId) {
            $uCheck = $conn->prepare("SELECT id FROM units WHERE id = :id");
            $uCheck->execute(['id' => $unitId]);
            if (!$uCheck->fetch()) $errors[] = 'Unit not found';
        }

        // Property-based access scoping: target unit must be in scope
        if ($unitId && !canAccessUnit($unitId)) apiScopeDeny();

        if (!empty($errors)) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors)], 400);
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO inspections (unit_id, lease_id, tenant_id, inspection_type,
                    inspection_date, inspector_name, status, overall_notes)
                VALUES (:unit_id, :lease_id, :tenant_id, :inspection_type,
                    :inspection_date, :inspector_name, :status, :overall_notes)
            ");
            $stmt->execute([
                'unit_id' => $unitId,
                'lease_id' => $leaseId,
                'tenant_id' => $tenantId,
                'inspection_type' => $inspectionType,
                'inspection_date' => date('Y-m-d', strtotime($inspectionDate)),
                'inspector_name' => $inspectorName ?: null,
                'status' => $statusVal,
                'overall_notes' => $overallNotes ?: null,
            ]);

            $inspectionId = (int) $conn->lastInsertId();

            // If template selected, pre-populate items
            if ($templateId) {
                $tplStmt = $conn->prepare("SELECT rooms_json FROM inspection_templates WHERE id = :id");
                $tplStmt->execute(['id' => $templateId]);
                $tplData = $tplStmt->fetch();

                if ($tplData && $tplData['rooms_json']) {
                    $rooms = json_decode($tplData['rooms_json'], true);
                    if (is_array($rooms)) {
                        $itemInsert = $conn->prepare("
                            INSERT INTO inspection_items (inspection_id, room, item_name, `condition`, notes)
                            VALUES (:iid, :room, :item, 'good', '')
                        ");
                        foreach ($rooms as $roomData) {
                            $roomName = $roomData['room'] ?? '';
                            $items = $roomData['items'] ?? [];
                            foreach ($items as $itemName) {
                                $itemInsert->execute([
                                    'iid' => $inspectionId,
                                    'room' => $roomName,
                                    'item' => $itemName,
                                ]);
                            }
                        }
                    }
                }
            }

            // Also add any custom items sent with the form
            $customRooms = $_POST['custom_rooms'] ?? [];
            $customItems = $_POST['custom_items'] ?? [];
            if (is_array($customRooms)) {
                $itemInsert = $conn->prepare("
                    INSERT INTO inspection_items (inspection_id, room, item_name, `condition`, notes)
                    VALUES (:iid, :room, :item, 'good', '')
                ");
                foreach ($customRooms as $idx => $room) {
                    $item = $customItems[$idx] ?? '';
                    if ($room && $item) {
                        $itemInsert->execute([
                            'iid' => $inspectionId,
                            'room' => trim($room),
                            'item' => trim($item),
                        ]);
                    }
                }
            }

            $conn->commit();

            logActivity('created', 'inspection', $inspectionId, [
                'type' => $inspectionType,
                'unit_id' => $unitId,
                'lease_id' => $leaseId,
            ]);

            jsonResponse(['success' => true, 'message' => 'Inspection created', 'id' => $inspectionId]);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // UPDATE INSPECTION STATUS / NOTES
    // ============================
    case 'update':
        if (!hasPermission('inspections', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'ID required'], 400);
        }
        if (!canAccessUnit(apiInspUnitId($conn, $id))) apiScopeDeny();

        $inspectorName = trim($_POST['inspector_name'] ?? '');
        $overallNotes = trim($_POST['overall_notes'] ?? '');
        $statusVal = trim($_POST['status'] ?? '');
        $inspectionDate = trim($_POST['inspection_date'] ?? '');

        $validStatuses = ['scheduled', 'in_progress', 'completed'];

        $sets = [];
        $params = ['id' => $id];

        if ($inspectorName !== '') {
            $sets[] = "inspector_name = :inspector_name";
            $params['inspector_name'] = $inspectorName;
        }
        if ($overallNotes !== '') {
            $sets[] = "overall_notes = :overall_notes";
            $params['overall_notes'] = $overallNotes;
        }
        if (in_array($statusVal, $validStatuses)) {
            $sets[] = "status = :status";
            $params['status'] = $statusVal;
        }
        if ($inspectionDate !== '') {
            $sets[] = "inspection_date = :inspection_date";
            $params['inspection_date'] = date('Y-m-d', strtotime($inspectionDate));
        }

        if (empty($sets)) {
            jsonResponse(['success' => false, 'message' => 'Nothing to update'], 400);
        }

        $sql = "UPDATE inspections SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        logActivity('updated', 'inspection', $id, $params);

        jsonResponse(['success' => true, 'message' => 'Inspection updated']);
        break;

    // ============================
    // DELETE INSPECTION
    // ============================
    case 'delete':
        if (!hasPermission('inspections', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'ID required'], 400);
        }
        if (!canAccessUnit(apiInspUnitId($conn, $id))) apiScopeDeny();

        // Delete photos first
        $photoStmt = $conn->prepare("SELECT photo_path FROM inspection_items WHERE inspection_id = :iid AND photo_path IS NOT NULL AND photo_path != ''");
        $photoStmt->execute(['iid' => $id]);
        while ($photo = $photoStmt->fetch()) {
            $fullPath = UPLOAD_PATH . $photo['photo_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        $stmt = $conn->prepare("DELETE FROM inspections WHERE id = :id");
        $stmt->execute(['id' => $id]);

        logActivity('deleted', 'inspection', $id);

        jsonResponse(['success' => true, 'message' => 'Inspection deleted']);
        break;

    // ============================
    // UPDATE INSPECTION ITEM (condition, notes)
    // ============================
    case 'update_item':
        if (!hasPermission('inspections', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $itemId = (int)($_POST['item_id'] ?? 0);
        if (!$itemId) {
            jsonResponse(['success' => false, 'message' => 'Item ID required'], 400);
        }
        if (!canAccessUnit(apiInspItemUnitId($conn, $itemId))) apiScopeDeny();

        $condition = trim($_POST['condition'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $validConditions = ['excellent', 'good', 'fair', 'poor', 'damaged', 'missing'];

        $sets = [];
        $params = ['id' => $itemId];

        if (in_array($condition, $validConditions)) {
            $sets[] = "`condition` = :cond";
            $params['cond'] = $condition;
        }
        $sets[] = "notes = :notes";
        $params['notes'] = $notes;

        $sql = "UPDATE inspection_items SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        jsonResponse(['success' => true, 'message' => 'Item updated']);
        break;

    // ============================
    // BULK UPDATE ITEMS (save entire inspection form)
    // ============================
    case 'save_items':
        if (!hasPermission('inspections', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $inspectionId = (int)($_POST['inspection_id'] ?? 0);
        if (!$inspectionId) {
            jsonResponse(['success' => false, 'message' => 'Inspection ID required'], 400);
        }
        if (!canAccessUnit(apiInspUnitId($conn, $inspectionId))) apiScopeDeny();

        $items = $_POST['items'] ?? [];
        $overallNotes = trim($_POST['overall_notes'] ?? '');
        $statusVal = trim($_POST['status'] ?? '');

        $validConditions = ['excellent', 'good', 'fair', 'poor', 'damaged', 'missing'];

        try {
            $conn->beginTransaction();

            // Update each item
            if (is_array($items)) {
                $updateStmt = $conn->prepare("
                    UPDATE inspection_items SET `condition` = :cond, notes = :notes WHERE id = :id AND inspection_id = :iid
                ");
                foreach ($items as $itemId => $itemData) {
                    $cond = $itemData['condition'] ?? 'good';
                    if (!in_array($cond, $validConditions)) $cond = 'good';
                    $updateStmt->execute([
                        'cond' => $cond,
                        'notes' => trim($itemData['notes'] ?? ''),
                        'id' => (int)$itemId,
                        'iid' => $inspectionId,
                    ]);
                }
            }

            // Update inspection itself
            $inspUpdate = ['id' => $inspectionId];
            $inspSets = [];
            if ($overallNotes !== '') {
                $inspSets[] = "overall_notes = :notes";
                $inspUpdate['notes'] = $overallNotes;
            }
            if (in_array($statusVal, ['scheduled', 'in_progress', 'completed'])) {
                $inspSets[] = "status = :status";
                $inspUpdate['status'] = $statusVal;
            }
            if ($inspSets) {
                $conn->prepare("UPDATE inspections SET " . implode(', ', $inspSets) . " WHERE id = :id")->execute($inspUpdate);
            }

            $conn->commit();

            logActivity('updated', 'inspection', $inspectionId, ['action' => 'save_items']);

            jsonResponse(['success' => true, 'message' => 'Inspection saved']);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // UPLOAD PHOTO FOR INSPECTION ITEM
    // ============================
    case 'upload_photo':
        if (!hasPermission('inspections', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $itemId = (int)($_POST['item_id'] ?? 0);
        if (!$itemId) {
            jsonResponse(['success' => false, 'message' => 'Item ID required'], 400);
        }

        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['success' => false, 'message' => 'No file uploaded'], 400);
        }

        // Get inspection ID from item
        $itemCheck = $conn->prepare("SELECT ii.inspection_id, ii.photo_path FROM inspection_items ii WHERE ii.id = :id");
        $itemCheck->execute(['id' => $itemId]);
        $itemData = $itemCheck->fetch();
        if (!$itemData) {
            jsonResponse(['success' => false, 'message' => 'Item not found'], 404);
        }
        if (!canAccessUnit(apiInspUnitId($conn, $itemData['inspection_id']))) apiScopeDeny();

        // Delete old photo if exists
        if ($itemData['photo_path']) {
            $oldPath = UPLOAD_PATH . $itemData['photo_path'];
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        $result = uploadFile($_FILES['photo'], 'inspections/' . $itemData['inspection_id'], ALLOWED_IMAGE_TYPES);

        if (!$result['success']) {
            jsonResponse(['success' => false, 'message' => $result['message']], 400);
        }

        $stmt = $conn->prepare("UPDATE inspection_items SET photo_path = :path WHERE id = :id");
        $stmt->execute(['path' => $result['filepath'], 'id' => $itemId]);

        jsonResponse(['success' => true, 'message' => 'Photo uploaded', 'photo_path' => $result['filepath']]);
        break;

    // ============================
    // DELETE PHOTO FROM INSPECTION ITEM
    // ============================
    case 'delete_photo':
        if (!hasPermission('inspections', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $itemId = (int)($_POST['item_id'] ?? 0);
        if (!$itemId) {
            jsonResponse(['success' => false, 'message' => 'Item ID required'], 400);
        }
        if (!canAccessUnit(apiInspItemUnitId($conn, $itemId))) apiScopeDeny();

        $itemCheck = $conn->prepare("SELECT photo_path FROM inspection_items WHERE id = :id");
        $itemCheck->execute(['id' => $itemId]);
        $itemData = $itemCheck->fetch();

        if ($itemData && $itemData['photo_path']) {
            $fullPath = UPLOAD_PATH . $itemData['photo_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
            $conn->prepare("UPDATE inspection_items SET photo_path = NULL WHERE id = :id")->execute(['id' => $itemId]);
        }

        jsonResponse(['success' => true, 'message' => 'Photo deleted']);
        break;

    // ============================
    // ADD ITEM TO EXISTING INSPECTION
    // ============================
    case 'add_item':
        if (!hasPermission('inspections', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $inspectionId = (int)($_POST['inspection_id'] ?? 0);
        $room = trim($_POST['room'] ?? '');
        $itemName = trim($_POST['item_name'] ?? '');

        if (!$inspectionId || !$room || !$itemName) {
            jsonResponse(['success' => false, 'message' => 'Inspection ID, room, and item name are required'], 400);
        }
        if (!canAccessUnit(apiInspUnitId($conn, $inspectionId))) apiScopeDeny();

        $stmt = $conn->prepare("
            INSERT INTO inspection_items (inspection_id, room, item_name, `condition`, notes)
            VALUES (:iid, :room, :item, 'good', '')
        ");
        $stmt->execute(['iid' => $inspectionId, 'room' => $room, 'item' => $itemName]);

        jsonResponse(['success' => true, 'message' => 'Item added', 'id' => (int)$conn->lastInsertId()]);
        break;

    // ============================
    // DELETE ITEM FROM INSPECTION
    // ============================
    case 'delete_item':
        if (!hasPermission('inspections', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $itemId = (int)($_POST['item_id'] ?? 0);
        if (!$itemId) {
            jsonResponse(['success' => false, 'message' => 'Item ID required'], 400);
        }
        if (!canAccessUnit(apiInspItemUnitId($conn, $itemId))) apiScopeDeny();

        // Delete photo if exists
        $itemCheck = $conn->prepare("SELECT photo_path FROM inspection_items WHERE id = :id");
        $itemCheck->execute(['id' => $itemId]);
        $itemData = $itemCheck->fetch();
        if ($itemData && $itemData['photo_path']) {
            $fullPath = UPLOAD_PATH . $itemData['photo_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        $stmt = $conn->prepare("DELETE FROM inspection_items WHERE id = :id");
        $stmt->execute(['id' => $itemId]);

        jsonResponse(['success' => true, 'message' => 'Item deleted']);
        break;

    // ============================
    // TEMPLATE CRUD
    // ============================
    case 'templates':
        if (!hasPermission('inspections', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        $stmt = $conn->query("SELECT * FROM inspection_templates ORDER BY is_default DESC, name");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'get_template':
        if (!hasPermission('inspections', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM inspection_templates WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $tpl = $stmt->fetch();
        if (!$tpl) {
            jsonResponse(['success' => false, 'message' => 'Template not found'], 404);
        }
        jsonResponse(['success' => true, 'data' => $tpl]);
        break;

    case 'save_template':
        if (!hasPermission('inspections', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $roomsJson = trim($_POST['rooms_json'] ?? '');
        $isDefault = (int)($_POST['is_default'] ?? 0);

        if (!$name) {
            jsonResponse(['success' => false, 'message' => 'Template name is required'], 400);
        }

        // Validate JSON
        $decoded = json_decode($roomsJson, true);
        if (!is_array($decoded)) {
            jsonResponse(['success' => false, 'message' => 'Invalid rooms JSON'], 400);
        }

        try {
            $conn->beginTransaction();

            // If setting as default, unset others
            if ($isDefault) {
                $conn->exec("UPDATE inspection_templates SET is_default = 0");
            }

            if ($id) {
                // Update
                $stmt = $conn->prepare("UPDATE inspection_templates SET name = :name, rooms_json = :rooms, is_default = :def WHERE id = :id");
                $stmt->execute(['name' => $name, 'rooms' => $roomsJson, 'def' => $isDefault, 'id' => $id]);
            } else {
                // Insert
                $stmt = $conn->prepare("INSERT INTO inspection_templates (name, rooms_json, is_default) VALUES (:name, :rooms, :def)");
                $stmt->execute(['name' => $name, 'rooms' => $roomsJson, 'def' => $isDefault]);
                $id = (int) $conn->lastInsertId();
            }

            $conn->commit();

            logActivity($id ? 'updated' : 'created', 'inspection_template', $id, ['name' => $name]);

            jsonResponse(['success' => true, 'message' => 'Template saved', 'id' => $id]);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
        break;

    case 'delete_template':
        if (!hasPermission('inspections', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'ID required'], 400);
        }

        $stmt = $conn->prepare("DELETE FROM inspection_templates WHERE id = :id");
        $stmt->execute(['id' => $id]);

        logActivity('deleted', 'inspection_template', $id);

        jsonResponse(['success' => true, 'message' => 'Template deleted']);
        break;

    // ============================
    // GET MOVE-IN DATA (for move-out pre-population) — 11.3
    // ============================
    case 'get_movein_data':
        if (!hasPermission('inspections', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $leaseId = (int)($_GET['lease_id'] ?? 0);
        $unitId = (int)($_GET['unit_id'] ?? 0);

        $where = "i.inspection_type = 'move_in' AND i.status = 'completed'";
        $params = [];

        if ($leaseId) {
            $where .= " AND i.lease_id = :lid";
            $params['lid'] = $leaseId;
        } elseif ($unitId) {
            $where .= " AND i.unit_id = :uid";
            $params['uid'] = $unitId;
        } else {
            jsonResponse(['success' => false, 'message' => 'lease_id or unit_id required'], 400);
        }

        $stmt = $conn->prepare("
            SELECT i.* FROM inspections i WHERE {$where} ORDER BY i.inspection_date DESC LIMIT 1
        ");
        $stmt->execute($params);
        $moveIn = $stmt->fetch();

        if (!$moveIn) {
            jsonResponse(['success' => false, 'message' => 'No move-in inspection found']);
        }
        if (!canAccessUnit($moveIn['unit_id'])) apiScopeDeny();

        $itemStmt = $conn->prepare("SELECT * FROM inspection_items WHERE inspection_id = :iid ORDER BY room, item_name");
        $itemStmt->execute(['iid' => $moveIn['id']]);
        $moveIn['items'] = $itemStmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $moveIn]);
        break;

    // ============================
    // REPORT DATA (side-by-side comparison) — 11.4
    // ============================
    case 'report_data':
        if (!hasPermission('inspections', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'ID required'], 400);
        }

        // Get the inspection
        $stmt = $conn->prepare("
            SELECT i.*, u.unit_number, u.unit_label,
                   p.name AS property_name, p.address AS property_address,
                   CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                   t.phone AS tenant_phone, t.email AS tenant_email,
                   l.start_date AS lease_start, l.end_date AS lease_end
            FROM inspections i
            INNER JOIN units u ON i.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            LEFT JOIN tenants t ON i.tenant_id = t.id
            LEFT JOIN leases l ON i.lease_id = l.id
            WHERE i.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $inspection = $stmt->fetch();

        if (!$inspection) {
            jsonResponse(['success' => false, 'message' => 'Not found'], 404);
        }
        if (!canAccessUnit($inspection['unit_id'])) apiScopeDeny();

        $itemStmt = $conn->prepare("SELECT * FROM inspection_items WHERE inspection_id = :iid ORDER BY room, item_name");
        $itemStmt->execute(['iid' => $id]);
        $inspection['items'] = $itemStmt->fetchAll();

        // If move-out, also get the move-in for comparison
        $moveInData = null;
        if ($inspection['inspection_type'] === 'move_out' && $inspection['lease_id']) {
            $miStmt = $conn->prepare("
                SELECT i.* FROM inspections i
                WHERE i.lease_id = :lid AND i.inspection_type = 'move_in' AND i.status = 'completed'
                ORDER BY i.inspection_date DESC LIMIT 1
            ");
            $miStmt->execute(['lid' => $inspection['lease_id']]);
            $moveInData = $miStmt->fetch();

            if ($moveInData) {
                $miItemStmt = $conn->prepare("SELECT * FROM inspection_items WHERE inspection_id = :iid ORDER BY room, item_name");
                $miItemStmt->execute(['iid' => $moveInData['id']]);
                $moveInData['items'] = $miItemStmt->fetchAll();
            }
        }

        // Company info
        $companyName = getSetting('company_name', APP_NAME);
        $companyPhone = getSetting('company_phone', '');
        $companyEmail = getSetting('company_email', '');
        $companyAddress = getSetting('company_address', '');

        jsonResponse([
            'success' => true,
            'inspection' => $inspection,
            'move_in' => $moveInData,
            'company' => [
                'name' => $companyName,
                'phone' => $companyPhone,
                'email' => $companyEmail,
                'address' => $companyAddress,
            ]
        ]);
        break;

    // ============================
    // SUGGEST DEDUCTIONS FROM DAMAGED ITEMS — 11.5
    // ============================
    case 'suggest_deductions':
        if (!hasPermission('inspections', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $inspectionId = (int)($_GET['inspection_id'] ?? 0);
        if (!$inspectionId) {
            jsonResponse(['success' => false, 'message' => 'Inspection ID required'], 400);
        }

        // Get the inspection (must be move_out)
        $inspStmt = $conn->prepare("SELECT * FROM inspections WHERE id = :id");
        $inspStmt->execute(['id' => $inspectionId]);
        $inspection = $inspStmt->fetch();

        if (!$inspection) {
            jsonResponse(['success' => false, 'message' => 'Not found'], 404);
        }
        if (!canAccessUnit($inspection['unit_id'])) apiScopeDeny();

        // Get damaged/missing items from move-out
        $damagedStmt = $conn->prepare("
            SELECT ii.* FROM inspection_items ii
            WHERE ii.inspection_id = :iid AND ii.`condition` IN ('poor', 'damaged', 'missing')
            ORDER BY ii.room, ii.item_name
        ");
        $damagedStmt->execute(['iid' => $inspectionId]);
        $damagedItems = $damagedStmt->fetchAll();

        // If this is a move-out, compare with move-in to find deteriorated items
        $deteriorated = [];
        if ($inspection['inspection_type'] === 'move_out' && $inspection['lease_id']) {
            // Get move-in inspection
            $miStmt = $conn->prepare("
                SELECT i.id FROM inspections i
                WHERE i.lease_id = :lid AND i.inspection_type = 'move_in' AND i.status = 'completed'
                ORDER BY i.inspection_date DESC LIMIT 1
            ");
            $miStmt->execute(['lid' => $inspection['lease_id']]);
            $moveInInsp = $miStmt->fetch();

            if ($moveInInsp) {
                $miItemStmt = $conn->prepare("SELECT * FROM inspection_items WHERE inspection_id = :iid");
                $miItemStmt->execute(['iid' => $moveInInsp['id']]);
                $miItems = $miItemStmt->fetchAll();

                // Index move-in items by room+item
                $miIndex = [];
                foreach ($miItems as $mi) {
                    $key = $mi['room'] . '|' . $mi['item_name'];
                    $miIndex[$key] = $mi;
                }

                $condOrder = ['excellent' => 1, 'good' => 2, 'fair' => 3, 'poor' => 4, 'damaged' => 5, 'missing' => 6];

                // For each move-out item, check if condition is worse
                $moItemStmt = $conn->prepare("SELECT * FROM inspection_items WHERE inspection_id = :iid ORDER BY room, item_name");
                $moItemStmt->execute(['iid' => $inspectionId]);
                $moItems = $moItemStmt->fetchAll();

                foreach ($moItems as $mo) {
                    $key = $mo['room'] . '|' . $mo['item_name'];
                    if (isset($miIndex[$key])) {
                        $miCond = $condOrder[$miIndex[$key]['condition']] ?? 2;
                        $moCond = $condOrder[$mo['condition']] ?? 2;
                        if ($moCond > $miCond) {
                            $deteriorated[] = [
                                'item_id' => $mo['id'],
                                'room' => $mo['room'],
                                'item_name' => $mo['item_name'],
                                'move_in_condition' => $miIndex[$key]['condition'],
                                'move_out_condition' => $mo['condition'],
                                'notes' => $mo['notes'],
                            ];
                        }
                    }
                }
            }
        }

        // Get the deposit for this lease (if any)
        $deposit = null;
        if ($inspection['lease_id']) {
            $depStmt = $conn->prepare("
                SELECT d.*,
                    (SELECT COALESCE(SUM(dd.amount),0) FROM deposit_deductions dd WHERE dd.deposit_id = d.id) AS total_deductions
                FROM deposits d WHERE d.lease_id = :lid AND d.status IN ('held','partially_refunded')
                LIMIT 1
            ");
            $depStmt->execute(['lid' => $inspection['lease_id']]);
            $deposit = $depStmt->fetch();
        }

        jsonResponse([
            'success' => true,
            'damaged_items' => $damagedItems,
            'deteriorated_items' => $deteriorated,
            'deposit' => $deposit,
        ]);
        break;

    // ============================
    // CREATE DEDUCTIONS FROM INSPECTION — 11.5
    // ============================
    case 'create_deductions':
        if (!hasPermission('deposits', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $depositId = (int)($_POST['deposit_id'] ?? 0);
        $deductions = $_POST['deductions'] ?? [];

        if (!$depositId || !is_array($deductions) || empty($deductions)) {
            jsonResponse(['success' => false, 'message' => 'Deposit ID and deductions are required'], 400);
        }

        // Verify deposit
        $depCheck = $conn->prepare("
            SELECT d.amount,
                (SELECT COALESCE(SUM(dd.amount),0) FROM deposit_deductions dd WHERE dd.deposit_id = d.id) AS total_deductions
            FROM deposits d WHERE d.id = :id AND d.status IN ('held','partially_refunded')
        ");
        $depCheck->execute(['id' => $depositId]);
        $depData = $depCheck->fetch();

        if (!$depData) {
            jsonResponse(['success' => false, 'message' => 'Deposit not found or not editable'], 404);
        }

        // Property-based access scoping: guard by the unit behind this deposit's lease
        $depUnitStmt = $conn->prepare("SELECT l.unit_id FROM deposits d INNER JOIN leases l ON d.lease_id = l.id WHERE d.id = ?");
        $depUnitStmt->execute([$depositId]);
        $depUnitId = (int)$depUnitStmt->fetchColumn();
        if ($depUnitId && !canAccessUnit($depUnitId)) apiScopeDeny();

        $available = (float)$depData['amount'] - (float)$depData['total_deductions'];
        $totalNew = 0;
        foreach ($deductions as $ded) {
            $totalNew += (float)($ded['amount'] ?? 0);
        }
        if ($totalNew > $available + 0.01) {
            jsonResponse(['success' => false, 'message' => 'Deductions exceed available deposit balance'], 400);
        }

        try {
            $conn->beginTransaction();

            $insertStmt = $conn->prepare("
                INSERT INTO deposit_deductions (deposit_id, description, amount, inspection_item_id)
                VALUES (:did, :desc, :amount, :item_id)
            ");

            foreach ($deductions as $ded) {
                $desc = trim($ded['description'] ?? '');
                $amount = (float)($ded['amount'] ?? 0);
                $itemId = (int)($ded['inspection_item_id'] ?? 0) ?: null;

                if ($desc && $amount > 0) {
                    $insertStmt->execute([
                        'did' => $depositId,
                        'desc' => $desc,
                        'amount' => $amount,
                        'item_id' => $itemId,
                    ]);
                }
            }

            $conn->commit();

            logActivity('created', 'deposit_deduction', $depositId, [
                'deposit_id' => $depositId,
                'source' => 'inspection',
                'count' => count($deductions),
            ]);

            jsonResponse(['success' => true, 'message' => 'Deductions created from inspection']);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // PROPERTIES DROPDOWN
    // ============================
    case 'properties':
        $stmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // LEASES FOR INSPECTION (active + recently ended)
    // ============================
    case 'leases_for_inspection':
        $stmt = $conn->query("
            SELECT l.id, l.start_date, l.end_date, l.status,
                   u.unit_number, u.id AS unit_id, p.name AS property_name, p.id AS property_id,
                   CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.id AS tenant_id
            FROM leases l
            INNER JOIN units u ON l.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            INNER JOIN tenants t ON l.tenant_id = t.id
            WHERE l.status IN ('active', 'expired', 'terminated')" . propertyScopeSql('p.id') . "
            ORDER BY l.status = 'active' DESC, t.first_name, t.last_name
        ");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // ACTIVITY LOG
    // ============================
    case 'activity':
        if (!hasPermission('inspections', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'ID required'], 400);
        }
        if (!canAccessUnit(apiInspUnitId($conn, $id))) apiScopeDeny();

        $stmt = $conn->prepare("
            SELECT al.*, u.full_name AS user_name
            FROM activity_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.entity_type = 'inspection' AND al.entity_id = :id
            ORDER BY al.created_at DESC
            LIMIT 15
        ");
        $stmt->execute(['id' => $id]);

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}
