<?php
/**
 * FIS Real Estate — Properties API
 * Handles CRUD operations for properties.
 * Used by AJAX calls from the properties module pages.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Must be logged in
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // LIST PROPERTIES (with filters, search, pagination)
    // ============================
    case 'list':
        if (!hasPermission('properties', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $search = trim($_GET['search'] ?? '');
        $type = trim($_GET['type'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $city = trim($_GET['city'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int)($_GET['per_page'] ?? RECORDS_PER_PAGE)));

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(p.name LIKE :search OR p.address LIKE :search2 OR p.city LIKE :search3)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
        }
        if ($type !== '') {
            $where[] = "p.property_type = :type";
            $params['type'] = $type;
        }
        if ($status !== '') {
            $where[] = "p.status = :status";
            $params['status'] = $status;
        }
        if ($city !== '') {
            $where[] = "p.city = :city";
            $params['city'] = $city;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Apply property-based access scoping (both queries carry the `p` alias)
        $scope = propertyScopeSql('p.id');
        $whereSql = ($whereSql === '' && $scope !== '') ? 'WHERE 1=1' . $scope : $whereSql . $scope;

        // Count total
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM properties p {$whereSql}");
        $countStmt->execute($params);
        $totalRecords = (int) $countStmt->fetchColumn();

        $pagination = paginate($totalRecords, $page, $perPage);

        // Fetch records with unit counts
        $sql = "SELECT p.*,
                    (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id) AS unit_count,
                    (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id AND u.status = 'occupied') AS occupied_count,
                    (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id AND u.status = 'vacant') AS vacant_count
                FROM properties p
                {$whereSql}
                ORDER BY p.created_at DESC
                LIMIT :offset, :limit";

        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":{$k}", $v);
        }
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $properties = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'data' => $properties,
            'pagination' => $pagination
        ]);
        break;

    // ============================
    // GET SINGLE PROPERTY
    // ============================
    case 'get':
        if (!hasPermission('properties', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Property ID required'], 400);
        }

        if (!canAccessProperty($id)) {
            jsonResponse(['success' => false, 'message' => "You don't have access to this record"], 403);
        }

        $stmt = $conn->prepare("
            SELECT p.*,
                (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id) AS unit_count,
                (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id AND u.status = 'occupied') AS occupied_count,
                (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id AND u.status = 'vacant') AS vacant_count
            FROM properties p
            WHERE p.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $property = $stmt->fetch();

        if (!$property) {
            jsonResponse(['success' => false, 'message' => 'Property not found'], 404);
        }

        // Get photos
        $photoStmt = $conn->prepare("SELECT * FROM property_photos WHERE property_id = :id ORDER BY sort_order ASC, id ASC");
        $photoStmt->execute(['id' => $id]);
        $property['photos'] = $photoStmt->fetchAll();

        // Get settings
        $settingsStmt = $conn->prepare("SELECT * FROM property_settings WHERE property_id = :id");
        $settingsStmt->execute(['id' => $id]);
        $property['settings'] = $settingsStmt->fetch() ?: null;

        jsonResponse(['success' => true, 'data' => $property]);
        break;

    // ============================
    // CREATE PROPERTY
    // ============================
    case 'create':
        if (!hasPermission('properties', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $name = trim($_POST['name'] ?? '');
        $propertyType = trim($_POST['property_type'] ?? 'residential');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $region = trim($_POST['region'] ?? '');
        $country = trim($_POST['country'] ?? 'Tanzania');
        $description = trim($_POST['description'] ?? '');
        $totalUnits = max(0, (int)($_POST['total_units'] ?? 0));
        $status = trim($_POST['status'] ?? 'active');

        // Validate
        $errors = [];
        if ($name === '') $errors[] = 'Property name is required';
        if (!in_array($propertyType, ['residential', 'commercial', 'land', 'mixed'])) {
            $errors[] = 'Invalid property type';
        }
        if (!in_array($status, ['active', 'inactive'])) {
            $errors[] = 'Invalid status';
        }

        if ($errors) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors], 422);
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO properties (name, property_type, address, city, region, country, description, total_units, status)
                VALUES (:name, :type, :address, :city, :region, :country, :description, :total_units, :status)
            ");
            $stmt->execute([
                'name' => $name,
                'type' => $propertyType,
                'address' => $address,
                'city' => $city,
                'region' => $region,
                'country' => $country,
                'description' => $description,
                'total_units' => $totalUnits,
                'status' => $status
            ]);

            $propertyId = (int) $conn->lastInsertId();

            // Create default property_settings row
            $conn->prepare("
                INSERT INTO property_settings (property_id, rent_cycle, late_fee_type, late_fee_value, grace_period_days, deposit_months)
                VALUES (:pid, 'monthly', 'none', 0, 5, 1)
            ")->execute(['pid' => $propertyId]);

            $conn->commit();

            logActivity('created', 'property', $propertyId, ['name' => $name, 'type' => $propertyType]);

            jsonResponse(['success' => true, 'message' => 'Property created successfully', 'id' => $propertyId]);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Failed to create property: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // UPDATE PROPERTY
    // ============================
    case 'update':
        if (!hasPermission('properties', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Property ID required'], 400);
        }

        if (!canAccessProperty($id)) {
            jsonResponse(['success' => false, 'message' => "You don't have access to this record"], 403);
        }

        // Check exists
        $existing = $conn->prepare("SELECT * FROM properties WHERE id = :id");
        $existing->execute(['id' => $id]);
        $old = $existing->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'message' => 'Property not found'], 404);
        }

        $name = trim($_POST['name'] ?? '');
        $propertyType = trim($_POST['property_type'] ?? 'residential');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $region = trim($_POST['region'] ?? '');
        $country = trim($_POST['country'] ?? 'Tanzania');
        $description = trim($_POST['description'] ?? '');
        $totalUnits = max(0, (int)($_POST['total_units'] ?? 0));
        $status = trim($_POST['status'] ?? 'active');

        $errors = [];
        if ($name === '') $errors[] = 'Property name is required';
        if (!in_array($propertyType, ['residential', 'commercial', 'land', 'mixed'])) {
            $errors[] = 'Invalid property type';
        }
        if (!in_array($status, ['active', 'inactive'])) {
            $errors[] = 'Invalid status';
        }

        if ($errors) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors], 422);
        }

        try {
            $stmt = $conn->prepare("
                UPDATE properties SET
                    name = :name, property_type = :type, address = :address, city = :city,
                    region = :region, country = :country, description = :description,
                    total_units = :total_units, status = :status
                WHERE id = :id
            ");
            $stmt->execute([
                'name' => $name,
                'type' => $propertyType,
                'address' => $address,
                'city' => $city,
                'region' => $region,
                'country' => $country,
                'description' => $description,
                'total_units' => $totalUnits,
                'status' => $status,
                'id' => $id
            ]);

            logActivity('updated', 'property', $id, ['name' => $name, 'old_name' => $old['name']]);

            jsonResponse(['success' => true, 'message' => 'Property updated successfully']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to update property: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // DELETE PROPERTY
    // ============================
    case 'delete':
        if (!hasPermission('properties', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Property ID required'], 400);
        }

        if (!canAccessProperty($id)) {
            jsonResponse(['success' => false, 'message' => "You don't have access to this record"], 403);
        }

        // Check if property has units
        $unitCount = (int) $conn->prepare("SELECT COUNT(*) FROM units WHERE property_id = :id")
            ->execute(['id' => $id]) ? $conn->query("SELECT COUNT(*) FROM units WHERE property_id = {$id}")->fetchColumn() : 0;

        if ($unitCount > 0) {
            jsonResponse(['success' => false, 'message' => "Cannot delete: property has {$unitCount} unit(s). Remove units first."], 422);
        }

        try {
            // Delete photos from disk
            $photos = $conn->prepare("SELECT file_path FROM property_photos WHERE property_id = :id");
            $photos->execute(['id' => $id]);
            foreach ($photos->fetchAll() as $photo) {
                $filePath = UPLOAD_PATH . $photo['file_path'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            // Delete property (cascades to photos & settings via FK)
            $stmt = $conn->prepare("DELETE FROM properties WHERE id = :id");
            $stmt->execute(['id' => $id]);

            if ($stmt->rowCount() === 0) {
                jsonResponse(['success' => false, 'message' => 'Property not found'], 404);
            }

            logActivity('deleted', 'property', $id);

            jsonResponse(['success' => true, 'message' => 'Property deleted successfully']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to delete property: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // TOGGLE STATUS (bulk)
    // ============================
    case 'toggle_status':
        if (!hasPermission('properties', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $ids = $_POST['ids'] ?? [];
        $newStatus = trim($_POST['new_status'] ?? '');

        if (empty($ids) || !is_array($ids)) {
            jsonResponse(['success' => false, 'message' => 'No properties selected'], 400);
        }
        if (!in_array($newStatus, ['active', 'inactive'])) {
            jsonResponse(['success' => false, 'message' => 'Invalid status'], 400);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("UPDATE properties SET status = ? WHERE id IN ({$placeholders})");
        $params = array_merge([$newStatus], array_map('intval', $ids));
        $stmt->execute($params);

        $count = $stmt->rowCount();
        logActivity('updated', 'property', null, ['bulk_status_toggle' => $newStatus, 'count' => $count]);

        jsonResponse(['success' => true, 'message' => "{$count} property(ies) updated to {$newStatus}"]);
        break;

    // ============================
    // GET CITIES (for filter dropdown)
    // ============================
    case 'cities':
        $stmt = $conn->query("SELECT DISTINCT city FROM properties WHERE city IS NOT NULL AND city != '' ORDER BY city ASC");
        $cities = $stmt->fetchAll(PDO::FETCH_COLUMN);
        jsonResponse(['success' => true, 'data' => $cities]);
        break;

    // ============================
    // UPLOAD PHOTO
    // ============================
    case 'upload_photo':
        if (!hasPermission('properties', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $propertyId = (int)($_POST['property_id'] ?? 0);
        if (!$propertyId) {
            jsonResponse(['success' => false, 'message' => 'Property ID required'], 400);
        }

        // Verify property exists
        $check = $conn->prepare("SELECT id FROM properties WHERE id = :id");
        $check->execute(['id' => $propertyId]);
        if (!$check->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Property not found'], 404);
        }

        if (empty($_FILES['photos'])) {
            jsonResponse(['success' => false, 'message' => 'No files uploaded'], 400);
        }

        $uploaded = [];
        $errors = [];
        $files = $_FILES['photos'];

        // Get current max sort_order
        $maxSort = (int) $conn->query("SELECT COALESCE(MAX(sort_order), 0) FROM property_photos WHERE property_id = {$propertyId}")->fetchColumn();

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

            $result = uploadFile($file, "properties/{$propertyId}", ALLOWED_IMAGE_TYPES);
            if ($result['success']) {
                $maxSort++;
                $caption = pathinfo($file['name'], PATHINFO_FILENAME);
                $stmt = $conn->prepare("
                    INSERT INTO property_photos (property_id, file_path, caption, sort_order)
                    VALUES (:pid, :path, :caption, :sort)
                ");
                $stmt->execute([
                    'pid' => $propertyId,
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
        if (!hasPermission('properties', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $photoId = (int)($_POST['photo_id'] ?? 0);
        if (!$photoId) {
            jsonResponse(['success' => false, 'message' => 'Photo ID required'], 400);
        }

        $photo = $conn->prepare("SELECT * FROM property_photos WHERE id = :id");
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

        $conn->prepare("DELETE FROM property_photos WHERE id = :id")->execute(['id' => $photoId]);

        jsonResponse(['success' => true, 'message' => 'Photo deleted']);
        break;

    // ============================
    // UPDATE PHOTO (caption, sort order)
    // ============================
    case 'update_photo':
        if (!hasPermission('properties', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $photoId = (int)($_POST['photo_id'] ?? 0);
        $caption = trim($_POST['caption'] ?? '');

        if (!$photoId) {
            jsonResponse(['success' => false, 'message' => 'Photo ID required'], 400);
        }

        $conn->prepare("UPDATE property_photos SET caption = :caption WHERE id = :id")
            ->execute(['caption' => $caption, 'id' => $photoId]);

        jsonResponse(['success' => true, 'message' => 'Photo updated']);
        break;

    // ============================
    // REORDER PHOTOS
    // ============================
    case 'reorder_photos':
        if (!hasPermission('properties', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $order = $_POST['order'] ?? [];
        if (!is_array($order)) {
            jsonResponse(['success' => false, 'message' => 'Invalid order data'], 400);
        }

        $stmt = $conn->prepare("UPDATE property_photos SET sort_order = :sort WHERE id = :id");
        foreach ($order as $index => $photoId) {
            $stmt->execute(['sort' => (int) $index, 'id' => (int) $photoId]);
        }

        jsonResponse(['success' => true, 'message' => 'Photo order updated']);
        break;

    // ============================
    // SAVE PROPERTY SETTINGS
    // ============================
    case 'save_settings':
        if (!hasPermission('properties', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $propertyId = (int)($_POST['property_id'] ?? 0);
        if (!$propertyId) {
            jsonResponse(['success' => false, 'message' => 'Property ID required'], 400);
        }

        $rentCycle = trim($_POST['rent_cycle'] ?? 'monthly');
        $lateFeeType = trim($_POST['late_fee_type'] ?? 'none');
        $lateFeeValue = max(0, (float)($_POST['late_fee_value'] ?? 0));
        $gracePeriodDays = max(0, (int)($_POST['grace_period_days'] ?? 5));
        $maxLateFee = max(0, (float)($_POST['max_late_fee'] ?? 0));
        $depositMonths = max(0, (float)($_POST['deposit_months'] ?? 1));
        $paymentInstructions = trim($_POST['payment_instructions'] ?? '');

        if (!in_array($rentCycle, ['monthly', 'quarterly', 'yearly'])) {
            jsonResponse(['success' => false, 'message' => 'Invalid rent cycle'], 422);
        }
        if (!in_array($lateFeeType, ['percentage', 'fixed', 'none'])) {
            jsonResponse(['success' => false, 'message' => 'Invalid late fee type'], 422);
        }

        try {
            $stmt = $conn->prepare("
                INSERT INTO property_settings (property_id, rent_cycle, late_fee_type, late_fee_value, grace_period_days, max_late_fee, deposit_months, payment_instructions)
                VALUES (:pid, :rc, :lft, :lfv, :gpd, :mlf, :dm, :pi)
                ON DUPLICATE KEY UPDATE
                    rent_cycle = VALUES(rent_cycle),
                    late_fee_type = VALUES(late_fee_type),
                    late_fee_value = VALUES(late_fee_value),
                    grace_period_days = VALUES(grace_period_days),
                    max_late_fee = VALUES(max_late_fee),
                    deposit_months = VALUES(deposit_months),
                    payment_instructions = VALUES(payment_instructions)
            ");
            $stmt->execute([
                'pid' => $propertyId,
                'rc' => $rentCycle,
                'lft' => $lateFeeType,
                'lfv' => $lateFeeValue,
                'gpd' => $gracePeriodDays,
                'mlf' => $maxLateFee,
                'dm' => $depositMonths,
                'pi' => $paymentInstructions
            ]);

            logActivity('updated', 'property_settings', $propertyId);

            jsonResponse(['success' => true, 'message' => 'Property settings saved successfully']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to save settings: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // PROPERTY DASHBOARD STATS
    // ============================
    case 'dashboard_stats':
        if (!hasPermission('properties', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $propertyId = (int)($_GET['property_id'] ?? 0);
        if (!$propertyId) {
            jsonResponse(['success' => false, 'message' => 'Property ID required'], 400);
        }

        $stats = [];

        // Unit counts
        $stmt = $conn->prepare("SELECT
            COUNT(*) AS total_units,
            SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) AS occupied,
            SUM(CASE WHEN status = 'vacant' THEN 1 ELSE 0 END) AS vacant,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance,
            SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) AS reserved
            FROM units WHERE property_id = :pid
        ");
        $stmt->execute(['pid' => $propertyId]);
        $stats['units'] = $stmt->fetch();

        // Occupancy rate
        $total = (int) $stats['units']['total_units'];
        $occupied = (int) $stats['units']['occupied'];
        $stats['occupancy_rate'] = $total > 0 ? round(($occupied / $total) * 100, 1) : 0;

        // Revenue this month
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(pay.amount), 0) AS revenue
            FROM payments pay
            INNER JOIN units u ON pay.unit_id = u.id
            WHERE u.property_id = :pid
              AND pay.payment_date BETWEEN :start AND :end
              AND pay.status = 'recorded'
        ");
        $stmt->execute(['pid' => $propertyId, 'start' => $monthStart, 'end' => $monthEnd]);
        $stats['revenue_this_month'] = (float) $stmt->fetchColumn();

        // Overdue amount (active leases where payment < rent)
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(l.rent_amount), 0) AS expected
            FROM leases l
            INNER JOIN units u ON l.unit_id = u.id
            WHERE u.property_id = :pid AND l.status = 'active'
        ");
        $stmt->execute(['pid' => $propertyId]);
        $expectedRent = (float) $stmt->fetchColumn();
        $stats['expected_rent'] = $expectedRent;
        $stats['overdue_amount'] = max(0, $expectedRent - $stats['revenue_this_month']);

        // Active tenants for this property
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT l.tenant_id) AS active_tenants
            FROM leases l
            INNER JOIN units u ON l.unit_id = u.id
            WHERE u.property_id = :pid AND l.status = 'active'
        ");
        $stmt->execute(['pid' => $propertyId]);
        $stats['active_tenants'] = (int) $stmt->fetchColumn();

        // Active leases
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM leases l
            INNER JOIN units u ON l.unit_id = u.id
            WHERE u.property_id = :pid AND l.status = 'active'
        ");
        $stmt->execute(['pid' => $propertyId]);
        $stats['active_leases'] = (int) $stmt->fetchColumn();

        // Revenue last 6 months (for mini chart)
        $revenueChart = [];
        for ($i = 5; $i >= 0; $i--) {
            $mStart = date('Y-m-01', strtotime("-{$i} months"));
            $mEnd = date('Y-m-t', strtotime("-{$i} months"));
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(pay.amount), 0) AS revenue
                FROM payments pay
                INNER JOIN units u ON pay.unit_id = u.id
                WHERE u.property_id = :pid
                  AND pay.payment_date BETWEEN :start AND :end
                  AND pay.status = 'recorded'
            ");
            $stmt->execute(['pid' => $propertyId, 'start' => $mStart, 'end' => $mEnd]);
            $revenueChart[] = [
                'month' => date('M Y', strtotime($mStart)),
                'revenue' => (float) $stmt->fetchColumn()
            ];
        }
        $stats['revenue_chart'] = $revenueChart;

        jsonResponse(['success' => true, 'data' => $stats]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
}
