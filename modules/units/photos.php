<?php
/**
 * FIS Real Estate — Unit Photos Management (6.4)
 * Upload multiple images, drag-to-reorder, set captions, delete.
 * Same pattern as property photos (Part 5.2).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

$subStatus = enforceSubscription();

if (!hasPermission('units', 'edit')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setAlert('Unit not found', 'danger');
    redirect('modules/units/');
}

$stmt = $conn->prepare("SELECT u.*, p.name AS property_name FROM units u INNER JOIN properties p ON u.property_id = p.id WHERE u.id = :id");
$stmt->execute(['id' => $id]);
$unit = $stmt->fetch();

if (!$unit) {
    setAlert('Unit not found', 'danger');
    redirect('modules/units/');
}

if (!canAccessUnit($id)) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/units/');
}

$pageTitle = __('photos', 'Photos') . ' - ' . $unit['unit_number'];

// Fetch all photos
$photoStmt = $conn->prepare("SELECT * FROM unit_photos WHERE unit_id = :id ORDER BY sort_order ASC, id ASC");
$photoStmt->execute(['id' => $id]);
$photos = $photoStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-images me-2"></i><?php _e('photos', 'Photos'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/units/"><?php _e('units'); ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/units/view.php?id=<?php echo $id; ?>"><?php echo htmlspecialchars($unit['unit_number']); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('photos', 'Photos'); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php pageIntro('units.photos'); ?>

<!-- Upload Area -->
<div class="card mb-3">
    <div class="card-body">
        <form id="uploadForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_photo">
            <input type="hidden" name="unit_id" value="<?php echo $id; ?>">

            <div id="dropZone" class="border border-2 border-dashed rounded-3 p-4 text-center" style="border-color:#ccc !important;cursor:pointer;transition:all 0.3s;">
                <i class="bi bi-cloud-arrow-up" style="font-size:2.5rem;color:#aaa;"></i>
                <p class="mb-1 mt-2 fw-semibold"><?php _e('drag_photos', 'Drag & drop photos here'); ?></p>
                <p class="text-muted small mb-2"><?php _e('or_click', 'or click to select files'); ?></p>
                <input type="file" id="fileInput" name="photos[]" multiple accept="image/*" style="display:none;">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="document.getElementById('fileInput').click()">
                    <i class="bi bi-folder2-open me-1"></i> <?php _e('choose_files', 'Choose Files'); ?>
                </button>
                <p class="text-muted small mt-2 mb-0"><?php _e('allowed_formats', 'JPG, PNG, GIF, WebP — Max 10MB each'); ?></p>
            </div>

            <!-- Upload Progress -->
            <div id="uploadProgress" class="mt-2 d-none">
                <div class="progress" style="height:4px;">
                    <div class="progress-bar bg-primary" id="uploadBar" style="width:0%;"></div>
                </div>
                <small class="text-muted" id="uploadStatus"></small>
            </div>
        </form>
    </div>
</div>

<!-- Existing Photos Gallery (drag to reorder) -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-grid me-1"></i> <?php _e('gallery', 'Gallery'); ?> (<?php echo count($photos); ?> <?php _e('photos', 'photos'); ?>)</span>
        <small class="text-muted"><?php _e('drag_reorder', 'Drag to reorder'); ?></small>
    </div>
    <div class="card-body">
        <?php if (empty($photos)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-camera" style="font-size:3rem;"></i>
            <p class="mt-2"><?php _e('no_photos_yet', 'No photos uploaded yet. Use the area above to upload.'); ?></p>
        </div>
        <?php else: ?>
        <div class="row g-3" id="photoGallery">
            <?php foreach ($photos as $photo): ?>
            <div class="col-6 col-md-4 col-lg-3" data-photo-id="<?php echo $photo['id']; ?>">
                <div class="card h-100 photo-card" style="cursor:grab;">
                    <div style="aspect-ratio:4/3;overflow:hidden;position:relative;">
                        <img src="<?php echo BASE_URL; ?>uploads/<?php echo htmlspecialchars($photo['file_path']); ?>"
                             alt="<?php echo htmlspecialchars($photo['caption'] ?? ''); ?>"
                             style="width:100%;height:100%;object-fit:cover;"
                             loading="lazy">
                        <div class="position-absolute top-0 end-0 p-1">
                            <button class="btn btn-sm btn-danger" style="padding:2px 6px;font-size:0.7rem;" onclick="deletePhoto(<?php echo $photo['id']; ?>, event)" title="<?php _e('delete'); ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                        <div class="position-absolute top-0 start-0 p-1">
                            <span class="badge bg-dark bg-opacity-50" style="font-size:0.65rem;cursor:grab;">
                                <i class="bi bi-grip-vertical"></i> #<?php echo $photo['sort_order']; ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body p-2">
                        <input type="text" class="form-control form-control-sm" value="<?php echo htmlspecialchars($photo['caption'] ?? ''); ?>" placeholder="<?php _e('caption', 'Caption'); ?>" onchange="updateCaption(<?php echo $photo['id']; ?>, this.value)">
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Sortable.js for drag reorder -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
const csrfToken = '<?php echo generateCSRF(); ?>';
const apiUrl = BASE_URL + 'api/units.php';
const unitId = <?php echo $id; ?>;

// Drag & Drop upload zone
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');

['dragenter', 'dragover'].forEach(evt => {
    dropZone.addEventListener(evt, e => {
        e.preventDefault();
        dropZone.style.borderColor = '#1a6b4a';
        dropZone.style.background = '#e8f5ee';
    });
});

['dragleave', 'drop'].forEach(evt => {
    dropZone.addEventListener(evt, e => {
        e.preventDefault();
        dropZone.style.borderColor = '#ccc';
        dropZone.style.background = '';
    });
});

dropZone.addEventListener('drop', e => {
    const files = e.dataTransfer.files;
    if (files.length) uploadFiles(files);
});

dropZone.addEventListener('click', e => {
    if (e.target === dropZone || e.target.closest('#dropZone')) {
        if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'INPUT') {
            fileInput.click();
        }
    }
});

fileInput.addEventListener('change', () => {
    if (fileInput.files.length) uploadFiles(fileInput.files);
});

function uploadFiles(files) {
    const formData = new FormData();
    formData.append('action', 'upload_photo');
    formData.append('unit_id', unitId);

    for (let i = 0; i < files.length; i++) {
        formData.append('photos[]', files[i]);
    }

    const progress = document.getElementById('uploadProgress');
    const bar = document.getElementById('uploadBar');
    const status = document.getElementById('uploadStatus');
    progress.classList.remove('d-none');
    bar.style.width = '0%';
    bar.className = 'progress-bar bg-primary';
    status.textContent = 'Uploading ' + files.length + ' file(s)...';

    const xhr = new XMLHttpRequest();
    xhr.open('POST', apiUrl, true);

    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            const pct = Math.round(e.loaded / e.total * 100);
            bar.style.width = pct + '%';
            status.textContent = 'Uploading... ' + pct + '%';
        }
    };

    xhr.onload = function() {
        try {
            const resp = JSON.parse(xhr.responseText);
            if (resp.success) {
                status.textContent = resp.message;
                bar.style.width = '100%';
                bar.classList.remove('bg-primary');
                bar.classList.add('bg-success');
                setTimeout(() => location.reload(), 800);
            } else {
                status.textContent = 'Error: ' + resp.message;
                bar.classList.remove('bg-primary');
                bar.classList.add('bg-danger');
            }
        } catch (e) {
            status.textContent = 'Upload failed';
            bar.classList.add('bg-danger');
        }
    };

    xhr.onerror = function() {
        status.textContent = 'Upload failed — network error';
        bar.classList.add('bg-danger');
    };

    xhr.send(formData);
    fileInput.value = '';
}

// Delete photo
function deletePhoto(photoId, event) {
    event.stopPropagation();
    if (!confirm('<?php echo __("delete_photo_confirm", "Delete this photo?"); ?>')) return;

    const formData = new FormData();
    formData.append('action', 'delete_photo');
    formData.append('csrf_token', csrfToken);
    formData.append('photo_id', photoId);

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const card = document.querySelector('[data-photo-id="' + photoId + '"]');
                if (card) card.remove();
            } else {
                alert(data.message);
            }
        });
}

// Update caption
let captionTimeout = null;
function updateCaption(photoId, caption) {
    clearTimeout(captionTimeout);
    captionTimeout = setTimeout(() => {
        const formData = new FormData();
        formData.append('action', 'update_photo');
        formData.append('csrf_token', csrfToken);
        formData.append('photo_id', photoId);
        formData.append('caption', caption);

        fetch(apiUrl, { method: 'POST', body: formData })
            .then(r => r.json())
            .catch(() => {});
    }, 500);
}

// Drag to reorder
const gallery = document.getElementById('photoGallery');
if (gallery) {
    new Sortable(gallery, {
        animation: 150,
        ghostClass: 'bg-light',
        handle: '.photo-card',
        onEnd: function() {
            const order = [];
            gallery.querySelectorAll('[data-photo-id]').forEach((el, idx) => {
                order.push(el.getAttribute('data-photo-id'));
            });

            const formData = new FormData();
            formData.append('action', 'reorder_photos');
            formData.append('csrf_token', csrfToken);
            order.forEach(id => formData.append('order[]', id));

            fetch(apiUrl, { method: 'POST', body: formData })
                .then(r => r.json())
                .catch(() => {});
        }
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
