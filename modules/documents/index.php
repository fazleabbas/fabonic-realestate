<?php
/**
 * FIS Real Estate — Document List (Part 18.3)
 * Browse, filter, and manage documents. Professional+ tier required.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('doc_documents', 'Documents');

$subStatus = enforceSubscription();

if (!hasTier('professional')) {
    setAlert(__('doc_tier_required', 'Professional tier or higher required for document management.'), 'warning');
    redirect('index.php');
}
if (getSetting('documents_enabled', '0') !== '1') {
    setAlert(__('doc_disabled', 'Documents module is disabled. Enable it in Settings > Modules.'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('documents', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// --- Filters ---
$filterCategory = $_GET['category'] ?? '';
$filterEntityType = $_GET['entity_type'] ?? '';
$filterStatus = $_GET['status'] ?? 'active';
$search = trim($_GET['search'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

// Fetch categories for filter
$categories = $conn->query("SELECT * FROM document_categories ORDER BY sort_order")->fetchAll();

// Build query
$where = "1=1";
$params = [];

if ($filterCategory) {
    $where .= " AND d.category_id = :cat";
    $params['cat'] = $filterCategory;
}
if ($filterEntityType) {
    $where .= " AND d.entity_type = :etype";
    $params['etype'] = $filterEntityType;
}
if ($filterStatus) {
    $where .= " AND d.status = :status";
    $params['status'] = $filterStatus;
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

$countStmt = $conn->prepare("SELECT COUNT(*) FROM documents d WHERE $where");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$pagination = paginate($totalRecords, $page, $perPage);

$stmt = $conn->prepare("
    SELECT d.*, dc.name AS category_name, dc.icon AS category_icon, dc.color AS category_color,
           u.full_name AS uploader_name
    FROM documents d
    LEFT JOIN document_categories dc ON d.category_id = dc.id
    LEFT JOIN users u ON d.uploaded_by = u.id
    WHERE $where
    ORDER BY d.created_at DESC
    LIMIT {$pagination['records_per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$documents = $stmt->fetchAll();

// Storage stats
$totalSize = (int)$conn->query("SELECT COALESCE(SUM(file_size), 0) FROM documents")->fetchColumn();
$quotaMb = (int)getSetting('storage_quota_mb', 500);
$quotaBytes = $quotaMb * 1024 * 1024;
$usagePercent = $quotaBytes > 0 ? round(($totalSize / $quotaBytes) * 100, 1) : 0;

// Helper: format file size
function fmtSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// Helper: file type icon
function fileIcon($mime, $ext = '') {
    if (strpos($mime, 'image') !== false) return 'bi-file-image text-success';
    if (strpos($mime, 'pdf') !== false) return 'bi-file-pdf text-danger';
    if (strpos($mime, 'word') !== false || $ext === 'doc' || $ext === 'docx') return 'bi-file-word text-primary';
    if (strpos($mime, 'spreadsheet') !== false || strpos($mime, 'excel') !== false || $ext === 'xlsx' || $ext === 'csv') return 'bi-file-excel text-success';
    if (strpos($mime, 'zip') !== false || strpos($mime, 'rar') !== false) return 'bi-file-zip text-warning';
    if (strpos($mime, 'text') !== false) return 'bi-file-text text-secondary';
    return 'bi-file-earmark text-muted';
}

// Helper: resolve entity name
function getEntityName($conn, $type, $id) {
    if (!$id || $type === 'general') return '';
    try {
        switch ($type) {
            case 'property':
                $s = $conn->prepare("SELECT name FROM properties WHERE id = ?"); $s->execute([$id]); return $s->fetchColumn() ?: '';
            case 'unit':
                $s = $conn->prepare("SELECT CONCAT(u.unit_number, ' (', p.name, ')') FROM units u INNER JOIN properties p ON u.property_id = p.id WHERE u.id = ?"); $s->execute([$id]); return $s->fetchColumn() ?: '';
            case 'tenant':
                $s = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM tenants WHERE id = ?"); $s->execute([$id]); return $s->fetchColumn() ?: '';
            case 'lease':
                $s = $conn->prepare("SELECT CONCAT(t.first_name, ' ', t.last_name, ' — ', u.unit_number) FROM leases l INNER JOIN tenants t ON l.tenant_id = t.id INNER JOIN units u ON l.unit_id = u.id WHERE l.id = ?"); $s->execute([$id]); return $s->fetchColumn() ?: '';
        }
    } catch (Exception $e) {}
    return '';
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h4 class="mb-1"><i class="bi bi-folder2-open me-2"></i><?php _e('doc_documents', 'Documents'); ?></h4>
        <p class="text-muted mb-0"><?php _e('doc_desc', 'Manage and organize property documents'); ?></p>
    </div>
    <div class="d-flex gap-1">
        <?php if (hasPermission('documents', 'create')): ?>
        <a href="<?php echo BASE_URL; ?>modules/documents/upload.php" class="btn btn-sm btn-primary">
            <i class="bi bi-cloud-upload me-1"></i> <?php _e('doc_upload', 'Upload Document'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php pageIntro('documents.index'); ?>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-primary mb-1"><i class="bi bi-files" style="font-size:1.5rem;"></i></div>
                <div class="fw-bold fs-5"><?php echo number_format($totalRecords); ?></div>
                <small class="text-muted"><?php _e('doc_total_documents', 'Total Documents'); ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-success mb-1"><i class="bi bi-hdd" style="font-size:1.5rem;"></i></div>
                <div class="fw-bold fs-5"><?php echo fmtSize($totalSize); ?></div>
                <small class="text-muted"><?php _e('doc_storage_used', 'Storage Used'); ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-warning mb-1"><i class="bi bi-speedometer2" style="font-size:1.5rem;"></i></div>
                <div class="fw-bold fs-5"><?php echo $usagePercent; ?>%</div>
                <small class="text-muted"><?php echo fmtSize($totalSize) . ' / ' . $quotaMb . ' MB'; ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="text-info mb-1"><i class="bi bi-grid" style="font-size:1.5rem;"></i></div>
                <div class="fw-bold fs-5"><?php echo count($categories); ?></div>
                <small class="text-muted"><?php _e('doc_categories', 'Categories'); ?></small>
            </div>
        </div>
    </div>
</div>

<!-- Storage Quota Bar -->
<?php if ($usagePercent > 0): ?>
<div class="mb-4">
    <div class="progress" style="height: 6px;">
        <div class="progress-bar bg-<?php echo $usagePercent > 90 ? 'danger' : ($usagePercent > 70 ? 'warning' : 'success'); ?>"
             style="width: <?php echo min($usagePercent, 100); ?>%"></div>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:0.78rem;"><?php _e('search'); ?></label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="<?php _e('doc_search_ph', 'Search title, file...'); ?>" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:0.78rem;"><?php _e('doc_category', 'Category'); ?></label>
                <select name="category" class="form-select form-select-sm">
                    <option value=""><?php _e('all'); ?></option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $filterCategory == $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:0.78rem;"><?php _e('doc_linked_to', 'Linked To'); ?></label>
                <select name="entity_type" class="form-select form-select-sm">
                    <option value=""><?php _e('all'); ?></option>
                    <option value="general" <?php echo $filterEntityType === 'general' ? 'selected' : ''; ?>><?php _e('doc_general', 'General'); ?></option>
                    <option value="property" <?php echo $filterEntityType === 'property' ? 'selected' : ''; ?>><?php _e('property'); ?></option>
                    <option value="unit" <?php echo $filterEntityType === 'unit' ? 'selected' : ''; ?>><?php _e('unit'); ?></option>
                    <option value="tenant" <?php echo $filterEntityType === 'tenant' ? 'selected' : ''; ?>><?php _e('tenant'); ?></option>
                    <option value="lease" <?php echo $filterEntityType === 'lease' ? 'selected' : ''; ?>><?php _e('lease'); ?></option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label mb-1" style="font-size:0.78rem;"><?php _e('status'); ?></label>
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all'); ?></option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>><?php _e('active'); ?></option>
                    <option value="archived" <?php echo $filterStatus === 'archived' ? 'selected' : ''; ?>><?php _e('doc_archived_status', 'Archived'); ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:0.78rem;"><?php _e('from_date', 'From'); ?></label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label mb-1" style="font-size:0.78rem;"><?php _e('to_date', 'To'); ?></label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Record count -->
<div class="d-flex justify-content-between align-items-center mb-2">
    <small class="text-muted"><?php echo number_format($totalRecords); ?> <?php _e('records'); ?></small>
    <?php if (hasPermission('documents', 'delete')): ?>
    <button class="btn btn-outline-danger btn-sm d-none" id="bulkDeleteBtn" onclick="bulkDelete()">
        <i class="bi bi-trash me-1"></i><span id="bulkCount">0</span> <?php _e('doc_delete_selected', 'Delete Selected'); ?>
    </button>
    <?php endif; ?>
</div>

<!-- Document Table -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:0.85rem;">
            <thead class="table-light">
                <tr>
                    <?php if (hasPermission('documents', 'delete')): ?>
                    <th style="width:30px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
                    <?php endif; ?>
                    <th style="width:30px;"></th>
                    <th><?php _e('doc_title', 'Title'); ?></th>
                    <th><?php _e('doc_category', 'Category'); ?></th>
                    <th><?php _e('doc_linked_to', 'Linked To'); ?></th>
                    <th><?php _e('doc_size', 'Size'); ?></th>
                    <th><?php _e('doc_uploaded_by', 'Uploaded By'); ?></th>
                    <th><?php _e('date'); ?></th>
                    <th style="width:100px;"><?php _e('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($documents)): ?>
                <tr><td colspan="<?php echo hasPermission('documents', 'delete') ? 9 : 8; ?>" class="text-center text-muted py-4">
                    <i class="bi bi-folder2-open d-block" style="font-size:2rem;opacity:0.3;"></i>
                    <?php _e('doc_no_documents', 'No documents found'); ?>
                </td></tr>
                <?php else: foreach ($documents as $doc):
                    $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
                    $iconClass = fileIcon($doc['mime_type'] ?? '', $ext);
                    $entityName = getEntityName($conn, $doc['entity_type'], $doc['entity_id']);
                ?>
                <tr>
                    <?php if (hasPermission('documents', 'delete')): ?>
                    <td><input type="checkbox" class="doc-check" value="<?php echo $doc['id']; ?>" onchange="updateBulk()"></td>
                    <?php endif; ?>
                    <td><i class="bi <?php echo $iconClass; ?>" style="font-size:1.2rem;"></i></td>
                    <td>
                        <a href="<?php echo BASE_URL; ?>modules/documents/view.php?id=<?php echo $doc['id']; ?>" class="fw-semibold text-decoration-none">
                            <?php echo htmlspecialchars($doc['title']); ?>
                        </a>
                        <?php if ($doc['is_confidential']): ?>
                        <i class="bi bi-lock-fill text-warning ms-1" title="<?php _e('doc_confidential', 'Confidential'); ?>"></i>
                        <?php endif; ?>
                        <?php if ($doc['status'] === 'archived'): ?>
                        <span class="badge bg-secondary ms-1"><?php _e('doc_archived_status', 'Archived'); ?></span>
                        <?php endif; ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($doc['file_name']); ?></small>
                    </td>
                    <td>
                        <?php if ($doc['category_name']): ?>
                        <span class="badge" style="background:<?php echo htmlspecialchars($doc['category_color']); ?>;">
                            <i class="bi <?php echo htmlspecialchars($doc['category_icon']); ?> me-1"></i><?php echo htmlspecialchars($doc['category_name']); ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($entityName): ?>
                        <small>
                            <span class="badge bg-light text-dark"><?php echo ucfirst($doc['entity_type']); ?></span>
                            <?php echo htmlspecialchars($entityName); ?>
                        </small>
                        <?php else: ?>
                        <small class="text-muted"><?php _e('doc_general', 'General'); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><small><?php echo fmtSize((int)$doc['file_size']); ?></small></td>
                    <td><small><?php echo htmlspecialchars($doc['uploader_name'] ?? '—'); ?></small></td>
                    <td><small><?php echo formatDate($doc['created_at']); ?></small></td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="<?php echo BASE_URL; ?>api/documents.php?action=download&id=<?php echo $doc['id']; ?>" class="btn btn-outline-primary" title="<?php _e('download'); ?>">
                                <i class="bi bi-download"></i>
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/documents/view.php?id=<?php echo $doc['id']; ?>" class="btn btn-outline-secondary" title="<?php _e('view'); ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (hasPermission('documents', 'delete')): ?>
                            <button class="btn btn-outline-danger" onclick="deleteDoc(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['title'])); ?>')" title="<?php _e('delete'); ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($pagination['total_pages'] > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php
        $qp = $_GET;
        if ($pagination['has_prev']):
            $qp['page'] = $page - 1;
        ?>
        <li class="page-item"><a class="page-link" href="?<?php echo http_build_query($qp); ?>">&laquo;</a></li>
        <?php endif; ?>

        <?php
        $start = max(1, $page - 2);
        $end = min($pagination['total_pages'], $page + 2);
        for ($p = $start; $p <= $end; $p++):
            $qp['page'] = $p;
        ?>
        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query($qp); ?>"><?php echo $p; ?></a>
        </li>
        <?php endfor; ?>

        <?php if ($pagination['has_next']):
            $qp['page'] = $page + 1;
        ?>
        <li class="page-item"><a class="page-link" href="?<?php echo http_build_query($qp); ?>">&raquo;</a></li>
        <?php endif; ?>
    </ul>
</nav>
<?php endif; ?>

<script>
const API_URL = BASE_URL + 'api/documents.php';

function deleteDoc(id, title) {
    if (!confirm('<?php _e('doc_delete_confirm', 'Delete this document?'); ?> "' + title + '"')) return;

    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);

    fetch(API_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { if (res.success) location.reload(); else alert(res.message); });
}

function toggleSelectAll(el) {
    document.querySelectorAll('.doc-check').forEach(cb => cb.checked = el.checked);
    updateBulk();
}

function updateBulk() {
    const checked = document.querySelectorAll('.doc-check:checked');
    const btn = document.getElementById('bulkDeleteBtn');
    if (btn) {
        btn.classList.toggle('d-none', checked.length === 0);
        document.getElementById('bulkCount').textContent = checked.length;
    }
}

function bulkDelete() {
    const ids = Array.from(document.querySelectorAll('.doc-check:checked')).map(cb => cb.value);
    if (!ids.length) return;
    if (!confirm('<?php _e('doc_bulk_delete_confirm', 'Delete selected documents?'); ?> (' + ids.length + ')')) return;

    const fd = new FormData();
    fd.append('action', 'bulk_delete');
    fd.append('ids', ids.join(','));

    fetch(API_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { if (res.success) location.reload(); else alert(res.message); });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
