<?php
/**
 * FIS Real Estate — Document View / Edit (Part 18.3)
 * View document details, preview, edit metadata, download.
 * Professional+ tier required.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('doc_view_title', 'View Document');

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

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert(__('doc_not_found', 'Document not found.'), 'danger');
    redirect('modules/documents/');
}

// Fetch document
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
    setAlert(__('doc_not_found', 'Document not found.'), 'danger');
    redirect('modules/documents/');
}

// Fetch categories
$categories = $conn->query("SELECT * FROM document_categories ORDER BY sort_order")->fetchAll();

// Parse tags
$tags = [];
if ($doc['tags']) {
    $decoded = json_decode($doc['tags'], true);
    if (is_array($decoded)) $tags = $decoded;
}

// File helpers
function fmtSizeView($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function fileIconView($mime, $ext = '') {
    if (strpos($mime, 'image') !== false) return 'bi-file-image text-success';
    if (strpos($mime, 'pdf') !== false) return 'bi-file-pdf text-danger';
    if (strpos($mime, 'word') !== false || $ext === 'doc' || $ext === 'docx') return 'bi-file-word text-primary';
    if (strpos($mime, 'spreadsheet') !== false || strpos($mime, 'excel') !== false || $ext === 'xlsx' || $ext === 'csv') return 'bi-file-excel text-success';
    if (strpos($mime, 'zip') !== false || strpos($mime, 'rar') !== false) return 'bi-file-zip text-warning';
    if (strpos($mime, 'text') !== false) return 'bi-file-text text-secondary';
    return 'bi-file-earmark text-muted';
}

// Entity name
function getEntityNameView($conn, $type, $eid) {
    if (!$eid || $type === 'general') return '';
    try {
        switch ($type) {
            case 'property':
                $s = $conn->prepare("SELECT name FROM properties WHERE id = ?"); $s->execute([$eid]); return $s->fetchColumn() ?: '';
            case 'unit':
                $s = $conn->prepare("SELECT CONCAT(u.unit_number, ' (', p.name, ')') FROM units u INNER JOIN properties p ON u.property_id = p.id WHERE u.id = ?"); $s->execute([$eid]); return $s->fetchColumn() ?: '';
            case 'tenant':
                $s = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM tenants WHERE id = ?"); $s->execute([$eid]); return $s->fetchColumn() ?: '';
            case 'lease':
                $s = $conn->prepare("SELECT CONCAT(t.first_name, ' ', t.last_name, ' — ', u.unit_number) FROM leases l INNER JOIN tenants t ON l.tenant_id = t.id INNER JOIN units u ON l.unit_id = u.id WHERE l.id = ?"); $s->execute([$eid]); return $s->fetchColumn() ?: '';
        }
    } catch (Exception $e) {}
    return '';
}

$ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
$iconClass = fileIconView($doc['mime_type'] ?? '', $ext);
$entityName = getEntityNameView($conn, $doc['entity_type'], $doc['entity_id']);
$isImage = strpos($doc['mime_type'] ?? '', 'image') !== false;
$isPdf = strpos($doc['mime_type'] ?? '', 'pdf') !== false;
$filePath = UPLOAD_PATH . $doc['file_path'];
$fileExists = file_exists($filePath);

// Log view activity
logActivity('viewed', 'document', $id, ['title' => $doc['title']]);

$pageTitle = htmlspecialchars($doc['title']) . ' — ' . __('doc_documents', 'Documents');
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h4 class="mb-1">
            <i class="bi <?php echo $iconClass; ?> me-2" style="font-size:1.3rem;"></i>
            <?php echo htmlspecialchars($doc['title']); ?>
            <?php if ($doc['is_confidential']): ?>
            <i class="bi bi-lock-fill text-warning ms-1" title="<?php _e('doc_confidential', 'Confidential'); ?>"></i>
            <?php endif; ?>
            <?php if ($doc['status'] === 'archived'): ?>
            <span class="badge bg-secondary ms-1" style="font-size:0.65rem;"><?php _e('doc_archived_status', 'Archived'); ?></span>
            <?php endif; ?>
        </h4>
        <p class="text-muted mb-0" style="font-size:0.85rem;">
            <?php echo htmlspecialchars($doc['file_name']); ?> &bull; <?php echo fmtSizeView((int)$doc['file_size']); ?>
        </p>
    </div>
    <div class="d-flex gap-1 flex-wrap">
        <a href="<?php echo BASE_URL; ?>api/documents.php?action=download&id=<?php echo $doc['id']; ?>" class="btn btn-sm btn-primary">
            <i class="bi bi-download me-1"></i> <?php _e('download'); ?>
        </a>
        <?php if (hasPermission('documents', 'edit')): ?>
        <button class="btn btn-sm btn-outline-secondary" onclick="toggleEdit()">
            <i class="bi bi-pencil me-1"></i> <?php _e('edit'); ?>
        </button>
        <button class="btn btn-sm btn-outline-secondary" onclick="archiveDoc()">
            <i class="bi bi-archive me-1"></i> <?php echo $doc['status'] === 'archived' ? __('doc_restore', 'Restore') : __('doc_archive', 'Archive'); ?>
        </button>
        <?php endif; ?>
        <?php if (hasPermission('documents', 'delete')): ?>
        <button class="btn btn-sm btn-outline-danger" onclick="deleteDoc()">
            <i class="bi bi-trash me-1"></i> <?php _e('delete'); ?>
        </button>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>modules/documents/" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> <?php _e('doc_back_to_list', 'Back to Documents'); ?>
        </a>
    </div>
</div>

<?php pageIntro('documents.view'); ?>

<div class="row g-4">
    <!-- Left: Preview / File Info -->
    <div class="col-lg-8">
        <!-- Preview -->
        <?php if ($fileExists && $isImage): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-2">
                <h6 class="mb-0"><i class="bi bi-eye me-2"></i><?php _e('doc_preview', 'Preview'); ?></h6>
            </div>
            <div class="card-body text-center p-3" style="background:#f8f9fa;">
                <img src="<?php echo BASE_URL . 'api/file.php?type=document&amp;id=' . (int)$doc['id']; ?>"
                     alt="<?php echo htmlspecialchars($doc['title']); ?>"
                     class="img-fluid rounded" style="max-height:500px;">
            </div>
        </div>
        <?php elseif ($fileExists && $isPdf): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-2">
                <h6 class="mb-0"><i class="bi bi-eye me-2"></i><?php _e('doc_preview', 'Preview'); ?></h6>
            </div>
            <div class="card-body p-0">
                <iframe src="<?php echo BASE_URL . 'api/file.php?type=document&amp;id=' . (int)$doc['id']; ?>"
                        style="width:100%;height:600px;border:none;"></iframe>
            </div>
        </div>
        <?php else: ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body text-center py-5" style="background:#f8f9fa;">
                <i class="bi <?php echo $iconClass; ?> d-block mb-2" style="font-size:4rem;opacity:0.4;"></i>
                <h6 class="text-muted"><?php _e('doc_no_preview', 'No preview available for this file type'); ?></h6>
                <?php if (!$fileExists): ?>
                <div class="text-danger mt-2"><i class="bi bi-exclamation-triangle me-1"></i><?php _e('doc_file_missing', 'File not found on disk'); ?></div>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>api/documents.php?action=download&id=<?php echo $doc['id']; ?>" class="btn btn-primary btn-sm mt-3">
                    <i class="bi bi-download me-1"></i> <?php _e('doc_download_to_view', 'Download to view'); ?>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Edit Form (hidden initially) -->
        <div id="editForm" class="card border-0 shadow-sm mb-3 d-none">
            <div class="card-header bg-white py-2">
                <h6 class="mb-0"><i class="bi bi-pencil me-2"></i><?php _e('doc_edit_metadata', 'Edit Document Details'); ?></h6>
            </div>
            <div class="card-body">
                <form id="metadataForm" onsubmit="saveMetadata(event)">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('doc_title', 'Title'); ?> *</label>
                            <input type="text" id="editTitle" class="form-control form-control-sm" value="<?php echo htmlspecialchars($doc['title']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('doc_category', 'Category'); ?></label>
                            <select id="editCategory" class="form-select form-select-sm">
                                <option value=""><?php _e('doc_no_category', 'No Category'); ?></option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo (int)$doc['category_id'] === (int)$cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php _e('description'); ?></label>
                            <textarea id="editDescription" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($doc['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php _e('doc_link_to', 'Link to Entity'); ?></label>
                            <select id="editEntityType" class="form-select form-select-sm" onchange="loadEditEntityList()">
                                <option value="general" <?php echo $doc['entity_type'] === 'general' ? 'selected' : ''; ?>><?php _e('doc_general', 'General (no link)'); ?></option>
                                <option value="property" <?php echo $doc['entity_type'] === 'property' ? 'selected' : ''; ?>><?php _e('property'); ?></option>
                                <option value="unit" <?php echo $doc['entity_type'] === 'unit' ? 'selected' : ''; ?>><?php _e('unit'); ?></option>
                                <option value="tenant" <?php echo $doc['entity_type'] === 'tenant' ? 'selected' : ''; ?>><?php _e('tenant'); ?></option>
                                <option value="lease" <?php echo $doc['entity_type'] === 'lease' ? 'selected' : ''; ?>><?php _e('lease'); ?></option>
                                <option value="payment" <?php echo $doc['entity_type'] === 'payment' ? 'selected' : ''; ?>><?php _e('payment', 'Payment'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-4" id="editEntityIdWrapper" <?php echo $doc['entity_type'] === 'general' ? 'style="display:none"' : ''; ?>>
                            <label class="form-label"><?php _e('doc_select_entity', 'Select'); ?></label>
                            <select id="editEntityId" class="form-select form-select-sm">
                                <option value="">—</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php _e('doc_expiry', 'Expiry Date'); ?></label>
                            <input type="date" id="editExpiry" class="form-control form-control-sm" value="<?php echo htmlspecialchars($doc['expiry_date'] ?? ''); ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label"><?php _e('doc_tags', 'Tags'); ?></label>
                            <input type="text" id="editTags" class="form-control form-control-sm" value="<?php echo htmlspecialchars(implode(', ', $tags)); ?>" placeholder="<?php _e('doc_tags_ph', 'Comma-separated tags'); ?>">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="editConfidential" <?php echo $doc['is_confidential'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="editConfidential"><?php _e('doc_confidential', 'Confidential'); ?></label>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-check-lg me-1"></i> <?php _e('save'); ?>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleEdit()">
                            <?php _e('cancel'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right: Details Panel -->
    <div class="col-lg-4">
        <!-- File Details -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-2">
                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i><?php _e('doc_details', 'Details'); ?></h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:0.85rem;">
                    <tr>
                        <td class="text-muted" style="width:35%;"><?php _e('doc_file_name', 'File Name'); ?></td>
                        <td class="text-break"><?php echo htmlspecialchars($doc['file_name']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('doc_size', 'Size'); ?></td>
                        <td><?php echo fmtSizeView((int)$doc['file_size']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('doc_type', 'Type'); ?></td>
                        <td><?php echo htmlspecialchars($doc['mime_type'] ?? 'Unknown'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('doc_category', 'Category'); ?></td>
                        <td>
                            <?php if ($doc['category_name']): ?>
                            <span class="badge" style="background:<?php echo htmlspecialchars($doc['category_color']); ?>;">
                                <i class="bi <?php echo htmlspecialchars($doc['category_icon']); ?> me-1"></i><?php echo htmlspecialchars($doc['category_name']); ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('doc_linked_to', 'Linked To'); ?></td>
                        <td>
                            <?php if ($entityName): ?>
                            <span class="badge bg-light text-dark"><?php echo ucfirst($doc['entity_type']); ?></span>
                            <?php echo htmlspecialchars($entityName); ?>
                            <?php else: ?>
                            <span class="text-muted"><?php _e('doc_general', 'General'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('status'); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $doc['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                <?php echo ucfirst($doc['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php if ($doc['is_confidential']): ?>
                    <tr>
                        <td class="text-muted"><?php _e('doc_access', 'Access'); ?></td>
                        <td><span class="badge bg-warning text-dark"><i class="bi bi-lock me-1"></i><?php _e('doc_confidential', 'Confidential'); ?></span></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($doc['expiry_date']): ?>
                    <tr>
                        <td class="text-muted"><?php _e('doc_expiry', 'Expiry Date'); ?></td>
                        <td>
                            <?php
                            echo formatDate($doc['expiry_date']);
                            if (strtotime($doc['expiry_date']) < time()) {
                                echo ' <span class="badge bg-danger">' . __('doc_expired', 'Expired') . '</span>';
                            } elseif (strtotime($doc['expiry_date']) < strtotime('+30 days')) {
                                echo ' <span class="badge bg-warning text-dark">' . __('doc_expiring_soon', 'Expiring Soon') . '</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($tags)): ?>
                    <tr>
                        <td class="text-muted"><?php _e('doc_tags', 'Tags'); ?></td>
                        <td>
                            <?php foreach ($tags as $tag): ?>
                            <span class="badge bg-light text-dark border me-1"><?php echo htmlspecialchars($tag); ?></span>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($doc['description']): ?>
                    <tr>
                        <td class="text-muted"><?php _e('description'); ?></td>
                        <td><?php echo nl2br(htmlspecialchars($doc['description'])); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Upload Info -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-2">
                <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i><?php _e('doc_upload_info', 'Upload Info'); ?></h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:0.85rem;">
                    <tr>
                        <td class="text-muted" style="width:35%;"><?php _e('doc_uploaded_by', 'Uploaded By'); ?></td>
                        <td><?php echo htmlspecialchars($doc['uploader_name'] ?? '—'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted"><?php _e('doc_uploaded_at', 'Uploaded At'); ?></td>
                        <td><?php echo formatDateTime($doc['created_at']); ?></td>
                    </tr>
                    <?php if ($doc['updated_at'] !== $doc['created_at']): ?>
                    <tr>
                        <td class="text-muted"><?php _e('doc_updated_at', 'Last Modified'); ?></td>
                        <td><?php echo formatDateTime($doc['updated_at']); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const API_URL = BASE_URL + 'api/documents.php';
const DOC_ID = <?php echo $doc['id']; ?>;
let dropdownsData = null;

function toggleEdit() {
    const form = document.getElementById('editForm');
    form.classList.toggle('d-none');
    const isOpen = !form.classList.contains('d-none');
    if (isOpen && '<?php echo $doc['entity_type']; ?>' !== 'general') {
        loadEditEntityList();
    }
    if (isOpen) {
        // Bring the edit form into view (it sits below a tall preview).
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        document.getElementById('editTitle').focus({ preventScroll: true });
    }
}

function saveMetadata(e) {
    e.preventDefault();

    const fd = new FormData();
    fd.append('action', 'update');
    fd.append('id', DOC_ID);
    fd.append('title', document.getElementById('editTitle').value);
    fd.append('description', document.getElementById('editDescription').value);
    fd.append('category_id', document.getElementById('editCategory').value);
    fd.append('entity_type', document.getElementById('editEntityType').value);
    fd.append('entity_id', document.getElementById('editEntityId').value || '');
    fd.append('expiry_date', document.getElementById('editExpiry').value);
    fd.append('tags', document.getElementById('editTags').value);
    if (document.getElementById('editConfidential').checked) {
        fd.append('is_confidential', '1');
    }

    fetch(API_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                location.reload();
            } else {
                alert(res.message);
            }
        });
}

function archiveDoc() {
    const action = '<?php echo $doc['status'] === 'archived' ? 'Restore' : 'Archive'; ?>';
    if (!confirm(action + ' this document?')) return;

    const fd = new FormData();
    fd.append('action', 'archive');
    fd.append('id', DOC_ID);

    fetch(API_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { if (res.success) location.reload(); else alert(res.message); });
}

function deleteDoc() {
    if (!confirm('<?php _e('doc_delete_confirm', 'Delete this document?'); ?>')) return;

    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', DOC_ID);

    fetch(API_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                window.location.href = BASE_URL + 'modules/documents/';
            } else {
                alert(res.message);
            }
        });
}

// Entity dropdown for edit form
function loadEditEntityList() {
    const type = document.getElementById('editEntityType').value;
    const wrapper = document.getElementById('editEntityIdWrapper');
    const select = document.getElementById('editEntityId');

    if (type === 'general') {
        wrapper.style.display = 'none';
        select.innerHTML = '<option value="">—</option>';
        return;
    }

    wrapper.style.display = '';
    select.innerHTML = '<option value=""><?php _e('loading', 'Loading...'); ?></option>';

    if (dropdownsData) {
        populateEditEntitySelect(type);
        return;
    }

    fetch(API_URL + '?action=dropdowns')
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                dropdownsData = res.data;
                populateEditEntitySelect(type);
            }
        });
}

function populateEditEntitySelect(type) {
    const select = document.getElementById('editEntityId');
    const currentId = '<?php echo $doc['entity_id'] ?? ''; ?>';
    select.innerHTML = '<option value="">— <?php _e('doc_select_entity', 'Select'); ?> —</option>';

    let items = [];
    if (type === 'property') items = dropdownsData.properties.map(p => ({ id: p.id, label: p.name }));
    else if (type === 'unit') items = dropdownsData.units.map(u => ({ id: u.id, label: u.unit_number + ' (' + u.property_name + ')' }));
    else if (type === 'tenant') items = dropdownsData.tenants.map(t => ({ id: t.id, label: t.name }));
    else if (type === 'lease') items = dropdownsData.leases.map(l => ({ id: l.id, label: l.label }));
    else if (type === 'payment') items = dropdownsData.payments.map(p => ({ id: p.id, label: p.label }));

    items.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.id;
        opt.textContent = item.label;
        if (String(item.id) === String(currentId)) opt.selected = true;
        select.appendChild(opt);
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
