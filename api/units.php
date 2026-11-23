<?php
/**
 * FIS Real Estate — Units API
 * Handles CRUD operations for units.
 * Used by AJAX calls from the units module pages.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';

// Must be logged in
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // LIST UNITS (with filters, search, pagination)
    // ============================
    case 'list':
        if (!hasPermission('units', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $search = trim($_GET['search'] ?? '');
        $propertyId = (int)($_GET['property_id'] ?? 0);
        $unitType = trim($_GET['unit_type'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $rentMin = trim($_GET['rent_min'] ?? '');
        $rentMax = trim($_GET['rent_max'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int)($_GET['per_page'] ?? RECORDS_PER_PAGE)));

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(u.unit_number LIKE :search OR u.unit_label LIKE :search2 OR u.description LIKE :search3)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
        }
        if ($propertyId > 0) {
            $where[] = "u.property_id = :property_id";
            $params['property_id'] = $propertyId;
        }
        if ($unitType !== '') {
            $where[] = "u.unit_type = :unit_type";
            $params['unit_type'] = $unitType;
        }
        if ($status !== '') {
            $where[] = "u.status = :status";
            $params['status'] = $status;
        }
        if ($rentMin !== '' && is_numeric($rentMin)) {
            $where[] = "u.rent_amount >= :rent_min";
            $params['rent_min'] = (float) $rentMin;
        }
        if ($rentMax !== '' && is_numeric($rentMax)) {
            $where[] = "u.rent_amount <= :rent_max";
            $params['rent_max'] = (float) $rentMax;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Apply property-based access scoping (both queries carry the `u` alias)
        $scope = propertyScopeSql('u.property_id');
        $whereSql = ($whereSql === '' && $scope !== '') ? 'WHERE 1=1' . $scope : $whereSql . $scope;

        // Count total
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM units u {$whereSql}");
        $countStmt->execute($params);
        $totalRecords = (int) $countStmt->fetchColumn();

        $pagination = paginate($totalRecords, $page, $perPage);

        // Fetch records with property name
        $sql = "SELECT u.*, p.name AS property_name
                FROM units u
                INNER JOIN properties p ON u.property_id = p.id
                {$whereSql}
                ORDER BY u.created_at DESC
                LIMIT :offset, :limit";

        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":{$k}", $v);
        }
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $units = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'data' => $units,
            'pagination' => $pagination
        ]);
        break;

    // ============================
    // GET SINGLE UNIT
    // ============================
    case 'get':
        if (!hasPermission('units', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Unit ID required'], 400);
        }

        if (!canAccessUnit($id)) {
            jsonResponse(['success' => false, 'message' => "You don't have access to this record"], 403);
        }

        $stmt = $conn->prepare("
            SELECT u.*, p.name AS property_name
            FROM units u
            INNER JOIN properties p ON u.property_id = p.id
            WHERE u.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $unit = $stmt->fetch();

        if (!$unit) {
            jsonResponse(['success' => false, 'message' => 'Unit not found'], 404);
        }

        // Get photos
        $photoStmt = $conn->prepare("SELECT * FROM unit_photos WHERE unit_id = :id ORDER BY sort_order ASC, id ASC");
        $photoStmt->execute(['id' => $id]);
        $unit['photos'] = $photoStmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $unit]);
        break;

    // ============================
    // CREATE UNIT
    // ============================
    case 'create':
        if (!hasPermission('units', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        // Enforce subscription unit quota
        if (!canAddUnits(1)) {
            $usage = getUnitUsage();
            jsonResponse([
                'success' => false,
                'code'    => 'unit_limit_reached',
                'message' => sprintf(
                    __('unit_limit_reached', 'You have reached your subscription unit limit (%1$d of %2$d units used). Upgrade your subscription to add more units.'),
                    $usage['used'], $usage['limit']
                ),
            ], 403);
        }

        $propertyId = (int)($_POST['property_id'] ?? 0);
        $unitNumber = trim($_POST['unit_number'] ?? '');
        $unitLabel = trim($_POST['unit_label'] ?? '');
        $unitType = trim($_POST['unit_type'] ?? 'apartment');
        $floor = trim($_POST['floor'] ?? '');
        $sizeSqm = ($_POST['size_sqm'] ?? '') !== '' ? (float)$_POST['size_sqm'] : null;
        $bedrooms = ($_POST['bedrooms'] ?? '') !== '' ? (int)$_POST['bedrooms'] : null;
        $bathrooms = ($_POST['bathrooms'] ?? '') !== '' ? (int)$_POST['bathrooms'] : null;
        $rentAmount = max(0, (float)($_POST['rent_amount'] ?? 0));
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $depositAmount = max(0, (float)($_POST['deposit_amount'] ?? 0));
        $status = trim($_POST['status'] ?? 'vacant');
        $description = trim($_POST['description'] ?? '');

        $validTypes = ['apartment','room','shop','office','warehouse','plot','house','flat','hostel','godown'];
        $validStatuses = ['vacant','occupied','maintenance','reserved'];

        // Validate
        $errors = [];
        if (!$propertyId) $errors[] = 'Property is required';
        if ($unitNumber === '') $errors[] = 'Unit number is required';
        if (!in_array($unitType, $validTypes)) $errors[] = 'Invalid unit type';
        if (!in_array($status, $validStatuses)) $errors[] = 'Invalid status';

        // Verify property exists
        if ($propertyId) {
            $propCheck = $conn->prepare("SELECT id FROM properties WHERE id = :id");
            $propCheck->execute(['id' => $propertyId]);
            if (!$propCheck->fetch()) {
                $errors[] = 'Property not found';
            }
        }

        // Property scoping: cannot create a unit under a property outside your scope
        if ($propertyId && !canAccessProperty($propertyId)) {
            jsonResponse(['success' => false, 'message' => __('no_access_record', "You don't have access to this record")], 403);
        }

        // Check unique unit_number within property
        if ($propertyId && $unitNumber !== '') {
            $dupeCheck = $conn->prepare("SELECT id FROM units WHERE property_id = :pid AND unit_number = :num");
            $dupeCheck->execute(['pid' => $propertyId, 'num' => $unitNumber]);
            if ($dupeCheck->fetch()) {
                $errors[] = 'Unit number already exists in this property';
            }
        }

        if ($errors) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors], 422);
        }

        try {
            $stmt = $conn->prepare("
                INSERT INTO units (property_id, unit_number, unit_label, unit_type, floor, size_sqm, bedrooms, bathrooms, rent_amount, currency, deposit_amount, status, description)
                VALUES (:property_id, :unit_number, :unit_label, :unit_type, :floor, :size_sqm, :bedrooms, :bathrooms, :rent_amount, :currency, :deposit_amount, :status, :description)
            ");
            $stmt->execute([
                'property_id' => $propertyId,
                'unit_number' => $unitNumber,
                'unit_label' => $unitLabel ?: null,
                'unit_type' => $unitType,
                'floor' => $floor ?: null,
                'size_sqm' => $sizeSqm,
                'bedrooms' => $bedrooms,
                'bathrooms' => $bathrooms,
                'rent_amount' => $rentAmount,
                'currency' => $currency,
                'deposit_amount' => $depositAmount,
                'status' => $status,
                'description' => $description
            ]);

            $unitId = (int) $conn->lastInsertId();

            logActivity('created', 'unit', $unitId, ['unit_number' => $unitNumber, 'property_id' => $propertyId]);

            jsonResponse(['success' => true, 'message' => 'Unit created successfully', 'id' => $unitId]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to create unit: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // UPDATE UNIT
    // ============================
    case 'update':
        if (!hasPermission('units', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Unit ID required'], 400);
        }

        if (!canAccessUnit($id)) {
            jsonResponse(['success' => false, 'message' => "You don't have access to this record"], 403);
        }

        // Check exists
        $existing = $conn->prepare("SELECT * FROM units WHERE id = :id");
        $existing->execute(['id' => $id]);
        $old = $existing->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'message' => 'Unit not found'], 404);
        }

        $propertyId = (int)($_POST['property_id'] ?? 0);
        $unitNumber = trim($_POST['unit_number'] ?? '');
        $unitLabel = trim($_POST['unit_label'] ?? '');
        $unitType = trim($_POST['unit_type'] ?? 'apartment');
        $floor = trim($_POST['floor'] ?? '');
        $sizeSqm = ($_POST['size_sqm'] ?? '') !== '' ? (float)$_POST['size_sqm'] : null;
        $bedrooms = ($_POST['bedrooms'] ?? '') !== '' ? (int)$_POST['bedrooms'] : null;
        $bathrooms = ($_POST['bathrooms'] ?? '') !== '' ? (int)$_POST['bathrooms'] : null;
        $rentAmount = max(0, (float)($_POST['rent_amount'] ?? 0));
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $depositAmount = max(0, (float)($_POST['deposit_amount'] ?? 0));
        $status = trim($_POST['status'] ?? 'vacant');
        $description = trim($_POST['description'] ?? '');

        $validTypes = ['apartment','room','shop','office','warehouse','plot','house','flat','hostel','godown'];
        $validStatuses = ['vacant','occupied','maintenance','reserved'];

        $errors = [];
        if (!$propertyId) $errors[] = 'Property is required';
        if ($unitNumber === '') $errors[] = 'Unit number is required';
        if (!in_array($unitType, $validTypes)) $errors[] = 'Invalid unit type';
        if (!in_array($status, $validStatuses)) $errors[] = 'Invalid status';

        // Property scoping: cannot move a unit into a property outside your scope
        if ($propertyId && !canAccessProperty($propertyId)) {
            jsonResponse(['success' => false, 'message' => __('no_access_record', "You don't have access to this record")], 403);
        }

        // Check unique unit_number within property (exclude self)
        if ($propertyId && $unitNumber !== '') {
            $dupeCheck = $conn->prepare("SELECT id FROM units WHERE property_id = :pid AND unit_number = :num AND id != :id");
            $dupeCheck->execute(['pid' => $propertyId, 'num' => $unitNumber, 'id' => $id]);
            if ($dupeCheck->fetch()) {
                $errors[] = 'Unit number already exists in this property';
            }
        }

        if ($errors) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors], 422);
        }

        try {
            $stmt = $conn->prepare("
                UPDATE units SET
                    property_id = :property_id, unit_number = :unit_number, unit_label = :unit_label,
                    unit_type = :unit_type, floor = :floor, size_sqm = :size_sqm,
                    bedrooms = :bedrooms, bathrooms = :bathrooms,
                    rent_amount = :rent_amount, currency = :currency, deposit_amount = :deposit_amount,
                    status = :status, description = :description
                WHERE id = :id
            ");
            $stmt->execute([
                'property_id' => $propertyId,
                'unit_number' => $unitNumber,
                'unit_label' => $unitLabel ?: null,
                'unit_type' => $unitType,
                'floor' => $floor ?: null,
                'size_sqm' => $sizeSqm,
                'bedrooms' => $bedrooms,
                'bathrooms' => $bathrooms,
                'rent_amount' => $rentAmount,
                'currency' => $currency,
                'deposit_amount' => $depositAmount,
                'status' => $status,
                'description' => $description,
                'id' => $id
            ]);

            // Log status change separately if changed
            if ($old['status'] !== $status) {
                logActivity('updated', 'unit', $id, [
                    'status_change' => $old['status'] . ' -> ' . $status,
                    'unit_number' => $unitNumber
                ]);
            } else {
                logActivity('updated', 'unit', $id, ['unit_number' => $unitNumber, 'old_number' => $old['unit_number']]);
            }

            jsonResponse(['success' => true, 'message' => 'Unit updated successfully']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to update unit: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // DELETE UNIT
    // ============================
    case 'delete':
        if (!hasPermission('units', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Unit ID required'], 400);
        }

        if (!canAccessUnit($id)) {
            jsonResponse(['success' => false, 'message' => "You don't have access to this record"], 403);
        }

        // Check if unit has active leases
        $leaseCheck = $conn->prepare("SELECT COUNT(*) FROM leases WHERE unit_id = :id AND status = 'active'");
        $leaseCheck->execute(['id' => $id]);
        $activeLeases = (int) $leaseCheck->fetchColumn();

        if ($activeLeases > 0) {
            jsonResponse(['success' => false, 'message' => "Cannot delete: unit has {$activeLeases} active lease(s). Terminate leases first."], 422);
        }

        try {
            // Delete photos from disk
            $photos = $conn->prepare("SELECT file_path FROM unit_photos WHERE unit_id = :id");
            $photos->execute(['id' => $id]);
            foreach ($photos->fetchAll() as $photo) {
                $filePath = UPLOAD_PATH . $photo['file_path'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            // Delete unit (cascades to photos via FK)
            $stmt = $conn->prepare("DELETE FROM units WHERE id = :id");
            $stmt->execute(['id' => $id]);

            if ($stmt->rowCount() === 0) {
                jsonResponse(['success' => false, 'message' => 'Unit not found'], 404);
            }

            logActivity('deleted', 'unit', $id);

            jsonResponse(['success' => true, 'message' => 'Unit deleted successfully']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to delete unit: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // CHANGE STATUS (single unit with reason)
    // ============================
    case 'change_status':
        if (!hasPermission('units', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        $newStatus = trim($_POST['new_status'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Unit ID required'], 400);
        }

        $validStatuses = ['vacant','occupied','maintenance','reserved'];
        if (!in_array($newStatus, $validStatuses)) {
            jsonResponse(['success' => false, 'message' => 'Invalid status'], 400);
        }

        $existing = $conn->prepare("SELECT * FROM units WHERE id = :id");
        $existing->execute(['id' => $id]);
        $unit = $existing->fetch();
        if (!$unit) {
            jsonResponse(['success' => false, 'message' => 'Unit not found'], 404);
        }

        $oldStatus = $unit['status'];

        $conn->prepare("UPDATE units SET status = :status WHERE id = :id")
            ->execute(['status' => $newStatus, 'id' => $id]);

        logActivity('updated', 'unit', $id, [
            'status_change' => $oldStatus . ' -> ' . $newStatus,
            'reason' => $reason,
            'unit_number' => $unit['unit_number']
        ]);

        jsonResponse(['success' => true, 'message' => "Status changed from {$oldStatus} to {$newStatus}"]);
        break;

    // ============================
    // BULK STATUS CHANGE
    // ============================
    case 'bulk_status':
        if (!hasPermission('units', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $ids = $_POST['ids'] ?? [];
        $newStatus = trim($_POST['new_status'] ?? '');

        if (empty($ids) || !is_array($ids)) {
            jsonResponse(['success' => false, 'message' => 'No units selected'], 400);
        }

        $validStatuses = ['vacant','occupied','maintenance','reserved'];
        if (!in_array($newStatus, $validStatuses)) {
            jsonResponse(['success' => false, 'message' => 'Invalid status'], 400);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("UPDATE units SET status = ? WHERE id IN ({$placeholders})");
        $params = array_merge([$newStatus], array_map('intval', $ids));
        $stmt->execute($params);

        $count = $stmt->rowCount();
        logActivity('updated', 'unit', null, ['bulk_status_change' => $newStatus, 'count' => $count]);

        jsonResponse(['success' => true, 'message' => "{$count} unit(s) updated to {$newStatus}"]);
        break;

    // ============================
    // BULK DELETE
    // ============================
    case 'bulk_delete':
        if (!hasPermission('units', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $ids = $_POST['ids'] ?? [];
        if (empty($ids) || !is_array($ids)) {
            jsonResponse(['success' => false, 'message' => 'No units selected'], 400);
        }

        $ids = array_map('intval', $ids);

        // Check for active leases
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $leaseCheck = $conn->prepare("SELECT COUNT(*) FROM leases WHERE unit_id IN ({$placeholders}) AND status = 'active'");
        $leaseCheck->execute($ids);
        $activeLeases = (int) $leaseCheck->fetchColumn();

        if ($activeLeases > 0) {
            jsonResponse(['success' => false, 'message' => "Cannot delete: {$activeLeases} unit(s) have active leases."], 422);
        }

        try {
            // Delete photos from disk
            $photoStmt = $conn->prepare("SELECT file_path FROM unit_photos WHERE unit_id IN ({$placeholders})");
            $photoStmt->execute($ids);
            foreach ($photoStmt->fetchAll() as $photo) {
                $filePath = UPLOAD_PATH . $photo['file_path'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            $stmt = $conn->prepare("DELETE FROM units WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $count = $stmt->rowCount();

            logActivity('deleted', 'unit', null, ['bulk_delete' => true, 'count' => $count]);

            jsonResponse(['success' => true, 'message' => "{$count} unit(s) deleted"]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to delete: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // GET PROPERTIES (for dropdown)
    // ============================
    case 'properties':
        $stmt = $conn->query("SELECT id, name, property_type, status FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name ASC");
        $properties = $stmt->fetchAll();
        jsonResponse(['success' => true, 'data' => $properties]);
        break;

    // ============================
    // UPLOAD PHOTO
    // ============================
    case 'upload_photo':
        if (!hasPermission('units', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $unitId = (int)($_POST['unit_id'] ?? 0);
        if (!$unitId) {
            jsonResponse(['success' => false, 'message' => 'Unit ID required'], 400);
        }

        // Verify unit exists
        $check = $conn->prepare("SELECT id FROM units WHERE id = :id");
        $check->execute(['id' => $unitId]);
        if (!$check->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Unit not found'], 404);
        }

        if (empty($_FILES['photos'])) {
            jsonResponse(['success' => false, 'message' => 'No files uploaded'], 400);
        }

        $uploaded = [];
        $errors = [];
        $files = $_FILES['photos'];

        // Get current max sort_order
        $maxSort = (int) $conn->query("SELECT COALESCE(MAX(sort_order), 0) FROM unit_photos WHERE unit_id = {$unitId}")->fetchColumn();

        $fileCount = is_array($files['name']) ? count($files['name']) : 1;
        for ($i = 0; $i < $fileCount; $i++) {
            $file = [
                'name' => is_array($files['name']) ? $files['name'][$i] : $files['name'],
                'type' => is_array($files['type']) ? $files['type'][$i] : $files['type'],
                'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
                'error' => is_array($files['error']) ? $files['error'][$i] : $files['error'],
                'size' => is_array($files['size']) ? $files['size'][$i] : $files['size'],
            ];

            if ($file['error'] !== UPLOAD_ERR_OK) continue;

            $result = uploadFile($file, "units/{$unitId}", ALLOWED_IMAGE_TYPES);
            if ($result['success']) {
                $maxSort++;
                $caption = pathinfo($file['name'], PATHINFO_FILENAME);
                $stmt = $conn->prepare("
                    INSERT INTO unit_photos (unit_id, file_path, caption, sort_order)
                    VALUES (:uid, :path, :caption, :sort)
                ");
                $stmt->execute([
                    'uid' => $unitId,
                    'path' => $result['filepath'],
                    'caption' => $caption,
                    'sort' => $maxSort
                ]);
                $uploaded[] = [
                    'id' => (int) $conn->lastInsertId(),
                    'file_path' => $result['filepath'],
                    'caption' => $caption,
                    'sort_order' => $maxSort
                ];
            } else {
                $errors[] = $file['name'] . ': ' . $result['message'];
            }
        }

        jsonResponse([
            'success' => count($uploaded) > 0,
            'message' => count($uploaded) . ' photo(s) uploaded' . (count($errors) ? ', ' . count($errors) . ' failed' : ''),
            'uploaded' => $uploaded,
            'errors' => $errors
        ]);
        break;

    // ============================
    // DELETE PHOTO
    // ============================
    case 'delete_photo':
        if (!hasPermission('units', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $photoId = (int)($_POST['photo_id'] ?? 0);
        if (!$photoId) {
            jsonResponse(['success' => false, 'message' => 'Photo ID required'], 400);
        }

        $photo = $conn->prepare("SELECT * FROM unit_photos WHERE id = :id");
        $photo->execute(['id' => $photoId]);
        $photoData = $photo->fetch();

        if (!$photoData) {
            jsonResponse(['success' => false, 'message' => 'Photo not found'], 404);
        }

        // Delete file from disk
        $filePath = UPLOAD_PATH . $photoData['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $conn->prepare("DELETE FROM unit_photos WHERE id = :id")->execute(['id' => $photoId]);

        jsonResponse(['success' => true, 'message' => 'Photo deleted']);
        break;

    // ============================
    // UPDATE PHOTO (caption)
    // ============================
    case 'update_photo':
        if (!hasPermission('units', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $photoId = (int)($_POST['photo_id'] ?? 0);
        $caption = trim($_POST['caption'] ?? '');

        if (!$photoId) {
            jsonResponse(['success' => false, 'message' => 'Photo ID required'], 400);
        }

        $conn->prepare("UPDATE unit_photos SET caption = :caption WHERE id = :id")
            ->execute(['caption' => $caption, 'id' => $photoId]);

        jsonResponse(['success' => true, 'message' => 'Photo updated']);
        break;

    // ============================
    // REORDER PHOTOS
    // ============================
    case 'reorder_photos':
        if (!hasPermission('units', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $order = $_POST['order'] ?? [];
        if (!is_array($order)) {
            jsonResponse(['success' => false, 'message' => 'Invalid order data'], 400);
        }

        $stmt = $conn->prepare("UPDATE unit_photos SET sort_order = :sort WHERE id = :id");
        foreach ($order as $index => $photoId) {
            $stmt->execute(['sort' => (int) $index, 'id' => (int) $photoId]);
        }

        jsonResponse(['success' => true, 'message' => 'Photo order updated']);
        break;

    // ============================
    // STATUS HISTORY (via activity_log)
    // ============================
    case 'status_history':
        if (!hasPermission('units', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $unitId = (int)($_GET['unit_id'] ?? 0);
        if (!$unitId) {
            jsonResponse(['success' => false, 'message' => 'Unit ID required'], 400);
        }

        $stmt = $conn->prepare("
            SELECT al.*, u.full_name AS user_name
            FROM activity_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.entity_type = 'unit'
              AND al.entity_id = :uid
              AND al.action = 'updated'
              AND JSON_EXTRACT(al.details, '$.status_change') IS NOT NULL
            ORDER BY al.created_at DESC
            LIMIT 20
        ");
        $stmt->execute(['uid' => $unitId]);
        $history = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $history]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
}
