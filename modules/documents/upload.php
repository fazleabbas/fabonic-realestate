<?php
/**
 * FIS Real Estate — Document Upload (Part 18.2)
 * Drag-drop & multi-file upload. Professional+ tier required.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('doc_upload_title', 'Upload Documents');

$subStatus = enforceSubscription();

if (!hasTier('professional')) {
    setAlert(__('doc_tier_required', 'Professional tier or higher required for document management.'), 'warning');
    redirect('index.php');
}
if (getSetting('documents_enabled', '0') !== '1') {
    setAlert(__('doc_disabled', 'Documents module is disabled. Enable it in Settings > Modules.'), 'warning');
    redirect('modules/settings/?tab=modules');
}
if (!hasPermission('documents', 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

// Fetch categories
$categories = $conn->query("SELECT * FROM document_categories ORDER BY sort_order")->fetchAll();

// Storage stats
$totalSize = (int)$conn->query("SELECT COALESCE(SUM(file_size), 0) FROM documents")->fetchColumn();
$quotaMb = (int)getSetting('storage_quota_mb', 500);
$quotaBytes = $quotaMb * 1024 * 1024;
$usagePercent = $quotaBytes > 0 ? round(($totalSize / $quotaBytes) * 100, 1) : 0;
$remainingBytes = max(0, $quotaBytes - $totalSize);

function fmtSizeUp($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// Pre-fill entity from query params (when linking from another module)
$prefillEntityType = clean($_GET['entity_type'] ?? 'general');
$prefillEntityId = (int)($_GET['entity_id'] ?? 0);

// Context-aware "back" target: when opened from an entity page, return there instead of the documents list.
$backUrl = BASE_URL . 'modules/documents/';
$backLabel = __('doc_back_to_list', 'Back to Documents');
if ($prefillEntityId > 0) {
    $entityBack = [
        'tenant'   => ['modules/tenants/view.php?id=',    __('doc_back_to_tenant', 'Back to Tenant')],
        'payment'  => ['modules/payments/view.php?id=',   __('doc_back_to_payment', 'Back to Payment')],
        'property' => ['modules/properties/view.php?id=', __('doc_back_to_property', 'Back to Property')],
        'unit'     => ['modules/units/view.php?id=',      __('doc_back_to_unit', 'Back to Unit')],
        'lease'    => ['modules/leases/view.php?id=',     __('doc_back_to_lease', 'Back to Lease')],
    ];
    if (isset($entityBack[$prefillEntityType])) {
        $backUrl = BASE_URL . $entityBack[$prefillEntityType][0] . $prefillEntityId;
        $backLabel = $entityBack[$prefillEntityType][1];
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h4 class="mb-1"><i class="bi bi-cloud-upload me-2"></i><?php _e('doc_upload_title', 'Upload Documents'); ?></h4>
        <p class="text-muted mb-0"><?php _e('doc_upload_desc', 'Upload single or multiple files with metadata'); ?></p>
    </div>
    <a href="<?php echo $backUrl; ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> <?php echo htmlspecialchars($backLabel); ?>
    </a>
</div>

<?php pageIntro('documents.upload'); ?>

<!-- Storage Quota Warning -->
<?php if ($usagePercent > 80): ?>
<div class="alert alert-<?php echo $usagePercent > 95 ? 'danger' : 'warning'; ?> alert-dismissible py-2 mb-3">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <?php echo sprintf(__('doc_quota_warning', 'Storage usage at %s%%. %s remaining of %s MB.'), $usagePercent, fmtSizeUp($remainingBytes), $quotaMb); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Left: Upload Zone -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0"><i class="bi bi-files me-2"></i><?php _e('doc_select_files', 'Select Files'); ?></h6>
            </div>
            <div class="card-body">
                <!-- Drag & Drop Zone -->
                <div id="dropZone" class="text-center p-5 rounded-3 mb-3" style="border:2px dashed #ccc;cursor:pointer;transition:all 0.2s;background:#fafbfc;">
                    <i class="bi bi-cloud-arrow-up d-block mb-2" style="font-size:3rem;color:#1a6b4a;opacity:0.6;"></i>
                    <h6 class="mb-1"><?php _e('doc_drag_drop', 'Drag & drop files here'); ?></h6>
                    <p class="text-muted mb-2"><?php _e('doc_or_click', 'or click to browse'); ?></p>
                    <small class="text-muted">
                        <?php echo sprintf(__('doc_allowed_types', 'Allowed: %s'), 'jpg, png, gif, webp, pdf, doc, docx, xlsx, csv, txt, zip, rar'); ?>
                        &nbsp;|&nbsp;
                        <?php echo sprintf(__('doc_max_size', 'Max %s per file'), '10 MB'); ?>
                    </small>
                    <input type="file" id="fileInput" multiple class="d-none"
                           accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xlsx,.csv,.txt,.zip,.rar">
                </div>

                <!-- File Queue -->
                <div id="fileQueue" class="d-none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0"><i class="bi bi-list-ul me-1"></i> <span id="queueCount">0</span> <?php _e('doc_files_selected', 'file(s) selected'); ?></h6>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearQueue()">
                            <i class="bi bi-x-lg me-1"></i><?php _e('doc_clear_all', 'Clear All'); ?>
                        </button>
                    </div>
                    <div id="fileList" class="list-group list-group-flush" style="max-height:300px;overflow-y:auto;"></div>
                </div>
            </div>
        </div>

        <!-- Upload Progress (hidden initially) -->
        <div id="uploadProgress" class="card border-0 shadow-sm mt-3 d-none">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0" id="progressHeader"><i class="bi bi-arrow-repeat me-1 spin"></i> <?php _e('doc_uploading', 'Uploading...'); ?></h6>
                    <span id="progressText" class="text-muted">0%</span>
                </div>
                <div class="progress" style="height:8px;">
                    <div id="progressBar" class="progress-bar bg-primary progress-bar-striped progress-bar-animated" style="width:0%"></div>
                </div>
                <div id="uploadResults" class="mt-3"></div>
            </div>
        </div>
    </div>

    <!-- Right: Options & Submit -->
    <div class="col-lg-4">
        <!-- Single File Metadata (shown when exactly 1 file selected) -->
        <div id="singleMeta" class="card border-0 shadow-sm mb-3 d-none">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i><?php _e('doc_metadata', 'Document Details'); ?></h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label"><?php _e('doc_title', 'Title'); ?></label>
                    <input type="text" id="docTitle" class="form-control form-control-sm" placeholder="<?php _e('doc_title_ph', 'e.g. Lease Agreement - Unit A1'); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php _e('description'); ?></label>
                    <textarea id="docDescription" class="form-control form-control-sm" rows="2" placeholder="<?php _e('doc_desc_ph', 'Optional description'); ?>"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php _e('doc_tags', 'Tags'); ?></label>
                    <input type="text" id="docTags" class="form-control form-control-sm" placeholder="<?php _e('doc_tags_ph', 'Comma-separated tags'); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php _e('doc_expiry', 'Expiry Date'); ?></label>
                    <input type="date" id="docExpiry" class="form-control form-control-sm">
                </div>
            </div>
        </div>

        <!-- Common Options -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0"><i class="bi bi-gear me-2"></i><?php _e('doc_options', 'Options'); ?></h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label"><?php _e('doc_category', 'Category'); ?></label>
                    <select id="docCategory" class="form-select form-select-sm">
                        <option value=""><?php _e('doc_no_category', 'No Category'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>">
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label"><?php _e('doc_link_to', 'Link to Entity'); ?></label>
                    <select id="docEntityType" class="form-select form-select-sm" onchange="loadEntityList()">
                        <option value="general" <?php echo $prefillEntityType === 'general' ? 'selected' : ''; ?>><?php _e('doc_general', 'General (no link)'); ?></option>
                        <option value="property" <?php echo $prefillEntityType === 'property' ? 'selected' : ''; ?>><?php _e('property'); ?></option>
                        <option value="unit" <?php echo $prefillEntityType === 'unit' ? 'selected' : ''; ?>><?php _e('unit'); ?></option>
                        <option value="tenant" <?php echo $prefillEntityType === 'tenant' ? 'selected' : ''; ?>><?php _e('tenant'); ?></option>
                        <option value="lease" <?php echo $prefillEntityType === 'lease' ? 'selected' : ''; ?>><?php _e('lease'); ?></option>
                        <option value="payment" <?php echo $prefillEntityType === 'payment' ? 'selected' : ''; ?>><?php _e('payment', 'Payment'); ?></option>
                    </select>
                </div>

                <div id="entityIdWrapper" class="mb-3 <?php echo $prefillEntityType === 'general' ? 'd-none' : ''; ?>">
                    <label class="form-label"><?php _e('doc_select_entity', 'Select'); ?></label>
                    <select id="docEntityId" class="form-select form-select-sm">
                        <option value="">— <?php _e('doc_select_entity', 'Select'); ?> —</option>
                    </select>
                </div>

                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" id="docConfidential">
                    <label class="form-check-label" for="docConfidential"><?php _e('doc_confidential', 'Confidential'); ?></label>
                </div>
            </div>
        </div>

        <!-- Submit Button -->
        <button type="button" id="btnUpload" class="btn btn-primary w-100 mb-3" onclick="startUpload()" disabled>
            <i class="bi bi-cloud-upload me-1"></i> <?php _e('doc_upload_now', 'Upload Now'); ?>
        </button>

        <!-- Storage Info -->
        <div class="card border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between mb-1">
                    <small class="text-muted"><?php _e('doc_storage', 'Storage'); ?></small>
                    <small class="text-muted"><?php echo fmtSizeUp($totalSize) . ' / ' . $quotaMb . ' MB'; ?></small>
                </div>
                <div class="progress" style="height:5px;">
                    <div class="progress-bar bg-<?php echo $usagePercent > 90 ? 'danger' : ($usagePercent > 70 ? 'warning' : 'success'); ?>"
                         style="width:<?php echo min($usagePercent, 100); ?>%"></div>
                </div>
                <small class="text-muted d-block mt-1"><?php echo fmtSizeUp($remainingBytes); ?> <?php _e('doc_remaining', 'remaining'); ?></small>
            </div>
        </div>
    </div>
</div>

<style>
#dropZone.drag-over {
    border-color: #1a6b4a !important;
    background: #e8f5ee !important;
}
.file-item {
    display: flex;
    align-items: center;
    padding: 0.5rem 0.75rem;
    border-bottom: 1px solid #f0f0f0;
}
.file-item:last-child { border-bottom: none; }
.file-item .file-icon { font-size: 1.3rem; margin-right: 0.75rem; flex-shrink: 0; }
.file-item .file-info { flex: 1; min-width: 0; }
.file-item .file-name { font-size: 0.85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.file-item .file-size { font-size: 0.75rem; color: #888; }
.file-item .file-remove { cursor: pointer; color: #dc3545; opacity: 0.6; }
.file-item .file-remove:hover { opacity: 1; }
.file-item .file-status { font-size: 0.85rem; margin-left: 0.5rem; }
.spin { animation: spin 1s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>

<script>
const API_URL = BASE_URL + 'api/documents.php';
let selectedFiles = [];
let dropdownsData = null;

// ============================
// Drag & Drop
// ============================
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');

dropZone.addEventListener('click', () => fileInput.click());

dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('drag-over');
});
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    addFiles(e.dataTransfer.files);
});

fileInput.addEventListener('change', () => {
    addFiles(fileInput.files);
    fileInput.value = '';
});

function addFiles(fileList) {
    const allowed = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xlsx','csv','txt','zip','rar'];
    const maxSize = 10 * 1024 * 1024;

    for (let f of fileList) {
        const ext = f.name.split('.').pop().toLowerCase();
        if (!allowed.includes(ext)) {
            alert('<?php _e('doc_type_not_allowed', 'File type not allowed'); ?>: ' + f.name);
            continue;
        }
        if (f.size > maxSize) {
            alert('<?php _e('doc_too_large', 'File too large (max 10MB)'); ?>: ' + f.name);
            continue;
        }
        // Avoid duplicates
        if (selectedFiles.some(sf => sf.name === f.name && sf.size === f.size)) continue;
        selectedFiles.push(f);
    }
    renderQueue();
}

function renderQueue() {
    const queue = document.getElementById('fileQueue');
    const list = document.getElementById('fileList');
    const count = document.getElementById('queueCount');
    const btn = document.getElementById('btnUpload');
    const singleMeta = document.getElementById('singleMeta');

    if (selectedFiles.length === 0) {
        queue.classList.add('d-none');
        btn.disabled = true;
        singleMeta.classList.add('d-none');
        return;
    }

    queue.classList.remove('d-none');
    btn.disabled = false;
    count.textContent = selectedFiles.length;

    // Show single-file metadata when exactly 1 file
    if (selectedFiles.length === 1) {
        singleMeta.classList.remove('d-none');
        if (!document.getElementById('docTitle').value) {
            document.getElementById('docTitle').value = selectedFiles[0].name.replace(/\.[^.]+$/, '');
        }
    } else {
        singleMeta.classList.add('d-none');
    }

    list.innerHTML = '';
    selectedFiles.forEach((f, i) => {
        const icon = getFileIcon(f.name);
        list.innerHTML += `
            <div class="file-item" id="file-${i}">
                <i class="bi ${icon} file-icon"></i>
                <div class="file-info">
                    <div class="file-name">${escHtml(f.name)}</div>
                    <div class="file-size">${fmtSize(f.size)}</div>
                </div>
                <span class="file-status" id="status-${i}"></span>
                <i class="bi bi-x-circle file-remove" onclick="removeFile(${i})" title="Remove"></i>
            </div>
        `;
    });
}

function removeFile(index) {
    selectedFiles.splice(index, 1);
    renderQueue();
}

function clearQueue() {
    selectedFiles = [];
    renderQueue();
}

// ============================
// Upload
// ============================
function startUpload() {
    if (selectedFiles.length === 0) return;

    const btn = document.getElementById('btnUpload');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i> <?php _e('doc_uploading', 'Uploading...'); ?>';

    document.getElementById('uploadProgress').classList.remove('d-none');

    if (selectedFiles.length === 1) {
        uploadSingle();
    } else {
        uploadMulti();
    }
}

function uploadSingle() {
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('file', selectedFiles[0]);
    fd.append('title', document.getElementById('docTitle').value);
    fd.append('description', document.getElementById('docDescription').value);
    fd.append('tags', document.getElementById('docTags').value);
    fd.append('expiry_date', document.getElementById('docExpiry').value);
    fd.append('category_id', document.getElementById('docCategory').value);
    fd.append('entity_type', document.getElementById('docEntityType').value);
    fd.append('entity_id', document.getElementById('docEntityId').value || '');
    if (document.getElementById('docConfidential').checked) {
        fd.append('is_confidential', '1');
    }

    const xhr = new XMLHttpRequest();
    xhr.open('POST', API_URL);

    xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            document.getElementById('progressBar').style.width = pct + '%';
            document.getElementById('progressText').textContent = pct + '%';
        }
    });

    xhr.onload = function() {
        const res = JSON.parse(xhr.responseText);
        document.getElementById('progressBar').style.width = '100%';
        document.getElementById('progressBar').classList.remove('progress-bar-animated');

        if (res.success) {
            document.getElementById('progressBar').classList.replace('bg-primary', 'bg-success');
            document.getElementById('uploadResults').innerHTML = `
                <div class="alert alert-success py-2 mb-0">
                    <i class="bi bi-check-circle me-1"></i> ${escHtml(res.message)}
                    <a href="${BASE_URL}modules/documents/view.php?id=${res.id}" class="ms-2"><?php _e('doc_view_document', 'View Document'); ?> &rarr;</a>
                </div>
            `;
            updateStatus(0, 'success');
            markUploadComplete();
        } else {
            document.getElementById('progressBar').classList.replace('bg-primary', 'bg-danger');
            document.getElementById('uploadResults').innerHTML = `
                <div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle me-1"></i> ${escHtml(res.message)}</div>
            `;
            updateStatus(0, 'error');
            resetUploadBtn();
        }
    };

    xhr.onerror = function() {
        document.getElementById('uploadResults').innerHTML = '<div class="alert alert-danger py-2 mb-0">Network error</div>';
        resetUploadBtn();
    };

    xhr.send(fd);
}

function uploadMulti() {
    const fd = new FormData();
    fd.append('action', 'upload_multi');
    fd.append('category_id', document.getElementById('docCategory').value);
    fd.append('entity_type', document.getElementById('docEntityType').value);
    fd.append('entity_id', document.getElementById('docEntityId').value || '');
    if (document.getElementById('docConfidential').checked) {
        fd.append('is_confidential', '1');
    }

    selectedFiles.forEach(f => fd.append('files[]', f));

    const xhr = new XMLHttpRequest();
    xhr.open('POST', API_URL);

    xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            document.getElementById('progressBar').style.width = pct + '%';
            document.getElementById('progressText').textContent = pct + '%';
        }
    });

    xhr.onload = function() {
        const res = JSON.parse(xhr.responseText);
        document.getElementById('progressBar').style.width = '100%';
        document.getElementById('progressBar').classList.remove('progress-bar-animated');

        if (res.success) {
            const barClass = res.failed > 0 ? 'bg-warning' : 'bg-success';
            document.getElementById('progressBar').classList.replace('bg-primary', barClass);

            let html = `<div class="alert alert-${res.failed > 0 ? 'warning' : 'success'} py-2 mb-0">
                <i class="bi bi-check-circle me-1"></i> ${escHtml(res.message)}
                <a href="${BASE_URL}modules/documents/" class="ms-2"><?php _e('doc_view_all', 'View All Documents'); ?> &rarr;</a>
            </div>`;

            if (res.errors && res.errors.length > 0) {
                html += '<div class="mt-2"><small class="text-danger">';
                res.errors.forEach(e => { html += escHtml(e) + '<br>'; });
                html += '</small></div>';
            }

            document.getElementById('uploadResults').innerHTML = html;
            // Mark all as success/error
            selectedFiles.forEach((f, i) => updateStatus(i, 'success'));
            markUploadComplete();
        } else {
            document.getElementById('progressBar').classList.replace('bg-primary', 'bg-danger');
            document.getElementById('uploadResults').innerHTML = `
                <div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle me-1"></i> ${escHtml(res.message)}</div>
            `;
            resetUploadBtn();
        }
    };

    xhr.onerror = function() {
        document.getElementById('uploadResults').innerHTML = '<div class="alert alert-danger py-2 mb-0">Network error</div>';
        resetUploadBtn();
    };

    xhr.send(fd);
}

function updateStatus(index, status) {
    const el = document.getElementById('status-' + index);
    if (!el) return;
    if (status === 'success') {
        el.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
    } else {
        el.innerHTML = '<i class="bi bi-x-circle-fill text-danger"></i>';
    }
}

function resetUploadBtn() {
    const btn = document.getElementById('btnUpload');
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i> <?php _e('doc_upload_now', 'Upload Now'); ?>';
}

// Stop the "Uploading..." spinners once a batch finishes successfully.
function markUploadComplete() {
    const hdr = document.getElementById('progressHeader');
    if (hdr) {
        hdr.innerHTML = '<i class="bi bi-check-circle me-1 text-success"></i> <?php _e('doc_upload_complete', 'Upload complete'); ?>';
    }
    const btn = document.getElementById('btnUpload');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-check-circle me-1"></i> <?php _e('doc_uploaded_done', 'Uploaded'); ?>';
}

// ============================
// Entity Dropdowns
// ============================
function loadEntityList() {
    const type = document.getElementById('docEntityType').value;
    const wrapper = document.getElementById('entityIdWrapper');
    const select = document.getElementById('docEntityId');

    if (type === 'general') {
        wrapper.classList.add('d-none');
        select.innerHTML = '<option value="">—</option>';
        return;
    }

    wrapper.classList.remove('d-none');
    select.innerHTML = '<option value=""><?php _e('loading', 'Loading...'); ?></option>';

    // Fetch dropdowns only once
    if (dropdownsData) {
        populateEntitySelect(type);
        return;
    }

    fetch(API_URL + '?action=dropdowns')
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                dropdownsData = res.data;
                populateEntitySelect(type);
            }
        });
}

function populateEntitySelect(type) {
    const select = document.getElementById('docEntityId');
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
        select.appendChild(opt);
    });

    // Pre-select if prefilled
    <?php if ($prefillEntityId): ?>
    select.value = '<?php echo $prefillEntityId; ?>';
    <?php endif; ?>
}

// ============================
// Helpers
// ============================
function fmtSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return bytes + ' B';
}

function getFileIcon(name) {
    const ext = name.split('.').pop().toLowerCase();
    const map = {
        'jpg': 'bi-file-image text-success', 'jpeg': 'bi-file-image text-success',
        'png': 'bi-file-image text-success', 'gif': 'bi-file-image text-success',
        'webp': 'bi-file-image text-success', 'pdf': 'bi-file-pdf text-danger',
        'doc': 'bi-file-word text-primary', 'docx': 'bi-file-word text-primary',
        'xlsx': 'bi-file-excel text-success', 'csv': 'bi-file-excel text-success',
        'txt': 'bi-file-text text-secondary', 'zip': 'bi-file-zip text-warning',
        'rar': 'bi-file-zip text-warning'
    };
    return map[ext] || 'bi-file-earmark text-muted';
}

function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Load entity dropdown if pre-filled
<?php if ($prefillEntityType !== 'general'): ?>
document.addEventListener('DOMContentLoaded', () => loadEntityList());
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
