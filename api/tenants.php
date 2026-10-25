<?php
/**
 * FIS Real Estate — Tenants API
 * Handles CRUD operations for tenants.
 * Used by AJAX calls from the tenants module pages.
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
    // LIST TENANTS (with filters, search, pagination)
    // ============================
    case 'list':
        if (!hasPermission('tenants', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $idType = trim($_GET['id_type'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int)($_GET['per_page'] ?? RECORDS_PER_PAGE)));

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(t.first_name LIKE :search OR t.last_name LIKE :search2 OR t.phone LIKE :search3 OR t.email LIKE :search4 OR t.id_number LIKE :search5 OR CONCAT(t.first_name, ' ', t.last_name) LIKE :search6)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
            $params['search4'] = "%{$search}%";
            $params['search5'] = "%{$search}%";
            $params['search6'] = "%{$search}%";
        }
        if ($status !== '') {
            $where[] = "t.status = :status";
            $params['status'] = $status;
        }
        if ($idType !== '') {
            $where[] = "t.id_type = :id_type";
            $params['id_type'] = $idType;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Property-based access scoping
        $scopeSql = tenantScopeSql('t.id');
        if ($scopeSql !== '') {
            $whereSql = ($whereSql === '' ? 'WHERE 1=1' : $whereSql) . $scopeSql;
        }

        // Count total
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM tenants t {$whereSql}");
        $countStmt->execute($params);
        $totalRecords = (int) $countStmt->fetchColumn();

        $pagination = paginate($totalRecords, $page, $perPage);

        // Fetch records — include active lease info via subquery
        $sql = "SELECT t.*,
                (SELECT CONCAT(u.unit_number, ' - ', p.name)
                 FROM leases l
                 INNER JOIN units u ON l.unit_id = u.id
                 INNER JOIN properties p ON u.property_id = p.id
                 WHERE l.tenant_id = t.id AND l.status = 'active'
                 ORDER BY l.created_at DESC LIMIT 1
                ) AS current_unit
                FROM tenants t
                {$whereSql}
                ORDER BY t.created_at DESC
                LIMIT :offset, :limit";

        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":{$k}", $v);
        }
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $tenants = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'data' => $tenants,
            'pagination' => $pagination
        ]);
        break;

    // ============================
    // GET SINGLE TENANT
    // ============================
    case 'get':
        if (!hasPermission('tenants', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Tenant ID required'], 400);
        }

        $stmt = $conn->prepare("SELECT * FROM tenants WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            jsonResponse(['success' => false, 'message' => 'Tenant not found'], 404);
        }

        if (!canAccessTenant($id)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        jsonResponse(['success' => true, 'data' => $tenant]);
        break;

    // ============================
    // CREATE TENANT
    // ============================
    case 'create':
        if (!hasPermission('tenants', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $idType = trim($_POST['id_type'] ?? '');
        $idNumber = trim($_POST['id_number'] ?? '');
        $occupation = trim($_POST['occupation'] ?? '');
        $employer = trim($_POST['employer'] ?? '');
        $employerPhone = trim($_POST['employer_phone'] ?? '');
        $emergencyName = trim($_POST['emergency_contact_name'] ?? '');
        $emergencyPhone = trim($_POST['emergency_contact_phone'] ?? '');
        $emergencyRelation = trim($_POST['emergency_contact_relation'] ?? '');
        $tinCheck = normalizeTin($_POST['tin'] ?? '');
        $tin = $tinCheck['value'];
        $nokName = trim($_POST['next_of_kin_name'] ?? '');
        $nokRelationship = trim($_POST['next_of_kin_relationship'] ?? '');
        $nokPhone = trim($_POST['next_of_kin_phone'] ?? '');
        $nokAddress = trim($_POST['next_of_kin_address'] ?? '');
        $ref1Name = trim($_POST['reference1_name'] ?? '');
        $ref1Phone = trim($_POST['reference1_phone'] ?? '');
        $ref2Name = trim($_POST['reference2_name'] ?? '');
        $ref2Phone = trim($_POST['reference2_phone'] ?? '');
        $screeningNotes = trim($_POST['screening_notes'] ?? '');
        $status = trim($_POST['status'] ?? 'active');
        $moveInDate = trim($_POST['move_in_date'] ?? '');

        $validStatuses = ['active', 'notice_given', 'moved_out'];
        $validIdTypes = ['national_id', 'passport', 'driving_license', 'voter_id', ''];

        // Validate
        $errors = [];
        if ($firstName === '') $errors[] = 'First name is required';
        if ($lastName === '') $errors[] = 'Last name is required';
        if ($phone === '') $errors[] = 'Phone number is required';
        if (!in_array($status, $validStatuses)) $errors[] = 'Invalid status';
        if (!in_array($idType, $validIdTypes)) $errors[] = 'Invalid ID type';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address';

        if ($errors) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors], 422);
        }

        try {
            $stmt = $conn->prepare("
                INSERT INTO tenants (first_name, last_name, phone, email, id_type, id_number,
                    occupation, employer, employer_phone,
                    emergency_contact_name, emergency_contact_phone, emergency_contact_relation,
                    tin, next_of_kin_name, next_of_kin_relationship, next_of_kin_phone, next_of_kin_address,
                    reference1_name, reference1_phone, reference2_name, reference2_phone,
                    screening_notes, status, move_in_date)
                VALUES (:first_name, :last_name, :phone, :email, :id_type, :id_number,
                    :occupation, :employer, :employer_phone,
                    :emergency_contact_name, :emergency_contact_phone, :emergency_contact_relation,
                    :tin, :next_of_kin_name, :next_of_kin_relationship, :next_of_kin_phone, :next_of_kin_address,
                    :reference1_name, :reference1_phone, :reference2_name, :reference2_phone,
                    :screening_notes, :status, :move_in_date)
            ");
            $stmt->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'email' => $email ?: null,
                'id_type' => $idType ?: null,
                'id_number' => $idNumber ?: null,
                'occupation' => $occupation ?: null,
                'employer' => $employer ?: null,
                'employer_phone' => $employerPhone ?: null,
                'emergency_contact_name' => $emergencyName ?: null,
                'emergency_contact_phone' => $emergencyPhone ?: null,
                'emergency_contact_relation' => $emergencyRelation ?: null,
                'tin' => $tin ?: null,
                'next_of_kin_name' => $nokName ?: null,
                'next_of_kin_relationship' => $nokRelationship ?: null,
                'next_of_kin_phone' => $nokPhone ?: null,
                'next_of_kin_address' => $nokAddress ?: null,
                'reference1_name' => $ref1Name ?: null,
                'reference1_phone' => $ref1Phone ?: null,
                'reference2_name' => $ref2Name ?: null,
                'reference2_phone' => $ref2Phone ?: null,
                'screening_notes' => $screeningNotes ?: null,
                'status' => $status,
                'move_in_date' => $moveInDate ? date('Y-m-d', strtotime($moveInDate)) : null,
            ]);

            $tenantId = (int) $conn->lastInsertId();

            logActivity('created', 'tenant', $tenantId, ['name' => $firstName . ' ' . $lastName, 'phone' => $phone]);

            jsonResponse(['success' => true, 'message' => 'Tenant created successfully', 'id' => $tenantId]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to create tenant: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // UPDATE TENANT
    // ============================
    case 'update':
        if (!hasPermission('tenants', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Tenant ID required'], 400);
        }

        $existing = $conn->prepare("SELECT * FROM tenants WHERE id = :id");
        $existing->execute(['id' => $id]);
        $old = $existing->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'message' => 'Tenant not found'], 404);
        }

        if (!canAccessTenant($id)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $idType = trim($_POST['id_type'] ?? '');
        $idNumber = trim($_POST['id_number'] ?? '');
        $occupation = trim($_POST['occupation'] ?? '');
        $employer = trim($_POST['employer'] ?? '');
        $employerPhone = trim($_POST['employer_phone'] ?? '');
        $emergencyName = trim($_POST['emergency_contact_name'] ?? '');
        $emergencyPhone = trim($_POST['emergency_contact_phone'] ?? '');
        $emergencyRelation = trim($_POST['emergency_contact_relation'] ?? '');
        $tinCheck = normalizeTin($_POST['tin'] ?? '');
        $tin = $tinCheck['value'];
        $nokName = trim($_POST['next_of_kin_name'] ?? '');
        $nokRelationship = trim($_POST['next_of_kin_relationship'] ?? '');
        $nokPhone = trim($_POST['next_of_kin_phone'] ?? '');
        $nokAddress = trim($_POST['next_of_kin_address'] ?? '');
        $ref1Name = trim($_POST['reference1_name'] ?? '');
        $ref1Phone = trim($_POST['reference1_phone'] ?? '');
        $ref2Name = trim($_POST['reference2_name'] ?? '');
        $ref2Phone = trim($_POST['reference2_phone'] ?? '');
        $screeningNotes = trim($_POST['screening_notes'] ?? '');
        $status = trim($_POST['status'] ?? 'active');
        $moveInDate = trim($_POST['move_in_date'] ?? '');
        $moveOutDate = trim($_POST['move_out_date'] ?? '');

        $validStatuses = ['active', 'notice_given', 'moved_out'];
        $validIdTypes = ['national_id', 'passport', 'driving_license', 'voter_id', ''];

        $errors = [];
        if ($firstName === '') $errors[] = 'First name is required';
        if ($lastName === '') $errors[] = 'Last name is required';
        if ($phone === '') $errors[] = 'Phone number is required';
        if (!in_array($status, $validStatuses)) $errors[] = 'Invalid status';
        if (!in_array($idType, $validIdTypes)) $errors[] = 'Invalid ID type';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address';

        if ($errors) {
            jsonResponse(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors], 422);
        }

        try {
            $stmt = $conn->prepare("
                UPDATE tenants SET
                    first_name = :first_name, last_name = :last_name, phone = :phone, email = :email,
                    id_type = :id_type, id_number = :id_number,
                    occupation = :occupation, employer = :employer, employer_phone = :employer_phone,
                    emergency_contact_name = :emergency_contact_name,
                    emergency_contact_phone = :emergency_contact_phone,
                    emergency_contact_relation = :emergency_contact_relation,
                    tin = :tin,
                    next_of_kin_name = :next_of_kin_name,
                    next_of_kin_relationship = :next_of_kin_relationship,
                    next_of_kin_phone = :next_of_kin_phone,
                    next_of_kin_address = :next_of_kin_address,
                    reference1_name = :reference1_name, reference1_phone = :reference1_phone,
                    reference2_name = :reference2_name, reference2_phone = :reference2_phone,
                    screening_notes = :screening_notes, status = :status,
                    move_in_date = :move_in_date, move_out_date = :move_out_date
                WHERE id = :id
            ");
            $stmt->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'email' => $email ?: null,
                'id_type' => $idType ?: null,
                'id_number' => $idNumber ?: null,
                'occupation' => $occupation ?: null,
                'employer' => $employer ?: null,
                'employer_phone' => $employerPhone ?: null,
                'emergency_contact_name' => $emergencyName ?: null,
                'emergency_contact_phone' => $emergencyPhone ?: null,
                'emergency_contact_relation' => $emergencyRelation ?: null,
                'tin' => $tin ?: null,
                'next_of_kin_name' => $nokName ?: null,
                'next_of_kin_relationship' => $nokRelationship ?: null,
                'next_of_kin_phone' => $nokPhone ?: null,
                'next_of_kin_address' => $nokAddress ?: null,
                'reference1_name' => $ref1Name ?: null,
                'reference1_phone' => $ref1Phone ?: null,
                'reference2_name' => $ref2Name ?: null,
                'reference2_phone' => $ref2Phone ?: null,
                'screening_notes' => $screeningNotes ?: null,
                'status' => $status,
                'move_in_date' => $moveInDate ? date('Y-m-d', strtotime($moveInDate)) : null,
                'move_out_date' => $moveOutDate ? date('Y-m-d', strtotime($moveOutDate)) : null,
                'id' => $id
            ]);

            // Log status change separately if changed
            if ($old['status'] !== $status) {
                logActivity('updated', 'tenant', $id, [
                    'status_change' => $old['status'] . ' -> ' . $status,
                    'name' => $firstName . ' ' . $lastName
                ]);
            } else {
                logActivity('updated', 'tenant', $id, ['name' => $firstName . ' ' . $lastName]);
            }

            jsonResponse(['success' => true, 'message' => 'Tenant updated successfully']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to update tenant: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // DELETE TENANT
    // ============================
    case 'delete':
        if (!hasPermission('tenants', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Tenant ID required'], 400);
        }

        if (!canAccessTenant($id)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        // Check if tenant has active leases
        $leaseCheck = $conn->prepare("SELECT COUNT(*) FROM leases WHERE tenant_id = :id AND status = 'active'");
        $leaseCheck->execute(['id' => $id]);
        $activeLeases = (int) $leaseCheck->fetchColumn();

        if ($activeLeases > 0) {
            jsonResponse(['success' => false, 'message' => "Cannot delete: tenant has {$activeLeases} active lease(s). Terminate leases first."], 422);
        }

        try {
            // Delete ID copy from disk if exists
            $tenantStmt = $conn->prepare("SELECT id_copy_path FROM tenants WHERE id = :id");
            $tenantStmt->execute(['id' => $id]);
            $tenantData = $tenantStmt->fetch();

            if ($tenantData && $tenantData['id_copy_path']) {
                $filePath = UPLOAD_PATH . $tenantData['id_copy_path'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            $stmt = $conn->prepare("DELETE FROM tenants WHERE id = :id");
            $stmt->execute(['id' => $id]);

            if ($stmt->rowCount() === 0) {
                jsonResponse(['success' => false, 'message' => 'Tenant not found'], 404);
            }

            logActivity('deleted', 'tenant', $id);

            jsonResponse(['success' => true, 'message' => 'Tenant deleted successfully']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to delete tenant: ' . $e->getMessage()], 500);
        }
        break;

    // ============================
    // CHANGE STATUS (with move_out_date handling)
    // ============================
    case 'change_status':
        if (!hasPermission('tenants', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $id = (int)($_POST['id'] ?? 0);
        $newStatus = trim($_POST['new_status'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $moveOutDate = trim($_POST['move_out_date'] ?? '');

        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Tenant ID required'], 400);
        }

        $validStatuses = ['active', 'notice_given', 'moved_out'];
        if (!in_array($newStatus, $validStatuses)) {
            jsonResponse(['success' => false, 'message' => 'Invalid status'], 400);
        }

        $existing = $conn->prepare("SELECT * FROM tenants WHERE id = :id");
        $existing->execute(['id' => $id]);
        $tenant = $existing->fetch();
        if (!$tenant) {
            jsonResponse(['success' => false, 'message' => 'Tenant not found'], 404);
        }

        if (!canAccessTenant($id)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        $oldStatus = $tenant['status'];

        // Build update SQL based on status
        if ($newStatus === 'moved_out') {
            $moveOut = $moveOutDate ? date('Y-m-d', strtotime($moveOutDate)) : date('Y-m-d');
            $conn->prepare("UPDATE tenants SET status = :status, move_out_date = :move_out_date WHERE id = :id")
                ->execute(['status' => $newStatus, 'move_out_date' => $moveOut, 'id' => $id]);
        } else {
            $conn->prepare("UPDATE tenants SET status = :status WHERE id = :id")
                ->execute(['status' => $newStatus, 'id' => $id]);
        }

        logActivity('updated', 'tenant', $id, [
            'status_change' => $oldStatus . ' -> ' . $newStatus,
            'reason' => $reason,
            'name' => $tenant['first_name'] . ' ' . $tenant['last_name']
        ]);

        jsonResponse(['success' => true, 'message' => "Status changed from {$oldStatus} to {$newStatus}"]);
        break;

    // ============================
    // BULK STATUS CHANGE
    // ============================
    case 'bulk_status':
        if (!hasPermission('tenants', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $ids = $_POST['ids'] ?? [];
        $newStatus = trim($_POST['new_status'] ?? '');

        if (empty($ids) || !is_array($ids)) {
            jsonResponse(['success' => false, 'message' => 'No tenants selected'], 400);
        }

        $validStatuses = ['active', 'notice_given', 'moved_out'];
        if (!in_array($newStatus, $validStatuses)) {
            jsonResponse(['success' => false, 'message' => 'Invalid status'], 400);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$newStatus], array_map('intval', $ids));

        if ($newStatus === 'moved_out') {
            $stmt = $conn->prepare("UPDATE tenants SET status = ?, move_out_date = CURDATE() WHERE id IN ({$placeholders})");
        } else {
            $stmt = $conn->prepare("UPDATE tenants SET status = ? WHERE id IN ({$placeholders})");
        }
        $stmt->execute($params);

        $count = $stmt->rowCount();
        logActivity('updated', 'tenant', null, ['bulk_status_change' => $newStatus, 'count' => $count]);

        jsonResponse(['success' => true, 'message' => "{$count} tenant(s) updated to {$newStatus}"]);
        break;

    // ============================
    // UPLOAD ID COPY
    // ============================
    case 'upload_id_copy':
        if (!hasPermission('tenants', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        if (!$tenantId) {
            jsonResponse(['success' => false, 'message' => 'Tenant ID required'], 400);
        }

        // Verify tenant exists
        $check = $conn->prepare("SELECT id, id_copy_path FROM tenants WHERE id = :id");
        $check->execute(['id' => $tenantId]);
        $tenantData = $check->fetch();
        if (!$tenantData) {
            jsonResponse(['success' => false, 'message' => 'Tenant not found'], 404);
        }

        if (empty($_FILES['id_copy'])) {
            jsonResponse(['success' => false, 'message' => 'No file uploaded'], 400);
        }

        $allowedTypes = array_merge(ALLOWED_IMAGE_TYPES, ['pdf']);
        $result = uploadFile($_FILES['id_copy'], "tenants/{$tenantId}/id", $allowedTypes);

        if ($result['success']) {
            // Delete old file if exists
            if ($tenantData['id_copy_path']) {
                $oldFile = UPLOAD_PATH . $tenantData['id_copy_path'];
                if (file_exists($oldFile)) {
                    unlink($oldFile);
                }
            }

            $conn->prepare("UPDATE tenants SET id_copy_path = :path WHERE id = :id")
                ->execute(['path' => $result['filepath'], 'id' => $tenantId]);

            logActivity('updated', 'tenant', $tenantId, ['id_copy_uploaded' => true]);

            jsonResponse([
                'success' => true,
                'message' => 'ID copy uploaded successfully',
                'file_path' => $result['filepath']
            ]);
        } else {
            jsonResponse(['success' => false, 'message' => $result['message']], 400);
        }
        break;

    // ============================
    // DELETE ID COPY
    // ============================
    case 'delete_id_copy':
        if (!hasPermission('tenants', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        checkCSRF();

        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        if (!$tenantId) {
            jsonResponse(['success' => false, 'message' => 'Tenant ID required'], 400);
        }

        $check = $conn->prepare("SELECT id, id_copy_path FROM tenants WHERE id = :id");
        $check->execute(['id' => $tenantId]);
        $tenantData = $check->fetch();
        if (!$tenantData) {
            jsonResponse(['success' => false, 'message' => 'Tenant not found'], 404);
        }

        if ($tenantData['id_copy_path']) {
            $filePath = UPLOAD_PATH . $tenantData['id_copy_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $conn->prepare("UPDATE tenants SET id_copy_path = NULL WHERE id = :id")
                ->execute(['id' => $tenantId]);
        }

        jsonResponse(['success' => true, 'message' => 'ID copy removed']);
        break;

    // ============================
    // TENANT HISTORY (leases, payments, inspections)
    // ============================
    case 'history':
        if (!hasPermission('tenants', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $tenantId = (int)($_GET['tenant_id'] ?? 0);
        $historyType = trim($_GET['type'] ?? 'leases');

        if (!$tenantId) {
            jsonResponse(['success' => false, 'message' => 'Tenant ID required'], 400);
        }

        if (!canAccessTenant($tenantId)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        $data = [];

        switch ($historyType) {
            case 'leases':
                $stmt = $conn->prepare("
                    SELECT l.*, u.unit_number, p.name AS property_name
                    FROM leases l
                    INNER JOIN units u ON l.unit_id = u.id
                    INNER JOIN properties p ON u.property_id = p.id
                    WHERE l.tenant_id = :tid
                    ORDER BY l.created_at DESC
                    LIMIT 50
                ");
                $stmt->execute(['tid' => $tenantId]);
                $data = $stmt->fetchAll();
                break;

            case 'payments':
                $stmt = $conn->prepare("
                    SELECT py.*, u.unit_number, p.name AS property_name
                    FROM payments py
                    INNER JOIN units u ON py.unit_id = u.id
                    INNER JOIN properties p ON u.property_id = p.id
                    WHERE py.tenant_id = :tid
                    ORDER BY py.payment_date DESC
                    LIMIT 50
                ");
                $stmt->execute(['tid' => $tenantId]);
                $data = $stmt->fetchAll();
                break;

            case 'inspections':
                $stmt = $conn->prepare("
                    SELECT i.*, u.unit_number, p.name AS property_name
                    FROM inspections i
                    INNER JOIN units u ON i.unit_id = u.id
                    INNER JOIN properties p ON u.property_id = p.id
                    WHERE i.tenant_id = :tid
                    ORDER BY i.inspection_date DESC
                    LIMIT 50
                ");
                $stmt->execute(['tid' => $tenantId]);
                $data = $stmt->fetchAll();
                break;
        }

        jsonResponse(['success' => true, 'data' => $data]);
        break;

    // ============================
    // ACTIVITY LOG (for tenant)
    // ============================
    case 'activity':
        if (!hasPermission('tenants', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $tenantId = (int)($_GET['tenant_id'] ?? 0);
        if (!$tenantId) {
            jsonResponse(['success' => false, 'message' => 'Tenant ID required'], 400);
        }

        if (!canAccessTenant($tenantId)) {
            jsonResponse(['success' => false, 'message' => 'No access to this record'], 403);
        }

        $stmt = $conn->prepare("
            SELECT al.*, u.full_name AS user_name
            FROM activity_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.entity_type = 'tenant'
              AND al.entity_id = :tid
            ORDER BY al.created_at DESC
            LIMIT 20
        ");
        $stmt->execute(['tid' => $tenantId]);
        $history = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $history]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
}
