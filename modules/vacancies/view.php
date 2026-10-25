<?php
/**
 * FIS Real Estate — View Vacancy Listing (Part 20.4 + 20.5)
 * Listing details + inquiry list + status management.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('vac_view_listing', 'View Listing');

$subStatus = enforceSubscription();

if (!hasTier('enterprise')) { setAlert(__('vac_tier_required'), 'warning'); redirect('index.php'); }
if (getSetting('vacancy_advertising_enabled', '0') !== '1') { setAlert(__('vac_disabled'), 'warning'); redirect('modules/settings/?tab=modules'); }
if (!hasPermission('vacancies', 'view')) { setAlert(__('no_permission'), 'danger'); redirect('index.php'); }

$id = (int)($_GET['id'] ?? 0);
if (!$id) { setAlert('Listing ID required', 'danger'); redirect('modules/vacancies/'); }

$stmt = $conn->prepare("
    SELECT vl.*, u.unit_number, u.unit_type, u.bedrooms, u.bathrooms, u.size_sqm, u.floor,
           p.name AS property_name, p.address AS property_address, p.city
    FROM vacancy_listings vl
    INNER JOIN units u ON vl.unit_id = u.id
    INNER JOIN properties p ON u.property_id = p.id
    WHERE vl.id = ?
");
$stmt->execute([$id]);
$listing = $stmt->fetch();
if (!$listing) { setAlert('Listing not found', 'danger'); redirect('modules/vacancies/'); }
if (!canAccessUnit($listing['unit_id'])) {
    setAlert(__('no_access_record', "You don't have access to this record"), 'danger');
    redirect('modules/vacancies/');
}

// Get inquiries for this listing
$inqStmt = $conn->prepare("
    SELECT vi.*, usr.full_name AS followed_up_by_name
    FROM vacancy_inquiries vi
    LEFT JOIN users usr ON vi.followed_up_by = usr.id
    WHERE vi.listing_id = ?
    ORDER BY vi.created_at DESC
");
$inqStmt->execute([$id]);
$inquiries = $inqStmt->fetchAll();

$statusColors = ['active' => 'success', 'inactive' => 'secondary', 'rented' => 'primary'];
$inqStatusColors = ['new' => 'danger', 'contacted' => 'info', 'viewing_scheduled' => 'warning', 'application_received' => 'primary', 'rejected' => 'secondary', 'converted' => 'success'];

$features = $listing['features_json'] ? json_decode($listing['features_json'], true) : [];
$photos = $listing['photos_json'] ? json_decode($listing['photos_json'], true) : [];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-megaphone me-2"></i><?php echo htmlspecialchars($listing['title']); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/vacancies/"><?php _e('vac_vacancies', 'Vacancies'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('view'); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-1 flex-wrap">
        <?php if (hasPermission('vacancies', 'edit')): ?>
        <a href="<?php echo BASE_URL; ?>modules/vacancies/create.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-pencil me-1"></i><?php _e('edit'); ?>
        </a>
        <?php endif; ?>
        <div class="btn-group btn-group-sm">
            <?php foreach (['active' => 'success', 'inactive' => 'secondary', 'rented' => 'primary'] as $st => $color): ?>
            <button class="btn btn-<?php echo $listing['status'] === $st ? '' : 'outline-'; ?><?php echo $color; ?>"
                    onclick="changeStatus('<?php echo $st; ?>')" <?php echo $listing['status'] === $st ? 'disabled' : ''; ?>>
                <?php echo ucfirst($st); ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php pageIntro('vacancies.view'); ?>

<div class="row g-3">
    <!-- Listing Details -->
    <div class="col-12 col-lg-5">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-info-circle me-1"></i> <?php _e('vac_details', 'Listing Details'); ?></span>
                <span class="badge bg-<?php echo $statusColors[$listing['status']] ?? 'secondary'; ?>"><?php echo ucfirst($listing['status']); ?></span>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width:40%;"><?php _e('property'); ?></td><td class="fw-semibold"><?php echo htmlspecialchars($listing['property_name']); ?></td></tr>
                    <tr><td class="text-muted"><?php _e('unit'); ?></td><td><?php echo htmlspecialchars($listing['unit_number']); ?> (<?php echo ucfirst($listing['unit_type']); ?>)</td></tr>
                    <?php if ($listing['city']): ?><tr><td class="text-muted"><?php _e('city'); ?></td><td><?php echo htmlspecialchars($listing['city']); ?></td></tr><?php endif; ?>
                    <tr><td class="text-muted"><?php _e('exp_rent', 'Rent'); ?></td><td class="fw-semibold text-success"><?php echo formatCurrency($listing['rent_amount'], $listing['currency']); ?>/<?php _e('month'); ?></td></tr>
                    <?php if ($listing['deposit_amount']): ?><tr><td class="text-muted"><?php _e('vac_deposit', 'Deposit'); ?></td><td><?php echo formatCurrency($listing['deposit_amount'], $listing['currency']); ?></td></tr><?php endif; ?>
                    <?php if ($listing['bedrooms']): ?><tr><td class="text-muted"><?php _e('bedrooms'); ?></td><td><?php echo $listing['bedrooms']; ?></td></tr><?php endif; ?>
                    <?php if ($listing['bathrooms']): ?><tr><td class="text-muted"><?php _e('bathrooms'); ?></td><td><?php echo $listing['bathrooms']; ?></td></tr><?php endif; ?>
                    <?php if ($listing['size_sqm']): ?><tr><td class="text-muted"><?php _e('size'); ?></td><td><?php echo $listing['size_sqm']; ?> sqm</td></tr><?php endif; ?>
                    <?php if ($listing['available_from']): ?><tr><td class="text-muted"><?php _e('vac_available_from'); ?></td><td><?php echo formatDate($listing['available_from']); ?></td></tr><?php endif; ?>
                    <tr><td class="text-muted"><?php _e('vac_views', 'Views'); ?></td><td><?php echo number_format($listing['views_count']); ?></td></tr>
                    <tr><td class="text-muted"><?php _e('created'); ?></td><td><?php echo formatDateTime($listing['created_at']); ?></td></tr>
                </table>
            </div>
        </div>

        <?php if (!empty($features)): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-star me-1"></i> <?php _e('vac_features', 'Features'); ?></div>
            <div class="card-body">
                <?php foreach ($features as $f): ?>
                <span class="badge bg-light text-dark border me-1 mb-1"><i class="bi bi-check2 me-1"></i><?php echo htmlspecialchars($f); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($listing['description']): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-text-paragraph me-1"></i> <?php _e('description'); ?></div>
            <div class="card-body"><p class="mb-0"><?php echo nl2br(htmlspecialchars($listing['description'])); ?></p></div>
        </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-person-lines-fill me-1"></i> <?php _e('vac_contact_info', 'Contact Info'); ?></div>
            <div class="card-body">
                <?php if ($listing['contact_name']): ?><p class="mb-1"><i class="bi bi-person me-1"></i> <?php echo htmlspecialchars($listing['contact_name']); ?></p><?php endif; ?>
                <?php if ($listing['contact_phone']): ?><p class="mb-1"><i class="bi bi-telephone me-1"></i> <?php echo htmlspecialchars($listing['contact_phone']); ?></p><?php endif; ?>
                <?php if ($listing['contact_email']): ?><p class="mb-0"><i class="bi bi-envelope me-1"></i> <?php echo htmlspecialchars($listing['contact_email']); ?></p><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Inquiries -->
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-chat-dots me-1"></i> <?php _e('vac_inquiries', 'Inquiries'); ?> (<?php echo count($inquiries); ?>)</span>
                <a href="<?php echo BASE_URL; ?>api/vacancies.php?action=export_inquiries" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-download me-1"></i>CSV
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($inquiries)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-chat-dots" style="font-size:2rem;"></i>
                    <p class="mt-2"><?php _e('vac_no_inquiries', 'No inquiries yet'); ?></p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th><?php _e('name'); ?></th>
                                <th class="d-none d-md-table-cell"><?php _e('vac_contact', 'Contact'); ?></th>
                                <th class="text-center"><?php _e('status'); ?></th>
                                <th><?php _e('date'); ?></th>
                                <th style="width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inquiries as $inq): ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold"><?php echo htmlspecialchars($inq['inquirer_name']); ?></span>
                                    <?php if ($inq['message']): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars(mb_substr($inq['message'], 0, 60)); ?><?php echo mb_strlen($inq['message']) > 60 ? '...' : ''; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <?php if ($inq['inquirer_phone']): ?><small><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($inq['inquirer_phone']); ?></small><br><?php endif; ?>
                                    <?php if ($inq['inquirer_email']): ?><small><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($inq['inquirer_email']); ?></small><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?php echo $inqStatusColors[$inq['status']] ?? 'secondary'; ?>" style="font-size:0.7rem;">
                                        <?php echo ucwords(str_replace('_', ' ', $inq['status'])); ?>
                                    </span>
                                </td>
                                <td><small><?php echo formatDate($inq['created_at']); ?></small></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick="showInquiry(<?php echo htmlspecialchars(json_encode($inq)); ?>)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Inquiry Detail Modal -->
<div class="modal fade" id="inquiryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-chat-dots me-1"></i> <?php _e('vac_inquiry_detail', 'Inquiry Detail'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="inqDetail"></div>
                <hr>
                <form id="inqUpdateForm">
                    <input type="hidden" name="id" id="inq_id">
                    <div class="mb-2">
                        <label class="form-label"><?php _e('status'); ?></label>
                        <select name="status" id="inq_status" class="form-select form-select-sm">
                            <option value="new"><?php _e('vac_status_new', 'New'); ?></option>
                            <option value="contacted"><?php _e('vac_status_contacted', 'Contacted'); ?></option>
                            <option value="viewing_scheduled"><?php _e('vac_status_viewing', 'Viewing Scheduled'); ?></option>
                            <option value="application_received"><?php _e('vac_status_application', 'Application Received'); ?></option>
                            <option value="rejected"><?php _e('vac_status_rejected', 'Rejected'); ?></option>
                            <option value="converted"><?php _e('vac_status_converted', 'Converted'); ?></option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label"><?php _e('vac_follow_up_date', 'Follow-up Date'); ?></label>
                        <input type="date" name="follow_up_date" id="inq_followup" class="form-control form-control-sm">
                    </div>
                    <div class="mb-2">
                        <label class="form-label"><?php _e('notes'); ?></label>
                        <textarea name="notes" id="inq_notes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php _e('cancel'); ?></button>
                <button type="button" class="btn btn-primary btn-sm" onclick="updateInquiry()"><?php _e('save'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
function changeStatus(status) {
    if (!confirm('<?php echo __("vac_change_status_confirm", "Change listing status?"); ?>')) return;
    const fd = new FormData();
    fd.append('action', 'toggle_status');
    fd.append('id', <?php echo $id; ?>);
    fd.append('status', status);
    fetch(BASE_URL + 'api/vacancies.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { if (res.success) location.reload(); else alert(res.message); });
}

function showInquiry(inq) {
    document.getElementById('inq_id').value = inq.id;
    document.getElementById('inq_status').value = inq.status;
    document.getElementById('inq_notes').value = inq.notes || '';
    document.getElementById('inq_followup').value = inq.follow_up_date || '';

    let html = `<p><strong>${inq.inquirer_name}</strong></p>`;
    if (inq.inquirer_phone) html += `<p><i class="bi bi-telephone me-1"></i> ${inq.inquirer_phone}</p>`;
    if (inq.inquirer_email) html += `<p><i class="bi bi-envelope me-1"></i> ${inq.inquirer_email}</p>`;
    if (inq.message) html += `<p class="bg-light p-2 rounded"><small>${inq.message}</small></p>`;
    html += `<small class="text-muted">${inq.created_at}</small>`;
    document.getElementById('inqDetail').innerHTML = html;

    new bootstrap.Modal(document.getElementById('inquiryModal')).show();
}

function updateInquiry() {
    const fd = new FormData(document.getElementById('inqUpdateForm'));
    fd.append('action', 'update_inquiry');
    fetch(BASE_URL + 'api/vacancies.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { if (res.success) location.reload(); else alert(res.message); });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
