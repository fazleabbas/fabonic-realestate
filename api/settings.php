<?php
/**
 * FIS Real Estate — Settings & Configuration API (Part 14)
 * Handles: company settings, system preferences, user CRUD, role CRUD, permissions, backup/export.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // COMPANY SETTINGS — GET
    // ============================
    case 'get_settings':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("SELECT setting_key, setting_value, setting_group FROM settings ORDER BY setting_group, setting_key");
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        jsonResponse(['success' => true, 'data' => $settings]);
        break;

    // ============================
    // COMPANY SETTINGS — SAVE
    // ============================
    case 'save_settings':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $group = trim($_POST['setting_group'] ?? 'general');
        $allowed_groups = ['general', 'payments', 'notifications', 'late_fees', 'modules', 'display'];
        if (!in_array($group, $allowed_groups)) {
            jsonResponse(['success' => false, 'message' => 'Invalid setting group']);
        }

        $fields = $_POST;
        unset($fields['action'], $fields['csrf_token'], $fields['setting_group']);

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("
                INSERT INTO settings (setting_key, setting_value, setting_group, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = ?, setting_group = ?, updated_at = NOW()
            ");

            foreach ($fields as $key => $value) {
                $cleanKey = preg_replace('/[^a-z0-9_]/', '', $key);
                if (empty($cleanKey)) continue;
                $cleanValue = trim($value);
                $stmt->execute([$cleanKey, $cleanValue, $group, $cleanValue, $group]);
            }

            $conn->commit();
            logActivity('updated', 'settings', null, ['group' => $group, 'keys' => array_keys($fields)]);
            jsonResponse(['success' => true, 'message' => 'Settings saved successfully']);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            jsonResponse(['success' => false, 'message' => 'Failed to save settings: ' . $e->getMessage()]);
        }
        break;

    // ============================
    // LOGO UPLOAD
    // ============================
    case 'upload_logo':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        if (empty($_FILES['logo'])) {
            jsonResponse(['success' => false, 'message' => 'No file uploaded']);
        }

        $result = uploadFile($_FILES['logo'], 'company', ALLOWED_IMAGE_TYPES);
        if (!$result['success']) {
            jsonResponse(['success' => false, 'message' => $result['message']]);
        }

        // Delete old logo if exists
        $oldLogo = getSetting('company_logo');
        if ($oldLogo && file_exists(UPLOAD_PATH . $oldLogo)) {
            unlink(UPLOAD_PATH . $oldLogo);
        }

        updateSetting('company_logo', $result['filepath']);
        logActivity('updated', 'settings', null, ['key' => 'company_logo']);
        jsonResponse(['success' => true, 'filepath' => $result['filepath']]);
        break;

    // ============================
    // LOGO DELETE
    // ============================
    case 'delete_logo':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $logo = getSetting('company_logo');
        if ($logo && file_exists(UPLOAD_PATH . $logo)) {
            unlink(UPLOAD_PATH . $logo);
        }
        updateSetting('company_logo', '');
        logActivity('deleted', 'settings', null, ['key' => 'company_logo']);
        jsonResponse(['success' => true]);
        break;

    // ============================
    // USERS — LIST
    // ============================
    case 'list_users':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $roleId = (int)($_GET['role_id'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));

        $where = "1=1";
        $params = [];

        if ($search) {
            $where .= " AND (u.full_name LIKE :search OR u.username LIKE :search2 OR u.email LIKE :search3 OR u.phone LIKE :search4)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
            $params['search4'] = "%{$search}%";
        }
        if ($status) {
            $where .= " AND u.status = :status";
            $params['status'] = $status;
        }
        if ($roleId > 0) {
            $where .= " AND u.role_id = :rid";
            $params['rid'] = $roleId;
        }

        // Count
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM users u WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pagination = paginate($total, $page);

        // Fetch
        $sql = "SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role_id, u.language,
                       u.status, u.last_login, u.created_at,
                       r.name AS role_name
                FROM users u
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE {$where}
                ORDER BY u.id ASC
                LIMIT :offset, :limit";
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $pagination['records_per_page'], PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(), 'pagination' => $pagination]);
        break;

    // ============================
    // USERS — GET
    // ============================
    case 'get_user':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("
            SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role_id, u.language, u.status, u.can_approve, u.last_login, u.created_at,
                   r.name AS role_name
            FROM users u LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.id = ?
        ");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            jsonResponse(['success' => false, 'message' => 'User not found'], 404);
        }
        // Assigned properties (Part 24)
        $pstmt = $conn->prepare("SELECT property_id FROM user_properties WHERE user_id = ?");
        $pstmt->execute([$id]);
        $user['property_ids'] = array_map('intval', $pstmt->fetchAll(PDO::FETCH_COLUMN));
        jsonResponse(['success' => true, 'data' => $user]);
        break;

    // ============================
    // USERS — CREATE
    // ============================
    case 'create_user':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 1);
        $language = trim($_POST['language'] ?? 'en');
        $status = trim($_POST['status'] ?? 'active');

        if (empty($username) || empty($fullName) || empty($password)) {
            jsonResponse(['success' => false, 'message' => 'Username, full name, and password are required']);
        }

        if (strlen($password) < 6) {
            jsonResponse(['success' => false, 'message' => 'Password must be at least 6 characters']);
        }

        // Check unique username
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Username already exists']);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("
            INSERT INTO users (username, full_name, email, phone, password_hash, role_id, language, status, can_approve)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$username, $fullName, $email ?: null, $phone ?: null, $hash, $roleId, $language, $status,
                        !empty($_POST['can_approve']) ? 1 : 0]);
        $newId = (int)$conn->lastInsertId();

        saveUserProperties($conn, $newId, $_POST['property_ids'] ?? []);

        logActivity('created', 'user', $newId, ['username' => $username, 'role_id' => $roleId]);
        jsonResponse(['success' => true, 'message' => 'User created successfully', 'id' => $newId]);
        break;

    // ============================
    // USERS — UPDATE
    // ============================
    case 'update_user':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 1);
        $language = trim($_POST['language'] ?? 'en');
        $status = trim($_POST['status'] ?? 'active');

        if (!$id || empty($fullName)) {
            jsonResponse(['success' => false, 'message' => 'ID and full name are required']);
        }

        // Don't allow deactivating own account
        if ($id == getUserId() && $status === 'inactive') {
            jsonResponse(['success' => false, 'message' => 'You cannot deactivate your own account']);
        }

        $stmt = $conn->prepare("
            UPDATE users SET full_name = ?, email = ?, phone = ?, role_id = ?, language = ?, status = ?, can_approve = ?
            WHERE id = ?
        ");
        $stmt->execute([$fullName, $email ?: null, $phone ?: null, $roleId, $language, $status,
                        !empty($_POST['can_approve']) ? 1 : 0, $id]);

        saveUserProperties($conn, $id, $_POST['property_ids'] ?? []);

        logActivity('updated', 'user', $id, ['full_name' => $fullName, 'role_id' => $roleId]);
        jsonResponse(['success' => true, 'message' => 'User updated successfully']);
        break;

    // ============================
    // USERS — DELETE
    // ============================
    case 'delete_user':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id == getUserId()) {
            jsonResponse(['success' => false, 'message' => 'You cannot delete your own account']);
        }

        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);

        logActivity('deleted', 'user', $id);
        jsonResponse(['success' => true, 'message' => 'User deleted successfully']);
        break;

    // ============================
    // USERS — RESET PASSWORD
    // ============================
    case 'reset_password':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $newPassword = trim($_POST['new_password'] ?? '');

        if (!$id || empty($newPassword)) {
            jsonResponse(['success' => false, 'message' => 'User ID and new password required']);
        }
        if (strlen($newPassword) < 6) {
            jsonResponse(['success' => false, 'message' => 'Password must be at least 6 characters']);
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?");
        $stmt->execute([$hash, $id]);

        logActivity('updated', 'user', $id, ['action' => 'password_reset']);
        jsonResponse(['success' => true, 'message' => 'Password reset successfully']);
        break;

    // ============================
    // ROLES — LIST
    // ============================
    case 'list_roles':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("
            SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count
            FROM roles r ORDER BY r.id
        ");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // ROLES — GET
    // ============================
    case 'get_role':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM roles WHERE id = ?");
        $stmt->execute([$id]);
        $role = $stmt->fetch();
        if (!$role) {
            jsonResponse(['success' => false, 'message' => 'Role not found'], 404);
        }
        jsonResponse(['success' => true, 'data' => $role]);
        break;

    // ============================
    // ROLES — CREATE
    // ============================
    case 'create_role':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $permissions = trim($_POST['permissions'] ?? '{}');

        if (empty($name)) {
            jsonResponse(['success' => false, 'message' => 'Role name is required']);
        }

        // Check unique name
        $check = $conn->prepare("SELECT id FROM roles WHERE name = ?");
        $check->execute([$name]);
        if ($check->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Role name already exists']);
        }

        // Validate JSON
        $permObj = json_decode($permissions, true);
        if ($permObj === null && $permissions !== '{}') {
            jsonResponse(['success' => false, 'message' => 'Invalid permissions JSON']);
        }

        $stmt = $conn->prepare("INSERT INTO roles (name, description, permissions, is_system) VALUES (?, ?, ?, 0)");
        $stmt->execute([$name, $description ?: null, $permissions]);
        $newId = (int)$conn->lastInsertId();

        logActivity('created', 'role', $newId, ['name' => $name]);
        jsonResponse(['success' => true, 'message' => 'Role created successfully', 'id' => $newId]);
        break;

    // ============================
    // ROLES — UPDATE
    // ============================
    case 'update_role':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $permissions = trim($_POST['permissions'] ?? '{}');

        if (!$id || empty($name)) {
            jsonResponse(['success' => false, 'message' => 'Role ID and name are required']);
        }

        // Check if system role — only allow permission edit, not name change
        $existing = $conn->prepare("SELECT * FROM roles WHERE id = ?");
        $existing->execute([$id]);
        $role = $existing->fetch();
        if (!$role) {
            jsonResponse(['success' => false, 'message' => 'Role not found'], 404);
        }

        // Check unique name (excluding current)
        $check = $conn->prepare("SELECT id FROM roles WHERE name = ? AND id != ?");
        $check->execute([$name, $id]);
        if ($check->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Role name already exists']);
        }

        // Validate JSON
        $permObj = json_decode($permissions, true);
        if ($permObj === null && $permissions !== '{}') {
            jsonResponse(['success' => false, 'message' => 'Invalid permissions JSON']);
        }

        if ($role['is_system'] && $role['name'] === 'Admin') {
            // Admin role: only description can change, permissions stay {"all": true}
            $stmt = $conn->prepare("UPDATE roles SET description = ? WHERE id = ?");
            $stmt->execute([$description, $id]);
        } else {
            $stmt = $conn->prepare("UPDATE roles SET name = ?, description = ?, permissions = ? WHERE id = ?");
            $stmt->execute([$name, $description ?: null, $permissions, $id]);
        }

        // Update session permissions if editing current user's role
        $currentRoleId = getUserData('role_id');
        if ($currentRoleId == $id) {
            $_SESSION['user_permissions'] = $permObj ?? [];
        }

        logActivity('updated', 'role', $id, ['name' => $name]);
        jsonResponse(['success' => true, 'message' => 'Role updated successfully']);
        break;

    // ============================
    // ROLES — DELETE
    // ============================
    case 'delete_role':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);

        // Check if system role
        $check = $conn->prepare("SELECT is_system FROM roles WHERE id = ?");
        $check->execute([$id]);
        $role = $check->fetch();
        if (!$role) {
            jsonResponse(['success' => false, 'message' => 'Role not found'], 404);
        }
        if ($role['is_system']) {
            jsonResponse(['success' => false, 'message' => 'System roles cannot be deleted']);
        }

        // Check if users are assigned
        $userCheck = $conn->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
        $userCheck->execute([$id]);
        if ((int)$userCheck->fetchColumn() > 0) {
            jsonResponse(['success' => false, 'message' => 'Cannot delete role with assigned users. Reassign users first.']);
        }

        $stmt = $conn->prepare("DELETE FROM roles WHERE id = ?");
        $stmt->execute([$id]);

        logActivity('deleted', 'role', $id);
        jsonResponse(['success' => true, 'message' => 'Role deleted successfully']);
        break;

    // ============================
    // ROLES DROPDOWN (for user forms)
    // ============================
    case 'roles_dropdown':
        $stmt = $conn->query("SELECT id, name FROM roles ORDER BY id");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // ACTIVITY LOG
    // ============================
    case 'activity_log':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $entityType = trim($_GET['entity_type'] ?? '');
        $userId = (int)($_GET['user_id'] ?? 0);
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');

        $where = "1=1";
        $params = [];

        if ($entityType) {
            $where .= " AND al.entity_type = :etype";
            $params['etype'] = $entityType;
        }
        if ($userId > 0) {
            $where .= " AND al.user_id = :uid";
            $params['uid'] = $userId;
        }
        if ($dateFrom) {
            $where .= " AND al.created_at >= :df";
            $params['df'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $where .= " AND al.created_at <= :dt";
            $params['dt'] = $dateTo . ' 23:59:59';
        }

        $countStmt = $conn->prepare("SELECT COUNT(*) FROM activity_log al WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pagination = paginate($total, $page);

        $sql = "SELECT al.*, u.full_name AS user_name
                FROM activity_log al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE {$where}
                ORDER BY al.created_at DESC
                LIMIT :offset, :limit";
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $pagination['records_per_page'], PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll(), 'pagination' => $pagination]);
        break;

    // ============================
    // DATA EXPORT — FULL BACKUP (CSV)
    // ============================
    case 'export_full':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $module = trim($_GET['module'] ?? 'all');
        $format = trim($_GET['format'] ?? 'csv');

        // Define exportable tables by module
        $moduleTables = [
            'properties' => ['properties', 'property_photos', 'property_settings'],
            'units' => ['units', 'unit_photos'],
            'tenants' => ['tenants'],
            'leases' => ['leases', 'lease_templates'],
            'payments' => ['payments', 'receipts'],
            'deposits' => ['deposits', 'deposit_deductions'],
            'late_fees' => ['late_fees'],
            'inspections' => ['inspections', 'inspection_items', 'inspection_templates'],
            'maintenance' => ['maintenance_requests', 'maintenance_photos', 'maintenance_categories', 'contractors', 'maintenance_assignments'],
            'users' => ['users', 'roles'],
            'settings' => ['settings'],
            'activity' => ['activity_log'],
        ];

        $tables = [];
        if ($module === 'all') {
            foreach ($moduleTables as $tblList) {
                $tables = array_merge($tables, $tblList);
            }
        } elseif (isset($moduleTables[$module])) {
            $tables = $moduleTables[$module];
        } else {
            jsonResponse(['success' => false, 'message' => 'Unknown module: ' . $module]);
        }

        // Create ZIP with CSVs
        $zipFile = tempnam(sys_get_temp_dir(), 'fis_export_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
            jsonResponse(['success' => false, 'message' => 'Cannot create archive']);
        }

        foreach ($tables as $table) {
            try {
                $rows = $conn->query("SELECT * FROM `{$table}`")->fetchAll();
                if (empty($rows)) {
                    $zip->addFromString("{$table}.csv", "-- No data --\n");
                    continue;
                }

                $csvContent = '';
                $fp = fopen('php://temp', 'r+');
                fputcsv($fp, array_keys($rows[0]));
                foreach ($rows as $row) {
                    // Mask password hashes
                    if (isset($row['password_hash'])) $row['password_hash'] = '***';
                    if (isset($row['portal_password_hash'])) $row['portal_password_hash'] = '***';
                    fputcsv($fp, $row);
                }
                rewind($fp);
                $csvContent = stream_get_contents($fp);
                fclose($fp);

                $zip->addFromString("{$table}.csv", $csvContent);
            } catch (PDOException $e) {
                $zip->addFromString("{$table}_error.txt", "Error: " . $e->getMessage());
            }
        }

        $zip->close();

        logActivity('exported', 'backup', null, ['module' => $module, 'tables' => count($tables)]);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="fis_export_' . $module . '_' . date('Y-m-d_His') . '.zip"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);
        unlink($zipFile);
        exit;

    // ============================
    // DATA EXPORT — SINGLE TABLE CSV
    // ============================
    case 'export_table':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $table = preg_replace('/[^a-z_]/', '', trim($_GET['table'] ?? ''));
        $allowedTables = ['properties', 'units', 'tenants', 'leases', 'payments', 'receipts',
                          'deposits', 'deposit_deductions', 'late_fees', 'inspections', 'inspection_items',
                          'maintenance_requests', 'contractors', 'maintenance_assignments',
                          'settings', 'users', 'roles', 'activity_log'];

        if (!in_array($table, $allowedTables)) {
            jsonResponse(['success' => false, 'message' => 'Table not allowed for export']);
        }

        $rows = $conn->query("SELECT * FROM `{$table}`")->fetchAll();

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $table . '_' . date('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        if (!empty($rows)) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) {
                if (isset($row['password_hash'])) $row['password_hash'] = '***';
                if (isset($row['portal_password_hash'])) $row['portal_password_hash'] = '***';
                fputcsv($out, $row);
            }
        }
        fclose($out);

        logActivity('exported', 'table', null, ['table' => $table]);
        exit;

    // ============================
    // DATA IMPORT — CSV
    // ============================
    // Retired in part 40: imports go through modules/import/, which validates
    // every row and can be undone. Kept as a stub so an old cached page gets a
    // clear answer instead of an unknown-action error.
    case 'import_csv':
        jsonResponse(['success' => false, 'message' => __('bak_import_moved', 'Importing has moved to the Data Import page, which checks every row before anything is saved, matches records by name, and can be undone.')], 410);
        break;

    // ============================
    // SYSTEM STATS (for backup page)
    // ============================
    case 'system_stats':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stats = [];
        $tables = ['properties', 'units', 'tenants', 'leases', 'payments', 'receipts',
                   'deposits', 'late_fees', 'inspections', 'maintenance_requests',
                   'contractors', 'users', 'roles', 'settings', 'activity_log'];

        foreach ($tables as $t) {
            try {
                $stats[$t] = (int)$conn->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            } catch (PDOException $e) {
                $stats[$t] = 0;
            }
        }

        jsonResponse(['success' => true, 'data' => $stats]);
        break;

    // ============================
    // PROPERTY SCOPING — TOGGLE (Part 24)
    // ============================
    case 'set_scoping':
        if (getUserData('role_name') !== 'Admin') {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }
        $enabled = (isset($_POST['enabled']) && ($_POST['enabled'] === '1' || $_POST['enabled'] === 1 || $_POST['enabled'] === true)) ? '1' : '0';
        updateSetting('property_scoping_enabled', $enabled);
        logActivity('updated', 'settings', null, ['property_scoping_enabled' => $enabled]);
        jsonResponse(['success' => true, 'message' => 'Property scoping ' . ($enabled === '1' ? 'enabled' : 'disabled'), 'enabled' => $enabled]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}

/**
 * Replace a user's assigned properties (Part 24). Accepts an array of IDs or a
 * comma-separated string. Empty input clears all assignments.
 */
function saveUserProperties($conn, $userId, $propInput) {
    if (!is_array($propInput)) {
        $propInput = ($propInput === '' || $propInput === null) ? [] : explode(',', (string)$propInput);
    }
    $propIds = array_values(array_unique(array_filter(array_map('intval', $propInput), function ($v) { return $v > 0; })));

    $conn->prepare("DELETE FROM user_properties WHERE user_id = ?")->execute([(int)$userId]);
    if ($propIds) {
        $ins = $conn->prepare("INSERT IGNORE INTO user_properties (user_id, property_id) VALUES (?, ?)");
        foreach ($propIds as $pid) {
            $ins->execute([(int)$userId, $pid]);
        }
    }
}
