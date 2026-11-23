<?php
/**
 * FIS Real Estate — Inquiry/Lead Management (Part 20.5)
 * All inquiries across all listings with status tracking.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';
$pageTitle = __('vac_all_inquiries', 'All Inquiries');

$subStatus = enforceSubscription();

if (!hasTier('enterprise')) { setAlert(__('vac_tier_required'), 'warning'); redirect('index.php'); }
if (getSetting('vacancy_advertising_enabled', '0') !== '1') { setAlert(__('vac_disabled'), 'warning'); redirect('modules/settings/?tab=modules'); }
if (!hasPermission('vacancies', 'view')) { setAlert(__('no_permission'), 'danger'); redirect('index.php'); }

// Filters
$filterStatus = trim($_GET['status'] ?? '');
$filterSearch = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$where = "1=1" . propertyScopeSql('u.property_id');
$params = [];
if ($filterStatus) { $where .= " AND vi.status = :status"; $params['status'] = $filterStatus; }
if ($filterSearch) { $where .= " AND (vi.inquirer_name LIKE :s1 OR vi.inquirer_phone LIKE :s2 OR vi.inquirer_email LIKE :s3)"; $params['s1'] = "%$filterSearch%"; $params['s2'] = "%$filterSearch%"; $params['s3'] = "%$filterSearch%"; }

$countStmt = $conn->prepare("SELECT COUNT(*) FROM vacancy_inquiries vi INNER JOIN vacancy_listings vl ON vi.listing_id = vl.id INNER JOIN units u ON vl.unit_id = u.id WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pagination = paginate($total, $page);

$sql = "SELECT vi.*, vl.title AS listing_title, u.unit_number,
               p.name AS property_name, vl.rent_amount, vl.currency,
               usr.full_name AS followed_up_by_name
        FROM vacancy_inquiries vi
        INNER JOIN vacancy_listings vl ON vi.listing_id = vl.id
        INNER JOIN units u ON vl.unit_id = u.id
        INNER JOIN properties p ON u.property_id = p.id
        LEFT JOIN users usr ON vi.followed_up_by = usr.id
        WHERE $where
        ORDER BY FIELD(vi.status, 'new', 'contacted', 'viewing_scheduled', 'application_received', 'rejected', 'converted'), vi.created_at DESC
        LIMIT :offset, :limit";
$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $pagination['records_per_page'], PDO::PARAM_INT);
$stmt->execute();
$inquiries = $stmt->fetchAll();

// Stats
$inqScope = " AND listing_id IN (SELECT id FROM vacancy_listings WHERE 1=1" . unitScopeSql('unit_id') . ")";
$newCount = (int)$conn->query("SELECT COUNT(*) FROM vacancy_inquiries WHERE status = 'new'" . $inqScope)->fetchColumn();
$contactedCount = (int)$conn->query("SELECT COUNT(*) FROM vacancy_inquiries WHERE status = 'contacted'" . $inqScope)->fetchColumn();
$viewingCount = (int)$conn->query("SELECT COUNT(*) FROM vacancy_inquiries WHERE status = 'viewing_scheduled'" . $inqScope)->fetchColumn();
$convertedCount = (int)$conn->query("SELECT COUNT(*) FROM vacancy_inquiries WHERE status = 'converted'" . $inqScope)->fetchColumn();

$inqStatusColors = ['new' => 'danger', 'contacted' => 'info', 'viewing_scheduled' => 'warning', 'application_received' => 'primary', 'rejected' => 'secondary', 'converted' => 'success'];

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4><i class="bi bi-chat-dots me-2"></i><?php _e('vac_all_inquiries', 'All Inquiries'); ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:0.85rem;">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>modules/vacancies/"><?php _e('vac_vacancies', 'Vacancies'); ?></a></li>
                <li class="breadcrumb-item active"><?php _e('vac_inquiries', 'Inquiries'); ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-1">
        <a href="<?php echo BASE_URL; ?>api/vacancies.php?action=export_inquiries" class="btn btn-sm btn-outline-success">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i><?php _e('vac_export_inquiries', 'Export CSV'); ?>
        </a>
    </div>
</div>

<?php pageIntro('vacancies.inquiries'); ?>

<!-- Stats -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fee2e2;color:#dc2626;"><i class="bi bi-bell"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $newCount; ?></div>
                <div class="stat-card-label"><?php _e('vac_status_new', 'New'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#dbeafe;color:#1e40af;"><i class="bi bi-telephone"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $contactedCount; ?></div>
                <div class="stat-card-label"><?php _e('vac_status_contacted', 'Contacted'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#fef3c7;color:#92400e;"><i class="bi bi-calendar-event"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $viewingCount; ?></div>
                <div class="stat-card-label"><?php _e('vac_status_viewing', 'Viewing Scheduled'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e8f5ee;color:#1a6b4a;"><i class="bi bi-check-circle"></i></div>
            <div>
                <div class="stat-card-value"><?php echo $convertedCount; ?></div>
                <div class="stat-card-label"><?php _e('vac_status_converted', 'Converted'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?php _e('all_statuses', 'All Statuses'); ?></option>
                    <?php foreach ($inqStatusColors as $st => $color): ?>
                    <option value="<?php echo $st; ?>" <?php echo $filterStatus === $st ? 'selected' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $st)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-4">
                <input type="text" class="form-control form-control-sm" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="<?php _e('vac_search_inquiries', 'Search by name, phone, email'); ?>...">
            </div>
            <div class="col-12 col-md-5 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?php _e('filter'); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/vacancies/inquiries.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Inquiries Table -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($inquiries)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-chat-dots" style="font-size:2.5rem;"></i>
            <p class="mt-2"><?php _e('vac_no_inquiries', 'No inquiries found'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th><?php _e('name'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('vac_contact', 'Contact'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('vac_listing', 'Listing'); ?></th>
                        <th class="text-center"><?php _e('status'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('vac_follow_up', 'Follow-up'); ?></th>
                        <th><?php _e('date'); ?></th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inquiries as $inq): ?>
                    <tr class="<?php echo $inq['status'] === 'new' ? 'table-warning' : ''; ?>">
                        <td>
                            <span class="fw-semibold"><?php echo htmlspecialchars($inq['inquirer_name']); ?></span>
                            <?php if ($inq['message']): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars(mb_substr($inq['message'], 0, 50)); ?><?php echo mb_strlen($inq['message']) > 50 ? '...' : ''; ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell">
                            <?php if ($inq['inquirer_phone']): ?><small><?php echo htmlspecialchars($inq['inquirer_phone']); ?></small><br><?php endif; ?>
                            <?php if ($inq['inquirer_email']): ?><small class="text-muted"><?php echo htmlspecialchars($inq['inquirer_email']); ?></small><?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell">
                            <a href="<?php echo BASE_URL; ?>modules/vacancies/view.php?id=<?php echo $inq['listing_id']; ?>" class="text-decoration-none">
                                <small><?php echo htmlspecialchars($inq['listing_title']); ?></small>
                            </a>
                            <br><small class="text-muted"><?php echo htmlspecialchars($inq['property_name'] . ' — ' . $inq['unit_number']); ?></small>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo $inqStatusColors[$inq['status']] ?? 'secondary'; ?>" style="font-size:0.7rem;">
                                <?php echo ucwords(str_replace('_', ' ', $inq['status'])); ?>
                            </span>
                        </td>
                        <td class="d-none d-md-table-cell">
                            <?php if ($inq['follow_up_date']): ?>
                            <small class="<?php echo strtotime($inq['follow_up_date']) < time() ? 'text-danger' : ''; ?>"><?php echo formatDate($inq['follow_up_date']); ?></small>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><small><?php echo formatDate($inq['created_at']); ?></small></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary btn-sm" onclick="showInquiry(<?php echo htmlspecialchars(json_encode($inq)); ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if (hasPermission('vacancies', 'delete')): ?>
                                <button class="btn btn-outline-danger btn-sm" onclick="deleteInquiry(<?php echo $inq['id']; ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted"><?php echo $pagination['total_records']; ?> <?php _e('records'); ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php if ($pagination['has_prev']): ?>
                <li class="page-item"><a class="page-link" href="?page=<?php echo $pagination['prev_page']; ?>&status=<?php echo $filterStatus; ?>&search=<?php echo urlencode($filterSearch); ?>">&laquo;</a></li>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($pagination['total_pages'], $page + 2); $i++): ?>
                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $filterStatus; ?>&search=<?php echo urlencode($filterSearch); ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($pagination['has_next']): ?>
                <li class="page-item"><a class="page-link" href="?page=<?php echo $pagination['next_page']; ?>&status=<?php echo $filterStatus; ?>&search=<?php echo urlencode($filterSearch); ?>">&raquo;</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Inquiry Update Modal -->
<div class="modal fade" id="inquiryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-chat-dots me-1"></i> <?php _e('vac_update_inquiry', 'Update Inquiry'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="inqDetail" class="mb-3"></div>
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
function showInquiry(inq) {
    document.getElementById('inq_id').value = inq.id;
    document.getElementById('inq_status').value = inq.status;
    document.getElementById('inq_notes').value = inq.notes || '';
    document.getElementById('inq_followup').value = inq.follow_up_date || '';

    let html = `<p><strong>${inq.inquirer_name}</strong></p>`;
    if (inq.inquirer_phone) html += `<p><i class="bi bi-telephone me-1"></i> ${inq.inquirer_phone}</p>`;
    if (inq.inquirer_email) html += `<p><i class="bi bi-envelope me-1"></i> ${inq.inquirer_email}</p>`;
    if (inq.message) html += `<p class="bg-light p-2 rounded"><small>${inq.message}</small></p>`;
    html += `<small class="text-muted">Listing: ${inq.listing_title} | ${inq.created_at}</small>`;
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

function deleteInquiry(id) {
    if (!confirm('<?php echo __("vac_delete_inquiry_confirm", "Delete this inquiry?"); ?>')) return;
    const fd = new FormData();
    fd.append('action', 'delete_inquiry');
    fd.append('id', id);
    fetch(BASE_URL + 'api/vacancies.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { if (res.success) location.reload(); else alert(res.message); });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
