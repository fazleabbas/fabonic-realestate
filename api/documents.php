<?php
/**
 * FIS Real Estate — Documents API (Part 18)
 * Handles: document CRUD, upload, download, categories, entity linking, storage stats.
 * Professional+ tier required.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

// Check tier and module
if (!hasTier('professional')) {
    jsonResponse(['success' => false, 'message' => 'Professional tier or higher required'], 403);
}
if (getSetting('documents_enabled', '0') !== '1') {
    jsonResponse(['success' => false, 'message' => 'Documents module is disabled'], 403);
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============================
    // DOCUMENTS — LIST
    // ============================
    case 'list':
        if (!hasPermission('documents', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $category = trim($_GET['category'] ?? '');
        $entityType = trim($_GET['entity_type'] ?? '');
        $entityId = (int)($_GET['entity_id'] ?? 0);
        $status = trim($_GET['status'] ?? 'active');
        $search = trim($_GET['search'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');
        $confidential = $_GET['confidential'] ?? '';

        $where = "1=1";
        $params = [];

        if ($category) {
            $where .= " AND d.category_id = :cat";
            $params['cat'] = $category;
        }
        if ($entityType) {
            $where .= " AND d.entity_type = :etype";
            $params['etype'] = $entityType;
        }
        if ($entityId) {
            $where .= " AND d.entity_id = :eid";
            $params['eid'] = $entityId;
        }
        if ($status) {
            $where .= " AND d.status = :status";
            $params['status'] = $status;
        }
        if ($search) {
            $where .= " AND (d.title LIKE :s1 OR d.description LIKE :s2 OR d.file_name LIKE :s3)";
            $params['s1'] = "%$search%";
            $params['s2'] = "%$search%";
            $params['s3'] = "%$search%";
        }
        if ($dateFrom) {
            $where .= " AND DATE(d.created_at) >= :df";
            $params['df'] = $dateFrom;
        }
        if ($dateTo) {
            $where .= " AND DATE(d.created_at) <= :dt";
            $params['dt'] = $dateTo;
        }
        if ($confidential !== '') {
            $where .= " AND d.is_confidential = :conf";
            $params['conf'] = (int)$confidential;
        }

        $countStmt = $conn->prepare("SELECT COUNT(*) FROM documents d WHERE $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pagination = paginate($total, $page);

        $sql = "SELECT d.*, dc.name AS category_name, dc.icon AS category_icon, dc.color AS category_color,
                       u.full_name AS uploader_name
                FROM documents d
                LEFT JOIN document_categories dc ON d.category_id = dc.id
                LEFT JOIN users u ON d.uploaded_by = u.id
                WHERE $where
                ORDER BY d.created_at DESC
                LIMIT :offset, :limit";
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $pagination['records_per_page'], PDO::PARAM_INT);
        $stmt->execute();

        $docs = $stmt->fetchAll();

        // Resolve entity names
        foreach ($docs as &$doc) {
            $doc['entity_name'] = resolveEntityName($conn, $doc['entity_type'], $doc['entity_id']);
            $doc['file_size_formatted'] = formatFileSize((int)$doc['file_size']);
        }

        jsonResponse(['success' => true, 'data' => $docs, 'pagination' => $pagination]);
        break;

    // ============================
    // DOCUMENTS — GET
    // ============================
    case 'get':
        if (!hasPermission('documents', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("
            SELECT d.*, dc.name AS category_name, dc.icon AS category_icon, dc.color AS category_color,
                   u.full_name AS uploader_name
            FROM documents d
            LEFT JOIN document_categories dc ON d.category_id = dc.id
            LEFT JOIN users u ON d.uploaded_by = u.id
            WHERE d.id = ?
        ");
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        if (!$doc) {
            jsonResponse(['success' => false, 'message' => 'Document not found'], 404);
        }
        $doc['entity_name'] = resolveEntityName($conn, $doc['entity_type'], $doc['entity_id']);
        $doc['file_size_formatted'] = formatFileSize((int)$doc['file_size']);

        jsonResponse(['success' => true, 'data' => $doc]);
        break;

    // ============================
    // DOCUMENTS — UPLOAD
    // ============================
    case 'upload':
        if (!hasPermission('documents', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        // Check storage quota
        $quotaCheck = checkStorageQuota($conn, 0);
        if (!$quotaCheck['ok']) {
            jsonResponse(['success' => false, 'message' => $quotaCheck['message']]);
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            jsonResponse(['success' => false, 'message' => __('doc_file_required')]);
        }

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
        $entityType = trim($_POST['entity_type'] ?? 'general');
        $entityId = (int)($_POST['entity_id'] ?? 0) ?: null;
        $tags = trim($_POST['tags'] ?? '');
        $isConfidential = isset($_POST['is_confidential']) ? 1 : 0;
        $expiryDate = trim($_POST['expiry_date'] ?? '') ?: null;

        $validEntityTypes = ['general', 'property', 'unit', 'tenant', 'lease', 'payment'];
        if (!in_array($entityType, $validEntityTypes)) $entityType = 'general';

        if ($entityType === 'general') $entityId = null;

        // Handle file upload
        $file = $_FILES['file'];
        $originalName = $file['name'];
        $fileSize = $file['size'];

        // Check quota with this file
        $quotaCheck = checkStorageQuota($conn, $fileSize);
        if (!$quotaCheck['ok']) {
            jsonResponse(['success' => false, 'message' => $quotaCheck['message']]);
        }

        if (empty($title)) {
            $title = pathinfo($originalName, PATHINFO_FILENAME);
        }

        $allowedTypes = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_DOC_TYPES, ['txt', 'zip', 'rar']);
        $result = uploadFile($file, 'documents', $allowedTypes);

        if (!$result['success']) {
            jsonResponse(['success' => false, 'message' => $result['message']]);
        }

        $mimeType = mime_content_type(UPLOAD_PATH . $result['filepath']) ?: $file['type'];

        // Parse tags
        $tagsArray = null;
        if ($tags) {
            $tagsArray = json_encode(array_map('trim', explode(',', $tags)));
        }

        $stmt = $conn->prepare("
            INSERT INTO documents (category_id, title, description, file_path, file_name, file_size, mime_type,
                                   entity_type, entity_id, tags, is_confidential, expiry_date, uploaded_by, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([
            $categoryId, $title, $description, $result['filepath'], $originalName,
            $fileSize, $mimeType, $entityType, $entityId, $tagsArray,
            $isConfidential, $expiryDate, getUserId()
        ]);
        $newId = (int)$conn->lastInsertId();

        logActivity('created', 'document', $newId, ['title' => $title, 'file' => $originalName]);
        jsonResponse(['success' => true, 'message' => __('doc_uploaded'), 'id' => $newId]);
        break;

    // ============================
    // DOCUMENTS — MULTI UPLOAD
    // ============================
    case 'upload_multi':
        if (!hasPermission('documents', 'create')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        if (empty($_FILES['files'])) {
            jsonResponse(['success' => false, 'message' => __('doc_file_required')]);
        }

        $categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
        $entityType = trim($_POST['entity_type'] ?? 'general');
        $entityId = (int)($_POST['entity_id'] ?? 0) ?: null;
        $isConfidential = isset($_POST['is_confidential']) ? 1 : 0;

        $validEntityTypes = ['general', 'property', 'unit', 'tenant', 'lease', 'payment'];
        if (!in_array($entityType, $validEntityTypes)) $entityType = 'general';
        if ($entityType === 'general') $entityId = null;

        $uploaded = 0;
        $failed = 0;
        $errors = [];
        $allowedTypes = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_DOC_TYPES, ['txt', 'zip', 'rar']);
        $files = $_FILES['files'];

        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $failed++;
                $errors[] = $files['name'][$i] . ': upload error';
                continue;
            }

            $quotaCheck = checkStorageQuota($conn, $files['size'][$i]);
            if (!$quotaCheck['ok']) {
                $failed++;
                $errors[] = $files['name'][$i] . ': ' . $quotaCheck['message'];
                continue;
            }

            $singleFile = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i],
            ];

            $result = uploadFile($singleFile, 'documents', $allowedTypes);
            if (!$result['success']) {
                $failed++;
                $errors[] = $files['name'][$i] . ': ' . $result['message'];
                continue;
            }

            $mimeType = mime_content_type(UPLOAD_PATH . $result['filepath']) ?: $files['type'][$i];
            $title = pathinfo($files['name'][$i], PATHINFO_FILENAME);

            $conn->prepare("
                INSERT INTO documents (category_id, title, file_path, file_name, file_size, mime_type,
                                       entity_type, entity_id, is_confidential, uploaded_by, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ")->execute([
                $categoryId, $title, $result['filepath'], $files['name'][$i],
                $files['size'][$i], $mimeType, $entityType, $entityId,
                $isConfidential, getUserId()
            ]);
            $uploaded++;
        }

        logActivity('created', 'document', null, ['multi_upload' => true, 'count' => $uploaded]);
        $msg = $uploaded . ' ' . __('doc_files_uploaded', 'file(s) uploaded');
        if ($failed > 0) $msg .= ', ' . $failed . ' failed';

        jsonResponse(['success' => true, 'message' => $msg, 'uploaded' => $uploaded, 'failed' => $failed, 'errors' => $errors]);
        break;

    // ============================
    // DOCUMENTS — UPDATE
    // ============================
    case 'update':
        if (!hasPermission('documents', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'Document ID required']);

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
        $entityType = trim($_POST['entity_type'] ?? 'general');
        $entityId = (int)($_POST['entity_id'] ?? 0) ?: null;
        $tags = trim($_POST['tags'] ?? '');
        $isConfidential = isset($_POST['is_confidential']) ? 1 : 0;
        $expiryDate = trim($_POST['expiry_date'] ?? '') ?: null;

        if (empty($title)) jsonResponse(['success' => false, 'message' => __('doc_title_required')]);

        $validEntityTypes = ['general', 'property', 'unit', 'tenant', 'lease', 'payment'];
        if (!in_array($entityType, $validEntityTypes)) $entityType = 'general';
        if ($entityType === 'general') $entityId = null;

        $tagsArray = null;
        if ($tags) {
            $tagsArray = json_encode(array_map('trim', explode(',', $tags)));
        }

        $stmt = $conn->prepare("
            UPDATE documents
            SET category_id = ?, title = ?, description = ?, entity_type = ?, entity_id = ?,
                tags = ?, is_confidential = ?, expiry_date = ?
            WHERE id = ?
        ");
        $stmt->execute([$categoryId, $title, $description, $entityType, $entityId, $tagsArray, $isConfidential, $expiryDate, $id]);

        logActivity('updated', 'document', $id, ['title' => $title]);
        jsonResponse(['success' => true, 'message' => __('doc_updated')]);
        break;

    // ============================
    // DOCUMENTS — DELETE
    // ============================
    case 'delete':
        if (!hasPermission('documents', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'Document ID required']);

        $doc = $conn->prepare("SELECT file_path, title FROM documents WHERE id = ?");
        $doc->execute([$id]);
        $docData = $doc->fetch();

        if ($docData) {
            // Delete physical file
            $filePath = UPLOAD_PATH . $docData['file_path'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            $conn->prepare("DELETE FROM documents WHERE id = ?")->execute([$id]);
            logActivity('deleted', 'document', $id, ['title' => $docData['title']]);
        }

        jsonResponse(['success' => true, 'message' => __('doc_deleted')]);
        break;

    // ============================
    // DOCUMENTS — BULK DELETE
    // ============================
    case 'bulk_delete':
        if (!hasPermission('documents', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $ids = $_POST['ids'] ?? '';
        if (is_string($ids)) {
            $ids = array_filter(array_map('intval', explode(',', $ids)));
        }
        if (empty($ids)) jsonResponse(['success' => false, 'message' => 'No documents selected']);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT id, file_path FROM documents WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $docs = $stmt->fetchAll();

        foreach ($docs as $doc) {
            $filePath = UPLOAD_PATH . $doc['file_path'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        $conn->prepare("DELETE FROM documents WHERE id IN ($placeholders)")->execute($ids);
        logActivity('deleted', 'document', null, ['bulk_delete' => count($docs)]);
        jsonResponse(['success' => true, 'message' => count($docs) . ' ' . __('doc_docs_deleted', 'document(s) deleted')]);
        break;

    // ============================
    // DOCUMENTS — ARCHIVE / UNARCHIVE
    // ============================
    case 'archive':
        if (!hasPermission('documents', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT status FROM documents WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();

        $newStatus = ($current === 'archived') ? 'active' : 'archived';
        $conn->prepare("UPDATE documents SET status = ? WHERE id = ?")->execute([$newStatus, $id]);

        logActivity('updated', 'document', $id, ['status' => $newStatus]);
        jsonResponse(['success' => true, 'message' => __($newStatus === 'archived' ? 'doc_archived' : 'doc_restored')]);
        break;

    // ============================
    // DOCUMENTS — DOWNLOAD
    // ============================
    case 'download':
        if (!hasPermission('documents', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("SELECT file_path, file_name, mime_type, entity_type, entity_id FROM documents WHERE id = ?");
        $stmt->execute([$id]);
        $doc = $stmt->fetch();

        if (!$doc) {
            jsonResponse(['success' => false, 'message' => 'Document not found'], 404);
        }

        // A document inherits the property scope of whatever it is linked to.
        if (!documentInScope($conn, $doc['entity_type'], (int)$doc['entity_id'])) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $filePath = UPLOAD_PATH . $doc['file_path'];
        if (!file_exists($filePath)) {
            jsonResponse(['success' => false, 'message' => 'File not found on disk'], 404);
        }

        logActivity('viewed', 'document', $id, ['downloaded' => true]);

        $safeName = preg_replace('/[^A-Za-z0-9 ._-]/', '_', $doc['file_name']);
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($filePath);
        exit;

    // ============================
    // CATEGORIES — LIST
    // ============================
    case 'list_categories':
        if (!hasPermission('documents', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stmt = $conn->query("
            SELECT dc.*, (SELECT COUNT(*) FROM documents d WHERE d.category_id = dc.id) AS doc_count
            FROM document_categories dc
            ORDER BY dc.sort_order ASC, dc.name ASC
        ");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ============================
    // CATEGORIES — CREATE
    // ============================
    case 'create_category':
        if (!hasPermission('documents', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $icon = trim($_POST['icon'] ?? 'bi-folder');
        $color = trim($_POST['color'] ?? '#6c757d');

        if (empty($name)) jsonResponse(['success' => false, 'message' => __('doc_cat_name_required')]);

        // Check duplicate
        $check = $conn->prepare("SELECT id FROM document_categories WHERE name = ?");
        $check->execute([$name]);
        if ($check->fetch()) {
            jsonResponse(['success' => false, 'message' => __('doc_cat_exists')]);
        }

        $maxOrder = (int)$conn->query("SELECT MAX(sort_order) FROM document_categories")->fetchColumn();

        $stmt = $conn->prepare("INSERT INTO document_categories (name, description, icon, color, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $description, $icon, $color, $maxOrder + 1]);

        logActivity('created', 'document_category', (int)$conn->lastInsertId(), ['name' => $name]);
        jsonResponse(['success' => true, 'message' => __('doc_cat_created')]);
        break;

    // ============================
    // CATEGORIES — UPDATE
    // ============================
    case 'update_category':
        if (!hasPermission('documents', 'edit')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $icon = trim($_POST['icon'] ?? 'bi-folder');
        $color = trim($_POST['color'] ?? '#6c757d');

        if (empty($name)) jsonResponse(['success' => false, 'message' => __('doc_cat_name_required')]);

        $stmt = $conn->prepare("UPDATE document_categories SET name = ?, description = ?, icon = ?, color = ? WHERE id = ?");
        $stmt->execute([$name, $description, $icon, $color, $id]);

        logActivity('updated', 'document_category', $id, ['name' => $name]);
        jsonResponse(['success' => true, 'message' => __('doc_cat_updated')]);
        break;

    // ============================
    // CATEGORIES — DELETE
    // ============================
    case 'delete_category':
        if (!hasPermission('documents', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);

        // Check if system category
        $check = $conn->prepare("SELECT is_system FROM document_categories WHERE id = ?");
        $check->execute([$id]);
        if ((int)$check->fetchColumn() === 1) {
            jsonResponse(['success' => false, 'message' => __('doc_cat_system')]);
        }

        // Set documents in this category to NULL
        $conn->prepare("UPDATE documents SET category_id = NULL WHERE category_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM document_categories WHERE id = ? AND is_system = 0")->execute([$id]);

        logActivity('deleted', 'document_category', $id);
        jsonResponse(['success' => true, 'message' => __('doc_cat_deleted')]);
        break;

    // ============================
    // STORAGE STATS
    // ============================
    case 'stats':
        if (!hasPermission('documents', 'view')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $stats = [];
        $stats['total_docs'] = (int)$conn->query("SELECT COUNT(*) FROM documents WHERE status = 'active'")->fetchColumn();
        $stats['archived_docs'] = (int)$conn->query("SELECT COUNT(*) FROM documents WHERE status = 'archived'")->fetchColumn();
        $stats['total_size'] = (int)$conn->query("SELECT COALESCE(SUM(file_size), 0) FROM documents")->fetchColumn();
        $stats['total_size_formatted'] = formatFileSize($stats['total_size']);
        $stats['quota_mb'] = (int)getSetting('storage_quota_mb', 500);
        $stats['quota_bytes'] = $stats['quota_mb'] * 1024 * 1024;
        $stats['usage_percent'] = $stats['quota_bytes'] > 0 ? round(($stats['total_size'] / $stats['quota_bytes']) * 100, 1) : 0;

        // By category
        $catStats = $conn->query("
            SELECT dc.name, dc.icon, dc.color, COUNT(d.id) AS count, COALESCE(SUM(d.file_size), 0) AS size
            FROM document_categories dc
            LEFT JOIN documents d ON d.category_id = dc.id
            GROUP BY dc.id
            ORDER BY size DESC
        ")->fetchAll();
        $stats['by_category'] = $catStats;

        // By entity type
        $entityStats = $conn->query("
            SELECT entity_type, COUNT(*) AS count, COALESCE(SUM(file_size), 0) AS size
            FROM documents
            GROUP BY entity_type
            ORDER BY count DESC
        ")->fetchAll();
        $stats['by_entity'] = $entityStats;

        // Expiring soon (within 30 days)
        $expStmt = $conn->prepare("SELECT COUNT(*) FROM documents WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE() AND status = 'active'");
        $expStmt->execute();
        $stats['expiring_soon'] = (int)$expStmt->fetchColumn();

        // Expired
        $stats['expired'] = (int)$conn->query("SELECT COUNT(*) FROM documents WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND status = 'active'")->fetchColumn();

        jsonResponse(['success' => true, 'data' => $stats]);
        break;

    // ============================
    // CLEANUP — ARCHIVE EXPIRED
    // ============================
    case 'cleanup':
        if (!hasPermission('documents', 'delete')) {
            jsonResponse(['success' => false, 'message' => 'No permission'], 403);
        }

        $cleanupAction = trim($_POST['cleanup_action'] ?? 'archive_expired');
        $count = 0;

        if ($cleanupAction === 'archive_expired') {
            $stmt = $conn->prepare("UPDATE documents SET status = 'archived' WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND status = 'active'");
            $stmt->execute();
            $count = $stmt->rowCount();
        } elseif ($cleanupAction === 'delete_archived') {
            $stmt = $conn->query("SELECT id, file_path FROM documents WHERE status = 'archived'");
            $docs = $stmt->fetchAll();
            foreach ($docs as $doc) {
                $filePath = UPLOAD_PATH . $doc['file_path'];
                if (file_exists($filePath)) @unlink($filePath);
            }
            $conn->query("DELETE FROM documents WHERE status = 'archived'");
            $count = count($docs);
        }

        logActivity('deleted', 'document', null, ['cleanup' => $cleanupAction, 'count' => $count]);
        jsonResponse(['success' => true, 'message' => $count . ' document(s) processed']);
        break;

    // ============================
    // DROPDOWNS (entity selectors)
    // ============================
    case 'dropdowns':
        $properties = $conn->query("SELECT id, name FROM properties WHERE status = 'active' ORDER BY name")->fetchAll();
        $units = $conn->query("SELECT u.id, u.unit_number, p.name AS property_name FROM units u INNER JOIN properties p ON u.property_id = p.id ORDER BY p.name, u.unit_number")->fetchAll();
        $tenants = $conn->query("SELECT id, CONCAT(first_name, ' ', last_name) AS name, phone FROM tenants WHERE status = 'active' ORDER BY first_name")->fetchAll();
        $leases = $conn->query("
            SELECT l.id, CONCAT(t.first_name, ' ', t.last_name, ' — ', u.unit_number) AS label
            FROM leases l
            INNER JOIN tenants t ON l.tenant_id = t.id
            INNER JOIN units u ON l.unit_id = u.id
            WHERE l.status = 'active'
            ORDER BY t.first_name
        ")->fetchAll();
        $payments = $conn->query("
            SELECT py.id, CONCAT('Payment #', py.id, ' — ', t.first_name, ' ', t.last_name, ' — ', DATE_FORMAT(py.payment_date, '%d-%m-%Y')) AS label
            FROM payments py
            INNER JOIN tenants t ON py.tenant_id = t.id
            ORDER BY py.payment_date DESC
            LIMIT 500
        ")->fetchAll();

        jsonResponse(['success' => true, 'data' => [
            'properties' => $properties,
            'units' => $units,
            'tenants' => $tenants,
            'leases' => $leases,
            'payments' => $payments
        ]]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Resolve entity name from type + id
 */
function resolveEntityName($conn, $entityType, $entityId) {
    if (!$entityId || $entityType === 'general') return '';

    try {
        switch ($entityType) {
            case 'property':
                $s = $conn->prepare("SELECT name FROM properties WHERE id = ?");
                $s->execute([$entityId]);
                return $s->fetchColumn() ?: '';
            case 'unit':
                $s = $conn->prepare("SELECT CONCAT(u.unit_number, ' (', p.name, ')') FROM units u INNER JOIN properties p ON u.property_id = p.id WHERE u.id = ?");
                $s->execute([$entityId]);
                return $s->fetchColumn() ?: '';
            case 'tenant':
                $s = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM tenants WHERE id = ?");
                $s->execute([$entityId]);
                return $s->fetchColumn() ?: '';
            case 'lease':
                $s = $conn->prepare("SELECT CONCAT(t.first_name, ' ', t.last_name, ' — ', u.unit_number) FROM leases l INNER JOIN tenants t ON l.tenant_id = t.id INNER JOIN units u ON l.unit_id = u.id WHERE l.id = ?");
                $s->execute([$entityId]);
                return $s->fetchColumn() ?: '';
            case 'payment':
                $s = $conn->prepare("SELECT CONCAT('Payment #', py.id, ' — ', t.first_name, ' ', t.last_name) FROM payments py INNER JOIN tenants t ON py.tenant_id = t.id WHERE py.id = ?");
                $s->execute([$entityId]);
                return $s->fetchColumn() ?: '';
        }
    } catch (Exception $e) {}
    return '';
}

/**
 * Format file size to human readable
 */
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

/**
 * Check storage quota
 */
function checkStorageQuota($conn, $additionalBytes = 0) {
    $quotaMb = (int)getSetting('storage_quota_mb', 500);
    if ($quotaMb <= 0) return ['ok' => true]; // unlimited

    $quotaBytes = $quotaMb * 1024 * 1024;
    $used = (int)$conn->query("SELECT COALESCE(SUM(file_size), 0) FROM documents")->fetchColumn();

    if (($used + $additionalBytes) > $quotaBytes) {
        $usedFormatted = formatFileSize($used);
        $quotaFormatted = $quotaMb . ' MB';
        return ['ok' => false, 'message' => __('doc_quota_exceeded', "Storage quota exceeded ({$usedFormatted} of {$quotaFormatted} used)")];
    }

    return ['ok' => true, 'used' => $used, 'quota' => $quotaBytes];
}
