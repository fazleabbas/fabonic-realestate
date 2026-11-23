<?php
/**
 * FIS Real Estate — Maintenance & Work Orders API (Part 12 + Part 19 Enterprise)
 * Handles CRUD for maintenance requests, assignments, contractors,
 * categories, time logs, photos, reports, and notifications.
 * Enterprise tier required.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';

// Must be logged in
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

// Enterprise tier + module check
if (!hasTier('enterprise')) {
    jsonResponse(['success' => false, 'message' => __('maint_tier_required', 'Enterprise tier required for maintenance management')], 403);
}
if (getSetting('maintenance_enabled', '0') !== '1') {
    jsonResponse(['success' => false, 'message' => __('maint_disabled', 'Maintenance module is disabled')], 403);
}

$action = $_REQUEST['action'] ?? '';

// --- Property-based access scoping helpers (local to this API) ---
// Resolve the unit_id behind a request / assignment / photo / time log and guard it.
if (!function_exists('apiMaintReqUnitId')) {
    function apiMaintReqUnitId($conn, $requestId) {
        $s = $conn->prepare("SELECT unit_id FROM maintenance_requests WHERE id = ?");
        $s->execute([(int)$requestId]);
        return (int)$s->fetchColumn();
    }
    function apiMaintAssignUnitId($conn, $assignId) {
        $s = $conn->prepare("SELECT mr.unit_id FROM maintenance_assignments ma INNER JOIN maintenance_requests mr ON ma.request_id = mr.id WHERE ma.id = ?");
        $s->execute([(int)$assignId]);
        return (int)$s->fetchColumn();
    }
    function apiMaintPhotoUnitId($conn, $photoId) {
        $s = $conn->prepare("SELECT mr.unit_id FROM maintenance_photos mp INNER JOIN maintenance_requests mr ON mp.request_id = mr.id WHERE mp.id = ?");
        $s->execute([(int)$photoId]);
        return (int)$s->fetchColumn();
    }
    function apiMaintLogUnitId($conn, $logId) {
        $s = $conn->prepare("SELECT mr.unit_id FROM maintenance_time_logs tl INNER JOIN maintenance_assignments ma ON tl.assignment_id = ma.id INNER JOIN maintenance_requests mr ON ma.request_id = mr.id WHERE tl.id = ?");
        $s->execute([(int)$logId]);
        return (int)$s->fetchColumn();
    }
    function apiScopeDeny() {
        jsonResponse(['success' => false, 'message' => "You don't have access to this record"], 403);
    }
}

switch ($action) {

    // ============================
    // LIST MAINTENANCE REQUESTS (with filters, search, pagination)
    // ============================
    case 'list':
        if (!hasPermission('maintenance', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $priority = trim($_GET['priority'] ?? '');
        $categoryId = (int)($_GET['category_id'] ?? 0);
        $propertyId = (int)($_GET['property_id'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int)($_GET['per_page'] ?? RECORDS_PER_PAGE)));

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(mr.title LIKE :search OR u.unit_number LIKE :search2 OR p.name LIKE :search3 OR CONCAT(t.first_name, ' ', t.last_name) LIKE :search4)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
            $params['search4'] = "%{$search}%";
        }
        if ($status !== '') {
            $where[] = "mr.status = :status";
            $params['status'] = $status;
        }
        if ($priority !== '') {
            $where[] = "mr.priority = :priority";
            $params['priority'] = $priority;
        }
        if ($categoryId > 0) {
            $where[] = "mr.category_id = :category_id";
            $params['category_id'] = $categoryId;
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
            FROM maintenance_requests mr
            INNER JOIN units u ON mr.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            LEFT JOIN tenants t ON mr.tenant_id = t.id
            {$whereSql}
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;

        $sql = "SELECT mr.*, u.unit_number, p.name AS property_name, p.id AS property_id,
                mc.name AS category_name,
                CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                (SELECT COUNT(*) FROM maintenance_photos mp WHERE mp.request_id = mr.id) AS photo_count,
                (SELECT COUNT(*) FROM maintenance_assignments ma WHERE ma.request_id = mr.id) AS assignment_count
                FROM maintenance_requests mr
                INNER JOIN units u ON mr.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                LEFT JOIN tenants t ON mr.tenant_id = t.id
                LEFT JOIN maintenance_categories mc ON mr.category_id = mc.id
                {$whereSql}
                ORDER BY
                    FIELD(mr.priority, 'urgent', 'high', 'medium', 'low'),
                    mr.created_at DESC
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
    // GET SINGLE MAINTENANCE REQUEST
    // ============================
    case 'get':
        if (!hasPermission('maintenance', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'ID required'], 400);
        }

        $stmt = $conn->prepare("
            SELECT mr.*, u.unit_number, u.unit_label, u.id AS unit_id_val,
                   p.name AS property_name, p.id AS property_id_val,
                   mc.name AS category_name,
                   CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                   t.phone AS tenant_phone, t.id AS tenant_id_val
            FROM maintenance_requests mr
            INNER JOIN units u ON mr.unit_id = u.id
            INNER JOIN properties p ON u.property_id = p.id
            LEFT JOIN tenants t ON mr.tenant_id = t.id
            LEFT JOIN maintenance_categories mc ON mr.category_id = mc.id
            WHERE mr.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $request = $stmt->fetch();

        if (!$request) {
            jsonResponse(['success' => false, 'message' => 'Request not found'], 404);
        }
        if (!canAccessUnit($request['unit_id'])) apiScopeDeny();

        // Get photos
        $photoStmt = $conn->prepare("SELECT * FROM maintenance_photos WHERE request_id = :rid ORDER BY photo_type, created_at");
        $photoStmt->execute(['rid' => $id]);
        $request['photos'] = $photoStmt->fetchAll();

        // Get assignments
        $assignStmt = $conn->prepare("
            SELECT ma.*, c.name AS contractor_name, c.phone AS contractor_phone,
                   c.specialty AS contractor_specialty
            FROM maintenance_assignments ma
            INNER JOIN contractors c ON ma.contractor_id = c.id
            WHERE ma.request_id = :rid
            ORDER BY ma.assigned_date DESC
        ");
        $assignStmt->execute(['rid' => $id]);
        $request['assignments'] = $assignStmt->fetchAll();

        // Get time logs
        $timeStmt = $conn->prepare("
            SELECT tl.*, u.full_name AS logged_by_name
            FROM maintenance_time_logs tl
            LEFT JOIN users u ON tl.logged_by = u.id
            INNER JOIN maintenance_assignments ma ON tl.assignment_id = ma.id
            WHERE ma.request_id = :rid
            ORDER BY tl.log_date DESC
        ");
        $timeStmt->execute(['rid' => $id]);
        $request['time_logs'] = $timeStmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $request]);
        break;

    // ============================
    // CREATE MAINTENANCE REQUEST (12.1)
    // ============================
    case 'create':
        if (!hasPermission('maintenance', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $unitId = (int)($_POST['unit_id'] ?? 0);
        $tenantId = (int)($_POST['tenant_id'] ?? 0) ?: null;
        $categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
        $priority = trim($_POST['priority'] ?? 'medium');
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $validPriorities = ['low', 'medium', 'high', 'urgent'];

        $errors = [];
        if (!$unitId) $errors[] = 'Unit is required';
        if (!$title) $errors[] = 'Title is required';
        if (!in_array($priority, $validPriorities)) $priority = 'medium';

        // Verify unit exists
        if ($unitId) {
            $uCheck = $conn->prepare("SELECT id FROM units WHERE id = :id");
            $uCheck->execute(['id' => $unitId]);
            if (!$uCheck->fetch()) $errors[] = 'Unit not found';
        }

        if (!empty($errors)) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors)], 400);
        }

        // Property-based access scoping: target unit must be in scope
        if ($unitId && !canAccessUnit($unitId)) apiScopeDeny();

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO maintenance_requests (unit_id, tenant_id, category_id, priority,
                    title, description, status, reported_by_type, reported_by_id)
                VALUES (:unit_id, :tenant_id, :category_id, :priority,
                    :title, :description, 'reported', 'staff', :reported_by)
            ");
            $stmt->execute([
                'unit_id' => $unitId,
                'tenant_id' => $tenantId,
                'category_id' => $categoryId,
                'priority' => $priority,
                'title' => $title,
                'description' => $description ?: null,
                'reported_by' => getUserId(),
            ]);

            $requestId = (int) $conn->lastInsertId();

            // Handle photo uploads
            if (isset($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
                $photoInsert = $conn->prepare("
                    INSERT INTO maintenance_photos (request_id, file_path, caption, photo_type)
                    VALUES (:rid, :path, :caption, 'before')
                ");
                $fileCount = count($_FILES['photos']['name']);
                for ($i = 0; $i < $fileCount; $i++) {
                    if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
                        $file = [
                            'name' => $_FILES['photos']['name'][$i],
                            'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                            'error' => $_FILES['photos']['error'][$i],
                            'size' => $_FILES['photos']['size'][$i],
                        ];
                        $result = uploadFile($file, 'maintenance/' . $requestId, ALLOWED_IMAGE_TYPES);
                        if ($result['success']) {
                            $photoInsert->execute([
                                'rid' => $requestId,
                                'path' => $result['filepath'],
                                'caption' => null,
                            ]);
                        }
                    }
                }
            }

            $conn->commit();

            logActivity('created', 'maintenance_request', $requestId, [
                'title' => $title,
                'priority' => $priority,
                'unit_id' => $unitId,
            ]);

            jsonResponse(['success' => true, 'message' => 'Maintenance request created', 'id' => $requestId]);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // UPDATE MAINTENANCE REQUEST
    // ============================
    case 'update':
        if (!hasPermission('maintenance', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'ID required'], 400);
        }
        if (!canAccessUnit(apiMaintReqUnitId($conn, $id))) apiScopeDeny();

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = trim($_POST['priority'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 0);

        $validPriorities = ['low', 'medium', 'high', 'urgent'];

        $sets = [];
        $params = ['id' => $id];

        if ($title !== '') {
            $sets[] = "title = :title";
            $params['title'] = $title;
        }
        if ($description !== '') {
            $sets[] = "description = :description";
            $params['description'] = $description;
        }
        if (in_array($priority, $validPriorities)) {
            $sets[] = "priority = :priority";
            $params['priority'] = $priority;
        }
        if ($categoryId > 0) {
            $sets[] = "category_id = :category_id";
            $params['category_id'] = $categoryId;
        }

        if (empty($sets)) {
            jsonResponse(['success' => false, 'message' => 'Nothing to update'], 400);
        }

        $sql = "UPDATE maintenance_requests SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        logActivity('updated', 'maintenance_request', $id, $params);

        jsonResponse(['success' => true, 'message' => 'Request updated']);
        break;

    // ============================
    // UPDATE STATUS (12.3)
    // ============================
    case 'update_status':
        if (!hasPermission('maintenance', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $validStatuses = ['reported', 'acknowledged', 'assigned', 'in_progress', 'completed', 'closed', 'cancelled'];

        if (!$id || !in_array($newStatus, $validStatuses)) {
            jsonResponse(['success' => false, 'message' => 'Valid ID and status required'], 400);
        }

        // Get old status
        $oldStmt = $conn->prepare("SELECT status FROM maintenance_requests WHERE id = :id");
        $oldStmt->execute(['id' => $id]);
        $oldData = $oldStmt->fetch();
        if (!$oldData) {
            jsonResponse(['success' => false, 'message' => 'Request not found'], 404);
        }
        if (!canAccessUnit(apiMaintReqUnitId($conn, $id))) apiScopeDeny();

        $sets = ["status = :status"];
        $params = ['id' => $id, 'status' => $newStatus];

        if ($notes !== '') {
            $sets[] = "completion_notes = :notes";
            $params['notes'] = $notes;
        }

        if (in_array($newStatus, ['completed', 'closed'])) {
            $sets[] = "completed_date = CURDATE()";
        }

        $sql = "UPDATE maintenance_requests SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        // Update cost/hours summary on request
        updateRequestSummary($conn, $id);

        logActivity('updated', 'maintenance_request', $id, [
            'status_change' => $oldData['status'] . ' → ' . $newStatus,
            'notes' => $notes,
        ]);

        // Send notifications on status change (Part 19)
        notifyStatusChange($conn, $id, $oldData['status'], $newStatus, $notes);

        jsonResponse(['success' => true, 'message' => __('maint_status_updated', 'Status updated')]);
        break;

    // ============================
    // DELETE MAINTENANCE REQUEST
    // ============================
    case 'delete':
        if (!hasPermission('maintenance', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'ID required'], 400);
        }
        if (!canAccessUnit(apiMaintReqUnitId($conn, $id))) apiScopeDeny();

        // Delete photos from disk
        $photoStmt = $conn->prepare("SELECT file_path FROM maintenance_photos WHERE request_id = :rid");
        $photoStmt->execute(['rid' => $id]);
        while ($photo = $photoStmt->fetch()) {
            $fullPath = UPLOAD_PATH . $photo['file_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        $stmt = $conn->prepare("DELETE FROM maintenance_requests WHERE id = :id");
        $stmt->execute(['id' => $id]);

        logActivity('deleted', 'maintenance_request', $id);

        jsonResponse(['success' => true, 'message' => 'Request deleted']);
        break;

    // ============================
    // UPLOAD PHOTO
    // ============================
    case 'upload_photo':
        if (!hasPermission('maintenance', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $requestId = (int)($_POST['request_id'] ?? 0);
        $photoType = trim($_POST['photo_type'] ?? 'before');
        $caption = trim($_POST['caption'] ?? '');

        if (!$requestId) {
            jsonResponse(['success' => false, 'message' => 'Request ID required'], 400);
        }
        if (!canAccessUnit(apiMaintReqUnitId($conn, $requestId))) apiScopeDeny();

        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['success' => false, 'message' => 'No file uploaded'], 400);
        }

        $validTypes = ['before', 'after'];
        if (!in_array($photoType, $validTypes)) $photoType = 'before';

        $result = uploadFile($_FILES['photo'], 'maintenance/' . $requestId, ALLOWED_IMAGE_TYPES);
        if (!$result['success']) {
            jsonResponse(['success' => false, 'message' => $result['message']], 400);
        }

        $stmt = $conn->prepare("
            INSERT INTO maintenance_photos (request_id, file_path, caption, photo_type)
            VALUES (:rid, :path, :caption, :type)
        ");
        $stmt->execute([
            'rid' => $requestId,
            'path' => $result['filepath'],
            'caption' => $caption ?: null,
            'type' => $photoType,
        ]);

        jsonResponse(['success' => true, 'message' => 'Photo uploaded', 'id' => (int)$conn->lastInsertId()]);
        break;

    // ============================
    // DELETE PHOTO
    // ============================
    case 'delete_photo':
        if (!hasPermission('maintenance', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $photoId = (int)($_POST['photo_id'] ?? 0);
        if (!$photoId) {
            jsonResponse(['success' => false, 'message' => 'Photo ID required'], 400);
        }
        if (!canAccessUnit(apiMaintPhotoUnitId($conn, $photoId))) apiScopeDeny();

        $photoCheck = $conn->prepare("SELECT file_path FROM maintenance_photos WHERE id = :id");
        $photoCheck->execute(['id' => $photoId]);
        $photoData = $photoCheck->fetch();

        if ($photoData) {
            $fullPath = UPLOAD_PATH . $photoData['file_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
            $conn->prepare("DELETE FROM maintenance_photos WHERE id = :id")->execute(['id' => $photoId]);
        }

        jsonResponse(['success' => true, 'message' => 'Photo deleted']);
        break;

    // ============================
    // ASSIGN CONTRACTOR (12.2)
    // ============================
    case 'assign':
        if (!hasPermission('maintenance', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $requestId = (int)($_POST['request_id'] ?? 0);
        $contractorId = (int)($_POST['contractor_id'] ?? 0);
        $assignedDate = trim($_POST['assigned_date'] ?? date('Y-m-d'));
        $estimatedCompletion = trim($_POST['estimated_completion'] ?? '');
        $estimatedCost = (float)($_POST['estimated_cost'] ?? 0);
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $notes = trim($_POST['notes'] ?? '');

        if (!$requestId || !$contractorId) {
            jsonResponse(['success' => false, 'message' => 'Request ID and contractor are required'], 400);
        }
        if (!canAccessUnit(apiMaintReqUnitId($conn, $requestId))) apiScopeDeny();

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO maintenance_assignments (request_id, contractor_id, assigned_by,
                    assigned_date, estimated_completion, estimated_cost, currency, notes, status)
                VALUES (:rid, :cid, :assigned_by, :adate, :ecomp, :ecost, :currency, :notes, 'assigned')
            ");
            $stmt->execute([
                'rid' => $requestId,
                'cid' => $contractorId,
                'assigned_by' => getUserId(),
                'adate' => date('Y-m-d', strtotime($assignedDate)),
                'ecomp' => $estimatedCompletion ? date('Y-m-d', strtotime($estimatedCompletion)) : null,
                'ecost' => $estimatedCost > 0 ? $estimatedCost : null,
                'currency' => $currency,
                'notes' => $notes ?: null,
            ]);

            $assignmentId = (int) $conn->lastInsertId();

            // Update request status to assigned if still reported/acknowledged
            $conn->prepare("
                UPDATE maintenance_requests SET status = 'assigned'
                WHERE id = :id AND status IN ('reported', 'acknowledged')
            ")->execute(['id' => $requestId]);

            $conn->commit();

            logActivity('created', 'maintenance_assignment', $assignmentId, [
                'request_id' => $requestId,
                'contractor_id' => $contractorId,
            ]);

            // Notify contractor of assignment (Part 19)
            notifyAssignment($conn, $requestId, $contractorId);

            jsonResponse(['success' => true, 'message' => __('maint_contractor_assigned', 'Contractor assigned'), 'id' => $assignmentId]);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // UPDATE ASSIGNMENT (12.3)
    // ============================
    case 'update_assignment':
        if (!hasPermission('maintenance', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $assignId = (int)($_POST['assignment_id'] ?? 0);
        if (!$assignId) {
            jsonResponse(['success' => false, 'message' => 'Assignment ID required'], 400);
        }
        if (!canAccessUnit(apiMaintAssignUnitId($conn, $assignId))) apiScopeDeny();

        $status = trim($_POST['status'] ?? '');
        $actualCost = $_POST['actual_cost'] ?? null;
        $actualCompletion = trim($_POST['actual_completion'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $validStatuses = ['assigned', 'accepted', 'in_progress', 'completed'];

        $sets = [];
        $params = ['id' => $assignId];

        if (in_array($status, $validStatuses)) {
            $sets[] = "status = :status";
            $params['status'] = $status;
        }
        if ($actualCost !== null && $actualCost !== '') {
            $sets[] = "actual_cost = :actual_cost";
            $params['actual_cost'] = (float)$actualCost;
        }
        if ($actualCompletion !== '') {
            $sets[] = "actual_completion = :actual_completion";
            $params['actual_completion'] = date('Y-m-d', strtotime($actualCompletion));
        }
        if ($notes !== '') {
            $sets[] = "notes = :notes";
            $params['notes'] = $notes;
        }

        if (empty($sets)) {
            jsonResponse(['success' => false, 'message' => 'Nothing to update'], 400);
        }

        $sql = "UPDATE maintenance_assignments SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        // If assignment completed, update request status
        if ($status === 'completed') {
            $reqStmt = $conn->prepare("SELECT request_id FROM maintenance_assignments WHERE id = :id");
            $reqStmt->execute(['id' => $assignId]);
            $reqData = $reqStmt->fetch();
            if ($reqData) {
                $conn->prepare("
                    UPDATE maintenance_requests SET status = 'completed', completed_date = CURDATE()
                    WHERE id = :id AND status NOT IN ('completed', 'closed', 'cancelled')
                ")->execute(['id' => $reqData['request_id']]);
            }
        }

        // Update cost summary on request
        $reqStmt2 = $conn->prepare("SELECT request_id FROM maintenance_assignments WHERE id = :id");
        $reqStmt2->execute(['id' => $assignId]);
        $reqRow = $reqStmt2->fetch();
        if ($reqRow) updateRequestSummary($conn, $reqRow['request_id']);

        logActivity('updated', 'maintenance_assignment', $assignId, $params);

        jsonResponse(['success' => true, 'message' => __('maint_assignment_updated', 'Assignment updated')]);
        break;

    // ============================
    // DELETE ASSIGNMENT
    // ============================
    case 'delete_assignment':
        if (!hasPermission('maintenance', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $assignId = (int)($_POST['assignment_id'] ?? 0);
        if (!$assignId) {
            jsonResponse(['success' => false, 'message' => 'Assignment ID required'], 400);
        }
        if (!canAccessUnit(apiMaintAssignUnitId($conn, $assignId))) apiScopeDeny();

        $stmt = $conn->prepare("DELETE FROM maintenance_assignments WHERE id = :id");
        $stmt->execute(['id' => $assignId]);

        logActivity('deleted', 'maintenance_assignment', $assignId);

        jsonResponse(['success' => true, 'message' => 'Assignment deleted']);
        break;

    // ============================
    // ADD TIME LOG (12.3)
    // ============================
    case 'add_time_log':
        if (!hasPermission('maintenance', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $logDate = trim($_POST['log_date'] ?? date('Y-m-d'));
        $hoursWorked = (float)($_POST['hours_worked'] ?? 0);
        $description = trim($_POST['description'] ?? '');

        if (!$assignmentId || $hoursWorked <= 0) {
            jsonResponse(['success' => false, 'message' => 'Assignment ID and hours are required'], 400);
        }
        if (!canAccessUnit(apiMaintAssignUnitId($conn, $assignmentId))) apiScopeDeny();

        $stmt = $conn->prepare("
            INSERT INTO maintenance_time_logs (assignment_id, log_date, hours_worked, description, logged_by)
            VALUES (:aid, :log_date, :hours, :desc, :logged_by)
        ");
        $stmt->execute([
            'aid' => $assignmentId,
            'log_date' => date('Y-m-d', strtotime($logDate)),
            'hours' => $hoursWorked,
            'desc' => $description ?: null,
            'logged_by' => getUserId(),
        ]);

        jsonResponse(['success' => true, 'message' => 'Time log added', 'id' => (int)$conn->lastInsertId()]);
        break;

    // ============================
    // DELETE TIME LOG
    // ============================
    case 'delete_time_log':
        if (!hasPermission('maintenance', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $logId = (int)($_POST['log_id'] ?? 0);
        if (!$logId) {
            jsonResponse(['success' => false, 'message' => 'Log ID required'], 400);
        }
        if (!canAccessUnit(apiMaintLogUnitId($conn, $logId))) apiScopeDeny();

        $stmt = $conn->prepare("DELETE FROM maintenance_time_logs WHERE id = :id");
        $stmt->execute(['id' => $logId]);

        jsonResponse(['success' => true, 'message' => 'Time log deleted']);
        break;

    // ============================
    // CONTRACTORS CRUD (12.4)
    // ============================
    case 'contractors':
        if (!hasPermission('maintenance', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $statusFilter = trim($_GET['status'] ?? '');
        $search = trim($_GET['search'] ?? '');

        $where = [];
        $params = [];

        if ($statusFilter !== '') {
            $where[] = "c.status = :status";
            $params['status'] = $statusFilter;
        }
        if ($search !== '') {
            $where[] = "(c.name LIKE :search OR c.company_name LIKE :search2 OR c.specialty LIKE :search3 OR c.phone LIKE :search4)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
            $params['search4'] = "%{$search}%";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $conn->prepare("
            SELECT c.*,
                (SELECT COUNT(*) FROM maintenance_assignments ma WHERE ma.contractor_id = c.id) AS total_jobs,
                (SELECT COUNT(*) FROM maintenance_assignments ma WHERE ma.contractor_id = c.id AND ma.status = 'completed') AS completed_jobs
            FROM contractors c
            {$whereSql}
            ORDER BY c.name
        ");
        $stmt->execute($params);

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'get_contractor':
        if (!hasPermission('maintenance', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM contractors WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $contractor = $stmt->fetch();

        if (!$contractor) {
            jsonResponse(['success' => false, 'message' => 'Contractor not found'], 404);
        }

        jsonResponse(['success' => true, 'data' => $contractor]);
        break;

    case 'save_contractor':
        if (!hasPermission('maintenance', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $companyName = trim($_POST['company_name'] ?? '');
        $specialty = trim($_POST['specialty'] ?? '');
        $hourlyRate = (float)($_POST['hourly_rate'] ?? 0);
        $currency = trim($_POST['currency'] ?? DEFAULT_CURRENCY);
        $rating = (float)($_POST['rating'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $status = trim($_POST['status'] ?? 'active');

        if (!$name) {
            jsonResponse(['success' => false, 'message' => 'Contractor name is required'], 400);
        }

        if ($id) {
            $stmt = $conn->prepare("
                UPDATE contractors SET name = :name, phone = :phone, email = :email,
                    company_name = :company, specialty = :spec, hourly_rate = :rate,
                    currency = :currency, rating = :rating, notes = :notes, status = :status
                WHERE id = :id
            ");
            $stmt->execute([
                'name' => $name, 'phone' => $phone ?: null, 'email' => $email ?: null,
                'company' => $companyName ?: null, 'spec' => $specialty ?: null,
                'rate' => $hourlyRate > 0 ? $hourlyRate : null, 'currency' => $currency,
                'rating' => $rating > 0 ? min(5, $rating) : null, 'notes' => $notes ?: null,
                'status' => in_array($status, ['active', 'inactive']) ? $status : 'active',
                'id' => $id,
            ]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO contractors (name, phone, email, company_name, specialty,
                    hourly_rate, currency, rating, notes, status)
                VALUES (:name, :phone, :email, :company, :spec, :rate, :currency, :rating, :notes, :status)
            ");
            $stmt->execute([
                'name' => $name, 'phone' => $phone ?: null, 'email' => $email ?: null,
                'company' => $companyName ?: null, 'spec' => $specialty ?: null,
                'rate' => $hourlyRate > 0 ? $hourlyRate : null, 'currency' => $currency,
                'rating' => $rating > 0 ? min(5, $rating) : null, 'notes' => $notes ?: null,
                'status' => in_array($status, ['active', 'inactive']) ? $status : 'active',
            ]);
            $id = (int) $conn->lastInsertId();
        }

        logActivity($id ? 'updated' : 'created', 'contractor', $id, ['name' => $name]);

        jsonResponse(['success' => true, 'message' => 'Contractor saved', 'id' => $id]);
        break;

    case 'delete_contractor':
        if (!hasPermission('maintenance', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'ID required'], 400);
        }

        // Check for existing assignments
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM maintenance_assignments WHERE contractor_id = :id");
        $checkStmt->execute(['id' => $id]);
        if ((int)$checkStmt->fetchColumn() > 0) {
            jsonResponse(['success' => false, 'message' => 'Cannot delete contractor with existing assignments. Set to inactive instead.'], 400);
        }

        $stmt = $conn->prepare("DELETE FROM contractors WHERE id = :id");
        $stmt->execute(['id' => $id]);

        logActivity('deleted', 'contractor', $id);

        jsonResponse(['success' => true, 'message' => 'Contractor deleted']);
        break;

    // ============================
    // CATEGORIES
    // ============================
    case 'categories':
        $stmt = $conn->query("SELECT * FROM maintenance_categories ORDER BY name");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'save_category':
        if (!hasPermission('maintenance', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '');

        if (!$name) {
            jsonResponse(['success' => false, 'message' => 'Category name is required'], 400);
        }

        if ($id) {
            $stmt = $conn->prepare("UPDATE maintenance_categories SET name = :name, icon = :icon WHERE id = :id");
            $stmt->execute(['name' => $name, 'icon' => $icon ?: null, 'id' => $id]);
        } else {
            $stmt = $conn->prepare("INSERT INTO maintenance_categories (name, icon) VALUES (:name, :icon)");
            $stmt->execute(['name' => $name, 'icon' => $icon ?: null]);
            $id = (int) $conn->lastInsertId();
        }

        jsonResponse(['success' => true, 'message' => 'Category saved', 'id' => $id]);
        break;

    case 'delete_category':
        if (!hasPermission('maintenance', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'ID required'], 400);
        }

        $stmt = $conn->prepare("DELETE FROM maintenance_categories WHERE id = :id");
        $stmt->execute(['id' => $id]);

        jsonResponse(['success' => true, 'message' => 'Category deleted']);
        break;

    // ============================
    // PROPERTIES DROPDOWN
    // ============================
    case 'properties':
        $stmt = $conn->query("SELECT id, name FROM properties WHERE status = 'active'" . propertyScopeSql('id') . " ORDER BY name");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // UNITS BY PROPERTY
    // ============================
    case 'units_by_property':
        $propertyId = (int)($_GET['property_id'] ?? 0);

        $where = "u.status != 'vacant'";
        $params = [];

        if ($propertyId > 0) {
            $where .= " AND u.property_id = :pid";
            $params['pid'] = $propertyId;
        }

        // Property-based access scoping
        $where .= propertyScopeSql('u.property_id');

        $stmt = $conn->prepare("
            SELECT u.id, u.unit_number, u.unit_label, u.property_id, p.name AS property_name,
                   CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.id AS tenant_id
            FROM units u
            INNER JOIN properties p ON u.property_id = p.id
            LEFT JOIN leases l ON l.unit_id = u.id AND l.status = 'active'
            LEFT JOIN tenants t ON l.tenant_id = t.id
            WHERE {$where}
            ORDER BY p.name, u.unit_number
        ");
        $stmt->execute($params);

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // ALL UNITS (for create form)
    // ============================
    case 'all_units':
        $stmt = $conn->query("
            SELECT u.id, u.unit_number, u.unit_label, u.property_id, u.status,
                   p.name AS property_name,
                   CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.id AS tenant_id
            FROM units u
            INNER JOIN properties p ON u.property_id = p.id
            LEFT JOIN leases l ON l.unit_id = u.id AND l.status = 'active'
            LEFT JOIN tenants t ON l.tenant_id = t.id
            WHERE 1=1" . propertyScopeSql('u.property_id') . "
            ORDER BY p.name, u.unit_number
        ");

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // ACTIVE CONTRACTORS (for assignment dropdown)
    // ============================
    case 'active_contractors':
        $stmt = $conn->query("SELECT id, name, specialty, phone FROM contractors WHERE status = 'active' ORDER BY name");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // REPORT DATA (12.5)
    // ============================
    case 'stats':
        if (!hasPermission('maintenance', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $propertyId = (int)($_GET['property_id'] ?? 0);
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');

        $where = [];
        $params = [];

        if ($propertyId > 0) {
            $where[] = "u.property_id = :pid";
            $params['pid'] = $propertyId;
        }
        if ($dateFrom) {
            $where[] = "mr.created_at >= :date_from";
            $params['date_from'] = date('Y-m-d', strtotime($dateFrom));
        }
        if ($dateTo) {
            $where[] = "mr.created_at <= :date_to";
            $params['date_to'] = date('Y-m-d', strtotime($dateTo)) . ' 23:59:59';
        }

        // Property-based access scoping (all aggregate queries join units as alias u)
        $scopeFrag = propertyScopeSql('u.property_id');
        if ($scopeFrag !== '') {
            $where[] = substr($scopeFrag, 5); // strip leading " AND "
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $whereAssign = $where ? 'AND ' . implode(' AND ', $where) : '';

        // Total requests
        $totalStmt = $conn->prepare("
            SELECT COUNT(*) FROM maintenance_requests mr
            INNER JOIN units u ON mr.unit_id = u.id
            {$whereSql}
        ");
        $totalStmt->execute($params);
        $totalRequests = (int)$totalStmt->fetchColumn();

        // Open (not completed/closed/cancelled)
        $openStmt = $conn->prepare("
            SELECT COUNT(*) FROM maintenance_requests mr
            INNER JOIN units u ON mr.unit_id = u.id
            {$whereSql} " . ($where ? "AND" : "WHERE") . " mr.status NOT IN ('completed', 'closed', 'cancelled')
        ");
        $openStmt->execute($params);
        $openRequests = (int)$openStmt->fetchColumn();

        // Completed
        $completedStmt = $conn->prepare("
            SELECT COUNT(*) FROM maintenance_requests mr
            INNER JOIN units u ON mr.unit_id = u.id
            {$whereSql} " . ($where ? "AND" : "WHERE") . " mr.status IN ('completed', 'closed')
        ");
        $completedStmt->execute($params);
        $completedRequests = (int)$completedStmt->fetchColumn();

        // Avg response time (days from created to first assignment)
        $avgRespStmt = $conn->prepare("
            SELECT AVG(DATEDIFF(ma.assigned_date, mr.created_at)) AS avg_days
            FROM maintenance_requests mr
            INNER JOIN units u ON mr.unit_id = u.id
            INNER JOIN maintenance_assignments ma ON ma.request_id = mr.id
            {$whereSql}
        ");
        $avgRespStmt->execute($params);
        $avgResponse = round((float)$avgRespStmt->fetchColumn(), 1);

        // Total cost
        $costStmt = $conn->prepare("
            SELECT COALESCE(SUM(ma.actual_cost), 0) AS total_cost
            FROM maintenance_assignments ma
            INNER JOIN maintenance_requests mr ON ma.request_id = mr.id
            INNER JOIN units u ON mr.unit_id = u.id
            {$whereSql}
        ");
        $costStmt->execute($params);
        $totalCost = (float)$costStmt->fetchColumn();

        // By category
        $catStmt = $conn->prepare("
            SELECT mc.name, COUNT(*) AS count
            FROM maintenance_requests mr
            INNER JOIN units u ON mr.unit_id = u.id
            LEFT JOIN maintenance_categories mc ON mr.category_id = mc.id
            {$whereSql}
            GROUP BY mc.id, mc.name
            ORDER BY count DESC
        ");
        $catStmt->execute($params);
        $byCategory = $catStmt->fetchAll();

        // By priority
        $priStmt = $conn->prepare("
            SELECT mr.priority, COUNT(*) AS count
            FROM maintenance_requests mr
            INNER JOIN units u ON mr.unit_id = u.id
            {$whereSql}
            GROUP BY mr.priority
            ORDER BY FIELD(mr.priority, 'urgent', 'high', 'medium', 'low')
        ");
        $priStmt->execute($params);
        $byPriority = $priStmt->fetchAll();

        // By status
        $statusStmt = $conn->prepare("
            SELECT mr.status, COUNT(*) AS count
            FROM maintenance_requests mr
            INNER JOIN units u ON mr.unit_id = u.id
            {$whereSql}
            GROUP BY mr.status
        ");
        $statusStmt->execute($params);
        $byStatus = $statusStmt->fetchAll();

        // Monthly trend (last 6 months)
        $trendStmt = $conn->prepare("
            SELECT DATE_FORMAT(mr.created_at, '%Y-%m') AS month, COUNT(*) AS count
            FROM maintenance_requests mr
            INNER JOIN units u ON mr.unit_id = u.id
            WHERE mr.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            " . ($propertyId > 0 ? "AND u.property_id = :pid2" : "") . propertyScopeSql('u.property_id') . "
            GROUP BY month
            ORDER BY month
        ");
        $trendParams = [];
        if ($propertyId > 0) $trendParams['pid2'] = $propertyId;
        $trendStmt->execute($trendParams);
        $monthlyTrend = $trendStmt->fetchAll();

        // Top contractors
        $topStmt = $conn->prepare("
            SELECT c.name, COUNT(ma.id) AS job_count,
                   COALESCE(SUM(ma.actual_cost), 0) AS total_cost,
                   c.rating
            FROM maintenance_assignments ma
            INNER JOIN contractors c ON ma.contractor_id = c.id
            INNER JOIN maintenance_requests mr ON ma.request_id = mr.id
            INNER JOIN units u ON mr.unit_id = u.id
            {$whereSql}
            GROUP BY c.id, c.name, c.rating
            ORDER BY job_count DESC
            LIMIT 10
        ");
        $topStmt->execute($params);
        $topContractors = $topStmt->fetchAll();

        jsonResponse([
            'success' => true,
            'total_requests' => $totalRequests,
            'open_requests' => $openRequests,
            'completed_requests' => $completedRequests,
            'avg_response_days' => $avgResponse,
            'total_cost' => $totalCost,
            'by_category' => $byCategory,
            'by_priority' => $byPriority,
            'by_status' => $byStatus,
            'monthly_trend' => $monthlyTrend,
            'top_contractors' => $topContractors,
        ]);
        break;

    // ============================
    // ACTIVITY LOG
    // ============================
    case 'activity':
        if (!hasPermission('maintenance', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'ID required'], 400);
        }
        if (!canAccessUnit(apiMaintReqUnitId($conn, $id))) apiScopeDeny();

        $stmt = $conn->prepare("
            SELECT al.*, u.full_name AS user_name
            FROM activity_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE (al.entity_type = 'maintenance_request' AND al.entity_id = :id)
               OR (al.entity_type = 'maintenance_assignment' AND al.entity_id IN (
                    SELECT ma.id FROM maintenance_assignments ma WHERE ma.request_id = :id2
               ))
            ORDER BY al.created_at DESC
            LIMIT 20
        ");
        $stmt->execute(['id' => $id, 'id2' => $id]);

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // EXPORT COST REPORT (CSV)
    // ============================
    case 'export_costs':
        if (!hasPermission('maintenance', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $filterProperty = (int)($_GET['property_id'] ?? 0);
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');

        $where = [];
        $params = [];
        if ($filterProperty > 0) {
            $where[] = "u.property_id = :pid";
            $params['pid'] = $filterProperty;
        }
        if ($dateFrom) {
            $where[] = "mr.created_at >= :df";
            $params['df'] = $dateFrom;
        }
        if ($dateTo) {
            $where[] = "mr.created_at <= :dt";
            $params['dt'] = $dateTo . ' 23:59:59';
        }

        // Property-based access scoping (joins units as alias u)
        $scopeFrag = propertyScopeSql('u.property_id');
        if ($scopeFrag !== '') {
            $where[] = substr($scopeFrag, 5); // strip leading " AND "
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT mr.id, mr.title, mr.priority, mr.status, mr.created_at, mr.completed_date,
                       u.unit_number, p.name AS property_name,
                       mc.name AS category_name,
                       CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                       COALESCE(SUM(ma.actual_cost), 0) AS total_cost,
                       COALESCE(SUM(tl_agg.total_hours), 0) AS total_hours,
                       GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') AS contractors
                FROM maintenance_requests mr
                INNER JOIN units u ON mr.unit_id = u.id
                INNER JOIN properties p ON u.property_id = p.id
                LEFT JOIN tenants t ON mr.tenant_id = t.id
                LEFT JOIN maintenance_categories mc ON mr.category_id = mc.id
                LEFT JOIN maintenance_assignments ma ON ma.request_id = mr.id
                LEFT JOIN contractors c ON ma.contractor_id = c.id
                LEFT JOIN (
                    SELECT assignment_id, SUM(hours_worked) AS total_hours
                    FROM maintenance_time_logs GROUP BY assignment_id
                ) tl_agg ON tl_agg.assignment_id = ma.id
                {$whereSql}
                GROUP BY mr.id
                ORDER BY mr.created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="maintenance_costs_' . date('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Title', 'Property', 'Unit', 'Category', 'Tenant', 'Priority', 'Status', 'Contractors', 'Total Cost', 'Total Hours', 'Created', 'Completed']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['title'], $r['property_name'], $r['unit_number'],
                $r['category_name'] ?? '', $r['tenant_name'] ?? '', $r['priority'],
                $r['status'], $r['contractors'] ?? '', number_format($r['total_cost'], 2),
                number_format($r['total_hours'], 1), $r['created_at'], $r['completed_date'] ?? ''
            ]);
        }
        fclose($out);
        exit;

    // ============================
    // EXPORT CONTRACTOR REPORT (CSV)
    // ============================
    case 'export_contractors':
        if (!hasPermission('maintenance', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("
            SELECT c.name, c.company_name, c.phone, c.email, c.specialty,
                   c.hourly_rate, c.currency, c.rating, c.status,
                   COUNT(ma.id) AS total_jobs,
                   SUM(CASE WHEN ma.status = 'completed' THEN 1 ELSE 0 END) AS completed_jobs,
                   COALESCE(SUM(ma.actual_cost), 0) AS total_cost
            FROM contractors c
            LEFT JOIN maintenance_assignments ma ON ma.contractor_id = c.id
            GROUP BY c.id
            ORDER BY c.name
        ");
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="contractors_' . date('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Name', 'Company', 'Phone', 'Email', 'Specialty', 'Hourly Rate', 'Currency', 'Rating', 'Status', 'Total Jobs', 'Completed', 'Total Cost']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['name'], $r['company_name'] ?? '', $r['phone'] ?? '', $r['email'] ?? '',
                $r['specialty'] ?? '', $r['hourly_rate'] ?? '', $r['currency'] ?? '',
                $r['rating'] ?? '', $r['status'], $r['total_jobs'], $r['completed_jobs'],
                number_format($r['total_cost'], 2)
            ]);
        }
        fclose($out);
        exit;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}

// ============================================================
// HELPER: Update request cost/hours summary
// ============================================================
function updateRequestSummary($conn, $requestId) {
    try {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(ma.actual_cost), 0) AS total_cost,
                   COALESCE(SUM(tl_sum.hours), 0) AS total_hours
            FROM maintenance_assignments ma
            LEFT JOIN (
                SELECT assignment_id, SUM(hours_worked) AS hours FROM maintenance_time_logs GROUP BY assignment_id
            ) tl_sum ON tl_sum.assignment_id = ma.id
            WHERE ma.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $row = $stmt->fetch();

        $conn->prepare("UPDATE maintenance_requests SET total_cost = ?, total_hours = ? WHERE id = ?")
            ->execute([$row['total_cost'], $row['total_hours'], $requestId]);
    } catch (PDOException $e) {
        error_log("updateRequestSummary error: " . $e->getMessage());
    }
}

// ============================================================
// HELPER: Create maintenance notification
// ============================================================
function createMaintenanceNotification($conn, $requestId, $recipientType, $recipientId, $recipientName, $eventType, $message) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO maintenance_notifications (request_id, recipient_type, recipient_id, recipient_name, channel, event_type, message, status)
            VALUES (?, ?, ?, ?, 'system', ?, ?, 'sent')
        ");
        $stmt->execute([$requestId, $recipientType, $recipientId, $recipientName, $eventType, $message]);

        // Also create a system notification for staff
        if ($recipientType === 'staff' && $recipientId) {
            $conn->prepare("
                INSERT INTO notifications (user_id, user_type, title, message, link, is_read)
                VALUES (?, 'staff', ?, ?, ?, 0)
            ")->execute([
                $recipientId,
                __('maint_notification', 'Maintenance Update'),
                $message,
                'modules/maintenance/view.php?id=' . $requestId
            ]);
        }
    } catch (PDOException $e) {
        error_log("Maintenance notification error: " . $e->getMessage());
    }
}

// ============================================================
// HELPER: Notify stakeholders on status change
// ============================================================
function notifyStatusChange($conn, $requestId, $oldStatus, $newStatus, $notes = '') {
    if (getSetting('maintenance_notify_on_status', '1') !== '1') return;

    // Get request details
    $stmt = $conn->prepare("
        SELECT mr.*, u.unit_number, p.name AS property_name,
               CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.id AS tenant_id_val,
               rep.full_name AS reporter_name, mr.reported_by_id
        FROM maintenance_requests mr
        INNER JOIN units u ON mr.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        LEFT JOIN tenants t ON mr.tenant_id = t.id
        LEFT JOIN users rep ON mr.reported_by_type = 'staff' AND mr.reported_by_id = rep.id
        WHERE mr.id = ?
    ");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
    if (!$req) return;

    $statusLabel = ucfirst(str_replace('_', ' ', $newStatus));
    $msg = sprintf(
        __('maint_status_notification', 'Maintenance #%d "%s" status changed to %s.'),
        $requestId, $req['title'], $statusLabel
    );
    if ($notes) $msg .= ' ' . __('notes') . ': ' . $notes;

    // Notify the reporter (if staff)
    if ($req['reported_by_type'] === 'staff' && $req['reported_by_id'] && $req['reported_by_id'] != getUserId()) {
        createMaintenanceNotification($conn, $requestId, 'staff', $req['reported_by_id'], $req['reporter_name'], 'status_change', $msg);
    }

    // Notify assigned contractors (as system log entry)
    $assignStmt = $conn->prepare("
        SELECT DISTINCT c.id, c.name FROM maintenance_assignments ma
        INNER JOIN contractors c ON ma.contractor_id = c.id
        WHERE ma.request_id = ? AND ma.status NOT IN ('completed')
    ");
    $assignStmt->execute([$requestId]);
    while ($contractor = $assignStmt->fetch()) {
        createMaintenanceNotification($conn, $requestId, 'contractor', $contractor['id'], $contractor['name'], 'status_change', $msg);
    }

    // Notify admin users
    $adminStmt = $conn->query("SELECT u.id, u.full_name FROM users u INNER JOIN roles r ON u.role_id = r.id WHERE r.name = 'Admin' AND u.status = 'active'");
    while ($admin = $adminStmt->fetch()) {
        if ($admin['id'] != getUserId()) {
            createMaintenanceNotification($conn, $requestId, 'staff', $admin['id'], $admin['full_name'], 'status_change', $msg);
        }
    }
}

// ============================================================
// HELPER: Notify on new assignment
// ============================================================
function notifyAssignment($conn, $requestId, $contractorId) {
    if (getSetting('maintenance_notify_on_assign', '1') !== '1') return;

    $stmt = $conn->prepare("
        SELECT mr.title, c.name AS contractor_name, u.unit_number, p.name AS property_name
        FROM maintenance_requests mr
        INNER JOIN units u ON mr.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id,
        contractors c
        WHERE mr.id = ? AND c.id = ?
    ");
    $stmt->execute([$requestId, $contractorId]);
    $data = $stmt->fetch();
    if (!$data) return;

    $msg = sprintf(
        __('maint_assign_notification', 'Contractor %s assigned to maintenance #%d "%s" at %s - %s.'),
        $data['contractor_name'], $requestId, $data['title'], $data['property_name'], $data['unit_number']
    );

    createMaintenanceNotification($conn, $requestId, 'contractor', $contractorId, $data['contractor_name'], 'assignment', $msg);
}
